<?php
/**
 * A vendor's media: uploads, the list, deleting, and the space used (SEC-7, CTL-14, CTL-24).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Catalog\Infrastructure;

use DateTimeInterface;
use Mercatus\Core\Catalog\Application\CatalogPolicy;
use Mercatus\Core\Catalog\Application\CatalogSettings;
use Mercatus\Core\Catalog\Application\MediaDirectory;
use Mercatus\Core\Catalog\Domain\Media;
use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Contracts\Identity\AccessDecision;
use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Contracts\Media\MediaQuota;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Shared\Audit\AuditLog;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Events\EventPublisher;
use Mercatus\Core\Shared\Http\ListRequest;
use Mercatus\Core\Shared\Persistence\TransactionRunner;
use Throwable;
use WP_Error;

/**
 * An upload is checked before anything is stored: its size, the vendor's space, and its type read from its
 * content. It's then saved again without its metadata (ImageSanitizer), stored through WordPress with the
 * extension of its real type, and recorded in the same request (CTL-14). Its bytes count the sizes WordPress
 * makes of it, so the space check runs again once they exist.
 */
final class MediaLibrary implements MediaDirectory {

	private const GROUP = 'mercatus_offers';

	private const TTL = 3600;

	private const MB = 1048576;

	/**
	 * Creates the service.
	 *
	 * @param MediaRepository   $media        Uploads.
	 * @param ImageSanitizer    $images       The image policy.
	 * @param MediaQuota        $quota        Each vendor's space.
	 * @param Products          $products     Products, which forget a deleted image.
	 * @param CatalogPolicy     $policy       Who may do what.
	 * @param TransactionRunner $transactions Transactions.
	 * @param EventPublisher    $events       Events.
	 * @param AuditLog          $audit        Audit log.
	 * @param CurrentActor      $actors       The current actor.
	 * @param SettingsReader    $settings     Settings.
	 * @param Clock             $clock        Clock.
	 * @param CachePort         $cache        Cache.
	 */
	public function __construct(
		private MediaRepository $media,
		private ImageSanitizer $images,
		private MediaQuota $quota,
		private Products $products,
		private CatalogPolicy $policy,
		private TransactionRunner $transactions,
		private EventPublisher $events,
		private AuditLog $audit,
		private CurrentActor $actors,
		private SettingsReader $settings,
		private Clock $clock,
		private CachePort $cache,
	) {}

	public function upload( array $file ): array {
		$vendor_id = $this->vendorId();
		$upload    = is_string( $file['tmp_name'] ?? null ) ? $file['tmp_name'] : '';
		if ( UPLOAD_ERR_OK !== ( $file['error'] ?? null ) || '' === $upload || ! is_file( $upload ) ) {
			throw DomainError::of( 'mercatus_media_upload_failed' );
		}
		$max = $this->settings->int( CatalogSettings::UPLOAD_MAX_MB ) * self::MB;
		if ( (int) filesize( $upload ) > $max ) {
			throw DomainError::of( 'mercatus_media_too_large', array( 'max_bytes' => $max ) );
		}
		$this->assertRoom( $vendor_id, (int) filesize( $upload ) );
		$mime = $this->images->type( $upload ) ?? throw DomainError::of( 'mercatus_media_type_refused', array( 'types' => $this->images->accepted() ) );
		if ( $this->images->pixels( $upload ) > ImageSanitizer::MAX_PIXELS ) {
			throw DomainError::of( 'mercatus_media_too_large', array( 'max_pixels' => ImageSanitizer::MAX_PIXELS ) );
		}

		// Work on a copy: the upload itself is never stored.
		$work = wp_tempnam( 'mercatus-media' );
		if ( ! copy( $upload, $work ) || ! $this->images->reencode( $work, $mime ) ) {
			wp_delete_file( $work );
			throw DomainError::of( 'mercatus_media_type_refused', array( 'types' => $this->images->accepted() ) );
		}
		$id = $this->store( $work, $mime, is_string( $file['name'] ?? null ) ? $file['name'] : '' );

		$meta   = wp_get_attachment_metadata( $id );
		$meta   = is_array( $meta ) ? $meta : array();
		$record = new Media( $id, $vendor_id, (string) ( $meta['file'] ?? '' ), self::thumbnail( $meta ), $mime, self::bytes( $id, $meta ), (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ), '' );
		try {
			$this->assertRoom( $vendor_id, $record->bytes );
			$this->transactions->run(
				function () use ( $record ): void {
					$this->media->insert( $record, $this->clock->now() );
					$this->events->publish(
						'catalog.media.uploaded',
						'media',
						$record->id,
						1,
						array(
							'attachment_id' => $record->id,
							'vendor_id'     => $record->vendorId,
							'bytes'         => $record->bytes,
						)
					);
					$this->audit->record( 'media.uploaded', 'media', $record->id, array( 'bytes' => $record->bytes ) );
				}
			);
		} catch ( Throwable $error ) {
			wp_delete_attachment( $id, true );
			throw $error;
		}
		$this->cache->bumpRevision( self::scope( $vendor_id ) );

		return $this->item( $this->media->byId( $id ) ?? $record );
	}

	public function mine( ListRequest $request ): array {
		return array_map(
			fn( Media $media ): array => array(
				'id'         => $media->id,
				'version'    => 1,
				'created_at' => $media->createdAt,
				'media'      => $this->item( $media ),
			),
			$this->media->page( $this->vendorId(), $request )
		);
	}

	public function get( int $id ): array {
		return $this->item( $this->allowed( $id, CatalogPolicy::VIEW ) );
	}

	public function delete( int $id ): array {
		$media = $this->allowed( $id, CatalogPolicy::EDIT );
		$item  = $this->item( $media );
		// WordPress calls forget() as it deletes; an upload whose attachment is already gone is forgotten here.
		wp_delete_attachment( $media->id, true );
		$this->forget( $media->id );

		return $item;
	}

	/**
	 * Forgets an upload WordPress is deleting, wherever the deletion started, the dashboard or WordPress's
	 * media library: it leaves its vendor's products and store, and stops counting against their space
	 * (CTL-14). An attachment no vendor uploaded, or one already forgotten, is left alone.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function forget( int $attachment_id ): void {
		$media = $this->media->byId( $attachment_id );
		if ( null === $media ) {
			return;
		}
		$this->products->forgetImage( $media->vendorId, $media->id );
		$this->transactions->run(
			function () use ( $media ): void {
				if ( ! $this->media->delete( $media->id ) ) {
					return;
				}
				$this->events->publish(
					'catalog.media.deleted',
					'media',
					$media->id,
					1,
					array(
						'attachment_id' => $media->id,
						'vendor_id'     => $media->vendorId,
					)
				);
				$this->audit->record( 'media.deleted', 'media', $media->id );
			}
		);
		$this->cache->bumpRevision( self::scope( $media->vendorId ) );
	}

	public function usage( int $vendor_id ): array {
		$used = $this->cache->remember(
			self::GROUP,
			'media-used:' . $vendor_id . ':r' . $this->cache->revision( self::scope( $vendor_id ) ),
			self::TTL,
			fn(): int => $this->media->usedBytes( $vendor_id )
		);

		return array(
			'used_bytes'       => $used,
			'quota_bytes'      => $this->quota->bytes( $vendor_id ),
			'upload_max_bytes' => $this->settings->int( CatalogSettings::UPLOAD_MAX_MB ) * self::MB,
			'types'            => $this->images->accepted(),
		);
	}

	/**
	 * Stores a sanitized image through WordPress and returns its attachment ID.
	 *
	 * @param string $work Sanitized file.
	 * @param string $mime Its type.
	 * @param string $name The name it was uploaded with.
	 * @throws DomainError When WordPress can't store it.
	 */
	private function store( string $work, string $mime, string $name ): int {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		// One extension, the real type's: no "map.php.jpg" for a server that runs any name with .php in it.
		$base      = str_replace( '.', '-', sanitize_file_name( (string) pathinfo( $name, PATHINFO_FILENAME ) ) );
		$extension = ImageSanitizer::TYPES[ $mime ];
		$sideload  = array(
			'name'     => ( '' === $base ? 'image' : $base ) . '.' . $extension,
			'tmp_name' => $work,
			'size'     => (int) filesize( $work ),
			'error'    => UPLOAD_ERR_OK,
			'type'     => $mime,
		);
		// Only the image's real type may be stored, whatever the site allows otherwise.
		$moved = wp_handle_sideload(
			$sideload,
			array(
				'test_form' => false,
				'mimes'     => array( $extension => $mime ),
			)
		);
		if ( isset( $moved['error'] ) ) {
			wp_delete_file( $work );
			throw DomainError::of( 'mercatus_media_upload_failed' );
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'     => '' === $base ? 'image' : $base,
				'post_content'   => '',
				'post_status'    => 'inherit',
				'post_author'    => $this->actors->identity()->userId,
				'guid'           => $moved['url'],
			),
			$moved['file'],
			0,
			true
		);
		if ( $id instanceof WP_Error ) {
			wp_delete_file( $moved['file'] );
			throw DomainError::of( 'mercatus_media_upload_failed' );
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $moved['file'] ) );

		return $id;
	}

	/**
	 * An upload the current actor may act on.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $action CatalogPolicy action.
	 * @throws DomainError When there's none they may (mercatus_media_unknown, a 404 rather than a 403: SEC-4).
	 */
	private function allowed( int $id, string $action ): Media {
		$media = $this->media->byId( $id );
		if ( null === $media || AccessDecision::Allowed !== $this->policy->decide( $this->actors->identity(), $action, $media ) ) {
			throw DomainError::of( 'mercatus_media_unknown' );
		}

		return $media;
	}

	/**
	 * Checks that bytes fit in a vendor's space.
	 *
	 * @param int $vendor_id Vendor.
	 * @param int $bytes     Bytes to add.
	 * @throws DomainError When they don't (mercatus_media_quota_exceeded).
	 */
	private function assertRoom( int $vendor_id, int $bytes ): void {
		$quota = $this->quota->bytes( $vendor_id );
		$used  = $this->media->usedBytes( $vendor_id );
		if ( $used + $bytes > $quota ) {
			throw DomainError::of(
				'mercatus_media_quota_exceeded',
				array(
					'quota_bytes' => $quota,
					'used_bytes'  => $used,
				)
			);
		}
	}

	/**
	 * A media item for the API.
	 *
	 * @param Media $media Upload.
	 * @return array<string, mixed>
	 */
	private function item( Media $media ): array {
		$base = (string) wp_get_upload_dir()['baseurl'];

		return array(
			'id'            => $media->id,
			'url'           => $base . '/' . $media->file,
			'thumbnail_url' => $base . '/' . ( '' === $media->thumbnail ? $media->file : $media->thumbnail ),
			'mime'          => $media->mime,
			'bytes'         => $media->bytes,
			'width'         => $media->width,
			'height'        => $media->height,
			'created_at'    => '' === $media->createdAt ? gmdate( DateTimeInterface::ATOM ) : str_replace( ' ', 'T', substr( $media->createdAt, 0, 19 ) ) . 'Z',
		);
	}

	/**
	 * An image's thumbnail path relative to the uploads folder, or ''.
	 *
	 * @param array<string, mixed> $meta Attachment metadata.
	 */
	private static function thumbnail( array $meta ): string {
		$thumbnail = $meta['sizes']['thumbnail']['file'] ?? null;
		if ( ! is_string( $thumbnail ) || ! is_string( $meta['file'] ?? null ) ) {
			return '';
		}
		$folder = dirname( $meta['file'] );

		return ( '.' === $folder ? '' : $folder . '/' ) . $thumbnail;
	}

	/**
	 * The bytes an image takes: its file, the original WordPress kept when it scaled a large one, and every
	 * size made of it.
	 *
	 * @param int                  $id   Attachment ID.
	 * @param array<string, mixed> $meta Attachment metadata.
	 */
	private static function bytes( int $id, array $meta ): int {
		$main  = (string) get_attached_file( $id );
		$files = array( $main );
		$kept  = wp_get_original_image_path( $id );
		if ( is_string( $kept ) && $kept !== $main ) {
			$files[] = $kept;
		}
		foreach ( is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array() as $size ) {
			if ( is_array( $size ) && is_string( $size['file'] ?? null ) ) {
				$files[] = dirname( $main ) . '/' . $size['file'];
			}
		}
		$total = 0;
		foreach ( array_unique( $files ) as $path ) {
			$total += is_file( $path ) ? (int) filesize( $path ) : 0;
		}

		return $total;
	}

	/**
	 * The current vendor.
	 *
	 * @throws DomainError When the actor isn't one.
	 */
	private function vendorId(): int {
		return $this->actors->identity()->vendorId ?? throw DomainError::of( 'mercatus_store_unknown' );
	}

	/**
	 * A vendor's media revision scope.
	 *
	 * @param int $vendor_id Vendor.
	 */
	private static function scope( int $vendor_id ): string {
		return 'catalog-media:' . $vendor_id;
	}
}

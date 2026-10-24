<?php
/**
 * Registration, review and the vendor lifecycle (VEN-1, VEN-3, VEN-11, VEN-12, VEN-17).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Vendor\Infrastructure;

use Mercatus\Core\Contracts\Cache\CachePort;
use Mercatus\Core\Contracts\Identity\AccessDecision;
use Mercatus\Core\Contracts\Identity\ActorType;
use Mercatus\Core\Contracts\Identity\CurrentActor;
use Mercatus\Core\Contracts\Identity\IdentityRevisions;
use Mercatus\Core\Contracts\Settings\SettingsReader;
use Mercatus\Core\Infrastructure\I18n\Multilingual;
use Mercatus\Core\Shared\Audit\AuditLog;
use Mercatus\Core\Shared\Clock\Clock;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Events\EventPublisher;
use Mercatus\Core\Shared\Http\ListRequest;
use Mercatus\Core\Shared\Persistence\TransactionRunner;
use Mercatus\Core\Vendor\Application\VendorDirectory;
use Mercatus\Core\Vendor\Application\VendorPolicy;
use Mercatus\Core\Vendor\Application\VendorSettings;
use Mercatus\Core\Vendor\Domain\ApplicationForm;
use Mercatus\Core\Vendor\Domain\ApplicationQuestion;
use Mercatus\Core\Vendor\Domain\Vendor;
use Mercatus\Core\Vendor\Domain\VendorApplication;
use Mercatus\Core\Vendor\Domain\VendorLifecycles;

/**
 * Every change publishes its event and writes its audit entry in one transaction; reasons and answers stay in
 * their tables, because the audit log holds no free text (SEC-7). Changes to who someone is bump their
 * identity right after commit (G-CACHE-4), and every change bumps the user's standing, which /me reads from the
 * cache (PLAT-10).
 */
final class Vendors implements VendorDirectory {

	/**
	 * Cache group for each user's standing, next to their identity (PLAT-3).
	 */
	private const STANDING_GROUP = 'mercatus_actors';

	/**
	 * Creates the service.
	 *
	 * @param ApplicationStore  $applications Attempts.
	 * @param VendorStore       $vendors      Vendors.
	 * @param VendorPolicy      $policy       Resource policy.
	 * @param TransactionRunner $transactions Transactions.
	 * @param EventPublisher    $events       Events.
	 * @param AuditLog          $audit        Audit log.
	 * @param CurrentActor      $actors       The current actor.
	 * @param IdentityRevisions $revisions    Identity revisions.
	 * @param SettingsReader    $settings     Settings.
	 * @param Clock             $clock        Current time.
	 * @param CachePort         $cache        Each user's standing, for /me.
	 * @param Multilingual      $languages    Translations of the administrator's questions.
	 */
	public function __construct(
		private ApplicationStore $applications,
		private VendorStore $vendors,
		private VendorPolicy $policy,
		private TransactionRunner $transactions,
		private EventPublisher $events,
		private AuditLog $audit,
		private CurrentActor $actors,
		private IdentityRevisions $revisions,
		private SettingsReader $settings,
		private Clock $clock,
		private CachePort $cache,
		private Multilingual $languages,
	) {}

	public function register( array $input ): array {
		$actor = $this->actors->identity();
		if ( $actor->isSignedIn() ) {
			$this->assertMayApply( $actor->userId, $actor->type );
		}
		$store_name = self::text( $input['store_name'] ?? null );
		if ( '' === $store_name || mb_strlen( $store_name ) > 191 ) {
			throw self::invalid( 'store_name' );
		}
		$answers  = is_array( $input['answers'] ?? null ) ? $input['answers'] : array();
		$answers += array(
			'phone'        => $input['phone'] ?? null,
			'message'      => $input['message'] ?? null,
			'accept_terms' => $input['accept_terms'] ?? null,
		);
		$snapshot = $this->form()->snapshot( $answers );

		$created = false;
		$user_id = $actor->userId;
		if ( ! $actor->isSignedIn() ) {
			$user_id = $this->createAccount( $input );
			$created = true;
		}
		$attempt = count( $this->applications->history( $user_id ) ) + 1;
		$id      = $this->transactions->run(
			function () use ( $user_id, $attempt, $store_name, $snapshot ): int {
				$id = $this->applications->insert( $user_id, $attempt, $store_name, $snapshot, $this->clock->now() );
				$this->events->publish(
					'vendor.application.submitted',
					'vendor_application',
					$id,
					1,
					array(
						'application_id' => $id,
						'user_id'        => $user_id,
						'attempt'        => $attempt,
					)
				);
				$this->audit->record( 'vendor_application.submitted', 'vendor_application', $id, array( 'attempt' => $attempt ) );

				return $id;
			}
		);
		$this->changed( $user_id );
		if ( $this->settings->bool( VendorSettings::AUTO_APPROVE ) ) {
			$this->approveAs( $this->find( $id ), 0 );
		}
		if ( $created ) {
			// Signed in the way WooCommerce's own registration does, so the dashboard can continue. REST requests
			// start no WooCommerce session, and older WooCommerce versions assume one here.
			if ( null === WC()->session ) {
				WC()->initialize_session();
			}
			wc_set_customer_auth_cookie( $user_id );
			wp_set_current_user( $user_id );
		}

		return array(
			'application'     => $this->find( $id )->toArray(),
			'account_created' => $created,
		);
	}

	public function applications( ListRequest $request ): array {
		$page = $this->applications->page( $request );
		cache_users( array_map( static fn( array $row ): int => $row['application']->userId, $page ) );

		return array_map(
			fn( array $row ): array => array(
				'id'          => $row['id'],
				'version'     => $row['version'],
				'application' => $row['application']->toArray() + array( 'applicant' => self::applicant( $row['application']->userId ) ),
			),
			$page
		);
	}

	public function application( int $id ): array {
		$application = $this->find( $id );
		if ( AccessDecision::Allowed !== $this->policy->decide( $this->actors->identity(), 'read', $application ) ) {
			throw DomainError::of( 'mercatus_vendor_application_unknown', array( 'application_id' => $id ) );
		}
		$earlier  = array_values( array_filter( $this->applications->history( $application->userId ), static fn( VendorApplication $attempt ): bool => $attempt->attempt < $application->attempt ) );
		$previous = end( $earlier );

		return $application->toArray() + array(
			'applicant' => self::applicant( $application->userId ),
			'history'   => array_map( static fn( VendorApplication $attempt ): array => $attempt->toArray(), array_reverse( $earlier ) ),
			'changes'   => false === $previous ? array() : ApplicationForm::changes( $previous, $application ),
		);
	}

	public function approve( int $id ): array {
		$application = $this->decidable( $id );
		$this->approveAs( $application, $this->actors->identity()->userId );

		return $this->find( $id )->toArray();
	}

	public function reject( int $id, string $reason ): array {
		$application = $this->decidable( $id );
		$reason      = self::reason( $reason );
		VendorLifecycles::application()->assert( $application->status, VendorLifecycles::REJECTED );
		$this->transactions->run(
			function () use ( $application, $reason ): void {
				if ( ! $this->applications->decide( $application->id, $application->version, VendorLifecycles::REJECTED, $reason, $this->actors->identity()->userId, $this->clock->now() ) ) {
					throw DomainError::of( 'mercatus_version_conflict', array( 'application_id' => $application->id ) );
				}
				$this->events->publish(
					'vendor.application.rejected',
					'vendor_application',
					$application->id,
					$application->version + 1,
					array(
						'application_id' => $application->id,
						'user_id'        => $application->userId,
					)
				);
				$this->audit->record( 'vendor_application.rejected', 'vendor_application', $application->id );
			}
		);
		$this->changed( $application->userId );

		return $this->find( $id )->toArray();
	}

	public function vendors( ListRequest $request ): array {
		$page = $this->vendors->page( $request );
		cache_users( array_map( static fn( array $row ): int => $row['vendor']->userId, $page ) );

		return array_map(
			static fn( array $row ): array => array(
				'id'      => $row['id'],
				'version' => $row['version'],
				'vendor'  => $row['vendor']->toArray() + array( 'user' => self::applicant( $row['vendor']->userId ) ),
			),
			$page
		);
	}

	public function suspend( int $id, string $reason ): array {
		return $this->transition( $id, VendorLifecycles::SUSPENDED, self::reason( $reason ), 'vendor.account.suspended' )->toArray();
	}

	public function reinstate( int $id ): array {
		return $this->transition( $id, VendorLifecycles::ACTIVE, null, 'vendor.account.reinstated' )->toArray();
	}

	public function standing( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array(
				'vendor'      => null,
				'application' => null,
			);
		}
		$key = 'vendor_standing:u' . $user_id . ':r' . $this->cache->revision( self::standingScope( $user_id ) );

		return $this->cache->remember( self::STANDING_GROUP, $key, 300, fn(): array => $this->freshStanding( $user_id ) );
	}

	/**
	 * A user's standing from the tables: two indexed queries.
	 *
	 * @param int $user_id User.
	 * @return array{vendor: array{id: int, status: string}|null, application: array{id: int, attempt: int, status: string, reason: string|null}|null}
	 */
	private function freshStanding( int $user_id ): array {
		$vendor  = $this->vendors->byUserId( $user_id );
		$history = $this->applications->history( $user_id );
		$latest  = end( $history );

		return array(
			'vendor'      => null === $vendor ? null : array(
				'id'     => $vendor->id,
				'status' => $vendor->status,
			),
			'application' => false === $latest ? null : array(
				'id'      => $latest->id,
				'attempt' => $latest->attempt,
				'status'  => $latest->status,
				'reason'  => $latest->reason,
			),
		);
	}

	/**
	 * Erases a user's personal data where nothing depends on it (VEN-9, SEC-7): the store names, answers and
	 * reasons of attempts that were rejected or withdrawn. A pending attempt waits for its decision, and a
	 * vendor's approved attempt and vendor row stay, because orders and money refer to them. Repeating it
	 * removes nothing more.
	 *
	 * @param int $user_id User.
	 * @return array{removed: int, pending: bool, vendor: bool} How many attempts were anonymised, and what was kept.
	 */
	public function erasePersonalData( int $user_id ): array {
		$deleted  = wp_privacy_anonymize_data( 'text' );
		$history  = $this->applications->history( $user_id );
		$erasable = array_filter( $history, static fn( VendorApplication $attempt ): bool => in_array( $attempt->status, array( VendorLifecycles::REJECTED, VendorLifecycles::WITHDRAWN ), true ) && $attempt->storeName !== $deleted );
		$removed  = 0;
		if ( array() !== $erasable ) {
			$removed = $this->transactions->run(
				function () use ( $erasable, $deleted ): int {
					$removed = 0;
					foreach ( $erasable as $attempt ) {
						$answers = array_map( static fn( array $answer ): array => array( 'answer' => null ) + $answer, $attempt->answers );
						if ( $this->applications->anonymise( $attempt->id, $deleted, $answers, $this->clock->now() ) ) {
							$this->audit->record( 'vendor_application.anonymised', 'vendor_application', $attempt->id );
							++$removed;
						}
					}

					return $removed;
				}
			);
			$this->changed( $user_id );
		}

		return array(
			'removed' => $removed,
			'pending' => array() !== array_filter( $history, static fn( VendorApplication $attempt ): bool => VendorLifecycles::PENDING === $attempt->status ),
			'vendor'  => null !== $this->vendors->byUserId( $user_id ),
		);
	}

	/**
	 * Closes a deleted user's vendor and withdraws their pending application (VEN-1).
	 *
	 * @param int $user_id The deleted user.
	 */
	public function close( int $user_id ): void {
		$vendor  = $this->vendors->byUserId( $user_id );
		$history = $this->applications->history( $user_id );
		$latest  = end( $history );
		$this->transactions->run(
			function () use ( $vendor, $latest ): void {
				$now = $this->clock->now();
				if ( null !== $vendor && VendorLifecycles::CLOSED !== $vendor->status && $this->vendors->transition( $vendor->id, $vendor->version, VendorLifecycles::CLOSED, null, $now ) ) {
					$this->events->publish( 'vendor.account.closed', 'vendor', $vendor->id, $vendor->version + 1, self::vendorPayload( $vendor ) );
					$this->audit->record( 'vendor.closed', 'vendor', $vendor->id );
				}
				if ( false !== $latest && VendorLifecycles::PENDING === $latest->status ) {
					$this->applications->decide( $latest->id, $latest->version, VendorLifecycles::WITHDRAWN, null, 0, $now );
				}
			}
		);
		$this->revisions->bump( $user_id );
		$this->changed( $user_id );
	}

	/**
	 * Approves an attempt and creates the vendor (VEN-1, VEN-5).
	 *
	 * @param VendorApplication $application Attempt.
	 * @param int               $decided_by  Who approves; 0 for automatic approval.
	 * @throws DomainError When it isn't pending, the user is already a vendor, or it changed meanwhile.
	 */
	private function approveAs( VendorApplication $application, int $decided_by ): void {
		VendorLifecycles::application()->assert( $application->status, VendorLifecycles::APPROVED );
		if ( null !== $this->vendors->byUserId( $application->userId ) ) {
			throw DomainError::of( 'mercatus_already_vendor', array( 'user_id' => $application->userId ) );
		}
		$this->transactions->run(
			function () use ( $application, $decided_by ): void {
				$now = $this->clock->now();
				if ( ! $this->applications->decide( $application->id, $application->version, VendorLifecycles::APPROVED, null, $decided_by, $now ) ) {
					throw DomainError::of( 'mercatus_version_conflict', array( 'application_id' => $application->id ) );
				}
				$vendor_id = $this->vendors->insert( $application->userId, $application->id, $now );
				$this->events->publish(
					'vendor.application.approved',
					'vendor_application',
					$application->id,
					$application->version + 1,
					array(
						'application_id' => $application->id,
						'user_id'        => $application->userId,
						'vendor_id'      => $vendor_id,
						'store_name'     => $application->storeName,
					)
				);
				$this->audit->record( 'vendor_application.approved', 'vendor_application', $application->id, array( 'vendor_id' => $vendor_id ) );
			}
		);
		$this->revisions->bump( $application->userId );
		$this->changed( $application->userId );
	}

	/**
	 * Moves a vendor through its lifecycle, inside the actor's scope (IDN-15).
	 *
	 * @param int         $id     Vendor ID.
	 * @param string      $status New status.
	 * @param string|null $reason Reason, for a suspension.
	 * @param string      $event  Event name.
	 * @throws DomainError When the vendor is unknown or out of scope, the move isn't allowed, or it changed meanwhile.
	 */
	private function transition( int $id, string $status, ?string $reason, string $event ): Vendor {
		$vendor = $this->vendors->byId( $id );
		if ( null === $vendor || AccessDecision::Allowed !== $this->policy->decide( $this->actors->identity(), 'manage', $vendor ) ) {
			throw DomainError::of( 'mercatus_vendor_unknown', array( 'vendor_id' => $id ) );
		}
		VendorLifecycles::vendor()->assert( $vendor->status, $status );
		$this->transactions->run(
			function () use ( $vendor, $status, $reason, $event ): void {
				if ( ! $this->vendors->transition( $vendor->id, $vendor->version, $status, $reason, $this->clock->now() ) ) {
					throw DomainError::of( 'mercatus_version_conflict', array( 'vendor_id' => $vendor->id ) );
				}
				$this->events->publish( $event, 'vendor', $vendor->id, $vendor->version + 1, self::vendorPayload( $vendor ) );
				$this->audit->record( 'vendor.' . $status, 'vendor', $vendor->id );
			}
		);
		$this->revisions->bump( $vendor->userId );
		$this->changed( $vendor->userId );

		return $this->vendors->byId( $id ) ?? $vendor;
	}

	/**
	 * Makes the next /me read a user's standing afresh.
	 *
	 * @param int $user_id User.
	 */
	private function changed( int $user_id ): void {
		$this->cache->bumpRevision( self::standingScope( $user_id ) );
	}

	/**
	 * A user's standing revision scope.
	 *
	 * @param int $user_id User.
	 */
	private static function standingScope( int $user_id ): string {
		return 'vendor_standing:' . $user_id;
	}

	/**
	 * Refuses users who may not apply (VEN-12).
	 *
	 * @param int        $user_id User.
	 * @param ActorType  $type    Their actor type.
	 * @throws DomainError When they're a vendor, have a pending attempt, or rank above a customer.
	 */
	private function assertMayApply( int $user_id, ActorType $type ): void {
		if ( null !== $this->vendors->byUserId( $user_id ) ) {
			throw DomainError::of( 'mercatus_already_vendor', array( 'user_id' => $user_id ) );
		}
		if ( ActorType::Customer !== $type ) {
			throw DomainError::of( 'mercatus_vendor_application_not_allowed', array( 'actor_type' => $type->value ) );
		}
		$history = $this->applications->history( $user_id );
		$latest  = end( $history );
		if ( false !== $latest && VendorLifecycles::PENDING === $latest->status ) {
			throw DomainError::of( 'mercatus_application_pending', array( 'application_id' => $latest->id ) );
		}
	}

	/**
	 * Creates a visitor's account through WooCommerce, so its password settings, its new-account email and the
	 * anti-spam plugins on `woocommerce_registration_errors` all apply (ARCH-12, VEN-12).
	 *
	 * @param array<string, mixed> $input Account fields.
	 * @throws DomainError When a field isn't valid, the email is taken, or WooCommerce refuses.
	 */
	private function createAccount( array $input ): int {
		$email    = self::text( $input['email'] ?? null );
		$password = is_string( $input['password'] ?? null ) ? $input['password'] : '';
		if ( ! is_email( $email ) ) {
			throw self::invalid( 'email' );
		}
		if ( email_exists( $email ) ) {
			throw DomainError::of( 'mercatus_email_registered' );
		}
		if ( '' === $password && 'yes' !== get_option( 'woocommerce_registration_generate_password' ) ) {
			throw self::invalid( 'password' );
		}
		$user_id = wc_create_new_customer(
			$email,
			'',
			$password,
			array(
				'first_name' => self::text( $input['first_name'] ?? null ),
				'last_name'  => self::text( $input['last_name'] ?? null ),
				'source'     => 'mercatus-vendor-registration',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw DomainError::of( 'mercatus_registration_refused', array( 'reason' => (string) $user_id->get_error_code() ) );
		}

		return (int) $user_id;
	}

	public function registrationForm(): array {
		$questions = array();
		foreach ( $this->form()->questions as $question ) {
			$custom      = ! in_array( $question->id, ApplicationForm::RESERVED, true );
			$questions[] = array(
				'id'       => $question->id,
				'label'    => $custom ? $this->languages->translate( self::stringName( $question->id ), $question->label ) : $question->label,
				'type'     => $question->type,
				'required' => $question->required,
				'choices'  => array_map(
					fn( string $choice ): array => array(
						'value' => $choice,
						'label' => $this->languages->translate( self::stringName( $question->id, $choice ), $choice ),
					),
					$question->choices
				),
			);
		}
		$terms = $this->settings->int( VendorSettings::TERMS_PAGE );
		$link  = $terms > 0 ? get_permalink( $this->languages->inCurrentLanguage( $terms ) ) : false;

		return array(
			'questions'         => $questions,
			'terms'             => false === $link ? null : array(
				'id'    => $terms,
				'title' => get_the_title( $this->languages->inCurrentLanguage( $terms ) ),
				'url'   => $link,
			),
			'generate_password' => 'yes' === get_option( 'woocommerce_registration_generate_password' ),
		);
	}

	/**
	 * Registers the administrator's questions and choices with the multilingual plugin, so they can be
	 * translated (VEN-15).
	 */
	public function registerStrings(): void {
		foreach ( ApplicationForm::fromJson( $this->settings->string( VendorSettings::APPLICATION_FIELDS ) )->questions as $question ) {
			$this->languages->register( self::stringName( $question->id ), $question->label );
			foreach ( $question->choices as $choice ) {
				$this->languages->register( self::stringName( $question->id, $choice ), $choice );
			}
		}
	}

	/**
	 * The translatable name of a question's label or of one of its choices.
	 *
	 * @param string      $question_id Question ID.
	 * @param string|null $choice      Choice, or null for the label.
	 */
	private static function stringName( string $question_id, ?string $choice = null ): string {
		return 'core.vendor.question.' . $question_id . ( null === $choice ? '' : '.choice.' . substr( md5( $choice ), 0, 8 ) );
	}

	/**
	 * The form in force: the fixed fields, the terms when a page is set, and the administrator's questions.
	 */
	private function form(): ApplicationForm {
		$fixed = array(
			new ApplicationQuestion( 'phone', __( 'Contact phone', 'mercatus' ), 'text' ),
			new ApplicationQuestion( 'message', __( 'Message', 'mercatus' ), 'textarea' ),
		);
		if ( $this->settings->int( VendorSettings::TERMS_PAGE ) > 0 ) {
			$fixed[] = new ApplicationQuestion( 'accept_terms', __( 'I accept the marketplace terms', 'mercatus' ), 'checkbox', true );
		}

		return new ApplicationForm( array_merge( $fixed, ApplicationForm::fromJson( $this->settings->string( VendorSettings::APPLICATION_FIELDS ) )->questions ) );
	}

	/**
	 * An attempt the current actor may decide.
	 *
	 * @param int $id Attempt ID.
	 * @throws DomainError When it doesn't exist or the actor can't review.
	 */
	private function decidable( int $id ): VendorApplication {
		$application = $this->find( $id );
		if ( AccessDecision::Allowed !== $this->policy->decide( $this->actors->identity(), 'review', $application ) ) {
			throw DomainError::of( 'mercatus_vendor_application_unknown', array( 'application_id' => $id ) );
		}

		return $application;
	}

	/**
	 * An attempt by ID.
	 *
	 * @param int $id Attempt ID.
	 * @throws DomainError When it doesn't exist.
	 */
	private function find( int $id ): VendorApplication {
		return $this->applications->byId( $id ) ?? throw DomainError::of( 'mercatus_vendor_application_unknown', array( 'application_id' => $id ) );
	}

	/**
	 * Who applied, for reviewers.
	 *
	 * @param int $user_id User.
	 * @return array{user_id: int, display_name: string, email: string}
	 */
	private static function applicant( int $user_id ): array {
		$user = get_userdata( $user_id );

		return array(
			'user_id'      => $user_id,
			'display_name' => false === $user ? '' : $user->display_name,
			'email'        => false === $user ? '' : $user->user_email,
		);
	}

	/**
	 * A vendor event's payload.
	 *
	 * @param Vendor $vendor Vendor.
	 * @return array<string, int>
	 */
	private static function vendorPayload( Vendor $vendor ): array {
		return array(
			'vendor_id' => $vendor->id,
			'user_id'   => $vendor->userId,
		);
	}

	/**
	 * A validated reason.
	 *
	 * @param string $reason Reason.
	 * @throws DomainError When it's empty or too long.
	 */
	private static function reason( string $reason ): string {
		$reason = trim( $reason );
		if ( '' === $reason || mb_strlen( $reason ) > 1000 ) {
			throw DomainError::of( 'mercatus_reason_required' );
		}

		return $reason;
	}

	/**
	 * A trimmed string from input.
	 *
	 * @param mixed $value Value.
	 */
	private static function text( mixed $value ): string {
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * The error for a field that isn't valid.
	 *
	 * @param string $field Field.
	 */
	private static function invalid( string $field ): DomainError {
		return DomainError::of( 'mercatus_vendor_registration_invalid', array( 'field' => $field ) );
	}
}

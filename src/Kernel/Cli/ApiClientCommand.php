<?php
/**
 * WP-CLI commands for API clients (IDN-18).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Kernel\Cli;

use Mercatus\Core\Identity\Infrastructure\ApiClients;
use Mercatus\Core\Identity\Infrastructure\ApiClientStore;
use Mercatus\Core\Kernel\Kernel;
use Mercatus\Core\Shared\Errors\DomainError;
use Mercatus\Core\Shared\Http\ListRequest;

/**
 * `wp mercatus api-client create|list|revoke|password`, over the same commands as the REST endpoints, so
 * integrations can be set up before the dashboard shell exists.
 */
final class ApiClientCommand {

	/**
	 * Creates the command.
	 *
	 * @param Kernel $kernel Booted kernel.
	 */
	public function __construct( private Kernel $kernel ) {}

	/**
	 * Registers an API client and prints its application ID, which isn't shown again.
	 *
	 * ## OPTIONS
	 *
	 * --name=<name>
	 * : Name.
	 *
	 * --kind=<kind>
	 * : integration or app.
	 *
	 * [--user=<id>]
	 * : The user an integration acts as.
	 *
	 * --scopes=<scopes>
	 * : Comma-separated: read, write and permission names.
	 *
	 * [--ips=<ranges>]
	 * : Comma-separated addresses or CIDR ranges allowed to use it.
	 *
	 * [--lifetime=<days>]
	 * : Days a password works after it's created; 0 for no limit.
	 * ---
	 * default: 0
	 * ---
	 *
	 * @param list<string>          $args       Arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function create( array $args, array $assoc_args ): void {
		$kind = $assoc_args['kind'] ?? '';
		if ( ! in_array( $kind, array( 'integration', 'app' ), true ) ) {
			\WP_CLI::error( '--kind must be integration or app.' );
		}
		$user                      = 'integration' === $kind ? (int) ( $assoc_args['user'] ?? 0 ) : null;
		list( $client, , $app_id ) = $this->run(
			fn(): array => $this->clients()->register( $assoc_args['name'] ?? '', $user, self::list( $assoc_args['scopes'] ?? '' ), self::list( $assoc_args['ips'] ?? '' ), (int) ( $assoc_args['lifetime'] ?? 0 ) )
		);
		\WP_CLI::success( sprintf( 'Registered API client %d. Application ID: %s', (int) $client['id'], $app_id ) );
	}

	/**
	 * Lists API clients.
	 */
	public function listClients(): void {
		foreach ( $this->kernel->container()->get( ApiClientStore::class )->page( new ListRequest( 'id', 'asc', 100 ) ) as $row ) {
			$client = $row['client'];
			\WP_CLI::log( sprintf( '%d %s %s %s [%s]', $client->id, $client->kind(), $client->status, $client->name, implode( ', ', $client->scopes ) ) );
		}
	}

	/**
	 * Revokes an API client.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Client ID.
	 *
	 * @param list<string> $args Arguments.
	 */
	public function revoke( array $args ): void {
		$id = (int) ( $args[0] ?? 0 );
		$this->run( fn(): array => $this->clients()->revoke( $id ) );
		\WP_CLI::success( sprintf( 'API client %d is revoked.', $id ) );
	}

	/**
	 * Creates an integration's application password and prints it once.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Client ID.
	 *
	 * @param list<string> $args Arguments.
	 */
	public function password( array $args ): void {
		list( $password ) = $this->run( fn(): array => $this->clients()->createPassword( (int) ( $args[0] ?? 0 ) ) );
		\WP_CLI::success( 'Application password (shown once): ' . $password );
	}

	/**
	 * Runs a command, reporting a domain error as a CLI error.
	 *
	 * @template T
	 * @param callable(): T $command Command.
	 * @return T
	 */
	private function run( callable $command ): mixed {
		try {
			return $command();
		} catch ( DomainError $error ) {
			\WP_CLI::error( $error->errorCode . ( array() === $error->details ? '' : ' ' . (string) wp_json_encode( $error->details ) ) );
		}
	}

	/**
	 * The API-client commands.
	 */
	private function clients(): ApiClients {
		return $this->kernel->container()->get( ApiClients::class );
	}

	/**
	 * A comma-separated option as a list.
	 *
	 * @param string $value Option value.
	 * @return list<string>
	 */
	private static function list( string $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), static fn( string $item ): bool => '' !== $item ) );
	}
}

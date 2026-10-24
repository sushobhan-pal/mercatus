<?php
/**
 * The few WP-CLI symbols Mercatus uses, for PHPStan only. php-stubs/wp-cli-stubs requires WordPress stubs
 * below 7.x, which Mercatus can't use. Never loaded at runtime.
 */

class WP_CLI {

	/**
	 * @param string   $name     Command name.
	 * @param callable $callable Handler.
	 * @param array<string, mixed> $args Registration arguments.
	 */
	public static function add_command( string $name, $callable, array $args = array() ): bool {
		return true;
	}

	public static function success( string $message ): void {}

	public static function log( string $message ): void {}

	/**
	 * @param string|\WP_Error $message Message.
	 */
	public static function error( $message, bool $exit = true ): never {
		exit( 1 );
	}
}

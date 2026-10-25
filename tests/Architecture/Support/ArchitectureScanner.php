<?php
/**
 * Static checks for the Layer 1 rules Deptrac can't express (TEST-1).
 *
 * @package Mercatus\Core\Tests
 */

declare( strict_types=1 );

namespace Mercatus\Core\Tests\Architecture\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Parses PHP files and reports rule violations as "relative/path.php:line message" strings.
 */
final class ArchitectureScanner {

	/**
	 * Request superglobals that Domain and Application code must not read (G-BND-2).
	 */
	private const REQUEST_SUPERGLOBALS = array( '_GET', '_POST', '_REQUEST', '_SERVER', '_COOKIE', '_FILES' );

	/**
	 * WordPress translation functions; domain code returns error codes instead (ARCH-7).
	 */
	private const TRANSLATION_FUNCTIONS = array(
		'__',
		'_e',
		'_x',
		'_ex',
		'_n',
		'_nx',
		'_n_noop',
		'_nx_noop',
		'esc_html__',
		'esc_html_e',
		'esc_html_x',
		'esc_attr__',
		'esc_attr_e',
		'esc_attr_x',
		'translate',
		'translate_nooped_plural',
	);

	/**
	 * Cache client classes that only WordPress's object cache may use (PLAT-3).
	 */
	private const CACHE_CLIENT_CLASSES = array( 'Redis', 'RedisArray', 'RedisCluster', 'Memcache', 'Memcached' );

	/**
	 * PHP parser for the newest supported syntax.
	 *
	 * @var Parser
	 */
	private Parser $parser;

	/**
	 * Node finder shared by all checks.
	 *
	 * @var NodeFinder
	 */
	private NodeFinder $finder;

	/**
	 * Creates the parser and node finder.
	 */
	public function __construct() {
		$this->parser = ( new ParserFactory() )->createForNewestSupportedVersion();
		$this->finder = new NodeFinder();
	}

	/**
	 * Request input, responses, output, translation and $wpdb use in layers that must stay framework-free
	 * (G-BND-1, G-BND-2). Api code may read requests but never $wpdb.
	 *
	 * @param string $src_dir Source root to scan.
	 * @return list<string>
	 */
	public function runtimeLeaks( string $src_dir ): array {
		$violations = array();

		foreach ( $this->parsedFiles( $src_dir ) as $path => $nodes ) {
			$layer = $this->layerOf( $path );
			if ( null === $layer ) {
				continue;
			}

			foreach ( $this->finder->findInstanceOf( $nodes, Node::class ) as $node ) {
				$message = 'Api' === $layer ? $this->wpdbUse( $node ) : $this->frameworkLeak( $node );
				if ( null !== $message ) {
					$violations[] = $this->format( $src_dir, $path, $node, $layer . ' layer ' . $message );
				}
			}
		}

		return $violations;
	}

	/**
	 * References from Core to Pro classes (G-BND-3).
	 *
	 * @param string $src_dir Source root to scan.
	 * @return list<string>
	 */
	public function proReferences( string $src_dir ): array {
		return $this->nameReferences(
			$src_dir,
			static fn ( string $name ): bool => str_starts_with( $name, 'Mercatus\\Pro\\' ),
			'references Pro class'
		);
	}

	/**
	 * Direct Redis, Memcached or Predis client use (PLAT-3, G-CACHE-2).
	 *
	 * @param string $src_dir Source root to scan.
	 * @return list<string>
	 */
	public function cacheClientReferences( string $src_dir ): array {
		return $this->nameReferences(
			$src_dir,
			static fn ( string $name ): bool => in_array( $name, self::CACHE_CLIENT_CLASSES, true ) || str_starts_with( $name, 'Predis\\' ),
			'uses cache client'
		);
	}

	/**
	 * HTTP calls that bypass the outbound allow-list (SEC-7, TM-31): only Infrastructure/Http/OutboundHttp.php
	 * may make them.
	 *
	 * @param string $src_dir Source root to scan.
	 * @return list<string>
	 */
	public function outboundCalls( string $src_dir ): array {
		$violations = array();
		foreach ( $this->parsedFiles( $src_dir ) as $path => $nodes ) {
			if ( str_ends_with( str_replace( '\\', '/', $path ), 'Infrastructure/Http/OutboundHttp.php' ) ) {
				continue;
			}
			foreach ( $this->finder->findInstanceOf( $nodes, Node\Expr\FuncCall::class ) as $call ) {
				if ( ! $call->name instanceof Node\Name ) {
					continue;
				}
				$function = $call->name->toLowerString();
				if ( 1 === preg_match( '/^(wp_remote_|wp_safe_remote_|curl_)/', $function ) || in_array( $function, array( 'fsockopen', 'stream_socket_client' ), true ) ) {
					$violations[] = $this->format( $src_dir, $path, $call, 'calls ' . $function . '() outside OutboundHttp' );
				}
			}
		}

		return $violations;
	}

	/**
	 * Command handlers and policies without a matching *Test.php file (TEST-1, Layer 1).
	 *
	 * @param string $src_dir   Source root to scan.
	 * @param string $tests_dir Test root to search for matching test files.
	 * @return list<string>
	 */
	public function untestedHandlersAndPolicies( string $src_dir, string $tests_dir ): array {
		$test_files = array();
		foreach ( $this->phpFiles( $tests_dir ) as $path ) {
			$test_files[ basename( $path ) ] = true;
		}

		$violations = array();
		foreach ( $this->parsedFiles( $src_dir ) as $path => $nodes ) {
			foreach ( $this->finder->findInstanceOf( $nodes, Node\Stmt\Class_::class ) as $class ) {
				$name = $class->name?->toString() ?? '';
				if ( ! preg_match( '/(Handler|Policy)$/', $name ) || isset( $test_files[ $name . 'Test.php' ] ) ) {
					continue;
				}
				$violations[] = $this->format( $src_dir, $path, $class, $name . ' has no ' . $name . 'Test.php' );
			}
		}

		return $violations;
	}

	/**
	 * Describes a request, response, output, translation or $wpdb leak, or returns null.
	 *
	 * @param Node $node Node to inspect.
	 */
	private function frameworkLeak( Node $node ): ?string {
		if ( $node instanceof Node\Expr\Variable && is_string( $node->name ) && in_array( $node->name, self::REQUEST_SUPERGLOBALS, true ) ) {
			return 'reads $' . $node->name;
		}

		if ( $node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name ) {
			$function = $node->name->toLowerString();
			if ( str_starts_with( $function, 'wp_send_json' ) ) {
				return 'calls ' . $function . '()';
			}
			if ( in_array( $function, self::TRANSLATION_FUNCTIONS, true ) ) {
				return 'calls translation function ' . $function . '()';
			}
		}

		if ( $node instanceof Node\Stmt\Echo_ || $node instanceof Node\Expr\Print_ || $node instanceof Node\Stmt\InlineHTML ) {
			return 'outputs directly';
		}

		return $this->wpdbUse( $node );
	}

	/**
	 * Describes use of the $wpdb global, or returns null.
	 *
	 * @param Node $node Node to inspect.
	 */
	private function wpdbUse( Node $node ): ?string {
		if ( $node instanceof Node\Expr\Variable && 'wpdb' === $node->name ) {
			return 'uses $wpdb';
		}

		if (
			$node instanceof Node\Expr\ArrayDimFetch
			&& $node->var instanceof Node\Expr\Variable
			&& 'GLOBALS' === $node->var->name
			&& $node->dim instanceof Node\Scalar\String_
			&& 'wpdb' === $node->dim->value
		) {
			return 'uses $GLOBALS[\'wpdb\']';
		}

		return null;
	}

	/**
	 * Reports resolved class names that match a predicate.
	 *
	 * @param string                  $src_dir Source root to scan.
	 * @param callable(string): bool $matches Predicate on the fully qualified name.
	 * @param string                  $message Violation description.
	 * @return list<string>
	 */
	private function nameReferences( string $src_dir, callable $matches, string $message ): array {
		$violations = array();

		foreach ( $this->parsedFiles( $src_dir ) as $path => $nodes ) {
			foreach ( $this->finder->findInstanceOf( $nodes, Node\Name::class ) as $name ) {
				$fqcn = ltrim( $name->toString(), '\\' );
				if ( $matches( $fqcn ) ) {
					$violations[] = $this->format( $src_dir, $path, $name, $message . ' ' . $fqcn );
				}
			}
		}

		return $violations;
	}

	/**
	 * Returns Domain, Application or Api for files in those layers, otherwise null (ARCH-2).
	 *
	 * @param string $path File path.
	 */
	private function layerOf( string $path ): ?string {
		return preg_match( '#/(Domain|Application|Api)/#', $path, $match ) ? $match[1] : null;
	}

	/**
	 * Parses every PHP file under a directory, with names resolved to fully qualified form.
	 *
	 * @param string $dir Directory to scan.
	 * @return array<string, array<Node>>
	 */
	private function parsedFiles( string $dir ): array {
		$parsed    = array();
		$traverser = new NodeTraverser( new NameResolver() );

		foreach ( $this->phpFiles( $dir ) as $path ) {
			$nodes = $this->parser->parse( (string) file_get_contents( $path ) ) ?? array();

			$parsed[ $path ] = $traverser->traverse( $nodes );
		}

		return $parsed;
	}

	/**
	 * Lists PHP files under a directory, sorted; an absent directory has none.
	 *
	 * @param string $dir Directory to scan.
	 * @return list<string>
	 */
	private function phpFiles( string $dir ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$files = array();
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}
		sort( $files );

		return $files;
	}

	/**
	 * Formats a violation as "relative/path.php:line message".
	 *
	 * @param string $root    Root the path is reported relative to.
	 * @param string $path    File path.
	 * @param Node   $node    Offending node.
	 * @param string $message Violation description.
	 */
	private function format( string $root, string $path, Node $node, string $message ): string {
		return ltrim( substr( $path, strlen( rtrim( $root, '/' ) ) ), '/' ) . ':' . $node->getStartLine() . ' ' . $message;
	}
}

<?php
/**
 * Compiles the dashboard menu for an actor (ARCH-13, IDN-12).
 *
 * @package Mercatus\Core
 */

declare( strict_types=1 );

namespace Mercatus\Core\Identity\Application;

use Mercatus\Core\Contracts\Identity\ActorIdentity;
use Mercatus\Core\Contracts\Identity\MenuItem;
use Mercatus\Core\Identity\Domain\AccessDecision;
use Mercatus\Core\Identity\Domain\PolicyEngine;

/**
 * Keeps the items the policy engine allows, sorted by position, and nests them under their parents. An item
 * under a hidden or missing parent is hidden too; a duplicate ID keeps the earlier module's item.
 */
final class DashboardMenuCompiler {

	/**
	 * Creates the compiler.
	 *
	 * @param ModuleExtensions $modules Active modules' menu providers.
	 */
	public function __construct( private ModuleExtensions $modules ) {}

	/**
	 * The menu of the active modules for an actor.
	 *
	 * @param ActorIdentity $actor Actor.
	 * @return list<array<string, mixed>>
	 */
	public function compile( ActorIdentity $actor ): array {
		$items = array();
		foreach ( $this->modules->menuProviders() as $provider ) {
			array_push( $items, ...$provider->items() );
		}

		return self::tree( $actor, $items );
	}

	/**
	 * The menu of some items for an actor.
	 *
	 * @param ActorIdentity  $actor Actor.
	 * @param list<MenuItem> $items Items.
	 * @return list<array<string, mixed>>
	 */
	public static function tree( ActorIdentity $actor, array $items ): array {
		$visible = array();
		foreach ( $items as $item ) {
			if ( ! isset( $visible[ $item->id ] ) && AccessDecision::Allowed === PolicyEngine::access( $actor, $item->access, $item->permission ) ) {
				$visible[ $item->id ] = $item;
			}
		}
		usort( $visible, static fn( MenuItem $a, MenuItem $b ): int => array( $a->position, $a->id ) <=> array( $b->position, $b->id ) );

		$children = array();
		foreach ( $visible as $item ) {
			$children[ $item->parentId ?? '' ][] = $item;
		}

		return self::branch( $children, '' );
	}

	/**
	 * The items under a parent, with theirs.
	 *
	 * @param array<string, list<MenuItem>> $children  Items by parent ID; '' for the top level.
	 * @param string                        $parent_id Parent ID.
	 * @return list<array<string, mixed>>
	 */
	private static function branch( array $children, string $parent_id ): array {
		$nodes = array();
		foreach ( $children[ $parent_id ] ?? array() as $item ) {
			$nodes[] = array(
				'id'       => $item->id,
				'label'    => $item->label,
				'screen'   => $item->screen,
				'children' => self::branch( $children, $item->id ),
			);
		}

		return $nodes;
	}
}

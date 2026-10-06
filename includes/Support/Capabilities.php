<?php
/**
 * WearGraph capabilities.
 *
 * @package WearGraph
 */

namespace WearGraph\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Custom capabilities so a store can delegate studio work without handing over settings.
 */
final class Capabilities {

	public const MANAGE       = 'weargraph_manage';
	public const STUDIO       = 'weargraph_studio';
	public const VIEW_REPORTS = 'weargraph_view_reports';

	private const ROLES = array( 'administrator', 'shop_manager' );

	/**
	 * Every WearGraph capability.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::MANAGE, self::STUDIO, self::VIEW_REPORTS );
	}

	/**
	 * Grant all capabilities to the default roles.
	 */
	public static function grant(): void {
		foreach ( self::ROLES as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all() as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove WearGraph capabilities from every role (uninstall).
	 */
	public static function revoke(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}

<?php
namespace Consolida;

/**
 * Roles y capacidades (§14) y quién ve qué empresa.
 *
 * - csl_corporativo: csl_access, csl_corp, csl_board.
 * - csl_pais:        csl_access, csl_country (solo sus empresas en company_users).
 * - csl_directiva:   csl_access, csl_board.
 * - administrator:   todas (csl_access, csl_corp, csl_board, csl_country, csl_admin).
 *
 * Los roles del plugin entran solo a /consolida/: tras el login van ahí y no pueden abrir wp-admin.
 */
class Roles {
	const CAP_ACCESS  = 'csl_access';
	const CAP_CORP    = 'csl_corp';
	const CAP_BOARD   = 'csl_board';
	const CAP_COUNTRY = 'csl_country';
	const CAP_ADMIN   = 'csl_admin';

	const ROLES = array(
		'csl_corporativo' => array( 'Consolida · Corporativo', array( 'csl_access', 'csl_corp', 'csl_board' ) ),
		'csl_pais'        => array( 'Consolida · País', array( 'csl_access', 'csl_country' ) ),
		'csl_directiva'   => array( 'Consolida · Directiva', array( 'csl_access', 'csl_board' ) ),
	);

	public static function install(): void {
		foreach ( self::ROLES as $slug => $def ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				$role = add_role( $slug, $def[0], array( 'read' => true ) );
			}
			if ( $role ) {
				$role->add_cap( 'read' );
				foreach ( $def[1] as $cap ) {
					$role->add_cap( $cap );
				}
			}
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( self::CAP_ACCESS, self::CAP_CORP, self::CAP_BOARD, self::CAP_COUNTRY, self::CAP_ADMIN ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function register(): void {
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 20, 3 );
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ), 0 );
		add_filter(
			'show_admin_bar',
			function ( $show ) {
				return self::is_plugin_only() ? false : $show;
			},
			20
		);
	}

	public static function panel_url(): string {
		return home_url( '/consolida/' );
	}

	/** Usuario con un rol del plugin que no administra el sitio. */
	public static function is_plugin_only( $user = null ): bool {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user instanceof \WP_User || ! $user->exists() ) {
			return false;
		}
		return (bool) array_intersect( array_keys( self::ROLES ), (array) $user->roles ) && ! user_can( $user, 'manage_options' );
	}

	public static function login_redirect( $to, $requested, $user ) {
		if ( $user instanceof \WP_User && self::is_plugin_only( $user ) ) {
			return self::panel_url();
		}
		return $to;
	}

	public static function keep_out_of_admin(): void {
		if ( wp_doing_ajax() || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ! is_user_logged_in() ) {
			return;
		}
		$script = basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ); // phpcs:ignore
		if ( in_array( $script, array( 'admin-post.php', 'async-upload.php' ), true ) ) {
			return;
		}
		if ( self::is_plugin_only() ) {
			wp_safe_redirect( self::panel_url() );
			exit;
		}
	}

	/* ---------- permisos de la persona actual ---------- */

	public static function caps(): array {
		return array(
			'corp'    => current_user_can( self::CAP_CORP ),
			'board'   => current_user_can( self::CAP_BOARD ),
			'country' => current_user_can( self::CAP_COUNTRY ),
			'admin'   => current_user_can( self::CAP_ADMIN ),
		);
	}

	public static function is_corp(): bool {
		return current_user_can( self::CAP_CORP );
	}

	public static function is_board(): bool {
		return current_user_can( self::CAP_BOARD );
	}

	public static function is_admin(): bool {
		return current_user_can( self::CAP_ADMIN );
	}

	/** Empresas asignadas al usuario en company_users. */
	public static function assigned_ids( int $uid = 0 ): array {
		$uid = $uid ? $uid : get_current_user_id();
		return Store::user_company_ids( $uid );
	}

	/**
	 * Dueño de la empresa: usuario País asignado a ella, o administrador.
	 * CONTRATO: «dueño» no está definido; se toma así para que la demo (que corre como admin) pueda aprobar y enviar.
	 */
	public static function owns_company( int $company_id ): bool {
		if ( self::is_admin() ) {
			return true;
		}
		return current_user_can( self::CAP_COUNTRY ) && in_array( $company_id, self::assigned_ids(), true );
	}

	/** Puede subir archivos de la empresa: dueño o corporativo. */
	public static function can_upload( int $company_id ): bool {
		return self::is_corp() || self::owns_company( $company_id );
	}

	/** Puede ver la empresa y sus subidas: corporativo, directiva o dueño. */
	public static function can_view_company( int $company_id ): bool {
		return self::is_corp() || self::is_board() || self::owns_company( $company_id );
	}

	/** Ids de empresas visibles para la persona actual (null = todas). */
	public static function visible_company_ids(): ?array {
		if ( self::is_corp() || self::is_board() || self::is_admin() ) {
			return null;
		}
		return self::assigned_ids();
	}
}

<?php
namespace Consolida;

/**
 * Sirve el panel en /trazo/ fuera del tema: una página mínima con <div id="csl-app"> que carga
 * assets/panel.css y assets/panel.js (§16). Sin sesión → login de WordPress con regreso a /trazo/.
 */
class Front {
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'rewrites' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter(
			'query_vars',
			function ( $v ) {
				$v[] = 'csl_app';
				return $v;
			}
		);
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
	}

	public static function rewrites(): void {
		// Marca Trazo: el panel vive en /trazo/; /consolida/ queda como alias de la primera versión.
		add_rewrite_rule( '^trazo/?$', 'index.php?csl_app=panel', 'top' );
		add_rewrite_rule( '^consolida/?$', 'index.php?csl_app=panel', 'top' );
	}

	public static function maybe_flush(): void {
		if ( get_option( 'csl_rewrite_version' ) !== CSL_VERSION ) {
			self::rewrites();
			flush_rewrite_rules( false );
			update_option( 'csl_rewrite_version', CSL_VERSION, false );
		}
	}

	public static function maybe_render(): void {
		if ( 'panel' !== get_query_var( 'csl_app' ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( Roles::panel_url() ) );
			exit;
		}
		if ( ! current_user_can( Roles::CAP_ACCESS ) ) {
			wp_die( 'Tu cuenta no tiene acceso a Trazo. Pide acceso al administrador.', 'Sin acceso', array( 'response' => 403 ) );
		}
		self::render();
	}

	/** Datos de arranque del panel: window.CSL. */
	public static function boot(): array {
		$u = wp_get_current_user();
		return array(
			'api'     => esc_url_raw( rest_url( 'consolida/v1' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
			'version' => CSL_VERSION,
			'logout'  => html_entity_decode( wp_logout_url( Roles::panel_url() ) ),
			'user'    => array(
				'id'    => (int) $u->ID,
				'name'  => $u->display_name,
				'roles' => array_values( (array) $u->roles ),
				'caps'  => Roles::caps(),
			),
		);
	}

	private static function render(): void {
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		$nonce = wp_generate_password( 24, false );
		header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . $nonce . "'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'" );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );
		header( 'X-Frame-Options: DENY' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
		// La versión de los archivos cambia si cambia el JS o el CSS.
		$mt  = max( array_map( fn( $f ) => is_file( CSL_DIR . $f ) ? (int) filemtime( CSL_DIR . $f ) : 0, array( 'assets/panel.js', 'assets/panel.css' ) ) );
		$v   = rawurlencode( CSL_VERSION . '-' . $mt );
		$css = CSL_URL . 'assets/panel.css?v=' . $v;
		$js  = CSL_URL . 'assets/panel.js?v=' . $v;
		echo '<!doctype html><html lang="es"><head><meta charset="utf-8">';
		echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
		echo '<meta name="robots" content="noindex,nofollow">';
		echo '<title>Trazo</title>';
		echo '<link rel="stylesheet" href="' . esc_url( $css ) . '">';
		echo '</head><body><div id="csl-app"><p class="boot">Cargando Trazo…</p></div>';
		echo '<script nonce="' . esc_attr( $nonce ) . '">window.CSL=' . wp_json_encode( self::boot(), JSON_HEX_TAG | JSON_HEX_AMP ) . ';</script>';
		echo '<script src="' . esc_url( $js ) . '" defer></script>';
		echo '</body></html>';
		exit;
	}
}

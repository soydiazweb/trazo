<?php
/**
 * Plugin Name: Trazo – Consolidación multiempresa
 * Plugin URI:  https://www.soydiaz.com
 * Description: Trazo consolida el presupuesto de un grupo de empresas en varias monedas a partir de un Excel por país: valida, elimina lo intercompañía, explica desviaciones y calcula ROIC, ROCE y EVA. Panel privado en /trazo/.
 * Version:     0.7.0
 * Author:      Jonathan Diaz
 * Author URI:  https://www.soydiaz.com
 * Requires PHP: 8.1
 * Requires at least: 6.5
 * Text Domain: consolida
 */

namespace Consolida;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CSL_VERSION', '0.7.0' );
define( 'CSL_FILE', __FILE__ );
define( 'CSL_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSL_URL', plugin_dir_url( __FILE__ ) );

/* Autoload: Consolida\Engine\Kpi → includes/Engine/Kpi.php */
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, __NAMESPACE__ . '\\' ) !== 0 ) {
			return;
		}
		$rel  = str_replace( '\\', '/', substr( $class, strlen( __NAMESPACE__ ) + 1 ) );
		$file = CSL_DIR . 'includes/' . $rel . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

/** Clases con ::register(). Si falta una, no rompe el sitio. */
function modules(): array {
	return array(
		'Roles',
		'Front',
		'Rest\\Admin',
		'Rest\\Data',
		'Rest\\Group',
	);
}

add_action(
	'plugins_loaded',
	function () {
		Schema::maybe_upgrade();
		foreach ( modules() as $m ) {
			$class = __NAMESPACE__ . '\\' . $m;
			if ( class_exists( $class ) && method_exists( $class, 'register' ) ) {
				$class::register();
			}
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		Schema::install();
		Roles::install();
		update_option( 'csl_rewrite_version', '' );
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules( false );
	}
);

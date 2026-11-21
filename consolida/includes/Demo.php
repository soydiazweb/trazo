<?php
namespace Consolida;

use Consolida\Rest\Admin;
use Consolida\Rest\Data;

/**
 * Demo con los datos reales del reto: importa Grupo_Corporativo.xlsx (misma lógica que /corporate/import) y luego los
 * archivos de país de 1_Paises/, cada uno por el mismo flujo que una subida real (Rest\Data::ingest).
 * También reinicia los datos del plugin y crea usuarios de prueba si se pide.
 *
 * No fija países ni versiones: los países salen del corporativo y las versiones del nombre del archivo (§1).
 */
class Demo {
	/** Carpeta de datos en el servidor (contrato v2 §15). */
	const DATA_DIR = WP_CONTENT_DIR . '/datos-meridia';

	const CORPORATE_REL = '2_Grupo/Grupo_Corporativo.xlsx';
	const MAX_FILES     = 200;

	/* =====================================================================
	 * Clasificación de archivos (§1)
	 * ===================================================================*/

	/**
	 * Dice de qué país y versión es un archivo, a partir de su ruta relativa a DATA_DIR.
	 *
	 * Devuelve null si no se reconoce, o:
	 *   ['corporate'=>true]  para 2_Grupo/Grupo_Corporativo.xlsx;
	 *   ['corporate'=>false, 'number'=>N|null, 'country'=>texto, 'version'=>clave, 'year'=>int, 'label'=>texto]
	 * Patrón: 1_Paises/PaisN_<País>/<País>_{Presupuesto_2026, Real_2026, Presupuesto_2027, Presupuesto_2027_v2}.xlsx.
	 * Claves (§1): Presupuesto_2026 → ppto_2026; Real_2026 → real_2026; Presupuesto_2027 → ppto_2027_v1;
	 * Presupuesto_2027_v2 → ppto_2027_v2. Un presupuesto sin «_vN» es v1 solo si existe un «_v2» del mismo año en la
	 * carpeta (así ppto_2026 queda sin sufijo, como en tasas_oficiales).
	 */
	public static function classify( string $relativePath, array $siblings = array() ): ?array {
		$rel = str_replace( '\\', '/', ltrim( $relativePath, '/' ) );
		if ( 0 === strcasecmp( $rel, self::CORPORATE_REL ) || preg_match( '#(^|/)2_Grupo/[^/]*Corporativo[^/]*\.xlsx$#i', $rel ) ) {
			return array( 'corporate' => true );
		}
		if ( ! preg_match( '#^(?:.*?/)?(?:Pais(\d+)_([^/]+)/)?([^/]+?)_(Presupuesto|Real)_(\d{4})(?:_v(\d+))?\.xlsx$#i', $rel, $m ) ) {
			return null;
		}
		$number  = '' !== $m[1] ? (int) $m[1] : null;
		$country = str_replace( '_', ' ', '' !== $m[2] ? $m[2] : $m[3] );
		$kind    = strtolower( $m[4] );
		$year    = (int) $m[5];
		$n       = isset( $m[6] ) && '' !== $m[6] ? (int) $m[6] : 0;
		if ( 'real' === $kind ) {
			$key = 'real_' . $year;
		} else {
			if ( 0 === $n ) {
				foreach ( $siblings as $s ) {
					if ( preg_match( '#_Presupuesto_' . $year . '_v\d+\.xlsx$#i', $s ) ) {
						$n = 1;
						break;
					}
				}
			}
			$key = $n ? 'ppto_' . $year . '_v' . $n : 'ppto_' . $year;
		}
		return array(
			'corporate' => false,
			'number'    => $number,
			'country'   => $country,
			'version'   => $key,
			'year'      => $year,
			'label'     => Store::label_for( $key ),
		);
	}

	/** Archivos .xlsx dentro de DATA_DIR (rutas relativas, ordenadas), sin salir de la carpeta ni seguir enlaces. */
	public static function files(): array {
		$base = realpath( self::DATA_DIR );
		if ( ! $base || ! is_dir( $base ) ) {
			return array();
		}
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( count( $out ) >= self::MAX_FILES ) {
				break;
			}
			$name = $f->getFilename();
			if ( ! $f->isFile() || $f->isLink() || ! preg_match( '/\.xlsx$/i', $name ) || str_starts_with( $name, '~$' ) || str_starts_with( $name, '.' ) ) {
				continue;
			}
			$real = realpath( $f->getPathname() );
			if ( ! $real || ! str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			$out[] = str_replace( DIRECTORY_SEPARATOR, '/', substr( $real, strlen( $base ) + 1 ) );
		}
		sort( $out );
		return $out;
	}

	/** Ruta absoluta del corporativo en la carpeta de datos, o null. */
	public static function corporate_path(): ?string {
		$base = realpath( self::DATA_DIR );
		if ( ! $base ) {
			return null;
		}
		foreach ( self::files() as $rel ) {
			$c = self::classify( $rel );
			if ( $c && ! empty( $c['corporate'] ) ) {
				return $base . '/' . $rel;
			}
		}
		return null;
	}

	private static function norm( string $s ): string {
		return strtolower( trim( preg_replace( '/\s+/', ' ', remove_accents( str_replace( '_', ' ', $s ) ) ) ) );
	}

	/** País por número de carpeta (PaisN) o por nombre del país/empresa (sin tildes). */
	private static function find_company( array $c ): ?array {
		if ( ! empty( $c['number'] ) ) {
			$co = Store::company_by_number( (int) $c['number'] );
			if ( $co ) {
				return $co;
			}
		}
		$want = self::norm( (string) ( $c['country'] ?? '' ) );
		if ( '' === $want ) {
			return null;
		}
		foreach ( Store::companies() as $co ) {
			if ( self::norm( $co['country'] ) === $want || self::norm( $co['name'] ) === $want || self::norm( $co['name'] ) === 'meridia ' . $want ) {
				return $co;
			}
		}
		return null;
	}

	/* =====================================================================
	 * Carga
	 * ===================================================================*/

	/**
	 * 1) Importa el corporativo. 2) Sube los archivos de país en orden cronológico de versión (así cada uno se valida
	 * contra las versiones anteriores ya cargadas). $send = aprobar y enviar los que no tengan críticos (sus avisos se
	 * marcan explicados con un texto de demo). $with_users = crea un usuario de prueba por rol.
	 */
	public static function load( int $uid, bool $with_users = false, bool $send = false ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore
		}
		$base  = realpath( self::DATA_DIR );
		$files = self::files();
		if ( ! $base || ! $files ) {
			return new \WP_Error( 'csl_not_found', 'No se encontró la carpeta de datos (' . basename( self::DATA_DIR ) . ') en wp-content o está vacía.', array( 'status' => 404 ) );
		}

		$corp_path = self::corporate_path();
		if ( ! $corp_path ) {
			return new \WP_Error( 'csl_not_found', 'Falta 2_Grupo/Grupo_Corporativo.xlsx en la carpeta de datos.', array( 'status' => 404 ) );
		}
		$corp = Admin::import_corporate_bytes( (string) file_get_contents( $corp_path ), basename( $corp_path ) ); // phpcs:ignore
		if ( is_wp_error( $corp ) ) {
			return $corp;
		}

		$items = array();
		$plan  = array();
		foreach ( $files as $rel ) {
			$c = self::classify( $rel, $files );
			if ( null === $c ) {
				$items[] = array( 'file' => $rel, 'status' => 'omitido', 'reason' => 'No se reconoce el nombre del archivo.' );
				continue;
			}
			if ( ! empty( $c['corporate'] ) ) {
				continue;
			}
			$company = self::find_company( $c );
			if ( ! $company ) {
				$items[] = array( 'file' => $rel, 'status' => 'omitido', 'reason' => 'El país «' . $c['country'] . '» no está en parametros_paises.' );
				continue;
			}
			$plan[] = array( $rel, $c, $company );
		}
		usort( $plan, fn( $x, $y ) => array( Store::rank_for( $x[1]['version'] ), $x[2]['number'], $x[2]['code'] ) <=> array( Store::rank_for( $y[1]['version'] ), $y[2]['number'], $y[2]['code'] ) );

		foreach ( $plan as [ $rel, $c, $company ] ) {
			$version = Store::ensure_version( $c['version'], $c['label'], $c['year'] );
			$res     = Data::ingest( $company, $version, (string) file_get_contents( $base . '/' . $rel ), basename( $rel ), $uid ); // phpcs:ignore
			if ( is_wp_error( $res ) ) {
				$items[] = array( 'file' => $rel, 'company' => $company['code'], 'version' => $c['version'], 'status' => 'error', 'reason' => $res->get_error_message() );
				// Si falta una pieza del motor, no tiene sentido seguir con los demás archivos.
				if ( 503 === (int) ( $res->get_error_data()['status'] ?? 0 ) ) {
					break;
				}
				continue;
			}
			$u = $res['upload'];
			if ( $send && 0 === $u['criticals'] ) {
				$u = self::approve_and_send( $u, $uid );
			}
			$items[] = array(
				'file'      => $rel,
				'company'   => $company['code'],
				'version'   => $c['version'],
				'upload_id' => $u['id'],
				'status'    => $u['status'],
				'criticals' => $u['criticals'],
				'warnings'  => $u['warnings'],
			);
		}
		$out = array(
			'dir'       => basename( self::DATA_DIR ),
			'files'     => count( $files ),
			'corporate' => $corp,
			'items'     => $items,
		);
		if ( $with_users ) {
			$out['users'] = self::create_users();
		}
		Log::write(
			'demo_cargar',
			'demo',
			0,
			array(
				'archivos' => count( $files ),
				'subidos'  => count( array_filter( $items, fn( $i ) => isset( $i['upload_id'] ) ) ),
				'enviar'   => $send,
				'usuarios' => $with_users,
			)
		);
		return $out;
	}

	/**
	 * Sube UN archivo de demo (país + versión) desde DATA_DIR por el flujo normal de subida (Data::ingest).
	 * No aprueba ni envía. Devuelve lo mismo que POST /uploads: {upload, issues}.
	 */
	public static function load_one( array $company, string $version_key, int $uid, bool $send = false ) {
		$base  = realpath( self::DATA_DIR );
		$files = self::files();
		if ( ! $base || ! $files ) {
			return new \WP_Error( 'csl_not_found', 'No se encontró la carpeta de datos (' . basename( self::DATA_DIR ) . ') en wp-content o está vacía.', array( 'status' => 404 ) );
		}
		if ( ! Store::has_corporate() ) {
			return new \WP_Error( 'csl_no_corporate', 'Primero hay que importar el archivo corporativo.', array( 'status' => 409 ) );
		}
		foreach ( $files as $rel ) {
			$c = self::classify( $rel, $files );
			if ( ! $c || ! empty( $c['corporate'] ) || $c['version'] !== $version_key ) {
				continue;
			}
			$co = self::find_company( $c );
			if ( ! $co || (int) $co['id'] !== (int) $company['id'] ) {
				continue;
			}
			$version = Store::ensure_version( $c['version'], $c['label'], $c['year'] );
			$res     = Data::ingest( $company, $version, (string) file_get_contents( $base . '/' . $rel ), basename( $rel ), $uid ); // phpcs:ignore
			// Con $send, si el archivo no tiene críticos se explican sus avisos, se aprueba y se envía (camino «bueno» del demo).
			if ( $send && ! is_wp_error( $res ) && empty( $res['upload']['criticals'] ) ) {
				$res['upload'] = self::approve_and_send( $res['upload'], $uid );
			}
			if ( ! is_wp_error( $res ) ) {
				Log::write( 'demo_cargar_uno', 'upload', (int) $res['upload']['id'], array( 'company' => $company['code'], 'version' => $version_key, 'file' => basename( $rel ) ) );
			}
			return $res;
		}
		return new \WP_Error( 'csl_not_found', 'No hay archivo de demo de ' . $company['country'] . ' para ' . Store::label_for( $version_key ) . ' en la carpeta de datos.', array( 'status' => 404 ) );
	}

	/** Explica los avisos con un texto de demo, aprueba y envía. */
	private static function approve_and_send( array $u, int $uid ): array {
		foreach ( Store::issues_for_upload( $u['id'] ) as $i ) {
			if ( 'aviso' === $i['severity'] && 'abierta' === $i['status'] ) {
				Store::update_issue( $i['id'], 'explicada', 'Carga de demo: aviso revisado.' );
			}
		}
		$a = Data::do_approve( $u['id'], $uid );
		if ( is_wp_error( $a ) ) {
			return $u;
		}
		$s = Data::do_send( $u['id'] );
		return is_wp_error( $s ) ? $a : $s;
	}

	/**
	 * Un usuario de prueba por rol del plugin, con contraseña aleatoria devuelta solo en esta respuesta (no se guarda
	 * ni se anota en la bitácora). Si ya existe, se le asigna una contraseña nueva.
	 */
	private static function create_users(): array {
		$out  = array();
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = '' !== $host && str_contains( $host, '.' ) ? $host : 'example.com';
		foreach ( Roles::ROLES as $role => $def ) {
			$login = str_replace( 'csl_', 'consolida-', $role );
			$pass  = wp_generate_password( 20, true, false );
			$user  = get_user_by( 'login', $login );
			if ( $user ) {
				wp_set_password( $pass, $user->ID );
				$user->set_role( $role );
				$id = (int) $user->ID;
			} else {
				$id = wp_insert_user(
					array(
						'user_login'   => $login,
						'user_pass'    => $pass,
						'user_email'   => $login . '@' . $host,
						'display_name' => $def[0],
						'role'         => $role,
					)
				);
				if ( is_wp_error( $id ) ) {
					$out[] = array( 'login' => $login, 'role' => $role, 'error' => $id->get_error_message() );
					continue;
				}
			}
			$out[] = array( 'id' => (int) $id, 'login' => $login, 'role' => $role, 'password' => $pass );
		}
		return $out;
	}

	/* =====================================================================
	 * Reinicio
	 * ===================================================================*/

	/** Vacía todas las tablas del plugin. No borra usuarios ni la carpeta de datos. */
	public static function reset(): void {
		Schema::truncate_all();
		delete_option( 'csl_corporate_meta' );
		Store::flush_corporate();
		Log::write( 'demo_reiniciar', 'demo', 0 );
	}
}

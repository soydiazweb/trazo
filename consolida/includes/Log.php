<?php
namespace Consolida;

/**
 * Bitácora simple: quién hizo qué y sobre qué objeto. Nunca guarda contraseñas, binarios ni contenido de archivos:
 * las claves sensibles del detalle se descartan antes de escribir.
 */
class Log {
	const PRIVATE_KEYS = array( 'password', 'pass', 'pwd', 'token', 'nonce', 'secret', 'file_blob', 'blob', 'bytes', 'workbook' );

	/**
	 * @param string $action      verbo corto: subir, aprobar, enviar, consolidar, tasas, demo_cargar…
	 * @param string $object_type upload, issue, cycle, company, rates, result, action, demo…
	 */
	public static function write( string $action, string $object_type = '', int $object_id = 0, array $detail = array() ): void {
		global $wpdb;
		$detail = self::scrub( $detail );
		$json   = $detail ? wp_json_encode( $detail, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) : null;
		$wpdb->insert(
			Schema::t( 'log' ),
			array(
				'at'          => Schema::now(),
				'user_id'     => get_current_user_id(),
				'action'      => substr( $action, 0, 60 ),
				'object_type' => substr( $object_type, 0, 40 ),
				'object_id'   => max( 0, $object_id ),
				'detail'      => $json,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s' )
		);
	}

	private static function scrub( array $d, int $depth = 0 ): array {
		$out = array();
		foreach ( $d as $k => $v ) {
			if ( is_string( $k ) && in_array( strtolower( $k ), self::PRIVATE_KEYS, true ) ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$out[ $k ] = $depth < 3 ? self::scrub( $v, $depth + 1 ) : '…';
			} elseif ( is_string( $v ) ) {
				$out[ $k ] = mb_substr( $v, 0, 300 );
			} else {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	/** Página de la bitácora (más reciente primero). */
	public static function page( int $page = 1, int $per = 50, string $object_type = '' ): array {
		global $wpdb;
		$t      = Schema::t( 'log' );
		$per    = max( 1, min( 200, $per ) );
		$offset = ( max( 1, $page ) - 1 ) * $per;
		if ( '' !== $object_type ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE object_type = %s", $object_type ) ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE object_type = %s ORDER BY id DESC LIMIT %d OFFSET %d", $object_type, $per, $offset ), ARRAY_A ); // phpcs:ignore
		} else {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t ORDER BY id DESC LIMIT %d OFFSET %d", $per, $offset ), ARRAY_A ); // phpcs:ignore
		}
		$items = array();
		foreach ( (array) $rows as $r ) {
			$items[] = array(
				'id'          => (int) $r['id'],
				'at'          => $r['at'],
				'user_id'     => (int) $r['user_id'],
				'user'        => self::user_name( (int) $r['user_id'] ),
				'action'      => $r['action'],
				'object_type' => $r['object_type'],
				'object_id'   => (int) $r['object_id'],
				'detail'      => $r['detail'] ? json_decode( $r['detail'], true ) : null,
			);
		}
		return array(
			'items' => $items,
			'total' => $total,
			'page'  => max( 1, $page ),
			'per'   => $per,
		);
	}

	public static function user_name( int $uid ): string {
		if ( ! $uid ) {
			return 'Sistema';
		}
		$u = get_userdata( $uid );
		return $u ? $u->display_name : 'Usuario ' . $uid;
	}
}

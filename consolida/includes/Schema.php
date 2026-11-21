<?php
namespace Consolida;

/**
 * Tablas propias (prefijo csl_), contrato v2 §15. Fechas en UTC.
 *
 * Estados de una subida (`uploads.status`):
 *   rechazado  → tiene al menos un crítico; hay que volver a subir.
 *   con_avisos → sin críticos, con avisos; el país debe explicarlos antes de aprobar.
 *   validado   → sin críticos ni avisos; lista para aprobar.
 *   aprobado   → el país la aprobó (0 críticos y avisos explicados).
 *   enviado    → el país la envió a corporativo; solo estas entran a la consolidación.
 * CONTRATO: el contrato no define estado para «sin ningún problema»; se agrega `validado`.
 * CONTRATO: `versions.key` del contrato se llama `version_key` (KEY es palabra reservada de MySQL); las demás tablas
 * usan también `version_key`.
 */
class Schema {
	const DB_VERSION = 2;

	/** Tablas del plugin (sin prefijo). */
	const TABLES = array(
		'log', 'actions', 'results', 'issues', 'uploads', 'rates', 'versions', 'profiles', 'company_users', 'companies',
		'catalog', 'products', 'channels', 'proposals', 'history_cycles', 'history_kpis',
	);

	/** Tablas de la v1 que ya no existen o cambiaron de forma (se borran al pasar a la v2; solo tenían datos de prueba). */
	const TABLES_V1 = array( 'actions', 'results', 'issues', 'uploads', 'account_map', 'rates', 'cycles', 'company_users', 'companies' );

	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'csl_' . $name;
	}

	public static function maybe_upgrade(): void {
		if ( (int) get_option( 'csl_db_version' ) !== self::DB_VERSION ) {
			self::install();
			Roles::install();
		}
	}

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$prev = (int) get_option( 'csl_db_version' );
		if ( $prev > 0 && $prev < 2 ) {
			foreach ( self::TABLES_V1 as $old ) {
				$wpdb->query( 'DROP TABLE IF EXISTS ' . self::t( $old ) ); // phpcs:ignore -- nombre fijo.
			}
		}
		$c   = $wpdb->get_charset_collate();
		$sql = array();

		$sql[] = 'CREATE TABLE ' . self::t( 'companies' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			code varchar(20) NOT NULL,
			number int(11) NOT NULL DEFAULT 0,
			name varchar(190) NOT NULL,
			country varchar(100) NOT NULL DEFAULT '',
			currency char(3) NOT NULL DEFAULT 'USD',
			tax decimal(9,6) NOT NULL DEFAULT 0,
			wacc decimal(9,6) NOT NULL DEFAULT 0,
			ownership decimal(9,6) NOT NULL DEFAULT 1,
			minority decimal(9,6) NOT NULL DEFAULT 0,
			minority_partner varchar(190) NULL,
			scale varchar(20) NOT NULL DEFAULT 'unidades',
			data_owner varchar(190) NOT NULL DEFAULT '',
			approver varchar(190) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'company_users' ) . " (
			company_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			PRIMARY KEY  (company_id,user_id),
			KEY user_id (user_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'profiles' ) . " (
			company_id bigint(20) unsigned NOT NULL,
			json longtext NULL,
			updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (company_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'versions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			version_key varchar(30) NOT NULL,
			label varchar(120) NOT NULL DEFAULT '',
			year smallint(5) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'abierto',
			sort int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY version_key (version_key)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'rates' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			version_key varchar(30) NOT NULL,
			currency char(3) NOT NULL,
			month tinyint(3) unsigned NOT NULL DEFAULT 0,
			budget decimal(20,8) NULL,
			avg decimal(20,8) NULL,
			close decimal(20,8) NULL,
			status varchar(20) NOT NULL DEFAULT 'borrador',
			approved_by varchar(190) NOT NULL DEFAULT '',
			approved_at varchar(30) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY version_currency_month (version_key,currency,month)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'catalog' ) . " (
			code varchar(20) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			type varchar(30) NOT NULL DEFAULT '',
			subtype varchar(40) NOT NULL DEFAULT '',
			operating tinyint(1) NOT NULL DEFAULT 0,
			is_current tinyint(1) NULL,
			intercompany tinyint(1) NOT NULL DEFAULT 0,
			sign tinyint(2) NOT NULL DEFAULT 1,
			data longtext NULL,
			PRIMARY KEY  (code)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'products' ) . " (
			code varchar(30) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			name_en varchar(190) NOT NULL DEFAULT '',
			line_name varchar(60) NOT NULL DEFAULT '',
			data longtext NULL,
			PRIMARY KEY  (code)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'channels' ) . " (
			code varchar(30) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			name_en varchar(190) NOT NULL DEFAULT '',
			data longtext NULL,
			PRIMARY KEY  (code)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'proposals' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			pid varchar(30) NOT NULL,
			site varchar(20) NOT NULL DEFAULT '',
			data longtext NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY pid (pid)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'history_cycles' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			country_code varchar(20) NOT NULL DEFAULT '',
			data longtext NULL,
			PRIMARY KEY  (id),
			KEY country_code (country_code)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'history_kpis' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			country_code varchar(20) NOT NULL DEFAULT '',
			data longtext NULL,
			PRIMARY KEY  (id),
			KEY country_code (country_code)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'uploads' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			version_key varchar(30) NOT NULL,
			company_id bigint(20) unsigned NOT NULL,
			file_name varchar(190) NOT NULL DEFAULT '',
			file_blob longblob NULL,
			sha256 char(64) NOT NULL DEFAULT '',
			size bigint(20) unsigned NOT NULL DEFAULT 0,
			uploaded_by bigint(20) unsigned NOT NULL DEFAULT 0,
			uploaded_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'rechazado',
			approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
			approved_at datetime NULL,
			sent_at datetime NULL,
			dataset longtext NULL,
			criticals int(11) NOT NULL DEFAULT 0,
			warnings int(11) NOT NULL DEFAULT 0,
			superseded tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY version_company (version_key,company_id,superseded)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'issues' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			upload_id bigint(20) unsigned NULL,
			version_key varchar(30) NOT NULL,
			company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			code varchar(40) NOT NULL,
			severity varchar(10) NOT NULL DEFAULT 'aviso',
			category varchar(30) NOT NULL DEFAULT '',
			sheet varchar(60) NOT NULL DEFAULT '',
			row_num int(11) NULL,
			col varchar(10) NULL,
			month tinyint(3) unsigned NULL,
			concept varchar(60) NULL,
			message text NULL,
			actual double NULL,
			expected double NULL,
			impact_usd double NULL,
			status varchar(20) NOT NULL DEFAULT 'abierta',
			explanation text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY upload_id (upload_id),
			KEY version_company (version_key,company_id)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'results' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			version_key varchar(30) NOT NULL,
			created_at datetime NOT NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			data longtext NULL,
			PRIMARY KEY  (id),
			KEY version_key (version_key)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'actions' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			version_key varchar(30) NOT NULL DEFAULT '',
			title varchar(255) NOT NULL,
			owner varchar(190) NOT NULL DEFAULT '',
			due_date date NULL,
			status varchar(20) NOT NULL DEFAULT 'pendiente',
			effect text NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY version_key (version_key)
		) $c;";

		$sql[] = 'CREATE TABLE ' . self::t( 'log' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(60) NOT NULL,
			object_type varchar(40) NOT NULL DEFAULT '',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			detail longtext NULL,
			PRIMARY KEY  (id),
			KEY at (at),
			KEY object (object_type,object_id)
		) $c;";

		foreach ( $sql as $q ) {
			dbDelta( $q );
		}
		update_option( 'csl_db_version', self::DB_VERSION, false );
	}

	/** Vacía todas las tablas del plugin (reinicio de la demo). Las tablas siguen existiendo. */
	public static function truncate_all(): void {
		global $wpdb;
		foreach ( self::TABLES as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . self::t( $t ) ); // phpcs:ignore -- nombre de tabla fijo.
		}
	}

	/** Vacía una tabla del plugin (solo nombres de TABLES). */
	public static function truncate( string $name ): void {
		global $wpdb;
		if ( in_array( $name, self::TABLES, true ) ) {
			$wpdb->query( 'TRUNCATE TABLE ' . self::t( $name ) ); // phpcs:ignore -- nombre validado.
		}
	}

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

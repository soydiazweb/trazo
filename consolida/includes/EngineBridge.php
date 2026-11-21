<?php
namespace Consolida;

/**
 * Único punto de contacto entre la capa WordPress y el motor (includes/Engine/*). Un método por operación con la
 * firma del contrato v2. Si el motor cambia una firma, se ajusta solo aquí.
 *
 * Cada método comprueba que la clase y el método existan y que acepten al menos los parámetros esperados (así no se
 * llama por error a una versión v1 con otra firma). Si falta algo lanza EngineMissing, que la API convierte en 503
 * con un mensaje claro.
 */
class EngineBridge {
	const NS = 'Consolida\\Engine\\';

	/** operación => [clase, método, parámetros mínimos] (lo que espera esta capa). */
	const OPS = array(
		'corporate'        => array( 'Corporate', 'load', 1 ),
		'profiles'         => array( 'Profiles', 'defaults', 0 ),
		'read'             => array( 'CountryReader', 'read', 3 ),
		'validate'         => array( 'Validator', 'validate', 2 ),
		'reconcile'        => array( 'Intercompany', 'reconcile', 3 ),
		'consolidate'      => array( 'Consolidator', 'run', 3 ),
		'variance'         => array( 'Variance', 'compare', 3 ),
		'kpi'              => array( 'Kpi', 'compute', 2 ),
		'kpi_copied'       => array( 'Kpi', 'copied', 3 ),
		'proposals'        => array( 'Kpi', 'proposals', 1 ),
		'issue_impact'     => array( 'Kpi', 'issue_impact', 3 ),
		'history_quality'  => array( 'History', 'quality', 2 ),
		'history_trend'    => array( 'History', 'kpi_trend', 2 ),
		'history_scenario' => array( 'History', 'scenarios', 2 ),
	);

	/** Etiquetas para los mensajes de «falta». */
	const LABELS = array(
		'corporate'        => 'la lectura del archivo corporativo',
		'profiles'         => 'los perfiles de lectura por país',
		'read'             => 'el lector de archivos de país',
		'validate'         => 'las reglas de validación',
		'reconcile'        => 'la conciliación intercompañía',
		'consolidate'      => 'la consolidación',
		'variance'         => 'el cálculo de desviaciones',
		'kpi'              => 'el cálculo de indicadores',
		'kpi_copied'       => 'la comparación de indicadores copiados',
		'proposals'        => 'el recálculo de propuestas de inversión',
		'issue_impact'     => 'el impacto de los hallazgos',
		'history_quality'  => 'el análisis preventivo',
		'history_trend'    => 'la serie evolutiva',
		'history_scenario' => 'los escenarios',
	);

	/** ¿Está disponible la operación con una firma compatible? */
	public static function has( string $op ): bool {
		if ( ! isset( self::OPS[ $op ] ) ) {
			return false;
		}
		[ $cls, $method, $min ] = self::OPS[ $op ];
		$fq                     = self::NS . $cls;
		if ( ! class_exists( $fq ) || ! method_exists( $fq, $method ) ) {
			return false;
		}
		try {
			$rm = new \ReflectionMethod( $fq, $method );
			return $rm->isStatic() && $rm->isPublic() && $rm->getNumberOfParameters() >= $min;
		} catch ( \ReflectionException $e ) {
			return false;
		}
	}

	/** Lista de operaciones y si están disponibles (para diagnóstico en /meta). */
	public static function status(): array {
		$out = array();
		foreach ( array_keys( self::OPS ) as $op ) {
			$out[ $op ] = self::has( $op );
		}
		return $out;
	}

	private static function call( string $op, array $args ) {
		if ( ! self::has( $op ) ) {
			[ $cls, $method ] = self::OPS[ $op ];
			throw new EngineMissing( 'Todavía no está disponible ' . self::LABELS[ $op ] . ' (falta ' . $cls . '::' . $method . ').' );
		}
		[ $cls, $method ] = self::OPS[ $op ];
		return call_user_func_array( array( self::NS . $cls, $method ), $args );
	}

	/** Corporate::load(string $path): array (§4). */
	public static function corporate( string $path ): array {
		return (array) self::call( 'corporate', array( $path ) );
	}

	/** Profiles::defaults(): array — [código país => perfil] (§5). */
	public static function default_profiles(): array {
		return (array) self::call( 'profiles', array() );
	}

	/**
	 * CountryReader::read(string $path, array $profile, array $corporate, ?string $fileName = null): array —
	 * Dataset con 'issues' (§6). $fileName = nombre original (el temporal ya lo conserva, se pasa igual).
	 */
	public static function read( string $path, array $profile, array $corporate, ?string $file_name = null ): array {
		return (array) self::call( 'read', array( $path, $profile, $corporate, $file_name ) );
	}

	/**
	 * Validator::validate(array $dataset, array $ctx): array — Issues (§7).
	 * $ctx = ['corporate'=>…, 'version'=>clave, 'others'=>[clave de versión => Dataset vigente del mismo país]]
	 * (+ 'company' y 'profile', que el validador ignora si no los usa).
	 */
	public static function validate( array $dataset, array $ctx ): array {
		return (array) self::call( 'validate', array( $dataset, $ctx ) );
	}

	/**
	 * Intercompany::reconcile(array $datasets, array $corporate, string $version, array $known): array — {pairs, issues} (§8).
	 * $known = hallazgos de lectura y validación de la versión: los meses con FMT_DATE o FMT_SCALE_JUMP no se vuelven a
	 * reportar como diferencias intercompañía (se reporta la causa, no la consecuencia).
	 */
	public static function reconcile( array $datasets, array $corporate, string $version, array $known = array() ): array {
		return (array) self::call( 'reconcile', array( $datasets, $corporate, $version, $known ) );
	}

	/** Consolidator::run(array $datasets, array $corporate, string $version, ?array $ic = null): array (§10). */
	public static function consolidate( array $datasets, array $corporate, string $version, ?array $ic = null ): array {
		return (array) self::call( 'consolidate', array( $datasets, $corporate, $version, $ic ) );
	}

	/**
	 * Variance::compare(array $resA, array $resB, array $ctx): array (§11).
	 * $ctx = ['corporate'=>…, 'company'=>código|null] (+ 'a', 'b', 'datasets_a', 'datasets_b' por si los usa).
	 */
	public static function variance( array $res_a, array $res_b, array $ctx ): array {
		return (array) self::call( 'variance', array( $res_a, $res_b, $ctx ) );
	}

	/**
	 * Kpi::compute(array $result, array $corporate, array $issues = []): array (§12). $issues = abiertos (semáforo).
	 * Salida: ['companies'=>[code=>['ebit','nopat','ci','ce','roic','roce','wacc','eva','light','reasons',…]],
	 * 'group'=>[…]].
	 */
	public static function kpi( array $result, array $corporate, array $issues = array() ): array {
		return (array) self::call( 'kpi', array( $result, $corporate, $issues ) );
	}

	/** Kpi::copied(array $dataset, array $kpiCompany, array $ctx): array — {rows, issues}; $ctx = ['corporate','version']. */
	public static function kpi_copied( array $dataset, array $kpi_company, array $ctx ): array {
		return (array) self::call( 'kpi_copied', array( $dataset, $kpi_company, $ctx ) );
	}

	/**
	 * Kpi::proposals(array $corporate): array — propuestas recalculadas (presentado vs recalculado, 'holds').
	 */
	public static function proposals( array $corporate ): array {
		return (array) self::call( 'proposals', array( $corporate ) );
	}

	/**
	 * Kpi::issue_impact(array $issue, array $kpiCompany, array $ctx): array.
	 * $ctx = ['corporate'=>…, 'version'=>…] (+ 'company', 'dataset'); salida con 'applies' (bool).
	 */
	public static function issue_impact( array $issue, array $kpi_company, array $ctx ): array {
		return (array) self::call( 'issue_impact', array( $issue, $kpi_company, $ctx ) );
	}

	/** History::quality(array $issuesByCountry, array $corporate): array (§13). */
	public static function history_quality( array $issues_by_country, array $corporate ): array {
		return (array) self::call( 'history_quality', array( $issues_by_country, $corporate ) );
	}

	/** History::kpi_trend(array $corporate, array $kpiByVersion): array (§13). */
	public static function history_trend( array $corporate, array $kpi_by_version ): array {
		return (array) self::call( 'history_trend', array( $corporate, $kpi_by_version ) );
	}

	/** History::scenarios(array $result, array $ctx): array (§13). CONTRATO: $ctx = ['corporate','version','kpi']. */
	public static function history_scenarios( array $result, array $ctx ): array {
		return (array) self::call( 'history_scenario', array( $result, $ctx ) );
	}
}

/** Falta una pieza del motor: la API responde 503 con el mensaje. */
class EngineMissing extends \RuntimeException {
}

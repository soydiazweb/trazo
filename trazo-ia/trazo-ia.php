<?php
/**
 * Plugin Name: Trazo · IA
 * Description: IA real para el panel /trazo/ (plan de mejora y reglas del país) con cualquier proveedor compatible con OpenAI o Anthropic. Requiere el plugin «consolida».
 * Version:     0.1.1
 * Requires PHP: 8.1
 * Text Domain: trazo-ia
 *
 * Trazo · IA — capa de IA para el panel /trazo/ (plugin «consolida»), sin tocar ese plugin.
 *
 * - Adaptadores: «compatible con OpenAI» (OpenAI, OpenRouter, Gemini, Mistral, Groq, DeepSeek, Ollama…) y Anthropic.
 * - Tareas: cada una define sus datos de entrada, instrucciones (editables en Ajustes → Trazo · IA), formato y
 *   validación.
 *     plan-mejora      → plan de acciones para una oportunidad del panel (Directiva).
 *     mapeo-etiquetas  → cuenta del catálogo para etiquetas del Excel de un país que no tienen cuenta (Corporativo).
 * - La IA no calcula ni aplica nada: propone; una persona edita y guarda por las rutas que ya tiene el panel.
 * - El botón del panel lo pone wp-content/trazo-ia/panel-ia.js (ver trazo_ia_inject_script).
 *
 * Nace en wp-content/novamira-sandbox/ porque Novamira solo carga PHP desde ahí. Para moverlo a plugins/:
 *   copiar a wp-content/plugins/trazo-ia/trazo-ia.php con cabecera de plugin, activarlo y deshabilitar esta copia.
 * Copia de trabajo: BUILD AND PITCH/trazo-wp/trazo-ia/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TRAZO_IA_VERSION = '0.1.1';
const TRAZO_IA_OPT     = 'trazo_ia_settings';

/* =========================================================================
 * Ajustes
 * ======================================================================= */

function trazo_ia_defaults(): array {
	return array(
		'provider'     => 'openai_compat',
		'base_url'     => 'https://openrouter.ai/api/v1',
		'model'        => '',
		'key_enc'      => '',
		'timeout'      => 90,
		'max_tokens'   => 2500,
		'prompt_plan'  => trazo_ia_default_prompt_plan(),
		'prompt_mapeo' => trazo_ia_default_prompt_mapeo(),
	);
}

function trazo_ia_settings(): array {
	$s = get_option( TRAZO_IA_OPT, array() );
	return array_merge( trazo_ia_defaults(), is_array( $s ) ? $s : array() );
}

/** Clave cifrada con las sales del sitio. Una constante TRAZO_IA_KEY en wp-config.php manda sobre la guardada. */
function trazo_ia_secret(): string {
	return hash( 'sha256', wp_salt( 'auth' ) . 'trazo-ia', true );
}
function trazo_ia_encrypt( string $plain ): string {
	$iv = random_bytes( 16 );
	$c  = openssl_encrypt( $plain, 'aes-256-cbc', trazo_ia_secret(), OPENSSL_RAW_DATA, $iv );
	return base64_encode( $iv . $c );
}
function trazo_ia_decrypt( string $enc ): string {
	$raw = base64_decode( $enc, true );
	if ( ! $raw || strlen( $raw ) < 17 ) {
		return '';
	}
	$p = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', trazo_ia_secret(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
	return is_string( $p ) ? $p : '';
}
function trazo_ia_key(): string {
	if ( defined( 'TRAZO_IA_KEY' ) && TRAZO_IA_KEY ) {
		return (string) TRAZO_IA_KEY;
	}
	$s = trazo_ia_settings();
	return $s['key_enc'] ? trazo_ia_decrypt( $s['key_enc'] ) : '';
}

add_action(
	'admin_menu',
	function () {
		add_options_page( 'Trazo · IA', 'Trazo · IA', 'manage_options', 'trazo-ia', 'trazo_ia_settings_page' );
	}
);

add_action(
	'admin_post_trazo_ia_save',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permiso.' );
		}
		check_admin_referer( 'trazo_ia_save' );
		$s  = trazo_ia_settings();
		$in = wp_unslash( $_POST ); // phpcs:ignore
		$s['provider']   = in_array( $in['provider'] ?? '', array( 'openai_compat', 'anthropic' ), true ) ? $in['provider'] : 'openai_compat';
		$s['base_url']   = esc_url_raw( trim( (string) ( $in['base_url'] ?? '' ) ) );
		$s['model']      = sanitize_text_field( (string) ( $in['model'] ?? '' ) );
		$s['timeout']    = max( 10, min( 280, (int) ( $in['timeout'] ?? 90 ) ) );
		$s['max_tokens'] = max( 500, min( 16000, (int) ( $in['max_tokens'] ?? 2500 ) ) );
		foreach ( array( 'prompt_plan', 'prompt_mapeo' ) as $p ) {
			$v       = trim( (string) ( $in[ $p ] ?? '' ) );
			$s[ $p ] = '' === $v ? trazo_ia_defaults()[ $p ] : sanitize_textarea_field( $v );
		}
		$key = trim( (string) ( $in['api_key'] ?? '' ) );
		if ( '' !== $key ) {
			$s['key_enc'] = trazo_ia_encrypt( $key );
		}
		if ( ! empty( $in['forget_key'] ) ) {
			$s['key_enc'] = '';
		}
		update_option( TRAZO_IA_OPT, $s, false );
		$msg = 'guardado';
		if ( ! empty( $in['test'] ) ) {
			$r   = trazo_ia_complete( 'Responde solo con el JSON pedido.', 'Devuelve exactamente {"ok":true,"modelo":"<tu nombre de modelo>"}' );
			$msg = is_wp_error( $r ) ? 'error:' . $r->get_error_message() : 'ok:' . mb_substr( $r['text'], 0, 200 );
		}
		set_transient( 'trazo_ia_notice_' . get_current_user_id(), $msg, 120 );
		wp_safe_redirect( admin_url( 'options-general.php?page=trazo-ia' ) );
		exit;
	}
);

function trazo_ia_settings_page(): void {
	$s      = trazo_ia_settings();
	$has    = '' !== trazo_ia_key();
	$notice = get_transient( 'trazo_ia_notice_' . get_current_user_id() );
	delete_transient( 'trazo_ia_notice_' . get_current_user_id() );
	echo '<div class="wrap"><h1>Trazo · IA</h1>';
	if ( $notice ) {
		$ok  = 0 !== strpos( $notice, 'error:' );
		$txt = 'guardado' === $notice ? 'Ajustes guardados.' : ( $ok ? 'Conexión correcta. Respuesta: ' . substr( $notice, 3 ) : 'La IA no respondió: ' . substr( $notice, 6 ) );
		echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $txt ) . '</p></div>';
	}
	echo '<p>La IA redacta planes y propone reglas; no calcula ni aplica nada. Cambiar de IA es cambiar el tipo de API, la URL, el modelo y la clave.</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	wp_nonce_field( 'trazo_ia_save' );
	echo '<input type="hidden" name="action" value="trazo_ia_save">';
	echo '<table class="form-table" role="presentation">';
	echo '<tr><th><label for="ti-p">Tipo de API</label></th><td><select id="ti-p" name="provider">';
	foreach ( array( 'openai_compat' => 'Compatible con OpenAI (OpenAI, OpenRouter, Gemini, Mistral, Groq, DeepSeek, Ollama…)', 'anthropic' => 'Anthropic (Claude, API directa)' ) as $k => $l ) {
		echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['provider'], $k, false ) . '>' . esc_html( $l ) . '</option>';
	}
	echo '</select></td></tr>';
	echo '<tr><th><label for="ti-u">URL base</label></th><td><input id="ti-u" class="regular-text code" name="base_url" value="' . esc_attr( $s['base_url'] ) . '">';
	echo '<p class="description">Solo para «compatible con OpenAI». OpenRouter <code>https://openrouter.ai/api/v1</code> · OpenAI <code>https://api.openai.com/v1</code> · Gemini <code>https://generativelanguage.googleapis.com/v1beta/openai</code> · Mistral <code>https://api.mistral.ai/v1</code> · Groq <code>https://api.groq.com/openai/v1</code> · DeepSeek <code>https://api.deepseek.com/v1</code>. Con Anthropic se ignora.</p></td></tr>';
	echo '<tr><th><label for="ti-m">Modelo</label></th><td><input id="ti-m" class="regular-text code" name="model" value="' . esc_attr( $s['model'] ) . '" placeholder="identificador exacto que da el proveedor"></td></tr>';
	echo '<tr><th><label for="ti-k">Clave de la API</label></th><td><input id="ti-k" type="password" autocomplete="new-password" class="regular-text" name="api_key" value="" placeholder="' . ( $has ? 'Guardada · déjalo vacío para conservarla' : 'Pega aquí la clave' ) . '">';
	echo '<p class="description">' . ( defined( 'TRAZO_IA_KEY' ) ? 'Se usa la constante TRAZO_IA_KEY de wp-config.php.' : 'Se guarda cifrada con las sales del sitio. Nunca llega al navegador.' ) . '</p>';
	if ( $has && ! defined( 'TRAZO_IA_KEY' ) ) {
		echo '<label><input type="checkbox" name="forget_key" value="1"> Borrar la clave guardada</label>';
	}
	echo '</td></tr>';
	echo '<tr><th><label for="ti-t">Tiempo máximo (s)</label></th><td><input id="ti-t" type="number" min="10" max="280" name="timeout" value="' . (int) $s['timeout'] . '"> &nbsp; <label for="ti-x">Máx. tokens de respuesta</label> <input id="ti-x" type="number" min="500" max="16000" name="max_tokens" value="' . (int) $s['max_tokens'] . '"></td></tr>';
	echo '<tr><th><label for="ti-pp">Instrucciones · plan de mejora</label></th><td><textarea id="ti-pp" name="prompt_plan" rows="14" class="large-text code">' . esc_textarea( $s['prompt_plan'] ) . '</textarea><p class="description">Vacíalo y guarda para volver al texto original. El formato de respuesta lo añade el sistema.</p></td></tr>';
	echo '<tr><th><label for="ti-pm">Instrucciones · reglas del país (mapeo de etiquetas)</label></th><td><textarea id="ti-pm" name="prompt_mapeo" rows="10" class="large-text code">' . esc_textarea( $s['prompt_mapeo'] ) . '</textarea></td></tr>';
	echo '</table>';
	echo '<p class="submit"><button class="button button-primary">Guardar</button> <button class="button" name="test" value="1">Guardar y probar conexión</button></p>';
	echo '</form></div>';
}

/* =========================================================================
 * Adaptadores
 * ======================================================================= */

/**
 * Una llamada a la IA configurada. Devuelve ['text','model','provider','ms'] o WP_Error.
 * $extra_turns: turnos adicionales (assistant/user) para el reintento.
 */
function trazo_ia_complete( string $system, string $user, array $extra_turns = array() ) {
	$s   = trazo_ia_settings();
	$key = trazo_ia_key();
	if ( '' === $key ) {
		return new WP_Error( 'trazo_ia_nokey', 'Falta la clave de la IA (Ajustes → Trazo · IA).' );
	}
	if ( '' === $s['model'] ) {
		return new WP_Error( 'trazo_ia_nomodel', 'Falta el modelo (Ajustes → Trazo · IA).' );
	}
	$msgs = array_merge( array( array( 'role' => 'user', 'content' => $user ) ), $extra_turns );

	if ( 'anthropic' === $s['provider'] ) {
		$url  = 'https://api.anthropic.com/v1/messages';
		$head = array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' );
		$body = array( 'model' => $s['model'], 'max_tokens' => (int) $s['max_tokens'], 'system' => $system, 'messages' => $msgs );
	} else {
		$base = rtrim( (string) $s['base_url'], '/' );
		if ( '' === $base ) {
			return new WP_Error( 'trazo_ia_nourl', 'Falta la URL base (Ajustes → Trazo · IA).' );
		}
		$url  = $base . '/chat/completions';
		$head = array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' );
		$body = array(
			'model'      => $s['model'],
			'max_tokens' => (int) $s['max_tokens'],
			'messages'   => array_merge( array( array( 'role' => 'system', 'content' => $system ) ), $msgs ),
		);
	}

	$t0  = microtime( true );
	$res = wp_remote_post( $url, array( 'timeout' => (int) $s['timeout'], 'headers' => $head, 'body' => wp_json_encode( $body ) ) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'trazo_ia_net', 'No hubo respuesta de la IA: ' . $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$j    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( $code < 200 || $code >= 300 ) {
		$m = is_array( $j ) ? ( $j['error']['message'] ?? ( $j['error'] ?? ( $j['message'] ?? '' ) ) ) : '';
		$m = is_string( $m ) ? $m : wp_json_encode( $m );
		return new WP_Error( 'trazo_ia_http', 'La IA respondió ' . $code . ( $m ? ': ' . mb_substr( $m, 0, 300 ) : '' ) );
	}
	if ( 'anthropic' === $s['provider'] ) {
		$text = '';
		foreach ( (array) ( $j['content'] ?? array() ) as $b ) {
			if ( ( $b['type'] ?? '' ) === 'text' ) {
				$text .= (string) $b['text'];
			}
		}
	} else {
		$text = (string) ( $j['choices'][0]['message']['content'] ?? '' );
	}
	$model = (string) ( $j['model'] ?? $s['model'] );
	if ( '' === trim( $text ) ) {
		return new WP_Error( 'trazo_ia_empty', 'La IA devolvió una respuesta vacía.' );
	}
	return array( 'text' => $text, 'model' => $model, 'provider' => $s['provider'], 'ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ) );
}

/** Saca el objeto JSON de la respuesta (con o sin ```json). */
function trazo_ia_parse_json( string $text ) {
	$t = trim( $text );
	$a = strpos( $t, '{' );
	$b = strrpos( $t, '}' );
	if ( false === $a || false === $b || $b < $a ) {
		return null;
	}
	$j = json_decode( substr( $t, $a, $b - $a + 1 ), true );
	return is_array( $j ) ? $j : null;
}

/** Pide JSON, lo valida con $check (devuelve lista de errores) y reintenta una vez explicando el error. */
function trazo_ia_json_task( string $system, string $user, callable $check ) {
	$r = trazo_ia_complete( $system, $user );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$j    = trazo_ia_parse_json( $r['text'] );
	$errs = null === $j ? array( 'no es un JSON válido' ) : $check( $j );
	if ( $errs ) {
		$r2 = trazo_ia_complete(
			$system,
			$user,
			array(
				array( 'role' => 'assistant', 'content' => mb_substr( $r['text'], 0, 6000 ) ),
				array( 'role' => 'user', 'content' => 'Tu respuesta no cumple el formato: ' . implode( '; ', $errs ) . '. Devuelve solo el JSON corregido, sin texto antes ni después.' ),
			)
		);
		if ( is_wp_error( $r2 ) ) {
			return $r2;
		}
		$j    = trazo_ia_parse_json( $r2['text'] );
		$errs = null === $j ? array( 'no es un JSON válido' ) : $check( $j );
		if ( $errs ) {
			return new WP_Error( 'trazo_ia_format', 'La IA no devolvió el formato pedido (' . implode( '; ', $errs ) . '). Prueba de nuevo o cambia de modelo.' );
		}
		$r = $r2;
	}
	return array( 'json' => $j, 'model' => $r['model'], 'provider' => $r['provider'], 'ms' => $r['ms'] );
}

/** Bitácora del panel, si existe. Nunca guarda la clave. */
function trazo_ia_log( string $action, array $detail ): void {
	if ( class_exists( '\\Consolida\\Log' ) ) {
		\Consolida\Log::write( $action, 'ia', 0, $detail );
	}
}

/* =========================================================================
 * Datos del panel y control de cifras
 * ======================================================================= */

function trazo_ia_companies(): array {
	return class_exists( '\\Consolida\\Store' ) ? (array) \Consolida\Store::companies() : array();
}

/** Llama a una ruta del panel con los permisos de quien pide. */
function trazo_ia_panel_get( string $route, array $params = array() ) {
	$req = new WP_REST_Request( 'GET', '/consolida/v1' . $route );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	$res = rest_do_request( $req );
	if ( $res->is_error() ) {
		return new WP_Error( 'trazo_ia_panel', 'El panel no dio los datos (' . $route . '): ' . $res->as_error()->get_error_message() );
	}
	return $res->get_data();
}

/** Indicadores legibles de una sede o del grupo (USD). */
function trazo_ia_kpi_view( array $x ): array {
	$rev = (float) ( $x['revenue'] ?? 0 );
	$ci  = (float) ( $x['ci'] ?? 0 );
	$r   = fn( $v, $d = 1 ) => null === $v ? null : round( (float) $v, $d );
	$out = array(
		'ingresos_usd_m'          => $rev ? $r( $rev / 1e6, 2 ) : null,
		'ebit_usd_m'              => isset( $x['ebit'] ) ? $r( $x['ebit'] / 1e6, 2 ) : null,
		'margen_ebit_pct'         => $rev && isset( $x['ebit'] ) ? $r( $x['ebit'] / $rev * 100 ) : null,
		'nopat_usd_m'             => isset( $x['nopat'] ) ? $r( $x['nopat'] / 1e6, 2 ) : null,
		'capital_invertido_usd_m' => $ci ? $r( $ci / 1e6, 2 ) : null,
		'activo_fijo_neto_usd_m'  => isset( $x['nfa'] ) ? $r( $x['nfa'] / 1e6, 2 ) : null,
		'capital_trabajo_usd_m'   => isset( $x['nowc'] ) ? $r( $x['nowc'] / 1e6, 2 ) : null,
		'rotacion_capital_veces'  => $ci && $rev ? $r( $rev / $ci, 2 ) : null,
		'dias_capital_trabajo'    => $rev && isset( $x['nowc'] ) ? $r( $x['nowc'] / $rev * 365, 0 ) : null,
		'roic_pct'                => isset( $x['roic'] ) ? $r( $x['roic'] * 100 ) : null,
		'roce_pct'                => isset( $x['roce'] ) ? $r( $x['roce'] * 100 ) : null,
		'wacc_pct'                => isset( $x['wacc'] ) ? $r( $x['wacc'] * 100 ) : null,
		'eva_usd_m'               => isset( $x['eva_usd'] ) ? $r( $x['eva_usd'] / 1e6, 2 ) : ( isset( $x['eva'] ) ? $r( $x['eva'] / 1e6, 2 ) : null ),
		'impuesto_pct'            => isset( $x['tax'] ) ? $r( $x['tax'] * 100 ) : null,
		'semaforo'                => $x['light'] ?? null,
		'motivos_semaforo'        => $x['reasons'] ?? null,
	);
	return array_filter( $out, fn( $v ) => null !== $v );
}

/** Todos los números de un arreglo (para comprobar las cifras que cite la IA). */
function trazo_ia_numbers_in( $data, array &$out ): void {
	if ( is_array( $data ) ) {
		foreach ( $data as $v ) {
			trazo_ia_numbers_in( $v, $out );
		}
	} elseif ( is_int( $data ) || is_float( $data ) ) {
		$out[] = (float) $data;
		$out[] = round( (float) $data, 1 );
	} elseif ( is_string( $data ) ) {
		foreach ( trazo_ia_extract_numbers( $data, false ) as $n ) {
			$out[] = $n;
		}
	}
}

/** Convierte «1.234,5», «1,234.5», «8,5» o «13,7» en número. */
function trazo_ia_to_float( string $n ): float {
	$n = str_replace( array( ' ', "\u{00A0}", "\u{202F}" ), '', $n );
	$c = substr_count( $n, ',' );
	$d = substr_count( $n, '.' );
	if ( $c && $d ) {
		// El separador que aparece al final es el decimal.
		if ( strrpos( $n, ',' ) > strrpos( $n, '.' ) ) {
			$n = str_replace( array( '.', ',' ), array( '', '.' ), $n );
		} else {
			$n = str_replace( ',', '', $n );
		}
	} elseif ( $c ) {
		$n = ( 1 === $c && ! preg_match( '/,\d{3}$/', $n ) ) ? str_replace( ',', '.', $n ) : str_replace( ',', '', $n );
	} elseif ( $d > 1 ) {
		$n = str_replace( '.', '', $n );
	}
	return (float) $n;
}

/**
 * Números de un texto. Con $only_figures=true, solo cifras de negocio: seguidas de %, M, millones, pts/puntos o ×,
 * o precedidas de USD/US$/$.
 */
function trazo_ia_extract_numbers( string $s, bool $only_figures = true ): array {
	$out = array();
	$re  = '/(USD|US\$|\$)?\s?(\d[\d.,\x{00A0}\x{202F}]*\d|\d)\s?(%|M\b|millones|pts|puntos|×)?/u';
	if ( ! preg_match_all( $re, $s, $m, PREG_SET_ORDER ) ) {
		return $out;
	}
	foreach ( $m as $g ) {
		$fig = ( $g[1] ?? '' ) !== '' || ( $g[3] ?? '' ) !== '';
		if ( $only_figures && ! $fig ) {
			continue;
		}
		$out[] = trazo_ia_to_float( $g[2] );
	}
	return $out;
}

/** Cifras del texto que no aparecen en los datos permitidos (±0,06 o ±1 %). */
function trazo_ia_unverified( string $text, array $allowed ): array {
	$bad = array();
	foreach ( trazo_ia_extract_numbers( $text, true ) as $n ) {
		$ok = false;
		foreach ( $allowed as $a ) {
			// Sin signo: el texto dice «EVA negativo de 0,38» o «-0,38» y el dato es -0.38.
			if ( abs( abs( $a ) - abs( $n ) ) <= max( 0.06, abs( $a ) * 0.01 ) ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok ) {
			$bad[] = $n;
		}
	}
	return array_values( array_unique( $bad ) );
}

/* =========================================================================
 * Tarea: plan de mejora
 * ======================================================================= */

function trazo_ia_default_prompt_plan(): string {
	return <<<TXT
Eres analista financiero senior de un grupo de empresas con sedes en Guatemala, México, Panamá, Ecuador y Estados Unidos. Escribes para la directiva, en español claro y sin tecnicismos innecesarios.

Te doy una oportunidad de mejora que el sistema ya detectó con reglas, los indicadores de la sede, los del grupo, los de las otras sedes y la mejor sede en cada indicador. Redacta un plan de acción concreto para esa oportunidad.

Reglas:
- Usa solo las cifras de los datos que te doy. No inventes cifras, montos, tasas ni porcentajes.
- Si una acción necesita una meta numérica nueva (por ejemplo, llevar el margen a cierto valor), ponla solo en el campo «meta» y nunca la presentes como un hecho.
- El «contexto del cliente» manda sobre todo lo demás: si dice que algo no se puede hacer, no lo propongas.
- Entre 3 y 5 acciones, ordenadas por impacto. Cada una debe poder asignarse a una persona y revisarse en una fecha.
- Responsable: el responsable de datos o el aprobador de la sede cuando corresponda, o un puesto (por ejemplo, «Gerente de compras de Ecuador»).
- Plazos realistas en días (entre 15 y 365).
- Nada de frases de relleno.
TXT;
}

function trazo_ia_plan_schema_text(): string {
	return <<<TXT
Responde SOLO con un objeto JSON, sin texto antes ni después, con esta forma exacta:
{
  "resumen": "2 o 3 frases: qué pasa y qué se propone",
  "acciones": [
    {
      "titulo": "qué hacer, en imperativo, máx. 120 caracteres",
      "responsable": "persona o puesto",
      "plazo_dias": 90,
      "indicador": "qué indicador mueve (margen EBIT, rotación, ROIC, días de capital de trabajo, EVA…)",
      "meta": "meta propuesta, o cadena vacía",
      "como": "pasos concretos, máx. 400 caracteres",
      "supuestos": "de qué depende, máx. 200 caracteres",
      "riesgos": "qué puede salir mal, máx. 200 caracteres"
    }
  ]
}
TXT;
}

function trazo_ia_check_plan( array $j ): array {
	if ( ! isset( $j['acciones'] ) || ! is_array( $j['acciones'] ) || ! $j['acciones'] ) {
		return array( 'falta la lista «acciones»' );
	}
	$e = array();
	if ( count( $j['acciones'] ) > 6 ) {
		$e[] = 'hay más de 6 acciones';
	}
	foreach ( array_values( $j['acciones'] ) as $i => $a ) {
		if ( ! is_array( $a ) || '' === trim( (string) ( $a['titulo'] ?? '' ) ) ) {
			$e[] = 'la acción ' . ( $i + 1 ) . ' no tiene «titulo»';
		} elseif ( ! is_numeric( $a['plazo_dias'] ?? null ) ) {
			$e[] = 'la acción ' . ( $i + 1 ) . ' no tiene «plazo_dias» numérico';
		}
	}
	return $e;
}

function trazo_ia_task_plan( WP_REST_Request $r ) {
	$opp      = (array) $r->get_param( 'opp' );
	$title    = sanitize_text_field( (string) ( $opp['title'] ?? '' ) );
	$text     = sanitize_textarea_field( (string) ( $opp['text'] ?? '' ) );
	$effects  = array_map( 'sanitize_text_field', array_slice( array_map( 'strval', (array) ( $opp['effects'] ?? array() ) ), 0, 6 ) );
	$contexto = mb_substr( sanitize_textarea_field( (string) $r->get_param( 'contexto' ) ), 0, 3000 );
	$version  = sanitize_key( (string) $r->get_param( 'version' ) );
	if ( '' === $title ) {
		return new WP_Error( 'trazo_ia_bad', 'Falta la oportunidad.', array( 'status' => 400 ) );
	}

	$kpi = trazo_ia_panel_get( '/kpi', $version ? array( 'version' => $version ) : array() );
	if ( is_wp_error( $kpi ) ) {
		return $kpi;
	}
	$cos   = (array) ( $kpi['companies'] ?? array() );
	$v     = $kpi['version'] ?? null;
	$v_key = is_array( $v ) ? (string) ( $v['key'] ?? $version ) : (string) ( $v ?? $version );
	$v_lab = is_array( $v ) ? (string) ( $v['label'] ?? $v_key ) : $v_key;

	// La sede sale del inicio del título: «Ecuador: …».
	$site = null;
	$meta = null;
	foreach ( trazo_ia_companies() as $c ) {
		foreach ( array( $c['country'], $c['name'], $c['code'] ) as $n ) {
			if ( $n && 0 === mb_stripos( $title, $n . ':' ) ) {
				$site = $c['code'];
				$meta = $c;
				break 2;
			}
		}
	}

	$views = array();
	foreach ( $cos as $code => $x ) {
		$views[ $code ] = trazo_ia_kpi_view( (array) $x );
	}
	$best = array();
	foreach ( array( 'margen_ebit_pct' => 'max', 'rotacion_capital_veces' => 'max', 'roic_pct' => 'max', 'dias_capital_trabajo' => 'min' ) as $k => $dir ) {
		$bk = null;
		foreach ( $views as $code => $v ) {
			if ( ! isset( $v[ $k ] ) ) {
				continue;
			}
			if ( null === $bk || ( 'max' === $dir ? $v[ $k ] > $views[ $bk ][ $k ] : $v[ $k ] < $views[ $bk ][ $k ] ) ) {
				$bk = $code;
			}
		}
		if ( $bk ) {
			$best[ $k ] = array( 'sede' => $bk, 'valor' => $views[ $bk ][ $k ] );
		}
	}

	$pkg = array(
		'version'          => $v_lab,
		'oportunidad'      => array( 'titulo' => $title, 'detalle' => $text, 'efectos_calculados' => $effects ),
		'sede'             => $site ? array_merge(
			array(
				'codigo'            => $site,
				'pais'              => $meta['country'],
				'moneda'            => $meta['currency'],
				'participacion_pct' => round( (float) $meta['ownership'] * 100 ),
				'responsable_datos' => $meta['data_owner'],
				'aprobador'         => $meta['approver'],
			),
			$views[ $site ] ?? array()
		) : null,
		'grupo'            => trazo_ia_kpi_view( (array) ( $kpi['group'] ?? array() ) ),
		'mejor_sede'       => $best,
		'otras_sedes'      => array_diff_key( $views, array( (string) $site => 1 ) ),
		'contexto_cliente' => '' === $contexto ? 'Sin contexto adicional.' : $contexto,
	);

	$s      = trazo_ia_settings();
	$system = $s['prompt_plan'] . "\n\n" . trazo_ia_plan_schema_text();
	$user   = "Datos (JSON):\n" . wp_json_encode( $pkg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	$out    = trazo_ia_json_task( $system, $user, 'trazo_ia_check_plan' );
	if ( is_wp_error( $out ) ) {
		trazo_ia_log( 'ia_plan_error', array( 'oportunidad' => $title, 'error' => $out->get_error_message() ) );
		return new WP_Error( $out->get_error_code(), $out->get_error_message(), array( 'status' => 502 ) );
	}

	$allowed = array();
	trazo_ia_numbers_in( $pkg, $allowed );
	$acc = array();
	foreach ( array_slice( array_values( $out['json']['acciones'] ), 0, 6 ) as $a ) {
		$a     = (array) $a;
		$clean = array(
			'titulo'      => mb_substr( sanitize_text_field( (string) ( $a['titulo'] ?? '' ) ), 0, 255 ),
			'responsable' => mb_substr( sanitize_text_field( (string) ( $a['responsable'] ?? '' ) ), 0, 190 ),
			'plazo_dias'  => max( 7, min( 365, (int) ( $a['plazo_dias'] ?? 90 ) ) ),
			'indicador'   => sanitize_text_field( (string) ( $a['indicador'] ?? '' ) ),
			'meta'        => sanitize_text_field( (string) ( $a['meta'] ?? '' ) ),
			'como'        => sanitize_textarea_field( (string) ( $a['como'] ?? '' ) ),
			'supuestos'   => sanitize_textarea_field( (string) ( $a['supuestos'] ?? '' ) ),
			'riesgos'     => sanitize_textarea_field( (string) ( $a['riesgos'] ?? '' ) ),
		);
		// La meta es propuesta por definición; las demás cifras deben salir de los datos.
		$clean['cifras_no_verificadas'] = trazo_ia_unverified( implode( ' ', array( $clean['titulo'], $clean['como'], $clean['supuestos'], $clean['riesgos'] ) ), $allowed );
		$acc[]                          = $clean;
	}
	$resumen = sanitize_textarea_field( (string) ( $out['json']['resumen'] ?? '' ) );

	trazo_ia_log(
		'ia_plan',
		array(
			'oportunidad' => $title,
			'sede'        => $site,
			'modelo'      => $out['model'],
			'proveedor'   => $out['provider'],
			'ms'          => $out['ms'],
			'acciones'    => count( $acc ),
			'con_contexto' => '' !== $contexto,
		)
	);

	return array(
		'version'                => $v_key,
		'version_label'          => $v_lab,
		'sede'                   => $site,
		'resumen'                => $resumen,
		'resumen_no_verificadas' => trazo_ia_unverified( $resumen, $allowed ),
		'acciones'               => $acc,
		'modelo'                 => $out['model'],
		'proveedor'              => $out['provider'],
		'ms'                     => $out['ms'],
	);
}

/* =========================================================================
 * Tarea: reglas del país · mapeo de etiquetas
 * ======================================================================= */

function trazo_ia_default_prompt_mapeo(): string {
	return <<<TXT
Eres contador del corporativo de un grupo de empresas. Cada país manda su presupuesto en Excel con sus propias etiquetas, y cada etiqueta debe ir a una cuenta del catálogo del grupo.

Te doy etiquetas de un país que hoy no tienen cuenta, con su hoja y fila, y el catálogo completo. Para cada etiqueta propone la cuenta del catálogo que corresponde.

Reglas:
- Solo puedes elegir un código que esté en el catálogo que te doy. Si ninguna cuenta corresponde con seguridad, usa "SIN_CUENTA" y explica qué falta saber.
- No adivines: si la etiqueta es ambigua, baja la confianza y dilo.
- Explica el motivo en una frase.
TXT;
}

/** Hoja del Excel → mapa del perfil que la lee. Solo estas dos se proponen por IA. */
const TRAZO_IA_SHEET_MAP = array( 'resultados' => 'pl', 'balance' => 'bs' );

/** Etiquetas abiertas sin cuenta (CAT_UNKNOWN), una por país + hoja + etiqueta. */
function trazo_ia_unknown_labels( string $company = '' ): array {
	global $wpdb;
	$cos = array();
	foreach ( trazo_ia_companies() as $c ) {
		$cos[ (int) $c['id'] ] = $c;
	}
	$rows = $wpdb->get_results( "SELECT id, version_key, company_id, sheet, row_num, message, actual FROM {$wpdb->prefix}csl_issues WHERE code = 'CAT_UNKNOWN' AND status = 'abierta' ORDER BY id DESC", ARRAY_A ); // phpcs:ignore
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$c = $cos[ (int) $r['company_id'] ] ?? null;
		if ( ! $c || ( $company && strtoupper( $company ) !== $c['code'] ) || ! isset( TRAZO_IA_SHEET_MAP[ $r['sheet'] ] ) ) {
			continue;
		}
		if ( ! preg_match( '/«([^»]+)»/u', (string) $r['message'], $m ) ) {
			continue;
		}
		$k = $c['code'] . '|' . $r['sheet'] . '|' . $m[1];
		if ( isset( $out[ $k ] ) ) {
			$out[ $k ]['versiones'][] = $r['version_key'];
			continue;
		}
		$out[ $k ] = array(
			'issue_id'  => (int) $r['id'],
			'pais'      => $c['code'],
			'pais_nombre' => $c['country'],
			'hoja'      => $r['sheet'],
			'fila'      => (int) $r['row_num'],
			'etiqueta'  => $m[1],
			'total_ano' => is_numeric( $r['actual'] ) ? round( (float) $r['actual'], 2 ) : null,
			'moneda'    => $c['currency'],
			'versiones' => array( $r['version_key'] ),
		);
	}
	return array_values( $out );
}

function trazo_ia_catalog(): array {
	global $wpdb;
	return (array) $wpdb->get_results( "SELECT code, name, type, subtype, intercompany FROM {$wpdb->prefix}csl_catalog ORDER BY code", ARRAY_A ); // phpcs:ignore
}

function trazo_ia_task_mapeo( WP_REST_Request $r ) {
	$labels = trazo_ia_unknown_labels( sanitize_key( (string) $r->get_param( 'company' ) ) );
	if ( ! $labels ) {
		return array( 'propuestas' => array(), 'mensaje' => 'No hay etiquetas sin cuenta abiertas.' );
	}
	$cat   = trazo_ia_catalog();
	$codes = array_column( $cat, 'name', 'code' );

	// Ejemplos del mismo país: cómo mapea hoy etiquetas parecidas (ayuda a la IA a seguir la convención).
	$examples = array();
	foreach ( array_unique( array_column( $labels, 'pais' ) ) as $pc ) {
		$p = trazo_ia_panel_get( '/profiles', array( 'company' => $pc ) );
		if ( ! is_wp_error( $p ) ) {
			foreach ( TRAZO_IA_SHEET_MAP as $sheet => $mk ) {
				$map = array_filter( (array) ( $p['profile']['maps'][ $mk ] ?? array() ), fn( $v ) => preg_match( '/^\d{4}$/', (string) $v ) );
				$examples[ $pc ][ $sheet ] = array_slice( $map, 0, 60, true );
			}
		}
	}

	$pkg = array(
		'etiquetas_sin_cuenta' => array_map( fn( $l ) => array( 'id' => $l['issue_id'], 'pais' => $l['pais_nombre'], 'hoja' => $l['hoja'], 'fila' => $l['fila'], 'etiqueta' => $l['etiqueta'], 'total_del_ano' => $l['total_ano'], 'moneda' => $l['moneda'] ), $labels ),
		'catalogo'             => $cat,
		'mapeos_actuales_del_pais' => $examples,
	);
	$schema = "Responde SOLO con un objeto JSON, sin texto antes ni después:\n{\n  \"propuestas\": [\n    { \"id\": 14, \"cuenta\": \"6104\", \"confianza\": \"alta|media|baja\", \"motivo\": \"una frase\" }\n  ]\n}\nUna propuesta por cada id recibido. \"cuenta\" es un código del catálogo o \"SIN_CUENTA\".";
	$ids    = array_column( $labels, 'issue_id' );
	$check  = function ( array $j ) use ( $codes, $ids ) {
		if ( ! isset( $j['propuestas'] ) || ! is_array( $j['propuestas'] ) ) {
			return array( 'falta la lista «propuestas»' );
		}
		$e = array();
		foreach ( $j['propuestas'] as $p ) {
			$c = (string) ( $p['cuenta'] ?? '' );
			if ( ! in_array( (int) ( $p['id'] ?? 0 ), $ids, true ) ) {
				$e[] = 'id ' . wp_json_encode( $p['id'] ?? null ) . ' no está en la lista';
			}
			if ( 'SIN_CUENTA' !== $c && ! isset( $codes[ $c ] ) ) {
				$e[] = 'la cuenta «' . $c . '» no está en el catálogo';
			}
		}
		return $e;
	};
	$s   = trazo_ia_settings();
	$out = trazo_ia_json_task( $s['prompt_mapeo'] . "\n\n" . $schema, "Datos (JSON):\n" . wp_json_encode( $pkg, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ), $check );
	if ( is_wp_error( $out ) ) {
		trazo_ia_log( 'ia_mapeo_error', array( 'error' => $out->get_error_message() ) );
		return new WP_Error( $out->get_error_code(), $out->get_error_message(), array( 'status' => 502 ) );
	}
	$by = array();
	foreach ( $out['json']['propuestas'] as $p ) {
		$by[ (int) $p['id'] ] = $p;
	}
	$props = array();
	foreach ( $labels as $l ) {
		$p       = $by[ $l['issue_id'] ] ?? array( 'cuenta' => 'SIN_CUENTA', 'confianza' => 'baja', 'motivo' => 'La IA no propuso cuenta.' );
		$c       = (string) $p['cuenta'];
		$props[] = array_merge(
			$l,
			array(
				'cuenta'        => $c,
				'cuenta_nombre' => $codes[ $c ] ?? '',
				'confianza'     => in_array( $p['confianza'] ?? '', array( 'alta', 'media', 'baja' ), true ) ? $p['confianza'] : 'baja',
				'motivo'        => sanitize_text_field( (string) ( $p['motivo'] ?? '' ) ),
			)
		);
	}
	trazo_ia_log( 'ia_mapeo', array( 'modelo' => $out['model'], 'proveedor' => $out['provider'], 'ms' => $out['ms'], 'etiquetas' => count( $props ) ) );
	return array(
		'propuestas' => $props,
		'catalogo'   => array_map( fn( $c ) => array( 'code' => $c['code'], 'name' => $c['name'] ), $cat ),
		'modelo'     => $out['model'],
		'ms'         => $out['ms'],
	);
}

/** Guarda en el perfil del país la cuenta que aprobó la persona (por la ruta del panel, con su bitácora). */
function trazo_ia_task_mapeo_aplicar( WP_REST_Request $r ) {
	$pais     = strtoupper( sanitize_key( (string) $r->get_param( 'pais' ) ) );
	$hoja     = sanitize_key( (string) $r->get_param( 'hoja' ) );
	$etiqueta = sanitize_text_field( (string) $r->get_param( 'etiqueta' ) );
	$cuenta   = sanitize_text_field( (string) $r->get_param( 'cuenta' ) );
	$codes    = array_column( trazo_ia_catalog(), 'name', 'code' );
	if ( ! isset( TRAZO_IA_SHEET_MAP[ $hoja ] ) || '' === $etiqueta || ! isset( $codes[ $cuenta ] ) ) {
		return new WP_Error( 'trazo_ia_bad', 'Falta el país, la hoja, la etiqueta o una cuenta válida del catálogo.', array( 'status' => 400 ) );
	}
	$open = array_filter( trazo_ia_unknown_labels( $pais ), fn( $l ) => $l['hoja'] === $hoja && $l['etiqueta'] === $etiqueta );
	if ( ! $open ) {
		return new WP_Error( 'trazo_ia_bad', 'Esa etiqueta ya no está pendiente.', array( 'status' => 409 ) );
	}
	$p = trazo_ia_panel_get( '/profiles', array( 'company' => $pais ) );
	if ( is_wp_error( $p ) ) {
		return $p;
	}
	$profile = (array) $p['profile'];
	$mk      = TRAZO_IA_SHEET_MAP[ $hoja ];
	$profile['maps'][ $mk ][ $etiqueta ] = $cuenta;
	$req = new WP_REST_Request( 'POST', '/consolida/v1/profiles/' . $pais );
	$req->set_param( 'company', $pais );
	$req->set_param( 'profile', $profile );
	$res = rest_do_request( $req );
	if ( $res->is_error() ) {
		return new WP_Error( 'trazo_ia_save', 'El panel no guardó el perfil: ' . $res->as_error()->get_error_message(), array( 'status' => 500 ) );
	}
	trazo_ia_log( 'ia_mapeo_aplicar', array( 'pais' => $pais, 'hoja' => $hoja, 'etiqueta' => $etiqueta, 'cuenta' => $cuenta, 'modelo' => sanitize_text_field( (string) $r->get_param( 'modelo' ) ) ) );
	return array( 'ok' => true, 'pais' => $pais, 'mapa' => $mk, 'etiqueta' => $etiqueta, 'cuenta' => $cuenta, 'cuenta_nombre' => $codes[ $cuenta ] );
}

/* =========================================================================
 * REST
 * ======================================================================= */

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'trazo-ia/v1',
			'/estado',
			array(
				'methods'             => 'GET',
				'permission_callback' => fn() => current_user_can( 'csl_access' ),
				'callback'            => function () {
					$s = trazo_ia_settings();
					return array(
						'configurada' => '' !== trazo_ia_key() && '' !== $s['model'],
						'modelo'      => $s['model'],
						'tareas'      => array(
							'plan-mejora'     => current_user_can( 'csl_board' ),
							'mapeo-etiquetas' => current_user_can( 'csl_corp' ),
						),
					);
				},
			)
		);
		register_rest_route(
			'trazo-ia/v1',
			'/tarea/mapeo-etiquetas/pendientes',
			array(
				'methods'             => 'GET',
				'permission_callback' => fn() => current_user_can( 'csl_corp' ),
				'callback'            => fn( WP_REST_Request $r ) => trazo_ia_unknown_labels( sanitize_key( (string) $r->get_param( 'company' ) ) ),
			)
		);
		register_rest_route(
			'trazo-ia/v1',
			'/tarea/mapeo-etiquetas',
			array(
				'methods'             => 'POST',
				'permission_callback' => fn() => current_user_can( 'csl_corp' ),
				'callback'            => 'trazo_ia_task_mapeo',
			)
		);
		register_rest_route(
			'trazo-ia/v1',
			'/tarea/mapeo-etiquetas/aplicar',
			array(
				'methods'             => 'POST',
				'permission_callback' => fn() => current_user_can( 'csl_corp' ),
				'callback'            => 'trazo_ia_task_mapeo_aplicar',
			)
		);
		register_rest_route(
			'trazo-ia/v1',
			'/tarea/plan-mejora',
			array(
				'methods'             => 'POST',
				'permission_callback' => fn() => current_user_can( 'csl_board' ),
				'callback'            => 'trazo_ia_task_plan',
			)
		);
	}
);

/* =========================================================================
 * Script del panel: se añade a /trazo/ sin tocar el plugin «consolida»
 * ======================================================================= */

add_action( 'template_redirect', 'trazo_ia_inject_script', -1 );

function trazo_ia_inject_script(): void {
	if ( 'panel' !== get_query_var( 'csl_app' ) || ! is_user_logged_in() || ! current_user_can( 'csl_access' ) ) {
		return;
	}
	// Instalado como plugin: el JS va junto a este archivo. En el sandbox de Novamira: wp-content/trazo-ia/.
	// El panel detecta la IA real por la ruta «trazo-ia/panel-ia.js»; ambas la contienen.
	if ( is_file( __DIR__ . '/panel-ia.js' ) && false !== strpos( wp_normalize_path( __DIR__ ), '/plugins/trazo-ia' ) ) {
		$file = __DIR__ . '/panel-ia.js';
		$url  = plugins_url( 'panel-ia.js', __FILE__ );
	} else {
		$file = WP_CONTENT_DIR . '/trazo-ia/panel-ia.js';
		$url  = content_url( 'trazo-ia/panel-ia.js' );
	}
	if ( ! is_file( $file ) ) {
		return;
	}
	$src = $url . '?v=' . TRAZO_IA_VERSION . '-' . filemtime( $file );
	ob_start(
		function ( $html ) use ( $src ) {
			$tag = '<script src="' . esc_url( $src ) . '" defer></script>';
			return false !== strpos( $html, '</body>' ) ? str_replace( '</body>', $tag . '</body>', $html ) : $html;
		}
	);
}

# Trazo · IA (plugin de WordPress)

IA real para el panel `/trazo/` del plugin [`consolida`](../consolida/). Va **aparte**: no cambia ni una línea de
`consolida`. Cuando está activa, el panel lo detecta (`hasRealAI()` busca `trazo-ia/panel-ia.js`) y esconde su
botón de IA simulada; si se desactiva, el panel vuelve solo a la simulación.

## Qué hace

| Tarea | Dónde aparece | Quién | Qué guarda |
| --- | --- | --- | --- |
| **Plan de mejora** | Inicio → cada mejora → «Generar plan con IA» | Directiva (`csl_board`) | Nada sola. La persona edita las acciones y las guarda con `consolida/v1/actions` → «Acciones y seguimiento». |
| **Reglas del país** (etiquetas sin cuenta) | Perfiles de lectura → «Proponer cuentas con IA» | Corporativo (`csl_corp`) | Nada sola. La persona elige la cuenta y la aplica con `consolida/v1/profiles/{país}`. |

Reglas de la capa:

- La IA **no calcula ni aplica nada**: recibe las cifras ya calculadas por el motor (`/kpi`, hallazgos `CAT_UNKNOWN`,
  catálogo) y devuelve una propuesta en JSON.
- Formato validado en el servidor con **un reintento** si llega mal. En reglas del país, una cuenta que no está en el
  catálogo se rechaza.
- Toda cifra que la IA cite y no esté en los datos sale **marcada en rojo** en el plan (las metas propuestas van en su
  propio campo y se muestran como propuestas).
- El «contexto del cliente» (restricciones reales) viaja con cada pedido y manda sobre todo lo demás.
- Bitácora del panel: `ia_plan`, `ia_mapeo`, `ia_mapeo_aplicar` (modelo, proveedor, tiempo). Nunca la clave.

## Cualquier IA

Ajustes → **Trazo · IA**: tipo de API, URL base, modelo y clave. Cambiar de IA es cambiar esos campos.

| Proveedor | Tipo de API | URL base |
| --- | --- | --- |
| Google Gemini (tiene plan gratis) | Compatible con OpenAI | `https://generativelanguage.googleapis.com/v1beta/openai` |
| Groq (tiene plan gratis) | Compatible con OpenAI | `https://api.groq.com/openai/v1` |
| OpenRouter | Compatible con OpenAI | `https://openrouter.ai/api/v1` |
| OpenAI · Mistral · DeepSeek | Compatible con OpenAI | la de cada proveedor |
| Anthropic (Claude) | Anthropic | — |

En el sitio de prueba está configurado `gemini-3.5-flash-lite` (plan gratis de Google AI Studio).

La clave se guarda **cifrada** con las sales del sitio (AES-256-CBC) y nunca llega al navegador; también se puede
definir `TRAZO_IA_KEY` en `wp-config.php`. Las instrucciones de cada tarea se editan en la misma pantalla.

**Datos reales:** en el plan gratis de Gemini, Google puede usar lo que se le envía. Con los datos ficticios de
Meridia no importa; con datos de un cliente, usar un plan de pago o un proveedor que no entrene con la API.

## Instalar

1. Subir esta carpeta a `wp-content/plugins/trazo-ia/` y activar **Trazo · IA** (requiere `consolida` activo).
2. Ajustes → Trazo · IA → tipo de API, URL, modelo y clave → **Guardar y probar conexión**.

En `trazo.kinsta.cloud` hoy corre desde el sandbox de Novamira (`wp-content/novamira-sandbox/trazo-ia.php` y
`wp-content/trazo-ia/panel-ia.js`), porque Novamira no escribe PHP fuera de ahí y Patchstack bloquea subir PHP por el
enlace de carga. El código detecta solo dónde está el JS.

## API (`/wp-json/trazo-ia/v1`, sesión + nonce `wp_rest`)

| Ruta | Permiso | Para |
| --- | --- | --- |
| `GET /estado` | `csl_access` | Si hay IA configurada y qué tareas puede usar la persona |
| `POST /tarea/plan-mejora` `{opp, contexto, version}` | `csl_board` | Plan de 3–5 acciones para una mejora |
| `GET /tarea/mapeo-etiquetas/pendientes` | `csl_corp` | Etiquetas `CAT_UNKNOWN` abiertas (resultados y balance) |
| `POST /tarea/mapeo-etiquetas` | `csl_corp` | Cuenta propuesta para cada etiqueta |
| `POST /tarea/mapeo-etiquetas/aplicar` `{pais, hoja, etiqueta, cuenta}` | `csl_corp` | Guarda la cuenta aprobada en el perfil del país |

## Cómo se probó

- Con IA simulada (`pre_http_request`): armado de datos, reintento por formato, cuenta fuera de catálogo rechazada,
  cifra inventada marcada, versión elegida en el panel.
- Con Gemini real en el panel (21-11-2026, panel 0.6): plan de Ecuador en 2,4 s respetando el contexto («no cambiar
  de proveedor», «no contratar»); cuenta 6104 · Administración propuesta para «Regional Consulting Fees» (Panamá).
- Desde la 0.7 los botones del panel viven en `.opp-acts`; el botón real se pone ahí. **Sin probar en pantalla con
  la 0.8** porque la demo estaba vacía.

Autora de la capa de IA: Fer Santizo, con Claude.

# Trazo – Consolidación multiempresa (plugin de WordPress)

Panel privado en `/trazo/` que consolida el presupuesto del Grupo Meridia desde el Excel de cada país.
Documento completo de la solución: [`../SOLUCION.md`](../SOLUCION.md). Contrato técnico: [`../CONTRACT.md`](../CONTRACT.md).

- Autor: Jonathan Diaz · https://www.soydiaz.com · SolucionWeb
- Requiere: WordPress 6.5+, PHP 8.1+ con `zip`, `xmlreader`, `simplexml`, `mbstring`, `json`. Sin Composer ni
  librerías externas; el panel no usa CDN.

## Instalación

1. Subir la carpeta `consolida/` a `wp-content/plugins/` y activar el plugin.
2. Copiar los datos del reto a `wp-content/datos-meridia/` (`1_Paises/…` y `2_Grupo/Grupo_Corporativo.xlsx`).
3. Entrar a `/trazo/` como administrador → Inicio → **Importar datos de Meridia** (importa el corporativo, carga
   los 20 archivos por el flujo normal de subida y envía los que no tienen críticos).
4. Opcional: crear usuarios de prueba por rol desde la misma carga.

## Roles

| Rol | Puede |
| --- | --- |
| Administrador | Todo, incluida la importación de datos y el reinicio. |
| `csl_corporativo` | Tasas, perfiles de lectura, consolidar, desviaciones, indicadores, hallazgos, acciones, bitácora. |
| `csl_pais` | Subir, validar, explicar avisos, aprobar y enviar **solo sus países**. |
| `csl_directiva` | Resumen de una página, indicadores, desviaciones (lectura) y acciones. |

## Estructura

```
consolida.php            Arranque, autoload, módulos, activación
includes/
  Schema.php             Tablas wp_csl_* (dbDelta) y migraciones
  Roles.php · Front.php  Roles y capacidades · página /trazo/ con CSP (alias /consolida/) y nonce REST
  Store.php · Log.php    Persistencia · bitácora
  Demo.php               Importación de datos-meridia/ y reinicio
  EngineBridge.php       Único punto de contacto entre WordPress y el motor
  Rest/                  API /wp-json/consolida/v1 (Admin, Data, Group)
  Engine/                Motor en PHP puro (sin WordPress)
    Xlsx/Reader, Writer  Lectura y escritura de .xlsx con protección contra zip bombs y XXE
    Corporate            Grupo_Corporativo.xlsx → tasas, parámetros, catálogo, maestros, propuestas, historial
    Profiles             Perfiles de lectura de los 5 países (editables desde el panel)
    CountryReader        Excel de país → Dataset normalizado con hoja/fila/columna de cada cifra
    Validator            Reglas por archivo (6 categorías del historial)
    Intercompany         Conciliación mensual por tipo, saldos, préstamos y diferencias de tiempo
    Fx                   Conversión mensual con tasas oficiales y CTA
    Consolidator         Eliminaciones, margen no realizado, minoritarios
    Variance             Volumen, precio, tipo de cambio, intercompañía (suma exacta)
    Kpi                  ROIC, ROCE, EVA, semáforo, indicadores copiados, propuestas
    History              Preventivo, evolutivo y escenarios
assets/panel.js · panel.css   Panel de una sola página (sin dependencias)
tests/run.php            167 pruebas (unitarias + integración con los 21 archivos) → tests/hallazgos.md
tools/inspect.php        Inspección de un archivo o de toda la carpeta de datos
```

## API (`/wp-json/consolida/v1`, sesión + nonce `wp_rest`)

| Ruta | Para |
| --- | --- |
| `GET /me` · `/meta` · `/home?version=` · `/versions` · `/rates?version=` | Usuario, listas, inicio por rol, versiones y tasas |
| `GET /findings?version=&company=&category=&severity=&status=` | Hallazgos con filtros y conteo por categoría |
| `POST /corporate/import` | Importar `Grupo_Corporativo.xlsx` |
| `GET/POST /companies` · `/profiles` · `/versions` · `/rates` | Países, perfiles de lectura, versiones y tasas (corporativo) |
| `POST /uploads` · `GET /uploads/{id}` · `/uploads/{id}/file` | Subir y ver la validación; descargar el original |
| `POST /uploads/{id}/approve` · `/send` · `/issues/{id}/explain` | Aprobar, enviar y explicar avisos (país) |
| `GET /ic` · `POST /consolidate` · `GET /results/latest` · `/trace` | Intercompañía, consolidación y traza hasta la fila |
| `GET /variance?a=&b=` · `/kpi` · `/proposals` · `/history` · `/quality` | Desviaciones, indicadores, propuestas, evolutivo y calidad |
| `GET /summary` · `GET/POST /actions` | Resumen de una página y acciones (directiva) |
| `POST /demo/load` · `/demo/reset` | Importar datos del reto · reiniciar (administrador) |

## Desarrollo

```bash
php tests/run.php                              # motor completo contra ../datos-meridia
php tools/inspect.php ../datos-meridia/1_Paises/Pais2_Mexico/Mexico_Presupuesto_2027.xlsx
```

Convenciones: interfaz y mensajes en español (Guatemala), nombres en inglés, comentarios en español; las
decisiones fuera del contrato están marcadas en el código con `// CONTRATO:`.

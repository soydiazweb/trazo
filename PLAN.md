# Trazo · plan de creación por fases con agentes

Sistema web (plugin de WordPress) que consolida el presupuesto del **Grupo Meridia** (Guatemala, México, Panamá,
Ecuador y Estados Unidos; GTQ, MXN y USD) a partir de los 20 Excel de los países, cada uno en su propio formato, y del
`Grupo_Corporativo.xlsx` (tasas, parámetros, catálogo, propuestas e historial). Detecta los errores, elimina lo
intercompañía, explica cada desviación y calcula ROIC, ROCE y EVA. Contrato técnico: `CONTRACT.md` (v2, datos reales).
Sigue la misma estructura que SW HQ (panel DMM): plugin con tablas propias, API REST y un panel de una sola página.

- Sitio destino: `https://trazo.kinsta.cloud` (vía Novamira).
- Panel: `/trazo/` · API: `/wp-json/consolida/v1`.
- Roles: **País** (responsable de datos), **Corporativo** (finanzas del grupo), **Directiva**; el administrador ve todo.
- Base de la pizarra: módulos 1–5 + Trazabilidad y el flujo de 10 pantallas.

## Fases

| Fase | Qué sale | Quién la hace | Módulo de la pizarra |
| --- | --- | --- | --- |
| 0 · Contrato | `CONTRACT.md`: tablas, formatos de datos, plantilla Excel, reglas de validación, fórmulas y API | Coordinador | Todos |
| 1a · Motor de Excel | Lector y escritor de .xlsx sin librerías (hecho) | Agente A | 1 · Un solo número desde cada país |
| 1b · Lectores por país | Corporativo, perfiles de lectura de los 5 formatos y Dataset normalizado con la fila de origen de cada cifra | Agente R | 1 · Un solo número desde cada país |
| 2 · Motor financiero | Validación, conciliación intercompañía, conversión de moneda, consolidación con eliminaciones y minoritarios, desviaciones (volumen, precio, tipo de cambio, intercompañía, otros) y KPIs | Agente B | 2, 3, 4 y 5 |
| 3 · Capa WordPress | Arranque, tablas, roles, ruta del panel, API REST, permisos por empresa, bitácora | Agente C | Trazabilidad |
| 4 · Panel | Las 10 pantallas del flujo + reglas, empresas, bitácora y marcador de demo | Agente D | Flujo del sistema |
| 5 · Integración y pruebas | Unir las piezas, pruebas automáticas contra las respuestas correctas, revisión de seguridad | Coordinador | «Para el demo» |
| 6 · Despliegue | Subir el .zip a Trazo con Novamira, activar, cargar demo, crear usuarios de prueba | Coordinador | — |

Las fases 1 a 4 corren en paralelo porque todas siguen el mismo contrato; la 5 las une.

## Qué prueba el demo

1. Funciona con los Excel de los 6 países.
2. Encuentra los errores sembrados (marcador «encontrados / sembrados» siempre visible).
3. Un director entiende el resumen sin ayuda.
4. Cada cifra se rastrea hasta el archivo, la hoja y la fila que la originó.

## Segunda fase (fuera de esta entrega)

- Lector configurable por país para formatos de Excel distintos a la plantilla (hoy: plantilla oficial con alias de columnas).
- Texto explicativo redactado por IA (hoy: plantillas de texto).
- Exportación a PDF (hoy: vista imprimible).

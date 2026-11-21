# Trazo · solución al reto «Consolidación multiempresa» (Grupo Meridia)

> **El reto.** ¿Cómo lograr que el presupuesto de un grupo se consolide solo, en una moneda y sin capturas manuales,
> y que cada cifra se explique sola?

**Trazo** es un sistema web (plugin de WordPress, igual en estructura al panel DMM de SolucionWeb) que recibe el
Excel de cada país tal como lo entrega, lo lee aunque cada país use otro formato, frena los errores antes de que
entren al número del grupo, elimina lo intercompañía, convierte todo a dólares con las tasas oficiales, explica cada
desviación y calcula ROIC, ROCE y EVA. Cada cifra se puede rastrear hasta el archivo, la hoja, la fila y la columna que
la originó.

- Sitio: `https://trazo.kinsta.cloud/trazo/`
- Código: `consolida/` · Contrato técnico: [`CONTRACT.md`](CONTRACT.md) · Plan por fases: [`PLAN.md`](PLAN.md)
- Hallazgos completos generados por el sistema: [`consolida/tests/hallazgos.md`](consolida/tests/hallazgos.md)
- Pizarra de conceptualización: https://claude.ai/artifact/Wk3SubbsmY9mgU4zTRjDyW

---

## 1. Qué problema resuelve

| Dolor del reto | Cómo lo resuelve Trazo |
| --- | --- |
| **Consolidación manual**: cada empresa usa otro formato y los totales se suman a mano | Un **perfil de lectura por país** entiende su formato (ancho o largo, idioma, fechas, escala, nombres o códigos de cuenta) y lo traduce al catálogo único del grupo. La consolidación es un botón. |
| **Ventas entre empresas** que se cuentan doble | Conciliación intercompañía **mes a mes y por tipo** (venta de producto, cuota de administración, préstamo e intereses) desde los dos lados, y eliminación automática de ventas, costos, saldos, préstamos, intereses y **margen no realizado en inventario**. |
| **Varias monedas** sin tasa oficial común | Tasas oficiales del corporativo por mes y versión: resultados a tasa promedio, balance a tasa de cierre, saldo inicial a la tasa de cierre anterior; diferencia de conversión (CTA) informada aparte. |
| **Rentabilidad a mano** (ROIC, ROCE, EVA copiados) | Se **calculan** desde los estados de cada país y del consolidado, y se comparan con lo que el país reportó: cada diferencia es un hallazgo con su impacto. |
| **Desviaciones sin explicar** | Cada variación de ventas se descompone en **volumen, precio, tipo de cambio e intercompañía**, sumando exacto, con una frase en español sencillo y acceso hasta la fila del Excel. |

## 2. Los datos que se analizaron

21 archivos ficticios del Grupo Meridia (carpeta [`datos-meridia/`](datos-meridia/)):

- **20 archivos de país**: Guatemala (GTQ), México (MXN), Panamá, Ecuador y Estados Unidos (USD), cada uno con 4
  versiones: Presupuesto 2026, Real 2026, Presupuesto 2027 v1 y Presupuesto 2027 v2. Hojas: portada, resultados
  mensuales (volumen, precio, ventas por línea, costos, gastos, financieros, impuesto), balance mensual con saldo
  inicial, ventas por producto y canal, intercompañía, inventario comprado a empresas del grupo, capex y, en 2027,
  los indicadores que calculó cada país.
- **1 archivo corporativo**: tasas oficiales por mes y versión, parámetros por país (moneda, impuesto, WACC,
  participación: Ecuador 75 % y Estados Unidos 90 %), catálogo único de 36 cuentas, 12 productos, 4 canales, las 4
  propuestas de inversión 2027 tal como las presentó cada país, el historial de ciclos 2024–2026 (errores por tipo,
  retrasos, días y horas para consolidar) y los indicadores reales 2023–2025.

**Cinco formatos distintos — el corazón del reto:**

| País | Forma | Idioma | Fechas | Escala | Particularidad |
| --- | --- | --- | --- | --- | --- |
| Guatemala | meses en columnas | español | Ene…Dic | unidades | nombres de cuenta propios |
| México | formato largo | español | dd/mm/aaaa | **miles** | columna de unidad por fila |
| Panamá | meses en columnas | **inglés** | **mm/dd/aaaa** | unidades | cuentas en inglés |
| Ecuador | formato largo | — | aaaa-mm | unidades | **códigos contables locales** y filas «Total Gastos» escritas a mano |
| Estados Unidos | formato largo | **inglés** | Mon-aaaa | **miles** | **números guardados como texto** («1,918.676») |

El lector se verificó **celda por celda**: 21,459 valores comparados contra el Excel original, sin diferencias.

## 3. Cómo funciona (procesos)

```
 Excel del país ──► 1 Lectura con perfil ──► 2 Reglas del grupo ──► 3 Validación ──► Aprobación y envío del país
                                                                                         │
 Corporativo (tasas, catálogo, parámetros) ─────────────────────────────────────────────┤
                                                                                         ▼
          5 Rentabilidad + preventivo/evolutivo/soluciones ◄── 4 Desviaciones ◄── Consolidación (IC + moneda)
```

| # | Módulo (empresarial) | Módulo (técnico) | Qué hace |
| --- | --- | --- | --- |
| 1 | Un solo número desde cada país | Ingesta y consolidación (ETL por entidad) | Lee el Excel con el perfil del país, normaliza a moneda local en unidades, mapea al catálogo, guarda la fila de origen de cada cifra, consolida y elimina lo intercompañía. |
| 2 | Mismas reglas en todos los países | Motor de reglas: conversión y políticas | Tasas oficiales por mes (promedio, cierre, presupuesto), conversión, CTA, WACC, impuesto y participación por país desde el corporativo. |
| 3 | Errores frenados antes del número del grupo | Validación y conciliación intercompañía | Reglas de formato, catálogo, cálculos y totales, intercompañía, tipo de cambio e indicadores copiados (las mismas 6 categorías del historial). Cada hallazgo dice archivo, hoja, fila, columna, mes, valor actual, esperado e impacto en USD. |
| 4 | Cada cifra se explica sola | Análisis de varianzas con drill-down | Volumen, precio, tipo de cambio, intercompañía y otros, sumando exacto; texto explicativo; drill-down grupo → país → cuenta → mes → fila del Excel. |
| 5 | Rentabilidad real del grupo | Motor de KPIs financieros | ROIC, ROCE y EVA por país y grupo; comparación con lo reportado; propuestas de inversión recalculadas; preventivo, evolutivo y soluciones. |
| Base | Trazabilidad | Linaje de datos y bitácora | Archivo original guardado con su huella sha256, fila y columna de cada cifra, bitácora de quién cargó, aprobó, cambió reglas y consolidó. |

**Flujo por rol** (10 pantallas):

1. **País · responsable de datos**: entra con su rol → carga el Excel → ve la validación (errores con hoja y fila,
   explica los avisos, corrige y vuelve a subir) → aprueba y envía.
2. **Corporativo · finanzas del grupo**: revisa tasas y perfiles de lectura → consolida (estado de los 5 países,
   conciliación intercompañía, eliminaciones, minoritarios) → explica desviaciones → ve ROIC, ROCE y EVA.
3. **Directiva**: resumen de una página (semáforo por país, mayores desviaciones, decisión sugerida) → acciones y
   seguimiento.

## 4. Lo que encontró el sistema

No había una lista de respuestas: el sistema encontró los errores por sí mismo. Las versiones Presupuesto 2026,
Real 2026 y Presupuesto 2027 v2 salen **sin errores críticos**; todos los errores sembrados están en el
**Presupuesto 2027 v1**:

| Versión | Críticos | Avisos |
| --- | ---: | ---: |
| Presupuesto 2026 | 0 | 4 |
| Real 2026 | 0 | 6 |
| **Presupuesto 2027 v1** | **26** | 7 |
| Presupuesto 2027 v2 | 0 | 6 |

**Presupuesto 2027 v1, por país** (detalle completo con hoja, fila y columna en `consolida/tests/hallazgos.md`):

- **Guatemala**: ventas de Bebidas de agosto 17,477,757 GTQ, pero volumen × precio y el detalle dan 16,805,536 GTQ ·
  celda vacía en «Publicidad y Promoción» de octubre · ROIC reportado 11.3 % contra 13.1 % calculado · venta a
  Estados Unidos de junio que no cuadra (592,900 contra 563,255 USD) · venta de Bebidas a Ecuador de septiembre que
  Ecuador no registra.
- **México**: todo el balance de abril cargado en unidades aunque dice «miles» · tipo de cambio usado 18.50 contra
  19.85 oficial (6.8 %) · venta a Estados Unidos de mayo pactada en MXN de un lado y en USD del otro · compra de
  Confitería a Estados Unidos de julio a diciembre (2.4 M USD) que Estados Unidos no reporta.
- **Panamá**: balance de septiembre descuadrado por 185,000 USD · cuenta nueva «Regional Consulting Fees» fuera del
  catálogo (191,618 USD) · columna de noviembre fechada «11/30/2026» en un archivo 2027 · ventas por producto de
  noviembre que no suman las ventas del mes.
- **Ecuador**: filas «Total Gastos» de mayo y julio que no cuadran (en julio por una cuenta de Mercadeo en negativo) ·
  impuesto de septiembre cargado en otra escala (5.38 contra ~4,099) · intereses del préstamo de agosto duplicados en
  resultados y en intercompañía · WACC reportado 11 % contra 13 % del corporativo.
- **Estados Unidos**: ventas a empresas del grupo de Confitería en el detalle por canal (2.4 M USD) que no están en
  resultados · capital de trabajo y activo fijo reportados que no salen de su propio balance · lado contrario de las
  diferencias con México y Guatemala.

**Avisos (no bloquean):** operaciones México–Estados Unidos pactadas en MXN (se convierten con la tasa oficial),
números guardados como texto en Estados Unidos, patrimonio negativo de Ecuador, y un embarque de Guatemala a Ecuador
de 180,000 USD en tránsito (diferencia de tiempo, no error).

**Frente al historial de errores** del corporativo, el Presupuesto 2027 v1 repite el patrón de cada país (Guatemala 6,
Panamá 4, Ecuador 10, Estados Unidos 8 hallazgos, igual que en el Presupuesto 2026; México baja de 8 a 5). Ese
patrón es lo que alimenta el módulo preventivo.

## 5. Logros del módulo 5 · Rentabilidad real del grupo

**ROIC, ROCE y EVA calculados, no copiados.** El sistema descubrió cómo calculan los países (capital promedio =
(saldo inicial + saldo de diciembre) / 2, comprobado al centavo contra Guatemala y Panamá) y con esa misma regla
recalcula todo desde los estados:

| Versión | Ingresos USD | EBIT USD | Utilidad atribuible al grupo | Minoritarios | ROIC grupo | WACC grupo | EVA grupo USD |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Presupuesto 2026 | 230.2 M | 33.4 M | 22.6 M | 0.55 M | 14.7 % | 10.9 % | 6.40 M |
| Real 2026 | 227.7 M | 33.0 M | 22.4 M | 0.53 M | 14.6 % | 10.9 % | 6.19 M |
| Presupuesto 2027 v1 | 238.8 M | 32.4 M | 22.3 M | 0.49 M | 14.4 % | 10.8 % | 5.92 M |
| Presupuesto 2027 v2 | 236.9 M | 30.8 M | 21.0 M | 0.36 M | 13.6 % | 10.8 % | 4.67 M |

**Semáforo por país (Presupuesto 2027 v2):** Guatemala 12.9 % vs WACC 11 % 🟢 · México 16.8 % vs 12 % 🟢 · Panamá
13.7 % vs 10 % 🟢 · **Ecuador 5.6 % vs 13 % 🔴 (EVA −0.68 M USD)** · Estados Unidos 15.7 % vs 9 % 🟢. Ecuador destruye
valor en todas las versiones desde el Real 2026: es la primera decisión para la directiva.

**Indicadores copiados detectados:** ROIC de Guatemala, tipo de cambio de México, WACC de Ecuador y los componentes
del capital invertido de Estados Unidos; cada uno con su impacto en USD.

**Propuestas de inversión recalculadas:** P1 México (16.5 % vs 12 %), P2 Panamá (13.4 % vs 10 %) y P4 Ecuador
(20.6 % vs 13 %) se sostienen. **P3 Estados Unidos «Entrada a cadena de supermercados» no se sostiene como
«aprobar»**: su ROIC de 9.75 % deja solo 0.75 puntos sobre el WACC de 9 %; con las reglas del grupo corresponde
**«ajustar»** antes de comprometer 13.65 M USD.

**Desviaciones explicadas** (ventas del grupo, sumando exacto):
- Presupuesto 2027 v1 vs Real 2026: **+11.1 M USD (+4.9 %)** = +14.4 M por volumen, +2.3 M por precio, −5.3 M por
  tipo de cambio (casi todo México) y −0.4 M por más ventas intercompañía eliminadas.
- Presupuesto 2027 v2 vs v1: **−1.9 M USD (−0.8 %)**: más volumen en Guatemala y Ecuador, mejores precios en Panamá,
  el peso mexicano en contra (−1.0 M) y más ventas entre empresas que se eliminan (−3.1 M).
- Real 2026 vs Presupuesto 2026: **−2.4 M USD (−1.1 %)**: precios más bajos en México y menos volumen en Guatemala y
  Ecuador, compensados en parte por Estados Unidos.

**Preventivo · que el error no vuelva:** ranking de calidad por país comparado con 2024–2026; para cada país la
categoría que más se repite se convierte en una regla de la plantilla (p. ej. Ecuador: cálculos y totales → quitar
las filas de totales escritas a mano; México: tipo de cambio → la tasa sale del corporativo, no se escribe).

**Evolutivo · hacia dónde va:** serie 2023–2027 por país de ingresos, EBIT, ROIC, WACC y EVA (historial real +
versiones 2026–2027), y escenarios de tipo de cambio y volumen ±10 % sobre el EVA.

**Soluciones · nuevos procesos en la empresa:**
1. Ciclo presupuestario único: mismo calendario, perfil de lectura y tasas para todos los países.
2. Responsable de datos por país que aprueba su carga en el sistema antes de enviarla.
3. Acuerdo intercompañía: cada venta interna se pacta con precio y moneda y la confirman ambas empresas.
4. Comité mensual de desviaciones con acción, responsable y fecha (pantalla de acciones).
5. Política de inversión: no se aprueba un proyecto cuyo ROIC esperado no supere el WACC con margen.
6. Gobierno de reglas: tasas, perfiles y fórmulas solo cambian con aprobación del corporativo (bitácora).

## 6. Cómo se construyó (plan por fases con agentes)

| Fase | Resultado | Responsable |
| --- | --- | --- |
| 0 · Contrato | `CONTRACT.md` v2 basado en los datos reales | Coordinador |
| 1a · Excel | Lector y escritor de .xlsx propios, sin librerías (protección contra archivos maliciosos) | Agente A |
| 1b · Lectura | Corporativo, 5 perfiles de lectura y Dataset normalizado con la fila de origen de cada cifra | Agente R |
| 2 · Motor | Validación, intercompañía, conversión, consolidación, desviaciones, KPIs, historial (167 pruebas) | Agente F |
| 3 · WordPress | Tablas, roles, API REST con permisos por país, importación del corporativo y de los 20 archivos | Agente C |
| 4 · Panel | Las pantallas del flujo, responsive, accesible, sin librerías externas | Agente D |
| 5 · Integración | Pruebas contra los 21 archivos, hallazgos, documentación | Coordinador |
| 6 · Despliegue | Instalación en Trazo vía Novamira e historial en GitHub | Coordinador |

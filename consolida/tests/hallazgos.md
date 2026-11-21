# Hallazgos del motor sobre los datos reales del Grupo Meridia

Generado por `php tests/run.php` el 2026-11-21 20:22 UTC con los 21 archivos de `datos-meridia/`. Lectura (Agente R) + reglas, conciliación, consolidación, KPIs y desviaciones (Agente F). Impacto en USD (valor absoluto).

## Resumen por versión y categoría

| Versión | Críticos | Avisos | Formato y escala | Catálogo y periodo | Cálculos y totales | Intercompañía | Tipo de cambio | Indicadores copiados |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Presupuesto 2026 | 0 | 4 | 1 | 1 | 0 | 0 | 2 | 0 |
| Real 2026 | 0 | 6 | 1 | 1 | 0 | 2 | 2 | 0 |
| Presupuesto 2027 v1 | 26 | 7 | 3 | 6 | 7 | 10 | 3 | 4 |
| Presupuesto 2027 v2 | 0 | 6 | 1 | 1 | 0 | 2 | 2 | 0 |

## Presupuesto 2027 v1 frente al historial (errores por categoría)

| País | Formato y escala | Catálogo y periodo | Cálculos y totales | Intercompañía | Tipo de cambio | Indicadores copiados | Total 2027 v1 | Total Ppto 2026 (historial) | Tendencia | Lo que más se repite |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | --- |
| GT | 0 | 0 | 2 | 3 | 0 | 1 | 6 | 6 | igual | Indicadores copiados |
| MX | 1 | 0 | 0 | 2 | 2 | 0 | 5 | 8 | mejora | Tipo de cambio |
| PA | 0 | 2 | 2 | 0 | 0 | 0 | 4 | 4 | igual | Catálogo y periodo |
| EC | 1 | 4 | 2 | 2 | 0 | 1 | 10 | 10 | igual | Cálculos y totales |
| US | 1 | 0 | 1 | 3 | 1 | 2 | 8 | 8 | igual | Intercompañía |

Nota: el historial cuenta errores por país; aquí cada hallazgo intercompañía se cuenta en las dos empresas implicadas, y «GRUPO» agrupa los saldos intercompañía que no se pueden atribuir a un país.

## Presupuesto 2026 (`ppto_2026`)

### EC · 1 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| SIGN | aviso | catalogo_periodo | balance | 13 | C | saldo inicial | La cuenta 3101 (Patrimonio) tiene saldo negativo en 13 meses (primero en saldo inicial: -2,318,210 USD); se espera positivo. |  |

### MX · 1 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| FX_IC_CURRENCY | aviso | tipo_cambio | intercompania | 4 | F | ene | 12 operaciones con US (venta de producto) están pactadas en MXN y no en USD (total 125,000,000 MXN); se convierten con la tasa oficial. |  |

### US · 2 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| FMT_TEXT_NUMBER | aviso | formato_escala | resultados | 2 | C |  | 1,459 números vienen guardados como texto (p. ej. resultados!C2 = «95,172»); se leyeron igual. Hojas: resultados (324), balance (143), ventas_detalle (912), intercompania (42), inventario_grupo (26), capex (12). |  |
| FX_IC_CURRENCY | aviso | tipo_cambio | intercompania | 4 | F | ene | 12 operaciones con MX (venta de producto) están pactadas en MXN y no en USD (total 125,000,000 MXN); se convierten con la tasa oficial. |  |

## Real 2026 (`real_2026`)

### EC · 2 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| SIGN | aviso | catalogo_periodo | balance | 13 | C | saldo inicial | La cuenta 3101 (Patrimonio) tiene saldo negativo en 13 meses (primero en saldo inicial: -2,318,210 USD); se espera positivo. |  |
| IC_TIMING | aviso | intercompania |  |  |  | dic | En dic, venta de producto de Snacks entre GT y EC tiene una diferencia de tiempo (180,000.00 USD): «Embarque en tránsito; EC lo registra en ene-2027». | 180,000 |

### GT · 1 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| IC_TIMING | aviso | intercompania | intercompania | 107 | F | dic | En dic, venta de producto de Snacks entre GT y EC tiene una diferencia de tiempo (180,000.00 USD): «Embarque en tránsito; EC lo registra en ene-2027». | 180,000 |

### MX · 1 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| FX_IC_CURRENCY | aviso | tipo_cambio | intercompania | 4 | F | ene | 12 operaciones con US (venta de producto) están pactadas en MXN y no en USD (total 125,000,000 MXN); se convierten con la tasa oficial. |  |

### US · 2 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| FMT_TEXT_NUMBER | aviso | formato_escala | resultados | 2 | C |  | 1,459 números vienen guardados como texto (p. ej. resultados!C2 = «99,138»); se leyeron igual. Hojas: resultados (324), balance (143), ventas_detalle (912), intercompania (42), inventario_grupo (26), capex (12). |  |
| FX_IC_CURRENCY | aviso | tipo_cambio | intercompania | 4 | F | ene | 12 operaciones con MX (venta de producto) están pactadas en MXN y no en USD (total 125,000,000 MXN); se convierten con la tasa oficial. |  |

## Presupuesto 2027 v1 (`ppto_2027_v1`)

### EC · 10 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| CALC_TOTAL | critico | calculos_totales | resultados | 141 | C | may | En may, la fila «Total Gastos» dice 418,825 USD y la suma del detalle da 373,825 USD (diferencia 45,000). | 45,000 |
| CALC_TOTAL | critico | calculos_totales | resultados | 199 | C | jul | En jul, la fila «Total Gastos» dice 378,742 USD y la suma del detalle da 301,494 USD (diferencia 77,248). La diferencia se explica porque 6103 viene en negativo en el detalle y el total la suma en positivo. | 77,248 |
| DATA_DUP | critico | catalogo_periodo | resultados | 233 | C | ago | «7220» (7112) aparece dos veces para agosto: resultados!C232 = 58,333.33 y resultados!C233 = 58,333.33. Se tomó el primero. |  |
| DATA_DUP | critico | catalogo_periodo | intercompania | 47 | F | ago | La operación intercompañía de la fila 47 («Intereses préstamo», PA, 58,333.33 USD) repite exactamente la fila 46 de «intercompania»; se tomó solo una. |  |
| FMT_SCALE_JUMP | critico | formato_escala | resultados | 263 | C | sep | «8110 (cuenta 8101)» en sep vale 5 y en los demás meses ronda 4,099: parece cargado en miles en vez de unidades. | 5,375 |
| KPI_WACC | critico | indicadores | indicadores_reportados | 11 | B |  | El país reporta un WACC de 11.0 % y el corporativo fija 13.0 % para EC. | 170,697 |
| IC_MISSING | critico | intercompania |  |  |  | sep | En sep, GT reporta venta de producto de Bebidas a EC por 121,275.00 USD, pero EC no lo registra. | 121,275 |
| SIGN | aviso | catalogo_periodo | balance | 13 | C | saldo inicial | La cuenta 3101 (Patrimonio) tiene saldo negativo en 13 meses (primero en saldo inicial: -2,260,631 USD); se espera positivo. |  |
| SIGN | aviso | catalogo_periodo | resultados | 196 | C | jul | La cuenta 6103 (Mercadeo) tiene saldo negativo en 1 mes (primero en jul: -38,624 USD); se espera positivo. La fila «Total Gastos» del mes la suma en positivo: el signo está mal (ver CALC_TOTAL). | 77,248 |
| IC_TIMING | aviso | intercompania | intercompania | 6 | F | ene | En ene, venta de producto de Snacks entre GT y EC tiene una diferencia de tiempo (-180,000.00 USD): «Factura GT de dic-2026 recibida en ene-2027 (en tránsito)». | 180,000 |

### GT · 6 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| CALC_VOLPRICE | critico | calculos_totales | resultados | 11 | I | ago | En ago, las ventas netas de Bebidas son 17,477,757 GTQ, pero volumen × precio da 16,805,536 GTQ (176,919 × 94.99); ventas_detalle también suma 16,805,536 GTQ. | 87,084 |
| DATA_EMPTY | critico | calculos_totales | resultados | 25 | K | oct | La celda resultados!K25 («Publicidad y Promoción», octubre) está vacía; falta el valor. |  |
| KPI_COPY_ROIC | critico | indicadores | indicadores_reportados | 8 | B |  | ROIC reportado: 11.3 %; calculado con sus propios estados: 13.1 % (diferencia -1.8 puntos). | 1,341,622 |
| IC_MISMATCH | critico | intercompania | intercompania | 55 | F | jun | En jun, GT reporta venta de producto de Snacks a US por 592,900.00 USD y US reporta 563,255.00 USD (diferencia 29,645.00 USD). | 29,645 |
| IC_MISSING | critico | intercompania | intercompania | 78 | F | sep | En sep, GT reporta venta de producto de Bebidas a EC por 121,275.00 USD, pero EC no lo registra. | 121,275 |
| IC_TIMING | aviso | intercompania |  |  |  | ene | En ene, venta de producto de Snacks entre GT y EC tiene una diferencia de tiempo (-180,000.00 USD): «Factura GT de dic-2026 recibida en ene-2027 (en tránsito)». | 180,000 |

### MX · 5 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| FMT_SCALE_JUMP | critico | formato_escala | balance | 46 | D | abr | En abr, 9 conceptos de balance están unas 1,000 veces fuera de escala frente a los demás meses (p. ej. «Efectivo y equivalentes»: 310,609,304,450 contra 331,392,874): el mes parece cargado en unidades en vez de miles. |  |
| IC_MISMATCH | critico | intercompania | intercompania | 16 | F | may | En may, MX reporta venta de producto de Bebidas a US por 13,500,000.00 MXN y US reporta 13,500,000.00 USD (diferencia -12,815,492.19 USD). Además, uno lo pacta en MXN y el otro en USD. | 12,815,492 |
| IC_MISSING | critico | intercompania | intercompania | 23 | F | jul | MX registra venta de producto de Confitería de US en jul a dic (6 meses, 2,400,000.00 USD en total), pero US no lo reporta. | 2,400,000 |
| FX_USED | critico | tipo_cambio | indicadores_reportados | 13 | B |  | El país usó un tipo de cambio de 18.5000 MXN/USD y la tasa oficial promedio de Presupuesto 2027 v1 es 19.8494 (diferencia 6.8 %). | 141,410 |
| FX_IC_CURRENCY | aviso | tipo_cambio | intercompania | 4 | F | ene | 12 operaciones con US (venta de producto) están pactadas en MXN y no en USD (total 162,000,000 MXN); se convierten con la tasa oficial. |  |

### PA · 4 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| CALC_BS | critico | calculos_totales | balance | 15 | K | sep | En sep el balance no cuadra: activos 47,464,203 USD y pasivo + patrimonio 47,279,203 USD (diferencia 185,000). | 185,000 |
| CALC_DETAIL | critico | calculos_totales | ventas_detalle | 442 | G | nov | En nov, ventas_detalle no suma las ventas netas de resultados en 3 líneas (detalle vs resultados, USD): Snacks 1,433,531 vs 1,378,325; Bebidas 1,029,092 vs 989,352; Confitería 762,563 vs 733,021. Ese mes además tiene la fecha fuera de la versión. | 124,489 |
| CAT_UNKNOWN | critico | catalogo_periodo | resultados | 26 | A |  | La cuenta «Regional Consulting Fees» (resultados!A26) no está en el catálogo del grupo ni en el perfil de lectura de PA; no se sumó. Total del año: 191,617.80. |  |
| FMT_DATE | critico | catalogo_periodo | resultados | 3 | L | nov | La fecha «11/30/2026» está fuera del año 2027 de la versión ppto_2027_v1; se tomó como noviembre de 2027. |  |

### US · 8 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| CALC_DETAIL | critico | calculos_totales | resultados | 178 | C | jul | El canal «Empresas del grupo» de Confitería no cuadra con las ventas intercompañía de resultados en jul a dic; en jul: canal 400,000 USD y resultados 0 USD. | 2,400,001 |
| KPI_COPY_NOWC | critico | indicadores | indicadores_reportados | 5 | B |  | Capital de trabajo operativo neto reportado: 6,921,809 USD; calculado con sus propios estados: 9,552,773 USD (diferencia -2,630,964 USD). | 2,630,964 |
| KPI_COPY_NFA | critico | indicadores | indicadores_reportados | 6 | B |  | Activo fijo neto reportado: 20,051,077 USD; calculado con sus propios estados: 18,930,946 USD (diferencia 1,120,132 USD). | 1,120,132 |
| IC_MISMATCH | critico | intercompania | intercompania | 16 | F | may | En may, MX reporta venta de producto de Bebidas a US por 13,500,000.00 MXN y US reporta 13,500,000.00 USD (diferencia -12,815,492.19 USD). Además, uno lo pacta en MXN y el otro en USD. | 12,815,492 |
| IC_MISMATCH | critico | intercompania | intercompania | 18 | F | jun | En jun, GT reporta venta de producto de Snacks a US por 592,900.00 USD y US reporta 563,255.00 USD (diferencia 29,645.00 USD). | 29,645 |
| IC_MISSING | critico | intercompania |  |  |  | jul | MX registra venta de producto de Confitería de US en jul a dic (6 meses, 2,400,000.00 USD en total), pero US no lo reporta. | 2,400,000 |
| FMT_TEXT_NUMBER | aviso | formato_escala | resultados | 2 | C |  | 1,466 números vienen guardados como texto (p. ej. resultados!C2 = «107,069»); se leyeron igual. Hojas: resultados (324), balance (143), ventas_detalle (912), intercompania (36), inventario_grupo (26), capex (12), indicadores_reportados (13). |  |
| FX_IC_CURRENCY | aviso | tipo_cambio | intercompania | 4 | F | ene | 11 operaciones con MX (venta de producto) están pactadas en MXN y no en USD (total 148,500,000 MXN); se convierten con la tasa oficial. |  |

## Presupuesto 2027 v2 (`ppto_2027_v2`)

### EC · 2 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| SIGN | aviso | catalogo_periodo | balance | 13 | C | saldo inicial | La cuenta 3101 (Patrimonio) tiene saldo negativo en 13 meses (primero en saldo inicial: -2,260,631 USD); se espera positivo. |  |
| IC_TIMING | aviso | intercompania | intercompania | 6 | F | ene | En ene, venta de producto de Snacks entre GT y EC tiene una diferencia de tiempo (-180,000.00 USD): «Factura GT de dic-2026 recibida en ene-2027 (en tránsito)». | 180,000 |

### GT · 1 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| IC_TIMING | aviso | intercompania |  |  |  | ene | En ene, venta de producto de Snacks entre GT y EC tiene una diferencia de tiempo (-180,000.00 USD): «Factura GT de dic-2026 recibida en ene-2027 (en tránsito)». | 180,000 |

### MX · 1 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| FX_IC_CURRENCY | aviso | tipo_cambio | intercompania | 4 | F | ene | 12 operaciones con US (venta de producto) están pactadas en MXN y no en USD (total 178,200,000 MXN); se convierten con la tasa oficial. |  |

### US · 2 hallazgos

| Código | Sev. | Categoría | Hoja | Fila | Col. | Mes | Mensaje | Impacto USD |
| --- | --- | --- | --- | ---: | --- | --- | --- | ---: |
| FMT_TEXT_NUMBER | aviso | formato_escala | resultados | 2 | C |  | 1,472 números vienen guardados como texto (p. ej. resultados!C2 = «107,069»); se leyeron igual. Hojas: resultados (324), balance (143), ventas_detalle (912), intercompania (42), inventario_grupo (26), capex (12), indicadores_reportados (13). |  |
| FX_IC_CURRENCY | aviso | tipo_cambio | intercompania | 4 | F | ene | 12 operaciones con MX (venta de producto) están pactadas en MXN y no en USD (total 178,200,000 MXN); se convierten con la tasa oficial. |  |

## Consolidado e indicadores (USD)

| Versión | Ingresos | EBIT | Utilidad neta | Atribuible al grupo | Minoritarios | IC sin conciliar | ROIC grupo | WACC grupo | EVA grupo |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| Presupuesto 2026 | 230,165,418 | 33,411,636 | 23,196,522 | 22,647,523 | 548,999 | 0 | 14.7 % | 10.9 % | 6,402,195 |
| Real 2026 | 227,731,707 | 33,021,810 | 22,961,273 | 22,431,337 | 529,936 | 360,000 | 14.6 % | 10.9 % | 6,185,177 |
| Presupuesto 2027 v1 | 238,795,128 | 32,390,963 | 22,766,862 | 22,275,153 | 491,709 | 15,546,412 | 14.4 % | 10.8 % | 5,923,296 |
| Presupuesto 2027 v2 | 236,942,975 | 30,834,362 | 21,335,413 | 20,973,586 | 361,827 | 180,000 | 13.6 % | 10.8 % | 4,670,426 |

| Versión | País | EBIT USD | NOPAT USD | CI USD | ROIC | WACC | EVA USD | Semáforo | CTA USD |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | --- | ---: |
| Presupuesto 2026 | GT | 11,555,313 | 8,666,485 | 73,926,691 | 11.7 % | 11.0 % | 534,549 | amarillo | 0 |
| Presupuesto 2026 | MX | 10,385,711 | 7,269,998 | 39,256,194 | 18.5 % | 12.0 % | 2,559,254 | verde | 0 |
| Presupuesto 2026 | PA | 4,343,794 | 3,257,846 | 21,364,151 | 15.2 % | 10.0 % | 1,121,431 | verde | 0 |
| Presupuesto 2026 | EC | 1,531,394 | 1,148,545 | 8,713,620 | 13.2 % | 13.0 % | 15,775 | amarillo | 0 |
| Presupuesto 2026 | US | 5,669,721 | 4,479,080 | 26,828,769 | 16.7 % | 9.0 % | 2,064,490 | verde | 7 |
| Real 2026 | GT | 11,244,811 | 8,433,609 | 74,199,264 | 11.4 % | 11.0 % | 271,689 | amarillo | 406,592 |
| Real 2026 | MX | 9,791,313 | 6,853,919 | 38,923,294 | 17.6 % | 12.0 % | 2,183,124 | verde | -1,300,092 |
| Real 2026 | PA | 4,343,794 | 3,257,846 | 21,364,151 | 15.2 % | 10.0 % | 1,121,431 | verde | 0 |
| Real 2026 | EC | 1,037,680 | 778,260 | 8,642,199 | 9.0 % | 13.0 % | -345,226 | rojo | 0 |
| Real 2026 | US | 6,665,085 | 5,265,417 | 26,972,886 | 19.5 % | 9.0 % | 2,837,857 | verde | -2 |
| Presupuesto 2027 v1 | GT | 12,746,717 | 9,560,038 | 72,759,054 | 13.1 % | 11.0 % | 1,556,542 | verde | -1,064,924 |
| Presupuesto 2027 v1 | MX | 8,872,709 | 6,210,897 | 36,200,065 | 17.2 % | 12.0 % | 1,866,889 | verde | -2,989,201 |
| Presupuesto 2027 v1 | PA | 4,406,184 | 3,304,638 | 23,847,607 | 13.9 % | 10.0 % | 919,877 | verde | -202,751 |
| Presupuesto 2027 v1 | EC | 977,395 | 733,046 | 8,534,831 | 8.6 % | 13.0 % | -376,482 | rojo | -198,523 |
| Presupuesto 2027 v1 | US | 6,383,891 | 5,043,274 | 28,483,718 | 17.7 % | 9.0 % | 2,479,739 | verde | -29,657 |
| Presupuesto 2027 v2 | GT | 12,530,053 | 9,397,540 | 72,869,270 | 12.9 % | 11.0 % | 1,381,920 | verde | -647,360 |
| Presupuesto 2027 v2 | MX | 8,644,936 | 6,051,455 | 35,939,903 | 16.8 % | 12.0 % | 1,738,667 | verde | -3,489,392 |
| Presupuesto 2027 v2 | PA | 4,365,130 | 3,273,847 | 23,881,864 | 13.7 % | 10.0 % | 885,661 | verde | 0 |
| Presupuesto 2027 v2 | EC | 690,523 | 517,892 | 9,193,395 | 5.6 % | 13.0 % | -677,249 | rojo | 0 |
| Presupuesto 2027 v2 | US | 5,671,477 | 4,480,467 | 28,524,589 | 15.7 % | 9.0 % | 1,913,254 | verde | -12 |

## Indicadores reportados vs calculados (moneda local)

| Versión | País | Indicador | Reportado | Calculado | ¿Cuadra? |
| --- | --- | --- | ---: | ---: | --- |
| Presupuesto 2027 v1 | GT | EBIT | 98,339,297 | 98,339,297 | sí |
| Presupuesto 2027 v1 | GT | NOPAT | 73,754,473 | 73,754,473 | sí |
| Presupuesto 2027 v1 | GT | Capital de trabajo operativo neto | 102,314,898 | 102,314,898 | sí |
| Presupuesto 2027 v1 | GT | Activo fijo neto | 458,814,893 | 458,814,893 | sí |
| Presupuesto 2027 v1 | GT | Capital invertido | 561,129,791 | 561,129,791 | sí |
| Presupuesto 2027 v1 | GT | Capital empleado | 718,355,451 | 718,355,451 | sí |
| Presupuesto 2027 v1 | GT | ROIC | 11.3 | 13.1 | **no** |
| Presupuesto 2027 v1 | GT | ROCE | 13.7 | 13.7 | sí |
| Presupuesto 2027 v1 | GT | EVA (moneda local) | 12,030,196 | 12,030,196 | sí |
| Presupuesto 2027 v1 | GT | EVA (USD) | 1,560,067 | 1,560,067 | sí |
| Presupuesto 2027 v1 | MX | EBIT | 175,986,807 | 175,986,809 | sí |
| Presupuesto 2027 v1 | MX | NOPAT | 123,190,765 | 123,190,766 | sí |
| Presupuesto 2027 v1 | MX | Capital de trabajo operativo neto | 198,831,497 | 198,831,497 | sí |
| Presupuesto 2027 v1 | MX | Activo fijo neto | 507,074,519 | 507,074,519 | sí |
| Presupuesto 2027 v1 | MX | Capital invertido | 705,906,015 | 705,906,015 | sí |
| Presupuesto 2027 v1 | MX | Capital empleado | 997,485,138 | 997,485,138 | sí |
| Presupuesto 2027 v1 | MX | ROIC | 17.5 | 17.5 | sí |
| Presupuesto 2027 v1 | MX | ROCE | 17.6 | 17.6 | sí |
| Presupuesto 2027 v1 | MX | EVA (moneda local) | 38,482,043 | 38,482,045 | sí |
| Presupuesto 2027 v1 | MX | EVA (USD) | 2,080,110 | 1,938,701 | no (explicado por insumos) |
| Presupuesto 2027 v1 | PA | EBIT | 4,214,566 | 4,406,184 | no (explicado por CAT_UNKNOWN) |
| Presupuesto 2027 v1 | PA | NOPAT | 3,160,925 | 3,304,638 | no (explicado por insumos) |
| Presupuesto 2027 v1 | PA | Capital de trabajo operativo neto | 4,422,276 | 4,422,276 | sí |
| Presupuesto 2027 v1 | PA | Activo fijo neto | 19,425,331 | 19,425,331 | sí |
| Presupuesto 2027 v1 | PA | Capital invertido | 23,847,607 | 23,847,607 | sí |
| Presupuesto 2027 v1 | PA | Capital empleado | 45,144,947 | 45,144,947 | sí |
| Presupuesto 2027 v1 | PA | ROIC | 13.3 | 13.9 | no (explicado por insumos) |
| Presupuesto 2027 v1 | PA | ROCE | 9.3 | 9.8 | no (explicado por insumos) |
| Presupuesto 2027 v1 | PA | EVA (moneda local) | 776,164 | 919,877 | no (explicado por insumos) |
| Presupuesto 2027 v1 | PA | EVA (USD) | 776,164 | 919,877 | no (explicado por insumos) |
| Presupuesto 2027 v1 | EC | EBIT | 977,395 | 977,395 | sí |
| Presupuesto 2027 v1 | EC | NOPAT | 733,046 | 733,046 | sí |
| Presupuesto 2027 v1 | EC | Capital de trabajo operativo neto | 2,545,344 | 2,545,344 | sí |
| Presupuesto 2027 v1 | EC | Activo fijo neto | 5,989,487 | 5,989,487 | sí |
| Presupuesto 2027 v1 | EC | Capital invertido | 8,534,831 | 8,534,831 | sí |
| Presupuesto 2027 v1 | EC | Capital empleado | 11,627,858 | 11,627,858 | sí |
| Presupuesto 2027 v1 | EC | ROIC | 8.6 | 8.6 | sí |
| Presupuesto 2027 v1 | EC | ROCE | 8.4 | 8.4 | sí |
| Presupuesto 2027 v1 | EC | EVA (moneda local) | -205,785 | -376,482 | no (explicado por insumos) |
| Presupuesto 2027 v1 | EC | EVA (USD) | -205,785 | -376,482 | no (explicado por insumos) |
| Presupuesto 2027 v1 | US | EBIT | 6,383,883 | 6,383,891 | sí |
| Presupuesto 2027 v1 | US | NOPAT | 5,043,268 | 5,043,274 | sí |
| Presupuesto 2027 v1 | US | Capital de trabajo operativo neto | 6,921,809 | 9,552,773 | **no** |
| Presupuesto 2027 v1 | US | Activo fijo neto | 20,051,077 | 18,930,946 | **no** |
| Presupuesto 2027 v1 | US | Capital invertido | 26,972,886 | 28,483,718 | no (explicado por insumos) |
| Presupuesto 2027 v1 | US | Capital empleado | 40,958,918 | 40,958,918 | sí |
| Presupuesto 2027 v1 | US | ROIC | 18.7 | 17.7 | no (explicado por insumos) |
| Presupuesto 2027 v1 | US | ROCE | 15.6 | 15.6 | sí |
| Presupuesto 2027 v1 | US | EVA (moneda local) | 2,615,708 | 2,479,739 | no (explicado por insumos) |
| Presupuesto 2027 v1 | US | EVA (USD) | 2,615,708 | 2,479,739 | no (explicado por insumos) |
| Presupuesto 2027 v2 | GT | EBIT | 96,665,613 | 96,665,613 | sí |
| Presupuesto 2027 v2 | GT | NOPAT | 72,499,210 | 72,499,210 | sí |
| Presupuesto 2027 v2 | GT | Capital de trabajo operativo neto | 103,168,334 | 103,168,334 | sí |
| Presupuesto 2027 v2 | GT | Activo fijo neto | 458,814,893 | 458,814,893 | sí |
| Presupuesto 2027 v2 | GT | Capital invertido | 561,983,227 | 561,983,227 | sí |
| Presupuesto 2027 v2 | GT | Capital empleado | 718,933,265 | 718,933,265 | sí |
| Presupuesto 2027 v2 | GT | ROIC | 12.9 | 12.9 | sí |
| Presupuesto 2027 v2 | GT | ROCE | 13.4 | 13.4 | sí |
| Presupuesto 2027 v2 | GT | EVA (moneda local) | 10,681,055 | 10,681,055 | sí |
| Presupuesto 2027 v2 | GT | EVA (USD) | 1,385,111 | 1,385,111 | sí |
| Presupuesto 2027 v2 | MX | EBIT | 174,029,561 | 174,029,560 | sí |
| Presupuesto 2027 v2 | MX | NOPAT | 121,820,693 | 121,820,692 | sí |
| Presupuesto 2027 v2 | MX | Capital de trabajo operativo neto | 198,719,738 | 198,719,738 | sí |
| Presupuesto 2027 v2 | MX | Activo fijo neto | 507,074,519 | 507,074,519 | sí |
| Presupuesto 2027 v2 | MX | Capital invertido | 705,794,257 | 705,794,257 | sí |
| Presupuesto 2027 v2 | MX | Capital empleado | 996,798,019 | 996,798,019 | sí |
| Presupuesto 2027 v2 | MX | ROIC | 17.3 | 17.3 | sí |
| Presupuesto 2027 v2 | MX | ROCE | 17.5 | 17.5 | sí |
| Presupuesto 2027 v2 | MX | EVA (moneda local) | 37,125,382 | 37,125,381 | sí |
| Presupuesto 2027 v2 | MX | EVA (USD) | 1,842,712 | 1,842,712 | sí |
| Presupuesto 2027 v2 | PA | EBIT | 4,365,130 | 4,365,130 | sí |
| Presupuesto 2027 v2 | PA | NOPAT | 3,273,847 | 3,273,847 | sí |
| Presupuesto 2027 v2 | PA | Capital de trabajo operativo neto | 4,456,533 | 4,456,533 | sí |
| Presupuesto 2027 v2 | PA | Activo fijo neto | 19,425,331 | 19,425,331 | sí |
| Presupuesto 2027 v2 | PA | Capital invertido | 23,881,864 | 23,881,864 | sí |
| Presupuesto 2027 v2 | PA | Capital empleado | 45,207,524 | 45,207,524 | sí |
| Presupuesto 2027 v2 | PA | ROIC | 13.7 | 13.7 | sí |
| Presupuesto 2027 v2 | PA | ROCE | 9.7 | 9.7 | sí |
| Presupuesto 2027 v2 | PA | EVA (moneda local) | 885,661 | 885,661 | sí |
| Presupuesto 2027 v2 | PA | EVA (USD) | 885,661 | 885,661 | sí |
| Presupuesto 2027 v2 | EC | EBIT | 690,523 | 690,523 | sí |
| Presupuesto 2027 v2 | EC | NOPAT | 517,892 | 517,892 | sí |
| Presupuesto 2027 v2 | EC | Capital de trabajo operativo neto | 2,618,908 | 2,618,908 | sí |
| Presupuesto 2027 v2 | EC | Activo fijo neto | 6,574,487 | 6,574,487 | sí |
| Presupuesto 2027 v2 | EC | Capital invertido | 9,193,395 | 9,193,395 | sí |
| Presupuesto 2027 v2 | EC | Capital empleado | 11,582,106 | 11,582,106 | sí |
| Presupuesto 2027 v2 | EC | ROIC | 5.6 | 5.6 | sí |
| Presupuesto 2027 v2 | EC | ROCE | 6.0 | 6.0 | sí |
| Presupuesto 2027 v2 | EC | EVA (moneda local) | -677,249 | -677,249 | sí |
| Presupuesto 2027 v2 | EC | EVA (USD) | -677,249 | -677,249 | sí |
| Presupuesto 2027 v2 | US | EBIT | 5,671,469 | 5,671,477 | sí |
| Presupuesto 2027 v2 | US | NOPAT | 4,480,461 | 4,480,467 | sí |
| Presupuesto 2027 v2 | US | Capital de trabajo operativo neto | 9,593,644 | 9,593,644 | sí |
| Presupuesto 2027 v2 | US | Activo fijo neto | 18,930,946 | 18,930,946 | sí |
| Presupuesto 2027 v2 | US | Capital invertido | 28,524,589 | 28,524,589 | sí |
| Presupuesto 2027 v2 | US | Capital empleado | 40,686,863 | 40,686,863 | sí |
| Presupuesto 2027 v2 | US | ROIC | 15.7 | 15.7 | sí |
| Presupuesto 2027 v2 | US | ROCE | 13.9 | 13.9 | sí |
| Presupuesto 2027 v2 | US | EVA (moneda local) | 1,913,248 | 1,913,254 | sí |
| Presupuesto 2027 v2 | US | EVA (USD) | 1,913,248 | 1,913,254 | sí |

## Propuestas de inversión recalculadas

| Propuesta | Sede | ROIC presentado | ROIC recalculado | WACC sede | EVA recalculado | Presentada | Recalculada | ¿Se sostiene? |
| --- | --- | ---: | ---: | ---: | ---: | --- | --- | --- |
| P1 | MX | 16.50 % | 16.50 % | 12.0 % | 542,212 | aprobar | aprobar | sí |
| P2 | PA | 13.42 % | 13.42 % | 10.0 % | 246,996 | aprobar | aprobar | sí |
| P3 | US | 9.75 % | 9.75 % | 9.0 % | 123,800 | aprobar | ajustar | **no**: ROIC recalculado 9.75 % frente a WACC de US 9.0 % (margen de 0.75 puntos). La decisión presentada es «aprobar», pero con las reglas del grupo corresponde «ajustar». |
| P4 | EC | 20.62 % | 20.62 % | 13.0 % | 195,518 | aprobar | aprobar | sí |

## Desviaciones de ventas (USD)

| Comparación | Δ ventas | Volumen | Precio | Tipo de cambio | Intercompañía | Otros | Explicación |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| ppto_2027_v2 vs ppto_2027_v1 | -1,852,154 | 1,641,338 | 559,747 | -970,470 | -3,082,769 | 0 | Las ventas del grupo bajan 0.8 %: 1.3 puntos por más ventas entre empresas del grupo y 0.7 por más volumen. |
| ppto_2027_v2 vs ppto_2027_v1 · GT | 972,898 | 1,059,982 | -87,084 | 0 | | 0 | Las ventas de GT suben 1.1 %: 1.2 puntos por más volumen y 0.1 por precios más bajos. |
| ppto_2027_v2 vs ppto_2027_v1 · MX | -970,470 | 0 | 0 | -970,470 | | 0 | Las ventas de MX bajan 1.5 %: 1.5 puntos por tipo de cambio en contra. |
| ppto_2027_v2 vs ppto_2027_v1 · PA | 646,832 | 0 | 646,832 | 0 | | 0 | Las ventas de PA suben 1.9 %: 1.9 puntos por mejores precios. |
| ppto_2027_v2 vs ppto_2027_v1 · EC | 581,356 | 581,356 | 0 | 0 | | 0 | Las ventas de EC suben 3.2 %: 3.2 puntos por más volumen. |
| ppto_2027_v2 vs ppto_2027_v1 · US | 0 | 0 | 0 | 0 | | 0 | Las ventas de US se mantienen. |
| ppto_2027_v1 vs real_2026 | 11,063,421 | 14,403,176 | 2,349,804 | -5,313,220 | -376,338 | 0 | Las ventas del grupo suben 4.9 %: 6.3 puntos por más volumen y 2.3 por tipo de cambio en contra. |
| ppto_2027_v1 vs real_2026 · GT | 6,895,720 | 6,936,701 | 87,288 | -128,269 | | 0 | Las ventas de GT suben 8.5 %: 8.6 puntos por más volumen y 0.2 por tipo de cambio en contra. |
| ppto_2027_v1 vs real_2026 · MX | -1,777,256 | 2,360,564 | 1,047,131 | -5,184,951 | | 0 | Las ventas de MX bajan 2.6 %: 7.7 puntos por tipo de cambio en contra y 3.5 por más volumen. |
| ppto_2027_v1 vs real_2026 · PA | 1,215,386 | 0 | 1,215,386 | 0 | | 0 | Las ventas de PA suben 3.6 %: 3.6 puntos por mejores precios. |
| ppto_2027_v1 vs real_2026 · EC | 528,734 | 528,734 | 0 | 0 | | 0 | Las ventas de EC suben 3 %: 3 puntos por más volumen. |
| ppto_2027_v1 vs real_2026 · US | 4,577,175 | 4,577,176 | -1 | 0 | | 0 | Las ventas de US suben 8 %: 8 puntos por más volumen. |
| real_2026 vs ppto_2026 | -2,433,711 | -683,540 | -1,696,557 | 83,513 | -137,126 | 0 | Las ventas del grupo bajan 1.1 %: 0.7 puntos por precios más bajos y 0.3 por menos volumen. |
| real_2026 vs ppto_2026 · GT | -1,409,140 | -1,914,781 | 0 | 505,641 | | 0 | Las ventas de GT bajan 1.7 %: 2.3 puntos por menos volumen y 0.6 por tipo de cambio a favor. |
| real_2026 vs ppto_2026 · MX | -2,118,684 | 0 | -1,696,556 | -422,129 | | 0 | Las ventas de MX bajan 3 %: 2.4 puntos por precios más bajos y 0.6 por tipo de cambio en contra. |
| real_2026 vs ppto_2026 · PA | 0 | 0 | 0 | 0 | | 0 | Las ventas de PA se mantienen. |
| real_2026 vs ppto_2026 · EC | -1,057,363 | -1,057,363 | 0 | 0 | | 0 | Las ventas de EC bajan 5.7 %: 5.7 puntos por menos volumen. |
| real_2026 vs ppto_2026 · US | 2,288,603 | 2,288,604 | -1 | 0 | | 0 | Las ventas de US suben 4.2 %: 4.2 puntos por más volumen. |

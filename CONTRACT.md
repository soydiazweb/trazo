# Consolida · contrato técnico v2 (datos reales del Grupo Meridia)

**Esta versión reemplaza a la v1.** Todo el sistema se basa en los archivos reales del reto, copiados en
`datos-meridia/` (en el servidor: `wp-content/datos-meridia/`). Nada de datos inventados.

Plugin WordPress `consolida/` · PHP 8.1+ · sin Composer · interfaz en español (Guatemala) · nombres en inglés,
comentarios en español · autor Jonathan Diaz (https://www.soydiaz.com). Modelo de estilo: `../WireSw/sw-hq/`.

## 1. Los datos

```
datos-meridia/
├── 1_Paises/Pais1_Guatemala/ Guatemala_{Presupuesto_2026, Real_2026, Presupuesto_2027, Presupuesto_2027_v2}.xlsx
│   Pais2_Mexico/  Pais3_Panama/  Pais4_Ecuador/  Pais5_Estados_Unidos/   (mismo patrón: <Pais>_<...>.xlsx)
└── 2_Grupo/Grupo_Corporativo.xlsx
```

**Versiones (ciclos)** — claves iguales a `tasas_oficiales.version`:

| archivo | clave | etiqueta |
| --- | --- | --- |
| `*_Presupuesto_2026.xlsx` | `ppto_2026` | Presupuesto 2026 |
| `*_Real_2026.xlsx` | `real_2026` | Real 2026 |
| `*_Presupuesto_2027.xlsx` | `ppto_2027_v1` | Presupuesto 2027 v1 |
| `*_Presupuesto_2027_v2.xlsx` | `ppto_2027_v2` | Presupuesto 2027 v2 |

**Grupo_Corporativo.xlsx** (formato limpio, encabezado en la fila 1):
- `parametros_paises`: codigo (GT, MX, PA, EC, US), pais_numero, empresa, pais, moneda (GTQ, MXN, USD×3),
  tasa_impuesto, wacc, participacion_grupo (EC 0.75, US 0.90), participacion_minoritarios, socio_minoritario,
  escala_archivo (MX y US «miles»), responsable_de_datos, aprueba_en_el_pais.
- `tasas_oficiales`: version, anio, mes, moneda (GTQ, MXN), contra (USD), tasa_presupuesto, tasa_promedio,
  tasa_cierre, estado, aprobado_por, fecha_aprobacion. Moneda local por 1 USD. `real_2026` trae además el cierre de
  2025-12 (saldo inicial). USD = 1 siempre.
- `catalogo_cuentas`: codigo, nombre, tipo (ingreso/costo/gasto/activo/pasivo/patrimonio), subtipo (ventas,
  otros_ingresos_operativos, costo_ventas, gasto_operacion, depreciacion, financiero, impuesto, efectivo,
  capital_trabajo, activo_fijo, deuda, patrimonio), operativa (si/no), corriente, intercompania (si/no),
  signo_esperado (+/−; 1502 Depreciación acumulada es −). 3102 y 5901 existen solo en el consolidado.
- `maestro_productos` (12: SNK-01..04, BEB-01..04, CON-01..04 con línea Snacks/Bebidas/Confitería),
  `canales` (C1 Supermercados, C2 Tiendas de barrio, C3 Mayoristas, C4 Empresas del grupo).
- `propuestas_inversion`: P1..P4 «tal como los presentó cada país» (inversion_usd, vida_util, ingreso y NOPAT
  incremental, capital_proyecto_usd, roic_proyecto_pct, wacc_sede_pct, eva_usd, decision_propuesta) → el sistema las
  **recalcula** y dice si la decisión se sostiene.
- `historial_ciclos` (2024–2026 por país: días de retraso, reenvíos, errores por tipo, días y horas para consolidar) y
  `historial_indicadores` (reales 2023–2025: ingresos, EBIT, NOPAT, CI, ROIC, WACC, EVA) → módulos preventivo y
  evolutivo.

**Archivos de país** — mismas hojas, cinco formatos distintos (ese es el reto):
`portada` (clave-valor: Empresa, País, Moneda, Montos en, Escenario, Preparado y cargado por, Fecha de carga, Revisado
y aprobado por, Fecha de aprobación, Notas), `resultados`, `balance`, `intercompania`, `ventas_detalle`,
`inventario_grupo`, `capex` y, solo en 2027, `indicadores_reportados` (EBIT, impuesto, NOPAT, capital de trabajo,
activo fijo, capital invertido, ROIC, capital empleado, ROCE, WACC, EVA local, tipo de cambio usado, EVA USD).
Panamá y Estados Unidos están en inglés (portada vacía o en inglés).

| País | resultados | balance | fechas | escala | números | cuentas |
| --- | --- | --- | --- | --- | --- | --- |
| GT | ancho: fila 3 encabezado `Cuenta, Ene..Dic` | ancho `Cuenta, Saldo inicial, Ene..Dic` | nombres de mes | unidades | numéricos | nombres propios en español |
| MX | largo `Fecha, Concepto, Importe, Unidad` | largo `Fecha, Tipo (Saldo inicial/Cierre de mes), Concepto, Saldo, Unidad` | dd/mm/aaaa | **miles** (salvo volumen y precio) | numéricos | nombres propios |
| PA | ancho, inglés `Account, 01/31/2027…` | ancho `Account, Opening 12/31/2026, …` | mm/dd/aaaa | unidades | numéricos | nombres en inglés |
| EC | largo `Periodo, Cuenta, Valor` | largo `Periodo, Cuenta, Saldo` (`Saldo inicial`) | aaaa-mm | unidades | numéricos | **códigos locales** (9101 volumen, 9201 precio, 9301 ventas netas…) + filas «Total Gastos» a mano |
| US | largo `Period, Account, Amount, Unit` | largo `Period, Account, Balance, Unit` (`Opening Balance`) | Mon-aaaa | **miles** (USD 000) | **texto** «1,918.676» | nombres en inglés |

En `ventas_detalle`, `intercompania`, `inventario_grupo` y `capex` también cambian idioma, fechas, códigos (EC usa
códigos de producto, canal y línea SNK/BEB/CON; contrapartes por código GT/PA o por nombre «Meridia Guatemala»).

## 2. Archivos y dueños (v2)

```
consolida/includes/Engine/
├── Xlsx/Reader.php, Xlsx/Writer.php          (ya hechos; se reutilizan)
├── Corporate.php      (R) lee Grupo_Corporativo.xlsx → Corporate (§4)
├── Profiles.php       (R) perfiles de lectura por país (§5): formato, fechas, escala, mapa de etiquetas → catálogo
├── CountryReader.php  (R) archivo de país + perfil → Dataset normalizado (§6) + issues de lectura
├── Validator.php      (F) reglas por archivo (§7)
├── Intercompany.php   (F) conciliación entre países por mes y tipo (§8)
├── Fx.php             (F) conversión mensual (§9)
├── Consolidator.php   (F) consolidación (§10)
├── Variance.php       (F) desviaciones (§11)
├── Kpi.php            (F) indicadores, copiados, propuestas, semáforo (§12)
└── History.php        (F) preventivo y evolutivo desde historial_* + calidad del ciclo actual (§13)
consolida/tests/run.php            (F) pruebas unitarias + integración con datos-meridia/ (§14)
consolida/tools/inspect.php        (R) `php tools/inspect.php <archivo>` imprime el Dataset y sus issues
```
`Catalog.php`, `Parser.php`, `Template.php`, `Support.php` de la v1 se pueden reutilizar o borrar (quien los
reemplaza decide). (R) = agente de lectura, (F) = agente financiero, (C) = capa WordPress, (D) = panel.

## 3. Unidades y signos

Todo el Dataset normalizado va en **moneda local en unidades** (escala aplicada: MX y US ×1000 salvo volúmenes y
precios). Montos de cuentas con su signo natural positivo (la depreciación acumulada 1502 se guarda como número
**positivo** que resta del activo fijo; si el archivo la trae negativa, se toma el valor absoluto y no es error).
Meses 1..12; `0` = saldo inicial.

## 4. Corporate (`Corporate::load(string $path): array`)

```php
['companies'=>['GT'=>['code'=>'GT','number'=>1,'name'=>'Meridia Guatemala','country'=>'Guatemala','currency'=>'GTQ',
   'tax'=>0.25,'wacc'=>0.11,'ownership'=>1.0,'minority'=>0.0,'minority_partner'=>null,'scale'=>'unidades',
   'data_owner'=>'Andrea López','approver'=>'Ricardo Morales, Gerente Financiero'], …],
 'rates'=>['ppto_2027_v1'=>['GTQ'=>[1=>['budget'=>7.7,'avg'=>7.6837,'close'=>7.6862,'approved_by'=>…,'approved_at'=>…],…,
            0=>['close'=>…] /*saldo inicial si existe*/],'MXN'=>[…]],…],
 'catalog'=>['4101'=>['code'=>'4101','name'=>…,'type'=>'ingreso','subtype'=>'ventas','operating'=>true,
   'current'=>null|bool,'intercompany'=>false,'sign'=>1], …],
 'products'=>['SNK-01'=>['name'=>…,'name_en'=>…,'line'=>'Snacks'],…], 'channels'=>['C1'=>['name'=>…,'name_en'=>…],…],
 'proposals'=>[['id'=>'P1','site'=>'MX','description'=>…,'investment_usd'=>…,'life_years'=>…,'pct_third_party_sales'=>…,
   'incremental_revenue_usd'=>…,'incremental_nopat_usd'=>…,'capital_usd'=>…,'roic_pct'=>…,'wacc_pct'=>…,'eva_usd'=>…,
   'decision'=>'aprobar','row'=>2],…],
 'history_cycles'=>[[…columnas en inglés…]], 'history_kpis'=>[[…]]]
```
Tasa de saldo inicial de un ciclo: `rates[version][cur][0].close` si existe; si no, el cierre de diciembre del año
anterior en `real_<año-1>`; si no, el cierre del mes 1. USD → 1.

## 5. Perfiles de lectura (`Profiles::defaults(): array`, uno por país)

Un perfil describe cómo leer el archivo de ese país: idioma, layout (`wide`|`long`) por hoja, fila de encabezado,
columnas, formato de fecha/periodo, escala, formato numérico, y **mapa de etiquetas** (texto o código local →
concepto). El perfil se guarda en la base (el corporativo puede editarlo en el panel) y `defaults()` trae los 5
perfiles construidos a partir de los archivos reales. Conceptos de `resultados`:
- `volume:<linea>` y `price:<linea>` (estadísticos; línea = `Snacks`|`Bebidas`|`Confitería`),
- `net_sales:<linea>` (ventas netas de la línea = terceros + intercompañía; no es cuenta del catálogo, se usa para
  validar),
- cuentas del catálogo: 4101..4103, 4111..4113, 4901, 5101..5103, 6101..6105, 6201, 7101, 7111, 7102, 7112, 8101,
- `total_row` (filas «Total Gastos» de EC: se validan, no se suman),
- etiqueta desconocida → issue `CAT_UNKNOWN` (no se adivina).
Ejemplos: GT «Ventas Snacks a Filiales» → 4111; «Ingreso Cuota Administración Filiales» → 4901; «Productos
Financieros» → 7101; «Intereses Bancarios» → 7102. MX «Management fee corporativo» → 6105; PA «Management Fee - HQ» →
6105, «Interest Income - Intercompany Loan» → 7111; EC 4201..4203 → 4111..4113, 6150 → 6105, 7220 → 7112, 9101..9303 →
volume/price/net_sales; US «Gross Sales - Snacks» → net_sales:Snacks, «Net Sales - Snacks - Affiliates» → 4111.
Balance: GT «Cuentas por Cobrar Filiales» → 1202, «Inventario Comprado a Filiales» → 1302, «Propiedad, Planta y
Equipo» → 1501, «Depreciación Acumulada» → 1502, «Capital y Utilidades Retenidas» → 3101; PA «Intercompany Loan
Receivable» → 1401; EC 2310 → 2301; etc. **El agente R debe revisar los 20 archivos y completar los mapas con todas
las etiquetas reales**; una etiqueta que aparece solo en algunas versiones (p. ej. PA 2027 «Regional Consulting
Fees») NO se mapea: es un hallazgo de catálogo.
Tipos de intercompañía (normalizados): `product_sale` (Venta de producto / Product sale), `management_fee` (Cuota de
administración / Management fee), `loan` (Préstamo / Loan), `loan_interest` (Intereses préstamo / Loan interest).
Sentidos: `sale`, `purchase`, `charge` (cobro/charge/cargo), `payment` (pago/expense), `granted`, `received`.
Contrapartes: código GT/MX/PA/EC/US (aceptar nombre «Meridia X»).

## 6. Dataset normalizado (`CountryReader::read(string $path, array $profile, array $corporate): array`)

```php
['meta'=>['company'=>'GT','version'=>'ppto_2027_v1','year'=>2027,'currency'=>'GTQ','scale_declared'=>'unidades',
   'scale_applied'=>1,'prepared_by'=>…,'loaded_at'=>'2026-11-15','approved_by'=>…,'approved_at'=>…,'notes'=>…,
   'file'=>'Guatemala_Presupuesto_2027.xlsx'],
 'pl'=>[ ['concept'=>'4101'|'volume:Snacks'|…,'label'=>'Ventas Snacks Clientes Externos','month'=>1,'value'=>float|null,
          'src'=>['sheet'=>'resultados','row'=>7,'col'=>'B']], … ],
 'bs'=>[ ['concept'=>'1101','label'=>…,'month'=>0..12,'value'=>…,'src'=>…], … ],
 'sales'=>[ ['month'=>1,'line'=>'Snacks','product'=>'SNK-01','channel'=>'C1','volume'=>…,'amount'=>…,'src'=>…], … ],
 'ic'=>[ ['month'=>1,'counterparty'=>'EC','type'=>'management_fee','line'=>null|'Snacks','direction'=>'charge',
          'amount'=>…,'currency'=>'USD','note'=>null|'…','src'=>…], … ],
 'ic_inventory'=>[ ['month'=>0..12,'supplier'=>'GT','line'=>'Snacks','amount'=>…(moneda local),'src'=>…], … ],
 'capex'=>[ ['month'=>1,'project'=>…,'description'=>…,'amount'=>…,'src'=>…], … ],
 'reported'=>['ebit'=>…,'tax_rate'=>…,'nopat'=>…,'nowc_avg'=>…,'net_fixed_avg'=>…,'ic_avg'=>…,'roic_pct'=>…,
   'ce_avg'=>…,'roce_pct'=>…,'wacc_pct'=>…,'eva_local'=>…,'fx_used'=>…,'eva_usd'=>…,'rows'=>['ebit'=>src,…]] | null,
 'issues'=>[Issue…]]   // problemas de lectura (§7 grupo formato/catalogo)
```
`src.col` = letra de columna Excel; `row` = fila real. Montos del Dataset ya en unidades locales (salvo `ic.amount`,
que queda en la moneda pactada, y `reported`, que se convierte a unidades: MX/US ×1000 en montos, no en % ni tasas).

## 7. Issue y reglas por archivo

```php
['code'=>'CALC_VOLPRICE','severity'=>'critico'|'aviso','category'=>'formato_escala'|'catalogo_periodo'|
 'calculos_totales'|'intercompania'|'tipo_cambio'|'indicadores','company'=>'MX','version'=>'ppto_2027_v1',
 'sheet'=>'resultados','row'=>12,'col'=>'C','month'=>3|null,'concept'=>'4101'|null,
 'message'=>'…','actual'=>float|null,'expected'=>float|null,'impact_usd'=>float|null]
```
Categorías = las mismas 6 de `historial_ciclos` para comparar con el historial. Reglas mínimas (F implementa en
`Validator::validate(array $ds, array $ctx)`; R produce las de lectura):

| código | categoría | sev. | regla |
| --- | --- | --- | --- |
| FMT_SCALE_DECL | formato_escala | crítico | la portada dice una escala distinta a `parametros_paises.escala_archivo` |
| FMT_SCALE_JUMP | formato_escala | crítico | un concepto ≥ 500× o ≤ 1/500 frente al mismo concepto en otra versión del mismo país o frente a meses vecinos |
| FMT_TEXT_NUMBER | formato_escala | aviso | números guardados como texto (se leen igual) |
| FMT_DATE | catalogo_periodo | crítico | fecha/periodo fuera del año de la versión o mes repetido/faltante (p. ej. «11/30/2026» en un archivo 2027) |
| CAT_UNKNOWN | catalogo_periodo | crítico | etiqueta o código sin mapeo al catálogo |
| CAT_PRODUCT / CAT_CHANNEL | catalogo_periodo | crítico | producto o canal fuera de los maestros |
| DATA_EMPTY | calculos_totales | crítico | celda vacía donde debe haber valor |
| DATA_DUP | catalogo_periodo | crítico | concepto repetido en el mismo mes |
| CALC_VOLPRICE | calculos_totales | crítico | ventas netas de la línea ≠ volumen × precio (tolerancia 0,5 %) |
| CALC_SPLIT | calculos_totales | crítico | ventas netas ≠ terceros + intercompañía (tolerancia 1 unidad o 0,01 %) |
| CALC_DETAIL | calculos_totales | crítico | Σ ventas_detalle del mes y línea ≠ ventas netas de la línea (0,1 %); canal C4 ≠ ventas intercompañía de la línea |
| CALC_TOTAL | calculos_totales | crítico | fila «Total Gastos» ≠ suma del detalle |
| CALC_BS | calculos_totales | crítico | activo (con 1502 restando) ≠ pasivo + patrimonio en algún mes (0,1 %) |
| CALC_DEPR | calculos_totales | aviso | Δ depreciación acumulada ≠ depreciación del mes (1 %) |
| SIGN | calidad (catalogo_periodo) | aviso | signo contrario al esperado |
| FX_USED | tipo_cambio | crítico | `reported.fx_used` difiere > 0,5 % de la tasa oficial promedio anual de la versión |
| FX_IC_CURRENCY | tipo_cambio | aviso | operación intercompañía pactada en moneda distinta de USD (se convierte con la tasa oficial; avisar) |
| KPI_COPY_* | indicadores | crítico | indicador reportado ≠ calculado (EBIT, NOPAT, CI, CE, ROIC, ROCE, WACC, EVA; tolerancia 0,5 % o 0,2 puntos) |
| KPI_WACC | indicadores | crítico | WACC reportado ≠ `parametros_paises.wacc` |

## 8. Intercompañía (`Intercompany::reconcile(array $datasets /* code => Dataset */, array $corporate, string $version)`)

Pares por mes y tipo: venta A→B (`sale` de A) con compra B←A (`purchase` de B); cuota: `charge` de GT con `payment`
del país; intereses: `charge` del prestamista con `payment` del deudor; préstamo: `granted` con `received`.
Comparación en moneda pactada (tolerancia 0,5 %). Además: (a) ventas intercompañía de `resultados` (4111..4113, 4901,
7111) del mes = suma de la hoja intercompañía convertida a moneda local; (b) saldos 1202/2102 y 1401/2301 entre
países a cierre de cada mes (convertidos a USD, tolerancia 1 %); (c) diferencias de tiempo: si una nota dice
«en tránsito» o la diferencia se compensa en el mes siguiente, `status = 'tiempo'` (aviso, no crítico).
Salida `['pairs'=>[['type','month','from','to','currency','amount_from','amount_to','status'=>'cuadra'|'no_cuadra'|
'falta_lado'|'tiempo','diff','src_from','src_to']…], 'issues'=>[IC_MISMATCH|IC_MISSING|IC_TIMING|IC_PL_TIE|IC_BALANCE…]]`.

## 9. Conversión (`Fx`)

Resultados de cada mes a `avg` del mes; balance de cada mes a `close` del mes; saldo inicial a la tasa de saldo
inicial (§4). Totales anuales = suma de meses convertidos. Intercompañía en moneda pactada: a USD con `avg` del mes
(flujos) o `close` (saldos) de esa moneda. `Fx::constant` = convertir con las tasas de otra versión.
Diferencia de conversión (CTA) por país = patrimonio convertido a cierre − (patrimonio inicial a tasa inicial +
utilidad del año convertida a promedio); se informa, no se valida.

## 10. Consolidación (`Consolidator::run(array $datasets, array $corporate, string $version): array`)

Por país: conceptos de catálogo convertidos a USD por mes. Eliminaciones (montos en USD, tomados de la parte que vende
/ cobra / presta; si la pareja no cuadra se elimina el lado del vendedor y la diferencia va a `ic_unreconciled`):
ventas de producto (−41xx vendedor, −51xx comprador por el mismo monto), cuota de administración (−4901, −6105),
intereses (−7111, −7112), saldos (−1202, −2102), préstamos (−1401, −2301) y **margen no realizado** del inventario
comprado al grupo (`ic_inventory` al cierre × margen bruto del proveedor en esa línea en el año: −1302 y cuenta 5901
por la variación del año). Minoritarios EC 25 %, US 10 %: `3102` = minoritario × patrimonio final; utilidad
atribuible a minoritarios = minoritario × utilidad neta. Salida: `lines` por cuenta con `by_company`, `eliminations`,
`total`, `months[1..12]` (PL) o `close`/`open` (BS) y `sources` (filas origen para la traza); `eliminations` (detalle);
`ic_unreconciled`; `minority`; `cta`; `totals` y `by_company` (ingresos, EBIT, utilidad neta, utilidad del grupo).
EBIT = ingresos operativos (41xx + 4901) − costo (51xx, 5901) − gastos operativos (61xx) − depreciación (6201).
Utilidad neta = EBIT + 7101 + 7111 − 7102 − 7112 − 8101.

## 11. Desviaciones (`Variance::compare(array $resA, array $resB, array $ctx)`)

Comparaciones útiles: `ppto_2027_v2` vs `ppto_2027_v1`, `ppto_2027_v1` vs `real_2026`, `real_2026` vs `ppto_2026`.
Ventas por línea y país: volumen, precio, tipo de cambio, intercompañía (grupo) y otros, **sumando exacto** la
desviación (tolerancia 0,01). Demás cuentas: a tipo de cambio constante + tipo de cambio. Texto en español sencillo
con los dos efectos mayores. Drill-down por país → cuenta → mes → fila del Excel.

## 12. Indicadores (`Kpi`)

Por país (USD y moneda local) y grupo: EBIT, NOPAT = EBIT × (1 − tasa del país), capital de trabajo operativo neto =
(1201 + 1202 + 1301 + 1302) − (2101 + 2102), activo fijo neto = 1501 − 1502, CI = CTON + activo fijo neto, CE =
activo total − pasivo corriente (2101 + 2102); **promedios**: el agente F determina con los archivos qué promedio
usan los países (inicial+final / 2, o 13 saldos) comparando con `indicadores_reportados` de archivos que cuadren, y lo
documenta. ROIC = NOPAT / CI, ROCE = EBIT / CE, EVA = NOPAT − WACC × CI. Grupo: con el balance consolidado (sin
intercompañía), WACC ponderado por CI. Semáforo verde/amarillo/rojo (ROIC vs WACC ± 1 punto y críticos abiertos) con
razones. `Kpi::copied` compara cada indicador reportado (§6 `reported`) y genera `KPI_COPY_*`. `Kpi::proposals`
recalcula P1..P4: ROIC = NOPAT incremental / capital del proyecto, EVA = NOPAT − WACC real de la sede × capital,
revisa que el WACC usado sea el de `parametros_paises` y que capital ≥ inversión, y da `decision_recalculada`
(aprobar ≥ WACC + 2 puntos, ajustar entre WACC y WACC + 2, rechazar < WACC) frente a `decision_propuesta`.
`Kpi::issue_impact` estima el efecto en ROIC/EVA de cada issue con `expected`.

## 13. Preventivo y evolutivo (`History`)

- `History::quality(array $issuesByCountry, array $corporate)`: errores del ciclo actual por país y categoría junto a
  `historial_ciclos` (2024–2026) → ranking y tendencia; categoría que más se repite por país → regla preventiva
  sugerida.
- `History::kpi_trend(array $corporate, array $kpiByVersion)`: `historial_indicadores` 2023–2025 + real 2026 +
  presupuestos 2027 → serie por país de ingresos, EBIT, ROIC, WACC, EVA.
- `History::scenarios(array $result, array $ctx)`: EVA y ROIC con tipo de cambio ±10 % y volumen ±10 %.

## 14. Pruebas (`tests/run.php`)

(a) unitarias con datos hechos a mano; (b) integración con `../datos-meridia/` (ruta relativa al plugin o variable
de entorno `CSL_DATA`): leer los 21 archivos, 0 issues de lectura en lo que no sea hallazgo real, validar, conciliar,
consolidar las 4 versiones, KPIs, variance (los efectos suman exacto) y **escribir `tests/hallazgos.md`** con la lista
de todo lo encontrado por país y versión (código, hoja, fila, mensaje, impacto). Esa lista es la base del demo: no
hay respuestas dadas; el sistema debe encontrar los errores sembrados.

## 15. Capa WordPress (C) — cambios sobre la v1

Tablas `wp_csl_*`: `companies` (code GT…, número, nombre, país, moneda, impuesto, WACC, participación, minoritario,
escala, responsable, aprobador), `profiles` (company_id, json), `versions` (key, label, year, status), `rates`
(version_key, currency, month, budget, avg, close, approved_by, approved_at), `catalog` (code…), `products`,
`channels`, `proposals`, `history_cycles`, `history_kpis`, `company_users`, `uploads` (version_key, company_id,
file_name, file_blob, sha256, status, dataset JSON, criticals, warnings, superseded…), `issues` (+ category, col,
month, concept, impact_usd), `results`, `actions`, `log`.
`POST /corporate/import` (corp): sube o lee `Grupo_Corporativo.xlsx` y llena companies, rates, catalog, products,
channels, proposals e historial. `POST /demo/load` (admin): importa el corporativo y los 20 archivos desde
`WP_CONTENT_DIR . '/datos-meridia'` pasándolos por el flujo normal de subida (clasifica por nombre de archivo, §1).
Rutas de §15 v1 se mantienen con `version` (clave) en lugar de `cycle`. Nuevas: `GET /profiles?company=`, `POST
/profiles/{company}` (corp), `GET /proposals`, `GET /history`, `GET /findings?version=` (todos los issues del ciclo
con filtros por país y categoría, y conteo por categoría como el historial).

## 16. Panel (D) — cambios sobre la v1

Países GT, MX, PA, EC, US (con bandera textual y moneda), 4 versiones. Pantallas nuevas o ajustadas: **Hallazgos**
(tabla filtrable por país, versión, categoría y severidad, con fila del Excel y el impacto; resumen por las 6
categorías del historial y comparación con ciclos anteriores), **Perfiles de lectura** (ver/editar el mapa de
etiquetas por país), **Propuestas de inversión** (presentado vs recalculado, decisión), **Evolutivo** (series
2023–2027) y **Preventivo** (ranking y reglas sugeridas). El resto del flujo de 10 pantallas se mantiene.

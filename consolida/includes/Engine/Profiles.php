<?php
/**
 * Perfiles de lectura por país (contrato §5).
 *
 * Un perfil es un arreglo de datos (se guarda como JSON en wp_csl_profiles y el corporativo lo edita en el panel):
 * cómo está armado el Excel de ese país (hojas, layout ancho/largo, fila de encabezado, columnas), cómo escribe las
 * fechas y los números, en qué escala manda los montos y el **mapa de etiquetas** de su archivo → concepto del grupo.
 *
 * defaults() trae los 5 perfiles construidos revisando los 20 archivos reales de datos-meridia/1_Paises
 * (4 versiones por país). Reglas que se siguieron:
 *  - Solo se mapean etiquetas que aparecen en el archivo del país y corresponden a una cuenta del catálogo (o a un
 *    estadístico volume/price/net_sales). Una etiqueta que aparece en una sola versión y no es cuenta del catálogo
 *    NO se mapea: PA «Regional Consulting Fees» (solo en Presupuesto 2027 v1) sale como CAT_UNKNOWN. Sería un gasto
 *    nuevo sin cuenta; mandarlo a 6104 sería adivinar.
 *  - Las búsquedas de etiquetas no distinguen mayúsculas, acentos ni espacios repetidos (CountryReader::norm).
 *  - Los mapas de líneas, canales, tipos y sentidos de intercompañía, contrapartes, portada e indicadores son del grupo
 *    y se ponen completos (español, inglés y códigos) en todos los perfiles: son maestros, no etiquetas locales.
 *  - Columnas de tablas en letra de Excel. Las hojas anchas detectan solas la fila de encabezado (la fila con meses);
 *    header_row es la pista.
 *
 * Conceptos de resultados: volume:<línea>, price:<línea>, net_sales:<línea>, cuentas 4101..8101 del catálogo,
 * total_row (filas «Total Gastos» de EC, se validan, no se suman). Balance: cuentas 1101..3101.
 *
 * PHP puro: sin funciones de WordPress.
 *
 * @package Consolida
 */

declare(strict_types=1);

namespace Consolida\Engine;

final class Profiles
{
    /** Versión del formato del perfil (para migrar perfiles guardados si cambia la estructura). */
    public const SCHEMA = 1;

    /** Líneas de producto del grupo. */
    public const LINES = ['Snacks', 'Bebidas', 'Confitería'];

    /**
     * Los 5 perfiles por defecto, indexados por código de empresa.
     *
     * @return array<string, array>
     */
    public static function defaults(): array
    {
        return [
            'GT' => self::gt(),
            'MX' => self::mx(),
            'PA' => self::pa(),
            'EC' => self::ec(),
            'US' => self::us(),
        ];
    }

    /**
     * Código de empresa a partir del nombre del archivo («Guatemala_Presupuesto_2027.xlsx» → 'GT'), según
     * file_prefix de cada perfil. null si no se reconoce.
     */
    public static function detect(string $fileName, ?array $profiles = null): ?string
    {
        $profiles ??= self::defaults();
        $base = CountryReader::norm(basename($fileName));
        $best = null;
        $bestLen = 0;
        foreach ($profiles as $code => $p) {
            foreach ((array) ($p['file_prefix'] ?? []) as $prefix) {
                $pr = CountryReader::norm((string) $prefix);
                if ($pr !== '' && str_starts_with(str_replace(' ', '_', $base), str_replace(' ', '_', $pr)) && strlen($pr) > $bestLen) {
                    $best = (string) $code;
                    $bestLen = strlen($pr);
                }
            }
        }
        return $best;
    }

    // ------------------------------------------------------------------------------------------------------------
    // Guatemala: ancho, meses en columnas «Ene..Dic», nombres propios en español, unidades, números reales.
    // ------------------------------------------------------------------------------------------------------------

    private static function gt(): array
    {
        return self::base('GT', [
            'name'          => 'Meridia Guatemala',
            'file_prefix'   => ['Guatemala', 'Pais1'],
            'language'      => 'es',
            'date_order'    => 'dmy',
            'date_format'   => 'Meses «Ene..Dic» en columnas (año de la versión); «Ene-2027» en hojas largas; portada dd/mm/aaaa',
            'number_format' => 'numeric',
            'scale'         => 'unidades',
            'sheets'        => [
                'resultados'     => ['layout' => 'wide', 'header_row' => 3, 'label_col' => 'A'],
                'balance'        => ['layout' => 'wide', 'header_row' => 3, 'label_col' => 'A'],
                'intercompania'  => self::icColumns(),
                'ventas_detalle' => self::salesColumns(),
            ],
            'pl' => [
                'Volumen Snacks (cajas)'                => 'volume:Snacks',
                'Precio Promedio Snacks (Q/caja)'       => 'price:Snacks',
                'Ventas Netas Snacks'                   => 'net_sales:Snacks',
                'Ventas Snacks Clientes Externos'       => '4101',
                'Ventas Snacks a Filiales'              => '4111',
                'Volumen Bebidas (cajas)'               => 'volume:Bebidas',
                'Precio Promedio Bebidas (Q/caja)'      => 'price:Bebidas',
                'Ventas Netas Bebidas'                  => 'net_sales:Bebidas',
                'Ventas Bebidas Clientes Externos'      => '4102',
                'Ventas Bebidas a Filiales'             => '4112',
                'Volumen Confitería (cajas)'            => 'volume:Confitería',
                'Precio Promedio Confitería (Q/caja)'   => 'price:Confitería',
                'Ventas Netas Confitería'               => 'net_sales:Confitería',
                'Ventas Confitería Clientes Externos'   => '4103',
                'Ventas Confitería a Filiales'          => '4113',
                'Ingreso Cuota Administración Filiales' => '4901',
                'Costo de Ventas Snacks'                => '5101',
                'Costo de Ventas Bebidas'               => '5102',
                'Costo de Ventas Confitería'            => '5103',
                'Sueldos y Prestaciones'                => '6101',
                'Fletes y Distribución'                 => '6102',
                'Publicidad y Promoción'                => '6103',
                'Gastos de Administración'              => '6104',
                'Depreciaciones'                        => '6201',
                // GT no tiene préstamos intercompañía: «Productos Financieros» son rendimientos bancarios (7101) e
                // «Intereses Bancarios» la deuda bancaria (7102), como dice el contrato.
                'Productos Financieros'                 => '7101',
                'Intereses Bancarios'                   => '7102',
                'ISR'                                   => '8101',
            ],
            'bs' => [
                'Caja y Bancos'                  => '1101',
                'Clientes'                       => '1201',
                'Cuentas por Cobrar Filiales'    => '1202',
                'Inventarios'                    => '1301',
                'Inventario Comprado a Filiales' => '1302',
                'Propiedad, Planta y Equipo'     => '1501',
                'Depreciación Acumulada'         => '1502',
                'Proveedores'                    => '2101',
                'Cuentas por Pagar Filiales'     => '2102',
                'Préstamos Bancarios'            => '2201',
                'Capital y Utilidades Retenidas' => '3101',
            ],
        ]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // México: largo, fechas dd/mm/aaaa, montos en miles de MXN (salvo volumen y precio), columna Unidad.
    // ------------------------------------------------------------------------------------------------------------

    private static function mx(): array
    {
        return self::base('MX', [
            'name'          => 'Meridia México',
            'file_prefix'   => ['Mexico', 'México', 'Pais2'],
            'language'      => 'es',
            'date_order'    => 'dmy',
            'date_format'   => 'dd/mm/aaaa (fin de mes); saldo inicial = 31/12 del año anterior con Tipo «Saldo inicial»',
            'number_format' => 'numeric',
            'scale'         => 'miles',
            'sheets'        => [
                'resultados'     => ['layout' => 'long', 'header_row' => 1,
                    'columns' => ['period' => 'A', 'label' => 'B', 'value' => 'C', 'unit' => 'D']],
                'balance'        => ['layout' => 'long', 'header_row' => 1,
                    'columns' => ['period' => 'A', 'kind' => 'B', 'label' => 'C', 'value' => 'D', 'unit' => 'E']],
                'intercompania'  => self::icColumns(),
                'ventas_detalle' => self::salesColumns(),
            ],
            'pl' => [
                'Volumen Snacks'                            => 'volume:Snacks',
                'Precio Snacks'                             => 'price:Snacks',
                'Ingreso total Snacks'                      => 'net_sales:Snacks',
                'Ingresos Snacks - Clientes'                => '4101',
                'Ingresos Snacks - Partes relacionadas'     => '4111',
                'Volumen Bebidas'                           => 'volume:Bebidas',
                'Precio Bebidas'                            => 'price:Bebidas',
                'Ingreso total Bebidas'                     => 'net_sales:Bebidas',
                'Ingresos Bebidas - Clientes'               => '4102',
                'Ingresos Bebidas - Partes relacionadas'    => '4112',
                'Volumen Confitería'                        => 'volume:Confitería',
                'Precio Confitería'                         => 'price:Confitería',
                'Ingreso total Confitería'                  => 'net_sales:Confitería',
                'Ingresos Confitería - Clientes'            => '4103',
                'Ingresos Confitería - Partes relacionadas' => '4113',
                'Costo Snacks'                              => '5101',
                'Costo Bebidas'                             => '5102',
                'Costo Confitería'                          => '5103',
                'Nómina'                                    => '6101',
                'Logística y fletes'                        => '6102',
                'Mercadotecnia'                             => '6103',
                'Administración'                            => '6104',
                'Management fee corporativo'                => '6105',
                'Depreciación del ejercicio'                => '6201',
                // MX no tiene préstamo intercompañía (ni en intercompania ni en balance): los intereses son
                // bancarios → 7101 / 7102.
                'Ingresos por intereses'                    => '7101',
                'Gastos por intereses'                      => '7102',
                'ISR del ejercicio'                         => '8101',
            ],
            'bs' => [
                'Efectivo y equivalentes'            => '1101',
                'Clientes'                           => '1201',
                'Partes relacionadas por cobrar'     => '1202',
                'Inventarios'                        => '1301',
                'Inventarios de partes relacionadas' => '1302',
                'Activo fijo'                        => '1501',
                'Depreciación acumulada'             => '1502',
                'Proveedores'                        => '2101',
                'Partes relacionadas por pagar'      => '2102',
                'Deuda bancaria'                     => '2201',
                'Capital contable'                   => '3101',
            ],
        ]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Panamá: ancho, inglés, fechas mm/dd/aaaa en el encabezado, «Opening 12/31/aaaa», unidades.
    // ------------------------------------------------------------------------------------------------------------

    private static function pa(): array
    {
        return self::base('PA', [
            'name'          => 'Meridia Panamá',
            'file_prefix'   => ['Panama', 'Panamá', 'Pais3'],
            'language'      => 'en',
            'date_order'    => 'mdy',
            'date_format'   => 'mm/dd/aaaa (fin de mes) en columnas; saldo inicial «Opening 12/31/aaaa»; portada mm/dd/aaaa',
            'number_format' => 'numeric',
            'scale'         => 'unidades',
            'sheets'        => [
                'resultados'     => ['layout' => 'wide', 'header_row' => 3, 'label_col' => 'A'],
                'balance'        => ['layout' => 'wide', 'header_row' => 3, 'label_col' => 'A'],
                'intercompania'  => self::icColumns(),
                'ventas_detalle' => self::salesColumns(),
            ],
            'pl' => [
                'Snacks Volume (cases)'                 => 'volume:Snacks',
                'Snacks Avg Price (USD/case)'           => 'price:Snacks',
                'Snacks Net Sales'                      => 'net_sales:Snacks',
                'Snacks Sales - Third Parties'          => '4101',
                'Snacks Sales - Intercompany'           => '4111',
                'Beverages Volume (cases)'              => 'volume:Bebidas',
                'Beverages Avg Price (USD/case)'        => 'price:Bebidas',
                'Beverages Net Sales'                   => 'net_sales:Bebidas',
                'Beverages Sales - Third Parties'       => '4102',
                'Beverages Sales - Intercompany'        => '4112',
                'Confectionery Volume (cases)'          => 'volume:Confitería',
                'Confectionery Avg Price (USD/case)'    => 'price:Confitería',
                'Confectionery Net Sales'               => 'net_sales:Confitería',
                'Confectionery Sales - Third Parties'   => '4103',
                'Confectionery Sales - Intercompany'    => '4113',
                'COGS Snacks'                           => '5101',
                'COGS Beverages'                        => '5102',
                'COGS Confectionery'                    => '5103',
                'Payroll'                               => '6101',
                'Logistics'                             => '6102',
                'Marketing'                             => '6103',
                'Administration'                        => '6104',
                'Management Fee - HQ'                   => '6105',
                'Depreciation'                          => '6201',
                'Interest Income'                       => '7101',
                'Interest Income - Intercompany Loan'   => '7111',
                // PA presta a EC (cobra intereses) y tiene deuda bancaria: «Interest Expense» es bancario → 7102.
                'Interest Expense'                      => '7102',
                'Income Tax'                            => '8101',
                // 'Regional Consulting Fees' (solo Budget 2027 v1) NO se mapea a propósito: hallazgo CAT_UNKNOWN.
            ],
            'bs' => [
                'Cash'                               => '1101',
                'Accounts Receivable'                => '1201',
                'Intercompany Receivables'           => '1202',
                'Inventory'                          => '1301',
                'Inventory - Intercompany Purchases' => '1302',
                'Intercompany Loan Receivable'       => '1401',
                'PP&E Gross'                         => '1501',
                'Accumulated Depreciation'           => '1502',
                'Accounts Payable'                   => '2101',
                'Intercompany Payables'              => '2102',
                'Bank Debt'                          => '2201',
                'Equity'                             => '3101',
            ],
        ]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Ecuador: largo, periodo aaaa-mm, códigos contables locales (sin nombre), códigos de producto/canal/línea.
    // ------------------------------------------------------------------------------------------------------------

    private static function ec(): array
    {
        return self::base('EC', [
            'name'          => 'Meridia Ecuador',
            'file_prefix'   => ['Ecuador', 'Pais4'],
            'language'      => 'es',
            'date_order'    => 'dmy',
            'date_format'   => 'aaaa-mm; saldo inicial «Saldo inicial»; portada dd/mm/aaaa',
            'number_format' => 'numeric',
            'scale'         => 'unidades',
            'sheets'        => [
                'resultados'       => ['layout' => 'long', 'header_row' => 1,
                    'columns' => ['period' => 'A', 'label' => 'B', 'value' => 'C']],
                'balance'          => ['layout' => 'long', 'header_row' => 1,
                    'columns' => ['period' => 'A', 'label' => 'B', 'value' => 'C']],
                'intercompania'    => self::icColumns(),
                // Sin columna de línea ni nombre de producto: la línea sale del maestro de productos.
                'ventas_detalle'   => ['layout' => 'table', 'header_row' => 1,
                    'columns' => ['period' => 'A', 'product' => 'B', 'channel' => 'C', 'volume' => 'D', 'amount' => 'E']],
            ],
            'pl' => [
                // 9xxx: estadísticos por línea. El último dígito es la línea (1 Snacks, 2 Bebidas, 3 Confitería):
                // comprobado contra ventas_detalle (9301 = Σ SNK-xx del mes) y contra el orden de las filas.
                '9101' => 'volume:Snacks',
                '9201' => 'price:Snacks',
                '9301' => 'net_sales:Snacks',
                '9102' => 'volume:Bebidas',
                '9202' => 'price:Bebidas',
                '9302' => 'net_sales:Bebidas',
                '9103' => 'volume:Confitería',
                '9203' => 'price:Confitería',
                '9303' => 'net_sales:Confitería',
                // 41xx local = ventas a terceros (mismo código que el grupo); 42xx local = ventas intercompañía.
                '4101' => '4101',
                '4102' => '4102',
                '4103' => '4103',
                '4201' => '4111',
                '4202' => '4112',
                '4203' => '4113',
                '5101' => '5101',
                '5102' => '5102',
                '5103' => '5103',
                // 61x0 local → 610x del grupo por el orden del plan de cuentas (personal, logística, mercadeo,
                // administración, cuota GT). 6150 = cuota de administración: coincide con intercompania (35,000 USD
                // por mes «Cuota de administración / Pago» a GT).
                '6110' => '6101',
                '6120' => '6102',
                '6130' => '6103',
                '6140' => '6104',
                '6150' => '6105',
                '6210' => '6201',
                '7110' => '7101',
                // 7210 = gastos financieros bancarios (EC tiene deuda bancaria 2210); 7220 = intereses del préstamo
                // de PA (coincide con «Intereses préstamo / Pago» a PA, 52,500 USD por mes).
                '7210' => '7102',
                '7220' => '7112',
                '8110' => '8101',
                // Filas escritas a mano: se validan contra la suma del detalle (Agente F), no se suman.
                'Total Gastos' => 'total_row',
            ],
            'bs' => [
                '1110' => '1101',
                '1210' => '1201',
                '1220' => '1202',
                '1310' => '1301',
                '1320' => '1302',
                '1510' => '1501',
                '1520' => '1502',
                '2110' => '2101',
                '2120' => '2102',
                '2210' => '2201',
                '2310' => '2301',
                '3110' => '3101',
            ],
        ]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Estados Unidos: largo, Mon-aaaa, inglés, miles de USD, números guardados como texto «1,918.676».
    // ------------------------------------------------------------------------------------------------------------

    private static function us(): array
    {
        return self::base('US', [
            'name'          => 'Meridia USA',
            'file_prefix'   => ['Estados_Unidos', 'Estados Unidos', 'USA', 'Pais5'],
            'language'      => 'en',
            'date_order'    => 'mdy',
            'date_format'   => 'Mon-aaaa (Jan-2027); saldo inicial «Opening Balance»; portada mm/dd/aaaa',
            'number_format' => 'text',
            'scale'         => 'miles',
            'sheets'        => [
                'resultados'     => ['layout' => 'long', 'header_row' => 1,
                    'columns' => ['period' => 'A', 'label' => 'B', 'value' => 'C', 'unit' => 'D']],
                'balance'        => ['layout' => 'long', 'header_row' => 1,
                    'columns' => ['period' => 'A', 'label' => 'B', 'value' => 'C', 'unit' => 'D']],
                'intercompania'  => self::icColumns(),
                'ventas_detalle' => self::salesColumns(),
            ],
            'pl' => [
                'Units Sold - Snacks'                    => 'volume:Snacks',
                'Avg Price - Snacks'                     => 'price:Snacks',
                // «Gross Sales» en el archivo de US es el total de la línea (= External + Affiliates, sin
                // descuentos): por eso es net_sales:<línea>, como indica el contrato.
                'Gross Sales - Snacks'                   => 'net_sales:Snacks',
                'Net Sales - Snacks - External'          => '4101',
                'Net Sales - Snacks - Affiliates'        => '4111',
                'Units Sold - Beverages'                 => 'volume:Bebidas',
                'Avg Price - Beverages'                  => 'price:Bebidas',
                'Gross Sales - Beverages'                => 'net_sales:Bebidas',
                'Net Sales - Beverages - External'       => '4102',
                'Net Sales - Beverages - Affiliates'     => '4112',
                'Units Sold - Confectionery'             => 'volume:Confitería',
                'Avg Price - Confectionery'              => 'price:Confitería',
                'Gross Sales - Confectionery'            => 'net_sales:Confitería',
                'Net Sales - Confectionery - External'   => '4103',
                'Net Sales - Confectionery - Affiliates' => '4113',
                'Cost of Sales - Snacks'                 => '5101',
                'Cost of Sales - Beverages'              => '5102',
                'Cost of Sales - Confectionery'          => '5103',
                'Salaries & Benefits'                    => '6101',
                'Freight & Distribution'                 => '6102',
                'Marketing & Advertising'                => '6103',
                'G&A'                                    => '6104',
                'Corporate Management Fee'               => '6105',
                // «& Amortization»: el catálogo solo tiene 6201 Depreciación; US no reporta intangibles aparte.
                'Depreciation & Amortization'            => '6201',
                // US no tiene préstamos intercompañía: intereses bancarios.
                'Interest Income'                        => '7101',
                'Interest Expense'                       => '7102',
                'Income Taxes'                           => '8101',
            ],
            'bs' => [
                'Cash & Equivalents'            => '1101',
                'Trade Receivables'             => '1201',
                'Due from Affiliates'           => '1202',
                'Inventories'                   => '1301',
                'Inventories - from Affiliates' => '1302',
                'PP&E'                          => '1501',
                'Accumulated Depreciation'      => '1502',
                'Trade Payables'                => '2101',
                'Due to Affiliates'             => '2102',
                'Bank Loans'                    => '2201',
                "Stockholders' Equity"          => '3101',
            ],
        ]);
    }

    // ------------------------------------------------------------------------------------------------------------
    // Piezas comunes
    // ------------------------------------------------------------------------------------------------------------

    /**
     * Arma el perfil completo: datos del país + hojas comunes + mapas del grupo.
     */
    private static function base(string $code, array $p): array
    {
        $sheets = array_merge([
            'portada'                => ['layout' => 'kv', 'key_col' => 'A', 'value_col' => 'B'],
            'intercompania'          => self::icColumns(),
            'ventas_detalle'         => self::salesColumns(),
            'inventario_grupo'       => ['layout' => 'table', 'header_row' => 1,
                'columns' => ['period' => 'A', 'supplier' => 'B', 'line' => 'C', 'amount' => 'D']],
            'capex'                  => ['layout' => 'table', 'header_row' => 1,
                'columns' => ['period' => 'A', 'project' => 'B', 'description' => 'C', 'amount' => 'D']],
            'indicadores_reportados' => ['layout' => 'table', 'header_row' => 1,
                'columns' => ['label' => 'A', 'value' => 'B', 'unit' => 'C']],
        ], $p['sheets']);

        return [
            'schema'        => self::SCHEMA,
            'company'       => $code,
            'name'          => $p['name'],
            'file_prefix'   => $p['file_prefix'],
            'language'      => $p['language'],
            'date_order'    => $p['date_order'],
            'date_format'   => $p['date_format'],
            'number_format' => $p['number_format'],
            'decimal'       => '.',
            'scale'         => $p['scale'],
            'sheets'        => $sheets,
            'maps'          => [
                'pl'           => $p['pl'],
                'bs'           => $p['bs'],
                'line'         => self::lineMap(),
                'channel'      => self::channelMap(),
                'product'      => self::productMap(),
                'ic_type'      => self::icTypeMap(),
                'ic_direction' => self::icDirectionMap(),
                'counterparty' => self::counterpartyMap(),
                'reported'     => self::reportedMap(),
                'cover'        => self::coverMap(),
                'opening'      => ['Saldo inicial', 'Opening', 'Opening Balance', 'Opening balance', 'Apertura'],
            ],
        ];
    }

    private static function icColumns(): array
    {
        return ['layout' => 'table', 'header_row' => 1, 'columns' => [
            'period' => 'A', 'counterparty' => 'B', 'type' => 'C', 'line' => 'D', 'direction' => 'E',
            'amount' => 'F', 'currency' => 'G', 'note' => 'H',
        ]];
    }

    private static function salesColumns(): array
    {
        return ['layout' => 'table', 'header_row' => 1, 'columns' => [
            'period' => 'A', 'line' => 'B', 'product' => 'C', 'product_name' => 'D', 'channel' => 'E',
            'volume' => 'F', 'amount' => 'G',
        ]];
    }

    private static function lineMap(): array
    {
        return [
            'Snacks' => 'Snacks', 'Bebidas' => 'Bebidas', 'Confitería' => 'Confitería',
            'Beverages' => 'Bebidas', 'Confectionery' => 'Confitería',
            'SNK' => 'Snacks', 'BEB' => 'Bebidas', 'CON' => 'Confitería',
        ];
    }

    /** Canales: códigos, nombres en español y en inglés → código del maestro. */
    private static function channelMap(): array
    {
        return [
            'C1' => 'C1', 'C2' => 'C2', 'C3' => 'C3', 'C4' => 'C4',
            'Supermercados' => 'C1', 'Tiendas de barrio' => 'C2', 'Mayoristas' => 'C3', 'Empresas del grupo' => 'C4',
            'Supermarkets' => 'C1', 'Convenience stores' => 'C2', 'Wholesalers' => 'C3', 'Group companies' => 'C4',
        ];
    }

    /**
     * Productos: nombres en español e inglés → código. El código del archivo se valida contra maestro_productos;
     * este mapa permite resolver un producto que venga solo con nombre.
     */
    private static function productMap(): array
    {
        return [
            'Papas clásicas 150 g' => 'SNK-01', 'Papas picantes 150 g' => 'SNK-02', 'Totopos de maíz 200 g' => 'SNK-03',
            'Mix botanero 300 g' => 'SNK-04', 'Agua pura 600 ml' => 'BEB-01', 'Jugo de naranja 1 L' => 'BEB-02',
            'Bebida isotónica 500 ml' => 'BEB-03', 'Té frío limón 500 ml' => 'BEB-04',
            'Chocolate de leche 45 g' => 'CON-01', 'Gomitas frutales 100 g' => 'CON-02',
            'Caramelos surtidos 1 kg' => 'CON-03', 'Galleta rellena 6 pack' => 'CON-04',
            'Classic potato chips 150 g' => 'SNK-01', 'Spicy potato chips 150 g' => 'SNK-02',
            'Corn tortilla chips 200 g' => 'SNK-03', 'Party snack mix 300 g' => 'SNK-04',
            'Still water 600 ml' => 'BEB-01', 'Orange juice 1 L' => 'BEB-02', 'Sports drink 500 ml' => 'BEB-03',
            'Lemon iced tea 500 ml' => 'BEB-04', 'Milk chocolate bar 45 g' => 'CON-01',
            'Fruit gummies 100 g' => 'CON-02', 'Assorted hard candy 1 kg' => 'CON-03',
            'Sandwich cookies 6 pack' => 'CON-04',
        ];
    }

    private static function icTypeMap(): array
    {
        return [
            'Venta de producto' => 'product_sale', 'Product sale' => 'product_sale',
            'Cuota de administración' => 'management_fee', 'Management fee' => 'management_fee',
            'Préstamo' => 'loan', 'Loan' => 'loan',
            'Intereses préstamo' => 'loan_interest', 'Intereses del préstamo' => 'loan_interest',
            'Loan interest' => 'loan_interest',
        ];
    }

    private static function icDirectionMap(): array
    {
        return [
            'Venta' => 'sale', 'Sale' => 'sale',
            'Compra' => 'purchase', 'Purchase' => 'purchase',
            'Cobro' => 'charge', 'Cargo' => 'charge', 'Charge' => 'charge',
            // «Expense» (PA, US) es el lado que paga la cuota: equivale a «Pago».
            'Pago' => 'payment', 'Payment' => 'payment', 'Expense' => 'payment', 'Gasto' => 'payment',
            'Otorgado' => 'granted', 'Concedido' => 'granted', 'Granted' => 'granted',
            'Recibido' => 'received', 'Received' => 'received',
        ];
    }

    private static function counterpartyMap(): array
    {
        return [
            'GT' => 'GT', 'MX' => 'MX', 'PA' => 'PA', 'EC' => 'EC', 'US' => 'US',
            'Meridia Guatemala' => 'GT', 'Meridia México' => 'MX', 'Meridia Panamá' => 'PA',
            'Meridia Ecuador' => 'EC', 'Meridia USA' => 'US', 'Meridia Estados Unidos' => 'US',
            'Guatemala' => 'GT', 'México' => 'MX', 'Panamá' => 'PA', 'Ecuador' => 'EC', 'Estados Unidos' => 'US',
        ];
    }

    /** indicadores_reportados: etiqueta → clave de Dataset.reported (§6). */
    private static function reportedMap(): array
    {
        return [
            'EBIT' => 'ebit',
            'Tasa de impuesto (%)' => 'tax_rate', 'Tax rate (%)' => 'tax_rate',
            'NOPAT' => 'nopat',
            'Capital de trabajo operativo neto (promedio)' => 'nowc_avg',
            'Net operating working capital (average)' => 'nowc_avg',
            'Activo fijo neto (promedio)' => 'net_fixed_avg', 'Net fixed assets (average)' => 'net_fixed_avg',
            'Capital invertido (promedio)' => 'ic_avg', 'Invested capital (average)' => 'ic_avg',
            'ROIC (%)' => 'roic_pct',
            'Capital empleado (promedio)' => 'ce_avg', 'Capital employed (average)' => 'ce_avg',
            'ROCE (%)' => 'roce_pct',
            'WACC (%)' => 'wacc_pct',
            'EVA (moneda local)' => 'eva_local', 'EVA (local currency)' => 'eva_local',
            'Tipo de cambio usado (por USD)' => 'fx_used', 'FX rate used (per USD)' => 'fx_used',
            'EVA (USD)' => 'eva_usd',
        ];
    }

    /** portada: etiqueta → campo de meta. */
    private static function coverMap(): array
    {
        return [
            'Empresa' => 'company_name', 'Company' => 'company_name',
            'País' => 'country', 'Country' => 'country',
            'Moneda' => 'currency', 'Currency' => 'currency',
            'Montos en' => 'amounts_in', 'Amounts in' => 'amounts_in',
            'Escenario' => 'scenario', 'Scenario' => 'scenario',
            'Preparado y cargado por' => 'prepared_by', 'Prepared and uploaded by' => 'prepared_by',
            'Fecha de carga' => 'loaded_at', 'Upload date' => 'loaded_at',
            'Revisado y aprobado por' => 'approved_by', 'Reviewed and approved by' => 'approved_by',
            'Fecha de aprobación' => 'approved_at', 'Approval date' => 'approved_at',
            'Notas' => 'notes', 'Notes' => 'notes',
        ];
    }
}

# Trazo · Consolida

Solución al reto **«Consolidación multiempresa»** (Accounting) para el Grupo Meridia: el presupuesto de un grupo con 5
países y 3 monedas que se consolida solo, en una moneda, sin capturas manuales y con cada cifra explicada.

| | |
| --- | --- |
| Sistema | Plugin de WordPress **Consolida** → [`consolida/`](consolida/) |
| En línea | https://trazo.kinsta.cloud/consolida/ |
| Documento de la solución | [`SOLUCION.md`](SOLUCION.md): reto, procesos, datos analizados, hallazgos y logros del módulo 5 |
| Plan por fases con agentes | [`PLAN.md`](PLAN.md) |
| Contrato técnico | [`CONTRACT.md`](CONTRACT.md) (v2, basado en los datos reales) |
| Datos del reto (ficticios) | [`datos-meridia/`](datos-meridia/) — 20 Excel de país + `Grupo_Corporativo.xlsx` |
| Hallazgos generados | [`consolida/tests/hallazgos.md`](consolida/tests/hallazgos.md) |

## En una línea por módulo

1. **Un solo número desde cada país** — lee el Excel de cada país en su propio formato y lo lleva al catálogo del grupo.
2. **Mismas reglas en todos los países** — tasas oficiales por mes, impuesto, WACC y participación desde el corporativo.
3. **Errores frenados antes del número del grupo** — 6 categorías de reglas + conciliación intercompañía mes a mes.
4. **Cada cifra se explica sola** — volumen, precio, tipo de cambio e intercompañía, hasta la fila del Excel.
5. **Rentabilidad real del grupo** — ROIC, ROCE y EVA calculados, propuestas recalculadas, preventivo, evolutivo y soluciones.

## Resultado sobre los datos reales

- 21 archivos leídos y verificados celda por celda (21,459 valores).
- Presupuesto 2026, Real 2026 y Presupuesto 2027 v2: **0 errores críticos**.
- Presupuesto 2027 v1: **26 errores críticos** encontrados en los 5 países, cada uno con hoja, fila, columna e impacto.
- La propuesta P3 (Estados Unidos) no se sostiene como «aprobar»: corresponde «ajustar».
- Ecuador destruye valor (ROIC < WACC) desde el Real 2026.

## Probar el motor sin WordPress

```bash
cd consolida && php tests/run.php
```

```bash
php consolida/tools/inspect.php --all datos-meridia
```

Autor: Jonathan Diaz · [soydiaz.com](https://www.soydiaz.com) · SolucionWeb.

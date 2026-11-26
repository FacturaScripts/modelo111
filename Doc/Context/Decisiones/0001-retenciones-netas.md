# 0001. Retenciones y bases netas por tipo de asiento

- Fecha: 2026-11-16
- Commits: `1040abc`, `ce674be`, `8d401d4`
- Estado: vigente

## Contexto

Las facturas rectificativas de compra y las regularizaciones de nóminas llevan la retención al debe de 4751; los pagos
de retenciones también. Sumar solo el haber inflaba el modelo y restar todo el debe contaba los pagos como retenciones.

## Decisión

En `Lib/Modelo111.php` el haber suma y el debe resta solo en asientos de factura de compra o con gastos (grupo 6).
La base de sueldos (640) es debe menos haber. Los asientos del plugin y las subcuentas 473 quedan fuera.

## Consecuencias

Cualquier nuevo tipo de asiento con 4751 hay que clasificarlo en `loadReversalAsientos`. Los tests de `Modelo111Test` fijan los casos.

# Cálculo del modelo 111

Resume de dónde sale cada importe y qué asientos cuentan. La referencia funcional está en el `README.md` del plugin.

## Cómo es hoy

`Lib/Modelo111::generate()` sigue estos pasos:

1. `loadDates`: fechas del periodo (T1-T4 o ANNUAL; un periodo desconocido se trata como T1). Los modelos van por año
   natural: cada trimestre cae en el primer año del ejercicio en el que tiene días (`year`) y las fechas se limitan a
   las del ejercicio (`partialPeriod` indica que se ha recortado, y la pantalla y la API avisan). Ficheros, asientos y
   API usan `year`, no el año de `fechainicio`.
2. `loadEntryLines`: partidas del periodo de las subcuentas de la cuenta especial IRPFPR (4751) y de las subcuentas de
   compras de las retenciones (`codsubcuentaacr`). Se excluyen las subcuentas IRPFA (alquileres, modelo 115) y los
   asientos de obligación y pago que genera el propio plugin (por concepto `Modelo 111 ... - Obligación|Pago`).
3. `loadBaseLines`: partidas de sueldos (640) de esos asientos.
4. `loadResults`: retención neta por perceptor, bases y total a ingresar (retenciones menos casilla 29, que no admite
   negativos: la pantalla la pone a 0 con un aviso y la API devuelve 400).
5. `loadGroups111`: totales por grupo de casillas según la clave del perceptor (trabajo 01-03, actividades 07-09,
   premios, forestales, imagen).

Reglas de netos:

- El haber de 4751 suma. El debe resta solo en asientos que revierten retenciones: facturas de compra y asientos con
  partidas del grupo 6.
- El debe de un pago o liquidación (asiento sin gastos) no resta ni cuenta como perceptor.
- Base de factura: neto de las líneas con IRPF (con descuentos del documento) o el neto de la factura. Base de nómina:
  640 al debe menos haber, asignada al perceptor de su contrapartida o al único del asiento; si no se puede repartir va a
  `unassignedBase` y se suma a trabajo.
- Los asientos del plugin se crean con `generateEntries` (obligación 465 contra 4751 y pago 4751 contra 572) y no se
  duplican si ya existe el concepto. Solo desde un trimestre: el anual (190) es informativo y no se ingresa.

## Por qué

- Primero solo se sumaban retenciones; se corrigieron bases (`65311e3`), asientos sin contrapartida (`d7b08e2`,
  `4c2d024`) y el importe de las rectificativas (`1040abc`, `ce674be`, PR 9).
- `8d401d4` completó el neto en todos los asientos (nóminas, pagos, 473 fuera, asientos propios fuera).
- IRPFA se excluye desde `47e1e8b`: esas retenciones corresponden al modelo 115. Sin subcuentas IRPFA no se pueden
  separar (`hasRentalSubaccounts`).
- La casilla 29 pasó de sumarse a restarse (`eb0942b`); después se dejó de admitir un importe negativo, que subía el
  resultado.
- Las fechas se fijaban al 01-01/31-12 del año de `fechainicio`, sin mirar `fechafin`: con un ejercicio que no empieza
  en enero, los trimestres caían fuera de él. Ahora se calculan como se explica arriba.

## Al cambiarlo

Ejecuta `Test/main/Modelo111Test.php`; cubre cada caso de neto. Cualquier cambio del filtro de subcuentas o del concepto
de los asientos propios afecta al recálculo.

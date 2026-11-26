# Modelo111
Plugin para FacturaScripts con los modelos 111 y 190 para la hacienda española.
- https://facturascripts.com/plugins/modelo111

Muestra y genera los datos de ambos modelos desde el menú **Informes → Modelos fiscales → Modelos 111 y 190**, con descarga de los ficheros de presentación de la AEAT (.111 en los trimestres y .190 en el anual), Excel y CSV, impresión y generación de los asientos de obligación y pago.

Las retenciones de alquileres no se incluyen en el cálculo: asigna la cuenta especial **IRPFA** a la subcuenta de retenciones de alquileres (o a su cuenta) para que queden fuera, ya que corresponden al modelo 115.

### Cómo se calcula
- Se usan las partidas del periodo de las subcuentas de retenciones practicadas: las de la cuenta especial **IRPFPR** (4751) y las subcuentas de compras de las retenciones. Las subcuentas de ventas de las retenciones (473) no se incluyen, porque son retenciones que te practican tus clientes.
- Las retenciones y las bases son netas: el haber de 4751 suma y el debe resta cuando revierte una retención, es decir, en los asientos de facturas de compra (rectificativas) y en los que tienen alguna partida de gastos (grupo 6), como la regularización de una nómina. La base de sueldos (640) también es neta: debe menos haber.
- El debe de 4751 de los pagos o liquidaciones de las retenciones (asientos sin gastos) no resta, y su contrapartida no cuenta como perceptor.
- Los asientos de obligación y pago que genera el propio plugin no se tienen en cuenta al volver a calcular el modelo.
- El perceptor de una retención es el proveedor de la factura de compra o, en los asientos sin factura (nóminas), la subcuenta contrapartida de la retención (por ejemplo, una 465 por trabajador). La base de una factura es el neto de sus líneas con IRPF; la de una nómina, sus sueldos (640), que se asignan al perceptor de su contrapartida o, si no tienen, al único perceptor del asiento.
- Cada perceptor tiene una clave del modelo 190 que decide sus casillas del 111: trabajo (claves A a F) en las 01-03 y actividades económicas (G, H e I) en las 07-09. A los proveedores se les propone la clave según su retención (15 % o 19 %: G.01; 7 %: G.03; 2 %: H.01; 1 %: H.04) y a las nóminas, la A.

### Modelo 190
- En el periodo anual, cada perceptor tiene un botón **Editar** para guardar sus datos del 190 en ese ejercicio: NIF, nombre, provincia, clave y subclave y, en las claves A, B y C, los datos personales y familiares (año de nacimiento, situación familiar, discapacidad, contrato, descendientes, ascendientes, gastos deducibles...). Si no hay datos guardados, se proponen los del proveedor (NIF, razón social y provincia de su código postal) o los del último ejercicio en el que se guardaron.
- El fichero .190 sigue el diseño de registro de la AEAT (registro del declarante y uno por perceptor, de 500 posiciones, en ISO-8859-1). No se genera mientras falten datos: la pantalla lista lo que hay que corregir.
- Solo se declaran percepciones dinerarias del propio ejercicio de perceptores con retenciones en la contabilidad. Los trabajadores sin retención, las percepciones en especie, la incapacidad laboral y los atrasos o reintegros de otros ejercicios hay que añadirlos aparte.

## Estructura y API
Cada modelo tiene la misma estructura: una clase de cálculo, una de respuesta de la API y una del fichero de la AEAT.

| Modelo | Cálculo | API | Fichero | Endpoint |
|---|---|---|---|---|
| 111 (trimestral) | `Lib/Modelo111` | `Lib/Modelo111Api` | `Lib/Modelo111File` (.111) | `GET /api/3/modelo111` |
| 190 (resumen anual) | `Lib/Modelo190` (con el cálculo anual de `Modelo111`) | `Lib/Modelo190Api` | `Lib/Modelo190File` (.190) | `GET /api/3/modelo190` |

Las dos clases de la API comparten `Lib/Modelo111ApiTrait` (empresa, ejercicio, periodo, perceptores y avisos) y los dos ficheros, `Lib/Modelo111FileTrait` (formato de los campos). Son traits para que no se desplieguen en `Dinamic/Lib`.

- `GET /api/3/modelo111`: `periodo` T1-T4 (obligatorio), `codejercicio` o `idempresa` y `anio`, `detalle` (perceptores y partidas) e `ingresos_anteriores` (casilla 29). Devuelve el resumen, las casillas 01-30 y el resultado (casilla 30).
- `GET /api/3/modelo190`: `periodo` opcional (solo `ANUAL`), `codejercicio` o `idempresa` y `anio`, y `detalle` (partidas). Devuelve el resumen (registros, totales y errores que impiden generar el fichero), las casillas 01-03 del resumen y siempre el detalle por perceptor. Es informativo: `resultado` vale `null`.

Una clave API sin acceso completo necesita el permiso de lectura del recurso `modelo111` o `modelo190`.

## Documentación
- [Cómo hacer el Modelo 111](https://facturascripts.com/publicaciones/como-hacer-el-modelo-111)
- [Cómo hacer el Modelo 190](https://facturascripts.com/publicaciones/como-hacer-el-modelo-190)

## Issues / Feedback
https://facturascripts.com/contacto

## Tests
Los tests se ejecutan en GitHub Actions contra MySQL y PostgreSQL con cada push. Para lanzarlos en local, copia `Test/main` a `Test/Plugins` en un FacturaScripts con el plugin instalado y ejecuta `vendor/bin/phpunit -c phpunit-plugins.xml`.

## Links
- [Curso de FacturaScripts](https://facturascripts.com/cursos/curso-de-usuario)
- [Programa de contabilidad gratis para autónomos](https://facturascripts.com/software-contabilidad)
- [Programa para hacer facturas gratis](https://facturascripts.com/programa-para-hacer-facturas)
- [Programa para hacer presupuestos gratis](https://facturascripts.com/programa-de-presupuestos)

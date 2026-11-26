# Modelo de datos

El plugin solo crea una tabla, `modelo190_perceptores`; el resto de datos salen de la contabilidad del core.

## Cómo es hoy

`Table/modelo190_perceptores.xml` y `Model/Modelo190Perceptor.php` guardan los datos del 190 de cada perceptor **por
ejercicio**:

- Identificación: `cifnif`, `nombre`, `codprovincia`; clave y subclave de percepción.
- Datos del registro tipo 2 de las claves A, B y C: año de nacimiento, situación familiar, discapacidad, contrato,
  descendientes y ascendientes, reducciones, gastos deducibles, pensión compensatoria, anualidades por alimentos, etc.
- Identidad del perceptor: `tipo` (`proveedor` o `subcuenta`) y `codigo`. Clave única `(codejercicio, tipo, codigo)`;
  clave ajena a `ejercicios` con borrado en cascada.

Un perceptor es el proveedor de la factura de compra o, en asientos sin factura (nóminas), la subcuenta
contrapartida de la retención.

## Por qué

- Esos datos no salen de la contabilidad y el fichero `.190` los exige; por eso se guardan y se editan desde la
  pantalla (`eb0942b`).
- Los importes (`AMOUNT_FIELDS`) son de cada ejercicio y no se copian al proponer datos del último ejercicio guardado.

## Al cambiarlo

Al añadir un campo del registro tipo 2, actualiza la tabla XML, `COUNT_FIELDS` o `AMOUNT_FIELDS` y `Modelo190File::additionalData`.

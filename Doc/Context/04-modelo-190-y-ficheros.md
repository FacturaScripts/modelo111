# Modelo 190 y ficheros de la AEAT

## Cómo es hoy

- El periodo ANNUAL de la pantalla es el resumen del 190. Genera `.190` con `Lib/Modelo190File.php`: registro del
  declarante y uno por perceptor, de 500 posiciones en ISO-8859-1 (diseño de la Orden EHA/3127/2009 según `Lib/Modelo190.php`).
- `Modelo190::validate` y `validateRecipient` listan lo que falta; sin datos completos no se genera el fichero.
- Claves propuestas: proveedores según el porcentaje de retención (`claveFromRetention`: 15 % y 19 % G.01, 7 % G.03,
  2 % H.01, 1 % H.04); nóminas A. Si no hay datos guardados se usan los del proveedor o los del último ejercicio.
- `.111` (`Lib/Modelo111File.php`): formato oficial `<T1110...>`, con tipo de declaración (I, U, G, N), IBAN y
  justificante de la complementaria. En ANNUAL no se descarga `.111`.
- La API (`Modelo111Api`, `Modelo190Api`) devuelve casillas, resumen, detalle y avisos. El 190 es informativo: `resultado` es `null`.

## Por qué

Todo llegó en un único commit grande (`eb0942b`): el 190 anterior (`3a8a839`) solo cambiaba el controlador y la
vista, y el fichero `.111` anterior (`96391ab`) estaba en el controlador con otro diseño.

## Al cambiarlo

Los catálogos (claves, subclaves, provincias) siguen el diseño de la AEAT de un ejercicio concreto; revísalos cuando
la AEAT publique uno nuevo. Solo se declaran percepciones dinerarias del propio ejercicio con retención en contabilidad.

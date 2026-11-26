# Modelo111: contexto del equipo

Esta carpeta guarda **por qué el código es como es**: las decisiones que no se ven leyendo una sola clase, para que
todo el equipo y los agentes trabajen con el mismo contexto. Lee este índice y abre solo el tema o la decisión que
necesites.

Se puede dividir en tantos archivos y subcarpetas como haga falta; este índice dice qué hay en cada uno y se actualiza
en el mismo trabajo que cambia el código.

## Temas

- [`01-arquitectura.md`](01-arquitectura.md): piezas del plugin (pantalla, cálculo, ficheros, API) y qué lee del core.
- [`02-modelo-de-datos.md`](02-modelo-de-datos.md): tabla `modelo190_perceptores` y qué es un perceptor.
- [`03-calculo-del-modelo-111.md`](03-calculo-del-modelo-111.md): qué asientos cuentan y cómo se obtienen retenciones, bases y total.
- [`04-modelo-190-y-ficheros.md`](04-modelo-190-y-ficheros.md): ficheros `.111` y `.190`, claves propuestas, validación y API.
- [`05-evolucion.md`](05-evolucion.md): etapas del código según el historial.

## Decisiones

- [`0001-retenciones-netas.md`](Decisiones/0001-retenciones-netas.md): por qué haber suma y el debe solo resta en ciertos asientos.
- [`0002-irpfa-fuera-del-111.md`](Decisiones/0002-irpfa-fuera-del-111.md): por qué los alquileres no entran en el 111.
- [`0003-perceptores-del-190-por-ejercicio.md`](Decisiones/0003-perceptores-del-190-por-ejercicio.md): por qué se guardan los datos del 190 por ejercicio.
- [`0004-api-de-solo-lectura.md`](Decisiones/0004-api-de-solo-lectura.md): endpoints de solo lectura y traits compartidos.

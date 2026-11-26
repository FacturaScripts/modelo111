# 0003. Datos del perceptor del 190 guardados por ejercicio

- Fecha: 2026-11-26
- Commits: `eb0942b`
- Estado: vigente

## Contexto

El `.190` exige NIF, provincia, clave y datos familiares que la contabilidad no tiene.

## Decisión

Tabla `modelo190_perceptores`, un registro por `(codejercicio, tipo, codigo)`, editable desde la pantalla anual. Se proponen datos del proveedor o del último ejercicio guardado (sin los importes). La clave decide en qué casillas del 111 cae cada perceptor.

## Consecuencias

El fichero no se genera mientras falten datos. Los importes hay que revisarlos cada ejercicio.

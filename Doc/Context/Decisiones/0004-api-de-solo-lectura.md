# 0004. API de solo lectura con traits compartidos

- Fecha: 2026-11-26
- Commits: `eb0942b`
- Estado: vigente

## Contexto

fs-mcp necesita consultar casillas, resumen y detalle de los modelos.

## Decisión

Endpoints `GET /api/3/modelo111` y `/api/3/modelo190` (`Controller/ApiModelo*.php`) que delegan en `Lib/Modelo*Api.php`. Lo común va en `Modelo111ApiTrait` y `Modelo111FileTrait`, traits para que no se desplieguen en `Dinamic/Lib`. No crean ni modifican registros.

## Consecuencias

Una clave API sin acceso completo necesita permiso de lectura de `modelo111` o `modelo190`.

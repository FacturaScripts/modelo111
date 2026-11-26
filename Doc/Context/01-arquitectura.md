# Arquitectura

El plugin calcula los modelos 111 (trimestral) y 190 (resumen anual) de la AEAT a partir de la contabilidad, los muestra
en una pantalla, genera sus ficheros de presentación y los expone por API de solo lectura.

## Cómo es hoy

No depende de otros plugins (`facturascripts.ini` no tiene `require`); pide core 2026.4 como mínimo. Lee del core
asientos, partidas, subcuentas, cuentas especiales, retenciones y facturas de compra. Cada modelo tiene la misma
estructura: cálculo, respuesta de la API y fichero.

| Pieza | Archivo | Qué hace |
|---|---|---|
| Inicio | `Init.php` | Registra `GET /api/3/modelo111` y `/api/3/modelo190` y los añade como recursos de la API. |
| Pantalla | `Controller/Modelo111.php`, `View/Modelo111.html.twig` | Menú Informes, Fiscalidad España (submenú `taxation-spain`). Acciones: descarga `.111` y `.190`, Excel, PDF, guardar perceptor y generar asientos. |
| Cálculo 111 | `Lib/Modelo111.php` | Clase estática con el cálculo de retenciones, bases y perceptores y la creación de los asientos de obligación y pago. |
| Reglas 190 | `Lib/Modelo190.php` | Catálogos (claves, subclaves, provincias), grupos de casillas del 111, validación y datos propuestos. |
| Ficheros | `Lib/Modelo111File.php`, `Lib/Modelo190File.php`, `Lib/Modelo111FileTrait.php` | Fichero `.111` (páginas de 1000 posiciones) y `.190` (registros de 500). El trait da el formato de campos. |
| API | `Controller/ApiModelo111.php`, `Controller/ApiModelo190.php`, `Lib/Modelo111Api.php`, `Lib/Modelo190Api.php`, `Lib/Modelo111ApiTrait.php` | Endpoints GET con casillas, resumen, detalle y avisos. |
| Datos del perceptor | `Model/Modelo190Perceptor.php`, `Table/modelo190_perceptores.xml` | Datos del 190 de cada perceptor por ejercicio. |
| Tests | `Test/main/` | Cálculo, 190, API y ficheros; `install-plugins.txt` lista los plugins que piden. |

## Por qué

- El cálculo salió del controlador a `Lib/Modelo111.php` (`6e96592`) y la estructura cálculo / API / fichero se unificó
  al añadir el 190 y la API (`eb0942b`).
- Los dos ficheros de API y de formato comparten **traits** para que no se desplieguen en `Dinamic/Lib` (README del
  plugin).
- La API existe «para fs-mcp» (mensaje de `eb0942b`).

## Al cambiarlo

- Las clases de `Lib` usan estado estático: un cálculo pisa el anterior dentro de la misma petición.
- Los endpoints no escriben nada; una clave API sin acceso completo necesita permiso de lectura de `modelo111` o `modelo190`.

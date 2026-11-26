El plugin Modelo111 expone los datos de los modelos 111 (retenciones trimestrales) y 190 (resumen anual) en dos endpoints de solo lectura de la API REST. Calculan con las mismas clases que la pantalla del plugin, así que las cifras coinciden con las que se ven en **menú Informes** → **Modelos fiscales** → **Modelos 111 y 190**. No crean asientos, no guardan datos y no generan los ficheros de la AEAT.

- `GET /api/3/modelo111`: un trimestre. Devuelve el resumen, las casillas y el resultado.
- `GET /api/3/modelo190`: el año completo. Devuelve el resumen, tres casillas y el detalle por perceptor. Es una declaración informativa y su `resultado` es `null`.

## 1. Autenticación y permisos

La API tiene que estar activada y hace falta una clave API, que se envía en la cabecera `Token` (también vale `X-Auth-Token`). Una clave con **acceso completo** puede llamar a los dos endpoints. Una clave sin acceso completo necesita, para cada endpoint, el permiso de lectura (`GET`) del recurso `modelo111` o `modelo190`; el plugin añade los dos recursos al listado de recursos de la API (`ApiRoot::addCustomResource()` en `Init.php`).

```bash
curl -H "Token: TU_CLAVE_API" \
  "https://tu-servidor/api/3/modelo111?periodo=T1&codejercicio=2025"
```

## 2. Parámetros

Todos van en la query string y los mismos nombres sirven para los dos endpoints, salvo los indicados.

| Parámetro | Endpoint | Descripción |
|---|---|---|
| `periodo` | 111 | **Obligatorio.** `T1`, `T2`, `T3` o `T4` (también `1T`-`4T`, en cualquier combinación de mayúsculas y minúsculas). |
| `periodo` | 190 | Opcional. Solo `ANUAL` (también `ANNUAL`, `Y`, `A` y `0A`), que es el valor por defecto. |
| `codejercicio` | ambos | Código del ejercicio. Si se indica, manda sobre `idempresa` y `anio`. |
| `idempresa` | ambos | Empresa. Por defecto, la predeterminada del programa. |
| `anio` | ambos | Año de cuatro cifras. Por defecto, el actual. Se usa el ejercicio de la empresa cuya fecha de inicio cae en ese año. |
| `detalle` | ambos | `1`, `true`, `yes`, `si`, `sí` u `on` para incluir las partidas de retenciones y de sueldos. En el 111 también añade los perceptores; en el 190 los perceptores van siempre. |
| `ingresos_anteriores` | 111 | Importe ya ingresado en autoliquidaciones anteriores del mismo periodo (casilla 29). Se pasa en positivo y se resta de las retenciones: casilla 30 = casilla 28 − casilla 29. Acepta coma decimal. Por defecto, `0`. |

El modelo 111 no admite el periodo anual: el resumen anual está en `/api/3/modelo190`.

## 3. Estructura de la respuesta

Los dos endpoints devuelven un objeto JSON con estos campos:

- `modelo`: `"111"` o `"190"`.
- `plugin_version`: versión del plugin, leída de la configuración del plugin.
- `empresa`: `idempresa`, `nombre` y `cifnif`.
- `ejercicio`: `codejercicio`, `nombre`, `fechainicio`, `fechafin` (en formato `Y-m-d`) y `estado`.
- `periodo`, `fechainicio` y `fechafin`: el periodo calculado y sus fechas. Los modelos van por año natural: cada trimestre cae en el año del ejercicio en el que tiene días y las fechas se limitan a las del ejercicio. Si el ejercicio no abarca el periodo natural entero (un ejercicio de julio a junio, por ejemplo, en el anual), `avisos` lo indica con las fechas que se han usado.
- `parametros`: en el 111, `ingresos_anteriores`; en el 190, un objeto vacío.
- `resumen`, `casillas` y `resultado`: ver más abajo.
- `detalle`: perceptores y, con `detalle=1`, `partidas` y `partidas_base`.
- `avisos`: lista de textos en español sobre los datos del modelo (por ejemplo, que no hay retenciones en el periodo, que el ejercicio no tiene subcuentas de retenciones de alquileres o que hay sueldos sin perceptor asignado).

### Modelo 111

`resumen` contiene `perceptores`, `base_retenciones`, `retenciones_practicadas`, `ingresos_anteriores`, `total_ingresar`, `total_debe` y `total_haber`. `casillas` usa la numeración oficial: perceptores, percepciones y retenciones del trabajo (`01`-`03`), de actividades económicas (`07`-`09`), de premios (`13`-`15`), de aprovechamientos forestales (`19`-`21`) y de derechos de imagen (`25`-`27`), más `28` (suma de retenciones), `29` (ingresos anteriores) y `30` (resultado a ingresar). `resultado` repite la casilla `30`.

```bash
curl -H "Token: TU_CLAVE_API" \
  "https://tu-servidor/api/3/modelo111?periodo=T1&idempresa=5&anio=2025"
```

```json
{
  "modelo": "111",
  "plugin_version": 2.51,
  "empresa": { "idempresa": 5, "nombre": "Empresa Ejemplo SL", "cifnif": "B00000001" },
  "ejercicio": {
    "codejercicio": "2025",
    "nombre": "Ejercicio 2025",
    "fechainicio": "2025-01-01",
    "fechafin": "2025-12-31",
    "estado": "ABIERTO"
  },
  "periodo": "T1",
  "fechainicio": "2025-01-01",
  "fechafin": "2025-03-31",
  "parametros": { "ingresos_anteriores": 0 },
  "resumen": {
    "perceptores": 3,
    "base_retenciones": 12400,
    "retenciones_practicadas": 1410,
    "ingresos_anteriores": 0,
    "total_ingresar": 1410,
    "total_debe": 0,
    "total_haber": 1410
  },
  "casillas": {
    "01": 2, "02": 11400, "03": 1260,
    "07": 1, "08": 1000, "09": 150,
    "13": 0, "14": 0, "15": 0,
    "19": 0, "20": 0, "21": 0,
    "25": 0, "26": 0, "27": 0,
    "28": 1410, "29": 0, "30": 1410
  },
  "resultado": 1410,
  "avisos": []
}
```

Con `detalle=1` se añade `detalle.perceptores` (ver el 190), `detalle.partidas` (partidas de las subcuentas de retenciones del periodo) y `detalle.partidas_base` (partidas de sueldos, 640, de los asientos con retenciones). Cada partida trae `idasiento`, `numero`, `fecha`, `codsubcuenta`, `codcontrapartida`, `concepto`, `debe` y `haber`.

### Modelo 190

`resumen` contiene `registros` (perceptores), `total_percepciones`, `total_retenciones`, `fichero_valido` (`true` si el fichero de la AEAT se podría generar con los datos actuales) y `errores` (textos que lo impiden). `casillas` solo tiene `01` (registros), `02` (percepciones) y `03` (retenciones). `resultado` es siempre `null`.

Cada elemento de `detalle.perceptores` trae:

| Campo | Contenido |
|---|---|
| `tipo` | Origen del perceptor: `proveedor` o `subcuenta`. |
| `codigo` | Código del proveedor o de la subcuenta. |
| `codsubcuenta` | Subcuenta contrapartida. |
| `descripcion` | Descripción del perceptor. |
| `cifnif`, `nombre`, `codprovincia` | Datos que se declararán en el modelo 190. |
| `clave`, `subclave` | Clave y subclave de percepción del modelo 190. |
| `grupo_111` | Grupo de casillas del 111 en el que cuenta: `trabajo`, `actividades`, `premios`, `forestales` o `imagen`. |
| `datos_guardados` | `true` si los datos están guardados para ese ejercicio; `false` si son los propuestos. |
| `base`, `retencion` | Percepción y retención del año. |
| `errores_190` | Lista de datos que faltan o son incorrectos para el fichero. |

```bash
curl -H "Token: TU_CLAVE_API" \
  "https://tu-servidor/api/3/modelo190?idempresa=5&anio=2025"
```

```json
{
  "modelo": "190",
  "plugin_version": 2.51,
  "empresa": { "idempresa": 5, "nombre": "Empresa Ejemplo SL", "cifnif": "B00000001" },
  "ejercicio": {
    "codejercicio": "2025",
    "nombre": "Ejercicio 2025",
    "fechainicio": "2025-01-01",
    "fechafin": "2025-12-31",
    "estado": "ABIERTO"
  },
  "periodo": "ANUAL",
  "fechainicio": "2025-01-01",
  "fechafin": "2025-12-31",
  "parametros": {},
  "resumen": {
    "registros": 4,
    "total_percepciones": 51000,
    "total_retenciones": 5738,
    "fichero_valido": true,
    "errores": []
  },
  "casillas": { "01": 4, "02": 51000, "03": 5738 },
  "resultado": null,
  "detalle": {
    "perceptores": [
      {
        "tipo": "proveedor",
        "codigo": "P0002",
        "codsubcuenta": "4100000002",
        "descripcion": "Asesoría Ejemplo",
        "cifnif": "00000002R",
        "nombre": "Asesoría Ejemplo",
        "codprovincia": "28",
        "clave": "G",
        "subclave": "01",
        "grupo_111": "actividades",
        "datos_guardados": false,
        "base": 4000,
        "retencion": 600,
        "errores_190": []
      },
      {
        "tipo": "proveedor",
        "codigo": "P0003",
        "codsubcuenta": "4100000003",
        "descripcion": "Diseñadora Ejemplo",
        "cifnif": "00000003W",
        "nombre": "Diseñadora Ejemplo",
        "codprovincia": "41",
        "clave": "G",
        "subclave": "03",
        "grupo_111": "actividades",
        "datos_guardados": false,
        "base": 1400,
        "retencion": 98,
        "errores_190": []
      },
      {
        "tipo": "subcuenta",
        "codigo": "4650000001",
        "codsubcuenta": "4650000001",
        "descripcion": "Empleado Ejemplo 1 (nómina)",
        "cifnif": "00000004A",
        "nombre": "EJEMPLO UNO EMPLEADO",
        "codprovincia": "28",
        "clave": "A",
        "subclave": "",
        "grupo_111": "trabajo",
        "datos_guardados": true,
        "base": 24000,
        "retencion": 2880,
        "errores_190": []
      },
      {
        "tipo": "subcuenta",
        "codigo": "4650000002",
        "codsubcuenta": "4650000002",
        "descripcion": "Empleada Ejemplo 2 (nómina)",
        "cifnif": "00000005G",
        "nombre": "EJEMPLO DOS EMPLEADA",
        "codprovincia": "28",
        "clave": "A",
        "subclave": "",
        "grupo_111": "trabajo",
        "datos_guardados": true,
        "base": 21600,
        "retencion": 2160,
        "errores_190": []
      }
    ]
  },
  "avisos": []
}
```

Los ejemplos son respuestas reales de una instalación de pruebas, con los datos personales sustituidos por valores ficticios. Los textos de `errores`, `errores_190` y `avisos` salen en el idioma del servidor.

## 4. Errores

Los errores de los endpoints del plugin tienen la forma `{"status": "error", "message": "..."}`, y algunos añaden datos extra.

| Código | `message` | Cuándo |
|---|---|---|
| 400 | `missing-period` | Falta `periodo` en el 111 (`allowed` lista los valores válidos). |
| 400 | `invalid-period` | Periodo no válido, o distinto de `ANUAL` en el 190 (`allowed`). |
| 400 | `invalid-parameter` | `ingresos_anteriores` no es un número o es negativo (`parameter`). |
| 400 | `invalid-year` | `anio` no tiene cuatro cifras (`anio`). |
| 404 | `exercise-not-found` | No existe el ejercicio (`codejercicio`) o la empresa no tiene ejercicio en ese año (`idempresa` y `anio`). |
| 404 | `company-not-found` | No existe la empresa (`idempresa`). |
| 405 | `method-not-allowed` | Cualquier método distinto de `GET`. |

Los errores de acceso los genera el core, con el mismo formato y el texto en `message`: 401 si la clave no es válida, 403 si la clave no tiene permiso de lectura sobre el recurso, 409 si la API está desactivada y 429 si la IP está bloqueada o se supera el límite de peticiones por minuto.

## 5. Usarlo desde otro plugin o desde un cliente

Desde PHP, otro plugin puede llamar a la clase de la API sin pasar por HTTP. `build()` devuelve el código HTTP y el cuerpo ya construido:

```php
use FacturaScripts\Dinamic\Lib\Modelo111Api;
use FacturaScripts\Dinamic\Lib\Modelo190Api;

$r111 = Modelo111Api::build(['periodo' => 'T1', 'codejercicio' => '2025']);
$r190 = Modelo190Api::build(['idempresa' => 5, 'anio' => 2025, 'detalle' => '1']);

if ($r111['code'] === 200) {
    $total = $r111['body']['casillas']['30'];
}
```

Los parámetros son los mismos de la query string. Desde fuera del servidor, basta una petición `GET` con la clave API.

El servidor MCP de fs-mcp envuelve estos endpoints en dos herramientas de solo lectura, `get_modelo_111` y `get_modelo_190`, que aceptan `idempresa` y `anio` o `codejercicio`, `periodo` y `detalle`. Requieren una versión del plugin que tenga los endpoints.

## 6. Estructura del código

Cada modelo tiene la misma estructura: una clase de cálculo, una de respuesta de la API y una del fichero de la AEAT.

| Archivo | Qué hace |
|---|---|
| `Init.php` | Registra las rutas con `Kernel::addRoute()` y los recursos con `ApiRoot::addCustomResource()`. |
| `Controller/ApiModelo111.php`, `Controller/ApiModelo190.php` | Extienden `ApiController`: validan que el método sea `GET`, leen la query string y responden con el resultado de `build()`. |
| `Lib/Modelo111.php` | Cálculo del 111 y del anual: `generate($codejercicio, $periodo, $ingresosAnteriores)`. El periodo anual se pide como `ANNUAL`. |
| `Lib/Modelo190.php` | Catálogos del 190 (claves, subclaves, provincias), datos de cada perceptor y validación (`validate()` y `validateRecipient()`). |
| `Lib/Modelo111Api.php`, `Lib/Modelo190Api.php` | Construyen la respuesta de cada endpoint con `build(array $params)`. |
| `Lib/Modelo111File.php`, `Lib/Modelo190File.php` | Generan los ficheros de presentación (.111 y .190) con `generate()`. El .190 usa registros de 500 posiciones en ISO-8859-1. |
| `Model/Modelo190Perceptor.php` | Datos de cada perceptor por ejercicio, en la tabla `modelo190_perceptores` (`Table/modelo190_perceptores.xml`), única por `codejercicio`, `tipo` y `codigo`. |

`Lib/Modelo111ApiTrait.php` (empresa, ejercicio, periodo, perceptores, partidas y avisos) y `Lib/Modelo111FileTrait.php` (formato de los campos de los ficheros) son traits, no clases base, para que no se desplieguen en `Dinamic/Lib`, que es común a todos los plugins. Para ampliar la API, por ejemplo con un campo nuevo en los perceptores, se añade en el método correspondiente del trait o de la clase `*Api`; para usar las clases desde otro plugin, se importan siempre desde el espacio de nombres `Dinamic\Lib`.

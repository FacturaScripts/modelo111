# CHANGELOG – Documentación oficial de los modelos 111 y 190

## 2026-11-26 (primera ejecución, carpeta vacía)

### Qué se ha descargado
- **Páginas (53)** en `paginas/`, con la URL de origen en la primera línea: ayuda técnica del 111 y del 190 (presentación
  electrónica, por fichero y por formulario, certificados, datos anteriores, predeclaración en papel, cálculo de
  retenciones), instrucciones del 111, ficha del procedimiento GH01, normativa y novedades del 190 en la campaña de
  informativas 2025, plazos, cuestiones relevantes, notas informativas, proyectos de orden, ejercicios anteriores del 190,
  guía práctica del censal 036 (capítulo 9) y las páginas de «Retenciones e ingresos a cuenta» del IRPF.
- **Diseños de registro** (`actual/`): `dr111e16v18.xls` (111, v 1.8, Orden EHA/3127/2009, ejercicios 2019 y siguientes,
  actualizado en mayo de 2021) y `DISENOS_LOGICOS_190_2025.pdf` (190, ejercicio 2025, con la Orden HAC/1431/2025).
- **Otros documentos** (`actual/`): certificado único de retenciones 2025, cuadros de tipos de retención IRPF 2015 a 2026,
  `NOVEDADES_2025.pdf` y `RECORDATORIOS_INF_2025.pdf` de la campaña de informativas, y el material del servicio de
  cálculo de retenciones 2026 (algoritmo, esquema `AEATRetenciones2026.xsd`, `Doc_adicional_W3C_2026.zip` sin
  descomprimir y `espeSWRET_2026.pdf`).
- **Normativa del BOE** (`actual/normativa/`, 21 textos consolidados): Orden EHA/586/2011 (modelo 111), Orden
  EHA/3127/2009 (modelo 190) y las 17 órdenes que la modifican o regulan la presentación (EHA/3061/2010 a HAC/1431/2025),
  más la Ley 35/2006 del IRPF y el Reglamento (RD 439/2007).

### Versiones vigentes comprobadas
- Modelo 111: diseño de registro **v 1.8** (mayo de 2021); página de ayuda técnica actualizada el 26/03/2026 y ficha GH01
  del 03/08/2026. Sin cambios de diseño recientes.
- Modelo 190: diseño de registro **ejercicio 2025** (Orden HAC/1431/2025, de 3 de diciembre, BOE del 12/12/2025). Novedades
  de la campaña: nuevo contenido del campo «Código de provincia», subclaves 03, 04 y 99 de la clave B, subclaves K01, K03,
  K04 y K05 de la clave K, nuevo campo de rendimientos de participaciones con derechos económicos especiales (DA 53.ª LIRPF)
  y campo «Tipo/s de prestación/es de la clave B.01». Página de ayuda técnica actualizada el 09/01/2026.
- Presentación: el 190 se presenta por formulario o por fichero (TGVI online, obligatorio por encima de 40.000
  registros); ambas vías son excluyentes. El 111 se presenta por formulario web, por fichero `.111` o en papel
  (predeclaración).

### ALERTA
- El tablón del portal de desarrolladores anuncia (17/11/2026) que **los modelos 190 y 296 de informativas 2026 ya están
  en el entorno de pruebas**, y (18/11/2026) nuevas versiones de los documentos de validaciones de los modelos XML 170, 181
  y 196. Aún no hay un diseño de registro del 190 para 2026 en la Sede (el último es el de 2025): vigila
  `modelos-100-199.html` y vuelve a ejecutar la skill cuando se publique.

### Descarga manual (el portal de desarrolladores no se pudo leer con curl) – HECHA el 2026-11-26
`www.agenciatributaria.es` devuelve «SSL certificate problem: self signed certificate in certificate chain» (la raíz
«AC RAIZ FNMT-RCM SERVIDORES SEGUROS» no está en la lista de confianza de este equipo) y no se desactiva la
verificación. Con el navegador integrado se comprobó que los cinco ficheros existen (HTTP 200); el navegador no los guarda
en disco: el usuario los descargó a mano y se registraron con `registrar_descarga.py` (`--origen manual`); los cinco SHA-256
coinciden con los publicados y están en `actual/`. `consultas.xsd` llegó como `consultas.xsd.xml` y se guardó con su nombre oficial.

| Fichero (base `https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/`) | Tamaño | Last-Modified | SHA-256 publicado |
|---|---|---|---|
| `Modelo_111/espes111e22v10.docx` (111, ejercicios 2022 y siguientes, v 1.0) | 34.294 | 14/04/2023 | `e4c6bad764e669fcc0727aa7bd670b5f59a44b3dac3e0f845db7574e69f62423` |
| `Modelo_111/espes111e19v11.doc` (111, ejercicios 2019 a 2021, v 1.1) | 573.440 | 25/01/2019 | `cb474aa1dd35d5612ba5b336dd51c906bbdc385397cfc687c47976cd7b6ef3f7` |
| `Informativas/General/Especificaciones_TGVI_Online.pdf` (TGVI online, v 2.8) | 1.259.756 | 17/10/2024 | `bc64dc52f898835c3ad9914c0b62070c9898a2304ddfe14d6c2585e4c7ebdb57` |
| `General/EspecificacionesServiciosDeclaracionesAEAT_ES.pdf` | 1.093.136 | 05/11/2026 | `cdbd1cad6d6db079d264490d5a0d6484818225db95a1d3ba563b253affce605e` |
| `General/consultas.xsd` | 2.342 | 17/09/2020 | `54220f18daa13363cb98558d15441ae6752c6eb60799e812893a482a433f41d2` |

Páginas del portal leídas con el navegador (su texto no se guarda en `paginas/`): `Documentacion/Pagos_a_cuenta/Modelo_111`,
`Documentacion/Declaraciones_Informativas/General`, `Documentacion/General`, `Documentacion/Retenciones/Ejercicio_2026` y el
tablón de anuncios. Para el 190 el portal no publica un documento propio: se usa el diseño de registro de la Sede y la
especificación general de TGVI online.

### Resultado
- 45 ficheros en el manifest (40 por script y 5 manuales del portal de desarrolladores). Sin nombres largos. Nada pendiente de descarga manual.

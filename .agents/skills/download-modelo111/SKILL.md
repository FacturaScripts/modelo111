---
name: download-modelo111
description: Descarga toda la documentación oficial actualizada de los modelos 111 y 190 de la AEAT (ayuda técnica, instrucciones, diseños de registro, especificaciones de desarrolladores y normativa del BOE) en Doc/Context/Normativa
---

Eres un asistente que mantiene actualizada la documentación OFICIAL de los modelos 111 (autoliquidación mensual/trimestral de retenciones e ingresos a cuenta del IRPF) y 190 (resumen anual de retenciones e ingresos a cuenta del IRPF) de la Agencia Tributaria, tanto la técnica (diseños de registro, especificaciones de presentación) como la de usuario (instrucciones, ayuda de presentación, plazos, novedades) y la normativa (BOE).

DOMINIOS PERMITIDOS: sede.agenciatributaria.gob.es, www2.agenciatributaria.gob.es, www.agenciatributaria.es (solo /static_files/AEAT_Desarrolladores/ y el portal de desarrolladores), www.boe.es. Nada de webs de terceros, foros, blogs ni GitHub, aunque aparezcan en buscadores. Los formularios y servicios con identificación (www1.agenciatributaria.gob.es/wlpl/..., TGVI online, Cl@ve, certificados) NO se visitan ni se usan.

DESCARGA: usa SIEMPRE /usr/bin/curl (SecureTransport de macOS) con -L y un User-Agent de navegador. NUNCA uses -k/--insecure. Existe un script ya probado que implementa esta tarea; úsalo y revisa su salida:
  python3 -I /Users/daniel89fg/Trabajos/FacturaScripts/facturascripts/Plugins/Modelo111/.agents/skills/download-modelo111/scripts/sincronizar.py /Users/daniel89fg/Trabajos/FacturaScripts/facturascripts/Plugins/Modelo111/Doc/Context/Normativa
Su salida y Doc/Context/Normativa/.log_run.json incluyen: nuevos, modificados, iguales, errores, páginas nuevas/cambiadas/desaparecidas y los enlaces descubiertos.

PÁGINAS (guardar texto en paginas/, con la URL de origen en la primera línea). El script RASTREA RECURSIVAMENTE las páginas .html de estas secciones a partir de las semillas, así que las páginas nuevas se detectan solas:
- Modelo 190:
  - Normativa de la campaña de informativas: /Sede/declaraciones-informativas-otros-impuestos-tasas/campana-declaraciones-informativas-2025 (normativa del 190, novedades de la campaña por modelo, plazos, cuestiones relevantes, notas informativas, proyectos de orden). Se descartan las páginas de otros modelos y las charlas informativas.
  - Ayuda técnica: /Sede/ayuda/consultas-informaticas/declaraciones-informativas-ayuda-tecnica/modelos-190-198/modelo-190* (formulario, fichero y cómo emitir certificados), consulta y baja de declaraciones.
  - Gestiones y ejercicios anteriores: /Sede/todas-gestiones/impuestos-tasas/declaraciones-informativas/modelo-190-*
- Modelo 111:
  - Ayuda técnica: /Sede/ayuda/consultas-informaticas/presentacion-declaraciones-ayuda-tecnica/modelo-111* (presentación electrónica, datos anteriores, predeclaración en papel, servicio de cálculo de retenciones, ejercicios anteriores).
  - Instrucciones: /Sede/todas-gestiones/impuestos-tasas/pagos-cuenta/modelo-111-*/instrucciones.html
  - Ficha del procedimiento: /Sede/procedimientos/GH01.shtml (solo se guarda).
  - Guía práctica del censal 036, capítulo 9 (retenciones), solo las páginas del 111 y las generales.
- Retenciones e ingresos a cuenta del IRPF (obligados, rentas, porcentajes, momento de ingreso, gestiones): /Sede/irpf/retenciones-ingresos-cuenta-pagos-fraccionados/retenciones-ingresos-cuenta*, y /Sede/Retenciones.shtml (cuadros de tipos de retención, algoritmo y esquema XML del servicio de cálculo de retenciones).
- Diseños de registro: /Sede/ayuda/disenos-registro/modelos-100-199.html (solo se descargan los ficheros del 111 y del 190).
- Si se amplía el rastreo, cambia CRAWL_PREFIXES / PAGES / EXCLUIR_PAGINAS en sincronizar.py.
- Las páginas guardadas antes que ya no aparecen se listan en "paginas_desaparecidas" (anótalas en el CHANGELOG; no se borran).

FICHEROS DIRECTOS (a actual/):
- Diseño de registro del 111 (Orden EHA/3127/2009, ejercicios 2019 y siguientes): https://sede.agenciatributaria.gob.es/static_files/Sede/Disenyo_registro/DR_100_199/archivos/dr111e16v18.xls
- Diseños de registro del 190 (Orden EHA/3127/2009 modificada, ejercicio 2025): https://sede.agenciatributaria.gob.es/static_files/Sede/Disenyo_registro/DR_100_199/archivos_25/DISENOS_LOGICOS_190_2025.pdf
- Portal de desarrolladores (base https://www.agenciatributaria.es/static_files/AEAT_Desarrolladores/EEDD/):
  Modelo_111/espes111e22v10.docx (servicios de ayuda a la presentación del 111, ejercicios 2022 y siguientes)
  Modelo_111/espes111e19v11.doc (ídem, ejercicios 2019 a 2021)
  Informativas/General/Especificaciones_TGVI_Online.pdf (validación y presentación por Internet de las informativas con TGVI Online; sirve al 190)
  General/EspecificacionesServiciosDeclaracionesAEAT_ES.pdf (servicios comunes de declaraciones: presentación directa, validación e impresión, consulta)
  General/consultas.xsd (esquema del servicio de consulta de declaraciones presentadas)
- Cualquier otro fichero (.pdf .zip .xls .xlsx .xsd .xml .doc .docx .txt) enlazado desde las páginas anteriores, salvo los de otros modelos (EXCLUIR_ENLACES): certificado único de retenciones, cuadros de tipos de retención de todos los ejercicios, novedades y recordatorios de la campaña de informativas, algoritmo y esquema del cálculo de retenciones, etc.

NORMATIVA (guardar el texto consolidado en actual/normativa/, desde https://www.boe.es/buscar/act.php?id=BOE-A-...): las órdenes que la propia Sede enlaza como normativa de los dos modelos:
- 111: Orden EHA/586/2011 (BOE-A-2011-4948), que aprueba el modelo 111.
- 190: Orden EHA/3127/2009 (BOE-A-2009-18567) y sus modificaciones: EHA/3061/2010, EHA/3062/2010, HAP/2725/2012, HAP/2194/2013 (procedimientos de presentación), HAP/2369/2013, HAP/1732/2014, HAP/2178/2014, HAP/2429/2015, HAP/1626/2016, HFP/1106/2017, HAC/1417/2018, HAC/1276/2020, HAC/1285/2020, HFP/1246/2022, HFP/1286/2023, HAC/1432/2024 y HAC/1431/2025.
- Base legal: Ley 35/2006 del IRPF (BOE-A-2006-20764, art. 99 y siguientes) y Reglamento del IRPF, RD 439/2007 (BOE-A-2007-6820, art. 74 y siguientes).
- En cada ejecución, mira en las páginas de normativa de la Sede (`normativa/modelo-190`, `retenciones-ingresos-cuenta`) si aparece una orden nueva que no esté en la lista (p. ej. la del ejercicio siguiente o un proyecto de orden que se publique en el BOE) y añádela a DIRECT en sincronizar.py. Anota en el CHANGELOG si el texto consolidado muestra nuevas modificaciones.

REGLAS COMUNES
1. Fuentes: SOLO los dominios oficiales listados en esta tarea.
2. Estructura dentro de la carpeta Doc/Context/Normativa del plugin:
   - actual/            → última versión de cada fichero (nombre original conservado)
   - actual/normativa/  → textos consolidados del BOE
   - Nombres: ningún nombre de archivo pasa de 60 caracteres (el script los acorta con `nombre_corto()` de `../FiscalES/.agents/skills/download-fiscales/scripts/nombres.py`, que añade una huella del nombre completo; el oficial queda en la URL del manifest), para que el plugin se pueda descargar en Windows. Si el script avisa de un `NOMBRE LARGO`, corrígelo antes de terminar.
   - historico/AAAA-MM-DD/ → versiones anteriores sustituidas (mover aquí, nunca borrar)
   - paginas/           → copia en texto/Markdown de cada página (primera línea: URL de origen) para detectar cambios
   - manifest.json      → por fichero: url, ruta, sha256, tamaño, fechas de descarga y de comprobación, cabeceras ETag/Last-Modified
   - CHANGELOG.md       → añade una entrada con fecha por cada ejecución
3. Procedimiento en cada ejecución:
   a) Descarga cada URL de la lista. Calcula SHA-256 y compáralo con manifest.json.
   b) Si el hash cambió: mueve la versión vieja a historico/AAAA-MM-DD/ y guarda la nueva en actual/.
   c) En cada página, extrae TODOS los enlaces a documentos y descarga los que no estén en el manifest (así detectas documentos nuevos con nombre distinto).
   d) Anota en el CHANGELOG: ficheros nuevos, modificados (con versión/fecha que figure dentro del documento), desaparecidos (404) y páginas cuyo texto cambió. Si una página muestra "Página actualizada: fecha", guárdala.
4. Seguridad: no ejecutes ni abras macros de ningún fichero descargado; no instales nada; no rellenes formularios ni inicies sesión; no accedas a nada que requiera certificado o contraseña. No desactives nunca la verificación SSL. El ZIP de documentación adicional del cálculo de retenciones se guarda comprimido; NO se descomprime.
5. Hay que intentar descargar siempre todo (decisión del usuario del 27/10/2026). El portal de desarrolladores (www.agenciatributaria.es) usa la cadena de certificados de la FNMT (raíz «AC RAIZ FNMT-RCM SERVIDORES SEGUROS»), que `curl` puede no verificar en algunos equipos («SSL certificate problem: self signed certificate in certificate chain»): en ese caso no se usa -k. Sigue con el navegador integrado y, si tampoco, con la lista de descarga manual del usuario, que se registra con `registrar_descarga.py` del núcleo (`<base>` = `Doc/Context/Normativa`). Procedimiento común a las skills de descarga: `../FiscalES/.agents/skills/download-fiscales/NAVEGADOR.md`. El navegador entra como un visitante normal: no se resuelven ni se saltan CAPTCHAs ni comprobaciones anti-bots, no se falsean cabeceras ni el agente de usuario y no se inicia sesión. Apunta en el CHANGELOG qué se comprobó con el navegador y qué queda en "Descarga manual".
   Páginas del portal de desarrolladores a revisar con el navegador (su texto no se guarda en paginas/): `AEAT.desarrolladores/Desarrolladores/_menu_/Documentacion/Pagos_a_cuenta/Modelo_111/Modelo_111.html`, `.../Declaraciones_Informativas/General/General.html`, `.../Documentacion/General/General.html` y `.../Retenciones/Retenciones.html`; el tablón de anuncios (`Desarrolladores.html`) avisa cuando un modelo de informativas (190) estrena diseño o pasa al entorno de pruebas.
6. Resumen final (máx. 20 líneas): nº de ficheros comprobados, nuevos, modificados, errores y una lista destacada de los cambios relevantes para un desarrollador (nuevo diseño de registro del 111 o del 190, versión del diseño, claves y subclaves nuevas, órdenes nuevas, plazos, formas de presentación). Sección "DESCARGA MANUAL" con lo que el usuario tiene que bajar (URL, motivo y dónde guardarlo), o "Nada que descargar a mano". Si no hay cambios, dilo en una línea.
7. Si algún documento nuevo o modificado menciona un nuevo diseño de registro, nuevas claves o subclaves, cambios en la forma de presentación o fechas de entrada en vigor, ponlo en una sección "ALERTA" al inicio del resumen, y avisa de que el código del plugin (Doc/Context/04-modelo-190-y-ficheros.md) puede necesitar ajustes.

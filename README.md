# Modelo111
Plugin para FacturaScripts con los modelos 111 y 190 para la hacienda española.
- https://facturascripts.com/plugins/modelo111

Muestra y genera los datos de ambos modelos desde el menú **Informes → Modelos fiscales → Modelos 111 y 190**, con descarga en formato .111, Excel y CSV, impresión y generación de los asientos de obligación y pago.

Las retenciones de alquileres no se incluyen en el cálculo: asigna la cuenta especial **IRPFA** a la subcuenta de retenciones de alquileres (o a su cuenta) para que queden fuera, ya que corresponden al modelo 115.

## Documentación
- [Cómo hacer el Modelo 111](https://facturascripts.com/publicaciones/como-hacer-el-modelo-111)
- [Cómo hacer el Modelo 190](https://facturascripts.com/publicaciones/como-hacer-el-modelo-190)

## Issues / Feedback
https://facturascripts.com/contacto

## Tests
Los tests se ejecutan en GitHub Actions contra MySQL y PostgreSQL con cada push. Para lanzarlos en local, copia `Test/main` a `Test/Plugins` en un FacturaScripts con el plugin instalado y ejecuta `vendor/bin/phpunit -c phpunit-plugins.xml`.

## Links
- [Curso de FacturaScripts](https://www.youtube.com/watch?v=rGopZA3ErzE&list=PLNxcJ5CWZ8V6nfeVu6vieKI_d8a_ObLfY)
- [Programa de contabilidad gratis para autónomos](https://facturascripts.com/software-contabilidad)
- [Programa para hacer facturas gratis](https://facturascripts.com/programa-para-hacer-facturas)
- [Programa para hacer presupuestos gratis](https://facturascripts.com/programa-de-presupuestos)

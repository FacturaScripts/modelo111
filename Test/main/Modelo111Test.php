<?php
/**
 * This file is part of Modelo111 plugin for FacturaScripts
 * Copyright (C) 2026 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Core\Lib\Calculator;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\PagoCliente;
use FacturaScripts\Dinamic\Model\PagoProveedor;
use FacturaScripts\Dinamic\Model\Partida;
use FacturaScripts\Dinamic\Model\Retencion;
use FacturaScripts\Dinamic\Model\Subcuenta;
use FacturaScripts\Plugins\Modelo111\Lib\Modelo111;
use FacturaScripts\Test\Traits\RandomDataTrait;

final class Modelo111Test extends Modelo111TestCase
{
    use RandomDataTrait;

    /**
     * El debe de 4751 de un pago de las retenciones (contra bancos, sin gastos) no debe restar de las
     * retenciones practicadas, ni su contrapartida contar como perceptor.
     */
    public function testDebeDePagoNoResta(): void
    {
        $exercise = $this->getCurrentExercise();
        $this->createNominaAsiento($exercise, 1000.0, 150.0);

        // pago del modelo: 4751 al debe, bancos (572) al haber
        $asiento = new Asiento();
        $asiento->codejercicio = $exercise->codejercicio;
        $asiento->concepto = 'Pago de prueba';
        $asiento->fecha = Tools::date();
        $asiento->idempresa = $exercise->idempresa;
        $asiento->importe = 150.0;
        $this->assertTrue($asiento->save());
        $this->addCleanup(static function () use ($asiento) {
            if ($asiento->exists()) {
                $asiento->delete();
            }
        });

        $p1 = new Partida();
        $p1->idasiento = $asiento->idasiento;
        $p1->codsubcuenta = '4751000000';
        $p1->debe = 150.0;
        $p1->codcontrapartida = '5720000000';
        $p1->concepto = $asiento->concepto;
        $this->assertTrue($p1->save());

        $p2 = new Partida();
        $p2->idasiento = $asiento->idasiento;
        $p2->codsubcuenta = '5720000000';
        $p2->haber = 150.0;
        $p2->codcontrapartida = '4751000000';
        $p2->concepto = $asiento->concepto;
        $this->assertTrue($p2->save());

        $result = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod());
        $this->assertEqualsWithDelta(150.0, $result['retencionesPracticadas'], 0.001);
        $this->assertEqualsWithDelta(150.0, $result['totalIngresar'], 0.001);
        $this->assertSame(1, $result['numRecipients']);
        $this->assertArrayNotHasKey('5720000000', $result['recipientDetails']);
    }

    public function testEjercicioInexistente(): void
    {
        $this->assertSame([], Modelo111::generate('NO-EXISTE', 'T1'));
    }

    /**
     * Las retenciones de alquileres (cuenta especial IRPFA) van al modelo 115,
     * no deben aparecer en el modelo 111. Issue 110-286-046.
     */
    public function testExcluyeRetencionesAlquiler(): void
    {
        $exercise = $this->getCurrentExercise();

        // creamos la subcuenta de IRPF de alquileres con la cuenta especial IRPFA
        $subAlquiler = new Subcuenta();
        $subAlquiler->codcuenta = '4751';
        $subAlquiler->codcuentaesp = 'IRPFA';
        $subAlquiler->codejercicio = $exercise->codejercicio;
        $subAlquiler->codsubcuenta = '4751000019';
        $subAlquiler->descripcion = 'HP acreedora retenciones alquileres';
        $this->assertTrue($subAlquiler->save());

        // creamos la retención del 19% de alquileres sobre esa subcuenta
        $retencion = new Retencion();
        $retencion->codretencion = 'ALQ19';
        $retencion->codsubcuentaacr = $subAlquiler->codsubcuenta;
        $retencion->descripcion = 'IRPF alquileres 19%';
        $retencion->porcentaje = 19;
        $this->assertTrue($retencion->save());
        $this->addCleanup(static function () use ($retencion, $subAlquiler) {
            $retencion->delete();
            $subAlquiler->delete();
        });

        // una nómina normal: 1000€ de sueldo con 150€ de retención
        $this->createNominaAsiento($exercise, 1000.0, 150.0);

        // y un asiento de alquiler: 800€ con 152€ de retención en la subcuenta IRPFA
        $asiento = new Asiento();
        $asiento->codejercicio = $exercise->codejercicio;
        $asiento->concepto = 'Alquiler de prueba';
        $asiento->fecha = Tools::date();
        $asiento->idempresa = $exercise->idempresa;
        $asiento->importe = 800.0;
        $this->assertTrue($asiento->save());
        $this->addCleanup(static function () use ($asiento) {
            if ($asiento->exists()) {
                $asiento->delete();
            }
        });

        // arrendamientos y cánones (621) al debe
        $p1 = new Partida();
        $p1->idasiento = $asiento->idasiento;
        $p1->codsubcuenta = '6210000000';
        $p1->debe = 800.0;
        $p1->codcontrapartida = '4100000000';
        $p1->concepto = $asiento->concepto;
        $this->assertTrue($p1->save());

        // retención de alquileres (4751000019, IRPFA) al haber
        $p2 = new Partida();
        $p2->idasiento = $asiento->idasiento;
        $p2->codsubcuenta = $subAlquiler->codsubcuenta;
        $p2->haber = 152.0;
        $p2->codcontrapartida = '4100000000';
        $p2->concepto = $asiento->concepto;
        $this->assertTrue($p2->save());

        // acreedores (410) al haber
        $p3 = new Partida();
        $p3->idasiento = $asiento->idasiento;
        $p3->codsubcuenta = '4100000000';
        $p3->haber = 648.0;
        $p3->codcontrapartida = '6210000000';
        $p3->concepto = $asiento->concepto;
        $this->assertTrue($p3->save());

        $result = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod());
        $this->assertNotEmpty($result);

        // solo debe salir la nómina: la retención del alquiler queda fuera
        $this->assertEqualsWithDelta(150.0, $result['retencionesPracticadas'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $result['baseRetenciones'], 0.001);
        $this->assertEqualsWithDelta(150.0, $result['totalIngresar'], 0.001);
        $this->assertSame(1, $result['numRecipients']);
        $this->assertArrayNotHasKey('4100000000', $result['recipientDetails']);
    }

    public function testGenerate(): void
    {
        $exercise = $this->getCurrentExercise();

        // creamos un asiento de nómina: 1000€ de sueldo con 150€ de retención de IRPF
        $asiento = $this->createNominaAsiento($exercise, 1000.0, 150.0);

        $result = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod());
        $this->assertNotEmpty($result);

        // casilla 01: número de perceptores
        $this->assertSame(1, $result['numRecipients']);

        // casilla 02: base de las retenciones (sueldos y salarios)
        $this->assertEqualsWithDelta(1000.0, $result['baseRetenciones'], 0.001);

        // casilla 03: retenciones practicadas
        $this->assertEqualsWithDelta(150.0, $result['retencionesPracticadas'], 0.001);

        // casilla 05: total a ingresar
        $this->assertEqualsWithDelta(150.0, $result['totalIngresar'], 0.001);

        // detalle del perceptor
        $this->assertCount(1, $result['recipientDetails']);
        $recipient = reset($result['recipientDetails']);
        $this->assertEqualsWithDelta(1000.0, $recipient['base'], 0.001);
        $this->assertEqualsWithDelta(150.0, $recipient['retencion'], 0.001);

        // totales debe/haber de las líneas de retención
        $this->assertEqualsWithDelta(0.0, $result['totalDebe'], 0.001);
        $this->assertEqualsWithDelta(150.0, $result['totalHaber'], 0.001);

        $this->assertTrue($asiento->exists());
    }

    public function testGenerateConIngresosAnteriores(): void
    {
        $exercise = $this->getCurrentExercise();
        $this->createNominaAsiento($exercise, 2000.0, 300.0);

        $result = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod(), -100.0);
        $this->assertNotEmpty($result);

        // casilla 05 = retenciones practicadas + resultados de periodos anteriores
        $this->assertEqualsWithDelta(300.0, $result['retencionesPracticadas'], 0.001);
        $this->assertEqualsWithDelta(200.0, $result['totalIngresar'], 0.001);
    }

    public function testGenerateEntries(): void
    {
        $exercise = $this->getCurrentExercise();
        $this->createNominaAsiento($exercise, 1000.0, 150.0);

        $result = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod());
        $this->assertNotEmpty($result);

        // programamos la limpieza de los asientos generados
        $concepto = 'Modelo 111 ' . $result['period'] . ' ' . date('Y', strtotime($exercise->fechainicio));
        $this->addCleanup(static function () use ($exercise, $concepto) {
            $where = [
                Where::eq('codejercicio', $exercise->codejercicio),
                Where::like('concepto', $concepto . '%'),
            ];
            foreach (Asiento::all($where) as $asiento) {
                $asiento->delete();
            }
        });

        // generamos los asientos de obligación y pago
        $this->assertTrue(Modelo111::generateEntries($result));

        // comprobamos que se han creado los dos asientos
        $obligacion = new Asiento();
        $this->assertTrue($obligacion->loadWhere([
            Where::eq('codejercicio', $exercise->codejercicio),
            Where::eq('concepto', $concepto . ' - Obligación'),
        ]));
        $this->assertEqualsWithDelta(150.0, $obligacion->importe, 0.001);

        $pago = new Asiento();
        $this->assertTrue($pago->loadWhere([
            Where::eq('codejercicio', $exercise->codejercicio),
            Where::eq('concepto', $concepto . ' - Pago'),
        ]));
        $this->assertEqualsWithDelta(150.0, $pago->importe, 0.001);

        // si volvemos a generar, no debe crear asientos duplicados
        $this->assertFalse(Modelo111::generateEntries($result));

        // los asientos de obligación y pago no deben contar al volver a calcular el modelo
        $again = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod());
        $this->assertCount(1, $again['entryLines']);
        $this->assertSame(1, $again['numRecipients']);
        $this->assertEqualsWithDelta(1000.0, $again['baseRetenciones'], 0.001);
        $this->assertEqualsWithDelta(150.0, $again['retencionesPracticadas'], 0.001);
        $this->assertEqualsWithDelta(150.0, $again['totalIngresar'], 0.001);
    }

    public function testGenerateEntriesSinDatos(): void
    {
        $this->assertFalse(Modelo111::generateEntries([]));
    }

    /**
     * Una factura de compra con retención y su rectificativa (que revierte la retención al debe)
     * deben dar una retención neta de cero.
     */
    public function testRectificativaCompraRestaRetencion(): void
    {
        $exercise = $this->getCurrentExercise();
        $this->ensurePaymentTables();

        // retención del 15% sobre la subcuenta de IRPF de acreedores
        $retencion = $this->getRetencion15();

        $subject = $this->getRandomSupplier('Modelo111Test');
        $this->assertTrue($subject->save());
        $this->addCleanup(static function () use ($subject) {
            $subject->delete();
        });

        // factura de 1000€ con 15% de retención: 150€ al haber de 4751
        $invoice = new FacturaProveedor();
        $invoice->setSubject($subject);
        $this->assertTrue($invoice->save());
        $line = $invoice->getNewLine();
        $line->cantidad = 1;
        $line->pvpunitario = 1000;
        $line->irpf = $retencion->porcentaje;
        $this->assertTrue($line->save());
        $lines = $invoice->getLines();
        $this->assertTrue(Calculator::calculate($invoice, $lines, true));
        $this->addCleanup(static function () use ($invoice) {
            $invoice->delete();
        });

        $before = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod());
        $this->assertEqualsWithDelta(150.0, $before['retencionesPracticadas'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $before['baseRetenciones'], 0.001);

        // rectificativa de 500€: revierte 75€ de retención al debe de 4751
        $refund = new FacturaProveedor();
        $refund->setSubject($subject);
        $refund->idfacturarect = $invoice->idfactura;
        $refund->codigorect = $invoice->codigo;
        $this->assertTrue($refund->save());
        $refundLine = $refund->getNewLine();
        $refundLine->cantidad = -1;
        $refundLine->pvpunitario = 500;
        $refundLine->irpf = $retencion->porcentaje;
        $this->assertTrue($refundLine->save());
        $refundLines = $refund->getLines();
        $this->assertTrue(Calculator::calculate($refund, $refundLines, true));
        $this->addCleanup(static function () use ($refund) {
            $refund->delete();
        });

        $after = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod());
        $this->assertEqualsWithDelta(75.0, $after['retencionesPracticadas'], 0.001);
        $this->assertEqualsWithDelta(500.0, $after['baseRetenciones'], 0.001);
        $this->assertEqualsWithDelta(75.0, $after['totalIngresar'], 0.001);
        $this->assertEqualsWithDelta(75.0, $after['totalDebe'], 0.001);
        $this->assertEqualsWithDelta(150.0, $after['totalHaber'], 0.001);
    }

    /**
     * La regularización de una nómina (4751 al debe y sueldos al haber) resta de las retenciones
     * practicadas y de la base: 1.500 € al haber y 500 € al debe dan 1.000 € de retenciones.
     */
    public function testRegularizacionNominaResta(): void
    {
        $exercise = $this->getCurrentExercise();
        $this->createNominaAsiento($exercise, 10000.0, 1500.0);
        $this->createRegularizacionNominaAsiento($exercise, 3000.0, 500.0);

        $result = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod());
        $this->assertSame(1, $result['numRecipients']);
        $this->assertEqualsWithDelta(7000.0, $result['baseRetenciones'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $result['retencionesPracticadas'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $result['totalIngresar'], 0.001);
        $this->assertEqualsWithDelta(500.0, $result['totalDebe'], 0.001);
        $this->assertEqualsWithDelta(1500.0, $result['totalHaber'], 0.001);

        // detalle del perceptor con los importes netos
        $this->assertArrayHasKey('4650000000', $result['recipientDetails']);
        $recipient = $result['recipientDetails']['4650000000'];
        $this->assertEqualsWithDelta(7000.0, $recipient['base'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $recipient['retencion'], 0.001);
    }

    /**
     * Una factura de venta con retención (473 al debe) y su rectificativa (473 al haber) no son
     * retenciones practicadas por el declarante, por lo que no deben alterar el modelo 111:
     * ni las retenciones, ni la base, ni el número de perceptores.
     */
    public function testVentaConRetencionNoAltera(): void
    {
        $exercise = $this->getCurrentExercise();
        $this->ensurePaymentTables();
        $this->createNominaAsiento($exercise, 1000.0, 150.0);

        // retención del 13% con subcuenta de ventas (473) configurada
        $retencion = new Retencion();
        $retencion->codretencion = 'T111V';
        $retencion->descripcion = 'IRPF 13% test ventas';
        $retencion->porcentaje = 13;
        $retencion->codsubcuentaacr = '4751000000';
        $retencion->codsubcuentaret = '4730000000';
        $this->assertTrue($retencion->save());
        $this->addCleanup(static function () use ($retencion) {
            $retencion->delete();
        });

        $before = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod());

        $customer = $this->getRandomCustomer('Modelo111Test');
        $this->assertTrue($customer->save());
        $this->addCleanup(static function () use ($customer) {
            $customer->delete();
        });

        $invoice = new FacturaCliente();
        $invoice->setSubject($customer);
        $this->assertTrue($invoice->save());
        $line = $invoice->getNewLine();
        $line->cantidad = 1;
        $line->pvpunitario = 1000;
        $line->irpf = $retencion->porcentaje;
        $this->assertTrue($line->save());
        $lines = $invoice->getLines();
        $this->assertTrue(Calculator::calculate($invoice, $lines, true));
        $this->addCleanup(static function () use ($invoice) {
            $invoice->delete();
        });

        // rectificativa de venta de 500€: revierte la retención al haber de 473
        $refund = new FacturaCliente();
        $refund->setSubject($customer);
        $refund->idfacturarect = $invoice->idfactura;
        $refund->codigorect = $invoice->codigo;
        $this->assertTrue($refund->save());
        $refundLine = $refund->getNewLine();
        $refundLine->cantidad = -1;
        $refundLine->pvpunitario = 500;
        $refundLine->irpf = $retencion->porcentaje;
        $this->assertTrue($refundLine->save());
        $refundLines = $refund->getLines();
        $this->assertTrue(Calculator::calculate($refund, $refundLines, true));
        $this->addCleanup(static function () use ($refund) {
            $refund->delete();
        });

        // comprobamos que las dos facturas tienen la retención contabilizada en 473
        foreach ([$invoice, $refund] as $doc) {
            $irpfLines = Partida::all([
                Where::eq('idasiento', $doc->idasiento),
                Where::eq('codsubcuenta', '4730000000'),
            ]);
            $this->assertNotEmpty($irpfLines, 'sale-irpf-line-not-found');
        }

        $after = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod());
        $this->assertCount(count($before['entryLines']), $after['entryLines']);
        $this->assertSame($before['numRecipients'], $after['numRecipients']);
        $this->assertEqualsWithDelta($before['baseRetenciones'], $after['baseRetenciones'], 0.001);
        $this->assertEqualsWithDelta($before['retencionesPracticadas'], $after['retencionesPracticadas'], 0.001);
        $this->assertEqualsWithDelta($before['totalIngresar'], $after['totalIngresar'], 0.001);
    }

    private function createNominaAsiento(Ejercicio $exercise, float $sueldo, float $retencion): Asiento
    {
        $asiento = new Asiento();
        $asiento->codejercicio = $exercise->codejercicio;
        $asiento->concepto = 'Nómina de prueba';
        $asiento->fecha = Tools::date();
        $asiento->idempresa = $exercise->idempresa;
        $asiento->importe = $sueldo;
        $this->assertTrue($asiento->save());
        $this->addCleanup(static function () use ($asiento) {
            if ($asiento->exists()) {
                $asiento->delete();
            }
        });

        // sueldos y salarios (640) al debe
        $p1 = new Partida();
        $p1->idasiento = $asiento->idasiento;
        $p1->codsubcuenta = '6400000000';
        $p1->debe = $sueldo;
        $p1->codcontrapartida = '4650000000';
        $p1->concepto = $asiento->concepto;
        $this->assertTrue($p1->save());

        // retención de IRPF (4751) al haber
        $p2 = new Partida();
        $p2->idasiento = $asiento->idasiento;
        $p2->codsubcuenta = '4751000000';
        $p2->haber = $retencion;
        $p2->codcontrapartida = '4650000000';
        $p2->concepto = $asiento->concepto;
        $this->assertTrue($p2->save());

        // remuneraciones pendientes de pago (465) al haber
        $p3 = new Partida();
        $p3->idasiento = $asiento->idasiento;
        $p3->codsubcuenta = '4650000000';
        $p3->haber = $sueldo - $retencion;
        $p3->codcontrapartida = '6400000000';
        $p3->concepto = $asiento->concepto;
        $this->assertTrue($p3->save());

        return $asiento;
    }

    /**
     * Crea el asiento de regularización de una nómina, inverso al de createNominaAsiento(): sueldos (640)
     * al haber, la retención revertida (4751) al debe y remuneraciones pendientes de pago (465) al debe.
     *
     * @param Ejercicio $exercise ejercicio del asiento
     * @param float $sueldo importe de sueldo que se regulariza
     * @param float $retencion importe de retención que se revierte
     *
     * @return Asiento
     */
    private function createRegularizacionNominaAsiento(Ejercicio $exercise, float $sueldo, float $retencion): Asiento
    {
        $asiento = new Asiento();
        $asiento->codejercicio = $exercise->codejercicio;
        $asiento->concepto = 'Regularización de nómina de prueba';
        $asiento->fecha = Tools::date();
        $asiento->idempresa = $exercise->idempresa;
        $asiento->importe = $sueldo;
        $this->assertTrue($asiento->save());
        $this->addCleanup(static function () use ($asiento) {
            if ($asiento->exists()) {
                $asiento->delete();
            }
        });

        // sueldos y salarios (640) al haber
        $p1 = new Partida();
        $p1->idasiento = $asiento->idasiento;
        $p1->codsubcuenta = '6400000000';
        $p1->haber = $sueldo;
        $p1->codcontrapartida = '4650000000';
        $p1->concepto = $asiento->concepto;
        $this->assertTrue($p1->save());

        // retención de IRPF revertida (4751) al debe
        $p2 = new Partida();
        $p2->idasiento = $asiento->idasiento;
        $p2->codsubcuenta = '4751000000';
        $p2->debe = $retencion;
        $p2->codcontrapartida = '4650000000';
        $p2->concepto = $asiento->concepto;
        $this->assertTrue($p2->save());

        // remuneraciones pendientes de pago (465) al debe
        $p3 = new Partida();
        $p3->idasiento = $asiento->idasiento;
        $p3->codsubcuenta = '4650000000';
        $p3->debe = $sueldo - $retencion;
        $p3->codcontrapartida = '6400000000';
        $p3->concepto = $asiento->concepto;
        $this->assertTrue($p3->save());

        return $asiento;
    }

    /**
     * Crea las tablas de pagos de clientes y proveedores si no existen. Al borrar una factura se borran sus
     * recibos, que consultan sus pagos dentro de una transacción, donde no se pueden crear tablas: si la
     * tabla no existe, el borrado falla y la factura y su asiento se quedan en la base de datos.
     */
    private function ensurePaymentTables(): void
    {
        new PagoCliente();
        new PagoProveedor();
    }

    private function getCurrentExercise(): Ejercicio
    {
        $exercise = new Ejercicio();
        $exercise->idempresa = Tools::settings('default', 'idempresa');
        $this->assertTrue($exercise->loadFromDate(Tools::date()));
        return $exercise;
    }

    private function getCurrentPeriod(): string
    {
        return 'T' . ceil((int)date('n') / 3);
    }

    /**
     * Devuelve la retención del 15%, creándola (y programando su borrado) si no existe.
     *
     * @return Retencion
     */
    private function getRetencion15(): Retencion
    {
        $retencion = new Retencion();
        if (false === $retencion->loadFromPercentage(15)) {
            $retencion->codretencion = 'T111';
            $retencion->descripcion = 'IRPF 15% test';
            $retencion->porcentaje = 15;
            $retencion->codsubcuentaacr = '4751000000';
            $this->assertTrue($retencion->save());
            $this->addCleanup(static function () use ($retencion) {
                $retencion->delete();
            });
        }
        return $retencion;
    }
}

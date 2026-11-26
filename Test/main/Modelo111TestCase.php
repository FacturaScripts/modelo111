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
use FacturaScripts\Core\Model\User;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\PagoCliente;
use FacturaScripts\Dinamic\Model\PagoProveedor;
use FacturaScripts\Dinamic\Model\Partida;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Dinamic\Model\Retencion;
use FacturaScripts\Test\Traits\DefaultSettingsTrait;
use FacturaScripts\Test\Traits\LogErrorsTrait;
use PHPUnit\Framework\TestCase;

class Modelo111TestCase extends TestCase
{
    use DefaultSettingsTrait;
    use LogErrorsTrait;

    /**
     * Cleanup callbacks to run after each test in reverse order.
     *
     * @var array<int, callable>
     */
    private array $cleanupCallbacks = [];

    public function setUp(): void
    {
        // inicializamos los modelos para que se creen las
        // tablas necesarias y no de error de Foreign Key
        new User();

        self::setDefaultSettings();
        self::installAccountingPlan();
    }

    protected function addCleanup(callable $callback): void
    {
        $this->cleanupCallbacks[] = $callback;
    }

    protected function tearDown(): void
    {
        while ($callback = array_pop($this->cleanupCallbacks)) {
            try {
                $callback();
            } catch (\Throwable $th) {
                error_log($th->getMessage());
            }
        }

        $this->logErrors();
    }

    /**
     * Crea un asiento de nómina: sueldos (640) al debe, la retención (4751) y remuneraciones pendientes de
     * pago (465) al haber, y programa su borrado al terminar el test.
     *
     * @param Ejercicio $exercise ejercicio del asiento
     * @param float $sueldo importe del sueldo
     * @param float $retencion importe de la retención
     *
     * @return Asiento
     */
    protected function createNominaAsiento(Ejercicio $exercise, float $sueldo, float $retencion): Asiento
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
     * Devuelve el ejercicio abierto de la empresa predeterminada para la fecha actual.
     *
     * @return Ejercicio
     */
    protected function getCurrentExercise(): Ejercicio
    {
        $exercise = new Ejercicio();
        $exercise->idempresa = Tools::settings('default', 'idempresa');
        $this->assertTrue($exercise->loadFromDate(Tools::date()));
        return $exercise;
    }

    /**
     * Devuelve el trimestre actual (T1-T4).
     *
     * @return string
     */
    protected function getCurrentPeriod(): string
    {
        return 'T' . ceil((int)date('n') / 3);
    }

    /**
     * Crea una factura de compra de una línea con retención (o una rectificativa si se indica la factura
     * original) y programa su borrado al terminar el test.
     *
     * @param Proveedor $subject proveedor de la factura
     * @param float $quantity cantidad de la línea (negativa en las rectificativas)
     * @param float $price precio unitario de la línea
     * @param float $irpf porcentaje de retención de la línea
     * @param FacturaProveedor|null $original factura que rectifica
     *
     * @return FacturaProveedor
     */
    protected function createSupplierInvoice(Proveedor $subject, float $quantity, float $price, float $irpf, ?FacturaProveedor $original = null): FacturaProveedor
    {
        $invoice = new FacturaProveedor();
        $invoice->setSubject($subject);
        if (null !== $original) {
            $invoice->idfacturarect = $original->idfactura;
            $invoice->codigorect = $original->codigo;
        }
        $this->assertTrue($invoice->save());
        $line = $invoice->getNewLine();
        $line->cantidad = $quantity;
        $line->pvpunitario = $price;
        $line->irpf = $irpf;
        $this->assertTrue($line->save());
        $lines = $invoice->getLines();
        $this->assertTrue(Calculator::calculate($invoice, $lines, true));
        $this->addCleanup(static function () use ($invoice) {
            $invoice->delete();
        });

        return $invoice;
    }

    /**
     * Crea las tablas de pagos de clientes y proveedores si no existen. Al borrar una factura se borran sus
     * recibos, que consultan sus pagos dentro de una transacción, donde no se pueden crear tablas: si la
     * tabla no existe, el borrado falla y la factura y su asiento se quedan en la base de datos.
     */
    protected function ensurePaymentTables(): void
    {
        new PagoCliente();
        new PagoProveedor();
    }

    /**
     * Devuelve la retención del porcentaje indicado, creándola (y programando su borrado) si no existe.
     *
     * @param int $percentage porcentaje de la retención
     *
     * @return Retencion
     */
    protected function getRetencion(int $percentage): Retencion
    {
        $retencion = new Retencion();
        if (false === $retencion->loadFromPercentage($percentage)) {
            $retencion->codretencion = 'T111' . $percentage;
            $retencion->descripcion = 'IRPF ' . $percentage . '% test';
            $retencion->porcentaje = $percentage;
            $retencion->codsubcuentaacr = '4751000000';
            $this->assertTrue($retencion->save());
            $this->addCleanup(static function () use ($retencion) {
                $retencion->delete();
            });
        }
        return $retencion;
    }

    /**
     * Devuelve la retención del 15%, creándola (y programando su borrado) si no existe.
     *
     * @return Retencion
     */
    protected function getRetencion15(): Retencion
    {
        return $this->getRetencion(15);
    }
}

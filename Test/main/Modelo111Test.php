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

use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Partida;
use FacturaScripts\Plugins\Modelo111\Lib\Modelo111;

final class Modelo111Test extends Modelo111TestCase
{
    public function testEjercicioInexistente(): void
    {
        $this->assertSame([], Modelo111::generate('NO-EXISTE', 'T1'));
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
    }

    public function testGenerateEntriesSinDatos(): void
    {
        $this->assertFalse(Modelo111::generateEntries([]));
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
}

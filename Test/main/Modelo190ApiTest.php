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
use FacturaScripts\Plugins\Modelo111\Lib\Modelo111;
use FacturaScripts\Plugins\Modelo111\Lib\Modelo190Api;

/**
 * Pruebas de la respuesta del endpoint GET /api/3/modelo190 (Modelo190Api): datos, resumen, casillas,
 * detalle por perceptor, periodo y errores.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class Modelo190ApiTest extends Modelo111TestCase
{
    /**
     * Con detalle=1 añade las partidas de retenciones y de sueldos al detalle por perceptor.
     */
    public function testDetalle(): void
    {
        $exercise = $this->getCurrentExercise();
        $asiento = $this->createNominaAsiento($exercise, 1000.0, 150.0);

        $data = $this->callApi(['codejercicio' => $exercise->codejercicio, 'detalle' => 'si']);
        $this->assertArrayHasKey('perceptores', $data['detalle']);
        $this->assertArrayHasKey('partidas', $data['detalle']);
        $this->assertArrayHasKey('partidas_base', $data['detalle']);

        $found = false;
        foreach ($data['detalle']['partidas'] as $line) {
            if ($line['idasiento'] === (int)$asiento->idasiento) {
                $found = true;
                $this->assertSame(150.0, $line['haber']);
            }
        }
        $this->assertTrue($found);
    }

    /**
     * Errores de los parámetros: periodo distinto del anual, ejercicio, empresa y año.
     */
    public function testErrores(): void
    {
        $exercise = $this->getCurrentExercise();

        $code = 0;
        foreach (['T1', '4T', '03', 'XX'] as $period) {
            $data = $this->callApi(['codejercicio' => $exercise->codejercicio, 'periodo' => $period], $code);
            $this->assertSame(400, $code, $period);
            $this->assertSame('error', $data['status']);
            $this->assertSame('invalid-period', $data['message']);
            $this->assertSame(['ANUAL'], $data['allowed']);
        }

        $data = $this->callApi(['codejercicio' => 'NO-EXISTE'], $code);
        $this->assertSame(404, $code);
        $this->assertSame('exercise-not-found', $data['message']);

        $data = $this->callApi(['idempresa' => '999999'], $code);
        $this->assertSame(404, $code);
        $this->assertSame('company-not-found', $data['message']);

        $data = $this->callApi(['anio' => '20X6'], $code);
        $this->assertSame(400, $code);
        $this->assertSame('invalid-year', $data['message']);
    }

    /**
     * La respuesta trae los datos del contrato, el año completo, siempre el detalle por perceptor (sin las
     * partidas) y las mismas cifras que el cálculo anual de la Lib. Es informativa: resultado vale null.
     */
    public function testRespuesta(): void
    {
        $exercise = $this->getCurrentExercise();
        $this->createNominaAsiento($exercise, 1000.0, 150.0);

        // sin periodo se usa el anual, y también se admiten sus alias
        foreach (['', 'ANUAL', '0A', 'annual'] as $period) {
            $code = 0;
            $data = $this->callApi(['codejercicio' => $exercise->codejercicio, 'periodo' => $period], $code);
            $this->assertSame(200, $code, $period);
            $this->assertSame('ANUAL', $data['periodo'], $period);
        }

        $this->assertSame([
            'modelo', 'plugin_version', 'empresa', 'ejercicio', 'periodo', 'fechainicio', 'fechafin', 'parametros',
            'resumen', 'casillas', 'resultado', 'detalle', 'avisos',
        ], array_keys($data));
        $this->assertSame('190', $data['modelo']);
        $ini = parse_ini_file(Tools::folder('Plugins', 'Modelo111', 'facturascripts.ini'));
        $this->assertSame((float)$ini['version'], $data['plugin_version']);
        $this->assertNull($data['resultado']);

        $year = date('Y', strtotime($exercise->fechainicio));
        $this->assertSame($year . '-01-01', $data['fechainicio']);
        $this->assertSame($year . '-12-31', $data['fechafin']);

        $this->assertArrayHasKey('perceptores', $data['detalle']);
        $this->assertArrayNotHasKey('partidas', $data['detalle']);
        $this->assertArrayNotHasKey('partidas_base', $data['detalle']);

        $found = null;
        foreach ($data['detalle']['perceptores'] as $recipient) {
            if ($recipient['codsubcuenta'] === '4650000000') {
                $found = $recipient;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame([
            'tipo', 'codigo', 'codsubcuenta', 'descripcion', 'cifnif', 'nombre', 'codprovincia', 'clave',
            'subclave', 'grupo_111', 'datos_guardados', 'base', 'retencion', 'errores_190',
        ], array_keys($found));
        $this->assertSame('A', $found['clave']);
        $this->assertFalse($found['datos_guardados']);
        $this->assertNotEmpty($found['errores_190']);
        $this->assertSame(1000.0, $found['base']);
        $this->assertSame(150.0, $found['retencion']);

        // resumen: sin NIF ni provincia del trabajador, el fichero no es válido
        $this->assertSame(
            ['registros', 'total_percepciones', 'total_retenciones', 'fichero_valido', 'errores'],
            array_keys($data['resumen'])
        );
        $this->assertSame(count($data['detalle']['perceptores']), $data['resumen']['registros']);
        $this->assertFalse($data['resumen']['fichero_valido']);
        $this->assertNotEmpty($data['resumen']['errores']);

        // casillas 01-03 del resumen: las del registro del declarante
        $this->assertSame([
            '01' => $data['resumen']['registros'],
            '02' => $data['resumen']['total_percepciones'],
            '03' => $data['resumen']['total_retenciones'],
        ], $data['casillas']);

        // mismas cifras que el cálculo anual de la Lib
        $result = Modelo111::generate($exercise->codejercicio, 'ANNUAL');
        $this->assertSame(count($result['recipientDetails']), $data['resumen']['registros']);
        $this->assertEqualsWithDelta($result['retencionesPracticadas'], $data['resumen']['total_retenciones'], 0.01);
    }

    /**
     * Llama a Modelo190Api::build() y devuelve el cuerpo de la respuesta.
     *
     * @param array $params parámetros de la petición
     * @param int|null $code código HTTP de la respuesta
     *
     * @return array
     */
    private function callApi(array $params, ?int &$code = null): array
    {
        $result = Modelo190Api::build($params);
        $code = $result['code'];
        return $result['body'];
    }
}

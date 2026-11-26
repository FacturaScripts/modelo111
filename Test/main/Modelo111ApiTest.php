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
use FacturaScripts\Dinamic\Model\Subcuenta;
use FacturaScripts\Plugins\Modelo111\Lib\Modelo111;
use FacturaScripts\Plugins\Modelo111\Lib\Modelo111Api;

/**
 * Pruebas de la respuesta del endpoint GET /api/3/modelo111 (Modelo111Api): datos, periodos, errores,
 * resolución del ejercicio por empresa y año, detalle y avisos. El modelo 190 se prueba en Modelo190ApiTest.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class Modelo111ApiTest extends Modelo111TestCase
{
    /**
     * Sin subcuentas IRPFA avisa de que las retenciones de alquileres pueden contarse también en el 115;
     * con ellas, no. Sin retenciones en el periodo, también lo avisa.
     */
    public function testAvisos(): void
    {
        $exercise = $this->getCurrentExercise();
        $params = ['codejercicio' => $exercise->codejercicio, 'periodo' => $this->getCurrentPeriod()];

        $data = $this->callApi($params);
        $this->assertTrue($this->hasWarning($data, 'IRPFA'));
        $this->assertTrue($this->hasWarning($data, 'No hay retenciones practicadas'));

        // creamos una subcuenta de retenciones de alquileres y una nómina
        $subAlquiler = new Subcuenta();
        $subAlquiler->codcuenta = '4751';
        $subAlquiler->codcuentaesp = 'IRPFA';
        $subAlquiler->codejercicio = $exercise->codejercicio;
        $subAlquiler->codsubcuenta = '4751000019';
        $subAlquiler->descripcion = 'HP acreedora retenciones alquileres';
        $this->assertTrue($subAlquiler->save());
        $this->addCleanup(static function () use ($subAlquiler) {
            $subAlquiler->delete();
        });
        $this->createNominaAsiento($exercise, 1000.0, 150.0);

        $data = $this->callApi($params);
        $this->assertFalse($this->hasWarning($data, 'IRPFA'));
        $this->assertFalse($this->hasWarning($data, 'No hay retenciones practicadas'));
    }

    /**
     * Con detalle=1 incluye los perceptores, las partidas de retenciones y las de sueldos como filas planas.
     */
    public function testDetalle(): void
    {
        $exercise = $this->getCurrentExercise();
        $asiento = $this->createNominaAsiento($exercise, 1000.0, 150.0);

        $data = $this->callApi([
            'codejercicio' => $exercise->codejercicio,
            'periodo' => $this->getCurrentPeriod(),
            'detalle' => '1',
        ]);
        $this->assertArrayHasKey('perceptores', $data['detalle']);
        $this->assertArrayHasKey('partidas', $data['detalle']);
        $this->assertArrayHasKey('partidas_base', $data['detalle']);

        $fields = ['idasiento', 'numero', 'fecha', 'codsubcuenta', 'codcontrapartida', 'concepto', 'debe', 'haber'];
        $retention = $this->findLine($data['detalle']['partidas'], (int)$asiento->idasiento);
        $this->assertNotNull($retention);
        $this->assertSame($fields, array_keys($retention));
        $this->assertSame('4751000000', $retention['codsubcuenta']);
        $this->assertSame('4650000000', $retention['codcontrapartida']);
        $this->assertSame(150.0, $retention['haber']);
        $this->assertSame((int)$asiento->numero, $retention['numero']);
        $this->assertSame(date('Y-m-d', strtotime($asiento->fecha)), $retention['fecha']);

        $base = $this->findLine($data['detalle']['partidas_base'], (int)$asiento->idasiento);
        $this->assertNotNull($base);
        $this->assertSame($fields, array_keys($base));
        $this->assertSame('6400000000', $base['codsubcuenta']);
        $this->assertSame(1000.0, $base['debe']);
        $this->assertSame($retention['numero'], $base['numero']);
    }

    /**
     * Errores de los parámetros: periodo ausente o no admitido, ejercicio, empresa, año e importe.
     */
    public function testErrores(): void
    {
        $exercise = $this->getCurrentExercise();

        $code = 0;
        $data = $this->callApi(['codejercicio' => $exercise->codejercicio], $code);
        $this->assertSame(400, $code);
        $this->assertSame('missing-period', $data['message']);

        foreach (['T5', '03', 'XX', 'S1', 'ANUAL'] as $period) {
            $data = $this->callApi(['codejercicio' => $exercise->codejercicio, 'periodo' => $period], $code);
            $this->assertSame(400, $code, $period);
            $this->assertSame('error', $data['status']);
            $this->assertSame('invalid-period', $data['message']);
            $this->assertSame(Modelo111Api::PERIODS, $data['allowed']);
        }

        $data = $this->callApi(['codejercicio' => 'NO-EXISTE', 'periodo' => 'T1'], $code);
        $this->assertSame(404, $code);
        $this->assertSame('exercise-not-found', $data['message']);

        $data = $this->callApi(['idempresa' => '999999', 'periodo' => 'T1'], $code);
        $this->assertSame(404, $code);
        $this->assertSame('company-not-found', $data['message']);

        $data = $this->callApi(['anio' => '1900', 'periodo' => 'T1'], $code);
        $this->assertSame(404, $code);
        $this->assertSame('exercise-not-found', $data['message']);

        $data = $this->callApi(['anio' => '20X6', 'periodo' => 'T1'], $code);
        $this->assertSame(400, $code);
        $this->assertSame('invalid-year', $data['message']);

        $data = $this->callApi(['codejercicio' => $exercise->codejercicio, 'periodo' => 'T1', 'ingresos_anteriores' => 'abc'], $code);
        $this->assertSame(400, $code);
        $this->assertSame('invalid-parameter', $data['message']);

        // la casilla 29 es lo ya ingresado: un importe negativo no se admite
        $data = $this->callApi(['codejercicio' => $exercise->codejercicio, 'periodo' => 'T1', 'ingresos_anteriores' => '-10'], $code);
        $this->assertSame(400, $code);
        $this->assertSame('invalid-parameter', $data['message']);
    }

    /**
     * Normaliza los periodos: T1-T4, 1T-4T, meses y las variantes del anual.
     */
    public function testNormalizarPeriodo(): void
    {
        $this->assertSame('T2', Modelo111Api::normalizePeriod(' t 2 '));
        $this->assertSame('T3', Modelo111Api::normalizePeriod('3t'));
        $this->assertSame('01', Modelo111Api::normalizePeriod('1'));
        $this->assertSame('11', Modelo111Api::normalizePeriod('11'));
        foreach (['ANUAL', 'Annual', 'y', 'A', '0a'] as $value) {
            $this->assertSame('ANUAL', Modelo111Api::normalizePeriod($value), $value);
        }
        $this->assertSame('13', Modelo111Api::normalizePeriod('13'));
    }

    /**
     * Sin codejercicio busca el ejercicio de la empresa (la predeterminada si no se indica) cuyo inicio cae
     * en el año indicado (el actual si no se indica).
     */
    public function testResolucionEmpresaAnio(): void
    {
        $exercise = $this->getCurrentExercise();
        $year = date('Y', strtotime($exercise->fechainicio));

        $code = 0;
        $data = $this->callApi(['idempresa' => (string)$exercise->idempresa, 'anio' => $year, 'periodo' => '2T'], $code);
        $this->assertSame(200, $code);
        $this->assertSame($exercise->codejercicio, $data['ejercicio']['codejercicio']);
        $this->assertSame((int)$exercise->idempresa, $data['empresa']['idempresa']);
        $this->assertSame('T2', $data['periodo']);
        $this->assertSame($year . '-04-01', $data['fechainicio']);
        $this->assertSame($year . '-06-30', $data['fechafin']);

        // sin empresa ni año: la predeterminada y el año actual
        $data = $this->callApi(['periodo' => 'T1'], $code);
        $this->assertSame(200, $code);
        $this->assertSame((int)Tools::settings('default', 'idempresa'), $data['empresa']['idempresa']);
        $this->assertSame(date('Y'), substr($data['ejercicio']['fechainicio'], 0, 4));
    }

    /**
     * La respuesta trae los datos del contrato y las mismas cifras que la Lib que usa la pantalla.
     */
    public function testRespuesta(): void
    {
        $exercise = $this->getCurrentExercise();
        $this->createNominaAsiento($exercise, 2000.0, 300.0);
        $period = $this->getCurrentPeriod();

        $code = 0;
        $data = $this->callApi([
            'codejercicio' => $exercise->codejercicio,
            'periodo' => $period,
            'ingresos_anteriores' => '100',
        ], $code);
        $this->assertSame(200, $code);

        $this->assertSame('111', $data['modelo']);
        $this->assertArrayNotHasKey('modelo_anual', $data);
        $ini = parse_ini_file(Tools::folder('Plugins', 'Modelo111', 'facturascripts.ini'));
        $this->assertSame((float)$ini['version'], $data['plugin_version']);
        $this->assertSame($exercise->codejercicio, $data['ejercicio']['codejercicio']);
        $this->assertSame($period, $data['periodo']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $data['fechainicio']);
        $this->assertSame(['ingresos_anteriores' => 100.0], $data['parametros']);
        $this->assertArrayNotHasKey('detalle', $data);
        $this->assertIsArray($data['avisos']);

        // mismas cifras que la Lib
        $result = Modelo111::generate($exercise->codejercicio, $period, 100.0);
        $this->assertSame((int)$result['numRecipients'], $data['resumen']['perceptores']);
        $this->assertSame(round($result['baseRetenciones'], 2), $data['resumen']['base_retenciones']);
        $this->assertSame(round($result['retencionesPracticadas'], 2), $data['resumen']['retenciones_practicadas']);
        $this->assertSame(100.0, $data['resumen']['ingresos_anteriores']);
        $this->assertSame(round($result['totalIngresar'], 2), $data['resumen']['total_ingresar']);
        $this->assertSame(round($result['totalDebe'], 2), $data['resumen']['total_debe']);
        $this->assertSame(round($result['totalHaber'], 2), $data['resumen']['total_haber']);
        $this->assertSame($data['resumen']['total_ingresar'], $data['resultado']);
        $this->assertSame(round($result['retencionesPracticadas'] - 100.0, 2), $data['resultado']);

        // casillas oficiales: trabajo (01-03), actividades económicas (07-09), premios (13-15), forestales
        // (19-21), derechos de imagen (25-27), 28 (suma), 29 (ingresado en autoliquidaciones anteriores) y
        // 30 (28 - 29). La nómina es de la clave A: va a las casillas del trabajo
        $this->assertSame(
            ['01', '02', '03', '07', '08', '09', '13', '14', '15', '19', '20', '21', '25', '26', '27', '28', '29', '30'],
            array_map('strval', array_keys($data['casillas']))
        );
        $this->assertSame($result['groups111']['trabajo']['perceptores'], $data['casillas']['01']);
        $this->assertSame(round($result['groups111']['trabajo']['base'], 2), $data['casillas']['02']);
        $this->assertSame(round($result['groups111']['trabajo']['retenciones'], 2), $data['casillas']['03']);
        $this->assertGreaterThanOrEqual(1, $data['casillas']['01']);
        $this->assertSame($data['resumen']['retenciones_practicadas'], $data['casillas']['28']);
        $this->assertArrayNotHasKey('modelo190', $data);
        $this->assertSame(100.0, $data['casillas']['29']);
        $this->assertEqualsWithDelta($data['casillas']['28'] - $data['casillas']['29'], $data['casillas']['30'], 0.001);
        $this->assertSame($data['resultado'], $data['casillas']['30']);
    }

    /**
     * Llama a Modelo111Api::build() y devuelve el cuerpo de la respuesta.
     *
     * @param array $params parámetros de la petición
     * @param int|null $code código HTTP de la respuesta
     *
     * @return array
     */
    private function callApi(array $params, ?int &$code = null): array
    {
        $result = Modelo111Api::build($params);
        $code = $result['code'];
        return $result['body'];
    }

    /**
     * Busca en una lista de partidas de la respuesta la del asiento indicado.
     *
     * @param array $lines partidas de la respuesta
     * @param int $idasiento id del asiento
     *
     * @return array|null
     */
    private function findLine(array $lines, int $idasiento): ?array
    {
        foreach ($lines as $line) {
            if ($line['idasiento'] === $idasiento) {
                return $line;
            }
        }
        return null;
    }

    /**
     * Indica si la respuesta tiene algún aviso que contenga el texto indicado.
     *
     * @param array $data respuesta del endpoint
     * @param string $text texto a buscar
     *
     * @return bool
     */
    private function hasWarning(array $data, string $text): bool
    {
        foreach ($data['avisos'] as $warning) {
            if (str_contains($warning, $text)) {
                return true;
            }
        }
        return false;
    }
}

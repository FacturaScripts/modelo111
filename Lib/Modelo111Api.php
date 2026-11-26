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

namespace FacturaScripts\Plugins\Modelo111\Lib;

use FacturaScripts\Dinamic\Lib\Modelo111;
use FacturaScripts\Dinamic\Lib\Modelo190;

/**
 * Construye la respuesta del endpoint de solo lectura GET /api/3/modelo111 a partir del cálculo de la
 * Lib Modelo111, la misma que usa la pantalla del plugin. Solo trimestres: el resumen anual (modelo 190) tiene
 * su propio endpoint, GET /api/3/modelo190 (Modelo190Api).
 *
 * Parámetros comunes: codejercicio, idempresa, anio, periodo (T1-T4, obligatorio) y detalle.
 * Parámetro propio: ingresos_anteriores (float, 0 por defecto): lo ya ingresado en autoliquidaciones
 * anteriores del mismo periodo (casilla 29), que se resta de las retenciones igual que en la pantalla.
 *
 * No crea, modifica ni borra registros.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo111Api
{
    use Modelo111ApiTrait;

    /** Modelo de la AEAT. */
    public const MODEL = '111';

    /** Periodos admitidos por el endpoint, ya normalizados. */
    public const PERIODS = ['T1', 'T2', 'T3', 'T4'];

    /**
     * Calcula el modelo 111 con los parámetros de la petición y devuelve el código HTTP y el cuerpo de la
     * respuesta.
     *
     * @param array $params parámetros de la query string: codejercicio, idempresa, anio, periodo, detalle
     *                      e ingresos_anteriores
     *
     * @return array ['code' => código HTTP, 'body' => datos del modelo o error con status, message y extras]
     */
    public static function build(array $params): array
    {
        // comprobamos el periodo
        $rawPeriod = trim((string)($params['periodo'] ?? ''));
        if ($rawPeriod === '') {
            return static::error(400, 'missing-period', ['allowed' => static::PERIODS]);
        }
        $period = static::normalizePeriod($rawPeriod);
        if (false === in_array($period, static::PERIODS, true)) {
            return static::error(400, 'invalid-period', ['allowed' => static::PERIODS]);
        }

        // comprobamos los parámetros propios
        $ingresosAnteriores = static::parseFloat($params['ingresos_anteriores'] ?? null);
        if (null === $ingresosAnteriores) {
            return static::error(400, 'invalid-parameter', ['parameter' => 'ingresos_anteriores']);
        }

        // buscamos el ejercicio y su empresa
        $resolved = static::resolveExercise($params);
        if (isset($resolved['error'])) {
            return $resolved['error'];
        }
        $exercise = $resolved['exercise'];

        // calculamos el modelo con la misma Lib que la pantalla
        $result = Modelo111::generate($exercise->codejercicio, $period, $ingresosAnteriores);

        $data = [
            'modelo' => static::MODEL,
            'plugin_version' => static::pluginVersion(),
            'empresa' => static::companyData($resolved['company']),
            'ejercicio' => static::exerciseData($exercise),
            'periodo' => $period,
            'fechainicio' => static::isoDate($result['dateStart']),
            'fechafin' => static::isoDate($result['dateEnd']),
            'parametros' => [
                'ingresos_anteriores' => static::money($ingresosAnteriores),
            ],
            'resumen' => [
                'perceptores' => (int)$result['numRecipients'],
                'base_retenciones' => static::money($result['baseRetenciones']),
                'retenciones_practicadas' => static::money($result['retencionesPracticadas']),
                'ingresos_anteriores' => static::money($result['ingresosPeriodoAnterior']),
                'total_ingresar' => static::money($result['totalIngresar']),
                'total_debe' => static::money($result['totalDebe']),
                'total_haber' => static::money($result['totalHaber']),
            ],
            'casillas' => static::getBoxes($result),
            'resultado' => static::money($result['totalIngresar']),
        ];

        if (static::parseBool($params['detalle'] ?? '')) {
            $data['detalle'] = [
                'perceptores' => static::getRecipients($result),
                'partidas' => static::getEntryLines($result),
                'partidas_base' => static::getBaseLines($result),
            ];
        }

        $data['avisos'] = static::getWarnings($exercise, $result);
        return ['code' => 200, 'body' => $data];
    }

    /**
     * Devuelve las casillas del modelo 111 que calcula el plugin, con la numeración oficial de la AEAT:
     * perceptores, percepciones y retenciones dinerarias de los rendimientos del trabajo (01-03), de las
     * actividades económicas (07-09), de los premios (13-15), de los aprovechamientos forestales (19-21) y de
     * la cesión de derechos de imagen (25-27), según la clave del modelo 190 de cada perceptor; 28 (suma de
     * retenciones), 29 (resultados a ingresar de autoliquidaciones anteriores) y 30 (resultado a ingresar,
     * 28 - 29).
     *
     * @param array $result resultados de Modelo111::generate()
     *
     * @return array casillas indexadas por su número
     */
    protected static function getBoxes(array $result): array
    {
        $boxes = [];
        foreach (Modelo190::GROUPS_111 as $group => $codes) {
            $data = $result['groups111'][$group];
            $boxes[$codes[0]] = (int)$data['perceptores'];
            $boxes[$codes[1]] = static::money($data['base']);
            $boxes[$codes[2]] = static::money($data['retenciones']);
        }
        $boxes['28'] = static::money($result['retencionesPracticadas']);
        $boxes['29'] = static::money($result['ingresosPeriodoAnterior']);
        $boxes['30'] = static::money($result['totalIngresar']);
        return $boxes;
    }
}

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
use FacturaScripts\Dinamic\Model\Empresa;

/**
 * Construye la respuesta del endpoint de solo lectura GET /api/3/modelo190 (resumen anual de retenciones e
 * ingresos a cuenta del trabajo, actividades económicas, premios y otras rentas) a partir del cálculo anual de
 * la Lib Modelo111, el mismo que usan la pantalla y el fichero del modelo 190.
 *
 * Devuelve el resumen de la declaración (registros, totales y errores que impiden generar el fichero con los
 * datos de la declaración por defecto), las casillas 01-03 del resumen y siempre el detalle por perceptor, con
 * su NIF, nombre, provincia, clave y subclave. Es una declaración informativa: resultado vale null.
 *
 * Parámetros: codejercicio, idempresa, anio, periodo (opcional; solo ANUAL, el valor por defecto) y detalle
 * (añade las partidas de retenciones y de sueldos).
 *
 * No crea, modifica ni borra registros.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo190Api
{
    use Modelo111ApiTrait;

    /** Modelo de la AEAT. */
    public const MODEL = '190';

    /** Periodos admitidos por el endpoint, ya normalizados. */
    public const PERIODS = ['ANUAL'];

    /**
     * Calcula el modelo 190 con los parámetros de la petición y devuelve el código HTTP y el cuerpo de la
     * respuesta.
     *
     * @param array $params parámetros de la query string: codejercicio, idempresa, anio, periodo y detalle
     *
     * @return array ['code' => código HTTP, 'body' => datos del modelo o error con status, message y extras]
     */
    public static function build(array $params): array
    {
        // periodo: solo anual, y si no se indica se usa el anual
        $rawPeriod = trim((string)($params['periodo'] ?? ''));
        $period = $rawPeriod === '' ? 'ANUAL' : static::normalizePeriod($rawPeriod);
        if (false === in_array($period, static::PERIODS, true)) {
            return static::error(400, 'invalid-period', ['allowed' => static::PERIODS]);
        }

        // buscamos el ejercicio y su empresa
        $resolved = static::resolveExercise($params);
        if (isset($resolved['error'])) {
            return $resolved['error'];
        }
        $exercise = $resolved['exercise'];
        $company = $resolved['company'];

        // calculamos el año completo con la misma Lib que la pantalla y el fichero
        $result = Modelo111::generate($exercise->codejercicio, 'ANNUAL');
        $summary = static::getSummary($result, $company);

        $data = [
            'modelo' => static::MODEL,
            'plugin_version' => static::pluginVersion(),
            'empresa' => static::companyData($company),
            'ejercicio' => static::exerciseData($exercise),
            'periodo' => $period,
            'fechainicio' => static::isoDate($result['dateStart']),
            'fechafin' => static::isoDate($result['dateEnd']),
            'parametros' => (object)[],
            'resumen' => $summary,
            // casillas del resumen de la declaración: 01 número de perceptores, 02 importe de las percepciones
            // y 03 importe de las retenciones (los mismos totales del registro del declarante del fichero)
            'casillas' => [
                '01' => $summary['registros'],
                '02' => $summary['total_percepciones'],
                '03' => $summary['total_retenciones'],
            ],
            'resultado' => null,
            // el detalle por perceptor va siempre: es lo que declara el modelo 190
            'detalle' => [
                'perceptores' => static::getRecipients($result),
            ],
        ];

        if (static::parseBool($params['detalle'] ?? '')) {
            $data['detalle']['partidas'] = static::getEntryLines($result);
            $data['detalle']['partidas_base'] = static::getBaseLines($result);
        }

        $data['avisos'] = static::getWarnings($exercise, $result);
        return ['code' => 200, 'body' => $data];
    }

    /**
     * Devuelve el resumen del modelo 190: número de registros de perceptor, total de percepciones y de
     * retenciones (los del registro del declarante) y los errores que impiden generar el fichero con los
     * datos de la declaración por defecto.
     *
     * @param array $result resultados de Modelo111::generate() del periodo anual
     * @param Empresa $company empresa declarante
     *
     * @return array
     */
    protected static function getSummary(array $result, Empresa $company): array
    {
        $base = 0.0;
        $retenciones = 0.0;
        foreach ($result['recipientDetails'] as $detail) {
            $base += round($detail['base'], 2);
            $retenciones += round($detail['retencion'], 2);
        }

        $year = date('Y', strtotime($result['exercise']->fechainicio));
        $errors = Modelo190::validate($result, $company, ['numero' => '190' . $year . '000001']);
        return [
            'registros' => count($result['recipientDetails']),
            'total_percepciones' => static::money($base),
            'total_retenciones' => static::money($retenciones),
            'fichero_valido' => empty($errors),
            'errores' => $errors,
        ];
    }
}

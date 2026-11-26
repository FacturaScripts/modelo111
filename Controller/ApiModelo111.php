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

namespace FacturaScripts\Plugins\Modelo111\Controller;

use FacturaScripts\Core\Response;
use FacturaScripts\Core\Template\ApiController;
use FacturaScripts\Dinamic\Lib\Modelo111Api;

/**
 * Endpoint de solo lectura de la API con los datos del modelo 111 (retenciones e ingresos a cuenta
 * trimestrales): GET /api/3/modelo111. El resumen anual tiene su propio endpoint, GET /api/3/modelo190.
 *
 * Parámetros de la query string:
 *   - codejercicio: ejercicio. Si se indica, manda sobre idempresa y anio.
 *   - idempresa: empresa (por defecto, la predeterminada) y anio: año natural (por defecto, el actual).
 *   - periodo: T1, T2, T3 o T4 (también 1T-4T). Obligatorio.
 *   - detalle: 1/true para incluir los perceptores y las partidas de retenciones y de sueldos.
 *   - ingresos_anteriores: importe de autoliquidaciones anteriores del mismo periodo (por defecto 0).
 *
 * Errores ({"status": "error", "message": ...}): 405 method-not-allowed, 400 missing-period, invalid-period,
 * invalid-parameter o invalid-year, 404 exercise-not-found o company-not-found.
 *
 * Una clave API sin acceso completo necesita el permiso de lectura del recurso «modelo111».
 *
 * No crea, modifica ni borra registros: no genera asientos ni ficheros.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class ApiModelo111 extends ApiController
{
    /** Parámetros de la query string que acepta el endpoint. */
    private const PARAMS = ['codejercicio', 'idempresa', 'anio', 'periodo', 'detalle', 'ingresos_anteriores'];

    /**
     * Valida el método, calcula el modelo con Modelo111Api y devuelve el resultado en JSON.
     */
    protected function runResource(): void
    {
        if ($this->request->method() !== 'GET') {
            $this->response
                ->setHttpCode(Response::HTTP_METHOD_NOT_ALLOWED)
                ->json(['status' => 'error', 'message' => 'method-not-allowed']);
            return;
        }

        $params = [];
        foreach (self::PARAMS as $key) {
            $params[$key] = $this->request->query($key);
        }

        $result = Modelo111Api::build($params);
        $this->response->setHttpCode($result['code'])->json($result['body']);
    }
}

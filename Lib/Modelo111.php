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

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\CuentaEspecial;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Partida;
use FacturaScripts\Dinamic\Model\Retencion;
use FacturaScripts\Dinamic\Model\Subcuenta;

/**
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo111
{
    /** @var Partida[] */
    protected static $baseLines = [];

    /** @var float */
    protected static $baseRetenciones = 0.0;

    /** @var DataBase */
    protected static $dataBase;

    /** @var string */
    protected static $dateEnd = '';

    /** @var string */
    protected static $dateStart = '';

    /** @var Partida[] */
    protected static $entryLines = [];

    /** @var Ejercicio */
    protected static $exercise;

    /** @var float */
    protected static $ingresosPeriodoAnterior = 0.0;

    /** @var int */
    protected static $numRecipients = 0;

    /** @var string */
    protected static $period = '';

    /** @var array */
    protected static $recipientDetails = [];

    /** @var float */
    protected static $retencionesPracticadas = 0.0;

    /** @var float */
    protected static $totalIngresar = 0.0;

    public static function generate(string $codejercicio, string $period, float $ingresosAnteriores = 0.0): array
    {
        // comprobamos que el ejercicio existe
        static::$exercise = new Ejercicio();
        if (false === static::$exercise->load($codejercicio)) {
            return [];
        }

        // inicializamos las variables
        static::$dataBase = new DataBase();
        static::$ingresosPeriodoAnterior = $ingresosAnteriores;
        static::$period = strtoupper($period);

        // cargamos las fechas del periodo
        static::loadDates();

        // cargamos las líneas de las retenciones
        static::loadEntryLines();

        // cargamos las líneas de las bases de retención
        static::loadBaseLines();

        // calculamos los resultados
        static::loadResults();

        return [
            'exercise' => static::$exercise,
            'period' => static::$period,
            'entryLines' => static::$entryLines,
            'baseLines' => static::$baseLines,
            'baseRetenciones' => static::$baseRetenciones,
            'retencionesPracticadas' => static::$retencionesPracticadas,
            'ingresosPeriodoAnterior' => static::$ingresosPeriodoAnterior,
            'numRecipients' => static::$numRecipients,
            'recipientDetails' => static::$recipientDetails,
            'totalIngresar' => static::$totalIngresar,
        ];
    }

    public static function generateEntries(array $result): bool
    {
        if (empty($result) || $result['totalIngresar'] <= 0) {
            Tools::log()->warning('no-data');
            return false;
        }

        $exercise = $result['exercise'];
        $total = $result['totalIngresar'];
        $concepto = 'Modelo 111 ' . $result['period'] . ' ' . date('Y', strtotime($exercise->fechainicio));

        $sub4751 = static::findSubcuentaIRPFPR($exercise->codejercicio);
        if (null === $sub4751) {
            Tools::log()->error('subaccount-not-found', ['%code%' => '4751']);
            return false;
        }

        $cod465 = static::findCod465($result['entryLines'], $exercise->codejercicio);
        if (null === $cod465) {
            Tools::log()->error('subaccount-not-found', ['%code%' => '465']);
            return false;
        }

        $sub572 = static::findSubcuenta572($exercise->codejercicio);
        if (null === $sub572) {
            Tools::log()->error('subaccount-not-found', ['%code%' => '572']);
            return false;
        }

        $fecha = Tools::date();
        $cod4751 = $sub4751->codsubcuenta;
        $cod572 = $sub572->codsubcuenta;

        return static::createAsiento($concepto . ' - Obligación', $fecha, $total, $exercise, $cod465, $cod4751)
            && static::createAsiento($concepto . ' - Pago', $fecha, $total, $exercise, $cod4751, $cod572);
    }

    protected static function createAsiento(string $concepto, string $fecha, float $total, Ejercicio $exercise, string $codDebe, string $codHaber): bool
    {
        $asiento = new Asiento();
        $asiento->codejercicio = $exercise->codejercicio;
        $asiento->concepto = $concepto;
        $asiento->fecha = $fecha;
        $asiento->idempresa = $exercise->idempresa;
        $asiento->importe = $total;
        if (!$asiento->save()) {
            return false;
        }

        $p1 = new Partida();
        $p1->idasiento = $asiento->idasiento;
        $p1->codsubcuenta = $codDebe;
        $p1->debe = $total;
        $p1->codcontrapartida = $codHaber;
        $p1->concepto = $concepto;
        if (!$p1->save()) {
            $asiento->delete();
            return false;
        }

        $p2 = new Partida();
        $p2->idasiento = $asiento->idasiento;
        $p2->codsubcuenta = $codHaber;
        $p2->haber = $total;
        $p2->codcontrapartida = $codDebe;
        $p2->concepto = $concepto;
        if (!$p2->save()) {
            $asiento->delete();
            return false;
        }

        return true;
    }

    protected static function findCod465(array $entryLines, string $codejercicio): ?string
    {
        foreach ($entryLines as $line) {
            if (!empty($line->codcontrapartida) && str_starts_with($line->codcontrapartida, '465')) {
                return $line->codcontrapartida;
            }
        }

        $where = [
            Where::eq('codejercicio', $codejercicio),
            Where::like('codsubcuenta', '465'),
        ];
        foreach (Subcuenta::all($where, [], 1) as $sub) {
            return $sub->codsubcuenta;
        }

        return null;
    }

    protected static function findSubcuenta572(string $codejercicio): ?Subcuenta
    {
        $where = [
            Where::eq('codejercicio', $codejercicio),
            Where::like('codsubcuenta', '572'),
        ];
        foreach (Subcuenta::all($where, [], 1) as $sub) {
            return $sub;
        }
        return null;
    }

    protected static function findSubcuentaIRPFPR(string $codejercicio): ?Subcuenta
    {
        $special = new CuentaEspecial();
        if (!$special->loadWhere([Where::eq('codcuentaesp', 'IRPFPR')])) {
            return null;
        }
        foreach ($special->getCuenta($codejercicio)->getSubcuentas() as $subcuenta) {
            return $subcuenta;
        }
        return null;
    }

    protected static function initRecipient(string $codContrapartida): void
    {
        if (isset(static::$recipientDetails[$codContrapartida])) {
            return;
        }

        // buscar la subcuenta de la contrapartida para obtener su descripción
        $subcuenta = new Subcuenta();
        $whereSubcuenta = [
            Where::eq('codejercicio', static::$exercise->codejercicio),
            Where::eq('codsubcuenta', $codContrapartida)
        ];
        $descripcion = '';
        if ($subcuenta->loadWhere($whereSubcuenta)) {
            $descripcion = $subcuenta->descripcion;
        }

        static::$recipientDetails[$codContrapartida] = [
            'codcontrapartida' => $codContrapartida,
            'descripcion' => $descripcion,
            'base' => 0.0,
            'retencion' => 0.0
        ];
    }

    protected static function loadBaseLines(): void
    {
        if (empty(static::$exercise->id()) || empty(static::$entryLines)) {
            return;
        }

        // obtenemos los asientos únicos de las retenciones
        $asientos = [];
        foreach (static::$entryLines as $line) {
            $asientos[$line->idasiento] = $line->idasiento;
        }

        if (empty($asientos)) {
            return;
        }

        // obtenemos las subcuentas de sueldos y salarios (640)
        $where = [
            Where::eq('codejercicio', static::$exercise->codejercicio),
            Where::like('codsubcuenta', '640')
        ];
        $ids = [];
        foreach (Subcuenta::all($where) as $sub) {
            $ids[$sub->id()] = $sub->id();
        }

        if (empty($ids)) {
            return;
        }

        // buscamos las partidas de gastos de personal que estén en los mismos asientos que las retenciones
        $sql = 'SELECT * FROM ' . Partida::tableName() . ' as p'
            . ' WHERE p.idasiento IN (' . implode(',', $asientos) . ')'
            . ' AND p.idsubcuenta IN (' . implode(',', $ids) . ')';

        foreach (static::$dataBase->select($sql) as $row) {
            static::$baseLines[] = new Partida($row);
        }
    }

    protected static function loadBasesByAsiento(): array
    {
        if (empty(static::$entryLines)) {
            return [];
        }

        // obtener IDs únicos de asientos con retenciones
        $asientosIds = [];
        foreach (static::$entryLines as $line) {
            $asientosIds[$line->idasiento] = $line->idasiento;
        }

        // cargar bases imponibles de todos los asientos con retenciones
        $basesByAsiento = [];
        $sql = 'SELECT idasiento, SUM(baseimponible) as total_base FROM ' . Partida::tableName()
            . ' WHERE idasiento IN (' . implode(',', $asientosIds) . ')'
            . ' GROUP BY idasiento';
        foreach (static::$dataBase->select($sql) as $row) {
            $basesByAsiento[$row['idasiento']] = (float)$row['total_base'];
        }

        return $basesByAsiento;
    }

    protected static function loadDates(): void
    {
        // si el periodo no es T1, T2, T3, T4 o Annual, se asume que es el primer trimestre
        if (!in_array(static::$period, ['T1', 'T2', 'T3', 'T4', 'ANNUAL'])) {
            static::$period = 'T1';
        }

        switch (static::$period) {
            case 'T1':
                static::$dateStart = date('01-01-Y', strtotime(static::$exercise->fechainicio));
                static::$dateEnd = date('31-03-Y', strtotime(static::$exercise->fechainicio));
                break;

            case 'T2':
                static::$dateStart = date('01-04-Y', strtotime(static::$exercise->fechainicio));
                static::$dateEnd = date('30-06-Y', strtotime(static::$exercise->fechainicio));
                break;

            case 'T3':
                static::$dateStart = date('01-07-Y', strtotime(static::$exercise->fechainicio));
                static::$dateEnd = date('30-09-Y', strtotime(static::$exercise->fechainicio));
                break;

            case 'ANNUAL':
                static::$dateStart = date('01-01-Y', strtotime(static::$exercise->fechainicio));
                static::$dateEnd = date('31-12-Y', strtotime(static::$exercise->fechainicio));
                break;

            default:
                static::$dateStart = date('01-10-Y', strtotime(static::$exercise->fechainicio));
                static::$dateEnd = date('31-12-Y', strtotime(static::$exercise->fechainicio));
                break;
        }
    }

    protected static function loadEntryLines(): void
    {
        // obtenemos el listado de subcuentas de IRPF del ejercicio
        $ids = [];

        $special = new CuentaEspecial();
        $where = [Where::eq('codcuentaesp', 'IRPFPR')];
        if ($special->loadWhere($where)) {
            foreach ($special->getCuenta(static::$exercise->codejercicio)->getSubcuentas() as $subcuenta) {
                $ids[$subcuenta->id()] = $subcuenta->id();
            }
        }

        // añadimos las de las retenciones
        foreach (Retencion::all() as $retention) {
            $subcuenta = new Subcuenta();

            // subcuenta para compras
            $whereAcr = [
                Where::eq('codejercicio', static::$exercise->codejercicio),
                Where::eq('codsubcuenta', $retention->codsubcuentaacr)
            ];
            if ($retention->codsubcuentaacr && $subcuenta->loadWhere($whereAcr)) {
                $ids[$subcuenta->id()] = $subcuenta->id();
            }

            // subcuenta para ventas
            $whereRet = [
                Where::eq('codejercicio', static::$exercise->codejercicio),
                Where::eq('codsubcuenta', $retention->codsubcuentaret)
            ];
            if ($retention->codsubcuentaret && $subcuenta->loadWhere($whereRet)) {
                $ids[$subcuenta->id()] = $subcuenta->id();
            }
        }
        if (empty($ids)) {
            return;
        }

        $sql = 'SELECT * FROM ' . Partida::tableName() . ' as p'
            . ' LEFT JOIN ' . Asiento::tableName() . ' as a ON p.idasiento = a.idasiento'
            . ' WHERE a.codejercicio = ' . static::$dataBase->var2str(static::$exercise->codejercicio)
            . ' AND a.fecha BETWEEN ' . static::$dataBase->var2str(static::$dateStart)
            . ' AND ' . static::$dataBase->var2str(static::$dateEnd)
            . ' AND p.idsubcuenta IN (' . implode(',', $ids) . ')'
            . ' ORDER BY a.fecha ASC, a.numero ASC';
        foreach (static::$dataBase->select($sql) as $row) {
            static::$entryLines[] = new Partida($row);
        }
    }

    protected static function loadResults(): void
    {
        $recipients = [];
        static::$baseRetenciones = 0.0;
        static::$retencionesPracticadas = 0.0;
        static::$recipientDetails = [];

        // obtener bases imponibles de los asientos con retenciones
        $basesByAsiento = static::loadBasesByAsiento();

        // calculamos las retenciones practicadas (casilla 03) y los perceptores
        foreach (static::$entryLines as $line) {
            $codcontrapartida = $line->codcontrapartida;
            if (empty($codcontrapartida)) {
                continue;
            }

            $recipients[$codcontrapartida] = $codcontrapartida;
            static::$retencionesPracticadas += $line->haber;

            // inicializar perceptor si no existe
            static::initRecipient($codcontrapartida);

            // sumar retención
            static::$recipientDetails[$codcontrapartida]['retencion'] += $line->haber;

            // sumar base imponible del asiento completo (puede estar en cualquier partida del asiento)
            if (isset($basesByAsiento[$line->idasiento]) && $basesByAsiento[$line->idasiento] > 0) {
                $baseImponible = $basesByAsiento[$line->idasiento];
                static::$baseRetenciones += $baseImponible;
                static::$recipientDetails[$codcontrapartida]['base'] += $baseImponible;
                // marcar como procesado para no duplicar si hay múltiples retenciones en el mismo asiento
                $basesByAsiento[$line->idasiento] = 0;
            }
        }

        // calculamos la base de retenciones de sueldos y salarios (casilla 02) y la agrupamos por perceptor
        foreach (static::$baseLines as $line) {
            $codcontrapartida = $line->codcontrapartida;
            if (empty($codcontrapartida)) {
                continue;
            }

            static::$baseRetenciones += $line->debe;

            // inicializar perceptor si no existe
            static::initRecipient($codcontrapartida);

            // sumar base de sueldos
            static::$recipientDetails[$codcontrapartida]['base'] += $line->debe;
        }

        static::$numRecipients = count($recipients);

        // calculamos el total a ingresar (casilla 05)
        static::$totalIngresar = static::$retencionesPracticadas + static::$ingresosPeriodoAnterior;

        // ordenar por código de contrapartida
        ksort(static::$recipientDetails);
    }
}

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
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\Partida;
use FacturaScripts\Dinamic\Model\Retencion;
use FacturaScripts\Dinamic\Model\Subcuenta;

/**
 * Calcula los datos del modelo 111 (retenciones e ingresos a cuenta del trabajo y de actividades
 * económicas) a partir de las partidas de las subcuentas de retenciones practicadas (4751) y genera
 * los asientos de obligación y pago del modelo.
 *
 * Las retenciones se calculan por su importe neto: el haber suma y el debe de los asientos que revierten
 * retenciones (facturas de compra rectificativas o asientos con gastos, como la regularización de una
 * nómina) resta. Los pagos y liquidaciones de la retención y los asientos que genera el propio modelo
 * no se tienen en cuenta.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo111
{
    /** Sufijo del concepto del asiento de obligación que genera el modelo. */
    public const ENTRY_CONCEPT_OBLIGATION = ' - Obligación';

    /** Sufijo del concepto del asiento de pago que genera el modelo. */
    public const ENTRY_CONCEPT_PAYMENT = ' - Pago';

    /** Prefijo del concepto de los asientos de obligación y pago que genera el modelo. */
    public const ENTRY_CONCEPT_PREFIX = 'Modelo 111 ';

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
    protected static $totalDebe = 0.0;

    /** @var float */
    protected static $totalHaber = 0.0;

    /** @var float */
    protected static $totalIngresar = 0.0;

    /**
     * Calcula los datos del modelo 111 del ejercicio y periodo indicados.
     *
     * @param string $codejercicio código del ejercicio
     * @param string $period periodo: T1, T2, T3, T4 o ANNUAL (cualquier otro valor se trata como T1)
     * @param float $ingresosAnteriores importe ingresado en declaraciones anteriores del mismo periodo
     *
     * @return array resultados del modelo o un array vacío si el ejercicio no existe
     */
    public static function generate(string $codejercicio, string $period, float $ingresosAnteriores = 0.0): array
    {
        // comprobamos que el ejercicio existe
        static::$exercise = new Ejercicio();
        if (false === static::$exercise->load($codejercicio)) {
            return [];
        }

        // inicializamos las variables
        static::$dataBase = new DataBase();
        static::$baseLines = [];
        static::$entryLines = [];
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
            'totalDebe' => static::$totalDebe,
            'totalHaber' => static::$totalHaber,
            'totalIngresar' => static::$totalIngresar,
        ];
    }

    /**
     * Genera los asientos de obligación y pago del modelo a partir de los resultados de generate().
     * Estos asientos no se tienen en cuenta al volver a calcular el modelo.
     *
     * @param array $result resultados devueltos por generate()
     *
     * @return bool true si se han creado los dos asientos
     */
    public static function generateEntries(array $result): bool
    {
        if (empty($result) || $result['totalIngresar'] <= 0) {
            Tools::log()->warning('no-data');
            return false;
        }

        $exercise = $result['exercise'];
        $total = $result['totalIngresar'];
        $concepto = static::ENTRY_CONCEPT_PREFIX . $result['period'] . ' ' . date('Y', strtotime($exercise->fechainicio));

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

        $accObl = static::createAsiento($concepto . static::ENTRY_CONCEPT_OBLIGATION, $fecha, $total, $exercise, $cod465, $cod4751);
        $accPag = static::createAsiento($concepto . static::ENTRY_CONCEPT_PAYMENT, $fecha, $total, $exercise, $cod4751, $cod572);

        return $accObl && $accPag;
    }

    /**
     * Crea un asiento de dos partidas por el total indicado, salvo que ya exista otro con el mismo concepto
     * en el ejercicio.
     *
     * @param string $concepto concepto del asiento y de sus partidas
     * @param string $fecha fecha del asiento
     * @param float $total importe del asiento
     * @param Ejercicio $exercise ejercicio del asiento
     * @param string $codDebe subcuenta de la partida al debe
     * @param string $codHaber subcuenta de la partida al haber
     *
     * @return bool true si se ha creado el asiento
     */
    protected static function createAsiento(string $concepto, string $fecha, float $total, Ejercicio $exercise, string $codDebe, string $codHaber): bool
    {
        $asiento = new Asiento();

        // si ya existe un asiento igual, no lo creamos
        if ($asiento->loadWhere(
            [
                Where::eq('codejercicio', $exercise->codejercicio),
                Where::eq('concepto', $concepto),
            ]
        )) {
            Tools::log()->warning('exists-accounting-111', ['%codejercicio%' => $exercise->codejercicio, '%concepto%' => $concepto]);
            return false;
        }

        $asiento->clear();
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

        Tools::log()->notice('accounting-created-111', ['%codejercicio%' => $exercise->codejercicio, '%concepto%' => $concepto]);
        return true;
    }

    /**
     * Devuelve la subcuenta de remuneraciones pendientes de pago (465) usada como contrapartida en las
     * retenciones o, en su defecto, la primera 465 del ejercicio.
     *
     * @param Partida[] $entryLines partidas de las retenciones
     * @param string $codejercicio código del ejercicio
     *
     * @return string|null código de la subcuenta o null si no hay ninguna
     */
    protected static function findCod465(array $entryLines, string $codejercicio): ?string
    {
        foreach ($entryLines as $line) {
            if (!empty($line->codcontrapartida) && str_starts_with($line->codcontrapartida, '465')) {
                return $line->codcontrapartida;
            }
        }

        $where = [
            Where::eq('codejercicio', $codejercicio),
            Where::like('codsubcuenta', '465%'),
        ];
        foreach (Subcuenta::all($where, [], 1) as $sub) {
            return $sub->codsubcuenta;
        }

        return null;
    }

    /**
     * Devuelve la primera subcuenta de bancos (572) del ejercicio.
     *
     * @param string $codejercicio código del ejercicio
     *
     * @return Subcuenta|null
     */
    protected static function findSubcuenta572(string $codejercicio): ?Subcuenta
    {
        $where = [
            Where::eq('codejercicio', $codejercicio),
            Where::like('codsubcuenta', '572%'),
        ];
        foreach (Subcuenta::all($where, [], 1) as $sub) {
            return $sub;
        }
        return null;
    }

    /**
     * Devuelve la primera subcuenta de la cuenta especial de retenciones practicadas (IRPFPR) del ejercicio.
     *
     * @param string $codejercicio código del ejercicio
     *
     * @return Subcuenta|null
     */
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

    /**
     * Inicializa el detalle de un perceptor (base y retención a cero) si todavía no existe.
     *
     * @param string $codContrapartida subcuenta del perceptor
     */
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

    /**
     * Carga las partidas de sueldos y salarios (640) de los asientos que tienen retenciones.
     */
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
            Where::like('codsubcuenta', '640%')
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

    /**
     * Devuelve la suma de las bases imponibles de cada asiento con retenciones. En las facturas
     * rectificativas la base es negativa.
     *
     * @return array bases indexadas por el id del asiento
     */
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

    /**
     * Calcula las fechas de inicio y fin del periodo del modelo.
     */
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

    /**
     * Carga las partidas del periodo de las subcuentas de retenciones practicadas: las de la cuenta especial
     * IRPFPR y las subcuentas de compras de las retenciones, sin las de alquileres (IRPFA, modelo 115) ni
     * los asientos de obligación y pago que genera el propio modelo.
     */
    protected static function loadEntryLines(): void
    {
        // obtenemos el listado de subcuentas de IRPF del ejercicio
        $ids = [];

        $special = new CuentaEspecial();
        $where = [Where::eq('codcuentaesp', 'IRPFPR')];
        if ($special->loadWhere($where)) {
            foreach ($special->getCuenta(static::$exercise->codejercicio)->getSubcuentas() as $subcuenta) {
                // excluimos las retenciones de alquileres (IRPFA), que van al modelo 115
                if ($subcuenta->getSpecialAccountCode() === 'IRPFA') {
                    continue;
                }
                $ids[$subcuenta->id()] = $subcuenta->id();
            }
        }

        // añadimos las subcuentas de compras de las retenciones. Las de ventas (codsubcuentaret, 473) son
        // retenciones que nos practican nuestros clientes, no retenciones practicadas, y no van al modelo 111
        foreach (Retencion::all() as $retention) {
            if (empty($retention->codsubcuentaacr)) {
                continue;
            }

            $subcuenta = new Subcuenta();
            $whereAcr = [
                Where::eq('codejercicio', static::$exercise->codejercicio),
                Where::eq('codsubcuenta', $retention->codsubcuentaacr)
            ];
            if ($subcuenta->loadWhere($whereAcr) && $subcuenta->getSpecialAccountCode() !== 'IRPFA') {
                $ids[$subcuenta->id()] = $subcuenta->id();
            }
        }
        if (empty($ids)) {
            return;
        }

        // excluimos los asientos de obligación y pago que genera el propio modelo
        $sql = 'SELECT * FROM ' . Partida::tableName() . ' as p'
            . ' LEFT JOIN ' . Asiento::tableName() . ' as a ON p.idasiento = a.idasiento'
            . ' WHERE a.codejercicio = ' . static::$dataBase->var2str(static::$exercise->codejercicio)
            . ' AND a.fecha BETWEEN ' . static::$dataBase->var2str(static::$dateStart)
            . ' AND ' . static::$dataBase->var2str(static::$dateEnd)
            . ' AND p.idsubcuenta IN (' . implode(',', $ids) . ')'
            . ' AND a.concepto NOT LIKE ' . static::$dataBase->var2str(static::ENTRY_CONCEPT_PREFIX . '%' . static::ENTRY_CONCEPT_OBLIGATION)
            . ' AND a.concepto NOT LIKE ' . static::$dataBase->var2str(static::ENTRY_CONCEPT_PREFIX . '%' . static::ENTRY_CONCEPT_PAYMENT)
            . ' ORDER BY a.fecha ASC, a.numero ASC';
        foreach (static::$dataBase->select($sql) as $row) {
            static::$entryLines[] = new Partida($row);
        }
    }

    /**
     * Calcula las casillas del modelo (perceptores, base, retenciones y total a ingresar), el detalle
     * por perceptor y los totales del debe y del haber de las partidas de retenciones.
     */
    protected static function loadResults(): void
    {
        $recipients = [];
        static::$baseRetenciones = 0.0;
        static::$retencionesPracticadas = 0.0;
        static::$recipientDetails = [];

        // obtener bases imponibles de los asientos con retenciones
        $basesByAsiento = static::loadBasesByAsiento();

        // asientos cuyo debe revierte retenciones (rectificativas de compra, regularizaciones de nóminas...)
        $reversalAsientos = static::loadReversalAsientos();

        // calculamos las retenciones practicadas (casilla 03) y los perceptores
        foreach (static::$entryLines as $line) {
            $codcontrapartida = $line->codcontrapartida;
            if (empty($codcontrapartida)) {
                continue;
            }

            // retención neta: el haber suma y el debe de los asientos que revierten retenciones resta
            $isReversal = isset($reversalAsientos[$line->idasiento]);
            if (false === $isReversal && $line->haber == 0.0) {
                // debe de un pago o liquidación de las retenciones: no es una retención ni un perceptor
                continue;
            }

            $recipients[$codcontrapartida] = $codcontrapartida;

            $retencionNeta = $isReversal ? $line->haber - $line->debe : $line->haber;
            static::$retencionesPracticadas += $retencionNeta;

            // inicializar perceptor si no existe
            static::initRecipient($codcontrapartida);

            // sumar retención
            static::$recipientDetails[$codcontrapartida]['retencion'] += $retencionNeta;

            // sumar base imponible del asiento completo (puede estar en cualquier partida del asiento).
            // En las facturas rectificativas la base es negativa, por lo que resta
            if (isset($basesByAsiento[$line->idasiento]) && abs($basesByAsiento[$line->idasiento]) > 0.0) {
                $baseImponible = $basesByAsiento[$line->idasiento];
                static::$baseRetenciones += $baseImponible;
                static::$recipientDetails[$codcontrapartida]['base'] += $baseImponible;
                // marcar como procesado para no duplicar si hay múltiples retenciones en el mismo asiento
                $basesByAsiento[$line->idasiento] = 0;
            }
        }

        // calculamos la base de retenciones de sueldos y salarios (casilla 02) y la agrupamos por perceptor.
        // La base es neta: el debe suma y el haber (regularización o anulación de una nómina) resta
        foreach (static::$baseLines as $line) {
            $codcontrapartida = $line->codcontrapartida;
            if (empty($codcontrapartida)) {
                continue;
            }

            $baseNeta = $line->debe - $line->haber;
            static::$baseRetenciones += $baseNeta;

            // inicializar perceptor si no existe
            static::initRecipient($codcontrapartida);

            // sumar base de sueldos
            static::$recipientDetails[$codcontrapartida]['base'] += $baseNeta;
        }

        // calcular totales debe/haber de entryLines
        static::$totalDebe = 0.0;
        static::$totalHaber = 0.0;
        foreach (static::$entryLines as $line) {
            static::$totalDebe += $line->debe;
            static::$totalHaber += $line->haber;
        }

        static::$numRecipients = count($recipients);

        // calculamos el total a ingresar (casilla 05)
        static::$totalIngresar = static::$retencionesPracticadas + static::$ingresosPeriodoAnterior;

        // ordenar por código de contrapartida
        ksort(static::$recipientDetails);
    }

    /**
     * Devuelve los ids de los asientos de las líneas de retención cuyo debe revierte retenciones practicadas:
     * - los de facturas de compra, cuyas rectificativas (importes negativos) llevan la retención al debe;
     * - los que tienen una partida de gastos (grupo 6), como la regularización de una nómina o una
     *   rectificativa de compra registrada con un asiento manual.
     *
     * Los asientos de pago o liquidación de las retenciones (4751 al debe contra tesorería u otra cuenta
     * de balance) no tienen gastos, por lo que no se incluyen y su debe no resta.
     *
     * @return array ids de los asientos, indexados por su id
     */
    protected static function loadReversalAsientos(): array
    {
        if (empty(static::$entryLines)) {
            return [];
        }

        $asientosIds = [];
        foreach (static::$entryLines as $line) {
            $asientosIds[$line->idasiento] = (int)$line->idasiento;
        }

        $reversalAsientos = [];

        // asientos de facturas de compra
        $sql = 'SELECT idasiento FROM ' . FacturaProveedor::tableName()
            . ' WHERE idasiento IN (' . implode(',', $asientosIds) . ')';
        foreach (static::$dataBase->select($sql) as $row) {
            $reversalAsientos[$row['idasiento']] = $row['idasiento'];
        }

        // asientos con alguna partida de gastos (grupo 6)
        $sql = 'SELECT DISTINCT idasiento FROM ' . Partida::tableName()
            . ' WHERE idasiento IN (' . implode(',', $asientosIds) . ')'
            . ' AND codsubcuenta LIKE ' . static::$dataBase->var2str('6%');
        foreach (static::$dataBase->select($sql) as $row) {
            $reversalAsientos[$row['idasiento']] = $row['idasiento'];
        }

        return $reversalAsientos;
    }
}

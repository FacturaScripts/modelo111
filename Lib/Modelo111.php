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
use FacturaScripts\Dinamic\Lib\Modelo190;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\FacturaProveedor;
use FacturaScripts\Dinamic\Model\Partida;
use FacturaScripts\Dinamic\Model\Proveedor;
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

    /** @var array perceptores, percepciones y retenciones de cada grupo de casillas del modelo 111 */
    protected static $groups111 = [];

    /** @var float */
    protected static $ingresosPeriodoAnterior = 0.0;

    /** @var int */
    protected static $numRecipients = 0;

    /** @var bool true si el periodo natural no cabe entero en el ejercicio y se ha limitado a sus fechas */
    protected static $partialPeriod = false;

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

    /** @var float base de sueldos (640) que no se ha podido asignar a ningún perceptor */
    protected static $unassignedBase = 0.0;

    /** @var int año natural del modelo (el de la declaración y sus ficheros) */
    protected static $year = 0;

    /**
     * Calcula los datos del modelo 111 del ejercicio y periodo indicados.
     *
     * @param string $codejercicio código del ejercicio
     * @param string $period periodo: T1, T2, T3, T4 o ANNUAL (cualquier otro valor se trata como T1)
     * @param float $ingresosAnteriores importe ya ingresado en autoliquidaciones anteriores del mismo periodo
     *                                  (casilla 29), que se resta de las retenciones
     *
     * @return array resultados del modelo (con las fechas del periodo, dateStart y dateEnd, en formato d-m-Y,
     *               limitadas a las del ejercicio; el año natural del modelo, year, y si el periodo se ha
     *               recortado porque el ejercicio no lo abarca entero, partialPeriod), el detalle por perceptor
     *               (recipientDetails, con los datos del modelo 190), los totales por grupo de casillas del
     *               modelo 111 (groups111) y la base de sueldos sin perceptor (unassignedBase), o un array vacío
     *               si el ejercicio no existe
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
            'dateStart' => static::$dateStart,
            'dateEnd' => static::$dateEnd,
            'entryLines' => static::$entryLines,
            'baseLines' => static::$baseLines,
            'baseRetenciones' => static::$baseRetenciones,
            'retencionesPracticadas' => static::$retencionesPracticadas,
            'ingresosPeriodoAnterior' => static::$ingresosPeriodoAnterior,
            'numRecipients' => static::$numRecipients,
            'recipientDetails' => static::$recipientDetails,
            'groups111' => static::$groups111,
            'unassignedBase' => static::$unassignedBase,
            'totalDebe' => static::$totalDebe,
            'totalHaber' => static::$totalHaber,
            'totalIngresar' => static::$totalIngresar,
            'year' => static::$year,
            'partialPeriod' => static::$partialPeriod,
        ];
    }

    /**
     * Genera los asientos de obligación y pago del modelo a partir de los resultados de generate() de un
     * trimestre. El periodo anual (modelo 190) es informativo y no se ingresa, así que no genera asientos.
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

        if ($result['period'] === 'ANNUAL') {
            Tools::log()->warning('m111-entries-quarter-only');
            return false;
        }

        $exercise = $result['exercise'];
        $total = $result['totalIngresar'];
        $concepto = static::ENTRY_CONCEPT_PREFIX . $result['period'] . ' ' . $result['year'];

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
     * Indica si el ejercicio tiene subcuentas de retenciones de alquileres (cuenta especial IRPFA, en la
     * subcuenta o en su cuenta). Sin ellas, las retenciones de alquileres no se pueden separar de las del
     * modelo 111 y se incluyen en él.
     *
     * @param string $codejercicio código del ejercicio
     *
     * @return bool
     */
    public static function hasRentalSubaccounts(string $codejercicio): bool
    {
        // subcuentas marcadas directamente con la cuenta especial IRPFA
        $where = [
            Where::eq('codejercicio', $codejercicio),
            Where::eq('codcuentaesp', 'IRPFA'),
        ];
        if (Subcuenta::count($where) > 0) {
            return true;
        }

        // subcuentas de la cuenta con la cuenta especial IRPFA
        $special = new CuentaEspecial();
        if (false === $special->loadWhere([Where::eq('codcuentaesp', 'IRPFA')])) {
            return false;
        }

        $cuenta = $special->getCuenta($codejercicio);
        return $cuenta->exists() && !empty($cuenta->getSubcuentas());
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
     * Inicializa el detalle de un perceptor (base y retención a cero) si todavía no existe y carga sus datos
     * del modelo 190: los guardados para el ejercicio o unos propuestos. En los perceptores de facturas de
     * compra se proponen el NIF, el nombre y la provincia del proveedor y la clave según el porcentaje de
     * retención; en los de subcuenta, la descripción de la subcuenta y la clave A (trabajo) si el asiento
     * tiene sueldos o la cuenta no es de proveedores o acreedores (400 y 410), o G.01 en otro caso.
     *
     * @param string $tipo tipo de perceptor: proveedor o subcuenta
     * @param string $codigo código del proveedor o de la subcuenta
     * @param string $codContrapartida subcuenta contrapartida de la retención o del sueldo
     * @param array|null $invoice datos de la factura de compra del asiento, si la hay
     * @param bool $isPayroll true si el asiento tiene partidas de sueldos y salarios (640)
     *
     * @return string clave del perceptor en el detalle (tipo:codigo)
     */
    protected static function initRecipient(string $tipo, string $codigo, string $codContrapartida, ?array $invoice, bool $isPayroll): string
    {
        $key = $tipo . ':' . $codigo;
        if (isset(static::$recipientDetails[$key])) {
            return $key;
        }

        // buscar la subcuenta de la contrapartida para obtener su descripción
        $subcuenta = new Subcuenta();
        $whereSubcuenta = [
            Where::eq('codejercicio', static::$exercise->codejercicio),
            Where::eq('codsubcuenta', $codContrapartida)
        ];
        $descripcion = '';
        if ($codContrapartida !== '' && $subcuenta->loadWhere($whereSubcuenta)) {
            $descripcion = $subcuenta->descripcion;
        }

        // datos propuestos del modelo 190
        if ($tipo === Modelo190::TYPE_SUPPLIER) {
            $defaults = static::supplierDefaults($codigo, $invoice ?? []);
        } else {
            $isActivity = false === $isPayroll
                && (str_starts_with($codContrapartida, '400') || str_starts_with($codContrapartida, '410'));
            $defaults = [
                'nombre' => $descripcion,
                'clave' => $isActivity ? 'G' : 'A',
                'subclave' => $isActivity ? '01' : '',
            ];
        }
        $perceptor = Modelo190::getPerceptor(static::$exercise->codejercicio, $tipo, $codigo, $defaults);

        static::$recipientDetails[$key] = [
            'key' => $key,
            'tipo' => $tipo,
            'codigo' => $codigo,
            'codcontrapartida' => $codContrapartida,
            'descripcion' => $descripcion,
            'cifnif' => (string)$perceptor->cifnif,
            'nombre' => empty($perceptor->nombre) ? $descripcion : (string)$perceptor->nombre,
            'codprovincia' => (string)$perceptor->codprovincia,
            'clave' => (string)$perceptor->clave,
            'subclave' => (string)$perceptor->subclave,
            'grupo111' => Modelo190::group111((string)$perceptor->clave, (string)$perceptor->subclave),
            'guardado' => $perceptor->exists(),
            'perceptor' => $perceptor,
            'base' => 0.0,
            'retencion' => 0.0,
        ];
        return $key;
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
        // si el periodo no es T1, T2, T3, T4 o ANNUAL, se asume que es el primer trimestre
        if (!in_array(static::$period, ['T1', 'T2', 'T3', 'T4', 'ANNUAL'])) {
            static::$period = 'T1';
        }

        // meses de inicio y fin del periodo dentro del año natural
        [$monthStart, $monthEnd] = match (static::$period) {
            'T2' => [4, 6],
            'T3' => [7, 9],
            'T4' => [10, 12],
            'ANNUAL' => [1, 12],
            default => [1, 3],
        };

        // los modelos 111 y 190 van por año natural: el año es el primero del ejercicio en el que el periodo
        // tiene algún día, para que los trimestres de un ejercicio que no empieza en enero caigan dentro de él
        $exerciseStart = strtotime(static::$exercise->fechainicio);
        $exerciseEnd = strtotime(static::$exercise->fechafin);
        $year = (int)date('Y', $exerciseStart);
        for ($candidate = $year; $candidate <= (int)date('Y', $exerciseEnd); $candidate++) {
            $end = strtotime(date('Y-m-t', mktime(0, 0, 0, $monthEnd, 1, $candidate)));
            if ($end >= $exerciseStart) {
                $year = $candidate;
                break;
            }
        }

        // el periodo natural se limita a las fechas del ejercicio, que es de donde se leen las partidas
        $start = mktime(0, 0, 0, $monthStart, 1, $year);
        $end = strtotime(date('Y-m-t', mktime(0, 0, 0, $monthEnd, 1, $year)));
        static::$year = $year;
        static::$partialPeriod = $start < $exerciseStart || $end > $exerciseEnd;
        static::$dateStart = date('d-m-Y', max($start, $exerciseStart));
        static::$dateEnd = date('d-m-Y', min($end, $exerciseEnd));
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
     * Calcula los perceptores, percepciones y retenciones de cada grupo de casillas del modelo 111 según la
     * clave de percepción de cada perceptor (trabajo 01-03, actividades económicas 07-09, premios 13-15,
     * aprovechamientos forestales 19-21 y derechos de imagen 25-27). Solo se cuentan como perceptores los que
     * tienen retenciones. La base de sueldos sin perceptor se suma a los rendimientos del trabajo.
     *
     * @param array $recipients claves de los perceptores con retenciones
     */
    protected static function loadGroups111(array $recipients): void
    {
        static::$groups111 = [];
        foreach (array_keys(Modelo190::GROUPS_111) as $group) {
            static::$groups111[$group] = ['perceptores' => 0, 'base' => 0.0, 'retenciones' => 0.0];
        }

        foreach (static::$recipientDetails as $key => $detail) {
            $group = $detail['grupo111'];
            if ($group === '') {
                continue;
            }

            static::$groups111[$group]['base'] += $detail['base'];
            static::$groups111[$group]['retenciones'] += $detail['retencion'];
            if (isset($recipients[$key])) {
                static::$groups111[$group]['perceptores']++;
            }
        }

        static::$groups111[Modelo190::GROUP_WORK]['base'] += static::$unassignedBase;
    }

    /**
     * Carga las facturas de compra de los asientos con retenciones y, de cada una, el proveedor, su NIF,
     * nombre y dirección, el porcentaje de retención y la base sujeta a retención (en euros): el neto de las
     * líneas con IRPF con los descuentos del documento o, si las líneas no tienen IRPF, el neto de la factura.
     * En las rectificativas la base es negativa.
     *
     * @return array datos de cada factura, indexados por el id de su asiento
     */
    protected static function loadInvoices(): array
    {
        if (empty(static::$entryLines)) {
            return [];
        }

        $asientosIds = [];
        foreach (static::$entryLines as $line) {
            $asientosIds[$line->idasiento] = (int)$line->idasiento;
        }

        $invoices = [];
        foreach (FacturaProveedor::all([Where::in('idasiento', array_values($asientosIds))]) as $invoice) {
            $base = 0.0;
            $irpf = (float)$invoice->irpf;
            foreach ($invoice->getLines() as $line) {
                if (empty($line->irpf)) {
                    continue;
                }
                $base += (float)$line->pvptotal;
                if (empty($irpf)) {
                    $irpf = (float)$line->irpf;
                }
            }
            $base = abs($base) > 0.0 ? $base * $invoice->getEUDiscount() : (float)$invoice->neto;
            $tasaconv = empty($invoice->tasaconv) ? 1.0 : (float)$invoice->tasaconv;

            $invoices[$invoice->idasiento] = [
                'codproveedor' => (string)$invoice->codproveedor,
                'cifnif' => (string)$invoice->cifnif,
                'nombre' => (string)$invoice->nombre,
                'codpostal' => (string)$invoice->codpostal,
                'codpais' => (string)$invoice->codpais,
                'irpf' => $irpf,
                'base' => round($base / $tasaconv, 2),
            ];
        }

        return $invoices;
    }

    /**
     * Calcula las casillas del modelo (perceptores, base, retenciones y total a ingresar), el detalle
     * por perceptor con sus datos del modelo 190, los totales por grupo de casillas del modelo 111 y los
     * totales del debe y del haber de las partidas de retenciones.
     *
     * El perceptor de una retención es el proveedor de la factura de compra del asiento o, si el asiento no
     * es de una factura (nóminas u otros asientos manuales), la subcuenta contrapartida de la retención.
     */
    protected static function loadResults(): void
    {
        $recipients = [];
        static::$baseRetenciones = 0.0;
        static::$retencionesPracticadas = 0.0;
        static::$recipientDetails = [];
        static::$unassignedBase = 0.0;

        // obtener bases imponibles de los asientos con retenciones y las facturas de compra
        $basesByAsiento = static::loadBasesByAsiento();
        $invoices = static::loadInvoices();

        // asientos cuyo debe revierte retenciones (rectificativas de compra, regularizaciones de nóminas...)
        $reversalAsientos = static::loadReversalAsientos();

        // asientos con sueldos y salarios (640): nóminas
        $payrollAsientos = [];
        foreach (static::$baseLines as $line) {
            $payrollAsientos[$line->idasiento] = true;
        }

        // perceptores de cada asiento y asientos cuya base ya se ha sumado
        $asientoRecipients = [];
        $baseDone = [];

        // calculamos las retenciones practicadas y los perceptores
        foreach (static::$entryLines as $line) {
            $codcontrapartida = (string)$line->codcontrapartida;
            $invoice = $invoices[$line->idasiento] ?? null;
            if (empty($codcontrapartida) && null === $invoice) {
                continue;
            }

            // retención neta: el haber suma y el debe de los asientos que revierten retenciones resta
            $isReversal = isset($reversalAsientos[$line->idasiento]);
            if (false === $isReversal && $line->haber == 0.0) {
                // debe de un pago o liquidación de las retenciones: no es una retención ni un perceptor
                continue;
            }

            // el perceptor es el proveedor de la factura o la subcuenta contrapartida
            $key = null === $invoice
                ? static::initRecipient(Modelo190::TYPE_SUBACCOUNT, $codcontrapartida, $codcontrapartida, null, isset($payrollAsientos[$line->idasiento]))
                : static::initRecipient(Modelo190::TYPE_SUPPLIER, $invoice['codproveedor'], $codcontrapartida, $invoice, false);
            $recipients[$key] = $key;
            $asientoRecipients[$line->idasiento][$key] = $key;

            $retencionNeta = $isReversal ? $line->haber - $line->debe : $line->haber;
            static::$retencionesPracticadas += $retencionNeta;
            static::$recipientDetails[$key]['retencion'] += $retencionNeta;

            // sumamos la base del asiento una sola vez, aunque tenga varias retenciones: la de la factura de
            // compra o, si no hay factura, la base imponible de sus partidas. En las rectificativas es negativa
            if (isset($baseDone[$line->idasiento])) {
                continue;
            }
            $baseDone[$line->idasiento] = true;
            $baseImponible = null === $invoice ? ($basesByAsiento[$line->idasiento] ?? 0.0) : $invoice['base'];
            static::$baseRetenciones += $baseImponible;
            static::$recipientDetails[$key]['base'] += $baseImponible;
        }

        // calculamos la base de retenciones de sueldos y salarios y la agrupamos por perceptor: el de su
        // contrapartida o, si no tiene o no retiene, el único perceptor del asiento. La base es neta: el debe
        // suma y el haber (regularización o anulación de una nómina) resta
        foreach (static::$baseLines as $line) {
            $baseNeta = $line->debe - $line->haber;
            static::$baseRetenciones += $baseNeta;

            $codcontrapartida = (string)$line->codcontrapartida;
            $asientoKeys = $asientoRecipients[$line->idasiento] ?? [];
            $key = Modelo190::TYPE_SUBACCOUNT . ':' . $codcontrapartida;
            if ($codcontrapartida === '' || false === isset($asientoKeys[$key])) {
                if (count($asientoKeys) === 1) {
                    $key = reset($asientoKeys);
                } elseif ($codcontrapartida !== '') {
                    $key = static::initRecipient(Modelo190::TYPE_SUBACCOUNT, $codcontrapartida, $codcontrapartida, null, true);
                } else {
                    // nómina de varios perceptores sin contrapartida: no se puede repartir
                    static::$unassignedBase += $baseNeta;
                    continue;
                }
            }

            static::$recipientDetails[$key]['base'] += $baseNeta;
        }

        // calcular totales debe/haber de entryLines
        static::$totalDebe = 0.0;
        static::$totalHaber = 0.0;
        foreach (static::$entryLines as $line) {
            static::$totalDebe += $line->debe;
            static::$totalHaber += $line->haber;
        }

        static::$numRecipients = count($recipients);

        // calculamos el total a ingresar: retenciones (casilla 28 de la AEAT) menos lo ya ingresado en
        // autoliquidaciones anteriores del mismo periodo (casilla 29), como en una complementaria
        static::$totalIngresar = static::$retencionesPracticadas - static::$ingresosPeriodoAnterior;

        // ordenar por tipo y código del perceptor
        ksort(static::$recipientDetails);

        static::loadGroups111($recipients);
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

    /**
     * Devuelve los datos propuestos del modelo 190 para un proveedor: NIF y razón social del proveedor (o los
     * de la factura), provincia según el código postal de su dirección (o el de la factura) y clave según el
     * porcentaje de retención de la factura.
     *
     * @param string $codproveedor código del proveedor
     * @param array $invoice datos de la factura de compra
     *
     * @return array
     */
    protected static function supplierDefaults(string $codproveedor, array $invoice): array
    {
        [$clave, $subclave] = Modelo190::claveFromRetention((float)($invoice['irpf'] ?? 0));
        $defaults = [
            'cifnif' => $invoice['cifnif'] ?? '',
            'nombre' => $invoice['nombre'] ?? '',
            'codprovincia' => Modelo190::provinceFromPostalCode($invoice['codpostal'] ?? '', $invoice['codpais'] ?? ''),
            'clave' => $clave,
            'subclave' => $subclave,
        ];

        $proveedor = new Proveedor();
        if (false === $proveedor->load($codproveedor)) {
            return $defaults;
        }

        if (!empty($proveedor->cifnif)) {
            $defaults['cifnif'] = $proveedor->cifnif;
        }
        if (!empty($proveedor->razonsocial)) {
            $defaults['nombre'] = $proveedor->razonsocial;
        }

        $address = $proveedor->getDefaultAddress();
        if ($address->exists()) {
            $province = Modelo190::provinceFromPostalCode($address->codpostal, $address->codpais);
            if ($province !== '') {
                $defaults['codprovincia'] = $province;
            }
        }

        return $defaults;
    }
}

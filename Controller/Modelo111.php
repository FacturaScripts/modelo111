<?php
/**
 * This file is part of Modelo111 plugin for FacturaScripts
 * Copyright (C) 2020-2026 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\CuentaEspecial;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\Partida;
use FacturaScripts\Dinamic\Model\Retencion;
use FacturaScripts\Dinamic\Model\Subcuenta;

/**
 * Description of Modelo111
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class Modelo111 extends Controller
{
    /** @var string */
    public $codejercicio;

    /** @var string */
    public $dateEnd;

    /** @var string */
    public $dateStart;

    /** @var Partida[] */
    public $entryLines = [];

    /** @var Partida[] */
    public $baseLines = [];

    /** @var int */
    public $numrecipients = 0;

    /** @var string */
    public $period = 'T1';

    /** @var float */
    public $baseRetenciones = 0.0;

    /** @var float */
    public $retencionesPracticadas = 0.0;

    /** @var float */
    public $ingresosPeriodoAnterior = 0.0;

    /** @var float */
    public $totalIngresar = 0.0;

    /** @var array */
    public $recipientDetails = [];

    /** @return Ejercicio[] */
    public function allExercises(?int $idempresa): array
    {
        if (empty($idempresa)) {
            return Ejercicios::all();
        }

        $list = [];
        foreach (Ejercicios::all() as $exercise) {
            if ($exercise->idempresa == $idempresa) {
                $list[] = $exercise;
            }
        }
        return $list;
    }

    public function allPeriods(): array
    {
        return [
            'T1' => 'first-trimester',
            'T2' => 'second-trimester',
            'T3' => 'third-trimester',
            'T4' => 'fourth-trimester',
            'Annual' => 'annual-190',
        ];
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['title'] = 'model-111-190';
        $data['icon'] = 'fa-solid fa-book';
        return $data;
    }

    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->loadDates();
        $this->ingresosPeriodoAnterior = (float)$this->request->request->get('ingresosanteriores', 0);
        $this->loadEntryLines();
        $this->loadBaseLines();
        $this->loadResults();

        // Manejar la acción de descarga del archivo
        $action = $this->request->request->get('action', '');
        if ($action === 'download') {
            $this->downloadFile($response);
        }
    }

    protected function loadDates(): void
    {
        $this->codejercicio = $this->request->request->get('codejercicio', '');
        $this->period = $this->request->request->get('period', $this->period);

        $exercise = new Ejercicio();
        $exercise->load($this->codejercicio);

        switch ($this->period) {
            case 'T1':
                $this->dateStart = date('01-01-Y', strtotime($exercise->fechainicio));
                $this->dateEnd = date('31-03-Y', strtotime($exercise->fechainicio));
                break;

            case 'T2':
                $this->dateStart = date('01-04-Y', strtotime($exercise->fechainicio));
                $this->dateEnd = date('30-06-Y', strtotime($exercise->fechainicio));
                break;

            case 'T3':
                $this->dateStart = date('01-07-Y', strtotime($exercise->fechainicio));
                $this->dateEnd = date('30-09-Y', strtotime($exercise->fechainicio));
                break;

            case 'Annual':
                $this->dateStart = date('01-01-Y', strtotime($exercise->fechainicio));
                $this->dateEnd = date('31-12-Y', strtotime($exercise->fechainicio));
                break;

            default:
                $this->dateStart = date('01-10-Y', strtotime($exercise->fechainicio));
                $this->dateEnd = date('31-12-Y', strtotime($exercise->fechainicio));
                break;
        }
    }

    protected function loadEntryLines(): void
    {
        if (empty($this->codejercicio)) {
            return;
        }

        // obtenemos el listado de subcuentas de IRPF del ejercicio
        $ids = [];

        $special = new CuentaEspecial();
        $where = [new DataBaseWhere('codcuentaesp', 'IRPFPR')];
        if ($special->loadWhere($where)) {
            foreach ($special->getCuenta($this->codejercicio)->getSubcuentas() as $subcuenta) {
                $ids[$subcuenta->id()] = $subcuenta->id();
            }
        }

        // añadimos las de las retenciones
        $retentionModel = new Retencion();
        foreach ($retentionModel->all() as $retention) {
            $subcuenta = new Subcuenta();

            // subcuenta para compras
            $whereAcr = [
                new DataBaseWhere('codejercicio', $this->codejercicio),
                new DataBaseWhere('codsubcuenta', $retention->codsubcuentaacr)
            ];
            if ($retention->codsubcuentaacr && $subcuenta->loadWhere($whereAcr)) {
                $ids[$subcuenta->id()] = $subcuenta->id();
            }

            // subcuenta para ventas
            $whereRet = [
                new DataBaseWhere('codejercicio', $this->codejercicio),
                new DataBaseWhere('codsubcuenta', $retention->codsubcuentaret)
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
            . ' WHERE a.codejercicio = ' . $this->dataBase->var2str($this->codejercicio)
            . ' AND a.fecha BETWEEN ' . $this->dataBase->var2str($this->dateStart)
            . ' AND ' . $this->dataBase->var2str($this->dateEnd)
            . ' AND p.idsubcuenta IN (' . implode(',', $ids) . ')';
        foreach ($this->dataBase->select($sql) as $row) {
            $this->entryLines[] = new Partida($row);
        }
    }

    protected function loadBaseLines(): void
    {
        if (empty($this->codejercicio) || empty($this->entryLines)) {
            return;
        }

        // obtenemos los asientos únicos de las retenciones
        $asientos = [];
        foreach ($this->entryLines as $line) {
            $asientos[$line->idasiento] = $line->idasiento;
        }

        if (empty($asientos)) {
            return;
        }

        // obtenemos las subcuentas de sueldos y salarios (640)
        $subcuenta = new Subcuenta();
        $where = [
            new DataBaseWhere('codejercicio', $this->codejercicio),
            new DataBaseWhere('codsubcuenta', '640', 'LIKE')
        ];
        $ids = [];
        foreach ($subcuenta->all($where) as $sub) {
            $ids[$sub->id()] = $sub->id();
        }

        if (empty($ids)) {
            return;
        }

        // buscamos las partidas de gastos de personal que estén en los mismos asientos que las retenciones
        $sql = 'SELECT * FROM ' . Partida::tableName() . ' as p'
            . ' WHERE p.idasiento IN (' . implode(',', $asientos) . ')'
            . ' AND p.idsubcuenta IN (' . implode(',', $ids) . ')';

        foreach ($this->dataBase->select($sql) as $row) {
            $this->baseLines[] = new Partida($row);
        }
    }

    protected function loadResults(): void
    {
        $recipients = [];
        $this->baseRetenciones = 0.0;
        $this->retencionesPracticadas = 0.0;
        $this->recipientDetails = [];

        // obtener bases imponibles de los asientos con retenciones
        $basesByAsiento = $this->loadBasesByAsiento();

        // calculamos las retenciones practicadas (casilla 03) y los perceptores
        foreach ($this->entryLines as $line) {
            $codcontrapartida = $line->codcontrapartida;
            $recipients[$codcontrapartida] = $codcontrapartida;
            $this->retencionesPracticadas += $line->haber;

            // si no existe la contrapartida saltar
            if (empty($codcontrapartida)) {
                continue;
            }

            // inicializar perceptor si no existe
            $this->initRecipient($codcontrapartida);

            // sumar retención
            $this->recipientDetails[$codcontrapartida]['retencion'] += $line->haber;

            // sumar base imponible del asiento completo (puede estar en cualquier partida del asiento)
            if (isset($basesByAsiento[$line->idasiento]) && $basesByAsiento[$line->idasiento] > 0) {
                $baseImponible = $basesByAsiento[$line->idasiento];
                $this->baseRetenciones += $baseImponible;
                $this->recipientDetails[$codcontrapartida]['base'] += $baseImponible;
                // marcar como procesado para no duplicar si hay múltiples retenciones en el mismo asiento
                $basesByAsiento[$line->idasiento] = 0;
            }
        }

        // calculamos la base de retenciones de sueldos y salarios (casilla 02) y la agrupamos por perceptor
        foreach ($this->baseLines as $line) {
            $codcontrapartida = $line->codcontrapartida;
            $this->baseRetenciones += $line->debe;

            // si no existe la contrapartida saltar
            if (empty($codcontrapartida)) {
                continue;
            }

            // inicializar perceptor si no existe
            $this->initRecipient($codcontrapartida);

            // sumar base de sueldos
            $this->recipientDetails[$codcontrapartida]['base'] += $line->debe;
        }

        $this->numrecipients = count($recipients);

        // calculamos el total a ingresar (casilla 05)
        $this->totalIngresar = $this->retencionesPracticadas + $this->ingresosPeriodoAnterior;

        // ordenar por código de contrapartida
        ksort($this->recipientDetails);
    }

    protected function loadBasesByAsiento(): array
    {
        if (empty($this->entryLines)) {
            return [];
        }

        // obtener IDs únicos de asientos con retenciones
        $asientosIds = [];
        foreach ($this->entryLines as $line) {
            $asientosIds[$line->idasiento] = $line->idasiento;
        }

        // cargar bases imponibles de todos los asientos con retenciones
        $basesByAsiento = [];
        $sql = 'SELECT idasiento, SUM(baseimponible) as total_base FROM ' . Partida::tableName()
            . ' WHERE idasiento IN (' . implode(',', $asientosIds) . ')'
            . ' GROUP BY idasiento';
        foreach ($this->dataBase->select($sql) as $row) {
            $basesByAsiento[$row['idasiento']] = (float)$row['total_base'];
        }

        return $basesByAsiento;
    }

    protected function initRecipient(string $codcontrapartida): void
    {
        if (isset($this->recipientDetails[$codcontrapartida])) {
            return;
        }

        // buscar la subcuenta de la contrapartida para obtener su descripción
        $subcuenta = new Subcuenta();
        $whereSubcuenta = [
            new DataBaseWhere('codejercicio', $this->codejercicio),
            new DataBaseWhere('codsubcuenta', $codcontrapartida)
        ];
        $descripcion = '';
        if ($subcuenta->loadWhere($whereSubcuenta)) {
            $descripcion = $subcuenta->descripcion;
        }

        $this->recipientDetails[$codcontrapartida] = [
            'codcontrapartida' => $codcontrapartida,
            'descripcion' => $descripcion,
            'base' => 0.0,
            'retencion' => 0.0
        ];
    }

    protected function downloadFile(&$response): void
    {
        if (empty($this->codejercicio)) {
            Tools::log()->warning('no-exercise-selected');
            return;
        }

        // Obtener datos de la empresa
        $ejercicio = new Ejercicio();
        if (!$ejercicio->load($this->codejercicio)) {
            Tools::log()->error('exercise-not-found');
            return;
        }

        $empresa = new Empresa();
        if (!$empresa->load($ejercicio->idempresa)) {
            Tools::log()->error('company-not-found');
            return;
        }

        // Generar el contenido del archivo
        $content = $this->generateFileContent($empresa, $ejercicio);

        // Configurar headers para descarga
        $year = date('Y', strtotime($ejercicio->fechainicio));
        $periodo = $this->getPeriodNumber();
        $filename = $empresa->cifnif . '_' . $year . '_' . $periodo . '.111';

        $response->headers->set('Content-Type', 'text/plain; charset=ISO-8859-1');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->setContent(mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8'));
        $response->send();
        exit;
    }

    protected function generateFileContent(Empresa $empresa, Ejercicio $ejercicio): string
    {
        $lines = [];
        $year = date('Y', strtotime($ejercicio->fechainicio));
        $periodo = $this->getPeriodNumber();

        // Registro tipo 1: Declarante
        $lines[] = $this->generateRecord1($empresa, $year, $periodo);

        // Registro tipo 2: Totales
        $lines[] = $this->generateRecord2($empresa, $year, $periodo);

        return implode("\r\n", $lines) . "\r\n";
    }

    protected function generateRecord1(Empresa $empresa, string $year, string $periodo): string
    {
        // Tipo de registro: 1
        $record = '1';

        // Modelo: 111
        $record .= '111';

        // Ejercicio (4 dígitos)
        $record .= $year;

        // NIF del declarante (9 caracteres, alineado a la izquierda)
        $record .= $this->formatAlphanumeric($empresa->cifnif, 9);

        // Nombre o razón social del declarante (40 caracteres)
        $record .= $this->formatAlphanumeric($empresa->nombre, 40);

        // Tipo de soporte: 'T' (telemático)
        $record .= 'T';

        // Teléfono (9 caracteres)
        $record .= $this->formatAlphanumeric($empresa->telefono1, 9);

        // Persona de contacto (40 caracteres)
        $record .= $this->formatAlphanumeric($empresa->administrador, 40);

        // Número de identificación fiscal del representante legal (9 caracteres, espacios si no aplica)
        $record .= str_repeat(' ', 9);

        // Período: dos dígitos (01, 02, 03, 04, 0A para anual)
        $record .= $periodo;

        // Espacios de relleno hasta completar 252 caracteres
        $record .= str_repeat(' ', 252 - strlen($record));

        return $record;
    }

    protected function generateRecord2(Empresa $empresa, string $year, string $periodo): string
    {
        // Tipo de registro: 2
        $record = '2';

        // Modelo: 111
        $record .= '111';

        // Ejercicio (4 dígitos)
        $record .= $year;

        // NIF del declarante (9 caracteres)
        $record .= $this->formatAlphanumeric($empresa->cifnif, 9);

        // Período: dos dígitos
        $record .= $periodo;

        // Subclave: Clave A - Rendimientos del trabajo (2 caracteres)
        $record .= '01';

        // Número de perceptores (9 dígitos, sin decimales)
        $record .= $this->formatNumeric($this->numrecipients, 9, 0);

        // Base de retenciones (15 enteros + 2 decimales = 17 caracteres)
        $record .= $this->formatNumeric($this->baseRetenciones, 17, 2);

        // Retenciones practicadas (15 enteros + 2 decimales = 17 caracteres)
        $record .= $this->formatNumeric($this->retencionesPracticadas, 17, 2);

        // Ingresos del período anterior (15 enteros + 2 decimales = 17 caracteres)
        $record .= $this->formatNumeric($this->ingresosPeriodoAnterior, 17, 2);

        // Espacios de relleno hasta completar 252 caracteres
        $record .= str_repeat(' ', 252 - strlen($record));

        return $record;
    }

    protected function formatAlphanumeric(string $value, int $length): string
    {
        // Eliminar caracteres especiales y acentos
        $value = $this->removeAccents($value);
        $value = strtoupper($value);

        // Truncar o rellenar con espacios a la derecha
        return str_pad(substr($value, 0, $length), $length, ' ', STR_PAD_RIGHT);
    }

    protected function formatNumeric(float $value, int $length, int $decimals): string
    {
        // Convertir a entero sin decimales (multiplicando por 10^decimales)
        $multiplier = pow(10, $decimals);
        $intValue = (int)round($value * $multiplier);

        // Formatear con ceros a la izquierda
        return str_pad($intValue, $length, '0', STR_PAD_LEFT);
    }

    protected function removeAccents(string $text): string
    {
        $unwanted = [
            'á' => 'A', 'é' => 'E', 'í' => 'I', 'ó' => 'O', 'ú' => 'U',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'N', 'Ñ' => 'N', 'ü' => 'U', 'Ü' => 'U'
        ];
        return strtr($text, $unwanted);
    }

    protected function getPeriodNumber(): string
    {
        switch ($this->period) {
            case 'T1':
                return '1T';
            case 'T2':
                return '2T';
            case 'T3':
                return '3T';
            case 'T4':
                return '4T';
            case 'Annual':
                return '0A';
            default:
                return '1T';
        }
    }
}

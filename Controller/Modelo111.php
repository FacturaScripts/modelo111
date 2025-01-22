<?php
/**
 * This file is part of Modelo111 plugin for FacturaScripts
 * Copyright (C) 2020-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Dinamic\Model\Asiento;
use FacturaScripts\Dinamic\Model\CuentaEspecial;
use FacturaScripts\Dinamic\Model\Ejercicio;
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

    /** @var int */
    public $numrecipients = 0;

    /** @var string */
    public $period = 'T1';

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
        $this->loadEntryLines();
        $this->loadResults();
    }

    protected function loadDates(): void
    {
        $this->codejercicio = $this->request->request->get('codejercicio', '');
        $this->period = $this->request->request->get('period', $this->period);

        $exercise = new Ejercicio();
        $exercise->loadFromCode($this->codejercicio);

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
        if ($special->loadFromCode('', $where)) {
            foreach ($special->getCuenta($this->codejercicio)->getSubcuentas() as $subcuenta) {
                $ids[$subcuenta->primaryColumnValue()] = $subcuenta->primaryColumnValue();
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
            if ($retention->codsubcuentaacr && $subcuenta->loadFromCode('', $whereAcr)) {
                $ids[$subcuenta->primaryColumnValue()] = $subcuenta->primaryColumnValue();
            }

            // subcuenta para ventas
            $whereRet = [
                new DataBaseWhere('codejercicio', $this->codejercicio),
                new DataBaseWhere('codsubcuenta', $retention->codsubcuentaret)
            ];
            if ($retention->codsubcuentaret && $subcuenta->loadFromCode('', $whereRet)) {
                $ids[$subcuenta->primaryColumnValue()] = $subcuenta->primaryColumnValue();
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

    protected function loadResults(): void
    {
        $recipients = [];
        foreach ($this->entryLines as $line) {
            $recipients[$line->codcontrapartida] = $line->codcontrapartida;
        }

        $this->numrecipients = count($recipients);
    }
}

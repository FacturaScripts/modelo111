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
use FacturaScripts\Core\DataSrc\Ejercicios;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\Modelo111 as LibModelo111;
use FacturaScripts\Dinamic\Model\Empresa;

/**
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo111 extends Controller
{
    /** @var string */
    public $codejercicio;

    /** @var float */
    public $ingresosPeriodoAnterior = 0.0;

    /** @var string */
    public $period = 'T1';

    /** @var array */
    public $result = [];

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
            'ANNUAL' => 'annual-190',
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

        $this->codejercicio = $this->request->inputOrQuery('codejercicio', date('Y'));
        $this->period = $this->request->inputOrQuery('period', $this->getCurrentPeriod());
        $this->ingresosPeriodoAnterior = (float)$this->request->inputOrQuery('ingresosanteriores', 0);

        $this->result = LibModelo111::generate(
            $this->codejercicio,
            $this->period,
            $this->ingresosPeriodoAnterior
        );

        $action = $this->request->inputOrQuery('action', '');
        if ($action === 'download') {
            $this->downloadFile($response);
        } elseif ($action === 'generate-entries') {
            if (LibModelo111::generateEntries($this->result)) {
                Tools::log()->notice('record-updated-correctly');
            }
        }
    }

    protected function getCurrentPeriod(): string
    {
        // obtenemos el número del trimestre en el que se encuentra la fecha actual
        $month = date('n');
        return match ($month) {
            1, 2, 3 => 'T1',
            4, 5, 6 => 'T2',
            7, 8, 9 => 'T3',
            10, 11, 12 => 'T4',
            default => 'T1',
        };
    }

    protected function downloadFile(&$response): void
    {
        if (empty($this->result)) {
            Tools::log()->warning('no-data');
            return;
        }

        $empresa = new Empresa();
        if (!$empresa->load($this->result['exercise']->idempresa)) {
            Tools::log()->error('company-not-found');
            return;
        }

        // Generar el contenido del archivo
        $content = $this->generateFileContent($empresa);

        // Configurar headers para descarga
        $year = date('Y', strtotime($this->result['exercise']->fechainicio));
        $periodo = $this->getPeriodNumber();
        $filename = $empresa->cifnif . '_' . $year . '_' . $periodo . '.111';

        $response->headers->set('Content-Type', 'text/plain; charset=ISO-8859-1');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->setContent(mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8'));
        $response->send();
        exit;
    }

    protected function generateFileContent(Empresa $empresa): string
    {
        $lines = [];
        $year = date('Y', strtotime($this->result['exercise']->fechainicio));
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
        $record .= $this->formatNumeric($this->result['numRecipients'], 9, 0);

        // Base de retenciones (15 enteros + 2 decimales = 17 caracteres)
        $record .= $this->formatNumeric($this->result['baseRetenciones'], 17, 2);

        // Retenciones practicadas (15 enteros + 2 decimales = 17 caracteres)
        $record .= $this->formatNumeric($this->result['retencionesPracticadas'], 17, 2);

        // Ingresos del período anterior (15 enteros + 2 decimales = 17 caracteres)
        $record .= $this->formatNumeric($this->result['ingresosPeriodoAnterior'], 17, 2);

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
        return match ($this->result['period']) {
            'T2' => '2T',
            'T3' => '3T',
            'T4' => '4T',
            'Annual' => '0A',
            default => '1T',
        };
    }
}

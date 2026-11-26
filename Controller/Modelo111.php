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
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Lib\Modelo111 as LibModelo111;
use FacturaScripts\Dinamic\Lib\Modelo111File;
use FacturaScripts\Dinamic\Lib\Modelo190;
use FacturaScripts\Dinamic\Lib\Modelo190File;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\Modelo190Perceptor;
use XLSXWriter;

/**
 * Pantalla de los modelos 111 (trimestral) y 190 (resumen anual): muestra el cálculo, genera los ficheros de
 * presentación de la AEAT de ambos modelos, guarda los datos del modelo 190 de cada perceptor, exporta los
 * datos a Excel o PDF y crea los asientos de obligación y pago.
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo111 extends Controller
{
    /** @var string */
    public $codejercicio;

    /** @var Empresa empresa del ejercicio del modelo */
    public $company;

    /** @var string[] errores que impiden generar el fichero del modelo 190 */
    public $errors190 = [];

    /** @var float */
    public $ingresosPeriodoAnterior = 0.0;

    /** @var array opciones del fichero del modelo 111: tipo, iban y justificante */
    public $options111 = [];

    /** @var array opciones del fichero del modelo 190: numero, tipo, numeroanterior, contacto, telefono y email */
    public $options190 = [];

    /** @var string */
    public $period = 'T1';

    /** @var array */
    public $result = [];

    /**
     * Devuelve los ejercicios disponibles, filtrados por empresa si se indica.
     *
     * @param int|null $idempresa id de la empresa o null para todas
     *
     * @return array
     */
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

    /**
     * Devuelve los periodos seleccionables (cuatro trimestres y el resumen anual del 190) con su clave de traducción.
     *
     * @return array
     */
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

    /**
     * Devuelve las claves de percepción del modelo 190.
     *
     * @return array
     */
    public function claves190(): array
    {
        return Modelo190::CLAVES;
    }

    /**
     * Datos de la página: menú Informes, submenú de fiscalidad España.
     *
     * @return array
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'reports';
        $data['submenu'] = 'taxation-spain';
        $data['title'] = 'model-111-190';
        $data['icon'] = 'fa-solid fa-book';
        return $data;
    }

    /**
     * Lógica del controlador: lee ejercicio, periodo y acción, guarda el perceptor si procede, calcula el modelo
     * y ejecuta la acción pedida (descargas, impresión o generación de asientos).
     *
     * @param mixed $response respuesta HTTP
     * @param mixed $user usuario actual
     * @param mixed $permissions permisos del usuario
     */
    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->company = new Empresa();
        $this->codejercicio = $this->request->inputOrQuery('codejercicio', date('Y'));
        $this->period = $this->request->inputOrQuery('period', $this->getCurrentPeriod());
        $this->ingresosPeriodoAnterior = (float)$this->request->inputOrQuery('ingresosanteriores', 0);
        if ($this->ingresosPeriodoAnterior < 0) {
            // la casilla 29 es lo ya ingresado y se resta: un importe negativo aumentaría el resultado
            Tools::log()->warning('m111-negative-previous-income');
            $this->ingresosPeriodoAnterior = 0.0;
        }

        // los datos de un perceptor se guardan antes de calcular, para que el cálculo los tenga en cuenta
        $action = $this->request->inputOrQuery('action', '');
        if ($action === 'save-recipient') {
            $this->saveRecipientAction();
        }

        $this->result = LibModelo111::generate(
            $this->codejercicio,
            $this->period,
            $this->ingresosPeriodoAnterior
        );
        $this->loadFileOptions();

        if ($action === 'download') {
            $this->downloadFile($response);
        } elseif ($action === 'download-190') {
            $this->downloadFile190($response);
        } elseif ($action === 'print') {
            $this->printAction();
        } elseif ($action === 'download-xlsx') {
            $this->downloadXlsx($response);
        } elseif ($action === 'generate-entries') {
            if (LibModelo111::generateEntries($this->result)) {
                Tools::log()->notice('record-updated-correctly');
            }
        }

        // errores que impiden generar el fichero del modelo 190
        if (!empty($this->result) && $this->result['period'] === 'ANNUAL' && $this->company->exists()) {
            $this->errors190 = Modelo190::validate($this->result, $this->company, $this->options190);
        }
    }

    /**
     * Devuelve las provincias del modelo 190.
     *
     * @return array
     */
    public function provincias190(): array
    {
        return Modelo190::PROVINCIAS;
    }

    /**
     * Devuelve los errores del modelo 190 de un perceptor del detalle.
     *
     * @param array $detail detalle del perceptor
     *
     * @return string[]
     */
    public function recipientErrors(array $detail): array
    {
        return Modelo190::validateRecipient($detail);
    }

    /**
     * Devuelve las subclaves de percepción del modelo 190 de cada clave.
     *
     * @return array
     */
    public function subclaves190(): array
    {
        return Modelo190::SUBCLAVES;
    }

    /**
     * Descarga el fichero de presentación del modelo 111 (trimestres) con el diseño de registro de la AEAT.
     *
     * @param mixed $response respuesta HTTP
     */
    protected function downloadFile(&$response): void
    {
        if (empty($this->result) || false === $this->loadCompany()) {
            return;
        }

        $errors = Modelo111File::validate($this->result, $this->company, $this->options111);
        if (!empty($errors)) {
            foreach ($errors as $error) {
                Tools::log()->warning($error);
            }
            return;
        }

        $content = Modelo111File::generate($this->result, $this->company, $this->options111);
        $year = (string)$this->result['year'];
        $filename = $this->company->cifnif . '_' . $year . '_' . Modelo111File::periodCode($this->result['period']) . '.111';
        $this->sendFile($response, $content, $filename);
    }

    /**
     * Descarga el fichero de presentación del modelo 190 (periodo anual) con el diseño de registro de la AEAT.
     * Si faltan datos de la declaración o de algún perceptor, no lo genera y muestra los errores.
     *
     * @param mixed $response respuesta HTTP
     */
    protected function downloadFile190(&$response): void
    {
        if (empty($this->result) || false === $this->loadCompany()) {
            return;
        }

        $errors = Modelo190::validate($this->result, $this->company, $this->options190);
        if (!empty($errors)) {
            Tools::log()->warning('m190-file-not-generated');
            foreach ($errors as $error) {
                Tools::log()->warning($error);
            }
            return;
        }

        $content = Modelo190File::generate($this->result, $this->company, $this->options190);
        $year = (string)$this->result['year'];
        $this->sendFile($response, $content, $this->company->cifnif . '_' . $year . '.190');
    }

    /**
     * Descarga las partidas de retenciones del periodo en Excel.
     *
     * @param mixed $response respuesta HTTP
     */
    protected function downloadXlsx(&$response): void
    {
        if (empty($this->result['entryLines'])) {
            Tools::log()->warning('no-data');
            return;
        }

        $year = (string)$this->result['year'];
        $periodo = $this->getPeriodNumber();
        $filename = 'modelo111_' . $year . '_' . $periodo . '.xlsx';

        $writer = new XLSXWriter();
        $writer->setAuthor((string)$this->company->nombre);
        $writer->setTitle('Modelo 111');

        $sheetName = 'Modelo 111';
        $headers = [
            Tools::trans('accounting-entry') => 'string',
            Tools::trans('subaccount') => 'string',
            Tools::trans('counterpart') => 'string',
            Tools::trans('concept') => 'string',
            Tools::trans('debit') => 'price',
            Tools::trans('credit') => 'price',
            Tools::trans('date') => 'string',
        ];
        $writer->writeSheetHeader($sheetName, $headers);

        foreach ($this->result['entryLines'] as $line) {
            $writer->writeSheetRow($sheetName, [
                $line->numero,
                $line->codsubcuenta,
                $line->codcontrapartida,
                strip_tags($line->concepto),
                $line->debe,
                $line->haber,
                $line->fecha,
            ]);
        }

        $writer->writeSheetRow($sheetName, [
            '',
            '',
            '',
            Tools::trans('total'),
            $this->result['totalDebe'],
            $this->result['totalHaber'],
            '',
        ]);

        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->setContent($writer->writeToString());
        $response->send();
        exit;
    }

    /**
     * Devuelve el trimestre (T1 a T4) de la fecha actual.
     *
     * @return string
     */
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

    /**
     * Devuelve el código de periodo del fichero de la AEAT (0A en el resumen anual).
     *
     * @return string
     */
    protected function getPeriodNumber(): string
    {
        return $this->result['period'] === 'ANNUAL' ? '0A' : Modelo111File::periodCode($this->result['period']);
    }

    /**
     * Carga la empresa del ejercicio del modelo.
     *
     * @return bool
     */
    protected function loadCompany(): bool
    {
        if ($this->company->load($this->result['exercise']->idempresa)) {
            return true;
        }

        Tools::log()->error('company-not-found');
        return false;
    }

    /**
     * Lee las opciones de los ficheros del formulario: las del modelo 111 (tipo de declaración, IBAN y
     * justificante de la declaración anterior) y las del modelo 190 (nº de la declaración, complementaria o
     * sustitutiva, nº de la anterior y persona de contacto, con los datos de la empresa por defecto).
     */
    protected function loadFileOptions(): void
    {
        $this->options111 = [
            'tipo' => (string)$this->request->input('tipo111', ''),
            'iban' => (string)$this->request->input('iban111', ''),
            'justificante' => preg_replace('/\D/', '', (string)$this->request->input('justificante111', '')),
        ];

        $year = empty($this->result) ? date('Y') : (string)$this->result['year'];
        if (!empty($this->result)) {
            $this->company->load($this->result['exercise']->idempresa);
        }
        $this->options190 = [
            'numero' => preg_replace('/\D/', '', (string)$this->request->input('numero190', '190' . $year . '000001')),
            'tipo' => (string)$this->request->input('tipo190', ''),
            'numeroanterior' => preg_replace('/\D/', '', (string)$this->request->input('numeroanterior190', '')),
            'contacto' => (string)$this->request->input('contacto190', (string)$this->company->administrador),
            'telefono' => (string)$this->request->input('telefono190', (string)$this->company->telefono1),
            'email' => (string)$this->request->input('email190', (string)$this->company->email),
        ];
    }

    /**
     * Genera el PDF con el resumen, los perceptores y las partidas del modelo.
     */
    protected function printAction(): void
    {
        if (empty($this->result)) {
            return;
        }

        $this->setTemplate(false);

        $exportManager = new ExportManager();
        $exportManager->newDoc('PDF', Tools::trans('model-111-190'));

        // resumen — las claves de las filas deben coincidir con los headers
        $recipients = Tools::trans('number-recipients');
        $base = Tools::trans('withholding-base');
        $withholdings = Tools::trans('withholdings-made');
        $previous = Tools::trans('previous-period-income');
        $total = Tools::trans('total-to-enter');

        $exportManager->addTablePage(
            [$recipients, $base, $withholdings, $previous, $total],
            [[
                $recipients => $this->result['numRecipients'],
                $base => Tools::money($this->result['baseRetenciones']),
                $withholdings => Tools::money($this->result['retencionesPracticadas']),
                $previous => Tools::money($this->result['ingresosPeriodoAnterior']),
                $total => Tools::money($this->result['totalIngresar']),
            ]]
        );

        // trimestres: casillas del modelo 111 por grupo; anual: perceptores del modelo 190
        if ($this->result['period'] !== 'ANNUAL') {
            $this->printGroups111($exportManager);
        } elseif (!empty($this->result['recipientDetails'])) {
            $this->printRecipients190($exportManager);
        }

        // asientos contables
        if (!empty($this->result['entryLines'])) {
            $entry = Tools::trans('accounting-entry');
            $subaccount = Tools::trans('subaccount');
            $counterpart = Tools::trans('counterpart');
            $concept = Tools::trans('concept');
            $debit = Tools::trans('debit');
            $credit = Tools::trans('credit');
            $date = Tools::trans('date');

            $entryRows = [];
            foreach ($this->result['entryLines'] as $line) {
                $entryRows[] = [
                    $entry => $line->numero,
                    $subaccount => $line->codsubcuenta,
                    $counterpart => $line->codcontrapartida,
                    $concept => $line->concepto,
                    $debit => Tools::money($line->debe),
                    $credit => Tools::money($line->haber),
                    $date => $line->fecha,
                ];
            }
            $entryRows[] = [
                $entry => '',
                $subaccount => '',
                $counterpart => '',
                $concept => Tools::trans('total'),
                $debit => Tools::money($this->result['totalDebe']),
                $credit => Tools::money($this->result['totalHaber']),
                $date => '',
            ];
            $exportManager->addTablePage(
                [$entry, $subaccount, $counterpart, $concept, $debit, $credit, $date],
                $entryRows
            );
        }

        $exportManager->show($this->response);
    }

    /**
     * Añade al PDF las casillas del modelo 111 de cada grupo (perceptores, percepciones y retenciones).
     *
     * @param ExportManager $exportManager documento PDF
     */
    protected function printGroups111(ExportManager $exportManager): void
    {
        $boxes = Tools::trans('m111-boxes');
        $group = Tools::trans('type');
        $recipients = Tools::trans('number-recipients');
        $base = Tools::trans('withholding-base');
        $withholdings = Tools::trans('withholdings-made');

        $rows = [];
        foreach (Modelo190::GROUPS_111 as $key => $codes) {
            $data = $this->result['groups111'][$key];
            $rows[] = [
                $boxes => implode('-', [$codes[0], $codes[2]]),
                $group => Tools::trans('m111-group-' . $key),
                $recipients => $data['perceptores'],
                $base => Tools::money($data['base']),
                $withholdings => Tools::money($data['retenciones']),
            ];
        }
        $exportManager->addTablePage([$boxes, $group, $recipients, $base, $withholdings], $rows);
    }

    /**
     * Añade al PDF los perceptores del modelo 190 con su NIF, provincia, clave y subclave.
     *
     * @param ExportManager $exportManager documento PDF
     */
    protected function printRecipients190(ExportManager $exportManager): void
    {
        $nif = Tools::trans('cifnif');
        $name = Tools::trans('name');
        $province = Tools::trans('province');
        $key = Tools::trans('m190-key');
        $base = Tools::trans('withholding-base');
        $withholdings = Tools::trans('withholdings-made');

        $rows = [];
        foreach ($this->result['recipientDetails'] as $detail) {
            $rows[] = [
                $nif => $detail['cifnif'],
                $name => $detail['nombre'],
                $province => $detail['codprovincia'],
                $key => trim($detail['clave'] . ' ' . $detail['subclave']),
                $base => Tools::money($detail['base']),
                $withholdings => Tools::money($detail['retencion']),
            ];
        }
        $exportManager->addTablePage([$nif, $name, $province, $key, $base, $withholdings], $rows);
    }

    /**
     * Guarda los datos del modelo 190 de un perceptor (identificación, clave, subclave y datos adicionales)
     * para el ejercicio del modelo.
     *
     * @return bool true si se han guardado
     */
    protected function saveRecipientAction(): bool
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return false;
        } elseif (false === $this->validateFormToken()) {
            return false;
        }

        $tipo = (string)$this->request->input('recipient_tipo', '');
        $codigo = (string)$this->request->input('recipient_codigo', '');
        $perceptor = Modelo190::getPerceptor($this->codejercicio, $tipo, $codigo, []);

        $fields = array_merge(
            ['cifnif', 'nombre', 'codprovincia', 'clave', 'subclave', 'nifrepresentante', 'ceutamelilla',
                'anionacimiento', 'situacionfamiliar', 'nifconyuge', 'discapacidad', 'contrato', 'hijo1', 'hijo2',
                'hijo3'],
            Modelo190Perceptor::AMOUNT_FIELDS,
            array_keys(Modelo190Perceptor::COUNT_FIELDS)
        );
        foreach ($fields as $field) {
            $value = $this->request->input('recipient_' . $field);
            if (null !== $value) {
                $perceptor->{$field} = in_array($field, Modelo190Perceptor::AMOUNT_FIELDS, true)
                    ? (float)str_replace(',', '.', $value)
                    : $value;
            }
        }
        $perceptor->movilidad = (bool)$this->request->input('recipient_movilidad', false);
        $perceptor->prestamovivienda = (bool)$this->request->input('recipient_prestamovivienda', false);

        if (false === $perceptor->save()) {
            Tools::log()->error('record-save-error');
            return false;
        }

        Tools::log()->notice('record-updated-correctly');
        return true;
    }

    /**
     * Envía un fichero de presentación en ISO-8859-1 y termina la petición.
     *
     * @param mixed $response respuesta HTTP
     * @param string $content contenido del fichero
     * @param string $filename nombre del fichero
     */
    protected function sendFile(&$response, string $content, string $filename): void
    {
        $response->headers->set('Content-Type', 'text/plain; charset=ISO-8859-1');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->setContent($content);
        $response->send();
        exit;
    }
}

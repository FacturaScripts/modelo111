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

use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\Modelo190Perceptor;
use RuntimeException;

/**
 * Genera el fichero de presentación del modelo 190 según el diseño de registro de la AEAT (Orden
 * EHA/3127/2009; diseño del ejercicio 2025 y, desde 2026, el campo 395-407 del proyecto de orden): un
 * registro tipo 1 (declarante) y un registro tipo 2 por perceptor, de 500 posiciones cada uno, separados por
 * CR+LF y en ISO-8859-1.
 *
 * Todas las percepciones son dinerarias, no derivadas de incapacidad laboral y del propio ejercicio (sin
 * atrasos ni reintegros de ejercicios anteriores). Antes de generarlo hay que comprobar los datos con
 * Modelo190::validate().
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo190File
{
    use Modelo111FileTrait;

    /** Longitud de los registros. */
    public const RECORD_LENGTH = 500;

    /**
     * Genera el contenido del fichero.
     *
     * @param array $result resultados de Modelo111::generate() del periodo anual
     * @param Empresa $empresa empresa declarante
     * @param array $options datos de la declaración: numero (13 dígitos), tipo ('', 'C' complementaria o 'S'
     *                       sustitutiva), numeroanterior, telefono, contacto y email de la persona de contacto
     *
     * @return string contenido en ISO-8859-1
     */
    public static function generate(array $result, Empresa $empresa, array $options): string
    {
        $year = (string)$result['year'];
        $records = [static::record1($result, $empresa, $options, $year)];
        foreach ($result['recipientDetails'] as $detail) {
            $records[] = static::record2($detail, $empresa, $year);
        }

        return static::toIso(implode("\r\n", $records) . "\r\n");
    }

    /**
     * Datos adicionales del registro tipo 2 (posiciones 153-254). Cada campo solo se rellena en las claves
     * que lo admiten; en el resto va a ceros (y el NIF del cónyuge, a blancos).
     *
     * @param Modelo190Perceptor $perceptor datos del perceptor
     *
     * @return string 102 posiciones
     */
    protected static function additionalData(Modelo190Perceptor $perceptor): string
    {
        $clave = (string)$perceptor->clave;
        $subclave = (string)$perceptor->subclave;
        $personal = Modelo190::hasPersonalData($clave, $subclave);
        $contract = Modelo190::hasContractData($clave);
        $value = static function (bool $applies, $data, int $length): string {
            return static::num($applies ? (int)$data : 0, $length);
        };
        $amount = static function (bool $applies, $data): string {
            return static::amount($applies ? (float)$data : 0.0, 13);
        };

        $data = $value($personal, $perceptor->anionacimiento, 4)                    // 153-156 año de nacimiento
            . $value($personal, $perceptor->situacionfamiliar, 1)                   // 157 situación familiar
            . ($personal && (int)$perceptor->situacionfamiliar === 2
                ? static::nif($perceptor->nifconyuge) : static::blank(9))  // 158-166 NIF cónyuge
            . $value($personal, $perceptor->discapacidad, 1)                        // 167 discapacidad
            . $value($contract, $perceptor->contrato, 1)                            // 168 contrato o relación
            . '0'                                                                   // 169 titular unidad convivencia
            . $value($contract, $perceptor->movilidad ? 1 : 0, 1)                   // 170 movilidad geográfica
            . $amount(Modelo190::hasReductions($clave, $subclave), $perceptor->reducciones)            // 171-183
            . $amount(Modelo190::hasDeductibleExpenses($clave, $subclave), $perceptor->gastosdeducibles) // 184-196
            . $amount($personal, $perceptor->pensioncompensatoria)                  // 197-209 pensión compensatoria
            . $amount($personal, $perceptor->anualidadesalimentos);                 // 210-222 anualidades alimentos

        // 223-250 descendientes y ascendientes: número total y computados por entero
        foreach (Modelo190Perceptor::COUNT_FIELDS as $field => $max) {
            $data .= $value($personal, $perceptor->{$field}, $max > 9 ? 2 : 1);
        }

        $data .= $value($personal, $perceptor->hijo1, 1)                            // 251 primer hijo
            . $value($personal, $perceptor->hijo2, 1)                               // 252 segundo hijo
            . $value($personal, $perceptor->hijo3, 1)                               // 253 tercer hijo
            . $value($personal, $perceptor->prestamovivienda ? 1 : 0, 1);           // 254 préstamo vivienda

        return $data;
    }

    /**
     * Comprueba que el registro tenga la longitud del diseño.
     *
     * @param string $record registro (UTF-8)
     *
     * @return string el mismo registro
     */
    protected static function checkLength(string $record): string
    {
        if (mb_strlen($record, 'UTF-8') !== static::RECORD_LENGTH) {
            throw new RuntimeException('Modelo 190: registro de ' . mb_strlen($record, 'UTF-8') . ' posiciones');
        }
        return $record;
    }

    /**
     * Registro tipo 1 (declarante).
     *
     * @param array $result resultados del modelo
     * @param Empresa $empresa empresa declarante
     * @param array $options datos de la declaración
     * @param string $year ejercicio (AAAA)
     *
     * @return string
     */
    protected static function record1(array $result, Empresa $empresa, array $options, string $year): string
    {
        $totalBase = 0.0;
        $totalRetenciones = 0.0;
        foreach ($result['recipientDetails'] as $detail) {
            $totalBase += round($detail['base'], 2);
            $totalRetenciones += round($detail['retencion'], 2);
        }

        $tipo = $options['tipo'] ?? '';
        $telefono = preg_replace('/\D/', '', (string)($options['telefono'] ?? ''));

        $record = '1190' . $year
            . static::nif($empresa->cifnif)                                    // 9-17 NIF declarante
            . static::alpha($empresa->nombre, 40)                              // 18-57 razón social
            . 'T'                                                                  // 58 tipo de soporte
            . static::num(substr($telefono, -9), 9)                            // 59-67 teléfono
            . static::alpha($options['contacto'] ?? '', 40)                    // 68-107 persona de contacto
            . static::num($options['numero'] ?? '', 13)                        // 108-120 nº declaración
            . ($tipo === 'C' ? 'C' : ' ')                                          // 121 complementaria
            . ($tipo === 'S' ? 'S' : ' ')                                          // 122 sustitutiva
            . static::num(in_array($tipo, ['C', 'S'], true) ? ($options['numeroanterior'] ?? '') : '', 13) // 123-135
            . static::num(count($result['recipientDetails']), 9)               // 136-144 nº de registros tipo 2
            . (round($totalBase, 2) < 0.0 ? 'N' : ' ')                             // 145 signo
            . static::amount($totalBase, 15)                                   // 146-160 total percepciones
            . static::amount($totalRetenciones, 15)                            // 161-175 total retenciones
            . static::free($options['email'] ?? '', 50)                        // 176-225 correo electrónico
            . static::blank(262)                                               // 226-487 blancos
            . static::blank(13);                                               // 488-500 sello electrónico

        return static::checkLength($record);
    }

    /**
     * Registro tipo 2 (perceptor).
     *
     * @param array $detail detalle del perceptor (con su Modelo190Perceptor en 'perceptor')
     * @param Empresa $empresa empresa declarante
     * @param string $year ejercicio (AAAA)
     *
     * @return string
     */
    protected static function record2(array $detail, Empresa $empresa, string $year): string
    {
        /** @var Modelo190Perceptor $perceptor */
        $perceptor = $detail['perceptor'];
        $clave = (string)$perceptor->clave;
        $subclave = Modelo190::needsSubclave($clave) ? (string)$perceptor->subclave : '00';
        $base = round($detail['base'], 2);

        $record = '2190' . $year
            . static::nif($empresa->cifnif)                                    // 9-17 NIF declarante
            . static::nif($perceptor->cifnif)                                  // 18-26 NIF perceptor
            . static::nif($perceptor->nifrepresentante)                        // 27-35 NIF representante
            . static::alpha($perceptor->nombre, 40)                            // 36-75 nombre
            . static::num($perceptor->codprovincia, 2)                         // 76-77 provincia
            . $clave                                                               // 78 clave
            . static::num($subclave, 2)                                        // 79-80 subclave
            . ($base < 0.0 ? 'N' : ' ')                                            // 81 signo
            . static::amount($base, 13)                                        // 82-94 percepción íntegra
            . static::amount($detail['retencion'], 13)                         // 95-107 retenciones
            . ' ' . str_repeat('0', 39)                                            // 108-147 en especie
            . '0000'                                                               // 148-151 ejercicio devengo
            . static::num($perceptor->ceutamelilla, 1)                         // 152 Ceuta/Melilla/La Palma
            . static::additionalData($perceptor)                                   // 153-254 datos adicionales
            . ' ' . str_repeat('0', 26)                                            // 255-281 IL dineraria
            . ' ' . str_repeat('0', 39)                                            // 282-321 IL en especie
            . '0'                                                                  // 322 complemento infancia
            . str_repeat('0', 65)                                                  // 323-387 desglose foral
            . '0'                                                                  // 388 empresas emergentes
            . '0'                                                                  // 389 fondos emprendimiento
            . '00000'                                                              // 390-394 prestaciones B.01
            . static::tail($year);                                                 // 395-500

        return static::checkLength($record);
    }

    /**
     * Posiciones 395-500: desde el ejercicio 2026, el importe de los rendimientos por la gestión de fondos
     * vinculados al emprendimiento (395-407, a ceros) y blancos; en ejercicios anteriores, blancos.
     *
     * @param string $year ejercicio (AAAA)
     *
     * @return string 106 posiciones
     */
    protected static function tail(string $year): string
    {
        if ((int)$year >= 2026) {
            return str_repeat('0', 13) . static::blank(93);
        }
        return static::blank(106);
    }
}

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

use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Empresa;
use RuntimeException;

/**
 * Genera el fichero de presentación del modelo 111 (autoliquidación trimestral) según el diseño de registro
 * de la AEAT «Ejercicios 2019 y siguientes» (dr111e16v18.xls, «vers 2» de julio de 2026): un único registro con la
 * cabecera `<T1110AAAAPP0000><AUX>`, la página 01 (1000 posiciones) y el cierre `</T1110AAAAPP0000>`, en
 * ISO-8859-1. La versión 2 solo renombra los campos 10 y 11 de la página 01 (posiciones 103-108) de
 * «Identificación» a «Devengo» (ejercicio y periodo); posiciones, longitudes y tipos no cambian.
 *
 * Las casillas se rellenan con los totales por grupo de Modelo111::generate() (groups111): trabajo (01-03),
 * actividades económicas (07-09), premios (13-15), aprovechamientos forestales (19-21) y derechos de imagen
 * (25-27). Las percepciones en especie (04-06, 10-12, 16-18 y 22-24) van a cero.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo111File
{
    use Modelo111FileTrait;

    /** Longitud de la página 01. */
    public const PAGE_LENGTH = 1000;

    /** Tipos de declaración: ingreso, domiciliación, ingreso a anotar en la cuenta corriente tributaria y negativa. */
    public const TYPES = ['I', 'U', 'G', 'N'];

    /**
     * Genera el contenido del fichero.
     *
     * @param array $result resultados de Modelo111::generate() de un trimestre
     * @param Empresa $empresa empresa declarante
     * @param array $options tipo (I, U, G o N; por defecto I si hay importe a ingresar y N si no), iban
     *                       (domiciliación) y justificante (13 dígitos de la declaración anterior, para las
     *                       complementarias, que son las que tienen ingresos anteriores)
     *
     * @return string contenido en ISO-8859-1
     */
    public static function generate(array $result, Empresa $empresa, array $options): string
    {
        $year = (string)$result['year'];
        $period = static::periodCode($result['period']);
        $id = '1110' . $year . $period . '0000>';

        $header = '<T' . $id . '<AUX>'
            . static::blank(70)          // reservado para la Administración
            . static::blank(4)           // versión del programa
            . static::blank(4)           // reservado para la Administración
            . static::blank(9)           // NIF de la empresa desarrolladora
            . static::blank(213)         // reservado para la Administración
            . '</AUX>';

        return static::toIso($header . static::page01($result, $empresa, $options, $year, $period) . '</T' . $id);
    }

    /**
     * Devuelve el código del periodo del fichero (1T a 4T).
     *
     * @param string $period periodo del modelo (T1 a T4)
     *
     * @return string
     */
    public static function periodCode(string $period): string
    {
        return match ($period) {
            'T2' => '2T',
            'T3' => '3T',
            'T4' => '4T',
            default => '1T',
        };
    }

    /**
     * Devuelve el tipo de declaración: el indicado o, por defecto, I (ingreso) si hay importe a ingresar y N
     * (negativa) si no.
     *
     * @param array $result resultados del modelo
     * @param array $options opciones del fichero
     *
     * @return string
     */
    public static function type(array $result, array $options): string
    {
        $type = strtoupper(trim((string)($options['tipo'] ?? '')));
        if (in_array($type, static::TYPES, true)) {
            return $type;
        }
        return round($result['totalIngresar'], 2) > 0.0 ? 'I' : 'N';
    }

    /**
     * Comprueba los datos antes de generar el fichero.
     *
     * @param array $result resultados de Modelo111::generate()
     * @param Empresa $empresa empresa declarante
     * @param array $options opciones del fichero (tipo, iban y justificante)
     *
     * @return string[] errores encontrados (vacío si se puede generar el fichero)
     */
    public static function validate(array $result, Empresa $empresa, array $options): array
    {
        $errors = [];
        if (false === in_array($result['period'] ?? '', ['T1', 'T2', 'T3', 'T4'], true)) {
            $errors[] = Tools::trans('file-111-not-for-model-190');
            return $errors;
        }

        $nif = Modelo190::normalizeNif($empresa->cifnif);
        if ($nif === '' || strlen($nif) > 9) {
            $errors[] = Tools::trans('m190-invalid-company-nif', ['%nif%' => $empresa->cifnif]);
        }

        foreach ($result['groups111'] as $group) {
            if (round($group['base'], 2) < 0.0 || round($group['retenciones'], 2) < 0.0) {
                $errors[] = Tools::trans('m111-negative-box');
                break;
            }
        }
        if (round($result['totalIngresar'], 2) < 0.0) {
            $errors[] = Tools::trans('m111-negative-result');
        }

        $type = static::type($result, $options);
        if ($type === 'U' && strlen(static::iban($options)) < 15) {
            $errors[] = Tools::trans('m111-missing-iban');
        }
        if ($type === 'N' && round($result['totalIngresar'], 2) > 0.0) {
            $errors[] = Tools::trans('m111-negative-type-with-amount');
        }
        if (round($result['ingresosPeriodoAnterior'], 2) != 0.0
            && 1 !== preg_match('/^\d{13}$/', (string)($options['justificante'] ?? ''))) {
            $errors[] = Tools::trans('m111-missing-previous-receipt');
        }

        return $errors;
    }

    /**
     * Comprueba que la página tenga la longitud del diseño.
     *
     * @param string $page página (UTF-8)
     *
     * @return string la misma página
     */
    protected static function checkLength(string $page): string
    {
        if (mb_strlen($page, 'UTF-8') !== static::PAGE_LENGTH) {
            throw new RuntimeException('Modelo 111: página de ' . mb_strlen($page, 'UTF-8') . ' posiciones');
        }
        return $page;
    }

    /**
     * Casillas de un grupo: nº de perceptores (8), importe de las percepciones (17) y de las retenciones (17).
     *
     * @param array|null $group totales del grupo (perceptores, base y retenciones) o null si va a cero
     *
     * @return string 42 posiciones
     */
    protected static function group(?array $group): string
    {
        return static::num($group['perceptores'] ?? 0, 8)
            . static::amount($group['base'] ?? 0.0, 17)
            . static::amount($group['retenciones'] ?? 0.0, 17);
    }

    /**
     * Devuelve el IBAN de las opciones, en mayúsculas y sin espacios.
     *
     * @param array $options opciones del fichero
     *
     * @return string
     */
    protected static function iban(array $options): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($options['iban'] ?? '')));
    }

    /**
     * Página 01 de la declaración.
     *
     * @param array $result resultados del modelo
     * @param Empresa $empresa empresa declarante
     * @param array $options opciones del fichero
     * @param string $year ejercicio (AAAA)
     * @param string $period periodo (1T a 4T)
     *
     * @return string
     */
    protected static function page01(array $result, Empresa $empresa, array $options, string $year, string $period): string
    {
        $groups = $result['groups111'];
        $suma = 0.0;
        foreach ($groups as $group) {
            $suma += round($group['retenciones'], 2);
        }
        $complementaria = round($result['ingresosPeriodoAnterior'], 2) != 0.0;

        $page = '<T11101000>'                                                      // 1-11 inicio de la página
            . ' '                                                                  // 12 página complementaria
            . static::type($result, $options)                                      // 13 tipo de declaración
            . static::alpha(Modelo190::normalizeNif($empresa->cifnif), 9)      // 14-22 NIF
            . static::alpha($empresa->nombre, 60)                              // 23-82 denominación o apellidos
            . static::blank(20)                                                // 83-102 nombre
            . $year . $period                                                      // 103-108 devengo
            . static::group($groups[Modelo190::GROUP_WORK] ?? null)                // 01-03 trabajo dinerario
            . static::group(null)                                                  // 04-06 trabajo en especie
            . static::group($groups[Modelo190::GROUP_ACTIVITIES] ?? null)          // 07-09 actividades dinerarias
            . static::group(null)                                                  // 10-12 actividades en especie
            . static::group($groups[Modelo190::GROUP_PRIZES] ?? null)              // 13-15 premios dinerarios
            . static::group(null)                                                  // 16-18 premios en especie
            . static::group($groups[Modelo190::GROUP_FOREST] ?? null)              // 19-21 forestales dinerarias
            . static::group(null)                                                  // 22-24 forestales en especie
            . static::group($groups[Modelo190::GROUP_IMAGE] ?? null)               // 25-27 derechos de imagen
            . static::amount($suma, 17)                                        // 28 suma de retenciones
            . static::signedAmount($result['ingresosPeriodoAnterior'], 17)     // 29 declaraciones anteriores
            . static::amount($suma - $result['ingresosPeriodoAnterior'], 17)   // 30 resultado a ingresar
            . ($complementaria ? 'X' : ' ')                                        // 538 complementaria
            . static::alpha($complementaria ? ($options['justificante'] ?? '') : '', 13) // 539-551 justificante
            . ' '                                                                  // 552 reservado
            . static::alpha(static::iban($options), 34)                        // 553-586 IBAN
            . static::blank(389)                                               // 587-975 reservado
            . static::blank(13)                                                // 976-988 sello electrónico
            . '</T11101000>';                                                      // 989-1000 fin de la página

        return static::checkLength($page);
    }
}

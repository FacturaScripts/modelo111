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

/**
 * Formatea los campos de los ficheros de presentación de la AEAT (diseños de registro): alfanuméricos en
 * mayúsculas, sin vocales acentuadas ni caracteres especiales, alineados a la izquierda y rellenos de
 * blancos; numéricos alineados a la derecha y rellenos de ceros; importes en céntimos y NIF alineados a la
 * derecha y rellenos de ceros. Los textos se devuelven en UTF-8: el fichero se convierte a ISO-8859-1 al
 * final con toIso().
 *
 * Lo comparten los dos ficheros del plugin, Modelo111File y Modelo190File. Es un trait y no una clase para que
 * no se despliegue en Dinamic/Lib, que es común a todos los plugins.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
trait Modelo111FileTrait
{
    /**
     * Campo alfanumérico: mayúsculas, sin vocales acentuadas (se conservan la Ñ y la Ç), sin puntos y con el
     * resto de caracteres especiales cambiados por blancos, alineado a la izquierda y relleno de blancos.
     *
     * @param string|null $value valor
     * @param int $length longitud del campo
     *
     * @return string
     */
    public static function alpha(?string $value, int $length): string
    {
        $text = mb_strtoupper(trim((string)$value), 'UTF-8');
        $text = strtr($text, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'ª' => 'A', 'º' => 'O', '.' => '',
        ]);
        $text = preg_replace('/[^A-Z0-9ÑÇ ]/u', ' ', $text);
        $text = trim(preg_replace('/ {2,}/', ' ', $text));
        return static::pad($text, $length);
    }

    /**
     * Importe sin signo en céntimos, alineado a la derecha y relleno de ceros (parte entera y dos decimales).
     *
     * @param float $value importe (se toma su valor absoluto)
     * @param int $length longitud del campo
     *
     * @return string
     */
    public static function amount(float $value, int $length): string
    {
        $cents = (int)round(abs($value) * 100);
        return static::num($cents, $length);
    }

    /**
     * Blancos.
     *
     * @param int $length longitud del campo
     *
     * @return string
     */
    public static function blank(int $length): string
    {
        return str_repeat(' ', $length);
    }

    /**
     * Texto libre (como un correo electrónico): sin caracteres fuera de ASCII imprimible, alineado a la
     * izquierda y relleno de blancos.
     *
     * @param string|null $value valor
     * @param int $length longitud del campo
     *
     * @return string
     */
    public static function free(?string $value, int $length): string
    {
        $text = preg_replace('/[^\x20-\x7E]/', '', trim((string)$value));
        return static::pad($text, $length);
    }

    /**
     * NIF alineado a la derecha y relleno de ceros por la izquierda (diseños de declaraciones informativas).
     * Sin NIF devuelve blancos.
     *
     * @param string|null $nif NIF
     * @param int $length longitud del campo
     *
     * @return string
     */
    public static function nif(?string $nif, int $length = 9): string
    {
        $value = Modelo190::normalizeNif($nif);
        if ($value === '') {
            return static::blank($length);
        }
        return str_pad(substr($value, -$length), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Campo numérico sin signo: solo cifras, alineado a la derecha y relleno de ceros.
     *
     * @param int|string|null $value valor
     * @param int $length longitud del campo
     *
     * @return string
     */
    public static function num($value, int $length): string
    {
        $digits = preg_replace('/\D/', '', (string)$value);
        return str_pad(substr($digits, -$length), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Importe con signo en céntimos: los negativos llevan una N en la primera posición del campo y el resto
     * alineado a la derecha y relleno de ceros (campos de tipo N, como la casilla 29 del modelo 111).
     *
     * @param float $value importe
     * @param int $length longitud del campo
     *
     * @return string
     */
    public static function signedAmount(float $value, int $length): string
    {
        if (round($value, 2) < 0.0) {
            return 'N' . static::amount($value, $length - 1);
        }
        return static::amount($value, $length);
    }

    /**
     * Convierte el contenido del fichero de UTF-8 a ISO-8859-1, la codificación de los ficheros de la AEAT.
     *
     * @param string $content contenido en UTF-8
     *
     * @return string
     */
    public static function toIso(string $content): string
    {
        return mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8');
    }

    /**
     * Recorta o rellena con blancos por la derecha un texto UTF-8 hasta la longitud indicada (en caracteres).
     *
     * @param string $text texto
     * @param int $length longitud
     *
     * @return string
     */
    protected static function pad(string $text, int $length): string
    {
        $text = mb_substr($text, 0, $length, 'UTF-8');
        return $text . str_repeat(' ', $length - mb_strlen($text, 'UTF-8'));
    }
}

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
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\Modelo190Perceptor;

/**
 * Catálogos y reglas del modelo 190 (resumen anual de retenciones e ingresos a cuenta del IRPF) según el
 * diseño de registro de la AEAT (Orden EHA/3127/2009, diseño del ejercicio 2025 y proyecto de 2026):
 * claves y subclaves de percepción, códigos de provincia, casillas del modelo 111 que corresponden a cada
 * clave, qué datos adicionales admite cada clave y la validación de los datos antes de generar el fichero.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo190
{
    /** Claves de percepción del registro tipo 2 (posición 78). */
    public const CLAVES = [
        'A' => 'Rendimientos del trabajo: empleados por cuenta ajena',
        'B' => 'Pensionistas y perceptores de haberes pasivos',
        'C' => 'Prestaciones o subsidios por desempleo',
        'D' => 'Desempleo en pago único (solo reintegros)',
        'E' => 'Consejeros y administradores',
        'F' => 'Cursos, conferencias, seminarios y obras literarias, artísticas o científicas',
        'G' => 'Actividades profesionales',
        'H' => 'Actividades agrícolas, ganaderas, forestales y en estimación objetiva',
        'I' => 'Rendimientos del art. 75.2.b) RIRPF de una actividad económica',
        'J' => 'Imputación de rentas por la cesión de derechos de imagen',
        'K' => 'Premios y ganancias de aprovechamientos forestales',
        'L' => 'Rentas exentas y dietas exceptuadas de gravamen',
    ];

    /** Grupo de casillas del modelo 111 de las actividades económicas (07-09). */
    public const GROUP_ACTIVITIES = 'actividades';

    /** Grupo de casillas del modelo 111 de las ganancias por aprovechamientos forestales (19-21). */
    public const GROUP_FOREST = 'forestales';

    /** Grupo de casillas del modelo 111 de la cesión de derechos de imagen (25-27). */
    public const GROUP_IMAGE = 'imagen';

    /** Grupo de casillas del modelo 111 de los premios (13-15). */
    public const GROUP_PRIZES = 'premios';

    /** Grupo de casillas del modelo 111 de los rendimientos del trabajo (01-03). */
    public const GROUP_WORK = 'trabajo';

    /** Casillas del modelo 111 (perceptores, percepciones y retenciones dinerarias) de cada grupo. */
    public const GROUPS_111 = [
        self::GROUP_WORK => ['01', '02', '03'],
        self::GROUP_ACTIVITIES => ['07', '08', '09'],
        self::GROUP_PRIZES => ['13', '14', '15'],
        self::GROUP_FOREST => ['19', '20', '21'],
        self::GROUP_IMAGE => ['25', '26', '27'],
    ];

    /** Códigos de provincia del registro tipo 2 (posiciones 76-77). */
    public const PROVINCIAS = [
        '01' => 'Araba/Álava', '02' => 'Albacete', '03' => 'Alicante/Alacant', '04' => 'Almería',
        '05' => 'Ávila', '06' => 'Badajoz', '07' => 'Illes Balears', '08' => 'Barcelona', '09' => 'Burgos',
        '10' => 'Cáceres', '11' => 'Cádiz', '12' => 'Castellón/Castelló', '13' => 'Ciudad Real',
        '14' => 'Córdoba', '15' => 'A Coruña', '16' => 'Cuenca', '17' => 'Girona', '18' => 'Granada',
        '19' => 'Guadalajara', '20' => 'Gipuzkoa', '21' => 'Huelva', '22' => 'Huesca', '23' => 'Jaén',
        '24' => 'León', '25' => 'Lleida', '26' => 'La Rioja', '27' => 'Lugo', '28' => 'Madrid',
        '29' => 'Málaga', '30' => 'Murcia', '31' => 'Navarra', '32' => 'Ourense', '33' => 'Asturias',
        '34' => 'Palencia', '35' => 'Las Palmas', '36' => 'Pontevedra', '37' => 'Salamanca',
        '38' => 'Santa Cruz de Tenerife (sin la isla de La Palma)', '39' => 'Cantabria', '40' => 'Segovia',
        '41' => 'Sevilla', '42' => 'Soria', '43' => 'Tarragona', '44' => 'Teruel', '45' => 'Toledo',
        '46' => 'Valencia/València', '47' => 'Valladolid', '48' => 'Bizkaia', '49' => 'Zamora',
        '50' => 'Zaragoza', '51' => 'Ceuta', '52' => 'Melilla', '53' => 'Isla de La Palma',
        '98' => 'Residente en el extranjero (arts. 8.2 y 10 LIRPF)',
    ];

    /** Subclaves de percepción de cada clave (posiciones 79-80). Las claves A, D y J no llevan subclave. */
    public const SUBCLAVES = [
        'B' => [
            '01' => 'Pensiones y haberes pasivos de la Seguridad Social y Clases Pasivas',
            '02' => 'Procedimiento especial de retención del art. 89.A RIRPF',
            '03' => 'Mutualidades generales obligatorias de funcionarios y similares',
            '04' => 'Planes de pensiones y otros sistemas de previsión social',
            '99' => 'Resto de prestaciones',
        ],
        'C' => [
            '01' => 'Prestaciones por desempleo', '02' => 'Desempleo por ERE', '03' => 'Desempleo por ERTE',
            '04' => 'Cese de actividad de autónomos', '05' => 'Subsidios por desempleo',
            '06' => 'Renta activa de inserción', '07' => 'Otras prestaciones contributivas',
            '08' => 'Otras prestaciones no contributivas', '09' => 'Más de uno de los tipos anteriores',
        ],
        'E' => [
            '01' => 'Asimilados al régimen general de la Seguridad Social',
            '02' => 'Asimilados al régimen general y entidad con INCN < 100.000 €',
            '03' => 'RETA y entidad con INCN < 100.000 €',
            '04' => 'Resto de consejeros y administradores',
        ],
        'F' => [
            '01' => 'Premios literarios, científicos o artísticos no exentos',
            '02' => 'Cursos, conferencias, coloquios y seminarios',
            '03' => 'Obras literarias, artísticas o científicas (tipo general)',
            '04' => 'Obras literarias, artísticas o científicas (tipo reducido)',
            '05' => 'Propiedad intelectual (tipo general)', '06' => 'Propiedad intelectual (tipo reducido)',
            '07' => 'Anticipos por cesión de derechos de autor de varios años',
        ],
        'G' => [
            '01' => 'Tipo general (15 %)',
            '02' => 'Recaudadores municipales, mediadores de seguros y delegados de Loterías (7 %)',
            '03' => 'Inicio de actividad profesional (7 %)',
            '04' => 'Letra d) del art. 95.1 RIRPF (7 %)',
            '05' => 'Propiedad intelectual (tipo general)', '06' => 'Propiedad intelectual (tipo reducido)',
            '07' => 'Anticipos por cesión de derechos de autor de varios años',
            '08' => 'Cesión del derecho de imagen',
        ],
        'H' => [
            '01' => 'Agrícolas o ganaderas (2 %)', '02' => 'Engorde de porcino y avicultura (1 %)',
            '03' => 'Forestales (2 %)', '04' => 'Estimación objetiva del art. 95.6.2º RIRPF (1 %)',
        ],
        'I' => [
            '01' => 'Cesión del derecho de imagen', '02' => 'Propiedad intelectual (tipo general)',
            '03' => 'Resto de conceptos del art. 75.2.b) RIRPF',
        ],
        'K' => [
            '01' => 'Premios de juego sometidos a retención', '02' => 'Aprovechamientos forestales en montes públicos',
            '03' => 'Premios de concursos publicitarios sometidos a retención',
            '04' => 'Premios de juego no sometidos a retención',
            '05' => 'Premios de concursos publicitarios no sometidos a retención',
        ],
        'L' => [
            '01' => '01', '02' => '02', '03' => '03', '04' => '04', '05' => '05', '06' => '06', '07' => '07',
            '08' => '08', '09' => '09', '10' => '10', '11' => '11', '12' => '12', '13' => '13', '14' => '14',
            '15' => '15', '16' => '16', '17' => '17', '18' => '18', '19' => '19', '20' => '20', '21' => '21',
            '22' => '22', '23' => '23', '24' => '24', '25' => '25', '26' => '26', '27' => '27', '28' => '28',
            '29' => '29', '30' => '30', '31' => '31', '99' => '99',
        ],
    ];

    /** Tipo de perceptor identificado por una subcuenta (asientos de nóminas u otros asientos manuales). */
    public const TYPE_SUBACCOUNT = 'subcuenta';

    /** Tipo de perceptor identificado por su proveedor (facturas de compra con retención). */
    public const TYPE_SUPPLIER = 'proveedor';

    /**
     * Devuelve la clave y la subclave que se proponen para un profesional según el porcentaje de retención
     * de sus facturas: 15 % o 19 % tipo general (G.01), 7 % inicio de actividad (G.03), 2 % agrícolas o
     * ganaderas (H.01) y 1 % estimación objetiva (H.04). El usuario puede cambiarla en los datos del perceptor.
     *
     * @param float $percentage porcentaje de retención
     *
     * @return array [clave, subclave]
     */
    public static function claveFromRetention(float $percentage): array
    {
        return match (true) {
            abs($percentage - 7.0) < 0.01 => ['G', '03'],
            abs($percentage - 2.0) < 0.01 => ['H', '01'],
            abs($percentage - 1.0) < 0.01 => ['H', '04'],
            default => ['G', '01'],
        };
    }

    /**
     * Devuelve los datos del perceptor guardados para el ejercicio o, si no hay, unos datos propuestos: los
     * del último ejercicio anterior en el que se guardaron (sin los importes, que son de cada año) o, en su
     * defecto, los recibidos en $defaults. El modelo devuelto no se guarda.
     *
     * @param string $codejercicio código del ejercicio
     * @param string $tipo tipo de perceptor (proveedor o subcuenta)
     * @param string $codigo código del proveedor o de la subcuenta
     * @param array $defaults valores propuestos (cifnif, nombre, codprovincia, clave, subclave...)
     *
     * @return Modelo190Perceptor
     */
    public static function getPerceptor(string $codejercicio, string $tipo, string $codigo, array $defaults): Modelo190Perceptor
    {
        $perceptor = new Modelo190Perceptor();
        $where = [
            Where::eq('codejercicio', $codejercicio),
            Where::eq('tipo', $tipo),
            Where::eq('codigo', $codigo),
        ];
        if ($perceptor->loadWhere($where)) {
            return $perceptor;
        }

        // datos de un ejercicio anterior
        $previous = new Modelo190Perceptor();
        $wherePrevious = [
            Where::lt('codejercicio', $codejercicio),
            Where::eq('tipo', $tipo),
            Where::eq('codigo', $codigo),
        ];
        if ($previous->loadWhere($wherePrevious, ['codejercicio' => 'DESC'])) {
            $perceptor->loadFromData($previous->toArray(), ['id']);
            $perceptor->resetAmounts();
        } else {
            foreach ($defaults as $field => $value) {
                $perceptor->{$field} = $value;
            }
        }

        $perceptor->codejercicio = $codejercicio;
        $perceptor->tipo = $tipo;
        $perceptor->codigo = $codigo;
        return $perceptor;
    }

    /**
     * Devuelve el grupo de casillas del modelo 111 al que van las percepciones de una clave: trabajo
     * (claves A a F, casillas 01-03), actividades económicas (G, H e I, casillas 07-09), premios (K.01, K.03,
     * K.04 y K.05, casillas 13-15), aprovechamientos forestales (K.02, casillas 19-21) y cesión de derechos de
     * imagen (J, casillas 25-27). Las rentas exentas (L) no van al modelo 111.
     *
     * @param string $clave clave de percepción
     * @param string $subclave subclave de percepción
     *
     * @return string grupo o cadena vacía si no va al modelo 111
     */
    public static function group111(string $clave, string $subclave): string
    {
        return match ($clave) {
            'A', 'B', 'C', 'D', 'E', 'F' => static::GROUP_WORK,
            'G', 'H', 'I' => static::GROUP_ACTIVITIES,
            'J' => static::GROUP_IMAGE,
            'K' => $subclave === '02' ? static::GROUP_FOREST : static::GROUP_PRIZES,
            default => '',
        };
    }

    /**
     * Indica si la clave y subclave admiten los datos de contrato y movilidad geográfica (solo clave A).
     *
     * @param string $clave clave de percepción
     *
     * @return bool
     */
    public static function hasContractData(string $clave): bool
    {
        return $clave === 'A';
    }

    /**
     * Indica si la clave y subclave admiten gastos deducibles (posiciones 184-196): claves A, B (01, 03, 04
     * y 99), C y E (01 y 02).
     *
     * @param string $clave clave de percepción
     * @param string $subclave subclave de percepción
     *
     * @return bool
     */
    public static function hasDeductibleExpenses(string $clave, string $subclave): bool
    {
        return static::hasPersonalData($clave, $subclave)
            || ($clave === 'E' && in_array($subclave, ['01', '02'], true));
    }

    /**
     * Indica si la clave y subclave admiten los datos personales del perceptor (año de nacimiento, situación
     * familiar, discapacidad, descendientes, ascendientes, etc.): claves A, B (01, 03, 04 y 99) y C.
     *
     * @param string $clave clave de percepción
     * @param string $subclave subclave de percepción
     *
     * @return bool
     */
    public static function hasPersonalData(string $clave, string $subclave): bool
    {
        return $clave === 'A' || $clave === 'C'
            || ($clave === 'B' && in_array($subclave, ['01', '03', '04', '99'], true));
    }

    /**
     * Indica si la clave y subclave admiten reducciones (posiciones 171-183): claves A, B (01, 03, 04 y 99),
     * C, E, F (01 a 06), G (01 a 06 y 08), H e I.
     *
     * @param string $clave clave de percepción
     * @param string $subclave subclave de percepción
     *
     * @return bool
     */
    public static function hasReductions(string $clave, string $subclave): bool
    {
        return static::hasPersonalData($clave, $subclave)
            || in_array($clave, ['E', 'H', 'I'], true)
            || ($clave === 'F' && in_array($subclave, ['01', '02', '03', '04', '05', '06'], true))
            || ($clave === 'G' && in_array($subclave, ['01', '02', '03', '04', '05', '06', '08'], true));
    }

    /**
     * Indica si la clave necesita subclave: B, C, E, F, G, H, I, K y L.
     *
     * @param string $clave clave de percepción
     *
     * @return bool
     */
    public static function needsSubclave(string $clave): bool
    {
        return isset(static::SUBCLAVES[$clave]);
    }

    /**
     * Normaliza un NIF: mayúsculas, sin espacios, guiones ni puntos y sin el prefijo de país «ES».
     *
     * @param string|null $nif NIF
     *
     * @return string
     */
    public static function normalizeNif(?string $nif): string
    {
        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$nif));
        if (strlen($value) === 11 && str_starts_with($value, 'ES')) {
            $value = substr($value, 2);
        }
        return $value;
    }

    /**
     * Devuelve el código de provincia del modelo 190 a partir de un código postal español: sus dos primeras
     * cifras, salvo la isla de La Palma (códigos 387xx), que tiene el código 53. Fuera de España devuelve una
     * cadena vacía: el usuario debe indicar 98 si el perceptor es contribuyente del IRPF residente en el
     * extranjero (los no residentes no van al modelo 190, sino al 296).
     *
     * @param string|null $codpostal código postal
     * @param string|null $codpais código del país
     *
     * @return string
     */
    public static function provinceFromPostalCode(?string $codpostal, ?string $codpais): string
    {
        if (!empty($codpais) && $codpais !== 'ESP') {
            return '';
        }

        $cp = preg_replace('/\D/', '', (string)$codpostal);
        if (strlen($cp) !== 5) {
            return '';
        }
        if (str_starts_with($cp, '387')) {
            return '53';
        }

        $code = substr($cp, 0, 2);
        return isset(static::PROVINCIAS[$code]) && $code !== '98' ? $code : '';
    }

    /**
     * Comprueba los datos del modelo 190 antes de generar el fichero: datos del declarante y de la declaración
     * y, de cada perceptor, NIF, nombre, provincia, clave, subclave, importes y datos personales.
     *
     * @param array $result resultados de Modelo111::generate() del periodo anual
     * @param Empresa $empresa empresa declarante
     * @param array $options datos de la declaración: numero, tipo ('', 'C' o 'S') y numeroanterior
     *
     * @return string[] errores encontrados (vacío si se puede generar el fichero)
     */
    public static function validate(array $result, Empresa $empresa, array $options): array
    {
        $errors = [];
        if (($result['period'] ?? '') !== 'ANNUAL') {
            $errors[] = Tools::trans('m190-only-annual');
            return $errors;
        }

        // declarante y declaración
        $nif = static::normalizeNif($empresa->cifnif);
        if ($nif === '' || strlen($nif) > 9) {
            $errors[] = Tools::trans('m190-invalid-company-nif', ['%nif%' => $empresa->cifnif]);
        }
        if (1 !== preg_match('/^190\d{10}$/', (string)($options['numero'] ?? ''))) {
            $errors[] = Tools::trans('m190-invalid-declaration-number');
        }
        $tipo = $options['tipo'] ?? '';
        if (in_array($tipo, ['C', 'S'], true) && 1 !== preg_match('/^\d{13}$/', (string)($options['numeroanterior'] ?? ''))) {
            $errors[] = Tools::trans('m190-invalid-previous-number');
        }

        if (empty($result['recipientDetails'])) {
            $errors[] = Tools::trans('m190-no-recipients');
            return $errors;
        }

        foreach ($result['recipientDetails'] as $detail) {
            foreach (static::validateRecipient($detail) as $error) {
                $errors[] = $detail['nombre'] . ' (' . $detail['codigo'] . '): ' . $error;
            }
        }

        return $errors;
    }

    /**
     * Comprueba los datos de un perceptor del detalle del modelo.
     *
     * @param array $detail detalle del perceptor (con su Modelo190Perceptor en 'perceptor')
     *
     * @return string[] errores del perceptor
     */
    public static function validateRecipient(array $detail): array
    {
        /** @var Modelo190Perceptor $perceptor */
        $perceptor = $detail['perceptor'];
        $errors = [];

        $nif = static::normalizeNif($perceptor->cifnif);
        if ($nif === '' || strlen($nif) > 9) {
            $errors[] = Tools::trans('m190-invalid-nif');
        }
        if (empty(trim((string)$perceptor->nombre))) {
            $errors[] = Tools::trans('m190-missing-name');
        }
        if (false === isset(static::PROVINCIAS[(string)$perceptor->codprovincia])) {
            $errors[] = Tools::trans('m190-invalid-province');
        }

        $clave = (string)$perceptor->clave;
        $subclave = (string)$perceptor->subclave;
        if (false === isset(static::CLAVES[$clave])) {
            $errors[] = Tools::trans('m190-invalid-key');
        } elseif (static::needsSubclave($clave) && false === isset(static::SUBCLAVES[$clave][$subclave])) {
            $errors[] = Tools::trans('m190-invalid-subkey', ['%clave%' => $clave]);
        }

        // las retenciones no admiten signo y el signo de la percepción solo es para reintegros de ejercicios
        // anteriores, que el plugin no calcula
        if (round($detail['retencion'], 2) < 0.0) {
            $errors[] = Tools::trans('m190-negative-withholding');
        }
        if (round($detail['base'], 2) < 0.0) {
            $errors[] = Tools::trans('m190-negative-base');
        }

        if (static::hasPersonalData($clave, $subclave)) {
            $year = (int)$perceptor->anionacimiento;
            if ($year < 1900 || $year > (int)date('Y')) {
                $errors[] = Tools::trans('m190-invalid-birth-year');
            }
            if ((int)$perceptor->situacionfamiliar === 2 && '' === static::normalizeNif($perceptor->nifconyuge)) {
                $errors[] = Tools::trans('m190-missing-spouse-nif');
            }
        }

        return $errors;
    }
}

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

namespace FacturaScripts\Plugins\Modelo111\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\Modelo111\Lib\Modelo190;

/**
 * Datos de un perceptor del modelo 190 en un ejercicio: identificación (NIF, nombre y provincia), clave y
 * subclave de percepción y datos adicionales del registro tipo 2 (posiciones 152-254), que no se pueden
 * obtener de la contabilidad.
 *
 * El perceptor se identifica por su origen: un proveedor (facturas de compra con retención) o una subcuenta
 * (contrapartida de las retenciones de los asientos de nóminas u otros asientos manuales).
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
class Modelo190Perceptor extends ModelClass
{
    use ModelTrait;

    /** Campos de importes, que son de cada ejercicio y no se copian de un ejercicio a otro. */
    public const AMOUNT_FIELDS = ['reducciones', 'gastosdeducibles', 'pensioncompensatoria', 'anualidadesalimentos'];

    /** Campos de número de descendientes y ascendientes (una posición o dos, según el campo). */
    public const COUNT_FIELDS = [
        'hijosmenores3' => 9, 'hijosmenores3entero' => 9, 'hijosresto' => 99, 'hijosrestoentero' => 99,
        'descdisc33' => 99, 'descdisc33entero' => 99, 'descdiscmov' => 99, 'descdiscmoventero' => 99,
        'descdisc65' => 99, 'descdisc65entero' => 99,
        'ascmenor75' => 9, 'ascmenor75entero' => 9, 'ascmayor75' => 9, 'ascmayor75entero' => 9,
        'ascdisc33' => 9, 'ascdisc33entero' => 9, 'ascdiscmov' => 9, 'ascdiscmoventero' => 9,
        'ascdisc65' => 9, 'ascdisc65entero' => 9,
    ];

    /** @var int|null año de nacimiento (claves A, B y C) */
    public $anionacimiento;

    /** @var float anualidades por alimentos a favor de los hijos por decisión judicial */
    public $anualidadesalimentos;

    /** @var int ascendientes con discapacidad entre el 33 % y el 65 %: número total */
    public $ascdisc33;

    /** @var int ascendientes con discapacidad entre el 33 % y el 65 %: computados por entero */
    public $ascdisc33entero;

    /** @var int ascendientes con discapacidad igual o superior al 65 %: número total */
    public $ascdisc65;

    /** @var int ascendientes con discapacidad igual o superior al 65 %: computados por entero */
    public $ascdisc65entero;

    /** @var int ascendientes con discapacidad y movilidad reducida: número total */
    public $ascdiscmov;

    /** @var int ascendientes con discapacidad y movilidad reducida: computados por entero */
    public $ascdiscmoventero;

    /** @var int ascendientes de 75 años o más: número total */
    public $ascmayor75;

    /** @var int ascendientes de 75 años o más: computados por entero */
    public $ascmayor75entero;

    /** @var int ascendientes menores de 75 años: número total */
    public $ascmenor75;

    /** @var int ascendientes menores de 75 años: computados por entero */
    public $ascmenor75entero;

    /** @var int Ceuta o Melilla (1), isla de La Palma (2) o ninguno (0) */
    public $ceutamelilla;

    /** @var string|null NIF del perceptor */
    public $cifnif;

    /** @var string clave de percepción (A-L) */
    public $clave;

    /** @var string código del ejercicio */
    public $codejercicio;

    /** @var string código del proveedor o de la subcuenta del perceptor */
    public $codigo;

    /** @var string|null código de provincia del modelo 190 (01-53 o 98) */
    public $codprovincia;

    /** @var int contrato o relación (clave A): 1 general, 2 inferior a un año, 3 especial, 4 peonadas */
    public $contrato;

    /** @var int descendientes con discapacidad entre el 33 % y el 65 %: número total */
    public $descdisc33;

    /** @var int descendientes con discapacidad entre el 33 % y el 65 %: computados por entero */
    public $descdisc33entero;

    /** @var int descendientes con discapacidad igual o superior al 65 %: número total */
    public $descdisc65;

    /** @var int descendientes con discapacidad igual o superior al 65 %: computados por entero */
    public $descdisc65entero;

    /** @var int descendientes con discapacidad y movilidad reducida: número total */
    public $descdiscmov;

    /** @var int descendientes con discapacidad y movilidad reducida: computados por entero */
    public $descdiscmoventero;

    /** @var int discapacidad: 0 ninguna, 1 del 33 % al 65 %, 2 del 33 % al 65 % con movilidad reducida, 3 65 % o más */
    public $discapacidad;

    /** @var float gastos deducibles (cotizaciones a la Seguridad Social, mutualidades, etc.) */
    public $gastosdeducibles;

    /** @var int cómputo del primer hijo: 0 no aplica, 1 por entero, 2 por mitad */
    public $hijo1;

    /** @var int cómputo del segundo hijo: 0 no aplica, 1 por entero, 2 por mitad */
    public $hijo2;

    /** @var int cómputo del tercer hijo: 0 no aplica, 1 por entero, 2 por mitad */
    public $hijo3;

    /** @var int descendientes menores de 3 años: número total */
    public $hijosmenores3;

    /** @var int descendientes menores de 3 años: computados por entero */
    public $hijosmenores3entero;

    /** @var int resto de descendientes: número total */
    public $hijosresto;

    /** @var int resto de descendientes: computados por entero */
    public $hijosrestoentero;

    /** @var int clave primaria */
    public $id;

    /** @var bool derecho al incremento de gastos por movilidad geográfica (clave A) */
    public $movilidad;

    /** @var string|null NIF del cónyuge (situación familiar 2) */
    public $nifconyuge;

    /** @var string|null NIF del representante legal (perceptores menores de 14 años) */
    public $nifrepresentante;

    /** @var string|null apellidos y nombre o razón social del perceptor */
    public $nombre;

    /** @var float pensiones compensatorias al cónyuge por resolución judicial */
    public $pensioncompensatoria;

    /** @var bool comunicación de préstamos para la adquisición de la vivienda habitual */
    public $prestamovivienda;

    /** @var float reducciones aplicables */
    public $reducciones;

    /** @var int situación familiar: 1 monoparental, 2 cónyuge a cargo, 3 resto */
    public $situacionfamiliar;

    /** @var string|null subclave de percepción */
    public $subclave;

    /** @var string origen del perceptor: proveedor o subcuenta */
    public $tipo;

    /**
     * Devuelve la descripción de la clave de percepción.
     *
     * @return string
     */
    public function claveDescription(): string
    {
        return Modelo190::CLAVES[$this->clave] ?? '';
    }

    /**
     * Valores por defecto: sin discapacidad ni descendientes, contrato general y situación familiar 3.
     */
    public function clear(): void
    {
        parent::clear();
        $this->ceutamelilla = 0;
        $this->contrato = 1;
        $this->discapacidad = 0;
        $this->hijo1 = 0;
        $this->hijo2 = 0;
        $this->hijo3 = 0;
        $this->movilidad = false;
        $this->prestamovivienda = false;
        $this->situacionfamiliar = 3;
        foreach (array_keys(static::COUNT_FIELDS) as $field) {
            $this->{$field} = 0;
        }
        $this->resetAmounts();
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    /**
     * Pone a cero los importes del perceptor, que son de cada ejercicio.
     */
    public function resetAmounts(): void
    {
        foreach (static::AMOUNT_FIELDS as $field) {
            $this->{$field} = 0.0;
        }
    }

    public static function tableName(): string
    {
        return 'modelo190_perceptores';
    }

    /**
     * Normaliza y comprueba los datos: NIF en mayúsculas sin separadores, provincia, clave y subclave del
     * catálogo de la AEAT, rangos de los códigos y que los números e importes no sean negativos.
     *
     * @return bool
     */
    public function test(): bool
    {
        $this->cifnif = Modelo190::normalizeNif($this->cifnif);
        $this->nifconyuge = Modelo190::normalizeNif($this->nifconyuge);
        $this->nifrepresentante = Modelo190::normalizeNif($this->nifrepresentante);
        $this->nombre = Tools::noHtml(trim((string)$this->nombre));
        $this->clave = strtoupper(trim((string)$this->clave));
        $this->subclave = trim((string)$this->subclave);
        $this->codprovincia = trim((string)$this->codprovincia);

        if (false === in_array($this->tipo, [Modelo190::TYPE_SUPPLIER, Modelo190::TYPE_SUBACCOUNT], true)
            || empty($this->codigo)) {
            Tools::log()->warning('m190-invalid-recipient-origin');
            return false;
        }

        foreach (['cifnif', 'nifconyuge', 'nifrepresentante'] as $field) {
            if (strlen($this->{$field}) > 9) {
                Tools::log()->warning('m190-nif-too-long', ['%nif%' => $this->{$field}]);
                return false;
            }
        }

        if (false === isset(Modelo190::CLAVES[$this->clave])) {
            Tools::log()->warning('m190-invalid-key');
            return false;
        }
        if (false === Modelo190::needsSubclave($this->clave)) {
            $this->subclave = '';
        } elseif (false === isset(Modelo190::SUBCLAVES[$this->clave][$this->subclave])) {
            Tools::log()->warning('m190-invalid-subkey', ['%clave%' => $this->clave]);
            return false;
        }

        if ($this->codprovincia !== '' && false === isset(Modelo190::PROVINCIAS[$this->codprovincia])) {
            Tools::log()->warning('m190-invalid-province');
            return false;
        }

        if (false === $this->testRanges()) {
            Tools::log()->warning('m190-invalid-additional-data');
            return false;
        }

        return parent::test();
    }

    /**
     * Comprueba que los códigos y números de los datos adicionales estén en los rangos del diseño de registro
     * y que los importes no sean negativos.
     *
     * @return bool
     */
    protected function testRanges(): bool
    {
        $ranges = [
            'ceutamelilla' => [0, 2], 'situacionfamiliar' => [1, 3], 'discapacidad' => [0, 3],
            'contrato' => [1, 4], 'hijo1' => [0, 2], 'hijo2' => [0, 2], 'hijo3' => [0, 2],
        ];
        foreach (static::COUNT_FIELDS as $field => $max) {
            $ranges[$field] = [0, $max];
        }

        foreach ($ranges as $field => [$min, $max]) {
            $this->{$field} = (int)$this->{$field};
            if ($this->{$field} < $min || $this->{$field} > $max) {
                return false;
            }
        }

        // los computados por entero no pueden superar el número total
        foreach (array_keys(static::COUNT_FIELDS) as $field) {
            if (str_ends_with($field, 'entero') && $this->{$field} > $this->{substr($field, 0, -6)}) {
                return false;
            }
        }

        if (!empty($this->anionacimiento)) {
            $this->anionacimiento = (int)$this->anionacimiento;
            if ($this->anionacimiento < 1900 || $this->anionacimiento > (int)date('Y')) {
                return false;
            }
        } else {
            $this->anionacimiento = null;
        }

        foreach (static::AMOUNT_FIELDS as $field) {
            $this->{$field} = round((float)$this->{$field}, 2);
            if ($this->{$field} < 0.0) {
                return false;
            }
        }

        return true;
    }
}

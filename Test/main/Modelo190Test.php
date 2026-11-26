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

namespace FacturaScripts\Test\Plugins;

use FacturaScripts\Dinamic\Lib\Modelo111Api;
use FacturaScripts\Dinamic\Lib\Modelo190Api;
use FacturaScripts\Dinamic\Model\Ejercicio;
use FacturaScripts\Dinamic\Model\Empresa;
use FacturaScripts\Dinamic\Model\Modelo190Perceptor;
use FacturaScripts\Dinamic\Model\Proveedor;
use FacturaScripts\Plugins\Modelo111\Lib\Modelo111;
use FacturaScripts\Plugins\Modelo111\Lib\Modelo111File;
use FacturaScripts\Plugins\Modelo111\Lib\Modelo190;
use FacturaScripts\Plugins\Modelo111\Lib\Modelo190File;
use FacturaScripts\Test\Traits\RandomDataTrait;

/**
 * Tests del modelo 190 (detalle por perceptor con NIF y clave, validación y fichero), de la separación de
 * las casillas de trabajo y actividades económicas del modelo 111 y de su fichero oficial.
 *
 * @author Daniel Fernández Giménez <contacto@danielfg.es>
 */
final class Modelo190Test extends Modelo111TestCase
{
    use RandomDataTrait;

    /**
     * El fichero del modelo 111 sigue el diseño oficial: cabecera <T1110...>, página 01 de 1000 posiciones
     * con el trabajo en las casillas 01-03 y las actividades económicas en las 07-09, y cierre.
     */
    public function testFichero111(): void
    {
        $exercise = $this->getCurrentExercise();
        $company = $this->getCompany($exercise);
        $this->createNominaAsiento($exercise, 2000.0, 300.0);
        $this->createProfessionalInvoice('28001', 1000.0);
        $period = $this->getCurrentPeriod();

        $result = Modelo111::generate($exercise->codejercicio, $period);
        $this->assertSame([], Modelo111File::validate($result, $company, []));

        $content = Modelo111File::generate($result, $company, []);
        $this->assertSame(1346, strlen($content));

        $year = date('Y', strtotime($exercise->fechainicio));
        $code = Modelo111File::periodCode($period);
        $this->assertStringStartsWith('<T1110' . $year . $code . '0000><AUX>', $content);
        $this->assertSame('</AUX>', substr($content, 322, 6));
        $this->assertStringEndsWith('</T1110' . $year . $code . '0000>', $content);

        $page = substr($content, 328, 1000);
        $this->assertStringStartsWith('<T11101000> I', $page);
        $this->assertSame(Modelo190::normalizeNif($company->cifnif), trim(substr($page, 13, 9)));
        $this->assertSame($year . $code, substr($page, 102, 6));

        // casillas 01-03: la nómina; 07-09: la factura del profesional
        $this->assertSame('00000001', substr($page, 108, 8));
        $this->assertSame(Modelo190File::amount(2000.0, 17), substr($page, 116, 17));
        $this->assertSame(Modelo190File::amount(300.0, 17), substr($page, 133, 17));
        $this->assertSame('00000001', substr($page, 192, 8));
        $this->assertSame(Modelo190File::amount(1000.0, 17), substr($page, 200, 17));
        $this->assertSame(Modelo190File::amount(150.0, 17), substr($page, 217, 17));

        // 28, 29 y 30
        $this->assertSame(Modelo190File::amount(450.0, 17), substr($page, 486, 17));
        $this->assertSame(str_repeat('0', 17), substr($page, 503, 17));
        $this->assertSame(Modelo190File::amount(450.0, 17), substr($page, 520, 17));
        $this->assertSame(' ', substr($page, 537, 1));
        $this->assertStringEndsWith('</T11101000>', $page);

        // complementaria: lo ingresado antes exige el justificante de la anterior y marca la X
        $complementary = Modelo111::generate($exercise->codejercicio, $period, 100.0);
        $this->assertNotEmpty(Modelo111File::validate($complementary, $company, []));
        $options = ['justificante' => '1111234567890'];
        $this->assertSame([], Modelo111File::validate($complementary, $company, $options));
        $page = substr(Modelo111File::generate($complementary, $company, $options), 328, 1000);
        $this->assertSame(Modelo190File::amount(100.0, 17), substr($page, 503, 17));
        $this->assertSame(Modelo190File::amount(350.0, 17), substr($page, 520, 17));
        $this->assertSame('X1111234567890', substr($page, 537, 14));

        // domiciliación sin IBAN
        $this->assertNotEmpty(Modelo111File::validate($result, $company, ['tipo' => 'U']));
        $this->assertSame([], Modelo111File::validate($result, $company, ['tipo' => 'U', 'iban' => 'ES91 2100 0418 4502 0005 1332']));

        // el anual no tiene fichero del 111
        $annual = Modelo111::generate($exercise->codejercicio, 'ANNUAL');
        $this->assertNotEmpty(Modelo111File::validate($annual, $company, []));
    }

    /**
     * Con los datos de los perceptores completos, el fichero del modelo 190 tiene un registro tipo 1 y un
     * tipo 2 por perceptor, de 500 posiciones, con los datos en sus posiciones del diseño oficial.
     */
    public function testFichero190(): void
    {
        $exercise = $this->getCurrentExercise();
        $company = $this->getCompany($exercise);
        $this->createNominaAsiento($exercise, 1000.0, 150.0);
        $subject = $this->createProfessionalInvoice('46001', 2000.0);

        // datos del trabajador (subcuenta 4650000000)
        $worker = new Modelo190Perceptor();
        $worker->codejercicio = $exercise->codejercicio;
        $worker->tipo = Modelo190::TYPE_SUBACCOUNT;
        $worker->codigo = '4650000000';
        $worker->cifnif = '00000001R';
        $worker->nombre = 'Pérez López, Juan';
        $worker->codprovincia = '46';
        $worker->clave = 'A';
        $worker->anionacimiento = 1980;
        $worker->situacionfamiliar = 2;
        $worker->nifconyuge = '00000002W';
        $worker->hijosmenores3 = 1;
        $worker->hijosmenores3entero = 1;
        $worker->gastosdeducibles = 63.5;
        $worker->movilidad = true;
        $this->assertTrue($worker->save());
        $this->addCleanup(static function () use ($worker) {
            $worker->delete();
        });

        $result = Modelo111::generate($exercise->codejercicio, 'ANNUAL');
        $options = [
            'numero' => '190' . date('Y', strtotime($exercise->fechainicio)) . '000001',
            'contacto' => 'Contacto de prueba',
            'telefono' => '+34 600 000 000',
            'email' => 'test@example.com',
        ];
        $this->assertSame([], Modelo190::validate($result, $company, $options));

        $content = Modelo190File::generate($result, $company, $options);
        $this->assertStringEndsWith("\r\n", $content);
        $records = explode("\r\n", rtrim($content, "\r\n"));
        $this->assertCount(3, $records);
        foreach ($records as $record) {
            $this->assertSame(500, strlen($record));
        }

        // registro tipo 1
        $year = date('Y', strtotime($exercise->fechainicio));
        $record1 = $records[0];
        $this->assertSame('1190' . $year . Modelo190File::nif($company->cifnif), substr($record1, 0, 17));
        $this->assertSame('T600000000', substr($record1, 57, 10));
        $this->assertSame($options['numero'], substr($record1, 107, 13));
        $this->assertSame('  ' . str_repeat('0', 13), substr($record1, 120, 15));
        $this->assertSame('000000002', substr($record1, 135, 9));
        $this->assertSame(' ' . Modelo190File::amount(3000.0, 15), substr($record1, 144, 16));
        $this->assertSame(Modelo190File::amount(450.0, 15), substr($record1, 160, 15));
        $this->assertSame('test@example.com', trim(substr($record1, 175, 50)));

        // registro tipo 2 del trabajador (clave A, con datos personales)
        $record2 = $this->findRecord($records, '00000001R');
        $this->assertSame('2190' . $year, substr($record2, 0, 8));
        $this->assertSame('PEREZ LOPEZ JUAN', trim(substr($record2, 35, 40)));
        $this->assertSame('46A00', substr($record2, 75, 5));
        $this->assertSame(' ' . Modelo190File::amount(1000.0, 13) . Modelo190File::amount(150.0, 13), substr($record2, 80, 27));
        $this->assertSame('0000', substr($record2, 147, 4));
        $this->assertSame('19802' . '00000002W', substr($record2, 152, 14));
        $this->assertSame('0101', substr($record2, 166, 4));
        $this->assertSame(Modelo190File::amount(63.5, 13), substr($record2, 183, 13));
        $this->assertSame('11', substr($record2, 222, 2));
        $this->assertSame((int)$year >= 2026 ? str_repeat('0', 13) : str_repeat(' ', 13), substr($record2, 394, 13));

        // registro tipo 2 del profesional (clave G.01, sin datos personales)
        $record3 = $this->findRecord($records, Modelo190File::nif($subject->cifnif));
        $this->assertSame('46G01', substr($record3, 75, 5));
        $this->assertSame(' ' . Modelo190File::amount(2000.0, 13) . Modelo190File::amount(300.0, 13), substr($record3, 80, 27));
        $this->assertSame('00000' . str_repeat(' ', 9) . '00', substr($record3, 152, 16));

        // complementaria sin el número de la anterior
        $options['tipo'] = 'C';
        $this->assertNotEmpty(Modelo190::validate($result, $company, $options));
        $options['numeroanterior'] = '1902026000000';
        $this->assertSame([], Modelo190::validate($result, $company, $options));
        $record1 = explode("\r\n", Modelo190File::generate($result, $company, $options))[0];
        $this->assertSame('C 1902026000000', substr($record1, 120, 15));

        // la API devuelve los mismos registros, ya sin errores
        $data = Modelo190Api::build(['codejercicio' => $exercise->codejercicio])['body'];
        $this->assertSame(2, $data['resumen']['registros']);
        $this->assertSame(3000.0, $data['resumen']['total_percepciones']);
        $this->assertSame(450.0, $data['resumen']['total_retenciones']);
        $this->assertTrue($data['resumen']['fichero_valido']);
        foreach ($data['detalle']['perceptores'] as $recipient) {
            $this->assertSame([], $recipient['errores_190']);
        }
    }

    /**
     * Formatos de los campos de los ficheros de la AEAT y reglas del catálogo del modelo 190.
     */
    public function testFormatosYCatalogo(): void
    {
        $this->assertSame('PEÑALVER ÑUÑEZ JOSE MARIA SL  ', Modelo190File::alpha('Peñalver Ñúñez, José-María S.L.', 30));
        $this->assertSame('ABC', Modelo190File::alpha('abcdef', 3));
        $this->assertSame('001235', Modelo190File::amount(-12.345, 6));
        $this->assertSame('N0000000000123456', Modelo190File::signedAmount(-1234.56, 17));
        $this->assertSame('00000000000123456', Modelo190File::signedAmount(1234.56, 17));
        $this->assertSame('12345678Z', Modelo190File::nif('12.345.678-z'));
        $this->assertSame('0B1234567', Modelo190File::nif('B1234567'));
        $this->assertSame('B12345674', Modelo190File::nif('ESB12345674'));
        $this->assertSame('         ', Modelo190File::nif(''));
        $this->assertSame('0012', Modelo190File::num('abc12', 4));
        $this->assertSame("\xD1", Modelo190File::toIso('Ñ'));

        $this->assertSame('53', Modelo190::provinceFromPostalCode('38700', 'ESP'));
        $this->assertSame('38', Modelo190::provinceFromPostalCode('38001', 'ESP'));
        $this->assertSame('46', Modelo190::provinceFromPostalCode('46001', null));
        $this->assertSame('', Modelo190::provinceFromPostalCode('75001', 'FRA'));
        $this->assertSame('', Modelo190::provinceFromPostalCode('999', 'ESP'));

        $this->assertSame(['G', '01'], Modelo190::claveFromRetention(15));
        $this->assertSame(['G', '01'], Modelo190::claveFromRetention(19));
        $this->assertSame(['G', '03'], Modelo190::claveFromRetention(7));
        $this->assertSame(['H', '01'], Modelo190::claveFromRetention(2));
        $this->assertSame(['H', '04'], Modelo190::claveFromRetention(1));

        $this->assertSame(Modelo190::GROUP_WORK, Modelo190::group111('A', ''));
        $this->assertSame(Modelo190::GROUP_WORK, Modelo190::group111('E', '01'));
        $this->assertSame(Modelo190::GROUP_ACTIVITIES, Modelo190::group111('G', '01'));
        $this->assertSame(Modelo190::GROUP_ACTIVITIES, Modelo190::group111('H', '04'));
        $this->assertSame(Modelo190::GROUP_PRIZES, Modelo190::group111('K', '01'));
        $this->assertSame(Modelo190::GROUP_FOREST, Modelo190::group111('K', '02'));
        $this->assertSame(Modelo190::GROUP_IMAGE, Modelo190::group111('J', ''));
        $this->assertSame('', Modelo190::group111('L', '01'));

        $this->assertTrue(Modelo190::hasPersonalData('B', '01'));
        $this->assertFalse(Modelo190::hasPersonalData('B', '02'));
        $this->assertTrue(Modelo190::hasReductions('G', '08'));
        $this->assertFalse(Modelo190::hasReductions('G', '07'));
        $this->assertTrue(Modelo190::hasDeductibleExpenses('E', '02'));
        $this->assertFalse(Modelo190::hasDeductibleExpenses('E', '03'));
    }

    /**
     * El perceptor de una factura de compra es su proveedor, con su NIF, la provincia de su código postal y
     * la clave G.01 propuesta por la retención del 15%; sus retenciones van a las casillas 07-09 del 111.
     * Sin datos guardados, el trabajador de una nómina tiene la clave A y le faltan el NIF y la provincia.
     */
    public function testPerceptoresYCasillas(): void
    {
        $exercise = $this->getCurrentExercise();
        $this->createNominaAsiento($exercise, 1000.0, 150.0);
        $subject = $this->createProfessionalInvoice('28001', 1000.0);

        $annual = Modelo111::generate($exercise->codejercicio, 'ANNUAL');
        $this->assertSame(2, $annual['numRecipients']);

        $supplier = $annual['recipientDetails']['proveedor:' . $subject->codproveedor];
        $this->assertSame(Modelo190::normalizeNif($subject->cifnif), $supplier['cifnif']);
        $this->assertSame('28', $supplier['codprovincia']);
        $this->assertSame('G', $supplier['clave']);
        $this->assertSame('01', $supplier['subclave']);
        $this->assertSame(Modelo190::GROUP_ACTIVITIES, $supplier['grupo111']);
        $this->assertFalse($supplier['guardado']);
        $this->assertEqualsWithDelta(1000.0, $supplier['base'], 0.001);
        $this->assertEqualsWithDelta(150.0, $supplier['retencion'], 0.001);
        $this->assertSame([], Modelo190::validateRecipient($supplier));

        $worker = $annual['recipientDetails']['subcuenta:4650000000'];
        $this->assertSame('A', $worker['clave']);
        $this->assertSame('', $worker['subclave']);
        $this->assertSame(Modelo190::GROUP_WORK, $worker['grupo111']);
        $this->assertEqualsWithDelta(1000.0, $worker['base'], 0.001);
        $this->assertCount(3, Modelo190::validateRecipient($worker));

        // casillas del trimestre separadas por grupo
        $quarter = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod());
        $this->assertSame(1, $quarter['groups111']['trabajo']['perceptores']);
        $this->assertEqualsWithDelta(150.0, $quarter['groups111']['trabajo']['retenciones'], 0.001);
        $this->assertSame(1, $quarter['groups111']['actividades']['perceptores']);
        $this->assertEqualsWithDelta(1000.0, $quarter['groups111']['actividades']['base'], 0.001);
        $this->assertEqualsWithDelta(150.0, $quarter['groups111']['actividades']['retenciones'], 0.001);

        $data = Modelo111Api::build(['codejercicio' => $exercise->codejercicio, 'periodo' => $this->getCurrentPeriod()])['body'];
        $this->assertSame(1, $data['casillas']['07']);
        $this->assertSame(1000.0, $data['casillas']['08']);
        $this->assertSame(150.0, $data['casillas']['09']);

        // si se cambia la clave del profesional a A, sus retenciones pasan a las casillas del trabajo
        $perceptor = $supplier['perceptor'];
        $perceptor->clave = 'A';
        $this->assertTrue($perceptor->save());
        $this->addCleanup(static function () use ($perceptor) {
            $perceptor->delete();
        });
        $quarter = Modelo111::generate($exercise->codejercicio, $this->getCurrentPeriod());
        $this->assertSame(2, $quarter['groups111']['trabajo']['perceptores']);
        $this->assertSame(0, $quarter['groups111']['actividades']['perceptores']);
        $this->assertTrue($quarter['recipientDetails']['proveedor:' . $subject->codproveedor]['guardado']);
    }

    /**
     * Si la partida de sueldos no tiene contrapartida, su base se asigna al único perceptor del asiento.
     */
    public function testSueldoSinContrapartida(): void
    {
        $exercise = $this->getCurrentExercise();
        $asiento = $this->createNominaAsiento($exercise, 1500.0, 200.0);
        foreach ($asiento->getLines() as $line) {
            if (str_starts_with($line->codsubcuenta, '640')) {
                $line->codcontrapartida = null;
                $this->assertTrue($line->save());
            }
        }

        $result = Modelo111::generate($exercise->codejercicio, 'ANNUAL');
        $this->assertEqualsWithDelta(1500.0, $result['recipientDetails']['subcuenta:4650000000']['base'], 0.001);
        $this->assertEqualsWithDelta(1500.0, $result['baseRetenciones'], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['unassignedBase'], 0.001);
    }

    /**
     * El modelo de los datos del perceptor valida la clave, la subclave, la provincia, los NIF y los
     * números de descendientes, y los datos de un ejercicio anterior se proponen sin sus importes.
     */
    public function testValidacionPerceptor(): void
    {
        $exercise = $this->getCurrentExercise();
        $perceptor = new Modelo190Perceptor();
        $perceptor->codejercicio = $exercise->codejercicio;
        $perceptor->tipo = Modelo190::TYPE_SUBACCOUNT;
        $perceptor->codigo = '4659999999';
        $perceptor->cifnif = 'es-12345678z';
        $perceptor->clave = 'G';
        $this->assertFalse($perceptor->save(), 'la clave G exige subclave');

        $perceptor->subclave = '09';
        $this->assertFalse($perceptor->save(), 'G.09 no existe');

        $perceptor->subclave = '03';
        $perceptor->codprovincia = '99';
        $this->assertFalse($perceptor->save(), 'la provincia 99 no existe');

        $perceptor->codprovincia = '53';
        $perceptor->nifconyuge = '1234567890';
        $this->assertFalse($perceptor->save(), 'NIF de más de 9 caracteres');

        $perceptor->nifconyuge = '';
        $perceptor->hijosresto = 1;
        $perceptor->hijosrestoentero = 2;
        $this->assertFalse($perceptor->save(), 'más hijos por entero que en total');

        $perceptor->hijosrestoentero = 1;
        $perceptor->tipo = 'otro';
        $this->assertFalse($perceptor->save(), 'origen no válido');

        $perceptor->tipo = Modelo190::TYPE_SUBACCOUNT;
        $perceptor->reducciones = 120.0;
        $this->assertTrue($perceptor->save());
        $this->addCleanup(static function () use ($perceptor) {
            $perceptor->delete();
        });
        $this->assertSame('12345678Z', $perceptor->cifnif);

        // la clave A no lleva subclave
        $perceptor->clave = 'A';
        $this->assertTrue($perceptor->save());
        $this->assertSame('', $perceptor->subclave);

        // en un ejercicio posterior sin datos se proponen los de este, sin los importes
        $next = new Ejercicio();
        $next->codejercicio = 'T190';
        $next->idempresa = $exercise->idempresa;
        $next->nombre = 'Ejercicio test 190';
        $nextYear = (int)date('Y', strtotime($exercise->fechainicio)) + 1;
        $next->fechainicio = '01-01-' . $nextYear;
        $next->fechafin = '31-12-' . $nextYear;
        if ($next->save()) {
            $this->addCleanup(static function () use ($next) {
                $next->delete();
            });
            $proposed = Modelo190::getPerceptor('T190', Modelo190::TYPE_SUBACCOUNT, '4659999999', ['clave' => 'G']);
            $this->assertFalse($proposed->exists());
            $this->assertSame('T190', $proposed->codejercicio);
            $this->assertSame('A', $proposed->clave);
            $this->assertSame('12345678Z', $proposed->cifnif);
            $this->assertSame(0.0, $proposed->reducciones);
        }
    }

    /**
     * Crea un proveedor persona física con NIF y código postal y una factura de compra suya con una
     * retención del 15%.
     *
     * @param string $codpostal código postal de la dirección del proveedor
     * @param float $amount importe de la factura
     *
     * @return Proveedor
     */
    private function createProfessionalInvoice(string $codpostal, float $amount): Proveedor
    {
        $this->ensurePaymentTables();
        $retencion = $this->getRetencion15();

        $subject = $this->getRandomSupplier('Modelo190Test');
        $subject->cifnif = '00000003A';
        $subject->personafisica = true;
        $this->assertTrue($subject->save());
        $this->addCleanup(static function () use ($subject) {
            $subject->delete();
        });

        $address = $subject->getDefaultAddress();
        $address->codpais = 'ESP';
        $address->codpostal = $codpostal;
        $this->assertTrue($address->save());

        $this->createSupplierInvoice($subject, 1, $amount, $retencion->porcentaje);
        return $subject;
    }

    /**
     * Busca un registro tipo 2 por el NIF del perceptor.
     *
     * @param string[] $records registros del fichero
     * @param string $nif NIF del perceptor (9 posiciones)
     *
     * @return string
     */
    private function findRecord(array $records, string $nif): string
    {
        foreach ($records as $record) {
            if ($record[0] === '2' && substr($record, 17, 9) === $nif) {
                return $record;
            }
        }

        $this->fail('No se encuentra el registro del perceptor ' . $nif);
    }

    /**
     * Devuelve la empresa del ejercicio con un NIF (si no lo tiene, le pone uno y lo restaura al terminar).
     *
     * @param Ejercicio $exercise ejercicio
     *
     * @return Empresa
     */
    private function getCompany(Ejercicio $exercise): Empresa
    {
        $company = new Empresa();
        $this->assertTrue($company->load($exercise->idempresa));
        if (empty($company->cifnif)) {
            $company->cifnif = 'B00000000';
            $this->assertTrue($company->save());
            $this->addCleanup(static function () use ($company) {
                $company->cifnif = '';
                $company->save();
            });
        }
        return $company;
    }
}

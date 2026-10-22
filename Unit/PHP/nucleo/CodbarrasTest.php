<?php

/**
 * Los códigos de barras (`controllers/codbarras.php`, `ClaseCodbarras`): el dígito de control, la
 * comprobación de un código leído y el desglose de los códigos propios de la tienda —los que empiezan
 * por 20, con el peso, y por 21, con el precio—, que la caja usa para buscar el artículo por su
 * referencia.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use PHPUnit\Framework\TestCase;

final class CodbarrasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/controllers/codbarras.php';
    }

    /**
     * @comportamiento El dígito de control es el de un EAN-13: las cifras pesan 1 y 3 alternas desde
     *   la izquierda, y el dígito es lo que falta hasta la decena siguiente, o 0. Coincide con el
     *   cálculo de referencia en códigos de doce cifras.
     */
    public function test_elDigitoDeControlEsElDeUnEan13(): void
    {
        $codigos = new \ClaseCodbarras();

        foreach (['841234567890', '400638133393', '200012301250', '590123412345', '000000000000'] as $sinDigito) {
            self::assertSame(self::digitoEan13($sinDigito), $codigos->ObtenerDigitoControl($sinDigito), $sinDigito);
        }
        self::assertSame(0, $codigos->ObtenerDigitoControl(''));
    }

    /**
     * @comportamiento Con un código de siete cifras, el de un EAN-8, los pesos van al revés de los de
     *   su estándar, y el dígito sale distinto. En la caja no tiene efecto: solo se desglosan los
     *   códigos de trece cifras, y uno que no supera la comprobación se busca tal cual.
     */
    public function test_conSieteCifrasElDigitoNoEsElDelEan8(): void
    {
        self::assertSame(8, (new \ClaseCodbarras())->ObtenerDigitoControl('1234567'));
        self::assertSame(0, self::digitoEan8('1234567'), 'El EAN-8 válido es 12345670');
    }

    /** @comportamiento Un código es correcto si su última cifra es su dígito de control; un código vacío cuenta como correcto. */
    public function test_unCodigoEsCorrectoSiSuUltimaCifraEsSuDigito(): void
    {
        $codigos = new \ClaseCodbarras();

        self::assertSame('OK', $codigos->ComprobarCodbarras('8412345678905'));
        self::assertSame('error', $codigos->ComprobarCodbarras('8412345678906'));
        self::assertSame('OK', $codigos->ComprobarCodbarras(''));
    }

    /**
     * @comportamiento Un código propio de trece cifras se desglosa: el que empieza por 20, en
     *   referencia de cinco cifras y peso en kilos con tres decimales; el que empieza por 21, en
     *   referencia y precio con dos. Cualquier otro no se desglosa.
     */
    public function test_losCodigosPropiosSeDesglosanEnReferenciaYPesoOPrecio(): void
    {
        $codigos = new \ClaseCodbarras();

        self::assertSame(['referencia' => '00123', 'peso' => 1.25], $codigos->DesgloseCodbarra('2000123012507'));
        self::assertSame(['referencia' => '00123', 'precio' => 12.5], $codigos->DesgloseCodbarra('2100123012508'));
        self::assertSame([], $codigos->DesgloseCodbarra('8412345678905'));
        self::assertSame([], $codigos->DesgloseCodbarra('200012301250'));
    }

    private static function digitoEan13(string $doce): int
    {
        $suma = 0;
        foreach (str_split($doce) as $posicion => $cifra) {
            $suma += (int) $cifra * ($posicion % 2 === 0 ? 1 : 3);
        }

        return (10 - $suma % 10) % 10;
    }

    private static function digitoEan8(string $siete): int
    {
        $suma = 0;
        foreach (str_split($siete) as $posicion => $cifra) {
            $suma += (int) $cifra * ($posicion % 2 === 0 ? 3 : 1);
        }

        return (10 - $suma % 10) % 10;
    }
}

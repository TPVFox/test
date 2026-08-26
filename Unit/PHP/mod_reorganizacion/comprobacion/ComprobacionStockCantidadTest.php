<?php
/**
 * Con qué precisión existe una cantidad de producto, y cómo se escribe. Las existencias
 * se guardan con seis decimales: por debajo de ahí lo que hay no es cantidad, es el
 * residuo de haber sumado en coma flotante, y ese residuo decide comparaciones y se
 * escribe en ficheros si nadie lo normaliza.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModReorganizacion\Comprobacion;

use PHPUnit\Framework\TestCase;

final class ComprobacionStockCantidadTest extends TestCase
{
    private static function cargar(): void
    {
        require_once RUTA_TPVFOX . '/modulos/mod_reorganizacion/clases/ClaseComprobacionStockCantidad.php';
    }

    public function test_T1_elResiduoDeSumarEnComaFlotanteNoEsUnaCantidadNegativa(): void
    {
        self::cargar();

        // Un producto recibe 8,1 kg y vende 3,7 y 4,4: neto exactamente cero. La suma en
        // coma flotante da -8,88e-16, que es menor que cero para el lenguaje. Sin
        // normalizar, ese producto entra en el conjunto que se examina y se marca como
        // existencia negativa sin haberlo estado nunca.
        $suma = 8.1 - 3.7 - 4.4;

        self::assertTrue($suma < 0, 'La suma cruda sí es negativa: es el defecto que se corrige');
        self::assertSame(0.0, \ClaseComprobacionStockCantidad::normalizar($suma));
        self::assertFalse(\ClaseComprobacionStockCantidad::normalizar($suma) < 0);
    }

    public function test_T2_unaCantidadDeSeisDecimalesSobrevive(): void
    {
        self::cargar();

        // El límite no es arbitrario: es con lo que la base guarda las existencias. Una
        // millonésima es una cantidad legítima y no puede perderse al normalizar.
        self::assertSame(0.000001, \ClaseComprobacionStockCantidad::normalizar(0.000001));
        self::assertSame(-12.345678, \ClaseComprobacionStockCantidad::normalizar(-12.345678));
    }

    public function test_T3_porDebajoDeLaMillonesimaNoHayCantidad(): void
    {
        self::cargar();

        self::assertSame(0.0, \ClaseComprobacionStockCantidad::normalizar(-0.0000001));
        self::assertSame(0.0, \ClaseComprobacionStockCantidad::normalizar(-2.2204460492503E-16));
    }

    public function test_T4_noHayCeroNegativo(): void
    {
        self::cargar();

        // -0,0 y 0,0 son la misma cantidad, y han de dar el mismo texto: si no, dos
        // ficheros con el mismo contenido darían resúmenes distintos.
        self::assertSame(0.0, \ClaseComprobacionStockCantidad::normalizar(-0.0));
        self::assertSame('0', \ClaseComprobacionStockCantidad::comoTexto(-0.0));
        self::assertSame('0', \ClaseComprobacionStockCantidad::comoTexto(-0.0000001));
    }

    public function test_T5_seEscribeComoDecimalYNuncaEnNotacionCientifica(): void
    {
        self::cargar();

        // El lenguaje pasa a exponente por debajo de una cienmilésima, y «1.0E-6» no es
        // un número decimal para el esquema del fichero: una sola fila así rechaza el
        // fichero entero, con todas las demás dentro.
        self::assertSame('1.0E-6', (string) 0.000001, 'Así lo escribe el lenguaje por su cuenta');
        self::assertSame('0.000001', \ClaseComprobacionStockCantidad::comoTexto(0.000001));
        self::assertStringNotContainsString('E', \ClaseComprobacionStockCantidad::comoTexto(-0.00001));
    }

    public function test_T6_sinCerosFinalesSobrantes(): void
    {
        self::cargar();

        self::assertSame('3', \ClaseComprobacionStockCantidad::comoTexto(3.0));
        self::assertSame('-1.5', \ClaseComprobacionStockCantidad::comoTexto(-1.5));
        self::assertSame('0.25', \ClaseComprobacionStockCantidad::comoTexto(0.25));
    }

    public function test_T7_quienEscribeYQuienReleeObtienenElMismoTexto(): void
    {
        self::cargar();

        // El resumen de contenido se calcula al emitir sobre el valor compuesto y al
        // admitir sobre el que se leyó del fichero. Si el paso por texto y vuelta no
        // diera el mismo texto, el resumen no cuadraría sobre un fichero intacto.
        foreach ([-12.345678, 0.000001, 3.0, -0.5, 0.0] as $valor) {
            $escrito = \ClaseComprobacionStockCantidad::comoTexto($valor);
            $releido = \ClaseComprobacionStockCantidad::comoTexto((float) $escrito);
            self::assertSame($escrito, $releido);
        }
    }
}

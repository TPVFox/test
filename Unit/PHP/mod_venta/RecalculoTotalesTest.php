<?php

/**
 * `recalculoTotales()` desglosa el importe de un documento de venta por tipo de IVA. Es la
 * base del total que ve el cliente en pantalla, en el PDF (`montarHTMLimprimir()`) y en lo
 * que se guarda en el temporal.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModVenta;

use PHPUnit\Framework\TestCase;
use TPVFox\Test\CargaAislada;

final class RecalculoTotalesTest extends TestCase
{
    private static function cargar(): void
    {
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
    }

    /** @return object{iva:int,importe:float,estadoLinea:string} */
    private static function linea(int $iva, float $importe, string $estadoLinea = 'Activo'): object
    {
        $producto = new \stdClass();
        $producto->iva = $iva;
        $producto->importe = $importe;
        $producto->estadoLinea = $estadoLinea;

        return $producto;
    }

    public function test_T1_unTramoDeIvaSumaBaseIvaYTotal(): void
    {
        self::cargar();

        $productos = [
            self::linea(21, 10.10),
            self::linea(21, 3.33),
            self::linea(21, 7.77),
        ];

        $resultado = \recalculoTotales($productos);

        self::assertSame('4.45', $resultado['desglose'][21]['iva']);
        self::assertSame('25.65', $resultado['desglose'][21]['BaseYiva']);
        self::assertSame('4.45', $resultado['subivas']);
        self::assertSame('25.65', $resultado['total']);
    }

    public function test_T2_laLineaNoActivaNoCuentaEnElDesglose(): void
    {
        self::cargar();

        // Una linea eliminada o retornada sigue en el array de productos (funciones.php no
        // filtra antes de llamar), y es la propia funcion quien la excluye.
        $productos = [
            self::linea(21, 10.00),
            self::linea(21, 90.00, 'Eliminado'),
        ];

        $resultado = \recalculoTotales($productos);

        self::assertSame('2.10', $resultado['desglose'][21]['iva'], 'Solo la linea Activa debe entrar en el tramo');
        self::assertSame('12.10', $resultado['total']);
    }

    /**
     * Defecto: la base del desglose no pasa por number_format, a diferencia de iva y
     * BaseYiva del mismo tramo.
     *
     * Sintoma: con tres lineas de 0.10 en el tramo 10%, `desglose[10]['base']` sale como
     * `0.30000000000000004` — el residuo tipico de sumar en coma flotante — mientras que
     * `desglose[10]['iva']` y `desglose[10]['BaseYiva']` del mismo tramo si llevan
     * `number_format(..., 2)`. Causa raiz: `funciones.php` solo redondea `iva` y `BaseYiva`
     * en el segundo `foreach` (funciones.php:932-933); `base` se deja tal como quedo del
     * primero, sin pasar nunca por `number_format` ni `round`. `htmlTotales()` y
     * `montarHTMLimprimir()` imprimen `$basesYivas['base']` sin formatear, de modo que el
     * PDF y la pantalla pueden mostrar ese residuo tal cual. Correccion propuesta: aplicar
     * `number_format(round(...), 2, '.', '')` a `base` en el mismo punto donde ya se hace
     * para `iva` y `BaseYiva`. Evidencia: este test, en rojo mientras el defecto siga sin
     * corregirse por CC.
     *
     * @estado rojo
          *
     * @group defecto
     * @group importes
     * @group alto
     * @codigo-afectado modulos/mod_venta/funciones.php:932-933
     *
     * @que-ocurre-hoy La base de un tramo de impuesto arrastra el residuo de sumar en coma
     *   flotante, y ese residuo llega tal cual a la pantalla y al documento impreso.
     * @que-deberia-ocurrir Que la base salga redondeada como el resto de campos del tramo.
     * @por-que-ocurre El segundo recorrido redondea el impuesto y el total del tramo, pero deja
     *   la base como quedo del primero.
     * @como-deberia-funcionar Redondear la base en el mismo punto en que ya se redondean los
     *   otros dos.
    */
    public function test_T3_laBaseDelTramoNoSeRedondeaYArrastraResiduoDeComaFlotante(): void
    {
        self::cargar();

        $productos = [
            self::linea(10, 0.10),
            self::linea(10, 0.10),
            self::linea(10, 0.10),
        ];

        $resultado = \recalculoTotales($productos);

        self::assertSame('0.30', $resultado['desglose'][10]['base']);
    }
}

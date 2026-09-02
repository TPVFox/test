<?php

/**
 * `htmlLineaProductos()` decide, ademas de componer la fila, si el input de esa linea se
 * puede editar: una linea que vino de un adjunto (pedido dentro de un albaran, o albaran
 * dentro de una factura) se bloquea salvo que `$dedonde` sea el propio albaran, que es
 * donde entra la cantidad real por primera vez.
 *
 * Usa siempre productos ya normalizados por `modificarArrayProductos()` (una sola clave
 * de adjunto por linea): el arrastre de clave que esa funcion puede producir entre lineas
 * -documentado en `ModificarArrayProductosTest`- se propagaria hasta aqui y bloquearia o
 * desbloquearia la linea equivocada, pero es consecuencia de ese defecto, no uno propio de
 * esta funcion.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModVenta;

use PHPUnit\Framework\TestCase;
use TPVFox\Test\CargaAislada;

final class HtmlLineaProductosTest extends TestCase
{
    private static function cargar(): void
    {
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
    }

    /** @param array<string,mixed> $extra */
    private static function producto(array $extra = []): array
    {
        return array_merge([
            'pvpSiva'     => 10.0,
            'nunidades'   => 2,
            'idArticulo'  => 1,
            'cref'        => 'R1',
            'ccodbar'     => '',
            'cdetalle'    => 'D',
            'precioCiva'  => 12.1,
            'iva'         => 21,
            'nfila'       => 1,
            'estadoLinea' => 'Activo',
        ], $extra);
    }

    public function test_albaran_sinAdjuntoPermiteEditar(): void
    {
        self::cargar();

        $html = \htmlLineaProductos(self::producto(), 'albaran', 'editar');

        self::assertStringNotContainsString('disabled', $html);
        self::assertStringContainsString("eliminarFila(1 , 'albaran');", $html);
    }

    public function test_albaran_conLineaDePedidoSigueEditable(): void
    {
        self::cargar();

        // El propio albaran es donde la cantidad real entra por primera vez, aunque la
        // linea venga de un pedido adjunto.
        $html = \htmlLineaProductos(self::producto(['NumpedCli' => 5]), 'albaran', 'editar');

        self::assertStringNotContainsString('disabled', $html);
        self::assertStringContainsString('<td>5</td>', $html);
    }

    public function test_factura_conLineaDeAlbaranQuedaBloqueada(): void
    {
        self::cargar();

        $html = \htmlLineaProductos(self::producto(['NumalbCli' => 5]), 'factura', 'editar');

        self::assertStringContainsString('disabled', $html);
        self::assertStringNotContainsString('eliminarFila', $html, 'Una linea bloqueada no lleva boton de eliminar');
    }

    public function test_estadoEliminadoQuedaTachadoYOfreceRetornar(): void
    {
        self::cargar();

        $html = \htmlLineaProductos(self::producto(['estadoLinea' => 'Eliminado']), 'albaran', 'editar');

        self::assertStringContainsString('tachado', $html);
        self::assertStringContainsString("retornarFila(1, 'albaran');", $html);
    }

    public function test_verSiempreDejaElInputDeshabilitadoYSinBotones(): void
    {
        self::cargar();

        $html = \htmlLineaProductos(self::producto(), 'albaran', 'ver');

        self::assertStringContainsString('disabled', $html);
        self::assertStringNotContainsString('eliminarFila', $html);
        self::assertStringNotContainsString('retornarFila', $html);
    }
}

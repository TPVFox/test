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

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:340-403
     *
     * @group esperado
     * @group entrada
     * @group validacion
     * @group alto
     *
     * @que-ocurre-hoy La caja de unidades de una linea de mil o mas se pinta con separador de
     *   miles, «1,000.00». Es una caja editable: en cuanto el operador pasa por ella, el
     *   navegador rechaza ese valor como numero, avisa y la deja en 1.
     * @que-deberia-ocurrir Que la caja lleve un valor que el propio navegador acepte como numero.
     * @por-que-ocurre El valor de la caja se compone con el formato de presentacion, con
     *   separador de miles por defecto, y una caja de entrada no es presentacion.
     * @como-deberia-funcionar Sin separador de miles en el valor de la caja.
     *
     * @dataProvider unidadesDeMilOMas
     */
    public function test_defecto_laCajaDeUnidadesDeUnaLineaDeMilOMasLlevaUnNumeroSinSeparador(float $unidades, string $esperado): void
    {
        self::cargar();

        $html = \htmlLineaProductos(self::producto(['nunidades' => $unidades]), 'albaran', 'editar');

        self::assertSame($esperado, self::valorDeLaCajaDeUnidades($html));
    }

    /** @return array<string,array{float,string}> */
    public static function unidadesDeMilOMas(): array
    {
        return [
            'mil justo'         => [1000.0, '1000.00'],
            'con decimales'     => [2500.5, '2500.50'],
            'devolucion de mil' => [-1000.0, '-1000.00'],
        ];
    }

    /**
     * @group control
     * @group entrada
     *
     * @para-que-sirve Acota el defecto de la caja de unidades: por debajo de mil el valor ya es
     *   un numero que el navegador acepta, y tiene que seguir siendolo cuando se corrija.
     */
    public function test_laCajaDeUnidadesPorDebajoDeMilLlevaUnNumeroConDosDecimales(): void
    {
        self::cargar();

        $html = \htmlLineaProductos(self::producto(['nunidades' => 999]), 'albaran', 'editar');

        self::assertSame('999.00', self::valorDeLaCajaDeUnidades($html));
    }

    private static function valorDeLaCajaDeUnidades(string $html): string
    {
        self::assertSame(1, preg_match('/id="Unidad_Fila_\d+"[^>]*\svalue="([^"]*)"/', $html, $coincidencia));

        return $coincidencia[1];
    }
}

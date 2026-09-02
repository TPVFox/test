<?php

/**
 * Composicion de HTML de `funciones.php` que no toca la base: filas de tabla, opciones de
 * `<select>` y los modales de cliente, producto, adjunto e incidencia. `mod_venta` compone
 * en servidor y el cliente solo inserta (CV-12): estas funciones son ese "componer".
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModVenta;

use PHPUnit\Framework\TestCase;
use TPVFox\Test\CargaAislada;

final class ComposicionHtmlTest extends TestCase
{
    private static function cargar(): void
    {
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
    }

    public function test_htmlOptions_marcaLaOpcionSeleccionada(): void
    {
        self::cargar();

        $html = \htmlOptions([
            ['id' => 1, 'descripcion' => 'Uno'],
            ['id' => 2, 'descripcion' => 'Dos'],
        ], 2);

        self::assertSame(
            '<option value= "1">Uno</option><option value= "2" selected="selected">Dos</option>',
            $html
        );
    }

    public function test_htmlLineaAdjunto_verNoMuestraBotonDeAccion(): void
    {
        self::cargar();

        $adjunto = ['NumAdjunto' => 10, 'fecha' => '2026-01-01', 'total' => 5.5, 'nfila' => 1, 'estado' => 'Activo'];

        self::assertSame(
            '<tr id="lineaP1" ><td>10</td><td>2026-01-01</td><td>5.5</td></tr>',
            \htmlLineaAdjunto($adjunto, 'albaran', 'ver')
        );
    }

    public function test_htmlLineaAdjunto_editarActivoMuestraEliminar(): void
    {
        self::cargar();

        $adjunto = ['NumAdjunto' => 10, 'fecha' => '2026-01-01', 'total' => 5.5, 'nfila' => 1, 'estado' => 'Activo'];
        $html = \htmlLineaAdjunto($adjunto, 'albaran', 'editar');

        self::assertStringContainsString('eliminarAdjunto(10 , \'albaran\' , 1);', $html);
        self::assertStringNotContainsString('tachado', $html);
    }

    public function test_htmlLineaAdjunto_editarEliminadoMuestraRetornarYTachado(): void
    {
        self::cargar();

        $adjunto = ['NumAdjunto' => 10, 'fecha' => '2026-01-01', 'total' => 5.5, 'nfila' => 1, 'estado' => 'Eliminado'];
        $html = \htmlLineaAdjunto($adjunto, 'albaran', 'editar');

        self::assertStringContainsString('retornarAdjunto(10, \'albaran\', 1);', $html);
        self::assertStringContainsString('tachado', $html);
    }

    public function test_htmlClientes_sinResultadosDejaElCuerpoVacio(): void
    {
        self::cargar();

        $resultado = \htmlClientes('', 'tpv', 'id_cliente', []);

        self::assertSame(0, $resultado['encontrados']);
        self::assertStringContainsString('<tbody></tbody>', $resultado['html']);
    }

    public function test_htmlClientes_conUnResultadoMuestraSuFila(): void
    {
        self::cargar();

        $resultado = \htmlClientes('ana', 'tpv', 'id_cliente', [
            ['idClientes' => 5, 'Nombre' => 'Ana', 'razonsocial' => 'Ana SL', 'nif' => 'X1', 'estado' => 'Activo'],
        ]);

        self::assertSame(1, $resultado['encontrados']);
        self::assertStringContainsString("buscarClientes('tpv','id_cliente',5);", $resultado['html']);
        self::assertStringNotContainsString('danger', $resultado['html'], 'Un cliente Activo no lleva la clase de inactivo');
    }

    public function test_htmlClientes_inactivoMarcaLaFilaYAvisa(): void
    {
        self::cargar();

        $resultado = \htmlClientes('', 'tpv', 'id_cliente', [
            ['idClientes' => 5, 'Nombre' => 'Ana', 'razonsocial' => 'Ana SL', 'nif' => 'X1', 'estado' => 'Inactivo'],
        ]);

        self::assertStringContainsString('FilaModal danger', $resultado['html']);
        self::assertStringContainsString('están Rojo', $resultado['html']);
    }

    public function test_htmlListadoProductos_devuelveElCampoDeBusquedaUsado(): void
    {
        self::cargar();

        $resultado = \htmlListadoProductos(
            [['idArticulo' => 1, 'crefTienda' => 'REF1', 'articulo_name' => 'Prod', 'iva' => 21, 'codBarras' => '888', 'pvpCiva' => 12.10, 'pvpCivaCLI' => null]],
            'idArticulo',
            'a.idArticulo',
            'prod',
            'albaran',
            null,
            5
        );

        self::assertSame('a.idArticulo', $resultado['campo']);
        self::assertSame(1, $resultado['encontrados']);
        self::assertStringContainsString('12.10', $resultado['html']);
    }

    public function test_htmlListadoProductos_sinBusquedaYsinResultadosPideBuscar(): void
    {
        self::cargar();

        $resultado = \htmlListadoProductos([], 'idArticulo', 'a.idArticulo', '', 'albaran', null, 5);

        self::assertStringContainsString('Pon las palabras', $resultado['html']);
    }

    public function test_htmlListadoProductos_conBusquedaYsinResultadosAvisaError(): void
    {
        self::cargar();

        $resultado = \htmlListadoProductos([], 'idArticulo', 'a.idArticulo', 'xyz', 'albaran', null, 5);

        self::assertStringContainsString('No se encontrado nada', $resultado['html']);
    }

    public function test_htmlTotales_conDesgloseVacioDevuelveLaFilaDeTotalesEnCero(): void
    {
        self::cargar();

        // Los tres callers de htmlTotales() (factura.php, pedido.php, albaran.php,
        // AddTemporal.php) siempre le pasan lo que devuelve recalculoTotales(), que fija
        // 'desglose' aunque este vacio: el camino sin esa clave (funciones.php:527, `return`
        // implicito a null) no tiene ningun llamador real que lo alcance hoy.
        $resultado = \htmlTotales(['desglose' => []]);

        self::assertSame('<tr><td> Totales </td><td>0</td><td>0</td></tr>', $resultado['html']);
    }

    public function test_modalAdjunto_sinAdjuntosAvisaQueNoHayNinguno(): void
    {
        self::cargar();

        $resultado = \modalAdjunto([], 'buscarAdjunto', 'albaran');

        self::assertSame(
            '<div class="alert alert-warning">No se encontro ningun albaran de ese cliente con estado guardado</div>',
            $resultado['html']
        );
    }

    public function test_modalAdjunto_conAdjuntosMuestraLaFila(): void
    {
        self::cargar();

        $resultado = \modalAdjunto([
            ['NumAdjunto' => 10, 'fecha' => '2026-01-01', 'total' => 5.5],
        ], 'buscarAdjunto', 'albaran');

        self::assertStringContainsString("buscarAdjunto('albaran',10)", $resultado['html']);
    }

    public function test_modalIncidenciasAdjuntas_componeUnBloquePorIncidencia(): void
    {
        self::cargar();

        $html = \modalIncidenciasAdjuntas([
            ['fecha_creacion' => '2026-01-01', 'dedonde' => 'mod_ventas', 'estado' => 'No resuelto', 'id_usuario' => 3, 'datos' => '{}', 'mensaje' => 'hola'],
        ]);

        self::assertStringContainsString('value="2026-01-01"', $html);
        self::assertStringContainsString('value="No resuelto"', $html);
        self::assertStringContainsString('hola', $html);
    }
}

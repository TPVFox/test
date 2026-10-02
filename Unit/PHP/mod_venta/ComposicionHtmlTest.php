<?php

/**
 * Composicion de HTML de `funciones.php` que no toca la base: filas de tabla, opciones de
 * `<select>` y los modales de cliente, producto, adjunto e incidencia. `mod_venta` compone
 * en servidor y el cliente solo inserta, como pide la convencion del proyecto: estas
 * funciones son ese "componer".
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

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:469-490
     *
     * @group esperado
     * @group busqueda
     * @group importes
     * @group critico
     *
     * @que-ocurre-hoy Si entre las coincidencias de una busqueda de producto hay un articulo de
     *   mil o mas, el listado no llega a componerse: la peticion muere y la pantalla no enseña
     *   nada, tampoco los demas articulos encontrados.
     * @que-deberia-ocurrir Que el listado se componga con todos los articulos, tambien ese.
     * @por-que-ocurre El precio se formatea con separador de miles y despues se vuelve a
     *   formatear ese texto, que ya no es un numero.
     * @como-deberia-funcionar Formatear el precio una sola vez, al presentarlo.
     *
     * @dataProvider articulosDeMilOMas
     *
     * @param array<string,mixed> $precios
     */
    public function test_defecto_htmlListadoProductos_conUnArticuloDeMilOMasComponeElListado(array $precios): void
    {
        self::cargar();

        $html = null;
        try {
            $html = \htmlListadoProductos(
                [self::coincidencia(1, 'Articulo corriente', ['pvpCiva' => 1.82]), self::coincidencia(2, 'Camara frigorifica', $precios)],
                'Descripcion',
                'a.articulo_name',
                'a',
                'pedido',
                null,
                5
            )['html'];
        } catch (\TypeError $e) {
            // El caso falla por su asercion, no por el error de tipo.
        }

        self::assertNotNull($html, 'El listado tiene que componerse aunque haya un articulo de mil o mas.');
        self::assertSame(2, substr_count($html, 'class="FilaModal"'));
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function articulosDeMilOMas(): array
    {
        return [
            'precio de catalogo'          => [['pvpCiva' => 1815.00]],
            'precio de tarifa del cliente' => [['pvpCiva' => 900.00, 'pvpCivaCLI' => 1500.00]],
        ];
    }

    /**
     * @group control
     * @group busqueda
     *
     * @para-que-sirve Acota el defecto del listado: con todos los precios por debajo de mil se
     *   compone, y tiene que seguir componiendose cuando se corrija.
     */
    public function test_htmlListadoProductos_conPreciosPorDebajoDeMilComponeElListado(): void
    {
        self::cargar();

        $html = \htmlListadoProductos(
            [self::coincidencia(1, 'Articulo corriente', ['pvpCiva' => 1.82]), self::coincidencia(2, 'Articulo casi de mil', ['pvpCiva' => 999.99])],
            'Descripcion',
            'a.articulo_name',
            'a',
            'pedido',
            null,
            5
        )['html'];

        self::assertSame(2, substr_count($html, 'class="FilaModal"'));
    }

    /**
     * Una coincidencia tal como la devuelve la busqueda de productos.
     *
     * @param array<string,mixed> $precios
     * @return array<string,mixed>
     */
    private static function coincidencia(int $idArticulo, string $nombre, array $precios): array
    {
        return array_merge([
            'idArticulo'    => $idArticulo,
            'articulo_name' => $nombre,
            'crefTienda'    => 'REF' . $idArticulo,
            'codBarras'     => '',
            'iva'           => 21.00,
            'pvpCiva'       => 1.00,
            'pvpCivaCLI'    => null,
        ], $precios);
    }
}

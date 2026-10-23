<?php

/**
 * Lo que no consulta la base en `modulos/mod_proveedor/`: el HTML y las fechas de `funciones.php` —las
 * tablas de documentos de la ficha, los desplegables, el resumen anual por trimestres y la ficha del
 * resumen de proveedores— y el coste medio de lo comprado (`ClaseProveedor::SumaLineasAlbaranesProveedores()`).
 *
 * Otros módulos declaran funciones con el mismo nombre (`verSelec()`, `htmlPanelDesplegable()`…): cada
 * caso corre en su propio proceso. La clase se crea sin constructor: el método no usa la base.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModProveedor;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesProveedorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_proveedor/funciones.php';
    }

    private function claseProveedor(): \ClaseProveedor
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_proveedor/clases/ClaseProveedor.php';

        return (new \ReflectionClass(\ClaseProveedor::class))->newInstanceWithoutConstructor();
    }

    private function intentar(callable $llamada): mixed
    {
        try {
            return $llamada();
        } catch (\Throwable $error) {
            return get_class($error) . ': ' . $error->getMessage();
        }
    }

    /**
     * @comportamiento Las últimas facturas, albaranes y pedidos de la ficha salen en una tabla con su
     *   fecha, su número enlazado al documento de compras y su total; la de albaranes lleva el botón del
     *   resumen; sin documentos, un aviso.
     */
    public function test_lasTablasDeDocumentosDeLaFicha(): void
    {
        $albaranes = [['fecha' => '2031-05-10 12:00:00', 'num' => '7', 'total' => '12.10', 'id' => '40', 'idProveedor' => '5']];

        $html = \htmlTablaGeneral($albaranes, '/TPVFox', 'albaranes');

        self::assertStringContainsString('onclick="abrirResumen(5,\'albaranes\')"', $html);
        self::assertStringContainsString('<td><a href="/TPVFox/modulos/mod_compras/albaran.php?id=40&accion=ver">7</a></td>', $html);
        self::assertStringContainsString('<td>12.10</td>', $html);
        self::assertStringContainsString('/modulos/mod_compras/factura.php?id=40', \htmlTablaGeneral($albaranes, '/TPVFox', 'facturas'));
        self::assertStringContainsString('/modulos/mod_compras/pedido.php?id=40', \htmlTablaGeneral($albaranes, '/TPVFox', 'pedidos'));
        self::assertSame('<div class="alert alert-info">Este proveedor no tiene pedidos</div>', \htmlTablaGeneral([], '/TPVFox', 'pedidos'));
    }

    /** @comportamiento El desplegable lleva su número, su título y su contenido; las clases de línea, un nombre por número positivo. */
    public function test_elDesplegableYLasClasesDeLinea(): void
    {
        $html = \htmlPanelDesplegable(3, 'Pedidos', '<p>x</p>');

        self::assertStringContainsString('href="#collapse3">Pedidos</a>', $html);
        self::assertStringContainsString('<div id="collapse3" class="panel-collapse collapse"><div class="panel-body"><p>x</p></div>', $html);
        self::assertSame('Padre_4 Padre_9 ', \htmlNombreClasesLinea([4, 0, 9], 'Padre'));
    }

    /**
     * @comportamiento Las dos fechas del resumen se devuelven como año, mes y día; si falta una, se avisa;
     *   el índice de un estado es el de su nombre, y el icono de orden marca el sentido del campo ordenado.
     */
    public function test_lasFechasElEstadoYElOrden(): void
    {
        self::assertSame(['fechaIni' => '2031-05-01', 'fechaFin' => '2031-05-31'], \comprobarFechas('01-05-2031', '31-05-2031'));
        self::assertSame(['error' => 'Error', 'consulta' => 'Una de las fechas está sin cubrir'], \comprobarFechas('', '2031-05-31'));
        self::assertSame(1, \obtenerIndexEstado([['nombre' => 'Activo'], ['nombre' => 'Baja']], 'Baja'));
        self::assertSame('', \obtenerIndexEstado([], 'Baja'));
        self::assertSame('<span class="glyphicon glyphicon-sort-by-attributes-alt"></span>', \obtenerIconoOrden('articulo_name', 'ASC', 'articulo_name'));
        self::assertSame('<span class="glyphicon glyphicon-sort-by-attributes"></span>', \obtenerIconoOrden('articulo_name', 'DESC', 'articulo_name'));
        self::assertSame('<span class="glyphicon glyphicon-sort"></span>', \obtenerIconoOrden('articulo_name', 'ASC', 'estado'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/funciones.php:112-129
     * @codigo-afectado modulos/mod_proveedor/OtrasVistas/resumenAlbaranes.php:37-50,61-64
     *
     * @group defecto
     * @group proveedor
     * @group bajo
     *
     * @que-ocurre-hoy Una fecha mal escrita en el resumen de albaranes termina la petición con un error de
     *   tipo de PHP, en lugar de avisar como avisa la que falta.
     * @que-deberia-ocurrir Que una fecha que no se entiende se avise igual que una que falta.
     * @por-que-ocurre `date_create()` devuelve false con una fecha que no entiende, y `date_format()` no
     *   admite false en PHP 8.
     * @como-deberia-funcionar Comprobar el resultado de `date_create()` antes de formatearlo.
     */
    public function test_unaFechaMalEscritaSeAvisa(): void
    {
        $resultado = $this->intentar(static fn () => \comprobarFechas('31 de mayo', '2031-05-31'));

        self::assertIsArray($resultado);
        self::assertArrayHasKey('error', $resultado);
    }

    /**
     * @comportamiento El resumen anual toma, de todos los ejercicios del proveedor, el pedido; sin él, todo a
     *   cero. La tabla da por trimestre la base —total menos IVA—, el IVA y el total; el de todos los
     *   proveedores lo valida proveedor a proveedor.
     */
    public function test_elResumenAnualPorTrimestres(): void
    {
        $ejercicios = ['resumen_facturas' => [
            ['idProveedor' => '5', 'ejercicio' => '2030', 'q1' => 0, 'q1Iva' => 0, 'q2' => 0, 'q2Iva' => 0, 'q3' => 0, 'q3Iva' => 0, 'q4' => 0, 'q4Iva' => 0, 'total' => 1, 'totalIva' => 0],
            ['idProveedor' => '5', 'ejercicio' => '2031', 'q1' => '121.00', 'q1Iva' => '21.00', 'q2' => '0', 'q2Iva' => '0', 'q3' => '0', 'q3Iva' => '0', 'q4' => '55.00', 'q4Iva' => '5.00', 'total' => '176.00', 'totalIva' => '26.00'],
        ]];

        $resumen = \validarResumenAnual($ejercicios, '2031');
        $html = \htmlTablaResumenAnual($resumen);

        self::assertSame('176.00', $resumen['facturas']['total']);
        self::assertSame(0, \validarResumenAnual($ejercicios, '2029')['facturas']['total']);
        self::assertStringContainsString('<td>Base imponible</td><td>100.00</td><td>0.00</td><td>0.00</td><td>50.00</td><td>150.00</td>', $html);
        self::assertStringContainsString('<td>IVA</td><td>21.00</td>', $html);
        self::assertSame(['5'], array_map('strval', array_keys(\validarResumenAnualTodosProveedores(['resumen_facturas' => [$ejercicios['resumen_facturas'][1]]], '2031'))));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/funciones.php:227-228
     *
     * @group defecto
     * @group proveedor
     * @group bajo
     *
     * @que-ocurre-hoy La fila del total del resumen anual no abre su fila: sus celdas quedan dentro de la del
     *   IVA, y la tabla sale mal formada.
     * @que-deberia-ocurrir Que cada fila de la tabla abra y cierre la suya.
     * @por-que-ocurre Falta el `<tr>` antes de la celda «Total».
     * @como-deberia-funcionar Abrir la fila del total.
     */
    public function test_laFilaDelTotalDelResumenAnualAbreSuFila(): void
    {
        $html = \htmlTablaResumenAnual(\validarResumenAnual([], '2031'));

        self::assertStringContainsString('<tr><td>Total</td>', $html);
    }

    /**
     * @comportamiento La ficha del resumen de proveedores lleva el nombre, el número, la dirección, el
     *   teléfono y el correo escapados, y los enlaces a la ficha, al resumen de albaranes y a sus facturas.
     */
    public function test_laFichaDelResumenDeProveedores(): void
    {
        $proveedor = ['idProveedor' => '5', 'nombrecomercial' => 'Huerta & Hijos', 'razonsocial' => 'Huerta SL', 'nif' => 'B11111111', 'direccion' => 'Rúa 1', 'telefono' => '986000000', 'email' => 'h@h.es'];

        $html = \mostrarResumenProveedor($proveedor, \validarResumenAnual([], '2031'), 'bg-info');

        self::assertStringContainsString('<h2>Resumen Anual de Huerta &amp; Hijos</h2>', $html);
        self::assertStringContainsString('<div class="col-md-12 bg-info">', $html);
        self::assertStringContainsString('href="./../proveedor.php?id=5&accion=ver"', $html);
        self::assertStringContainsString('href="./resumenAlbaranes.php?id=5&historyJS=1"', $html);
        self::assertStringContainsString('facturasListado.php?buscar=Huerta+%26+Hijos', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/funciones.php:263
     *
     * @group defecto
     * @group proveedor
     * @group medio
     *
     * @que-ocurre-hoy En el resumen anual de proveedores, la línea «NIF» enseña la razón social: el NIF de
     *   cada proveedor no aparece, aunque el resumen sirve para cuadrar sus operaciones del año.
     * @que-deberia-ocurrir Que la línea «NIF» enseñe el NIF.
     * @por-que-ocurre La línea escribe `razonsocial` bajo la etiqueta del NIF.
     * @como-deberia-funcionar Escribir `nif`.
     */
    public function test_elNifDelResumenDeProveedoresEsElNif(): void
    {
        $proveedor = ['idProveedor' => '5', 'nombrecomercial' => 'Huerta', 'razonsocial' => 'Huerta SL', 'nif' => 'B11111111', 'direccion' => '', 'telefono' => '', 'email' => ''];

        $html = \mostrarResumenProveedor($proveedor, \validarResumenAnual([], '2031'), '');

        self::assertStringContainsString('<p><strong>NIF: </strong>B11111111</p>', $html);
    }

    /**
     * @comportamiento Las líneas de los albaranes se suman por artículo: las unidades se acumulan, cuántas
     *   veces se compró, y, si el coste cambia, el coste medio ponderado por unidades, marcado; con el mismo
     *   coste, sin marca. Se devuelven en lista o por número de artículo.
     */
    public function test_elCosteMedioDeLoComprado(): void
    {
        $lineas = [
            ['idArticulo' => '7', 'costeSiva' => '10.0000', 'totalUnidades' => '2'],
            ['idArticulo' => '7', 'costeSiva' => '13.0000', 'totalUnidades' => '1'],
            ['idArticulo' => '7', 'costeSiva' => '10.0000', 'totalUnidades' => '1'],
            ['idArticulo' => '8', 'costeSiva' => '5.0000', 'totalUnidades' => '3'],
            ['idArticulo' => '8', 'costeSiva' => '5.0000', 'totalUnidades' => '1'],
        ];

        $porArticulo = $this->claseProveedor()->SumaLineasAlbaranesProveedores($lineas, 'KO');
        $lista = $this->claseProveedor()->SumaLineasAlbaranesProveedores($lineas);

        self::assertEqualsWithDelta(10.75, $porArticulo['7']['costeSiva'], 0.0001);
        self::assertEquals(4, $porArticulo['7']['totalUnidades']);
        self::assertSame(3, $porArticulo['7']['num_compras']);
        self::assertSame('OK', $porArticulo['7']['coste_medio']);
        self::assertEqualsWithDelta(43.0, $porArticulo['7']['total_linea'], 0.0001);
        self::assertSame('KO', $porArticulo['8']['coste_medio']);
        self::assertEqualsWithDelta(20.0, $porArticulo['8']['total_linea'], 0.0001);
        self::assertSame([0, 1], array_keys($lista));
    }
}

<?php

/**
 * Las funciones de `modulos/mod_producto/funciones.php` que no consultan la base: las tablas y las
 * líneas de la ficha de producto, las opciones de los desplegables, los paneles, la configuración de
 * columnas del listado, la preparación de lo que llega al guardar la ficha, la selección de productos
 * en la sesión, el buscador de proveedores, las etiquetas, los modales de familias y de estados, el
 * resumen del borrado y las tablas y cuentas del mayor.
 *
 * Otros módulos declaran funciones con el mismo nombre (`htmlPanelDesplegable()`,
 * `htmlLineaRefTienda()`…): cada caso corre en su propio proceso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModProducto;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesProductoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_producto/funciones.php';
    }

    /** @comportamiento La tabla de códigos de barras pinta una línea editable por código, con su papelera; sin códigos, solo la cabecera. */
    public function test_laTablaDeCodigosDeBarras(): void
    {
        $html = \htmlTablaCodBarras(['8410000000017', '8410000000024']);

        self::assertStringContainsString('id="codBarras_0" name="codBarras_0" value="8410000000017"', $html);
        self::assertStringContainsString('id="codBarras_1" name="codBarras_1" value="8410000000024"', $html);
        self::assertSame(2, substr_count($html, 'onclick="eliminarCodBarras(this)"'));
        self::assertStringNotContainsString('codBarras_', \htmlTablaCodBarras([]));
    }

    /** @comportamiento La tabla de familias pinta cada familia con su número oculto y su papelera, y el enlace para añadir. */
    public function test_laTablaDeFamilias(): void
    {
        $html = \htmlTablaFamilias([['idFamilia' => '7', 'familiaNombre' => 'Frutas']], 15);

        self::assertStringContainsString('<input type="hidden" id="idFamilias_7" name="idFamilias_7" value="7">7</td><td>Frutas</td>', $html);
        self::assertStringContainsString('onclick="modalFamiliaProducto(15)"', $html);
        self::assertStringNotContainsString('idFamilias_', \htmlTablaFamilias(null, 15));
    }

    /**
     * @comportamiento Un proveedor se pinta con su referencia y su coste de solo lectura: el principal,
     *   marcado y fijo; el último al que se compró, en rojo; y la papelera solo si se puede quitar.
     */
    public function test_laLineaDeUnProveedor(): void
    {
        $proveedor = ['idProveedor' => '3', 'nombrecomercial' => 'Huerta', 'razonsocial' => 'Huerta SL', 'crefProveedor' => 'H-1',
            'coste' => '1.20', 'estado' => 'Activo', 'fechaActualizacion' => '2026-01-01'];

        $principal = \htmlLineaProveedorCoste($proveedor + ['principal' => 'Si', 'ultimo_pro' => 'Si'], 'Ok');
        $otro = \htmlLineaProveedorCoste($proveedor);

        self::assertStringContainsString('readonly onclick="return false;" checked="true"', $principal);
        self::assertStringContainsString('3.-Huerta SL-Huerta', $principal);
        self::assertStringContainsString('style="color:red;"', $principal);
        self::assertStringContainsString('onclick="EliminarRefProveedor(this)"', $principal);
        self::assertStringContainsString('name="check_pro"  disabled', $otro);
        self::assertStringNotContainsString('EliminarRefProveedor', $otro);
    }

    /** @comportamiento La tabla de proveedores pasa la lista de proveedores al JavaScript de la página; sin proveedores, no. */
    public function test_laTablaDeProveedoresPasaLaListaAlJavaScript(): void
    {
        $proveedores = [['idProveedor' => '3', 'nombrecomercial' => 'Huerta', 'razonsocial' => 'Huerta', 'coste' => '1', 'estado' => 'Activo', 'fechaActualizacion' => '']];

        $html = \htmlTablaProveedoresCostes($proveedores, 'KO');

        self::assertStringContainsString('<script>var proveedores =[{"idProveedor":"3"', $html);
        self::assertStringContainsString('3.-Huerta', $html);
        self::assertStringNotContainsString('<script>', \htmlTablaProveedoresCostes([], 'KO'));
    }

    /** @comportamiento La tabla de referencias de tienda deja fuera la principal, y la papelera solo con permiso. */
    public function test_laTablaDeReferenciasDeTienda(): void
    {
        $referencias = [
            ['id' => '1', 'idTienda' => '1', 'crefTienda' => 'P-1', 'idVirtuemart' => '0', 'tipoTienda' => 'principal'],
            ['id' => '2', 'idTienda' => '9', 'crefTienda' => 'W-1', 'idVirtuemart' => '44', 'tipoTienda' => 'web'],
        ];

        $conPermiso = \htmlTablaRefTiendas($referencias, '<a>web</a>', 1);
        $sinPermiso = \htmlTablaRefTiendas($referencias, '<a>web</a>');

        self::assertStringNotContainsString('P-1', $conPermiso);
        self::assertStringContainsString('<small>W-1/44</small>', $conPermiso);
        self::assertStringContainsString('onclick="EliminarReferenciaTienda(2,this)"', $conPermiso);
        self::assertStringNotContainsString('EliminarReferenciaTienda', $sinPermiso);
    }

    /** @comportamiento Las tablas de balanzas, albaranes, pedidos e histórico pintan sus filas; el histórico, del más antiguo al más reciente. */
    public function test_lasTablasDeBalanzasAlbaranesPedidosEHistorico(): void
    {
        $balanza = \htmlTablaBalanza([['id' => '5', 'idBalanza' => '1', 'plu' => '22', 'seccion' => 'A']]);
        $albaranes = \htmlTablaAlbaranes([['Fecha' => '2026-03-01 10:00:00', 'Numalbpro' => '12', 'razonsocial' => 'Huerta', 'costeSiva' => '1.2', 'estado' => 'Guardado']]);
        $pedidos = \htmlTablaPedidos([['Fecha' => '2026-03-02', 'id' => '8', 'razonsocial' => 'Huerta', 'costeSiva' => '1.1', 'estado' => 'Guardado']]);
        $historico = \htmlTablaHistoricoPrecios([
            ['id' => '2', 'Fecha_Creacion' => '2026-02-02', 'Antes' => '2', 'Nuevo' => '3', 'NumDoc' => '0', 'Dedonde' => 'producto', 'Tipo' => 'Productos'],
            ['id' => '1', 'Fecha_Creacion' => '2026-02-01', 'Antes' => '1', 'Nuevo' => '2', 'NumDoc' => '0', 'Dedonde' => 'producto', 'Tipo' => 'Productos'],
        ]);

        self::assertStringContainsString('onclick="EliminarReferenciaBalanza(5,this)"', $balanza);
        self::assertStringContainsString('<td>01-03-2026</td><td>12</td>', $albaranes);
        self::assertStringContainsString('mod_compras/albaran.php?id=12&accion=ver', $albaranes);
        self::assertStringContainsString('mod_compras/pedido.php?id=8&accion=ver', $pedidos);
        self::assertLessThan(strpos($historico, '02-02-2026'), strpos($historico, '01-02-2026'));
    }

    /** @comportamiento Los desplegables de IVA y de estado marcan el del producto. */
    public function test_losDesplegablesMarcanElDelProducto(): void
    {
        $ivas = \htmlOptionIvas([['idIva' => '1', 'iva' => '21.00'], ['idIva' => '2', 'iva' => '10.00']], '10.00');
        $estados = \htmlOptionEstados([['estado' => 'Activo'], ['estado' => 'Baja']], 'Baja');

        self::assertStringContainsString('<option value="2"  selected>10.00%</option>', $ivas);
        self::assertStringContainsString('<option value="1" >21.00%</option>', $ivas);
        self::assertStringContainsString('<option value="Baja"  selected>Baja</option>', $estados);
    }

    /** @comportamiento Un panel desplegable lleva su título como enlace que abre su cuerpo. */
    public function test_elPanelDesplegable(): void
    {
        $html = \htmlPanelDesplegable(4, 'Proveedores', '<p>cuerpo</p>');

        self::assertStringContainsString('<a data-toggle="collapse" href="#collapse4">Proveedores</a>', $html);
        self::assertStringContainsString('<div id="collapse4" class="panel-collapse collapse"><div class="panel-body"><p>cuerpo</p></div>', $html);
    }

    /**
     * @comportamiento La configuración de columnas del listado dice si una columna se enseña; las
     *   marcadas pasan al desplegable de búsqueda, con la de por defecto delante, que no se puede cambiar.
     */
    public function test_laConfiguracionDeColumnasDelListado(): void
    {
        $lista = [
            (object) ['nombre' => 'articulo_name', 'valor' => 'Si', 'descripcion' => 'Nombre', 'buscar_default' => 'Si'],
            (object) ['nombre' => 'codBarras', 'valor' => 'Si', 'descripcion' => 'Código'],
            (object) ['nombre' => 'crefTienda', 'valor' => 'No', 'descripcion' => 'Referencia'],
        ];

        $html = \HtmlListadoCheckMostrar($lista);

        self::assertSame('Si', \MostrarColumnaConfiguracion($lista, 'codBarras'));
        self::assertNull(\MostrarColumnaConfiguracion($lista, 'otra'));
        self::assertSame('articulo_name', $html['campo_defecto']);
        self::assertSame('<option value="articulo_name">Nombre</option><option value="codBarras">Código</option>', $html['htmlOption']);
        self::assertStringContainsString('name="codBarras" value="Si" checked  onchange="GuardarConfiguracion(this)"', $html['htmlCheck']);
        self::assertStringNotContainsString('name="articulo_name"', $html['htmlCheck']);
    }

    /** @comportamiento Con dos columnas de búsqueda por defecto seguidas, la configuración avisa del error. */
    public function test_dosColumnasPorDefectoSeguidasSeAvisan(): void
    {
        $lista = [
            (object) ['nombre' => 'articulo_name', 'valor' => 'Si', 'descripcion' => 'Nombre', 'buscar_default' => 'Si'],
            (object) ['nombre' => 'codBarras', 'valor' => 'Si', 'descripcion' => 'Código', 'buscar_default' => 'Si'],
        ];

        self::assertArrayHasKey('error', \HtmlListadoCheckMostrar($lista));
    }

    /**
     * @comportamiento Lo que llega al guardar la ficha se ordena: el IVA se toma de su número, el
     *   número de producto solo si es una modificación, los códigos de barras sin los vacíos, los
     *   proveedores con referencia o coste, las familias y el resto tal cual; un nombre vacío se avisa.
     */
    public function test_loQueLlegaAlGuardarLaFichaSeOrdena(): void
    {
        $post = [
            'cref_tienda_principal' => 'KIWI-1', 'id' => '15', 'articulo_name' => 'Kiwi', 'idIva' => '2', 'check_pro' => '3',
            'codBarras_0' => '8410000000017', 'codBarras_1' => ' ', 'idProveedor_3' => '3', 'prov_cref_3' => 'H-1', 'prov_coste_3' => '1.2',
            'idFamilias_7' => '7', 'pvpCiva' => '2.42', 'estado' => 'Activo',
        ];

        $datos = \prepararandoPostProducto($post, $this->claseDeArticulos());
        $sinNombre = \prepararandoPostProducto(['cref_tienda_principal' => '', 'articulo_name' => '  '], $this->claseDeArticulos());

        self::assertSame(55, $datos['idTienda']);
        self::assertSame('KIWI-1', $datos['refProducto']);
        self::assertSame('15', $datos['idArticulo']);
        self::assertSame('10.00', $datos['iva']);
        self::assertSame('3', $datos['idProveedor']);
        self::assertSame(['8410000000017'], $datos['codBarras']);
        self::assertSame([['idArticulo' => '15', 'idProveedor' => '3', 'crefProveedor' => 'H-1', 'coste' => '1.2']], $datos['proveedores_costes']);
        self::assertSame([['idFamilia' => '7']], $datos['familias']);
        self::assertSame('2.42', $datos['pvpCiva']);
        self::assertArrayNotHasKey('prov_cref_3', $datos);
        self::assertSame('danger', $sinNombre['comprobaciones'][0]['tipo']);
    }

    /** @comportamiento La selección de productos se guarda en la sesión: se marca, se desmarca, se alterna y se vacía. */
    public function test_laSeleccionDeProductosEnLaSesion(): void
    {
        $_SESSION = [];

        \productosSesion(4, 'seleccionar');
        \productosSesion(4, 'seleccionar');
        \productosSesion(5, 'alternar');
        $tras = \productosSesion(4, 'NoSeleccionar');
        $alternado = \productosSesion(5, 'alternar');

        self::assertSame([5], $tras['productos_seleccionados']);
        self::assertSame(1, $tras['Nitems']);
        self::assertSame([], $alternado['productos_seleccionados']);
        \productosSesion(6, 'seleccionar');
        \eliminarSeleccion();
        self::assertSame([], $_SESSION['productos_seleccionados']);
    }

    /** @comportamiento El buscador de proveedores pinta hasta diez, con los descartados aparte; sin proveedores, lo dice. */
    public function test_elBuscadorDeProveedores(): void
    {
        $proveedores = [];
        for ($i = 1; $i <= 12; $i++) {
            $proveedores[] = ['idProveedor' => (string) $i, 'nombrecomercial' => "Huerta {$i}", 'razonsocial' => "Huerta {$i} SL", 'nif' => 'B0000'];
        }

        $con = \htmlBuscarProveedor('huerta', 'producto', $proveedores, [['nombrecomercial' => 'Ya', 'razonsocial' => 'Ya SL']]);
        $sin = \htmlBuscarProveedor('nada', 'producto', null, []);

        self::assertSame(12, $con['encontrados']);
        self::assertStringContainsString('<span>10 proveedores de 12</span>', $con['html']);
        self::assertSame(10, substr_count($con['html'], 'class="FilaModal"'));
        self::assertStringContainsString("seleccionProveedor('producto' , '1')", $con['html']);
        self::assertStringContainsString('<p>Ya - Ya SL</p>', $con['html']);
        self::assertStringContainsString('No existe ningun proveedor valido', $sin['html']);
    }

    /**
     * @comportamiento Las etiquetas se reparten por columnas y hojas según su tamaño, con la fecha, el
     *   número, el nombre, el precio con IVA y la referencia y los códigos; con la tecla o la
     *   referencia, si se piden.
     */
    public function test_lasEtiquetasSeRepartenPorColumnasYHojas(): void
    {
        $producto = ['idArticulo' => '15', 'articulo_name' => 'Kiwi', 'pvpCiva' => '2.42', 'cref_tienda_principal' => 'KIWI-1',
            'codBarras' => ['8410000000017'], 'numEtiquetas' => 3, 'plu' => '22', 'proveedor_principal' => ['idProveedor' => '3']];

        $a7 = \ImprimirEtiquetas([$producto], 'A7')['html'];
        $conTecla = \ImprimirEtiquetas([$producto + ['numEtiquetas' => 1]], 'A5', 2)['html'];
        $conReferencia = \ImprimirEtiquetas([['numEtiquetas' => 1] + $producto], 'A9', 3)['html'];

        self::assertSame(3, substr_count($a7, '<font size="80 em">2,42</font>€'));
        self::assertSame(2, substr_count($a7, '<tr>'));
        self::assertStringContainsString('<td></td></tr></table>', $a7);
        self::assertStringContainsString('Id: 15 Prov:3', $a7);
        self::assertStringContainsString('Ref: KIWI-1 Codbarras: 8410000000017', $a7);
        self::assertStringContainsString('Tecla:<br>', $conTecla);
        self::assertStringContainsString('Referencia:<br>', $conReferencia);
    }

    /** @comportamiento El proveedor al que se compró por última vez es el de la fecha más reciente, y su coste, el último. */
    public function test_elUltimoProveedorEsElDeLaFechaMasReciente(): void
    {
        $resultado = \comprobarUltimaCompraProveedor([
            ['idProveedor' => '3', 'coste' => '1.0', 'fechaActualizacion' => '2026-01-01'],
            ['idProveedor' => '4', 'coste' => '1.5', 'fechaActualizacion' => '2026-02-01'],
        ]);

        self::assertSame('1.5', $resultado['coste_ultimo']);
        self::assertSame('Si', $resultado['proveedores'][1]['ultimo_pro']);
        self::assertArrayNotHasKey('ultimo_pro', $resultado['proveedores'][0]);
        self::assertSame(0, \comprobarUltimaCompraProveedor([])['coste_ultimo']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/funciones.php:934-958
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El último coste es el del proveedor de la fecha más reciente, también si es un
     *   precio de tarifa, que el proveedor propone y aún no se ha comprado.
     * @que-deberia-ocurrir Que el último coste sea el de la última compra: una tarifa posterior no lo
     *   sustituye.
     * @por-que-ocurre La función compara solo las fechas, sin mirar el estado del coste de cada
     *   proveedor.
     * @como-deberia-funcionar Tomar el coste de la última compra, y el último conocido solo si el
     *   producto nunca se compró.
     */
    public function test_unaTarifaPosteriorALaUltimaCompraNoEsElUltimoCoste(): void
    {
        $resultado = \comprobarUltimaCompraProveedor([
            ['idProveedor' => '3', 'coste' => '1.0', 'fechaActualizacion' => '2026-01-01', 'estado' => 'Activo'],
            ['idProveedor' => '4', 'coste' => '1.5', 'fechaActualizacion' => '2026-02-01', 'estado' => 'Tarifa'],
        ]);

        self::assertSame('1.0', $resultado['coste_ultimo']);
    }

    /** @comportamiento Los modales de familias y de estados llevan sus opciones y su botón según desde dónde se abren. */
    public function test_losModalesDeFamiliasYDeEstados(): void
    {
        $familias = [['idFamilia' => '7', 'familiaNombre' => 'Frutas']];

        $desdeListado = \modalAutocompleteFamilias($familias, 0, 'ListadoProductos');
        $desdeFicha = \modalAutocompleteFamilias($familias, 15, 'producto');
        $estados = \modalAutocompleteEstadoProductos([4, 5], [['estado' => 'Activo'], ['estado' => 'Baja']]);

        self::assertStringContainsString('<option value="7">Frutas</option>', $desdeListado);
        self::assertStringContainsString('<p id="botonEnviar2"></p>', $desdeListado);
        self::assertStringContainsString('<p id="botonEnviar"></p>', $desdeFicha);
        self::assertStringContainsString('value="4,5" id="idProductosModal"', $estados);
        self::assertStringContainsString('<option value="Baja">Baja</option>', $estados);
    }

    /** @comportamiento El tipo de producto marca unidad o peso; uno desconocido, unidad. */
    public function test_elTipoDeProducto(): void
    {
        self::assertStringContainsString('<option value="peso" selected="">', \htmlTipoProducto('peso'));
        self::assertStringContainsString('<option value="unidad" selected="">', \htmlTipoProducto('unidad'));
        self::assertStringContainsString('<option value="unidad" selected="">', \htmlTipoProducto('otro'));
    }

    /** @comportamiento El resumen del borrado lista los productos borrados y los que no, con sus motivos. */
    public function test_elResumenDelBorrado(): void
    {
        $html = \construirHTMLEliminarProductos(
            [['id' => '4', 'nombre' => 'Kiwi']],
            [['id' => '5', 'nombre' => 'Pera', 'comprobaciones' => [['mensaje' => 'Tickets ticketslinea: 1']]]]
        );

        self::assertStringContainsString('<td>4</td><td>Kiwi</td>', $html);
        self::assertStringContainsString('<td>5 - Pera</td>', $html);
        self::assertStringContainsString('<li>Tickets ticketslinea: 1</li>', $html);
        self::assertStringContainsString('Productos eliminados: 0', \construirHTMLEliminarProductos([], []));
    }

    /** @comportamiento Las cuentas del mayor en unidades: entradas, salidas y existencia, en total, en especiales y sin especiales. */
    public function test_lasCuentasDelMayorEnUnidades(): void
    {
        $meses = \guardarDatosUnidades(['entrega' => 10, 'salida' => 4], ['entrega' => 2, 'salida' => 1], 0);

        self::assertSame([['10', '4', '6'], ['2', '1', '1'], ['8', '3', '5']], $meses);
    }

    /** @comportamiento Las tablas del mayor pintan los subtotales del mes, los totales y la tabla lateral, en ámbar el mes sin existencia. */
    public function test_lasTablasDelMayor(): void
    {
        $mes = ['cantidades' => [['10', '4', '6']], 'importes' => [['12', '20', '8']]];
        $total = ['entrega' => 10, 'salida' => 4, 'compras' => 12, 'ventas' => 20, 'beneficio' => 8,
            'entregaES' => 0, 'salidaES' => 0, 'comprasES' => 0, 'ventasES' => 0, 'beneficioES' => 0];

        $subtotal = \tablasSubMes('colorSubtotal', $mes, 0);
        $totales = \tablaTotal('tabla', $total, 0);
        $lateral = \tablasLateral(['2026-03' => ['cantidades' => [['10', '4', '6']]], '2026-04' => ['cantidades' => [['0', '1', '-1']]]], 'cantidades', 0);
        $suma = array_reduce([['entrega' => 2, 'salida' => 1, 'compras' => 3, 'ventas' => 4], ['entrega' => 1, 'salida' => 1, 'compras' => 1, 'ventas' => 1]], 'sumar_reduce');

        self::assertStringContainsString('<td><b>8.00 €</b></td>', $subtotal);
        self::assertStringContainsString('Beneficio = 8.00 €', $totales);
        self::assertStringContainsString('<tr><td>2026-03</td><td>10</td>', $lateral);
        self::assertStringContainsString('<tr class="bg-warning"><td>2026-04</td>', $lateral);
        self::assertSame(['entrega' => 3, 'salida' => 2, 'compras' => 4, 'ventas' => 5], $suma);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/funciones.php:1168-1190
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy En el mayor, las compras de los movimientos especiales en importe se toman de sus
     *   unidades: el importe de compras sin especiales y el de compras especiales salen mal.
     * @que-deberia-ocurrir Que el importe de compras especiales sea el de sus compras.
     * @por-que-ocurre Las cuentas de importes usan las unidades de entrada de los especiales donde
     *   deberían usar su importe de compras.
     * @como-deberia-funcionar Usar el importe de compras de los especiales.
     */
    public function test_lasComprasEspecialesDelMayorEnImporteSonSuImporte(): void
    {
        $meses = \guardarDatosImportes(['compras' => 100, 'ventas' => 150], ['entrega' => 2, 'compras' => 20, 'ventas' => 30], 2);

        self::assertSame(['20.00', '30.00', '10.00'], $meses[1]);
        self::assertSame(['80.00', '120.00', '40.00'], $meses[2]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/funciones.php:660-675
     * @codigo-afectado modulos/mod_producto/funciones.php:715-729
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy El buscador de proveedores escribe el texto buscado tal como llega: un texto con
     *   un script se ejecuta en la página de quien busca.
     * @que-deberia-ocurrir Que el texto buscado se escriba como texto.
     * @por-que-ocurre La caja de búsqueda y el aviso de que no hay proveedores escriben el texto sin
     *   escapar.
     * @como-deberia-funcionar Escapar el texto al escribirlo en la página.
     */
    public function test_elBuscadorDeProveedoresEscribeLaBusquedaComoTexto(): void
    {
        $html = \htmlBuscarProveedor('"><script>x=1</script>', 'producto', null, [])['html'];

        self::assertStringNotContainsString('<script>x=1</script>', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/funciones.php:229-241
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy Un proveedor cuyo nombre lleva el cierre de un script corta el script de la
     *   ficha del producto, y lo que sigue al nombre se ejecuta en la página.
     * @que-deberia-ocurrir Que el nombre del proveedor llegue al JavaScript como texto.
     * @por-que-ocurre La lista de proveedores se escribe dentro de una etiqueta de script sin escapar
     *   las etiquetas de su texto.
     * @como-deberia-funcionar Escapar las etiquetas al convertir la lista para el script.
     */
    public function test_elNombreDeUnProveedorNoCortaElScriptDeLaFicha(): void
    {
        $proveedores = [['idProveedor' => '3', 'nombrecomercial' => 'a</script><script>x=1</script>', 'razonsocial' => 'b', 'coste' => '1', 'estado' => 'Activo', 'fechaActualizacion' => '']];

        $html = \htmlTablaProveedoresCostes($proveedores, 'KO');

        self::assertStringNotContainsString('</script><script>x=1', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/funciones.php:2-15
     * @codigo-afectado modulos/mod_producto/funciones.php:19-30
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy La ficha del producto escribe los datos guardados tal como están: una familia
     *   cuyo nombre lleva un script lo ejecuta en la ficha, y un código de barras con comillas rompe su
     *   caja.
     * @que-deberia-ocurrir Que los datos se escriban como texto.
     * @por-que-ocurre Las líneas de la ficha escriben cada valor sin escapar.
     * @como-deberia-funcionar Escapar cada valor al escribirlo en la página.
     */
    public function test_laFichaEscribeLosDatosGuardadosComoTexto(): void
    {
        $familias = \htmlTablaFamilias([['idFamilia' => '7', 'familiaNombre' => '<script>x=1</script>']], 15);
        $codigo = \htmlLineaCodigoBarras(0, '84"><script>x=2</script>');

        self::assertStringNotContainsString('<script>x=1</script>', $familias);
        self::assertStringNotContainsString('<script>x=2</script>', $codigo);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/funciones.php:546-566
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Guardar la ficha con un proveedor sin referencia ni coste, y sin proveedor
     *   principal, deja avisos de PHP.
     * @que-deberia-ocurrir Que el proveedor se descarte con su aviso, sin avisos de PHP.
     * @por-que-ocurre El aviso cita una variable que no existe, y la marca del principal se lee sin
     *   comprobar que llegó.
     * @como-deberia-funcionar Quitar la variable del aviso y comprobar la marca antes de leerla.
     */
    public function test_unProveedorSinDatosSeDescartaSinAvisosDePhp(): void
    {
        $datos = \prepararandoPostProducto(
            ['cref_tienda_principal' => '', 'id' => '15', 'idProveedor_3' => '3', 'prov_cref_3' => '', 'prov_coste_3' => '0'],
            $this->claseDeArticulos()
        );

        self::assertSame([], $datos['proveedores_costes']);
        self::assertSame('warning', $datos['comprobaciones'][0]['tipo']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/funciones.php:506-507
     * @codigo-afectado modulos/mod_producto/funciones.php:592-596
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Una petición de guardado de la ficha que trae otra tienda cambia la tienda de
     *   los datos: el alta de un producto deja su precio en esa tienda.
     * @que-deberia-ocurrir Que la tienda sea la de la instalación, llegue lo que llegue.
     * @por-que-ocurre La tienda se pone antes de recorrer lo recibido, y cualquier campo que no se
     *   conoce se copia tal cual encima.
     * @como-deberia-funcionar Copiar solo los campos que la ficha envía, y la tienda después.
     */
    public function test_laTiendaDeLosDatosNoLaCambiaLaPeticion(): void
    {
        $datos = \prepararandoPostProducto(['cref_tienda_principal' => '', 'idTienda' => '99'], $this->claseDeArticulos());

        self::assertSame(55, $datos['idTienda']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/funciones.php:464-486
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Con dos columnas de búsqueda por defecto, el error solo se avisa si van seguidas
     *   al principio: con otra columna delante, la segunda se toma como la de por defecto sin avisar.
     * @que-deberia-ocurrir Que dos columnas por defecto se avisen siempre.
     * @por-que-ocurre El contador de columnas por defecto es la misma variable que guarda el texto de
     *   las casillas, que lo sobrescribe.
     * @como-deberia-funcionar Usar una variable para cada cosa.
     */
    public function test_dosColumnasPorDefectoSeAvisanSiempre(): void
    {
        $lista = [
            (object) ['nombre' => 'crefTienda', 'valor' => 'No', 'descripcion' => 'Referencia'],
            (object) ['nombre' => 'articulo_name', 'valor' => 'Si', 'descripcion' => 'Nombre', 'buscar_default' => 'Si'],
            (object) ['nombre' => 'codBarras', 'valor' => 'Si', 'descripcion' => 'Código', 'buscar_default' => 'Si'],
        ];

        self::assertArrayHasKey('error', \HtmlListadoCheckMostrar($lista));
    }

    /** Una clase de artículos de prueba: la tienda 55 y el IVA de cada número. */
    private function claseDeArticulos(): object
    {
        return new class () {
            public function GetIdTienda(): int
            {
                return 55;
            }

            public function GetUnIva($id): string
            {
                return ['1' => '21.00', '2' => '10.00'][$id] ?? '';
            }
        };
    }
}

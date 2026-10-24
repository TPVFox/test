<?php

/**
 * Las funciones de pintado y de preparación de las compras (`modulos/mod_compras/funciones.php`) que no
 * consultan la base: los listados de proveedores y de productos de la búsqueda, la línea de un producto
 * del pedido, del albarán y de la factura, la preparación de las líneas y de los importes, la línea y la
 * cabecera de un documento adjunto, la ventana de los adjuntos y la de cambiar el estado, los totales, el
 * formulario del correo y las incidencias adjuntas.
 *
 * El fichero declara funciones con el mismo nombre que las de la venta: cada caso corre en su propio
 * proceso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModCompras;

use PHPUnit\Framework\TestCase;
use TPVFox\Test\CargaAislada;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesComprasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_compras/funciones.php');
    }

    /** @param array<string, string> $cambios */
    private function proveedor(int $id, array $cambios = []): array
    {
        return $cambios + ['idProveedor' => (string) $id, 'nombrecomercial' => 'Frutas ' . $id, 'razonsocial' => 'Frutas ' . $id . ' SL', 'nif' => 'B0000000' . $id, 'estado' => 'Activo'];
    }

    /** @param array<string, mixed> $cambios */
    private function producto(array $cambios = []): array
    {
        return $cambios + ['idArticulo' => '5', 'articulo_name' => 'Pera conferencia', 'codBarras' => '8400000000017', 'ultimoCoste' => '1.20', 'crefTienda' => '1001', 'ref_prov' => 'PER-1', 'coste' => '1.10', 'fechaActualizacion' => '2026-01-10', 'iva' => '4.00', 'estadoTabla' => 'Activo'];
    }

    /** @param array<string, mixed> $cambios */
    private function linea(array $cambios = []): array
    {
        return $cambios + ['idArticulo' => '5', 'cref' => '1001', 'ccodbar' => '8400000000017', 'cdetalle' => 'Pera conferencia', 'ref_prov' => '', 'ultimoCoste' => '1.5', 'nunidades' => '2', 'ncant' => '2', 'iva' => '4', 'estado' => 'Activo', 'nfila' => 3];
    }

    /**
     * @comportamiento El listado de proveedores de la búsqueda pinta cada uno con su nombre y su razón
     *   como texto, y la orden de elegirlo; los inactivos van en rojo con su aviso, y sin ninguno lo dice.
     */
    public function test_elListadoDeProveedoresDeLaBusqueda(): void
    {
        $con = \htmlProveedores('frutas', 'albaran', 'id_proveedor', [$this->proveedor(7, ['nombrecomercial' => 'Frutas <b>7</b>']), $this->proveedor(8, ['estado' => 'inactivo'])]);
        $sin = \htmlProveedores('nada', 'pedido', 'id_proveedor', []);

        self::assertSame(2, $con['encontrados']);
        self::assertStringContainsString('<label>Busqueda Proveedor en albaran</label>', $con['html']);
        self::assertStringContainsString("onclick=\"buscarProveedor('albaran' , 'id_proveedor', 7, 'popup');\"", $con['html']);
        self::assertStringContainsString('<td>Frutas &lt;b&gt;7&lt;/b&gt;</td>', $con['html']);
        self::assertStringContainsString('class="FilaModal danger"', $con['html']);
        self::assertStringContainsString('Recuerda que los proveedores INACTIVOS están Rojo', $con['html']);
        self::assertStringContainsString('No se encontro ningun proveedor, para esa busqueda', $sin['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/funciones.php:25-27, :32, :52-55
     *
     * @group defecto
     * @group presentacion
     * @group bajo
     *
     * @que-ocurre-hoy Con más de diez proveedores, el listado dice que enseña diez, pero pinta once.
     * @que-deberia-ocurrir Que pinte los diez que dice.
     * @por-que-ocurre Corta después de pintar el de índice 10, que es el undécimo.
     * @como-deberia-funcionar Cortar al llegar a diez pintados.
     */
    public function test_elListadoDeProveedoresEnsenaDiezComoMucho(): void
    {
        $proveedores = array_map(fn (int $i): array => $this->proveedor($i), range(1, 12));

        $listado = \htmlProveedores('frutas', 'albaran', 'id_proveedor', $proveedores);

        self::assertStringContainsString('<span> Se muestra 10 proveedores de 12</span>', $listado['html']);
        self::assertSame(10, substr_count($listado['html'], 'class="FilaModal'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/funciones.php:20-23, :47, :151-155
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Lo buscado se pinta sin escapar en la caja de búsqueda de los listados de proveedores
     *   y de productos, y el NIF del proveedor también: unas comillas cierran el atributo y lo que sigue
     *   entra en la página.
     * @que-deberia-ocurrir Que lo buscado y el NIF se pinten como texto.
     * @por-que-ocurre Se concatenan sin escapar.
     * @como-deberia-funcionar Escaparlos al pintarlos.
     */
    public function test_loBuscadoSePintaComoTextoEnLosListados(): void
    {
        $buscado = '"><script>alert(1)</script>';

        $proveedores = \htmlProveedores($buscado, 'albaran', 'id_proveedor', [$this->proveedor(7, ['nif' => '<i>B7</i>'])]);
        $productos = \htmlProductos([], 'Codbarras', 'ac.codBarras', $buscado, 'albaran');

        self::assertStringNotContainsString('<script>alert(1)</script>', $proveedores['html']);
        self::assertStringNotContainsString('<i>B7</i>', $proveedores['html']);
        self::assertStringNotContainsString('<script>alert(1)</script>', $productos);
    }

    /**
     * @comportamiento El listado de productos de la búsqueda pinta cada uno con su referencia —la de la
     *   tienda o la del proveedor, según la caja—, su nombre como texto, su último coste y el del proveedor,
     *   y la orden de elegirlo con sus datos; enseña diez como mucho y dice de cuántos.
     */
    public function test_elListadoDeProductosDeLaBusqueda(): void
    {
        $productos = array_map(fn (int $i): array => $this->producto(['idArticulo' => (string) $i, 'articulo_name' => 'Pera <' . $i . '>']), range(1, 12));

        $tienda = \htmlProductos($productos, 'Referencia', 'at.crefTienda', 'pera', 'albaran');
        $proveedor = \htmlProductos([$this->producto()], 'ReferenciaPro', 'p.crefProveedor', 'per', 'pedido');

        self::assertStringContainsString("cajaBusquedaproductos.parametros.campo='at.crefTienda';", $tienda);
        self::assertStringContainsString('<span>10 productos de 12</span>', $tienda);
        self::assertSame(10, substr_count($tienda, 'class="FilaModal"'));
        self::assertStringContainsString('<td>1001</td><td>Pera &lt;1&gt;</td><td >1.20</td><td >1.10</td>', $tienda);
        self::assertStringContainsString("onclick=\"escribirProductoSeleccionado('ReferenciaPro','1001','Pera conferencia','4.00','8400000000017','1.20',5, 'pedido' , 'PER-1' , '1.10' , '2026-01-10');\"", $proveedor);
        self::assertStringContainsString('<td>PER-1</td>', $proveedor);
    }

    /** @comportamiento Un producto de baja se pinta en rosa y no se puede elegir; uno sin coste ni referencia del proveedor, atenuado; sin productos, un aviso según haya búsqueda o no. */
    public function test_losProductosDeBajaSinProveedorYLaBusquedaVacia(): void
    {
        $baja = \htmlProductos([$this->producto(['estadoTabla' => 'Baja'])], 'Referencia', 'at.crefTienda', 'pera', 'albaran');
        $sinProveedor = \htmlProductos([$this->producto(['ref_prov' => null, 'coste' => null])], 'Referencia', 'at.crefTienda', 'pera', 'albaran');
        $vacia = \htmlProductos([], 'Referencia', 'at.crefTienda', '', 'albaran');
        $nada = \htmlProductos([], 'Referencia', 'at.crefTienda', 'pera', 'albaran');

        self::assertStringContainsString('style="background-color:#f5b7b1;" class="FilaModal"  >', $baja);
        self::assertStringNotContainsString('escribirProductoSeleccionado', $baja);
        self::assertStringContainsString('style="opacity:0.5;" class="FilaModal" onclick=', $sinProveedor);
        self::assertStringContainsString('Pon las palabras para buscar productos', $vacia);
        self::assertStringContainsString('No se encontrado nada con esa busqueda', $nada);
    }

    /**
     * @comportamiento La línea de un producto del pedido deja editar su descripción; la del albarán y la
     *   de la factura, su coste, con el documento de origen —el pedido en el albarán, el albarán en la
     *   factura—. Lleva unidades, importe e importe con IVA, y la orden de eliminarla.
     */
    public function test_laLineaDeUnProductoSegunElDocumento(): void
    {
        $pedido = \htmlLineaProducto($this->linea(), 'pedido');
        $albaran = \htmlLineaProducto($this->linea(['idpedpro' => '12']), 'albaran');
        $factura = \htmlLineaProducto((object) $this->linea(['numAlbaran' => '31']), 'factura');

        self::assertStringContainsString('id="Descripcion_Fila_3" type="text" data-obj="Descripcion_Fila" name="descripcion[]" value="Pera conferencia"', $pedido['html']);
        self::assertStringNotContainsString('ultimo_coste_3', $pedido['html']);
        self::assertStringContainsString('<td class="Ndocumento">12</td>', $albaran['html']);
        self::assertStringContainsString('id="ultimo_coste_3" data-obj="ultimo_coste"', $albaran['html']);
        self::assertStringContainsString('value="1.5000"', $albaran['html']);
        self::assertStringContainsString('<td class="Ndocumento">31</td>', $factura['html']);
        self::assertStringContainsString('value="2.000"', $pedido['html']);
        self::assertStringContainsString('<td  id="N3_Importe" class="importe" >3.00</td><td class="ImporteIva" id="N3_ImporteIva">3.12</td>', $pedido['html']);
        self::assertStringContainsString("eliminarFila(3 , 'pedido');", $pedido['html']);
        self::assertStringContainsString('<td class="codbarras">8400000000017</td>', $pedido['html']);
    }

    /** @comportamiento Una línea eliminada se pinta tachada, bloqueada y con la orden de recuperarla; en solo lectura, sin ninguna orden; la referencia del proveedor, con su botón de cambiarla. */
    public function test_laLineaEliminadaEnSoloLecturaYConReferenciaDelProveedor(): void
    {
        $eliminada = \htmlLineaProducto($this->linea(['estado' => 'Eliminado']), 'albaran');
        $lectura = \htmlLineaProducto($this->linea(['ref_prov' => 'PER-1']), 'albaran', 'readonly');
        $conReferencia = \htmlLineaProducto($this->linea(['ref_prov' => 'PER-1']), 'albaran');

        self::assertStringContainsString('<tr id="Row3"  class="tachado" >', $eliminada['html']);
        self::assertStringContainsString("retornarFila(3, 'albaran');", $eliminada['html']);
        self::assertStringContainsString('disabled', $eliminada['html']);
        self::assertStringNotContainsString('onclick=" eliminarFila', $lectura['html']);
        self::assertStringNotContainsString('permitirModificarReferenciaProveedor', $lectura['html']);
        self::assertStringContainsString('value="PER-1"', $lectura['html']);
        self::assertStringContainsString("permitirModificarReferenciaProveedor('Proveedor_Fila_3')\" style=\"text-align: right\"", $conReferencia['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/funciones.php:229-233
     *
     * @group defecto
     * @group calculo
     * @group medio
     *
     * @que-ocurre-hoy Una línea de mil euros o más pinta su importe con separador de miles, y el importe
     *   con IVA lo calcula sobre ese texto: sale el de un euro.
     * @que-deberia-ocurrir Que el importe con IVA sea el de la línea.
     * @por-que-ocurre El importe se formatea con separador de miles antes de calcular el IVA sobre él.
     * @como-deberia-funcionar Calcular sobre el número y formatear después.
     */
    public function test_unaLineaDeMilEurosOMasTieneSuImporteConIva(): void
    {
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        });
        try {
            $linea = \htmlLineaProducto($this->linea(['ultimoCoste' => '500', 'nunidades' => '2', 'iva' => '21']), 'albaran');
        } finally {
            restore_error_handler();
        }

        self::assertStringContainsString('id="N3_ImporteIva">1,210.00</td>', $linea['html']);
        self::assertSame([], $avisos);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/funciones.php:241, :294-301, :316, :329-331
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy La descripción, la referencia y la referencia del proveedor de una línea se pintan sin
     *   escapar: un nombre de producto con etiquetas entra en la página del documento.
     * @que-deberia-ocurrir Que se pinten como texto.
     * @por-que-ocurre Se concatenan sin escapar.
     * @como-deberia-funcionar Escaparlos al pintarlos.
     */
    public function test_losDatosDeLaLineaSePintanComoTexto(): void
    {
        $linea = \htmlLineaProducto($this->linea(['cdetalle' => 'Pera <b>dulce</b>', 'cref' => '<i>1001</i>', 'ref_prov' => '"><b>x</b>']), 'albaran');

        self::assertStringNotContainsString('<b>dulce</b>', $linea['html']);
        self::assertStringNotContainsString('<i>1001</i>', $linea['html']);
        self::assertStringNotContainsString('"><b>x</b>', $linea['html']);
    }

    /** @comportamiento Las líneas guardadas se preparan para el documento: su estado, su coste sin IVA como último coste, su importe, y el pedido o el albarán de origen. */
    public function test_lasLineasGuardadasSePreparanParaElDocumento(): void
    {
        $lineas = \modificarArrayProductos([
            ['ccodbar' => '84', 'cdetalle' => 'Pera', 'cref' => '1001', 'ref_prov' => 'PER-1', 'estadoLinea' => 'Activo', 'idArticulo' => '5', 'costeSiva' => '1.5', 'iva' => '4', 'ncant' => '2', 'nfila' => '1', 'nunidades' => '2', 'Numpedpro' => '12'],
            ['ccodbar' => '', 'cdetalle' => 'Vino', 'cref' => '2001', 'ref_prov' => '', 'estadoLinea' => 'Eliminado', 'idArticulo' => '6', 'costeSiva' => '10', 'iva' => '21', 'ncant' => '1', 'nfila' => '2', 'nunidades' => '1', 'Numalbpro' => '31'],
        ]);

        self::assertSame(['Activo', 3.0, '1.5', '12'], [$lineas[0]['estado'], $lineas[0]['importe'], $lineas[0]['ultimoCoste'], $lineas[0]['numPedido']]);
        self::assertSame(['Eliminado', '31'], [$lineas[1]['estado'], $lineas[1]['numAlbaran']]);
        self::assertArrayNotHasKey('numAlbaran', $lineas[0]);
    }

    /** @comportamiento Los importes pagados de una factura se preparan con lo que queda pendiente tras cada uno. */
    public function test_losImportesPagadosLlevanLoPendiente(): void
    {
        $importes = \modificarArraysImportes([
            ['importe' => '40', 'FechaPago' => '2026-02-01', 'Referencia' => 'T1', 'idFormasPago' => '1'],
            ['importe' => '25.5', 'FechaPago' => '2026-03-01', 'Referencia' => 'T2', 'idFormasPago' => '2'],
        ], 100);

        self::assertSame(['60.00', '34.50'], array_column($importes, 'pendiente'));
        self::assertSame(['importe' => '40', 'fecha' => '2026-02-01', 'referencia' => 'T1', 'forma' => '1', 'pendiente' => '60.00'], $importes[0]);
    }

    /**
     * @comportamiento La línea de un adjunto lleva el enlace a su documento —el albarán en la factura, el
     *   pedido en el albarán—, su fecha, su total sin y con IVA y la orden de quitarlo; uno quitado, tachado
     *   y con la de recuperarlo; al verlo, sin orden.
     */
    public function test_laLineaDeUnAdjunto(): void
    {
        $factura = \lineaAdjunto(['estado' => 'activo', 'NumAdjunto' => '31', 'nfila' => 1, 'Su_numero' => 'A-7', 'fecha' => '2026-02-03 10:00:00', 'totalSiva' => '10', 'total' => '12.10'], 'factura');
        $albaran = \lineaAdjunto(['estado' => 'eliminado', 'NumAdjunto' => '12', 'nfila' => 2, 'fecha' => '2026-02-03', 'total' => '5'], 'albaran');
        $ver = \lineaAdjunto(['estado' => 'activo', 'NumAdjunto' => '12', 'nfila' => 1, 'fecha' => '2026-02-03', 'total' => '5'], 'albaran', 'ver');

        self::assertStringContainsString('<td><a href="./albaran.php?id=31&accion=ver">31</a></td><td>A-7</td><td>03-02-2026</td><td>10.00</td><td>12.10</td>', $factura['html']);
        self::assertStringContainsString("eliminarAdjunto(31 , 'factura' , 1);", $factura['html']);
        self::assertStringContainsString('<tr id="lineaP2"  class="tachado" >', $albaran['html']);
        self::assertStringContainsString('<a href="./pedido.php?id=12&accion=ver">12</a>', $albaran['html']);
        self::assertStringContainsString("retornarAdjunto(12, 'albaran', 2);", $albaran['html']);
        self::assertStringContainsString('<td>0.00</td>', $albaran['html']);
        self::assertStringNotContainsString('Adjunto(', $ver['html']);
    }

    /** @comportamiento La cabecera de los productos de un adjunto enlaza su documento y lleva su número del proveedor, su fecha y sus totales. */
    public function test_laCabeceraDeLosProductosDeUnAdjunto(): void
    {
        $albaran = \htmlDatosAdjuntoProductos(['NumAdjunto' => '12', 'fecha' => '2026-02-03', 'total' => '12.10', 'totalSiva' => '10'], 'albaran');
        $factura = \htmlDatosAdjuntoProductos(['NumAdjunto' => '31', 'fecha' => '2026-02-03', 'Su_numero' => 'A-7'], 'factura');

        self::assertStringContainsString('<strong>NºPedido: <a href="./pedido.php?id=12&accion=ver">12</a></strong>', $albaran);
        self::assertStringContainsString('<strong>Total sin IVA:10</strong>', $albaran);
        self::assertStringContainsString('<strong>Total con IVA:12.10</strong>', $albaran);
        self::assertStringContainsString('<strong>NºAlbaran: <a href="./albaran.php?id=31&accion=ver">31</a></strong>', $factura);
        self::assertStringContainsString('<strong>Su número:A-7</strong>', $factura);
    }

    /** @comportamiento La ventana de los pedidos que se pueden añadir a un albarán lleva cada uno con su número, su fecha y su total, y la orden de añadirlo. */
    public function test_laVentanaDeLosPedidosQueSePuedenAnadir(): void
    {
        $ventana = \modalAdjunto([['Numpedpro' => '12', 'Fecha' => '2026-02-03 10:00:00', 'total' => '12.10']], 'albaran', null);

        self::assertStringContainsString("onclick=\"buscarAdjunto('albaran', 12);\"", $ventana['html']);
        self::assertStringContainsString('<td>12</td><td>2026-02-03</td><td>12.10</td>', $ventana['html']);
    }

    /** @comportamiento La ventana de cambiar el estado de varios albaranes ofrece los cinco estados. */
    public function test_laVentanaDeCambiarElEstadoDeLosAlbaranes(): void
    {
        $ventana = \modalSelectEstadoAlbaranes();

        foreach (['Guardado', 'Exportado', 'Importado', 'Facturado', 'Validado'] as $estado) {
            self::assertStringContainsString("<option value=\"{$estado}\">{$estado}</option>", $ventana);
        }
        self::assertStringContainsString('onclick="cambiarEstadoVariosAlbaranes()"', $ventana);
    }

    /** @comportamiento Los totales pintan una fila por tipo de IVA con su base y su cuota, la de los totales y el total; sin desglose, nada. */
    public function test_losTotalesSePintanPorTipoDeIva(): void
    {
        $totales = \htmlTotales(['desglose' => ['21' => ['base' => '20.00', 'iva' => '4.20'], '4' => ['base' => '5.50', 'iva' => '0.22']], 'total' => '29.92']);

        self::assertStringContainsString('<tr id="line21"><td id="tipo21"> 21%</td><td id="base21"> 20.00</td><td id="iva21">4.20</td></tr>', $totales['html']);
        self::assertStringContainsString('<td> Totales </td><td>25.50</td><td>4.42</td>', $totales['html']);
        self::assertStringContainsString('<div class="totalImporte" style="font-size: 3em;">29.92</div>', $totales['html']);
        self::assertSame('', \htmlTotales([])['html']);
    }

    /** @comportamiento El formulario del correo lleva el destinatario de solo lectura, el asunto y el mensaje de la configuración, y el documento que se envía. */
    public function test_elFormularioDelCorreo(): void
    {
        $formulario = \htmlFormularioEmail('compras@example.com', ['asunto' => 'Pedido 12 automatico', 'body' => 'Confirme el pedido'], 12, 'pedido', 55);

        self::assertStringContainsString('value ="compras@example.com" readonly>', $formulario);
        self::assertStringContainsString('value="Pedido 12 automatico">', $formulario);
        self::assertStringContainsString('rows="7">Confirme el pedido</textarea>', $formulario);
        self::assertStringContainsString('<input name="id" type="hidden" value="12"><input name="dedonde" type="hidden" value="pedido"><input name="idTienda" type="hidden" value="55">', preg_replace('/>\s+</', '><', $formulario));
    }

    /** @comportamiento Las incidencias adjuntas se pintan con su fecha, su origen, su estado, su usuario, sus datos y su mensaje. */
    public function test_lasIncidenciasAdjuntasSePintan(): void
    {
        $html = \modalIncidenciasAdjuntas([['fecha_creacion' => '2026-02-03', 'dedonde' => 'albaran', 'estado' => 'No resuelto', 'id_usuario' => '83', 'datos' => '{"id":1}', 'mensaje' => 'Falta una caja']]);

        self::assertStringContainsString('value="2026-02-03" readonly=""', $html);
        self::assertStringContainsString('value="No resuelto" readonly=""', $html);
        self::assertStringContainsString('<textarea rows="4" cols="20" readonly> Falta una caja</textarea>', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/funciones.php:950-971
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El mensaje y los datos de una incidencia se pintan sin escapar en la ventana de las
     *   incidencias adjuntas: lo que un usuario escribe en una incidencia entra en la página de quien la abre.
     * @que-deberia-ocurrir Que se pinten como texto.
     * @por-que-ocurre La plantilla interpola los valores sin escapar.
     * @como-deberia-funcionar Escaparlos al pintarlos.
     */
    public function test_elMensajeDeUnaIncidenciaSePintaComoTexto(): void
    {
        $html = \modalIncidenciasAdjuntas([['fecha_creacion' => '2026-02-03', 'dedonde' => 'albaran', 'estado' => 'No resuelto', 'id_usuario' => '83', 'datos' => '{}', 'mensaje' => '</textarea><script>alert(1)</script>']]);

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }
}

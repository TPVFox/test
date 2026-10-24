<?php

/**
 * Las funciones de las compras (`modulos/mod_compras/funciones.php`) que consultan o escriben en la base:
 * la búsqueda de productos, la ventana de los albaranes que se pueden facturar, la preparación de los
 * adjuntos, cancelar el temporal de un pedido, de un albarán y de una factura, las formas de pago, las
 * incidencias adjuntas y la comprobación de los albaranes guardados de un proveedor.
 *
 * `funciones.php` empieza incluyendo el arranque con una ruta relativa que aquí no resuelve, y las clases
 * de compras incluyen las suyas con rutas relativas a la carpeta del módulo: el fichero se carga como en
 * las pruebas unitarias, y las clases desde la carpeta del módulo. Las funciones tienen el nombre de las de
 * la venta: cada caso corre en su propio proceso. Los documentos de compra se siembran en la transacción del
 * caso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCompras;

use TPVFox\Test\CargaAislada;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesComprasIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        CargaAislada::llamar(function (): void {
            $this->incluirTPVFox('/modulos/mod_compras/funciones.php');
        });
        $this->incluirTPVFox('/controllers/Controladores.php');
        $cwd = (string) getcwd();
        chdir(RUTA_TPVFOX . '/modulos/mod_compras');
        try {
            $this->incluirTPVFox('/modulos/mod_compras/clases/pedidosCompras.php');
            $this->incluirTPVFox('/modulos/mod_compras/clases/albaranesCompras.php');
            $this->incluirTPVFox('/modulos/mod_compras/clases/facturasCompras.php');
            $this->incluirTPVFox('/modulos/mod_incidencias/clases/ClaseIncidencia.php');
        } finally {
            chdir($cwd);
        }
        $this->siembra = new Siembra($this->db);
    }

    /**
     * @comportamiento La búsqueda de productos prueba primero lo idéntico y, si no hay, lo que contiene
     *   todas las palabras; trae el coste y la referencia del proveedor. Desde la caja del listado y por la
     *   descripción, solo lo que contiene.
     */
    public function test_laBusquedaDeProductosPruebaLoIdenticoYDespuesLoQueContiene(): void
    {
        $proveedor = $this->siembra->proveedor('Proveedor de la búsqueda');
        $pera = $this->siembra->articulo('Pera conferencia de compra');
        $peral = $this->siembra->articulo('Pera limonera de compra');
        $this->siembra->articuloProveedor($pera, $proveedor, 1.1, ['crefProveedor' => 'PER-1']);

        $identico = \BuscarProductos('Descripcion', 'a.articulo_name', 'id_input', 'Pera conferencia de compra', $this->db, $proveedor);
        $contiene = \BuscarProductos('Descripcion', 'a.articulo_name', 'id_input', 'pera compra', $this->db, $proveedor);
        $caja = \BuscarProductos('Descripcion', 'a.articulo_name', 'cajaBusqueda', 'Pera conferencia de compra', $this->db, $proveedor);

        self::assertSame([(string) $pera], array_column($identico['datos'], 'idArticulo'));
        self::assertSame(['PER-1', '1.100000'], [$identico['datos'][0]['ref_prov'], $identico['datos'][0]['coste']]);
        self::assertEqualsCanonicalizing([(string) $pera, (string) $peral], array_column($contiene['datos'], 'idArticulo'));
        self::assertCount(1, $caja['sql']);
        self::assertSame(['Pera', 'conferencia', 'de', 'compra'], $identico['palabras']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/funciones.php:71-136
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Lo buscado se pega a la consulta de la búsqueda de productos: unas comillas la
     *   rompen, y lo que sigue cambia la consulta.
     * @que-deberia-ocurrir Que lo buscado sea un dato: unas comillas se buscan como texto.
     * @por-que-ocurre Las palabras, el campo y el proveedor se concatenan en la sentencia.
     * @como-deberia-funcionar Pasarlos como parámetros, con el campo de una lista cerrada.
     */
    public function test_unasComillasEnLoBuscadoSeBuscanComoTexto(): void
    {
        $proveedor = $this->siembra->proveedor('Proveedor de la búsqueda');
        $this->siembra->articulo('Pera "extra" de compra');

        $busqueda = \BuscarProductos('Descripcion', 'a.articulo_name', 'id_input', '"extra"', $this->db, $proveedor);

        self::assertArrayNotHasKey('error', $busqueda);
        self::assertCount(1, $busqueda['datos']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/funciones.php:105-111
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy La búsqueda de productos trae la referencia de la tienda 1, escrita en la consulta,
     *   aunque la principal sea otra.
     * @que-deberia-ocurrir Que traiga la referencia de la tienda principal, o la de la sesión.
     * @por-que-ocurre La consulta une la referencia con `idTienda = 1`.
     * @como-deberia-funcionar Tomar la tienda de la sesión.
     */
    public function test_laBusquedaTraeLaReferenciaDeLaTiendaPrincipal(): void
    {
        $proveedor = $this->siembra->proveedor('Proveedor de la búsqueda');
        $pera = $this->siembra->articulo('Pera de la tienda de compra');
        $tienda = $this->siembra->tiendaPorDefecto();
        $this->db->query("INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES ({$pera}, {$tienda}, 'REF-PRINCIPAL', 'Activo')");

        $busqueda = \BuscarProductos('Descripcion', 'a.articulo_name', 'id_input', 'Pera de la tienda de compra', $this->db, $proveedor);

        self::assertNotSame(1, $tienda);
        self::assertSame('REF-PRINCIPAL', $busqueda['datos'][0]['crefTienda']);
    }

    /** @comportamiento La ventana de los albaranes que se pueden facturar lleva su vencimiento, su forma de pago, su número del proveedor y sus totales. */
    public function test_laVentanaDeLosAlbaranesQueSePuedenFacturar(): void
    {
        $this->db->query("INSERT INTO formasPago (descripcion) VALUES ('Transferencia de compra')");
        $forma = (int) $this->db->insert_id;

        $ventana = \modalAdjunto([['Numalbpro' => '31', 'Fecha' => '2026-02-03', 'FechaVencimiento' => '2026-03-03', 'formaPago' => (string) $forma, 'Su_numero' => 'A-7', 'total' => '12.10', 'totalSiva' => '10.00']], 'factura', $this->db);

        self::assertStringContainsString("onclick=\"buscarAdjunto('factura', 31);\"", $ventana['html']);
        self::assertStringContainsString('<td>31</td><td>2026-02-03</td><td>2026-03-03</td><td>Transferencia de compra</td>', $ventana['html']);
        self::assertStringContainsString('A-7', $ventana['html']);
        self::assertStringContainsString('<td>12.10</td><td>10.00</td></tr>', $ventana['html']);
    }

    /** @comportamiento Los adjuntos de un documento se preparan con los datos de su documento: el pedido del albarán, y el albarán de la factura con su total sin IVA y su número del proveedor. */
    public function test_losAdjuntosSePreparanConLosDatosDeSuDocumento(): void
    {
        $proveedor = $this->siembra->proveedor('Proveedor de los adjuntos');
        $pera = $this->siembra->articulo('Pera de los adjuntos', ['ultimoCoste' => 2.0, 'iva' => 4]);
        $albaran = $this->siembra->entradaProveedor($pera, 5, '2026-02-03', ['idProveedor' => $proveedor, 'suNumero' => 'A-7']);
        $numero = (int) $this->db->query("SELECT Numalbpro FROM albprot WHERE id = {$albaran}")->fetch_row()[0];
        $pedido = $this->pedido($proveedor, 12);

        $deFactura = \modificarArrayAdjunto([['numAlbaran' => $numero, 'idAlbaran' => $numero]], $this->db, 'factura');
        $deAlbaran = \modificarArrayAdjunto([['numPedido' => 12, 'idPedido' => $pedido]], $this->db, 'albaran');

        self::assertSame([$numero, (string) $albaran, (string) $proveedor, '10.40', '10.00', 'A-7', 'activo', 1], [$deFactura[0]['NumAdjunto'], $deFactura[0]['idAdjunto'], $deFactura[0]['idPePro'], $deFactura[0]['total'], $deFactura[0]['totalSiva'], $deFactura[0]['Su_numero'], $deFactura[0]['estado'], $deFactura[0]['nfila']]);
        self::assertSame([12, (string) $pedido, (string) $proveedor, '2026-02-01 00:00:00'], [$deAlbaran[0]['NumAdjunto'], $deAlbaran[0]['idAdjunto'], $deAlbaran[0]['idPePro'], $deAlbaran[0]['fecha']]);
    }

    /** @comportamiento Cancelar el temporal de un pedido lo quita; sin número de temporal, no se hace nada y lo dice. */
    public function test_cancelarElTemporalDeUnPedido(): void
    {
        $temporal = $this->temporal('pedprotemporales', ['estadoPedPro' => 'Sin guardar']);

        $cancelado = \cancelarPedido($temporal, $this->db);
        $sinNumero = \cancelarPedido(0, $this->db);

        self::assertSame([], $cancelado);
        self::assertSame(0, $this->cuantos('pedprotemporales', "id = {$temporal}"));
        self::assertSame('alert alert-info', $sinNumero['class']);
    }

    /** @comportamiento Cancelar el temporal de un albarán lo quita y devuelve a «Guardado» sus pedidos; uno que no existe da el error. */
    public function test_cancelarElTemporalDeUnAlbaranDevuelveSusPedidos(): void
    {
        $proveedor = $this->siembra->proveedor('Proveedor del temporal');
        $pedido = $this->pedido($proveedor, 12, 'Facturado');
        $temporal = $this->temporal('albproltemporales', ['estadoAlbPro' => 'Sin guardar', 'Pedidos' => json_encode([['idAdjunto' => $pedido, 'NumAdjunto' => 12]])]);

        $cancelado = \cancelarAlbaran($temporal, $this->db);
        $noExiste = \cancelarAlbaran(999999, $this->db);

        self::assertSame([], $cancelado);
        self::assertSame(0, $this->cuantos('albproltemporales', "id = {$temporal}"));
        self::assertSame('Guardado', $this->estado('pedprot', $pedido));
        self::assertSame('alert alert-danger', $noExiste['class']);
    }

    /** @comportamiento Cancelar el temporal de una factura lo quita y devuelve a «Guardado» sus albaranes. */
    public function test_cancelarElTemporalDeUnaFacturaDevuelveSusAlbaranes(): void
    {
        $proveedor = $this->siembra->proveedor('Proveedor del temporal');
        $pera = $this->siembra->articulo('Pera del temporal');
        $albaran = $this->siembra->entradaProveedor($pera, 1, '2026-02-03', ['idProveedor' => $proveedor, 'estado' => 'Facturado']);
        $temporal = $this->temporal('facproltemporales', ['estadoFacPro' => 'Sin guardar', 'Albaranes' => json_encode([['idAdjunto' => $albaran, 'NumAdjunto' => 31]])]);

        $cancelado = $this->desdeElModulo(static fn (): array => \cancelarFactura($temporal, $GLOBALS['BDTpv']));

        self::assertSame([], $cancelado);
        self::assertSame(0, $this->cuantos('facproltemporales', "id = {$temporal}"));
        self::assertSame('Guardado', $this->estado('albprot', $albaran));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/funciones.php:789-849, :677-740
     *
     * @group defecto
     * @group integridad
     * @group alto
     *
     * @que-ocurre-hoy Cancelar el temporal que se abrió para modificar un albarán guardado devuelve a
     *   «Guardado» sus pedidos, aunque el albarán guardado los siga teniendo: se podrían servir otra vez en
     *   otro albarán. Lo mismo con los albaranes de una factura guardada.
     * @que-deberia-ocurrir Que cancelar el temporal deje los adjuntos como estaban en el documento guardado.
     * @por-que-ocurre Cancelar devuelve los adjuntos del temporal sin mirar si el temporal es de un documento
     *   ya guardado.
     * @como-deberia-funcionar Con un documento guardado, no cambiar el estado de los adjuntos que tiene.
     */
    public function test_cancelarElTemporalDeUnAlbaranGuardadoNoLiberaSusPedidos(): void
    {
        $proveedor = $this->siembra->proveedor('Proveedor del temporal');
        $pedido = $this->pedido($proveedor, 12, 'Facturado');
        $albaran = $this->siembra->entradaProveedor($this->siembra->articulo('Pera servida'), 1, '2026-02-03', ['idProveedor' => $proveedor]);
        $numero = (int) $this->db->query("SELECT Numalbpro FROM albprot WHERE id = {$albaran}")->fetch_row()[0];
        $this->db->query("INSERT INTO pedproAlb (idAlbaran, numAlbaran, idPedido, numPedido) VALUES ({$albaran}, {$numero}, {$pedido}, 12)");
        $temporal = $this->temporal('albproltemporales', ['estadoAlbPro' => 'Sin guardar', 'Numalbpro' => $numero, 'idProveedor' => $proveedor, 'Pedidos' => json_encode([['idAdjunto' => $pedido, 'NumAdjunto' => 12]])]);

        \cancelarAlbaran($temporal, $this->db);

        self::assertSame('Facturado', $this->estado('pedprot', $pedido));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/funciones.php:732-739, :782-789, :842-849
     *
     * @group defecto
     * @group presentacion
     * @group bajo
     *
     * @que-ocurre-hoy Cancelar sin número de temporal un pedido o un albarán dice que solo se pueden
     *   cancelar las facturas temporales.
     * @que-deberia-ocurrir Que el aviso nombre el documento que se cancela.
     * @por-que-ocurre Los tres avisos son una copia del de la factura.
     * @como-deberia-funcionar Nombrar el pedido o el albarán.
     */
    public function test_cancelarSinTemporalNombraElDocumento(): void
    {
        self::assertStringContainsString('pedido', \cancelarPedido(0, $this->db)['mensaje']);
        self::assertStringContainsString('albar', \cancelarAlbaran(0, $this->db)['mensaje']);
    }

    /** @comportamiento Las formas de pago de un documento ponen primero la suya y después las demás; un importe pagado se pinta con su forma. */
    public function test_lasFormasDePagoYUnImportePagado(): void
    {
        $this->db->query("INSERT INTO formasPago (descripcion) VALUES ('Contado de compra'), ('Transferencia de compra')");
        $transferencia = (int) $this->db->insert_id + 1;
        $contado = $transferencia - 1;

        $formas = \htmlFormasVenci($transferencia, $this->db);
        $importe = \htmlImporteFactura(['importe' => '40', 'fecha' => '2026-02-01', 'forma' => $contado, 'referencia' => 'T1', 'pendiente' => '60.00'], $this->db);

        self::assertStringStartsWith('<option value="' . $transferencia . '">Transferencia de compra</option>', $formas['html']);
        self::assertStringContainsString('<option value= "' . $contado . '">Contado de compra</option>', $formas['html']);
        self::assertSame('<tr><td>40</td><td>2026-02-01</td><td>Contado de compra</td><td>T1</td><td>60.00</td></tr>', $importe['html']);
    }

    /** @comportamiento Las incidencias adjuntas de un documento se leen por su número y su vista. */
    public function test_lasIncidenciasAdjuntasDeUnDocumento(): void
    {
        $this->siembra->incidencia('mod_compras', ['vista' => 'albaran', 'idReal' => '31'], 'Falta una caja');

        $incidencias = CargaAislada::llamar(fn (): array => \incidenciasAdjuntas(31, 'mod_compras', $this->db, 'albaran'));

        self::assertSame(['Falta una caja'], array_column($incidencias['datos'], 'mensaje'));
    }

    /** @comportamiento Comprobar si un proveedor tiene albaranes guardados da 1 o 0. */
    public function test_comprobarSiUnProveedorTieneAlbaranesGuardados(): void
    {
        $con = $this->siembra->proveedor('Proveedor con albarán');
        $sin = $this->siembra->proveedor('Proveedor sin albarán');
        $pera = $this->siembra->articulo('Pera guardada');
        $this->siembra->entradaProveedor($pera, 1, '2026-02-03', ['idProveedor' => $con]);

        self::assertSame([1, 0], [\comprobarAlbaran($con, $this->db), \comprobarAlbaran($sin, $this->db)]);
    }

    /** Un pedido guardado del proveedor, con su número. */
    private function pedido(int $proveedor, int $numero, string $estado = 'Guardado'): int
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $usuario = $this->siembra->usuarioPorDefecto();
        $this->db->query("INSERT INTO pedprot (Numpedpro, Numtemp_pedpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES ({$numero}, 0, '2026-02-01', {$tienda}, {$usuario}, {$proveedor}, '{$estado}', 0, 0)");

        return (int) $this->db->insert_id;
    }

    /** @param array<string, mixed> $campos */
    private function temporal(string $tabla, array $campos): int
    {
        $campos += ['idTienda' => $this->siembra->tiendaPorDefecto(), 'idUsuario' => $this->siembra->usuarioPorDefecto(), 'Fecha' => '2026-02-01', 'fechaInicio' => '2026-02-01', 'idProveedor' => $this->siembra->proveedor('Proveedor del temporal'), 'total' => 0, 'Productos' => '[]'];
        if ($tabla === 'albproltemporales') {
            $campos += ['Su_numero' => ''];
        }
        $columnas = implode(', ', array_keys($campos));
        $valores = implode(', ', array_map(fn ($v): string => "'" . $this->db->real_escape_string((string) $v) . "'", $campos));
        $this->db->query("INSERT INTO {$tabla} ({$columnas}) VALUES ({$valores})");

        return (int) $this->db->insert_id;
    }

    private function cuantos(string $tabla, string $donde): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$tabla} WHERE {$donde}")->fetch_row()[0];
    }

    private function estado(string $tabla, int $id): string
    {
        return (string) $this->db->query("SELECT estado FROM {$tabla} WHERE id = {$id}")->fetch_row()[0];
    }

    /**
     * Ejecuta desde la carpeta del módulo lo que crea una clase de compras dentro de una función, como
     * la factura, que incluye la clase común con una ruta relativa.
     *
     * @template T
     * @param callable(): T $funcion
     * @return T
     */
    private function desdeElModulo(callable $funcion): mixed
    {
        $GLOBALS['BDTpv'] = $this->db;
        $cwd = (string) getcwd();
        chdir(RUTA_TPVFOX . '/modulos/mod_compras');
        try {
            return $funcion();
        } finally {
            chdir($cwd);
        }
    }
}

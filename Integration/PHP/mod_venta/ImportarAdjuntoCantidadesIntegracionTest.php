<?php

/**
 * Traer las lineas de un pedido a un albaran, o las de un albaran a una factura, y guardar.
 *
 * Es el camino que recorre el producto cuando se adjunta un documento a otro: lee las lineas
 * del documento de origen, las pasa por `modificarArrayProductos()` para darles la forma que
 * el navegador espera, y el navegador las devuelve tal cual al guardar el documento nuevo.
 * Aqui se recorre sin navegador, con las mismas tres piezas y en el mismo orden.
 *
 * Lo que estos casos fijan es que una cantidad o un precio de mil o mas no sobrevive a ese
 * viaje: sale con separador de miles, y el guardado lo escribe sin comillas en la sentencia.
 * Y que una cantidad con decimales tampoco: llega redondeada a entero.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CargaAislada;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ImportarAdjuntoCantidadesIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private \AlbaranesVentas $albaranes;
    private \PedidosVentas $pedidos;
    private \FacturasVentas $facturas;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/pedidosVentas.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/facturasVentas.php');
        $this->albaranes = new \AlbaranesVentas($this->db);
        $this->pedidos = new \PedidosVentas($this->db);
        $this->facturas = new \FacturasVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:672
     *
     * @group esperado
     * @group pedido
     * @group albaran
     * @group adjuntos
     * @group guardado
     * @group critico
     *
     * @que-ocurre-hoy Un pedido con una linea de mil unidades no se puede convertir en albaran:
     *   el guardado del albaran falla al escribir la linea.
     * @que-deberia-ocurrir Que el albaran se guarde con su linea de mil unidades.
     * @por-que-ocurre Al traer las lineas del pedido la cantidad se formatea con separador de
     *   miles, «1,000», y el guardado la escribe en la sentencia sin comillas: la coma la
     *   parte en dos valores y sobra uno para las columnas declaradas.
     * @como-deberia-funcionar Que la cantidad viaje como numero, sin formato de presentacion.
     */
    public function test_defecto_unPedidoDeMilUnidadesSeGuardaComoAlbaranConSuLinea(): void
    {
        $idArticulo = $this->siembra->articulo('Platano por unidades');
        $this->siembra->existenciaRegistrada($idArticulo, 5000.0);
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1000.0, '2026-02-10');

        $this->guardarAlbaranCon($this->lineasTraidasDelPedido($idPedido));

        $lineas = $this->lineasDelUltimoAlbaran();
        self::assertCount(1, $lineas, 'El albaran tiene que quedar con la linea del pedido.');
        self::assertEqualsWithDelta(1000.0, (float) $lineas[0]['ncant'], 0.000001);
    }

    /**
     * @codigo-afectado modulos/mod_venta/clases/albaranesVentas.php:85-91
     *
     * @group estado-actual
     * @group defecto
     * @group albaran
     * @group adjuntos
     * @group guardado
     * @group critico
     *
     * @que-ocurre-hoy Cuando el guardado falla por la linea de mil unidades, el albaran ya esta
     *   escrito: queda una cabecera con su numero y su importe, y ninguna linea.
     * @que-deberia-ocurrir Que un guardado que no termina no deje nada escrito.
     * @por-que-ocurre La cabecera se escribe antes que las lineas y el guardado no tiene
     *   transaccion que la deshaga.
     * @como-deberia-funcionar Con el guardado entero dentro de una transaccion.
     */
    public function test_defecto_elAlbaranQueFallaAlImportarQuedaConCabeceraYSinLineas(): void
    {
        $idArticulo = $this->siembra->articulo('Platano que deja el albaran a medias');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1000.0, '2026-02-10');
        $antes = $this->numeroDeAlbaranes();

        $lanzada = $this->guardarAlbaranCon($this->lineasTraidasDelPedido($idPedido));

        self::assertNotNull($lanzada, 'La linea de mil unidades rompe la insercion.');
        self::assertSame($antes + 1, $this->numeroDeAlbaranes(), 'La cabecera queda escrita.');
        self::assertCount(0, $this->lineasDelUltimoAlbaran(), 'Y no tiene ninguna linea.');
    }

    /**
     * El navegador marca el pedido como procesado en cuanto recibe sus lineas, antes de pedir
     * ningun guardado (`funciones.js`, al incorporar el adjunto). Aqui se reproduce ese orden.
     *
     * @codigo-afectado modulos/mod_venta/clases/pedidosVentas.php:176-196
     *
     * @group estado-actual
     * @group defecto
     * @group pedido
     * @group adjuntos
     * @group estados
     * @group critico
     *
     * @que-ocurre-hoy El pedido queda marcado como procesado aunque el albaran no llegue a
     *   guardarse, y deja de ofrecerse para adjuntar: no se puede volver a cargar.
     * @que-deberia-ocurrir Que el pedido solo pase a procesado si el albaran que lo incorpora
     *   se guarda, y que siga disponible si no.
     * @por-que-ocurre El cambio de estado y el guardado del albaran son dos peticiones
     *   independientes, y la primera no se deshace cuando falla la segunda. El buscador de
     *   adjuntos solo ofrece pedidos en estado guardado.
     * @como-deberia-funcionar Cambiar el estado del pedido dentro del mismo guardado que
     *   escribe el albaran.
     */
    public function test_defecto_elPedidoQuedaProcesadoYDejaDeOfrecerseAunqueElAlbaranFalle(): void
    {
        $idArticulo = $this->siembra->articulo('Platano de un pedido que se pierde');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1000.0, '2026-02-10');
        $numero = (int) $this->db->query("SELECT Numpedcli FROM pedclit WHERE id=$idPedido")->fetch_assoc()['Numpedcli'];
        $idCliente = $this->siembra->clientePorDefecto();

        self::assertSame(1, $this->pedidos->PedidosClienteGuardado($numero, $idCliente)['Nitems']);

        $lineas = $this->lineasTraidasDelPedido($idPedido);
        $this->pedidos->ModificarEstadoPedido($idPedido, 'Procesado');
        $lanzada = $this->guardarAlbaranCon($lineas);

        self::assertNotNull($lanzada, 'El albaran no llega a guardarse.');
        self::assertSame(
            0,
            $this->pedidos->PedidosClienteGuardado($numero, $idCliente)['Nitems'],
            'El pedido ya no se ofrece para adjuntar.'
        );
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:672
     *
     * @group esperado
     * @group pedido
     * @group albaran
     * @group adjuntos
     * @group existencias
     * @group alto
     *
     * @que-ocurre-hoy Un pedido de 0,420 se convierte en un albaran cuya linea dice cantidad
     *   cero, y las existencias no se descuentan. El guardado no falla.
     * @que-deberia-ocurrir Que el albaran conserve la cantidad del pedido y descuente lo mismo.
     * @por-que-ocurre Al traer las lineas la cantidad se formatea sin decimales, y esa es la
     *   cantidad con la que el guardado mueve las existencias.
     * @como-deberia-funcionar Sin redondear la cantidad al traerla.
     */
    public function test_defecto_unPedidoConDecimalesSeGuardaComoAlbaranConSuCantidadYDescuentaExistencias(): void
    {
        $idArticulo = $this->siembra->articulo('Fresa al peso');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 0.420, '2026-02-10');

        $this->guardarAlbaranCon($this->lineasTraidasDelPedido($idPedido));

        $lineas = $this->lineasDelUltimoAlbaran();
        self::assertCount(1, $lineas);
        self::assertEqualsWithDelta(0.420, (float) $lineas[0]['ncant'], 0.000001);
        self::assertEqualsWithDelta(9.580, $this->existenciasDe($idArticulo), 0.000001);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:660-665
     *
     * @group esperado
     * @group pedido
     * @group albaran
     * @group adjuntos
     * @group importes
     * @group critico
     *
     * @que-ocurre-hoy Un pedido con una linea de precio mil o mas tampoco se puede convertir
     *   en albaran, aunque la cantidad sea una unidad.
     * @que-deberia-ocurrir Que el albaran se guarde con su linea y su precio.
     * @por-que-ocurre El precio sin impuestos recibe el mismo formato con separador de miles
     *   que la cantidad, y se escribe igual, sin comillas.
     * @como-deberia-funcionar Que el precio viaje como numero.
     */
    public function test_defecto_unPedidoConPrecioDeMilOMasSeGuardaComoAlbaranConSuLinea(): void
    {
        $idArticulo = $this->siembra->articulo('Maquina cara', ['ultimoCoste' => 1500.0]);
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1.0, '2026-02-10');

        $this->guardarAlbaranCon($this->lineasTraidasDelPedido($idPedido));

        $lineas = $this->lineasDelUltimoAlbaran();
        self::assertCount(1, $lineas, 'El albaran tiene que quedar con la linea del pedido.');
        self::assertEqualsWithDelta(1500.0, (float) $lineas[0]['pvpSiva'], 0.005);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:672
     *
     * @group esperado
     * @group albaran
     * @group factura
     * @group adjuntos
     * @group guardado
     * @group critico
     *
     * @que-ocurre-hoy Un albaran con una linea de mil unidades no se puede facturar: el guardado
     *   de la factura falla al escribir la linea, igual que el del albaran con el pedido.
     * @que-deberia-ocurrir Que la factura se guarde con la linea del albaran.
     * @por-que-ocurre Las lineas de un albaran se traen a la factura por la misma funcion que
     *   las de un pedido al albaran.
     * @como-deberia-funcionar Que la cantidad viaje como numero.
     */
    public function test_defecto_unAlbaranDeMilUnidadesSeFacturaConSuLinea(): void
    {
        $idArticulo = $this->siembra->articulo('Platano ya entregado');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1000.0, '2026-02-10');
        $lineas = CargaAislada::llamar(
            fn () => \modificarArrayProductos($this->albaranes->ProductosAlbaran($idAlbaran))
        );

        try {
            $this->facturas->AddFacturaGuardado([
                'Numtemp_faccli'    => 0,
                'Fecha'             => '2026-02-15',
                'idTienda'          => $this->siembra->tiendaPorDefecto(),
                'idUsuario'         => $this->siembra->usuarioPorDefecto(),
                'idCliente'         => $this->siembra->clientePorDefecto(),
                'estado'            => 'Guardado',
                'total'             => 1000.00,
                'DatosTotales'      => ['desglose' => ['0' => ['iva' => 0.00, 'base' => 1000.00]]],
                'productos'         => json_encode($lineas),
                'albaranes'         => json_encode([]),
                'fechaCreacion'     => '2026-02-15',
                'fechaVencimiento'  => '2026-03-15',
                'fechaModificacion' => '2026-02-15',
            ], 0);
        } catch (\mysqli_sql_exception $e) {
            // El caso falla por su asercion, no por la excepcion.
        }

        $idCliente = $this->siembra->clientePorDefecto();
        $ncant = $this->db->query(
            "SELECT l.ncant FROM facclilinea l JOIN facclit f ON f.id = l.idfaccli
              WHERE f.idCliente = $idCliente ORDER BY f.id DESC, l.id DESC LIMIT 1"
        )->fetch_assoc();

        self::assertNotNull($ncant, 'La factura tiene que quedar con la linea del albaran.');
        self::assertEqualsWithDelta(1000.0, (float) $ncant['ncant'], 0.000001);
    }

    /**
     * @group control
     * @group pedido
     * @group albaran
     * @group adjuntos
     *
     * @para-que-sirve Demuestra que el camino entero funciona cuando la cantidad es entera y
     *   menor que mil: lo que rompe los otros casos es el valor, no la forma de recorrerlo.
     */
    public function test_unPedidoDe999UnidadesSeGuardaComoAlbaranYDescuentaExistencias(): void
    {
        $idArticulo = $this->siembra->articulo('Platano por debajo de mil');
        $this->siembra->existenciaRegistrada($idArticulo, 5000.0);
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 999.0, '2026-02-10');

        $lanzada = $this->guardarAlbaranCon($this->lineasTraidasDelPedido($idPedido));

        self::assertNull($lanzada);
        $lineas = $this->lineasDelUltimoAlbaran();
        self::assertCount(1, $lineas);
        self::assertEqualsWithDelta(999.0, (float) $lineas[0]['ncant'], 0.000001);
        self::assertEqualsWithDelta(4001.0, $this->existenciasDe($idArticulo), 0.000001);
    }

    // --- Apoyos -------------------------------------------------------------

    /**
     * Las lineas del pedido tal como el producto se las entrega al navegador al adjuntarlo.
     *
     * @return list<array<string,mixed>>
     */
    private function lineasTraidasDelPedido(int $idPedido): array
    {
        return CargaAislada::llamar(
            fn () => \modificarArrayProductos($this->pedidos->ProductosPedido($idPedido))
        );
    }

    /**
     * Guarda un albaran nuevo con esas lineas, como hace la pantalla al pulsar guardar.
     *
     * Devuelve la excepcion si el guardado la lanza, para que cada caso decida si la espera.
     *
     * @param list<array<string,mixed>> $lineas
     */
    private function guardarAlbaranCon(array $lineas): ?\mysqli_sql_exception
    {
        try {
            $this->albaranes->AddAlbaranGuardado([
                'Numtemp_albcli' => 0,
                'Fecha'          => '2026-02-15',
                'idTienda'       => $this->siembra->tiendaPorDefecto(),
                'idUsuario'      => $this->siembra->usuarioPorDefecto(),
                'idCliente'      => $this->siembra->clientePorDefecto(),
                'estado'         => 'Guardado',
                'total'          => 1000.00,
                'DatosTotales'   => ['desglose' => ['0' => ['iva' => 0.00, 'base' => 1000.00]]],
                'productos'      => json_encode($lineas),
                'pedidos'        => json_encode([]),
            ], 0);
        } catch (\mysqli_sql_exception $e) {
            return $e;
        }

        return null;
    }

    private function numeroDeAlbaranes(): int
    {
        $idCliente = $this->siembra->clientePorDefecto();

        return (int) $this->db->query("SELECT COUNT(*) c FROM albclit WHERE idCliente=$idCliente")->fetch_assoc()['c'];
    }

    /** @return list<array<string,mixed>> */
    private function lineasDelUltimoAlbaran(): array
    {
        $idCliente = $this->siembra->clientePorDefecto();
        $ultimo = $this->db
            ->query("SELECT id FROM albclit WHERE idCliente=$idCliente ORDER BY id DESC LIMIT 1")
            ->fetch_assoc();

        if ($ultimo === null) {
            return [];
        }

        return $this->db
            ->query('SELECT ncant, nunidades, pvpSiva FROM albclilinea WHERE idalbcli=' . (int) $ultimo['id'])
            ->fetch_all(MYSQLI_ASSOC);
    }

    private function existenciasDe(int $idArticulo): float
    {
        $idTienda = $this->siembra->tiendaPorDefecto();

        return (float) $this->db
            ->query("SELECT stockOn FROM articulosStocks WHERE idArticulo=$idArticulo AND idTienda=$idTienda")
            ->fetch_assoc()['stockOn'];
    }
}

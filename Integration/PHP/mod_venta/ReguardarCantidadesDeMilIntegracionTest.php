<?php

/**
 * Abrir un documento de venta ya guardado y volver a guardarlo.
 *
 * Las tres pantallas —pedido, albaran y factura— leen las lineas del documento guardado y las
 * pasan por `modificarArrayProductos()` antes de entregarselas al navegador, igual que cuando
 * se adjunta un documento a otro. El navegador las devuelve al guardar, y guardar un
 * documento que ya existe es borrarlo entero y volver a escribirlo.
 *
 * De modo que un documento con una linea de mil o mas unidades, que se guardo bien la primera
 * vez porque la cantidad se tecleo, no sobrevive a que se vuelva a guardar: el borrado se
 * hace, la reescritura falla en la linea, y lo que habia deja de existir.
 *
 * Aqui se recorre sin navegador, con las mismas piezas y en el mismo orden que la pantalla.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CargaAislada;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ReguardarCantidadesDeMilIntegracionTest extends CasoIntegracion
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
     * @group albaran
     * @group guardado
     * @group critico
     *
     * @que-ocurre-hoy Abrir un albaran guardado que tiene una linea de mil unidades y volver a
     *   guardarlo lo destruye: queda la cabecera, con su numero y su importe, y ninguna linea.
     * @que-deberia-ocurrir Que el albaran siga teniendo su linea de mil unidades.
     * @por-que-ocurre Al abrirlo, la cantidad se formatea con separador de miles y vuelve asi
     *   al guardar. Guardar un albaran existente es borrarlo y reescribirlo: el borrado se
     *   hace, la linea no se puede escribir, y no hay transaccion que devuelva lo borrado.
     * @como-deberia-funcionar Que la cantidad viaje como numero; y, aparte, que el guardado
     *   no pueda dejar un documento a medias.
     */
    public function test_defecto_reguardarUnAlbaranDeMilUnidadesConservaSuLinea(): void
    {
        $idArticulo = $this->siembra->articulo('Platano de un albaran que se reabre');
        $this->siembra->existenciaRegistrada($idArticulo, 5000.0);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1000.0, '2026-02-10');

        $lineas = $this->comoLasEntregaLaPantalla($this->albaranes->ProductosAlbaran($idAlbaran));
        $this->albaranes->eliminarAlbaranTablas($idAlbaran);
        $this->sinQueLaExcepcionDecida(
            fn () => $this->albaranes->AddAlbaranGuardado($this->datos('albcli', $lineas) + ['pedidos' => json_encode([])], $idAlbaran)
        );

        $despues = $this->albaranes->ProductosAlbaran($idAlbaran);
        self::assertCount(1, $despues, 'El albaran tiene que seguir teniendo su linea.');
        self::assertEqualsWithDelta(1000.0, (float) $despues[0]['ncant'], 0.000001);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:672
     *
     * @group esperado
     * @group pedido
     * @group guardado
     * @group critico
     *
     * @que-ocurre-hoy Abrir un pedido guardado con una linea de mil unidades y volver a
     *   guardarlo lo deja sin lineas.
     * @que-deberia-ocurrir Que el pedido siga teniendo su linea.
     * @por-que-ocurre El mismo camino que el albaran: la pantalla del pedido formatea la
     *   cantidad al abrirlo y la reescritura, que empieza borrando, falla en la linea.
     * @como-deberia-funcionar Que la cantidad viaje como numero.
     */
    public function test_defecto_reguardarUnPedidoDeMilUnidadesConservaSuLinea(): void
    {
        $idArticulo = $this->siembra->articulo('Platano de un pedido que se reabre');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 1000.0, '2026-02-10');

        $lineas = $this->comoLasEntregaLaPantalla($this->pedidos->ProductosPedido($idPedido));
        $this->pedidos->eliminarPedidoTablas($idPedido);
        $this->sinQueLaExcepcionDecida(
            fn () => $this->pedidos->AddPedidoGuardado($this->datos('pedcli', $lineas), $idPedido)
        );

        $despues = $this->pedidos->ProductosPedido($idPedido);
        self::assertCount(1, $despues, 'El pedido tiene que seguir teniendo su linea.');
        self::assertEqualsWithDelta(1000.0, (float) $despues[0]['ncant'], 0.000001);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:672
     *
     * @group esperado
     * @group factura
     * @group guardado
     * @group critico
     *
     * @que-ocurre-hoy Abrir una factura guardada con una linea de mil unidades y volver a
     *   guardarla la deja sin lineas: el documento fiscal que habia deja de existir.
     * @que-deberia-ocurrir Que la factura siga teniendo su linea.
     * @por-que-ocurre El mismo camino que el albaran y el pedido, sobre la factura.
     * @como-deberia-funcionar Que la cantidad viaje como numero.
     */
    public function test_defecto_reguardarUnaFacturaDeMilUnidadesConservaSuLinea(): void
    {
        $idArticulo = $this->siembra->articulo('Platano de una factura que se reabre');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1000.0, '2026-02-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        $lineas = $this->comoLasEntregaLaPantalla($this->facturas->ProductosFactura($idFactura));
        $this->facturas->eliminarFacturasTablas($idFactura);
        $this->sinQueLaExcepcionDecida(fn () => $this->facturas->AddFacturaGuardado(
            $this->datos('faccli', $lineas) + [
                'albaranes'         => json_encode([]),
                'fechaCreacion'     => '2026-02-15',
                'fechaVencimiento'  => '2026-03-15',
                'fechaModificacion' => '2026-02-15',
            ],
            $idFactura
        ));

        $despues = $this->facturas->ProductosFactura($idFactura);
        self::assertCount(1, $despues, 'La factura tiene que seguir teniendo su linea.');
        self::assertEqualsWithDelta(1000.0, (float) $despues[0]['ncant'], 0.000001);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:672
     *
     * @group esperado
     * @group albaran
     * @group guardado
     * @group existencias
     * @group alto
     *
     * @que-ocurre-hoy Abrir un albaran con una linea de 0,420 y volver a guardarlo le cambia la
     *   cantidad a cero. No falla, de modo que nadie lo ve.
     * @que-deberia-ocurrir Que volver a guardar un albaran sin tocarlo lo deje como estaba.
     * @por-que-ocurre Al abrirlo la cantidad se formatea sin decimales, y la reescritura
     *   guarda lo que recibe.
     * @como-deberia-funcionar Sin redondear la cantidad al entregarla a la pantalla.
     */
    public function test_defecto_reguardarUnAlbaranConDecimalesConservaSuCantidad(): void
    {
        $idArticulo = $this->siembra->articulo('Fresa de un albaran que se reabre');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 0.420, '2026-02-10');

        $lineas = $this->comoLasEntregaLaPantalla($this->albaranes->ProductosAlbaran($idAlbaran));
        $this->albaranes->eliminarAlbaranTablas($idAlbaran);
        $this->albaranes->AddAlbaranGuardado($this->datos('albcli', $lineas) + ['pedidos' => json_encode([])], $idAlbaran);

        $despues = $this->albaranes->ProductosAlbaran($idAlbaran);
        self::assertCount(1, $despues);
        self::assertEqualsWithDelta(0.420, (float) $despues[0]['ncant'], 0.000001);
    }

    /**
     * @group control
     * @group albaran
     * @group guardado
     *
     * @para-que-sirve Demuestra que abrir y volver a guardar funciona con una cantidad entera
     *   menor que mil: lo que destruye los otros documentos es el valor, no el recorrido.
     */
    public function test_reguardarUnAlbaranDe999UnidadesConservaSuLinea(): void
    {
        $idArticulo = $this->siembra->articulo('Platano por debajo de mil que se reabre');
        $this->siembra->existenciaRegistrada($idArticulo, 5000.0);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 999.0, '2026-02-10');

        $lineas = $this->comoLasEntregaLaPantalla($this->albaranes->ProductosAlbaran($idAlbaran));
        $this->albaranes->eliminarAlbaranTablas($idAlbaran);
        $this->albaranes->AddAlbaranGuardado($this->datos('albcli', $lineas) + ['pedidos' => json_encode([])], $idAlbaran);

        $despues = $this->albaranes->ProductosAlbaran($idAlbaran);
        self::assertCount(1, $despues);
        self::assertEqualsWithDelta(999.0, (float) $despues[0]['ncant'], 0.000001);
    }

    // --- Apoyos -------------------------------------------------------------

    /**
     * Las lineas de un documento guardado tal como su pantalla se las entrega al navegador.
     *
     * @param list<array<string,mixed>> $lineasGuardadas
     * @return list<array<string,mixed>>
     */
    private function comoLasEntregaLaPantalla(array $lineasGuardadas): array
    {
        return CargaAislada::llamar(static fn () => \modificarArrayProductos($lineasGuardadas));
    }

    /**
     * Lo comun de los datos con que cada pantalla pide el guardado.
     *
     * @param list<array<string,mixed>> $lineas
     * @return array<string,mixed>
     */
    private function datos(string $documento, array $lineas): array
    {
        return [
            'Numtemp_' . $documento => 0,
            'Fecha'                 => '2026-02-15',
            'idTienda'              => $this->siembra->tiendaPorDefecto(),
            'idUsuario'             => $this->siembra->usuarioPorDefecto(),
            'idCliente'             => $this->siembra->clientePorDefecto(),
            'estado'                => 'Guardado',
            'total'                 => 1000.00,
            'DatosTotales'          => ['desglose' => ['0' => ['iva' => 0.00, 'base' => 1000.00]]],
            'productos'             => json_encode($lineas),
        ];
    }

    /** Ejecuta el guardado dejando que el caso falle por su asercion y no por la excepcion. */
    private function sinQueLaExcepcionDecida(callable $guardado): void
    {
        try {
            $guardado();
        } catch (\mysqli_sql_exception $e) {
            // Lo que importa es lo que queda escrito despues.
        }
    }
}

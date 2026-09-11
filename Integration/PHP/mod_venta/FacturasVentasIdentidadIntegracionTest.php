<?php

/**
 * La identidad del documento fiscal: con que nombre se emite una factura, si ese nombre se
 * conserva, y que ocurre cuando el numero de la serie y el identificador de la fila dejan
 * de ser el mismo valor.
 *
 * La propia clase declara la separacion como pendiente: el comentario de `TodosTemporal()`
 * anota que la columna deberia ser el identificador «el dia de mañana que pongamos en
 * funcionamiento el poder distinto numero que id».
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class FacturasVentasIdentidadIntegracionTest extends CasoIntegracion
{
    private \FacturasVentas $facturas;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/facturasVentas.php');
        $this->facturas = new \FacturasVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    /**
     * El numero de la factura no se toma de ninguna serie: se escribe el identificador que
     * la tabla acaba de asignar. Lo que deberia ser la numeracion fiscal es el contador
     * interno de filas.
     */
    public function test_defecto_elNumeroDeLaFacturaSeDerivaDelIdentificadorDeLaFila(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura sin serie propia');

        $this->facturas->AddFacturaGuardado($this->datosDeGuardado($idArticulo), 0);

        $fila = $this->ultimaFactura();
        self::assertSame($fila['id'], $fila['Numfaccli']);
    }

    /**
     * Reguardar una factura cuyo numero no coincide con su identificador la reescribe con
     * el identificador como numero: el documento cambia de nombre sin que nadie lo pida,
     * y el numero con el que se emitio deja de existir.
     */
    public function test_defecto_reguardarUnaFacturaLaRenombraConSuIdentificador(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura que se renombra');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);
        $this->db->query("UPDATE facclit SET Numfaccli=900001 WHERE id=$idFactura");

        $this->facturas->eliminarFacturasTablas($idFactura);
        $this->facturas->AddFacturaGuardado($this->datosDeGuardado($idArticulo), $idFactura);

        $reescrita = $this->facturas->datosFactura($idFactura);
        self::assertSame((string) $idFactura, $reescrita['Numfaccli'], 'La factura pasa a llamarse como su id...');
        self::assertNotSame('900001', $reescrita['Numfaccli'], '... y pierde el numero con el que se emitio.');
    }

    /**
     * El desglose de impuestos se indexa por el identificador de la factura, y la suma que
     * el listado pinta se consulta por el numero. Mientras ambos coincidan el listado
     * cuadra; cuando no, la factura aparece con base e impuestos a cero aunque los tenga
     * escritos.
     */
    public function test_defecto_laBaseYElIvaDelListadoNoSeEncuentranCuandoNumeroEIdDivergen(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura con base perdida', ['iva' => 21.0]);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);
        $this->facturas->eliminarFacturasTablas($idFactura);
        $this->facturas->AddFacturaGuardado($this->datosDeGuardado($idArticulo), $idFactura);
        $this->db->query("UPDATE facclit SET Numfaccli=900002 WHERE id=$idFactura");

        self::assertCount(1, $this->facturas->IvasFactura($idFactura), 'El desglose esta escrito...');
        self::assertNull($this->facturas->sumarIva(900002)['totalbase'], '... y la suma del listado no lo encuentra.');
    }

    /**
     * La navegacion entre facturas del cliente recibe el identificador de la factura
     * abierta y lo compara contra el numero de las demas. Con numero e identificador
     * separados, «anterior» y «siguiente» dejan de referirse a la factura abierta.
     */
    public function test_defecto_laNavegacionComparaElIdentificadorContraElNumeroDeLasDemas(): void
    {
        $idCliente = $this->siembra->cliente('Cliente con numeracion desplazada');
        $idArticulo = $this->siembra->articulo('Producto de factura desplazada');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);
        $this->db->query("UPDATE facclit SET Numfaccli=900003 WHERE id=$idFactura");

        $anterior = $this->facturas->getFacturaAnteriorSiguiente($idFactura, $idCliente, 'anterior');

        self::assertSame(0, $anterior, 'Con el id como criterio no encuentra ninguna anterior...');
        self::assertSame(900003, (int) $this->facturas->getUltimaFactura($idCliente), '... aunque el cliente tenga una.');
    }

    /**
     * La navegacion global de la pantalla no consulta cual es el documento contiguo:
     * construye el enlace sumando y restando uno al identificador. Con un hueco en la
     * secuencia —cualquier factura borrada— el enlace lleva a un documento que no existe.
     */
    public function test_defecto_elDocumentoContiguoDeLaNavegacionGlobalNoSeConsulta(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura contigua');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        self::assertSame(
            [],
            $this->facturas->datosFactura($idFactura + 1),
            'El identificador siguiente no corresponde a ninguna factura, y la pantalla enlaza a el igualmente.'
        );
    }

    /**
     * El metodo que modifica fecha, vencimiento y forma de pago de una factura escribe una
     * columna que la tabla no declara. Ninguna vista lo invoca, asi que la sentencia rota
     * no se ha ejecutado nunca.
     */
    public function test_defecto_modificarFechaFacturaEscribeUnaColumnaQueLaTablaNoTiene(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de factura con forma de pago');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        $this->expectException(\mysqli_sql_exception::class);

        $this->facturas->modificarFechaFactura($idFactura, '2026-02-15', 'Efectivo', '2026-03-15');
    }

    /**
     * «Crear factura desde albaran», la accion del listado de albaranes, envia el
     * identificador del albaran, y la busqueda que la factura ejecuta con ese valor
     * consulta por el numero. La factura se abre sin el albaran que se pidio facturar, o
     * con uno distinto si algun otro albaran del cliente lleva ese numero.
     */
    public function test_defecto_crearFacturaDesdeAlbaranEnviaElIdYLaBusquedaConsultaElNumero(): void
    {
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $albaranes = new \AlbaranesVentas($this->db);
        $idCliente = $this->siembra->cliente('Cliente que crea factura desde albaran');
        $idArticulo = $this->siembra->articulo('Producto de albaran facturable');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);
        $this->db->query("UPDATE albclit SET Numalbcli=$idAlbaran + 500000 WHERE id=$idAlbaran");

        $porIdentificador = $albaranes->AlbaranClienteGuardado($idAlbaran, $idCliente);
        $porNumero = $albaranes->AlbaranClienteGuardado($idAlbaran + 500000, $idCliente);

        self::assertSame(0, $porIdentificador['Nitems'], 'Con el identificador que el listado envia no encuentra nada...');
        self::assertSame(1, $porNumero['Nitems'], '... y con el numero si.');
    }

    // --- Apoyos del caso --------------------------------------------------------

    private function datosDeGuardado(int $idArticulo): array
    {
        return [
            'Numtemp_faccli'    => 0,
            'Fecha'             => '2026-02-15',
            'idTienda'          => $this->siembra->tiendaPorDefecto(),
            'idUsuario'         => $this->siembra->usuarioPorDefecto(),
            'idCliente'         => $this->siembra->clientePorDefecto(),
            'estado'            => 'Guardado',
            'total'             => 12.10,
            'DatosTotales'      => ['desglose' => ['21' => ['iva' => 2.10, 'base' => 10.00]]],
            'productos'         => json_encode([[
                'idArticulo'  => $idArticulo,
                'cref'        => 'REF' . $idArticulo,
                'ccodbar'     => '',
                'cdetalle'    => 'Linea de identidad de factura',
                'ncant'       => 1.0,
                'nunidades'   => 1.0,
                'precioCiva'  => 12.10,
                'iva'         => 21,
                'pvpSiva'     => 10.00,
                'estadoLinea' => 'Activo',
                'NumalbCli'   => 0,
            ]]),
            'albaranes'         => json_encode([]),
            'fechaCreacion'     => date('Y-m-d'),
            'fechaVencimiento'  => '2026-03-15',
            'fechaModificacion' => date('Y-m-d'),
        ];
    }

    private function ultimaFactura(): array
    {
        $idCliente = $this->siembra->clientePorDefecto();

        return $this->db
            ->query("SELECT id, Numfaccli FROM facclit WHERE idCliente=$idCliente ORDER BY id DESC LIMIT 1")
            ->fetch_assoc();
    }
}

<?php

/**
 * El estado de la factura: quien lo escribe, contra que catalogo se contrasta y que
 * catalogos coexisten.
 *
 * Este componente no es el unico que escribe aqui: el despacho compartido decide con
 * asignacion en lugar de comparacion, de modo que toda peticion de cambio de estado
 * ejecuta tambien la escritura sobre la factura que lleve ese identificador
 * (`PCP-TPY-compartida`, FM-04). Lo que se comprueba aqui es el efecto sobre la factura.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class FacturasVentasEstadosIntegracionTest extends CasoIntegracion
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

    public function test_modificarEstado_escribeElEstadoDeLaFactura(): void
    {
        $idFactura = $this->facturaSembrada('Producto de factura que cambia de estado');

        $this->facturas->modificarEstado($idFactura, 'Procesado');

        self::assertSame('Procesado', $this->facturas->getEstado($idFactura));
    }

    /**
     * El cambio de estado no comprueba si encontro la fila: una factura que no existe y
     * una factura que si existe se despachan igual, sin nada que distinga los dos casos
     * para quien lo invoca.
     */
    public function test_defecto_cambiarElEstadoDeUnaFacturaInexistenteNoSeDistingueDelExito(): void
    {
        self::assertNull($this->facturas->modificarEstado(999999999, 'Procesado'));
        self::assertNull($this->facturas->modificarEstado($this->facturaSembrada('Producto de factura viva'), 'Procesado'));
    }

    /**
     * El cambio de estado no contrasta el valor contra el catalogo que la propia clase
     * declara: cualquier cadena que quepa en la columna queda escrita, y despues sale por
     * el filtro del listado, que se construye con los valores presentes en la tabla.
     */
    public function test_defecto_elEstadoSeEscribeSinContrastarloConElCatalogo(): void
    {
        $idFactura = $this->facturaSembrada('Producto de factura con estado libre');

        $this->facturas->modificarEstado($idFactura, 'Inventado');

        self::assertSame('Inventado', $this->facturas->getEstado($idFactura));
        self::assertNotContains('Inventado', array_column($this->facturas->posiblesEstados(), 'estado'));
        self::assertContains('Inventado', $this->facturas->getEstadosFacturas(), 'Y el filtro del listado lo ofrece.');
    }

    /**
     * La columna del estado admite doce caracteres. Los estados que la vista de factura
     * documenta en su cabecera —«Pagado Parci», «Pagado Total»— caben justos, y cualquier
     * denominacion mas larga es rechazada por la base en el momento de escribirla, sin que
     * la clase lo compruebe antes. Es la familia del issue TPVFox #165.
     */
    public function test_defecto_unEstadoMasLargoQueLaColumnaLoRechazaLaBaseNoLaClase(): void
    {
        $idFactura = $this->facturaSembrada('Producto de factura con estado largo');

        $this->facturas->modificarEstado($idFactura, 'Pagado Parci');
        self::assertSame('Pagado Parci', $this->facturas->getEstado($idFactura), 'Doce caracteres caben justos.');

        $this->expectException(\mysqli_sql_exception::class);
        $this->facturas->modificarEstado($idFactura, 'Pagado Parcialmente');
    }

    /**
     * Hay tres catalogos de estado de factura y no dicen lo mismo: el que la clase declara
     * —«Guardado», «Sin Guardar», «Procesado»—, el que la cabecera de la vista documenta
     * —que añade «Nuevo», «Pagado Parci» y «Pagado Total», y escribe «Sin guardar» con ge
     * minuscula— y el que la tabla contiene. El catalogo de la clase no sirve para validar
     * porque no describe los estados que el sistema usa.
     */
    public function test_defecto_elCatalogoDeLaClaseNoRecogeLosEstadosDePagoDeLaFactura(): void
    {
        $declarados = array_column($this->facturas->posiblesEstados(), 'estado');

        self::assertNotContains('Pagado Parci', $declarados);
        self::assertNotContains('Pagado Total', $declarados);
        self::assertContains('Sin Guardar', $declarados, 'Declara la grafia con ge mayuscula...');
    }

    /**
     * Ninguna escritura de la clase produce el estado «Sin guardar» que la vista compara al
     * abrir una factura con borrador: el alta escribe siempre lo que la pantalla envia, y
     * la pantalla envia «Guardado». La comparacion que decide si la factura y su borrador
     * concuerdan se evalua por tanto siempre como discrepancia.
     */
    public function test_defecto_ningunaFacturaLlevaElEstadoQueLaVistaEsperaDeUnaConBorrador(): void
    {
        $this->facturaSembrada('Producto de factura sin estado de borrador');

        self::assertSame(0, $this->cuantasFacturasConEstado('Sin guardar'));
        self::assertSame(0, $this->cuantasFacturasConEstado('Sin Guardar'));
        self::assertGreaterThan(0, $this->cuantasFacturasConEstado('Guardado'));
    }

    /**
     * La factura no participa en el ciclo de estados de su albaran: facturar un albaran lo
     * deja «Procesado», pero borrar la factura no lo devuelve, y el estado del albaran no
     * se consulta al facturarlo. Dos facturas pueden incorporar el mismo albaran.
     */
    public function test_defecto_borrarLaFacturaNoDevuelveElAlbaranASuEstadoAnterior(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran que no vuelve');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        $this->facturas->eliminarFacturasTablas($idFactura);

        self::assertSame(
            'Procesado',
            $this->db->query("SELECT estado FROM albclit WHERE id=$idAlbaran")->fetch_assoc()['estado']
        );
    }

    // --- Apoyos del caso --------------------------------------------------------

    private function facturaSembrada(string $nombreArticulo): int
    {
        $idArticulo = $this->siembra->articulo($nombreArticulo);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');

        return $this->siembra->facturarAlbaranCliente($idAlbaran);
    }

    /** Recuento sensible a mayusculas: la intercalacion de la columna no las distingue. */
    private function cuantasFacturasConEstado(string $estado): int
    {
        return (int) $this->db
            ->query("SELECT COUNT(*) as n FROM facclit WHERE BINARY estado = '$estado'")
            ->fetch_assoc()['n'];
    }
}

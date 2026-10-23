<?php

/**
 * Los resúmenes de un proveedor en `ClaseProveedor`: el de albaranes entre dos fechas
 * (`albaranesProveedoresFechas()`) y el anual por trimestres, que lee la vista
 * `vw_resumenProveedoresFacturas` (`obtenerResumenAnualProveedor()`).
 *
 * Contra la base, en una transacción que se deshace, con la conexión del caso compartida con la clase.
 * Los albaranes los siembra la siembra común con sus líneas y su IVA; las facturas, también, y el caso
 * les añade su desglose de IVA, que la vista necesita y la siembra no crea.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProveedor;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ResumenesProveedorIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private \ClaseProveedor $proveedores;
    private Siembra $siembra;
    private int $idProveedor;
    private int $tomate;
    private int $vino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_proveedor/clases/ClaseProveedor.php');
        $this->proveedores = new \ClaseProveedor();
        $this->siembra = new Siembra($this->db);
        $sentencia = $this->db->prepare("INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Huerta Prueba', 'Huerta Prueba SL', 'B70000001', 'Rúa 1', '2020-01-01', 'Activo')");
        $sentencia->execute();
        $this->idProveedor = (int) $this->db->insert_id;
        $this->tomate = $this->siembra->articulo('Tomate', ['ultimoCoste' => 2.0, 'iva' => 10]);
        $this->vino = $this->siembra->articulo('Vino', ['ultimoCoste' => 5.0, 'iva' => 21]);
    }

    private function albaran(int $articulo, float $unidades, string $fecha, array $opciones = []): int
    {
        return $this->siembra->entradaProveedor($articulo, $unidades, $fecha, $opciones + ['idProveedor' => $this->idProveedor]);
    }

    /** Una factura del albarán con su desglose de IVA, que la vista suma. */
    private function factura(int $albaran, string $fecha, string $estado = 'Guardado'): int
    {
        $factura = $this->siembra->facturarAlbaranProveedor($albaran, $fecha);
        $this->db->query("UPDATE facprot SET estado = '$estado' WHERE id = $factura");
        $this->db->query("INSERT INTO facproIva (idfacpro, Numfacpro, iva, importeIva, totalbase)
            SELECT f.id, f.Numfacpro, i.iva, i.importeIva, i.totalbase FROM facprot f JOIN albproIva i ON i.idalbpro = $albaran WHERE f.id = $factura");

        return $factura;
    }

    /**
     * @comportamiento El resumen de albaranes entre dos fechas reúne los del proveedor en el intervalo: sus
     *   líneas no eliminadas por albarán, artículo y coste; sus bases y cuotas por albarán, por fecha; y el
     *   desglose por tipo de IVA. Los de fuera del intervalo no entran. La línea eliminada se siembra sin
     *   desglose, como la deja el albarán.
     */
    public function test_elResumenDeAlbaranesEntreDosFechas(): void
    {
        $primero = $this->albaran($this->tomate, 10, '2031-05-10 12:00:00');
        $this->siembra->lineaEntrada($primero, $this->vino, 2);
        $this->db->query("INSERT INTO albprolinea (idalbpro, Numalbpro, idArticulo, ncant, nunidades, estadoLinea, ref_prov, costeSiva, iva, nfila)
            SELECT id, Numalbpro, {$this->vino}, 1, 1, 'Eliminado', '', 5, 21, 9 FROM albprot WHERE id = $primero");
        $segundo = $this->albaran($this->tomate, 5, '2031-05-12 12:00:00');
        $this->albaran($this->tomate, 99, '2031-06-01 12:00:00');

        $resumen = $this->proveedores->albaranesProveedoresFechas($this->idProveedor, '2031-05-01', '2031-05-31');

        self::assertEqualsCanonicalizing(
            [[$primero, $this->tomate, 10.0], [$primero, $this->vino, 2.0], [$segundo, $this->tomate, 5.0]],
            array_map(static fn (array $l): array => [(int) $l['idalbpro'], (int) $l['idArticulo'], (float) $l['totalUnidades']], $resumen['productos'])
        );
        self::assertSame([(string) $primero, (string) $segundo], array_column($resumen['resumenBases'], 'idalbpro'));
        self::assertSame(['30.00', '4.10'], [$resumen['resumenBases'][0]['sumabase'], $resumen['resumenBases'][0]['sumarIva']]);
        $desglose = array_column($resumen['desglose'], null, 'iva');
        self::assertSame(['30.00', '3.00'], [$desglose['10']['sumBase'], $desglose['10']['sumiva']]);
        self::assertSame(['10.00', '2.10'], [$desglose['21']['sumBase'], $desglose['21']['sumiva']]);
    }

    /** @comportamiento Sin fechas, entran todos los albaranes del proveedor; sin ninguno en el intervalo, un aviso. */
    public function test_sinFechasTodosYSinAlbaranesUnAviso(): void
    {
        $this->albaran($this->tomate, 10, '2031-05-10 12:00:00');
        $this->albaran($this->tomate, 99, '2031-06-01 12:00:00');

        self::assertCount(2, $this->proveedores->albaranesProveedoresFechas($this->idProveedor, '', '')['resumenBases']);
        $vacio = $this->proveedores->albaranesProveedoresFechas($this->idProveedor, '2040-01-01', '2040-01-31');
        self::assertSame('warning', $vacio['error'][0]['tipo']);
        self::assertArrayNotHasKey('productos', $vacio);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/clases/ClaseProveedor.php:191-204
     *
     * @group defecto
     * @group proveedor
     * @group medio
     *
     * @que-ocurre-hoy El resumen de albaranes cuenta también los que están «Sin Guardar»: sus productos, sus
     *   bases y su IVA suman en el resumen, en su impresión y en la factura que se crea desde él, aunque el
     *   resumen anual de facturas excluye ese estado.
     * @que-deberia-ocurrir Que solo cuenten los albaranes guardados.
     * @por-que-ocurre La consulta de los albaranes del proveedor no filtra por estado.
     * @como-deberia-funcionar Excluir el estado «Sin Guardar», como la vista del resumen anual.
     */
    public function test_unAlbaranSinGuardarNoEntraEnElResumen(): void
    {
        $guardado = $this->albaran($this->tomate, 10, '2031-05-10 12:00:00');
        $this->albaran($this->tomate, 5, '2031-05-11 12:00:00', ['estado' => 'Sin Guardar']);

        $resumen = $this->proveedores->albaranesProveedoresFechas($this->idProveedor, '2031-05-01', '2031-05-31');

        self::assertSame([(string) $guardado], array_column($resumen['resumenBases'], 'idalbpro'));
    }

    /**
     * @comportamiento El resumen anual suma, por ejercicio, el total y el IVA de las facturas del proveedor
     *   en cada trimestre y en el año; las «Sin Guardar» no entran.
     */
    public function test_elResumenAnualSumaLasFacturasPorTrimestre(): void
    {
        $this->factura($this->albaran($this->tomate, 10, '2031-02-10 12:00:00'), '2031-02-10 12:00:00');
        $this->factura($this->albaran($this->vino, 2, '2031-11-10 12:00:00'), '2031-11-10 12:00:00');
        $this->factura($this->albaran($this->vino, 4, '2031-11-20 12:00:00'), '2031-11-20 12:00:00', 'Sin Guardar');
        $this->factura($this->albaran($this->tomate, 1, '2030-05-10 12:00:00'), '2030-05-10 12:00:00');

        $resumen = array_column($this->proveedores->obtenerResumenAnualProveedor($this->idProveedor)['resumen_facturas'], null, 'ejercicio');

        self::assertSame(['22.00', '2.00', '0.00', '12.10', '2.10', '34.10', '4.10'], [
            $resumen['2031']['q1'], $resumen['2031']['q1Iva'], $resumen['2031']['q2'], $resumen['2031']['q4'], $resumen['2031']['q4Iva'],
            $resumen['2031']['total'], $resumen['2031']['totalIva'],
        ]);
        self::assertSame('2.20', $resumen['2030']['total']);
    }
}

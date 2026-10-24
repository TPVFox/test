<?php

/**
 * Las funciones de pintado y de cálculo de los clientes (`modulos/mod_cliente/funciones.php`): el
 * buscador de productos de la tarifa, las tablas de los documentos de la ficha, las fechas de los
 * resúmenes, los desplegables, las filas de productos de un resumen con su precio medio, y el resumen
 * anual de un cliente y de todos.
 *
 * Ninguna consulta la base: reciben los datos ya leídos, y aquí se les dan los del caso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModCliente;

use PHPUnit\Framework\TestCase;

final class FuncionesClienteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_cliente/funciones.php';
    }

    /** @param array<string, mixed> $cambios */
    private function producto(array $cambios = []): array
    {
        return $cambios + ['idArticulo' => 5, 'articulo_name' => 'Pera conferencia', 'iva' => '10.00', 'pvpSiva' => '2.090909', 'pvpCiva' => '2.30', 'crefTienda' => '1001', 'cref_tienda_principal' => '1001', 'codBarras' => '8400000000017'];
    }

    /** @param array<string, mixed> $cambios */
    private function linea(array $cambios = []): array
    {
        return $cambios + ['idArticulo' => '5', 'ccodbar' => '8400000000017', 'cdetalle' => 'Pera conferencia', 'totalUnidades' => '2', 'precioCiva' => '2.30'];
    }

    /** @comportamiento El buscador de la tarifa lista los productos encontrados, cada uno con lo que lleva a la línea de entrada al pulsarlo. */
    public function test_elBuscadorListaLosProductosEncontrados(): void
    {
        $resultado = \htmlProductos(1, [$this->producto(['articulo_name' => 'Pera "extra"'])], 'referencia', 'Referencia', '1001');

        self::assertStringContainsString('value="1001"', $resultado['html']);
        self::assertStringContainsString("escribirProductoSeleccionado('Pera &quot;extra&quot;','10.00','2.090909','2.30',5);", $resultado['html']);
        self::assertStringContainsString('<td>Pera &quot;extra&quot;</td>', $resultado['html']);
        self::assertSame(['encontrados' => 1, 'campo' => 'Referencia'], array_diff_key($resultado, ['html' => 0]));
    }

    /** @comportamiento Sin productos, el buscador pide palabras si la búsqueda está vacía, y si no, dice que no encontró nada. */
    public function test_sinProductosElBuscadorLoDice(): void
    {
        $vacia = \htmlProductos(0, [], 'descripcion', 'Descripcion', '');
        $sinNada = \htmlProductos(0, [], 'descripcion', 'Descripcion', 'Kiwi');

        self::assertStringContainsString('Pon las palabras para buscar productos', $vacia['html']);
        self::assertStringContainsString('No se encontrado nada con esa busqueda', $sinNada['html']);
    }

    /**
     * @comportamiento La tabla de los tickets de un cliente enlaza cada uno con su ticket y ofrece su
     *   resumen; la de los descuentos dice lo comprado; y sin documentos, lo dice. Pinta como mucho
     *   once filas.
     */
    public function test_laTablaDeDocumentosEnlazaCadaUno(): void
    {
        $tickets = array_map(static fn (int $i): array => ['fecha' => '2026-10-0' . ($i % 9 + 1), 'num' => (string) (100 + $i), 'total' => '10.00', 'estado' => 'Cerrado', 'id' => (string) $i, 'idCliente' => '7'], range(1, 15));

        $html = \htmlTablaGeneral($tickets, '/TPVFox', 'tickets');
        $descuentos = \htmlTablaGeneral([['fecha' => '2026-09-30', 'num' => '4', 'total' => '1.50', 'estado' => 'Pendiente', 'id' => '1', 'idCliente' => '7', 'importeTickets' => '30.00']], '/TPVFox', 'desc_tickets');
        $vacia = \htmlTablaGeneral([], '/TPVFox', 'albaranes');

        self::assertStringContainsString('href="/TPVFox/modulos/mod_tpv/ticketCobrado.php?id=1"', $html);
        self::assertStringContainsString("onclick=\"resumen('tickets', 7)\"", $html);
        self::assertSame(11, substr_count($html, '<tr><td>'));
        self::assertStringContainsString('title="Comprado 30.00€ en estos tickets"', $descuentos);
        self::assertSame('<div class="alert alert-info">Este cliente no tiene albaranes</div>', $vacia);
    }

    /** @comportamiento Las fechas del resumen se ponen en año-mes-día; sin la inicial, desde el año 2000; sin la final, hasta hoy. */
    public function test_lasFechasDelResumenSeCompletan(): void
    {
        self::assertSame(['fechaIni' => '2026-03-01', 'fechaFin' => '2026-03-31'], \comprobarFechas('01-03-2026', '2026/03/31'));
        self::assertSame(['fechaIni' => '2000-01-01', 'fechaFin' => date('Y-m-d')], \comprobarFechas('', ''));
    }

    /** @comportamiento El desplegable empieza por «Seleccione opción» y marca la opción del valor dado; sin datos, solo esa. */
    public function test_elDesplegableMarcaElValor(): void
    {
        $html = \getHtmlOptions([['id' => '1', 'descripcion' => 'Contado'], ['id' => '2', 'descripcion' => '30 días']], '2');

        self::assertStringStartsWith('<option value="0">	Seleccione opción </option>', $html);
        self::assertStringContainsString('<option value="2" selected>30 días</option>', $html);
        self::assertStringNotContainsString('<option value="1" selected>', $html);
        self::assertSame('<option value="0">	Seleccione opción </option>', \getHtmlOptions(false));
    }

    /**
     * @comportamiento Las filas de productos de un resumen juntan las de un mismo producto, suman sus
     *   unidades y, si se vendió a precios distintos, ponen el precio medio con su marca; van por orden
     *   de nombre, y dan el total.
     */
    public function test_lasFilasDeProductosJuntanCadaProducto(): void
    {
        $filas = \getHmtlTrProductos([
            $this->linea(['totalUnidades' => '1', 'precioCiva' => '2.00']),
            $this->linea(['idArticulo' => '6', 'cdetalle' => 'Manzana', 'ccodbar' => '', 'totalUnidades' => '3', 'precioCiva' => '1.00']),
            $this->linea(['totalUnidades' => '1', 'precioCiva' => '3.00']),
        ], 'pantalla');

        self::assertSame(8.0, $filas['totalLineas']);
        self::assertMatchesRegularExpression('#<tr><td>6</td>.*<td>Manzana</td><td>3\.00</td><td>1\.00</td><td>3\.00</td></tr><tr><td>5</td>#', $filas['html']);
        self::assertStringContainsString('<td>Pera conferencia</td><td>2.00</td><td>2.50<span title="Precio medio">*</span></td><td>5.00</td>', $filas['html']);
    }

    /** @comportamiento En el PDF, las filas de productos no llevan el número ni el código de barras. */
    public function test_enElPdfLasFilasNoLlevanNumeroNiCodigo(): void
    {
        $filas = \getHmtlTrProductos([$this->linea()], 'pdf');

        self::assertSame('<tr><td><font size="8">Pera conferencia</font></td><td><font size="8">2.00</font></td><td><font size="8">2.30</font></td><td><font size="8">4.60</font></td></tr>', $filas['html']);
    }

    /** @comportamiento El resumen anual de un cliente toma sus tickets y sus facturas del ejercicio y los suma por trimestre. */
    public function test_elResumenAnualSumaTicketsYFacturas(): void
    {
        $fila = static fn (int $cliente, int $ejercicio, float $q1, float $q3): array => ['idCliente' => (string) $cliente, 'ejercicio' => (string) $ejercicio, 'q1' => $q1, 'q1Iva' => $q1 / 10, 'q2' => 0, 'q2Iva' => 0, 'q3' => $q3, 'q3Iva' => $q3 / 10, 'q4' => 0, 'q4Iva' => 0, 'total' => $q1 + $q3, 'totalIva' => ($q1 + $q3) / 10];
        $resumen = ['resumen_tickets' => [$fila(7, 2026, 100, 50), $fila(7, 2025, 999, 0), $fila(8, 2026, 10, 0)], 'resumen_facturas' => [$fila(7, 2026, 20, 0)]];

        $cliente = \validarResumenAnual($resumen, 2026, 7);
        $todos = \validarResumenAnualTodosClientes($resumen, 2026);

        self::assertSame([150.0, 20.0, 170.0, 120.0], [(float) $cliente['tickets']['total'], (float) $cliente['facturas']['total'], $cliente['total']['total'], $cliente['total']['q1']]);
        self::assertSame(['7', '8'], array_map('strval', array_keys($todos)));
        self::assertSame(10.0, $todos[8]['total']['total']);
    }

    /** @comportamiento La tabla del resumen anual enseña los tickets y las facturas solo si tienen importe, y siempre el total. */
    public function test_laTablaDelResumenAnualEnsenaLoQueTieneImporte(): void
    {
        $vacio = array_fill_keys(['q1Iva', 'q1', 'q2Iva', 'q2', 'q3Iva', 'q3', 'q4Iva', 'q4', 'totalIva', 'total'], 0);
        $tickets = ['q1' => 110.0, 'q1Iva' => 10.0, 'total' => 110.0, 'totalIva' => 10.0] + $vacio;

        $html = \htmlTablaResumenAnual(['tickets' => $tickets, 'facturas' => $vacio, 'total' => $tickets]);

        self::assertStringContainsString('<h4>Tickets</h4>', $html);
        self::assertStringNotContainsString('<h4>Facturas</h4>', $html);
        self::assertStringContainsString('<h4>Total Anual</h4>', $html);
        self::assertStringContainsString('<td>100.00</td>', $html);
    }

    /** @comportamiento El resumen de un cliente enseña sus datos escapados y enlaza su ficha, sus albaranes y sus facturas. */
    public function test_elResumenDeUnClienteEnsenaSusDatos(): void
    {
        $vacio = array_fill_keys(['q1Iva', 'q1', 'q2Iva', 'q2', 'q3Iva', 'q3', 'q4Iva', 'q4', 'totalIva', 'total'], 0);

        $html = \mostrarResumenCliente(['idClientes' => '7', 'Nombre' => 'Bar <Pepe>', 'nif' => 'B1', 'direccion' => 'Rúa', 'telefono' => '9', 'email' => 'a@b'], ['tickets' => $vacio, 'facturas' => $vacio, 'total' => $vacio]);

        self::assertStringContainsString('<h2>Resumen Anual de Bar &lt;Pepe&gt;</h2>', $html);
        self::assertStringContainsString('href="./../cliente.php?id=7&accion=ver"', $html);
        self::assertStringContainsString('href="../../mod_venta/facturasListado.php?buscar=Bar+%3CPepe%3E"', $html);
    }
}

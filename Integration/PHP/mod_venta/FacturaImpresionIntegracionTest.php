<?php

/**
 * El documento imprimible de la factura de cliente: la unica de las tres composiciones de
 * `montarHTMLimprimir()` que intercala cabeceras de albaran entre las lineas.
 *
 * La funcion vive en `funciones.php`, de la capa compartida, pero esta rama es exclusiva de
 * la factura y su efecto solo se observa aqui: por eso el caso vive en este componente y no
 * en el 1, que verifico la composicion comun.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CargaAislada;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class FacturaImpresionIntegracionTest extends CasoIntegracion
{
    private Siembra $siembra;

    /** @var array<string,string> */
    private array $datosTienda;

    protected function setUp(): void
    {
        parent::setUp();
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
        $this->incluirTPVFox('/clases/cliente.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $this->incluirTPVFox('/modulos/mod_venta/clases/facturasVentas.php');

        $this->siembra = new Siembra($this->db);
        $this->datosTienda = [
            'NombreComercial' => 'Tienda',
            'razonsocial'     => 'Tienda SL',
            'direccion'       => 'Calle 1',
            'nif'             => 'B1',
            'telefono'        => '900',
        ];
    }

    public function test_factura_intercalaLaCabeceraDelAlbaranAntesDeSusLineas(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de factura a imprimir');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);
        $numeroAlbaran = $this->numeroDeAlbaran($idAlbaran);

        $resultado = \montarHTMLimprimir($idFactura, $this->db, 'factura', $this->datosTienda);

        self::assertStringContainsString('Factura de Cliente', $resultado['cabecera']);
        self::assertStringContainsString('Nun Alb:' . $numeroAlbaran, $resultado['html']);
        self::assertStringContainsString('Articulo de factura a imprimir', $resultado['html']);
    }

    /**
     * Las cabeceras de albaran se emiten por posicion en la lista, y esa posicion solo
     * avanza cuando aparece una linea de otro albaran. Un albaran sin lineas —el caso que el
     * issue TPVFox #159 reporto en 2024— no consume la suya, de modo que a partir de ahi
     * cada bloque de lineas sale bajo la cabecera del albaran anterior y el ultimo albaran
     * de la factura se queda sin cabecera.
     */
    public function test_defecto_unAlbaranSinLineasDesplazaLasCabecerasDeLosDemas(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de factura con albaran vacio');

        // La factura se emite desde un albaran sin ninguna linea: su cabecera es la primera
        // de la lista. Es el caso que el issue reporta —incorporar un albaran a cero—.
        $vacio = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-11');
        $this->db->query("DELETE FROM albclilinea WHERE idalbcli = $vacio");
        $idFactura = $this->siembra->facturarAlbaranCliente($vacio);

        // Y despues se incorpora el albaran que si aporta lineas.
        $conLineas = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $this->incorporarAlbaranAFactura($idFactura, $conLineas);

        $resultado = \montarHTMLimprimir($idFactura, $this->db, 'factura', $this->datosTienda);

        // La cabecera que se imprime es la del albaran vacio, que se lleva la unica posicion
        // que las lineas consumen; la del albaran que las aporta no llega a imprimirse.
        self::assertStringContainsString('Nun Alb:' . $this->numeroDeAlbaran($vacio), $resultado['html']);
        self::assertStringNotContainsString('Nun Alb:' . $this->numeroDeAlbaran($conLineas), $resultado['html']);
    }

    /**
     * La consulta que sirve los albaranes de la factura devuelve la fecha bajo la clave
     * `Fecha` y la composicion la busca bajo `fecha`, de modo que la columna de fecha de
     * cada cabecera de albaran sale siempre vacia.
     */
    public function test_defecto_laFechaDeLaCabeceraDelAlbaranSaleSiempreVacia(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de factura con fecha de albaran');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);

        $resultado = \montarHTMLimprimir($idFactura, $this->db, 'factura', $this->datosTienda);

        self::assertStringContainsString('Nun Alb:' . $this->numeroDeAlbaran($idAlbaran), $resultado['html']);
        self::assertStringNotContainsString('2026-01-10', $resultado['html'], 'La fecha del albaran no llega al documento.');
    }

    /**
     * El caso correcto, que ninguna prueba cubria: dos albaranes que si aportan lineas.
     * Posicion e identidad coinciden y cada bloque sale bajo su cabecera. Sin este caso el
     * defecto de la prueba anterior no queda acotado. Hueco cerrado desde `MCT-2026-052-TPY`.
     */
    public function test_factura_conDosAlbaranesConLineasEmiteLasDosCabeceras(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de factura con dos albaranes');
        $primero = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($primero);
        $segundo = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-11');
        $this->incorporarAlbaranAFactura($idFactura, $segundo);

        $resultado = \montarHTMLimprimir($idFactura, $this->db, 'factura', $this->datosTienda);

        self::assertStringContainsString('Nun Alb:' . $this->numeroDeAlbaran($primero), $resultado['html']);
        self::assertStringContainsString('Nun Alb:' . $this->numeroDeAlbaran($segundo), $resultado['html']);
    }

    /**
     * Una factura compuesta solo de lineas directas, sin ningun albaran: el documento sale
     * sin cabeceras intercaladas y con todas sus lineas. Hueco cerrado desde `MCT-2026-052-TPY`.
     */
    public function test_factura_sinAlbaranesEmiteElDocumentoSinCabecerasIntercaladas(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de factura sin albaranes');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10');
        $idFactura = $this->siembra->facturarAlbaranCliente($idAlbaran);
        $this->db->query("DELETE FROM albclifac WHERE idFactura = $idFactura");
        $this->db->query("UPDATE facclilinea SET NumalbCli = 0 WHERE idfaccli = $idFactura");

        $resultado = \montarHTMLimprimir($idFactura, $this->db, 'factura', $this->datosTienda);

        self::assertStringNotContainsString('Nun Alb:', $resultado['html']);
        self::assertStringContainsString('Articulo de factura sin albaranes', $resultado['html']);
    }

    // --- Apoyos del caso --------------------------------------------------------

    private function numeroDeAlbaran(int $idAlbaran): int
    {
        return (int) $this->db
            ->query("SELECT Numalbcli FROM albclit WHERE id=$idAlbaran")
            ->fetch_assoc()['Numalbcli'];
    }

    /**
     * Incorpora un albaran ya guardado a una factura existente: copia sus lineas y escribe
     * el enlace, tal como lo deja el guardado de la factura.
     */
    private function incorporarAlbaranAFactura(int $idFactura, int $idAlbaran): void
    {
        $numeroFactura = (int) $this->db
            ->query("SELECT Numfaccli FROM facclit WHERE id=$idFactura")->fetch_assoc()['Numfaccli'];
        $numeroAlbaran = $this->numeroDeAlbaran($idAlbaran);
        $fila = (int) $this->db
            ->query("SELECT COALESCE(MAX(nfila),0) as n FROM facclilinea WHERE idfaccli=$idFactura")
            ->fetch_assoc()['n'];

        $lineas = $this->db->query("SELECT * FROM albclilinea WHERE idalbcli=$idAlbaran ORDER BY nfila");
        while ($linea = $lineas->fetch_assoc()) {
            $fila++;
            $this->db->query(
                'INSERT INTO facclilinea (idfaccli, Numfaccli, idArticulo, cdetalle, ncant, nunidades,'
                . ' pvpSiva, precioCiva, iva, nfila, estadoLinea, NumalbCli) VALUES ('
                . $idFactura . ', ' . $numeroFactura . ', ' . (int) $linea['idArticulo'] . ', "'
                . $linea['cdetalle'] . '", ' . $linea['ncant'] . ', ' . $linea['nunidades'] . ', '
                . $linea['pvpSiva'] . ', ' . $linea['precioCiva'] . ', ' . $linea['iva'] . ', '
                . $fila . ', "Activo", ' . $numeroAlbaran . ')'
            );
        }

        $this->db->query(
            'INSERT INTO albclifac (idFactura, numFactura, idAlbaran, numAlbaran) VALUES ('
            . $idFactura . ', ' . $idFactura . ', ' . $idAlbaran . ', ' . $numeroAlbaran . ')'
        );
    }
}

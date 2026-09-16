<?php

/**
 * `tareas.php` como punto de entrada real: un `$_POST['pulsado']` por caso, sin
 * pasar por HTTP. Usa `DespachoTareas`, que resuelve lo que este fichero da por hecho —
 * `$BDTpv` global, su propio `include_once` relativo, la salida por `echo`.
 *
 * Los quince casos de despacho se reparten en dos ficheros: los que se comportan como cabe
 * esperar, aquí, y los tres defectuosos (`modificarEstadoDocumento`, `anhadirPedidoTemp`,
 * `buscarPedido`) en `TareasDefectosIntegracionTest`.
 *
 * Cada caso comprueba ademas el conjunto exacto de claves de la respuesta (`array_keys`), no solo
 * el valor que le interesa a ese test: no hay contrato declarado en ningun fichero (nada mas lo
 * comprueba), y el navegador lee esas claves de memoria. Si una clave cambia de nombre, se anhade o
 * se retira sin tocar la funcion de `funciones.js` que la lee, esta aserción es la que lo detecta.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\DespachoTareas;
use TPVFox\Test\Siembra\Siembra;

final class TareasDespachoIntegracionTest extends CasoIntegracion
{
    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_venta/tareas.php';

    private Siembra $siembra;
    private int $idArticulo;
    private int $idUsuario;

    /** @var string[] Ficheros que datosImprimir escribe fuera de la transaccion. */
    private array $rutasEmitidas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siembra = new Siembra($this->db);
        $this->idArticulo = $this->siembra->articulo('Articulo de despacho');
        $this->idUsuario = $this->siembra->usuarioPorDefecto();
        $_SESSION['usuarioTpv'] = ['id' => $this->idUsuario];
    }

    protected function tearDown(): void
    {
        unset($_SESSION['usuarioTpv']);
        foreach ($this->rutasEmitidas as $ruta) {
            if (file_exists($ruta)) {
                unlink($ruta);
            }
        }
        parent::tearDown();
    }

    private function despachar(array $post): mixed
    {
        return DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, $post);
    }

    public function test_buscarProductos_devuelveElListado(): void
    {
        $idCliente = $this->siembra->clientePorDefecto();

        $r = $this->despachar([
            'pulsado' => 'buscarProductos',
            'valorCampo' => 'despacho',
            'campo' => 'a.articulo_name',
            'cajaInput' => 'idArticulo',
            'idcaja' => 'cajaBusqueda',
            'dedonde' => 'albaran',
            'idCliente' => $idCliente,
        ]);

        self::assertSame('Listado', $r['Estado']);
        self::assertSame(['listado', 'Estado', 'datos'], array_keys($r), 'contrato: claves que buscarProductos() del navegador lee');
    }

    public function test_buscarClientes_porId(): void
    {
        $idCliente = $this->siembra->clientePorDefecto();

        $r = $this->despachar([
            'pulsado' => 'buscarClientes',
            'busqueda' => (string) $idCliente,
            'dedonde' => 'albaran',
            'idcaja' => 'id_cliente',
        ]);

        self::assertSame((string) $idCliente, $r['id'], 'mysqli::fetch_assoc devuelve todo como cadena');
        self::assertSame(['Nitems', 'id', 'nombre', 'formasVenci', 'datos'], array_keys($r), 'contrato: claves que buscarClientes() del navegador lee');
    }

    public function test_buscarAdjunto_sinCoincidenciasMuestraModalVacio(): void
    {
        $idCliente = $this->siembra->clientePorDefecto();

        $r = $this->despachar([
            'pulsado' => 'buscarAdjunto',
            'busqueda' => '',
            'idCliente' => $idCliente,
            'dedonde' => 'factura', // busca albaranes con estado Guardado
        ]);

        self::assertSame(0, $r['Nitems']);
        self::assertSame(['res', 'Nitems', 'html'], array_keys($r), 'contrato: claves que buscarAdjunto() del navegador lee');
    }

    public function test_comprobarAlbaran_conAlbaranesGuardadosLosCuenta(): void
    {
        $idCliente = $this->siembra->clientePorDefecto();
        $this->siembra->ventaAlbaranCliente($this->idArticulo, 1.0, '2026-01-10', ['idCliente' => $idCliente]);

        $r = $this->despachar([
            'pulsado' => 'comprobarAlbaran',
            'idCliente' => $idCliente,
            'dedonde' => 'factura',
        ]);

        // AlbaranesVentas::ComprobarAlbaranes() usa 'NItems' (dos mayusculas); el propio
        // BuscarProductos() de funciones.php usa 'Nitems' -- inconsistencia de nombres
        // entre dos partes de la misma capa, sin efecto funcional mas alla de la confusion
        // al leer el codigo que consume una u otra.
        self::assertGreaterThan(0, $r['NItems'] ?? 0);
        self::assertSame(['NItems'], array_keys($r), 'contrato: claves que comprobarAdjuntosExis() del navegador lee');
    }

    public function test_anhadirTemporal_creaElRegistro(): void
    {
        $r = $this->despachar([
            'pulsado' => 'anhadirTemporal',
            'idTemporal' => 0,
            'idUsuario' => $this->idUsuario,
            'idTienda' => $this->siembra->tiendaPorDefecto(),
            'idReal' => 0,
            'fecha' => '2026-01-10',
            'productos' => json_encode([]),
            'idCliente' => $this->siembra->clientePorDefecto(),
            'dedonde' => 'pedido',
        ]);

        self::assertArrayHasKey('id', $r);
        $fila = $this->db->query("SELECT COUNT(*) c FROM pedcliltemporales WHERE id={$r['id']}")->fetch_assoc();
        self::assertSame('1', $fila['c']);
        self::assertSame(
            ['numDocumento', 'total', 'totales', 'htmlTabla', 'id', 'existe', 'productos'],
            array_keys($r),
            'contrato: claves que addTemporal() del navegador lee'
        );
    }

    public function test_cancelarTemporal_conIdCeroAvisaQueSoloSeCancelaLoTemporal(): void
    {
        $r = $this->despachar([
            'pulsado' => 'cancelarTemporal',
            'idTemporal' => 0,
            'dedonde' => 'pedido',
        ]);

        self::assertSame('Info!', $r['tipo']);
        self::assertSame(['tipo', 'dato', 'class', 'mensaje'], array_keys($r), 'contrato: claves que cancelarTemporal() del navegador lee');
    }

    public function test_htmlAgregarFilaAdjunto_componeLaFila(): void
    {
        $r = $this->despachar([
            'pulsado' => 'htmlAgregarFilaAdjunto',
            'datos' => ['NumAdjunto' => 5, 'fecha' => '2026-01-01', 'total' => 10, 'nfila' => 1, 'estado' => 'Activo'],
            'dedonde' => 'albaran',
        ]);

        self::assertStringContainsString('lineaP1', $r['html']);
        self::assertSame(['html'], array_keys($r), 'contrato: claves que AgregarFilaAdjunto() del navegador lee');
    }

    public function test_htmlAgregarFilasProductos_conUnProductoSuelto(): void
    {
        $producto = [
            'pvpSiva' => 10.0, 'nunidades' => 2, 'idArticulo' => $this->idArticulo,
            'cref' => 'R1', 'ccodbar' => '', 'cdetalle' => 'D', 'precioCiva' => 12.1,
            'iva' => 21, 'nfila' => 1, 'estadoLinea' => 'Activo',
        ];

        $r = $this->despachar([
            'pulsado' => 'htmlAgregarFilasProductos',
            'productos' => $producto,
            'dedonde' => 'albaran',
        ]);

        self::assertStringContainsString('Row1', $r['html']);
        self::assertSame(['html'], array_keys($r), 'contrato: claves que AgregarFilaProductosAl() del navegador lee');
    }

    public function test_abririncidencia_componeElModal(): void
    {
        $r = $this->despachar([
            'pulsado' => 'abririncidencia',
            'dedonde' => 'albaran',
            'usuario' => $this->idUsuario,
            'configuracion' => '{}',
        ]);

        self::assertArrayHasKey('html', $r);
        self::assertArrayHasKey('datos', $r);
        self::assertSame(['html', 'datos'], array_keys($r), 'contrato: claves que abrirModalIndicencia() del navegador lee');
    }

    public function test_abrirIncidenciasAdjuntas_sinIncidenciasDevuelveHtmlVacio(): void
    {
        $idAlbaran = $this->siembra->ventaAlbaranCliente($this->idArticulo, 1.0, '2026-01-10');

        $r = $this->despachar([
            'pulsado' => 'abrirIncidenciasAdjuntas',
            'id' => $idAlbaran,
            'modulo' => 'mod_ventas',
            'dedonde' => 'albaran',
        ]);

        self::assertSame('', $r['html']);
        self::assertSame(['html'], array_keys($r), 'contrato: claves que abrirIncidenciasAdjuntas() del navegador lee');
    }

    public function test_nuevaIncidencia_laInsertaConElUsuarioDeSesion(): void
    {
        $idAlbaran = $this->siembra->ventaAlbaranCliente($this->idArticulo, 1.0, '2026-01-10');

        $r = $this->despachar([
            'pulsado' => 'nuevaIncidencia',
            'usuario' => $this->idUsuario,
            'fecha' => '2026-01-01',
            'datos' => json_encode(['vista' => 'albaran', 'idReal' => (string) $idAlbaran]),
            'estado' => '0',
            'mensaje' => 'una incidencia',
        ]);

        self::assertArrayHasKey('id', $r);
        // El navegador solo lee `resultado.error` (ausente aqui, luego falsy) y, si lo hay,
        // `resultado.mensaje`: no consume `id` ni `sql`, asi que el contrato de esta llamada es mas
        // laxo que el de las demas -- se deja constancia igualmente de lo que hoy se emite.
        self::assertSame(['id', 'sql'], array_keys($r), 'contrato: forma actual de la respuesta de nuevaIncidencia()');
    }

    public function test_datosImprimir_generaElPdfYDevuelveSuRuta(): void
    {
        $idPedido = $this->siembra->pedidoVentaCliente($this->idArticulo, 1.0, '2026-01-10');

        $ruta = $this->despachar([
            'pulsado' => 'datosImprimir',
            'id' => $idPedido,
            'dedonde' => 'pedido',
            'tienda' => 1,
        ]);

        // Contrato de este caso: la respuesta entera es la ruta, sin envoltura de array.
        self::assertIsString($ruta);
        $rutaReal = RUTA_TPVFOX . '/../datos/tmp/pedidoventas.pdf';
        $this->rutasEmitidas[] = $rutaReal;
        self::assertFileExists($rutaReal);
    }
}

<?php

/**
 * El mayor de un producto de `mod_productos` (`DetalleMayor.php`): sus movimientos entre dos fechas
 * —compras, ventas por ticket y albaranes de venta—, con la existencia que deja cada uno, los
 * subtotales de cada mes y el enlace a su documento.
 *
 * Cada pantalla se pinta en un PHP aparte, desde la carpeta de `mod_productos`, con la sesión del
 * usuario de pruebas y su tienda —o sin sesión, si el caso lo pide— y una transacción que se deshace
 * al terminar. El caso siembra un producto (`:p1`, `:p2`…), un proveedor y un cliente, y los
 * documentos de cada movimiento.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class MayorProductosIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    private int $idUsuario;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
    }

    /**
     * @comportamiento El mayor enseña cada movimiento del producto entre las dos fechas, en orden, con su
     *   entrada o su salida y la existencia que deja desde la inicial indicada.
     */
    public function test_elMayorEnsenaCadaMovimientoConLaExistenciaQueDeja(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31', 'stock' => '5'], [
            ...$this->producto('Kiwi'), ...$this->terceros(),
            ...$this->compra('2026-10-01 10:00:00', 10), ...$this->ticket('2026-10-02 10:00:00', 3), ...$this->albaranDeVenta('2026-10-03 10:00:00', 2),
        ]);

        self::assertStringContainsString('<h2 class="text-center">Kiwi</h2>', $pantalla['html']);
        self::assertSame(['15', '12', '10'], $this->existencias($pantalla['html']));
        self::assertSame('', $pantalla['errores']);
    }

    /** @comportamiento Un movimiento fuera de las dos fechas no sale en el mayor. */
    public function test_unMovimientoFueraDeLasFechasNoSale(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-09-30 10:00:00', 10), ...$this->ticket('2026-10-02 10:00:00', 3),
        ]);

        self::assertSame(['-3'], $this->existencias($pantalla['html']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/DetalleMayor.php:30
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Con la fecha inicial y sin la final, el mayor sale sin ningún movimiento: toma la
     *   final vacía en lugar de la de hoy.
     * @que-deberia-ocurrir Que sin fecha final tome la de hoy, como dice la pantalla.
     * @por-que-ocurre La comprobación de la fecha final mira si falta la inicial.
     * @como-deberia-funcionar Comprobar la fecha final.
     */
    public function test_sinFechaFinalTomaLaDeHoy(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => ''], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 10),
        ]);

        self::assertSame(['10'], $this->existencias($pantalla['html']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/DetalleMayor.php:239
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El enlace de una compra del mayor abre el albarán de compra cuyo identificador es el
     *   número del albarán, que puede ser otro.
     * @que-deberia-ocurrir Que abra el albarán de la compra.
     * @por-que-ocurre El cálculo del mayor da como identificador de la compra su número de albarán.
     * @como-deberia-funcionar Enlazar por el identificador del albarán.
     */
    public function test_cadaCompraEnlazaConSuAlbaran(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 10, 777777),
        ]);

        self::assertStringContainsString('mod_compras/albaran.php?id=' . $pantalla['ids'][':albpro'] . '&accion=ver', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/DetalleMayor.php:250
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El enlace de un albarán de venta del mayor abre el albarán cuyo identificador es su
     *   número, que puede ser otro.
     * @que-deberia-ocurrir Que abra el albarán de la venta.
     * @por-que-ocurre El cálculo del mayor da como identificador del albarán de venta su número.
     * @como-deberia-funcionar Enlazar por el identificador del albarán.
     */
    public function test_cadaAlbaranDeVentaEnlazaConSuAlbaran(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->albaranDeVenta('2026-10-03 10:00:00', 2, 777777),
        ]);

        self::assertStringContainsString('mod_venta/albaran.php?id=' . $pantalla['ids'][':albcli'] . '&estado=ver', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/DetalleMayor.php:283
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El mayor escribe tal cual el nombre del cliente o del proveedor de cada movimiento: un
     *   nombre con una etiqueta de script la ejecuta al abrir el mayor.
     * @que-deberia-ocurrir Que lo escriba como texto.
     * @por-que-ocurre La fila pinta el nombre sin escaparlo.
     * @como-deberia-funcionar Escapar el nombre al pintarlo.
     */
    public function test_losNombresSeEscribenComoTexto(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(self::SCRIPT), ...$this->ticket('2026-10-02 10:00:00', 3),
        ]);

        self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/DetalleMayor.php:211
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Con movimientos en el mismo mes de dos años seguidos y ninguno entre medias, el mayor
     *   los suma en un solo subtotal.
     * @que-deberia-ocurrir Que cada mes de cada año tenga su subtotal.
     * @por-que-ocurre El cambio de mes se mira por el número del mes, sin el año.
     * @como-deberia-funcionar Comparar el año y el mes.
     */
    public function test_elMismoMesDeDosAnosTieneSuSubtotal(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2025-01-01', 'fecha_final' => '2026-12-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2025-01-10 10:00:00', 10), ...$this->ticket('2026-01-10 10:00:00', 3),
        ]);

        self::assertSame(2, substr_count($pantalla['html'], '<b>Subtotal</b>'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/DetalleMayor.php:17
     * @codigo-afectado modulos/mod_producto/clases/ClaseArticulos.php:213
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un número de producto con una condición añadida en la dirección enseña en el mayor
     *   los movimientos de todos los productos.
     * @que-deberia-ocurrir Que enseñe solo los del producto indicado.
     * @por-que-ocurre La pantalla toma el número como llega, y el cálculo del mayor lo pega a su consulta.
     * @como-deberia-funcionar Convertir el número del producto a entero al recibirlo.
     */
    public function test_unNumeroDeProductoConUnaCondicionNoEnsenaOtrosProductos(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1 OR 1=1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 10),
            ...$this->producto('Pera'), ...$this->compra('2026-10-02 10:00:00', 4),
        ]);

        self::assertCount(1, $this->existencias($pantalla['html']));
    }

    /** @return list<string> La existencia de cada fila de movimiento, en orden. */
    private function existencias(string $html): array
    {
        preg_match_all('#<td>\d{4}-\d\d-\d\d[^<]*</td>(?:<td>[^<]*</td>){2}<td>(-?[\d.]+)</td>#', $html, $m);

        return $m[1];
    }

    /** @return list<string> */
    private function producto(string $nombre): array
    {
        return [
            "INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, iva, beneficio, tipo) VALUES ('{$nombre}', 'Activo', '2026-01-01', 1, 21, 10, 'unidad')",
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 2.42, 2, :tienda)',
        ];
    }

    /** @return list<string> El proveedor y el cliente de los movimientos. */
    private function terceros(string $nombre = 'Frutas del Norte'): array
    {
        return [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('{$nombre}', '{$nombre}', 'B00000000', 'Calle Mayor 1', '2026-01-01', 'activo')",
            "INSERT INTO clientes (Nombre, estado) VALUES ('{$nombre}', 'Activo')",
        ];
    }

    /** @return list<string> */
    private function compra(string $fecha, float $unidades, int $numero = 1): array
    {
        return [
            "INSERT INTO albprot (Numalbpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva) VALUES ({$numero}, '{$fecha}', :tienda, :usuario, :proveedor, 'Guardado', 0)",
            "INSERT INTO albprolinea (idalbpro, Numalbpro, idArticulo, ref_prov, nunidades, costeSiva) VALUES (:albpro, {$numero}, :pN, '', {$unidades}, 1)",
        ];
    }

    /** @return list<string> */
    private function ticket(string $fecha, float $unidades): array
    {
        return [
            "INSERT INTO ticketst (Numticket, Numtempticket, Fecha, idTienda, idUsuario, idCliente, estado, formaPago, entregado, total) VALUES (1, 1, '{$fecha}', :tienda, :usuario, :cliente, 'Cobrado', 'contado', 0, 0)",
            "INSERT INTO ticketslinea (idticketst, Numticket, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, precioCiva, iva, nfila, estadoLinea) VALUES (:ticket, 1, :pN, '', '', '', {$unidades}, {$unidades}, 2.42, 21, 1, 'Activo')",
        ];
    }

    /** @return list<string> */
    private function albaranDeVenta(string $fecha, float $unidades, int $numero = 1): array
    {
        return [
            "INSERT INTO albclit (Numalbcli, Fecha, idTienda, idUsuario, idCliente, estado) VALUES ({$numero}, '{$fecha}', :tienda, :usuario, :cliente, 'Guardado')",
            "INSERT INTO albclilinea (idalbcli, Numalbcli, idArticulo, nunidades, precioCiva) VALUES (:albcli, {$numero}, :pN, {$unidades}, 2.42)",
        ];
    }

    /**
     * Pinta el mayor de `mod_productos` en un PHP aparte, con la preparación sembrada en una transacción
     * que se deshace al terminar.
     *
     * @param array<string, string> $consulta
     * @param list<string> $preparacion
     * @return array{html: string, errores: string, ids: array<string, string>}
     */
    private function pintar(array $consulta, array $preparacion = [], bool $conSesion = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_productos/DetalleMayor.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_productos', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = 0;'
            . ' $claves = ["proveedores" => ":proveedor", "clientes" => ":cliente", "albprot" => ":albpro", "ticketst" => ":ticket", "albclit" => ":albcli"];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' if (preg_match("/^INSERT INTO (\\\\w+) /", $sql, $t)) { $id = (string) $BDTpv->insert_id;'
            . ' if (isset($claves[$t[1]])) { $sembrados[$claves[$t[1]]] = $id; } elseif ($t[1] === "articulos") { $cuentas++; $sembrados[":p" . $cuentas] = $sembrados[":pN"] = $id; } } }'
            . ' $_GET = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($consulta, true) . ');'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use (&$sembrados) { echo "\n@@DATOS@@" . json_encode(["ids" => $sembrados]); });'
            . ' include "DetalleMayor.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'mayor');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $datos = json_decode($datos, true) ?? [];
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores), 'ids' => $datos['ids'] ?? []];
        unlink($errores);

        return $pintada;
    }
}

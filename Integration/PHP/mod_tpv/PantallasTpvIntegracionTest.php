<?php

/**
 * Las pantallas del TPV: la venta (`modulos/mod_tpv/tpv.php`), el listado de tickets
 * (`ListaTickets.php`) y un ticket cobrado (`ticketCobrado.php`).
 *
 * Cada una se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna, y los permisos del módulo concedidos o con algunas de sus acciones quitadas.
 * Tras el arranque común se abre una transacción en su conexión, donde el caso siembra sus temporales y
 * la configuración del usuario; lo que la página guarda por esa conexión se deshace al terminar el
 * proceso. Antes de terminar, el proceso escribe los temporales del caso y la configuración del usuario.
 *
 * Los tickets cobrados los lee la clase de tickets, que abre su propia conexión, fuera de la
 * transacción: los que el caso necesita se graban de verdad antes de ejecutar la página y se borran al
 * terminar el caso, con su producto. Salvo que el caso diga lo contrario, el usuario tiene la impresión
 * de tickets desactivada, para que la página no compruebe ninguna impresora.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModTpv;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasTpvIntegracionTest extends CasoIntegracion
{
    protected bool $aislarPorTransaccion = false;

    private int $idUsuario;

    private int $idTienda;

    private int $idCliente;

    private int $producto;

    /** @var list<int> */
    private array $grabados = [];

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->idCliente = $siembra->clientePorDefecto();
        $this->db->query("INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (10.00, 'Pera de pantallas del tpv', 'Activo', NOW(), 1, 'unidad')");
        $this->producto = (int) $this->db->insert_id;
        $this->carpeta = sys_get_temp_dir() . '/tpv-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->grabados as $id) {
            $this->db->query('DELETE FROM ticketslinea WHERE idticketst = ' . $id);
            $this->db->query('DELETE FROM ticketstIva WHERE idticketst = ' . $id);
            $this->db->query('DELETE FROM ticketst WHERE id = ' . $id);
        }
        $this->db->query('DELETE FROM articulos WHERE idArticulo = ' . $this->producto);
        foreach (glob($this->carpeta . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        rmdir($this->carpeta);
        parent::tearDown();
    }

    /** @comportamiento El TPV abre un ticket nuevo, sin número, y deja abiertos los temporales que el usuario tenía como actuales. */
    public function test_elTpvAbreUnTicketNuevo(): void
    {
        $pantalla = $this->ejecutar('tpv.php', [], [$this->temporal(5, 'Actual')]);

        self::assertStringContainsString('cabecera[\'estadoTicket\'] = "Nuevo";', $pantalla['html']);
        self::assertStringContainsString("cabecera['numTicket'] = 0;", $pantalla['html']);
        self::assertSame([['5', 'Abierto']], array_map(static fn (array $t): array => [$t['numticket'], $t['estadoTicket']], $pantalla['temporales']));
    }

    /** @comportamiento Un temporal abierto se abre en el TPV con su número, su hora y sus líneas. */
    public function test_unTicketAbiertoSeAbreConSusLineas(): void
    {
        $pantalla = $this->ejecutar('tpv.php', ['tAbierto' => '5'], [$this->temporal(5, 'Abierto')]);

        self::assertStringContainsString("cabecera['numTicket'] = 5;", $pantalla['html']);
        self::assertStringContainsString('<span id="HoraInicio">09:00</span>', $pantalla['html']);
        self::assertStringContainsString('Pera de pantallas del tpv', $pantalla['html']);
    }

    /** @comportamiento Un temporal ya cobrado no se abre en el TPV: la página dice que desde ahí no se puede modificar y se detiene. */
    public function test_unTicketCobradoNoSeAbreEnElTpv(): void
    {
        $pantalla = $this->ejecutar('tpv.php', ['tAbierto' => '5'], [$this->temporal(5, 'Cobrado')]);

        self::assertStringContainsString('Desde aquí no puede modificarlo', $pantalla['html']);
        self::assertStringNotContainsString('id="tabla"', $pantalla['html']);
    }

    /** @comportamiento Si la impresora configurada no existe, el TPV lo avisa y deja la impresión desactivada para el usuario. */
    public function test_sinImpresoraElTpvLoAvisaYDejaDeImprimir(): void
    {
        $pantalla = $this->ejecutar('tpv.php', [], [], true, [], ['impresion_ticket' => 'Si', 'impresora_ticket' => $this->carpeta . '/no-existe']);

        self::assertStringContainsString('La impresora de tickets esta mal configurada, en la ruta:' . $this->carpeta . '/no-existe', $pantalla['html']);
        self::assertSame('No', json_decode($pantalla['configuraciones'][0]['configuracion'], true)['impresion_ticket']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/tpv.php:115-125,155
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El número de ticket de la dirección se pega en el JavaScript de la página: una
     *   dirección escrita a propósito ejecuta su código en el TPV de quien la abre.
     * @que-deberia-ocurrir Que el número se trate como un número.
     * @por-que-ocurre La página toma el número de la dirección y lo escribe tal como llega.
     * @como-deberia-funcionar Convertir el número a entero al leerlo.
     */
    public function test_elNumeroDeTicketDeLaDireccionSeTrataComoNumero(): void
    {
        $pantalla = $this->ejecutar('tpv.php', ['tActual' => '5;alert(1)//'], [$this->temporal(5, 'Abierto')]);

        self::assertStringNotContainsString("cabecera['numTicket'] = 5;alert(1)//;", $pantalla['html']);
    }

    /** @comportamiento Sin sesión, el TPV no se pinta. */
    public function test_sinSesionElTpvNoSePinta(): void
    {
        $pantalla = $this->ejecutar('tpv.php', [], [], false);

        self::assertStringNotContainsString("cabecera['estadoTicket']", $pantalla['html']);
    }

    /** @comportamiento El listado enseña los tickets cobrados, cada uno con su fecha, su serie y número, su cliente, su forma de pago y su total. */
    public function test_elListadoEnsenaLosTicketsCobrados(): void
    {
        $id = $this->grabado('Cobrado', date('Y-m-d') . ' 10:00:00', [['Pera', '3', '1.10']]);

        $pantalla = $this->ejecutar('ListaTickets.php', []);

        self::assertStringContainsString('value="' . $id . '"', $pantalla['html']);
        self::assertStringContainsString('<td>' . $this->idTienda . '-' . $this->idUsuario . '-9001</td><td>' . $this->idCliente . '</td><td>Cliente de pruebas</td><td>Cobrado</td><td>contado</td><td>3.30</td>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/ListaTickets.php:36-39,99-104
     *
     * @group defecto
     * @group presentacion
     * @group medio
     *
     * @que-ocurre-hoy Pedido el listado de los tickets cerrados, la página cuenta los cerrados pero
     *   enseña los cobrados de esas fechas.
     * @que-deberia-ocurrir Que enseñe los tickets del estado pedido.
     * @por-que-ocurre La consulta de la página tiene escrito el estado «Cobrado».
     * @como-deberia-funcionar Usar el estado pedido en las dos consultas.
     */
    public function test_elListadoDeTicketsCerradosEnsenaLosCerrados(): void
    {
        $cerrado = $this->grabado('Cerrado', '2031-03-03 10:00:00', [['Pera', '3', '1.10']]);

        $pantalla = $this->ejecutar('ListaTickets.php', ['estado' => 'Cerrado', 'fecha_inicio' => '2031-03-01', 'fecha_final' => '2031-03-31 23:59:59']);

        self::assertStringContainsString('value="' . $cerrado . '"', $pantalla['html']);
    }

    /** @comportamiento El listado ofrece cambiar la fecha de los tickets marcados solo con su permiso. */
    public function test_cambiarLaFechaSoloConSuPermiso(): void
    {
        $con = $this->ejecutar('ListaTickets.php', []);
        $sin = $this->ejecutar('ListaTickets.php', [], [], true, ['cambiarfechatickets']);

        self::assertStringContainsString("metodoClick('cambiarFechaItemsSeleccionado')", $con['html']);
        self::assertStringNotContainsString("metodoClick('cambiarFechaItemsSeleccionado')", $sin['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/ListaTickets.php:133-139; modulos/mod_tpv/ticketCobrado.php:108-110; modulos/mod_tpv/acces.xml
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Sin los permisos de ver e imprimir tickets, el listado ofrece ver e imprimir el
     *   marcado, y el ticket cobrado ofrece imprimirlo.
     * @que-deberia-ocurrir Que cada opción se ofrezca solo con su permiso.
     * @por-que-ocurre Las páginas solo consultan los permisos de cambiar fechas y de descontar el stock.
     * @como-deberia-funcionar Consultar el permiso de cada opción antes de pintarla.
     */
    public function test_sinPermisosNoSeOfreceVerNiImprimir(): void
    {
        $id = $this->grabado('Cobrado', date('Y-m-d') . ' 10:00:00', [['Pera', '3', '1.10']]);

        $listado = $this->ejecutar('ListaTickets.php', [], [], true, ['ver', 'imprimir']);
        $ticket = $this->ejecutar('ticketCobrado.php', ['id' => (string) $id], [], true, ['imprimir']);

        self::assertStringNotContainsString("metodoClick('VerTicket')", $listado['html']);
        self::assertStringNotContainsString("metodoClick('imprimirTicket')", $listado['html']);
        self::assertStringNotContainsString('imprimirTicketCerrado(' . $id . ')', $ticket['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/ListaTickets.php:36-39,125,128,159
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El estado pedido en la dirección se pinta en la página sin escapar y se pega a las
     *   consultas de los tickets.
     * @que-deberia-ocurrir Que el estado sea uno de los de los tickets, y se pinte como texto.
     * @por-que-ocurre La página toma el estado de la dirección tal como llega.
     * @como-deberia-funcionar Elegir el estado de una lista cerrada.
     */
    public function test_elEstadoDeLaDireccionSePintaComoTexto(): void
    {
        $pantalla = $this->ejecutar('ListaTickets.php', ['estado' => '<b>Cobrado</b>']);

        self::assertStringNotContainsString('Tickets <b>Cobrado</b>s', $pantalla['html']);
    }

    /**
     * @comportamiento Un ticket cobrado se enseña con su estado, número, empleado y cliente, sus líneas con
     *   unidades, precio, IVA e importe, el desglose, el total, la forma de pago marcada y lo entregado.
     */
    public function test_unTicketCobradoSeEnsenaConSusLineasYTotales(): void
    {
        $id = $this->grabado('Cobrado', '2026-10-24 10:00:00', [['Pera', '3', '1.10'], ['Uva', '0.5', '4.00', 'Eliminado']]);

        $pantalla = $this->ejecutar('ticketCobrado.php', ['id' => (string) $id]);

        self::assertStringContainsString('<h1 class="text-center"> Ticket Cobrado</h1>', $pantalla['html']);
        self::assertStringContainsString('<span id="NTicket">9001</span>', $pantalla['html']);
        self::assertStringContainsString('<span id="Usuario">pruebas</span>', $pantalla['html']);
        self::assertMatchesRegularExpression('#<td>Pera</td>\s*<td>3\.000</td>\s*<td>1\.10</td>\s*<td>10\.00</td>\s*<td>3\.30</td>#', $pantalla['html']);
        self::assertMatchesRegularExpression('#<tr class="tachado">\s*<td>2</td>#', $pantalla['html']);
        self::assertMatchesRegularExpression('#<td class="base">\s*3\.00\s*</td>#', $pantalla['html']);
        self::assertStringContainsString('<div style="font-size: 3em;">3.30</div>', $pantalla['html']);
        self::assertStringContainsString('<option value="contado" selected="selected">Contado</option>', $pantalla['html']);
        self::assertStringNotContainsString('Total no coincide', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tpv/ticketCobrado.php:166-173
     *
     * @group defecto
     * @group calculo
     * @group medio
     *
     * @que-ocurre-hoy Una línea de mil euros o más se enseña con un importe de 1 €, y la página avisa de
     *   que el total no coincide.
     * @que-deberia-ocurrir Que la línea se enseñe con su importe.
     * @por-que-ocurre El importe se calcula multiplicando las unidades y el precio ya formateados, con la
     *   coma de los miles, que corta el número.
     * @como-deberia-funcionar Calcular con los números y formatear solo al pintar.
     */
    public function test_unaLineaDeMilEurosOMasSeEnsenaConSuImporte(): void
    {
        $id = $this->grabado('Cobrado', '2026-10-24 10:00:00', [['Jamon', '1', '1250.00']]);

        $pantalla = $this->ejecutar('ticketCobrado.php', ['id' => (string) $id]);

        self::assertStringNotContainsString('Total no coincide', $pantalla['html']);
        self::assertMatchesRegularExpression('#<td>1,250\.00</td>\s*<td>10\.00</td>\s*<td>1,250\.00</td>#', $pantalla['html']);
    }

    /** @comportamiento Un ticket que no existe no se enseña: la página dice que no encontró ninguno y se detiene. */
    public function test_unTicketQueNoExisteLoDice(): void
    {
        $pantalla = $this->ejecutar('ticketCobrado.php', ['id' => '0']);

        self::assertStringContainsString('<div class="alert alert-danger">Error 0 registros encontrado para el idticketst 0</div>', $pantalla['html']);
        self::assertStringNotContainsString('id="tabla"', $pantalla['html']);
    }

    /**
     * Graba de verdad un ticket del estado y la fecha dados, con sus líneas —nombre, unidades, precio y,
     * si se da, su estado— al 10 % y su desglose, y lo apunta para borrarlo al terminar.
     *
     * @param list<list<string>> $lineas
     */
    private function grabado(string $estado, string $fecha, array $lineas): int
    {
        $total = 0.0;
        foreach ($lineas as $linea) {
            if (($linea[3] ?? 'Activo') === 'Activo') {
                $total += (float) $linea[1] * (float) $linea[2];
            }
        }
        $this->db->query("INSERT INTO ticketst (Numticket, Numtempticket, Fecha, idTienda, idUsuario, idCliente, estado, formaPago, entregado, total) VALUES (9001, 1, '{$fecha}', {$this->idTienda}, {$this->idUsuario}, {$this->idCliente}, '{$estado}', 'contado', {$total}, {$total})");
        $id = (int) $this->db->insert_id;
        $this->grabados[] = $id;
        foreach ($lineas as $fila => $linea) {
            $this->db->query("INSERT INTO ticketslinea (idticketst, Numticket, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, precioCiva, iva, nfila, estadoLinea) VALUES ({$id}, 9001, {$this->producto}, '1001', '', '{$linea[0]}', {$linea[1]}, {$linea[1]}, {$linea[2]}, 10, " . ($fila + 1) . ", '" . ($linea[3] ?? 'Activo') . "')");
        }
        $base = round($total / 1.1, 2);
        $this->db->query("INSERT INTO ticketstIva (idticketst, Numticket, iva, importeIva, totalbase) VALUES ({$id}, 9001, 10, " . round($total - $base, 2) . ", {$base})");

        return $id;
    }

    private function temporal(int $numero, string $estado): string
    {
        $linea = ['id' => (string) $this->producto, 'cref' => '1001', 'cdetalle' => 'Pera de pantallas del tpv', 'pvpconiva' => '1.10', 'ccodebar' => '', 'ctipoiva' => '10.00', 'unidad' => 3, 'estado' => 'Activo', 'tipo' => 'unidad', 'nfila' => 1];

        return "INSERT INTO ticketstemporales (numticket, estadoTicket, idTienda, idUsuario, fechaInicio, idClientes, total, Productos) VALUES ({$numero}, '{$estado}', :tienda, :usuario, '2026-10-24 09:00:00', :cliente, 3.30, '" . addslashes((string) json_encode([(string) json_encode($linea)])) . "')";
    }

    /**
     * Ejecuta una pantalla en un PHP aparte y devuelve su HTML, sus avisos, los temporales y la
     * configuración del usuario.
     *
     * @param array<string, string> $consulta
     * @param list<string> $preparacion
     * @param list<string> $sinPermiso Las acciones del módulo que se quitan.
     * @param array<string, string> $configuracion La configuración del TPV del usuario.
     * @return array{html: string, errores: string, temporales: list<array<string, ?string>>, configuraciones: list<array<string, ?string>>}
     */
    private function ejecutar(string $fichero, array $consulta, array $preparacion = [], bool $conSesion = true, array $sinPermiso = [], array $configuracion = ['impresion_ticket' => 'No']): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas', 'nif' => 'B00000000', 'direccion' => 'Calle de pruebas 1', 'telefono' => '986000000', 'ano' => '2026'],
        ] : [];
        array_unshift($preparacion, "INSERT INTO modulos_configuracion (idusuario, nombre_modulo, configuracion, fecha) VALUES (:usuario, 'mod_tpv', '" . json_encode($configuracion, JSON_UNESCAPED_SLASHES) . "', NOW())");
        $quitar = $sinPermiso === [] ? 'FALSE' : 'accion IN (' . implode(', ', array_map(static fn (string $a): string => "'{$a}'", $sinPermiso)) . ')';
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["DOCUMENT_ROOT"] = "";'
            . ' $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_tpv/{$fichero}", true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_tpv', true) . ');'
            . ' require ' . var_export(RUTA_TPVFOX . '/inicial.php', true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = IF(' . $quitar . ', 0, 1) WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_tpv\'");'
            . ' $BDTpv->query("DELETE FROM modulos_configuracion WHERE idusuario = ' . $this->idUsuario . ' AND nombre_modulo = \'mod_tpv\'");'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '", ":cliente" => "' . $this->idCliente . '"];'
            . ' $antes = (int) $BDTpv->query("SELECT COALESCE(MAX(id), 0) FROM ticketstemporales")->fetch_row()[0];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids)); }'
            . ' $_GET = ' . var_export($consulta, true) . ';'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use ($BDTpv, $antes) { echo "\n@@DATOS@@" . json_encode(['
            . ' "temporales" => $BDTpv->query("SELECT * FROM ticketstemporales WHERE id > " . $antes . " ORDER BY id")->fetch_all(MYSQLI_ASSOC),'
            . ' "configuraciones" => $BDTpv->query("SELECT * FROM modulos_configuracion WHERE idusuario = ' . $this->idUsuario . ' AND nombre_modulo = \'mod_tpv\'")->fetch_all(MYSQLI_ASSOC)], JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'tpv');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $pintada = ['html' => $html, 'errores' => (string) file_get_contents($errores)] + ((json_decode($datos, true) ?? []) + ['temporales' => [], 'configuraciones' => []]);
        unlink($errores);

        return $pintada;
    }
}

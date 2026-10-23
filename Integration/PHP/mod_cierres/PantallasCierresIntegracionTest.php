<?php

/**
 * Las pantallas de los cierres —la del cierre (`CierreCaja.php`), el listado (`ListaCierres.php`), la
 * vista de un cierre (`VistaCierre.php`) y el resumen entre fechas (`ResumenFechas.php`)— y la petición de
 * fondo (`tareas.php`), que guarda el cierre, busca clientes y borra el último cierre.
 *
 * Cada una se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de pruebas
 * o sin ninguna, y una transacción del proceso que no se confirma. El caso siembra con sentencias dentro
 * de esa transacción y guarda en variables de la base (`@nombre`) los números que necesita; en la
 * dirección y en lo enviado, `{@nombre}` se cambia por su valor. Al terminar, el proceso escribe lo que
 * responden las consultas del caso. El borrado por la petición de fondo no se ejecuta aquí: la clase abre
 * su propia conexión, que no ve la transacción del proceso, y se verifica en `BorrarCierreIntegracionTest`.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCierres;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasCierresIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;
    private int $idTienda;
    private int $idCliente;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->idCliente = $siembra->clientePorDefecto();
    }

    /**
     * Ejecuta un fichero del módulo en un PHP aparte y devuelve su HTML, su stderr y lo que responden las
     * consultas del caso. Sin `$grupo`, sin sesión.
     *
     * Con `$retirar`, la siembra se confirma en lugar de quedar en la transacción, y esas sentencias la
     * retiran al terminar: lo necesitan las pantallas que leen con `ClaseCierres`, que abre su propia
     * conexión y no ve lo que no está confirmado.
     */
    private function ejecutar(string $fichero, array $get = [], array $post = [], array $preparacion = [], array $consultas = [], ?string $grupo = '1', array $retirar = []): array
    {
        $sesion = $grupo !== null ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => $grupo, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = ' . var_export($post !== [] ? 'POST' : 'GET', true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_cierres/{$fichero}", true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_cierres', true) . ');'
            . ' require "./../../inicial.php";'
            . ($retirar === [] ? ' $BDTpv->begin_transaction();' : '')
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query($sql); }'
            . ' $texto = ' . var_export(json_encode(['get' => $get, 'post' => $post]), true) . '; $valores = [];'
            . ' preg_match_all("/\\\\{@(\\\\w+)\\\\}/", $texto, $m); foreach (array_unique($m[1]) as $v) { $valores["{@$v}"] = (string) $BDTpv->query("SELECT @$v")->fetch_row()[0]; }'
            . ' $datos = json_decode(strtr($texto, $valores), true); $_GET = $datos["get"]; $_POST = $datos["post"];'
            . ' register_shutdown_function(function () use ($BDTpv) { $r = [];'
            . ' foreach (' . var_export($consultas, true) . ' as $n => $sql) { $fila = $BDTpv->query($sql)->fetch_row(); $r[$n] = $fila === null || $fila[0] === null ? null : (string) $fila[0]; }'
            . ' foreach (' . var_export($retirar, true) . ' as $sql) { $BDTpv->query($sql); }'
            . ' echo "\n@@ESTADO@@" . json_encode(["consultas" => $r]); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'cierres');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $estado] = array_pad(explode('@@ESTADO@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['consultas' => []];
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $pintada;
    }

    /** Un usuario más, guardado en `@$variable`. */
    private function usuario(string $variable, string $username, string $nombre): array
    {
        return [
            "INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$username', 'sin-uso', '2020-01-01', 1, 'Activo', '$nombre')",
            "SET @$variable = LAST_INSERT_ID()",
        ];
    }

    /**
     * Un ticket con su desglose de IVA, guardado en `@$variable`; `$usuario` es un número o una variable.
     *
     * @param array<int,float> $bases
     */
    private function ticket(string $variable, string $usuario, int $numero, string $fecha, string $formaPago, array $bases, string $estado = 'Cobrado'): array
    {
        $total = 0.0;
        foreach ($bases as $iva => $base) {
            $total += $base + round($base * $iva / 100, 2);
        }
        $sentencias = [
            "INSERT INTO ticketst (Numticket, Numtempticket, Fecha, idTienda, idUsuario, idCliente, estado, formaPago, entregado, total) "
                . "VALUES ($numero, $numero, '$fecha', {$this->idTienda}, $usuario, {$this->idCliente}, '$estado', '$formaPago', 0, " . number_format($total, 2, '.', '') . ')',
            "SET @$variable = LAST_INSERT_ID()",
        ];
        foreach ($bases as $iva => $base) {
            $sentencias[] = "INSERT INTO ticketstIva (idticketst, Numticket, iva, importeIva, totalbase) VALUES (@$variable, $numero, $iva, "
                . number_format(round($base * $iva / 100, 2), 2, '.', '') . ', ' . number_format($base, 2, '.', '') . ')';
        }

        return $sentencias;
    }

    /** Un día con dos tickets cobrados: uno del usuario de pruebas en efectivo y otro del cajero con tarjeta. */
    private function diaConTickets(): array
    {
        return array_merge(
            $this->usuario('dos', 'cierre_dos', 'Cajero Dos'),
            $this->ticket('t1', (string) $this->idUsuario, 9101, '2031-05-10 11:00:00', 'Efectivo', [21 => 10.00]),
            $this->ticket('t2', '@dos', 9201, '2031-05-10 12:00:00', 'Tarjeta', [10 => 10.00]),
        );
    }

    /**
     * Un cierre guardado, en `@$variable`, hecho por el usuario de pruebas, con sus IVA, sus formas de pago
     * y su rango de tickets.
     *
     * @param array<int,array{0:float,1:float}> $ivas tipo => [base, cuota]
     */
    private function cierre(string $variable, string $dia, string $inicio, float $total, array $ivas): array
    {
        $sentencias = [
            "INSERT INTO cierres (FechaCierre, idTienda, idUsuario, FechaInicio, FechaFinal, FechaCreacion, Total) "
                . "VALUES ('$dia', {$this->idTienda}, {$this->idUsuario}, '$inicio', '$dia 20:00:00', '$dia 21:00:00', $total)",
            "SET @$variable = LAST_INSERT_ID()",
            "INSERT INTO cierres_usuariosFormasPago (idCierre, idTienda, idUsuario, FormasPago, importe) VALUES (@$variable, {$this->idTienda}, {$this->idUsuario}, 'Efectivo', $total)",
            "INSERT INTO cierres_usuarios_tickets (idCierre, idUsuario, idTienda, Importe, Num_ticket_inicial, Num_ticket_final) VALUES (@$variable, {$this->idUsuario}, {$this->idTienda}, $total, 9101, 9102)",
        ];
        foreach ($ivas as $tipo => [$base, $cuota]) {
            $sentencias[] = "INSERT INTO cierres_ivas (idCierre, idTienda, tipo_iva, importe_base, importe_iva) VALUES (@$variable, {$this->idTienda}, $tipo, $base, $cuota)";
        }

        return $sentencias;
    }

    /** Las sentencias que retiran los cierres guardados en esas variables. */
    private function retirarCierres(string ...$variables): array
    {
        $sentencias = [];
        foreach ($variables as $variable) {
            foreach (['cierres_usuarios_tickets', 'cierres_usuariosFormasPago', 'cierres_ivas', 'cierres'] as $tabla) {
                $sentencias[] = "DELETE FROM $tabla WHERE idCierre = @$variable";
            }
        }

        return $sentencias;
    }

    /** Lo que la pantalla del cierre deja en la página para guardarlo. */
    private function ccierre(string $html): ?array
    {
        return preg_match('/var Ccierre=(.*);/', $html, $m) === 1 ? json_decode($m[1], true) : null;
    }

    /** Lo que la pantalla del cierre enviaría al guardar el día de `diaConTickets()`, solo con el ticket del usuario de pruebas. */
    private function datosDelCierre(): array
    {
        $usuario = (string) $this->idUsuario;

        return [
            'tienda' => (string) $this->idTienda, 'idUsuarioLogin' => $usuario,
            'sumasIvas' => [['importeIva' => '2.10', 'importeBase' => '10.00', 'iva' => '21']],
            'totalcaja' => '12.10', 'rangoTickets' => ['{@t1}'],
            'fechaCierre' => '10-05-2031', 'fechaCreacion' => '10-05-2031 21:00:00',
            'FI_unix' => (string) gmmktime(9, 0, 0, 5, 10, 2031), 'FF_unix' => (string) gmmktime(9, 0, 0, 5, 10, 2031),
            'fechaInicio_tickets' => '10-05-2031 09:00:00', 'fechaFinal_tickets' => '10-05-2031 09:00:00',
            'FinicioSINhora' => '10-05-2031', 'FfinalSINhora' => '10-05-2031',
            'usuarios' => [$usuario => ['nombre' => 'Usuario de pruebas', 'NumInicial' => '9101', 'NumFinal' => '9101', 'subtotal' => '12.10']],
            'modoPago' => [$usuario => ['formasPago' => ['Efectivo' => '12.10']]],
        ];
    }

    private function sinAvisos(array $pantalla): void
    {
        self::assertStringNotContainsString('Warning', $pantalla['errores']);
        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
    }

    /**
     * @comportamiento Con los tickets cobrados de un día, la pantalla del cierre enseña a cada usuario con su
     *   total y sus formas de pago, el desglose de IVA, y deja en la página el cierre que se guardará —la
     *   tienda y el usuario de la sesión, el total y los tickets—; «Aceptar» queda activo.
     */
    public function test_laPantallaDelCierreEnsenaElDiaYDejaElCierreEnLaPagina(): void
    {
        $pantalla = $this->ejecutar('CierreCaja.php', ['dedonde' => 'tpv'], [], $this->diaConTickets());

        $this->sinAvisos($pantalla);
        self::assertStringContainsString('<td>Usuario de pruebas</td>', $pantalla['html']);
        self::assertStringContainsString('<td>Cajero Dos</td>', $pantalla['html']);
        self::assertStringContainsString('<tr><td><b>Total:</b></td><td><b>23.10</b></td></tr>', $pantalla['html']);
        self::assertStringContainsString('type="button"  onclick="guardarCierreCaja()"', $pantalla['html']);
        $cierre = $this->ccierre($pantalla['html']);
        self::assertSame([(string) $this->idTienda, (string) $this->idUsuario], [$cierre['tienda'], $cierre['idUsuarioLogin']]);
        self::assertEqualsWithDelta(23.10, $cierre['totalcaja'], 0.001);
        self::assertCount(2, $cierre['rangoTickets']);
        self::assertEqualsCanonicalizing(['21', '10'], array_column($cierre['sumasIvas'], 'iva'));
    }

    /** @comportamiento Con un ticket abierto, la pantalla lo avisa, lo enseña por usuario y no deja aceptar ni recalcular. */
    public function test_conUnTicketAbiertoNoSeDejaCerrar(): void
    {
        $preparacion = array_merge($this->diaConTickets(), [
            "INSERT INTO ticketstemporales (numticket, estadoTicket, idTienda, idUsuario, fechaInicio, idClientes, total) VALUES (1, 'Abierto', {$this->idTienda}, {$this->idUsuario}, '2031-05-10 12:30:00', {$this->idCliente}, 0)",
        ]);

        $pantalla = $this->ejecutar('CierreCaja.php', ['dedonde' => 'tpv'], [], $preparacion);

        self::assertStringContainsString('<strong>Hay tickets abiertos.</strong>', $pantalla['html']);
        self::assertStringContainsString('<h2>Tickets Abiertos</h2>', $pantalla['html']);
        self::assertStringContainsString('type="button" disabled onclick="guardarCierreCaja()"', $pantalla['html']);
        self::assertStringContainsString('name="Recalcular" disabled', $pantalla['html']);
    }

    /** @comportamiento Con tickets cobrados de varios días, la pantalla lo avisa y no deja aceptar hasta recalcular. */
    public function test_conTicketsDeVariosDiasNoSeDejaAceptar(): void
    {
        $preparacion = array_merge($this->diaConTickets(), $this->ticket('t3', (string) $this->idUsuario, 9102, '2031-05-11 12:00:00', 'Efectivo', [21 => 20.00]));

        $pantalla = $this->ejecutar('CierreCaja.php', ['dedonde' => 'tpv'], [], $preparacion);

        self::assertStringContainsString('<strong>Varios dias sin cerrar caja.</strong>', $pantalla['html']);
        self::assertStringContainsString('type="button" disabled onclick="guardarCierreCaja()"', $pantalla['html']);
        self::assertCount(3, $this->ccierre($pantalla['html'])['rangoTickets']);
    }

    /**
     * @comportamiento Recalcular con una fecha final cierra hasta ese día: el cierre de la página lleva solo
     *   los tickets cobrados hasta entonces, y «Aceptar» queda activo.
     */
    public function test_recalcularHastaUnDiaDejaSoloSusTickets(): void
    {
        $preparacion = array_merge($this->diaConTickets(), $this->ticket('t3', (string) $this->idUsuario, 9102, '2031-05-11 12:00:00', 'Efectivo', [21 => 20.00]));

        $pantalla = $this->ejecutar('CierreCaja.php', ['dedonde' => 'tpv'], ['fecha' => '10-05-2031', 'fechaFinal' => '10-05-2031', 'Recalcular' => 'Recalcular'], $preparacion);

        $cierre = $this->ccierre($pantalla['html']);
        self::assertEqualsWithDelta(23.10, $cierre['totalcaja'], 0.001);
        self::assertCount(2, $cierre['rangoTickets']);
        self::assertSame('10-05-2031', $cierre['fechaCierre']);
        self::assertStringContainsString('type="button"  onclick="guardarCierreCaja()"', $pantalla['html']);
    }

    /** @comportamiento Sin sesión, la cabecera común pide entrar: la pantalla del cierre no se pinta. */
    public function test_sinSesionLaPantallaDelCierreNoSeSirve(): void
    {
        $pantalla = $this->ejecutar('CierreCaja.php', ['dedonde' => 'tpv'], [], $this->diaConTickets(), [], null);

        self::assertNull($this->ccierre($pantalla['html']));
        self::assertStringNotContainsString('Cierre por Usuarios', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/CierreCaja.php:23,44-47,60,87-91,119
     * @codigo-afectado modulos/mod_cierres/funciones.php:384-391
     *
     * @group defecto
     * @group cierres
     * @group medio
     *
     * @que-ocurre-hoy Sin tickets cobrados que cerrar, la pantalla del cierre no avisa: termina con un error
     *   de PHP antes de pintarse.
     * @que-deberia-ocurrir Que diga «No hay tickets para cerrar» y no deje aceptar.
     * @por-que-ocurre Sin tickets, la fecha del primero y del último llega nula; `strftime()` con una fecha
     *   nula da la de hoy, no la de 1970 que espera el aviso, y la pantalla recorre una lista de usuarios que
     *   no existe y monta la de tickets con `implode()` sobre un nulo, que en PHP 8 es un error de tipo.
     * @como-deberia-funcionar Comprobar que hay tickets cobrados antes de calcular nada, y avisar si no.
     */
    public function test_sinTicketsCobradosLaPantallaLoAvisa(): void
    {
        $pantalla = $this->ejecutar('CierreCaja.php', ['dedonde' => 'tpv']);

        self::assertStringContainsString('No hay tickets para cerrar.', $pantalla['html']);
        $this->sinAvisos($pantalla);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/CierreCaja.php:76,237
     * @codigo-afectado modulos/mod_cierres/VistaCierre.php:10,36
     * @codigo-afectado modulos/mod_cierres/ListaCierres.php:30
     *
     * @group defecto
     * @group cierres
     * @group medio
     *
     * @que-ocurre-hoy Las pantallas escriben tal cual lo que llega en la dirección: el origen en la del
     *   cierre, el número en la vista y las fechas en el enlace del resumen del listado. Unas comillas cierran
     *   el atributo, y una etiqueta se pinta como HTML de la página.
     * @que-deberia-ocurrir Que lo que llega en la dirección se escriba como texto.
     * @por-que-ocurre Se escribe con `echo` sin escapar.
     * @como-deberia-funcionar Escapar con `htmlspecialchars()` todo lo que llega de la petición, y comprobar
     *   que el origen es uno de los conocidos.
     */
    public function test_loQueLlegaEnLaDireccionSeEscribeComoTexto(): void
    {
        $pantalla = $this->ejecutar('CierreCaja.php', ['dedonde' => 'tpv"x'], [], $this->diaConTickets());

        self::assertStringContainsString('action="./CierreCaja.php?dedonde=tpv&quot;x"', $pantalla['html']);
    }

    /**
     * @comportamiento La petición de fondo guarda el cierre con los datos que le envía la pantalla: lo crea,
     *   pasa su ticket a «Cerrado» y responde cada paso.
     */
    public function test_laPeticionDeFondoGuardaElCierre(): void
    {
        $pantalla = $this->ejecutar('tareas.php', [], ['pulsado' => 'insertarCierre', 'datos_cierre' => $this->datosDelCierre()], $this->diaConTickets(), [
            'cierres' => "SELECT COUNT(*) FROM cierres WHERE FechaCierre = '2031-05-10'",
            'ticket' => 'SELECT estado FROM ticketst WHERE id = @t1',
            'otro' => 'SELECT estado FROM ticketst WHERE id = @t2',
        ]);

        $respuesta = json_decode(explode("\n", $pantalla['html'])[0], true);
        self::assertSame('Correcto', $respuesta['Proceso1']['insertarCierre']);
        self::assertSame('Correcto', $respuesta['Proceso3']['insertarTickets_usuarios']);
        self::assertSame(['cierres' => '1', 'ticket' => 'Cerrado', 'otro' => 'Cobrado'], $pantalla['consultas']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/tareas.php:10-57
     *
     * @group defecto
     * @group cierres
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, la petición de fondo guarda un cierre —crea sus filas y pasa sus tickets a
     *   «Cerrado»—, busca clientes y devuelve su nombre, su razón social y su NIF, y borra el último cierre.
     * @que-deberia-ocurrir Que sin sesión, y sin el permiso de la acción, no atienda ninguna orden.
     * @por-que-ocurre `tareas.php` no comprueba la sesión ni el permiso: el arranque común deja seguir sin
     *   sesión, solo la cabecera de las pantallas corta el paso, y el borrado solo lo esconde el listado a
     *   quien no es administrador.
     * @como-deberia-funcionar Comprobar la sesión y el permiso de cada orden antes de atenderla.
     */
    public function test_sinSesionLaPeticionDeFondoNoGuardaUnCierre(): void
    {
        $pantalla = $this->ejecutar('tareas.php', [], ['pulsado' => 'insertarCierre', 'datos_cierre' => $this->datosDelCierre()], $this->diaConTickets(), [
            'cierres' => "SELECT COUNT(*) FROM cierres WHERE FechaCierre = '2031-05-10'",
            'ticket' => 'SELECT estado FROM ticketst WHERE id = @t1',
        ], null);

        self::assertSame(['cierres' => '0', 'ticket' => 'Cobrado'], $pantalla['consultas']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/tareas.php:33-48
     *
     * @group defecto
     * @group cierres
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, la búsqueda de clientes de la petición de fondo devuelve los clientes que
     *   encuentra, con su nombre, su razón social y su NIF.
     * @que-deberia-ocurrir Que sin sesión no devuelva ningún dato.
     * @por-que-ocurre La misma que la de guardar un cierre sin sesión: `tareas.php` no comprueba la sesión.
     * @como-deberia-funcionar Comprobar la sesión antes de atender la orden.
     */
    public function test_sinSesionLaBusquedaDeClientesNoDevuelveDatos(): void
    {
        $pantalla = $this->ejecutar('tareas.php', [], ['pulsado' => 'buscarClientes', 'busqueda' => 'Cliente de pruebas'], [], [], null);

        self::assertSame(0, json_decode(explode("\n", $pantalla['html'])[0], true)['encontrados'] ?? 0);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/tareas.php:45
     *
     * @group defecto
     * @group cierres
     * @group bajo
     *
     * @que-ocurre-hoy La búsqueda de clientes de la petición de fondo deja un aviso de PHP: pasa a la
     *   función que pinta la lista una variable de origen que no existe.
     * @que-deberia-ocurrir Que la búsqueda responda sin avisos, con el origen que envía la pantalla.
     * @por-que-ocurre La rama no lee `dedonde` de lo enviado, aunque el JavaScript lo envía.
     * @como-deberia-funcionar Leer `$_POST['dedonde']`, o retirar la orden si ninguna pantalla del módulo la usa.
     */
    public function test_laBusquedaDeClientesRespondeSinAvisos(): void
    {
        $pantalla = $this->ejecutar('tareas.php', [], ['pulsado' => 'buscarClientes', 'busqueda' => 'Cliente de pruebas', 'dedonde' => 'cerrados']);

        self::assertGreaterThan(0, json_decode(explode("\n", $pantalla['html'])[0], true)['encontrados']);
        $this->sinAvisos($pantalla);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/tareas.php:10,17-57
     *
     * @group defecto
     * @group cierres
     * @group bajo
     *
     * @que-ocurre-hoy Una petición de fondo sin orden deja un aviso de PHP y no responde nada.
     * @que-deberia-ocurrir Que responda un error que diga que la orden no es conocida.
     * @por-que-ocurre Toma `pulsado` sin comprobar que viene, y el `switch` no tiene rama para lo que no conoce.
     * @como-deberia-funcionar Comprobar la orden y responder un error si falta o no es conocida.
     */
    public function test_unaPeticionDeFondoSinOrdenRespondeUnError(): void
    {
        $pantalla = $this->ejecutar('tareas.php', [], ['otra' => 'cosa']);

        $this->sinAvisos($pantalla);
        self::assertNotSame('', $pantalla['html']);
    }

    /**
     * @comportamiento El listado enseña cada cierre con quien lo hizo, su intervalo y su total; el enlace del
     *   resumen solo aparece con las dos fechas de la búsqueda.
     */
    public function test_elListadoEnsenaLosCierres(): void
    {
        $preparacion = $this->cierre('c1', '2031-05-10', '2031-05-10 09:15:30', 52.80, [21 => [30.00, 6.30], 10 => [15.00, 1.50]]);

        $pantalla = $this->ejecutar('ListaCierres.php', [], [], $preparacion, [], '1', $this->retirarCierres('c1'));
        $conFechas = $this->ejecutar('ListaCierres.php', ['fecha1' => '2031-05-10', 'fecha2' => '2031-05-10'], [], $preparacion, [], '1', $this->retirarCierres('c1'));

        $this->sinAvisos($pantalla);
        self::assertStringContainsString('<td>Usuario de pruebas</td>', $pantalla['html']);
        self::assertStringContainsString('<td>52.80</td>', $pantalla['html']);
        self::assertStringContainsString('-Cierres encontrados BD local filtrados:', $pantalla['html']);
        self::assertStringNotContainsString('ResumenFechas.php?', $pantalla['html']);
        self::assertStringContainsString('<a href="ResumenFechas.php?fecha1=2031-05-10&fecha2=2031-05-10">Ver Resumen</a>', $conFechas['html']);
        self::assertStringContainsString('<td>52.80</td>', $conFechas['html']);
    }

    /** @comportamiento La opción de eliminar un cierre solo la ofrece el listado a quien está en el grupo de administración. */
    public function test_eliminarUnCierreSoloSeOfreceAlAdministrador(): void
    {
        self::assertStringNotContainsString("metodoClick('EliminarCierre')", $this->ejecutar('ListaCierres.php')['html']);
        self::assertStringContainsString("metodoClick('EliminarCierre')", $this->ejecutar('ListaCierres.php', [], [], [], [], '9')['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/ListaCierres.php:15,147
     *
     * @group defecto
     * @group cierres
     * @group bajo
     *
     * @que-ocurre-hoy El listado enseña en el intervalo de cada cierre el mes donde van los minutos: un cierre
     *   que empieza a las 09:15:30 de mayo sale a las 09:05:30.
     * @que-deberia-ocurrir Que la hora se enseñe con sus minutos.
     * @por-que-ocurre El formato es `d-m-Y H:m:s`, y en `date()` la `m` es el mes; los minutos son `i`.
     * @como-deberia-funcionar Usar `d-m-Y H:i:s`.
     */
    public function test_elListadoEnsenaLaHoraConSusMinutos(): void
    {
        $pantalla = $this->ejecutar('ListaCierres.php', [], [], $this->cierre('c1', '2031-05-10', '2031-05-10 09:15:30', 52.80, [21 => [43.64, 9.16]]), [], '1', $this->retirarCierres('c1'));

        self::assertStringContainsString('<td>Usuario de pruebas</td>', $pantalla['html']);
        self::assertStringContainsString('<small>10-05-2031 09:15:30</small>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/ListaCierres.php:26-34
     * @codigo-afectado modulos/mod_cierres/clases/ClaseCierres.php:32-38
     * @codigo-afectado modulos/mod_cierres/funciones.php:302-326
     * @codigo-afectado modulos/mod_cierres/ResumenFechas.php:9-13,29,88,121
     *
     * @group defecto
     * @group cierres
     * @group medio
     *
     * @que-ocurre-hoy Las fechas de la búsqueda del listado y del resumen, y el número de cierre de la vista,
     *   se pegan en el texto de la consulta: unas comillas en la fecha la rompen, y el listado termina con un
     *   error de la base.
     * @que-deberia-ocurrir Que lo que llega en la dirección sea siempre un dato de la consulta: una fecha que
     *   no lo es no encuentra nada, o se avisa.
     * @por-que-ocurre El listado compone el filtro con las fechas entre comillas dobles, sin comprobarlas ni
     *   escaparlas, y la clase lo añade tal cual; la vista y el resumen hacen lo mismo con el número y las
     *   fechas.
     * @como-deberia-funcionar Comprobar que la fecha es una fecha y el número un número, y pasarlos como
     *   parámetros de una sentencia preparada.
     */
    public function test_unaFechaConComillasNoRompeElListado(): void
    {
        $pantalla = $this->ejecutar('ListaCierres.php', ['fecha1' => '2031-05-10"', 'fecha2' => '2031-05-10'], [], $this->cierre('c1', '2031-05-10', '2031-05-10 09:15:30', 52.80, [21 => [43.64, 9.16]]));

        $this->sinAvisos($pantalla);
        self::assertStringContainsString('</table>', $pantalla['html']);
    }

    /**
     * @comportamiento La vista de un cierre enseña su cabecera, sus usuarios con su primer y su último ticket
     *   y el enlace a sus tickets en el TPV, lo de cada usuario por forma de pago con los totales, y las bases
     *   y cuotas por tipo de IVA con su suma.
     */
    public function test_laVistaEnsenaUnCierre(): void
    {
        $pantalla = $this->ejecutar('VistaCierre.php', ['id' => '{@c1}'], [], $this->cierre('c1', '2031-05-10', '2031-05-10 09:15:30', 52.80, [21 => [30.00, 6.30], 10 => [15.00, 1.50]]));

        $this->sinAvisos($pantalla);
        self::assertMatchesRegularExpression('/<h1> Cierre de caja \d+<\/h1>/', $pantalla['html']);
        self::assertStringContainsString('<td>9101</td>', $pantalla['html']);
        self::assertStringContainsString('/modulos/mod_tpv/ListaTickets.php?estado=Cerrado&idUsuario=' . $this->idUsuario . '&idCierre=', $pantalla['html']);
        self::assertStringContainsString('<td>21 %:</td>', $pantalla['html']);
        self::assertStringContainsString('<td>45.00</td>', $pantalla['html']);
        self::assertStringContainsString('<td><b>52.80</b></td>', $pantalla['html']);
        self::assertStringContainsString('<tr><td><b>Total:</b></td><td><b>52,80</b></td></tr>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/VistaCierre.php:9-12
     * @codigo-afectado modulos/mod_cierres/funciones.php:302-326
     *
     * @group defecto
     * @group cierres
     * @group medio
     *
     * @que-ocurre-hoy Un número de cierre con unas comillas en la dirección rompe la consulta de la vista, que
     *   termina con un error de la base.
     * @que-deberia-ocurrir Que un número que no lo es no encuentre ningún cierre, o se avise.
     * @por-que-ocurre La misma que la de la fecha del listado: el número se pega en el texto de la consulta.
     * @como-deberia-funcionar Comprobar que es un número y pasarlo como parámetro de una sentencia preparada.
     */
    public function test_unNumeroDeCierreConComillasNoRompeLaVista(): void
    {
        $pantalla = $this->ejecutar('VistaCierre.php', ['id' => '{@c1}"'], [], $this->cierre('c1', '2031-05-10', '2031-05-10 09:15:30', 52.80, [21 => [43.64, 9.16]]));

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
    }

    /**
     * @comportamiento El resumen entre dos fechas enseña cuántos cierres hay y su total, la base y la cuota de
     *   cada tipo de IVA de la tabla de IVA, lo de cada usuario y lo de cada forma de pago.
     */
    public function test_elResumenEntreFechasSumaLosCierres(): void
    {
        $preparacion = array_merge(
            $this->cierre('c1', '2031-05-10', '2031-05-10 09:00:00', 12.10, [21 => [10.00, 2.10]]),
            $this->cierre('c2', '2031-05-11', '2031-05-11 09:00:00', 24.20, [21 => [20.00, 4.20]]),
        );

        $pantalla = $this->ejecutar('ResumenFechas.php', ['fecha1' => '2031-05-10', 'fecha2' => '2031-05-11'], [], $preparacion, [], '1', $this->retirarCierres('c1', 'c2'));

        $this->sinAvisos($pantalla);
        self::assertStringContainsString('<td>2</td>', $pantalla['html']);
        self::assertStringContainsString('<td>36.3</td>', $pantalla['html']);
        self::assertStringContainsString('<td>21.00%</td>', $pantalla['html']);
        self::assertStringContainsString('<td>30.00</td>', $pantalla['html']);
        self::assertStringContainsString('<td>6.30</td>', $pantalla['html']);
        self::assertStringContainsString('<td>Usuario de pruebas</td>', $pantalla['html']);
        self::assertStringContainsString('<td>36.30</td>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/ResumenFechas.php:27,86-105
     *
     * @group defecto
     * @group cierres
     * @group medio
     *
     * @que-ocurre-hoy El resumen de IVA entre fechas solo enseña los tipos que hoy están en la tabla de IVA:
     *   un cierre con un tipo que se retiró —o que nunca se dio de alta— desaparece del resumen, y las bases y
     *   cuotas que enseña no suman el total de los cierres.
     * @que-deberia-ocurrir Que el resumen enseñe todos los tipos de IVA de los cierres del intervalo.
     * @por-que-ocurre El resumen recorre los tipos de la tabla de IVA y suma, para cada uno, lo de los cierres.
     * @como-deberia-funcionar Agrupar los IVA de los cierres del intervalo por su tipo, sin partir de la tabla.
     */
    public function test_elResumenDeIvaEnsenaTodosLosTiposDeLosCierres(): void
    {
        $pantalla = $this->ejecutar('ResumenFechas.php', ['fecha1' => '2031-05-10', 'fecha2' => '2031-05-10'], [], $this->cierre('c1', '2031-05-10', '2031-05-10 09:00:00', 29.01, [21 => [10.00, 2.10], 7 => [15.37, 1.54]]));

        self::assertStringContainsString('<td>15.37</td>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/ResumenFechas.php:121-122,145
     * @codigo-afectado modulos/mod_cierres/funciones.php:654-685
     *
     * @group defecto
     * @group cierres
     * @group bajo
     *
     * @que-ocurre-hoy El resumen de un intervalo sin cierres deja avisos de PHP al pintar los usuarios y las
     *   formas de pago.
     * @que-deberia-ocurrir Que enseñe el resumen vacío sin avisos.
     * @por-que-ocurre Las dos consultas devuelven una variable sin definir cuando no hay filas
     *   (`ConsultaCierresIntegracionTest`), y la pantalla la recorre.
     * @como-deberia-funcionar Que las consultas devuelvan una lista vacía.
     */
    public function test_unResumenSinCierresSePintaSinAvisos(): void
    {
        $pantalla = $this->ejecutar('ResumenFechas.php', ['fecha1' => '2040-01-01', 'fecha2' => '2040-01-02']);

        $this->sinAvisos($pantalla);
    }
}

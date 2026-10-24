<?php

/**
 * La petición de fondo de los clientes (`modulos/mod_cliente/tareas.php`, con los ficheros de `tareas/`):
 * grabar y borrar la tarifa de un producto, buscar productos para la tarifa, imprimir la ficha, la
 * tarifa y los resúmenes, el descuento mensual de tickets de todos los clientes y las incidencias.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna, y los permisos del módulo concedidos o con sus acciones quitadas. Tras el
 * arranque común se abre una transacción en su conexión, y las clases escriben por esa misma conexión:
 * lo que siembra el caso y lo que guarda la petición se deshace al terminar el proceso. Antes de
 * terminar, el proceso escribe las tarifas, los descuentos y las incidencias del caso, y el texto que
 * las impresiones pasan al PDF.
 *
 * La carpeta temporal de la configuración, donde las impresiones escriben su PDF, se cambia por una del
 * caso, que se borra al terminar.
 *
 * La preparación es una lista de sentencias; cada alta de un cliente queda con un nombre —`:c1`,
 * `:c2`…— y cada alta de un producto también —`:a1`, `:a2`…—, que las sentencias siguientes y el
 * formulario pueden usar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCliente;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasClienteIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->carpeta = sys_get_temp_dir() . '/clientes-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        rmdir($this->carpeta);
        parent::tearDown();
    }

    /** @comportamiento Grabar la tarifa de un producto que el cliente no tiene la da de alta, activa, con sus dos precios. */
    public function test_grabarUnaTarifaNuevaLaDaDeAlta(): void
    {
        $peticion = $this->ejecutar($this->tarifa('2.20', '2.00'), [$this->cliente('Bar Pepe'), $this->producto('Pera')]);

        self::assertSame([[':c1', ':a1', '2.000000', '2.200000', '1']], $this->tarifas($peticion));
    }

    /** @comportamiento Grabar la tarifa de un producto que el cliente ya tiene cambia sus precios y la deja activa, aunque estuviera borrada. */
    public function test_grabarUnaTarifaExistenteLaCambia(): void
    {
        $peticion = $this->ejecutar($this->tarifa('3.30', '3.00'), [$this->cliente('Bar Pepe'), $this->producto('Pera'), $this->tarifaSembrada('2')]);

        self::assertSame([[':c1', ':a1', '3.000000', '3.300000', '1']], $this->tarifas($peticion));
    }

    /** @comportamiento Borrar la tarifa de un producto la deja borrada, sin quitarla. */
    public function test_borrarUnaTarifaLaDejaBorrada(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'Borrar_producto_tarifa_cliente', 'idarticulo' => ':a1', 'idcliente' => ':c1'], [$this->cliente('Bar Pepe'), $this->producto('Pera'), $this->tarifaSembrada('1')]);

        self::assertSame([[':c1', ':a1', '1.818182', '2.000000', '2']], $this->tarifas($peticion));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/tareas.php:1-13
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, la petición graba la tarifa de un cliente.
     * @que-deberia-ocurrir Que sin sesión no se atienda.
     * @por-que-ocurre La petición no mira la sesión: el arranque común la deja pasar.
     * @como-deberia-funcionar Comprobar la sesión al empezar la petición.
     */
    public function test_sinSesionNoSeGrabaUnaTarifa(): void
    {
        $peticion = $this->ejecutar($this->tarifa('2.20', '2.00'), [$this->cliente('Bar Pepe'), $this->producto('Pera')], false);

        self::assertSame([], $this->tarifas($peticion));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/tareas.php:22-51; modulos/mod_cliente/tareas/ResumenMensualVentaClientes.php:1-11
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin los permisos de la tarifa y de los descuentos, que el listado exige para
     *   ofrecerlos, la petición graba una tarifa y calcula y guarda los descuentos de todos los clientes.
     * @que-deberia-ocurrir Que la petición exija el permiso de cada orden.
     * @por-que-ocurre La petición solo consulta el permiso de sobrescribir descuentos.
     * @como-deberia-funcionar Consultar el permiso de cada orden que escribe.
     */
    public function test_sinPermisosNoSeGrabanTarifasNiDescuentos(): void
    {
        $tarifa = $this->ejecutar($this->tarifa('2.20', '2.00'), [$this->cliente('Bar Pepe'), $this->producto('Pera')], true, false);
        $descuentos = $this->ejecutar($this->descuentos(), $this->conTicketsDeSeptiembre(), true, false);

        self::assertSame([[], []], [$this->tarifas($tarifa), $descuentos['descuentos']]);
    }

    /**
     * @comportamiento Buscar productos para la tarifa por su número, su nombre o su referencia responde
     *   el buscador con ellos; sin ninguno, dice que no encontró nada.
     */
    public function test_buscarProductosParaLaTarifa(): void
    {
        $preparacion = [$this->producto('Pera conferencia de tarifa', '2.00'), $this->precio(':a1', '1001', '2.00')];

        $porNumero = $this->ejecutar(['pulsado' => 'leerArticulo', 'caja' => 'idArticulo', 'valor' => ':a1', 'idcliente' => '0'], $preparacion);
        $porNombre = $this->ejecutar(['pulsado' => 'leerArticulo', 'caja' => 'Descripcion', 'valor' => 'conferencia de tarifa', 'idcliente' => '0'], $preparacion);
        $porReferencia = $this->ejecutar(['pulsado' => 'leerArticulo', 'caja' => 'Referencia', 'valor' => '1001', 'idcliente' => '0'], $preparacion);
        $sinNada = $this->ejecutar(['pulsado' => 'leerArticulo', 'caja' => 'Descripcion', 'valor' => 'Ningún producto se llama así', 'idcliente' => '0'], $preparacion);

        self::assertStringContainsString("'Pera conferencia de tarifa'", $porNumero['respuesta']['html']['html']);
        self::assertSame(1, $porNombre['respuesta']['NItems']);
        self::assertStringContainsString('Pera conferencia de tarifa', $porReferencia['respuesta']['html']['html']);
        self::assertStringContainsString('No se encontrado nada con esa busqueda', $sinNada['respuesta']['html']['html']);
    }

    /**
     * @comportamiento Imprimir la ficha de un cliente escribe su PDF en la carpeta temporal, con el
     *   nombre de la tienda, los datos del cliente y el texto de protección de datos, y responde su ruta.
     */
    public function test_imprimirLaFichaEscribeElPdf(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'imprimirFichaCliente', 'idCliente' => ':c1'], ["INSERT INTO clientes (Nombre, razonsocial, nif, email, estado) VALUES ('Bar Pepe', 'Pepe SL', 'B99000001', 'bar@pepe.test', 'Activo')"]);

        self::assertStringEndsWith('/FichaCliente.pdf', $peticion['respuesta']);
        self::assertFileExists($this->carpeta . '/FichaCliente.pdf');
        self::assertStringContainsString('Tienda de pruebas', $peticion['impreso']['cabecera']);
        self::assertStringContainsString('B99000001', $peticion['impreso']['cabecera']);
        self::assertStringContainsString('Protección de datos personales', $peticion['impreso']['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/tareas/imprimirTarifasCliente.php:21
     *
     * @group defecto
     * @group tarifas
     * @group medio
     *
     * @que-ocurre-hoy La tarifa impresa de cualquier cliente lleva en la cabecera, en lugar del nombre
     *   comercial de la tienda, el de otra tienda, escrito en el código.
     * @que-deberia-ocurrir Que lleve el nombre comercial de la tienda de la sesión, como la ficha y el
     *   resumen.
     * @por-que-ocurre El nombre está escrito en la plantilla.
     * @como-deberia-funcionar Tomar el nombre comercial de la tienda de la sesión.
     */
    public function test_laTarifaImpresaLlevaLaTiendaDeLaSesion(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'imprimirTarifasCliente', 'idCliente' => ':c1'], [$this->cliente('Bar Pepe'), $this->producto('Pera'), $this->tarifaSembrada('1')]);

        self::assertFileExists($this->carpeta . '/TarifaCliente.pdf');
        self::assertStringContainsString('Pera', $peticion['impreso']['html']);
        self::assertStringContainsString('B00000000', $peticion['impreso']['cabecera']);
        self::assertStringContainsString('<font size="20">Tienda de pruebas', $peticion['impreso']['cabecera']);
    }

    /**
     * @comportamiento Imprimir el resumen de tickets de un cliente escribe su PDF con cada ticket, el
     *   desglose por IVA y los productos; sin fechas, responde que faltan.
     */
    public function test_imprimirElResumenDeTickets(): void
    {
        $resumen = $this->ejecutar(['pulsado' => 'imprimirResumen', 'idCliente' => ':c1', 'fechaInicial' => '2026-09-01', 'fechaFinal' => '2026-09-30', 'dedonde' => 'ticket'], $this->conTicketsDeSeptiembre());
        $sinFechas = $this->ejecutar(['pulsado' => 'imprimirResumen', 'idCliente' => ':c1', 'fechaInicial' => '', 'fechaFinal' => '', 'dedonde' => 'ticket'], $this->conTicketsDeSeptiembre());

        self::assertFileExists($this->carpeta . '/Resumen.pdf');
        self::assertStringContainsString('Facturas simplificadas (Tickets)', $resumen['impreso']['html']);
        self::assertSame(2, substr_count($resumen['impreso']['html'], '-' . $this->idUsuario . '-'));
        self::assertStringContainsString('<b>30€</b>', $resumen['impreso']['html']);
        self::assertSame('Error no se han enviado corectamente las fechas', $sinFechas['respuesta']['error']['mensaje']);
    }

    /**
     * @comportamiento El descuento mensual de tickets se calcula para cada cliente activo con tickets en
     *   el periodo, con su porcentaje, y se guarda; responde cuántos procesó, cuántos sin tickets y
     *   cuántos insertó.
     */
    public function test_elDescuentoMensualSeCalculaYSeGuarda(): void
    {
        $peticion = $this->ejecutar($this->descuentos(), [...$this->conTicketsDeSeptiembre(), $this->cliente('Sin tickets')]);

        self::assertSame([[':c1', '2026-09-01', '2', '30.00', '0.60']], $this->descuentosDe($peticion));
        self::assertStringContainsString('Descuentos insertados: 1', $peticion['respuesta'][0]);
        self::assertMatchesRegularExpression('/Clientes sin tickets: [1-9]/', $peticion['respuesta'][0]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cliente/tareas/ResumenMensualVentaClientes.php:42-46
     *
     * @group defecto
     * @group descuentos
     * @group alto
     *
     * @que-ocurre-hoy Sobrescribir el descuento de septiembre de un cliente cambia también sus descuentos
     *   de los demás meses: todos quedan con las fechas, los tickets y el importe de septiembre.
     * @que-deberia-ocurrir Que se cambie solo el descuento del periodo calculado.
     * @por-que-ocurre El cambio se hace por el cliente, sin el periodo.
     * @como-deberia-funcionar Cambiar el descuento por su número, o por el cliente y la fecha de inicio.
     */
    public function test_sobrescribirUnDescuentoNoCambiaLosDeOtrosMeses(): void
    {
        $peticion = $this->ejecutar($this->descuentos(['actualizar' => '1']), [
            ...$this->conTicketsDeSeptiembre(),
            $this->descuentoSembrado('2026-08-01', '2026-08-31 23:59:59', '50.00', '1.00'),
            $this->descuentoSembrado('2026-09-01', '2026-09-30 23:59:59', '10.00', '0.20'),
        ]);

        self::assertSame([[':c1', '2026-08-01', '2', '50.00', '1.00'], [':c1', '2026-09-01', '2', '30.00', '0.60']], $this->descuentosDe($peticion));
    }

    /** @comportamiento Abrir una incidencia desde la ficha responde su ventana; grabarla la guarda como de clientes. */
    public function test_lasIncidenciasDeLaFicha(): void
    {
        $abrir = $this->ejecutar(['pulsado' => 'abririncidencia', 'dedonde' => 'cliente', 'usuario' => (string) $this->idUsuario, 'idReal' => '7', 'configuracion' => '']);
        $grabar = $this->ejecutar(['pulsado' => 'nuevaIncidencia', 'usuario' => (string) $this->idUsuario, 'fecha' => '2026-10-24', 'datos' => '{"vista":"cliente","idReal":"7"}', 'estado' => 'No resuelto', 'mensaje' => 'El NIF no coincide']);

        self::assertSame('{"vista":"cliente","idReal":"7"}', $abrir['respuesta']['datos']);
        self::assertNotSame('', $abrir['respuesta']['html']);
        self::assertSame([['mod_clientes', 'El NIF no coincide']], array_map(static fn (array $i): array => [$i['dedonde'], $i['mensaje']], $grabar['incidencias']));
    }

    /** @return list<string> Un cliente con dos tickets en septiembre, de 10 € y de 20 €, y uno en octubre. */
    private function conTicketsDeSeptiembre(): array
    {
        return [
            "INSERT INTO clientes (Nombre, estado, descuento_ticket) VALUES ('Bar Pepe', 'Activo', 2)",
            $this->ticket('2026-09-05', '10.00'),
            $this->ticket('2026-09-20', '20.00'),
            $this->ticket('2026-10-02', '40.00'),
        ];
    }

    private function ticket(string $fecha, string $base): string
    {
        return "INSERT INTO ticketst (Numticket, Numtempticket, Fecha, idTienda, idUsuario, idCliente, estado, formaPago, entregado, total) SELECT COALESCE(MAX(Numticket), 0) + 1, COALESCE(MAX(Numticket), 0) + 1, '{$fecha} 10:00:00', :tienda, :usuario, :c1, 'Cerrado', 'Efectivo', 0, {$base} FROM ticketst;"
            . " INSERT INTO ticketstIva (idticketst, Numticket, iva, importeIva, totalbase) SELECT id, Numticket, 0, 0, {$base} FROM ticketst WHERE id = LAST_INSERT_ID()";
    }

    private function cliente(string $nombre): string
    {
        return "INSERT INTO clientes (Nombre, estado) VALUES ('{$nombre}', 'Activo')";
    }

    private function producto(string $nombre, string $precio = '2.00'): string
    {
        return "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (10.00, '{$nombre}', 'Activo', NOW(), 1, 'unidad')";
    }

    private function precio(string $producto, string $referencia, string $precio): string
    {
        return "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES ({$producto}, :tienda, '{$referencia}', 'Activo');"
            . " INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES ({$producto}, {$precio}, {$precio} / 1.1, :tienda)";
    }

    private function tarifaSembrada(string $estado): string
    {
        return "INSERT INTO articulosClientes (idArticulo, idClientes, pvpSiva, pvpCiva, fechaActualizacion, estado) VALUES (:a1, :c1, 1.818182, 2.00, '2026-10-01 10:00:00', '{$estado}')";
    }

    private function descuentoSembrado(string $inicio, string $fin, string $importe, string $descuento): string
    {
        return "INSERT INTO descuentos_tickets (idCliente, descuentoCliente, fechaInicio, fechaFin, numTickets, importeTickets, importeDescuento, idUsuario, estado) VALUES (:c1, 2, '{$inicio}', '{$fin}', 1, {$importe}, {$descuento}, :usuario, 'Pendiente')";
    }

    /** @return array<string, string> */
    private function tarifa(string $conIva, string $sinIva): array
    {
        return ['pulsado' => 'Grabar_tarifa_producto_cliente', 'idarticulo' => ':a1', 'idcliente' => ':c1', 'pvpCiva' => $conIva, 'pvpSiva' => $sinIva];
    }

    /**
     * @param array<string, string> $cambios
     * @return array<string, string>
     */
    private function descuentos(array $cambios = []): array
    {
        return $cambios + ['pulsado' => 'generarResumenTicketsClientes', 'fechainicio' => '2026-09-01', 'fechafin' => '2026-09-30 23:59:59'];
    }

    /** @return list<list<string>> Las tarifas del caso, con los números sembrados vueltos a su nombre. */
    private function tarifas(array $peticion): array
    {
        $nombres = array_flip($peticion['ids']);

        return array_map(static fn (array $t): array => [$nombres[$t['idClientes']] ?? $t['idClientes'], $nombres[$t['idArticulo']] ?? $t['idArticulo'], $t['pvpSiva'], $t['pvpCiva'], $t['estado']], $peticion['tarifas']);
    }

    /** @return list<list<string>> Los descuentos del caso, con el número del cliente vuelto a su nombre. */
    private function descuentosDe(array $peticion): array
    {
        $nombres = array_flip($peticion['ids']);

        return array_map(static fn (array $d): array => [$nombres[$d['idCliente']] ?? $d['idCliente'], substr($d['fechaInicio'], 0, 10), (string) (int) $d['descuentoCliente'], $d['importeTickets'], $d['importeDescuento']], $peticion['descuentos']);
    }

    /**
     * Ejecuta la petición de fondo en un PHP aparte y devuelve su respuesta, sus avisos, las tarifas, los
     * descuentos y las incidencias del caso, el texto de la impresión y los números de lo sembrado.
     *
     * @param array<string, string> $formulario
     * @param list<string> $preparacion
     * @return array{respuesta: mixed, errores: string, tarifas: list<array<string, string>>, descuentos: list<array<string, string>>, incidencias: list<array<string, string>>, impreso: array<string, ?string>, ids: array<string, string>}
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true, bool $conPermisos = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas', 'nif' => 'B00000000', 'direccion' => 'Calle de pruebas 1', 'telefono' => '986000000', 'ano' => '2026'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_cliente/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_cliente', true) . ');'
            . ' $_POST = ["pulsado" => ""]; require "./../../inicial.php";'
            . ' $rutatmp = str_repeat("/..", substr_count(rtrim($RutaServidor, "/"), "/")) . ' . var_export($this->carpeta, true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = IF(accion IS NULL OR accion = \'\', 1, ' . ($conPermisos ? 1 : 0) . ') WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_cliente\'");'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = ["clientes" => 0, "articulos" => 0];'
            . ' $antes = ["clientes" => (int) $BDTpv->query("SELECT MAX(idClientes) FROM clientes")->fetch_row()[0], "incidencia" => (int) $BDTpv->query("SELECT COALESCE(MAX(id), 0) FROM modulo_incidencia")->fetch_row()[0]];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->multi_query(strtr($sql, $ids)); do { if ($r = $BDTpv->store_result()) { $r->free(); } } while ($BDTpv->more_results() && $BDTpv->next_result());'
            . ' if (preg_match("/^INSERT INTO (clientes|articulos) /", $sql, $t)) { $ids[($t[1] === "clientes" ? ":c" : ":a") . ++$cuentas[$t[1]]] = (string) $BDTpv->insert_id; } }'
            . ' $_POST = array_map(fn ($valor) => strtr((string) $valor, $ids), ' . var_export($formulario, true) . ');'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use ($BDTpv, &$ids, $antes) { echo "\n@@DATOS@@" . json_encode(["ids" => $ids,'
            . ' "tarifas" => $BDTpv->query("SELECT * FROM articulosClientes WHERE idClientes > " . $antes["clientes"])->fetch_all(MYSQLI_ASSOC),'
            . ' "descuentos" => $BDTpv->query("SELECT * FROM descuentos_tickets WHERE idCliente > " . $antes["clientes"] . " ORDER BY fechaInicio")->fetch_all(MYSQLI_ASSOC),'
            . ' "incidencias" => $BDTpv->query("SELECT dedonde, mensaje FROM modulo_incidencia WHERE id > " . $antes["incidencia"])->fetch_all(MYSQLI_ASSOC),'
            . ' "impreso" => ["cabecera" => $GLOBALS["cabecera"] ?? null, "html" => $GLOBALS["html"] ?? null]], JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'clientes');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['ids' => [], 'tarifas' => [], 'descuentos' => [], 'incidencias' => [], 'impreso' => []];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}

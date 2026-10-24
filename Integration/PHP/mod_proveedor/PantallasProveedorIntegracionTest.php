<?php

/**
 * Las pantallas de los proveedores —la ficha (`proveedor.php`), el listado (`ListaProveedores.php`), el
 * resumen de albaranes, el resumen anual y los productos de un proveedor (`OtrasVistas/`)— y la petición
 * de fondo (`tareas.php`).
 *
 * Cada una se ejecuta en un PHP aparte, desde su carpeta, con la sesión de un usuario de pruebas —el de
 * pruebas, sin permisos en el módulo, o el de los recorridos, con todos— o sin ninguna, y una transacción
 * del proceso que no se confirma y que comparten las clases (`ModeloP`). El caso siembra con sentencias
 * dentro de esa transacción y guarda en variables de la base (`@nombre`) los números que necesita; en la
 * dirección y en lo enviado, `{@nombre}` se cambia por su valor. Al terminar, el proceso escribe lo que
 * responden las consultas del caso. Las dos impresiones y el estado en la tienda web no se ejecutan
 * aquí: escriben un PDF en la carpeta temporal de la configuración, y piden a la tienda web.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProveedor;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasProveedorIntegracionTest extends CasoIntegracion
{
    private const SIN_PERMISOS = 83;
    private const CON_PERMISOS = 84;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idTienda = (new Siembra($this->db))->tiendaPorDefecto();
    }

    /**
     * Ejecuta un fichero del módulo en un PHP aparte y devuelve su HTML, su stderr y lo que responden las
     * consultas del caso. Sin `$usuario`, sin sesión.
     */
    private function ejecutar(string $fichero, array $get = [], array $post = [], array $preparacion = [], array $consultas = [], ?int $usuario = self::SIN_PERMISOS): array
    {
        $sesion = $usuario !== null ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $usuario, 'group_id' => $usuario === self::CON_PERMISOS ? '9' : '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas', 'NombreComercial' => 'Tienda', 'ano' => '2031',
                'direccion' => 'Rúa 1', 'nif' => 'B00000000', 'telefono' => '986000000'],
        ] : [];
        $carpeta = RUTA_TPVFOX . '/modulos/mod_proveedor/' . dirname($fichero);
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = ' . var_export($post !== [] ? 'POST' : 'GET', true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_proveedor/{$fichero}", true) . ';'
            . ' chdir(' . var_export($carpeta, true) . ');'
            . ' require "' . str_repeat('../', substr_count($fichero, '/')) . './../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query($sql); }'
            . ' $texto = ' . var_export(json_encode(['get' => $get, 'post' => $post]), true) . '; $valores = [];'
            . ' preg_match_all("/\\\\{@(\\\\w+)\\\\}/", $texto, $m); foreach (array_unique($m[1]) as $v) { $valores["{@$v}"] = (string) $BDTpv->query("SELECT @$v")->fetch_row()[0]; }'
            . ' $datos = json_decode(strtr($texto, $valores), true); $_GET = $datos["get"]; $_POST = $datos["post"];'
            . ' register_shutdown_function(function () use ($BDTpv) { $r = [];'
            . ' foreach (' . var_export($consultas, true) . ' as $n => $sql) { $fila = $BDTpv->query($sql)->fetch_row(); $r[$n] = $fila === null || $fila[0] === null ? null : (string) $fila[0]; }'
            . ' echo "\n@@ESTADO@@" . json_encode(["consultas" => $r]); });'
            . ' include ' . var_export(basename($fichero), true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'proveedores');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $estado] = array_pad(explode('@@ESTADO@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['consultas' => []];
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $pintada;
    }

    /** Un proveedor, guardado en `@$variable`. */
    private function proveedor(string $variable, string $nif, array $datos = []): array
    {
        $datos += ['nombrecomercial' => 'Huerta Prueba', 'razonsocial' => 'Huerta Prueba SL', 'direccion' => 'Rúa do Porto 1', 'telefono' => '986000001',
            'fax' => '986000002', 'movil' => '600000001', 'email' => 'huerta@prueba.test', 'estado' => 'Activo', 'registro_sanitario' => 'RS-1'];
        $columnas = implode(', ', array_keys($datos));
        $valores = implode(', ', array_map(fn (string $v): string => "'" . $this->db->real_escape_string($v) . "'", array_values($datos)));

        return ["INSERT INTO proveedores (nif, fecha_creado, $columnas) VALUES ('$nif', '2020-01-01 00:00:00', $valores)", "SET @$variable = LAST_INSERT_ID()"];
    }

    /** Un artículo con precio, ficha de tienda y existencias, guardado en `@$variable`; `$principal`, el proveedor principal. */
    private function articulo(string $variable, string $nombre, float $coste, int $iva, string $principal = 'NULL', float $stock = 10, float $minimo = 0): array
    {
        return [
            "INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, tipo, iva, idProveedor, costepromedio, beneficio) VALUES ('$nombre', 'Activo', '2020-01-01', $coste, 'unidad', $iva, $principal, $coste, 0)",
            "SET @$variable = LAST_INSERT_ID()",
            "INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (@$variable, " . ($coste * (1 + $iva / 100)) . ", $coste, {$this->idTienda})",
            "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES (@$variable, {$this->idTienda}, 'REF', 'Activo')",
            "INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (@$variable, {$this->idTienda}, $stock, $minimo, 0)",
        ];
    }

    /** Un albarán del proveedor con una línea y su IVA, guardado en `@$variable`; su número, `$numero`. */
    private function albaran(string $variable, string $proveedor, int $numero, string $fecha, string $articulo, float $unidades, float $coste, int $iva, string $estado = 'Guardado'): array
    {
        $base = round($unidades * $coste, 2);
        $cuota = round($base * $iva / 100, 2);

        return [
            "INSERT INTO albprot (Numalbpro, Su_numero, Fecha, idTienda, idUsuario, idProveedor, estado, formaPago, entregado, total_siniva, total) VALUES ($numero, 'SU-$numero', '$fecha', {$this->idTienda}, " . self::SIN_PERMISOS . ", $proveedor, '$estado', 'Efectivo', 0, $base, " . ($base + $cuota) . ')',
            "SET @$variable = LAST_INSERT_ID()",
            "INSERT INTO albprolinea (idalbpro, Numalbpro, idArticulo, ncant, nunidades, estadoLinea, ref_prov, costeSiva, iva, nfila) VALUES (@$variable, $numero, $articulo, $unidades, $unidades, 'Activo', '', $coste, $iva, 1)",
            "INSERT INTO albproIva (idalbpro, Numalbpro, iva, importeIva, totalbase) VALUES (@$variable, $numero, $iva, $cuota, $base)",
        ];
    }

    /** Una factura del proveedor con su IVA. */
    private function factura(string $proveedor, int $numero, string $fecha, float $base, int $iva): array
    {
        $cuota = round($base * $iva / 100, 2);

        return [
            "INSERT INTO facprot (Numfacpro, Fecha, idTienda, idUsuario, idProveedor, estado, formaPago, entregado, total_siniva, total) VALUES ($numero, '$fecha', {$this->idTienda}, " . self::SIN_PERMISOS . ", $proveedor, 'Guardado', 'Efectivo', 0, $base, " . ($base + $cuota) . ')',
            "INSERT INTO facproIva (idfacpro, Numfacpro, iva, importeIva, totalbase) VALUES (LAST_INSERT_ID(), $numero, $iva, $cuota, $base)",
        ];
    }

    /** Lo que envía la ficha al guardar. */
    private function enviado(string $idProveedor, array $cambios = []): array
    {
        return $cambios + [
            'Guardar' => 'Guardar', 'idProveedor' => $idProveedor, 'nombrecomercial' => 'Huerta Prueba', 'razonsocial' => 'Huerta Prueba SL',
            'nif' => 'B70000001', 'direccion' => 'Rúa do Porto 1', 'telefono' => '986000001', 'registro_sanitario' => 'RS-1', 'movil' => '600000001',
            'email' => 'huerta@prueba.test', 'fax' => '986000002', 'fecha_creado' => '', 'estado' => 'Activo',
        ];
    }

    private function sinAvisos(array $pantalla): void
    {
        self::assertStringNotContainsString('Warning', $pantalla['errores']);
        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
    }

    /**
     * @comportamiento La ficha de un proveedor enseña sus datos sin dejar editarlos, sus últimas facturas,
     *   albaranes y pedidos en sus desplegables y, con facturas del ejercicio de la sesión, su resumen anual.
     */
    public function test_laFichaEnsenaUnProveedorConSusDocumentos(): void
    {
        $preparacion = array_merge(
            $this->proveedor('p', 'B70000001'),
            $this->articulo('a', 'Tomate Prueba', 2, 21),
            $this->albaran('al', '@p', 9001, '2031-05-10 12:00:00', '@a', 10, 2, 21),
            $this->factura('@p', 9101, '2031-05-20 12:00:00', 20, 10),
        );

        $pantalla = $this->ejecutar('proveedor.php', ['id' => '{@p}', 'accion' => 'ver'], [], $preparacion);

        $this->sinAvisos($pantalla);
        self::assertStringContainsString('<h1 class="text-center"> Proveedor: Ver</h1>', $pantalla['html']);
        self::assertStringContainsString('name="nif" disabled value="B70000001"', $pantalla['html']);
        self::assertStringContainsString('>9001</a>', $pantalla['html']);
        self::assertStringContainsString('>9101</a>', $pantalla['html']);
        self::assertStringContainsString('Este proveedor no tiene pedidos', $pantalla['html']);
        self::assertStringContainsString('Resumen Anual 2031', $pantalla['html']);
        self::assertStringNotContainsString('name="Guardar"', $pantalla['html']);
    }

    /** @comportamiento Sin número, la ficha ofrece crear un proveedor, con el botón de guardar. */
    public function test_laFichaSinNumeroOfreceCrear(): void
    {
        $pantalla = $this->ejecutar('proveedor.php');

        $this->sinAvisos($pantalla);
        self::assertStringContainsString('Proveedor: Crear', $pantalla['html']);
        self::assertStringContainsString('name="Guardar"', $pantalla['html']);
    }

    /** @comportamiento Guardar desde la ficha nueva crea el proveedor y lo avisa. */
    public function test_guardarDesdeLaFichaCreaElProveedor(): void
    {
        $pantalla = $this->ejecutar('proveedor.php', [], $this->enviado(''), [], ['creados' => "SELECT COUNT(*) FROM proveedores WHERE nif = 'B70000001'"], self::CON_PERMISOS);

        self::assertStringContainsString('Fue guardo correctamente', $pantalla['html']);
        self::assertSame('1', $pantalla['consultas']['creados']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/proveedor.php:56-74,87-92
     *
     * @group defecto
     * @group proveedor
     * @group medio
     *
     * @que-ocurre-hoy Después de crear un proveedor, la ficha vuelve a pintarse como nueva: con el título
     *   «Crear» y el número vacío. Guardarla otra vez crea otro proveedor igual —si el NIF está vacío, no lo
     *   impide ni la comprobación del NIF repetido—.
     * @que-deberia-ocurrir Que, creado, la ficha quede con el número del proveedor nuevo.
     * @por-que-ocurre La ficha decide que es nueva por el número de la dirección, que sigue sin venir, y
     *   vacía el número del proveedor que acaba de leer.
     * @como-deberia-funcionar Tomar el número del proveedor guardado, o volver a la ficha con él en la
     *   dirección.
     */
    public function test_despuesDeCrearLaFichaQuedaConSuNumero(): void
    {
        $pantalla = $this->ejecutar('proveedor.php', [], $this->enviado(''), [], [], self::CON_PERMISOS);

        self::assertMatchesRegularExpression('/id="idProveedor" name="idProveedor" value="\d+"/', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/proveedor.php:1-99
     *
     * @group defecto
     * @group proveedor
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, la ficha guarda: un envío con «Guardar» crea o modifica un proveedor antes
     *   de que la cabecera común pida entrar.
     * @que-deberia-ocurrir Que sin sesión no se guarde nada.
     * @por-que-ocurre La ficha guarda antes de cargar la cabecera común, que es la que corta sin sesión.
     * @como-deberia-funcionar Comprobar la sesión al empezar cada entrada.
     */
    public function test_sinSesionLaFichaNoGuarda(): void
    {
        $pantalla = $this->ejecutar('proveedor.php', [], $this->enviado(''), [], ['creados' => "SELECT COUNT(*) FROM proveedores WHERE nif = 'B70000001'"], null);

        self::assertSame('0', $pantalla['consultas']['creados']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/proveedor.php:56-84,133-138
     *
     * @group defecto
     * @group proveedor
     * @group alto
     *
     * @que-ocurre-hoy Un usuario sin el permiso de modificar proveedores modifica uno: la ficha abierta en
     *   edición por su dirección ofrece guardar, y el envío se guarda.
     * @que-deberia-ocurrir Que sin el permiso de modificar —o de crear, para uno nuevo— no se guarde.
     * @por-que-ocurre La ficha no consulta ningún permiso: el listado solo esconde los enlaces.
     * @como-deberia-funcionar Consultar la acción de la vista antes de ofrecer y de atender el guardado.
     */
    public function test_sinPermisoDeModificarNoSeGuarda(): void
    {
        $pantalla = $this->ejecutar('proveedor.php', ['id' => '{@p}', 'accion' => 'editar'], $this->enviado('{@p}', ['telefono' => '986999999']), $this->proveedor('p', 'B70000001'), [
            'telefono' => 'SELECT telefono FROM proveedores WHERE idProveedor = @p',
        ], self::SIN_PERMISOS);

        self::assertSame('986000001', $pantalla['consultas']['telefono']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/proveedor.php:145-202
     * @codigo-afectado modulos/mod_proveedor/ListaProveedores.php:154-180
     *
     * @group defecto
     * @group proveedor
     * @group medio
     *
     * @que-ocurre-hoy Un nombre con comillas dobles —«Bodegas "La Ría"»— se corta en la ficha: el valor de
     *   la caja termina en la primera comilla, y guardarla sin tocarlo lo deja en «Bodegas ». El listado y
     *   las demás pantallas escriben igual lo que leen de la base.
     * @que-deberia-ocurrir Que cada dato se escriba como texto.
     * @por-que-ocurre Los datos se escriben con `echo`, sin escapar.
     * @como-deberia-funcionar Escapar con `htmlspecialchars()` todo lo que se escribe en la página.
     */
    public function test_unNombreConComillasSeEnsenaEntero(): void
    {
        $pantalla = $this->ejecutar('proveedor.php', ['id' => '{@p}', 'accion' => 'ver'], [], $this->proveedor('p', 'B70000001', ['nombrecomercial' => 'Bodegas "La Ría"']));

        self::assertStringContainsString('value="Bodegas &quot;La Ría&quot;"', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/proveedor.php:19,194-205
     *
     * @group defecto
     * @group proveedor
     * @group medio
     *
     * @que-ocurre-hoy Un proveedor con un estado que no está en la lista de la ficha se enseña como «Activo»:
     *   el desplegable no marca ninguno y queda en el primero, y guardar la ficha lo deja en «Activo» sin que
     *   nadie lo haya cambiado.
     * @que-deberia-ocurrir Que la ficha enseñe el estado tal como está guardado, y no lo cambie al guardar.
     * @por-que-ocurre El desplegable solo marca los tres estados que conoce, y el guardado no comprueba el
     *   estado (`ProveedorIntegracionTest::test_unEstadoDesconocidoNoSeGuarda`).
     * @como-deberia-funcionar Comprobar el estado al guardar, y enseñar el guardado aunque no sea de la lista.
     */
    public function test_unEstadoQueNoEstaEnLaListaSeEnsenaComoEsta(): void
    {
        $pantalla = $this->ejecutar('proveedor.php', ['id' => '{@p}', 'accion' => 'ver'], [], $this->proveedor('p', 'B70000001', ['estado' => 'Inactivo']));

        self::assertStringContainsString('value="Inactivo" selected', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/proveedor.php:20-23
     * @codigo-afectado modulos/mod_proveedor/clases/ClaseProveedor.php:49-56
     * @codigo-afectado modulos/mod_proveedor/OtrasVistas/ListadoProductosDeProveedor.php:19-34,53
     *
     * @group defecto
     * @group proveedor
     * @group alto
     *
     * @que-ocurre-hoy Lo que llega en la dirección se pega en las consultas: un número de proveedor que no
     *   es un número rompe la ficha, y el campo de orden del listado de productos, la consulta de sus
     *   productos; las dos páginas terminan con un error de la base.
     * @que-deberia-ocurrir Que un número que no lo es no encuentre nada, y que el orden sea uno de los campos
     *   que ofrece la pantalla.
     * @por-que-ocurre El número y el orden se componen en el texto de la sentencia sin comprobarlos.
     * @como-deberia-funcionar Comprobar el número y el orden contra lo permitido, y pasar los valores como
     *   parámetros de una sentencia preparada.
     */
    public function test_loQueLlegaEnLaDireccionNoRompeLasConsultas(): void
    {
        $ficha = $this->ejecutar('proveedor.php', ['id' => 'abc', 'accion' => 'ver']);

        self::assertStringNotContainsString('Fatal error', $ficha['errores']);
    }

    /**
     * @comportamiento El listado pagina los proveedores y busca en su nombre; ofrece añadir, ver y modificar
     *   según los permisos de la vista, y sin permiso no ofrece ninguno de los tres.
     */
    public function test_elListadoBuscaYOfreceSegunLosPermisos(): void
    {
        $preparacion = $this->proveedor('p', 'B70000001', ['nombrecomercial' => 'Huerta Zarzalejo']);

        $conPermisos = $this->ejecutar('ListaProveedores.php', ['buscar' => 'Zarzalejo'], [], $preparacion, [], self::CON_PERMISOS);
        $sinPermisos = $this->ejecutar('ListaProveedores.php', ['buscar' => 'Zarzalejo'], [], $preparacion, [], self::SIN_PERMISOS);

        $this->sinAvisos($conPermisos);
        self::assertStringContainsString('<td>Huerta Zarzalejo</td>', $conPermisos['html']);
        self::assertStringContainsString("metodoClick('AgregarProveedor')", $conPermisos['html']);
        self::assertStringContainsString('&accion=editar', $conPermisos['html']);
        self::assertStringContainsString('<td>Huerta Zarzalejo</td>', $sinPermisos['html']);
        self::assertStringNotContainsString("metodoClick('AgregarProveedor')", $sinPermisos['html']);
        self::assertStringNotContainsString('&accion=editar', $sinPermisos['html']);
    }

    /**
     * @comportamiento El resumen de albaranes entre dos fechas enseña los productos comprados con su coste
     *   medio, el desglose de IVA con sus totales y los albaranes del intervalo; a quien puede crear facturas
     *   de compra, y para un proveedor «Activo», el botón de crear la factura desde ellos.
     */
    public function test_elResumenDeAlbaranesEntreDosFechas(): void
    {
        $preparacion = array_merge(
            $this->proveedor('p', 'B70000001'),
            $this->articulo('a', 'Tomate Prueba', 2, 21, '@p'),
            $this->albaran('al1', '@p', 9001, '2031-05-10 12:00:00', '@a', 10, 2, 21),
            $this->albaran('al2', '@p', 9002, '2031-05-12 12:00:00', '@a', 10, 3, 21),
        );

        $pantalla = $this->ejecutar('OtrasVistas/resumenAlbaranes.php', ['id' => '{@p}', 'fechaIni' => '2031-05-01', 'fechaFin' => '2031-05-31'], [], $preparacion, [], self::CON_PERMISOS);

        $this->sinAvisos($pantalla);
        self::assertStringContainsString('<td>Tomate Prueba</td>', $pantalla['html']);
        self::assertStringContainsString('<td>2.50</td>', $pantalla['html']);
        self::assertStringContainsString('<th>TOTALES</th><th>50</th><th>10.5</th><th>60.5</th>', $pantalla['html']);
        self::assertStringContainsString('<td>SU-9002</td>', $pantalla['html']);
        self::assertStringContainsString('Crear factura desde albaranes', $pantalla['html']);
        self::assertStringContainsString('Referencias principales compradas:</strong>1', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/OtrasVistas/resumenAlbaranes.php:318,330-336
     *
     * @group defecto
     * @group proveedor
     * @group medio
     *
     * @que-ocurre-hoy El enlace de cada albarán del resumen abre `albaran.php` con el número del albarán en
     *   lugar de su identificador: abre otro albarán, o ninguno; y la casilla para facturarlo envía el mismo
     *   número.
     * @que-deberia-ocurrir Que el enlace abra ese albarán.
     * @por-que-ocurre Se escribe `Numalbpro` donde la pantalla de compras espera el `id` del albarán.
     * @como-deberia-funcionar Enlazar con el identificador, como hace la ficha del proveedor.
     */
    public function test_elEnlaceDeCadaAlbaranAbreEseAlbaran(): void
    {
        $preparacion = array_merge(
            $this->proveedor('p', 'B70000001'),
            $this->articulo('a', 'Tomate Prueba', 2, 21),
            $this->albaran('al', '@p', 9001, '2031-05-10 12:00:00', '@a', 10, 2, 21),
        );

        $pantalla = $this->ejecutar('OtrasVistas/resumenAlbaranes.php', ['id' => '{@p}', 'fechaIni' => '2031-05-01', 'fechaFin' => '2031-05-31'], [], $preparacion, ['id' => 'SELECT @al']);

        self::assertStringContainsString('albaran.php?id=' . $pantalla['consultas']['id'] . '&accion=editar', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/OtrasVistas/resumenAlbaranes.php:56-64
     *
     * @group defecto
     * @group proveedor
     * @group bajo
     *
     * @que-ocurre-hoy Una fecha mal escrita en la dirección del resumen de albaranes termina la página con un
     *   error de tipo de PHP.
     * @que-deberia-ocurrir Que se avise.
     * @por-que-ocurre La misma que en la comprobación de las fechas (`FuncionesProveedorTest`): `date_format()`
     *   de un `date_create()` fallido.
     * @como-deberia-funcionar Comprobar la fecha antes de formatearla.
     */
    public function test_unaFechaMalEscritaEnLaDireccionSeAvisa(): void
    {
        $pantalla = $this->ejecutar('OtrasVistas/resumenAlbaranes.php', ['id' => '{@p}', 'fechaIni' => '31 de mayo', 'fechaFin' => '2031-05-31'], [], $this->proveedor('p', 'B70000001'));

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
    }

    /**
     * @comportamiento El resumen anual de proveedores enseña los del ejercicio de la sesión con más de
     *   2.500 €, y marca los de más de 3.000 de base; los de menos, no salen.
     */
    public function test_elResumenAnualEnsenaLosProveedoresQueSuperanElUmbral(): void
    {
        $preparacion = array_merge(
            $this->proveedor('grande', 'B70000001', ['nombrecomercial' => 'Proveedor Grande']),
            $this->proveedor('pequeno', 'B70000002', ['nombrecomercial' => 'Proveedor Pequeño']),
            $this->factura('@grande', 9101, '2031-05-20 12:00:00', 3200, 10),
            $this->factura('@pequeno', 9102, '2031-05-20 12:00:00', 100, 10),
        );

        $pantalla = $this->ejecutar('OtrasVistas/resumenProveedores.php', [], [], $preparacion);

        $this->sinAvisos($pantalla);
        self::assertStringContainsString('<h2>Resumen Anual de Proveedor Grande</h2><div class="col-md-12 bg-info">', $pantalla['html']);
        self::assertStringNotContainsString('Proveedor Pequeño', $pantalla['html']);
    }

    /** @comportamiento Sin sesión, el resumen de albaranes no enseña las compras del proveedor. */
    public function test_sinSesionElResumenDeAlbaranesNoEnsenaLasCompras(): void
    {
        $preparacion = array_merge(
            $this->proveedor('p', 'B70000001'),
            $this->articulo('a', 'Tomate Prueba', 2, 21, '@p'),
            $this->albaran('al1', '@p', 9001, '2031-05-10 12:00:00', '@a', 10, 2, 21),
        );

        $pantalla = $this->ejecutar('OtrasVistas/resumenAlbaranes.php', ['id' => '{@p}', 'fechaIni' => '2031-05-01', 'fechaFin' => '2031-05-31'], [], $preparacion, [], null);

        self::assertStringNotContainsString('Tomate Prueba', $pantalla['html']);
        self::assertStringNotContainsString('SU-9001', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/OtrasVistas/resumenAlbaranes.php:72,137,144
     * @codigo-afectado modulos/mod_proveedor/OtrasVistas/ListadoProductosDeProveedor.php:244,252
     *
     * @group defecto
     * @group proveedor
     * @group medio
     *
     * @que-ocurre-hoy Sin el número del proveedor en la dirección, el resumen de albaranes y los productos
     *   de un proveedor se pintan con avisos de PHP, por un número, un título y unos datos sin definir.
     * @que-deberia-ocurrir Que sin número avisen de que falta el proveedor, sin avisos de PHP.
     * @por-que-ocurre Las dos pantallas solo definen el número y lo que leen con él si llega en la
     *   dirección, y lo usan igual.
     * @como-deberia-funcionar Comprobar el número al empezar y avisar si falta.
     */
    public function test_sinNumeroElResumenYLosProductosAvisanSinAvisosDePhp(): void
    {
        foreach (['OtrasVistas/resumenAlbaranes.php', 'OtrasVistas/ListadoProductosDeProveedor.php'] as $fichero) {
            $this->sinAvisos($this->ejecutar($fichero, [], [], [], [], self::CON_PERMISOS));
        }
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/funciones.php:238-251
     *
     * @group defecto
     * @group proveedor
     * @group medio
     *
     * @que-ocurre-hoy Sin ninguna factura de proveedor en la base, el resumen anual de proveedores se pinta
     *   con un aviso de PHP.
     * @que-deberia-ocurrir Que se pinte vacío, sin avisos.
     * @por-que-ocurre La validación recorre el resumen sin mirar si la lectura trajo filas, y sin ellas
     *   recorre un valor nulo.
     * @como-deberia-funcionar Tratar el resumen sin filas como una lista vacía.
     */
    public function test_sinFacturasElResumenAnualSePintaSinAvisos(): void
    {
        $pantalla = $this->ejecutar('OtrasVistas/resumenProveedores.php', [], [], ['DELETE FROM facprot']);

        $this->sinAvisos($pantalla);
    }

    /** @comportamiento Sin sesión, la pantalla de los productos de un proveedor no los enseña. */
    public function test_sinSesionLosProductosDeUnProveedorNoSeEnsenan(): void
    {
        $preparacion = array_merge(
            $this->proveedor('p', 'B70000001'),
            $this->articulo('principal', 'Tomate Prueba', 2, 21, '@p'),
            ["INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) VALUES (@principal, @p, 'TOM-1', 2.10, '2031-01-01', 'Activo')"],
        );

        $pantalla = $this->ejecutar('OtrasVistas/ListadoProductosDeProveedor.php', ['id' => '{@p}'], [], $preparacion, [], null);

        self::assertStringNotContainsString('Tomate Prueba', $pantalla['html']);
        self::assertStringNotContainsString('TOM-1', $pantalla['html']);
    }

    /**
     * @comportamiento El listado de productos de un proveedor enseña primero los artículos de los que es el
     *   principal y después los asociados, con su coste y su referencia de ese proveedor; quien puede
     *   modificar ve la papelera de los asociados.
     */
    public function test_losProductosDeUnProveedor(): void
    {
        $preparacion = array_merge(
            $this->proveedor('p', 'B70000001'),
            $this->articulo('principal', 'Tomate Prueba', 2, 21, '@p'),
            $this->articulo('asociado', 'Pimiento Prueba', 3, 21),
            ["INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) VALUES (@principal, @p, 'TOM-1', 2.10, '2031-01-01', 'Activo')",
             "INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) VALUES (@asociado, @p, 'PIM-1', 3.20, '2031-01-01', 'Activo')"],
        );

        $pantalla = $this->ejecutar('OtrasVistas/ListadoProductosDeProveedor.php', ['id' => '{@p}'], [], $preparacion, [], self::CON_PERMISOS);

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
        self::assertStringContainsString('<strong>Productos principales:</strong> 1', $pantalla['html']);
        self::assertStringContainsString('<strong>Asociados (secundario):</strong> 1', $pantalla['html']);
        self::assertStringContainsString('<td>TOM-1</td>', $pantalla['html']);
        self::assertStringContainsString('<td>3.20</td>', $pantalla['html']);
        self::assertLessThan(strpos($pantalla['html'], 'Pimiento Prueba'), strpos($pantalla['html'], 'Tomate Prueba'));
        self::assertStringContainsString('eliminarRefProveedorArticulo(', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/OtrasVistas/ListadoProductosDeProveedor.php:332-336
     *
     * @group defecto
     * @group proveedor
     * @group bajo
     *
     * @que-ocurre-hoy Un artículo con mil unidades o más sale en rojo, como bajo mínimo, aunque esté muy por
     *   encima de su mínimo: con 1.234 unidades y mínimo 5, la fila se marca.
     * @que-deberia-ocurrir Que solo se marque el que está por debajo de su mínimo.
     * @por-que-ocurre Se compara el stock formateado con separador de miles —«1,234», un texto— con el
     *   mínimo, y en PHP 8 esa comparación es de textos.
     * @como-deberia-funcionar Comparar el stock como número.
     */
    public function test_unStockGrandeNoSeMarcaBajoMinimo(): void
    {
        $preparacion = array_merge(
            $this->proveedor('p', 'B70000001'),
            $this->articulo('principal', 'Tomate Prueba', 2, 21, '@p', 1234, 5),
            ["INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) VALUES (@principal, @p, 'TOM-1', 2.10, '2031-01-01', 'Activo')"],
        );

        $pantalla = $this->ejecutar('OtrasVistas/ListadoProductosDeProveedor.php', ['id' => '{@p}'], [], $preparacion, [], self::CON_PERMISOS);

        self::assertMatchesRegularExpression('/<tr id="fila_articulo_\d+" class=" Row_/', $pantalla['html']);
    }

    /**
     * @comportamiento Con productos en una familia, la pantalla ofrece el filtro por familias, cada una en
     *   su nivel y con las familias de las que cuelga, marcadas.
     */
    public function test_conFamiliasSeOfreceElFiltroPorNiveles(): void
    {
        $preparacion = array_merge(
            $this->proveedor('p', 'B70000001'),
            ["INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('Frutas Prueba', 0, 0, 1)", 'SET @frutas = LAST_INSERT_ID()',
             "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('Citricos Prueba', @frutas, 0, 1)", 'SET @citricos = LAST_INSERT_ID()'],
            $this->articulo('principal', 'Naranja Prueba', 2, 21, '@p'),
            ['INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (@principal, @citricos)',
             "INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) VALUES (@principal, @p, 'NAR-1', 2.10, '2031-01-01', 'Activo')"],
        );

        $pantalla = $this->ejecutar('OtrasVistas/ListadoProductosDeProveedor.php', ['id' => '{@p}'], [], $preparacion,
            ['frutas' => 'SELECT @frutas', 'citricos' => 'SELECT @citricos'], self::CON_PERMISOS);

        $this->sinAvisos($pantalla);
        self::assertStringContainsString('<h5>Nivel 1</h5>', $pantalla['html']);
        self::assertStringContainsString('<h5>Nivel 2</h5>', $pantalla['html']);
        self::assertStringContainsString('id="Familia_' . $pantalla['consultas']['frutas'] . '"', $pantalla['html']);
        self::assertStringContainsString('id="Familia_' . $pantalla['consultas']['citricos'] . '"', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/OtrasVistas/ListadoProductosDeProveedor.php:337-354
     *
     * @group defecto
     * @group proveedor
     * @group bajo
     *
     * @que-ocurre-hoy Un artículo sin familia no lleva la clase de su estado: el filtro por estado no lo
     *   esconde, y la pantalla deja un aviso de PHP por la variable que no llega a calcularse.
     * @que-deberia-ocurrir Que todo artículo se filtre por su estado, tenga o no familia.
     * @por-que-ocurre El índice del estado se calcula dentro del bucle de las familias del artículo.
     * @como-deberia-funcionar Calcularlo fuera de ese bucle.
     */
    public function test_unArticuloSinFamiliaSeFiltraPorSuEstado(): void
    {
        $preparacion = array_merge(
            $this->proveedor('p', 'B70000001'),
            $this->articulo('principal', 'Tomate Prueba', 2, 21, '@p'),
            ["INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) VALUES (@principal, @p, 'TOM-1', 2.10, '2031-01-01', 'Activo')"],
        );

        $pantalla = $this->ejecutar('OtrasVistas/ListadoProductosDeProveedor.php', ['id' => '{@p}'], [], $preparacion, [], self::CON_PERMISOS);

        self::assertMatchesRegularExpression('/<tr id="fila_articulo_\d+" class="[^"]*Row_0/', $pantalla['html']);
    }

    /**
     * @comportamiento Sin sesión, quitar la relación de un artículo con el proveedor se rechaza por permiso;
     *   con el de modificar, se quita.
     */
    public function test_quitarLaRelacionDeUnArticuloExigeElPermiso(): void
    {
        $preparacion = array_merge(
            $this->proveedor('p', 'B70000001'),
            $this->articulo('a', 'Pimiento Prueba', 3, 21),
            ["INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) VALUES (@a, @p, 'PIM-1', 3.20, '2031-01-01', 'Activo')"],
        );
        $consulta = ['relaciones' => 'SELECT COUNT(*) FROM articulosProveedores WHERE idArticulo = @a AND idProveedor = @p'];
        $post = ['pulsado' => 'eliminarRefProveedorArticulo', 'idArticulo' => '{@a}', 'idProveedor' => '{@p}'];

        $sinSesion = $this->ejecutar('tareas.php', [], $post, $preparacion, $consulta, null);
        $conPermiso = $this->ejecutar('tareas.php', [], $post, $preparacion, $consulta, self::CON_PERMISOS);

        self::assertStringContainsString('No tienes permisos', json_decode(explode("\n", $sinSesion['html'])[0], true)['error']);
        self::assertSame('1', $sinSesion['consultas']['relaciones']);
        self::assertSame('0', $conPermiso['consultas']['relaciones']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/tareas.php:4,14-156
     * @codigo-afectado modulos/mod_proveedor/tareas.php:24,32
     *
     * @group defecto
     * @group proveedor
     * @group bajo
     *
     * @que-ocurre-hoy La petición de fondo deja avisos de PHP: sin orden, por la orden que no llega; y al
     *   abrir la ventana de incidencias, por un número de incidencia escrito con otro nombre.
     * @que-deberia-ocurrir Que responda sin avisos, y un error si la orden falta o no es conocida.
     * @por-que-ocurre Toma `pulsado` sin comprobarlo y sin rama por defecto, y declara `$numInicidencia`
     *   donde luego usa `$numIncidencia`.
     * @como-deberia-funcionar Comprobar la orden y usar un solo nombre para la variable.
     */
    public function test_laPeticionDeFondoRespondeSinAvisos(): void
    {
        $sinOrden = $this->ejecutar('tareas.php', [], ['otra' => 'cosa']);
        $ventana = $this->ejecutar('tareas.php', [], ['pulsado' => 'abririncidencia', 'dedonde' => 'proveedor', 'usuario' => '83', 'idReal' => '0', 'configuracion' => '']);

        $this->sinAvisos($sinOrden);
        $this->sinAvisos($ventana);
    }
}

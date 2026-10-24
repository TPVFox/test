<?php

/**
 * La ficha de un producto de `mod_productos` (`producto.php`, con `template/view_producto.php`): leer,
 * dar de alta y modificar el producto con su formulario (`tareas/reciboPostProducto.php`) y enviar a las
 * balanzas el precio cambiado de un producto al peso (`tareas/comunicarBalanza.php`).
 *
 * Cada ficha se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de pruebas
 * o sin ninguna, los permisos del módulo concedidos salvo los que el caso quite, y una transacción que se
 * deshace al terminar; lo que el guardado deja en la base se lee antes de deshacerla. La carpeta temporal
 * de la configuración, donde el envío escribe el fichero de cada balanza, se cambia por una propia del
 * caso, sin driver, que se borra al terminar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class FichaProductosIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    private string $idIva;

    private string $carpeta = '';

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->idIva = (string) $this->db->query('SELECT idIva FROM iva WHERE iva = 21 LIMIT 1')->fetch_assoc()['idIva'];
        $this->carpeta = sys_get_temp_dir() . '/tpvfox-ficha-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        $this->borrar($this->carpeta);
        parent::tearDown();
    }

    /** @comportamiento La ficha enseña el producto guardado: su nombre, su referencia, su estado, su IVA y sus precios. */
    public function test_laFichaEnsenaElProductoGuardado(): void
    {
        $ficha = $this->pintar(['id' => ':p1'], [], [...$this->producto('Kiwi'), $this->referencia('KIW-1')]);

        self::assertStringContainsString('name="articulo_name" size="50" required', $ficha['html']);
        self::assertStringContainsString('value="Kiwi"', $ficha['html']);
        self::assertStringContainsString('value="KIW-1"', $ficha['html']);
        self::assertStringContainsString('name="pvpCiva"', $ficha['html']);
        self::assertStringContainsString('value="2.42"', $ficha['html']);
        self::assertSame('', $ficha['errores']);
    }

    /** @comportamiento Guardar la ficha cambia el nombre y el precio, y apunta el cambio de precio con su usuario. */
    public function test_guardarCambiaElProductoYApuntaElPrecio(): void
    {
        $ficha = $this->pintar(['id' => ':p1'], $this->formulario(['articulo_name' => 'Kiwi gold', 'pvpCiva' => '3.63', 'pvpSiva' => '3.00']), [...$this->producto('Kiwi')], [
            'producto' => 'SELECT articulo_name FROM articulos WHERE idArticulo = :p1',
            'precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1 AND idTienda = :tienda',
            'historico' => 'SELECT Antes, Nuevo, idUsuario FROM historico_precios WHERE idArticulo = :p1',
        ]);

        self::assertSame('Kiwi gold', $ficha['leido']['producto'][0]['articulo_name']);
        self::assertSame('3.630000', $ficha['leido']['precio'][0]['pvpCiva']);
        self::assertCount(1, $ficha['leido']['historico']);
        self::assertSame((string) $this->idUsuario, (string) $ficha['leido']['historico'][0]['idUsuario']);
    }

    /** @comportamiento Guardar una ficha sin número da de alta el producto, con su precio, y la enseña. */
    public function test_guardarUnaFichaNuevaDaDeAltaElProducto(): void
    {
        $ficha = $this->pintar([], $this->formulario(['id' => '0', 'articulo_name' => 'Zqx nuevo']), [], [
            'producto' => "SELECT idArticulo FROM articulos WHERE articulo_name = 'Zqx nuevo'",
        ]);

        self::assertCount(1, $ficha['leido']['producto']);
        self::assertStringContainsString('Producto creado con ID ' . $ficha['leido']['producto'][0]['idArticulo'], $ficha['html']);
    }

    /** @comportamiento La ficha sin número es la de crear un producto, vacía y con el IVA predeterminado de la configuración. */
    public function test_laFichaNuevaSaleVaciaConElIvaPredeterminado(): void
    {
        $ficha = $this->pintar([]);

        self::assertStringContainsString('Productos: Crear', $ficha['html']);
        self::assertMatchesRegularExpression('#<option value="\d+"\s+selected>21\.00%</option>#', $ficha['html']);
        self::assertSame('', $ficha['errores']);
    }

    /** @comportamiento Un precio nuevo de un producto al peso se envía a la balanza en que tiene tecla. */
    public function test_unPrecioNuevoAlPesoSeEnviaALaBalanza(): void
    {
        $ficha = $this->pintar(['id' => ':p1'], $this->formulario(['cref_tienda_principal' => '123', 'tipo' => 'peso', 'pvpCiva' => '3.63', 'pvpSiva' => '3.00']), [
            ...$this->producto('Kiwi', 'peso'), $this->referencia('123'), ...$this->balanzaConTecla(7), $this->moduloDeBalanza(),
        ], [], true, true);

        self::assertCount(1, $ficha['balanzas']);
        self::assertStringContainsString('Fichero enviado a balanza', $ficha['html']);
    }

    /** @comportamiento Un producto al peso sin referencia numérica no se envía a la balanza, y la ficha lo dice. */
    public function test_alPesoSinReferenciaNumericaNoSeEnvia(): void
    {
        $ficha = $this->pintar(['id' => ':p1'], $this->formulario(['cref_tienda_principal' => 'KIW-1', 'tipo' => 'peso', 'pvpCiva' => '3.63', 'pvpSiva' => '3.00']), [
            ...$this->producto('Kiwi', 'peso'), $this->referencia('KIW-1'), ...$this->balanzaConTecla(7), $this->moduloDeBalanza(),
        ], [], true, true);

        self::assertSame([], $ficha['balanzas']);
        self::assertStringContainsString('La referencia principal debe ser numérica', $ficha['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/producto.php:29
     * @codigo-afectado modulos/mod_productos/tareas/reciboPostProducto.php:26
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin el permiso de modificar productos, guardar la ficha cambia el producto igual.
     * @que-deberia-ocurrir Que sin ese permiso no se guarde, y el botón no se ofrezca.
     * @por-que-ocurre La ficha guarda todo formulario que llega, sin consultar los permisos de guardar y de
     *   modificar que declara su módulo.
     * @como-deberia-funcionar Consultar el permiso antes de guardar y antes de pintar el botón.
     */
    public function test_sinElPermisoDeModificarNoSeGuarda(): void
    {
        $ficha = $this->pintar(['id' => ':p1'], $this->formulario(['articulo_name' => 'Kiwi gold']), [...$this->producto('Kiwi'), $this->quitarPermiso('Modificar')], [
            'producto' => 'SELECT articulo_name FROM articulos WHERE idArticulo = :p1',
        ]);

        self::assertSame('Kiwi', $ficha['leido']['producto'][0]['articulo_name']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/producto.php:29
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, un formulario que llega a la ficha cambia el producto.
     * @que-deberia-ocurrir Que sin sesión no se guarde, y la ficha pida el acceso.
     * @por-que-ocurre El guardado se hace antes de pintar la cabecera, que es la que pide la sesión.
     * @como-deberia-funcionar Comprobar la sesión antes de guardar.
     */
    public function test_sinSesionNoSeGuarda(): void
    {
        $ficha = $this->pintar(['id' => ':p1'], $this->formulario(['articulo_name' => 'Kiwi gold']), [...$this->producto('Kiwi')], [
            'producto' => 'SELECT articulo_name FROM articulos WHERE idArticulo = :p1',
        ], false);

        self::assertSame('Kiwi', $ficha['leido']['producto'][0]['articulo_name']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/template/view_producto.php:209
     * @codigo-afectado modulos/mod_productos/tareas/reciboPostProducto.php:59
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Sin el permiso de los códigos de barras, que solo los deja de lectura en la pantalla,
     *   un formulario con otro código lo guarda.
     * @que-deberia-ocurrir Que sin ese permiso los códigos no cambien.
     * @por-que-ocurre El permiso solo se aplica en el navegador; el guardado toma los códigos que llegan.
     * @como-deberia-funcionar Consultar el permiso al guardar los códigos.
     */
    public function test_sinElPermisoDeLosCodigosNoSeCambian(): void
    {
        $ficha = $this->pintar(['id' => ':p1'], $this->formulario(['codBarras_0' => '8400000000024']), [
            ...$this->producto('Kiwi'), $this->codigo('8400000000017'), $this->quitarPermiso('verCodBarras'),
        ], ['codigos' => 'SELECT codBarras FROM articulosCodigoBarras WHERE idArticulo = :p1']);

        self::assertSame(['8400000000017'], array_column($ficha['leido']['codigos'], 'codBarras'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:772
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:783
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Guardar en la ficha un precio de mil euros o más no lo cambia: la ficha enseña un
     *   error con la sentencia, y el histórico apunta el cambio.
     * @que-deberia-ocurrir Que se guarde el precio.
     * @por-que-ocurre Los precios se componen con el separador de miles, que la base no admite en un número.
     * @como-deberia-funcionar Componer los precios sin separador de miles.
     */
    public function test_unPrecioDeMilEurosOMasSeGuarda(): void
    {
        $ficha = $this->pintar(['id' => ':p1'], $this->formulario(['pvpCiva' => '1210.00', 'pvpSiva' => '1000.00']), [...$this->producto('Kiwi')], [
            'precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1 AND idTienda = :tienda',
        ]);

        self::assertSame('1210.000000', $ficha['leido']['precio'][0]['pvpCiva']);
    }

    /** @comportamiento Si el coste del último albarán difiere del guardado, la ficha lo enseña como coste, con su aviso. */
    public function test_elCosteDelUltimoAlbaranSeEnsenaConSuAviso(): void
    {
        $ficha = $this->pintar(['id' => ':p1'], [], [...$this->producto('Kiwi'), $this->proveedor('Frutas del Norte'), ...$this->compra(1.5)]);

        self::assertStringContainsString('Coste actualizado desde último albarán', $ficha['html']);
        self::assertMatchesRegularExpression('#name="ultimoCoste"[^>]*value="1\.50"#', $ficha['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:158
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:363
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy La ficha de un producto que no existe se pinta vacía, con avisos de PHP de su lectura.
     * @que-deberia-ocurrir Que diga que el producto no existe, sin avisos.
     * @por-que-ocurre La lectura del producto comprueba el estado sobre la respuesta vacía.
     * @como-deberia-funcionar Comprobar el estado solo de un producto leído, y avisar del que no existe.
     */
    public function test_unProductoQueNoExisteLoDice(): void
    {
        $ficha = $this->pintar(['id' => '999999']);

        self::assertSame('', $ficha['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/tareas/reciboPostProducto.php:69
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Guardar la ficha con dos proveedores nuevos los añade, pero la ficha lo dice una vez.
     * @que-deberia-ocurrir Que diga lo que pasó con cada proveedor.
     * @por-que-ocurre El mensaje de cada proveedor se guarda con la misma clave, y el siguiente reemplaza
     *   al anterior.
     * @como-deberia-funcionar Un mensaje por proveedor.
     */
    public function test_dosProveedoresNuevosDicenCadaUno(): void
    {
        $ficha = $this->pintar(['id' => ':p1'], $this->formulario([
            'idProveedor_:proveedor1' => ':proveedor1', 'prov_cref_:proveedor1' => 'N-1', 'prov_coste_:proveedor1' => '1.00',
            'idProveedor_:proveedor2' => ':proveedor2', 'prov_cref_:proveedor2' => 'S-1', 'prov_coste_:proveedor2' => '1.10',
        ]), [...$this->producto('Kiwi'), $this->proveedor('Frutas del Norte'), $this->proveedor('Frutas del Sur')]);

        self::assertSame(2, substr_count($ficha['html'], 'Proveedor añadido correctamente.'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/producto.php:149
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Con el módulo de la balanza desactivado, un precio nuevo de un producto al peso se
     *   envía igual a las balanzas de envío.
     * @que-deberia-ocurrir Que sin el módulo no se envíe a ninguna balanza.
     * @por-que-ocurre El envío solo mira si cambió el precio de un producto al peso, no el módulo.
     * @como-deberia-funcionar Consultar el módulo antes de enviar.
     */
    public function test_sinElModuloDeLaBalanzaNoSeEnvia(): void
    {
        $ficha = $this->pintar(['id' => ':p1'], $this->formulario(['cref_tienda_principal' => '123', 'tipo' => 'peso', 'pvpCiva' => '3.63', 'pvpSiva' => '3.00']), [
            ...$this->producto('Kiwi', 'peso'), $this->referencia('123'), ...$this->balanzaConTecla(7),
        ], [], true, true);

        self::assertSame([], $ficha['balanzas']);
    }

    /** @param array<string, string> $cambios @return array<string, string> El formulario de la ficha del primer producto sembrado. */
    private function formulario(array $cambios = []): array
    {
        return array_merge([
            'id' => ':p1', 'estado' => 'Activo', 'tipo' => 'unidad', 'cref_tienda_principal' => '', 'articulo_name' => 'Kiwi',
            'ultimoCoste' => '1.00', 'idIva' => $this->idIva, 'beneficio' => '10.00', 'pvpSiva' => '2.00', 'pvpCiva' => '2.42',
        ], $cambios);
    }

    /** @return list<string> */
    private function producto(string $nombre, string $tipo = 'unidad'): array
    {
        return [
            "INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, iva, beneficio, tipo) VALUES ('{$nombre}', 'Activo', '2026-01-01', 1, 21, 10, '{$tipo}')",
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 2.42, 2, :tienda)',
            'INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:pN, :tienda, 5, 0, 0)',
        ];
    }

    private function proveedor(string $nombre): string
    {
        return "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('{$nombre}', '{$nombre}', 'B00000000', 'Calle Mayor 1', '2026-01-01', 'activo')";
    }

    /** @return list<string> Un albarán de compra del primer proveedor sembrado, con una línea del último producto al coste dado. */
    private function compra(float $coste): array
    {
        return [
            "INSERT INTO albprot (Numalbpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva) VALUES (1, '2026-10-01 10:00:00', :tienda, :usuario, :proveedor1, 'Guardado', 0)",
            "INSERT INTO albprolinea (idalbpro, Numalbpro, idArticulo, ref_prov, nunidades, costeSiva) VALUES (:albpro1, 1, :pN, '', 1, {$coste})",
        ];
    }

    private function codigo(string $codigo): string
    {
        return "INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES (:pN, '{$codigo}')";
    }

    private function referencia(string $referencia): string
    {
        return "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, idVirtuemart, estado) VALUES (:pN, :tienda, '{$referencia}', 0, 'Activo')";
    }

    /** @return list<string> Una balanza a la que se envían los precios, con su tecla para el último producto sembrado. */
    private function balanzaConTecla(int $plu): array
    {
        return [
            "INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES ('Balanza de pruebas', 'Prueba', 'no', 0, 50, '127.0.0.1', 0)",
            "INSERT INTO modulo_balanza_plus (idBalanza, plu, seccion, idArticulo) VALUES (:b1, {$plu}, 0, :pN)",
        ];
    }

    private function moduloDeBalanza(): string
    {
        return "UPDATE permisos SET permiso = 1 WHERE idUsuario = :usuario AND modulo = 'mod_balanza' AND (vista IS NULL OR vista = '') AND (accion IS NULL OR accion = '')";
    }

    private function quitarPermiso(string $accion): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_productos' AND vista = 'producto.php' AND accion = '{$accion}'";
    }

    private function borrar(string $ruta): void
    {
        foreach (glob($ruta . '/*') ?: [] as $dentro) {
            is_dir($dentro) ? $this->borrar($dentro) : unlink($dentro);
        }
        if (is_dir($ruta)) {
            rmdir($ruta);
        }
    }

    /**
     * Pinta la ficha de `mod_productos` en un PHP aparte. Siembra la preparación, crea la carpeta de cada
     * balanza si el caso lo pide y, al terminar, lee las consultas indicadas y el fichero de cada balanza.
     *
     * @param array<string, string> $consulta
     * @param array<string, string> $formulario
     * @param list<string> $preparacion
     * @param array<string, string> $lecturas
     * @return array{html: string, errores: string, ids: array<string, string>, leido: array<string, list<array<string, mixed>>>, balanzas: array<string, string>}
     */
    private function pintar(array $consulta, array $formulario = [], array $preparacion = [], array $lecturas = [], bool $conSesion = true, bool $carpetasDeBalanza = false): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_productos/producto.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_productos', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $rutatmp = str_repeat("/..", substr_count(rtrim($RutaServidor, "/"), "/")) . ' . var_export($this->carpeta, true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_productos\'");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = [];'
            . ' $claves = ["articulos" => ":p", "modulo_balanza" => ":b", "proveedores" => ":proveedor", "albprot" => ":albpro"];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' if (preg_match("/^INSERT INTO (\\\\w+) /", $sql, $t) && isset($claves[$t[1]])) { $id = (string) $BDTpv->insert_id;'
            . ' $cuentas[$t[1]] = ($cuentas[$t[1]] ?? 0) + 1; $sembrados[$claves[$t[1]] . $cuentas[$t[1]]] = $id; if ($t[1] === "articulos") { $sembrados[":pN"] = $id; } } }'
            . ($carpetasDeBalanza ? ' foreach ($BDTpv->query("SELECT idBalanza, nombreBalanza FROM modulo_balanza") as $b) { @mkdir(' . var_export($this->carpeta, true) . ' . "/" . str_replace(" ", "", $b["nombreBalanza"]) . $b["idBalanza"]); }' : '')
            . ' $_GET = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($consulta, true) . ');'
            . ' $_POST = []; foreach (' . var_export($formulario, true) . ' as $clave => $valor) { $_POST[strtr((string) $clave, $sembrados)] = strtr((string) $valor, $sembrados); }'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use (&$sembrados, $BDTpv) {'
            . ' $leido = []; foreach (' . var_export($lecturas, true) . ' as $nombre => $sql) { $leido[$nombre] = $BDTpv->query(strtr($sql, $sembrados))->fetch_all(MYSQLI_ASSOC); }'
            . ' $balanzas = []; foreach (glob(' . var_export($this->carpeta, true) . ' . "/*/filetx") ?: [] as $f) { $balanzas[basename(dirname($f))] = file_get_contents($f); }'
            . ' echo "\n@@DATOS@@" . json_encode(["ids" => $sembrados, "leido" => $leido, "balanzas" => $balanzas]); });'
            . ' include "producto.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'ficha');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $datos = json_decode($datos, true) ?? [];
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores), 'ids' => $datos['ids'] ?? [],
            'leido' => $datos['leido'] ?? [], 'balanzas' => $datos['balanzas'] ?? []];
        unlink($errores);

        return $pintada;
    }
}

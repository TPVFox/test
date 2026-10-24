<?php

/**
 * La petición de fondo del módulo de la tienda web (`modulos/mod_virtuemart/tareas.php`, con
 * `clases/ClaseVirtuemart.php`): contar los productos, comparar los de la web con los del TPV, dar de
 * alta y modificar en el TPV con los datos de la web, el id en la web de un producto, y pedir a la web
 * las imágenes y los campos de peso.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna. Tras el arranque común se abre una transacción en su conexión, y las clases
 * escriben por esa misma conexión: lo que siembra el caso y lo que guarda la petición se deshace al
 * terminar el proceso. Antes de terminar, el proceso escribe los productos del caso con su relación con
 * la tienda web y su precio.
 *
 * El complemento de productos que carga el módulo lee la tienda web por su propia conexión: la tienda web
 * de pega de `web/` —en la dirección local, con la clave del caso— se graba de verdad y se borra al
 * terminar el caso.
 *
 * La preparación es una lista de sentencias; cada alta de un producto queda con un nombre —`:a1`,
 * `:a2`…—, que las sentencias siguientes y el formulario pueden usar, como `:tienda` y `:web`.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVirtuemart;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\Siembra;

final class TareasVirtuemartIntegracionTest extends CasoIntegracion
{
    protected bool $aislarPorTransaccion = false;

    private int $idUsuario;

    private int $idTienda;

    private int $web;

    private string $clave;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->clave = 'm' . bin2hex(random_bytes(8));
        $this->carpeta = __DIR__ . '/web/casos/' . $this->clave;
        mkdir($this->carpeta, 0755, true);
        $sentencia = $this->db->prepare("INSERT INTO tiendas (tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano, dominio, key_api) VALUES ('web', 'Tienda web de pruebas', 'X0000000X', '000000000', 'Activo', 'Web de pruebas', 'Sin direccion', '2026', ?, ?)");
        $dominio = (string) preg_replace('#TPVFox/?$#', 'test/Integration/PHP/mod_virtuemart/web', Entorno::valor('TPVFOX_URL'));
        $sentencia->bind_param('ss', $dominio, $this->clave);
        $sentencia->execute();
        $this->web = (int) $this->db->insert_id;
    }

    protected function tearDown(): void
    {
        $this->db->query('DELETE FROM articulosTiendas WHERE idTienda = ' . $this->web);
        $this->db->query('DELETE FROM tiendas WHERE idTienda = ' . $this->web);
        foreach (glob($this->carpeta . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        rmdir($this->carpeta);
        @rmdir(dirname($this->carpeta));
        parent::tearDown();
    }

    /** @comportamiento Se cuentan los productos del TPV, los que tienen relación con la tienda web y los que no la tienen. */
    public function test_contarLosProductosDelTpvYDeLaWeb(): void
    {
        $preparacion = [$this->producto('Pera de la web'), $this->relacionWeb(':a1', 31), $this->producto('Uva de la web'), "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES (:a2, :tienda, 'UVA', 'Activo')"];

        $todos = $this->ejecutar(['pulsado' => 'contarProductostpv'], $preparacion);
        $enWeb = $this->ejecutar(['pulsado' => 'nuevosEnWeb', 'tiendaWeb' => ':web'], $preparacion);
        $sinWeb = $this->ejecutar(['pulsado' => 'nuevosEnTpv', 'tiendaWeb' => ':web'], $preparacion);

        self::assertSame((string) $this->recuento('articulos', 2), $todos['respuesta']['productosTpv'][0]['cantTpv']);
        self::assertSame('1', $enWeb['respuesta']['productos'][0]['cantArticulo']);
        self::assertSame((string) $this->sinRelacionWeb(1), $sinWeb['respuesta']['productos'][0]['cantArticulo']);
    }

    /**
     * @comportamiento Comparar los productos de la web separa los nuevos —sin id o sin relación— de los
     *   modificados —con relación y algún dato distinto—, los pinta y los cuenta; los iguales no salen.
     */
    public function test_compararLosProductosDeLaWeb(): void
    {
        $preparacion = [$this->producto('Pera de la web'), $this->relacionWeb(':a1', 31), $this->producto('Uva de la web'), $this->relacionWeb(':a2', 32)];
        $productos = [
            $this->productoWeb(31, 'Pera de la web'),
            $this->productoWeb(32, 'Uva moscatel de la web'),
            $this->productoWeb(33, 'Kiwi de la web'),
            ['nombre' => 'Mango de la web', 'refTienda' => '', 'iva' => '21.00', 'precioSiva' => '2.000000', 'codBarra' => ''],
        ];

        $peticion = $this->ejecutar(['pulsado' => 'comprobarProductos', 'productos' => $productos, 'idTienda' => ':web', 'sel_codigoBarras' => '2', 'sel_referencia' => '3', 'prodNuevos' => '0', 'prodModif' => '0'], $preparacion);

        self::assertSame([2, 1], [$peticion['respuesta']['totalNuevos'], $peticion['respuesta']['totalModificados']]);
        self::assertSame(['Kiwi de la web', 'Mango de la web'], array_column($peticion['respuesta']['productosNuevos'], 'nombre'));
        self::assertSame('Uva moscatel de la web', $peticion['respuesta']['productosModificados'][0][0]['nombre']);
        self::assertStringContainsString('<tr id="nuevo_0">', $peticion['respuesta']['htmlNuevos']);
        self::assertStringContainsString('<tr id="mod_0">', $peticion['respuesta']['htmlMod']);
    }

    /**
     * @comportamiento Dar de alta en el TPV un producto de la web lo crea con su nombre, su IVA, su estado y
     *   sus códigos de barras, con su relación con la tienda web, y su precio con IVA calculado.
     */
    public function test_darDeAltaUnProductoDeLaWeb(): void
    {
        $peticion = $this->ejecutar($this->alta('2.00', '2'));

        $alta = $this->altas($peticion);
        self::assertSame(['Kiwi de la web', '21.00', 'Nuevo'], [$alta[0]['articulo_name'], $alta[0]['iva'], $alta[0]['estado']]);
        self::assertSame([[(string) $this->web, '33']], array_map(static fn (array $r): array => [$r['idTienda'], $r['idVirtuemart']], array_values(array_filter($peticion['relaciones'], fn (array $r): bool => $r['idTienda'] === (string) $this->web))));
        self::assertSame('2.42', number_format((float) $peticion['respuesta']['datos']['precioCiva'], 2));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_virtuemart/tareas.php:116-121
     *
     * @group defecto
     * @group calculo
     * @group medio
     *
     * @que-ocurre-hoy Con la opción de calcular el coste, el producto de la web se da de alta con el coste de
     *   la parte entera de su precio: 9,99 € con un 25 % de beneficio da 7,20 € en lugar de 7,99 €.
     * @que-deberia-ocurrir Que el coste salga del precio entero.
     * @por-que-ocurre El precio se convierte a entero antes de dividir.
     * @como-deberia-funcionar Convertirlo a número decimal.
     */
    public function test_elCosteCalculadoSaleDelPrecioConSusDecimales(): void
    {
        $peticion = $this->ejecutar($this->alta('9.99', '1'));

        self::assertSame('7.99', number_format((float) $this->altas($peticion)[0]['ultimoCoste'], 2));
    }

    /** @comportamiento Modificar en el TPV un producto con los datos de la web cambia su nombre, su IVA, su estado y su precio. */
    public function test_modificarUnProductoConLosDatosDeLaWeb(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'modificarProducto', 'nombre' => 'Pera conferencia de la web', 'refTienda' => '', 'iva' => '21.00', 'precioSiva' => '3.00', 'optEstado' => 'Activo', 'optCodBarra' => '2', 'codBarras' => '', 'id' => ':a1', 'optRef' => '3', 'tiendaWeb' => ':web'], [$this->producto('Pera de la web'), $this->precio(':a1')]);

        self::assertSame(['Pera conferencia de la web', 'Activo'], [$peticion['productos'][0]['articulo_name'], $peticion['productos'][0]['estado']]);
        self::assertSame(['3.000000', '3.630000'], [$peticion['precios'][0]['pvpSiva'], $peticion['precios'][0]['pvpCiva']]);
    }

    /** @comportamiento El id en la web de un producto sale de su relación con la tienda web. */
    public function test_elIdEnLaWebDeUnProducto(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'obtenerIdVirtuemart', 'idProductoTpv' => ':a1'], [$this->producto('Pera de la web'), $this->relacionWeb(':a1', 31)]);

        self::assertSame('31', $peticion['respuesta']['idVirtuemart']);
    }

    /**
     * @comportamiento Buscar las imágenes manda a la web las relaciones de un tramo con la familia de cada
     *   producto; añadir los campos de peso, el id en la web.
     */
    public function test_buscarImagenesYAnadirLosCamposDePeso(): void
    {
        $this->responde('buscarImagenesParaRelacionar', '{"Datos":{"encontradas":1}}');
        $preparacion = [$this->producto('Pera de la web'), $this->relacionWeb(':a1', 31), "INSERT INTO familias (familiaNombre, familiaPadre, mostrar_tpv) VALUES ('Frutas de la web', 0, 1)", 'INSERT INTO articulosFamilias (idArticulo, idFamilia) SELECT :a1, MAX(idFamilia) FROM familias'];

        $imagenes = $this->ejecutar(['pulsado' => 'obtenerProductosRelacionados', 'reg_inicial' => '0', 'reg_final' => '100'], $preparacion);
        $campos = $this->ejecutar(['pulsado' => 'anhadirCamposIdVirtuemart', 'idVirtuemart' => '31']);

        self::assertSame(1, $imagenes['respuesta']['imagenes']['Datos']['encontradas']);
        $enviadas = json_decode($this->peticiones()[0]['datos'], true);
        self::assertSame([$imagenes['ids'][':a1'], '31'], [$enviadas[0]['idArticulo'], $enviadas[0]['idVirtuemart']]);
        self::assertArrayHasKey('IdFamilia', $enviadas[0]);
        self::assertSame(['AnadirCamposPersonalizado', '31'], [$this->peticiones()[1]['action'], $this->peticiones()[1]['idVirtuemart']]);
        self::assertArrayHasKey('parametros', $campos['respuesta']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_virtuemart/clases/ClaseVirtuemart.php:51-68,109-133; modulos/mod_virtuemart/tareas.php:156-196
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Las respuestas de buscar las imágenes y de añadir los campos de peso llevan los
     *   parámetros de la llamada, con la clave de la API de la web, al navegador.
     * @que-deberia-ocurrir Que la clave de la API no salga del servidor.
     * @por-que-ocurre Los métodos añaden a la respuesta los parámetros que enviaron.
     * @como-deberia-funcionar Devolver solo la respuesta de la web.
     */
    public function test_lasRespuestasNoLlevanLaClaveDeLaApi(): void
    {
        $campos = $this->ejecutar(['pulsado' => 'anhadirCamposIdVirtuemart', 'idVirtuemart' => '31']);

        self::assertStringNotContainsString($this->clave, $campos['escrito']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_virtuemart/tareas.php:1-12
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, la petición del módulo da de alta en el TPV un producto con los datos que
     *   lleve el formulario.
     * @que-deberia-ocurrir Que sin sesión, y sin el permiso del módulo, no se atienda.
     * @por-que-ocurre La petición no mira la sesión ni los permisos.
     * @como-deberia-funcionar Comprobar la sesión y el permiso al empezar la petición.
     */
    public function test_sinSesionNoSeDaDeAltaUnProducto(): void
    {
        $peticion = $this->ejecutar($this->alta('2.00', '2'), [], false);

        self::assertSame([], $this->altas($peticion));
    }

    /** @return array<string, string> El alta de un producto de la web, «Kiwi de la web» al 21 %, con el coste calculado o no. */
    private function alta(string $precio, string $optCoste): array
    {
        return ['pulsado' => 'addProductoTpv', 'nombre' => 'Kiwi de la web', 'refTienda' => 'KIW-1', 'iva' => '21.00', 'precioSiva' => $precio, 'codBarras' => '8400000000031;8400000000048', 'optCodBarra' => '1', 'optRefWeb' => '1', 'optEstado' => 'Nuevo', 'beneficio' => '25', 'optCoste' => $optCoste, 'tiendaWeb' => ':web', 'id' => '33'];
    }

    /** @return list<array<string, string>> Los productos dados de alta en el caso. */
    private function altas(array $peticion): array
    {
        return array_values(array_filter($peticion['productos'], static fn (array $p): bool => $p['articulo_name'] === 'Kiwi de la web'));
    }

    private function producto(string $nombre): string
    {
        return "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (21.00, '{$nombre}', 'Activo', NOW(), 1, 'unidad')";
    }

    private function relacionWeb(string $producto, int $idVirtuemart): string
    {
        return "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, idVirtuemart, estado) VALUES ({$producto}, :web, '', {$idVirtuemart}, 'Publicado')";
    }

    private function precio(string $producto): string
    {
        return "INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES ({$producto}, 2.42, 2.00, :tienda)";
    }

    /** @return array<string, mixed> Un producto de la web como lo manda la página. */
    private function productoWeb(int $id, string $nombre): array
    {
        return ['id' => $id, 'nombre' => $nombre, 'refTienda' => '', 'iva' => '21.00', 'precioSiva' => '0.000000', 'codBarra' => ''];
    }

    private function recuento(string $tabla, int $mas): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM {$tabla}")->fetch_row()[0] + $mas;
    }

    private function sinRelacionWeb(int $mas): int
    {
        return (int) $this->db->query('SELECT COUNT(idArticulo) FROM articulosTiendas WHERE idTienda <> ' . $this->web . ' AND idArticulo NOT IN (SELECT idArticulo FROM articulosTiendas WHERE idTienda = ' . $this->web . ')')->fetch_row()[0] + $mas;
    }

    private function responde(string $accion, string $cuerpo): void
    {
        file_put_contents($this->carpeta . '/' . $accion . '.json', $cuerpo);
    }

    /** @return list<array<string, string>> */
    private function peticiones(): array
    {
        $lineas = is_file($this->carpeta . '/peticiones.jsonl') ? file($this->carpeta . '/peticiones.jsonl', FILE_IGNORE_NEW_LINES) : [];

        return array_map(static fn (string $linea): array => json_decode($linea, true), $lineas ?: []);
    }

    /**
     * Ejecuta la petición del módulo en un PHP aparte y devuelve su respuesta, lo que escribió, sus avisos,
     * los productos del caso, sus relaciones y sus precios, y los números de lo sembrado.
     *
     * @param array<string, mixed> $formulario
     * @param list<string> $preparacion
     * @return array<string, mixed>
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_virtuemart/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_virtuemart', true) . ');'
            . ' $_POST = ["pulsado" => ""]; require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $ids = [":tienda" => "' . $this->idTienda . '", ":web" => "' . $this->web . '"]; $cuenta = 0;'
            . ' $antes = (int) $BDTpv->query("SELECT MAX(idArticulo) FROM articulos")->fetch_row()[0];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids)); if (str_starts_with($sql, "INSERT INTO articulos ")) { $ids[":a" . ++$cuenta] = (string) $BDTpv->insert_id; } }'
            . ' $_POST = json_decode(strtr(' . var_export((string) json_encode($formulario), true) . ', $ids), true);'
            . ' register_shutdown_function(function () use ($BDTpv, &$ids, $antes) { echo "\n@@DATOS@@" . json_encode(["ids" => $ids,'
            . ' "productos" => $BDTpv->query("SELECT * FROM articulos WHERE idArticulo > " . $antes . " ORDER BY idArticulo")->fetch_all(MYSQLI_ASSOC),'
            . ' "relaciones" => $BDTpv->query("SELECT * FROM articulosTiendas WHERE idArticulo > " . $antes . " ORDER BY id")->fetch_all(MYSQLI_ASSOC),'
            . ' "precios" => $BDTpv->query("SELECT * FROM articulosPrecios WHERE idArticulo > " . $antes . " ORDER BY idArticulo")->fetch_all(MYSQLI_ASSOC)], JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'virtuemart');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'escrito' => $escrito, 'errores' => (string) file_get_contents($errores)] + ((json_decode($estado, true) ?? []) + ['ids' => [], 'productos' => [], 'relaciones' => [], 'precios' => []]);
        unlink($errores);

        return $ejecucion;
    }
}

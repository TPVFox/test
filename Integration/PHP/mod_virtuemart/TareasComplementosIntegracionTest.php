<?php

/**
 * La petición de fondo de los complementos de la tienda web (`plugins/mod_producto/virtuemart/tareas_virtuemart.php`
 * y `plugins/mod_familia/virtuemart/tareas_virtuemart.php`): modificar o añadir un producto en la web,
 * subir los seleccionados, la ventana y el envío del correo a un cliente, contar y leer productos de la
 * web, restar stock, y modificar, añadir o subir familias.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta de su complemento, con la sesión del
 * usuario de pruebas o sin ninguna. La petición y el complemento abren su propia conexión: lo que
 * necesitan —la tienda web de pega, los productos y las familias del caso— se graba de verdad antes de
 * ejecutarla, y se borra al terminar el caso con lo que la petición haya escrito. La tienda web es la de
 * pega de `web/`, en la dirección local, con la clave del caso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVirtuemart;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\Siembra;

final class TareasComplementosIntegracionTest extends CasoIntegracion
{
    protected bool $aislarPorTransaccion = false;

    private int $idUsuario;

    private int $idTienda;

    private string $clave;

    private string $carpeta;

    /** @var list<int> */
    private array $tiendas = [];

    /** @var list<int> */
    private array $articulos = [];

    /** @var list<int> */
    private array $familias = [];

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->clave = 't' . bin2hex(random_bytes(8));
        $this->carpeta = __DIR__ . '/web/casos/' . $this->clave;
        mkdir($this->carpeta, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->articulos as $id) {
            foreach (['articulosFamilias', 'articulosTiendas', 'articulosPrecios', 'articulosStocks', 'articulosCodigoBarras'] as $tabla) {
                $this->db->query("DELETE FROM {$tabla} WHERE idArticulo = {$id}");
            }
            $this->db->query("DELETE FROM articulos WHERE idArticulo = {$id}");
        }
        foreach ($this->familias as $id) {
            $this->db->query("DELETE FROM familiasTienda WHERE idFamilia = {$id}");
            $this->db->query("DELETE FROM familias WHERE idFamilia = {$id}");
        }
        foreach ($this->tiendas as $id) {
            $this->db->query("DELETE FROM familiasTienda WHERE idTienda = {$id}");
            $this->db->query("DELETE FROM articulosTiendas WHERE idTienda = {$id}");
            $this->db->query("DELETE FROM tiendas WHERE idTienda = {$id}");
        }
        foreach (glob($this->carpeta . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        rmdir($this->carpeta);
        @rmdir(dirname($this->carpeta));
        parent::tearDown();
    }

    /** @comportamiento Con más de una tienda web activa, el complemento lo dice y la petición se detiene. */
    public function test_conDosTiendasWebElComplementoSeDetiene(): void
    {
        $this->tiendaWeb();
        $this->tiendaWeb();

        $peticion = $this->ejecutar('mod_producto', ['pulsado' => 'contarProductosWeb']);

        self::assertStringContainsString('Error hay mas de una empresa tipo web', $peticion['salida']);
        self::assertSame([], $this->peticiones());
    }

    /** @comportamiento Un nombre de más de 180 caracteres no se envía a la web: la respuesta lo avisa. */
    public function test_unNombreDemasiadoLargoNoSeEnvia(): void
    {
        $this->tiendaWeb();

        $peticion = $this->ejecutar('mod_producto', ['pulsado' => 'modificarDatosWeb', 'datos' => (string) json_encode(['nombre' => str_repeat('a', 181)])]);

        self::assertStringContainsString('por que el nombre es superior a 180 caracteres', $peticion['respuesta']['htmlAlerta']);
        self::assertSame([], $this->peticiones());
    }

    /** @comportamiento Si el IVA que se va a poner en la web no es el del producto, no se envía nada: la respuesta lo dice. */
    public function test_unIvaDistintoDelDelProductoNoSeEnvia(): void
    {
        $web = $this->tiendaWeb();
        $pera = $this->articulo('Pera de la web');
        $this->responde('ObtenerProducto', $this->ivasWeb());

        $peticion = $this->ejecutar('mod_producto', ['pulsado' => 'modificarDatosWeb', 'datos' => $this->datosProducto($pera, $web, 31, '3')]);

        self::assertSame(' Error Iva no coinciden.', $peticion['respuesta']['error']);
        self::assertSame(['ObtenerProducto'], array_column($this->peticiones(), 'action'));
    }

    /** @comportamiento Modificar un producto relacionado envía sus datos a la web y cambia el estado de su relación. */
    public function test_modificarUnProductoEnLaWeb(): void
    {
        $web = $this->tiendaWeb();
        $pera = $this->articulo('Pera de la web');
        $this->relacion($pera, $web, 31, 'Publicado');
        $this->responde('ObtenerProducto', $this->ivasWeb());

        $peticion = $this->ejecutar('mod_producto', ['pulsado' => 'modificarDatosWeb', 'datos' => $this->datosProducto($pera, $web, 31, '4', 0)]);

        self::assertStringContainsString('Has modificados los datos del producto', $peticion['respuesta']['htmlAlerta']);
        self::assertSame(['ObtenerProducto', 'ModificarProducto'], array_column($this->peticiones(), 'action'));
        self::assertSame('Sin Publicar', $this->estadoRelacion($pera, $web));
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/mod_producto/virtuemart/tareas_virtuemart.php:92-137
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy Añadir un producto a la web guarda su relación con la tienda web sin estado: la
     *   variable del estado no existe en esa rama, y la relación queda vacía.
     * @que-deberia-ocurrir Que la relación quede con el estado del producto en la web.
     * @por-que-ocurre La rama de añadir usa `$estado`, que solo se define al subir los seleccionados.
     * @como-deberia-funcionar Tomar el estado de los datos enviados.
     */
    public function test_anadirUnProductoALaWebGuardaSuRelacionPublicada(): void
    {
        $web = $this->tiendaWeb();
        $pera = $this->articulo('Pera de la web');
        $this->familiaWeb($pera, $web, 12);
        $this->responde('ObtenerProducto', $this->ivasWeb());
        $this->responde('AddProducto', '{"Datos":{"idArticulo":77}}');

        $peticion = $this->ejecutar('mod_producto', ['pulsado' => 'modificarDatosWeb', 'datos' => $this->datosProducto($pera, $web, 0, '4')]);

        self::assertStringContainsString('Has añadido el producto a la web', $peticion['respuesta']['htmlAlerta']);
        self::assertSame('Publicado', $this->estadoRelacion($pera, $web));
    }

    /**
     * @comportamiento Subir los productos seleccionados añade a la web los que no tienen relación, con su
     *   IVA de la web y sus familias, y guarda la relación; los que ya la tienen y los de un IVA que la web
     *   no tiene se cuentan aparte.
     */
    public function test_subirLosProductosSeleccionados(): void
    {
        $web = $this->tiendaWeb();
        $nueva = $this->articulo('Pera nueva de la web');
        $this->familiaWeb($nueva, $web, 12);
        $subida = $this->articulo('Pera subida de la web');
        $this->relacion($subida, $web, 31, 'Publicado');
        $sinIva = $this->articulo('Vino de la web', '4.00');
        $this->responde('ObtenerProducto', $this->ivasWeb());
        $this->responde('AddProducto', '{"Datos":{"idArticulo":78}}');

        $peticion = $this->ejecutar('mod_producto', ['pulsado' => 'subirProductosWeb', 'idTiendaWeb' => (string) $web], true, [$nueva, $subida, $sinIva]);

        self::assertSame(1, $peticion['respuesta']['contadorProductos']);
        self::assertSame([['id' => (string) $subida, 'nombre' => 'Pera subida de la web']], $peticion['respuesta']['productoEnWeb']);
        self::assertSame([(string) $sinIva], $peticion['respuesta']['productosError']);
        self::assertSame('Publicado', $this->estadoRelacion($nueva, $web));
        $enviado = json_decode($this->peticiones()[1]['datos'], true);
        self::assertSame(['Pera nueva de la web', '4', [['idFamilia' => (string) $this->familias[0], 'idFamilia_tienda' => '12']]], [$enviado['nombre'], $enviado['iva'], $enviado['familias']]);
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/mod_producto/virtuemart/tareas_virtuemart.php:333-341
     *
     * @group defecto
     * @group errores
     * @group medio
     *
     * @que-ocurre-hoy Si la web responde un error al subir un producto seleccionado, la petición termina
     *   en un error de PHP, sin respuesta.
     * @que-deberia-ocurrir Que la respuesta diga qué producto no se subió y por qué.
     * @por-que-ocurre El mensaje usa `$datosProducto[idArticulo]`, una constante que no existe.
     * @como-deberia-funcionar Usar la clave entre comillas.
     */
    public function test_unErrorDeLaWebAlSubirUnProductoSeDice(): void
    {
        $web = $this->tiendaWeb();
        $nueva = $this->articulo('Pera nueva de la web');
        $this->familiaWeb($nueva, $web, 12);
        $this->responde('ObtenerProducto', $this->ivasWeb());
        $this->responde('AddProducto', '{"Datos":{"error":"Duplicado","consulta":"INSERT"}}');

        $peticion = $this->ejecutar('mod_producto', ['pulsado' => 'subirProductosWeb', 'idTiendaWeb' => (string) $web], true, [$nueva]);

        self::assertStringNotContainsString('Fatal error', $peticion['errores']);
        self::assertSame([(string) $nueva], $peticion['respuesta']['productosError'] ?? null);
    }

    /** @comportamiento Sin conexión con la web —sin sus IVA—, subir los seleccionados no sube nada y lo dice. */
    public function test_sinIvasDeLaWebNoSeSubeNada(): void
    {
        $web = $this->tiendaWeb();
        $nueva = $this->articulo('Pera nueva de la web');
        $this->responde('ObtenerProducto', '{"Datos":{"ivasWeb":{"items":[]}}}');

        $peticion = $this->ejecutar('mod_producto', ['pulsado' => 'subirProductosWeb', 'idTiendaWeb' => (string) $web], true, [$nueva]);

        self::assertSame('No se pudo obtener ivas, o fallo conexion', $peticion['respuesta']['errores']['mensaje']);
        self::assertSame(['ObtenerProducto'], array_column($this->peticiones(), 'action'));
    }

    /** @comportamiento La ventana de responder a un cliente lleva su nombre, el producto, la notificación, el correo y el asunto. */
    public function test_laVentanaDeResponderAUnCliente(): void
    {
        $this->tiendaWeb();

        $peticion = $this->ejecutar('mod_producto', ['pulsado' => 'mostrarModalNotificacion', 'datos' => ['nombreUsuario' => 'Ana', 'id' => '31', 'idNotificacion' => '7', 'numLinea' => '1', 'correo' => 'ana@example.com', 'nombreProducto' => 'Pera']]);

        self::assertStringContainsString('<h4>Enviar correo a :Ana</h4>', $peticion['respuesta']['html']);
        self::assertStringContainsString('id="email" name="email" value="ana@example.com"', $peticion['respuesta']['html']);
        self::assertStringContainsString('value="Contestacion a pregunta sobre Pera"', $peticion['respuesta']['html']);
    }

    /** @comportamiento Enviar el correo a un cliente lo pide a la web y, si lo envió, marca la notificación; si no, devuelve su mensaje. */
    public function test_enviarElCorreoAUnCliente(): void
    {
        $this->tiendaWeb();
        $this->responde('enviarCorreo', '{"Datos":{"mailer":true}}');
        $enviado = $this->ejecutar('mod_producto', ['pulsado' => 'enviarCorreoNotificacion', 'datos' => ['idProducto' => '31', 'email' => 'ana@example.com', 'numLinea' => '1']]);
        $this->responde('enviarCorreo', '{"Datos":{"mailer":false,"mensaje":"Sin servidor"}}');
        $noEnviado = $this->ejecutar('mod_producto', ['pulsado' => 'enviarCorreoNotificacion', 'datos' => ['idProducto' => '31', 'email' => 'ana@example.com', 'numLinea' => '1']]);

        self::assertSame([2, '1'], [$enviado['respuesta']['mail'], $enviado['respuesta']['numLinea']]);
        self::assertSame([1, 'Sin servidor'], [$noEnviado['respuesta']['mail'], $noEnviado['respuesta']['error']]);
        self::assertSame(['enviarCorreo', 'modificarNotificacion', 'enviarCorreo'], array_column($this->peticiones(), 'action'));
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/mod_producto/virtuemart/tareas_virtuemart.php:1-17; plugins/mod_familia/virtuemart/tareas_virtuemart.php:1-10
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, la petición del complemento envía un correo desde la tienda web a la
     *   dirección que diga el formulario, y modifica productos y familias en la web.
     * @que-deberia-ocurrir Que sin sesión, y sin el permiso de la acción, no se atienda.
     * @por-que-ocurre La petición no carga el arranque común ni mira la sesión ni los permisos.
     * @como-deberia-funcionar Comprobar la sesión y el permiso al empezar la petición.
     */
    public function test_sinSesionNoSeEnviaUnCorreo(): void
    {
        $this->tiendaWeb();
        $this->responde('enviarCorreo', '{"Datos":{"mailer":true}}');

        $this->ejecutar('mod_producto', ['pulsado' => 'enviarCorreoNotificacion', 'datos' => ['idProducto' => '31', 'email' => 'cualquiera@example.com', 'numLinea' => '1']], false);

        self::assertSame([], $this->peticiones());
    }

    /** @comportamiento Contar los productos de la web, leer un tramo y restar el stock de un ticket devuelven lo que responde la web. */
    public function test_contarLeerYRestarStockEnLaWeb(): void
    {
        $this->tiendaWeb();
        $this->responde('contarProductos', '{"Datos":{"item":{"productosWeb":120}}}');
        $this->responde('descontarStock', '{"Datos":{"error":""}}');

        $contar = $this->ejecutar('mod_producto', ['pulsado' => 'contarProductosWeb']);
        $tramo = $this->ejecutar('mod_producto', ['pulsado' => 'actualizarProductosWeb', 'inicio' => '0', 'final' => '100']);
        $restar = $this->ejecutar('mod_producto', ['pulsado' => 'RestarStock', 'productos' => [['idVirtuemart' => '31', 'nunidades' => '2']]]);

        self::assertSame(120, $contar['respuesta']['Datos']['item']['productosWeb']);
        self::assertSame([], $tramo['respuesta']['productos']);
        self::assertSame(['error' => ''], $restar['respuesta']['Datos']);
        self::assertSame(['contarProductos', 'productosInicioFinal', 'descontarStock'], array_column($this->peticiones(), 'action'));
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/mod_familia/virtuemart/tareas_virtuemart.php:1-10; modulos/mod_familia/clases/ClaseFamilias.php:18-20
     *
     * @group defecto
     * @group errores
     * @group alto
     *
     * @que-ocurre-hoy La petición del complemento de familias termina en un error de PHP antes de atender
     *   ninguna orden: añadir, modificar o subir familias a la web no funciona.
     * @que-deberia-ocurrir Que atienda sus órdenes y responda.
     * @por-que-ocurre La clase de familias incluye su `trait` con la ruta del arranque común, que esta
     *   petición no carga.
     * @como-deberia-funcionar Cargar el arranque común, o incluir el `trait` con la ruta de la clase.
     */
    public function test_laPeticionDelComplementoDeFamiliasAtiendeSusOrdenes(): void
    {
        $web = $this->tiendaWeb();
        $frutas = $this->familia('Frutas de la web');
        $this->responde('AddFamilia', '{"Datos":{"idFamilia":12}}');

        $peticion = $this->ejecutar('mod_familia', ['pulsado' => 'modificarFamiliaWeb', 'datos' => (string) json_encode(['idFamiliaPadreWeb' => '0', 'idFamiliaWeb' => '0', 'nombreFamilia' => 'Frutas de la web', 'idTienda' => (string) $web, 'idFamiliaTpv' => (string) $frutas])]);

        self::assertStringNotContainsString('Fatal error', $peticion['errores']);
        self::assertStringContainsString('Subida Familia a la web correctamente', $peticion['respuesta']['htmlAlerta'] ?? '');
    }

    /**
     * Ejecuta la petición de un complemento en un PHP aparte y devuelve su respuesta, lo que escribió y sus
     * avisos.
     *
     * @param array<string, mixed> $formulario
     * @param list<int> $seleccionados Los productos marcados en la sesión.
     * @return array{respuesta: mixed, salida: string, errores: string}
     */
    private function ejecutar(string $modulo, array $formulario, bool $conSesion = true, array $seleccionados = []): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas'],
            'productos_seleccionados' => array_map('strval', $seleccionados),
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/plugins/' . $modulo . '/virtuemart/tareas_virtuemart.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/plugins/' . $modulo . '/virtuemart', true) . ');'
            . ' $_POST = ' . var_export($formulario, true) . ';'
            . ' include "tareas_virtuemart.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'virtuemart');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        $escrito = implode("\n", $salida);
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'salida' => $escrito, 'errores' => (string) file_get_contents($errores)];
        unlink($errores);

        return $ejecucion;
    }

    /** Graba de verdad la tienda web de pega, con su dominio y la clave del caso. */
    private function tiendaWeb(): int
    {
        $sentencia = $this->db->prepare("INSERT INTO tiendas (tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano, dominio, key_api) VALUES ('web', 'Tienda web de pruebas', 'X0000000X', '000000000', 'Activo', 'Web de pruebas', 'Sin direccion', '2026', ?, ?)");
        $dominio = (string) preg_replace('#TPVFox/?$#', 'test/Integration/PHP/mod_virtuemart/web', Entorno::valor('TPVFOX_URL'));
        $sentencia->bind_param('ss', $dominio, $this->clave);
        $sentencia->execute();
        $this->tiendas[] = (int) $this->db->insert_id;

        return (int) $this->db->insert_id;
    }

    /** Graba de verdad un producto con su precio, su referencia y su stock en la tienda principal; el IVA de la base de pruebas es el 21 %. */
    private function articulo(string $nombre, string $iva = '21.00'): int
    {
        $this->db->query("INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES ({$iva}, '{$nombre}', 'Activo', NOW(), 1, 'unidad')");
        $id = (int) $this->db->insert_id;
        $this->articulos[] = $id;
        $this->db->query("INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES ({$id}, 2.20, 2.00, {$this->idTienda})");
        $this->db->query("INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES ({$id}, {$this->idTienda}, 'REF-{$id}', 'Activo')");
        $this->db->query("INSERT INTO articulosStocks (idArticulo, idTienda, stockMin, stockMax, stockOn) VALUES ({$id}, {$this->idTienda}, 1, 10, 5)");

        return $id;
    }

    private function relacion(int $articulo, int $web, int $idVirtuemart, string $estado): void
    {
        $this->db->query("INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, idVirtuemart, estado) VALUES ({$articulo}, {$web}, '', {$idVirtuemart}, '{$estado}')");
    }

    /** Graba de verdad una familia del producto con su relación con la tienda web. */
    private function familiaWeb(int $articulo, int $web, int $idWeb): void
    {
        $familia = $this->familia('Frutas de la web');
        $this->db->query("INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES ({$articulo}, {$familia})");
        $this->db->query("INSERT INTO familiasTienda (idFamilia, idTienda, idFamilia_tienda) VALUES ({$familia}, {$web}, {$idWeb})");
    }

    private function familia(string $nombre, int $padre = 0): int
    {
        $this->db->query("INSERT INTO familias (familiaNombre, familiaPadre, mostrar_tpv) VALUES ('{$nombre}', {$padre}, 1)");
        $this->familias[] = (int) $this->db->insert_id;

        return (int) $this->db->insert_id;
    }

    private function estadoRelacion(int $articulo, int $web): ?string
    {
        $fila = $this->db->query("SELECT estado FROM articulosTiendas WHERE idArticulo = {$articulo} AND idTienda = {$web}")->fetch_row();

        return $fila === null ? null : (string) $fila[0];
    }

    /** Los datos que la ficha del producto manda a la petición. */
    private function datosProducto(int $articulo, int $web, int $idVirtuemart, string $ivaWeb, int $estado = 1): string
    {
        return (string) json_encode(['nombre' => 'Pera de la web', 'referencia' => 'PER-1', 'codBarras' => '', 'precioSiva' => '2.00', 'iva' => $ivaWeb, 'id' => $idVirtuemart, 'alias' => '', 'idProducto' => $articulo, 'idTiendaWeb' => $web, 'estado' => $estado, 'stock' => 4]);
    }

    private function ivasWeb(): string
    {
        return '{"Datos":{"ivasWeb":{"items":[{"virtuemart_calc_id":"3","calc_value":"10.0000"},{"virtuemart_calc_id":"4","calc_value":"21.0000"}]}}}';
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
}

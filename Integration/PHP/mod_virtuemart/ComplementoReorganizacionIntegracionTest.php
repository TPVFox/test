<?php

/**
 * El complemento de la tienda web de la reorganización (`plugins/mod_reorganizacion/virtuemart/ClaseVirtuemart.php`):
 * la tienda web, el envío del stock y el precio, contar y leer productos de la web, el HTML de un
 * producto en la web y sus notificaciones de clientes.
 *
 * Como en `ComplementoProductosIntegracionTest`: la tienda web es la de pega de `web/`, en la dirección
 * local; la clase se crea sin su constructor, con la conexión del caso, y toma la tienda web como lo hace
 * el constructor. Declara una clase con el mismo nombre que la del complemento de productos: cada caso
 * corre en su propio proceso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVirtuemart;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Entorno;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ComplementoReorganizacionIntegracionTest extends CasoIntegracion
{
    private string $clave;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['DOCUMENT_ROOT'] = '';
        $this->incluirTPVFox('/clases/ClaseConexion.php');
        $this->incluirTPVFox('/plugins/mod_reorganizacion/virtuemart/ClaseVirtuemart.php');
        $this->clave = 'r' . bin2hex(random_bytes(8));
        $this->carpeta = __DIR__ . '/web/casos/' . $this->clave;
        mkdir($this->carpeta, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        rmdir($this->carpeta);
        @rmdir(dirname($this->carpeta));
        parent::tearDown();
    }

    /** @comportamiento Sin tienda web, la lectura lo dice; con una, la trae; el id de la web de un producto sale de su relación con ella. */
    public function test_laTiendaWebYElIdDeLaWebDeUnProducto(): void
    {
        $sin = $this->complemento()->ObtenerTiendasWeb();
        $id = (string) $this->tiendaWeb();
        $complemento = $this->complemento();

        self::assertSame('No hay tienda on-line', $sin['error']);
        self::assertSame($this->clave, $complemento->key_api);
        self::assertSame('31', $complemento->obtenerIdVirtuemart([['idTienda' => $id, 'idVirtuemart' => '31']]));
    }

    /** @comportamiento Enviar el stock y el precio manda los datos a la web; contar los productos, solo la acción. */
    public function test_enviarElStockYElPrecio(): void
    {
        $this->tiendaWeb();
        $this->responde('cambiarStockYPrecios', '{"Datos":{"cambiados":2}}');
        $complemento = $this->complemento();

        $respuesta = $complemento->enviarStockYPrecio('[{"idVirtuemart":31,"stock":4,"precio":"2.00"}]');
        $complemento->contarProductos();

        self::assertSame(2, $respuesta['Datos']['cambiados']);
        self::assertSame([['cambiarStockYPrecios', '[{"idVirtuemart":31,"stock":4,"precio":"2.00"}]'], ['contarProductos', null]], array_map(static fn (array $p): array => [$p['action'], $p['datos'] ?? null], $this->peticiones()));
    }

    /** @comportamiento Los datos de un producto y los de un tramo se piden a la web. */
    public function test_losDatosDeUnProductoYDeUnTramo(): void
    {
        $this->tiendaWeb();
        $this->responde('ObtenerProducto', '{"Datos":{"datosProducto":{"item":{"idVirtual":31}}}}');
        $complemento = $this->complemento();

        $producto = $complemento->ObtenerDatosDeProducto(31);
        $complemento->productosInicioFinal(0, 100);

        self::assertSame(31, $producto['Datos']['datosProducto']['item']['idVirtual']);
        self::assertSame(['ObtenerProducto', 'productosInicioFinal'], array_column($this->peticiones(), 'action'));
    }

    /**
     * @comportamiento Las notificaciones de un producto se piden a la web y se pintan en una tabla; sin
     *   ninguna, se dice; con un error de la web, se enseña.
     */
    public function test_lasNotificacionesDeUnProducto(): void
    {
        $this->tiendaWeb();
        $this->responde('ObtenerNotificacionesProducto', (string) json_encode(['Datos' => ['items' => [['nombreUsuario' => 'Ana', 'email' => 'ana@example.com', 'idNotificacion' => '7', 'cant' => '2']]]]));
        $con = $this->complemento()->htmlNotificacionesProducto(31)['html'];
        $this->responde('ObtenerNotificacionesProducto', '{"Datos":{"items":[]}}');
        $sin = $this->complemento()->htmlNotificacionesProducto(31)['html'];
        $this->responde('ObtenerNotificacionesProducto', '{"Datos":{"error":"Tabla"}}');
        $error = $this->complemento()->htmlNotificacionesProducto(31)['html'];

        self::assertStringContainsString('<td id="nombre_1">Ana</td>', $con);
        self::assertSame('<div class="alert alert-info">Este producto no tiene notificaciones de Clientes</div>', $sin);
        self::assertSame('<div class="alert alert-danger">Error de SQL: Tabla</div>', $error);
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/mod_reorganizacion/virtuemart/ClaseVirtuemart.php:350-399
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El nombre que un cliente escribe en la web se pinta sin escapar en la tabla de
     *   notificaciones de este complemento, igual que en el de productos.
     * @que-deberia-ocurrir Que se pinte como texto.
     * @por-que-ocurre La tabla concatena los datos de la notificación tal como llegan.
     * @como-deberia-funcionar Escapar cada dato al pintarlo.
     */
    public function test_losDatosDeLosClientesDeLaWebSePintanComoTexto(): void
    {
        $this->tiendaWeb();
        $this->responde('ObtenerNotificacionesProducto', (string) json_encode(['Datos' => ['items' => [['nombreUsuario' => '<script>alert(1)</script>', 'email' => 'ana@example.com', 'idNotificacion' => '7', 'cant' => '2']]]]));

        $html = $this->complemento()->htmlNotificacionesProducto(31)['html'];

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /**
     * @comportamiento El JavaScript y la ruta de este complemento son los del complemento de productos: sus
     *   llamadas van a la petición de aquel. El producto en la web se pinta como allí.
     */
    public function test_elJavascriptYLaRutaSonLosDelComplementoDeProductos(): void
    {
        $this->tiendaWeb();
        $complemento = $this->complemento();

        $html = $complemento->htmlDatosProductoSeleccionado(['idVirtual' => 31, 'estado' => 1, 'articulo_name' => 'Pera', 'refTienda' => 'PER-1', 'codBarra' => '', 'precioSiva' => '2', 'idIva' => '3', 'alias' => '', 'iva' => '10'], [['virtuemart_calc_id' => '3', 'calc_value' => '10.0000']], 5, 9, '10.00')['html'];

        self::assertStringEndsWith('/plugins/mod_producto/virtuemart/', $complemento->getRutaPlugin());
        self::assertStringContainsString('/plugins/mod_producto/virtuemart/func_plg_virtuemart.js', $complemento->htmlJava());
        self::assertStringContainsString('value="Modificar en Web"', $html);
        self::assertSame('<option value="3"  selected>10.00%</option>', $complemento->htmlOptionIvasWeb([['virtuemart_calc_id' => '3', 'calc_value' => '10.0000']], '3'));
    }

    /** @comportamiento Marcar una notificación la manda a la web, y un error de conexión se explica. */
    public function test_marcarUnaNotificacionYElErrorDeConexion(): void
    {
        $this->tiendaWeb();
        $complemento = $this->complemento();

        $complemento->modificarNotificacion(31, 'ana@example.com');

        self::assertSame(['modificarNotificacion', '31', 'ana@example.com'], [$this->peticiones()[0]['action'], $this->peticiones()[0]['idProducto'], $this->peticiones()[0]['email']]);
        self::assertSame('Sin conexión', $complemento->error_string(['error_conexion' => 'Sin conexión']));
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/mod_reorganizacion/virtuemart/ClaseVirtuemart.php:446-473
     *
     * @group defecto
     * @group errores
     * @group medio
     *
     * @que-ocurre-hoy El error que responde la web y la falta de IVA en ella no se explican: el método solo
     *   reconoce el error de conexión.
     * @que-deberia-ocurrir Que los explique, como el complemento de productos.
     * @por-que-ocurre Las dos comprobaciones leen una variable que no existe en el método, en lugar de lo
     *   que recibe.
     * @como-deberia-funcionar Comprobar lo que recibe.
     */
    public function test_elErrorDeLaWebYLaFaltaDeIvasSeExplican(): void
    {
        $this->tiendaWeb();
        $complemento = $this->complemento();

        self::assertSame(['Clave', ' No hay ivas en la web'], [$complemento->error_string(['error' => 'Clave']), $complemento->error_string(['Datos' => ['ivasWeb' => ['items' => []]]])]);
    }

    /** El complemento, creado sin su constructor con la conexión del caso, y la tienda web tomada como lo hace el constructor. */
    private function complemento(): \PluginClaseVirtuemart
    {
        $complemento = (new \ReflectionClass(\PluginClaseVirtuemart::class))->newInstanceWithoutConstructor();
        $complemento->ruta_proyecto = RUTA_TPVFOX;
        $complemento->conexion = $this->db;
        $complemento->obtenerRutaProyecto();
        $tiendas = $complemento->ObtenerTiendasWeb();
        if (isset($tiendas['items'][0])) {
            $complemento->TiendaWeb = $tiendas['items'][0];
            $complemento->key_api = $tiendas['items'][0]['key_api'];
            $complemento->ruta_web = $tiendas['items'][0]['dominio'] . '/administrator/apisv/tareas.php';
        }

        return $complemento;
    }

    private function tiendaWeb(): int
    {
        $sentencia = $this->db->prepare("INSERT INTO tiendas (tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano, dominio, key_api) VALUES ('web', 'Tienda web de pruebas', 'X0000000X', '000000000', 'Activo', 'Web de pruebas', 'Sin direccion', '2026', ?, ?)");
        $dominio = (string) preg_replace('#TPVFox/?$#', 'test/Integration/PHP/mod_virtuemart/web', Entorno::valor('TPVFOX_URL'));
        $sentencia->bind_param('ss', $dominio, $this->clave);
        $sentencia->execute();

        return (int) $this->db->insert_id;
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

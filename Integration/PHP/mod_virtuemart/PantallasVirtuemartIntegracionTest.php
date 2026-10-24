<?php

/**
 * Las pantallas del módulo de la tienda web: las acciones (`modulos/mod_virtuemart/Acciones_virtuemart.php`),
 * la importación (`Importar_virtuemart.php`), relacionar las imágenes (`Relacionar_imagenes_virtuemart.php`)
 * y los campos de peso (`Subir_campospersonalizado_peso_virtuemart.php`).
 *
 * Cada una se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de pruebas
 * o sin ninguna. Tras el arranque común se abre una transacción en su conexión, donde el caso siembra o
 * cambia sus productos; todo se deshace al terminar el proceso. El complemento de productos lee la tienda
 * web por su propia conexión: la tienda web de pega de `web/`, cuando el caso la necesita, se graba de
 * verdad y se borra al terminar el caso. Ninguna pantalla llama a la web al pintarse.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVirtuemart;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\Siembra;

final class PantallasVirtuemartIntegracionTest extends CasoIntegracion
{
    protected bool $aislarPorTransaccion = false;

    private int $idUsuario;

    private int $idTienda;

    /** @var list<int> */
    private array $tiendas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
    }

    protected function tearDown(): void
    {
        foreach ($this->tiendas as $id) {
            $this->db->query('DELETE FROM tiendas WHERE idTienda = ' . $id);
        }
        parent::tearDown();
    }

    /** @comportamiento Las acciones ofrecen la importación, relacionar las imágenes y los campos de peso; sin sesión, no se pintan. */
    public function test_lasAccionesOfrecenSusTresProcesos(): void
    {
        $con = $this->ejecutar('Acciones_virtuemart.php');
        $sin = $this->ejecutar('Acciones_virtuemart.php', [], false);

        foreach (['href="Importar_virtuemart.php"', 'href="Relacionar_imagenes_virtuemart.php"', 'href="Subir_campospersonalizado_peso_virtuemart.php"'] as $enlace) {
            self::assertStringContainsString($enlace, $con['html']);
            self::assertStringNotContainsString($enlace, $sin['html']);
        }
    }

    /**
     * @comportamiento La importación ofrece las tiendas web y las opciones de la configuración —código de
     *   barras, referencia y coste—, con las de partida marcadas, el beneficio y los estados de partida, y
     *   carga el JavaScript del complemento si hay tienda web.
     */
    public function test_laImportacionOfreceLasTiendasWebYLasOpciones(): void
    {
        $web = $this->tiendaWeb();

        $pantalla = $this->ejecutar('Importar_virtuemart.php');

        self::assertStringContainsString('<option value="' . $web . '">Tienda web de pruebas</option>', $pantalla['html']);
        self::assertStringContainsString('<option value="2" title="Ignorar codbarras de la web tanto al añadir, modificar y comparar" selected>Ignorar codbarras</option>', $pantalla['html']);
        self::assertStringContainsString('<option value="3" title="Ignorar referencia , tanto a la hora añadir, modificar como de comparar" selected>Ignorar</option>', $pantalla['html']);
        self::assertStringContainsString('<input type="text" id="beneficio" value="25 " size="5px">', $pantalla['html']);
        self::assertStringContainsString('<option value="Nuevo" selected>Nuevo</option>', $pantalla['html']);
        self::assertStringContainsString('/plugins/mod_producto/virtuemart/func_plg_virtuemart.js', $pantalla['html']);
    }

    /** @comportamiento Relacionar las imágenes enseña cuántos productos tienen relación con la tienda web y ofrece buscarlas. */
    public function test_relacionarLasImagenesCuentaLosProductosDeLaWeb(): void
    {
        $this->tiendaWeb();

        $pantalla = $this->ejecutar('Relacionar_imagenes_virtuemart.php', ["INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (21.00, 'Pera de la web', 'Activo', NOW(), 1, 'unidad')", "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, idVirtuemart, estado) SELECT MAX(idArticulo), :web, '', 31, 'Publicado' FROM articulos"]);

        self::assertStringContainsString('var totalReferenciasWeb = 1;', $pantalla['html']);
        self::assertStringContainsString('onclick="BuscarImagenes_producto()"', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_virtuemart/Relacionar_imagenes_virtuemart.php:14-25,57-68; modulos/mod_virtuemart/Subir_campospersonalizado_peso_virtuemart.php:21-34,65-76
     *
     * @group defecto
     * @group errores
     * @group bajo
     *
     * @que-ocurre-hoy Sin tienda web, la pantalla dice que hay más de una, con avisos de PHP.
     * @que-deberia-ocurrir Que diga que no hay tienda web.
     * @por-que-ocurre El aviso se da cuando no se cargó la tienda web, sea cual sea el motivo, y enseña una
     *   variable que no existe.
     * @como-deberia-funcionar Distinguir la falta de tienda web de que haya varias.
     */
    public function test_sinTiendaWebLaPantallaLoDice(): void
    {
        $pantalla = $this->ejecutar('Relacionar_imagenes_virtuemart.php');

        self::assertStringNotContainsString('Hay mas de una empresa web', $pantalla['html']);
        self::assertStringNotContainsString('Warning', $pantalla['errores']);
    }

    /** @comportamiento Los campos de peso enseñan cuántos productos de peso hay y pasan sus números a la página. */
    public function test_losCamposDePesoCuentanLosProductosDePeso(): void
    {
        $this->tiendaWeb();

        $pantalla = $this->ejecutar('Subir_campospersonalizado_peso_virtuemart.php', ["UPDATE articulos SET tipo = 'unidad' WHERE tipo = 'peso'", "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (21.00, 'Jamón al corte de la web', 'Activo', NOW(), 1, 'peso')"]);

        self::assertStringContainsString('var totaProductoPeso = 1;', $pantalla['html']);
        self::assertMatchesRegularExpression('/var Ids = \["\d+"\];/', $pantalla['html']);
        self::assertStringContainsString('onclick="AnhadirCamposPersonalidosIdPeso()"', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_virtuemart/Subir_campospersonalizado_peso_virtuemart.php:25,44,83; modulos/mod_virtuemart/clases/ClaseVirtuemart.php:69-94
     *
     * @group defecto
     * @group errores
     * @group medio
     *
     * @que-ocurre-hoy Sin productos de peso, la pantalla de los campos de peso termina en un error de PHP.
     * @que-deberia-ocurrir Que diga que no hay productos de peso.
     * @por-que-ocurre Sin productos, la respuesta no trae la lista, y la pantalla la cuenta.
     * @como-deberia-funcionar Tomar la falta de productos como lista vacía, o enseñar el aviso.
     */
    public function test_sinProductosDePesoLaPantallaLoDice(): void
    {
        $this->tiendaWeb();

        $pantalla = $this->ejecutar('Subir_campospersonalizado_peso_virtuemart.php', ["UPDATE articulos SET tipo = 'unidad' WHERE tipo = 'peso'"]);

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
        self::assertStringContainsString('var totaProductoPeso = 0;', $pantalla['html']);
    }

    /** Graba de verdad la tienda web de pega; las pantallas no la llaman. */
    private function tiendaWeb(): int
    {
        $dominio = (string) preg_replace('#TPVFox/?$#', 'test/Integration/PHP/mod_virtuemart/web', Entorno::valor('TPVFOX_URL'));
        $this->db->query("INSERT INTO tiendas (tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano, dominio, key_api) VALUES ('web', 'Tienda web de pruebas', 'X0000000X', '000000000', 'Activo', 'Web de pruebas', 'Sin direccion', '2026', '{$dominio}', 'sinclavedepruebas')");
        $this->tiendas[] = (int) $this->db->insert_id;

        return (int) $this->db->insert_id;
    }

    /**
     * Ejecuta una pantalla en un PHP aparte y devuelve su HTML y sus avisos.
     *
     * @param list<string> $preparacion
     * @return array{html: string, errores: string}
     */
    private function ejecutar(string $fichero, array $preparacion = [], bool $conSesion = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas'],
        ] : [];
        $web = (string) (end($this->tiendas) ?: 0);
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["DOCUMENT_ROOT"] = "";'
            . ' $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_virtuemart/{$fichero}", true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_virtuemart', true) . ');'
            . ' require ' . var_export(RUTA_TPVFOX . '/inicial.php', true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, [":web" => ' . var_export($web, true) . '])); }'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'virtuemart');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        $pantalla = ['html' => implode("\n", $salida), 'errores' => (string) file_get_contents($errores)];
        unlink($errores);

        return $pantalla;
    }
}

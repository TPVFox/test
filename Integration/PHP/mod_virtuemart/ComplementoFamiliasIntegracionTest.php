<?php

/**
 * El complemento de la tienda web de las familias (`plugins/mod_familia/virtuemart/ClaseVirtuemartFamilia.php`):
 * la tienda web, el enlace y el HTML de una familia en la web, y las llamadas a la API de la web —leer,
 * añadir y modificar una familia, y leer todas—.
 *
 * Como en `ComplementoProductosIntegracionTest`: la tienda web es la de pega de `web/`, en la dirección
 * local; la clase se crea sin su constructor, con la conexión del caso, y toma la tienda web como lo hace
 * el constructor.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVirtuemart;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Entorno;

final class ComplementoFamiliasIntegracionTest extends CasoIntegracion
{
    private string $clave;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['DOCUMENT_ROOT'] = '';
        $this->incluirTPVFox('/clases/ClaseConexion.php');
        $this->incluirTPVFox('/plugins/mod_familia/virtuemart/ClaseVirtuemartFamilia.php');
        $this->clave = 'f' . bin2hex(random_bytes(8));
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

    /** @comportamiento Sin tienda web, la lectura lo dice; con una, la trae con su dominio y su clave. */
    public function test_laTiendaWebSeLee(): void
    {
        $sin = $this->complemento()->ObtenerTiendasWeb();
        $id = (string) $this->tiendaWeb();
        $complemento = $this->complemento();

        self::assertSame('No hay tienda on-line', $sin['error']);
        self::assertSame([$id, $this->clave], [$complemento->getTiendaWeb()['idTienda'], $complemento->key_api]);
        self::assertStringEndsWith('/plugins/mod_familia/virtuemart/', $complemento->getRutaPlugin());
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/mod_familia/virtuemart/ClaseVirtuemartFamilia.php:33,84-89
     *
     * @group defecto
     * @group presentacion
     * @group bajo
     *
     * @que-ocurre-hoy El enlace a la familia en la web pega el dominio con «index.php» sin la barra: lleva
     *   a una dirección que no existe.
     * @que-deberia-ocurrir Que el enlace sea el de la categoría en la web.
     * @por-que-ocurre La ruta de la categoría se arma sin la barra, a diferencia de la del producto.
     * @como-deberia-funcionar Poner la barra entre el dominio y la ruta.
     */
    public function test_elEnlaceALaFamiliaEnLaWeb(): void
    {
        $this->tiendaWeb();

        $html = $this->complemento()->btnLinkCategoria(12);

        self::assertStringContainsString('href="' . $this->dominio() . '/index.php?option=com_virtuemart&view=category&virtuemart_category_id=12"', $html);
    }

    /** @comportamiento Una familia con relación se pinta para modificarla en la web, con su id y su nombre en la web; una sin ella, para añadirla con su nombre del TPV. */
    public function test_laFamiliaEnLaWebSePinta(): void
    {
        $id = $this->tiendaWeb();
        $complemento = $this->complemento();

        $editar = $complemento->htmlDatosFamiliaWeb(['accion' => 'edit', 'idFamilia' => 5, 'idFamilia_tienda' => '12', 'nombre_familia_tienda' => 'Frutas web', 'id_padre_web' => '0', 'nombre_padre_web' => '']);
        $anadir = $complemento->htmlDatosFamiliaWeb(['accion' => 'add', 'idFamilia' => 5, 'idFamilia_tienda' => '', 'nombre' => 'Frutas', 'id_padre_web' => '0', 'nombre_padre_web' => '']);

        self::assertStringContainsString('class="btn btn-primary" id="botonWeb" type="button"' . "\n" . '                            value="Modificar familia en Web" name="modifWeb" onclick="modificarFamiliaWeb(5, ' . $id . ')">', $editar);
        self::assertStringContainsString('id="idFamiliaweb" value="12"', $editar);
        self::assertStringContainsString('id="nombreFamilia" value="Frutas web"', $editar);
        self::assertStringContainsString('value="Añadir familia en Web"', $anadir);
        self::assertStringContainsString('id="nombreFamilia" value="Frutas"', $anadir);
    }

    /** @comportamiento Leer, añadir y modificar una familia, y leer todas, llaman a la web con su acción y sus datos. */
    public function test_lasLlamadasALaWeb(): void
    {
        $this->tiendaWeb();
        $this->responde('todasFamilias', '{"Datos":{"items":[{"id":12,"nombre":"Frutas web"}]}}');
        $complemento = $this->complemento();

        $complemento->obtenerDatosDeFamilia(12);
        $complemento->addFamilia('{"nombreFamilia":"Frutas"}');
        $complemento->modificarFamiliaWeb('{"id":12}');
        $todas = $complemento->todasFamilias();

        self::assertSame([['datosFamilia', '12'], ['AddFamilia', '{"nombreFamilia":"Frutas"}'], ['modificarFamilia', '{"id":12}'], ['todasFamilias', null]], array_map(static fn (array $p): array => [$p['action'], $p['idWeb'] ?? $p['datos'] ?? null], $this->peticiones()));
        self::assertSame('Frutas web', $todas['Datos']['items'][0]['nombre']);
    }

    /** @comportamiento Sin conexión, leer todas las familias devuelve el error también como error de la web. */
    public function test_sinConexionTodasLasFamiliasDanElError(): void
    {
        $this->tiendaWeb();
        $complemento = $this->complemento();
        $complemento->ruta_web = 'http://localhost:9/';

        $todas = $complemento->todasFamilias();

        self::assertSame($todas['error_conexion'], $todas['error']);
    }

    /** El complemento, creado sin su constructor con la conexión del caso, y la tienda web tomada como lo hace el constructor. */
    private function complemento(): \PluginClaseVirtuemartFamilia
    {
        $complemento = (new \ReflectionClass(\PluginClaseVirtuemartFamilia::class))->newInstanceWithoutConstructor();
        $complemento->ruta_proyecto = RUTA_TPVFOX;
        $complemento->conexion = $this->db;
        $complemento->obtenerRutaProyecto();
        $tiendas = $complemento->ObtenerTiendasWeb();
        if (isset($tiendas['items'][0])) {
            $complemento->TiendaWeb = $tiendas['items'][0];
            $complemento->ruta_categoria = $tiendas['items'][0]['dominio'] . 'index.php?option=com_virtuemart&view=category&virtuemart_category_id=';
            $complemento->key_api = $tiendas['items'][0]['key_api'];
            $complemento->ruta_web = $tiendas['items'][0]['dominio'] . '/administrator/apisv/tareas.php';
        }

        return $complemento;
    }

    private function tiendaWeb(): int
    {
        $sentencia = $this->db->prepare("INSERT INTO tiendas (tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano, dominio, key_api) VALUES ('web', 'Tienda web de pruebas', 'X0000000X', '000000000', 'Activo', 'Web de pruebas', 'Sin direccion', '2026', ?, ?)");
        $dominio = $this->dominio();
        $sentencia->bind_param('ss', $dominio, $this->clave);
        $sentencia->execute();

        return (int) $this->db->insert_id;
    }

    private function dominio(): string
    {
        return (string) preg_replace('#TPVFox/?$#', 'test/Integration/PHP/mod_virtuemart/web', Entorno::valor('TPVFOX_URL'));
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

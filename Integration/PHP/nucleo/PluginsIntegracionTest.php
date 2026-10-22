<?php

/**
 * La carga de los complementos de un módulo (`plugins/plugins.php`, `ClasePlugins`): busca las
 * carpetas de complemento bajo `plugins/<módulo>/`, lee el `parametros.xml` de cada una, le añade su
 * ruta y crea la clase que nombra.
 *
 * El complemento de la tienda web de familias abre su propia conexión con los datos de
 * `configuracion.php` y consulta las tiendas web; el caso corre en un proceso aparte, porque esa
 * clase hereda de `ClaseConexion` y otros complementos declaran clases con el mismo nombre.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\Nucleo;

use PHPUnit\Framework\TestCase;

final class PluginsIntegracionTest extends TestCase
{
    /**
     * @comportamiento Los complementos de un módulo son las carpetas de `plugins/<módulo>/`: de cada
     *   una se leen sus datos generales, con su ruta añadida, y se crea la clase que nombra su
     *   `parametros.xml`, con el prefijo `Plugin`, para la vista que los pide.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_losComplementosDeUnModuloSeCarganConSuClase(): void
    {
        global $RutaServidor, $HostNombre;
        require_once RUTA_TPVFOX . '/clases/ClaseConexion.php';
        require_once RUTA_TPVFOX . '/plugins/plugins.php';

        $plugins = new \ClasePlugins('mod_familia', 'ListaFamilias');
        $cargados = $plugins->GetParametrosPlugins();

        self::assertSame(['virtuemart'], $plugins->ObtenerDir(RUTA_TPVFOX . '/plugins/mod_familia'));
        self::assertCount(1, $cargados);
        self::assertSame('ClaseVirtuemartFamilia', $cargados[0]['datos_generales']['nombre_fichero_clase']);
        self::assertSame(realpath(RUTA_TPVFOX . '/plugins/mod_familia/virtuemart'), realpath($cargados[0]['datos_generales']['ruta']));
        self::assertInstanceOf('PluginClaseVirtuemartFamilia', $cargados[0]['clase']);
        self::assertSame('ListaFamilias', $cargados[0]['clase']->dedonde);
    }
}

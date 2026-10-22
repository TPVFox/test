<?php

/**
 * La consulta de permisos de TPVFox (`clases/ClasePermisos.php`) sobre una lista de permisos dada,
 * sin base: si el menú pinta una entrada, qué permiso tiene un usuario sobre un módulo, una vista o
 * una acción, cómo se fuerza a 1 el árbol de un administrador y qué descripción enseña la pantalla
 * de usuarios para cada permiso.
 *
 * Se construye sin constructor, que siembra y lee los permisos en la base.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use PHPUnit\Framework\TestCase;

final class PermisosTest extends TestCase
{
    private const PERMISOS = [
        ['modulo' => 'mod_venta', 'vista' => null, 'accion' => null, 'permiso' => '1'],
        ['modulo' => 'mod_venta', 'vista' => 'albaranesListado.php', 'accion' => null, 'permiso' => '1'],
        ['modulo' => 'mod_venta', 'vista' => 'albaranesListado.php', 'accion' => 'Crear', 'permiso' => '1'],
        ['modulo' => 'mod_venta', 'vista' => 'albaranesListado.php', 'accion' => 'EliminarAlbaran', 'permiso' => '0'],
        ['modulo' => 'mod_venta', 'vista' => 'factura.php', 'accion' => null, 'permiso' => '0'],
        ['modulo' => 'mod_compras', 'vista' => null, 'accion' => null, 'permiso' => '0'],
        ['modulo' => 'mod_compras', 'vista' => 'albaranesListado.php', 'accion' => null, 'permiso' => '1'],
    ];

    private ?string $phpSelfOriginal = null;

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        $this->phpSelfOriginal = $_SERVER['PHP_SELF'] ?? null;
    }

    protected function tearDown(): void
    {
        $_SERVER['PHP_SELF'] = $this->phpSelfOriginal;
        parent::tearDown();
    }

    /**
     * @comportamiento El menú pinta una entrada si el módulo y la vista tienen permiso, y la enlaza a
     *   la pantalla del módulo; basta con que falte uno de los dos para que no la pinte.
     */
    public function test_elMenuPintaLaEntradaSoloConPermisoDeModuloYDeVista(): void
    {
        $permisos = self::permisos();

        self::assertSame(
            ['permiso' => 'Ok', 'link' => '/modulos/mod_venta/albaranesListado.php'],
            $permisos->comprobarPermisos(['resultado' => self::PERMISOS], 'mod_venta', 'albaranesListado.php')
        );
        self::assertSame(['permiso' => 'No Ok'], $permisos->comprobarPermisos(['resultado' => self::PERMISOS], 'mod_venta', 'factura.php'));
        self::assertSame(['permiso' => 'No Ok'], $permisos->comprobarPermisos(['resultado' => self::PERMISOS], 'mod_compras', 'albaranesListado.php'));
    }

    /** @comportamiento Una entrada del menú sin módulo es un enlace directo: se pinta siempre, con su dirección. */
    public function test_unaEntradaSinModuloSePintaSiempre(): void
    {
        self::assertSame(
            ['permiso' => 'Ok', 'link' => 'index.php'],
            self::permisos()->comprobarPermisos(['resultado' => []], '', 'index.php')
        );
    }

    /** @comportamiento El permiso de una acción es el de su fila; una acción sin fila devuelve un texto vacío. */
    public function test_elPermisoDeUnaAccionEsElDeSuFila(): void
    {
        $permisos = self::permisos();
        $donde = ['modulo' => 'mod_venta', 'vista' => 'albaranesListado.php'];

        self::assertSame('1', $permisos->getAccion('Crear', $donde));
        self::assertSame('0', $permisos->getAccion('EliminarAlbaran', $donde));
        self::assertSame('', $permisos->getAccion('AccionQueNoExiste', $donde));
    }

    /** @comportamiento Sin módulo ni vista, la acción se busca en la pantalla que se está sirviendo, deducida de su dirección. */
    public function test_sinModuloNiVistaLaAccionSeBuscaEnLaPantallaPedida(): void
    {
        $_SERVER['PHP_SELF'] = '/TPVFox/modulos/mod_venta/albaranesListado.php';

        self::assertSame('1', self::permisos()->getAccion('Crear'));
    }

    /** @comportamiento El permiso de un módulo es el de su fila de módulo. */
    public function test_elPermisoDeUnModuloEsElDeSuFila(): void
    {
        self::assertSame('1', self::permisos()->getModulo('mod_venta'));
        self::assertSame('0', self::permisos()->getModulo('mod_compras'));
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClasePermisos.php:296-308
     *
     * @group esperado
     * @group permisos
     * @group bajo
     *
     * @que-ocurre-hoy Preguntar por un módulo sin fila deja un aviso de PHP por una variable sin
     *   definir y devuelve null.
     * @que-deberia-ocurrir Que devuelva un texto vacío, como una acción sin fila, sin avisos.
     * @por-que-ocurre La variable del resultado solo se asigna si se encuentra la fila.
     * @como-deberia-funcionar Darle un valor vacío antes de recorrer los permisos.
     */
    public function test_unModuloSinFilaDevuelveVacioSinAvisos(): void
    {
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        });
        try {
            $permiso = self::permisos()->getModulo('mod_que_no_existe');
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $avisos, 'Sin avisos de PHP.');
        self::assertSame('', $permiso);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClasePermisos.php:319
     *
     * @group esperado
     * @group permisos
     * @group bajo
     *
     * @que-ocurre-hoy Pedir el permiso de una vista termina siempre con un error de tipo. Hoy no lo
     *   pide nada que llegue a ejecutarse: su único llamador, la reorganización de permisos, no
     *   alcanza esa rama.
     * @que-deberia-ocurrir Que devuelva el permiso de la vista del módulo pedido.
     * @por-que-ocurre La condición cuenta los elementos de `$mod_modulo`, una variable que no existe;
     *   en PHP 8 contar algo que no es un array es un error. Además, si la condición se cumpliera, el
     *   módulo y la vista pedidos se sustituirían por los de la pantalla servida.
     * @como-deberia-funcionar Deducir el módulo de la pantalla servida solo cuando no se pasa.
     */
    public function test_elPermisoDeUnaVistaEsElDeSuFila(): void
    {
        $fallo = null;
        set_error_handler(static fn (): bool => true);
        try {
            $permiso = self::permisos()->getVista('factura.php', 'mod_venta');
        } catch (\TypeError $error) {
            $fallo = $error->getMessage();
        } finally {
            restore_error_handler();
        }

        self::assertNull($fallo, 'Pedir el permiso de una vista no puede terminar en error.');
        self::assertSame('0', $permiso);
    }

    /** @comportamiento El árbol de permisos de un administrador se pone entero a 1: módulo, vistas y acciones. */
    public function test_elArbolDeUnAdministradorSePoneEnteroAUno(): void
    {
        $xml = simplexml_load_file(RUTA_TPVFOX . '/modulos/mod_venta/acces.xml');

        $forzado = self::permisos()->ModificarPermisos($xml);

        self::assertSame('1', (string) $forzado['permiso']);
        foreach ($forzado->vista as $vista) {
            self::assertSame('1', (string) $vista['permiso']);
            foreach ($vista->accion as $accion) {
                self::assertSame('1', (string) $accion['permiso']);
            }
        }
    }

    /**
     * @comportamiento La descripción de un permiso es la que declara el `acces.xml` de su módulo para
     *   el módulo, la vista o la acción; si la acción no está allí, la de un complemento del módulo.
     */
    public function test_laDescripcionDeUnPermisoEsLaDeSuAcces(): void
    {
        $permisos = self::permisosConRutas();
        $fila = ['modulo' => 'mod_venta', 'vista' => 'albaranesListado.php'];

        self::assertSame('Ventas', (string) $permisos->ObtenerDescripcion('mod_venta', $fila));
        self::assertSame('Listado albaranes', (string) $permisos->ObtenerDescripcion('albaranesListado.php', $fila));
        self::assertSame('Crear albarán', (string) $permisos->ObtenerDescripcion('Crear', $fila));
        self::assertSame(
            'Crear Producto Web',
            (string) $permisos->ObtenerDescripcion('CrearProductoWeb', ['modulo' => 'mod_producto', 'vista' => 'producto.php']),
            'Una acción que declara un complemento del módulo se busca en su acces.xml'
        );
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClasePermisos.php:336-389
     *
     * @group esperado
     * @group permisos
     * @group bajo
     *
     * @que-ocurre-hoy Un permiso cuya acción ya no está en el `acces.xml` —una acción retirada—
     *   deja un aviso de PHP por una variable sin definir al pintar su descripción.
     * @que-deberia-ocurrir Que su descripción salga vacía, sin avisos.
     * @por-que-ocurre La descripción solo se asigna cuando se encuentra el nombre.
     * @como-deberia-funcionar Darle un valor vacío antes de buscarla.
     */
    public function test_unaAccionRetiradaDelAccesNoTieneDescripcionNiAvisos(): void
    {
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        });
        try {
            $descripcion = self::permisosConRutas()->ObtenerDescripcion(
                'AccionRetirada',
                ['modulo' => 'mod_venta', 'vista' => 'albaranesListado.php']
            );
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $avisos, 'Sin avisos de PHP.');
        self::assertSame('', (string) $descripcion);
    }

    /** Los permisos con la lista de prueba, sin pasar por el constructor. */
    private static function permisos(): \ClasePermisos
    {
        $permisos = (new \ReflectionClass(\ClasePermisos::class))->newInstanceWithoutConstructor();
        $permisos->permisos = ['resultado' => self::PERMISOS];

        return $permisos;
    }

    /** Como `permisos()`, con las rutas de módulos y complementos resueltas, que leen los `acces.xml`. */
    private static function permisosConRutas(): \ClasePermisos
    {
        $permisos = self::permisos();
        $permisos->obtenerRutaProyecto();

        return $permisos;
    }
}

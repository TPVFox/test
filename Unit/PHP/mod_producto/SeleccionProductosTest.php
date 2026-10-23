<?php

/**
 * La selección de productos de `mod_productos` (`modulos/mod_productos/clases/ClaseSeleccionProductos.php`):
 * un fichero XML por usuario con los productos que marcó, que se lee, se cuenta, se marca sin repetir,
 * se quita producto a producto y se vacía borrando el fichero.
 *
 * Cada caso trabaja en una carpeta temporal propia, que la clase recibe como la de la caché del módulo;
 * la carpeta del módulo no se toca. La clase carga su clase base con la ruta del producto, que el caso
 * le da antes de cargarla.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModProducto;

use PHPUnit\Framework\TestCase;

final class SeleccionProductosTest extends TestCase
{
    private string $carpeta = '';

    protected function setUp(): void
    {
        parent::setUp();
        $URLCom = RUTA_TPVFOX;
        require_once RUTA_TPVFOX . '/modulos/mod_productos/clases/ClaseSeleccionProductos.php';
        $this->carpeta = sys_get_temp_dir() . '/tpvfox-seleccion-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        if (is_dir($this->carpeta)) {
            rmdir($this->carpeta);
        }
        parent::tearDown();
    }

    /** @comportamiento Sin fichero, la selección está vacía; quitar o vaciar no crean ninguno. */
    public function test_sinFicheroLaSeleccionEstaVacia(): void
    {
        $seleccion = new \ClaseSeleccionProductos(3, $this->carpeta);

        self::assertSame([], $seleccion->getIds());
        self::assertSame(0, $seleccion->contar());
        self::assertTrue($seleccion->quitar(4));
        self::assertTrue($seleccion->limpiar());
        self::assertDirectoryDoesNotExist($this->carpeta);
    }

    /**
     * @comportamiento Marcar un producto crea la carpeta y el fichero del usuario, con su número, y guarda
     *   el producto una sola vez aunque se marque dos.
     */
    public function test_marcarUnProductoLoGuardaUnaVezEnElFicheroDelUsuario(): void
    {
        $seleccion = new \ClaseSeleccionProductos(3, $this->carpeta);

        self::assertTrue($seleccion->agregar(4));
        self::assertTrue($seleccion->agregar(4));
        self::assertTrue($seleccion->agregar(7));

        self::assertSame([4, 7], $seleccion->getIds());
        self::assertSame(2, $seleccion->contar());
        $guardado = simplexml_load_file($this->carpeta . '/3_seleccion.xml');
        self::assertSame('seleccion', $guardado->getName());
        self::assertSame('3', (string) $guardado['usuario']);
        self::assertCount(2, $guardado->item);
    }

    /** @comportamiento Quitar un producto deja los demás en su orden; quitar uno que no está no cambia nada. */
    public function test_quitarUnProductoDejaLosDemas(): void
    {
        $seleccion = new \ClaseSeleccionProductos(3, $this->carpeta);
        foreach ([4, 7, 9] as $idArticulo) {
            $seleccion->agregar($idArticulo);
        }

        self::assertTrue($seleccion->quitar(7));
        self::assertTrue($seleccion->quitar(99));

        self::assertSame([4, 9], $seleccion->getIds());
    }

    /** @comportamiento Vaciar la selección borra el fichero del usuario. */
    public function test_vaciarBorraElFicheroDelUsuario(): void
    {
        $seleccion = new \ClaseSeleccionProductos(3, $this->carpeta);
        $seleccion->agregar(4);

        self::assertTrue($seleccion->limpiar());

        self::assertFileDoesNotExist($this->carpeta . '/3_seleccion.xml');
        self::assertSame([], $seleccion->getIds());
    }

    /** @comportamiento Cada usuario tiene su propio fichero: lo que marca uno no lo ve ni lo quita el otro. */
    public function test_cadaUsuarioTieneSuSeleccion(): void
    {
        $uno = new \ClaseSeleccionProductos(3, $this->carpeta);
        $otro = new \ClaseSeleccionProductos(5, $this->carpeta);

        $uno->agregar(4);
        $otro->agregar(8);
        $otro->quitar(4);
        $otro->limpiar();

        self::assertSame([4], $uno->getIds());
        self::assertSame([], $otro->getIds());
    }
}

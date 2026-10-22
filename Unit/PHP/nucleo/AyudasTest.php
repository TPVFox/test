<?php

/**
 * Las funciones de apoyo de `app/helpers.php`, que `inicial.php` carga en cada petición: poner un
 * texto entre comillas y volcar o mostrar un dato para depurar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use PHPUnit\Framework\TestCase;

final class AyudasTest extends TestCase
{
    private const RUTA_AYUDAS = RUTA_TPVFOX . '/app/helpers.php';

    protected function setUp(): void
    {
        parent::setUp();
        require_once self::RUTA_AYUDAS;
    }

    /** @comportamiento Pone el texto entre comillas dobles, o entre las que se le pidan. */
    public function test_entreComillasPoneElTextoEntreLasComillasPedidas(): void
    {
        self::assertSame('"lomo"', \entreComillas('lomo'));
        self::assertSame("'lomo'", \entreComillas('lomo', "'"));
        self::assertSame('""', \entreComillas(''));
    }

    /** @comportamiento Muestra el dato tal como lo escribe print_r, dentro de un bloque <pre>. */
    public function test_dumpMuestraElDatoDentroDeUnBloquePre(): void
    {
        $this->expectOutputString("<pre>Array\n(\n    [0] => lomo\n)\n</pre>");

        \dump(['lomo']);
    }

    /**
     * @comportamiento Muestra el dato como `dump` y termina la petición: lo que venga después no
     *   se ejecuta. Se comprueba en un proceso aparte, porque termina el proceso en el que corre.
     */
    public function test_ddMuestraElDatoYTerminaLaPeticion(): void
    {
        $guion = 'require ' . var_export(self::RUTA_AYUDAS, true) . '; dd(["lomo"]); echo "después";';

        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($guion), $salida, $codigo);

        self::assertSame("<pre>Array\n(\n    [0] => lomo\n)\n</pre>", implode("\n", $salida));
        self::assertSame(0, $codigo);
    }

    /**
     * @estado rojo
     * @codigo-afectado app/helpers.php:25
     *
     * @group esperado
     * @group bajo
     *
     * @que-ocurre-hoy Pedir el texto de un error lo escribe en la página en ese momento, fuera de
     *   su sitio, y lo que devuelve es un bloque <pre> con un 1 dentro.
     * @que-deberia-ocurrir Que devuelva el dato dentro de un bloque <pre>, sin escribir nada, para
     *   que quien lo pida lo coloque donde corresponda.
     * @por-que-ocurre `print_r()` sin su segundo argumento escribe el dato y devuelve true, que al
     *   concatenarse se convierte en un 1.
     * @como-deberia-funcionar Llamar a `print_r($respuesta, true)`.
     */
    public function test_mostrarErrorDevuelveElDatoSinEscribirlo(): void
    {
        ob_start();
        $devuelto = \mostrarError(['lomo']);
        $escrito = ob_get_clean();

        self::assertSame('', $escrito, 'No tiene que escribir nada.');
        self::assertSame("<pre>Array\n(\n    [0] => lomo\n)\n</pre>", $devuelto);
    }
}

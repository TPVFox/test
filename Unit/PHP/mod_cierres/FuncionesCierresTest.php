<?php

/**
 * Las funciones de `modulos/mod_cierres/funciones.php` que no consultan la base: las fechas con las que
 * la pantalla del cierre monta su intervalo y el HTML de la búsqueda de clientes.
 *
 * Otros módulos declaran funciones con el mismo nombre (`htmlClientes()`, `baseIva()`…): cada caso corre
 * en su propio proceso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModCierres;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesCierresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        date_default_timezone_set('UTC');
        require_once RUTA_TPVFOX . '/modulos/mod_cierres/funciones.php';
    }

    /** Llama a la función sin que el aviso de función obsoleta llegue a PHPUnit; devuelve los avisos. */
    private function sinAvisosDeObsoleta(callable $llamada, ?array &$avisos = null): mixed
    {
        $avisos = [];
        $anterior = error_reporting(E_ALL);
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        }, E_DEPRECATED);

        try {
            return $llamada();
        } finally {
            restore_error_handler();
            error_reporting($anterior);
        }
    }

    /**
     * @comportamiento Una fecha en segundos se devuelve, bajo el nombre pedido, con los tres textos que usa
     *   la pantalla —día, mes y año con la hora; sin ella; y año, mes y día— y sus partes.
     */
    public function test_lasFechasDelCierreSeDevuelvenEnLosTresFormatos(): void
    {
        $fechas = $this->sinAvisosDeObsoleta(static fn () => \ArrayFechaUnix(gmmktime(12, 34, 56, 5, 10, 2031), 'fechaMin'));

        self::assertSame(gmmktime(12, 34, 56, 5, 10, 2031), $fechas['fechaMin']['Epoch-Unix']);
        self::assertSame('10-05-2031 12:34:56', $fechas['fechaMin']['String_d-m-y_hora']);
        self::assertSame('10-05-2031', $fechas['fechaMin']['String_d-m-y']);
        self::assertSame('2031-05-10', $fechas['fechaMin']['String_y-m-d']);
        self::assertSame(2031, $fechas['fechaMin']['date']['year']);
        self::assertSame(34, $fechas['fechaMin']['date']['minute']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/funciones.php:384-391
     * @codigo-afectado modulos/mod_cierres/funciones.php:154-155
     *
     * @group defecto
     * @group cierres
     * @group bajo
     *
     * @que-ocurre-hoy Las fechas del cierre —las de la pantalla y las del cambio de estado de los tickets
     *   al guardarlo— se componen con `strftime()`, obsoleta desde PHP 8.1: cada llamada deja un aviso, y
     *   con la versión de PHP que la retire la pantalla y el guardado dejarán de funcionar.
     * @que-deberia-ocurrir Que las fechas del cierre se compongan sin funciones obsoletas.
     * @por-que-ocurre `ArrayFechaUnix()` y `InsertarProceso1Cierres()` formatean con `strftime()`.
     * @como-deberia-funcionar Formatear con `date()` o con `DateTimeImmutable`.
     */
    public function test_lasFechasDelCierreNoUsanFuncionesObsoletas(): void
    {
        $this->sinAvisosDeObsoleta(static fn () => \ArrayFechaUnix(gmmktime(12, 0, 0, 5, 10, 2031), 'fechaMin'), $avisos);

        self::assertSame([], $avisos);
    }

    /**
     * @comportamiento La búsqueda de clientes pinta su caja con lo buscado y una fila por cliente, hasta
     *   diez, que al pulsarla devuelve su número, su nombre y su razón social, y de dónde viene la búsqueda:
     *   1 desde los tickets cerrados, 2 desde los cobrados y 0 en otro caso.
     */
    public function test_laBusquedaDeClientesPintaUnaFilaPorCliente(): void
    {
        $clientes = [
            ['idClientes' => '7', 'nombre' => 'Ana', 'razonsocial' => 'Ana SL', 'nif' => 'B11111111'],
            ['idClientes' => '8', 'nombre' => 'Luis', 'razonsocial' => 'Luis SA', 'nif' => 'A22222222'],
        ];

        $resultado = \htmlClientes('an', 'cerrados', $clientes);

        self::assertSame(2, $resultado['encontrados']);
        self::assertStringContainsString('id="cajaBusquedacliente"', $resultado['html']);
        self::assertStringContainsString('value="an"', $resultado['html']);
        self::assertStringContainsString("cerrarModalClientes('7','Ana - Ana SL',1);", $resultado['html']);
        self::assertStringContainsString('<td>B11111111</td>', $resultado['html']);
        self::assertStringContainsString('id="Fila_1"', $resultado['html']);
        self::assertStringContainsString("cerrarModalClientes('8','Luis - Luis SA',2);", \htmlClientes('', 'cobrados', $clientes)['html']);
        self::assertStringContainsString("cerrarModalClientes('8','Luis - Luis SA',0);", \htmlClientes('', 'tpv', $clientes)['html']);
    }

    /** @comportamiento Con más de diez clientes, la búsqueda pinta los diez primeros y dice cuántos hay. */
    public function test_laBusquedaDeClientesPintaComoMuchoDiez(): void
    {
        $clientes = [];
        for ($i = 1; $i <= 12; $i++) {
            $clientes[] = ['idClientes' => (string) $i, 'nombre' => "Cliente $i", 'razonsocial' => '', 'nif' => ''];
        }

        $html = \htmlClientes('', '', $clientes)['html'];

        self::assertStringContainsString('10 productos de 12', $html);
        self::assertStringContainsString('id="Fila_9"', $html);
        self::assertStringNotContainsString('id="Fila_10"', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_cierres/funciones.php:576-579
     * @codigo-afectado modulos/mod_cierres/funciones.php:604
     *
     * @group defecto
     * @group cierres
     * @group bajo
     *
     * @que-ocurre-hoy La búsqueda de clientes escribe tal cual lo buscado, dentro del valor de la caja, y el
     *   NIF de cada cliente: unas comillas en lo buscado cierran el atributo, y un NIF con etiquetas se pinta
     *   como HTML.
     * @que-deberia-ocurrir Que lo buscado y el NIF se escriban como texto.
     * @por-que-ocurre El nombre y la razón social se escapan; lo buscado y el NIF, no.
     * @como-deberia-funcionar Escapar lo buscado y el NIF con `htmlspecialchars()`, como el nombre.
     */
    public function test_laBusquedaDeClientesEscribeComoTextoLoBuscadoYElNif(): void
    {
        $clientes = [['idClientes' => '7', 'nombre' => 'Ana', 'razonsocial' => 'Ana SL', 'nif' => 'B1<b>2</b>']];

        $html = \htmlClientes('Ana "la del 5"', '', $clientes)['html'];

        self::assertStringContainsString('value="Ana &quot;la del 5&quot;"', $html);
        self::assertStringContainsString('<td>B1&lt;b&gt;2&lt;/b&gt;</td>', $html);
    }
}

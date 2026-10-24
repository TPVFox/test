<?php

/**
 * La puerta de sesión de los `tareas.php` (`clases/exigirSesion.php`): cuándo da la sesión por
 * iniciada, y que cada `tareas.php` de un módulo la llama.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use PHPUnit\Framework\TestCase;

final class PuertaDeSesionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once RUTA_TPVFOX . '/clases/exigirSesion.php';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /** @comportamiento Sin nada en la sesión, no está iniciada. */
    public function test_sinSesionNoEstaIniciada(): void
    {
        $_SESSION = [];

        self::assertFalse(\sesionCorrecta());
    }

    /** @comportamiento Con la sesión en un estado que no es «Correcto», tampoco. */
    public function test_conLaSesionEnErrorNoEstaIniciada(): void
    {
        $_SESSION = ['estadoTpv' => 'Erroneo'];

        self::assertFalse(\sesionCorrecta());
    }

    /** @comportamiento Con la sesión en «Correcto», está iniciada. */
    public function test_conLaSesionCorrectaEstaIniciada(): void
    {
        $_SESSION = ['estadoTpv' => 'Correcto'];

        self::assertTrue(\sesionCorrecta());
    }

    /**
     * @comportamiento Cada `tareas.php` de un módulo llama a la puerta, también el de las tareas
     *   programadas, que el cron no usa: lo pide la pantalla del módulo, con sesión.
     *
     * @dataProvider tareasDeCadaModulo
     */
    public function test_cadaTareasPhpExigeLaSesion(string $fichero): void
    {
        self::assertStringContainsString('exigirSesion();', (string) file_get_contents($fichero));
    }

    /** @return array<string, array{string}> */
    public static function tareasDeCadaModulo(): array
    {
        $casos = [];
        foreach (glob(RUTA_TPVFOX . '/modulos/*/tareas.php') as $fichero) {
            $casos[basename(dirname($fichero))] = [$fichero];
        }

        return $casos;
    }
}

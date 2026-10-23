<?php

/**
 * Las funciones de la pantalla de temperaturas (`modulos/mod_temperaturas/funciones.php`): la última
 * lectura de cada dispositivo, que la lista de dispositivos enseña con su fecha.
 *
 * No tocan la base: el caso les da los dispositivos y las lecturas como los devuelve la clase.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModTemperaturas;

use PHPUnit\Framework\TestCase;

final class FuncionesTemperaturasTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_temperaturas/funciones.php';
    }

    /**
     * @comportamiento Cada dispositivo recibe su lectura más reciente y la fecha de esta, sea cual sea el
     *   orden de las lecturas; el que no tiene ninguna, las dos vacías.
     */
    public function test_cadaDispositivoRecibeSuUltimaLectura(): void
    {
        $dispositivos = [['idDispositivo' => '1'], ['idDispositivo' => '2'], ['idDispositivo' => '3']];
        $lecturas = [
            ['idDispositivo' => '1', 'temperatura' => '4.00', 'fechaRegistro' => '2026-10-20 08:00:00'],
            ['idDispositivo' => '1', 'temperatura' => '5.50', 'fechaRegistro' => '2026-10-21 08:00:00'],
            ['idDispositivo' => '2', 'temperatura' => '-18.00', 'fechaRegistro' => '2026-10-21 20:00:00'],
            ['idDispositivo' => '1', 'temperatura' => '3.00', 'fechaRegistro' => '2026-10-19 20:00:00'],
        ];

        $conUltima = agregarUltimasTemperaturas($dispositivos, $lecturas);

        self::assertSame(['5.50', '-18.00', null], array_column($conUltima, 'ultima_temperatura'));
        self::assertSame(['2026-10-21 08:00:00', '2026-10-21 20:00:00', null], array_column($conUltima, 'ultimo_registro'));
        self::assertSame('5.50', $dispositivos[0]['ultima_temperatura']);
    }
}

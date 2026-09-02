<?php

/**
 * `prepararCaberaAdjuntoTemporal()` normaliza la cabecera de un adjunto (albaran o pedido)
 * a la forma estandar `NumAdjunto`/`fecha`/`total`/`estado`. `prepararAdjuntos()` aplica esa
 * normalizacion a una lista completa y compone su HTML con `htmlLineaAdjunto()`.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModVenta;

use PHPUnit\Framework\TestCase;
use TPVFox\Test\CargaAislada;

final class PrepararAdjuntosTest extends TestCase
{
    private static function cargar(): void
    {
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
    }

    public function test_prepararCaberaAdjuntoTemporal_facturaTomaElNumeroDeAlbaran(): void
    {
        self::cargar();

        $resultado = \prepararCaberaAdjuntoTemporal(
            ['id' => 1, 'NumalbCli' => 50, 'Fecha' => '2026-01-01', 'total' => 10],
            'factura'
        );

        self::assertSame(50, $resultado['NumAdjunto']);
        self::assertSame('Activo', $resultado['estado']);
    }

    /**
     * El unico otro valor real de `$dedonde` en esta llamada es 'albaran' (`BuscarAdjunto.php`
     * solo distingue 'factura' de cualquier otra cosa): el `else` lee `Numpedcli` asumiendo
     * ese caso sin comprobarlo. Documentado, no es un test en rojo porque ningun llamador
     * actual pasa un tercer valor de `$dedonde` a esta funcion.
     */
    public function test_prepararCaberaAdjuntoTemporal_elElseAsumeAlbaranYTomaElNumeroDePedido(): void
    {
        self::cargar();

        $resultado = \prepararCaberaAdjuntoTemporal(
            ['id' => 2, 'Numpedcli' => 60, 'Fecha' => '2026-01-02', 'total' => 20],
            'albaran'
        );

        self::assertSame(60, $resultado['NumAdjunto']);
    }

    public function test_prepararAdjuntos_yaPreparadoNoLoVuelveAPasarPorLaNormalizacion(): void
    {
        self::cargar();

        $resultado = \prepararAdjuntos(
            [['NumAdjunto' => 10, 'fecha' => '2026-01-01', 'total' => 5, 'estado' => 'Activo']],
            'albaran',
            'editar'
        );

        self::assertSame(1, $resultado['adjuntos'][0]['nfila']);
        self::assertStringContainsString('eliminarAdjunto(10', $resultado['html']);
    }

    public function test_prepararAdjuntos_sinPrepararLoNormalizaAntesDeNumerar(): void
    {
        self::cargar();

        $resultado = \prepararAdjuntos(
            [['id' => 2, 'Numpedcli' => 60, 'Fecha' => '2026-01-02', 'total' => 20]],
            'albaran',
            'ver'
        );

        self::assertSame(60, $resultado['adjuntos'][0]['NumAdjunto']);
        self::assertSame(1, $resultado['adjuntos'][0]['nfila']);
    }

    public function test_prepararAdjuntos_numeraLasFilasEnOrdenEmpezandoEnUno(): void
    {
        self::cargar();

        $resultado = \prepararAdjuntos(
            [
                ['NumAdjunto' => 10, 'fecha' => '2026-01-01', 'total' => 5, 'estado' => 'Activo'],
                ['NumAdjunto' => 11, 'fecha' => '2026-01-02', 'total' => 6, 'estado' => 'Activo'],
            ],
            'albaran',
            'ver'
        );

        self::assertSame(1, $resultado['adjuntos'][0]['nfila']);
        self::assertSame(2, $resultado['adjuntos'][1]['nfila']);
    }
}

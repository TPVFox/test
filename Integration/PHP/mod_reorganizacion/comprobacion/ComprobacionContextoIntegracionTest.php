<?php
/**
 * Contexto de operación: ejercicio y tienda desde la sesión, esquema y parámetros
 * presentes, y bloque de lectura abierto en una transacción de solo lectura.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use mysqli_sql_exception;
use TPVFox\Test\CasoIntegracion;

final class ComprobacionContextoIntegracionTest extends CasoIntegracion
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION ??= [];
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionContexto.php');
    }

    protected function tearDown(): void
    {
        unset($_SESSION['tiendaTpv']);
        parent::tearDown();
    }

    public function test_T1_ejercicioYTiendaSalenDeLaSesion(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => '2019', 'idTienda' => '42'];

        $comprobacion = new \ClaseComprobacionContexto();
        $contexto = $comprobacion->abrir();

        self::assertTrue($contexto['ok']);
        self::assertSame('2019', $contexto['ano']);
        self::assertSame('42', $contexto['idTienda']);

        $comprobacion->cerrar();
    }

    public function test_T2_sinSesionEstablecidaSePara(): void
    {
        unset($_SESSION['tiendaTpv']);

        $contexto = (new \ClaseComprobacionContexto())->abrir();

        self::assertFalse($contexto['ok']);
        self::assertNotEmpty($contexto['motivo']);
    }

    public function test_T3_objetoDelEsquemaAusenteSePara(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];

        $comprobacion = new \ClaseComprobacionContexto(['tabla_inexistente_de_prueba']);
        $contexto = $comprobacion->abrir();

        self::assertFalse($contexto['ok']);
        self::assertStringContainsString('tabla_inexistente_de_prueba', $contexto['motivo']);
    }

    public function test_T4_parametroDeCriterioAusenteSePara(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];

        $comprobacion = new \ClaseComprobacionContexto(null, ['umbral_inexistente_de_prueba']);
        $contexto = $comprobacion->abrir();

        self::assertFalse($contexto['ok']);
        self::assertStringContainsString('umbral_inexistente_de_prueba', $contexto['motivo']);
    }

    public function test_T5_proveedorDeCierreAusenteSePara(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];

        $comprobacion = new \ClaseComprobacionContexto(null, null, 'configuracion/ruta_inexistente_de_prueba');
        $contexto = $comprobacion->abrir();

        self::assertFalse($contexto['ok']);
        self::assertStringContainsString('proveedor', $contexto['motivo']);
    }

    public function test_T6_familiasExcluidasAusentesSePara(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];

        $comprobacion = new \ClaseComprobacionContexto(null, null, null, 'configuracion/ruta_inexistente_de_prueba');
        $contexto = $comprobacion->abrir();

        self::assertFalse($contexto['ok']);
        self::assertStringContainsString('familias_excluidas', $contexto['motivo']);
    }

    public function test_T7_bloqueDeLecturaAbreEnTransaccionDeSoloLectura(): void
    {
        $_SESSION['tiendaTpv'] = ['ano' => '2026', 'idTienda' => '1'];
        $comprobacion = new \ClaseComprobacionContexto();

        $contexto = $comprobacion->abrir();
        self::assertTrue($contexto['ok']);

        $db = $comprobacion->conexionBDTPV();
        $enTransaccion = $db->query('SELECT @@in_transaction AS activa')->fetch_assoc();
        self::assertSame('1', $enTransaccion['activa']);

        $escrituraRechazada = false;
        try {
            $db->query('DELETE FROM articulos WHERE id = -1');
        } catch (mysqli_sql_exception) {
            $escrituraRechazada = true;
        }
        self::assertTrue($escrituraRechazada, 'La escritura tendría que fallar dentro del bloque de solo lectura');

        $comprobacion->cerrar();
    }
}

<?php

/**
 * Los identificadores de la capa de datos `clases/DB.php`: una tabla o una columna no se pueden
 * escapar como un valor, así que solo se admiten nombres sencillos, y los que llegan de fuera, si
 * están en la lista de los permitidos.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use PHPUnit\Framework\TestCase;

final class CapaDBTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once RUTA_TPVFOX . '/clases/DB.php';
    }

    /** @comportamiento Un nombre sencillo se admite y se escribe entre comillas invertidas. */
    public function test_unNombreSencilloSeEscribeEntreComillasInvertidas(): void
    {
        self::assertSame('`clientes`', \DB::ident('clientes'));
        self::assertSame('`articulo_name`', \DB::ident('articulo_name'));
    }

    /** @comportamiento Un nombre que lleva una sentencia detrás no se admite. */
    public function test_unNombreConUnaSentenciaDetrasNoSeAdmite(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        \DB::ident('clientes` ; DROP TABLE usuarios -- ');
    }

    /** @comportamiento De fuera, solo se admite un nombre de la lista de permitidos. */
    public function test_deFueraSoloSeAdmiteUnNombrePermitido(): void
    {
        self::assertSame('`Nombre`', \DB::identWhitelist('Nombre', ['Nombre', 'razonsocial']));

        $this->expectException(\InvalidArgumentException::class);
        \DB::identWhitelist('password', ['Nombre', 'razonsocial']);
    }
}

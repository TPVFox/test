<?php

/**
 * La clase de IVA de `clases/ClaseTablaIva.php` sin conexión: se puede crear sin ella y dársela
 * después, y guarda los datos de un tipo en sus propiedades. Lo que lee de la base se prueba en la
 * integración.
 *
 * Corre en su propio proceso: el producto solo carga la clase a través de la de artículos, que la
 * incluye sin comprobar si ya lo está, y cargarla en el proceso común de la suite haría que esa
 * inclusión la declarase dos veces.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModConfiguracion;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ClaseTablaIvaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/clases/ClaseTablaIva.php';
    }

    /** @comportamiento Sin conexión, la clase se crea sin consultar nada y sin recuento. */
    public function test_sinConexionSeCreaSinConsultar(): void
    {
        $ivas = new \ClaseTablaIva();

        self::assertNull($ivas->getNumRows());
        self::assertSame(0, $ivas->getId());
    }

    /** @comportamiento Los datos de un tipo se guardan y se leen por sus propiedades. */
    public function test_losDatosDeUnTipoSeGuardanEnLaClase(): void
    {
        $ivas = new \ClaseTablaIva();

        $ivas->setId(3);
        $ivas->setDescripcion('IVA general');
        $ivas->setIva('21.00');
        $ivas->setRecargo('5.20');

        self::assertSame([3, 'IVA general', '21.00', '5.20'], [$ivas->getId(), $ivas->getDescripcion(), $ivas->getIva(), $ivas->getRecargo()]);
    }
}

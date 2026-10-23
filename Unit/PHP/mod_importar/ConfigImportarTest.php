<?php

/**
 * La configuración de la importación de artículos (`modulos/mod_importar/clases/ClaseConfigImportar.php`):
 * el campo que identifica cada artículo, los filtros de la importación y de la fusión, los campos que
 * no se copian cuando vienen vacíos y qué se anota en el fichero de registro.
 *
 * No toca la base: está escrita en la clase, sin pantalla ni parámetros que la cambien.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModImportar;

use PHPUnit\Framework\TestCase;

final class ConfigImportarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_importar/clases/ClaseConfigImportar.php';
    }

    /**
     * @comportamiento El código identifica cada artículo; la importación descarta las filas con el nombre
     *   o el campo de nulo vacíos, y la fusión solo deja de crear las marcadas como nulas.
     */
    public function test_elCodigoIdentificaYLosFiltrosDescartanLoVacioYNoCreanLoNulo(): void
    {
        $configuracion = new \ConfigImportar();

        self::assertSame('CODIGO', $configuracion->campo_principal);
        self::assertSame(
            [['nombre_campo' => 'NULO', 'valor' => ''], ['nombre_campo' => 'NOMBRE', 'valor' => '']],
            $configuracion->filtros['importar']
        );
        self::assertSame('', $configuracion->filtros['fusionar']['actualizar']['valor']);
        self::assertSame(['nombre_campo' => 'NULO', 'valor' => '1'], $configuracion->filtros['fusionar']['crear']);
        self::assertSame('', $configuracion->filtros['fusionar']['ambos']['valor']);
    }

    /**
     * @comportamiento Los vacíos y la fecha de la última venta no se copian cuando no traen valor; el
     *   registro anota los nulos, los errores, el código y estado de cada artículo y sus diferencias, pero
     *   no la sentencia que falló.
     */
    public function test_losCamposSinDatosYLoQueSeAnotaEnElRegistro(): void
    {
        $configuracion = new \ConfigImportar();

        self::assertSame(['VACIOS' => 'None', 'FECHA_UL' => 'None'], $configuracion->campos_sindatos);
        self::assertSame(['nulo' => 'Si', 'error' => 'Si', 'sql' => 'No'], $configuracion->reg_log['importar']);
        self::assertSame(['Codigo_y_estado' => 'Si', 'diferencia_array' => 'Si'], $configuracion->reg_log['fusionar']);
    }
}

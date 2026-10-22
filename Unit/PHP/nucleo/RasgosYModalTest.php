<?php

/**
 * Las piezas comunes de PHP que no tocan la base: el aviso que montan los módulos
 * (`clases/traits/MontarAdvertenciaTrait.php`), el reparto de datos por nombre de columna
 * (`clases/traits/DesglosaDatosPorNombreTrait.php`) y la ventana modal que incluyen las pantallas
 * (`plugins/modal/ventanaModal.php`).
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use PHPUnit\Framework\TestCase;

final class RasgosYModalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/clases/traits/MontarAdvertenciaTrait.php';
        require_once RUTA_TPVFOX . '/clases/traits/DesglosaDatosPorNombreTrait.php';
    }

    /** @comportamiento Un aviso es un array con su tipo y su mensaje, sea texto o una lista. */
    public function test_unAvisoEsUnArrayConTipoYMensaje(): void
    {
        $avisos = self::conAvisos();

        self::assertSame(['tipo' => 'warning', 'mensaje' => 'Sin stock'], $avisos->montarAdvertencia('warning', 'Sin stock'));
        self::assertSame(['tipo' => 'danger', 'mensaje' => ['a' => 1]], $avisos->montarAdvertencia('danger', ['a' => 1]));
    }

    /**
     * @comportamiento Pedido como HTML, el aviso es una alerta con el tipo como clase y como título;
     *   una lista se pinta con print_r dentro de un bloque <pre>. El mensaje se escribe tal cual.
     */
    public function test_pedidoComoHtmlElAvisoEsUnaAlerta(): void
    {
        $avisos = self::conAvisos();

        self::assertSame(
            '<div class="alert alert-info"><strong>info </strong><br/> Guardado</div>',
            $avisos->montarAdvertencia('info', 'Guardado', 'OK')
        );
        self::assertSame(
            "<div class=\"alert alert-danger\"><strong>danger </strong><br/> <pre>Array\n(\n    [error] => 1062\n)\n</pre></div>",
            $avisos->montarAdvertencia('danger', ['error' => 1062], 'OK')
        );
    }

    /** @comportamiento Repartir datos por nombre toma solo las columnas pedidas que vienen, o todas menos esas. */
    public function test_repartirDatosPorNombreTomaOQuitaLasColumnasPedidas(): void
    {
        $datos = self::conReparto();
        $fila = ['id' => 7, 'nombre' => 'Lomo', 'precio' => 12.5];

        self::assertSame(['id' => 7, 'precio' => 12.5], $datos->desglosaDatosPorNombre($fila, ['id', 'precio', 'no_viene']));
        self::assertSame(['nombre' => 'Lomo'], $datos->desglosaNoDatosPorNombre($fila, ['id', 'precio']));
    }

    /**
     * @comportamiento La ventana modal es un diálogo de Bootstrap, `#ventanaModal`, con título, cuerpo
     *   vacío y botón de cierre; las funciones de JavaScript le ponen el título y el contenido.
     */
    public function test_laVentanaModalEsUnDialogoVacioConTituloYCierre(): void
    {
        ob_start();
        include RUTA_TPVFOX . '/plugins/modal/ventanaModal.php';
        $html = (string) ob_get_clean();

        $documento = new \DOMDocument();
        $documento->loadHTML('<meta charset="utf-8">' . $html, LIBXML_NOERROR);
        $xpath = new \DOMXPath($documento);

        self::assertSame(1, $xpath->query('//div[@id="ventanaModal"][contains(@class,"modal")]')->length);
        self::assertSame('Titulo Provisorio...', trim($xpath->query('//*[contains(@class,"modal-title")]')->item(0)->textContent));
        self::assertSame('', trim($xpath->query('//div[contains(@class,"modal-body")]')->item(0)->textContent));
        self::assertSame(2, $xpath->query('//button[@data-dismiss="modal"]')->length);
    }

    private static function conAvisos(): object
    {
        return new class {
            use \MontarAdvertenciaTrait;
        };
    }

    private static function conReparto(): object
    {
        return new class {
            use \DesglosaDatosPorNombreTrait;
        };
    }
}

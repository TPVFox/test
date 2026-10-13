<?php
/**
 * Los parámetros del listado de productos relacionados: qué se hace con lo que llega en la
 * dirección cuando no es lo que el listado ofrece. Los casos T1 a T13 de este fichero
 * probaban el filtrado en PHP y se retiraron al pasar el listado a paginar en la consulta;
 * su comportamiento lo cubre ahora `RelacionesLecturaIntegracionTest`.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModRelaciones;

use PHPUnit\Framework\TestCase;

final class RelacionesListadoTest extends TestCase
{
    protected function setUp(): void
    {
        require_once RUTA_TPVFOX . '/modulos/mod_relaciones/funciones.php';
    }

    public function test_T14_losParametrosQueElListadoOfreceSeAceptanTalCual(): void
    {
        $recibidos = ['pagina' => '3', 'buscar' => 'pollo de corral', 'filtro' => 'Despiece', 'orden' => 'numRelacion:DESC'];

        $parametros = parametrosDelListado($recibidos, ['Despiece', 'Elaboración']);

        self::assertSame(['pagina' => 3, 'buscar' => 'pollo de corral', 'filtro' => 'Despiece', 'orden' => 'numRelacion:DESC'], $parametros);
    }

    public function test_T15_sinParametrosSeEmpiezaEnLaPrimeraPaginaSinBusquedaNiFiltro(): void
    {
        self::assertSame(['pagina' => 1, 'buscar' => '', 'filtro' => '', 'orden' => ''], parametrosDelListado([], ['Despiece']));
    }

    /**
     * @comportamiento La página tiene que ser un número de página. Cualquier otra cosa —un
     *   texto, un negativo, un decimal, una lista— se trata como la primera, sin error.
     */
    public function test_T16_unaPaginaQueNoEsUnNumeroDePaginaEsLaPrimera(): void
    {
        foreach (['abc', '-5', '0', '2.5', '1e3', '', ' 2', '99999999999999999999', ['2']] as $pagina) {
            self::assertSame(1, parametrosDelListado(['pagina' => $pagina], [])['pagina'], json_encode($pagina));
        }
    }

    /**
     * @comportamiento De lo que se escribe en la búsqueda solo se conservan letras, números,
     *   espacios y guiones. El texto se vuelve a pintar en los enlaces del paginador, y con
     *   eso no puede llevar marcas ni comillas.
     */
    public function test_T17_deLaBusquedaSoloQuedanLetrasNumerosEspaciosYGuiones(): void
    {
        $limpio = parametrosDelListado(['buscar' => '"><script>alert(1)</script>'], [])['buscar'];
        $conAcentos = parametrosDelListado(['buscar' => "  jamón ibérico-2 'ñu' & más  "], [])['buscar'];

        self::assertSame('scriptalert1script', $limpio);
        self::assertSame('jamón ibérico-2 ñu  más', $conAcentos);
        self::assertSame('', parametrosDelListado(['buscar' => ['x']], [])['buscar']);
        self::assertSame(60, mb_strlen(parametrosDelListado(['buscar' => str_repeat('a', 500)], [])['buscar']));
    }

    public function test_T18_unTipoOUnOrdenQueElListadoNoOfreceSeTratanComoAusentes(): void
    {
        $parametros = parametrosDelListado(['filtro' => '"><script>', 'orden' => 'a.id; DROP TABLE relaciones'], ['Despiece']);

        self::assertSame('', $parametros['filtro']);
        self::assertSame('', $parametros['orden']);
        self::assertSame('', parametrosDelListado(['filtro' => 'despiece'], ['Despiece'])['filtro']);
    }
}

<?php
/**
 * El listado de productos relacionados: cómo se componen sus filas a partir de las relaciones
 * que rigen, una por relación, y cómo se les aplican la búsqueda, el filtro, el orden y la página. También qué
 * hace con los parámetros de la dirección que no son los que el listado ofrece.
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

    public function test_T1_cadaRelacionOcupaUnaFilaConSuNumeroTipoVersionFechaYCuantosProductos(): void
    {
        $filas = filasDelListado([$this->despieceDelPollo()]);

        self::assertCount(1, $filas);
        self::assertSame(7, $filas[0]['numRelacion']);
        self::assertSame('Pollo entero', $filas[0]['relacion']);
        self::assertSame('Despiece', $filas[0]['tipo']);
        self::assertSame('1.2', $filas[0]['version']);
        self::assertSame('2026-03-01 09:00:00', $filas[0]['fecha']);
        self::assertSame(3, $filas[0]['numProductos']);
    }

    public function test_T2_unProductoQueEstaEnDosRelacionesNoAnadeFilas(): void
    {
        $filas = filasDelListado([$this->despieceDelPollo(), $this->elaboracionDeLaCroqueta()]);

        self::assertSame([7, 12], array_column($filas, 'numRelacion'));
    }

    /**
     * @comportamiento Una relación sin nombre se presenta con el del producto que está solo
     *   en su lado: el que se despieza, o el que se elabora.
     */
    public function test_T3_unaRelacionSinNombreSeLlamaComoElProductoQueEstaSoloEnSuLado(): void
    {
        $despiece = $this->despieceDelPollo();
        $despiece['nombre'] = null;
        $elaboracion = $this->elaboracionDeLaCroqueta();
        $elaboracion['nombre'] = '';

        $filas = filasDelListado([$despiece, $elaboracion]);

        self::assertSame(['Pollo', 'Croqueta'], array_column($filas, 'relacion'));
    }

    public function test_T4_sinRelacionesNoHayFilas(): void
    {
        self::assertSame([], filasDelListado([]));
    }

    public function test_T5_laBusquedaCasaConElNombreDeLaRelacionSinMirarMayusculas(): void
    {
        $filas = $this->todasLasFilas();

        self::assertSame([12], array_column(filtrarFilasDelListado($filas, 'CROQUETAS', ''), 'numRelacion'));
    }

    /**
     * @comportamiento Buscar un producto devuelve las relaciones en las que está, entre o
     *   salga, aunque no figure en su nombre. Es como se sabe desde el listado qué
     *   relaciones tiene un producto que solo es ingrediente.
     */
    public function test_T6_buscarUnProductoDevuelveLasRelacionesEnLasQueEntraOSale(): void
    {
        $filas = $this->todasLasFilas();

        self::assertSame([7, 12], array_column(filtrarFilasDelListado($filas, 'pechuga', ''), 'numRelacion'));
        self::assertSame([7], array_column(filtrarFilasDelListado($filas, 'alas', ''), 'numRelacion'));
        self::assertSame([7], array_column(filtrarFilasDelListado($filas, 'pechuga entero', ''), 'numRelacion'));
        self::assertSame([], filtrarFilasDelListado($filas, 'pechuga ternera', ''));
    }

    public function test_T7_elFiltroDejaSoloLasRelacionesDeEseTipo(): void
    {
        $filas = filtrarFilasDelListado($this->todasLasFilas(), '', 'Elaboración');

        self::assertSame([12], array_column($filas, 'numRelacion'));
    }

    public function test_T8_busquedaYFiltroALaVezAcotanPorLosDos(): void
    {
        self::assertSame([7], array_column(filtrarFilasDelListado($this->todasLasFilas(), 'pechuga', 'Despiece'), 'numRelacion'));
        self::assertSame([], filtrarFilasDelListado($this->todasLasFilas(), 'alas', 'Elaboración'));
    }

    public function test_T9_sinBusquedaNiFiltroQuedanTodasLasFilas(): void
    {
        self::assertCount(2, filtrarFilasDelListado($this->todasLasFilas(), '', ''));
    }

    /**
     * @comportamiento El número se ordena como número: la relación 9 va antes que la 10.
     */
    public function test_T10_sinOrdenPedidoSeOrdenaPorNumeroDeRelacion(): void
    {
        $filas = ordenarFilasDelListado($this->variasFilas([10, 9, 100, 2]), '');

        self::assertSame([2, 9, 10, 100], array_column($filas, 'numRelacion'));
    }

    public function test_T11_seOrdenaPorNumeroOPorFechaEnLosDosSentidos(): void
    {
        $filas = $this->todasLasFilas();

        self::assertSame([12, 7], array_column(ordenarFilasDelListado($filas, 'numRelacion:DESC'), 'numRelacion'));
        self::assertSame([12, 7], array_column(ordenarFilasDelListado($filas, 'fecha:ASC'), 'numRelacion'));
        self::assertSame([7, 12], array_column(ordenarFilasDelListado($filas, 'fecha:DESC'), 'numRelacion'));
    }

    public function test_T12_laPaginaTraeSusFilasYLaUltimaLasQueQueden(): void
    {
        $filas = ordenarFilasDelListado($this->variasFilas([1, 2, 3, 4, 5]), '');

        self::assertSame([1, 2], array_column(paginaDeFilasDelListado($filas, 1, 2), 'numRelacion'));
        self::assertSame([5], array_column(paginaDeFilasDelListado($filas, 3, 2), 'numRelacion'));
        self::assertSame(3, ultimaPaginaDelListado(5, 2));
        self::assertSame(1, ultimaPaginaDelListado(0, 2));
        self::assertSame(1, ultimaPaginaDelListado(2, 2));
    }

    /**
     * @comportamiento Entre todas las páginas están todas las filas y ninguna dos veces: lo
     *   que no se ve en una página está en otra.
     */
    public function test_T13_entreTodasLasPaginasEstanTodasLasFilasUnaSolaVez(): void
    {
        $filas = ordenarFilasDelListado($this->variasFilas([1, 2, 3, 4, 5]), '');

        $vistas = [];
        for ($pagina = 1; $pagina <= ultimaPaginaDelListado(count($filas), 2); $pagina++) {
            $vistas = array_merge($vistas, paginaDeFilasDelListado($filas, $pagina, 2));
        }

        self::assertSame($filas, $vistas);
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

    private function todasLasFilas(): array
    {
        return filasDelListado([$this->despieceDelPollo(), $this->elaboracionDeLaCroqueta()]);
    }

    /** Tantas relaciones como números se indiquen, todas iguales salvo en el número. */
    private function variasFilas(array $numeros): array
    {
        $relaciones = [];
        foreach ($numeros as $numero) {
            $relacion = $this->despieceDelPollo();
            $relacion['numRelacion'] = $numero;
            $relaciones[] = $relacion;
        }

        return filasDelListado($relaciones);
    }

    private function despieceDelPollo(): array
    {
        return [
            'id' => 1, 'numRelacion' => 7, 'idTipo' => 1, 'tipo' => 'Despiece', 'forma' => '1:N',
            'nombre' => 'Pollo entero', 'descripcion' => null, 'diasViabilidad' => null,
            'version' => '1.2', 'fechaCreacion' => '2026-03-01 09:00:00',
            'lineas' => [$this->linea(10, 'Pollo', 'entrada'), $this->linea(11, 'Pechuga', 'salida'), $this->linea(12, 'Alas', 'salida')],
        ];
    }

    private function elaboracionDeLaCroqueta(): array
    {
        return [
            'id' => 2, 'numRelacion' => 12, 'idTipo' => 2, 'tipo' => 'Elaboración', 'forma' => 'N:1',
            'nombre' => 'Croquetas caseras', 'descripcion' => null, 'diasViabilidad' => 3,
            'version' => '1.0', 'fechaCreacion' => '2026-01-10 09:00:00',
            'lineas' => [$this->linea(11, 'Pechuga', 'entrada'), $this->linea(13, 'Croqueta', 'salida')],
        ];
    }

    private function linea(int $idArticulo, string $articulo, string $sentido): array
    {
        return ['idArticulo' => $idArticulo, 'articulo' => $articulo, 'sentido' => $sentido, 'cantidad' => '1.000000', 'unidad' => 'kg', 'estado' => 'Activo'];
    }
}

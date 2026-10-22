<?php

/**
 * La paginación común de los listados (`plugins/paginacion/ClasePaginacion.php`): lee de la dirección
 * la página, lo buscado y el filtro; compone la condición, el orden y el límite de la consulta del
 * listado; y pinta los enlaces de páginas, el formulario de búsqueda, el de filtro y las flechas de
 * orden.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use PHPUnit\Framework\TestCase;

final class PaginacionTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $getOriginal = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/plugins/paginacion/ClasePaginacion.php';
        $this->getOriginal = $_GET;
    }

    protected function tearDown(): void
    {
        $_GET = $this->getOriginal;
        parent::tearDown();
    }

    /** @comportamiento De la dirección toma la página —y con ella desde qué registro se lee—, lo buscado y el filtro. */
    public function test_deLaDireccionTomaPaginaBusquedaYFiltro(): void
    {
        $paginacion = self::paginacion(['pagina' => '3', 'buscar' => 'lomo cerdo', 'filtro' => 'Guardado']);

        self::assertSame('./ListaPrueba.php?', $paginacion->GetLinkBase());
        self::assertSame('3', $paginacion->GetPagActual());
        self::assertSame(80, $paginacion->GetDesde());
        self::assertSame(40, $paginacion->GetLimite());
        self::assertSame('lomo cerdo', $paginacion->GetBusqueda());
        self::assertSame(['lomo', 'cerdo'], $paginacion->arrayBusqueda);
        self::assertSame('Guardado', $paginacion->valorFiltro);
    }

    /**
     * @comportamiento Con el total de registros calcula las páginas, de 40 en 40, y el límite de la
     *   consulta, que solo se pone si hay más de una página.
     */
    public function test_conElTotalCalculaLasPaginasYElLimite(): void
    {
        $paginacion = self::paginacion(['pagina' => '2']);

        $paginacion->SetCantidadRegistros(100);

        self::assertSame(3, $paginacion->TotalPaginas);
        self::assertSame(' LIMIT 40 OFFSET 40', $paginacion->GetLimitConsulta());
        self::assertSame('<div>Encontrados 100</div>', $paginacion->InformacionPaginado());

        $una = self::paginacion();
        $una->SetCantidadRegistros(40);
        self::assertSame(1, $una->TotalPaginas);
        self::assertSame('', $una->GetLimitConsulta());
        self::assertSame('', $una->htmlPaginado(), 'Con una página no hay enlaces');
    }

    /**
     * @comportamiento Alrededor de la página actual enlaza hasta cinco anteriores y cinco siguientes,
     *   más tres saltos en bloque hacia el principio o el final cuando quedan lejos.
     */
    public function test_enlazaLasPaginasCercanasYSaltosEnBloque(): void
    {
        $paginacion = self::paginacion(['pagina' => '40']);
        $paginacion->SetCantidadRegistros(80 * 40);

        $paginas = $paginacion->GetPaginas();

        self::assertSame([39, 38, 37, 36, 35], array_slice(array_values($paginas['previo']), 0, 5));
        self::assertSame([41, 42, 43, 44, 45], array_slice(array_values($paginas['next']), 0, 5));
        self::assertCount(8, $paginas['previo']);
        self::assertCount(8, $paginas['next']);
        self::assertSame(1, $paginas['inicio']);
        self::assertSame(80, $paginas['ultima']);
    }

    /** @comportamiento Los enlaces de página llevan lo buscado y el filtro, y marcan la página actual. */
    public function test_losEnlacesDePaginaLlevanLaBusquedaYElFiltro(): void
    {
        $paginacion = self::paginacion(['pagina' => '2', 'buscar' => 'lomo', 'filtro' => 'Guardado']);
        $paginacion->SetCantidadRegistros(100);

        $html = $paginacion->htmlPaginado();

        self::assertStringStartsWith('<ul class="pagination"><li><a href="./ListaPrueba.php?buscar=lomo&pagina=&filtro=Guardado&pagina=1">Inicio</a></li>', $html);
        self::assertStringContainsString('<li class="active"><a>2</a></li>', $html);
        self::assertStringEndsWith('&filtro=Guardado&pagina=3">Ultima</a></li></ul>', $html);
    }

    /**
     * @comportamiento La condición de la búsqueda pone, por cada campo, un LIKE por palabra unidos por
     *   AND; los campos se unen por el operador pedido, y el conjunto queda entre dos pares de
     *   paréntesis. El filtro añade la igualdad de su campo, con el valor escapado, y el orden va
     *   detrás.
     */
    public function test_laCondicionUneBusquedaFiltroYOrden(): void
    {
        $paginacion = self::paginacion(['buscar' => 'lomo cerdo', 'filtro' => 'Gu"ardado']);
        $paginacion->SetCamposControler(['a.nombre', 'a.ref']);
        $paginacion->SetCampoFiltro('a.estado');
        $paginacion->SetOrderConsulta('a.nombre');

        self::assertSame(
            'WHERE ( ((a.nombre LIKE "%lomo%"  AND a.nombre LIKE "%cerdo%" ) AND (a.ref LIKE "%lomo%"  AND a.ref LIKE "%cerdo%" )) ) '
            . 'AND a.estado = "Gu\\"ardado"  ORDER BY a.nombre DESC',
            $paginacion->GetFiltroWhere()
        );
        self::assertStringContainsString(') OR (', $paginacion->GetFiltroWhere('OR'));
    }

    /** @comportamiento Sin búsqueda ni filtro, la condición es solo el orden; sin orden, nada. */
    public function test_sinBusquedaNiFiltroLaCondicionEsElOrden(): void
    {
        $paginacion = self::paginacion();
        $paginacion->SetCamposControler(['a.nombre']);

        self::assertSame('', $paginacion->GetFiltroWhere());
        $paginacion->SetOrderConsulta(['a.fecha DESC', 'a.id']);
        self::assertSame(' ORDER BY a.fecha DESC, a.id', $paginacion->GetFiltroWhere());
    }

    /**
     * @comportamiento El orden que llega como «campo:dirección» se aplica con esa dirección si es ASC o
     *   DESC; con otra, la de por defecto, DESC. Sin campo, se queda el que había.
     */
    public function test_elOrdenDeLaDireccionSeAplicaConSuSentido(): void
    {
        $asc = self::paginacion();
        $asc->SetOrderByConsulta('a.Fecha:asc');
        self::assertSame(' ORDER BY a.Fecha ASC', $asc->GetFiltroWhere());

        $otra = self::paginacion();
        $otra->SetOrderByConsulta('a.Fecha:arriba');
        self::assertSame(' ORDER BY a.Fecha DESC', $otra->GetFiltroWhere());

        $sinCampo = self::paginacion();
        $sinCampo->SetCampoOrd('a.id');
        $sinCampo->SetOrderByConsulta(':ASC');
        self::assertSame(' ORDER BY a.id ASC', $sinCampo->GetFiltroWhere());
    }

    /** @comportamiento El formulario de búsqueda y el de filtro escriben lo buscado escapado, y el filtro marca la opción elegida. */
    public function test_losFormulariosEscribenLoBuscadoEscapado(): void
    {
        $paginacion = self::paginacion(['buscar' => 'lomo "ibérico"', 'filtro' => '1']);

        self::assertStringContainsString('value="lomo &quot;ibérico&quot;"', $paginacion->htmlBuscar());
        $filtro = $paginacion->htmlFiltrar(['1' => 'Activo', '0' => 'Baja'], 'Estado', 'Boolean');
        self::assertStringContainsString('<label>Estado</label>', $filtro);
        self::assertStringContainsString('<option value="1" selected>Activo</option>', $filtro);
        self::assertStringContainsString('<option value="0">Baja</option>', $filtro);
    }

    /**
     * @comportamiento Las flechas de orden enlazan el sentido contrario al aplicado en la columna
     *   ordenada, con un aspa para quitar el orden si llegó por la dirección; en las demás, una flecha
     *   para ordenar.
     */
    public function test_lasFlechasDeOrdenEnlazanElSentidoContrario(): void
    {
        $paginacion = self::paginacion(['orden' => 'a.fecha:ASC']);
        $paginacion->SetOrderByConsulta('a.fecha:ASC');

        $ordenada = $paginacion->htmlordenar('a.fecha');
        $otra = $paginacion->htmlordenar('a.nombre');

        self::assertStringContainsString('title="Orden descendente" href="./ListaPrueba.php?orden=a.fecha:DESC"', $ordenada);
        self::assertStringContainsString('title="Quitar orden" href="./ListaPrueba.php?"', $ordenada);
        self::assertStringContainsString('title="Ordenar" href="./ListaPrueba.php?orden=a.nombre:DESC"', $otra);
    }

    /**
     * @comportamiento En la primera página «Inicio» va activo; en una intermedia se enlazan las
     *   anteriores y las siguientes, las lejanas con «&gt;»; en la última, «Ultima» va activo. Sin
     *   búsqueda, los enlaces solo llevan la página; con orden en la dirección, también el orden.
     */
    public function test_losEnlacesSegunLaPaginaEnQueSeEsta(): void
    {
        $primera = self::paginacion(['pagina' => '1']);
        $primera->SetCantidadRegistros(40 * 30);
        $htmlPrimera = $primera->htmlPaginado();
        self::assertStringStartsWith('<ul class="pagination"><li class="active"><a>Inicio</a></li><li><a href="./ListaPrueba.php?pagina=2">2</a></li>', $htmlPrimera);
        self::assertStringContainsString('">&gt;', $htmlPrimera, 'Los saltos lejanos llevan «&gt;»');

        $intermedia = self::paginacion(['pagina' => '8', 'orden' => 'a.fecha:ASC']);
        $intermedia->SetOrderByConsulta('a.fecha:ASC');
        $intermedia->SetCantidadRegistros(40 * 30);
        $htmlIntermedia = $intermedia->htmlPaginado();
        self::assertStringContainsString('<li><a href="./ListaPrueba.php?pagina=&orden=a.fecha:ASC&pagina=7">7</a></li>', $htmlIntermedia);
        self::assertStringContainsString('<li class="active"><a>8</a></li>', $htmlIntermedia);

        $ultima = self::paginacion(['pagina' => '30']);
        $ultima->SetCantidadRegistros(40 * 30);
        self::assertStringEndsWith('<li class="active"><a>Ultima</a></li></ul>', $ultima->htmlPaginado());
    }

    /**
     * @comportamiento Con lo buscado y el filtro, las flechas de orden los conservan; la columna
     *   ordenada de forma descendente enlaza la ascendente, y el aspa de quitar orden los conserva.
     */
    public function test_lasFlechasConservanLaBusquedaYElFiltro(): void
    {
        $paginacion = self::paginacion(['buscar' => 'lomo', 'filtro' => 'Guardado', 'orden' => 'a.fecha:DESC']);
        $paginacion->SetOrderByConsulta('a.fecha:DESC');

        $html = $paginacion->htmlordenar('a.fecha');

        self::assertStringContainsString('title="Orden ascendente" href="./ListaPrueba.php?buscar=lomo&orden=a.fecha:ASC&filtro=Guardado"', $html);
        self::assertStringContainsString('title="Quitar orden" href="./ListaPrueba.php?buscar=lomo&filtro=Guardado"', $html);
    }

    /** @comportamiento La ruta del proyecto se deduce de la carpeta de la paginación. */
    public function test_laRutaDelProyectoSeDeduceDeSuCarpeta(): void
    {
        $paginacion = self::paginacion();

        $paginacion->obtenerRutaProyecto();

        self::assertSame(realpath(RUTA_TPVFOX) . '/', realpath($paginacion->HostNombre) . '/');
    }

    /** @comportamiento Se pueden añadir parámetros fijos a los enlaces. */
    public function test_seAnadenParametrosFijosALosEnlaces(): void
    {
        $paginacion = self::paginacion();

        $paginacion->AnahadirLinkBase('idCliente=7&');

        self::assertSame('./ListaPrueba.php?idCliente=7&', $paginacion->GetLinkBase());
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/paginacion/ClasePaginacion.php:473-500
     *
     * @group esperado
     * @group listado
     * @group medio
     *
     * @que-ocurre-hoy Buscar solo espacios deja una condición vacía entre paréntesis, que la base no
     *   admite: el listado falla en lugar de mostrarse entero.
     * @que-deberia-ocurrir Que una búsqueda sin palabras se trate como ninguna búsqueda.
     * @por-que-ocurre Lo buscado se da por bueno si no es una cadena vacía, aunque solo tenga
     *   espacios, y la composición de la condición no comprueba si quedó alguna palabra.
     * @como-deberia-funcionar Recortar lo buscado antes de darlo por bueno, o no componer la condición
     *   si no hay palabras.
     */
    public function test_unaBusquedaSoloDeEspaciosNoDejaUnaCondicionVacia(): void
    {
        $paginacion = self::paginacion(['buscar' => '   ']);

        set_error_handler(static fn (): bool => true);
        try {
            $paginacion->SetCamposControler(['a.nombre']);
            $condicion = $paginacion->GetFiltroWhere();
        } finally {
            restore_error_handler();
        }

        self::assertStringNotContainsString('()', $condicion, 'La condición no puede quedar vacía.');
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/paginacion/ClasePaginacion.php:427-444
     *
     * @group esperado
     * @group listado
     * @group alto
     *
     * @que-ocurre-hoy El campo de orden que llega por la dirección se escribe en la consulta tal cual,
     *   sin comprobar que sea una columna que el listado ofrece para ordenar.
     * @que-deberia-ocurrir Que solo se ordene por las columnas que el listado declara, y que cualquier
     *   otro valor se descarte.
     * @por-que-ocurre La clase no tiene lista de columnas ordenables: compone el orden con lo recibido,
     *   y los listados le pasan el parámetro de la dirección.
     * @como-deberia-funcionar Que el listado declare sus columnas ordenables y la clase solo acepte
     *   esas.
     */
    public function test_soloSeOrdenaPorColumnasQueElListadoOfrece(): void
    {
        $paginacion = self::paginacion(['orden' => 'columna_que_no_ofrece:ASC']);
        $paginacion->SetCamposControler(['a.nombre']);

        $paginacion->SetOrderByConsulta('columna_que_no_ofrece:ASC');

        self::assertStringNotContainsString('columna_que_no_ofrece', $paginacion->GetFiltroWhere(), 'Una columna que el listado no ofrece no llega a la consulta.');
    }

    /**
     * @estado rojo
     * @codigo-afectado plugins/paginacion/ClasePaginacion.php:245-254
     * @codigo-afectado plugins/paginacion/ClasePaginacion.php:350-352
     *
     * @group esperado
     * @group listado
     * @group medio
     *
     * @que-ocurre-hoy Lo buscado se escribe sin escapar en los enlaces de página y en las flechas de
     *   orden: unas comillas cierran el atributo del enlace y lo que sigue se interpreta como HTML.
     * @que-deberia-ocurrir Que los valores de la dirección se escriban codificados en los enlaces.
     * @por-que-ocurre El filtro se codifica con `urlencode()`, pero lo buscado se pega tal cual.
     * @como-deberia-funcionar Componer los enlaces con `http_build_query()` y escapar el atributo.
     */
    public function test_loBuscadoSeEscribeCodificadoEnLosEnlaces(): void
    {
        $paginacion = self::paginacion(['pagina' => '2', 'buscar' => 'lomo"<b>']);
        $paginacion->SetCantidadRegistros(100);

        self::assertStringNotContainsString('lomo"<b>', $paginacion->htmlPaginado(), 'En los enlaces de página.');
        self::assertStringNotContainsString('lomo"<b>', $paginacion->htmlordenar('a.nombre'), 'En las flechas de orden.');
    }

    /** @param array<string,string> $get */
    private static function paginacion(array $get = []): \PluginClasePaginacion
    {
        $_GET = $get;

        return new \PluginClasePaginacion('/tpvfox/modulos/mod_prueba/ListaPrueba.php');
    }
}

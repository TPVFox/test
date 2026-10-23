<?php

/**
 * Las funciones de pintado de las familias (`modulos/mod_familia/funciones.php`) que no consultan la
 * base: las filas del listado, la tabla de las hijas de una familia, el panel desplegable y la tabla
 * de sus relaciones con la tienda web.
 *
 * El fichero incluye la clase de familias y la de artículos, que cargan los complementos y la clase
 * base: cada caso corre en su propio proceso, para no repetir declaraciones en el de la suite.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModFamilia;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesFamiliaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . '/modulos/mod_familia/funciones.php';
    }

    /**
     * @comportamiento Cada familia del listado es una fila con su casilla, su número, un botón que lleva
     *   a su ficha, su padre con su nombre, sus hijas y sus productos; una fila oculta recibe sus hijas.
     */
    public function test_cadaFamiliaDelListadoEsUnaFilaConSusDatos(): void
    {
        $html = \familias2Html([$this->familia(['idFamilia' => 7, 'familiaNombre' => 'Frutas secas', 'familiaPadre' => 3, 'nombrepadre' => 'Frutas', 'hijos' => 0, 'productos' => 2])]);

        self::assertStringContainsString('<tr id="fila0-7"  data-idfamilia="7">', $html);
        self::assertStringContainsString('id="check7"', $html);
        self::assertStringContainsString("onclick=\"window.location.href='familia.php?id=7'\">Frutas secas</button>", $html);
        self::assertStringContainsString('<td> 3 (Frutas)</td>', $html);
        self::assertStringContainsString('<td>2 productos </td>', $html);
        self::assertStringContainsString('<tr id="fila-7" style="display:none">', $html);
    }

    /**
     * @comportamiento Una familia con hijas lleva los botones de expandir y compactar; una sin hijas, el
     *   de marcarla para borrar, con su número de productos.
     */
    public function test_conHijasSeExpandeYSinHijasSeMarcaParaBorrar(): void
    {
        $html = \familias2Html([
            $this->familia(['idFamilia' => 1, 'hijos' => 2]),
            $this->familia(['idFamilia' => 2, 'hijos' => 0, 'productos' => 5]),
        ]);

        self::assertStringContainsString('onclick="expandir(1)"', $html);
        self::assertStringContainsString('onclick="compactar(1)"', $html);
        self::assertStringContainsString('onclick="marcarFamiliaEliminar(5, 2)"', $html);
        self::assertStringNotContainsString('onclick="marcarFamiliaEliminar(0, 1)"', $html);
        self::assertStringContainsString('<td> </td>', $html, 'Una familia de la raíz no escribe padre');
    }

    /** @comportamiento Sin familias, el listado no pinta ninguna fila. */
    public function test_sinFamiliasNoSePintaNinguna(): void
    {
        self::assertSame('', \familias2Html([]));
    }

    /**
     * @comportamiento Las hijas de una familia se pintan con su número, su nombre, sus hijas y sus
     *   productos; con la tienda web, también su relación y la papelera para quitarla.
     */
    public function test_lasHijasSePintanConSuRelacionConLaWeb(): void
    {
        $hija = $this->familia(['idFamilia' => 8, 'familiaNombre' => 'Nueces', 'hijos' => 0, 'productos' => 1])
            + ['familiaTienda' => [['idTienda' => '4', 'idFamilia_tienda' => '77', 'estado' => '1', 'link_front_end_categoria' => 'https://web/77']]];

        $sinWeb = \htlmLineasFamiliasHijas([$hija], 0);
        $conWeb = \htlmLineasFamiliasHijas([$hija], '4');

        self::assertSame('<tr id="Relacion0"><td>8</td><td> Nueces </td><td> 0</td><td>1</td></tr>', $sinWeb);
        self::assertStringContainsString('<a href="https://web/77"><span title="Id de familia web 77" class="glyphicon glyphicon-globe"></span></a>', $conWeb);
        self::assertStringContainsString('onclick="EliminarReferenciaTienda(8,4,this)"', $conWeb);
        self::assertSame('', \htlmLineasFamiliasHijas([], 4));
    }

    /**
     * @comportamiento La relación de una hija sin publicar en la web se pinta con su icono y su título,
     *   sin enlace.
     */
    public function test_unaHijaSinPublicarSePintaSinEnlace(): void
    {
        $hija = $this->familia(['idFamilia' => 8, 'familiaNombre' => 'Nueces'])
            + ['familiaTienda' => [['idTienda' => '4', 'idFamilia_tienda' => '77', 'estado' => '0']]];

        $html = \htlmLineasFamiliasHijas([$hija], '4');

        self::assertStringContainsString('<span title="No publico el Id web 77" class="glyphicon glyphicon-globe"></span>', $html);
        self::assertStringNotContainsString('<a href=', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/funciones.php:116-122
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy El icono de la relación de una hija con error en la web pega su título y su
     *   estilo sin espacio (`title="..."style="color:red"`): la etiqueta queda mal formada.
     * @que-deberia-ocurrir Que los atributos del icono estén separados.
     * @por-que-ocurre El estilo se compone sin el espacio que lo separa del título.
     * @como-deberia-funcionar Separar los atributos con un espacio.
     */
    public function test_elIconoDeUnaHijaConErrorEstaBienFormado(): void
    {
        $hija = $this->familia(['idFamilia' => 8, 'familiaNombre' => 'Nueces'])
            + ['familiaTienda' => [['idTienda' => '4', 'idFamilia_tienda' => '77', 'estado' => '-1']]];

        $html = \htlmLineasFamiliasHijas([$hija], '4');

        self::assertStringContainsString('title="ERROR OBTENER Id de familia web 77" style="color:red"', $html);
    }

    /** @comportamiento La tabla de las hijas añade la columna de la web solo con tienda web, y el botón de subirlas al final. */
    public function test_laTablaDeLasHijasLlevaLaColumnaWebSoloConTienda(): void
    {
        $sinWeb = \htmlTablaFamiliasHijas([], 0, '<a>Subir</a>');
        $conWeb = \htmlTablaFamiliasHijas([], 4, '');

        self::assertStringNotContainsString('Tienda Web', $sinWeb);
        self::assertStringEndsWith('</table>  <a>Subir</a>', $sinWeb);
        self::assertStringContainsString('<th title="Tienda Web 4"> Web</th>', $conWeb);
    }

    /** @comportamiento Un panel desplegable lleva su título como enlace que abre su cuerpo, con su número. */
    public function test_elPanelDesplegableAbreSuCuerpo(): void
    {
        $html = \htmlPanelDesplegable(3, 'Hijas', '<p>cuerpo</p>');

        self::assertStringContainsString('<a data-toggle="collapse" href="#collapse3">Hijas</a>', $html);
        self::assertStringContainsString('<div id="collapse3" class="panel-collapse collapse"><div class="panel-body"><p>cuerpo</p></div>', $html);
    }

    /** @comportamiento Sin relaciones con la web, la tabla ofrece añadir una; con ellas, pinta una fila por relación. */
    public function test_laTablaDeRelacionesOfreceAnadirSoloSinNinguna(): void
    {
        $vacia = \htmlTablaRefTiendas([], 'https://web/?id=');
        $conUna = \htmlTablaRefTiendas([['idFamilia' => '5', 'idTienda' => '4', 'idFamilia_tienda' => '77']], 'https://web/?id=');

        self::assertStringContainsString('onclick="HtmlRefFamiliaTienda()"', $vacia);
        self::assertStringNotContainsString('HtmlRefFamiliaTienda', $conUna);
        self::assertStringContainsString('<tr id="ref_tienda_0"><td>4</td><td><small>77</small></td>', $conUna);
    }

    /**
     * @comportamiento La relación publicada enlaza a la categoría de la web; sin estado, o sin publicar,
     *   se pinta el icono con su título, sin enlace.
     */
    public function test_laRelacionPublicadaEnlazaALaWeb(): void
    {
        $relacion = ['idFamilia' => '5', 'idTienda' => '4', 'idFamilia_tienda' => '77'];

        $publicada = \htmlLineaRefTienda(0, $relacion + ['estado' => '1'], 'https://web/?id=', 1);
        $sinPublicar = \htmlLineaRefTienda(0, $relacion + ['estado' => '0'], 'https://web/?id=', 1);

        self::assertStringContainsString('<a href="https://web/?id=77"><span title="Publicado" class="glyphicon glyphicon-globe"></span></a>', $publicada);
        self::assertStringContainsString('<span title="No publicado" class="glyphicon glyphicon-globe"></span>', $sinPublicar);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/funciones.php:53-62
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy El listado escribe el nombre de cada familia tal como está guardado: un nombre con
     *   un script se ejecuta en la página de quien abre las familias.
     * @que-deberia-ocurrir Que el nombre se escriba como texto.
     * @por-que-ocurre Cada celda escribe el valor guardado sin escapar.
     * @como-deberia-funcionar Escapar cada valor al escribirlo en la página.
     */
    public function test_elListadoEscribeElNombreComoTexto(): void
    {
        $html = \familias2Html([$this->familia(['familiaNombre' => '<script>x=1</script>'])]);

        self::assertStringNotContainsString('<script>x=1</script>', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/funciones.php:260-293
     *
     * @group defecto
     * @group permisos
     * @group bajo
     *
     * @que-ocurre-hoy La papelera que quita una relación con la web se pinta cuando no hay permiso de
     *   borrar, y se oculta cuando lo hay.
     * @que-deberia-ocurrir Que la papelera solo se pinte con el permiso de borrar.
     * @por-que-ocurre La condición pinta la papelera si el permiso es distinto de 1.
     * @como-deberia-funcionar Pintarla solo si el permiso es 1.
     */
    public function test_laPapeleraDeLaRelacionSoloSePintaConPermiso(): void
    {
        $relacion = ['idFamilia' => '5', 'idTienda' => '4', 'idFamilia_tienda' => '77', 'estado' => '1'];

        self::assertStringNotContainsString('glyphicon-trash', \htmlLineaRefTienda(0, $relacion, 'https://web/?id=', 0), 'Sin permiso se pinta la papelera');
        self::assertStringContainsString('glyphicon-trash', \htmlLineaRefTienda(0, $relacion, 'https://web/?id=', 1), 'Con permiso no se pinta');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/funciones.php:272-278
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy El icono de una relación con error en la web pega su estilo y su título sin
     *   espacio (`style="color:red"title="error"`): la etiqueta queda mal formada.
     * @que-deberia-ocurrir Que los atributos del icono estén separados.
     * @por-que-ocurre El estilo se compone sin el espacio que lo separa del título.
     * @como-deberia-funcionar Separar los atributos con un espacio.
     */
    public function test_elIconoDeUnaRelacionConErrorEstaBienFormado(): void
    {
        $html = \htmlLineaRefTienda(0, ['idFamilia' => '5', 'idTienda' => '4', 'idFamilia_tienda' => '77', 'estado' => '-1'], 'https://web/?id=', 1);

        self::assertStringContainsString('style="color:red" title="error"', $html);
    }

    /** @return array<string, int|string> */
    private function familia(array $datos): array
    {
        return $datos + ['idFamilia' => 1, 'familiaNombre' => 'Familia', 'familiaPadre' => 0, 'nombrepadre' => null, 'hijos' => 0, 'productos' => 0];
    }
}

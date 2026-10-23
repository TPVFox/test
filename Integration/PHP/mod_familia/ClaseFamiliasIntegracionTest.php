<?php

/**
 * El servicio de familias (`modulos/mod_familia/clases/ClaseFamilias.php`): el árbol de familias, los
 * productos de cada una y su relación con la tienda web; y la clase de `clases/ClaseTablaFamilias.php`,
 * que usa la clase de artículos.
 *
 * Crear la clase carga los complementos del módulo, y el de la tienda web declara clases que otros
 * complementos repiten: cada caso corre en su propio proceso. La clase hereda de la clase base de
 * acceso a datos: el caso le presta su conexión, para que vea lo que siembra y su escritura se deshaga
 * al terminar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModFamilia;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ClaseFamiliasIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $_SERVER['DOCUMENT_ROOT'] = '';
        $_SERVER['PHP_SELF'] = '/TPVFox/modulos/mod_familia/ListaFamilias.php';
        $this->incluirTPVFox('/clases/ClaseConexion.php');
        $this->incluirTPVFox('/modulos/mod_familia/clases/ClaseFamilias.php');
        $this->incluirTPVFox('/clases/ClaseTablaFamilias.php');
        $this->siembra = new Siembra($this->db);
    }

    /** @comportamiento Todas las familias se leen, cada una con sus datos. */
    public function test_todasLasFamiliasSeLeen(): void
    {
        $frutas = $this->siembra->familia('Frutas');
        $this->siembra->familia('Verduras');

        $todas = $this->familias()->todasFamilias();

        self::assertCount($this->recuento('familias'), $todas);
        self::assertContains('Frutas', array_column($todas, 'familiaNombre'));
        self::assertContains((string) $frutas, array_column($todas, 'idFamilia'));
    }

    /** @comportamiento Una familia se lee por su número, con su nombre, su padre, su beneficio y la marca del TPV. */
    public function test_unaFamiliaSeLeePorSuNumero(): void
    {
        $padre = $this->siembra->familia('Frutas');
        $id = $this->siembra->familia('Frutas secas', $padre);

        $familia = $this->familias()->buscarFamiliaId($id);

        self::assertSame('Frutas secas', $familia['familiaNombre']);
        self::assertSame((string) $padre, $familia['familiaPadre']);
        self::assertSame('1', $familia['mostrar_tpv']);
        self::assertSame(['familiaNombre' => 'Frutas secas'], $this->familias()->buscarPorId($id)['datos'][0]);
    }

    /** @comportamiento Buscar por nombre trae las familias que contienen todas las palabras buscadas. */
    public function test_laBusquedaPorNombreExigeTodasLasPalabras(): void
    {
        $this->siembra->familia('Frutas de temporada');
        $secas = $this->siembra->familia('Frutas secas');

        $encontradas = $this->familias()->buscarFamiliaNombre('frutas secas')['datos'];

        self::assertSame([(string) $secas], array_column($encontradas, 'idFamilia'));
    }

    /** @comportamiento Los ascendientes de una familia van de su padre a la raíz, que es el 0. */
    public function test_losAscendientesLleganHastaLaRaiz(): void
    {
        [$abuela, $madre, $nieta] = $this->arbol();

        $ascendientes = $this->familias()->buscarAscendientes($nieta);

        self::assertSame([(string) $madre, (string) $abuela, '0'], $ascendientes);
    }

    /** @comportamiento Los descendientes de una familia son sus hijas y las hijas de estas; los directos, solo las hijas. */
    public function test_losDescendientesSonHijasYNietas(): void
    {
        [$abuela, $madre, $nieta] = $this->arbol();
        $familias = $this->familias();

        self::assertSame([(string) $madre, (string) $nieta], $familias->buscardescendientes($abuela));
        self::assertSame([(string) $madre], array_column($familias->descendientes($abuela)['datos'], 'idFamilia'));
        self::assertSame('1', $familias->contarHijos($abuela));
    }

    /** @comportamiento Leer una familia la trae con el número de sus hijas. */
    public function test_leerTraeLaFamiliaConSuNumeroDeHijas(): void
    {
        [$abuela] = $this->arbol();

        $leida = $this->familias()->leer($abuela)['datos'][0];

        self::assertSame((string) $abuela, $leida['idFamilia']);
        self::assertSame('1', $leida['hijos']);
    }

    /** @comportamiento Las hijas de un padre salen ordenadas por nombre, con el nombre del padre. */
    public function test_lasHijasDeUnPadreSalenOrdenadasYConElNombreDelPadre(): void
    {
        $padre = $this->siembra->familia('Bebidas');
        $this->siembra->familia('Zumos', $padre);
        $this->siembra->familia('Aguas', $padre);

        $hijas = $this->familias()->leerUnPadre($padre)['datos'];

        self::assertSame(['Aguas', 'Zumos'], array_column($hijas, 'familiaNombre'));
        self::assertSame(['Bebidas', 'Bebidas'], array_column($hijas, 'nombrepadre'));
    }

    /** @comportamiento Grabar con el número 0 da de alta la familia; con su número, la modifica. */
    public function test_grabarConNumeroCeroDaDeAltaYConSuNumeroModifica(): void
    {
        $familias = $this->familias();

        $familias->grabar($this->datos(['familiaNombre' => 'Lácteos']));
        $id = (int) $this->db->insert_id;
        $familias->grabar($this->datos(['idFamilia' => $id, 'familiaNombre' => 'Lácteos y huevos', 'beneficiomedio' => '30', 'mostrar_tpv' => 0]));

        self::assertSame(
            ['familiaNombre' => 'Lácteos y huevos', 'familiaPadre' => '0', 'beneficiomedio' => '30.00', 'mostrar_tpv' => '0'],
            $this->fila($id, 'familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv')
        );
    }

    /** @comportamiento La lista de padres trae todas las familias, ordenadas si se pide y con la raíz delante si se pide. */
    public function test_laListaDePadresLlevaLaRaizDelanteSiSePide(): void
    {
        $this->siembra->familia('Verduras');
        $this->siembra->familia('Frutas');

        $padres = $this->familias()->todoslosPadres('familiaNombre', true)['datos'];

        self::assertSame(0, $padres[0]['idFamilia']);
        self::assertCount($this->recuento('familias') + 1, $padres);
        $nombres = array_column(array_slice($padres, 1), 'familiaNombre');
        $ordenados = $nombres;
        sort($ordenados);
        self::assertSame($ordenados, $nombres);
    }

    /** @comportamiento Las familias que pueden ser padre de una son todas menos ella y sus descendientes. */
    public function test_losPosiblesPadresExcluyenLaFamiliaYSusDescendientes(): void
    {
        [$abuela, $madre, $nieta] = $this->arbol();
        $otra = $this->siembra->familia('Otra');

        $posibles = array_column($this->familias()->familiasSinDescendientes($madre, true)['datos'], 'idFamilia');

        self::assertContains(0, $posibles);
        self::assertContains((string) $abuela, $posibles);
        self::assertContains((string) $otra, $posibles);
        self::assertNotContains((string) $madre, $posibles);
        self::assertNotContains((string) $nieta, $posibles);
    }

    /** @comportamiento Un producto se guarda en una familia, se comprueba, se cuenta y se lista con su nombre. */
    public function test_unProductoSeGuardaEnUnaFamiliaYSeLista(): void
    {
        $familia = $this->siembra->familia('Conservas');
        $atun = $this->siembra->articulo('Atún en aceite');
        $familias = $this->familias();

        $familias->guardarProductoFamilia($atun, $familia);

        self::assertCount(1, $familias->comprobarRegistro($atun, $familia)['datos']);
        self::assertSame('1', $familias->contarProductos($familia));
        self::assertSame([['articulo_name' => 'Atún en aceite', 'idArticulo' => (string) $atun, 'idFamilia' => (string) $familia]], $familias->buscarProductosFamilias($familia)['datos']);
        self::assertSame([['nombreFamilia' => 'Conservas']], $familias->familiaDeProducto($atun)['datos']);
    }

    /** @comportamiento Los productos sin familia son los que no están en ninguna. */
    public function test_losProductosSinFamiliaSonLosQueNoEstanEnNinguna(): void
    {
        $familia = $this->siembra->familia('Conservas');
        $conFamilia = $this->siembra->articulo('Atún en aceite', ['familia' => $familia]);
        $sinFamilia = $this->siembra->articulo('Sal');

        $sin = array_column($this->familias()->buscarProductosSinFamilias()['datos'], 'idArticulo');

        self::assertContains((string) $sinFamilia, $sin);
        self::assertNotContains((string) $conFamilia, $sin);
    }

    /** @comportamiento Las familias que se enseñan en el TPV son las marcadas, todas o las hijas de un padre. */
    public function test_lasFamiliasDelTpvSonLasMarcadas(): void
    {
        $padre = $this->siembra->familia('Panadería');
        $hija = $this->siembra->familia('Bollería', $padre);
        $oculta = $this->siembra->familia('Harinas', $padre);
        $this->db->query('UPDATE familias SET mostrar_tpv = 0 WHERE idFamilia = ' . $oculta);
        $familias = $this->familias();

        $todas = array_column($familias->buscarFamilisMostrarTpv()['datos'], 'idFamilia');
        $deUnPadre = array_column($familias->buscarFamilisMostrarTpv($padre)['datos'], 'idFamilia');

        self::assertContains((string) $padre, $todas);
        self::assertNotContains((string) $oculta, $todas);
        self::assertSame([(string) $hija], $deUnPadre);
    }

    /** @comportamiento Borrar una familia la quita de la tabla. */
    public function test_borrarUnaFamiliaLaQuita(): void
    {
        $id = $this->siembra->familia('Temporal');

        $respuesta = $this->familias()->Borrar($id);

        self::assertSame('0', $respuesta['error']);
        self::assertSame(0, $this->recuento('familias', 'idFamilia = ' . $id));
    }

    /** @comportamiento La relación con la tienda web se guarda, se lee en los dos sentidos y se borra. */
    public function test_laRelacionConLaTiendaWebSeGuardaSeLeeYSeBorra(): void
    {
        $tienda = $this->siembra->tienda($this->ano(), 'web');
        $familia = $this->siembra->familia('Frutas');
        $familias = $this->familias();

        $familias->guardarRelacionFamiliasTiendas($familia, $tienda, 77);

        self::assertSame([['idFamilia' => (string) $familia, 'idFamilia_tienda' => '77']], $familias->obtenerRelacionFamilia($tienda, $familia)['datos']);
        self::assertSame([['idFamilia' => (string) $familia, 'idFamilia_tienda' => '77']], $familias->obtenerRelacionFamilia_tienda($tienda, 77)['datos']);
        self::assertCount(1, $familias->regRelacionFamiliaTienda($familia)['datos']);

        self::assertSame('0', $familias->BorrarRelacionFamiliasTiendas($familia, $tienda)['error']);
        self::assertSame(0, $this->recuento('familiasTienda', 'idFamilia = ' . $familia));
    }

    /** @comportamiento Dos relaciones de una familia con la misma tienda web se avisan como error. */
    public function test_unaRelacionRepetidaConLaMismaTiendaSeAvisa(): void
    {
        $tienda = $this->siembra->tienda($this->ano(), 'web');
        $familia = $this->siembra->familia('Frutas');
        $familias = $this->familias();
        $familias->addFamiliaTiendaWeb($tienda, $familia, 77);
        $familias->addFamiliaTiendaWeb($tienda, $familia, 78);

        $relacion = $familias->regRelacionFamiliaTienda($familia);

        self::assertSame('danger', $relacion['error'][0]['tipo']);
        self::assertStringContainsString('tiene duplicado una relacion', $relacion['error'][0]['mensaje']);
    }

    /** @comportamiento Los datos de una familia reúnen los suyos, sus productos, su relación con la web y sus hijas con sus recuentos. */
    public function test_losDatosDeUnaFamiliaReunenSusHijasYSusProductos(): void
    {
        [$abuela, $madre] = $this->arbol();
        $this->siembra->articulo('Manzana', ['familia' => $abuela]);

        $datos = $this->familias()->datosFamilia($abuela);

        self::assertSame('Abuela', $datos['familiaNombre']);
        self::assertSame('1', $datos['productos']);
        self::assertSame([], $datos['familiaTienda']);
        self::assertSame([(string) $madre], array_column($datos['hijos'], 'idFamilia'));
        self::assertSame('1', $datos['hijos'][0]['hijos']);
        self::assertSame('0', $datos['hijos'][0]['productos']);
    }

    /** @comportamiento El desplegable de familias marca la seleccionada. */
    public function test_elDesplegableMarcaLaSeleccionada(): void
    {
        $html = $this->familias()->htmlComboFamilias([
            ['idFamilia' => 0, 'familiaNombre' => 'Raíz'],
            ['idFamilia' => 4, 'familiaNombre' => 'Frutas'],
        ], 4);

        self::assertSame('<option value=0>Raíz</option><option value=4 selected = "selected" >Frutas</option>', $html);
    }

    /** @comportamiento El árbol jerarquizado da cada familia con su nivel —1 en la raíz— y su ruta, con las hijas tras su padre. */
    public function test_elArbolJerarquizadoPoneLasHijasTrasSuPadre(): void
    {
        [$abuela, $madre, $nieta] = $this->arbol();

        $arbol = array_values(array_filter(
            $this->familias()->getFamiliasJerarquizadas()['datos'],
            static fn (array $fila): bool => in_array((int) $fila['idFamilia'], [$abuela, $madre, $nieta], true)
        ));

        self::assertSame([(string) $abuela, (string) $madre, (string) $nieta], array_column($arbol, 'idFamilia'));
        self::assertSame(['1', '2', '3'], array_map('strval', array_column($arbol, 'nivel')));
    }

    /** @comportamiento El complemento de la tienda web se pide por el nombre de su clase; otro nombre no da nada. */
    public function test_elComplementoSePideporSuNombre(): void
    {
        $familias = $this->familias();

        self::assertInstanceOf('PluginClaseVirtuemartFamilia', $familias->SetPlugin('ClaseVirtuemartFamilia'));
        self::assertFalse($familias->SetPlugin('OtroComplemento'));
    }

    /** @comportamiento La clase de `clases/` cuenta las familias al crearse, y sin conexión no consulta nada. */
    public function test_laClaseDeClasesCuentaLasFamilias(): void
    {
        $this->siembra->familia('Frutas');

        $tabla = new \ClaseTablaFamilias($this->db);

        $recuento = (new \ReflectionProperty(\ClaseTablaFamilias::class, 'num_rows'));
        $recuento->setAccessible(true);
        self::assertSame((string) $this->recuento('familias'), (string) $recuento->getValue($tabla));
        self::assertSame([], $tabla->ArrayDatos(['idFamilia' => 1]));
        self::assertNull($recuento->getValue(new \ClaseTablaFamilias()));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/claseModeloP.php:116-131; modulos/mod_familia/clases/ClaseFamilias.php:190-197
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Una familia cuyo nombre lleva una comilla simple no se graba: la petición termina
     *   con un error de la base. Lo tecleado entra en la sentencia como parte de ella.
     * @que-deberia-ocurrir Que se grabe tal como se escribió.
     * @por-que-ocurre El alta pega cada valor entre comillas simples sin escaparlo.
     * @como-deberia-funcionar Pasar los valores como parámetros de una sentencia preparada.
     */
    public function test_unNombreConComillaSeGrabaTalCual(): void
    {
        try {
            $this->familias()->grabar($this->datos(['familiaNombre' => "Fruits d'Aragó"]));
        } catch (\mysqli_sql_exception) {
            // El error de la base es el síntoma; el caso comprueba lo guardado.
        }

        self::assertSame(1, $this->recuentoDe("Fruits d'Aragó"));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/clases/ClaseFamilias.php:81-106
     *
     * @group defecto
     * @group entrada
     * @group medio
     *
     * @que-ocurre-hoy Buscar un nombre con comillas dobles termina la petición con un error de la base.
     * @que-deberia-ocurrir Que busque el texto tal como se escribió.
     * @por-que-ocurre Cada palabra se pega entre comillas dobles en la condición, sin escaparla.
     * @como-deberia-funcionar Pasar las palabras como parámetros de una sentencia preparada.
     */
    public function test_buscarUnNombreConComillasDoblesNoRompeLaBusqueda(): void
    {
        $this->siembra->familia('Vinos "de la casa"');

        try {
            $encontradas = $this->familias()->buscarFamiliaNombre('"de');
        } catch (\mysqli_sql_exception $error) {
            self::fail('La búsqueda termina con un error de la base: ' . $error->getMessage());
        }

        self::assertCount(1, $encontradas['datos']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/clases/ClaseFamilias.php:68-79
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Un número de familia con una condición añadida —`0 OR 1=1`— trae otra familia.
     * @que-deberia-ocurrir Que traiga como mucho la familia de ese número.
     * @por-que-ocurre El número se pega a la consulta sin convertirlo.
     * @como-deberia-funcionar Tomar el número como entero, en una sentencia preparada.
     */
    public function test_unNumeroManipuladoNoTraeOtraFamilia(): void
    {
        $this->siembra->familia('Frutas');
        $familias = $this->familias();

        [$familia] = self::recogiendoAvisos(fn () => $familias->buscarFamiliaId('0 OR 1=1'));

        self::assertNull($familia, 'Trae otra familia');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/clases/ClaseFamilias.php:190-197
     *
     * @group defecto
     * @group arbol
     * @group alto
     *
     * @que-ocurre-hoy Grabar una familia con una de sus descendientes como padre se acepta: el árbol
     *   queda con un ciclo, y recorrer sus ascendientes o sus descendientes no termina.
     * @que-deberia-ocurrir Que el guardado rechace un padre que es la propia familia o una descendiente.
     * @por-que-ocurre Solo la ficha limita los padres posibles; el guardado escribe el que recibe.
     * @como-deberia-funcionar Comprobar al guardar que el padre no es la familia ni una descendiente.
     */
    public function test_unaFamiliaNoTomaComoPadreAUnaDescendiente(): void
    {
        [$abuela, , $nieta] = $this->arbol();

        $this->familias()->grabar($this->datos(['idFamilia' => $abuela, 'familiaNombre' => 'Abuela', 'familiaPadre' => $nieta]));

        self::assertSame('0', $this->fila($abuela, 'familiaPadre')['familiaPadre'], 'El árbol queda con un ciclo');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/claseModeloP.php:116-131; modulos/mod_familia/clases/ClaseFamilias.php:190-197
     *
     * @group defecto
     * @group guardado
     * @group alto
     *
     * @que-ocurre-hoy Grabar una familia con un beneficio que no es un número —el campo vacío, por
     *   ejemplo— termina la petición con un error de la base, y quien graba no sabe qué ha pasado.
     * @que-deberia-ocurrir Que el guardado devuelva un error propio.
     * @por-que-ocurre La base avisa con una excepción y nadie la recoge.
     * @como-deberia-funcionar Comprobar el valor antes de guardarlo y recoger el fallo de la base.
     */
    public function test_unGuardadoQueLaBaseRechazaNoTerminaLaPeticion(): void
    {
        try {
            $this->familias()->grabar($this->datos(['familiaNombre' => 'Sin beneficio', 'beneficiomedio' => '']));
        } catch (\mysqli_sql_exception $error) {
            self::fail('La petición termina con un error de la base: ' . $error->getMessage());
        }

        self::assertTrue(\ModeloP::hayErrorConsulta());
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/clases/ClaseFamilias.php:321-336
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Los productos de una familia pedidos con un límite son siempre 30, se pida el
     *   límite que se pida.
     * @que-deberia-ocurrir Que traiga como mucho el número de productos pedido, como dice su comentario.
     * @por-que-ocurre El límite de la consulta está escrito como 30 y no usa el recibido.
     * @como-deberia-funcionar Usar el límite recibido.
     */
    public function test_losProductosDeUnaFamiliaRespetanElLimite(): void
    {
        $familia = $this->siembra->familia('Conservas');
        foreach (['Atún', 'Bonito', 'Sardinas'] as $nombre) {
            $this->siembra->articulo($nombre, ['familia' => $familia]);
        }

        $productos = $this->familias()->buscarProductosFamilias($familia, 2)['datos'];

        self::assertCount(2, $productos);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/clases/ClaseFamilias.php:168-176, 140-153
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Leer una familia que no existe deja avisos de PHP.
     * @que-deberia-ocurrir Que devuelva la lectura sin filas, sin avisos.
     * @por-que-ocurre Cuenta las hijas de unos datos que, sin filas, no existen.
     * @como-deberia-funcionar Contar solo si la lectura trae filas.
     */
    public function test_leerUnaFamiliaQueNoExisteNoDejaAvisos(): void
    {
        $familias = $this->familias();

        [, $avisos] = self::recogiendoAvisos(fn () => $familias->leer(999999));

        self::assertSame([], $avisos, 'Sin avisos de PHP.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/clases/ClaseFamilias.php:463-471
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Los datos de una familia no avisan de la relación repetida de una de sus hijas
     *   con la tienda web: miran el aviso de la propia familia.
     * @que-deberia-ocurrir Que avisen también de la relación repetida de una hija.
     * @por-que-ocurre Al recorrer las hijas se comprueba el aviso de la madre y no el de cada hija.
     * @como-deberia-funcionar Comprobar el aviso de la relación de cada hija.
     */
    public function test_losDatosDeUnaFamiliaAvisanDeLaRelacionRepetidaDeUnaHija(): void
    {
        [$abuela, $madre] = $this->arbol();
        $tienda = $this->siembra->tienda($this->ano(), 'web');
        $familias = $this->familias();
        $familias->addFamiliaTiendaWeb($tienda, $madre, 77);
        $familias->addFamiliaTiendaWeb($tienda, $madre, 78);

        $datos = $familias->datosFamilia($abuela);

        self::assertArrayHasKey('errores', $datos, 'Sin aviso de la relación repetida de la hija');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/clases/ClaseFamilias.php:506-544
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Una categoría de la tienda web sin número termina la petición con un error de
     *   PHP: el aviso se pide a una variable que no existe en la clase.
     * @que-deberia-ocurrir Que la relación de las familias de la web avise del error de su base y siga.
     * @por-que-ocurre La rama del error usa `$Cfamilias`, que no está definida dentro del método, y
     *   devuelve `$error`, que nunca se asigna.
     * @como-deberia-funcionar Montar el aviso con la propia clase y devolverlo.
     */
    public function test_unaCategoriaWebSinNumeroSeAvisaSinTerminarLaPeticion(): void
    {
        $familias = $this->familias();

        try {
            [$respuesta] = self::recogiendoAvisos(fn () => $familias->anhadirRelacionArrayTiendaFamilia([['virtuemart_category_id' => 0, 'category_name' => 'Sin número']], 1));
        } catch (\Error $error) {
            self::fail('La petición termina con un error de PHP: ' . $error->getMessage());
        }

        self::assertArrayHasKey('errorWeb', $respuesta);
    }

    /** @comportamiento Las familias de la web relacionadas reciben su familia local; las que no, se apartan como sin relación. */
    public function test_lasFamiliasDeLaWebSeSeparanPorSuRelacion(): void
    {
        $tienda = $this->siembra->tienda($this->ano(), 'web');
        $familia = $this->siembra->familia('Frutas');
        $familias = $this->familias();
        $familias->addFamiliaTiendaWeb($tienda, $familia, 77);

        $respuesta = $familias->anhadirRelacionArrayTiendaFamilia([
            ['virtuemart_category_id' => 77, 'category_name' => 'Frutas web'],
            ['virtuemart_category_id' => 78, 'category_name' => 'Verduras web'],
        ], $tienda);

        self::assertSame([['virtuemart_category_id' => 77, 'category_name' => 'Frutas web', 'idFamilia' => (string) $familia]], $respuesta['todasFamiliasWeb']);
        self::assertSame([['virtuemart_category_id' => 78, 'category_name' => 'Verduras web']], $respuesta['familiasWebSinRelacion']);
    }

    private function familias(): \ClaseFamilias
    {
        return new \ClaseFamilias($this->db);
    }

    /** Abuela en la raíz, madre de la abuela y nieta de la madre. @return array{0:int, 1:int, 2:int} */
    private function arbol(): array
    {
        $abuela = $this->siembra->familia('Abuela');
        $madre = $this->siembra->familia('Madre', $abuela);
        $nieta = $this->siembra->familia('Nieta', $madre);

        return [$abuela, $madre, $nieta];
    }

    /** @return array<string, int|string> */
    private function datos(array $datos): array
    {
        return $datos + ['idFamilia' => 0, 'familiaNombre' => 'Familia', 'familiaPadre' => 0, 'beneficiomedio' => '25', 'mostrar_tpv' => 1];
    }

    private function recuento(string $tabla, string $condicion = '1'): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM $tabla WHERE $condicion")->fetch_row()[0];
    }

    private function recuentoDe(string $nombre): int
    {
        $sentencia = $this->db->prepare('SELECT COUNT(*) FROM familias WHERE familiaNombre = ?');
        $sentencia->bind_param('s', $nombre);
        $sentencia->execute();

        return (int) $sentencia->get_result()->fetch_row()[0];
    }

    /** @return array<string, string|null> */
    private function fila(int $id, string $columnas): array
    {
        return $this->db->query('SELECT ' . $columnas . ' FROM familias WHERE idFamilia = ' . $id)->fetch_assoc();
    }

    /**
     * Ejecuta la llamada recogiendo los avisos de PHP en lugar de dejar que la suite los convierta en
     * error.
     *
     * @return array{0:mixed, 1:list<string>}
     */
    private static function recogiendoAvisos(callable $llamada): array
    {
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        });
        try {
            $resultado = $llamada();
        } finally {
            restore_error_handler();
        }

        return [$resultado, $avisos];
    }
}

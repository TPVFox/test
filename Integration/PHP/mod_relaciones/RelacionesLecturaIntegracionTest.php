<?php
/**
 * Leer las relaciones: cuáles rigen, cuáles tiene un producto, qué regía en una fecha, y la
 * comprobación de que ninguna relación ha quedado en un estado que no debería existir.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModRelaciones;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

final class RelacionesLecturaIntegracionTest extends CasoIntegracion
{
    /** El módulo abre su propia transacción: estos casos limpian lo que escriben. */
    protected bool $aislarPorTransaccion = false;

    protected bool $compartirConexionConElProducto = true;

    private EscenarioRelaciones $escenario;
    private Siembra $siembra;
    private string|false $registroDeErrores = false;
    private int $usuario;
    private int $despiece;
    private int $elaboracion;
    /** @var array<string,int> */
    private array $articulo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/Relaciones.php');
        $this->registroDeErrores = ini_set('error_log', '/dev/null');

        $this->escenario = new EscenarioRelaciones($this->db);
        $this->siembra = new Siembra($this->db);
        $this->escenario->limpiar();

        $this->usuario = $this->escenario->usuario();
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        $this->elaboracion = $this->escenario->tipoDeRelacion('Elaboración', 'N:1');
        foreach (['pollo', 'pechuga', 'alas', 'cerdo picado', 'vacuno picado', 'hamburguesa', 'criollo'] as $nombre) {
            $this->articulo[$nombre] = $this->siembra->articulo('Prueba de relaciones: ' . $nombre);
        }
    }

    protected function tearDown(): void
    {
        $this->escenario->limpiar(array_values($this->articulo));
        ini_set('error_log', (string) $this->registroDeErrores);
        parent::tearDown();
    }

    /**
     * @comportamiento Un producto tiene una relación tanto si entra en ella como si sale. La
     *   carne picada, que solo es ingrediente, tiene que aparecer al preguntar por ella.
     */
    public function test_T1_unProductoQueSoloEntraEnUnaElaboracionLaTieneEntreSusRelaciones(): void
    {
        $hamburguesa = $this->escenario->relacionVigente($this->elaboracion, $this->lineasDeLaHamburguesa());

        $respuesta = $this->relaciones()->vigentes($this->articulo['cerdo picado']);

        self::assertSame([$hamburguesa['numRelacion']], $this->numerosDe($respuesta));
    }

    public function test_T2_unProductoQueSaleDeUnaRelacionLaTieneEntreSusRelaciones(): void
    {
        $hamburguesa = $this->escenario->relacionVigente($this->elaboracion, $this->lineasDeLaHamburguesa());

        $respuesta = $this->relaciones()->vigentes($this->articulo['hamburguesa']);

        self::assertSame([$hamburguesa['numRelacion']], $this->numerosDe($respuesta));
    }

    public function test_T3_unProductoSinRelacionesDevuelveUnaListaVaciaYNoUnError(): void
    {
        $this->escenario->relacionVigente($this->elaboracion, $this->lineasDeLaHamburguesa());

        $respuesta = $this->relaciones()->vigentes($this->articulo['pollo']);

        self::assertArrayNotHasKey('error', $respuesta);
        self::assertSame([], $respuesta['datos']);
    }

    public function test_T4_deLasRelacionesDeUnProductoSalenLasQueRigenYNoLaDadaDeBaja(): void
    {
        $hamburguesa = $this->escenario->relacionVigente($this->elaboracion, $this->lineasDeLaHamburguesa());
        $criollo = $this->escenario->relacionVigente($this->elaboracion, [$this->linea('cerdo picado', 'entrada'), $this->linea('criollo', 'salida')]);
        $this->relaciones()->darDeBaja($criollo['numRelacion'], $this->usuario);

        $respuesta = $this->relaciones()->vigentes($this->articulo['cerdo picado']);

        self::assertSame([$hamburguesa['numRelacion']], $this->numerosDe($respuesta));
        self::assertSame([], $this->relaciones()->vigentes($this->articulo['criollo'])['datos']);
    }

    public function test_T5_unaRelacionQueSoloTieneUnBorradorNoAparece(): void
    {
        $this->relaciones()->crearBorrador($this->despiece, [], $this->lineasDelPollo(), $this->usuario);

        self::assertSame([], $this->relaciones()->vigentes()['datos']);
        self::assertSame([], $this->relaciones()->vigentes($this->articulo['pollo'])['datos']);
    }

    /**
     * @comportamiento Mientras un cambio está en preparación, quien lee sigue viendo lo que
     *   rige: ni los productos ni las cantidades del borrador.
     */
    public function test_T6_deUnaRelacionConUnCambioEnPreparacionSeLeeLaVersionQueRige(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida')]);
        $idBorrador = $this->relaciones()->crearBorradorDeCambio($pollo['numRelacion'], $this->usuario)['datos']['id'];
        $this->relaciones()->editarBorrador($idBorrador, [], $this->lineasDelPollo());

        $respuesta = $this->relaciones()->vigentes();

        self::assertCount(1, $respuesta['datos']);
        self::assertSame('1.0', $respuesta['datos'][0]['version']);
        self::assertCount(2, $respuesta['datos'][0]['lineas']);
        self::assertSame([], $this->relaciones()->vigentes($this->articulo['alas'])['datos']);
    }

    /**
     * @comportamiento El producto que una versión quita se conserva en ella como eliminado,
     *   para el historial, pero ya no forma parte de la relación: no aparece al leerla ni
     *   al preguntar por él.
     */
    public function test_T7_unProductoQuitadoDeLaVersionQueRigeYaNoTieneEsaRelacion(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->relaciones()->modificar($pollo['numRelacion'], [], [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida')], $this->usuario);

        $deLasAlas = $this->relaciones()->vigentes($this->articulo['alas']);
        $delPollo = $this->relaciones()->vigentes($this->articulo['pollo']);

        self::assertSame([], $deLasAlas['datos']);
        self::assertSame('2.0', $delPollo['datos'][0]['version']);
        self::assertSame([$this->articulo['pollo'], $this->articulo['pechuga']], array_map('intval', array_column($delPollo['datos'][0]['lineas'], 'idArticulo')));
    }

    public function test_T8_trasUnCambioSoloApareceLaVersionNuevaYNoLaSustituida(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $ajustadas = [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida', 0.25), $this->linea('alas', 'salida')];
        $this->relaciones()->modificar($pollo['numRelacion'], [], $ajustadas, $this->usuario, 'Pesada');

        $respuesta = $this->relaciones()->vigentes($this->articulo['pollo']);

        self::assertCount(1, $respuesta['datos']);
        self::assertSame('1.1', $respuesta['datos'][0]['version']);
    }

    public function test_T9_sinIndicarProductoSeDevuelvenTodasLasQueRigenConSuTipoYSusLineas(): void
    {
        $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['nombre' => 'Pollo entero']);
        $this->escenario->relacionVigente($this->elaboracion, $this->lineasDeLaHamburguesa());

        $respuesta = $this->relaciones()->vigentes();

        self::assertCount(2, $respuesta['datos']);
        $delPollo = $respuesta['datos'][0];
        self::assertSame('Pollo entero', $delPollo['nombre']);
        self::assertSame('Despiece', $delPollo['tipo']);
        self::assertSame('1:N', $delPollo['forma']);
        self::assertSame('1.0', $delPollo['version']);
        self::assertSame(['entrada', 'salida', 'salida'], array_column($delPollo['lineas'], 'sentido'));
        self::assertSame('1.000000', $delPollo['lineas'][0]['cantidad']);
        self::assertSame('kg', $delPollo['lineas'][0]['unidad']);
        self::assertSame('Elaboración', $respuesta['datos'][1]['tipo']);
    }

    /**
     * @comportamiento La relación guarda solo el identificador del producto. El nombre se lee
     *   del catálogo cada vez, así que un producto renombrado se ve con su nombre nuevo sin
     *   tocar la relación.
     */
    public function test_T10_elNombreDelProductoEsElQueTieneAhoraEnElCatalogo(): void
    {
        $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->db->query("UPDATE articulos SET articulo_name = 'Prueba de relaciones: pollo de corral' WHERE idArticulo = {$this->articulo['pollo']}");

        $respuesta = $this->relaciones()->vigentes($this->articulo['pollo']);

        self::assertSame('Prueba de relaciones: pollo de corral', $respuesta['datos'][0]['lineas'][0]['articulo']);
    }

    public function test_T11_conTresVersionesSucesivasLaFechaDevuelveLaQueRegiaEnCadaTramo(): void
    {
        $numRelacion = $this->polloConTresVersiones();

        $enEnero = $this->relaciones()->estadoEnFecha($numRelacion, '2026-01-20');
        $enMarzo = $this->relaciones()->estadoEnFecha($numRelacion, '2026-03-15');
        $enJunio = $this->relaciones()->estadoEnFecha($numRelacion, '2026-06-01');

        self::assertSame(['regia', '1.0'], [$enEnero['datos']['situacion'], $enEnero['datos']['version']['version']]);
        self::assertSame(['regia', '1.1'], [$enMarzo['datos']['situacion'], $enMarzo['datos']['version']['version']]);
        self::assertSame(['regia', '1.2'], [$enJunio['datos']['situacion'], $enJunio['datos']['version']['version']]);
        self::assertSame('0.240000', $enMarzo['datos']['version']['lineas'][1]['cantidad']);
    }

    public function test_T12_unaFechaAnteriorAlAltaRespondeQueNoExistiaYNoEsUnError(): void
    {
        $numRelacion = $this->polloConTresVersiones();

        $respuesta = $this->relaciones()->estadoEnFecha($numRelacion, '2025-12-31');

        self::assertArrayNotHasKey('error', $respuesta);
        self::assertSame('noExistia', $respuesta['datos']['situacion']);
        self::assertNull($respuesta['datos']['version']);
    }

    /**
     * @comportamiento Dar de baja no deja fecha: la consulta por fecha devuelve la última
     *   versión anterior a la fecha, con su contenido y su estado actual, sin saber desde
     *   cuándo está de baja.
     */
    public function test_T13_trasLaBajaLaFechaDevuelveLaUltimaVersionConSuEstadoActual(): void
    {
        $numRelacion = $this->polloConTresVersiones();
        $this->db->query("UPDATE relaciones SET estado = 'Baja' WHERE numRelacion = $numRelacion AND estado = 'Activo'");

        $enMarzo = $this->relaciones()->estadoEnFecha($numRelacion, '2026-03-15');
        $despues = $this->relaciones()->estadoEnFecha($numRelacion, '2026-07-02');

        self::assertSame(['regia', '1.1', 'Sustituido'], [$enMarzo['datos']['situacion'], $enMarzo['datos']['version']['version'], $enMarzo['datos']['version']['estado']]);
        self::assertSame(['regia', '1.2', 'Baja'], [$despues['datos']['situacion'], $despues['datos']['version']['version'], $despues['datos']['version']['estado']]);
        self::assertSame('0.250000', $despues['datos']['version']['lineas'][1]['cantidad']);
    }

    public function test_T14_unBorradorNoCuentaParaLoQueRegiaEnUnaFecha(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['fecha' => '2026-01-10 09:00:00']);
        $this->escenario->versionDeRelacion($pollo['numRelacion'], $this->despiece, '0.0', 'Borrador', '2026-02-01 09:00:00', $this->lineasDelPollo());

        $respuesta = $this->relaciones()->estadoEnFecha($pollo['numRelacion'], '2026-03-01');

        self::assertSame('1.0', $respuesta['datos']['version']['version']);
    }

    /**
     * @comportamiento Quien pregunta por un día, sin hora, recibe lo que regía al terminar
     *   ese día: si la fórmula cambió por la tarde, la respuesta es la de la tarde.
     */
    public function test_T15_conSoloElDiaSeRespondeLoQueRegiaAlTerminarEseDia(): void
    {
        $numRelacion = $this->polloConDosVersionesElMismoDia();

        $respuesta = $this->relaciones()->estadoEnFecha($numRelacion, '2026-03-15');

        self::assertSame('1.1', $respuesta['datos']['version']['version']);
    }

    public function test_T16_conDiaYHoraSeRespondeLoQueRegiaEnEseMomento(): void
    {
        $numRelacion = $this->polloConDosVersionesElMismoDia();

        $aMediodia = $this->relaciones()->estadoEnFecha($numRelacion, '2026-03-15 12:00:00');
        $antesDeAbrir = $this->relaciones()->estadoEnFecha($numRelacion, '2026-03-15 08:00:00');

        self::assertSame('1.0', $aMediodia['datos']['version']['version']);
        self::assertSame('noExistia', $antesDeAbrir['datos']['situacion']);
    }

    public function test_T17_deDosVersionesConLaMismaFechaRigeLaActivadaDespues(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['fecha' => '2026-03-15 09:00:00']);
        $this->db->query("UPDATE relaciones SET estado = 'Sustituido' WHERE id = {$pollo['id']}");
        $this->escenario->versionDeRelacion($pollo['numRelacion'], $this->despiece, '1.1', 'Activo', '2026-03-15 09:00:00', $this->lineasDelPollo());

        $respuesta = $this->relaciones()->estadoEnFecha($pollo['numRelacion'], '2026-03-15 09:00:00');

        self::assertSame('1.1', $respuesta['datos']['version']['version']);
    }

    public function test_T18_unaRelacionQueNoExisteRespondeQueNoExistia(): void
    {
        $respuesta = $this->relaciones()->estadoEnFecha(424242, '2026-03-15');

        self::assertArrayNotHasKey('error', $respuesta);
        self::assertSame('noExistia', $respuesta['datos']['situacion']);
    }

    /**
     * @comportamiento La comprobación busca relaciones en un estado que las operaciones del
     *   módulo no deberían dejar nunca. Tras usarlo con normalidad no encuentra nada.
     */
    public function test_T19_laComprobacionNoEncuentraNadaTrasAltasCambiosBorradoresYBajas(): void
    {
        $relaciones = $this->relaciones();
        $pollo = $relaciones->alta($this->despiece, [], $this->lineasDelPollo(), $this->usuario);
        $ajustadas = [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida', 0.25), $this->linea('alas', 'salida')];
        $relaciones->modificar($pollo['datos']['numRelacion'], [], $ajustadas, $this->usuario, 'Pesada');
        $relaciones->crearBorradorDeCambio($pollo['datos']['numRelacion'], $this->usuario);
        $hamburguesa = $relaciones->alta($this->elaboracion, [], $this->lineasDeLaHamburguesa(), $this->usuario);
        $relaciones->darDeBaja($hamburguesa['datos']['numRelacion'], $this->usuario);
        $relaciones->crearBorrador($this->elaboracion, [], [$this->linea('cerdo picado', 'entrada')], $this->usuario);

        $respuesta = $relaciones->comprobarVersiones();

        self::assertSame([], $respuesta['datos']);
    }

    public function test_T20_laComprobacionDetectaUnaRelacionConDosVersionesRigiendo(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->escenario->versionDeRelacion($pollo['numRelacion'], $this->despiece, '1.1', 'Activo', '2026-03-15 09:00:00', $this->lineasDelPollo());

        $respuesta = $this->relaciones()->comprobarVersiones();

        self::assertSame([$pollo['numRelacion']], array_map('intval', array_column($respuesta['datos'], 'numRelacion')));
        self::assertStringContainsString('más de una versión', $respuesta['datos'][0]['motivo']);
    }

    public function test_T21_laComprobacionDetectaUnaRelacionConDosBorradores(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->escenario->versionDeRelacion($pollo['numRelacion'], $this->despiece, '0.0', 'Borrador', '2026-03-15 09:00:00');
        $this->escenario->versionDeRelacion($pollo['numRelacion'], $this->despiece, '0.1', 'Borrador', '2026-03-16 09:00:00');

        $respuesta = $this->relaciones()->comprobarVersiones();

        self::assertCount(1, $respuesta['datos']);
        self::assertStringContainsString('más de un borrador', $respuesta['datos'][0]['motivo']);
    }

    public function test_T22_laComprobacionDetectaUnaRelacionSinVersionQueRijaNiBaja(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->db->query("UPDATE relaciones SET estado = 'Sustituido' WHERE id = {$pollo['id']}");

        $respuesta = $this->relaciones()->comprobarVersiones();

        self::assertCount(1, $respuesta['datos']);
        self::assertStringContainsString('ninguna versión', $respuesta['datos'][0]['motivo']);
    }

    public function test_T23_unValorQueIntentaCerrarLaSentenciaNoAmpliaLoQueSeLee(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $hostil = "0' OR '1'='1";

        $porProducto = $this->relaciones()->vigentes($hostil);
        $porFecha = $this->relaciones()->estadoEnFecha($hostil, '2026-03-15');
        $conFechaHostil = $this->relaciones()->estadoEnFecha($pollo['numRelacion'], "2000-01-01' OR '1'='1");

        self::assertSame([], $porProducto['datos'] ?? []);
        self::assertNull($porFecha['datos']['version'] ?? null);
        self::assertNull($conFechaHostil['datos']['version'] ?? null);
    }

    public function test_T24_siLaBaseFallaLaLecturaDevuelveElFalloYNoLoLanza(): void
    {
        $relaciones = $this->relaciones();
        $relaciones->fallar = true;

        self::assertSame('danger', $relaciones->vigentes()['error']['tipo']);
        self::assertSame('danger', $relaciones->estadoEnFecha(1, '2026-03-15')['error']['tipo']);
        self::assertSame('danger', $relaciones->comprobarVersiones()['error']['tipo']);
    }

    public function test_T25_elListadoTraeUnaFilaPorRelacionConSuNumeroTipoVersionFechaYProductos(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['nombre' => 'Pollo entero', 'fecha' => '2026-03-01 09:00:00']);
        $this->escenario->relacionVigente($this->elaboracion, $this->lineasDeLaHamburguesa());

        $listado = $this->relaciones()->listado('', '', '', 1, 40)['datos'];

        self::assertSame(2, $listado['total']);
        self::assertSame(1, $listado['pagina']);
        self::assertSame(
            ['numRelacion' => $pollo['numRelacion'], 'relacion' => 'Pollo entero', 'tipo' => 'Despiece', 'version' => '1.0', 'estado' => 'Activo', 'fecha' => '2026-03-01 09:00:00', 'numProductos' => 3],
            $listado['filas'][0]
        );
    }

    /**
     * @comportamiento Una relación sin nombre se presenta con el del producto que está solo
     *   en su lado: el que se despieza, o el que se elabora.
     */
    public function test_T26_unaRelacionSinNombreSeLlamaComoElProductoQueEstaSoloEnSuLado(): void
    {
        $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->escenario->relacionVigente($this->elaboracion, $this->lineasDeLaHamburguesa(), ['nombre' => '']);

        $filas = $this->relaciones()->listado('', '', '', 1, 40)['datos']['filas'];

        self::assertSame(['Prueba de relaciones: pollo', 'Prueba de relaciones: hamburguesa'], array_column($filas, 'relacion'));
    }

    /**
     * @comportamiento Buscar un producto devuelve las relaciones en las que está, entre o
     *   salga, aunque no figure en su nombre. Es como se sabe desde el listado qué
     *   relaciones tiene un producto que solo es ingrediente.
     */
    public function test_T27_laBusquedaCasaConElNombreDeLaRelacionOConElDeCualquieraDeSusProductos(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['nombre' => 'Despiece de ave']);
        $hamburguesa = $this->escenario->relacionVigente($this->elaboracion, $this->lineasDeLaHamburguesa(), ['nombre' => 'Mixta']);
        $numeros = fn(string $buscar) => array_column($this->relaciones()->listado($buscar, '', '', 1, 40)['datos']['filas'], 'numRelacion');

        self::assertSame([$pollo['numRelacion']], $numeros('DESPIECE'));
        self::assertSame([$hamburguesa['numRelacion']], $numeros('cerdo picado'));
        self::assertSame([$hamburguesa['numRelacion']], $numeros('mixta vacuno'));
        self::assertSame([$pollo['numRelacion'], $hamburguesa['numRelacion']], $numeros('relaciones'));
        self::assertSame([], $numeros('pechuga ternera'));
    }

    public function test_T28_elFiltroPorTipoYLaBusquedaAcotanALaVez(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->escenario->relacionVigente($this->elaboracion, $this->lineasDeLaHamburguesa());
        $listado = fn(string $buscar, string $tipo) => $this->relaciones()->listado($buscar, $tipo, '', 1, 40)['datos'];

        self::assertSame([$pollo['numRelacion']], array_column($listado('', 'Despiece')['filas'], 'numRelacion'));
        self::assertSame(1, $listado('relaciones', 'Elaboración')['total']);
        self::assertSame(0, $listado('pechuga', 'Elaboración')['total']);
        self::assertSame(0, $listado('', 'Un tipo que no existe')['total']);
    }

    public function test_T29_seOrdenaPorNumeroDeRelacionOPorFechaEnLosDosSentidos(): void
    {
        $uno = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['fecha' => '2026-03-01 09:00:00'])['numRelacion'];
        $dos = $this->escenario->relacionVigente($this->elaboracion, $this->lineasDeLaHamburguesa(), ['fecha' => '2026-01-10 09:00:00'])['numRelacion'];
        $numeros = fn(string $orden) => array_column($this->relaciones()->listado('', '', $orden, 1, 40)['datos']['filas'], 'numRelacion');

        self::assertSame([$uno, $dos], $numeros(''));
        self::assertSame([$dos, $uno], $numeros('numRelacion:DESC'));
        self::assertSame([$dos, $uno], $numeros('fecha:ASC'));
        self::assertSame([$uno, $dos], $numeros('fecha:DESC'));
        self::assertSame([$uno, $dos], $numeros('r.id; DROP TABLE relaciones'));
    }

    /**
     * @comportamiento De la base solo se leen las filas de la página pedida, y el total es
     *   el de todas las que casan. Entre todas las páginas están todas, una sola vez; una
     *   página que ya no existe devuelve la última que hay.
     */
    public function test_T30_cadaPaginaTraeSoloSusFilasYEntreTodasEstanTodasUnaVez(): void
    {
        $sembradas = [];
        foreach (['pollo', 'pechuga', 'alas', 'criollo', 'hamburguesa'] as $nombre) {
            $sembradas[] = $this->escenario->relacionVigente($this->despiece, [$this->linea($nombre, 'entrada'), $this->linea('cerdo picado', 'salida')])['numRelacion'];
        }
        $relaciones = $this->relaciones();

        $primera = $relaciones->listado('', '', '', 1, 2)['datos'];
        $tercera = $relaciones->listado('', '', '', 3, 2)['datos'];
        $fueraDeRango = $relaciones->listado('', '', '', 9999, 2)['datos'];
        $vistas = array_merge(array_column($primera['filas'], 'numRelacion'), array_column($relaciones->listado('', '', '', 2, 2)['datos']['filas'], 'numRelacion'), array_column($tercera['filas'], 'numRelacion'));

        self::assertSame(5, $primera['total']);
        self::assertCount(2, $primera['filas']);
        self::assertCount(1, $tercera['filas']);
        self::assertSame($sembradas, $vistas);
        self::assertSame([3, $tercera['filas']], [$fueraDeRango['pagina'], $fueraDeRango['filas']]);
        self::assertSame(1, $relaciones->listado('', '', '', -4, 2)['datos']['pagina']);
    }

    public function test_T31_enElListadoCuentanLaRelacionDeBajaPeroNoElBorradorNiElProductoQuitado(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->relaciones()->modificar($pollo['numRelacion'], [], [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida')], $this->usuario);
        $retirada = $this->escenario->relacionVigente($this->elaboracion, $this->lineasDeLaHamburguesa());
        $this->relaciones()->darDeBaja($retirada['numRelacion'], $this->usuario);
        $this->relaciones()->crearBorrador($this->despiece, [], [$this->linea('criollo', 'entrada')], $this->usuario);

        $todas = $this->relaciones()->listado('', '', '', 1, 40)['datos'];

        self::assertSame(2, $todas['total']);
        self::assertSame(['2.0', 2, 'Activo'], [$todas['filas'][0]['version'], $todas['filas'][0]['numProductos'], $todas['filas'][0]['estado']]);
        self::assertSame('Baja', $todas['filas'][1]['estado']);
        self::assertSame(0, $this->relaciones()->listado('alas', '', '', 1, 40)['datos']['total']);
        self::assertSame(1, $this->relaciones()->listado('hamburguesa', '', '', 1, 40)['datos']['total']);
        self::assertSame(0, $this->relaciones()->listado('criollo', '', '', 1, 40)['datos']['total']);
    }

    /**
     * @comportamiento Lo buscado es texto: un porcentaje o un guion bajo no hacen de comodín,
     *   y una comilla no cierra la consulta.
     */
    public function test_T32_losComodinesYLasComillasDeLoBuscadoSeBuscanComoTexto(): void
    {
        $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['nombre' => 'Pollo entero']);
        $total = fn(string $buscar, string $tipo = '') => $this->relaciones()->listado($buscar, $tipo, '', 1, 40)['datos']['total'];

        self::assertSame(0, $total('%'));
        self::assertSame(0, $total('P_llo'));
        self::assertSame(0, $total("x' OR '1'='1"));
        self::assertSame(0, $total('', "Despiece' OR '1'='1"));
        self::assertSame(1, $total('Pollo'));
    }

    public function test_T33_sinRelacionesElListadoEstaVacioEnSuPrimeraPaginaYNoEsUnError(): void
    {
        $respuesta = $this->relaciones()->listado('', '', '', 1, 40);

        self::assertArrayNotHasKey('error', $respuesta);
        self::assertSame(['total' => 0, 'pagina' => 1, 'filas' => []], $respuesta['datos']);
    }

    public function test_T34_siLaBaseFallaElListadoDevuelveElFalloYNoLoLanza(): void
    {
        $relaciones = $this->relaciones();
        $relaciones->fallar = true;

        self::assertSame('danger', $relaciones->listado('', '', '', 1, 40)['error']['tipo']);
    }

    /** La clase del módulo, con la posibilidad de hacer fallar sus consultas desde la prueba. */
    private function relaciones(): object
    {
        return new class extends \Relaciones {
            public bool $fallar = false;

            protected function consultaPreparada($sql, $valores = array())
            {
                if ($this->fallar) {
                    return ['error' => $this->montarAdvertencia('danger', 'Fallo provocado por la prueba'), 'consulta' => $sql];
                }

                return parent::consultaPreparada($sql, $valores);
            }
        };
    }

    /** Un despiece con tres versiones: enero, marzo y mayo, cada una con otra cantidad de pechuga. */
    public function test_T35_laComprobacionDetectaUnaRelacionConUnaVersionQueRigeYOtraDeBaja(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->escenario->versionDeRelacion($pollo['numRelacion'], $this->despiece, '1.1', 'Baja', '2026-03-15 09:00:00', $this->lineasDelPollo());

        $respuesta = $this->relaciones()->comprobarVersiones();

        self::assertSame([$pollo['numRelacion']], array_map('intval', array_column($respuesta['datos'], 'numRelacion')));
        self::assertStringContainsString('más de una versión', $respuesta['datos'][0]['motivo']);
    }

    /**
     * @comportamiento Una relación de baja no tiene borrador: la baja se rechaza si lo hay, y
     *   preparar un cambio exige que la relación rija. Si alguna vez ocurriera, la comprobación
     *   lo encuentra.
     */
    public function test_T36_laComprobacionDetectaUnaRelacionDeBajaConUnBorrador(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->db->query("UPDATE relaciones SET estado = 'Baja' WHERE id = {$pollo['id']}");
        $this->escenario->versionDeRelacion($pollo['numRelacion'], $this->despiece, '0.0', 'Borrador', '2026-03-15 09:00:00');

        $respuesta = $this->relaciones()->comprobarVersiones();

        self::assertSame([$pollo['numRelacion']], array_map('intval', array_column($respuesta['datos'], 'numRelacion')));
        self::assertStringContainsString('de baja y tiene un borrador', $respuesta['datos'][0]['motivo']);
    }

    /**
     * @comportamiento Una relación de baja no tiene un cambio en preparación: la baja se rechaza
     *   si lo hay, y el primer guardado exige que la relación rija. Si alguna vez ocurriera, la
     *   comprobación lo encuentra.
     */
    public function test_T37_laComprobacionDetectaUnaRelacionDeBajaConUnCambioEnPreparacion(): void
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->db->query("UPDATE relaciones SET estado = 'Baja' WHERE id = {$pollo['id']}");
        $this->escenario->temporal($this->despiece, $this->lineasDelPollo(), ['numRelacion' => $pollo['numRelacion'], 'idRelacion' => $pollo['id']]);

        $respuesta = $this->relaciones()->comprobarVersiones();

        self::assertSame([$pollo['numRelacion']], array_map('intval', array_column($respuesta['datos'], 'numRelacion')));
        self::assertStringContainsString('de baja y tiene un cambio en preparación', $respuesta['datos'][0]['motivo']);
    }

    /** @comportamiento Lo que se activa desde un temporal deja las versiones como las demás escrituras. */
    public function test_T38_laComprobacionNoEncuentraNadaTrasActivarDesdeTemporalesYDarDeBaja(): void
    {
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/RelacionesTemporales.php');
        $relaciones = $this->relaciones();
        $temporales = new \RelacionesTemporales();
        $nueva = $this->escenario->temporal($this->despiece, $this->lineasDelPollo());
        $pollo = $temporales->activar($nueva, $this->usuario, $relaciones);
        $ajustadas = [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida', 0.25), $this->linea('alas', 'salida')];
        $vigente = $relaciones->vigente($pollo['datos']['numRelacion'])['datos'];
        $cambio = $this->escenario->temporal($this->despiece, $ajustadas, ['numRelacion' => $vigente['numRelacion'], 'idRelacion' => $vigente['id'], 'origenAjuste' => 'Pesada']);
        $temporales->activar($cambio, $this->usuario, $relaciones);
        $hamburguesa = $relaciones->alta($this->elaboracion, [], $this->lineasDeLaHamburguesa(), $this->usuario);
        $relaciones->darDeBaja($hamburguesa['datos']['numRelacion'], $this->usuario);

        $respuesta = $relaciones->comprobarVersiones();

        self::assertSame('1.1', $relaciones->vigente($pollo['datos']['numRelacion'])['datos']['version']);
        self::assertSame([], $respuesta['datos']);
    }

    private function polloConTresVersiones(): int
    {
        $lineas = fn(float $pechuga) => [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida', $pechuga), $this->linea('alas', 'salida')];
        $pollo = $this->escenario->relacionVigente($this->despiece, $lineas(0.23), ['fecha' => '2026-01-10 09:00:00']);
        $this->db->query("UPDATE relaciones SET estado = 'Sustituido' WHERE id = {$pollo['id']}");
        $this->escenario->versionDeRelacion($pollo['numRelacion'], $this->despiece, '1.1', 'Sustituido', '2026-03-01 09:00:00', $lineas(0.24));
        $this->escenario->versionDeRelacion($pollo['numRelacion'], $this->despiece, '1.2', 'Activo', '2026-05-01 09:00:00', $lineas(0.25));

        return $pollo['numRelacion'];
    }

    /** Un despiece dado de alta a las nueve y ajustado a las cinco de la tarde del mismo día. */
    private function polloConDosVersionesElMismoDia(): int
    {
        $pollo = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['fecha' => '2026-03-15 09:00:00']);
        $this->db->query("UPDATE relaciones SET estado = 'Sustituido' WHERE id = {$pollo['id']}");
        $this->escenario->versionDeRelacion($pollo['numRelacion'], $this->despiece, '1.1', 'Activo', '2026-03-15 17:00:00', $this->lineasDelPollo());

        return $pollo['numRelacion'];
    }

    private function linea(string $nombre, string $sentido, float $cantidad = 1): array
    {
        return ['idArticulo' => $this->articulo[$nombre], 'sentido' => $sentido, 'cantidad' => $cantidad, 'unidad' => 'kg'];
    }

    private function lineasDelPollo(): array
    {
        return [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida'), $this->linea('alas', 'salida')];
    }

    private function lineasDeLaHamburguesa(): array
    {
        return [$this->linea('cerdo picado', 'entrada', 0.4), $this->linea('vacuno picado', 'entrada', 0.6), $this->linea('hamburguesa', 'salida')];
    }

    /** @return list<int> */
    private function numerosDe(array $respuesta): array
    {
        return array_map('intval', array_column($respuesta['datos'], 'numRelacion'));
    }
}

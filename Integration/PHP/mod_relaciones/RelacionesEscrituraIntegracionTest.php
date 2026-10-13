<?php
/**
 * Cómo escribe el módulo de relaciones: los valores viajan aparte de la sentencia, una
 * escritura de varios pasos queda entera o no queda, y el fallo llega siempre a quien la
 * pidió como un aviso, nunca como una excepción ni como texto en pantalla.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModRelaciones;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

final class RelacionesEscrituraIntegracionTest extends CasoIntegracion
{
    /**
     * Lo que se prueba es que el propio módulo abre y cierra su transacción. Dentro de la
     * de la suite, esa apertura la confirmaría sin avisar: estos casos limpian lo que escriben.
     */
    protected bool $aislarPorTransaccion = false;

    protected bool $compartirConexionConElProducto = true;

    private string|false $registroDeErrores = false;
    private EscenarioRelaciones $escenario;
    private int $usuario;
    /** @var array<string,int> */
    private array $articulo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/Relaciones.php');
        $this->escenario = new EscenarioRelaciones($this->db);
        $this->usuario = $this->escenario->usuario();
        // Cada fallo provocado se apunta en el registro del servidor, que en una prueba es
        // la propia salida.
        $this->registroDeErrores = ini_set('error_log', '/dev/null');
        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        $this->escenario->limpiar(array_values($this->articulo));
        ini_set('error_log', (string) $this->registroDeErrores);
        parent::tearDown();
    }

    public function test_T1_unaLecturaDevuelveLasFilasQueCasanConElValorRecibido(): void
    {
        $modelo = $this->modelo();
        $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba despiece']);
        $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, 'N:1')", ['prueba elaboracion']);

        $respuesta = $modelo->consultar('SELECT nombre, forma FROM relacionesTipos WHERE nombre = ?', ['prueba despiece']);

        self::assertSame([['nombre' => 'prueba despiece', 'forma' => '1:N']], $respuesta['datos']);
    }

    public function test_T2_unValorQueIntentaCerrarLaSentenciaSeGuardaComoTextoYNoLaAltera(): void
    {
        $modelo = $this->modelo();
        $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:1')", ['prueba cruce']);
        $hostil = "prueba' OR '1'='1";

        $alLeer = $modelo->consultar('SELECT nombre FROM relacionesTipos WHERE nombre = ?', [$hostil]);
        $alEscribir = $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:1')", [$hostil]);
        $guardado = $modelo->consultar('SELECT nombre FROM relacionesTipos WHERE id = ?', [$alEscribir['datos']['idInsertado']]);

        self::assertSame([], $alLeer['datos'], 'El valor no amplía lo que la lectura devuelve');
        self::assertSame($hostil, $guardado['datos'][0]['nombre']);
    }

    public function test_T3_unaEscrituraDevuelveCuantasFilasAfectoYElIdentificadorCreado(): void
    {
        $modelo = $this->modelo();

        $alta = $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba alta']);
        $cambio = $modelo->consultar('UPDATE relacionesTipos SET llevaCantidad = ? WHERE id = ?', [0, $alta['datos']['idInsertado']]);
        $sinEfecto = $modelo->consultar('UPDATE relacionesTipos SET llevaCantidad = ? WHERE id = ?', [0, 0]);

        self::assertSame(1, $alta['datos']['afectadas']);
        self::assertGreaterThan(0, $alta['datos']['idInsertado']);
        self::assertSame(1, $cambio['datos']['afectadas']);
        self::assertSame(0, $sinEfecto['datos']['afectadas']);
    }

    public function test_T4_unFalloDeLaBaseSeDevuelveConSuAvisoYSuSentenciaSinLanzarNiImprimir(): void
    {
        $this->expectOutputString('');
        $sentencia = 'SELECT * FROM tablaQueNoExiste WHERE id = ?';

        $respuesta = $this->modelo()->consultar($sentencia, [1]);

        self::assertArrayNotHasKey('datos', $respuesta);
        self::assertSame('danger', $respuesta['error']['tipo']);
        self::assertSame($sentencia, $respuesta['consulta']);
    }

    public function test_T5_unaEscrituraDeVariosPasosQueTerminaBienQuedaGuardadaEntera(): void
    {
        $modelo = $this->modelo();

        $respuesta = $modelo->transaccion(function () use ($modelo) {
            $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba uno']);

            return $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, 'N:1')", ['prueba dos']);
        });

        self::assertArrayHasKey('datos', $respuesta);
        self::assertSame(2, $this->filasDePrueba());
    }

    public function test_T6_siUnPasoLanzaUnFalloNoQuedaNadaDeLosPasosAnteriores(): void
    {
        $modelo = $this->modelo();
        $modelo->fallarEnLaConsulta = 2;

        $respuesta = $modelo->transaccion(function () use ($modelo) {
            $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba uno']);

            return $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, 'N:1')", ['prueba dos']);
        });

        self::assertSame('danger', $respuesta['error']['tipo']);
        self::assertSame(0, $this->filasDePrueba());
    }

    public function test_T7_siUnPasoDevuelveUnErrorSinLanzarloTampocoQuedaNada(): void
    {
        $modelo = $this->modelo();

        $respuesta = $modelo->transaccion(function () use ($modelo) {
            $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba uno']);

            // La forma no es ninguna de las tres: la base la rechaza y la respuesta lo dice.
            return $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, ?)", ['prueba dos', '2:2']);
        });

        self::assertArrayHasKey('error', $respuesta);
        self::assertSame(0, $this->filasDePrueba());
    }

    /**
     * @comportamiento Una operación que se ejecuta dentro de otra no confirma lo suyo por su cuenta:
     *   si la de fuera falla después, lo que hizo la de dentro tampoco queda. Es lo que permite que un
     *   alta directa sea «preparar» y «poner a regir» sin que pueda quedar a medias.
     */
    public function test_T8_unaOperacionDentroDeOtraNoConfirmaPorSuCuenta(): void
    {
        $modelo = $this->modelo();
        $modelo->fallarEnLaConsulta = 2;

        $respuesta = $modelo->transaccion(function () use ($modelo) {
            // La de dentro termina bien…
            $modelo->transaccion(fn() => $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba dentro']));

            // …y la de fuera falla después: lo de dentro tampoco puede quedar.
            return $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, 'N:1')", ['prueba fuera']);
        });

        self::assertArrayHasKey('error', $respuesta);
        self::assertSame(0, $this->filasDePrueba());
    }

    public function test_T9_unaOperacionDentroDeOtraQuedaGuardadaCuandoLaDeFueraTerminaBien(): void
    {
        $modelo = $this->modelo();

        $respuesta = $modelo->transaccion(function () use ($modelo) {
            $modelo->transaccion(fn() => $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba dentro']));

            return $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, 'N:1')", ['prueba fuera']);
        });

        self::assertArrayHasKey('datos', $respuesta);
        self::assertSame(2, $this->filasDePrueba());
    }

    /**
     * @comportamiento Si falla una operación de dentro y la de fuera no mira su respuesta y contesta
     *   que todo fue bien, la escritura se deshace igual y la respuesta final es un fallo. Sin esto se
     *   confirmaría una escritura con un paso sin hacer.
     */
    public function test_T10_elFalloDeLaOperacionDeDentroDeshaceTodoAunqueLaDeFueraRespondaBien(): void
    {
        $modelo = $this->modelo();
        $modelo->fallarEnLaConsulta = 2;

        $respuesta = $modelo->transaccion(function () use ($modelo) {
            $primera = $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba fuera']);
            // La de dentro falla y la de fuera no mira su respuesta.
            $modelo->transaccion(fn() => $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, 'N:1')", ['prueba dentro']));

            return $primera;
        });

        self::assertArrayHasKey('error', $respuesta, 'Una escritura con un paso fallido no puede darse por buena');
        self::assertSame(0, $this->filasDePrueba());
    }

    public function test_T11_trasUnaEscrituraFallidaLaSiguienteVuelveAConfirmar(): void
    {
        $modelo = $this->modelo();
        $modelo->fallarEnLaConsulta = 1;
        $modelo->transaccion(fn() => $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba fallida']));

        $respuesta = $modelo->transaccion(fn() => $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba siguiente']));

        // Se cuenta por otra conexión: lo que esta viera sin confirmar no demostraría nada.
        $otra = self::conectar($this->ejercicio);
        $confirmadas = (int) $otra->query("SELECT COUNT(*) AS n FROM relacionesTipos WHERE nombre = 'prueba siguiente'")->fetch_assoc()['n'];
        $otra->close();

        self::assertArrayHasKey('datos', $respuesta);
        self::assertSame(1, $confirmadas);
    }

    /**
     * @comportamiento El fallo de la base se devuelve como aviso también en un servidor que no lanza
     *   excepciones. TPVFox no fija cómo avisa la base de un fallo: según el servidor llega como
     *   excepción o como un «falso», y los dos acaban en la misma respuesta.
     */
    public function test_T12_elFalloSeDevuelveIgualCuandoLaInstalacionNoLanzaLosErroresDeLaBase(): void
    {
        $modelo = $this->modelo();
        mysqli_report(MYSQLI_REPORT_OFF);

        try {
            $alPreparar = $modelo->consultar('SELECT * FROM tablaQueNoExiste WHERE id = ?', [1]);
            $alEjecutar = $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, ?)", ['prueba forma', '2:2']);
        } finally {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        }

        self::assertArrayHasKey('error', $alPreparar);
        self::assertArrayHasKey('error', $alEjecutar);
        self::assertSame(0, $this->filasDePrueba());
    }

    public function test_T13_siLaEscrituraNoPuedeNiEmpezarElFalloSeDevuelve(): void
    {
        $modelo = $this->modelo();
        $cerrada = self::conectar($this->ejercicio);
        $cerrada->close();
        $anterior = \ModeloP::usarConexion($cerrada);

        try {
            $respuesta = $modelo->transaccion(fn() => ['datos' => 'no debería llegar aquí']);
        } finally {
            \ModeloP::usarConexion($anterior);
        }

        self::assertSame('danger', $respuesta['error']['tipo']);
    }

    public function test_T14_unaRelacionDeCadaFormaSeDaDeAltaYSeLeeTalComoSeGuardo(): void
    {
        $despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        $elaboracion = $this->escenario->tipoDeRelacion('Elaboración', 'N:1');
        $conversion = $this->escenario->tipoDeRelacion('Conversión', '1:1');
        $delPollo = [$this->linea('pollo', 'entrada', 1), $this->linea('pechuga', 'salida', 0.23), $this->linea('alas', 'salida', 0.12)];
        $deLaHamburguesa = [$this->linea('cerdo picado', 'entrada', 0.4), $this->linea('vacuno picado', 'entrada', 0.6), $this->linea('hamburguesa', 'salida', 1)];
        $delAtado = [$this->linea('atado de leche', 'entrada', 1, 'ud'), $this->linea('cartón de leche', 'salida', 6, 'ud')];

        foreach ([[$despiece, $delPollo], [$elaboracion, $deLaHamburguesa], [$conversion, $delAtado]] as [$tipo, $lineas]) {
            $respuesta = $this->relaciones()->alta($tipo, [], $lineas, $this->usuario);

            self::assertSame('1.0', $respuesta['datos']['version']);
            self::assertSame('Activo', $this->fila($respuesta['datos']['id'])['estado']);
            self::assertSame($this->comoSeGuardan($lineas), $this->lineasGuardadas($respuesta['datos']['id']));
        }
    }

    /**
     * @comportamiento Un alta que la admisión rechaza no deja nada: ni la relación ni un
     *   borrador a medias. Quien la pidió recibe el motivo.
     */
    public function test_T15_unAltaQueNoCumpleLaFormaDeSuTipoNoDejaNiElBorrador(): void
    {
        $despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        $dosEntradas = [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'entrada'), $this->linea('alas', 'salida')];

        $respuesta = $this->relaciones()->alta($despiece, [], $dosEntradas, $this->usuario);

        self::assertStringContainsString('una sola entrada', $respuesta['error']['mensaje']);
        self::assertSame(0, $this->cuantas('relaciones'));
        self::assertSame(0, $this->cuantas('relacionesLineas'));
    }

    /**
     * @comportamiento Un producto puede estar en varias relaciones a la vez, saliendo de una y
     *   entrando en otras: la carne picada sale de un despiece y entra en dos elaboraciones.
     */
    public function test_T16_unProductoPuedeSalirDeUnaRelacionYEntrarEnOtrasDos(): void
    {
        $despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        $elaboracion = $this->escenario->tipoDeRelacion('Elaboración', 'N:1');
        $relaciones = $this->relaciones();

        $uno = $relaciones->alta($despiece, [], [$this->linea('cerdo', 'entrada'), $this->linea('cerdo picado', 'salida')], $this->usuario);
        $dos = $relaciones->alta($elaboracion, [], [$this->linea('cerdo picado', 'entrada'), $this->linea('hamburguesa', 'salida')], $this->usuario);
        $tres = $relaciones->alta($elaboracion, [], [$this->linea('cerdo picado', 'entrada'), $this->linea('criollo', 'salida')], $this->usuario);

        self::assertArrayHasKey('datos', $uno);
        self::assertArrayHasKey('datos', $dos);
        self::assertArrayHasKey('datos', $tres);
        self::assertSame(3, $this->cuantas('relaciones'));
    }

    /**
     * @comportamiento El cruce de referencias lleva dirección: qué referencia se pide y con
     *   cuál se confunde. Si la confusión se da en los dos sentidos, son dos relaciones.
     */
    public function test_T17_unCruceEnCadaSentidoSonDosRelacionesDistintas(): void
    {
        $cruce = $this->escenario->tipoDeRelacion('Cruce', '1:1', 0);
        $deMesaAZumo = [$this->linea('naranja de mesa', 'entrada', null, null), $this->linea('naranja de zumo', 'salida', null, null)];
        $deZumoAMesa = [$this->linea('naranja de zumo', 'entrada', null, null), $this->linea('naranja de mesa', 'salida', null, null)];

        $uno = $this->relaciones()->alta($cruce, [], $deMesaAZumo, $this->usuario);
        $dos = $this->relaciones()->alta($cruce, [], $deZumoAMesa, $this->usuario);

        self::assertArrayHasKey('datos', $uno);
        self::assertArrayHasKey('datos', $dos);
        self::assertNotSame($uno['datos']['numRelacion'], $dos['datos']['numRelacion']);
    }

    public function test_T18_dosTiposConLaMismaFormaEntreLosMismosProductosSeGuardanComoRelacionesDistintas(): void
    {
        $sustitucion = $this->escenario->tipoDeRelacion('Sustitución', '1:1');
        $conversion = $this->escenario->tipoDeRelacion('Conversión', '1:1');
        $lineas = [$this->linea('pechuga', 'entrada'), $this->linea('pechuga envasada', 'salida')];

        $uno = $this->relaciones()->alta($sustitucion, [], $lineas, $this->usuario);
        $dos = $this->relaciones()->alta($conversion, [], $lineas, $this->usuario);

        self::assertSame($sustitucion, (int) $this->fila($uno['datos']['id'])['idTipo']);
        self::assertSame($conversion, (int) $this->fila($dos['datos']['id'])['idTipo']);
    }

    public function test_T19_lasCantidadesMasPequenasYMasGrandesSeLeenComoSeGuardaron(): void
    {
        $conversion = $this->escenario->tipoDeRelacion('Conversión', '1:1');
        $enKilos = [$this->linea('pollo', 'entrada', 0.001, 'kg'), $this->linea('pechuga', 'salida', 99999999999.999, 'kg')];
        $enUnidades = [$this->linea('atado de leche', 'entrada', 1, 'ud'), $this->linea('cartón de leche', 'salida', 99999999999, 'ud')];

        $kilos = $this->relaciones()->alta($conversion, [], $enKilos, $this->usuario);
        $unidades = $this->relaciones()->alta($conversion, [], $enUnidades, $this->usuario);

        self::assertSame(['0.001000', '99999999999.999000'], array_column($this->lineasGuardadas($kilos['datos']['id']), 'cantidad'));
        self::assertSame(['1.000000', '99999999999.000000'], array_column($this->lineasGuardadas($unidades['datos']['id']), 'cantidad'));
    }

    public function test_T20_unaModificacionDirectaDejaLaVersionNuevaRigiendoYLaAnteriorSustituida(): void
    {
        $despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        $alta = $this->relaciones()->alta($despiece, [], [$this->linea('pollo', 'entrada', 1), $this->linea('pechuga', 'salida', 0.23)], $this->usuario);
        $ajustadas = [$this->linea('pollo', 'entrada', 1), $this->linea('pechuga', 'salida', 0.25)];

        $respuesta = $this->relaciones()->modificar($alta['datos']['numRelacion'], [], $ajustadas, $this->usuario, 'Pesada de un pollo');

        self::assertSame('1.1', $respuesta['datos']['version']);
        self::assertSame('proporciones', $respuesta['datos']['tipoCambio']);
        self::assertSame('Sustituido', $this->fila($alta['datos']['id'])['estado']);
        self::assertSame('Activo', $this->fila($respuesta['datos']['id'])['estado']);
    }

    /**
     * @comportamiento Si la relación tiene un borrador a medias, no se puede modificar por la
     *   vía directa hasta que ese borrador se active o se descarte: habría dos cambios en curso.
     */
    public function test_T21_unaModificacionDirectaSeRechazaSiLaRelacionTieneUnBorradorPendiente(): void
    {
        $despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        $alta = $this->relaciones()->alta($despiece, [], [$this->linea('pollo', 'entrada', 1), $this->linea('pechuga', 'salida', 0.23)], $this->usuario);
        $this->relaciones()->crearBorradorDeCambio($alta['datos']['numRelacion'], $this->usuario);

        $respuesta = $this->relaciones()->modificar($alta['datos']['numRelacion'], [], [$this->linea('pollo', 'entrada', 1), $this->linea('pechuga', 'salida', 0.25)], $this->usuario, 'Pesada');

        self::assertStringContainsString('ya tiene un borrador', $respuesta['error']['mensaje']);
        self::assertSame('Activo', $this->fila($alta['datos']['id'])['estado']);
    }

    public function test_T22_unaModificacionRechazadaNoDejaUnBorradorDetras(): void
    {
        $despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        $alta = $this->relaciones()->alta($despiece, [], [$this->linea('pollo', 'entrada', 1), $this->linea('pechuga', 'salida', 0.23)], $this->usuario);

        // Un ajuste de cantidades sin decir de dónde sale.
        $respuesta = $this->relaciones()->modificar($alta['datos']['numRelacion'], [], [$this->linea('pollo', 'entrada', 1), $this->linea('pechuga', 'salida', 0.25)], $this->usuario);

        self::assertStringContainsString('origen', $respuesta['error']['mensaje']);
        self::assertSame(1, $this->cuantas('relaciones'));
        self::assertSame('Activo', $this->fila($alta['datos']['id'])['estado']);
    }

    public function test_T23_siElAltaFallaAMitadNoQuedaNada(): void
    {
        $despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        $lineas = [$this->linea('pollo', 'entrada', 1), $this->linea('pechuga', 'salida', 0.23)];

        // Se recorre el alta entera, haciendo fallar cada vez una consulta más tardía.
        for ($consulta = 1; $consulta <= 40; $consulta++) {
            $relaciones = $this->relaciones();
            $relaciones->fallarEnLaConsulta = $consulta;
            $relaciones->lanzarElFallo = false;
            $respuesta = $relaciones->alta($despiece, [], $lineas, $this->usuario);
            if (isset($respuesta['datos'])) {
                break;
            }
            self::assertSame(0, $this->cuantas('relaciones'), "Fallo en la consulta $consulta");
            self::assertSame(0, $this->cuantas('relacionesLineas'), "Fallo en la consulta $consulta");
        }

        self::assertGreaterThan(5, $consulta, 'El alta tiene más de cinco consultas que pueden fallar');
        self::assertArrayHasKey('datos', $respuesta);
    }

    /**
     * @comportamiento Dar de alta, modificar y dar de baja una relación solo escribe en las
     *   tres tablas del módulo: ni el coste, ni el nombre ni las existencias de un artículo cambian.
     */
    public function test_T24_lasOperacionesSoloEscribenEnLasTablasDelModulo(): void
    {
        $despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        $relaciones = $this->relaciones();
        $alta = $relaciones->alta($despiece, [], [$this->linea('pollo', 'entrada', 1), $this->linea('pechuga', 'salida', 0.23)], $this->usuario);
        $relaciones->modificar($alta['datos']['numRelacion'], [], [$this->linea('pollo', 'entrada', 1), $this->linea('pechuga', 'salida', 0.25)], $this->usuario, 'Pesada');
        $relaciones->darDeBaja($alta['datos']['numRelacion'], $this->usuario);

        $escrituras = array_filter($relaciones->sentencias, static fn(string $sql) => !str_starts_with(ltrim($sql), 'SELECT'));
        self::assertNotEmpty($escrituras);
        foreach ($escrituras as $sql) {
            self::assertMatchesRegularExpression('/^(INSERT INTO|UPDATE|DELETE FROM) relaciones(Lineas)? /', ltrim($sql));
        }
    }

    /**
     * La clase del módulo con sus operaciones internas a la vista, y la posibilidad de
     * hacer fallar la consulta que se le indique. El código del producto no lleva nada
     * pensado para pruebas: el fallo se provoca desde aquí.
     */
    private function modelo(): object
    {
        return new class extends \Relaciones {
            public int $fallarEnLaConsulta = 0;
            /** Si el fallo provocado se lanza o se devuelve como hace la base del módulo. */
            public bool $lanzarElFallo = true;
            /** @var list<string> */
            public array $sentencias = [];
            private int $consultas = 0;

            public function consultar(string $sql, array $valores = []): array
            {
                return $this->consultaPreparada($sql, $valores);
            }

            /**
             * Una escritura de varios pasos, escrita como la escribe una operación del
             * módulo: iniciar, ejecutar los pasos recogiendo cualquier fallo, y terminar.
             */
            public function transaccion(callable $pasos): array
            {
                $this->iniciarEscritura();
                try {
                    $respuesta = $pasos();
                } catch (\Throwable $e) {
                    $respuesta = ['error' => $this->montarAdvertencia('danger', 'La escritura no se pudo completar.'), 'consulta' => ''];
                }

                return $this->terminarEscritura($respuesta);
            }

            protected function consultaPreparada($sql, $valores = array())
            {
                $this->sentencias[] = $sql;
                if (++$this->consultas === $this->fallarEnLaConsulta) {
                    if ($this->lanzarElFallo) {
                        throw new \RuntimeException('Fallo provocado por la prueba');
                    }

                    return ['error' => $this->montarAdvertencia('danger', 'Fallo provocado por la prueba'), 'consulta' => $sql];
                }

                return parent::consultaPreparada($sql, $valores);
            }
        };
    }

    private function relaciones(): object
    {
        return $this->modelo();
    }

    /** Un artículo sembrado con ese nombre; el mismo nombre devuelve el mismo artículo. */
    private function linea(string $nombre, string $sentido, $cantidad = 1, ?string $unidad = 'kg'): array
    {
        if (!isset($this->articulo[$nombre])) {
            $this->articulo[$nombre] = (new Siembra($this->db))->articulo('Prueba de relaciones: ' . $nombre);
        }

        return ['idArticulo' => $this->articulo[$nombre], 'sentido' => $sentido, 'cantidad' => $cantidad, 'unidad' => $unidad];
    }

    private function fila(int $id): ?array
    {
        return $this->db->query("SELECT * FROM relaciones WHERE id = $id")->fetch_assoc();
    }

    private function lineasGuardadas(int $idRelacion): array
    {
        return $this->db->query("SELECT idArticulo, sentido, cantidad, unidad FROM relacionesLineas WHERE idRelacion = $idRelacion ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    }

    /** Las líneas pedidas, con la forma en que la base las devuelve. */
    private function comoSeGuardan(array $lineas): array
    {
        return array_map(static fn(array $l) => [
            'idArticulo' => (string) $l['idArticulo'],
            'sentido' => $l['sentido'],
            'cantidad' => number_format((float) $l['cantidad'], 6, '.', ''),
            'unidad' => $l['unidad'],
        ], $lineas);
    }

    private function cuantas(string $tabla): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM $tabla")->fetch_assoc()['n'];
    }

    private function filasDePrueba(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM relacionesTipos WHERE nombre LIKE 'prueba%'")->fetch_assoc()['n'];
    }

    private function limpiar(): void
    {
        $this->db->query("DELETE FROM relacionesTipos WHERE nombre LIKE 'prueba%'");
    }
}

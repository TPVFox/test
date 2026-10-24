<?php
/**
 * El planificador de tareas: qué tareas lanza en cada pasada y cuándo, qué escribe en el diario y
 * qué hace cuando una tarea no se encuentra, no carga o falla.
 *
 * Las tareas que lanza son las de `support/raiz-tareas-cron/`, que solo anotan que se ejecutaron.
 * El último caso recorre las dos tareas reales del módulo sobre la raíz de TPVFox.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModTareasCron;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\PrecargaInicial;
use TPVFox\Test\RegistroDeTareas;
use TPVFox\Test\Siembra\EscenarioTareasCron;
use TPVFox\Test\Siembra\Siembra;

final class PlanificadorIntegracionTest extends CasoIntegracion
{
    /** Una fecha de inicio ya pasada: con ella, solo decide el periodo. */
    private const INICIO_PASADO = '2020-01-01';

    protected bool $compartirConexionConElProducto = true;

    private EscenarioTareasCron $siembra;

    private int $diarioPrevio;

    private string $ficheroDeAvisos;

    private string $ficheroDeAvisosPrevio;

    protected function setUp(): void
    {
        parent::setUp();
        PrecargaInicial::hacer();
        $this->incluirTPVFox('/modulos/mod_tareas_cron/clases/TareasCron.php');
        RegistroDeTareas::vaciar();

        $this->siembra = new EscenarioTareasCron($this->db);
        $this->siembra->soloLasDelCaso();
        $this->diarioPrevio = $this->siembra->ultimaEntradaDelDiario();

        // El planificador avisa con error_log de la tarea que no encuentra o no carga: los avisos van
        // a un fichero del caso, que se lee después y no ensucia la consola.
        $this->ficheroDeAvisos = (string) tempnam(sys_get_temp_dir(), 'planificador-');
        $this->ficheroDeAvisosPrevio = (string) ini_get('error_log');
        ini_set('error_log', $this->ficheroDeAvisos);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->ficheroDeAvisosPrevio);
        if (is_file($this->ficheroDeAvisos)) {
            unlink($this->ficheroDeAvisos);
        }
        parent::tearDown();
    }

    public function test_T1_unaTareaQueNuncaSeEjecutoSeLanzaEnLaPrimeraPasada(): void
    {
        $id = $this->tarea('TareaQueAnota');

        $this->pasada();

        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($id));
    }

    /**
     * @comportamiento Con un periodo de 60 minutos, una tarea que se ejecutó hace 61 se lanza, y una
     *   que se ejecutó hace 59 espera.
     */
    public function test_T2_unaTareaSeLanzaCuandoHaPasadoSuPeriodoEnMinutos(): void
    {
        $vencida = $this->tarea('TareaQueAnota', ['cantidad' => 60, 'ultima' => self::hace(61)]);
        $enPlazo = $this->tarea('TareaQueAnota', ['cantidad' => 60, 'ultima' => self::hace(59)]);

        $this->pasada();

        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($vencida));
        self::assertSame(0, RegistroDeTareas::vecesQueSeEjecuto($enPlazo));
    }

    /**
     * @comportamiento El periodo en horas y en días se pasa a minutos: cada 2 horas son 120 minutos,
     *   y cada día, 1.440.
     */
    public function test_T3_elPeriodoEnHorasYEnDiasSeMideEnSusMinutos(): void
    {
        $horas = ['cantidad' => 2, 'tipo' => \MTareasCron::PERIODO_HORAS];
        $dias = ['cantidad' => 1, 'tipo' => \MTareasCron::PERIODO_DIAS];
        $horasVencida = $this->tarea('TareaQueAnota', $horas + ['ultima' => self::hace(121)]);
        $horasEnPlazo = $this->tarea('TareaQueAnota', $horas + ['ultima' => self::hace(119)]);
        $diasVencida = $this->tarea('TareaQueAnota', $dias + ['ultima' => self::hace(1441)]);
        $diasEnPlazo = $this->tarea('TareaQueAnota', $dias + ['ultima' => self::hace(1439)]);

        $this->pasada();

        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($horasVencida));
        self::assertSame(0, RegistroDeTareas::vecesQueSeEjecuto($horasEnPlazo));
        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($diasVencida));
        self::assertSame(0, RegistroDeTareas::vecesQueSeEjecuto($diasEnPlazo));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:26-31
     *
     * @group esperado
     * @group periodo
     * @group alto
     *
     * @que-ocurre-hoy Una tarea mensual se lanza en cada pasada: su periodo se cuenta como minutos, y
     *   un minuto ya ha pasado siempre.
     * @que-deberia-ocurrir Que una tarea mensual que se ejecutó hace dos días espere a que pase el
     *   mes.
     * @por-que-ocurre La conversión a minutos tiene dos casos para el tipo 3, días, y ninguno para el
     *   4, meses: el segundo no se alcanza nunca, y el mes se queda en la cantidad sin convertir.
     * @como-deberia-funcionar Convertir el tipo 4, meses, en sus minutos.
     */
    public function test_T4_unaTareaMensualEsperaAQuePaseElMes(): void
    {
        $id = $this->tarea('TareaQueAnota', [
            'cantidad' => 1,
            'tipo' => \MTareasCron::PERIODO_MESES,
            'ultima' => self::hace(2 * 1440),
        ]);

        $this->pasada();

        self::assertSame(0, RegistroDeTareas::vecesQueSeEjecuto($id), 'Una tarea mensual no puede lanzarse a los dos días.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:73-75
     *
     * @group esperado
     * @group periodo
     * @group medio
     *
     * @que-ocurre-hoy Una tarea que nunca se ejecutó se lanza en la primera pasada aunque su fecha de
     *   inicio sea futura.
     * @que-deberia-ocurrir Que espere a su fecha de inicio.
     * @por-que-ocurre La fecha de inicio solo se compara con la última ejecución; si no la hay, la
     *   tarea se lanza sin mirarla.
     * @como-deberia-funcionar Comparar la fecha de inicio con la de hoy antes de lanzar una tarea que
     *   no se ha ejecutado nunca.
     */
    public function test_T5_unaTareaConLaFechaDeInicioFuturaNoSeLanza(): void
    {
        $id = $this->tarea('TareaQueAnota', ['inicio' => date('Y-m-d', strtotime('+1 day'))]);

        $this->pasada();

        self::assertSame(0, RegistroDeTareas::vecesQueSeEjecuto($id), 'La tarea tiene que esperar a su fecha de inicio.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:52-54
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:70-71
     *
     * @group esperado
     * @group periodo
     * @group alto
     *
     * @que-ocurre-hoy Una tarea cuya última ejecución es anterior a su fecha de inicio no vuelve a
     *   lanzarse, aunque esa fecha ya haya pasado, y cada pasada anota en el diario que la fecha de
     *   inicio no ha llegado. Es lo que le ocurre a una tarea ya ejecutada a la que se pone una fecha
     *   de inicio posterior.
     * @que-deberia-ocurrir Que, pasada la fecha de inicio, la tarea se lance cuando venza su periodo.
     * @por-que-ocurre El planificador compara la fecha de inicio con la última ejecución, no con la
     *   fecha de hoy. Como la tarea no se lanza, la última ejecución no cambia, y la comparación da
     *   siempre lo mismo.
     * @como-deberia-funcionar Comparar la fecha de inicio con la de hoy.
     */
    public function test_T6_unaTareaEjecutadaAntesDeSuFechaDeInicioSeLanzaUnaVezPasada(): void
    {
        $id = $this->tarea('TareaQueAnota', [
            'inicio' => date('Y-m-d', strtotime('-5 days')),
            'ultima' => self::hace(10 * 1440),
        ]);

        $this->pasada();

        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($id), 'Pasada la fecha de inicio y vencido el periodo, la tarea tiene que lanzarse.');
    }

    public function test_T7_unaTareaDeBajaNoSeLanza(): void
    {
        $id = $this->tarea('TareaQueAnota', ['estado' => \MTareasCron::ESTADO_BAJA]);

        $this->pasada();

        self::assertSame(0, RegistroDeTareas::vecesQueSeEjecuto($id));
    }

    /**
     * @comportamiento Una tarea cuyo fichero no está en la carpeta de tareas pasa al estado de
     *   fichero no encontrado y deja de estar entre las activas. El aviso va al registro de errores
     *   de PHP.
     */
    public function test_T8_unaTareaSinFicheroPasaAFicheroNoEncontrado(): void
    {
        $id = $this->tarea('TareaQueNoExiste');

        $this->pasada();

        self::assertSame(\MTareasCron::ESTADO_FICHERO_NO_ENCONTRADO, (int) $this->siembra->filaDeTarea($id)['estado']);
        self::assertStringContainsString('fichero no encontrado', $this->avisos());
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:82-83
     *
     * @group esperado
     * @group ejecucion
     * @group medio
     *
     * @que-ocurre-hoy Si el fichero de la tarea existe pero no declara su clase, el planificador solo
     *   lo avisa en el registro de errores de PHP. La tarea sigue activa, y cada pasada vuelve a
     *   anunciarla en el diario y a fallar igual.
     * @que-deberia-ocurrir Que la tarea deje de estar activa, como cuando falta el fichero.
     * @por-que-ocurre Ese caso no cambia el estado de la tarea; el del fichero que falta, sí.
     * @como-deberia-funcionar Pasar la tarea a un estado de error, como se hace cuando falta el
     *   fichero.
     */
    public function test_T9_unaTareaCuyoFicheroNoTieneLaClaseDejaDeEstarActiva(): void
    {
        $id = $this->tarea('FicheroSinClase');

        $this->pasada();

        self::assertStringContainsString('No carga la clase: FicheroSinClase', $this->avisos());
        self::assertNotSame(\MTareasCron::ESTADO_ACTIVO, (int) $this->siembra->filaDeTarea($id)['estado'], 'La tarea no puede seguir activa.');
    }

    /**
     * @comportamiento Cada pasada empieza el diario con dos entradas sin tarea. Antes de lanzar una
     *   tarea escribe tres más, atadas a ella: que la ejecuta, la ruta de su fichero y su nombre.
     */
    public function test_T10_elDiarioAnunciaLaPasadaYCadaTareaQueLanza(): void
    {
        $id = $this->tarea('TareaQueAnota', ['nombre' => 'Tarea que anota']);

        $this->pasada();

        self::assertSame([
            ['descripcion' => 'Empezamos', 'tarea_id' => null],
            ['descripcion' => 'Hay tareas', 'tarea_id' => null],
            ['descripcion' => 'ejecutar', 'tarea_id' => $id],
            ['descripcion' => 'estoy:' . RegistroDeTareas::raiz() . '/modulos/mod_tareas_cron/tareas/TareaQueAnota.php', 'tarea_id' => $id],
            ['descripcion' => 'Ejecuta tarea Tarea que anota', 'tarea_id' => $id],
        ], $this->siembra->diarioDesde($this->diarioPrevio));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:87-88
     *
     * @group esperado
     * @group diario
     * @group medio
     *
     * @que-ocurre-hoy El diario anuncia cada tarea antes de lanzarla y después no escribe nada: no
     *   dice si terminó ni si falló.
     * @que-deberia-ocurrir Que el diario deje constancia de cómo acabó cada tarea que lanza.
     * @por-que-ocurre Las tres entradas de la tarea se escriben antes de ejecutarla, y después no se
     *   escribe ninguna.
     * @como-deberia-funcionar Escribir en el diario, atado a la tarea, si terminó o con qué error.
     */
    public function test_T11_elDiarioDiceComoAcaboCadaTarea(): void
    {
        $id = $this->tarea('TareaQueAnota', ['nombre' => 'Tarea que anota']);

        $this->pasada();

        $entradas = $this->diarioDe($id);
        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($id));
        self::assertNotSame('Ejecuta tarea Tarea que anota', end($entradas), 'Después de lanzar la tarea, el diario tiene que decir cómo acabó.');
    }

    /**
     * @comportamiento El planificador no anota la ejecución de la tarea: lo deja a la propia tarea.
     *   Una que no la anota sigue sin última ejecución y se lanza en cada pasada.
     */
    public function test_T12_elPlanificadorDejaAnotarLaEjecucionALaTarea(): void
    {
        $id = $this->tarea('TareaQueAnota');

        $this->pasada();
        $this->pasada();

        $fila = $this->siembra->filaDeTarea($id);
        self::assertNull($fila['ultima_ejecucion']);
        self::assertSame(\MTareasCron::ESTADO_ACTIVO, (int) $fila['estado']);
        self::assertSame(2, RegistroDeTareas::vecesQueSeEjecuto($id));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:87-88
     *
     * @group esperado
     * @group ejecucion
     * @group alto
     *
     * @que-ocurre-hoy Si una tarea falla con una excepción, la excepción sale del planificador sin
     *   recoger y la pasada se corta: las tareas que venían detrás no se lanzan.
     * @que-deberia-ocurrir Que el fallo de una tarea no impida lanzar las demás.
     * @por-que-ocurre El planificador lanza cada tarea sin recoger lo que pueda lanzar.
     * @como-deberia-funcionar Recoger el fallo de cada tarea, dejarlo anotado y seguir con la
     *   siguiente.
     */
    public function test_T13_unaTareaQueFallaNoImpideLanzarLasDemas(): void
    {
        $falla = $this->tarea('TareaQueFalla');
        $siguiente = $this->tarea('TareaQueAnota');

        try {
            $this->pasada();
        } catch (\RuntimeException $e) {
            // Lo que decide es si la tarea siguiente se lanzó.
        }

        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($falla));
        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($siguiente), 'La tarea siguiente tiene que lanzarse.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:43-47
     *
     * @group esperado
     * @group ejecucion
     * @group alto
     *
     * @que-ocurre-hoy Si una pasada empieza antes de que acabe la anterior, las dos lanzan las mismas
     *   tareas: nada marca que hay una pasada en curso ni qué tareas ha lanzado ya.
     * @que-deberia-ocurrir Que cada tarea se lance una sola vez aunque coincidan dos pasadas.
     * @por-que-ocurre El planificador lee las tareas activas sin bloquear la pasada ni marcar las que
     *   lanza. Solo la tarea del coste medio se pone en proceso al empezar; la del último coste, no.
     * @como-deberia-funcionar Que una pasada no empiece mientras haya otra en curso, o marcar en
     *   proceso cada tarea antes de lanzarla.
     */
    public function test_T14_dosPasadasQueCoincidenNoLanzanDosVecesLaMismaTarea(): void
    {
        $this->tarea('TareaQueRelanza');
        $id = $this->tarea('TareaQueAnota');

        $this->pasada();

        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($id), 'La tarea no puede lanzarse dos veces.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/DiarioCron.php:12
     *
     * @group esperado
     * @group diario
     * @group alto
     *
     * @que-ocurre-hoy Un apóstrofo en el nombre de una tarea rompe la entrada del diario que lo
     *   lleva: la base rechaza la instrucción, y la pasada se corta antes de lanzar esa tarea y las
     *   que vienen detrás. La pantalla de tareas admite ese nombre.
     * @que-deberia-ocurrir Que el diario guarde el nombre tal cual y la pasada siga.
     * @por-que-ocurre La entrada del diario se forma pegando el texto a la instrucción, sin escaparlo.
     * @como-deberia-funcionar Pasar el texto como parámetro de la instrucción, o escaparlo.
     */
    public function test_T15_unApostrofoEnElNombreNoRompeElDiarioNiLaPasada(): void
    {
        [$conApostrofo, $errores] = (new \CTareasCron())->guardar([
            'id' => '',
            'nombre' => "Coste d'entrada",
            'cantidad_periodo' => '60',
            'tipo_periodo' => '1',
            'inicio_ejecucion' => self::INICIO_PASADO,
            'nombre_clase' => 'TareaQueAnota',
            'estado' => '1',
        ]);
        $siguiente = $this->tarea('TareaQueAnota');

        try {
            $this->pasada();
        } catch (\mysqli_sql_exception $e) {
            // Lo que decide es si las dos tareas se lanzaron.
        }

        self::assertSame([], $errores, 'La pantalla de tareas admite el nombre.');
        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto((int) $conApostrofo), 'La tarea con apóstrofo tiene que lanzarse.');
        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($siguiente));
    }

    /**
     * @comportamiento Sobre la raíz de TPVFox, una pasada lanza las dos tareas reales del módulo, y
     *   cada una anota su ejecución. La del último coste pone a cada artículo el coste de su compra
     *   más reciente, y la del coste medio, la media de sus compras ponderada por unidades.
     */
    public function test_T16_unaPasadaSobreTPVFoxLanzaLasDosTareasDeCoste(): void
    {
        $compras = new Siembra($this->db);
        $ano = $this->ano();
        $primero = $compras->articulo('Artículo con dos compras', ['ultimoCoste' => 1.0]);
        $segundo = $compras->articulo('Artículo con una compra', ['ultimoCoste' => 1.0]);
        $compras->entradaProveedor($primero, 10, "$ano-01-10", ['coste' => 2.0]);
        $compras->entradaProveedor($primero, 30, "$ano-02-10", ['coste' => 3.0]);
        $compras->entradaProveedor($segundo, 5, "$ano-03-10", ['coste' => 4.0]);
        $costeMedio = $this->siembra->tarea('Coste medio', 'AcumuladoComprasTarea', ['inicio' => self::INICIO_PASADO]);
        $ultimoCoste = $this->siembra->tarea('Último coste', 'UltimoPrecioCompraTarea', ['inicio' => self::INICIO_PASADO]);

        (new \TareasCron(RUTA_TPVFOX))->execute();

        foreach ([$costeMedio, $ultimoCoste] as $id) {
            $fila = $this->siembra->filaDeTarea($id);
            self::assertSame(\MTareasCron::ESTADO_ACTIVO, (int) $fila['estado']);
            self::assertLessThan(60, abs(strtotime((string) $fila['ultima_ejecucion']) - time()));
        }
        self::assertEqualsWithDelta(3.0, $this->siembra->ultimoCoste($primero), 0.0001);
        self::assertEqualsWithDelta(4.0, $this->siembra->ultimoCoste($segundo), 0.0001);
        // (10 × 2 + 30 × 3) / 40. Solo el del primero: el coste medio del último artículo que recorre
        // la tarea es cosa de las pruebas de esa tarea.
        self::assertEqualsWithDelta(2.75, $this->siembra->costeMedio($primero), 0.0001);
    }

    /**
     * @comportamiento Una tarea ya ejecutada cuya fecha de inicio es futura espera a esa fecha: su
     *   última ejecución queda antes de la fecha de inicio, y el planificador no la lanza.
     */
    public function test_T17_unaTareaYaEjecutadaConLaFechaDeInicioFuturaEspera(): void
    {
        $id = $this->tarea('TareaQueAnota', [
            'inicio' => date('Y-m-d', strtotime('+5 days')),
            'ultima' => self::hace(1440),
        ]);

        $this->pasada();

        self::assertSame(0, RegistroDeTareas::vecesQueSeEjecuto($id));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/TareaCron.php:89
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:52-54
     *
     * @group esperado
     * @group periodo
     * @group critico
     *
     * @que-ocurre-hoy Guardar sin tocar nada la ficha de una tarea que ya se ejecutó le pone la fecha
     *   de hoy como inicio, y desde ese momento el planificador no vuelve a lanzarla: su última
     *   ejecución queda antes de la fecha de inicio, y solo cambiaría si la tarea se ejecutara.
     * @que-deberia-ocurrir Que la tarea siga lanzándose cuando venza su periodo.
     * @por-que-ocurre La ficha propone siempre la fecha de hoy como inicio, y el planificador compara
     *   la fecha de inicio con la última ejecución, no con la fecha de hoy.
     * @como-deberia-funcionar Que la ficha proponga la fecha guardada, y que el planificador compare
     *   la fecha de inicio con la de hoy.
     */
    public function test_T18_unaTareaYaEjecutadaGuardadaSinTocarSigueLanzandoseCuandoLeToca(): void
    {
        $id = $this->tarea('TareaQueAnota', ['nombre' => 'Guardada sin tocar', 'cantidad' => 60, 'ultima' => self::hace(2 * 1440)]);

        // Lo que envía la ficha de esa tarea si se guarda sin tocar nada: hoy como fecha de inicio.
        [, $errores] = (new \CTareasCron())->guardar([
            'id' => (string) $id,
            'nombre' => 'Guardada sin tocar',
            'cantidad_periodo' => '60',
            'tipo_periodo' => '1',
            'inicio_ejecucion' => date('Y-m-d'),
            'nombre_clase' => 'TareaQueAnota',
            'estado' => '1',
        ]);
        $this->pasada();

        self::assertSame([], $errores);
        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($id), 'Vencido su periodo, la tarea tiene que lanzarse.');
    }

    /**
     * @comportamiento El fichero se comprueba antes de mirar si a la tarea le toca: una tarea sin
     *   fichero pasa al estado de fichero no encontrado aunque su periodo no haya vencido.
     */
    public function test_T19_unaTareaSinFicheroPasaAFicheroNoEncontradoAunqueNoLeToque(): void
    {
        $id = $this->tarea('TareaQueNoExiste', ['cantidad' => 60, 'ultima' => self::hace(10)]);

        $this->pasada();

        self::assertSame(\MTareasCron::ESTADO_FICHERO_NO_ENCONTRADO, (int) $this->siembra->filaDeTarea($id)['estado']);
    }

    /**
     * @comportamiento Una tarea que se pone en proceso al empezar, como la del coste medio, no se
     *   vuelve a lanzar en una pasada que empieza mientras ella corre: la pasada solo lee las tareas
     *   activas.
     */
    public function test_T20_unaTareaQueSePoneEnProcesoNoSeRelanzaEnUnaPasadaQueCoincide(): void
    {
        $id = $this->tarea('TareaQueSePoneEnProceso');

        $this->pasada();

        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($id));
        self::assertSame(1, RegistroDeTareas::maximoALaVez('TareaQueSePoneEnProceso'));
        self::assertSame(\MTareasCron::ESTADO_ACTIVO, (int) $this->siembra->filaDeTarea($id)['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:43-47
     * @codigo-afectado modulos/mod_tareas_cron/clases/CTareasCron.php:44-70
     *
     * @group esperado
     * @group ejecucion
     * @group medio
     *
     * @que-ocurre-hoy Dos tareas pueden nombrar la misma clase. Si una pasada empieza mientras corre
     *   una de ellas, lanza la otra: el mismo cálculo corre dos veces a la vez sobre los mismos datos.
     *   La marca de en proceso es de cada tarea y no protege a la otra.
     * @que-deberia-ocurrir Que no corran a la vez dos ejecuciones de la misma clase.
     * @por-que-ocurre La pasada solo deja fuera a la tarea en proceso, no a las demás de su clase, y
     *   la ficha admite una clase que ya tiene otra tarea.
     * @como-deberia-funcionar No admitir dos tareas con la misma clase, o no lanzar una tarea mientras
     *   otra de su clase está en proceso.
     */
    public function test_T21_dosTareasDeLaMismaClaseNoCorrenALaVez(): void
    {
        $this->tarea('TareaQueSePoneEnProceso', ['nombre' => 'Primera']);
        $this->tarea('TareaQueSePoneEnProceso', ['nombre' => 'Segunda']);

        $this->pasada();

        self::assertSame(1, RegistroDeTareas::maximoALaVez('TareaQueSePoneEnProceso'), 'Dos ejecuciones de la misma clase no pueden coincidir.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:48-49
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:81
     *
     * @group esperado
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Con un nombre de clase que es una ruta fuera de la carpeta de tareas, el
     *   planificador incluye ese fichero y ejecuta su código. La pantalla de tareas admite ese nombre.
     * @que-deberia-ocurrir Que solo incluya ficheros de la carpeta de tareas.
     * @por-que-ocurre El planificador forma la ruta pegando el nombre de clase guardado a la carpeta de
     *   tareas, y no comprueba adónde lleva.
     * @como-deberia-funcionar Rechazar un nombre de clase con barras o puntos antes de formar la ruta.
     */
    public function test_T22_elPlanificadorNoIncluyeUnFicheroDeFueraDeLaCarpetaDeTareas(): void
    {
        $clase = '../../../fuera-de-tareas/OtroDeFuera';
        $ruta = RegistroDeTareas::raiz() . '/modulos/mod_tareas_cron/tareas/' . $clase . '.php';
        self::assertLessThanOrEqual(150, strlen('estoy:' . $ruta), 'Precondición: la ruta cabe en el diario, que si no corta la pasada antes de incluir.');
        $this->tarea($clase);

        $this->pasada();

        self::assertFalse(RegistroDeTareas::seIncluyo('OtroDeFuera.php'), 'Un fichero de fuera de la carpeta de tareas no puede incluirse.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:59
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:63
     * @codigo-afectado modulos/mod_tareas_cron/clases/TareasCron.php:68
     *
     * @group esperado
     * @group diario
     * @group medio
     *
     * @que-ocurre-hoy Cuando a una tarea no le toca, el diario anota los minutos de su periodo, los
     *   transcurridos y el motivo de no lanzarla, pero sin la tarea: con varias tareas, no se sabe de
     *   cuál habla cada entrada.
     * @que-deberia-ocurrir Que toda entrada que trata de una tarea lleve la tarea.
     * @por-que-ocurre Esas tres entradas se escriben sin pasar el identificador de la tarea.
     * @como-deberia-funcionar Pasar el identificador de la tarea en todas las entradas que tratan de
     *   ella.
     */
    public function test_T23_lasEntradasDelDiarioDeUnaTareaQueNoTocaLlevanLaTarea(): void
    {
        $id = $this->tarea('TareaQueAnota', ['nombre' => 'En plazo', 'cantidad' => 60, 'ultima' => self::hace(10)]);

        $this->pasada();

        $entradas = $this->siembra->diarioDesde($this->diarioPrevio);
        $deLaTarea = array_slice($entradas, 2);
        self::assertSame(['minutos periodo-->60', 'minutos-->10', 'No ejecuta tarea En plazo:No ha pasado periodo de 60 minutos'], array_column($deLaTarea, 'descripcion'));
        self::assertSame([$id, $id, $id], array_column($deLaTarea, 'tarea_id'), 'Cada entrada sobre la tarea tiene que llevarla.');
    }

    /**
     * @comportamiento Cuando la última ejecución queda antes de la fecha de inicio, el diario anota el
     *   motivo atado a la tarea.
     */
    public function test_T24_elMotivoDeLaFechaDeInicioLlevaLaTarea(): void
    {
        $id = $this->tarea('TareaQueAnota', [
            'nombre' => 'Antes de su inicio',
            'inicio' => date('Y-m-d', strtotime('+5 days')),
            'ultima' => self::hace(1440),
        ]);

        $this->pasada();

        self::assertSame(['No ejecuta tarea Antes de su inicio:fecha inicio no ha pasado'], $this->diarioDe($id));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/DiarioCron.php:12
     *
     * @group esperado
     * @group diario
     * @group medio
     *
     * @que-ocurre-hoy Una entrada del diario de más de 150 caracteres no se escribe: la base la
     *   rechaza en modo estricto y la excepción corta a quien escribía. La entrada que anuncia una
     *   tarea lleva la ruta completa de su fichero, así que pasa de 150 con una ruta de instalación
     *   larga o una clase de nombre largo.
     * @que-deberia-ocurrir Que el diario guarde la entrada, sin depender de su longitud.
     * @por-que-ocurre La columna admite 150 caracteres, y la entrada se escribe tal cual.
     * @como-deberia-funcionar No guardar la ruta entera, o ajustar la entrada a la columna.
     */
    public function test_T25_unaEntradaDelDiarioDeMasDe150CaracteresSeGuarda(): void
    {
        $excepcion = null;

        try {
            \DiarioCron::log(str_repeat('a', 151));
        } catch (\mysqli_sql_exception $e) {
            $excepcion = $e;
        }

        self::assertNull($excepcion, 'Una entrada larga no puede romper la escritura del diario.');
        self::assertCount(1, $this->siembra->diarioDesde($this->diarioPrevio));
    }

    /**
     * @comportamiento El momento de cada entrada del diario lo pone la base al escribirla.
     */
    public function test_T26_cadaEntradaDelDiarioLlevaElMomentoEnQueSeEscribe(): void
    {
        $this->tarea('TareaQueAnota');

        $this->pasada();

        $fila = $this->db->query(
            'SELECT COUNT(*) AS n, MAX(ABS(TIMESTAMPDIFF(SECOND, ejecucion, NOW()))) AS desfase FROM diario_cron WHERE id > ' . $this->diarioPrevio
        )->fetch_assoc();
        self::assertSame(5, (int) $fila['n']);
        self::assertLessThanOrEqual(60, (int) $fila['desfase']);
    }

    /**
     * @comportamiento Una tarea con periodo cero se lanza en cada pasada, aunque se acabe de ejecutar:
     *   cero minutos han pasado siempre. Es lo que hace la pasada con una tarea que llega a la base con
     *   ese periodo; la ficha no debería admitirlo.
     */
    public function test_T27_unaTareaConPeriodoCeroSeLanzaEnCadaPasada(): void
    {
        $id = $this->tarea('TareaQueAnota', ['cantidad' => 0, 'ultima' => self::hace(0)]);

        $this->pasada();

        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($id));
    }

    /**
     * @comportamiento Un tipo de periodo que no es ninguno de los cuatro queda sin convertir, y el
     *   periodo se cuenta como minutos. Es lo que hace la pasada con una tarea que llega a la base con
     *   ese tipo; la ficha no debería admitirlo.
     */
    public function test_T28_unTipoDePeriodoQueNoExisteSeCuentaComoMinutos(): void
    {
        $vencida = $this->tarea('TareaQueAnota', ['cantidad' => 60, 'tipo' => 9, 'ultima' => self::hace(61)]);
        $enPlazo = $this->tarea('TareaQueAnota', ['cantidad' => 60, 'tipo' => 9, 'ultima' => self::hace(59)]);

        $this->pasada();

        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($vencida));
        self::assertSame(0, RegistroDeTareas::vecesQueSeEjecuto($enPlazo));
    }

    /** Una pasada del planificador sobre la raíz de pruebas, como la lanza el cron. */
    private function pasada(): void
    {
        (new \TareasCron(RegistroDeTareas::raiz()))->execute();
    }

    /**
     * Una tarea de la raíz de pruebas, con una fecha de inicio ya pasada salvo que el caso diga otra.
     *
     * @param array{nombre?:string,cantidad?:int,tipo?:int,inicio?:string,ultima?:?string,estado?:int} $opciones
     */
    private function tarea(string $clase, array $opciones = []): int
    {
        $nombre = $opciones['nombre'] ?? 'Tarea de prueba';
        unset($opciones['nombre']);

        return $this->siembra->tarea($nombre, $clase, $opciones + ['inicio' => self::INICIO_PASADO]);
    }

    /**
     * Lo que el planificador escribió en el diario sobre esa tarea durante el caso, en orden.
     *
     * @return list<string>
     */
    private function diarioDe(int $idTarea): array
    {
        $entradas = [];
        foreach ($this->siembra->diarioDesde($this->diarioPrevio) as $entrada) {
            if ($entrada['tarea_id'] === $idTarea) {
                $entradas[] = $entrada['descripcion'];
            }
        }

        return $entradas;
    }

    /** Lo que el planificador dejó en el registro de errores de PHP durante el caso. */
    private function avisos(): string
    {
        return (string) file_get_contents($this->ficheroDeAvisos);
    }

    /** La fecha y hora de hace esos minutos, como la anota una tarea al ejecutarse. */
    private static function hace(int $minutos): string
    {
        return date('Y-m-d H:i:s', time() - $minutos * 60);
    }
}

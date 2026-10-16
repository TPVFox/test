<?php
/**
 * La ejecución manual: qué hace `tareas.php` cuando la pantalla de tareas pide ejecutar una, qué
 * responde y qué deja escrito.
 *
 * Cada caso es una petición de la pantalla: `tareas.php` corre con la carpeta del módulo como
 * directorio de trabajo, como lo pone el servidor web, y con `inicial.php` ya cargado. Las tareas
 * que lanza son las de `support/raiz-tareas-cron/`, salvo en el caso que ejecuta una tarea real.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModTareasCron;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\PrecargaInicial;
use TPVFox\Test\RegistroDeTareas;
use TPVFox\Test\Siembra\EscenarioTareasCron;
use TPVFox\Test\Siembra\Siembra;

final class EjecucionManualIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private EscenarioTareasCron $tareas;

    private int $diarioPrevio;

    protected function setUp(): void
    {
        parent::setUp();
        PrecargaInicial::hacer();
        $this->incluirTPVFox('/modulos/mod_tareas_cron/clases/CTareasCron.php');
        RegistroDeTareas::vaciar();
        $this->tareas = new EscenarioTareasCron($this->db);
        $this->diarioPrevio = $this->tareas->ultimaEntradaDelDiario();
    }

    public function test_T1_lanzaLaTareaPedidaYRespondeQueLaEjecuto(): void
    {
        $id = $this->tareas->tarea('Tarea que anota', 'TareaQueAnota');

        $respuesta = $this->pedirEjecucion((string) $id);

        self::assertSame(['mensaje' => 'ejecutado --->./tareas/TareaQueAnota.php', 'error' => 0], $respuesta);
        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($id));
    }

    /**
     * @comportamiento Un identificador que no es de ninguna tarea, o que no es un número, responde con
     *   el error 2 y sin datos.
     */
    public function test_T2_unaTareaQueNoExisteRespondeConElError2(): void
    {
        self::assertSame(['data' => null, 'error' => 2], $this->pedirEjecucion('999999'));
        self::assertSame(['data' => null, 'error' => 2], $this->pedirEjecucion('ninguna'));
    }

    /**
     * @comportamiento La ejecución manual no mira el estado de la tarea: lanza también una de baja.
     */
    public function test_T3_lanzaTambienUnaTareaDeBaja(): void
    {
        $id = $this->tareas->tarea('De baja', 'TareaQueAnota', ['estado' => \MTareasCron::ESTADO_BAJA]);

        $this->pedirEjecucion((string) $id);

        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($id));
    }

    /**
     * @comportamiento Desde la pantalla, una tarea real se ejecuta sobre la raíz de TPVFox igual que
     *   desde el planificador. Ejecutar a mano la del último coste, parada en el estado de error, la
     *   devuelve a activa si termina bien.
     */
    public function test_T4_unaTareaDeCosteEnErrorVuelveAActivaAlEjecutarlaAMano(): void
    {
        $compras = new Siembra($this->db);
        $articulo = $compras->articulo('Comprado una vez', ['ultimoCoste' => 1.0]);
        $compras->entradaProveedor($articulo, 10, $this->ano() . '-01-10', ['coste' => 2.0]);
        $id = $this->tareas->tarea('Último coste', 'UltimoPrecioCompraTarea', ['estado' => \MTareasCron::ESTADO_ERROR_EN_PROCESO]);

        $respuesta = $this->pedirEjecucion((string) $id, RUTA_TPVFOX);

        self::assertSame(0, $respuesta['error']);
        $tarea = $this->tareas->filaDeTarea($id);
        self::assertSame(\MTareasCron::ESTADO_ACTIVO, (int) $tarea['estado']);
        self::assertLessThan(60, abs(strtotime((string) $tarea['ultima_ejecucion']) - time()));
        self::assertEqualsWithDelta(2.0, $this->tareas->ultimoCoste($articulo), 0.0001);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/tareas.php:17-25
     *
     * @group esperado
     * @group diario
     * @group medio
     *
     * @que-ocurre-hoy La ejecución manual no escribe nada en el diario: ni que lanza la tarea ni cómo
     *   acabó. Lo que se ejecuta a mano no deja rastro en él.
     * @que-deberia-ocurrir Que deje constancia en el diario, atada a la tarea, como hace el
     *   planificador.
     * @por-que-ocurre `tareas.php` no usa el diario.
     * @como-deberia-funcionar Anotar en el diario la ejecución y su resultado, con el identificador de
     *   la tarea.
     */
    public function test_T5_laEjecucionManualDejaConstanciaEnElDiario(): void
    {
        $id = $this->tareas->tarea('Tarea que anota', 'TareaQueAnota');

        $this->pedirEjecucion((string) $id);

        self::assertSame(1, RegistroDeTareas::vecesQueSeEjecuto($id));
        self::assertNotSame([], $this->diarioDe($id), 'La ejecución manual tiene que quedar en el diario.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/tareas.php:14-20
     *
     * @group esperado
     * @group ejecucion
     * @group alto
     *
     * @que-ocurre-hoy La ejecución manual lanza una tarea que está en proceso: si otra ejecución suya
     *   sigue en curso, las dos corren a la vez.
     * @que-deberia-ocurrir Que no la lance mientras está en proceso, y responda por qué.
     * @por-que-ocurre `tareas.php` no mira el estado de la tarea antes de lanzarla.
     * @como-deberia-funcionar Comprobar que la tarea no está en proceso, y marcarla en proceso al
     *   lanzarla.
     */
    public function test_T6_unaTareaEnProcesoNoSeVuelveALanzarAMano(): void
    {
        $id = $this->tareas->tarea('En proceso', 'TareaQueAnota', ['estado' => \MTareasCron::ESTADO_EN_PROCESO]);

        $respuesta = $this->pedirEjecucion((string) $id);

        self::assertSame(0, RegistroDeTareas::vecesQueSeEjecuto($id), 'Una tarea en proceso no puede lanzarse otra vez.');
        self::assertNotSame(0, $respuesta['error'] ?? null);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/tareas.php:23
     *
     * @group esperado
     * @group estados
     * @group alto
     *
     * @que-ocurre-hoy Cuando el fichero de la tarea no existe, la respuesta lo dice, pero la tarea no
     *   pasa al estado de fichero no encontrado. En su lugar cambia la tarea número 3, si existe: su
     *   estado pasa a valer el identificador de la tarea pedida, deja de estar activa y la pantalla no
     *   sabe nombrar ese estado.
     * @que-deberia-ocurrir Que pase al estado de fichero no encontrado la tarea pedida, y ninguna otra.
     * @por-que-ocurre La llamada que cambia el estado pasa sus dos argumentos al revés: el
     *   identificador como estado y el estado como identificador.
     * @como-deberia-funcionar Pasar primero el estado y después el identificador de la tarea.
     */
    public function test_T7_unaTareaSinFicheroPasaAFicheroNoEncontradoSinTocarOtra(): void
    {
        $otra = $this->tareas->tarea('Tarea número 3', 'TareaQueAnota', ['id' => 3]);
        $sinFichero = $this->tareas->tarea('Sin fichero', 'TareaQueNoExiste');

        $respuesta = $this->pedirEjecucion((string) $sinFichero);

        self::assertSame(['mensaje' => 'NO ejecutable --->./tareas/TareaQueNoExiste.php', 'error' => 1], $respuesta);
        self::assertSame(
            [$sinFichero => \MTareasCron::ESTADO_FICHERO_NO_ENCONTRADO, $otra => \MTareasCron::ESTADO_ACTIVO],
            [
                $sinFichero => (int) $this->tareas->filaDeTarea($sinFichero)['estado'],
                $otra => (int) $this->tareas->filaDeTarea($otra)['estado'],
            ],
            'Solo la tarea pedida pasa a fichero no encontrado.'
        );
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/tareas.php:18-21
     *
     * @group esperado
     * @group ejecucion
     * @group bajo
     *
     * @que-ocurre-hoy Si el fichero de la tarea no declara su clase, o la tarea falla con una
     *   excepción, el error sale de `tareas.php` sin recoger y la petición termina sin respuesta.
     * @que-deberia-ocurrir Que responda con un error que diga qué pasó.
     * @por-que-ocurre `tareas.php` crea la clase y la ejecuta sin comprobar que existe ni recoger lo que
     *   lance.
     * @como-deberia-funcionar Comprobar la clase antes de crearla, recoger lo que lance la tarea y
     *   responder con el error.
     */
    public function test_T8_siLaTareaNoCargaOFallaLaRespuestaLoDice(): void
    {
        $errores = [];
        foreach (['FicheroSinClase', 'TareaQueFalla'] as $clase) {
            $id = $this->tareas->tarea("Tarea $clase", $clase);
            try {
                $errores[$clase] = $this->pedirEjecucion((string) $id)['error'] ?? null;
            } catch (\Throwable $e) {
                $errores[$clase] = null;
            }
        }

        $sinRespuestaDeError = array_keys(array_filter($errores, static fn ($error): bool => $error === null || $error === 0));
        self::assertSame([], $sinRespuestaDeError, 'La petición tiene que responder con un error.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/tareas.php:15-18
     *
     * @group esperado
     * @group ejecucion
     * @group critico
     *
     * @que-ocurre-hoy Si el nombre de clase de una tarea es una ruta que sale de la carpeta de tareas,
     *   la ejecución manual incluye el fichero al que lleva y ejecuta su código. La pantalla de tareas
     *   admite ese nombre.
     * @que-deberia-ocurrir Que solo incluya ficheros de la carpeta de tareas.
     * @por-que-ocurre `tareas.php` forma la ruta pegando el nombre de clase guardado a la carpeta de
     *   tareas, y no comprueba adónde lleva.
     * @como-deberia-funcionar Rechazar un nombre de clase con barras o puntos antes de formar la ruta.
     */
    public function test_T9_noIncluyeUnFicheroDeFueraDeLaCarpetaDeTareas(): void
    {
        $id = $this->tareas->tarea('Clase con ruta', '../../../fuera-de-tareas/FicheroDeFuera');

        try {
            $this->pedirEjecucion((string) $id);
        } catch (\Error $e) {
            // Lo que decide es si el fichero se incluyó.
        }

        self::assertFalse(RegistroDeTareas::seIncluyo('FicheroDeFuera.php'), 'Un fichero de fuera de la carpeta de tareas no puede incluirse.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/tareas.php:14-20
     *
     * @group esperado
     * @group ejecucion
     * @group medio
     *
     * @que-ocurre-hoy La ejecución manual lanza una tarea aunque otra tarea de la misma clase esté en
     *   proceso: el mismo cálculo corre dos veces a la vez sobre los mismos datos.
     * @que-deberia-ocurrir Que no la lance mientras otra ejecución de su clase está en curso, y
     *   responda por qué.
     * @por-que-ocurre `tareas.php` no mira el estado de ninguna tarea antes de lanzar la pedida, y la
     *   ficha admite dos tareas con la misma clase.
     * @como-deberia-funcionar No admitir dos tareas con la misma clase, o no lanzar una tarea mientras
     *   otra de su clase está en proceso.
     */
    public function test_T10_noLanzaUnaTareaMientrasOtraDeSuMismaClaseEstaEnProceso(): void
    {
        $this->tareas->tarea('Primera, en proceso', 'TareaQueAnota', ['estado' => \MTareasCron::ESTADO_EN_PROCESO]);
        $segunda = $this->tareas->tarea('Segunda, activa', 'TareaQueAnota');

        $respuesta = $this->pedirEjecucion((string) $segunda);

        self::assertSame(0, RegistroDeTareas::vecesQueSeEjecuto($segunda), 'Con otra ejecución de su clase en curso, la tarea no puede lanzarse.');
        self::assertNotSame(0, $respuesta['error'] ?? null);
    }

    /**
     * Una petición de la pantalla de tareas para ejecutar una: `tareas.php` corre con la carpeta del
     * módulo de esa raíz como directorio de trabajo, como lo pone el servidor web. Devuelve la
     * respuesta decodificada, o null si no la hubo.
     *
     * @return array<string,mixed>|null
     */
    private function pedirEjecucion(string $identificador, ?string $raiz = null): ?array
    {
        // tareas.php incluye sus clases con $URLCom, como variable global.
        global $URLCom;

        $directorio = (string) getcwd();
        chdir(($raiz ?? RegistroDeTareas::raiz()) . '/modulos/mod_tareas_cron');
        $_POST = ['tareaid' => $identificador];
        ob_start();
        try {
            require RUTA_TPVFOX . '/modulos/mod_tareas_cron/tareas.php';
        } finally {
            $salida = (string) ob_get_clean();
            $_POST = [];
            chdir($directorio);
        }

        return json_decode(trim($salida), true);
    }

    /**
     * Lo que se escribió en el diario sobre esa tarea durante el caso.
     *
     * @return list<string>
     */
    private function diarioDe(int $idTarea): array
    {
        $entradas = [];
        foreach ($this->tareas->diarioDesde($this->diarioPrevio) as $entrada) {
            if ($entrada['tarea_id'] === $idTarea) {
                $entradas[] = $entrada['descripcion'];
            }
        }

        return $entradas;
    }
}

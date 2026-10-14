<?php
/**
 * El modelo de las tareas programadas: alta, lectura, modificación, borrado y estados de una
 * tarea, tal como los usan la pantalla de tareas y el planificador.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModTareasCron;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioTareasCron;

final class TareasModeloIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private EscenarioTareasCron $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_tareas_cron/clases/CTareasCron.php');
        $this->siembra = new EscenarioTareasCron($this->db);
    }

    public function test_T1_unaTareaGuardadaSinIdSeDaDeAltaConSusDatosYActiva(): void
    {
        [$id, $errores] = (new \CTareasCron())->guardar($this->formulario([
            'nombre' => 'Coste medio de las compras',
            'nombre_clase' => 'AcumuladoComprasTarea',
        ]));

        self::assertSame([], $errores);
        $fila = $this->siembra->filaDeTarea((int) $id);
        self::assertSame('Coste medio de las compras', $fila['nombre']);
        self::assertSame('AcumuladoComprasTarea', $fila['nombre_clase']);
        self::assertSame(\MTareasCron::ESTADO_ACTIVO, (int) $fila['estado']);
        self::assertNull($fila['ultima_ejecucion']);
    }

    public function test_T2_leerDevuelveLaTareaYUnaQueNoExisteDevuelveNulo(): void
    {
        $id = $this->siembra->tarea('Último coste de compra', 'UltimoPrecioCompraTarea');
        $tareas = new \MTareasCron();

        self::assertSame('Último coste de compra', $tareas->leer($id)['nombre']);
        self::assertNull($tareas->leer(999999));
    }

    public function test_T3_existeDiceSiHayUnaTareaConEseIdentificador(): void
    {
        $id = $this->siembra->tarea('Tarea que existe', 'AcumuladoComprasTarea');
        $tareas = new \MTareasCron();

        self::assertSame(1, (int) $tareas->existe($id));
        self::assertSame(0, (int) $tareas->existe(999999));
    }

    /**
     * @comportamiento El planificador solo mira las tareas activas: una de baja, una en
     *   proceso, una cuyo fichero no se encontró y una que falló quedan fuera.
     */
    public function test_T4_lasTareasActivasSonSoloLasDeEstadoActivo(): void
    {
        $activa = $this->siembra->tarea('Activa', 'AcumuladoComprasTarea');
        $fuera = [
            $this->siembra->tarea('De baja', 'AcumuladoComprasTarea', ['estado' => \MTareasCron::ESTADO_BAJA]),
            $this->siembra->tarea('En proceso', 'AcumuladoComprasTarea', ['estado' => \MTareasCron::ESTADO_EN_PROCESO]),
            $this->siembra->tarea('Sin fichero', 'AcumuladoComprasTarea', ['estado' => \MTareasCron::ESTADO_FICHERO_NO_ENCONTRADO]),
            $this->siembra->tarea('Con error', 'AcumuladoComprasTarea', ['estado' => \MTareasCron::ESTADO_ERROR_EN_PROCESO]),
        ];

        $activas = array_map('intval', array_column((new \MTareasCron())->getTareasActivas() ?? [], 'id'));

        self::assertContains($activa, $activas);
        foreach ($fuera as $id) {
            self::assertNotContains($id, $activas);
        }
    }

    public function test_T5_unaTareaGuardadaConIdSeModifica(): void
    {
        $id = $this->siembra->tarea('Coste medio', 'AcumuladoComprasTarea', ['cantidad' => 60]);

        [, $errores] = (new \CTareasCron())->guardar($this->formulario([
            'id' => (string) $id,
            'nombre' => 'Coste medio de las compras',
            'nombre_clase' => 'AcumuladoComprasTarea',
            'cantidad_periodo' => '120',
        ]));

        self::assertSame([], $errores);
        $fila = $this->siembra->filaDeTarea($id);
        self::assertSame('Coste medio de las compras', $fila['nombre']);
        self::assertSame(120, (int) $fila['cantidad_periodo']);
    }

    public function test_T6_anotarLaEjecucionPoneLaHoraDeAhoraYDejaLaTareaActiva(): void
    {
        $id = $this->siembra->tarea('En proceso', 'AcumuladoComprasTarea', ['estado' => \MTareasCron::ESTADO_EN_PROCESO]);

        (new \MTareasCron())->updateFechaEjecucion($id);

        $fila = $this->siembra->filaDeTarea($id);
        self::assertSame(\MTareasCron::ESTADO_ACTIVO, (int) $fila['estado']);
        self::assertLessThan(60, abs(strtotime($fila['ultima_ejecucion']) - time()));
    }

    public function test_T7_cambiarElEstadoAlcanzaSoloALaTareaIndicada(): void
    {
        $id = $this->siembra->tarea('Para dar de baja', 'AcumuladoComprasTarea');
        $otra = $this->siembra->tarea('Sigue activa', 'AcumuladoComprasTarea');

        (new \MTareasCron())->updateEstado(\MTareasCron::ESTADO_BAJA, $id);

        self::assertSame(\MTareasCron::ESTADO_BAJA, (int) $this->siembra->filaDeTarea($id)['estado']);
        self::assertSame(\MTareasCron::ESTADO_ACTIVO, (int) $this->siembra->filaDeTarea($otra)['estado']);
    }

    /**
     * @comportamiento El formulario se rechaza sin nombre, sin clase o con un periodo que no es
     *   un número, y en ninguno de los tres casos se guarda nada.
     */
    public function test_T8_sinNombreSinClaseOConUnPeriodoQueNoEsNumeroNoSeGuarda(): void
    {
        $casos = [
            'nombre.empty' => ['nombre' => '', 'nombre_clase' => 'AcumuladoComprasTarea'],
            'nombre_clase.empty' => ['nombre' => 'Tarea sin clase', 'nombre_clase' => ''],
            'periodo' => ['nombre' => 'Tarea de periodo raro', 'nombre_clase' => 'AcumuladoComprasTarea', 'cantidad_periodo' => 'cada hora'],
        ];

        foreach ($casos as $clave => $campos) {
            [$tarea, $errores] = (new \CTareasCron())->guardar($this->formulario($campos));

            self::assertArrayHasKey($clave, $errores, $clave);
            self::assertSame([], $tarea, $clave);
        }
        self::assertSame(0, $this->siembra->cuantasSeLlaman('Tarea sin clase'));
        self::assertSame(0, $this->siembra->cuantasSeLlaman('Tarea de periodo raro'));
    }

    /**
     * @group control
     * @group historial
     *
     * @para-que-sirve Demuestra que borrar una tarea funciona cuando no tiene ejecuciones en el
     *   diario: lo que impide borrar las demás es su historial, no el borrado.
     */
    public function test_T9_unaTareaSinEjecucionesSeBorra(): void
    {
        $id = $this->siembra->tarea('Para borrar', 'AcumuladoComprasTarea');

        [$errores] = (new \CTareasCron())->eliminar($id);

        self::assertSame([], $errores);
        self::assertNull($this->siembra->filaDeTarea($id));
    }

    public function test_T10_buscarUnaTareaQueNoExisteDevuelveLosValoresPorDefecto(): void
    {
        $tarea = (new \MTareasCron())->find(999999);

        self::assertNull($tarea['id']);
        self::assertSame('', $tarea['nombre']);
        self::assertSame(\MTareasCron::PERIODO_MINUTOS, $tarea['tipo_periodo']);
        self::assertSame(\MTareasCron::ESTADO_ACTIVO, $tarea['estado']);
    }

    /**
     * @group control
     * @group estados
     *
     * @para-que-sirve Demuestra que los cuatro primeros estados y los cuatro tipos de periodo
     *   tienen su texto: al de error es al único que le falta.
     */
    public function test_T11_losEstadosYLosPeriodosTienenSuTexto(): void
    {
        $tareas = new \MTareasCron();

        self::assertSame(
            ['Baja', 'activo', 'En proceso', 'Fichero no encontrado'],
            [$tareas->textoEstado(0), $tareas->textoEstado(1), $tareas->textoEstado(2), $tareas->textoEstado(3)]
        );
        self::assertSame(
            ['Minutos', 'Horas', 'Días', 'Meses'],
            [$tareas->textoPeriodo(1), $tareas->textoPeriodo(2), $tareas->textoPeriodo(3), $tareas->textoPeriodo(4)]
        );
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/CTareasCron.php:61-67
     *
     * @group esperado
     * @group validacion
     * @group medio
     *
     * @que-ocurre-hoy Un nombre de más de cincuenta caracteres pasa la validación, y es la
     *   base la que lo rechaza al guardar, con una excepción en lugar de un aviso.
     * @que-deberia-ocurrir Que la validación lo rechace con su aviso, como hace con un nombre
     *   vacío.
     * @por-que-ocurre La comprobación de longitud mide el literal 'nombre', no el valor
     *   recibido, así que nunca se cumple; lo mismo pasa con 'nombre_clase'.
     * @como-deberia-funcionar Medir el valor de cada campo.
     */
    public function test_T12_unNombreDeMasDeCincuentaCaracteresSeRechazaAlValidar(): void
    {
        $errores = [];
        try {
            [, $errores] = (new \CTareasCron())->guardar($this->formulario([
                'nombre' => str_repeat('n', 60),
                'nombre_clase' => 'AcumuladoComprasTarea',
            ]));
        } catch (\mysqli_sql_exception $e) {
            // Lo que decide es si la validación avisó.
        }

        self::assertArrayHasKey('nombre.max', $errores, 'La validación tiene que rechazar el nombre largo.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/CTareasCron.php:50-52
     *
     * @group esperado
     * @group validacion
     * @group critico
     *
     * @que-ocurre-hoy Se guarda una tarea cuya clase es una ruta que sale de la carpeta de
     *   tareas. El planificador y la ejecución manual incluyen después el fichero que esa ruta
     *   señale.
     * @que-deberia-ocurrir Que solo se admita como clase una de las tareas del propio módulo.
     * @por-que-ocurre La única comprobación sobre la clase es que no esté vacía.
     * @como-deberia-funcionar Admitir solo nombres de clase, sin barras ni puntos, que
     *   correspondan a un fichero de la carpeta de tareas.
     */
    public function test_T13_unaClaseQueEsUnaRutaNoSeAdmite(): void
    {
        [, $errores] = (new \CTareasCron())->guardar($this->formulario([
            'nombre' => 'Tarea con ruta',
            'nombre_clase' => '../../../tmp/otra',
        ]));

        self::assertNotSame([], $errores, 'Una clase que sale de la carpeta de tareas tiene que rechazarse.');
        self::assertSame(0, $this->siembra->cuantasSeLlaman('Tarea con ruta'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/MTareasCron.php:140-141
     *
     * @group esperado
     * @group historial
     * @group alto
     *
     * @que-ocurre-hoy Borrar una tarea que ya se ejecutó alguna vez falla con una excepción,
     *   porque el diario la referencia, y quien lo pidió no recibe el motivo.
     * @que-deberia-ocurrir Que el borrado devuelva por qué no se puede hacer, y la tarea quede
     *   como estaba.
     * @por-que-ocurre Cada ejecución deja filas en el diario atadas a la tarea por una clave
     *   ajena, y el borrado ni lo comprueba antes ni recoge el fallo.
     * @como-deberia-funcionar Comprobar el diario antes de borrar y devolver el aviso, o dar la
     *   tarea de baja en vez de borrarla.
     */
    public function test_T14_borrarUnaTareaConEjecucionesDiceElMotivoYNoLaToca(): void
    {
        $id = $this->siembra->tarea('Con ejecuciones', 'AcumuladoComprasTarea');
        $this->siembra->entradaDeDiario($id);

        $errores = null;
        try {
            [$errores] = (new \CTareasCron())->eliminar($id);
        } catch (\mysqli_sql_exception $e) {
            // Lo que decide es si el borrado devolvió el motivo.
        }

        self::assertIsArray($errores, 'El borrado tiene que devolver el motivo, no lanzar una excepción.');
        self::assertNotSame([], $errores);
        self::assertNotNull($this->siembra->filaDeTarea($id));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/CTareasCron.php:41
     *
     * @group esperado
     * @group guardado
     * @group bajo
     *
     * @que-ocurre-hoy Preparar una tarea nueva lee una propiedad que no existe: avisa y deja la
     *   tarea en curso sin sus valores por defecto. Ninguna pantalla lo llama hoy.
     * @que-deberia-ocurrir Que la tarea en curso quede con los valores por defecto.
     * @por-que-ocurre Falta el paréntesis de la llamada, así que se lee `initTareaCron` como
     *   si fuera una propiedad.
     * @como-deberia-funcionar Llamar al método.
     */
    public function test_T15_prepararUnaTareaNuevaLaDejaConSusValoresPorDefecto(): void
    {
        $control = new \CTareasCron();

        $control->crear();

        self::assertIsArray($control->tareaCron);
        self::assertSame('', $control->tareaCron['nombre']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/MTareasCron.php:38
     *
     * @group esperado
     * @group estados
     * @group medio
     *
     * @que-ocurre-hoy El estado de error, el que pone la tarea del último coste cuando su
     *   lectura no devuelve líneas, no tiene texto: pedirlo avisa de una clave inexistente, y
     *   la pantalla de tareas no puede decir en qué estado está esa tarea.
     * @que-deberia-ocurrir Que el estado de error tenga su texto, como los demás.
     * @por-que-ocurre La lista de textos tiene cuatro estados y las constantes, cinco.
     * @como-deberia-funcionar Añadir el texto del quinto estado.
     */
    public function test_T16_elEstadoDeErrorTieneTexto(): void
    {
        self::assertNotEmpty((new \MTareasCron())->textoEstado(\MTareasCron::ESTADO_ERROR_EN_PROCESO));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/MTareasCron.php:65
     *
     * @group esperado
     * @group guardado
     * @group bajo
     *
     * @que-ocurre-hoy Dar de alta una tarea sin fecha de inicio falla: el valor por defecto
     *   lleva día, mes, año y hora, y la base no lo admite como fecha.
     * @que-deberia-ocurrir Que la tarea se dé de alta con la fecha de hoy.
     * @por-que-ocurre El valor por defecto se forma con el formato de pantalla, no con el de la
     *   base. El formulario siempre envía la fecha, de modo que solo lo alcanza un alta hecha
     *   desde código.
     * @como-deberia-funcionar Formar el valor por defecto con el formato de fecha de la base.
     */
    public function test_T17_unaTareaSinFechaDeInicioSeDaDeAltaConLaDeHoy(): void
    {
        $datos = $this->formulario(['nombre' => 'Sin fecha de inicio', 'nombre_clase' => 'AcumuladoComprasTarea']);
        unset($datos['inicio_ejecucion']);

        $id = null;
        try {
            [$id] = (new \CTareasCron())->guardar($datos);
        } catch (\mysqli_sql_exception $e) {
            // Lo que decide es si la tarea quedó dada de alta.
        }

        self::assertNotEmpty($id, 'La tarea tiene que darse de alta.');
        self::assertSame(date('Y-m-d'), $this->siembra->filaDeTarea((int) $id)['inicio_ejecucion']);
    }

    /**
     * @comportamiento El listado de la pantalla trae todas las tareas, estén en el estado que
     *   estén.
     */
    public function test_T18_elListadoTraeTodasLasTareasSeaCualSeaSuEstado(): void
    {
        $activa = $this->siembra->tarea('Activa del listado', 'AcumuladoComprasTarea');
        $deBaja = $this->siembra->tarea('De baja del listado', 'AcumuladoComprasTarea', ['estado' => \MTareasCron::ESTADO_BAJA]);

        $ids = array_map('intval', array_column((new \CTareasCron())->list() ?? [], 'id'));

        self::assertContains($activa, $ids);
        self::assertContains($deBaja, $ids);
    }

    public function test_T19_editarCargaEnLaTareaEnCursoLosDatosGuardados(): void
    {
        $id = $this->siembra->tarea('Para editar', 'UltimoPrecioCompraTarea', ['cantidad' => 2, 'tipo' => \MTareasCron::PERIODO_HORAS]);
        $control = new \CTareasCron();

        $control->edit($id);

        self::assertSame('Para editar', $control->tareaCron['nombre']);
        self::assertSame('UltimoPrecioCompraTarea', $control->tareaCron['nombre_clase']);
        self::assertSame(2, (int) $control->tareaCron['cantidad_periodo']);
        self::assertSame(\MTareasCron::PERIODO_HORAS, (int) $control->tareaCron['tipo_periodo']);
    }

    public function test_T20_guardarConUnIdQueNoExisteAvisaYNoDaDeAltaOtra(): void
    {
        [$tarea, $errores] = (new \CTareasCron())->guardar($this->formulario([
            'id' => '999999',
            'nombre' => 'Tarea con id inventado',
        ]));

        self::assertArrayHasKey('id.notfound', $errores);
        self::assertSame([], $tarea);
        self::assertSame(0, $this->siembra->cuantasSeLlaman('Tarea con id inventado'));
    }

    public function test_T21_borrarUnaTareaQueNoExisteAvisa(): void
    {
        [$errores] = (new \CTareasCron())->eliminar(999999);

        self::assertArrayHasKey('id.notfound', $errores);
    }

    /**
     * Los campos que envía el formulario de la pantalla de tareas, con los cambios del caso.
     *
     * @param array<string,string> $campos
     * @return array<string,string>
     */
    private function formulario(array $campos): array
    {
        return $campos + [
            'id' => '',
            'nombre' => 'Tarea de prueba',
            'cantidad_periodo' => '60',
            'tipo_periodo' => '1',
            'inicio_ejecucion' => date('Y-m-d'),
            'nombre_clase' => 'AcumuladoComprasTarea',
            'estado' => '1',
        ];
    }
}

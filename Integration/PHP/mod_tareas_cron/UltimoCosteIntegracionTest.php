<?php
/**
 * La tarea del último coste: qué compra toma de cada artículo, qué escribe en su ficha y cómo
 * queda la tarea al terminar.
 *
 * La tarea se ejecuta como la lanza el planificador, con el identificador de su fila y con
 * `inicial.php` ya cargado.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModTareasCron;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\PrecargaInicial;
use TPVFox\Test\Siembra\EscenarioTareasCron;
use TPVFox\Test\Siembra\Siembra;

final class UltimoCosteIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $compras;

    private EscenarioTareasCron $tareas;

    private int $idTarea;

    private string $ficheroDeAvisos;

    private string $ficheroDeAvisosPrevio;

    protected function setUp(): void
    {
        parent::setUp();
        PrecargaInicial::hacer();
        $this->incluirTPVFox('/modulos/mod_tareas_cron/tareas/UltimoPrecioCompraTarea.php');
        $this->compras = new Siembra($this->db);
        $this->tareas = new EscenarioTareasCron($this->db);
        $this->idTarea = $this->tareas->tarea('Último coste', 'UltimoPrecioCompraTarea');

        // Sin compras, la tarea escribe en el registro de errores de PHP: va a un fichero del caso
        // y no ensucia la consola.
        $this->ficheroDeAvisos = (string) tempnam(sys_get_temp_dir(), 'ultimo-coste-');
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

    /**
     * @comportamiento El último coste es el de la compra con la fecha de albarán más reciente, aunque
     *   se registrara antes que otra de fecha anterior.
     */
    public function test_T1_tomaElCosteDeLaCompraConLaFechaDeAlbaranMasReciente(): void
    {
        $ano = $this->ano();
        $articulo = $this->compras->articulo('Comprado en febrero y después en enero');
        $this->compras->entradaProveedor($articulo, 10, "$ano-02-10", ['coste' => 3.0]);
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-10", ['coste' => 2.0]);

        $this->ejecutar();

        self::assertEqualsWithDelta(3.0, $this->tareas->ultimoCoste($articulo), 0.0001);
    }

    public function test_T2_escribeElUltimoCosteDeCadaArticuloConComprasTambienElDelUltimo(): void
    {
        $ano = $this->ano();
        $primero = $this->compras->articulo('Primero con compras');
        $ultimo = $this->compras->articulo('Último con compras');
        $this->compras->entradaProveedor($primero, 10, "$ano-01-10", ['coste' => 2.0]);
        $this->compras->entradaProveedor($ultimo, 10, "$ano-01-10", ['coste' => 4.0]);

        $this->ejecutar();

        self::assertEqualsWithDelta(2.0, $this->tareas->ultimoCoste($primero), 0.0001);
        self::assertEqualsWithDelta(4.0, $this->tareas->ultimoCoste($ultimo), 0.0001);
    }

    /**
     * @comportamiento La compra más reciente cuenta sea cual sea el estado de su albarán —en
     *   edición, facturado o importado— y sea cual sea su proveedor.
     */
    public function test_T3_cuentaLaCompraMasRecienteSeaCualSeaSuEstadoYSuProveedor(): void
    {
        $ano = $this->ano();
        $otroProveedor = $this->compras->proveedor('Otro proveedor');
        $esperados = [];
        foreach (['Sin Guardar' => 3.0, 'Facturado' => 4.0, 'Importado' => 5.0] as $estado => $coste) {
            $articulo = $this->compras->articulo("Última compra en estado $estado");
            $this->compras->entradaProveedor($articulo, 10, "$ano-01-10", ['coste' => 2.0]);
            $this->compras->entradaProveedor($articulo, 10, "$ano-02-10", [
                'coste' => $coste,
                'estado' => $estado,
                'idProveedor' => $otroProveedor,
            ]);
            $esperados[$articulo] = $coste;
        }

        $this->ejecutar();

        foreach ($esperados as $articulo => $coste) {
            self::assertEqualsWithDelta($coste, $this->tareas->ultimoCoste($articulo), 0.0001);
        }
    }

    /**
     * @group control
     * @group ejecucion
     *
     * @para-que-sirve Demuestra que, con compras, la tarea anota su ejecución y sigue activa: lo que
     *   la pasa al estado de error es no encontrar ninguna compra.
     */
    public function test_T4_conComprasLaTareaAnotaSuEjecucionYSigueActiva(): void
    {
        $articulo = $this->compras->articulo('Comprado una vez');
        $this->compras->entradaProveedor($articulo, 10, $this->ano() . '-01-10', ['coste' => 2.0]);

        $this->ejecutar();

        $tarea = $this->tareas->filaDeTarea($this->idTarea);
        self::assertSame(\MTareasCron::ESTADO_ACTIVO, (int) $tarea['estado']);
        self::assertLessThan(60, abs(strtotime((string) $tarea['ultima_ejecucion']) - time()));
    }

    /**
     * @comportamiento En la ficha del artículo, la tarea solo escribe el último coste. El coste medio
     *   y el resto de la ficha, sus líneas de compra, sus acumulados y los artículos sin compras
     *   quedan como estaban.
     */
    public function test_T5_soloCambiaElUltimoCosteDeLosArticulosConCompras(): void
    {
        $comprado = $this->compras->articulo('Comprado', ['ultimoCoste' => 1.0, 'iva' => 10.0, 'beneficio' => 30.0]);
        $sinCompras = $this->compras->articulo('Sin compras');
        $this->compras->entradaProveedor($comprado, 10, $this->ano() . '-01-10', ['coste' => 2.0]);
        $fichaAntes = $this->fichaDe($comprado);
        $sinComprasAntes = $this->fichaDe($sinCompras);
        $lineasAntes = $this->lineasDe($comprado);

        $this->ejecutar();

        $fichaDespues = $this->fichaDe($comprado);
        self::assertEqualsWithDelta(2.0, (float) $fichaDespues['ultimoCoste'], 0.0001);
        unset($fichaAntes['ultimoCoste'], $fichaDespues['ultimoCoste']);
        self::assertSame($fichaAntes, $fichaDespues);
        self::assertSame($sinComprasAntes, $this->fichaDe($sinCompras));
        self::assertSame($lineasAntes, $this->lineasDe($comprado));
        self::assertSame([], $this->tareas->acumuladoDe($comprado));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/MUltimoPrecioCompra.php:17
     *
     * @group esperado
     * @group coste
     * @group medio
     *
     * @que-ocurre-hoy Cuando varias compras del artículo comparten la fecha más reciente, la consulta
     *   no decide cuál tomar. Con la conexión de la suite, el mismo escenario repetido en diez
     *   sesiones toma unas veces la compra registrada primero y otras la última.
     * @que-deberia-ocurrir Que tome siempre la misma: la registrada la última.
     * @por-que-ocurre La consulta ordena las compras de cada artículo solo por la fecha del albarán,
     *   que se introduce sin hora, y no desempata.
     * @como-deberia-funcionar Desempatar por el identificador de la línea, de mayor a menor.
     */
    public function test_T6_entreComprasDelMismoDiaTomaSiempreLaRegistradaLaUltima(): void
    {
        // Lo que toma un empate cambia de una sesión de base de datos a otra, no dentro de la misma:
        // el escenario se repite en diez sesiones.
        $costes = [];
        for ($vez = 1; $vez <= 10; $vez++) {
            $costes[] = $this->enOtraSesion(function (\mysqli $sesion): float {
                $compras = new Siembra($sesion);
                $tareas = new EscenarioTareasCron($sesion);
                $dia = $this->ano() . '-05-05';
                $articulo = $compras->articulo('Comprado dos veces el mismo día');
                $compras->entradaProveedor($articulo, 10, $dia, ['coste' => 2.0]);
                $compras->entradaProveedor($articulo, 10, $dia, ['coste' => 3.0]);

                (new \UltimoPrecioCompraTarea($tareas->tarea('Último coste', 'UltimoPrecioCompraTarea')))->execute();

                return $tareas->ultimoCoste($articulo);
            });
        }

        self::assertSame(array_fill(0, 10, 3.0), $costes, 'Entre compras del mismo día manda siempre la registrada la última.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/clases/MUltimoPrecioCompra.php:15-21
     *
     * @group esperado
     * @group coste
     * @group medio
     *
     * @que-ocurre-hoy Una devolución al proveedor posterior a la última compra pasa a ser el último
     *   coste del artículo, con el coste que lleve la devolución.
     * @que-deberia-ocurrir Que el último coste sea el de la última compra: una devolución no es una
     *   compra.
     * @por-que-ocurre La devolución es una línea de cantidad negativa en un albarán de compra, y la
     *   consulta toma la línea más reciente sin mirar su cantidad.
     * @como-deberia-funcionar Dejar fuera las líneas de cantidad negativa.
     */
    public function test_T7_unaDevolucionNoPasaASerElUltimoCoste(): void
    {
        $ano = $this->ano();
        $articulo = $this->compras->articulo('Comprado dos veces y devuelto');
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-10", ['coste' => 2.0]);
        $this->compras->entradaProveedor($articulo, 10, "$ano-02-10", ['coste' => 3.0]);
        $this->compras->devolucionProveedor($articulo, 5, "$ano-03-10", ['coste' => 2.0]);

        $this->ejecutar();

        self::assertEqualsWithDelta(3.0, $this->tareas->ultimoCoste($articulo), 0.0001, 'El último coste es el de la última compra, no el de la devolución.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/tareas/UltimoPrecioCompraTarea.php:40-50
     *
     * @group esperado
     * @group ejecucion
     * @group alto
     *
     * @que-ocurre-hoy Sin ninguna línea de compra en la base, la tarea avisa de una clave que no
     *   existe, no anota su ejecución y pasa al estado de error, que la pantalla de tareas no sabe
     *   nombrar. El planificador solo lanza las tareas activas, así que ya no vuelve a lanzarla,
     *   aunque después entren compras.
     * @que-deberia-ocurrir Que la tarea termine sin nada que escribir, anote su ejecución y siga
     *   activa.
     * @por-que-ocurre Una lectura sin filas devuelve nulo y sin error, y la tarea la trata como una
     *   consulta fallida: anota en el registro de errores un error que no existe y pasa la tarea a
     *   error.
     * @como-deberia-funcionar Distinguir la lectura vacía, que no es un fallo, de la consulta que
     *   falla.
     */
    public function test_T8_sinComprasLaTareaAnotaSuEjecucionYSigueActiva(): void
    {
        // Una instalación sin compras: se quitan, dentro de la transacción del caso, las líneas que
        // trae la base.
        $this->db->query('DELETE FROM albprolinea');

        $avisos = $this->ejecutarSinDetenerseEnAvisos();

        $tarea = $this->tareas->filaDeTarea($this->idTarea);
        self::assertSame(\MTareasCron::ESTADO_ACTIVO, (int) $tarea['estado'], 'La tarea tiene que seguir activa.');
        self::assertNotNull($tarea['ultima_ejecucion'], 'La tarea tiene que anotar su ejecución.');
        self::assertSame([], $avisos);
    }

    /** Una ejecución de la tarea, como la lanza el planificador. */
    private function ejecutar(): void
    {
        (new \UltimoPrecioCompraTarea($this->idTarea))->execute();
    }

    /**
     * Una ejecución de la tarea en la que un aviso de PHP no la detiene, como en producción: los
     * avisos se devuelven, y cualquier otro error llega a PHPUnit.
     *
     * @return list<string>
     */
    private function ejecutarSinDetenerseEnAvisos(): array
    {
        $avisos = [];
        $anterior = null;
        $anterior = set_error_handler(
            static function (int $nivel, string $mensaje, string $fichero = '', int $linea = 0) use (&$avisos, &$anterior): bool {
                if ($nivel === E_WARNING) {
                    $avisos[] = $mensaje;

                    return true;
                }

                return $anterior !== null ? (bool) $anterior($nivel, $mensaje, $fichero, $linea) : false;
            }
        );
        try {
            $this->ejecutar();
        } finally {
            restore_error_handler();
        }

        return $avisos;
    }

    /**
     * Ejecuta el paso en una sesión de base de datos nueva, con su propia transacción, que se deshace
     * al acabar. Mientras tanto, el producto usa esa sesión.
     *
     * @template T
     * @param callable(\mysqli): T $paso
     * @return T
     */
    private function enOtraSesion(callable $paso): mixed
    {
        $sesion = self::conectar($this->ejercicio);
        $sesion->begin_transaction();
        $anterior = \ModeloP::usarConexion($sesion);
        try {
            return $paso($sesion);
        } finally {
            \ModeloP::usarConexion($anterior);
            $sesion->rollback();
            $sesion->close();
        }
    }

    /** La ficha del artículo, tal como está en la base. */
    private function fichaDe(int $idArticulo): array
    {
        return $this->db->query('SELECT * FROM articulos WHERE idArticulo = ' . $idArticulo)->fetch_assoc();
    }

    /** Las líneas de compra del artículo, tal como están en la base. */
    private function lineasDe(int $idArticulo): array
    {
        return $this->db->query('SELECT * FROM albprolinea WHERE idArticulo = ' . $idArticulo . ' ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    }
}

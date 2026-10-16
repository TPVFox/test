<?php
/**
 * La tarea del coste medio: qué guarda por artículo y mes, qué coste medio escribe en el artículo,
 * con qué compras lo calcula y cómo queda la tarea al terminar.
 *
 * La tarea se ejecuta como la lanza el planificador, con el identificador de su fila y con
 * `inicial.php` ya cargado: guardar el acumulado usa una de las funciones de ayuda que carga.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModTareasCron;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\PrecargaInicial;
use TPVFox\Test\Siembra\EscenarioTareasCron;
use TPVFox\Test\Siembra\Siembra;

final class CosteMedioIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $compras;

    private EscenarioTareasCron $tareas;

    private int $idTarea;

    protected function setUp(): void
    {
        parent::setUp();
        PrecargaInicial::hacer();
        $this->incluirTPVFox('/modulos/mod_tareas_cron/tareas/AcumuladoComprasTarea.php');
        $this->compras = new Siembra($this->db);
        $this->tareas = new EscenarioTareasCron($this->db);
        $this->idTarea = $this->tareas->tarea('Coste medio', 'AcumuladoComprasTarea');
    }

    /**
     * @comportamiento Por artículo y mes, la tarea guarda la cantidad comprada y el coste de esas
     *   compras ponderado por la cantidad.
     */
    public function test_T1_porArticuloYMesGuardaLaCantidadYSuCostePonderado(): void
    {
        $ano = $this->ano();
        $articulo = $this->compras->articulo('Comprado dos veces en enero');
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-10", ['coste' => 2.0]);
        $this->compras->entradaProveedor($articulo, 30, "$ano-01-20", ['coste' => 3.0]);

        $this->ejecutar();

        // (10 × 2 + 30 × 3) / 40
        self::assertEquals(
            [['year' => (int) $ano, 'month' => 1, 'cantidad' => 40.0, 'costemedio' => 2.75]],
            $this->tareas->acumuladoDe($articulo)
        );
    }

    /**
     * @group control
     * @group coste
     *
     * @para-que-sirve Demuestra que el coste medio de un artículo que no es el último del recorrido
     *   se calcula y se escribe: la media de todas sus compras, de todos los meses, ponderada por la
     *   cantidad. Con el último falla la escritura, no el cálculo.
     */
    public function test_T2_elCosteMedioEsLaMediaDeTodasSusComprasPonderadaPorLaCantidad(): void
    {
        $ano = $this->ano();
        $articulo = $this->compras->articulo('Comprado en tres meses');
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-10", ['coste' => 2.0]);
        $this->compras->entradaProveedor($articulo, 30, "$ano-02-10", ['coste' => 3.0]);
        $this->compras->entradaProveedor($articulo, 60, "$ano-03-10", ['coste' => 4.0]);
        $this->articuloDeCierre();

        $this->ejecutar();

        // (10 × 2 + 30 × 3 + 60 × 4) / 100
        self::assertEqualsWithDelta(3.5, $this->tareas->costeMedio($articulo), 0.000001);
    }

    /**
     * @comportamiento La tarea pondera por la cantidad de la línea (`ncant`), no por sus unidades
     *   (`nunidades`): dos líneas de diez pesan lo mismo aunque traigan una y nueve unidades.
     */
    public function test_T3_ponderaPorLaCantidadDeLaLineaNoPorSusUnidades(): void
    {
        $ano = $this->ano();
        $articulo = $this->compras->articulo('Cantidad y unidades distintas');
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-10", ['coste' => 2.0, 'nunidades' => 1]);
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-20", ['coste' => 4.0, 'nunidades' => 9]);
        $this->articuloDeCierre();

        $this->ejecutar();

        // Por cantidad, (10 × 2 + 10 × 4) / 20; por unidades sería (1 × 2 + 9 × 4) / 10 = 3,8.
        self::assertEqualsWithDelta(3.0, $this->tareas->costeMedio($articulo), 0.000001);
        self::assertEqualsWithDelta(20.0, $this->tareas->acumuladoDe($articulo)[0]['cantidad'], 0.000001);
    }

    /**
     * @comportamiento Cuentan las líneas de todos los albaranes de compra, estén en el estado que
     *   estén —guardado, en edición, facturado o importado— y sean del proveedor que sean.
     */
    public function test_T4_cuentanLosAlbaranesDeCualquierEstadoYProveedor(): void
    {
        $ano = $this->ano();
        $otroProveedor = $this->compras->proveedor('Otro proveedor');
        $articulo = $this->compras->articulo('Comprado en cuatro albaranes');
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-05", ['coste' => 2.0, 'estado' => 'Guardado']);
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-10", ['coste' => 2.0, 'estado' => 'Sin Guardar']);
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-15", ['coste' => 2.0, 'estado' => 'Facturado', 'idProveedor' => $otroProveedor]);
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-20", ['coste' => 2.0, 'estado' => 'Importado']);

        $this->ejecutar();

        self::assertEqualsWithDelta(40.0, $this->tareas->acumuladoDe($articulo)[0]['cantidad'], 0.000001);
    }

    /**
     * @comportamiento Una devolución al proveedor entra como una compra en negativo: resta su
     *   cantidad y su coste.
     */
    public function test_T5_unaDevolucionRestaSuCantidadYSuCoste(): void
    {
        $ano = $this->ano();
        $articulo = $this->compras->articulo('Comprado y devuelto en parte');
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-10", ['coste' => 2.0]);
        $this->compras->entradaProveedor($articulo, 30, "$ano-02-10", ['coste' => 3.0]);
        $this->compras->devolucionProveedor($articulo, 10, "$ano-03-10", ['coste' => 3.0]);
        $this->articuloDeCierre();

        $this->ejecutar();

        // (10 × 2 + 30 × 3 − 10 × 3) / 30
        self::assertEqualsWithDelta(80 / 30, $this->tareas->costeMedio($articulo), 0.000001);
    }

    /**
     * @comportamiento Si las devoluciones igualan a las compras, la cantidad total es cero y la tarea
     *   no toca el coste medio del artículo: conserva el que tenía.
     */
    public function test_T6_siSeDevuelveTodoLoCompradoElCosteMedioNoCambia(): void
    {
        $ano = $this->ano();
        $articulo = $this->compras->articulo('Devuelto entero', ['ultimoCoste' => 1.5]);
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-10", ['coste' => 2.0]);
        $this->compras->devolucionProveedor($articulo, 10, "$ano-02-10", ['coste' => 2.0]);
        $this->articuloDeCierre();

        $this->ejecutar();

        self::assertEqualsWithDelta(1.5, $this->tareas->costeMedio($articulo), 0.000001);
    }

    public function test_T7_volverAEjecutarlaActualizaElMesSinDuplicarlo(): void
    {
        $ano = $this->ano();
        $articulo = $this->compras->articulo('Comprado entre dos ejecuciones');
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-10", ['coste' => 2.0]);
        $this->ejecutar();
        $this->compras->entradaProveedor($articulo, 30, "$ano-01-20", ['coste' => 3.0]);

        $this->ejecutar();

        self::assertEquals(
            [['year' => (int) $ano, 'month' => 1, 'cantidad' => 40.0, 'costemedio' => 2.75]],
            $this->tareas->acumuladoDe($articulo)
        );
    }

    /**
     * @comportamiento En la ficha del artículo, la tarea solo escribe el coste medio: el resto de la
     *   ficha, sus líneas de compra y los artículos sin compras quedan como estaban.
     */
    public function test_T8_soloCambiaElCosteMedioDeLosArticulosConCompras(): void
    {
        $comprado = $this->compras->articulo('Comprado', ['ultimoCoste' => 1.0, 'iva' => 10.0, 'beneficio' => 30.0]);
        $sinCompras = $this->compras->articulo('Sin compras');
        $this->compras->entradaProveedor($comprado, 10, $this->ano() . '-01-10', ['coste' => 2.0]);
        $this->articuloDeCierre();
        $fichaAntes = $this->fichaDe($comprado);
        $sinComprasAntes = $this->fichaDe($sinCompras);
        $lineasAntes = $this->lineasDe($comprado);

        $this->ejecutar();

        $fichaDespues = $this->fichaDe($comprado);
        self::assertEqualsWithDelta(2.0, (float) $fichaDespues['costepromedio'], 0.000001);
        unset($fichaAntes['costepromedio'], $fichaDespues['costepromedio']);
        self::assertSame($fichaAntes, $fichaDespues);
        self::assertSame($sinComprasAntes, $this->fichaDe($sinCompras));
        self::assertSame($lineasAntes, $this->lineasDe($comprado));
    }

    /**
     * @group control
     * @group ejecucion
     *
     * @para-que-sirve Demuestra que, con compras, la tarea termina, anota su ejecución y vuelve a
     *   activa: lo que la deja en proceso es no encontrar ninguna compra.
     */
    public function test_T9_conComprasLaTareaAnotaSuEjecucionYQuedaActiva(): void
    {
        $articulo = $this->compras->articulo('Comprado una vez');
        $this->compras->entradaProveedor($articulo, 10, $this->ano() . '-01-10', ['coste' => 2.0]);

        $this->ejecutar();

        $tarea = $this->tareas->filaDeTarea($this->idTarea);
        self::assertSame(\MTareasCron::ESTADO_ACTIVO, (int) $tarea['estado']);
        self::assertLessThan(60, abs(strtotime((string) $tarea['ultima_ejecucion']) - time()));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/tareas/AcumuladoComprasTarea.php:55-71
     *
     * @group esperado
     * @group coste
     * @group medio
     *
     * @que-ocurre-hoy La tarea nunca escribe el coste medio del último artículo que recorre, el de
     *   identificador más alto entre los que tienen compras, que es el más reciente de ellos: se
     *   queda con el que tuviera.
     * @que-deberia-ocurrir Que escriba el coste medio de todos los artículos con compras.
     * @por-que-ocurre La tarea escribe el coste de un artículo al pasar al siguiente, y al acabar el
     *   recorrido no escribe el del último.
     * @como-deberia-funcionar Escribir también el coste del último artículo al acabar el recorrido.
     */
    public function test_T10_elCosteMedioDelUltimoArticuloDelRecorridoSeEscribe(): void
    {
        $articulo = $this->compras->articulo('Último artículo con compras', ['ultimoCoste' => 1.0]);
        $this->compras->entradaProveedor($articulo, 10, $this->ano() . '-01-10', ['coste' => 2.0]);

        $this->ejecutar();

        self::assertEqualsWithDelta(2.0, $this->tareas->costeMedio($articulo), 0.000001, 'El coste medio del último artículo tiene que escribirse.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/tareas/AcumuladoComprasTarea.php:40-44
     *
     * @group esperado
     * @group ejecucion
     * @group alto
     *
     * @que-ocurre-hoy Sin ninguna línea de compra en la base, la tarea falla con un error de tipo y
     *   se queda en proceso. El planificador solo lanza las tareas activas, así que ya no vuelve a
     *   lanzarla, aunque después entren compras.
     * @que-deberia-ocurrir Que la tarea termine sin nada que escribir, anote su ejecución y quede
     *   activa.
     * @por-que-ocurre Una lectura sin filas devuelve nulo y no una lista vacía, y la tarea pasa ese
     *   nulo a un método que exige una lista. Además, nada devuelve la tarea a activa cuando falla.
     * @como-deberia-funcionar Tratar la lectura vacía como una lista sin acumulados y, si la tarea
     *   falla, dejarla en estado de error.
     */
    public function test_T11_sinComprasLaTareaTerminaYQuedaActiva(): void
    {
        // Una instalación sin compras: se quitan, dentro de la transacción del caso, las líneas que
        // trae la base.
        $this->db->query('DELETE FROM albprolinea');

        try {
            $this->ejecutar();
        } catch (\TypeError $e) {
            // Lo que decide es cómo queda la tarea.
        }

        $tarea = $this->tareas->filaDeTarea($this->idTarea);
        self::assertSame(\MTareasCron::ESTADO_ACTIVO, (int) $tarea['estado'], 'La tarea tiene que quedar activa.');
        self::assertNotNull($tarea['ultima_ejecucion'], 'La tarea tiene que anotar su ejecución.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tareas_cron/tareas/AcumuladoComprasTarea.php:48-72
     *
     * @group esperado
     * @group coste
     * @group bajo
     *
     * @que-ocurre-hoy Si un mes se queda sin compras —por ejemplo, porque se corrige la fecha del
     *   albarán—, su acumulado conserva la cantidad y el coste que tenía. Ningún otro módulo lee esa
     *   tabla.
     * @que-deberia-ocurrir Que el acumulado refleje las compras que hay: un mes sin compras, sin
     *   cantidad.
     * @por-que-ocurre La tarea solo escribe los meses que tienen líneas, y no toca los demás.
     * @como-deberia-funcionar Quitar, o dejar a cero, el acumulado de los meses que se quedan sin
     *   compras.
     */
    public function test_T12_unMesQueSeQuedaSinComprasNoConservaSuAcumulado(): void
    {
        $ano = $this->ano();
        $articulo = $this->compras->articulo('Albarán cambiado de mes');
        $albaran = $this->compras->entradaProveedor($articulo, 10, "$ano-01-10", ['coste' => 2.0]);
        $this->ejecutar();
        // Al volver a guardar el albarán con otra fecha, cambia la de su cabecera.
        $this->db->query("UPDATE albprot SET Fecha = '$ano-02-10 00:00:00' WHERE id = $albaran");

        $this->ejecutar();

        $cantidades = array_column($this->tareas->acumuladoDe($articulo), 'cantidad', 'month');
        self::assertEqualsWithDelta(10.0, $cantidades[2] ?? 0.0, 0.000001);
        self::assertEqualsWithDelta(0.0, $cantidades[1] ?? 0.0, 0.000001, 'Enero ya no tiene compras.');
    }

    /**
     * @comportamiento Un mes cuya cantidad neta es cero —lo comprado y lo devuelto se igualan— no
     *   aporta nada a la media, tampoco su coste: si la devolución se valoró a otro coste que la
     *   compra, esa diferencia no cuenta.
     */
    public function test_T13_unMesConCantidadNetaCeroNoAportaNadaALaMedia(): void
    {
        $ano = $this->ano();
        $articulo = $this->compras->articulo('Comprado y devuelto el mismo mes');
        $this->compras->entradaProveedor($articulo, 10, "$ano-01-10", ['coste' => 8.0]);
        $this->compras->entradaProveedor($articulo, 5, "$ano-02-10", ['coste' => 10.0]);
        $this->compras->devolucionProveedor($articulo, 5, "$ano-02-20", ['coste' => 12.0]);
        $this->articuloDeCierre();

        $this->ejecutar();

        // Solo cuenta enero: 10 × 8 / 10. Con febrero sumado línea a línea sería (80 + 50 − 60) / 10.
        self::assertEqualsWithDelta(8.0, $this->tareas->costeMedio($articulo), 0.000001);
    }

    /** Una ejecución de la tarea, como la lanza el planificador. */
    private function ejecutar(): void
    {
        (new \AcumuladoComprasTarea($this->idTarea))->execute();
    }

    /**
     * Un artículo con una compra, sembrado detrás de los del caso.
     *
     * La tarea escribe el coste medio de un artículo cuando pasa al siguiente. Sin otro con compras
     * detrás, el del caso sería el último del recorrido, cuyo coste no se escribe.
     */
    private function articuloDeCierre(): void
    {
        $articulo = $this->compras->articulo('Artículo de cierre');
        $this->compras->entradaProveedor($articulo, 1, $this->ano() . '-01-01');
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

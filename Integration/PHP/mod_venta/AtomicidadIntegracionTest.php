<?php

/**
 * Atomicidad del guardado temporal: `anhadirTemporal` hace tres escrituras sueltas
 * -modificar los datos del temporal, enlazar el numero de documento real si lo hay, y actualizar sus
 * totales- sin abrir transaccion. Un fallo en la segunda deja hecha la primera y nunca llega a la
 * tercera: el temporal queda con datos nuevos pero totales viejos, sin ningun aviso de que la
 * operacion no termino.
 *
 * El test corre en proceso propio porque el caso de despacho `anhadirTemporal` se carga con
 * `include_once`: `TareasDespachoIntegracionTest::test_anhadirTemporal_creaElRegistro` ya lo invoca
 * una vez en el proceso normal de la suite, y una segunda invocacion sin aislar devolveria una
 * respuesta vacia en vez de ejecutar el codigo que aqui importa.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\DespachoTareas;
use TPVFox\Test\Siembra\Siembra;

final class AtomicidadIntegracionTest extends CasoIntegracion
{
    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_venta/tareas.php';

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siembra = new Siembra($this->db);
    }

    /**
     * Defecto: un temporal con totales conocidos (999.99) se modifica con una linea de producto
     * nueva y un numero de documento real invalido. La primera escritura (los datos del temporal)
     * se confirma; la segunda (enlazar el documento real) revienta por SQL invalido; la tercera (los
     * totales) nunca se ejecuta. El temporal queda con productos nuevos y totales de antes, sin que
     * nada distinga ese estado de uno guardado con exito.
     *
     * Afirma el comportamiento defectuoso, de modo que pasa mientras el defecto siga vivo.
     *
     * @estado verde
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_defecto_fallaEntreDosEscriturasDejaLaPrimeraConfirmadaSinLaTercera(): void
    {
        $idUsuario = $this->siembra->usuarioPorDefecto();
        $idTienda = $this->siembra->tiendaPorDefecto();
        $idCliente = $this->siembra->clientePorDefecto();

        $idTemporal = $this->siembra->pedidoTemporal([], [
            'total' => 999.99,
            'total_ivas' => '0',
            'fecha' => '2026-01-01',
        ]);

        $lanzada = null;
        try {
            DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, [
                'pulsado' => 'anhadirTemporal',
                'idTemporal' => $idTemporal,
                'idUsuario' => $idUsuario,
                'idTienda' => $idTienda,
                'idReal' => 'no_es_un_id', // fuerza SQL invalido en la segunda escritura
                'fecha' => '2026-02-15',
                'productos' => json_encode([['idArticulo' => 1, 'pvpSiva' => 10.0, 'nunidades' => 1]]),
                'idCliente' => $idCliente,
                'adjuntos' => [],
                'dedonde' => 'pedido',
            ]);
        } catch (\mysqli_sql_exception $e) {
            $lanzada = $e;
        }

        self::assertNotNull($lanzada, 'se esperaba que la segunda escritura reventara con SQL invalido');

        $fila = $this->db->query("SELECT Fecha, total, Numpedcli FROM pedcliltemporales WHERE id={$idTemporal}")
            ->fetch_assoc();

        self::assertSame(
            '2026-02-15',
            substr($fila['Fecha'], 0, 10),
            'la primera escritura (modificarDatosTemporal) quedo confirmada con la fecha nueva'
        );
        self::assertNull($fila['Numpedcli'], 'la segunda escritura revento antes de completarse');
        self::assertSame(
            '999.990000',
            $fila['total'],
            'la tercera escritura (modTotales) no debio ejecutarse nunca: el total sigue siendo el de antes del fallo'
        );
    }
}

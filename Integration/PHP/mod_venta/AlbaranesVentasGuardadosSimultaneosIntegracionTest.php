<?php

/**
 * Dos guardados que mueven el stock de los mismos articulos a la vez, cada uno en su transaccion.
 *
 * Dentro de una transaccion, cada fila de stock que el guardado toca queda bloqueada hasta el
 * final. Si dos guardados tocan los mismos dos articulos en orden contrario, cada uno retiene la
 * fila que el otro necesita y ninguno puede seguir: la base lo detecta, cancela uno y deja
 * terminar al otro.
 *
 * El guardado que se observa es el del albaran, por su clase. El otro se reproduce a mano por una
 * segunda conexion, con las mismas sentencias de stock: una prueba corre en un solo proceso y no
 * puede lanzar dos guardados a la vez. La base cancela al que menos lleva escrito, y para que ese
 * sea el del albaran el otro llega con mas movimientos hechos.
 *
 * No corre dentro de la transaccion de la suite, porque abre las suyas. Limpia lo que siembra.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use mysqli;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class AlbaranesVentasGuardadosSimultaneosIntegracionTest extends CasoIntegracion
{
    private const INTERBLOQUEO = 1213;

    protected bool $aislarPorTransaccion = false;

    private \AlbaranesVentas $albaranes;
    private Siembra $siembra;
    private mysqli $otroGuardado;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $this->albaranes = new \AlbaranesVentas($this->db);
        $this->siembra = new Siembra($this->db);
        $this->otroGuardado = self::conectar('vigente');
    }

    protected function tearDown(): void
    {
        // La segunda conexion se cierra sin mas: si se quedo a medias, cerrarla deshace lo suyo.
        $this->otroGuardado->close();
        if ($this->db->query('SELECT @@in_transaction AS t')->fetch_assoc()['t'] ?? 0) {
            $this->db->rollback();
        }
        foreach ($this->siembra->insertadoEn('articulos') as $id) {
            foreach ($this->db->query("SELECT DISTINCT idalbcli FROM albclilinea WHERE idArticulo = $id")->fetch_all(MYSQLI_ASSOC) as $fila) {
                $idAlbaran = (int) $fila['idalbcli'];
                $this->db->query("DELETE FROM albcliIva WHERE idalbcli = $idAlbaran");
                $this->db->query("DELETE FROM albclilinea WHERE idalbcli = $idAlbaran");
                $this->db->query("DELETE FROM albclit WHERE id = $idAlbaran");
            }
            $this->db->query("DELETE FROM articulosStocks WHERE idArticulo = $id");
            $this->db->query("DELETE FROM articulos WHERE idArticulo = $id");
        }
        parent::tearDown();
    }

    /**
     * @group control
     * @group existencias
     *
     * @para-que-sirve Cuando la base cancela un guardado de albaran por interbloqueo con otro, del
     *   cancelado no queda nada —ni albaran ni movimiento de stock— y el otro se completa. Es lo
     *   que hace inofensivo al interbloqueo: se ve como un error y se vuelve a guardar.
     */
    public function test_elGuardadoCanceladoPorInterbloqueoNoDejaRastroYElOtroSeCompleta(): void
    {
        $manzana = $this->siembra->articulo('Manzana de los guardados simultaneos');
        $kiwi = $this->siembra->articulo('Kiwi de los guardados simultaneos');
        $pera = $this->siembra->articulo('Pera de los guardados simultaneos');
        $fichaManzana = $this->siembra->existenciaRegistrada($manzana, 10.0);
        $this->siembra->existenciaRegistrada($kiwi, 10.0);
        $fichaPera = $this->siembra->existenciaRegistrada($pera, 10.0);
        $albaranesPrevios = $this->numeroDeAlbaranes();

        // El otro guardado lleva ya cincuenta entradas y salidas de la pera, que queda bloqueada.
        // Medio segundo despues pedira la manzana; no se espera a la respuesta.
        $this->otroGuardado->begin_transaction();
        for ($i = 0; $i < 50; $i++) {
            $this->otroGuardado->query("UPDATE articulosStocks SET stockOn = stockOn + (1) WHERE id = $fichaPera");
            $this->otroGuardado->query("UPDATE articulosStocks SET stockOn = stockOn + (-1) WHERE id = $fichaPera");
        }
        $this->otroGuardado->query("UPDATE articulosStocks SET stockOn = stockOn + (-1) WHERE id = $fichaPera");
        $this->otroGuardado->query(
            'UPDATE articulosStocks SET stockOn = stockOn + (-1)'
            . " WHERE id = (SELECT ficha FROM (SELECT SLEEP(0.5), $fichaManzana AS ficha) espera)",
            MYSQLI_ASYNC
        );

        // El guardado que se observa va al reves: bloquea la manzana y el kiwi, y se queda
        // esperando la pera. Cuando el otro pide la manzana, ninguno de los dos puede seguir.
        $this->db->begin_transaction();
        $lanzada = null;
        try {
            $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
                $this->linea($manzana, 2.0),
                $this->linea($kiwi, 2.0),
                $this->linea($pera, 2.0),
            ]), 0);
            $this->db->commit();
        } catch (\mysqli_sql_exception $e) {
            $lanzada = $e;
            $this->db->rollback();
        }

        $this->otroGuardado->reap_async_query();
        $this->otroGuardado->commit();

        self::assertNotNull($lanzada, 'La base tiene que cancelar el guardado del albaran.');
        self::assertSame(self::INTERBLOQUEO, $lanzada->getCode(), 'Y cancelarlo por interbloqueo, no por otra causa.');
        self::assertSame($albaranesPrevios, $this->numeroDeAlbaranes(), 'Del guardado cancelado no queda albaran.');
        self::assertEqualsWithDelta(9.0, $this->saldoDe($manzana), 0.000001, 'En la manzana solo consta la salida del otro guardado.');
        self::assertEqualsWithDelta(10.0, $this->saldoDe($kiwi), 0.000001, 'El kiwi, que solo movia el cancelado, sigue como estaba.');
        self::assertEqualsWithDelta(9.0, $this->saldoDe($pera), 0.000001, 'Y en la pera, solo la salida del otro guardado.');
    }

    // --- Apoyos -------------------------------------------------------------

    private function numeroDeAlbaranes(): int
    {
        $idCliente = $this->siembra->clientePorDefecto();

        return (int) $this->db->query("SELECT COUNT(*) n FROM albclit WHERE idCliente = $idCliente")->fetch_assoc()['n'];
    }

    private function saldoDe(int $idArticulo): float
    {
        $idTienda = $this->siembra->tiendaPorDefecto();

        return (float) $this->db
            ->query("SELECT stockOn FROM articulosStocks WHERE idArticulo = $idArticulo AND idTienda = $idTienda")
            ->fetch_assoc()['stockOn'];
    }

    /** Los datos de guardado con la forma exacta que `albaran.php` monta al pulsar Guardar. */
    private function datosDeGuardado(array $productos): array
    {
        return [
            'Numtemp_albcli' => 0,
            'Fecha'          => '2026-02-15',
            'idTienda'       => $this->siembra->tiendaPorDefecto(),
            'idUsuario'      => $this->siembra->usuarioPorDefecto(),
            'idCliente'      => $this->siembra->clientePorDefecto(),
            'estado'         => 'Guardado',
            'total'          => 12.10,
            'DatosTotales'   => ['desglose' => ['21' => ['iva' => 2.10, 'base' => 10.00]]],
            'productos'      => json_encode($productos),
            'pedidos'        => json_encode([]),
        ];
    }

    /** Una linea de producto tal como el navegador la envia dentro de `productos`. */
    private function linea(int $idArticulo, float $cantidad): array
    {
        return [
            'idArticulo'  => $idArticulo,
            'cref'        => 'REF' . $idArticulo,
            'ccodbar'     => '',
            'cdetalle'    => 'Linea de prueba',
            'ncant'       => $cantidad,
            'nunidades'   => $cantidad,
            'precioCiva'  => 12.10,
            'iva'         => 21,
            'pvpSiva'     => 10.00,
            'estadoLinea' => 'Activo',
        ];
    }
}

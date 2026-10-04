<?php

/**
 * Por que conexion sale el movimiento de stock cuando se guarda un albaran.
 *
 * El albaran se escribe por la conexion que la pantalla le pasa a `AlbaranesVentas`; el stock, por la
 * que `ModeloP` abre por su cuenta. Mientras no haya transaccion da igual. Con una transaccion en la
 * conexion del documento, deshacer el guardado no deshace el stock: ya se confirmo por la otra.
 *
 * Estos casos le dan a `ModeloP` una conexion propia, como la que tiene en produccion —en `albaran.php`
 * la abre `ClaseCliente` antes de guardar—, en vez de la compartida de la suite. Y no corren dentro de
 * la transaccion de la suite: lo que escribe esa otra conexion se confirma al momento, y los casos que
 * reproducen el guardado abren su propia transaccion. Limpian lo que siembran y lo que guardan.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use mysqli;
use ReflectionProperty;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class AlbaranesVentasConexionDelStockIntegracionTest extends CasoIntegracion
{
    protected bool $aislarPorTransaccion = false;

    private \AlbaranesVentas $albaranes;
    private Siembra $siembra;
    private mysqli $conexionDelModelo;
    private int $ultimoAlbaranPrevio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $this->albaranes = new \AlbaranesVentas($this->db);
        $this->siembra = new Siembra($this->db);
        $this->ultimoAlbaranPrevio = (int) $this->db->query('SELECT COALESCE(MAX(id), 0) m FROM albclit')->fetch_assoc()['m'];

        $this->conexionDelModelo = self::conectar('vigente');
        $this->fijarConexionDelModelo($this->conexionDelModelo);
    }

    protected function tearDown(): void
    {
        if ($this->db->query('SELECT @@in_transaction AS t')->fetch_assoc()['t'] ?? 0) {
            $this->db->rollback();
        }
        $this->fijarConexionDelModelo(null);
        $this->conexionDelModelo->close();

        $albaranes = array_merge(
            $this->siembra->insertadoEn('albclit'),
            array_column(
                $this->db->query("SELECT id FROM albclit WHERE id > {$this->ultimoAlbaranPrevio}")->fetch_all(MYSQLI_ASSOC),
                'id'
            )
        );
        foreach (array_unique(array_map('intval', $albaranes)) as $id) {
            $this->db->query("DELETE FROM albcliIva WHERE idalbcli = $id");
            $this->db->query("DELETE FROM albclilinea WHERE idalbcli = $id");
            $this->db->query("DELETE FROM pedcliAlb WHERE idAlbaran = $id");
            $this->db->query("DELETE FROM albclit WHERE id = $id");
        }
        foreach ($this->siembra->insertadoEn('articulos') as $id) {
            $this->db->query("DELETE FROM articulosStocks WHERE idArticulo = $id");
            $this->db->query("DELETE FROM articulos WHERE idArticulo = $id");
        }
        parent::tearDown();
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/clases/albaranesVentas.php:98
     *
     * @group defecto
     * @group existencias
     * @group alto
     *
     * @que-ocurre-hoy Si el guardado de un albaran se hace dentro de una transaccion y se deshace, el
     *   albaran desaparece pero la salida de stock de sus lineas se queda: el saldo baja sin ningun
     *   documento que lo justifique.
     * @que-deberia-ocurrir Que deshacer el guardado deshaga tambien su salida de stock.
     * @por-que-ocurre La salida de stock la escribe `alArticulosStocks` por la conexion de `ModeloP`, que
     *   no es la del documento y confirma cada sentencia al momento. La transaccion del documento no
     *   la alcanza.
     * @como-deberia-funcionar Que durante el guardado el movimiento de stock salga por la conexion del
     *   documento.
     */
    public function test_defecto_deshacerElGuardadoDeUnAlbaranDeshaceTambienSuSalidaDeStock(): void
    {
        $idArticulo = $this->articuloConSaldo(10.0);

        $this->db->begin_transaction();
        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([$this->linea($idArticulo, 2.0)]), 0);
        $this->db->rollback();

        self::assertEqualsWithDelta(
            10.0,
            $this->saldoDe($idArticulo),
            0.000001,
            'Deshecho el guardado, el saldo tiene que ser el de antes: la salida de 2 no puede quedarse.'
        );
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/clases/albaranesVentas.php:404
     *
     * @group defecto
     * @group existencias
     * @group alto
     *
     * @que-ocurre-hoy Si el borrado del albaran anterior, primer paso de volver a guardarlo, se hace
     *   dentro de una transaccion y se deshace, el albaran vuelve pero la reposicion de stock de sus
     *   lineas se queda: el saldo sube sin que nada haya entrado.
     * @que-deberia-ocurrir Que deshacer el borrado deshaga tambien la reposicion de stock.
     * @por-que-ocurre La reposicion la escribe `alArticulosStocks` por la conexion de `ModeloP`, que no es
     *   la del documento y confirma cada sentencia al momento.
     * @como-deberia-funcionar Que durante el guardado el movimiento de stock salga por la conexion del
     *   documento.
     */
    public function test_defecto_deshacerElBorradoDeUnAlbaranDeshaceTambienSuReposicionDeStock(): void
    {
        $idArticulo = $this->articuloConSaldo(10.0);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 3.0, '2026-02-15');

        $this->db->begin_transaction();
        $this->albaranes->eliminarAlbaranTablas($idAlbaran);
        $this->db->rollback();

        self::assertEqualsWithDelta(
            10.0,
            $this->saldoDe($idArticulo),
            0.000001,
            'Deshecho el borrado, el saldo tiene que ser el de antes: la reposicion de 3 no puede quedarse.'
        );
        self::assertCount(1, $this->albaranes->ProductosAlbaran($idAlbaran), 'El albaran vuelve con su linea.');
    }

    /**
     * @group control
     * @group existencias
     *
     * @para-que-sirve Sin transaccion, guardar un albaran resta del saldo lo que dicen sus lineas, y
     *   volver a guardarlo repone lo anterior y resta lo nuevo. Tiene que seguir siendo asi cuando el
     *   stock salga por la conexion del documento.
     */
    public function test_guardarYVolverAGuardarSinTransaccionMuevenElStockComoHoy(): void
    {
        $idArticulo = $this->articuloConSaldo(10.0);

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([$this->linea($idArticulo, 2.0)]), 0);
        self::assertEqualsWithDelta(8.0, $this->saldoDe($idArticulo), 0.000001, 'El guardado resta 2.');

        $idAlbaran = $this->ultimoAlbaran();
        $this->albaranes->eliminarAlbaranTablas($idAlbaran);
        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([$this->linea($idArticulo, 5.0)]), $idAlbaran);
        self::assertEqualsWithDelta(5.0, $this->saldoDe($idArticulo), 0.000001, 'Volver a guardar repone 2 y resta 5.');
    }

    /**
     * @group control
     * @group existencias
     *
     * @para-que-sirve Tras guardar, `ModeloP` sigue con la conexion que tenia antes. En la misma peticion
     *   la usan las demas clases que heredan de el, empezando por la del cliente.
     */
    public function test_trasGuardarElModeloSigueConLaConexionQueTenia(): void
    {
        $idArticulo = $this->articuloConSaldo(10.0);

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([$this->linea($idArticulo, 1.0)]), 0);
        $this->albaranes->eliminarAlbaranTablas($this->ultimoAlbaran());

        self::assertSame($this->conexionDelModelo, $this->conexionActualDelModelo());
    }

    /**
     * @group control
     * @group existencias
     *
     * @para-que-sirve Si el movimiento de stock falla a mitad del guardado, `ModeloP` sigue con la
     *   conexion que tenia antes. El fallo se provoca con un saldo en el limite inferior de la columna:
     *   restar una unidad lo saca de rango y la base rechaza la sentencia.
     */
    public function test_siElMovimientoDeStockFallaElModeloSigueConLaConexionQueTenia(): void
    {
        $idArticulo = $this->articuloConSaldo(-99999999999.0);

        $lanzada = null;
        try {
            $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([$this->linea($idArticulo, 1.0)]), 0);
        } catch (\mysqli_sql_exception $e) {
            $lanzada = $e;
        }

        self::assertNotNull($lanzada, 'El movimiento de stock tiene que fallar para llegar al estado que este caso comprueba.');
        self::assertSame($this->conexionDelModelo, $this->conexionActualDelModelo());
    }

    // --- Apoyos -------------------------------------------------------------

    /** Un articulo con su ficha de existencias en la tienda por defecto, ya confirmados. */
    private function articuloConSaldo(float $saldo): int
    {
        $idArticulo = $this->siembra->articulo('Articulo de la conexion del stock');
        $this->siembra->existenciaRegistrada($idArticulo, $saldo);

        return $idArticulo;
    }

    private function saldoDe(int $idArticulo): float
    {
        $idTienda = $this->siembra->tiendaPorDefecto();

        return (float) $this->db
            ->query("SELECT stockOn FROM articulosStocks WHERE idArticulo = $idArticulo AND idTienda = $idTienda")
            ->fetch_assoc()['stockOn'];
    }

    private function ultimoAlbaran(): int
    {
        return (int) $this->db->query('SELECT MAX(id) m FROM albclit')->fetch_assoc()['m'];
    }

    private function fijarConexionDelModelo(?mysqli $conexion): void
    {
        $propiedad = new ReflectionProperty('ModeloP', 'db');
        $propiedad->setAccessible(true);
        $propiedad->setValue(null, $conexion);
    }

    private function conexionActualDelModelo(): ?mysqli
    {
        $propiedad = new ReflectionProperty('ModeloP', 'db');
        $propiedad->setAccessible(true);

        return $propiedad->getValue();
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

<?php

/**
 * Lo que el albaran hace con las existencias cuando algo no va como en el camino sano.
 *
 * El descuento y la reposicion simples ya estan cubiertos en
 * `AlbaranesVentasGuardadoIntegracionTest`. Aqui van las condiciones adversas: vender lo
 * que no hay, vender sobre un articulo sin ficha, anular un albaran que no se puede
 * borrar entero, y lo que el movimiento escribe ademas del saldo.
 *
 * La conexion se comparte con el producto porque el movimiento lo sirve `alArticulosStocks`,
 * de `mod_producto`, que abre la suya propia si no se le inyecta esta.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class AlbaranesVentasExistenciasIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private \AlbaranesVentas $albaranes;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/albaranesVentas.php');
        $this->albaranes = new \AlbaranesVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    // --- Vender lo que no hay -------------------------------------------------

    /**
     * Defecto: vender un articulo que no tiene ficha de existencias la crea a cero y resta
     * sobre ella, dejando el inventario en negativo sin ningun aviso.
     *
     * Sintoma: tras guardar un albaran de 3 unidades de un articulo sin ficha, el inventario
     * de ese articulo en esa tienda queda en -3. Causa raiz: `actualizarStock()` del
     * colaborador crea la ficha con saldo cero cuando no existe y aplica el movimiento sobre
     * ella sin comprobar el resultado; no hay suelo ni comprobacion de disponibilidad en
     * ningun punto del trayecto. Correccion propuesta: advertir cuando el movimiento lleve el
     * inventario a un valor imposible, sin bloquear necesariamente la venta —vender sin
     * existencias registradas puede ser legitimo mientras el inventario no este al dia—, y
     * dejar constancia de que ocurrio. Evidencia: este test.
     */
    public function test_defecto_venderUnArticuloSinFichaDeExistenciasDejaElSaldoEnNegativo(): void
    {
        $idArticulo = $this->siembra->articulo('Producto sin ficha de existencias');

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea($idArticulo, ['ncant' => 3.0]),
        ]), 0);

        self::assertSame(-3.0, $this->existenciasDe($idArticulo));
    }

    /**
     * Defecto: la misma ausencia de suelo, por la puerta habitual — un articulo que si tiene
     * ficha, con menos existencias de las que se venden.
     *
     * Sintoma: con 2 unidades registradas, vender 5 deja el inventario en -3. Causa raiz y
     * correccion, las del caso anterior. Se prueba por separado porque es el camino que un
     * operador recorre a diario, mientras que el articulo sin ficha es el caso de alta
     * reciente. Evidencia: este test.
     */
    public function test_defecto_venderMasDeLoDisponibleDejaElSaldoEnNegativoSinAviso(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con existencias escasas');
        $this->siembra->existenciaRegistrada($idArticulo, 2.0);

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea($idArticulo, ['ncant' => 5.0]),
        ]), 0);

        self::assertSame(-3.0, $this->existenciasDe($idArticulo));
    }

    // --- Lo que el movimiento escribe ademas del saldo ------------------------

    /**
     * Defecto: una venta escribe los campos de traza de regularizacion de existencias, que
     * no son suyos.
     *
     * Sintoma: tras guardar un albaran, la fecha de regularizacion del articulo pasa a ser la
     * de la venta y el usuario que consta como responsable pasa a ser cero, borrando quien
     * regularizo de verdad y cuando. Causa raiz: el metodo del colaborador que aplica el
     * movimiento escribe esos dos campos en toda actualizacion, venga de una regularizacion
     * real o de una venta; solo la regularizacion propiamente dicha pone el usuario correcto.
     * El alcance sale del modulo de venta: otro modulo consulta esa fecha con un intervalo
     * para saber que productos se tocaron en un periodo, de modo que cada venta introduce en
     * ese resultado un producto que nadie regularizo. Correccion propuesta: que la traza de
     * regularizacion la escriba solo una regularizacion, y que el movimiento de venta deje su
     * propia constancia. Evidencia: este test.
     */
    public function test_defecto_laVentaPisaLaTrazaDeRegularizacionDelArticulo(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con regularizacion anterior');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);
        $idUsuario = $this->siembra->usuarioPorDefecto();
        $idTienda = $this->siembra->tiendaPorDefecto();
        $this->db->query(
            "UPDATE articulosStocks SET fechaRegularizacion='2026-01-15 09:00:00', usuarioRegularizacion=$idUsuario"
            . " WHERE idArticulo=$idArticulo AND idTienda=$idTienda"
        );

        $this->albaranes->AddAlbaranGuardado($this->datosDeGuardado([
            $this->linea($idArticulo, ['ncant' => 1.0]),
        ]), 0);

        $traza = $this->trazaDe($idArticulo);
        self::assertNotSame('2026-01-15 09:00:00', $traza['fechaRegularizacion'], 'La venta ha reescrito la fecha.');
        self::assertSame('0', $traza['usuarioRegularizacion'], 'Y ha dejado el usuario responsable a cero.');
    }

    /** La reposicion al anular pasa por el mismo sitio, de modo que tambien pisa la traza. */
    public function test_defecto_laReposicionAlAnularTambienPisaLaTrazaDeRegularizacion(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de reposicion con traza');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');
        $idUsuario = $this->siembra->usuarioPorDefecto();
        $idTienda = $this->siembra->tiendaPorDefecto();
        $this->db->query(
            "UPDATE articulosStocks SET fechaRegularizacion='2026-01-15 09:00:00', usuarioRegularizacion=$idUsuario"
            . " WHERE idArticulo=$idArticulo AND idTienda=$idTienda"
        );

        $this->albaranes->eliminarAlbaranTablas($idAlbaran);

        $traza = $this->trazaDe($idArticulo);
        self::assertNotSame('2026-01-15 09:00:00', $traza['fechaRegularizacion']);
        self::assertSame('0', $traza['usuarioRegularizacion']);
    }

    // --- El movimiento que no se pudo hacer -----------------------------------

    /**
     * Defecto: si el movimiento de existencias no se puede hacer, la linea ya esta escrita y
     * nadie lo deshace.
     *
     * Sintoma: al guardar un albaran cuya tienda no existe en el catalogo, la linea se
     * escribe y el movimiento de existencias falla a continuacion; el albaran queda con su
     * mercancia y sin la salida de inventario que le corresponde. Causa raiz: el movimiento
     * va despues del `INSERT` de la linea y no comparte transaccion con el; ademas su
     * resultado no se recoge, de modo que el guardado no puede reaccionar aunque quisiera.
     * La tienda inexistente es solo el instrumento —la ficha de existencias tiene clave ajena
     * contra el catalogo de tiendas—; el defecto es la falta de atadura entre las dos
     * escrituras, no la causa concreta del fallo. Correccion propuesta: comprobar el
     * resultado del movimiento y abortar la operacion que lo pidio, dentro de una transaccion
     * que abarque tambien el inventario. Evidencia: este test.
     *
     * @estado rojo
     */
    public function test_defecto_siElMovimientoDeExistenciasFallaLaLineaYaQuedoEscrita(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de tienda inexistente');
        $datos = $this->datosDeGuardado([$this->linea($idArticulo)]);
        $datos['idTienda'] = 999999999;

        $lanzada = null;
        try {
            $this->albaranes->AddAlbaranGuardado($datos, 0);
        } catch (\mysqli_sql_exception $e) {
            $lanzada = $e;
        }

        self::assertNotNull($lanzada, 'El movimiento debe fallar para llegar al estado que este caso documenta.');
        $idAlbaran = (int) $this->db
            ->query('SELECT id FROM albclit ORDER BY id DESC LIMIT 1')
            ->fetch_assoc()['id'];
        self::assertCount(
            1,
            $this->albaranes->ProductosAlbaran($idAlbaran),
            'La linea quedo escrita aunque su salida de inventario no llego a hacerse.'
        );
    }

    // --- Anular lo que no se puede borrar entero ------------------------------

    /**
     * Defecto: anular un albaran ya facturado destruye su contenido y no puede retirar su
     * cabecera, dejando un albaran vacio que sigue figurando como facturado.
     *
     * Sintoma: sobre un albaran que tiene factura, la anulacion borra sus lineas, su desglose
     * de ivas y sus enlaces con pedidos, y falla al borrar la cabecera porque la relacion con
     * la factura sigue apuntando a ella. Queda un albaran con su numero, su importe y su
     * estado, sin ninguna mercancia, y con las existencias sin reponer. Causa raiz: la
     * anulacion borra cuatro tablas en orden fijo y **la tabla que relaciona el albaran con
     * su factura no esta entre ellas**; ademas la comprobacion de que ningun borrado fallo se
     * evalua cuando los cuatro ya se ejecutaron, de modo que llega tarde para proteger nada.
     * Correccion propuesta: no permitir anular un albaran ya facturado, o retirar tambien esa
     * relacion, y en cualquier caso envolver la secuencia en una transaccion que la deshaga
     * ante el primer fallo. Evidencia: este test.
     */
    public function test_defecto_anularUnAlbaranFacturadoLoVaciaSinLlegarABorrarlo(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de albaran facturado');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');
        $this->siembra->facturarAlbaranCliente($idAlbaran);

        $lanzada = null;
        try {
            $this->albaranes->eliminarAlbaranTablas($idAlbaran);
        } catch (\mysqli_sql_exception $e) {
            $lanzada = $e;
        }

        self::assertNotNull($lanzada, 'El borrado de la cabecera debe fallar por la relacion con la factura.');
        self::assertNotSame([], $this->albaranes->datosAlbaran($idAlbaran), 'La cabecera sigue ahi ...');
        self::assertSame([], $this->albaranes->ProductosAlbaran($idAlbaran), '... pero sus lineas ya no.');
        self::assertSame([], $this->albaranes->IvasAlbaran($idAlbaran), 'Ni su desglose de ivas.');
        self::assertSame(
            10.0,
            $this->existenciasDe($idArticulo),
            'Y el inventario queda como estaba: la reposicion no llego a ejecutarse, porque su condicion se evalua despues del borrado que fallo.'
        );
    }

    /**
     * Defecto: la reposicion devuelve lo que las lineas dicen en el momento de anular, no lo
     * que el albaran saco cuando se guardo.
     *
     * Sintoma: un albaran cuyas lineas decian 2 unidades y se reescribieron despues con 5
     * repone **5** al anularse, con independencia de lo que en su dia saliera. Causa raiz: no
     * hay asiento por movimiento —el inventario es una unica cifra corriente—,
     * de modo que la unica fuente para calcular la devolucion son las lineas vigentes, que
     * pueden no ser las que originaron la salida. Correccion propuesta: reconstruir la
     * devolucion desde el asiento del movimiento, lo que exige que ese asiento exista.
     * Evidencia: este test.
     */
    public function test_defecto_laReposicionDevuelveLoQueDicenLasLineasDeAhoraNoLoQueSalio(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de lineas reescritas');
        $this->siembra->existenciaRegistrada($idArticulo, 10.0);
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');

        // El albaran saco 2 en su origen; sus lineas pasan a decir 5 sin que el inventario
        // se entere, que es lo que una reescritura produce.
        $this->db->query("UPDATE albclilinea SET ncant=5 WHERE idalbcli={$idAlbaran}");

        $this->albaranes->eliminarAlbaranTablas($idAlbaran);

        self::assertSame(
            15.0,
            $this->existenciasDe($idArticulo),
            'Repone 5 sobre las 10 sembradas, cuando el albaran solo habia sacado 2.'
        );
    }

    // --- Apoyos del caso ------------------------------------------------------

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
    private function linea(int $idArticulo, array $extra = []): array
    {
        return array_merge([
            'idArticulo'  => $idArticulo,
            'cref'        => 'REF' . $idArticulo,
            'ccodbar'     => '',
            'cdetalle'    => 'Linea de prueba',
            'ncant'       => 1.0,
            'nunidades'   => 1.0,
            'precioCiva'  => 12.10,
            'iva'         => 21,
            'pvpSiva'     => 10.00,
            'estadoLinea' => 'Activo',
        ], $extra);
    }

    /** Las existencias registradas del producto en la tienda por defecto. */
    private function existenciasDe(int $idArticulo): float
    {
        $idTienda = $this->siembra->tiendaPorDefecto();

        return (float) $this->db
            ->query("SELECT stockOn FROM articulosStocks WHERE idArticulo=$idArticulo AND idTienda=$idTienda")
            ->fetch_assoc()['stockOn'];
    }

    /** Los dos campos de traza de regularizacion del producto en la tienda por defecto. */
    private function trazaDe(int $idArticulo): array
    {
        $idTienda = $this->siembra->tiendaPorDefecto();

        return $this->db
            ->query("SELECT fechaRegularizacion, usuarioRegularizacion FROM articulosStocks WHERE idArticulo=$idArticulo AND idTienda=$idTienda")
            ->fetch_assoc();
    }
}

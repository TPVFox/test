<?php
/**
 * Extracción del estado del vigente: trayectoria compuesta sobre el catálogo
 * completo, cruzada con el detector de existencias negativas, marcada y anotada con
 * sus condiciones conocidas.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra;

final class ComprobacionExtraccionIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siembra = new Siembra($this->db);
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionExtraccion.php');
    }

    private function contexto(array $cambios = []): array
    {
        return array_merge([
            'ano' => '2026',
            'idTienda' => '1',
            'familiasExcluidas' => [],
            'ventanaDias' => 0,
        ], $cambios);
    }

    /** stocksRegularizacion exige un idTienda existente; el esquema de referencia no siembra ninguna. */
    private function asegurarTienda1(): void
    {
        $this->db->query(
            "INSERT IGNORE INTO tiendas (idTienda, tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano) "
                . "VALUES (1, 'principal', 'Tienda de pruebas', 'X0000000X', '000000000', 'Activo', 'Tienda de pruebas', 'Sin direccion', '2026')"
        );
    }

    private function fila(array $resultado, int $idArticulo): ?array
    {
        foreach ($resultado as $fila) {
            if ($fila['idArticulo'] === $idArticulo) {
                return $fila;
            }
        }
        return null;
    }

    public function test_T1_productoSinMovimientosEnElPeriodoSeMarcaAunqueElDetectorNoLoVea(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con arrastre negativo');
        // Salida dentro de la ventana de saldo de partida (31 dic - 1 ene): el producto
        // arranca el ejercicio ya en negativo, sin ningún movimiento posterior.
        $this->siembra->ventaTicket($idArticulo, 5.0, '2026-01-01');

        $comprobacion = new \ClaseComprobacionExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila, 'El producto tiene que aparecer: su trayectoria alcanzó negativo');
        self::assertSame(-5.0, $fila['saldoAlCorte']);
        self::assertSame(-5.0, $fila['saldoDeApertura'], 'Sin movimientos en el periodo, coincide con la partida');
        self::assertTrue($fila['marcado']);
        self::assertNull($fila['tipoIncidencia'], 'El detector no examina productos sin movimiento en el periodo');
    }

    public function test_T2_unAlbaranDelProveedorDeCierreFueraDeLaFronteraCuentaComoMovimiento(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con traspaso y compra tardía');
        // Dentro de la frontera (1 de enero): es saldo de partida.
        $this->siembra->entradaProveedor($idArticulo, 8.0, '2026-01-01', ['idProveedor' => 112]);
        // Del mismo proveedor, pero fuera de la frontera: cuenta como movimiento del
        // ejercicio, no como traspaso. La fecha decide, no el proveedor.
        $this->siembra->entradaProveedor($idArticulo, 3.0, '2026-01-05', ['idProveedor' => 112]);
        $this->siembra->ventaTicket($idArticulo, 20.0, '2026-01-10');

        $comprobacion = new \ClaseComprobacionExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        // Correcto: partida = 8; recorrido = +3 (05-ene) - 20 (10-ene) = -17 acumulado.
        self::assertSame(-9.0, $fila['saldoAlCorte']);
        self::assertSame(-9.0, $fila['minimoAlcanzado']);
        self::assertSame(8.0, $fila['saldoDeApertura']);
    }

    public function test_T3_familiaExcluidaSeAnotaComoCondicionConocida(): void
    {
        $idFamilia = $this->siembra->familia('Familia de prueba excluida');
        $idArticulo = $this->siembra->articulo('Producto de familia excluida', ['familia' => $idFamilia]);
        $this->siembra->ventaTicket($idArticulo, 4.0, '2026-01-01');

        $comprobacion = new \ClaseComprobacionExtraccion();
        $resultado = $comprobacion->extraer($this->contexto(['familiasExcluidas' => [$idFamilia]]));

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertContains('familia_excluida', $fila['condicionesConocidas']);
    }

    public function test_T3b_unaFamiliaConfiguradaQueNoExisteEnLaJerarquiaNoAnotaNada(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de familia sin configurar');
        $this->siembra->ventaTicket($idArticulo, 4.0, '2026-01-01');

        $comprobacion = new \ClaseComprobacionExtraccion();
        // 999999 no existe en vw_jerarquias_familias: expandirFamilias() no encuentra nada.
        $resultado = $comprobacion->extraer($this->contexto(['familiasExcluidas' => [999999]]));

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertNotContains('familia_excluida', $fila['condicionesConocidas']);
    }

    public function test_T4_productoSinExistenciaRegistradaEnTienda1SeAnotaComoNuncaIncluidoEnElCierre(): void
    {
        $idArticulo = $this->siembra->articulo('Producto nunca incluido en el cierre');
        $this->siembra->ventaTicket($idArticulo, 4.0, '2026-01-01');
        // Deliberadamente no se llama a existenciaRegistrada(): no hay fila en
        // articulosStocks para idTienda = 1, así que el cierre nunca lo habría tomado.

        $comprobacion = new \ClaseComprobacionExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertContains('nunca_incluido_en_cierre', $fila['condicionesConocidas']);
    }

    public function test_T4b_productoConExistenciaRegistradaEnTienda1NoSeAnotaComoNuncaIncluido(): void
    {
        $this->asegurarTienda1();
        $idArticulo = $this->siembra->articulo('Producto sí incluido en el cierre');
        $this->siembra->ventaTicket($idArticulo, 40.0, '2026-01-01');
        $this->db->query(
            'INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) '
                . "VALUES ({$idArticulo}, 1, 5, 0, 0)"
        );

        $comprobacion = new \ClaseComprobacionExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertNotContains('nunca_incluido_en_cierre', $fila['condicionesConocidas']);
    }

    public function test_T5_minimoDentroDeLaVentanaDeConsolidacionSeAnotaComoPeriodoNoConsolidado(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con mínimo reciente');
        $this->siembra->ventaTicket($idArticulo, 6.0, '2026-01-20');

        $comprobacion = new \ClaseComprobacionExtraccion();
        // Fecha de corte a 3 días del mínimo, con ventana de 7: cae dentro.
        $resultado = $comprobacion->extraer($this->contexto(['ventanaDias' => 7]), false, '2026-01-23');

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertContains('periodo_no_consolidado', $fila['condicionesConocidas']);
    }

    public function test_T6_unaRegularizacionActivaEnElPeriodoSeAnotaComoCondicionConocida(): void
    {
        $this->asegurarTienda1();
        $idArticulo = $this->siembra->articulo('Producto regularizado en el periodo');
        $this->siembra->ventaTicket($idArticulo, 4.0, '2026-01-01');

        $idUsuario = $this->siembra->usuarioPorDefecto();
        $sentencia = $this->db->prepare(
            'INSERT INTO stocksRegularizacion '
                . '(idArticulo, idTienda, fechaRegularizacion, stockActual, stockModif, stockFinal, stockOperacion, idUsuario, estado) '
                . 'VALUES (?, 1, "2026-01-15 10:00:00", 0, -2, -2, 0, ?, 1)'
        );
        $sentencia->bind_param('ii', $idArticulo, $idUsuario);
        $sentencia->execute();

        $comprobacion = new \ClaseComprobacionExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertContains('regularizacion_en_periodo', $fila['condicionesConocidas']);
    }

    public function test_T7_unProductoCuyoMinimoNuncaBajaDeCeroNoSeExamina(): void
    {
        $idArticulo = $this->siembra->articulo('Producto siempre en positivo');
        $this->siembra->entradaProveedor($idArticulo, 100.0, '2026-01-05');
        $this->siembra->ventaTicket($idArticulo, 10.0, '2026-01-10');

        $comprobacion = new \ClaseComprobacionExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        self::assertNull($this->fila($resultado, $idArticulo));
    }

    public function test_T8_invisibleEnModoNormalDetectadoEnModoEstricto(): void
    {
        $idArticulo = $this->siembra->articulo('Producto sostenido por la apertura');
        $this->siembra->entradaProveedor($idArticulo, 50.0, '2026-01-01');
        $this->siembra->ventaTicket($idArticulo, 10.0, '2026-01-05');

        $comprobacion = new \ClaseComprobacionExtraccion();
        $modoNormal = $comprobacion->extraer($this->contexto());
        $modoEstricto = $comprobacion->extraer($this->contexto(), true);

        self::assertNull($this->fila($modoNormal, $idArticulo), 'La apertura sostiene el saldo por encima de cero');
        self::assertNotNull($this->fila($modoEstricto, $idArticulo), 'Con S0=0 la misma bajada de 10 ya es negativa');
    }
}

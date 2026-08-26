<?php
/**
 * Extracción del estado del vigente: trayectoria compuesta sobre el catálogo
 * completo, cruzada con el detector de existencias negativas, marcada y anotada con
 * sus condiciones conocidas.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion\Comprobacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ComprobacionStockExtraccionIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siembra = new Siembra($this->db);
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseComprobacionStockExtraccion.php');
    }

    private function contexto(array $cambios = []): array
    {
        return array_merge([
            'ano' => '2026',
            'idTienda' => '1',
            'familiasExcluidas' => [],
            'ventanaDias' => 0,
            'umbralFraccionado' => 0.05,
            'umbralMagnitud' => 0.5,
            'umbralPorVenta' => 0.010,
            'timingVentanaDias' => 1,
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

        $comprobacion = new \ClaseComprobacionStockExtraccion();
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

        $comprobacion = new \ClaseComprobacionStockExtraccion();
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

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto(['familiasExcluidas' => [$idFamilia]]));

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertContains('familia_excluida', $fila['condicionesConocidas']);
    }

    public function test_T3b_unaFamiliaConfiguradaQueNoExisteEnLaJerarquiaNoAnotaNada(): void
    {
        $idArticulo = $this->siembra->articulo('Producto de familia sin configurar');
        $this->siembra->ventaTicket($idArticulo, 4.0, '2026-01-01');

        $comprobacion = new \ClaseComprobacionStockExtraccion();
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

        $comprobacion = new \ClaseComprobacionStockExtraccion();
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

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertNotContains('nunca_incluido_en_cierre', $fila['condicionesConocidas']);
    }

    public function test_T5_minimoDentroDeLaVentanaDeConsolidacionSeAnotaComoPeriodoNoConsolidado(): void
    {
        $idArticulo = $this->siembra->articulo('Producto con mínimo reciente');
        $this->siembra->ventaTicket($idArticulo, 6.0, '2026-01-20');

        $comprobacion = new \ClaseComprobacionStockExtraccion();
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

        $comprobacion = new \ClaseComprobacionStockExtraccion();
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

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        self::assertNull($this->fila($resultado, $idArticulo));
    }

    public function test_T8_invisibleEnModoNormalDetectadoEnModoEstricto(): void
    {
        $idArticulo = $this->siembra->articulo('Producto sostenido por la apertura');
        $this->siembra->entradaProveedor($idArticulo, 50.0, '2026-01-01');
        $this->siembra->ventaTicket($idArticulo, 10.0, '2026-01-05');

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $modoNormal = $comprobacion->extraer($this->contexto());
        $modoEstricto = $comprobacion->extraer($this->contexto(), true);

        self::assertNull($this->fila($modoNormal, $idArticulo), 'La apertura sostiene el saldo por encima de cero');
        self::assertNotNull($this->fila($modoEstricto, $idArticulo), 'Con S0=0 la misma bajada de 10 ya es negativa');
    }

    public function test_T9_unaEntradaExportadaCuentaEnElRecorridoIgualQueEnElSaldoDePartida(): void
    {
        // Un albarán de proveedor pasa a 'Exportado' cuando se exporta a XML, y sigue
        // siendo una recepción real. Las dos mitades de la trayectoria —el saldo de
        // partida y el recorrido del periodo— tienen que contarlo las dos: si una lo
        // deja fuera, la curva baja por una entrada que sí existe y el producto sale
        // examinado sin haber estado nunca en negativo.
        $idArticulo = $this->siembra->articulo('Producto con recepcion exportada');
        $this->siembra->entradaProveedor($idArticulo, 10.0, '2026-03-01', ['estado' => 'Exportado']);
        $this->siembra->ventaTicket($idArticulo, 12.0, '2026-03-05');

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        // -2 es 10 recibidas menos 12 vendidas. Sin contar la entrada saldría -12.
        self::assertSame(-2.0, $fila['saldoAlCorte']);
        self::assertSame(-2.0, $fila['minimoAlcanzado']);
    }

    public function test_T10_alterarLaExistenciaRegistradaNoCambiaLaTrayectoria(): void
    {
        // La trayectoria se reconstruye desde los movimientos y no desde la existencia
        // que la base declara. Si esa existencia entrara en el cálculo, un traspaso que
        // hubiese importado de más quedaría escondido por construcción.
        $this->asegurarTienda1();
        $idArticulo = $this->siembra->articulo('Producto con existencia registrada enganosa');
        $this->siembra->ventaTicket($idArticulo, 5.0, '2026-01-05');

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $antes = $this->fila($comprobacion->extraer($this->contexto()), $idArticulo);

        $this->db->query(
            'INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) '
                . "VALUES ({$idArticulo}, 1, 999, 0, 0)"
        );
        $despues = $this->fila((new \ClaseComprobacionStockExtraccion())->extraer($this->contexto()), $idArticulo);

        self::assertNotNull($antes);
        self::assertNotNull($despues, 'El producto sigue examinandose: 999 registradas no borran la bajada');
        self::assertSame($antes['saldoAlCorte'], $despues['saldoAlCorte']);
        self::assertSame($antes['minimoAlcanzado'], $despues['minimoAlcanzado']);
        self::assertSame($antes['saldoDeApertura'], $despues['saldoDeApertura']);
    }

    public function test_T11_laAperturaFechadaElUltimoDiaOElPrimeroDaElMismoSaldoDeArranque(): void
    {
        // Unas instalaciones fechan la importación de apertura el 31 de diciembre y
        // otras el 1 de enero. El rango del saldo de partida abarca los dos días, de
        // modo que la trayectoria arranca igual con cualquiera de las dos convenciones.
        $conCierre = $this->siembra->articulo('Producto con apertura fechada el ultimo dia');
        $this->siembra->entradaProveedor($conCierre, 6.0, '2025-12-31', ['idProveedor' => 112]);
        $this->siembra->ventaTicket($conCierre, 9.0, '2026-02-10');

        $conApertura = $this->siembra->articulo('Producto con apertura fechada el primer dia');
        $this->siembra->entradaProveedor($conApertura, 6.0, '2026-01-01', ['idProveedor' => 112]);
        $this->siembra->ventaTicket($conApertura, 9.0, '2026-02-10');

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $filaCierre = $this->fila($resultado, $conCierre);
        $filaApertura = $this->fila($resultado, $conApertura);

        self::assertNotNull($filaCierre);
        self::assertNotNull($filaApertura);
        self::assertSame(6.0, $filaCierre['saldoDeApertura']);
        self::assertSame($filaCierre['saldoDeApertura'], $filaApertura['saldoDeApertura']);
        self::assertSame($filaCierre['saldoAlCorte'], $filaApertura['saldoAlCorte']);
        self::assertSame($filaCierre['minimoAlcanzado'], $filaApertura['minimoAlcanzado']);
    }

    public function test_T12_unProductoDadoDeBajaTambienSeExamina(): void
    {
        // El catálogo no se criba por estado: un producto dado de baja con trayectoria
        // negativa es justo lo que hay que ver, y quien decide qué productos se examinan
        // es el catálogo entero.
        $idArticulo = $this->siembra->articulo('Producto dado de baja', ['estado' => 'Baja']);
        $this->siembra->ventaTicket($idArticulo, 3.0, '2026-01-05');

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila, 'Un producto en baja no queda fuera del catalogo examinado');
        self::assertSame(-3.0, $fila['saldoAlCorte']);
    }

    public function test_T13_unProductoQueAbreEnNegativoYSoloRecibeSeExaminaPorSuApertura(): void
    {
        // El punto de partida forma parte de la trayectoria. Este producto abre el
        // ejercicio debiendo tres unidades y a partir de ahí solo sube: su mínimo es la
        // propia apertura, y es el caso que el conjunto emitido tiene que recoger aunque
        // el recorrido del periodo no baje en ningún momento.
        $idArticulo = $this->siembra->articulo('Producto que abre en negativo y solo recibe');
        $this->siembra->ventaTicket($idArticulo, 3.0, '2026-01-01');
        $this->siembra->entradaProveedor($idArticulo, 10.0, '2026-03-01');

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto());

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila, 'El minimo es el saldo de apertura, y eso ya es negativo');
        self::assertSame(-3.0, $fila['saldoDeApertura']);
        self::assertSame(-3.0, $fila['minimoAlcanzado']);
        self::assertSame(7.0, $fila['saldoAlCorte']);
        self::assertFalse($fila['marcado'], 'Al corte ya no debe existencias');
    }

    public function test_T14_sinMovimientoQueExpliqueElMinimoNoSeAnotaPeriodoNoConsolidado(): void
    {
        // La condición dice que el mínimo cae en la ventana en que el periodo aún puede
        // cambiar. Si el mínimo es el saldo de apertura no hay ningún movimiento del
        // periodo que lo explique, y la condición no aplica por reciente que sea el corte.
        $idArticulo = $this->siembra->articulo('Producto con minimo en la apertura');
        $this->siembra->ventaTicket($idArticulo, 4.0, '2026-01-01');
        $this->siembra->entradaProveedor($idArticulo, 1.0, '2026-01-21');

        $comprobacion = new \ClaseComprobacionStockExtraccion();
        $resultado = $comprobacion->extraer($this->contexto(['ventanaDias' => 7]), false, '2026-01-23');

        $fila = $this->fila($resultado, $idArticulo);
        self::assertNotNull($fila);
        self::assertSame(-4.0, $fila['minimoAlcanzado']);
        self::assertNotContains('periodo_no_consolidado', $fila['condicionesConocidas']);
    }
}

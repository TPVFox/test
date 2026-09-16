<?php

/**
 * `ClaseVentas`, la base comun de `AlbaranesVentas`, `PedidosVentas` y `FacturasVentas`
 * (el SQL vive en una clase de `clases/`, como pide la convencion).
 *
 * Dos de sus seis metodos no tienen ningun llamador dentro de `mod_venta`:
 * `sumarIvaBases()` solo lo usa la copia duplicada de `mod_compras`
 * (`ClaseCompras::sumarIvaBases()`, hallazgo ya recogido para el modulo de compras), y
 * ninguna de las tres clases hijas de venta lo invoca. Se prueba igual, por el
 * mismo motivo que `modificarArrayPedidos()`.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ClaseVentasIntegracionTest extends CasoIntegracion
{
    private \ClaseVentas $claseVentas;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_venta/clases/ClaseVentas.php');
        $this->claseVentas = new \ClaseVentas($this->db);
        $this->siembra = new Siembra($this->db);
    }

    public function test_consulta_conSqlValidoDevuelveElResultado(): void
    {
        $resultado = $this->claseVentas->consulta('SELECT 1 AS uno');

        self::assertInstanceOf(\mysqli_result::class, $resultado);
    }

    /**
     * Defecto: tras una `consulta()` que tiene exito, `affected_rows` e `insert_id` de la
     * propia clase quedan sin asignar, pese a que sus comentarios documentan que "se
     * guarda cuando hacemos una consulta".
     *
     * Sintoma: `$this->affected_rows` y `$this->insert_id` valen `null` incluso despues de
     * un `consulta()` correcto. Causa raiz: `ClaseVentas::consulta()` (funciones.php... no,
     * `clases/ClaseVentas.php`) hace `return $smt;` dentro del propio `if ($smt)`, antes de
     * llegar a las dos lineas que asignan `$this->affected_rows`/`$this->insert_id`: ese
     * codigo esta despues del `if/else` y nunca se ejecuta. Sin impacto conocido dentro de
     * `mod_venta`: `AlbaranesVentas`, `PedidosVentas` y `FacturasVentas` leen siempre
     * `$db->insert_id` directamente (`grep` confirma 6 usos, ninguno via
     * `$this->insert_id`), nunca la propiedad de esta clase. Correccion propuesta: mover las
     * dos asignaciones antes del `return`, o eliminarlas si de verdad no las usa nadie.
     * Evidencia: este test, en rojo mientras el defecto siga sin corregirse por CC.
     *
     * @estado rojo
     */
    public function test_defecto_consulta_conExitoNuncaRellenaAffectedRowsNiInsertId(): void
    {
        $this->claseVentas->consulta('SELECT 1 AS uno');

        self::assertNotNull($this->claseVentas->affected_rows);
    }

    /**
     * Defecto (contrato incumplido, no comportamiento nuevo): la convencion documenta que un fallo
     * de SQL va en `['error']`/`['consulta']`, pero en este entorno cualificado mysqli
     * lanza excepcion antes de que `consulta()` pueda construir esa respuesta.
     *
     * Sintoma: `consulta()` con una tabla inexistente no devuelve el array de error — deja
     * escapar una `mysqli_sql_exception`. Causa raiz: el modo de informe de errores de
     * mysqli en PHP 8.1+ es `MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT` por defecto (lanza
     * excepcion), y ninguna parte de `consulta()` lo desactiva ni la captura; el `else` que
     * construye `$respuesta['error']` (para cuando `$db->query()` devuelve `false`, el
     * comportamiento del modo anterior a 8.1) queda inalcanzable con esta configuracion.
     * Mismo mecanismo ya documentado para el bloqueo de solo lectura en
     * `mod_reorganizacion`. Correccion propuesta: no corresponde a estas pruebas decidir si se captura
     * la excepcion o se asume el nuevo contrato — es una decision de diseño para F2.
     * Evidencia: este test, que fija el comportamiento real (la excepcion) como resultado
     * esperado; se pone en rojo si `consulta()` alguna vez vuelve a devolver el array de
     * error en su lugar, lo que señalaria un cambio de la configuración cualificada del
     * entorno, no del código.
     */
    public function test_defecto_consulta_conSqlInvalidoLanzaExcepcionEnVezDeDevolverError(): void
    {
        $this->expectException(\mysqli_sql_exception::class);

        $this->claseVentas->consulta('SELECT * FROM tabla_que_no_existe');
    }

    public function test_selectUnResult_conCoincidenciaDevuelveLaFila(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo select un result');

        $fila = $this->claseVentas->SelectUnResult('articulos', "idArticulo=$idArticulo");

        self::assertSame('Articulo select un result', $fila['articulo_name']);
    }

    public function test_selectUnResult_sinCoincidenciaDevuelveArrayVacio(): void
    {
        $fila = $this->claseVentas->SelectUnResult('articulos', 'idArticulo=999999999');

        self::assertSame([], $fila);
    }

    public function test_selectVariosResult_devuelveTodasLasCoincidencias(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo select varios');
        $this->siembra->articuloProveedor($idArticulo, $this->siembra->proveedor(), 3.0);
        $this->siembra->articuloProveedor($idArticulo, $this->siembra->proveedor('Otro proveedor'), 4.0);

        $filas = $this->claseVentas->SelectVariosResult('articulosProveedores', "idArticulo=$idArticulo");

        self::assertCount(2, $filas);
    }

    public function test_sumarIvaBases_sumaBaseYCuotaDeLasFilasDelDocumento(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo sumar iva');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');

        $sumas = $this->claseVentas->sumarIvaBases("FROM albcliIva WHERE idalbcli=$idAlbaran");

        self::assertSame('2.00', $sumas['totalbase']);
    }

    public function test_sumarIvaBases_sinFilasDevuelveNull(): void
    {
        // Documentado, no corregido: SUM() de SQL sobre cero filas es NULL, no cero: quien
        // llame a esta funcion sin comprobarlo sumaria null a un acumulador.
        $sumas = $this->claseVentas->sumarIvaBases('FROM albcliIva WHERE idalbcli=999999999');

        self::assertNull($sumas['totalbase']);
    }

    public function test_deleteRegistrosTabla_eliminaSoloLasFilasDeLaCondicion(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo borrar');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 2.0, '2026-01-10');

        $this->claseVentas->deleteRegistrosTabla('albcliIva', "WHERE idalbcli=$idAlbaran");

        $fila = $this->db->query("SELECT COUNT(*) c FROM albcliIva WHERE idalbcli=$idAlbaran")->fetch_assoc();
        self::assertSame('0', $fila['c']);
    }

    public function test_obtenerDatosUsuario_devuelveLaFilaDelUsuario(): void
    {
        $idUsuario = $this->siembra->usuarioPorDefecto();

        $usuario = $this->claseVentas->obtenerDatosUsuario($idUsuario);

        self::assertSame('pruebas', $usuario['username']);
    }
}

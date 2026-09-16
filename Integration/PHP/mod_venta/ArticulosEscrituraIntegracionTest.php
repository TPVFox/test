<?php

/**
 * `clases/articulos.php`: métodos de escritura. Ver `ArticulosLecturaIntegracionTest` para
 * el contexto del fichero.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ArticulosEscrituraIntegracionTest extends CasoIntegracion
{
    private \Articulos $articulos;
    private Siembra $siembra;
    private int $idArticulo;
    private int $idProveedor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/clases/articulos.php');
        $this->articulos = new \Articulos($this->db);
        $this->siembra = new Siembra($this->db);
        $this->idArticulo = $this->siembra->articulo('Articulo de escritura');
        $this->idProveedor = $this->siembra->proveedor('Proveedor de escritura');
    }

    public function test_addArticulosProveedores_creaLaRelacion(): void
    {
        $this->articulos->addArticulosProveedores([
            'idArticulo' => $this->idArticulo,
            'idProveedor' => $this->idProveedor,
            'refProveedor' => 'REF-1',
            'coste' => 5.5,
            'fecha' => '2026-01-01',
            'estado' => 'Activo',
        ]);

        $fila = $this->db->query(
            "SELECT coste FROM articulosProveedores WHERE idArticulo={$this->idArticulo} AND idProveedor={$this->idProveedor}"
        )->fetch_assoc();
        self::assertSame('5.500000', $fila['coste']);
    }

    public function test_modificarProveedorArticulo_cambiaLaReferencia(): void
    {
        $this->siembra->articuloProveedor($this->idArticulo, $this->idProveedor, 1.0);

        $this->articulos->modificarProveedorArticulo([
            'idArticulo' => $this->idArticulo,
            'idProveedor' => $this->idProveedor,
            'refProveedor' => 'REF-NUEVA',
        ]);

        $fila = $this->db->query(
            "SELECT crefProveedor FROM articulosProveedores WHERE idArticulo={$this->idArticulo} AND idProveedor={$this->idProveedor}"
        )->fetch_assoc();
        self::assertSame('REF-NUEVA', $fila['crefProveedor']);
    }

    public function test_modificarCosteProveedorArticulo_conCosteValidoLoGuarda(): void
    {
        $this->siembra->articuloProveedor($this->idArticulo, $this->idProveedor, 1.0);

        $this->articulos->modificarCosteProveedorArticulo([
            'idArticulo' => $this->idArticulo,
            'idProveedor' => $this->idProveedor,
            'coste' => 7.25,
            'fecha' => '2026-01-01',
        ]);

        $fila = $this->db->query(
            "SELECT coste FROM articulosProveedores WHERE idArticulo={$this->idArticulo} AND idProveedor={$this->idProveedor}"
        )->fetch_assoc();
        self::assertSame('7.250000', $fila['coste']);
    }

    /**
     * Reproduccion de la incidencia registrada en produccion, aun abierta: la guarda de coste vacío no protege nada.
     *
     * Síntoma: `modificarCosteProveedorArticulo()` con `coste` vacío lanza
     * `mysqli_sql_exception: Incorrect decimal value: ''` — error 1366, exactamente el
     * sintoma que esa incidencia documento en produccion. Causa raiz, ya establecida
     * entonces: la función calcula `$antes = 0` cuando `$datos['coste']` está vacío pero nunca
     * usa esa variable — el `$coste` original, sin validar, sigue yendo a la sentencia SQL
     * tal cual. Este test fija el comportamiento reproducido en el entorno cualificado de
     * el entorno cualificado como evidencia adicional para la correccion que ya tiene pendiente;
     * no es un hallazgo nuevo. Evidencia: este test, en rojo hasta que esa correccion
     * se valide.
     */
    public function test_conCosteVacioLanzaExcepcionPorqueLaGuardaNoProtege(): void
    {
        $this->siembra->articuloProveedor($this->idArticulo, $this->idProveedor, 1.0);

        $this->expectException(\mysqli_sql_exception::class);
        $this->expectExceptionMessageMatches('/Incorrect decimal value/');

        $this->articulos->modificarCosteProveedorArticulo([
            'idArticulo' => $this->idArticulo,
            'idProveedor' => $this->idProveedor,
            'coste' => '',
            'fecha' => '2026-01-02',
        ]);
    }

    public function test_addHistorico_conAntesNoNumericoLoGuardaComoCero(): void
    {
        $this->articulos->addHistorico([
            'idArticulo' => $this->idArticulo,
            'antes' => 'abc',
            'nuevo' => 5.5,
            'numDoc' => 1,
            'dedonde' => 'compras',
            'tipo' => 'compra',
            'estado' => 'Sin revisar',
            'idUsuario' => $this->siembra->usuarioPorDefecto(),
        ]);

        $fila = $this->db->query(
            "SELECT Antes FROM historico_precios WHERE idArticulo={$this->idArticulo} ORDER BY id DESC LIMIT 1"
        )->fetch_assoc();
        self::assertSame('0.0000', $fila['Antes'], 'A diferencia del coste, aqui la guarda si evita el error de SQL');
    }

    /**
     * Documentado, no corregido: la condicion excluye justo las filas que su propio
     * nombre sugiere que deberia alcanzar.
     *
     * `UPDATE historico_precios SET estado="Revisado" WHERE ... AND estado <> "Sin
     * revisar"` solo toca filas que YA no estan en "Sin revisar" — una fila recien creada
     * con ese estado por defecto no se modifica nunca por esta via. Tiene llamadores reales
     * en `mod_producto`/`mod_productos` (`Recalculo_precios.php`), fuera del alcance de
     * `mod_venta`: se documenta aqui porque la clase es compartida, y la interpretacion de
     * si el `<>` es intencional le corresponde a quien conozca ese flujo, no a estas pruebas.
     */
    public function test_modificarEstadosHistorico_noTocaLasFilasEnSinRevisar(): void
    {
        $this->siembra->historicoPrecio($this->idArticulo, 1.0, 2.0, 'compras', 9, ['estado' => 'Sin revisar']);

        $this->articulos->modificarEstadosHistorico(9, 'compras');

        $fila = $this->db->query(
            "SELECT estado FROM historico_precios WHERE idArticulo={$this->idArticulo} AND NumDoc=9"
        )->fetch_assoc();
        self::assertSame('Sin revisar', $fila['estado']);
    }

    public function test_modEstadoArticuloHistorico_cambiaElEstadoDeLaFilaIndicada(): void
    {
        $this->siembra->historicoPrecio($this->idArticulo, 1.0, 2.0, 'compras', 10, [
            'tipo' => 'compra',
            'estado' => 'Sin revisar',
        ]);

        $this->articulos->modEstadoArticuloHistorico($this->idArticulo, 10, 'compras', 'compra', 'Revisado');

        $fila = $this->db->query(
            "SELECT estado FROM historico_precios WHERE idArticulo={$this->idArticulo} AND NumDoc=10"
        )->fetch_assoc();
        self::assertSame('Revisado', $fila['estado']);
    }

    public function test_modificarRegHistorico_cambiaElEstadoPorId(): void
    {
        $idHistorico = $this->siembra->historicoPrecio($this->idArticulo, 1.0, 2.0, 'compras', 11);

        $this->articulos->modificarRegHistorico($idHistorico, 'Cerrado');

        $fila = $this->db->query("SELECT estado FROM historico_precios WHERE id=$idHistorico")->fetch_assoc();
        self::assertSame('Cerrado', $fila['estado']);
    }

    public function test_modArticulosPrecio_actualizaCivaYSiva(): void
    {
        $this->siembra->precioYTienda($this->idArticulo, 10.0, 8.0);

        $this->articulos->modArticulosPrecio(15.0, 12.0, $this->idArticulo);

        $fila = $this->db->query(
            "SELECT pvpCiva, pvpSiva FROM articulosPrecios WHERE idArticulo={$this->idArticulo}"
        )->fetch_assoc();
        self::assertSame('15.000000', $fila['pvpCiva']);
        self::assertSame('12.000000', $fila['pvpSiva']);
    }
}

<?php

/**
 * `ClaseCompras`, la base común de las clases del pedido, el albarán y la factura de proveedor: su
 * consulta, la suma del desglose de IVA, el borrado y las lecturas de una tabla, el recálculo de los
 * totales de las líneas, el coste que deja una compra en el proveedor del producto con su histórico, y
 * los datos del usuario de un documento.
 *
 * La clase recibe la conexión al crearse: se le da la del caso, con su transacción. La escritura del
 * coste del proveedor y del histórico la hace `clases/articulos.php`, que tiene sus propias pruebas en
 * `mod_venta`; aquí se prueba lo que la clase de compras decide.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCompras;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ClaseComprasIntegracionTest extends CasoIntegracion
{
    private \ClaseCompras $compras;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_compras/clases/ClaseCompras.php');
        $this->compras = new \ClaseCompras($this->db);
        $this->siembra = new Siembra($this->db);
    }

    /** @comportamiento Una consulta que funciona devuelve su resultado y deja en la clase las filas afectadas y el id insertado. */
    public function test_unaConsultaQueFuncionaGuardaLasFilasAfectadasYElIdInsertado(): void
    {
        $resultado = $this->compras->consulta("INSERT INTO proveedores (razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Proveedor de la consulta', 'X0000000X', 'Sin direccion', NOW(), 'Activo')");
        $id = (int) $this->db->insert_id;

        self::assertTrue($resultado);
        self::assertSame([1, $id], [$this->compras->affected_rows, $this->compras->insert_id]);
        self::assertInstanceOf(\mysqli_result::class, $this->compras->consulta('SELECT 1'));
    }

    /**
     * @comportamiento Una consulta que la base rechaza lanza la excepción de la conexión: la rama que
     *   devolvería la sentencia y el error no se alcanza, como en el resto de clases sobre la conexión del
     *   núcleo.
     */
    public function test_unaConsultaQueLaBaseRechazaLanzaLaExcepcionDeLaConexion(): void
    {
        $this->expectException(\mysqli_sql_exception::class);

        $this->compras->consulta('SELECT * FROM tabla_que_no_existe');
    }

    /** @comportamiento La suma del desglose de IVA de un documento da su cuota y su base totales; sin filas, las dos vacías. */
    public function test_laSumaDelDesgloseDeIvaDaLaCuotaYLaBaseTotales(): void
    {
        $pan = $this->siembra->articulo('Pan de compra', ['ultimoCoste' => 2.0, 'iva' => 4]);
        $vino = $this->siembra->articulo('Vino de compra', ['ultimoCoste' => 10.0, 'iva' => 21]);
        $albaran = $this->siembra->entradaProveedor($pan, 5, '2026-03-02');
        $this->siembra->lineaEntrada($albaran, $vino, 2);

        $suma = $this->compras->sumarIvaBases('FROM albproIva WHERE idalbpro = ' . $albaran);
        $vacia = $this->compras->sumarIvaBases('FROM albproIva WHERE idalbpro = 0');

        self::assertEqualsWithDelta([4.6, 30.0], [(float) $suma['importeIva'], (float) $suma['totalbase']], 0.001);
        self::assertSame(['importeIva' => null, 'totalbase' => null], $vacia);
    }

    /** @comportamiento Borrar en una tabla quita las filas de su condición; sin condición, todas. */
    public function test_borrarEnUnaTablaQuitaLasFilasDeSuCondicion(): void
    {
        $this->db->query('CREATE TEMPORARY TABLE compras_borrado (id INT, grupo INT)');
        $this->db->query('INSERT INTO compras_borrado VALUES (1, 1), (2, 1), (3, 2)');

        $this->compras->deleteRegistrosTabla('compras_borrado', 'WHERE grupo = 1');
        $quedan = (int) $this->db->query('SELECT COUNT(*) FROM compras_borrado')->fetch_row()[0];
        $this->compras->deleteRegistrosTabla('compras_borrado');
        $ninguna = (int) $this->db->query('SELECT COUNT(*) FROM compras_borrado')->fetch_row()[0];

        self::assertSame([1, 0], [$quedan, $ninguna]);
    }

    /** @comportamiento Leer una fila trae la primera de su condición, y leer varias, todas; sin filas, nada. */
    public function test_leerUnaYVariasFilasDeUnaTabla(): void
    {
        $this->db->query('CREATE TEMPORARY TABLE compras_lectura (id INT, grupo INT)');
        $this->db->query('INSERT INTO compras_lectura VALUES (1, 1), (2, 1), (3, 2)');

        $una = $this->compras->SelectUnResult('compras_lectura', 'grupo = 1 ORDER BY id');
        $varias = $this->compras->SelectVariosResult('compras_lectura', 'grupo = 1 ORDER BY id');

        self::assertSame(['id' => '1', 'grupo' => '1'], $una);
        self::assertSame([['id' => '1', 'grupo' => '1'], ['id' => '2', 'grupo' => '1']], $varias);
        self::assertSame([], $this->compras->SelectUnResult('compras_lectura', 'grupo = 9'));
        self::assertSame([], $this->compras->SelectVariosResult('compras_lectura', 'grupo = 9'));
    }

    /**
     * @comportamiento Los totales suman las líneas activas —por su importe o, sin él, por sus unidades y
     *   su coste—, desglosadas por tipo de IVA en base, cuota y base más cuota, a dos decimales.
     */
    public function test_losTotalesSumanLasLineasActivasDesglosadasPorIva(): void
    {
        $lineas = [
            (object) ['estado' => 'Activo', 'iva' => '21', 'ncant' => 2, 'ultimoCoste' => '10'],
            ['estado' => 'Activo', 'iva' => '4', 'importe' => '5.50'],
            ['estado' => 'Eliminado', 'iva' => '21', 'importe' => '100'],
        ];

        $totales = $this->compras->recalculoTotales($lineas);

        self::assertSame(['base_raw' => 20.0, 'base' => '20.00', 'iva' => '4.20', 'BaseYiva' => '24.20'], $totales['desglose']['21']);
        self::assertSame(['base_raw' => 5.5, 'base' => '5.50', 'iva' => '0.22', 'BaseYiva' => '5.72'], $totales['desglose']['4']);
        self::assertSame(['4.42', '29.92'], [$totales['subivas'], $totales['total']]);
    }

    /** @comportamiento El estado de la línea puede leerse de otro campo, y la base se redondea una vez por tipo, de modo que base más cuota es el total. */
    public function test_losTotalesRedondeanUnaVezPorTipoYCuadranConElTotal(): void
    {
        $lineas = array_fill(0, 3, ['estadoLinea' => 'Activo', 'iva' => '21', 'importe' => '0.333']);

        $totales = $this->compras->recalculoTotales($lineas, 'estadoLinea');

        self::assertSame(['1.00', '0.21'], [$totales['desglose']['21']['base'], $totales['desglose']['21']['iva']]);
        self::assertSame(['0.21', '1.21'], [$totales['subivas'], $totales['total']]);
    }

    /**
     * @comportamiento Un coste cambiado en la compra se guarda en el proveedor del producto con la fecha de
     *   la compra, y deja en el histórico el anterior, el nuevo, el documento, «compras», «Pendiente» y el
     *   usuario.
     */
    public function test_unCosteCambiadoSeGuardaEnElProveedorYDejaSuHistorico(): void
    {
        $proveedor = $this->siembra->proveedor('Proveedor del coste');
        $pan = $this->siembra->articulo('Pan del coste');
        $this->siembra->articuloProveedor($pan, $proveedor, 1.5, ['fecha' => '2026-01-10']);

        $avisos = $this->compras->comprobarHistoricoCoste($this->productos([['idArticulo' => $pan, 'CosteAnt' => '1.5', 'ultimoCoste' => '1.8']]), 'albaran', 77, $proveedor, '2026-02-01', 83);

        self::assertSame([], $avisos);
        self::assertSame(['1.800000', '2026-02-01'], $this->costeDelProveedor($pan, $proveedor));
        self::assertSame([['1.5000', '1.8000', '77', 'albaran', 'compras', 'Pendiente', '83']], $this->historico($pan));
    }

    /** @comportamiento Si el proveedor ya tiene un coste más reciente que la compra, no se cambia: un aviso lo dice y no queda histórico. */
    public function test_unCosteMasRecienteEnElProveedorNoSeSustituye(): void
    {
        $proveedor = $this->siembra->proveedor('Proveedor del coste');
        $pan = $this->siembra->articulo('Pan del coste');
        $this->siembra->articuloProveedor($pan, $proveedor, 1.5, ['fecha' => '2026-03-01']);

        $avisos = $this->compras->comprobarHistoricoCoste($this->productos([['idArticulo' => $pan, 'CosteAnt' => '1.5', 'ultimoCoste' => '1.8']]), 'albaran', 77, $proveedor, '2026-02-01', 83);

        self::assertSame('warning', $avisos[0]['tipo']);
        self::assertStringContainsString('es mayor que la del producto' . $pan, $avisos[0]['mensaje']);
        self::assertSame(['1.500000', '2026-03-01'], $this->costeDelProveedor($pan, $proveedor));
        self::assertSame([], $this->historico($pan));
    }

    /** @comportamiento Un producto sin relación con el proveedor y sin coste anterior queda relacionado con él, con el coste de la compra y sin referencia. */
    public function test_unProductoSinRelacionConElProveedorQuedaRelacionado(): void
    {
        $proveedor = $this->siembra->proveedor('Proveedor nuevo');
        $pan = $this->siembra->articulo('Pan sin proveedor');

        $avisos = $this->compras->comprobarHistoricoCoste($this->productos([['idArticulo' => $pan, 'ultimoCoste' => '1.8']]), 'albaran', 77, $proveedor, '2026-02-01', 83);

        self::assertSame([], $avisos);
        self::assertSame(['1.800000', '2026-02-01'], $this->costeDelProveedor($pan, $proveedor));
        self::assertSame([], $this->historico($pan));
    }

    /** @comportamiento Sin productos, el histórico del coste devuelve el error. */
    public function test_sinProductosElHistoricoDelCosteLoDice(): void
    {
        $avisos = $this->compras->comprobarHistoricoCoste('[]', 'albaran', 77, 1, '2026-02-01', 83);

        self::assertSame([['tipo' => 'danger', 'mensaje' => 'Error no tiene productos']], $avisos);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/ClaseCompras.php:148-282
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy Un producto con un coste más reciente en su proveedor da un aviso, y desde él ningún
     *   producto de la misma compra deja su histórico: la lista de avisos es común a todos, y el histórico
     *   solo se escribe si está vacía.
     * @que-deberia-ocurrir Que cada producto deje su histórico según su propio caso.
     * @por-que-ocurre El histórico se condiciona a que no haya ningún aviso en toda la compra, no a los del
     *   producto.
     * @como-deberia-funcionar Comprobar los avisos del producto que se está tratando.
     */
    public function test_unAvisoDeUnProductoNoQuitaElHistoricoDeLosDemas(): void
    {
        $proveedor = $this->siembra->proveedor('Proveedor del coste');
        $pan = $this->siembra->articulo('Pan reciente');
        $vino = $this->siembra->articulo('Vino antiguo');
        $this->siembra->articuloProveedor($pan, $proveedor, 1.5, ['fecha' => '2026-03-01']);
        $this->siembra->articuloProveedor($vino, $proveedor, 10, ['fecha' => '2026-01-01']);

        $this->compras->comprobarHistoricoCoste($this->productos([
            ['idArticulo' => $pan, 'CosteAnt' => '1.5', 'ultimoCoste' => '1.8'],
            ['idArticulo' => $vino, 'CosteAnt' => '10', 'ultimoCoste' => '11'],
        ]), 'albaran', 77, $proveedor, '2026-02-01', 83);

        self::assertSame(['11.000000', '2026-02-01'], $this->costeDelProveedor($vino, $proveedor));
        self::assertSame([['10.0000', '11.0000', '77', 'albaran', 'compras', 'Pendiente', '83']], $this->historico($vino));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/ClaseCompras.php:166-212
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy Un coste cambiado en la compra de un producto que no tiene relación con el proveedor
     *   deja su histórico, pero no relaciona el producto con el proveedor ni guarda el coste en él.
     * @que-deberia-ocurrir Que el producto quede relacionado con el proveedor con el coste de la compra,
     *   como cuando no hay coste anterior.
     * @por-que-ocurre Con coste anterior solo se actualiza la relación si existe; el alta de la relación
     *   está en la otra rama.
     * @como-deberia-funcionar Dar de alta la relación si no existe, en las dos ramas.
     */
    public function test_unCosteCambiadoDeUnProductoSinRelacionLoRelacionaConElProveedor(): void
    {
        $proveedor = $this->siembra->proveedor('Proveedor nuevo');
        $pan = $this->siembra->articulo('Pan sin proveedor');

        $this->compras->comprobarHistoricoCoste($this->productos([['idArticulo' => $pan, 'CosteAnt' => '1.5', 'ultimoCoste' => '1.8']]), 'albaran', 77, $proveedor, '2026-02-01', 83);

        self::assertCount(1, $this->historico($pan));
        self::assertSame(['1.800000', '2026-02-01'], $this->costeDelProveedor($pan, $proveedor));
    }

    /** @comportamiento Los datos del usuario de un documento se leen por su id. */
    public function test_losDatosDelUsuarioDeUnDocumento(): void
    {
        $usuario = $this->siembra->usuarioPorDefecto();

        $datos = $this->compras->obtenerDatosUsuario($usuario);

        self::assertSame([(string) $usuario, 'pruebas'], [$datos['id'], $datos['username']]);
    }

    /** @param list<array<string, mixed>> $productos */
    private function productos(array $productos): string
    {
        return (string) json_encode($productos);
    }

    /** @return array{0: string, 1: string}|null */
    private function costeDelProveedor(int $articulo, int $proveedor): ?array
    {
        $fila = $this->db->query("SELECT coste, fechaActualizacion FROM articulosProveedores WHERE idArticulo = {$articulo} AND idProveedor = {$proveedor}")->fetch_row();

        return $fila === null ? null : [(string) $fila[0], (string) $fila[1]];
    }

    /** @return list<list<string>> */
    private function historico(int $articulo): array
    {
        return $this->db->query("SELECT Antes, Nuevo, NumDoc, Dedonde, Tipo, estado, idUsuario FROM historico_precios WHERE idArticulo = {$articulo} ORDER BY id")->fetch_all();
    }
}

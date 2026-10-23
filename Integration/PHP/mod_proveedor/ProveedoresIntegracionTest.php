<?php

/**
 * La clase compartida `clases/Proveedores.php`, con la que las compras, el producto, la reorganización,
 * las relaciones y la portada leen los proveedores: uno por su número, la búsqueda por nombre, todos con
 * su estado y los productos de uno.
 *
 * Contra la base, en una transacción que se deshace, con la conexión del caso compartida con la clase
 * (`ModeloP`). Cada caso siembra sus proveedores con un NIF propio.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProveedor;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ProveedoresIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private \Proveedores $proveedores;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/clases/Proveedores.php');
        $this->proveedores = new \Proveedores();
    }

    private function proveedor(string $nombre, string $razon, string $nif, string $estado = 'Activo'): int
    {
        $sentencia = $this->db->prepare(
            'INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $valores = [$nombre, $razon, $nif, 'Rúa 1', '2020-01-01 00:00:00', $estado];
        $sentencia->bind_param('ssssss', ...$valores);
        $sentencia->execute();

        return (int) $this->db->insert_id;
    }

    private function intentar(callable $llamada): mixed
    {
        try {
            return $llamada();
        } catch (\Throwable $error) {
            return get_class($error) . ': ' . $error->getMessage();
        }
    }

    /** @comportamiento Un proveedor se lee por su número con todos sus campos. */
    public function test_unProveedorSeLeePorSuNumero(): void
    {
        $id = $this->proveedor('Huerta Prueba', 'Huerta Prueba SL', 'B70000001');

        $proveedor = $this->proveedores->buscarProveedorId($id);

        self::assertSame(['Huerta Prueba', 'B70000001', 'Activo'], [$proveedor['nombrecomercial'], $proveedor['nif'], $proveedor['estado']]);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/Proveedores.php:7-18
     * @codigo-afectado clases/Proveedores.php:74-85
     *
     * @group defecto
     * @group proveedor
     * @group bajo
     *
     * @que-ocurre-hoy Leer un proveedor que no existe, o los productos de uno que no tiene ninguno, deja un
     *   aviso de PHP: la clase toma la primera fila, o el error, de una consulta que no trae ni lo uno ni lo
     *   otro.
     * @que-deberia-ocurrir Que devuelva un error que diga que no hay proveedor, y una lista vacía de
     *   productos.
     * @por-que-ocurre Sin filas, la clase base devuelve los datos a nulo y sin error, y estos métodos solo
     *   distinguen entre datos y error.
     * @como-deberia-funcionar Tratar el caso sin filas, como hace `todosProveedores()`.
     */
    public function test_sinFilasNoDejaAvisos(): void
    {
        $id = $this->proveedor('Huerta Prueba', 'Huerta Prueba SL', 'B70000001');

        self::assertIsArray($this->intentar(fn () => $this->proveedores->buscarProveedorId(999999999)));
        self::assertIsArray($this->intentar(fn () => $this->proveedores->buscarProductosProveedor($id)));
    }

    /**
     * @comportamiento La búsqueda por nombre exige todas las palabras, en el nombre comercial o en la razón
     *   social.
     */
    public function test_laBusquedaPorNombreExigeTodasLasPalabras(): void
    {
        $huerta = $this->proveedor('Huerta Prueba Norte', 'Hortalizas SL', 'B70000001');
        $bodega = $this->proveedor('Bodega Prueba', 'Vinos Prueba Norte SA', 'B70000002');
        $this->proveedor('Huerta Sur', 'Otra SL', 'B70000003');

        $encontrados = array_column($this->proveedores->buscarProveedorNombre('Prueba Norte')['datos'], 'idProveedor');

        self::assertEqualsCanonicalizing([(string) $huerta, (string) $bodega], $encontrados);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/Proveedores.php:19-47
     *
     * @group defecto
     * @group proveedor
     * @group alto
     *
     * @que-ocurre-hoy Buscar un proveedor con unas comillas dobles en lo tecleado —«Bodegas "La»— rompe la
     *   consulta: la búsqueda de proveedores de las compras y del producto termina con un error de la base.
     * @que-deberia-ocurrir Que las comillas se busquen como cualquier otro carácter.
     * @por-que-ocurre Cada palabra se pega entre comillas dobles en el texto de la consulta.
     * @como-deberia-funcionar Pasar las palabras como parámetros de una sentencia preparada.
     */
    public function test_buscarConComillasNoRompeLaConsulta(): void
    {
        $this->proveedor('Bodegas "La Ría"', 'Bodegas La Ría SL', 'B70000001');

        $resultado = $this->intentar(fn () => $this->proveedores->buscarProveedorNombre('Bodegas "La'));

        self::assertIsArray($resultado);
        self::assertCount(1, $resultado['datos'] ?? []);
    }

    /**
     * @comportamiento Todos los proveedores se leen con su número, su nombre, su NIF, su razón social, su
     *   estado —también «Especial»— y su registro sanitario.
     */
    public function test_todosLosProveedoresConSuEstado(): void
    {
        $especial = $this->proveedor('Despiece Prueba', 'Despiece Prueba SL', 'B70000001', 'Especial');

        $todos = array_column($this->proveedores->todosProveedores(), null, 'idProveedor');

        self::assertSame('Especial', $todos[$especial]['estado']);
        self::assertSame(['idProveedor', 'nombrecomercial', 'nif', 'razonsocial', 'estado', 'registro_sanitario'], array_keys($todos[$especial]));
    }

    /** @comportamiento Los productos de un proveedor son sus filas de `articulosProveedores`. */
    public function test_losProductosDeUnProveedor(): void
    {
        $siembra = new Siembra($this->db);
        $id = $this->proveedor('Huerta Prueba', 'Huerta Prueba SL', 'B70000001');
        $articulo = $siembra->articulo('Tomate');
        $siembra->articuloProveedor($articulo, $id, 2.5, ['crefProveedor' => 'TOM-1']);

        $productos = $this->proveedores->buscarProductosProveedor($id);

        self::assertSame([[(string) $articulo, 'TOM-1', '2.500000']], array_map(static fn (array $p): array => [$p['idArticulo'], $p['crefProveedor'], $p['coste']], $productos));
    }
}

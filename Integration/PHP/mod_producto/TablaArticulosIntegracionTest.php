<?php

/**
 * La clase de `clases/ClaseTablaArticulos.php`: la base de la clase de productos, que lee un producto
 * con todo lo que cuelga de él —precio en la tienda, referencias de tienda, proveedores con su coste,
 * familias, códigos de barras, existencias, histórico de precios y los últimos albaranes y pedidos de
 * compra— y lo devuelve como una lista de datos.
 *
 * El fichero incluye las clases del IVA y de las familias con `include`, sin comprobar si ya están
 * cargadas: cada caso corre en su propio proceso. La clase recibe la conexión del caso, y la de
 * existencias usa la que el caso presta a la clase base, para que vean lo que siembra y su escritura
 * se deshaga al terminar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class TablaArticulosIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/clases/ClaseTablaArticulos.php');
        $this->siembra = new Siembra($this->db);
    }

    /** @comportamiento Al crearse, la clase cuenta los artículos y toma la tienda principal. */
    public function test_alCrearseCuentaLosArticulosYTomaLaTiendaPrincipal(): void
    {
        $this->siembra->articulo('Manzana');

        $tabla = $this->tabla();

        self::assertSame($this->recuento('articulos'), (int) $tabla->GetNumRows());
        self::assertSame((string) $this->siembra->tiendaPorDefecto(), (string) $tabla->GetIdTienda());
    }

    /**
     * @comportamiento Un producto se lee con su precio en la tienda principal, su referencia en ella,
     *   sus proveedores con su coste, sus familias, sus códigos de barras y sus existencias.
     */
    public function test_unProductoSeLeeConTodoLoQueCuelgaDeEl(): void
    {
        $id = $this->productoCompleto('Manzana');

        $producto = $this->tabla()->GetProducto($id);

        self::assertSame('Manzana', $producto['articulo_name']);
        self::assertSame('21.00', $producto['iva']);
        self::assertSame(2.42, round((float) $producto['pvpCiva'], 2));
        self::assertSame(2.0, round((float) $producto['pvpSiva'], 2));
        self::assertSame('MANZ-1', $producto['cref_tienda_principal']);
        self::assertSame(['8410000000017'], $producto['codBarras']);
        self::assertSame(['Frutas'], array_column($producto['familias'], 'familiaNombre'));
        self::assertSame(['1.200000'], array_column($producto['proveedores_costes'], 'coste'));
        self::assertSame(7.0, (float) $producto['stocks']['stockOn']);
        self::assertSame([], $producto['comprobaciones']);
    }

    /** @comportamiento La lista de datos del producto no lleva la conexión, la tienda ni el recuento, que son internos. */
    public function test_losDatosDelProductoNoLlevanLoInterno(): void
    {
        $producto = $this->tabla()->GetProducto($this->productoCompleto('Manzana'));

        foreach (array_keys($producto) as $clave) {
            self::assertStringNotContainsString('ClaseTablaArticulos', (string) $clave);
        }
        self::assertArrayNotHasKey('db', $producto);
    }

    /** @comportamiento Sin número, el producto que se lee es uno nuevo: estado «Nuevo», beneficio 25 y sin precio. */
    public function test_sinNumeroSeLeeUnProductoNuevo(): void
    {
        $producto = $this->tabla()->GetProducto(0);

        self::assertSame('Nuevo', $producto['estado']);
        self::assertSame(25, $producto['beneficio']);
        self::assertSame(0, $producto['pvpCiva']);
        self::assertSame([], $producto['codBarras']);
    }

    /** @comportamiento Leer un producto que no existe devuelve el aviso grave en lugar de sus datos. */
    public function test_unProductoQueNoExisteDevuelveElAvisoGrave(): void
    {
        $producto = $this->tabla()->GetProducto(999999);

        self::assertSame('No puedo continuar porque hay error grave', $producto['error']);
        self::assertSame('danger', $producto['comprobaciones'][0]['tipo']);
    }

    /** @comportamiento El proveedor principal de un producto se lee con sus datos; uno que no existe se avisa. */
    public function test_elProveedorPrincipalSeLeeYUnoQueNoExisteSeAvisa(): void
    {
        $proveedor = $this->siembra->proveedor('Huerta del Norte');
        $conProveedor = $this->siembra->articulo('Pera', ['idProveedor' => (string) $proveedor, 'iva' => 21]);
        $this->siembra->precioYTienda($conProveedor, 1.21, 1.0);
        $sinProveedor = $this->siembra->articulo('Ciruela', ['idProveedor' => '99999', 'iva' => 21]);
        $this->siembra->precioYTienda($sinProveedor, 1.21, 1.0);

        $pera = $this->tabla()->GetProducto($conProveedor);
        $ciruela = $this->tabla()->GetProducto($sinProveedor);

        self::assertSame('Huerta del Norte', $pera['proveedor_principal']['razonsocial']);
        self::assertSame([], $ciruela['proveedor_principal']);
        self::assertContains('warning', array_column($ciruela['comprobaciones'], 'tipo'));
    }

    /** @comportamiento El histórico de precios se lee del más reciente al más antiguo, con los quince últimos. */
    public function test_elHistoricoDePreciosTraeLosQuinceUltimos(): void
    {
        $id = $this->siembra->articulo('Pera', ['iva' => 21]);
        $this->siembra->precioYTienda($id, 1.21, 1.0);
        for ($dia = 1; $dia <= 16; $dia++) {
            $this->siembra->historicoPrecio($id, $dia, $dia + 1, 'producto', 0, ['fecha' => sprintf('2026-02-%02d', $dia)]);
        }

        $historico = $this->tabla()->GetProducto($id)['productos_historico'];

        self::assertCount(15, $historico);
        self::assertSame('17.0000', $historico[0]['Nuevo']);
    }

    /** @comportamiento Los últimos albaranes y pedidos de compra del producto se leen con su proveedor. */
    public function test_losAlbaranesYLosPedidosDeCompraDelProductoSeLeen(): void
    {
        $id = $this->siembra->articulo('Pera', ['iva' => 21]);
        $this->siembra->precioYTienda($id, 1.21, 1.0);
        $this->siembra->entradaProveedor($id, 4, '2026-03-01');
        $sinPedidos = $this->tabla()->GetProducto($id)['pedidos'];
        $proveedor = $this->siembra->proveedor('Huerta del Norte');
        $tienda = $this->siembra->tiendaPorDefecto();
        $usuario = $this->siembra->usuarioPorDefecto();
        $this->db->query("INSERT INTO pedprot (Numtemp_pedpro, Fecha, idTienda, idUsuario, idProveedor, total_siniva) VALUES (0, '2026-03-02', {$tienda}, {$usuario}, {$proveedor}, 0)");
        $pedido = $this->db->insert_id;
        $this->db->query("INSERT INTO pedprolinea (idpedpro, idArticulo, ref_prov, cdetalle, costeSiva, iva, nfila, estadoLinea) VALUES ({$pedido}, {$id}, '', 'Pera', 1, 21, 1, 'Activo')");

        $producto = $this->tabla()->GetProducto($id);

        self::assertCount(1, $producto['albaranes']);
        self::assertSame('4.000000', $producto['albaranes'][0]['nunidades']);
        self::assertSame([], $sinPedidos);
        self::assertSame(['Huerta del Norte'], array_column($producto['pedidos'], 'razonsocial'));
    }

    /** @comportamiento Los IVA se leen de la tabla de IVA: todos, o uno por su número. */
    public function test_losIvaSeLeenDeSuTabla(): void
    {
        $tabla = $this->tabla();

        $todos = $tabla->GetTodosIvas();

        self::assertContains('21.00', array_column($todos, 'iva'));
        self::assertNotEmpty($tabla->GetUnIva($todos[0]['idIva']));
    }

    /** @comportamiento El precio de un producto en una tienda se da de alta; sin número de producto, no. */
    public function test_elPrecioDeUnProductoSeDaDeAltaYSinNumeroNo(): void
    {
        $id = $this->siembra->articulo('Pera', ['iva' => 21]);
        $idTienda = $this->siembra->tiendaPorDefecto();

        $alta = $this->tabla()->InsertarPreciosVentas(['id' => $id, 'idTienda' => $idTienda, 'pvpCiva' => '1.21', 'pvpSiva' => '1.00']);
        $sinNumero = $this->tabla()->InsertarPreciosVentas(['id' => 0, 'idTienda' => $idTienda, 'pvpCiva' => '1.21', 'pvpSiva' => '1.00']);

        self::assertSame(1, $alta['Afectados']);
        self::assertSame('1.210000', $this->unValor("SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = {$id} AND idTienda = {$idTienda}"));
        self::assertSame('danger', $sinNumero['error']['tipo']);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseTablaArticulos.php:141-151
     * @codigo-afectado clases/ClaseTablaArticulos.php:196-222
     * @codigo-afectado clases/ClaseTablaArticulos.php:544-562
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Leer un producto que no tiene precio en la tienda principal termina con un error
     *   de la base: la lectura intenta darle un precio a cero, sin la tienda.
     * @que-deberia-ocurrir Que el producto se lea, con el aviso de que no tiene precio.
     * @por-que-ocurre El alta del precio toma la tienda de la lista de datos del producto, de la que se
     *   quitaron antes los datos internos, la tienda entre ellos.
     * @como-deberia-funcionar Dar de alta el precio con la tienda de la clase, o no escribir al leer.
     */
    public function test_unProductoSinPrecioEnLaTiendaSeLeeConSuAviso(): void
    {
        $id = $this->siembra->articulo('Producto sin precio', ['iva' => 21]);

        $producto = $this->tabla()->GetProducto($id);

        self::assertSame('Producto sin precio', $producto['articulo_name']);
        self::assertContains('warning', array_column($producto['comprobaciones'], 'tipo'));
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseTablaArticulos.php:264-269
     * @codigo-afectado clases/ClaseTablaArticulos.php:291-298
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un producto con un IVA que no está en la tabla de IVA se lee con IVA 0 y sin
     *   ningún aviso, y deja avisos de PHP.
     * @que-deberia-ocurrir Que el cambio del IVA se avise en las comprobaciones del producto.
     * @por-que-ocurre La comprobación del IVA prepara el aviso, pero lo añade con una variable que no
     *   existe, y el aviso cita una consulta que tampoco existe.
     * @como-deberia-funcionar Añadir el aviso que devuelve la comprobación.
     */
    public function test_unIvaQueNoEstaEnLaTablaSeAvisa(): void
    {
        $id = $this->siembra->articulo('Producto con IVA raro', ['iva' => 13]);
        $this->siembra->precioYTienda($id, 1.13, 1.0);

        $producto = $this->tabla()->GetProducto($id);

        self::assertSame(0.0, (float) $producto['iva']);
        self::assertContains('warning', array_column($producto['comprobaciones'], 'tipo'));
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseTablaArticulos.php:196-212
     * @codigo-afectado clases/ClaseTablaArticulos.php:533-542
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Después de leer un producto que no existe, cualquier otro producto leído con la
     *   misma clase devuelve el aviso grave en lugar de sus datos. La clase de productos vacía después
     *   los avisos, y lo que queda en ella es un aviso de estado falso.
     * @que-deberia-ocurrir Que cada lectura devuelva las comprobaciones de su producto.
     * @por-que-ocurre Las comprobaciones se acumulan en la clase y ninguna lectura las vacía; basta un
     *   aviso grave anterior para que la lista de datos se sustituya por el error.
     * @como-deberia-funcionar Vaciar las comprobaciones al empezar cada lectura.
     */
    public function test_lasComprobacionesDeUnProductoNoPasanAlSiguiente(): void
    {
        $id = $this->productoCompleto('Manzana');
        $tabla = $this->tabla();

        $tabla->GetProducto(999999);
        $producto = $tabla->GetProducto($id);

        self::assertArrayNotHasKey('error', $producto);
        self::assertSame('Manzana', $producto['articulo_name'] ?? null);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseTablaArticulos.php:367-387
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Leído un producto con proveedores, el siguiente producto sin proveedores leído
     *   con la misma clase aparece con los proveedores y los costes del primero.
     * @que-deberia-ocurrir Que un producto sin proveedores se lea sin ninguno.
     * @por-que-ocurre Sin proveedores, la lectura solo añade un aviso y deja la lista de la lectura
     *   anterior, a diferencia de las de familias, códigos de barras o referencias, que la vacían.
     * @como-deberia-funcionar Vaciar la lista de proveedores cuando no hay ninguno.
     */
    public function test_unProductoSinProveedoresNoTomaLosDelAnterior(): void
    {
        $conProveedores = $this->productoCompleto('Manzana');
        $sinProveedores = $this->siembra->articulo('Pera', ['iva' => 21]);
        $this->siembra->precioYTienda($sinProveedores, 1.21, 1.0);
        $tabla = $this->tabla();

        $tabla->GetProducto($conProveedores);
        $pera = $tabla->GetProducto($sinProveedores);

        self::assertEmpty($pera['proveedores_costes']);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseTablaArticulos.php:98-103
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Un número de producto con una condición añadida trae otro producto: la condición
     *   entra en la consulta.
     * @que-deberia-ocurrir Que el número se tome como número, y un número que no es de ningún producto
     *   no traiga ninguno.
     * @por-que-ocurre El número se pega a la consulta sin convertirlo.
     * @como-deberia-funcionar Convertir el número antes de usarlo, o pasarlo como parámetro.
     */
    public function test_unNumeroConUnaCondicionNoTraeOtroProducto(): void
    {
        $otro = $this->productoCompleto('Manzana');

        $producto = $this->tabla()->GetProducto("999999 OR a.idArticulo = {$otro}");

        self::assertNotSame('Manzana', $producto['articulo_name'] ?? null);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseTablaArticulos.php:326-338
     * @codigo-afectado clases/ClaseTablaArticulos.php:98-103
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Sin una tienda principal, leer un producto termina con un error de la base.
     * @que-deberia-ocurrir Que la falta de tienda principal se avise, sin terminar la lectura.
     * @por-que-ocurre La clase solo guarda la tienda si encuentra exactamente una principal, y la
     *   lectura pega a la consulta la tienda vacía.
     * @como-deberia-funcionar Comprobar que hay tienda antes de leer, y avisar si no la hay.
     */
    public function test_sinTiendaPrincipalLeerUnProductoNoTerminaConUnError(): void
    {
        $id = $this->productoCompleto('Manzana');
        $this->db->query("UPDATE tiendas SET tipoTienda = 'fisica' WHERE tipoTienda = 'principal'");

        $producto = $this->tabla()->GetProducto($id);

        self::assertIsArray($producto);
    }

    /** @comportamiento Leer un producto que no tiene existencias en la tienda principal se las crea a cero. */
    public function test_leerUnProductoSinExistenciasLasCreaACero(): void
    {
        $id = $this->siembra->articulo('Pera', ['iva' => 21]);
        $this->siembra->precioYTienda($id, 1.21, 1.0);
        $tienda = $this->siembra->tiendaPorDefecto();
        $existencias = "SELECT stockOn FROM articulosStocks WHERE idArticulo = {$id} AND idTienda = {$tienda}";
        self::assertNull($this->unValor($existencias));

        $producto = $this->tabla()->GetProducto($id);

        self::assertSame(0.0, (float) $producto['stocks']['stockOn']);
        self::assertSame(0.0, (float) $this->unValor($existencias));
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/ClaseTablaArticulos.php:326-338
     * @codigo-afectado clases/ClaseTablaArticulos.php:98-103
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Con dos tiendas principales, leer un producto termina con un error de la base.
     * @que-deberia-ocurrir Que se avise de que la tienda principal no es una sola, sin terminar la
     *   lectura.
     * @por-que-ocurre La clase solo guarda la tienda si encuentra exactamente una principal, y la
     *   lectura pega a la consulta la tienda vacía.
     * @como-deberia-funcionar Comprobar que hay una sola tienda principal antes de leer, y avisar si
     *   no es así.
     */
    public function test_conDosTiendasPrincipalesLeerUnProductoNoTerminaConUnError(): void
    {
        $id = $this->productoCompleto('Manzana');
        $this->siembra->tienda(date('Y'), 'principal');

        $producto = $this->tabla()->GetProducto($id);

        self::assertIsArray($producto);
    }

    private function tabla(): \ClaseTablaArticulos
    {
        return new \ClaseTablaArticulos($this->db);
    }

    /** Un producto con precio y referencia en la tienda principal, un proveedor, una familia, un código de barras y existencias. */
    private function productoCompleto(string $nombre): int
    {
        $id = $this->siembra->articulo($nombre, ['iva' => 21]);
        $this->siembra->precioYTienda($id, 2.42, 2.0, null, ['crefTienda' => 'MANZ-1']);
        $this->siembra->articuloProveedor($id, $this->siembra->proveedor('Frutas del Valle'), 1.2);
        $familia = $this->siembra->familia('Frutas');
        $this->db->query("INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES ({$id}, {$familia})");
        $this->db->query("INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES ({$id}, '8410000000017')");
        $this->siembra->existenciaRegistrada($id, 7.0);

        return $id;
    }

    private function recuento(string $tabla): int
    {
        return (int) $this->unValor("SELECT COUNT(*) FROM {$tabla}");
    }

    private function unValor(string $sql): ?string
    {
        $fila = $this->db->query($sql)->fetch_row();

        return $fila === null ? null : (string) $fila[0];
    }
}

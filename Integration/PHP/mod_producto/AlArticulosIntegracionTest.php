<?php

/**
 * La clase de artículos de `modulos/mod_producto/clases/ClaseArticulos.php` (`alArticulos`): leer un
 * artículo o una página de ellos, saber si existe, sus movimientos para el mayor, su existencia
 * calculada desde los documentos, y los productos de una familia.
 *
 * La usan el mayor, la reorganización de existencias, las tareas programadas y las familias. Las
 * búsquedas por código de barras, por referencia y por descripción, la lectura del precio y la
 * comprobación y el alta de un producto en una familia no las llama ningún fichero del producto: se
 * prueban igual, porque siguen en la clase.
 *
 * La clase hereda de la clase base de acceso a datos: el caso le presta su conexión.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class AlArticulosIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulos.php');
        $this->siembra = new Siembra($this->db);
    }

    /** @comportamiento Un artículo se lee por su número; sin número, una página desde la posición indicada. */
    public function test_unArticuloSeLeePorSuNumeroYSinNumeroUnaPagina(): void
    {
        $id = $this->siembra->articulo('Manzana');
        $articulos = new \alArticulos();

        $uno = $articulos->leer($id);
        $pagina = $articulos->leer(0, 0, 3);

        self::assertSame('Manzana', $uno[0]['articulo_name']);
        self::assertCount(min(3, $this->recuento('articulos')), $pagina);
    }

    /** @comportamiento Un artículo existe si hay una fila con su número. */
    public function test_unArticuloExisteSiHayUnaFilaConSuNumero(): void
    {
        $id = $this->siembra->articulo('Manzana');
        $articulos = new \alArticulos();

        self::assertTrue($articulos->existe($id));
        self::assertFalse($articulos->existe(999999));
    }

    /**
     * @comportamiento Los movimientos del mayor de un artículo son sus entradas de compra, sus tickets
     *   activos y sus albaranes de venta entre las dos fechas, ordenados por fecha.
     */
    public function test_losMovimientosDelMayorSonCompraTicketYAlbaranEntreLasFechas(): void
    {
        $id = $this->siembra->articulo('Manzana');
        $this->siembra->entradaProveedor($id, 10, '2026-03-01');
        $this->siembra->ventaTicket($id, 3, '2026-03-03');
        $this->siembra->ventaAlbaranCliente($id, 2, '2026-03-02');
        $this->siembra->ventaTicket($id, 1, '2026-04-10');

        $mayor = (new \alArticulos())->calculaMayor(['fecha_inicial' => '2026-03-01', 'fecha_final' => '2026-03-31', 'idArticulo' => $id]);

        self::assertSame(['C', 'V', 'T'], array_column($mayor['datos'], 'tipodoc'));
        self::assertSame([10.0, 0.0, 0.0], array_map('floatval', array_column($mayor['datos'], 'entrega')));
        self::assertSame([0.0, 2.0, 3.0], array_map('floatval', array_column($mayor['datos'], 'salida')));
    }

    /**
     * @comportamiento La existencia calculada de un artículo en una tienda es lo comprado menos lo
     *   vendido en albaranes y en tickets activos; sin movimientos, cero.
     */
    public function test_laExistenciaCalculadaEsCompradoMenosVendido(): void
    {
        $id = $this->siembra->articulo('Manzana');
        $sinMovimientos = $this->siembra->articulo('Pera');
        $tienda = $this->siembra->tiendaPorDefecto();
        $this->siembra->entradaProveedor($id, 10, '2026-03-01');
        $this->siembra->ventaAlbaranCliente($id, 2, '2026-03-02');
        $this->siembra->ventaTicket($id, 3, '2026-03-03');
        $articulos = new \alArticulos();

        self::assertSame(5.0, (float) $articulos->calculaStock($id, $tienda));
        self::assertSame(0, $articulos->calculaStock($sinMovimientos, $tienda));
    }

    /** @comportamiento La existencia registrada de un artículo se lee, y se crea a cero si no la tenía. */
    public function test_laExistenciaRegistradaSeLeeYSeCreaSiNoHay(): void
    {
        $conExistencia = $this->siembra->articulo('Manzana');
        $this->siembra->existenciaRegistrada($conExistencia, 4.0);
        $sinExistencia = $this->siembra->articulo('Pera');
        $tienda = $this->siembra->tiendaPorDefecto();
        $articulos = new \alArticulos();

        self::assertSame(4.0, (float) $articulos->getStock($conExistencia, $tienda)['stockOn']);
        $articulos->getStock($sinExistencia, $tienda);
        self::assertSame(1, (int) $this->unValor("SELECT COUNT(*) FROM articulosStocks WHERE idArticulo = {$sinExistencia}"));
    }

    /** @comportamiento Los productos de una familia se leen, se comprueba si uno está en ella y se quita. */
    public function test_losProductosDeUnaFamiliaSeLeenSeCompruebanYSeQuitan(): void
    {
        $familia = $this->siembra->familia('Frutas');
        $id = $this->siembra->articulo('Manzana', ['familia' => $familia]);

        $leidos = \alArticulos::leerArticulosXFamilia($familia);
        $esta = \alArticulos::existeArticuloFamilia($id, $familia);
        \alArticulos::borrarArticuloFamilia($id, $familia);

        self::assertSame(['Manzana'], array_column($leidos, 'articulo_name'));
        self::assertSame('1', (string) $esta);
        self::assertSame(0, (int) \alArticulos::existeArticuloFamilia($id, $familia));
    }

    /** @comportamiento La clase da de alta y modifica artículos con la clase base, y devuelve la sentencia. */
    public function test_daDeAltaYModificaArticulosConLaClaseBase(): void
    {
        $articulos = new \alArticulos();

        $articulos->insert(['articulo_name' => 'Kiwi', 'estado' => 'Activo', 'fecha_creado' => '2026-01-01', 'ultimoCoste' => 1]);
        $id = (int) $this->db->insert_id;
        $sentencia = $articulos->update(['costepromedio' => '1.5'], ['idArticulo = ' . $id]);

        self::assertSame('1.500000', $this->unValor("SELECT costepromedio FROM articulos WHERE idArticulo = {$id}"));
        self::assertStringContainsString('UPDATE articulos', $sentencia);
    }

    /** @comportamiento Las búsquedas por código de barras, por referencia y por descripción en una tienda, y la lectura del precio. */
    public function test_lasBusquedasYLaLecturaDelPrecio(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $id = $this->siembra->articulo('Manzana qzxreineta', ['iva' => 21]);
        $this->siembra->precioYTienda($id, 2.42, 2.0, $tienda, ['crefTienda' => 'QZX-REF-1']);
        $this->db->query("INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES ({$id}, '8419999999017')");
        $articulos = new \alArticulos();

        self::assertSame('Manzana qzxreineta', $articulos->leerXCodBarras('8419999999017', $tienda)['datos'][0]['articulo_name']);
        self::assertSame('QZX-REF-1', $articulos->leerXReferencia('QZX-REF-1', $tienda)['datos'][0]['referencia']);
        self::assertSame('1', (string) $articulos->contarLikeCodBarras('841999999901', $tienda));
        self::assertSame('1', (string) $articulos->contarLikeReferencia('QZX-REF', $tienda));
        self::assertSame('1', (string) $articulos->contarLikeDescripcion('qzxreineta'));
        self::assertCount(1, $articulos->leerLikeDescripcion('qzxreineta', 0, $tienda)['datos']);
        self::assertCount(1, $articulos->leerLikeCodBarras('841999999901', 0, $tienda)['datos']);
        self::assertCount(1, $articulos->leerLikeReferencia('QZX-REF', 0, $tienda)['datos']);
        self::assertSame('Manzana qzxreineta', $articulos->leerPrecio($id, $tienda)['datos'][0]['descripcion']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseArticulos.php:108-121
     * @codigo-afectado modulos/mod_producto/clases/ClaseArticulos.php:150-163
     * @codigo-afectado modulos/mod_producto/clases/ClaseArticulos.php:178-191
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy La primera página de una búsqueda empieza en el segundo resultado: el primero
     *   no sale en ninguna página.
     * @que-deberia-ocurrir Que la primera página empiece en el primer resultado.
     * @por-que-ocurre La posición de cada página se calcula sumando uno, y la base cuenta desde cero.
     * @como-deberia-funcionar Calcular la posición sin sumar uno.
     */
    public function test_laPrimeraPaginaDeUnaBusquedaEmpiezaEnElPrimerResultado(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $id = $this->siembra->articulo('Producto buscado único', ['iva' => 21]);
        $this->siembra->precioYTienda($id, 1.21, 1.0, $tienda);

        $pagina = (new \alArticulos())->leerLikeDescripcion('buscado único', 1, $tienda);

        self::assertCount(1, $pagina['datos'] ?? []);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseArticulos.php:335-340
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Poner un producto en una familia con esta clase termina con un error de la base.
     * @que-deberia-ocurrir Que el producto quede en la familia.
     * @por-que-ocurre Los datos se pasan como textos sueltos y no como columna y valor: la sentencia
     *   que resulta no es válida.
     * @como-deberia-funcionar Pasar la familia y el artículo como columna y valor.
     */
    public function test_ponerUnProductoEnUnaFamiliaLoDejaEnElla(): void
    {
        $familia = $this->siembra->familia('Frutas');
        $id = $this->siembra->articulo('Manzana');

        \alArticulos::grabarArticuloFamilia($id, $familia);

        self::assertSame(1, (int) \alArticulos::existeArticuloFamilia($id, $familia));
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

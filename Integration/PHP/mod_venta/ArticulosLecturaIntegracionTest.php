<?php

/**
 * `clases/articulos.php` (raíz de la aplicación): metodos de lectura. No pertenece a
 * ningún módulo — la consumen `mod_compras`, `mod_producto`, `mod_productos` y
 * `mod_etiquetado` (`PCP-TPY` §4, punto de acoplamiento); `mod_venta` no la usa
 * directamente, pero se prueba aquí porque es donde el central decidió que se prueba una
 * sola vez para que `PCP-TPZ` la herede.
 *
 * `datosPrincipalesArticulo($idArticulo)` y `datosArticulosPrincipal($idArticulo,
 * $idTienda)` son dos métodos distintos con el nombre casi intercambiado: el primero no
 * exige que el artículo tenga precio (LEFT JOIN); el segundo sí (INNER JOIN con
 * `articulosPrecios`) y además filtra por tienda. Confundirlos al leer el código que los
 * llama es fácil.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ArticulosLecturaIntegracionTest extends CasoIntegracion
{
    private \Articulos $articulos;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/clases/articulos.php');
        $this->articulos = new \Articulos($this->db);
        $this->siembra = new Siembra($this->db);
    }

    public function test_buscarReferencia_conMatchDevuelveLaFila(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo con referencia');
        $idProveedor = $this->siembra->proveedor();
        $this->siembra->articuloProveedor($idArticulo, $idProveedor, 3.5, ['crefProveedor' => 'REF-X']);

        $fila = $this->articulos->buscarReferencia($idArticulo, $idProveedor);

        self::assertSame('REF-X', $fila['crefProveedor']);
    }

    public function test_buscarReferencia_sinMatchDevuelveNullSinAviso(): void
    {
        $resultado = $this->articulos->buscarReferencia(999999999, 999999999);

        self::assertNull($resultado);
    }

    public function test_buscarNombreArticulo_conMatch(): void
    {
        $idArticulo = $this->siembra->articulo('Nombre del articulo');

        $fila = $this->articulos->buscarNombreArticulo($idArticulo);

        self::assertSame('Nombre del articulo', $fila['articulo_name']);
    }

    /**
     * Defecto: sin coincidencia, la funcion lee una variable que solo se asigna dentro del
     * `if` que nunca se cumplio.
     *
     * Sintoma: `buscarNombreArticulo(999999999)` avisa "Undefined variable $referencia"
     * y devuelve `null`. Causa raiz: `$referencia` se asigna solo dentro de `if ($result =
     * $smt->fetch_assoc())` (clases/articulos.php) pero se lee en el `return` final, fuera
     * del `if`, sin inicializarla antes. Mismo patron que `articulosPrecio()` (ver el otro
     * test de este fichero) y que `modificarArrayPedidos()` de `funciones.php` — recurrente
     * en este componente. Correccion propuesta: inicializar la variable a `null` antes del
     * `if`. Evidencia: este test, en rojo por el propio E_WARNING de PHP convertido en
     * error (no por una aserción), mientras el defecto siga sin corregirse por CC.
     */
    public function test_defecto_buscarNombreArticulo_sinMatchAvisaVariableIndefinida(): void
    {
        $this->articulos->buscarNombreArticulo(999999999);

        self::fail('No debería llegar aquí: la línea anterior debe fallar por "Undefined variable $referencia".');
    }

    public function test_articulosPrecio_conPrecioDevuelveLaFila(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo con precio propio');
        $this->siembra->precioYTienda($idArticulo, 12.10, 10.00);

        $fila = $this->articulos->articulosPrecio($idArticulo);

        self::assertSame('12.100000', $fila['pvpCiva']);
    }

    /**
     * Defecto: mismo patron que `buscarNombreArticulo()` — un articulo sin fila de precio
     * (plausible: uno recien creado, antes de fijarle precio) hace que la funcion avise de
     * variable indefinida en vez de devolver algo que distinga "sin precio" de un error.
     * Evidencia: este test, en rojo por el propio E_WARNING de PHP convertido en error (no
     * por una aserción), mientras el defecto siga sin corregirse por CC.
     */
    public function test_defecto_articulosPrecio_sinPrecioAvisaVariableIndefinida(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo sin precio propio');

        $this->articulos->articulosPrecio($idArticulo);

        self::fail('No debería llegar aquí: la línea anterior debe fallar por "Undefined variable $articulo".');
    }

    public function test_datosPrincipalesArticulo_noExigePrecioNiTienda(): void
    {
        $idArticulo = $this->siembra->articulo('Solo con nombre');

        $fila = $this->articulos->datosPrincipalesArticulo($idArticulo);

        self::assertSame('Solo con nombre', $fila['articulo_name']);
    }

    public function test_datosArticulosPrincipal_exigePrecioYTienda(): void
    {
        $idArticulo = $this->siembra->articulo('Con precio y tienda');
        $idTienda = $this->siembra->tiendaPorDefecto();
        $this->siembra->precioYTienda($idArticulo, 20.0, 18.0, $idTienda);

        $fila = $this->articulos->datosArticulosPrincipal($idArticulo, $idTienda);

        self::assertSame('Con precio y tienda', $fila['articulo_name']);
        self::assertSame('20.000000', $fila['pvpCiva']);
    }

    public function test_buscarPorNombre_filtraPorTienda(): void
    {
        $idArticulo = $this->siembra->articulo('Buscable por nombre');
        $idTienda = $this->siembra->tiendaPorDefecto();
        $this->siembra->precioYTienda($idArticulo, 5.0, 4.0, $idTienda);

        $resultado = $this->articulos->buscarPorNombre('Buscable', $idTienda);

        self::assertCount(1, $resultado);
    }

    /**
     * Defecto: `buscarPorNombre()` concatena `$valor` sin escapar dentro de un `LIKE` (CV-19,
     * DS-TPY-COM-005). Una entrada que cierra la comilla y comenta el resto de la condición anula el
     * filtro de tienda: la busqueda deja de estar acotada a la tienda pedida y alcanza a todo
     * `articulos`. Se conserva en rojo.
     */
    public function test_defecto_buscarPorNombreEntradaConCargaIgnoraElFiltroDeTienda(): void
    {
        $idTiendaA = $this->siembra->tiendaPorDefecto();
        $idTiendaB = $this->siembra->tienda('2025');
        $idArticuloA = $this->siembra->articulo('CargaTienda uno');
        $idArticuloB = $this->siembra->articulo('CargaTienda dos');
        $this->siembra->precioYTienda($idArticuloA, 5.0, 4.0, $idTiendaA);
        $this->siembra->precioYTienda($idArticuloB, 6.0, 5.0, $idTiendaB);

        $legitimo = $this->articulos->buscarPorNombre('CargaTienda', $idTiendaA);
        $conCarga = $this->articulos->buscarPorNombre('CargaTienda%" OR 1=1-- ', $idTiendaA);

        self::assertGreaterThan(
            count($legitimo),
            count($conCarga),
            'una entrada del operador no debe poder ignorar el filtro de tienda'
        );
    }

    public function test_getTipoArticulo_devuelveElTipoIndexadoPorId(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de peso', ['tipo' => 'peso']);

        $resultado = $this->articulos->getTipoArticulo($idArticulo);

        self::assertSame('peso', $resultado[$idArticulo]);
    }

    public function test_historicoCompras_filtraPorDocumentoDedondeYTipo(): void
    {
        $idArticulo = $this->siembra->articulo('Con historico');
        $this->siembra->historicoPrecio($idArticulo, 1.0, 2.0, 'compras', 55, ['tipo' => 'compra']);
        $this->siembra->historicoPrecio($idArticulo, 2.0, 3.0, 'ventas', 55, ['tipo' => 'venta']); // mismo NumDoc, otro Dedonde/Tipo

        $resultado = $this->articulos->historicoCompras(55, 'compras', 'compra');

        self::assertCount(1, $resultado);
        self::assertSame('2.0000', $resultado[0]['Nuevo']);
    }

    public function test_comprobarFechasHistorico_soloTraeLoPosteriorALaFecha(): void
    {
        $idArticulo = $this->siembra->articulo('Con historico por fecha');
        $this->siembra->historicoPrecio($idArticulo, 1.0, 2.0, 'compras', 1, ['fecha' => '2025-06-01']);
        $this->siembra->historicoPrecio($idArticulo, 2.0, 3.0, 'compras', 2, ['fecha' => '2026-06-01']);

        $resultado = $this->articulos->ComprobarFechasHistorico($idArticulo, '2026-01-01');

        self::assertCount(1, $resultado);
        self::assertSame('2', $resultado[0]['NumDoc']);
    }
}

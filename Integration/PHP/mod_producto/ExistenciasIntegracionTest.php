<?php

/**
 * La clase de existencias de `modulos/mod_producto/clases/ClaseArticulosStocks.php`
 * (`alArticulosStocks`): leer, crear y localizar la ficha de existencias de un producto en una
 * tienda, su mínimo, vaciar las de una tienda, regularizar y anotar la regularización.
 *
 * El movimiento del saldo —sumar o restar sobre el vigente, y no mover nada con una cantidad que no
 * es un número— tiene sus casos en `ArticulosStocksSaldoIntegracionTest` y
 * `ArticulosStocksFilaDuplicadaIntegracionTest`: aquí no se repite.
 *
 * La clase hereda de la clase base de acceso a datos: el caso le presta su conexión.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ExistenciasIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseArticulosStocks.php');
        $this->siembra = new Siembra($this->db);
    }

    /**
     * @comportamiento La ficha de existencias de un producto en una tienda se lee; sin ficha no se lee
     *   nada, salvo que se pida crearla, y entonces se crea a cero.
     */
    public function test_laFichaSeLeeYSinEllaSoloSeCreaSiSePide(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $conFicha = $this->siembra->articulo('Manzana');
        $this->siembra->existenciaRegistrada($conFicha, 4.0);
        $sinFicha = $this->siembra->articulo('Pera');

        $leida = \alArticulosStocks::leer($conFicha, $tienda);
        $sinCrear = \alArticulosStocks::leer($sinFicha, $tienda);
        $fichasSinCrear = $this->fichas($sinFicha);
        $creada = \alArticulosStocks::leer($sinFicha, $tienda, true);

        self::assertSame(4.0, (float) $leida['stockOn']);
        self::assertEmpty($sinCrear);
        self::assertSame(0, $fichasSinCrear);
        self::assertSame(0.0, (float) $creada['stockOn']);
        self::assertSame(1, $this->fichas($sinFicha));
    }

    /**
     * @comportamiento Una ficha existe si el producto tiene una en la tienda, se localiza por el producto
     *   y la tienda, y su existencia se lee por su número.
     */
    public function test_laFichaSeLocalizaPorSuProductoYSuTienda(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $id = $this->siembra->articulo('Manzana');
        $ficha = $this->siembra->existenciaRegistrada($id, 4.0);
        $sinFicha = $this->siembra->articulo('Pera');

        self::assertTrue(\alArticulosStocks::existe($id, $tienda));
        self::assertFalse(\alArticulosStocks::existe($sinFicha, $tienda));
        self::assertSame($ficha, (int) \alArticulosStocks::getIdbyArticulo($id, $tienda));
        self::assertSame(4.0, (float) \alArticulosStocks::leerStockXId($ficha));
    }

    /** @comportamiento El mínimo de existencias es el de la ficha; sin ficha, menos uno. */
    public function test_elMinimoEsElDeLaFichaYSinFichaMenosUno(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $id = $this->siembra->articulo('Manzana');
        $ficha = $this->siembra->existenciaRegistrada($id, 4.0);
        $this->db->query("UPDATE articulosStocks SET stockMin = 2 WHERE id = {$ficha}");
        $sinFicha = $this->siembra->articulo('Pera');

        self::assertSame(2.0, (float) \alArticulosStocks::getStockMin($id, $tienda));
        self::assertSame(-1, \alArticulosStocks::getStockMin($sinFicha, $tienda));
    }

    /** @comportamiento La ficha se crea con el mínimo, la existencia y el máximo indicados, y solo para un producto que existe. */
    public function test_laFichaSoloSeCreaParaUnProductoQueExiste(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $id = $this->siembra->articulo('Manzana');
        $noExiste = (int) $this->unValor('SELECT COALESCE(MAX(idArticulo), 0) + 1000 FROM articulos');

        $creada = \alArticulosStocks::crearStock([$id, $tienda, 1, 5, 9]);
        $noCreada = \alArticulosStocks::crearStock([$noExiste, $tienda, 0, 0, 0]);

        $fila = $this->db->query("SELECT stockMin, stockOn, stockMax FROM articulosStocks WHERE id = {$creada}")->fetch_assoc();
        self::assertSame([1.0, 5.0, 9.0], array_map('floatval', array_values($fila)));
        self::assertNull($noCreada);
        self::assertSame(0, $this->fichas($noExiste));
    }

    /** @comportamiento Vaciar las existencias de una tienda quita sus fichas y deja las de las demás. */
    public function test_vaciarLasExistenciasDeUnaTiendaDejaLasDeOtra(): void
    {
        $principal = $this->siembra->tiendaPorDefecto();
        $otra = $this->siembra->tienda($this->ano(), 'fisica');
        $id = $this->siembra->articulo('Manzana');
        $this->siembra->existenciaRegistrada($id, 4.0, $principal);
        $this->siembra->existenciaRegistrada($id, 7.0, $otra);

        \alArticulosStocks::limpiaStock($otra);

        self::assertSame(0, (int) $this->unValor("SELECT COUNT(*) FROM articulosStocks WHERE idTienda = {$otra}"));
        self::assertSame(4.0, (float) $this->unValor("SELECT stockOn FROM articulosStocks WHERE idArticulo = {$id} AND idTienda = {$principal}"));
    }

    /**
     * @comportamiento Regularizar suma a la existencia la diferencia indicada y apunta en la ficha el
     *   usuario y la fecha; un producto sin ficha la recibe con la diferencia.
     */
    public function test_regularizarSumaLaDiferenciaYApuntaQuienYCuando(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $usuario = $this->siembra->usuarioPorDefecto();
        $conFicha = $this->siembra->articulo('Manzana');
        $this->siembra->existenciaRegistrada($conFicha, 10.0);
        $sinFicha = $this->siembra->articulo('Pera');

        \alArticulosStocks::regularizaStock($conFicha, $tienda, -3, $usuario);
        \alArticulosStocks::regularizaStock($sinFicha, $tienda, 5, $usuario);

        $ficha = $this->db->query("SELECT stockOn, usuarioRegularizacion, DATE(fechaRegularizacion) AS dia FROM articulosStocks WHERE idArticulo = {$conFicha}")->fetch_assoc();
        self::assertSame(7.0, (float) $ficha['stockOn']);
        self::assertSame($usuario, (int) $ficha['usuarioRegularizacion']);
        self::assertSame(date('Y-m-d'), $ficha['dia']);
        self::assertSame(5.0, (float) $this->unValor("SELECT stockOn FROM articulosStocks WHERE idArticulo = {$sinFicha}"));
    }

    /** @comportamiento La regularización se anota con sus unidades y su usuario, activa y con la fecha del momento. */
    public function test_laRegularizacionSeAnotaActivaConLaFechaDelMomento(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $usuario = $this->siembra->usuarioPorDefecto();
        $id = $this->siembra->articulo('Manzana');

        $anotada = \alArticulosStocks::grabarRegularizacion([
            'idArticulo' => $id,
            'idTienda' => $tienda,
            'stockActual' => 10,
            'stockModif' => -3,
            'stockFinal' => 7,
            'stockOperacion' => K_STOCKARTICULO_SUMA,
            'idUsuario' => $usuario,
        ]);

        $fila = $this->db->query("SELECT idArticulo, stockFinal, idUsuario, estado, DATE(fechaRegularizacion) AS dia FROM stocksRegularizacion WHERE id = {$anotada}")->fetch_assoc();
        self::assertSame($id, (int) $fila['idArticulo']);
        self::assertSame(7.0, (float) $fila['stockFinal']);
        self::assertSame($usuario, (int) $fila['idUsuario']);
        self::assertSame(K_STOCKREGULARIZACION_ESTADO_ACTIVO, $fila['estado']);
        self::assertSame(date('Y-m-d'), $fila['dia']);
    }

    private function fichas(int $idArticulo): int
    {
        return (int) $this->unValor("SELECT COUNT(*) FROM articulosStocks WHERE idArticulo = {$idArticulo}");
    }

    private function unValor(string $sql): ?string
    {
        $fila = $this->db->query($sql)->fetch_row();

        return $fila === null ? null : (string) $fila[0];
    }
}

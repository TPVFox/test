<?php

/**
 * La clase de regularizaciones de `modulos/mod_producto/clases/ClaseRegularizaciones.php`
 * (`alArticulosRegularizacion`): leer las regularizaciones, saber si un producto tiene una,
 * localizarla, quitarlas y anotar una nueva.
 *
 * Solo la lista de regularización la llama, para leer las activas con el nombre de su producto. Las
 * peticiones de fondo anotan la regularización con la clase de existencias. El resto de métodos no
 * los llama ningún fichero: se prueban igual, porque siguen en la clase.
 *
 * La clase hereda de la clase base de acceso a datos: el caso le presta su conexión.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class RegularizacionesIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_producto/clases/ClaseRegularizaciones.php');
        $this->siembra = new Siembra($this->db);
    }

    /** @comportamiento Las regularizaciones activas se leen con el nombre de su producto; las borradas no. */
    public function test_lasRegularizacionesActivasSeLeenConElNombreDeSuProducto(): void
    {
        $activa = $this->siembra->articulo('Manzana');
        $borrada = $this->siembra->articulo('Pera');
        $this->siembra->regularizacion($activa, '2026-03-01', -2);
        $otra = $this->siembra->regularizacion($borrada, '2026-03-01', 4);
        $this->db->query('UPDATE stocksRegularizacion SET estado = ' . K_STOCKREGULARIZACION_ESTADO_BORRADO . " WHERE id = {$otra}");

        $leidas = (new \alArticulosRegularizacion())->leerTodos(['regu.estado=' . K_STOCKREGULARIZACION_ESTADO_ACTIVO], ['regu.*', 'arts.articulo_name']);
        $nuestras = array_values(array_filter($leidas, fn ($fila) => in_array((int) $fila['idArticulo'], [$activa, $borrada], true)));

        self::assertSame(['Manzana'], array_column($nuestras, 'articulo_name'));
        self::assertSame(-2.0, (float) $nuestras[0]['stockModif']);
    }

    /** @comportamiento Una regularización se localiza por su producto y su tienda; sin ninguna, no se localiza nada. */
    public function test_unaRegularizacionSeLocalizaPorSuProductoYSuTienda(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $id = $this->siembra->articulo('Manzana');
        $regularizacion = $this->siembra->regularizacion($id, '2026-03-01', -2);
        $sinRegularizar = $this->siembra->articulo('Pera');

        self::assertSame($regularizacion, (int) \alArticulosRegularizacion::getIdbyArticulo($id, $tienda));
        self::assertEmpty(\alArticulosRegularizacion::getIdbyArticulo($sinRegularizar, $tienda));
    }

    /** @comportamiento Una regularización se anota activa y con la fecha del momento. */
    public function test_unaRegularizacionSeAnotaActivaConLaFechaDelMomento(): void
    {
        $id = $this->siembra->articulo('Manzana');

        $anotada = \alArticulosRegularizacion::grabar([
            'idArticulo' => $id,
            'idTienda' => $this->siembra->tiendaPorDefecto(),
            'stockActual' => 10,
            'stockModif' => -3,
            'stockFinal' => 7,
            'stockOperacion' => K_STOCKARTICULO_SUMA,
            'idUsuario' => $this->siembra->usuarioPorDefecto(),
        ]);

        $fila = $this->db->query("SELECT estado, DATE(fechaRegularizacion) AS dia FROM stocksRegularizacion WHERE id = {$anotada}")->fetch_assoc();
        self::assertSame(K_STOCKREGULARIZACION_ESTADO_ACTIVO, $fila['estado']);
        self::assertSame(date('Y-m-d'), $fila['dia']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseRegularizaciones.php:22-28
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Leer las regularizaciones de un producto en una tienda termina con un error de la
     *   base, y deja avisos de PHP.
     * @que-deberia-ocurrir Que devuelva las regularizaciones de ese producto en esa tienda.
     * @por-que-ocurre El método no recibe el producto ni la tienda, y compone la condición con dos
     *   variables que no existen: la sentencia queda sin valores.
     * @como-deberia-funcionar Recibir el producto y la tienda, y leer con ellos.
     */
    public function test_lasRegularizacionesDeUnProductoEnUnaTiendaSeLeen(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $id = $this->siembra->articulo('Manzana');
        $regularizacion = $this->siembra->regularizacion($id, '2026-03-01', -2);

        $leidas = \alArticulosRegularizacion::leer($id, $tienda);

        self::assertSame([$regularizacion], array_map('intval', array_column($leidas, 'id')));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseRegularizaciones.php:43-50
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Preguntar si un producto tiene una regularización en una tienda termina con un
     *   error de la base.
     * @que-deberia-ocurrir Que responda que sí cuando la tiene.
     * @por-que-ocurre El límite de una fila se pasa en el lugar de las tablas que se unen, y la sentencia
     *   pide unir la tabla «1». Además, con dos regularizaciones respondería que no: compara el recuento
     *   con uno.
     * @como-deberia-funcionar Pasar el límite en su lugar y responder que sí con una o más.
     */
    public function test_saberSiUnProductoTieneUnaRegularizacionEnUnaTienda(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $id = $this->siembra->articulo('Manzana');
        $this->siembra->regularizacion($id, '2026-03-01', -2);

        self::assertTrue(\alArticulosRegularizacion::existe('stocksRegularizacion', $id, $tienda));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseRegularizaciones.php:68-73
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Quitar las regularizaciones de un producto en una tienda borra las existencias de
     *   todos los productos de esa tienda.
     * @que-deberia-ocurrir Que las existencias de la tienda queden como estaban.
     * @por-que-ocurre La sentencia borra en la tabla de existencias, no en la de regularizaciones, y no
     *   usa el producto.
     * @como-deberia-funcionar Borrar en la tabla de regularizaciones, solo las del producto y la tienda.
     */
    public function test_quitarLasRegularizacionesDeUnProductoNoVaciaLasExistenciasDeLaTienda(): void
    {
        $tienda = $this->siembra->tiendaPorDefecto();
        $id = $this->siembra->articulo('Manzana');
        $otro = $this->siembra->articulo('Pera');
        $this->siembra->existenciaRegistrada($id, 4.0);
        $this->siembra->existenciaRegistrada($otro, 7.0);
        $this->siembra->regularizacion($id, '2026-03-01', -2);

        \alArticulosRegularizacion::limpia($id, $tienda);

        self::assertSame(2, (int) $this->db->query("SELECT COUNT(*) FROM articulosStocks WHERE idArticulo IN ({$id}, {$otro})")->fetch_row()[0]);
    }
}

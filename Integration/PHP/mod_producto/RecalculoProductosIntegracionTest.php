<?php

/**
 * El recálculo de precios de `mod_productos` (`Recalculo_precios.php` con su plantilla
 * `template/view_recalculo.php`): los productos de un albarán de compra cuyo coste cambió, con su
 * precio recomendado; guardar los precios aceptados, con su línea en el histórico de precios y su envío
 * a las balanzas; y la vista guardada, que sale de la caché del recálculo.
 *
 * Cada pantalla se pinta en un PHP aparte, desde la carpeta de `mod_productos`, con la sesión del
 * usuario de pruebas, su tienda y los permisos del módulo concedidos —o sin sesión, si el caso lo
 * pide—, y una transacción que se deshace al terminar. El caso siembra un proveedor (`:proveedor`), un
 * albarán suyo (`:albaran`), sus productos (`:p1`, `:p2`…), las líneas del histórico (`:h1`, `:h2`…) y
 * las balanzas (`:b1`…). Al terminar, el PHP aparte responde lo que el caso pide leer de la base, la
 * caché del recálculo y el fichero de cada balanza.
 *
 * La carpeta temporal de la configuración, donde el guardado escribe el fichero de cada balanza, se
 * cambia por una propia del caso, que se borra al terminar: en ella no hay ningún driver de balanza, y
 * ninguno se ejecuta. La caché que escribe el guardado se borra al terminar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class RecalculoProductosIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    private int $idUsuario;

    private int $idTienda;

    private string $carpeta = '';

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->carpeta = sys_get_temp_dir() . '/tpvfox-recalculo-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        $this->borrar($this->carpeta);
        parent::tearDown();
    }

    /**
     * @comportamiento El recálculo de un albarán enseña sus datos y, de cada producto con el coste
     *   pendiente de revisar, su número, su nombre, la referencia del proveedor, el coste nuevo y el
     *   anterior, el beneficio, el IVA, el precio actual y el recomendado —el coste nuevo con su IVA y su
     *   beneficio, con dos decimales—, que se puede cambiar, y el botón de quitarlo del recálculo.
     */
    public function test_laPantallaEnsenaCadaProductoPendienteConSuPrecioRecomendado(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], [], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI')]);
        $id = $pantalla['ids'][':p1'];
        $albaran = $pantalla['ids'][':albaran'];

        self::assertStringContainsString('Recalculo precios PVP de albaran: ' . $albaran, $pantalla['html']);
        self::assertStringContainsString('value="Frutas del Norte"', $pantalla['html']);
        self::assertStringContainsString('value="2026-10-01"', $pantalla['html']);
        self::assertMatchesRegularExpression('/<tr id="Row1" class="">\s*<td>' . $id . '<\/td>\s*<td>Kiwi<\/td>\s*<td>REF-KIWI<\/td>\s*<td>2\.0000<\/td>\s*<td>1\.0000<\/td>\s*<td>10\.00<\/td>\s*<td>21\.00<\/td>\s*<td>2\.4200<\/td>/', $pantalla['html']);
        self::assertMatchesRegularExpression('/name="pvpRecomendado_1"[^>]*value="2\.66">/', $pantalla['html']);
        self::assertStringContainsString("cambiarEstadoRecalculo({$id}, 'albaran', {$albaran}, 'compras', 1, 'eliminar')", $pantalla['html']);
        self::assertStringNotContainsString('onclick="imprimirRecalculo(', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /** @comportamiento Sin albarán en la dirección, el recálculo manda al listado de albaranes y no pinta nada. */
    public function test_sinAlbaranNoSePintaNada(): void
    {
        $pantalla = $this->pintar([]);

        self::assertSame('', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @comportamiento Un producto descartado sale tachado, con el precio sin poder cambiarse y el botón de
     *   recuperarlo; uno ya revisado no sale; uno con un recálculo posterior pasa a «Sin revisar»: sale con
     *   el precio sin poder cambiarse, su aviso y sin botón.
     */
    public function test_cadaProductoSaleSegunSuEstado(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], [], [
            ...$this->albaran(),
            ...$this->producto('Kiwi'), $this->historico(1, 2, 'Sin Cambios'),
            ...$this->producto('Pera'), $this->historico(1, 2, 'Revisado'),
            ...$this->producto('Uva'), $this->historico(1, 2), $this->historico(1, 3, 'Pendiente', '2026-10-05 10:00:00', '0'),
        ], ['uva' => "SELECT estado FROM historico_precios WHERE idArticulo = :p3 AND NumDoc = :albaran"]);
        $kiwi = $pantalla['ids'][':p1'];
        $albaran = $pantalla['ids'][':albaran'];

        self::assertMatchesRegularExpression('/<tr id="Row1" class="tachado">\s*<td>' . $kiwi . '<\/td>/', $pantalla['html']);
        self::assertMatchesRegularExpression('/name="pvpRecomendado_1"[^>]*value="2\.66" disabled>/', $pantalla['html']);
        self::assertStringContainsString("cambiarEstadoRecalculo({$kiwi}, 'albaran', {$albaran}, 'compras', 1, 'retorno')", $pantalla['html']);
        self::assertStringNotContainsString('<td>Pera</td>', $pantalla['html']);
        self::assertMatchesRegularExpression('/<tr id="Row2" class="">\s*<td>' . $pantalla['ids'][':p3'] . '<\/td>\s*<td>Uva<\/td>/', $pantalla['html']);
        self::assertMatchesRegularExpression('/name="pvpRecomendado_2"[^>]*disabled>\s*<span class="glyphicon glyphicon-ban-circle text-danger"/', $pantalla['html']);
        self::assertSame('Sin revisar', $pantalla['leido']['uva'][0]['estado']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @comportamiento Guardar pone al producto pendiente el precio de venta aceptado, con IVA y sin él;
     *   apunta en el histórico de precios el anterior y el nuevo, el albarán, el usuario de la sesión y si
     *   fue el recomendado o a mano; deja revisada la línea del albarán, y guarda en la caché lo guardado,
     *   que la pantalla enseña ya sin poder guardarse otra vez, con el botón de imprimir.
     */
    public function test_guardarFijaElPrecioYLoApuntaEnElHistorico(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '3.00'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)], [
            'precio' => 'SELECT pvpCiva, pvpSiva FROM articulosPrecios WHERE idArticulo = :p1',
            'historico' => 'SELECT Antes, Nuevo, NumDoc, Dedonde, Tipo, idUsuario, estado FROM historico_precios WHERE idArticulo = :p1 ORDER BY id',
        ]);
        $albaran = $pantalla['ids'][':albaran'];

        self::assertSame([['pvpCiva' => '3.000000', 'pvpSiva' => '2.479339']], $pantalla['leido']['precio']);
        self::assertSame('Revisado', $pantalla['leido']['historico'][0]['estado']);
        self::assertSame(['Antes' => '2.4200', 'Nuevo' => '3.0000', 'NumDoc' => $albaran, 'Dedonde' => 'Recalculo', 'Tipo' => 'Productos', 'idUsuario' => (string) $this->idUsuario, 'estado' => 'A mano'], $pantalla['leido']['historico'][1]);
        self::assertStringContainsString('Precios guardados correctamente', $pantalla['html']);
        self::assertStringContainsString('disabled title="Ya guardado"', $pantalla['html']);
        self::assertStringContainsString("onclick=\"imprimirRecalculo({$albaran})\"", $pantalla['html']);
        self::assertMatchesRegularExpression('/<td>Kiwi<\/td>.*?<td>2\.4200<\/td>\s*<td><strong>3\.0000<\/strong><\/td>/s', $pantalla['html']);
        self::assertStringContainsString('<pvpAnterior>2.4200</pvpAnterior><pvpNuevo>3.0000</pvpNuevo>', (string) $pantalla['cache']);
        self::assertSame('', $pantalla['errores']);
    }

    /** @comportamiento Un precio aceptado igual al actual no cambia el precio ni se apunta en el histórico. */
    public function test_unPrecioIgualAlActualNoSeCambiaNiSeApunta(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '2.42'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)], [
            'historico' => 'SELECT estado FROM historico_precios WHERE idArticulo = :p1 ORDER BY id',
        ]);

        self::assertSame([['estado' => 'Revisado']], $pantalla['leido']['historico']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @comportamiento Al guardar, un producto descartado no cambia aunque llegue su precio; el pendiente
     *   que va detrás toma el suyo.
     */
    public function test_alGuardarUnProductoDescartadoNoCambia(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '9.00', 'pvpRecomendado_2' => '3.00'], [
            ...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2, 'Sin Cambios'), ...$this->producto('Uva'), $this->historico(1, 2),
        ], ['precios' => 'SELECT idArticulo, pvpCiva FROM articulosPrecios WHERE idArticulo IN (:p1, :p2) ORDER BY idArticulo']);

        self::assertSame('2.420000', $pantalla['leido']['precios'][0]['pvpCiva']);
        self::assertSame('3.000000', $pantalla['leido']['precios'][1]['pvpCiva']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @comportamiento Con el recálculo ya guardado, la pantalla enseña lo guardado desde la caché, con
     *   casillas para marcar los productos, el botón de imprimir y el de guardar desactivado; un guardado
     *   que llegue no cambia nada.
     */
    public function test_conElRecalculoYaGuardadoNoSeGuardaOtraVez(): void
    {
        $cache = '<?xml version="1.0" encoding="UTF-8"?><recalculo><idAlbaran>:albaran</idAlbaran><fecha>2026-10-01</fecha><idProveedor>:proveedor</idProveedor>'
            . '<nombreProveedor>Frutas del Norte</nombreProveedor><productos><producto><idArticulo>:p1</idArticulo><nombre>Kiwi</nombre><referencia>R1</referencia>'
            . '<costeAnterior>1</costeAnterior><costeNuevo>2</costeNuevo><pvpAnterior>2.4200</pvpAnterior><pvpNuevo>3.0000</pvpNuevo></producto></productos></recalculo>';
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '5.00'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)], [
            'precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1',
        ], true, $cache);
        $id = $pantalla['ids'][':p1'];

        self::assertSame('2.420000', $pantalla['leido']['precio'][0]['pvpCiva']);
        self::assertStringContainsString('disabled title="Ya guardado"', $pantalla['html']);
        self::assertStringContainsString('onclick="imprimirRecalculo(' . $pantalla['ids'][':albaran'] . ')"', $pantalla['html']);
        self::assertMatchesRegularExpression('/value="' . $id . '" class="chk-articulo"><\/td>\s*<td>' . $id . '<\/td>\s*<td>Kiwi<\/td>\s*<td>R1<\/td>\s*<td>1<\/td>\s*<td>2<\/td>\s*<td>2\.4200<\/td>\s*<td><strong>3\.0000<\/strong>/', $pantalla['html']);
        self::assertStringNotContainsString('Precios guardados correctamente', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /** @comportamiento El nombre del producto y el del proveedor se escriben como texto, antes y después de guardar. */
    public function test_losNombresSeEscribenComoTexto(): void
    {
        $preparacion = [...$this->albaran(self::SCRIPT), ...$this->producto(self::SCRIPT), $this->historico(1, 2)];
        $antes = $this->pintar(['id' => ':albaran'], [], $preparacion);
        $despues = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '3.00'], $preparacion);

        foreach ([$antes, $despues] as $pantalla) {
            self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
            self::assertStringContainsString('<td>' . htmlspecialchars(self::SCRIPT) . '</td>', $pantalla['html']);
            self::assertStringContainsString('value="' . htmlspecialchars(self::SCRIPT) . '"', $pantalla['html']);
        }
    }

    /**
     * @comportamiento Guardar un producto al peso con referencia de tienda escribe, en la carpeta de cada
     *   balanza de envío, el registro del producto con su referencia, su tecla en esa balanza, su nombre y
     *   su precio nuevo.
     */
    public function test_unProductoAlPesoSeEnviaALaBalanza(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [
            ...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7),
        ], [], true, null, true);
        $fichero = $pantalla['balanzas']['Balanzadepruebas' . $pantalla['ids'][':b1']] ?? '';

        self::assertStringContainsString('A000123007Queso', $fichero);
        self::assertStringContainsString('00001500', $fichero);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:75
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Un precio aceptado escrito con coma decimal, como se escribe en castellano, se guarda
     *   sin sus decimales: 2,50 queda en 2,00.
     * @que-deberia-ocurrir Que se guarde 2,50, o que se rechace con un aviso.
     * @por-que-ocurre El precio recibido se convierte a número tal cual llega, y la conversión se detiene en
     *   la coma.
     * @como-deberia-funcionar Aceptar la coma como separador decimal al recibir el precio, o validarlo y
     *   avisar.
     */
    public function test_unPrecioConComaDecimalSeGuardaConSusDecimales(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '2,50'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)], [
            'precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1',
        ]);

        self::assertSame('2.500000', $pantalla['leido']['precio'][0]['pvpCiva']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:75
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:84
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Un precio aceptado vacío deja el producto con el precio de venta a cero, y lo apunta
     *   así en el histórico.
     * @que-deberia-ocurrir Que un precio vacío o que no es un número no cambie el precio, y se avise.
     * @por-que-ocurre El precio recibido se convierte a número sin validarlo: vacío, o sin llegar, es cero,
     *   y cero es distinto del precio actual.
     * @como-deberia-funcionar Validar el precio recibido antes de guardarlo: un número mayor que cero.
     */
    public function test_unPrecioVacioNoDejaElProductoACero(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => ''], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)], [
            'precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1',
        ]);

        self::assertSame('2.420000', $pantalla['leido']['precio'][0]['pvpCiva']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:87-88
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Aceptar el precio recomendado tal como lo enseña la pantalla lo apunta en el
     *   histórico como puesto a mano.
     * @que-deberia-ocurrir Que se apunte como recomendado.
     * @por-que-ocurre La pantalla enseña el recomendado con dos decimales, y el guardado lo compara con el
     *   recomendado sin redondear.
     * @como-deberia-funcionar Comparar con el recomendado redondeado a dos decimales, como se enseña.
     */
    public function test_aceptarElPrecioRecomendadoLoApuntaComoRecomendado(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '2.66'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)], [
            'historico' => "SELECT estado FROM historico_precios WHERE idArticulo = :p1 AND Dedonde = 'Recalculo'",
        ]);

        self::assertSame('Recomendado', $pantalla['leido']['historico'][0]['estado'] ?? null);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:90
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:103-104
     * @codigo-afectado clases/articulos.php:157-163
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un precio aceptado de mil euros o más termina el guardado con un error de la base,
     *   después de apuntarlo en el histórico: el histórico dice que el precio cambió y el producto sigue
     *   con el anterior.
     * @que-deberia-ocurrir Que el precio se guarde, o que nada se guarde y se avise.
     * @por-que-ocurre El precio sin IVA se escribe con separador de miles, que la sentencia del precio no
     *   admite; el histórico se apunta antes, y ningún paso del guardado está en una transacción.
     * @como-deberia-funcionar Escribir el precio sin separador de miles, y guardar el histórico y el precio
     *   juntos, o ninguno.
     */
    public function test_unPrecioDeMilEurosOMasSeGuarda(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '1210'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)], [
            'precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1',
        ]);

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
        self::assertSame('1210.000000', $pantalla['leido']['precio'][0]['pvpCiva']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:77-78
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:104
     * @codigo-afectado clases/articulos.php:141-149
     * @codigo-afectado clases/articulos.php:157-163
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Guardar el recálculo cambia el precio del producto en todas las tiendas, y toma como
     *   precio actual el de cualquiera de ellas.
     * @que-deberia-ocurrir Que el recálculo lea y cambie el precio de la tienda de la sesión, como el resto
     *   del producto.
     * @por-que-ocurre La lectura y la escritura del precio buscan solo por el producto, sin la tienda.
     * @como-deberia-funcionar Leer y escribir el precio de la tienda de la sesión.
     */
    public function test_guardarNoCambiaElPrecioDeOtraTienda(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '3.00'], [
            ...$this->albaran(), ...$this->producto('Kiwi'), 'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 9.68, 8, :tienda + 1000)', $this->historico(1, 2),
        ], ['otra' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1 AND idTienda = :tienda + 1000']);

        self::assertSame('9.680000', $pantalla['leido']['otra'][0]['pvpCiva']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:47
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Sin el permiso de guardar el recálculo, el guardado cambia los precios igual.
     * @que-deberia-ocurrir Que sin ese permiso no se guarde, y el botón no se ofrezca.
     * @por-que-ocurre La pantalla no consulta el permiso de guardar que declara su módulo.
     * @como-deberia-funcionar Consultar el permiso antes de guardar y antes de pintar el botón.
     */
    public function test_sinElPermisoDeGuardarNoSeGuarda(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '3.00'], [
            ...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2),
            "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_productos' AND vista = 'Recalculo_precios.php' AND accion = 'guardarRecalculo'",
        ], ['precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1']);

        self::assertSame('2.420000', $pantalla['leido']['precio'][0]['pvpCiva']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:23
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:47
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Sin sesión, un guardado que llega al recálculo se intenta y termina con un error de
     *   la base, en lugar de pedir el acceso.
     * @que-deberia-ocurrir Que sin sesión no se intente guardar, y la pantalla pida el acceso.
     * @por-que-ocurre El guardado se hace antes de pintar la cabecera, que es la que pide la sesión, y
     *   apunta en el histórico un usuario que no hay.
     * @como-deberia-funcionar Comprobar la sesión antes de guardar.
     */
    public function test_sinSesionNoSeIntentaGuardar(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '3.00'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)], [], false);

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
        self::assertStringContainsString('name="usr"', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:132
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Al enviar a la balanza un precio recalculado, el registro con los datos del producto
     *   —tipo, IVA y sección— lleva como código el número de la línea del histórico, no la referencia del
     *   producto que lleva el registro del precio.
     * @que-deberia-ocurrir Que los dos registros lleven la referencia del producto, como en el resto de
     *   envíos a la balanza.
     * @por-que-ocurre El código del segundo registro se toma del número de la línea del histórico.
     * @como-deberia-funcionar Tomar la referencia de la tienda del producto para los dos registros.
     */
    public function test_laBalanzaRecibeLaReferenciaDelProductoEnSusDosRegistros(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [
            ...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7),
        ], [], true, null, true);
        $fichero = $pantalla['balanzas']['Balanzadepruebas' . $pantalla['ids'][':b1']] ?? '';

        self::assertSame(2, substr_count($fichero, '000123'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:174-200
     * @codigo-afectado modulos/mod_productos/template/view_recalculo.php:18-23
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Si el precio no llega a una balanza —no se puede escribir su fichero o su driver no
     *   se ejecuta—, la pantalla dice igual que los precios se guardaron correctamente, sin ningún aviso.
     * @que-deberia-ocurrir Que la pantalla diga qué balanzas no recibieron el precio.
     * @por-que-ocurre El guardado anota el resultado de cada balanza, pero la pantalla no lo enseña.
     * @como-deberia-funcionar Enseñar el resultado del envío a cada balanza junto al aviso del guardado.
     */
    public function test_siLaBalanzaNoRecibeElPrecioLaPantallaLoDice(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [
            ...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7),
        ]);
        $visible = strip_tags((string) preg_replace('#<script\b.*?</script>#s', '', $pantalla['html']));

        self::assertStringContainsString('Precios guardados correctamente', $visible);
        self::assertStringContainsStringIgnoringCase('balanza', $visible);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:107-109
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Guardar un producto al peso con referencia de tienda que no tiene tecla en ninguna
     *   balanza deja un aviso de PHP.
     * @que-deberia-ocurrir Que se envíe a las balanzas de envío sin aviso.
     * @por-que-ocurre La consulta de las balanzas del producto devuelve nada en lugar de una lista vacía, y
     *   el guardado la recorre sin mirarlo.
     * @como-deberia-funcionar Tomar como lista vacía las balanzas de un producto que no tiene ninguna.
     */
    public function test_unProductoAlPesoSinTeclaEnNingunaBalanzaNoDaAvisos(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [
            ...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'),
            "INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES ('Balanza de pruebas', 'Prueba', 'no', 0, 50, '127.0.0.1', 0)",
        ], [], true, null, true);

        self::assertStringNotContainsString('Warning', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:215
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:219
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Tras guardar, la vista guardada del recálculo, y su impresión, salen sin la
     *   referencia del proveedor de cada producto.
     * @que-deberia-ocurrir Que lleven la referencia del proveedor, como antes de guardar.
     * @por-que-ocurre La caché toma la referencia de la tienda de la ficha del proveedor del producto, que
     *   no la tiene: la del proveedor está en otro campo.
     * @como-deberia-funcionar Tomar la referencia del proveedor al escribir la caché.
     */
    public function test_laVistaGuardadaEnsenaLaReferenciaDelProveedor(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '3.00'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI')]);

        self::assertStringContainsString('<referencia>REF-KIWI</referencia>', (string) $pantalla['cache']);
        self::assertMatchesRegularExpression('/<td>Kiwi<\/td>\s*<td>REF-KIWI<\/td>/', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:35-39
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy El recálculo de un albarán que no existe, pedido por su dirección, termina con avisos
     *   y un error de la base.
     * @que-deberia-ocurrir Que diga que el albarán no existe, o vuelva al listado de albaranes.
     * @por-que-ocurre La pantalla usa los datos del albarán sin mirar si lo encontró.
     * @como-deberia-funcionar Comprobar que el albarán existe antes de leer su proveedor y su histórico.
     */
    public function test_unAlbaranQueNoExisteNoTerminaLaPagina(): void
    {
        $pantalla = $this->pintar(['id' => '999999'], [], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)]);

        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/template/view_recalculo.php:61
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy El botón de eliminar la selección, en el panel de las acciones a los seleccionados,
     *   envía además el formulario del recálculo: la página se vuelve a cargar mientras se vacía la
     *   selección.
     * @que-deberia-ocurrir Que solo vacíe la selección, como los demás botones del panel.
     * @por-que-ocurre El botón está dentro del formulario y no dice que es un botón sin envío: por omisión,
     *   envía el formulario.
     * @como-deberia-funcionar Declarar el botón como de tipo botón.
     */
    public function test_eliminarLaSeleccionNoEnviaElFormulario(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], [], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)]);

        self::assertMatchesRegularExpression('/<button type="button"[^>]*onclick="limpiarSeleccionRecalculo\(\)"/', $pantalla['html']);
    }

    /**
     * @comportamiento Un número de albarán con una condición añadida en la dirección se toma como el número
     *   que lleva delante: la pantalla enseña solo las líneas de ese albarán, no las de los demás.
     */
    public function test_unNumeroDeAlbaranConUnaCondicionNoEnsenaOtrosAlbaranes(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran OR 1=1'], [], [
            ...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2),
            ...$this->albaran('Frutas del Sur'), ...$this->producto('Pera'), $this->historico(1, 2),
        ]);

        self::assertStringContainsString('<td>Pera</td>', $pantalla['html']);
        self::assertStringNotContainsString('<td>Kiwi</td>', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /** @comportamiento Un producto sin referencia del proveedor del albarán sale con la referencia vacía, sin avisos. */
    public function test_unProductoSinReferenciaDelProveedorSaleSinElla(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], [], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)]);

        self::assertMatchesRegularExpression('/<td>Kiwi<\/td>\s*<td><\/td>\s*<td>2\.0000<\/td>/', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:23
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:43
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Sin sesión, la pantalla pide el acceso sin enseñar el recálculo, pero deja avisos de
     *   PHP: el del usuario de la sesión, que no hay, el del nombre de la caché y los del menú del acceso.
     * @que-deberia-ocurrir Que pida el acceso sin avisos, como las demás pantallas.
     * @por-que-ocurre La pantalla toma el usuario de la sesión sin mirar si lo hay, y deja sin usuario al
     *   menú, al que el arranque había dado el de invitado.
     * @como-deberia-funcionar Usar el usuario que deja el arranque, o comprobar la sesión antes de leer nada.
     */
    public function test_sinSesionLaPantallaPideElAccesoSinAvisos(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], [], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)], [], false);

        self::assertStringNotContainsString('<td>Kiwi</td>', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/articulos.php:144
     * @codigo-afectado modulos/mod_productos/template/view_recalculo.php:172
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Con el producto a precios distintos en dos tiendas, la pantalla enseña como precio
     *   actual el de la tienda de número más bajo, que puede no ser la de la sesión.
     * @que-deberia-ocurrir Que enseñe el precio de la tienda de la sesión, el que el recálculo cambia.
     * @por-que-ocurre La lectura del precio busca solo por el producto, sin la tienda, y toma la primera
     *   fila, que por el índice de la tabla es la de la tienda de número más bajo.
     * @como-deberia-funcionar Leer el precio de la tienda de la sesión.
     */
    public function test_laPantallaEnsenaElPrecioActualDeLaTiendaDeLaSesion(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], [], [
            ...$this->albaran(), $this->producto('Kiwi')[0],
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 9.68, 8, :tienda - 1)',
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 2.42, 2, :tienda)',
            $this->historico(1, 2),
        ]);

        self::assertMatchesRegularExpression('/<td>Kiwi<\/td>(\s*<td>[^<]*<\/td>){5}\s*<td>2\.4200<\/td>/', $pantalla['html']);
    }

    /**
     * @comportamiento Detrás de un producto descartado y de uno revisado, el precio de cada fila se guarda en
     *   su producto: el guardado cuenta las filas como la pantalla. El descartado no cambia de precio, y su
     *   línea queda revisada con las demás.
     */
    public function test_cadaPrecioSeGuardaEnSuProductoDetrasDeUnDescartadoYUnRevisado(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_2' => '3.30'], [
            ...$this->albaran(),
            ...$this->producto('Kiwi'), $this->historico(1, 2, 'Sin Cambios'),
            ...$this->producto('Pera'), $this->historico(1, 2, 'Revisado'),
            ...$this->producto('Uva'), $this->historico(1, 2),
        ], [
            'precios' => 'SELECT idArticulo, pvpCiva FROM articulosPrecios WHERE idArticulo IN (:p1, :p2, :p3) ORDER BY idArticulo',
            'kiwi' => 'SELECT estado FROM historico_precios WHERE id = :h1',
        ]);

        self::assertSame(['2.420000', '2.420000', '3.300000'], array_column($pantalla['leido']['precios'], 'pvpCiva'));
        self::assertSame('Revisado', $pantalla['leido']['kiwi'][0]['estado']);
    }

    /** @comportamiento Al guardar, un producto con un recálculo posterior no cambia de precio, y su línea sigue «Sin revisar». */
    public function test_alGuardarUnProductoSinRevisarNoCambiaNiSeRevisa(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar'], [
            ...$this->albaran(), ...$this->producto('Uva'), $this->historico(1, 2), $this->historico(1, 3, 'Pendiente', '2026-10-05 10:00:00', '0'),
        ], [
            'precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1',
            'linea' => 'SELECT estado FROM historico_precios WHERE id = :h1',
        ]);

        self::assertSame('2.420000', $pantalla['leido']['precio'][0]['pvpCiva']);
        self::assertSame('Sin revisar', $pantalla['leido']['linea'][0]['estado']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:75
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Una línea pendiente cuyo precio no llega en el formulario —una línea que entró en el
     *   albarán después de pintar la pantalla— deja el producto con el precio de venta a cero, y lo apunta
     *   así en el histórico.
     * @que-deberia-ocurrir Que una línea sin precio no se guarde, y la pantalla lo diga.
     * @por-que-ocurre El guardado toma como cero el precio de una fila que no llega.
     * @como-deberia-funcionar Saltar la línea cuyo precio no llega y avisarlo.
     */
    public function test_unaLineaSinSuPrecioEnElFormularioNoDejaElProductoACero(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)], [
            'precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1',
        ]);

        self::assertSame('2.420000', $pantalla['leido']['precio'][0]['pvpCiva']);
    }

    /**
     * @comportamiento Dos líneas pendientes del mismo producto en el albarán se guardan una tras otra: cada
     *   cambio se apunta desde el precio que dejó el anterior, y queda el de la última.
     */
    public function test_dosLineasDelMismoProductoApuntanCadaCambioSobreElAnterior(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '3.00', 'pvpRecomendado_2' => '4.00'], [
            ...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->historico(2, 3),
        ], [
            'precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1',
            'cambios' => "SELECT Antes, Nuevo FROM historico_precios WHERE idArticulo = :p1 AND Dedonde = 'Recalculo' ORDER BY id",
        ]);

        self::assertSame('4.000000', $pantalla['leido']['precio'][0]['pvpCiva']);
        self::assertSame([['Antes' => '2.4200', 'Nuevo' => '3.0000'], ['Antes' => '3.0000', 'Nuevo' => '4.0000']], $pantalla['leido']['cambios']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/funciones.php:967
     * @codigo-afectado modulos/mod_producto/funciones.php:974-977
     * @codigo-afectado clases/articulos.php:224
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy El recálculo de un albarán ya guardado, abierto otra vez sin su caché —por otro
     *   usuario, o desde el recálculo de `mod_producto`—, pasa sus líneas revisadas a «Sin revisar» en el
     *   histórico y las vuelve a enseñar: el cambio de precio que apuntó el propio recálculo cuenta como uno
     *   posterior.
     * @que-deberia-ocurrir Que las líneas revisadas sigan revisadas y no vuelvan a salir.
     * @por-que-ocurre La marca de los recálculos posteriores mira cualquier línea del histórico del
     *   producto con fecha mayor, también la del propio recálculo, y cambia el estado de la línea sea cual
     *   sea.
     * @como-deberia-funcionar Marcar solo las líneas pendientes, y contar como posteriores solo las líneas
     *   de coste de otros albaranes.
     */
    public function test_unAlbaranYaRecalculadoNoVuelveAEnsenarSusLineas(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], [], [
            ...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2, 'Revisado'),
            "INSERT INTO historico_precios (idArticulo, Antes, Nuevo, Fecha_Creacion, NumDoc, Dedonde, Tipo, idUsuario, estado) VALUES (:pN, 2.42, 3, '2026-10-02 10:00:00', :albaran, 'Recalculo', 'Productos', :usuario, 'A mano')",
        ], ['linea' => 'SELECT estado FROM historico_precios WHERE id = :h1']);

        self::assertSame('Revisado', $pantalla['leido']['linea'][0]['estado']);
        self::assertStringNotContainsString('<td>Kiwi</td>', $pantalla['html']);
    }

    /** @comportamiento Un albarán sin productos al peso no envía nada a las balanzas: no escribe ningún fichero. */
    public function test_unProductoPorUnidadesNoSeEnviaALaBalanza(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '3.00'], [
            ...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7),
        ], [], true, null, true);

        self::assertSame([], $pantalla['balanzas']);
    }

    /**
     * @comportamiento Un producto al peso sin referencia de la tienda, o con el mismo precio, no se envía: la
     *   balanza de envío recibe su fichero vacío.
     */
    public function test_unProductoAlPesoSinReferenciaOConElMismoPrecioNoSeEnvia(): void
    {
        $sinReferencia = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [
            ...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), ...$this->balanzaConTecla(7),
        ], [], true, null, true);
        $mismoPrecio = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '12.10'], [
            ...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7),
        ], [], true, null, true);

        self::assertSame('', $sinReferencia['balanzas']['Balanzadepruebas' . $sinReferencia['ids'][':b1']] ?? null);
        self::assertSame('', $mismoPrecio['balanzas']['Balanzadepruebas' . $mismoPrecio['ids'][':b1']] ?? null);
    }

    /** @comportamiento Una balanza solo de teclas recibe el producto que tiene tecla en ella, con su tecla. */
    public function test_unaBalanzaSoloDeTeclasRecibeElProductoConSuTecla(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [
            ...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'), ...$this->balanzaSoloDeTeclas(7),
        ], [], true, null, true);

        self::assertStringContainsString('A000123007Queso', $pantalla['balanzas']['Balanzadeteclas' . $pantalla['ids'][':b1']] ?? '');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:109-122
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Con dos productos al peso en el albarán, el que no tiene tecla en una balanza solo de
     *   teclas se le envía también si el anterior sí la tenía: la balanza recibe un producto que no lleva.
     *   Con los productos en el otro orden, no se le envía.
     * @que-deberia-ocurrir Que una balanza solo de teclas reciba solo los productos que tienen tecla en ella.
     * @por-que-ocurre El envío añade la balanza del primer producto a la lista de balanzas, y la lista sigue
     *   así para los productos siguientes.
     * @como-deberia-funcionar Componer las balanzas de cada producto sin cambiar la lista de las de envío.
     */
    public function test_unaBalanzaSoloDeTeclasNoRecibeUnProductoSinTeclaEnElla(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00', 'pvpRecomendado_2' => '20.00'], [
            ...$this->albaran(),
            ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'), ...$this->balanzaSoloDeTeclas(7),
            ...$this->producto('Jamon', 18.15, 'peso'), $this->historico(8, 9), $this->referenciaDeLaTienda('124'),
        ], [], true, null, true);
        $fichero = $pantalla['balanzas']['Balanzadeteclas' . $pantalla['ids'][':b1']] ?? '';

        self::assertStringContainsString('A000123007Queso', $fichero);
        self::assertStringNotContainsString('A000124', $fichero);
    }

    /**
     * @comportamiento Con dos productos al peso, el segundo, sin tecla en la balanza de envío, va sin la tecla
     *   del primero: la tecla se vuelve a poner en blanco para cada producto y balanza.
     */
    public function test_elSegundoProductoNoHeredaLaTeclaDelPrimero(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00', 'pvpRecomendado_2' => '20.00'], [
            ...$this->albaran(),
            ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7),
            ...$this->producto('Jamon', 18.15, 'peso'), $this->historico(8, 9), $this->referenciaDeLaTienda('124'),
        ], [], true, null, true);
        $fichero = $pantalla['balanzas']['Balanzadepruebas' . $pantalla['ids'][':b1']] ?? '';

        self::assertStringContainsString('A000123007Queso', $fichero);
        self::assertStringContainsString('A000124000Jamon', $fichero);
    }

    /** @comportamiento Una balanza con secciones recibe el producto en el modo de secciones, con la sección de su tecla. */
    public function test_unaBalanzaConSeccionesRecibeLaSeccionDeLaTecla(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [
            ...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'),
            "INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES ('Balanza de secciones', 'Prueba', 'si', 0, 52, '127.0.0.1', 0)",
            'INSERT INTO modulo_balanza_plus (idBalanza, plu, seccion, idArticulo) VALUES (:b1, 7, 3, :pN)',
        ], [], true, null, true);
        $fichero = $pantalla['balanzas']['Balanzadesecciones' . $pantalla['ids'][':b1']] ?? '';

        self::assertMatchesRegularExpression('/^00H252A000123007Queso/m', $fichero);
        self::assertMatchesRegularExpression('/^00H352.{41}03/m', $fichero);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:160-161
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Un producto al peso guardado con el precio vacío —que el guardado toma como cero— termina
     *   el guardado con una excepción del envío a la balanza: el precio ya está a cero y apuntado, y las
     *   líneas del albarán quedan sin revisar y sin caché.
     * @que-deberia-ocurrir Que el guardado no deje el producto a cero y termine, diciendo lo que no guardó.
     * @por-que-ocurre El traductor de la balanza rechaza un registro sin precio con una excepción, y el
     *   recálculo no la recoge; el precio cero llega porque el guardado no valida el precio recibido.
     * @como-deberia-funcionar Validar el precio antes de guardar, y recoger el error del envío a la balanza.
     */
    public function test_unProductoAlPesoConElPrecioVacioNoTerminaElGuardado(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => ''], [
            ...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7),
        ], ['linea' => 'SELECT estado FROM historico_precios WHERE id = :h1'], true, null, true);

        self::assertSame('', $pantalla['errores']);
        self::assertSame('Revisado', $pantalla['leido']['linea'][0]['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/Recalculo_precios.php:160-161
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Un producto al peso con IVA cero termina el guardado con una excepción del envío a la
     *   balanza: su precio ya está cambiado y apuntado, la balanza no lo recibe, y las líneas del albarán
     *   quedan sin revisar y sin caché.
     * @que-deberia-ocurrir Que se envíe a la balanza con IVA cero, como los demás, y el guardado termine.
     * @por-que-ocurre El traductor de la balanza rechaza con una excepción el registro de los datos cuando el
     *   IVA es cero, aunque sabe traducirlo, y el recálculo no la recoge.
     * @como-deberia-funcionar Aceptar el IVA cero en el registro de los datos, y recoger el error del envío a
     *   la balanza.
     */
    public function test_unProductoAlPesoConIvaCeroSeEnviaALaBalanza(): void
    {
        $pantalla = $this->pintar(['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [
            ...$this->albaran(), ...$this->producto('Pan', 10, 'peso', 0), $this->historico(5, 6), $this->referenciaDeLaTienda('125'), ...$this->balanzaConTecla(7),
        ], [], true, null, true);

        self::assertSame('', $pantalla['errores']);
        self::assertStringContainsString('A000125007Pan', $pantalla['balanzas']['Balanzadepruebas' . $pantalla['ids'][':b1']] ?? '');
    }

    /** @return list<string> */
    private function albaran(string $proveedor = 'Frutas del Norte'): array
    {
        return [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('{$proveedor}', 'Frutas del Norte SL', 'B00000000', 'Calle Mayor 1', '2026-01-01', 'activo')",
            "INSERT INTO albprot (Numalbpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva) VALUES (0, '2026-10-01 10:00:00', :tienda, :usuario, :proveedor, 'Guardado', 0)",
        ];
    }

    /** @return list<string> */
    private function producto(string $nombre, float $pvp = 2.42, string $tipo = 'unidad', int $iva = 21, int $beneficio = 10): array
    {
        $sinIva = round($pvp / (1 + $iva / 100), 6);

        return [
            "INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, iva, beneficio, tipo) VALUES ('{$nombre}', 'Activo', '2026-01-01', 1, {$iva}, {$beneficio}, '{$tipo}')",
            "INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, {$pvp}, {$sinIva}, :tienda)",
        ];
    }

    /** La línea del histórico de compras del albarán para el último producto sembrado. */
    private function historico(float $antes, float $nuevo, string $estado = 'Pendiente', string $fecha = '2026-10-01 10:00:00', string $documento = ':albaran'): string
    {
        return "INSERT INTO historico_precios (idArticulo, Antes, Nuevo, Fecha_Creacion, NumDoc, Dedonde, Tipo, idUsuario, estado) VALUES (:pN, {$antes}, {$nuevo}, '{$fecha}', {$documento}, 'albaran', 'compras', :usuario, '{$estado}')";
    }

    private function referenciaDelProveedor(string $referencia): string
    {
        return "INSERT INTO articulosProveedores (idArticulo, idProveedor, crefProveedor, coste, fechaActualizacion, estado) VALUES (:pN, :proveedor, '{$referencia}', 2, '2026-10-01', 'activo')";
    }

    private function referenciaDeLaTienda(string $referencia): string
    {
        return "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES (:pN, :tienda, '{$referencia}', 'Activo')";
    }

    /** Una balanza solo de teclas, que no recibe los envíos generales, con su tecla para el último producto sembrado. */
    private function balanzaSoloDeTeclas(int $plu): array
    {
        return [
            "INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES ('Balanza de teclas', 'Prueba', 'no', 0, 51, '127.0.0.1', 1)",
            "INSERT INTO modulo_balanza_plus (idBalanza, plu, seccion, idArticulo) VALUES (:b1, {$plu}, 0, :pN)",
        ];
    }

    /** Una balanza a la que se envían los precios, con su tecla para el último producto sembrado. */
    private function balanzaConTecla(int $plu): array
    {
        return [
            "INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES ('Balanza de pruebas', 'Prueba', 'no', 0, 50, '127.0.0.1', 0)",
            "INSERT INTO modulo_balanza_plus (idBalanza, plu, seccion, idArticulo) VALUES (:b1, {$plu}, 0, :pN)",
        ];
    }

    private function borrar(string $ruta): void
    {
        foreach (glob($ruta . '/*') ?: [] as $dentro) {
            is_dir($dentro) ? $this->borrar($dentro) : unlink($dentro);
        }
        if (is_dir($ruta)) {
            rmdir($ruta);
        }
    }

    /**
     * Pinta el recálculo de `mod_productos` en un PHP aparte. Siembra la preparación, guarda la caché del
     * recálculo si el caso la da, crea la carpeta de cada balanza si el caso lo pide y, al terminar, lee
     * las consultas indicadas, la caché y el fichero de cada balanza, y borra la caché.
     *
     * @param array<string, string> $consulta
     * @param array<string, string> $formulario
     * @param list<string> $preparacion
     * @param array<string, string> $lecturas
     * @return array{html: string, errores: string, ids: array<string, string>, leido: array<string, mixed>, cache: ?string, balanzas: array<string, string>}
     */
    private function pintar(array $consulta, array $formulario = [], array $preparacion = [], array $lecturas = [], bool $conSesion = true, ?string $cache = null, bool $carpetasDeBalanza = false): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $cacheDir = RUTA_TPVFOX . '/modulos/mod_productos/cache';
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_productos/Recalculo_precios.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_productos', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $rutatmp = str_repeat("/..", substr_count(rtrim($RutaServidor, "/"), "/")) . ' . var_export($this->carpeta, true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_productos\'");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = [];'
            . ' $claves = ["articulos" => ":p", "historico_precios" => ":h", "modulo_balanza" => ":b"];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' if (preg_match("/^INSERT INTO (\\\\w+) /", $sql, $t)) { $id = (string) $BDTpv->insert_id;'
            . ' if ($t[1] === "proveedores") { $sembrados[":proveedor"] = $id; } elseif ($t[1] === "albprot") { $sembrados[":albaran"] = $id; }'
            . ' elseif (isset($claves[$t[1]])) { $cuentas[$t[1]] = ($cuentas[$t[1]] ?? 0) + 1; $sembrados[$claves[$t[1]] . $cuentas[$t[1]]] = $id; if ($t[1] === "articulos") { $sembrados[":pN"] = $id; } } } }'
            . ' $cacheFichero = ' . var_export($cacheDir . '/recalculoprecios_' . ($conSesion ? $this->idUsuario : '') . '_', true) . ' . ($sembrados[":albaran"] ?? "") . ".xml";'
            . ' $cacheHabia = is_file($cacheFichero);'
            . ($cache !== null ? ' if (!$cacheHabia) { file_put_contents($cacheFichero, strtr(' . var_export($cache, true) . ', $sembrados)); }' : '')
            . ($carpetasDeBalanza ? ' foreach ($BDTpv->query("SELECT idBalanza, nombreBalanza FROM modulo_balanza") as $b) { @mkdir(' . var_export($this->carpeta, true) . ' . "/" . str_replace(" ", "", $b["nombreBalanza"]) . $b["idBalanza"]); }' : '')
            . ' $_GET = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($consulta, true) . ');'
            . ' $_POST = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($formulario, true) . ');'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use (&$sembrados, $BDTpv, $cacheFichero, $cacheHabia) {'
            . ' $leido = []; foreach (' . var_export($lecturas, true) . ' as $nombre => $sql) { $leido[$nombre] = $BDTpv->query(strtr($sql, $sembrados))->fetch_all(MYSQLI_ASSOC); }'
            . ' $cache = is_file($cacheFichero) ? file_get_contents($cacheFichero) : null; if (!$cacheHabia && is_file($cacheFichero)) { unlink($cacheFichero); }'
            . ' $balanzas = []; foreach (glob(' . var_export($this->carpeta, true) . ' . "/*/filetx") ?: [] as $f) { $balanzas[basename(dirname($f))] = file_get_contents($f); }'
            . ' echo "\n@@DATOS@@" . json_encode(["ids" => $sembrados, "leido" => $leido, "cache" => $cache, "balanzas" => $balanzas]); });'
            . ' include "Recalculo_precios.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'recalculo');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $datos = json_decode($datos, true) ?? [];
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores), 'ids' => $datos['ids'] ?? [],
            'leido' => $datos['leido'] ?? [], 'cache' => $datos['cache'] ?? null, 'balanzas' => $datos['balanzas'] ?? []];
        unlink($errores);

        return $pintada;
    }
}

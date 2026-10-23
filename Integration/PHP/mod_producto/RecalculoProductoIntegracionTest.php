<?php

/**
 * El recálculo de precios de `mod_producto` (`Recalculo_precios.php`), al que se llega por su
 * dirección, y la impresión del recálculo (`tareas/imprimirRecalculo.php`), que incluyen las órdenes
 * `imprimir` de las peticiones de fondo de los dos módulos; también la orden `imprimirRecalculo` de la de
 * `mod_productos`, que imprime lo guardado desde su caché.
 *
 * Cada pantalla o petición se ejecuta en un PHP aparte, desde la carpeta de su módulo, con la sesión del
 * usuario de pruebas, su tienda y los permisos del módulo concedidos —o sin sesión, si el caso lo
 * pide—, y una transacción que se deshace al terminar. El caso siembra un proveedor (`:proveedor`), un
 * albarán suyo (`:albaran`), sus productos (`:p1`, `:p2`…), las líneas del histórico (`:h1`, `:h2`…) y
 * las balanzas (`:b1`…). Al terminar, el PHP aparte responde lo que el caso pide leer de la base, las
 * variables de la petición que el caso nombra y el fichero de cada balanza.
 *
 * La carpeta temporal de la configuración, donde se escriben el PDF y el fichero de cada balanza, se
 * cambia por una propia del caso, que se borra al terminar: en ella no hay ningún driver de balanza, y
 * ninguno se ejecuta. La caché del recálculo que el caso pone para imprimir se borra al terminar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class RecalculoProductoIntegracionTest extends CasoIntegracion
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
     * @comportamiento El recálculo de `mod_producto` enseña, de cada producto con el coste
     *   pendiente de revisar, su número, su nombre, la referencia del proveedor, el coste nuevo y el
     *   anterior, el beneficio, el IVA, el precio actual y el recomendado, que se puede cambiar, y el
     *   botón de quitarlo del recálculo.
     */
    public function test_laPantallaEnsenaCadaProductoPendienteConSuPrecioRecomendado(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], [], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI')]);
        $id = $pantalla['ids'][':p1'];
        $albaran = $pantalla['ids'][':albaran'];

        self::assertStringContainsString('Recalculo precios PVP  de albaran :' . $albaran, $pantalla['html']);
        self::assertStringContainsString('<tr id="Row1" ><td>' . $id . '</td><td>Kiwi</td><td>REF-KIWI</td><td>2.0000</td><td>1.0000</td><td>10.00</td><td>21.00</td><td>2.4200</td>', $pantalla['html']);
        self::assertMatchesRegularExpression('/name="pvpRecomendado_1"[^>]*value="2\.66" size="5">/', $pantalla['html']);
        self::assertStringContainsString("cambiarEstadoRecalculo({$id},'albaran',{$albaran},'compras',1,'eliminar')", $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @comportamiento Un producto descartado sale tachado, con el botón de recuperarlo; uno ya
     *   revisado no sale; uno con un recálculo posterior sale con el precio sin poder cambiarse, su
     *   aviso y sin botón.
     */
    public function test_cadaProductoSaleSegunSuEstado(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], [], [
            ...$this->albaran(),
            ...$this->producto('Kiwi'), $this->historico(1, 2, 'Sin Cambios'), $this->referenciaDelProveedor('K'),
            ...$this->producto('Pera'), $this->historico(1, 2, 'Revisado'), $this->referenciaDelProveedor('P'),
            ...$this->producto('Uva'), $this->historico(1, 2), $this->historico(1, 3, 'Pendiente', '2026-10-05 10:00:00', '0'), $this->referenciaDelProveedor('U'),
        ]);
        $albaran = $pantalla['ids'][':albaran'];

        self::assertStringContainsString("<tr id=\"Row1\" class='tachado'><td>" . $pantalla['ids'][':p1'] . '</td>', $pantalla['html']);
        self::assertStringContainsString("cambiarEstadoRecalculo(" . $pantalla['ids'][':p1'] . ",'albaran',{$albaran},'compras',1,'retorno')", $pantalla['html']);
        self::assertStringNotContainsString('<td>Pera</td>', $pantalla['html']);
        self::assertMatchesRegularExpression('/name="pvpRecomendado_2"[^>]*disabled>\s*<span class="glyphicon glyphicon-ban-circle"/', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @comportamiento Guardar pone al producto pendiente el precio aceptado, con IVA y sin él;
     *   apunta en el histórico de precios el anterior y el nuevo, el albarán y el usuario; deja
     *   revisada la línea del albarán y pide imprimir el recálculo.
     */
    public function test_guardarFijaElPrecioYLoApuntaEnElHistoricoYPideImprimir(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '3.00'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI')], [
            'precio' => 'SELECT pvpCiva, pvpSiva FROM articulosPrecios WHERE idArticulo = :p1',
            'historico' => 'SELECT Antes, Nuevo, NumDoc, Dedonde, Tipo, idUsuario, estado FROM historico_precios WHERE idArticulo = :p1 ORDER BY id',
        ]);
        $albaran = $pantalla['ids'][':albaran'];

        self::assertSame([['pvpCiva' => '3.000000', 'pvpSiva' => '2.479339']], $pantalla['leido']['precio']);
        self::assertSame('Revisado', $pantalla['leido']['historico'][0]['estado']);
        self::assertSame(['Antes' => '2.4200', 'Nuevo' => '3.0000', 'NumDoc' => $albaran, 'Dedonde' => 'Recalculo', 'Tipo' => 'Productos', 'idUsuario' => (string) $this->idUsuario, 'estado' => 'A mano'], $pantalla['leido']['historico'][1]);
        self::assertStringContainsString("mensajeImprimir({$albaran}, 'albaran')", $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @comportamiento Un precio aceptado igual al actual no cambia el precio ni se apunta en el
     *   histórico.
     */
    public function test_unPrecioIgualAlActualNoSeCambiaNiSeApunta(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '2.42'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI')], [
            'historico' => 'SELECT estado FROM historico_precios WHERE idArticulo = :p1 ORDER BY id',
        ]);

        self::assertSame([['estado' => 'Revisado']], $pantalla['leido']['historico']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @comportamiento Guardar un producto al peso con referencia de tienda escribe, en la carpeta
     *   de cada balanza de envío, el registro del producto con su referencia, su tecla, su nombre y
     *   su precio nuevo.
     */
    public function test_unProductoAlPesoSeEnviaALaBalanza(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7)], [], [], true, null, true);
        $fichero = $pantalla['balanzas']['Balanzadepruebas' . $pantalla['ids'][':b1']] ?? '';

        self::assertStringContainsString('A000123007Queso', $fichero);
        self::assertStringContainsString('00001500', $fichero);
    }

    /**
     * @comportamiento Imprimir el recálculo, desde la orden de imprimir de cualquiera de las dos
     *   peticiones de fondo, escribe el PDF en la carpeta temporal con el albarán, su fecha, su
     *   proveedor y, de cada producto recalculado, su nombre, su referencia y su precio anterior y
     *   nuevo; con el mensaje de la balanza, si no es «KO».
     */
    public function test_imprimirElRecalculoDaLosPreciosAnteriorYNuevo(): void
    {
        $preparacion = [...$this->albaran(), ...$this->producto('Kiwi'), $this->referenciaDeLaTienda('K1'), $this->recalculado(2.42, 3)];

        foreach (['mod_producto' => 'OK', 'mod_productos' => 'KO'] as $modulo => $mensaje) {
            $impresion = $this->ejecutar($modulo, 'tareas.php', [], ['pulsado' => 'imprimir', 'id' => ':albaran', 'mensaje' => $mensaje], $preparacion, [], ['htmlImprimir', 'respuesta']);
            $html = (string) ($impresion['variables']['htmlImprimir']['html'] ?? '');

            self::assertStringContainsString('ALBARÁN NÚMERO : ' . $impresion['ids'][':albaran'], $html, $modulo);
            self::assertStringContainsString('FECHA : 2026-10-01', $html, $modulo);
            self::assertStringContainsString('PROVEEDOR : Frutas del Norte', $html, $modulo);
            self::assertStringContainsString('<td WIDTH="35%">Kiwi</td><td>K1</td><td>2.4200</td><td>3.0000</td>', $html, $modulo);
            self::assertSame($mensaje === 'OK', str_contains($html, '<p>Mensaje: OK</p>'), $modulo);
            self::assertStringStartsWith('%PDF', (string) file_get_contents($this->carpeta . '/Recalculorecalculo.pdf'), $modulo);
            unlink($this->carpeta . '/Recalculorecalculo.pdf');
        }
    }

    /**
     * @comportamiento La orden de imprimir el recálculo guardado de `mod_productos` escribe el PDF
     *   desde la caché, con el albarán, su fecha, su proveedor y, de cada producto, su número, su
     *   nombre, el coste anterior y el nuevo, el precio anterior y el precio nuevo.
     */
    public function test_imprimirElRecalculoGuardadoLoTomaDeLaCache(): void
    {
        $cache = '<?xml version="1.0" encoding="UTF-8"?><recalculo><idAlbaran>:albaran</idAlbaran><fecha>2026-10-01</fecha><idProveedor>:proveedor</idProveedor>'
            . '<nombreProveedor>Frutas del Norte</nombreProveedor><productos><producto><idArticulo>:p1</idArticulo><nombre>Kiwi</nombre><referencia>R1</referencia>'
            . '<costeAnterior>1</costeAnterior><costeNuevo>2</costeNuevo><pvpAnterior>2.4200</pvpAnterior><pvpNuevo>3.0000</pvpNuevo></producto></productos></recalculo>';
        $impresion = $this->ejecutar('mod_productos', 'tareas.php', [], ['pulsado' => 'imprimirRecalculo', 'id' => ':albaran'], [...$this->albaran(), ...$this->producto('Kiwi')], [], ['html', 'respuesta'], true, $cache);
        $html = (string) $impresion['variables']['html'];

        self::assertStringContainsString('ALBARÁN NÚMERO: ' . $impresion['ids'][':albaran'], $html);
        self::assertStringContainsString('PROVEEDOR: Frutas del Norte', $html);
        self::assertStringContainsString('<strong>' . $impresion['ids'][':p1'] . '</strong></td><td width="56%"><strong>Kiwi</strong><br><font color="#666666"><small>Coste 1.00 -> 2.00&nbsp;&nbsp;PVP ant.: 2.42</small>', $html);
        self::assertStringContainsString('<strong>3.00</strong>', $html);
        self::assertStringStartsWith('%PDF', (string) file_get_contents($this->carpeta . '/Recalculorecalculo.pdf'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:70-71
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, un precio aceptado con coma decimal se
     *   guarda sin sus decimales: 2,50 queda en 2,00.
     * @que-deberia-ocurrir Que se guarde 2,50, o que se rechace con un aviso.
     * @por-que-ocurre El precio recibido se convierte a número tal cual llega, y la conversión se
     *   detiene en la coma.
     * @como-deberia-funcionar Aceptar la coma como separador decimal al recibir el precio, o
     *   validarlo y avisar.
     */
    public function test_unPrecioConComaDecimalSeGuardaConSusDecimales(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '2,50'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI')], ['precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1']);

        self::assertSame('2.500000', $pantalla['leido']['precio'][0]['pvpCiva']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:70-71
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:75
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, un precio aceptado vacío deja el producto
     *   con el precio de venta a cero, y lo apunta así en el histórico.
     * @que-deberia-ocurrir Que un precio vacío o que no es un número no cambie el precio, y se
     *   avise.
     * @por-que-ocurre El precio recibido se convierte a número sin validarlo: vacío es cero,
     *   distinto del actual.
     * @como-deberia-funcionar Validar el precio recibido antes de guardarlo: un número mayor que
     *   cero.
     */
    public function test_unPrecioVacioNoDejaElProductoACero(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => ''], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI')], ['precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1']);

        self::assertSame('2.420000', $pantalla['leido']['precio'][0]['pvpCiva']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:76-82
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, aceptar el precio recomendado tal como lo
     *   enseña la pantalla lo apunta en el histórico como puesto a mano.
     * @que-deberia-ocurrir Que se apunte como recomendado.
     * @por-que-ocurre La pantalla enseña el recomendado con dos decimales, y el guardado lo compara
     *   con el recomendado sin redondear.
     * @como-deberia-funcionar Comparar con el recomendado redondeado a dos decimales, como se
     *   enseña.
     */
    public function test_aceptarElPrecioRecomendadoLoApuntaComoRecomendado(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '2.66'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI')], ['historico' => "SELECT estado FROM historico_precios WHERE idArticulo = :p1 AND Dedonde = 'Recalculo'"]);

        self::assertSame('Recomendado', $pantalla['leido']['historico'][0]['estado'] ?? null);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:89
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:102-103
     * @codigo-afectado clases/articulos.php:157-163
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, un precio aceptado de mil euros o más
     *   termina el guardado con un error de la base, después de apuntarlo en el histórico: el
     *   histórico dice que el precio cambió y el producto sigue con el anterior.
     * @que-deberia-ocurrir Que el precio se guarde, o que nada se guarde y se avise.
     * @por-que-ocurre El precio sin IVA se escribe con separador de miles, que la sentencia del
     *   precio no admite; el histórico se apunta antes, y ningún paso del guardado está en una
     *   transacción.
     * @como-deberia-funcionar Escribir el precio sin separador de miles, y guardar el histórico y
     *   el precio juntos, o ninguno.
     */
    public function test_unPrecioDeMilEurosOMasSeGuarda(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '1210'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI')], ['precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1']);

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
        self::assertSame('1210.000000', $pantalla['leido']['precio'][0]['pvpCiva']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:73
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:103
     * @codigo-afectado clases/articulos.php:141-149
     * @codigo-afectado clases/articulos.php:157-163
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, guardar cambia el precio del producto en
     *   todas las tiendas, y toma como precio actual el de cualquiera de ellas.
     * @que-deberia-ocurrir Que el recálculo lea y cambie el precio de la tienda de la sesión, como
     *   el resto del producto.
     * @por-que-ocurre La lectura y la escritura del precio buscan solo por el producto, sin la
     *   tienda.
     * @como-deberia-funcionar Leer y escribir el precio de la tienda de la sesión.
     */
    public function test_guardarNoCambiaElPrecioDeOtraTienda(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '3.00'], [...$this->albaran(), ...$this->producto('Kiwi'), 'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 9.68, 8, :tienda + 1000)', $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI')], ['otra' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1 AND idTienda = :tienda + 1000']);

        self::assertSame('9.680000', $pantalla['leido']['otra'][0]['pvpCiva']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:20
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:34
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, sin sesión, un guardado que llega se
     *   intenta y termina con un error de la base, en lugar de pedir el acceso.
     * @que-deberia-ocurrir Que sin sesión no se intente guardar, y la pantalla pida el acceso.
     * @por-que-ocurre El guardado se hace antes de pintar la cabecera, que es la que pide la
     *   sesión, y apunta en el histórico un usuario que no hay.
     * @como-deberia-funcionar Comprobar la sesión antes de guardar.
     */
    public function test_sinSesionNoSeIntentaGuardar(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '3.00'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI')], [], [], false);

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
        self::assertStringContainsString('name="usr"', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:146
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, al enviar a la balanza un precio
     *   recalculado, el registro con los datos del producto lleva como código el número de la línea
     *   del histórico, no la referencia que lleva el registro del precio.
     * @que-deberia-ocurrir Que los dos registros lleven la referencia del producto, como en el
     *   resto de envíos a la balanza.
     * @por-que-ocurre El código del segundo registro se toma del número de la línea del histórico.
     * @como-deberia-funcionar Tomar la referencia de la tienda del producto para los dos registros.
     */
    public function test_laBalanzaRecibeLaReferenciaDelProductoEnSusDosRegistros(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7)], [], [], true, null, true);
        $fichero = $pantalla['balanzas']['Balanzadepruebas' . $pantalla['ids'][':b1']] ?? '';

        self::assertSame(2, substr_count($fichero, '000123'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:22-33
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:290-311
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy El recálculo de `mod_producto` pedido sin albarán se pinta vacío, con avisos
     *   de PHP.
     * @que-deberia-ocurrir Que vuelva al listado de albaranes, como el de `mod_productos`.
     * @por-que-ocurre La pantalla solo lee el albarán si llega su número, y después pinta sus datos
     *   sin mirarlo.
     * @como-deberia-funcionar Volver al listado de albaranes si no llega el número del albarán.
     */
    public function test_sinAlbaranNoDaAvisos(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', [], [], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)]);

        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:23
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:27
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:30
     * @codigo-afectado clases/articulos.php:120-128
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, un número de albarán con una condición
     *   añadida en la dirección enseña las líneas pendientes de todos los albaranes, que un guardado
     *   recalcularía.
     * @que-deberia-ocurrir Que solo enseñe las del albarán indicado: ese número no es el de un
     *   albarán.
     * @por-que-ocurre El número llega tal como se escribe en la dirección y la lectura del
     *   histórico lo pega a su consulta.
     * @como-deberia-funcionar Convertir el número del albarán a entero al recibirlo.
     */
    public function test_unNumeroDeAlbaranConUnaCondicionNoEnsenaOtrosAlbaranes(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran OR 1=1'], [], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI'), ...$this->albaran('Frutas del Sur'), ...$this->producto('Pera'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-PERA')]);

        self::assertStringContainsString('<td>Pera</td>', $pantalla['html']);
        self::assertStringNotContainsString('<td>Kiwi</td>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:302
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:307
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:352
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El recálculo de `mod_producto` escribe tal cual el nombre del producto y el
     *   del proveedor: un nombre con una etiqueta de script la ejecuta al abrirlo.
     * @que-deberia-ocurrir Que se escriban como texto, como en el de `mod_productos`.
     * @por-que-ocurre La pantalla escribe los nombres sin escaparlos.
     * @como-deberia-funcionar Escapar los nombres al escribirlos.
     */
    public function test_losNombresSeEscribenComoTexto(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], [], [...$this->albaran(self::SCRIPT), ...$this->producto(self::SCRIPT), $this->historico(1, 2), $this->referenciaDelProveedor('REF')]);

        self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
        self::assertStringContainsString('<td>' . htmlspecialchars(self::SCRIPT) . '</td>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:353
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, un producto sin referencia del proveedor
     *   del albarán deja un aviso de PHP.
     * @que-deberia-ocurrir Que salga con la referencia vacía, sin aviso.
     * @por-que-ocurre La pantalla escribe la referencia de la ficha del proveedor sin mirar si el
     *   producto la tiene.
     * @como-deberia-funcionar Tomar como vacía la referencia de un producto que no la tiene.
     */
    public function test_unProductoSinReferenciaDelProveedorNoDaAvisos(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], [], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)]);

        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:107-110
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:120
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:140
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:189
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, guardar un producto al peso sin referencia
     *   de tienda termina con un error, después de cambiar su precio y apuntarlo en el histórico: la
     *   línea del albarán sigue pendiente.
     * @que-deberia-ocurrir Que el precio se guarde y el producto se envíe solo a las balanzas que
     *   admiten su registro, o que se avise sin dejar el guardado a medias.
     * @por-que-ocurre El envío a la balanza da por hecha la referencia de tienda, que el registro
     *   del precio exige, y la traducción del registro lanza una excepción que nadie recoge; el
     *   guardado no está en una transacción.
     * @como-deberia-funcionar Enviar a la balanza solo los productos con referencia de tienda, como
     *   el de `mod_productos`, y guardar el recálculo en una transacción.
     */
    public function test_unProductoAlPesoSinReferenciaDeTiendaNoTerminaElGuardado(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), "INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES ('Balanza de pruebas', 'Prueba', 'no', 0, 50, '127.0.0.1', 0)"], ['linea' => "SELECT estado FROM historico_precios WHERE idArticulo = :p1 AND Dedonde = 'albaran'"], [], true, null, true);

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
        self::assertSame('Revisado', $pantalla['leido']['linea'][0]['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:212
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, enviar un precio a la balanza deja un
     *   aviso de PHP por una variable que no existe.
     * @que-deberia-ocurrir Que el envío no dé avisos.
     * @por-que-ocurre La carpeta de la balanza se compone primero con una variable que no se define
     *   en ninguna parte, y después se vuelve a componer bien.
     * @como-deberia-funcionar Quitar la primera composición.
     */
    public function test_elEnvioALaBalanzaNoDaAvisos(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7)], [], [], true, null, true);

        self::assertStringNotContainsString('Undefined variable', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:107-110
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:120
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, guardar un producto al peso con referencia
     *   de tienda que no tiene tecla en ninguna balanza deja avisos de PHP.
     * @que-deberia-ocurrir Que se envíe a las balanzas de envío sin aviso.
     * @por-que-ocurre La consulta de las balanzas del producto devuelve nada en lugar de una lista
     *   vacía, y el guardado la recorre sin mirarlo.
     * @como-deberia-funcionar Tomar como lista vacía las balanzas de un producto que no tiene
     *   ninguna.
     */
    public function test_unProductoAlPesoSinTeclaEnNingunaBalanzaNoDaAvisos(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDeLaTienda('123'), "INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES ('Balanza de pruebas', 'Prueba', 'no', 0, 50, '127.0.0.1', 0)"], [], [], true, null, true);

        self::assertStringNotContainsString('foreach()', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareas/imprimirRecalculo.php:11
     * @codigo-afectado modulos/mod_producto/tareas/imprimirRecalculo.php:27
     * @codigo-afectado modulos/mod_producto/tareas/imprimirRecalculo.php:34-35
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy La impresión del recálculo escribe tal cual en el documento el nombre del
     *   producto, el del proveedor y el mensaje recibido: un nombre con etiquetas cambia lo que se
     *   imprime.
     * @que-deberia-ocurrir Que se escriban como texto.
     * @por-que-ocurre La impresión compone el documento con los nombres y el mensaje sin
     *   escaparlos.
     * @como-deberia-funcionar Escapar los nombres y el mensaje al componer el documento.
     */
    public function test_laImpresionEscribeLosNombresComoTexto(): void
    {
        $impresion = $this->ejecutar('mod_producto', 'tareas.php', [], ['pulsado' => 'imprimir', 'id' => ':albaran', 'mensaje' => 'KO'], [...$this->albaran(), ...$this->producto('<b>Kiwi</b>'), $this->recalculado(2.42, 3)], [], ['htmlImprimir']);

        self::assertStringContainsString(htmlspecialchars('<b>Kiwi</b>'), (string) ($impresion['variables']['htmlImprimir']['html'] ?? ''));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareas.php:286
     * @codigo-afectado modulos/mod_producto/tareas.php:293
     * @codigo-afectado modulos/mod_productos/tareas.php:346
     * @codigo-afectado modulos/mod_productos/tareas.php:355
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Cada impresión del recálculo, de cualquier usuario y desde cualquiera de los
     *   dos módulos, se escribe en el mismo fichero: una que coincide con otra puede abrir los
     *   precios de la otra.
     * @que-deberia-ocurrir Que cada impresión tenga su fichero.
     * @por-que-ocurre El nombre del fichero es fijo.
     * @como-deberia-funcionar Dar a cada impresión un nombre propio, con el usuario y el momento.
     */
    public function test_cadaImpresionDelRecalculoTieneSuFichero(): void
    {
        $preparacion = [...$this->albaran(), ...$this->producto('Kiwi'), $this->recalculado(2.42, 3)];
        $una = $this->ejecutar('mod_producto', 'tareas.php', [], ['pulsado' => 'imprimir', 'id' => ':albaran', 'mensaje' => 'KO'], $preparacion, [], ['respuesta']);
        $otra = $this->ejecutar('mod_productos', 'tareas.php', [], ['pulsado' => 'imprimir', 'id' => ':albaran', 'mensaje' => 'KO'], $preparacion, [], ['respuesta']);

        self::assertNotSame(basename((string) $una['variables']['respuesta']['fichero']), basename((string) $otra['variables']['respuesta']['fichero']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/tareas.php:342
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Imprimir el recálculo guardado de `mod_productos` deja avisos de PHP de la
     *   librería del PDF.
     * @que-deberia-ocurrir Que la impresión no dé avisos.
     * @por-que-ocurre Cada producto lleva una línea con un estilo de borde que la librería del PDF
     *   no interpreta entero.
     * @como-deberia-funcionar Separar los productos con un estilo que la librería admita.
     */
    public function test_imprimirElRecalculoGuardadoNoDaAvisos(): void
    {
        $cache = '<?xml version="1.0" encoding="UTF-8"?><recalculo><idAlbaran>:albaran</idAlbaran><fecha>2026-10-01</fecha><idProveedor>:proveedor</idProveedor>'
            . '<nombreProveedor>Frutas del Norte</nombreProveedor><productos><producto><idArticulo>:p1</idArticulo><nombre>Kiwi</nombre><referencia>R1</referencia>'
            . '<costeAnterior>1</costeAnterior><costeNuevo>2</costeNuevo><pvpAnterior>2.4200</pvpAnterior><pvpNuevo>3.0000</pvpNuevo></producto></productos></recalculo>';
        $impresion = $this->ejecutar('mod_productos', 'tareas.php', [], ['pulsado' => 'imprimirRecalculo', 'id' => ':albaran'], [...$this->albaran(), ...$this->producto('Kiwi')], [], [], true, $cache);

        self::assertSame('', $impresion['errores']);
    }

    /**
     * @comportamiento En el recálculo de `mod_producto`, detrás de un producto descartado y de uno revisado, el
     *   precio de cada fila se guarda en su producto: el guardado cuenta las filas como la pantalla.
     */
    public function test_cadaPrecioSeGuardaEnSuProductoDetrasDeUnDescartadoYUnRevisado(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '9.99', 'pvpRecomendado_2' => '3.30'], [
            ...$this->albaran(),
            ...$this->producto('Kiwi'), $this->historico(1, 2, 'Sin Cambios'), $this->referenciaDelProveedor('REF-KIWI'),
            ...$this->producto('Pera'), $this->historico(1, 2, 'Revisado'), $this->referenciaDelProveedor('REF-PERA'),
            ...$this->producto('Uva'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-UVA'),
        ], ['precios' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo IN (:p1, :p2, :p3) ORDER BY idArticulo']);

        self::assertSame(['2.420000', '2.420000', '3.300000'], array_column($pantalla['leido']['precios'], 'pvpCiva'));
    }

    /** @comportamiento En el recálculo de `mod_producto`, un albarán sin productos al peso no escribe ningún fichero de balanza. */
    public function test_unProductoPorUnidadesNoSeEnviaALaBalanza(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '3.00'], [
            ...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI'), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7),
        ], [], [], true, null, true);

        self::assertSame([], $pantalla['balanzas']);
    }

    /** @comportamiento En el recálculo de `mod_producto`, una balanza con secciones recibe el producto en su modo, con la sección de la tecla. */
    public function test_unaBalanzaConSeccionesRecibeLaSeccionDeLaTecla(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [
            ...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDelProveedor('REF-QUESO'), $this->referenciaDeLaTienda('123'),
            "INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES ('Balanza de secciones', 'Prueba', 'si', 0, 52, '127.0.0.1', 0)",
            'INSERT INTO modulo_balanza_plus (idBalanza, plu, seccion, idArticulo) VALUES (:b1, 7, 3, :pN)',
        ], [], [], true, null, true);
        $fichero = $pantalla['balanzas']['Balanzadesecciones' . $pantalla['ids'][':b1']] ?? '';

        self::assertMatchesRegularExpression('/^00H252A000123007Queso/m', $fichero);
        self::assertMatchesRegularExpression('/^00H352.{41}03/m', $fichero);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:108-117
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:174-182
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, un producto al peso con tecla en dos balanzas de
     *   envío llega a la segunda con la tecla de la primera: solo marca como suya la primera balanza del
     *   producto, y la tecla de la anterior se queda para la siguiente.
     * @que-deberia-ocurrir Que cada balanza reciba el producto con su tecla.
     * @por-que-ocurre La marca de las balanzas del producto compara cada una solo con la primera en que
     *   tiene tecla, y la tecla no se pone en blanco entre una balanza y otra.
     * @como-deberia-funcionar Marcar cada balanza en que el producto tiene tecla, y poner en blanco la tecla
     *   y la sección para cada balanza.
     */
    public function test_conTeclaEnDosBalanzasCadaUnaRecibeLaSuya(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [
            ...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDelProveedor('REF-QUESO'), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7),
            "INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES ('Balanza segunda', 'Prueba', 'no', 0, 53, '127.0.0.1', 0)",
            'INSERT INTO modulo_balanza_plus (idBalanza, plu, seccion, idArticulo) VALUES (:b2, 9, 0, :pN)',
        ], [], [], true, null, true);

        self::assertStringContainsString('A000123007Queso', $pantalla['balanzas']['Balanzadepruebas' . $pantalla['ids'][':b1']] ?? '');
        self::assertStringContainsString('A000123009Queso', $pantalla['balanzas']['Balanzasegunda' . $pantalla['ids'][':b2']] ?? '');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:119-132
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, con dos productos al peso, una balanza solo de teclas
     *   en que el primero tiene tecla recibe también el segundo, que no la tiene.
     * @que-deberia-ocurrir Que una balanza solo de teclas reciba solo los productos que tienen tecla en ella.
     * @por-que-ocurre El envío añade a la lista de balanzas la del primer producto, y la lista sigue así para
     *   los siguientes.
     * @como-deberia-funcionar Componer las balanzas de cada producto sin cambiar la lista de las de envío.
     */
    public function test_unaBalanzaSoloDeTeclasNoRecibeUnProductoSinTeclaEnElla(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00', 'pvpRecomendado_2' => '20.00'], [
            ...$this->albaran(),
            ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDelProveedor('REF-QUESO'), $this->referenciaDeLaTienda('123'),
            "INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES ('Balanza de teclas', 'Prueba', 'no', 0, 51, '127.0.0.1', 1)",
            'INSERT INTO modulo_balanza_plus (idBalanza, plu, seccion, idArticulo) VALUES (:b1, 7, 0, :pN)',
            ...$this->producto('Jamon', 18.15, 'peso'), $this->historico(8, 9), $this->referenciaDelProveedor('REF-JAMON'), $this->referenciaDeLaTienda('124'),
        ], [], [], true, null, true);
        $fichero = $pantalla['balanzas']['Balanzadeteclas' . $pantalla['ids'][':b1']] ?? '';

        self::assertStringContainsString('A000123007Queso', $fichero);
        self::assertStringNotContainsString('A000124', $fichero);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:195-196
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, un producto al peso guardado con el precio vacío
     *   —que el guardado toma como cero— termina el guardado con una excepción del envío a la balanza: el
     *   precio ya está a cero y apuntado, y las líneas del albarán quedan sin revisar.
     * @que-deberia-ocurrir Que el guardado no deje el producto a cero y termine, diciendo lo que no guardó.
     * @por-que-ocurre El traductor de la balanza rechaza un registro sin precio con una excepción, y el
     *   recálculo no la recoge; el precio cero llega porque el guardado no valida el precio recibido.
     * @como-deberia-funcionar Validar el precio antes de guardar, y recoger el error del envío a la balanza.
     */
    public function test_unProductoAlPesoConElPrecioVacioNoTerminaElGuardado(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => ''], [
            ...$this->albaran(), ...$this->producto('Queso', 12.10, 'peso'), $this->historico(5, 6), $this->referenciaDelProveedor('REF-QUESO'), $this->referenciaDeLaTienda('123'), ...$this->balanzaConTecla(7),
        ], ['linea' => 'SELECT estado FROM historico_precios WHERE id = :h1'], [], true, null, true);

        self::assertSame('', $pantalla['errores']);
        self::assertSame('Revisado', $pantalla['leido']['linea'][0]['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:195-196
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, un producto al peso con IVA cero termina el guardado
     *   con una excepción del envío a la balanza: su precio ya está cambiado y apuntado, la balanza no lo
     *   recibe, y las líneas del albarán quedan sin revisar.
     * @que-deberia-ocurrir Que se envíe a la balanza con IVA cero, como los demás, y el guardado termine.
     * @por-que-ocurre El traductor de la balanza rechaza con una excepción el registro de los datos cuando el
     *   IVA es cero, aunque sabe traducirlo, y el recálculo no la recoge.
     * @como-deberia-funcionar Aceptar el IVA cero en el registro de los datos, y recoger el error del envío a
     *   la balanza.
     */
    public function test_unProductoAlPesoConIvaCeroSeEnviaALaBalanza(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar', 'pvpRecomendado_1' => '15.00'], [
            ...$this->albaran(), ...$this->producto('Pan', 10, 'peso', 0), $this->historico(5, 6), $this->referenciaDelProveedor('REF-PAN'), $this->referenciaDeLaTienda('125'), ...$this->balanzaConTecla(7),
        ], [], [], true, null, true);

        self::assertSame('', $pantalla['errores']);
        self::assertStringContainsString('A000125007Pan', $pantalla['balanzas']['Balanzadepruebas' . $pantalla['ids'][':b1']] ?? '');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:70-71
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, una línea pendiente cuyo precio no llega en el
     *   formulario —una línea que entró en el albarán después de pintar la pantalla— deja un aviso y el
     *   producto con el precio de venta a cero, y lo apunta así en el histórico.
     * @que-deberia-ocurrir Que una línea sin precio no se guarde, y la pantalla lo diga.
     * @por-que-ocurre El guardado lee el precio de la fila sin mirar si llegó, y lo convierte en cero.
     * @como-deberia-funcionar Saltar la línea cuyo precio no llega y avisarlo.
     */
    public function test_unaLineaSinSuPrecioEnElFormularioNoDejaElProductoACero(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], ['Guardar' => 'Guardar'], [
            ...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI'),
        ], ['precio' => 'SELECT pvpCiva FROM articulosPrecios WHERE idArticulo = :p1']);

        self::assertSame('2.420000', $pantalla['leido']['precio'][0]['pvpCiva']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:31
     * @codigo-afectado modulos/mod_producto/funciones.php:967
     * @codigo-afectado modulos/mod_producto/funciones.php:974-977
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy El recálculo de `mod_producto` de un albarán ya guardado pasa sus líneas revisadas a
     *   «Sin revisar» en el histórico y las vuelve a enseñar: el cambio de precio que apuntó el propio
     *   recálculo cuenta como uno posterior.
     * @que-deberia-ocurrir Que las líneas revisadas sigan revisadas y no vuelvan a salir.
     * @por-que-ocurre La marca de los recálculos posteriores mira cualquier línea del histórico del
     *   producto con fecha mayor, también la del propio recálculo, y cambia el estado de la línea sea cual
     *   sea.
     * @como-deberia-funcionar Marcar solo las líneas pendientes, y contar como posteriores solo las líneas
     *   de coste de otros albaranes.
     */
    public function test_unAlbaranYaRecalculadoNoVuelveAEnsenarSusLineas(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], [], [
            ...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2, 'Revisado'), $this->referenciaDelProveedor('REF-KIWI'),
            "INSERT INTO historico_precios (idArticulo, Antes, Nuevo, Fecha_Creacion, NumDoc, Dedonde, Tipo, idUsuario, estado) VALUES (:pN, 2.42, 3, '2026-10-02 10:00:00', :albaran, 'Recalculo', 'Productos', :usuario, 'A mano')",
        ], ['linea' => 'SELECT estado FROM historico_precios WHERE id = :h1']);

        self::assertSame('Revisado', $pantalla['leido']['linea'][0]['estado']);
        self::assertStringNotContainsString('<td>Kiwi</td>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado clases/articulos.php:144
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:336
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy En el recálculo de `mod_producto`, con el producto a precios distintos en dos tiendas,
     *   la pantalla enseña como precio actual el de la tienda de número más bajo, que puede no ser la de la
     *   sesión.
     * @que-deberia-ocurrir Que enseñe el precio de la tienda de la sesión, el que el recálculo cambia.
     * @por-que-ocurre La lectura del precio busca solo por el producto, sin la tienda, y toma la primera
     *   fila, que por el índice de la tabla es la de la tienda de número más bajo.
     * @como-deberia-funcionar Leer el precio de la tienda de la sesión.
     */
    public function test_laPantallaEnsenaElPrecioActualDeLaTiendaDeLaSesion(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], [], [
            ...$this->albaran(), $this->producto('Kiwi')[0],
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 9.68, 8, :tienda - 1)',
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 2.42, 2, :tienda)',
            $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI'),
        ]);

        self::assertMatchesRegularExpression('/<td>Kiwi<\/td>(\s*<td>[^<]*<\/td>){5}\s*<td>2\.4200<\/td>/', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/Recalculo_precios.php:20
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Sin sesión, el recálculo de `mod_producto` pide el acceso sin enseñar el recálculo, pero
     *   deja avisos de PHP: el del usuario de la sesión, que no hay, y los del menú del acceso.
     * @que-deberia-ocurrir Que pida el acceso sin avisos, como las demás pantallas.
     * @por-que-ocurre La pantalla toma el usuario de la sesión sin mirar si lo hay, y deja sin usuario al
     *   menú, al que el arranque había dado el de invitado.
     * @como-deberia-funcionar Usar el usuario que deja el arranque, o comprobar la sesión antes de leer nada.
     */
    public function test_sinSesionLaPantallaPideElAccesoSinAvisos(): void
    {
        $pantalla = $this->ejecutar('mod_producto', 'Recalculo_precios.php', ['id' => ':albaran'], [], [
            ...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2), $this->referenciaDelProveedor('REF-KIWI'),
        ], [], [], false);

        self::assertStringNotContainsString('<td>Kiwi</td>', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareas.php:284
     * @codigo-afectado modulos/mod_productos/tareas.php:353
     * @codigo-afectado clases/articulos.php:123
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy La orden de imprimir el recálculo, desde los dos módulos, con un número de albarán con
     *   una condición añadida, imprime los cambios de precio de los recálculos de todos los albaranes.
     * @que-deberia-ocurrir Que imprima solo los del albarán indicado: ese número no es el de un albarán.
     * @por-que-ocurre Las peticiones de fondo toman el número tal como llega, y la lectura del histórico lo
     *   pega a su consulta.
     * @como-deberia-funcionar Convertir el número del albarán a entero al recibirlo.
     */
    public function test_imprimirConUnNumeroConUnaCondicionNoTraeOtrosAlbaranes(): void
    {
        $preparacion = [
            ...$this->albaran(), ...$this->producto('Kiwi'), $this->referenciaDeLaTienda('K1'), $this->recalculado(2.42, 3),
            ...$this->albaran('Frutas del Sur'), ...$this->producto('Pera'), $this->referenciaDeLaTienda('P1'), $this->recalculado(2.42, 4),
        ];

        foreach (['mod_producto', 'mod_productos'] as $modulo) {
            $impresion = $this->ejecutar($modulo, 'tareas.php', [], ['pulsado' => 'imprimir', 'id' => ':albaran OR 1=1', 'mensaje' => 'KO'], $preparacion, [], ['htmlImprimir']);
            $html = (string) ($impresion['variables']['htmlImprimir']['html'] ?? '');

            self::assertStringContainsString('<td WIDTH="35%">Pera</td>', $html, $modulo);
            self::assertStringNotContainsString('<td WIDTH="35%">Kiwi</td>', $html, $modulo);
        }
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareas/imprimirRecalculo.php:2
     * @codigo-afectado modulos/mod_producto/tareas/imprimirRecalculo.php:24-33
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy La orden de imprimir el recálculo de un albarán que no se ha guardado da un documento con
     *   la tabla vacía, sin avisar; imprimir lo guardado de `mod_productos`, en cambio, responde que primero
     *   hay que guardarlo.
     * @que-deberia-ocurrir Que avise de que el albarán no tiene recálculo guardado, como la otra orden.
     * @por-que-ocurre La impresión compone el documento con las líneas del recálculo que encuentre, sin mirar
     *   si hay alguna.
     * @como-deberia-funcionar Comprobar que el albarán tiene líneas del recálculo y, si no, responder el aviso.
     */
    public function test_imprimirUnRecalculoQueNoSeGuardoLoAvisa(): void
    {
        foreach (['mod_producto', 'mod_productos'] as $modulo) {
            $impresion = $this->ejecutar($modulo, 'tareas.php', [], ['pulsado' => 'imprimir', 'id' => ':albaran', 'mensaje' => 'KO'], [...$this->albaran(), ...$this->producto('Kiwi'), $this->historico(1, 2)], [], ['respuesta']);

            self::assertArrayHasKey('error', (array) ($impresion['variables']['respuesta'] ?? []), $modulo);
        }
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_productos/tareas.php:325-330
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Imprimir el recálculo guardado de `mod_productos` con su caché dañada —un fichero que no
     *   es XML— deja avisos de PHP y da un documento sin datos.
     * @que-deberia-ocurrir Que responda que lo guardado no se puede leer.
     * @por-que-ocurre La orden lee la caché sin mirar si la lectura falló.
     * @como-deberia-funcionar Recoger el fallo de la lectura de la caché y responderlo como error.
     */
    public function test_imprimirUnaCacheDanadaNoDaAvisos(): void
    {
        $impresion = $this->ejecutar('mod_productos', 'tareas.php', [], ['pulsado' => 'imprimirRecalculo', 'id' => ':albaran'], [...$this->albaran(), ...$this->producto('Kiwi')], [], [], true, 'no es un recálculo guardado');

        self::assertSame('', $impresion['errores']);
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

    /** La línea del histórico de precios que apunta el guardado del recálculo, para el último producto sembrado. */
    private function recalculado(float $antes, float $nuevo): string
    {
        return "INSERT INTO historico_precios (idArticulo, Antes, Nuevo, Fecha_Creacion, NumDoc, Dedonde, Tipo, idUsuario, estado) VALUES (:pN, {$antes}, {$nuevo}, NOW(), :albaran, 'Recalculo', 'Productos', :usuario, 'A mano')";
    }

    /**
     * Ejecuta una pantalla o una petición del módulo indicado en un PHP aparte. Siembra la preparación,
     * guarda la caché del recálculo de `mod_productos` si el caso la da, crea la carpeta de cada balanza
     * si el caso lo pide y, al terminar, lee las consultas indicadas, las variables nombradas y el
     * fichero de cada balanza, y borra la caché que haya puesto.
     *
     * @param array<string, string> $consulta
     * @param array<string, string> $formulario
     * @param list<string> $preparacion
     * @param array<string, string> $lecturas
     * @param list<string> $variables
     * @return array{html: string, errores: string, ids: array<string, string>, leido: array<string, mixed>, variables: array<string, mixed>, balanzas: array<string, string>}
     */
    private function ejecutar(string $modulo, string $fichero, array $consulta, array $formulario = [], array $preparacion = [], array $lecturas = [], array $variables = [], bool $conSesion = true, ?string $cache = null, bool $carpetasDeBalanza = false): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $cacheDir = RUTA_TPVFOX . '/modulos/mod_productos/cache';
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/{$modulo}/{$fichero}", true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . "/modulos/{$modulo}", true) . ');'
            . ' require "./../../inicial.php";'
            . ' $rutatmp = str_repeat("/..", substr_count(rtrim($RutaServidor, "/"), "/")) . ' . var_export($this->carpeta, true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = ' . var_export($modulo, true) . '");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = [];'
            . ' $claves = ["articulos" => ":p", "historico_precios" => ":h", "modulo_balanza" => ":b"];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' if (preg_match("/^INSERT INTO (\\\\w+) /", $sql, $t)) { $id = (string) $BDTpv->insert_id;'
            . ' if ($t[1] === "proveedores") { $sembrados[":proveedor"] = $id; } elseif ($t[1] === "albprot") { $sembrados[":albaran"] = $id; }'
            . ' elseif (isset($claves[$t[1]])) { $cuentas[$t[1]] = ($cuentas[$t[1]] ?? 0) + 1; $sembrados[$claves[$t[1]] . $cuentas[$t[1]]] = $id; if ($t[1] === "articulos") { $sembrados[":pN"] = $id; } } } }'
            . ' $cacheFichero = ' . var_export($cacheDir . '/recalculoprecios_' . $this->idUsuario . '_', true) . ' . ($sembrados[":albaran"] ?? "") . ".xml";'
            . ' $cacheHabia = is_file($cacheFichero);'
            . ($cache !== null ? ' if (!$cacheHabia) { file_put_contents($cacheFichero, strtr(' . var_export($cache, true) . ', $sembrados)); }' : '')
            . ($carpetasDeBalanza ? ' foreach ($BDTpv->query("SELECT idBalanza, nombreBalanza FROM modulo_balanza") as $b) { @mkdir(' . var_export($this->carpeta, true) . ' . "/" . str_replace(" ", "", $b["nombreBalanza"]) . $b["idBalanza"]); }' : '')
            . ' $_GET = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($consulta, true) . ');'
            . ' $_POST = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($formulario, true) . ');'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use (&$sembrados, $BDTpv, $cacheFichero, $cacheHabia) {'
            . ' $leido = []; foreach (' . var_export($lecturas, true) . ' as $nombre => $sql) { $leido[$nombre] = $BDTpv->query(strtr($sql, $sembrados))->fetch_all(MYSQLI_ASSOC); }'
            . ' $variables = []; foreach (' . var_export($variables, true) . ' as $nombre) { $variables[$nombre] = $GLOBALS[$nombre] ?? null; }'
            . ' if (!$cacheHabia && is_file($cacheFichero)) { unlink($cacheFichero); }'
            . ' $balanzas = []; foreach (glob(' . var_export($this->carpeta, true) . ' . "/*/filetx") ?: [] as $f) { $balanzas[basename(dirname($f))] = file_get_contents($f); }'
            . ' echo "\n@@DATOS@@" . json_encode(["ids" => $sembrados, "leido" => $leido, "variables" => $variables, "balanzas" => $balanzas], JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'recalculo');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $datos = json_decode($datos, true) ?? [];
        $resultado = ['html' => trim($html), 'errores' => (string) file_get_contents($errores), 'ids' => $datos['ids'] ?? [],
            'leido' => $datos['leido'] ?? [], 'variables' => $datos['variables'] ?? [], 'balanzas' => $datos['balanzas'] ?? []];
        unlink($errores);

        return $resultado;
    }
}

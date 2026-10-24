<?php

/**
 * El mayor de un producto de `mod_producto` (`DetalleMayor.php`), el que enlazan su ficha, la lista del
 * mayor, los informes y el listado de un proveedor, y la petición que lo imprime (`tareasmayor.php`, con
 * `funciones_mayor.inc.php`), que no llama ninguna pantalla.
 *
 * Cada pantalla y cada petición se pinta en un PHP aparte, desde la carpeta de `mod_producto`, con la sesión del
 * usuario de pruebas y su tienda —o sin sesión, si el caso lo pide— y una transacción que se deshace
 * al terminar. El caso siembra un producto (`:p1`, `:p2`…), un proveedor y un cliente, y los
 * documentos de cada movimiento. La carpeta temporal de la configuración, donde la petición escribe lo
 * que imprime, se cambia por una propia del caso, que se borra al terminar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class MayorProductoIntegracionTest extends CasoIntegracion
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
        $this->carpeta = sys_get_temp_dir() . '/tpvfox-mayor-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->carpeta . '/*') ?: []);
        rmdir($this->carpeta);
        parent::tearDown();
    }

    /**
     * @comportamiento El mayor enseña cada movimiento del producto entre las dos fechas, en orden, con su
     *   entrada o su salida y la existencia que deja desde la inicial indicada.
     */
    public function test_elMayorEnsenaCadaMovimientoConLaExistenciaQueDeja(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31', 'stock' => '5'], [
            ...$this->producto('Kiwi'), ...$this->terceros(),
            ...$this->compra('2026-10-01 10:00:00', 10), ...$this->ticket('2026-10-02 10:00:00', 3), ...$this->albaranDeVenta('2026-10-03 10:00:00', 2),
        ]);

        self::assertStringContainsString('<h2 class="text-center">Kiwi</h2>', $pantalla['html']);
        self::assertSame(['15', '12', '10'], $this->existencias($pantalla['html']));
        self::assertSame('', $pantalla['errores']);
    }

    /** @comportamiento Un movimiento fuera de las dos fechas no sale en el mayor. */
    public function test_unMovimientoFueraDeLasFechasNoSale(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-09-30 10:00:00', 10), ...$this->ticket('2026-10-02 10:00:00', 3),
        ]);

        self::assertSame(['-3'], $this->existencias($pantalla['html']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:31
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Con la fecha inicial y sin la final, el mayor sale sin ningún movimiento: toma la
     *   final vacía en lugar de la de hoy.
     * @que-deberia-ocurrir Que sin fecha final tome la de hoy, como dice la pantalla.
     * @por-que-ocurre La comprobación de la fecha final mira si falta la inicial.
     * @como-deberia-funcionar Comprobar la fecha final.
     */
    public function test_sinFechaFinalTomaLaDeHoy(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => ''], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 10),
        ]);

        self::assertSame(['10'], $this->existencias($pantalla['html']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:253
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El enlace de una compra del mayor abre el albarán de compra cuyo identificador es el
     *   número del albarán, que puede ser otro.
     * @que-deberia-ocurrir Que abra el albarán de la compra.
     * @por-que-ocurre El cálculo del mayor da como identificador de la compra su número de albarán.
     * @como-deberia-funcionar Enlazar por el identificador del albarán.
     */
    public function test_cadaCompraEnlazaConSuAlbaran(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 10, 777777),
        ]);

        self::assertStringContainsString('mod_compras/albaran.php?id=' . $pantalla['ids'][':albpro'] . '&accion=ver', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:265
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El enlace de un albarán de venta del mayor abre el albarán cuyo identificador es su
     *   número, que puede ser otro.
     * @que-deberia-ocurrir Que abra el albarán de la venta.
     * @por-que-ocurre El cálculo del mayor da como identificador del albarán de venta su número.
     * @como-deberia-funcionar Enlazar por el identificador del albarán.
     */
    public function test_cadaAlbaranDeVentaEnlazaConSuAlbaran(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->albaranDeVenta('2026-10-03 10:00:00', 2, 777777),
        ]);

        self::assertStringContainsString('mod_venta/albaran.php?id=' . $pantalla['ids'][':albcli'] . '&estado=ver', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:290
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy El mayor escribe tal cual el nombre del cliente o del proveedor de cada movimiento: un
     *   nombre con una etiqueta de script la ejecuta al abrir el mayor.
     * @que-deberia-ocurrir Que lo escriba como texto.
     * @por-que-ocurre La fila pinta el nombre sin escaparlo.
     * @como-deberia-funcionar Escapar el nombre al pintarlo.
     */
    public function test_losNombresSeEscribenComoTexto(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(self::SCRIPT), ...$this->ticket('2026-10-02 10:00:00', 3),
        ]);

        self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:215
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Con movimientos en el mismo mes de dos años seguidos y ninguno entre medias, el mayor
     *   los suma en un solo subtotal.
     * @que-deberia-ocurrir Que cada mes de cada año tenga su subtotal.
     * @por-que-ocurre El cambio de mes se mira por el número del mes, sin el año.
     * @como-deberia-funcionar Comparar el año y el mes.
     */
    public function test_elMismoMesDeDosAnosTieneSuSubtotal(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2025-01-01', 'fecha_final' => '2026-12-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2025-01-10 10:00:00', 10), ...$this->ticket('2026-01-10 10:00:00', 3),
        ]);

        self::assertSame(2, substr_count($pantalla['html'], '<b>Subtotal</b>'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:225
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:316
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Con movimientos en el mismo mes de dos años y otros entre medias, el total del mayor y
     *   sus tablas por mes pierden los del primer año.
     * @que-deberia-ocurrir Que el total sume todos los movimientos, y las tablas den cada mes de cada año.
     * @por-que-ocurre Las cuentas de cada mes se guardan por el número del mes, y el del segundo año
     *   reemplaza al del primero.
     * @como-deberia-funcionar Guardarlas por el año y el mes.
     */
    public function test_elTotalCuentaElMismoMesDeCadaAno(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2025-01-01', 'fecha_final' => '2026-12-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2025-01-10 10:00:00', 10),
            ...$this->ticket('2025-02-10 10:00:00', 3), ...$this->ticket('2026-01-10 10:00:00', 2),
        ]);

        preg_match('#<b>Total</b></td>\s*<td><b>([^<]*)</b></td>\s*<td><b>([^<]*)</b></td>#', $pantalla['html'], $total);
        self::assertSame(['10', '5'], array_slice($total, 1));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:17
     * @codigo-afectado modulos/mod_producto/clases/ClaseArticulos.php:214
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Un número de producto con una condición añadida en la dirección enseña en el mayor
     *   los movimientos de todos los productos.
     * @que-deberia-ocurrir Que enseñe solo los del producto indicado.
     * @por-que-ocurre La pantalla toma el número como llega, y el cálculo del mayor lo pega a su consulta.
     * @como-deberia-funcionar Convertir el número del producto a entero al recibirlo.
     */
    public function test_unNumeroDeProductoConUnaCondicionNoEnsenaOtrosProductos(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1 OR 1=1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 10),
            ...$this->producto('Pera'), ...$this->compra('2026-10-02 10:00:00', 4),
        ]);

        self::assertCount(1, $this->existencias($pantalla['html']));
    }

    /** @comportamiento Sin fechas, el mayor enseña todos los movimientos del producto hasta hoy. */
    public function test_sinFechasEnsenaTodosLosMovimientos(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2020-01-10 10:00:00', 10), ...$this->ticket('2026-01-10 10:00:00', 3),
        ]);

        self::assertSame(['10', '7'], $this->existencias($pantalla['html']));
    }

    /** @comportamiento El enlace de un ticket del mayor abre ese ticket. */
    public function test_cadaTicketEnlazaConSuTicket(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->ticket('2026-10-02 10:00:00', 3),
        ]);

        self::assertStringContainsString('mod_tpv/ticketCobrado.php?id=' . $pantalla['ids'][':ticket'] . '"', $pantalla['html']);
    }

    /** @comportamiento Un producto al peso enseña sus entradas y salidas con tres decimales. */
    public function test_unProductoAlPesoEnsenaTresDecimales(): void
    {
        $alPeso = array_map(static fn ($sql) => str_replace("'unidad'", "'peso'", $sql), $this->producto('Kiwi'));
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$alPeso, ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 1.5),
        ]);

        self::assertStringContainsString('<td>1.500</td>', $pantalla['html']);
    }

    /**
     * @comportamiento La fila que deja la existencia sin unidades se destaca, la de un cliente especial en
     *   otro color, y el domingo se marca.
     */
    public function test_cadaFilaSeDestacaPorSuExistenciaSuClienteYSuDia(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 10), ...$this->albaranDeVenta('2026-10-02 10:00:00', 12),
            "INSERT INTO clientes (Nombre, estado) VALUES ('Cliente especial', 'Especial')", ...$this->ticket('2026-10-04 10:00:00', 1),
        ]);

        self::assertSame(1, preg_match_all('#<tr><td class="text-center"><b></b></td><td>2026-10-01#', $pantalla['html']));
        self::assertSame(1, preg_match_all('#<tr class="bg-warning"><td class="text-center"><b></b></td><td>2026-10-02#', $pantalla['html']));
        self::assertSame(1, preg_match_all('#<tr class="bg-danger"><td class="text-center"><b>X</b></td><td>2026-10-04#', $pantalla['html']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:158
     * @codigo-afectado modulos/mod_producto/clases/ClaseProductos.php:363
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy El mayor de un producto que no existe lo avisa, pero deja un aviso de PHP.
     * @que-deberia-ocurrir Que lo avise sin avisos de PHP.
     * @por-que-ocurre La lectura del producto comprueba el estado sobre la respuesta vacía.
     * @como-deberia-funcionar Comprobar el estado solo de un producto leído.
     */
    public function test_unProductoQueNoExisteLoDice(): void
    {
        $pantalla = $this->pintar(['idArticulo' => '999999', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31']);

        self::assertStringContainsString('No se encontró un producto con ese id', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:16
     * @codigo-afectado modulos/mod_producto/clases/ClaseArticulos.php:214
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Sin el número del producto en la dirección, la página termina con un error de la base.
     * @que-deberia-ocurrir Que avise de que no se encontró el producto, como con un número que no existe.
     * @por-que-ocurre Sin el número no se fijan ni el producto ni las fechas, y la lectura y el cálculo se
     *   hacen igual.
     * @como-deberia-funcionar Avisar y no pedir los movimientos si no llega el número.
     */
    public function test_sinNumeroDeProductoLoDice(): void
    {
        $pantalla = $this->pintar([]);

        self::assertStringContainsString('No se encontró un producto con ese id', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:40
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:256
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Una existencia inicial con letras termina la página en el primer movimiento.
     * @que-deberia-ocurrir Que avise de que la existencia no vale y pinte la página entera.
     * @por-que-ocurre La existencia se toma tal como llega y se le suma la entrada del primer movimiento.
     * @como-deberia-funcionar Tomar la existencia como número y avisar de la que no lo es.
     */
    public function test_unaExistenciaConLetrasNoTerminaLaPagina(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31', 'stock' => 'abc'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 10),
        ]);

        self::assertStringContainsString('</html>', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /** @comportamiento Sin sesión, la cabecera pide el acceso y no se pintan los movimientos. */
    public function test_sinSesionPideElAccesoSinPintarLosMovimientos(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 10),
        ], false);

        self::assertSame([], $this->existencias($pantalla['html']));
        self::assertSame('', $pantalla['errores']);
    }

    /** @comportamiento Cada mes tiene su subtotal, y el total suma los de todos. */
    public function test_cadaMesTieneSuSubtotalYElTotalLosSuma(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-11-30'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 10), ...$this->ticket('2026-11-02 10:00:00', 3),
        ]);

        self::assertSame(2, substr_count($pantalla['html'], '<b>Subtotal</b>'));
        self::assertSame(['10', '3'], array_slice($this->total($pantalla['html']), 0, 2));
    }

    /** @comportamiento Las ventas a un cliente especial se cuentan también aparte. */
    public function test_lasVentasAUnClienteEspecialSeCuentanAparte(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->ticket('2026-10-02 10:00:00', 3),
            "INSERT INTO clientes (Nombre, estado) VALUES ('Cliente especial', 'Especial')", ...$this->ticket('2026-10-03 10:00:00', 2),
        ]);

        self::assertSame('5', $this->total($pantalla['html'])[1]);
        self::assertSame(1, preg_match('#<b>Especiales</b></td>\s*<td><b>0</b></td>\s*<td><b>2</b></td>#', $pantalla['html']));
    }

    /** @comportamiento Sin movimientos entre las dos fechas, el total sale a cero. */
    public function test_sinMovimientosElTotalSaleACero(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [...$this->producto('Kiwi')]);

        self::assertSame(['0', '0', '0.00', '0.00'], $this->total($pantalla['html']));
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:226
     * @codigo-afectado modulos/mod_producto/DetalleMayor.php:305
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Los importes de cada mes y del total de un producto por unidades salen en euros
     *   enteros: las compras de 3,63 € salen 4,00 € y las ventas de 2,42 €, 2,00 €.
     * @que-deberia-ocurrir Que salgan con sus céntimos.
     * @por-que-ocurre Las cuentas de cada mes redondean los importes con los decimales de las unidades del
     *   producto, que por unidades son cero.
     * @como-deberia-funcionar Redondear los importes a dos decimales.
     */
    public function test_losImportesDelTotalLlevanSusCentimos(): void
    {
        $pantalla = $this->pintar(['idArticulo' => ':p1', 'fecha_inicial' => '2026-10-01', 'fecha_final' => '2026-10-31'], [
            ...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 3), ...$this->ticket('2026-10-02 10:00:00', 1),
        ]);

        self::assertSame(['3.63', '2.42'], array_slice($this->total($pantalla['html']), 2, 2));
    }

    /** @comportamiento Pedir la impresión de un producto que no existe responde que no existe. */
    public function test_imprimirUnProductoQueNoExisteLoDice(): void
    {
        $pantalla = $this->pintar([], [], true, 'tareasmayor.php', ['pulsado' => 'imprimemayor', 'idproducto' => '999999', 'stockinicial' => '0', 'fechainicio' => '01/10/2026', 'fechafin' => '31/10/2026']);

        self::assertSame('"No existe articulo"', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareasmayor.php:97
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy La petición que compone el mayor para imprimirlo nunca encuentra movimientos: responde
     *   que no hay datos en el intervalo, con avisos de PHP, aunque el producto los tenga.
     * @que-deberia-ocurrir Que componga el mayor con los movimientos del producto entre las dos fechas y sus
     *   totales.
     * @por-que-ocurre Pasa las fechas al cálculo del mayor con otros nombres que los que este lee, y el
     *   cálculo las toma vacías.
     * @como-deberia-funcionar Pasar las fechas con los nombres que lee el cálculo.
     */
    public function test_imprimirElMayorDaSusMovimientosYTotales(): void
    {
        $pantalla = $this->pintar([], [...$this->producto('Kiwi'), ...$this->terceros(), ...$this->compra('2026-10-01 10:00:00', 10)], true, 'tareasmayor.php',
            ['pulsado' => 'imprimemayor', 'idproducto' => ':p1', 'stockinicial' => '5', 'fechainicio' => '01/10/2026', 'fechafin' => '31/10/2026']);
        $respuesta = json_decode($pantalla['html'], true);

        self::assertStringContainsString('<b>TOTALES:</b>', (string) ($respuesta['html'] ?? ''));
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareasmayor.php:41-62
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Pedir el PDF del mayor sin haberlo compuesto antes responde un enlace a un fichero sin
     *   nombre, con un aviso de PHP.
     * @que-deberia-ocurrir Que responda que primero hay que componer el mayor.
     * @por-que-ocurre El nombre del fichero solo se fija si encuentra lo compuesto, y la respuesta lo usa
     *   igual.
     * @como-deberia-funcionar Responder un error si no encuentra lo compuesto.
     */
    public function test_pedirElPdfSinComponerElMayorLoDice(): void
    {
        $pantalla = $this->pintar([], [], true, 'tareasmayor.php', ['pulsado' => 'imprimePDFMayor', 'idproducto' => '999999']);

        self::assertSame('', $pantalla['errores']);
        self::assertArrayHasKey('error', (array) json_decode($pantalla['html'], true));
    }

    /** @comportamiento Imprimir con un número de producto con una condición añadida no compone nada. */
    public function test_imprimirUnNumeroConUnaCondicionNoLoCompone(): void
    {
        $pantalla = $this->pintar([], [...$this->producto('Kiwi')], true, 'tareasmayor.php',
            ['pulsado' => 'imprimemayor', 'idproducto' => ':p1 OR 1=1', 'stockinicial' => '0', 'fechainicio' => '01/10/2026', 'fechafin' => '31/10/2026']);

        self::assertSame('"No existe articulo"', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareasmayor.php:145
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Imprimir el mayor de un producto sin movimientos responde que no hay datos con la
     *   consulta entera a la base.
     * @que-deberia-ocurrir Que responda que no hay datos, sin la consulta.
     * @por-que-ocurre La respuesta lleva siempre la consulta del cálculo.
     * @como-deberia-funcionar No poner la consulta en la respuesta.
     */
    public function test_imprimirSinMovimientosLoDiceSinEnsenarLaConsulta(): void
    {
        $pantalla = $this->pintar([], [...$this->producto('Kiwi')], true, 'tareasmayor.php',
            ['pulsado' => 'imprimemayor', 'idproducto' => ':p1', 'stockinicial' => '0', 'fechainicio' => '01/10/2026', 'fechafin' => '31/10/2026']);
        $respuesta = (array) json_decode($pantalla['html'], true);

        self::assertArrayHasKey('error', $respuesta);
        self::assertArrayNotHasKey('consulta', $respuesta);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareasmayor.php:89-93
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Imprimir el mayor sin fechas deja avisos de PHP.
     * @que-deberia-ocurrir Que responda que las fechas no valen, sin avisos.
     * @por-que-ocurre Las fechas se parten por sus barras sin comprobar que las lleven.
     * @como-deberia-funcionar Comprobar las dos fechas antes de componer.
     */
    public function test_imprimirSinFechasLoDice(): void
    {
        $pantalla = $this->pintar([], [...$this->producto('Kiwi')], true, 'tareasmayor.php',
            ['pulsado' => 'imprimemayor', 'idproducto' => ':p1', 'stockinicial' => '0', 'fechainicio' => '', 'fechafin' => '']);

        self::assertSame('', $pantalla['errores']);
        self::assertArrayHasKey('error', (array) json_decode($pantalla['html'], true));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareasmayor.php:24-35
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy La petición que imprime el mayor se atiende sin sesión.
     * @que-deberia-ocurrir Que sin sesión no se atienda.
     * @por-que-ocurre La petición no comprueba la sesión.
     * @como-deberia-funcionar Comprobar la sesión antes de atender la orden.
     */
    public function test_imprimirSinSesionNoSeAtiende(): void
    {
        $pantalla = $this->pintar([], [...$this->producto('Kiwi')], false, 'tareasmayor.php',
            ['pulsado' => 'imprimemayor', 'idproducto' => ':p1', 'stockinicial' => '0', 'fechainicio' => '01/10/2026', 'fechafin' => '31/10/2026']);

        self::assertArrayNotHasKey('idproducto', (array) json_decode($pantalla['html'], true));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareasmayor.php:24-35
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy La petición que imprime el mayor se atiende sin el permiso de imprimirlo.
     * @que-deberia-ocurrir Que sin ese permiso no se atienda.
     * @por-que-ocurre La petición no consulta el permiso de imprimir el mayor que declara su módulo.
     * @como-deberia-funcionar Consultar el permiso antes de atender la orden.
     */
    public function test_imprimirSinElPermisoNoSeAtiende(): void
    {
        $pantalla = $this->pintar([], [
            ...$this->producto('Kiwi'),
            "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_producto' AND accion = 'imprimirMayor'",
        ], true, 'tareasmayor.php',
            ['pulsado' => 'imprimemayor', 'idproducto' => ':p1', 'stockinicial' => '0', 'fechainicio' => '01/10/2026', 'fechafin' => '31/10/2026']);

        self::assertArrayNotHasKey('idproducto', (array) json_decode($pantalla['html'], true));
    }

    /** @comportamiento Con el mayor ya compuesto, pedir el PDF lo escribe y responde su enlace. */
    public function test_imprimirElPdfDeLoCompuestoLoEscribe(): void
    {
        file_put_contents($this->carpeta . '/cabecera_999.htmp', json_encode('<h2>Mayor productos</h2>'));
        file_put_contents($this->carpeta . '/cuerpo_999.htmp', json_encode('<table><tr><td>2026-10-01</td></tr></table>'));
        $pantalla = $this->pintar([], [], true, 'tareasmayor.php', ['pulsado' => 'imprimePDFMayor', 'idproducto' => '999']);

        self::assertFileExists($this->carpeta . '/mayor999.pdf');
        self::assertStringContainsString('mayor999.pdf', (string) (json_decode($pantalla['html'], true)['fichero'] ?? ''));
    }

    /** @return list<string> La existencia de cada fila de movimiento, en orden. */
    private function existencias(string $html): array
    {
        preg_match_all('#<td>\d{4}-\d\d-\d\d[^<]*</td>(?:<td>[^<]*</td>){2}<td>(-?[\d.]+)</td>#', $html, $m);

        return $m[1];
    }

    /** @return list<string> Las entradas, las salidas, las compras y las ventas de la fila del total. */
    private function total(string $html): array
    {
        preg_match('#<b>Total</b></td>\s*<td><b>([^<]*)</b></td>\s*<td><b>([^<]*)</b></td>\s*<td></td>\s*<td><b>([^<]*)</b></td>\s*<td><b>([^<]*)</b></td>#', $html, $m);

        return array_slice($m, 1);
    }

    /** @return list<string> */
    private function producto(string $nombre): array
    {
        return [
            "INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, iva, beneficio, tipo) VALUES ('{$nombre}', 'Activo', '2026-01-01', 1, 21, 10, 'unidad')",
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 2.42, 2, :tienda)',
        ];
    }

    /** @return list<string> El proveedor y el cliente de los movimientos. */
    private function terceros(string $nombre = 'Frutas del Norte'): array
    {
        return [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('{$nombre}', '{$nombre}', 'B00000000', 'Calle Mayor 1', '2026-01-01', 'activo')",
            "INSERT INTO clientes (Nombre, estado) VALUES ('{$nombre}', 'Activo')",
        ];
    }

    /** @return list<string> */
    private function compra(string $fecha, float $unidades, int $numero = 1): array
    {
        return [
            "INSERT INTO albprot (Numalbpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva) VALUES ({$numero}, '{$fecha}', :tienda, :usuario, :proveedor, 'Guardado', 0)",
            "INSERT INTO albprolinea (idalbpro, Numalbpro, idArticulo, ref_prov, nunidades, costeSiva) VALUES (:albpro, {$numero}, :pN, '', {$unidades}, 1)",
        ];
    }

    /** @return list<string> */
    private function ticket(string $fecha, float $unidades): array
    {
        return [
            "INSERT INTO ticketst (Numticket, Numtempticket, Fecha, idTienda, idUsuario, idCliente, estado, formaPago, entregado, total) VALUES (1, 1, '{$fecha}', :tienda, :usuario, :cliente, 'Cobrado', 'contado', 0, 0)",
            "INSERT INTO ticketslinea (idticketst, Numticket, idArticulo, cref, ccodbar, cdetalle, ncant, nunidades, precioCiva, iva, nfila, estadoLinea) VALUES (:ticket, 1, :pN, '', '', '', {$unidades}, {$unidades}, 2.42, 21, 1, 'Activo')",
        ];
    }

    /** @return list<string> */
    private function albaranDeVenta(string $fecha, float $unidades, int $numero = 1): array
    {
        return [
            "INSERT INTO albclit (Numalbcli, Fecha, idTienda, idUsuario, idCliente, estado) VALUES ({$numero}, '{$fecha}', :tienda, :usuario, :cliente, 'Guardado')",
            "INSERT INTO albclilinea (idalbcli, Numalbcli, idArticulo, nunidades, precioCiva) VALUES (:albcli, {$numero}, :pN, {$unidades}, 2.42)",
        ];
    }

    /**
     * Pinta una página de `mod_producto` en un PHP aparte, con la preparación sembrada en una transacción
     * que se deshace al terminar.
     *
     * @param array<string, string> $consulta
     * @param list<string> $preparacion
     * @return array{html: string, errores: string, ids: array<string, string>}
     */
    private function pintar(array $consulta, array $preparacion = [], bool $conSesion = true, string $fichero = 'DetalleMayor.php', array $formulario = []): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_producto/DetalleMayor.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_producto', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $rutatmp = str_repeat("/..", substr_count(rtrim($RutaServidor, "/"), "/")) . ' . var_export($this->carpeta, true) . ';'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = 0;'
            . ' $claves = ["proveedores" => ":proveedor", "clientes" => ":cliente", "albprot" => ":albpro", "ticketst" => ":ticket", "albclit" => ":albcli"];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' if (preg_match("/^INSERT INTO (\\\\w+) /", $sql, $t)) { $id = (string) $BDTpv->insert_id;'
            . ' if (isset($claves[$t[1]])) { $sembrados[$claves[$t[1]]] = $id; } elseif ($t[1] === "articulos") { $cuentas++; $sembrados[":p" . $cuentas] = $sembrados[":pN"] = $id; } } }'
            . ' $_GET = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($consulta, true) . ');'
            . ' $_POST = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($formulario, true) . ');'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use (&$sembrados) { echo "\n@@DATOS@@" . json_encode(["ids" => $sembrados]); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'mayor');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $datos = json_decode($datos, true) ?? [];
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores), 'ids' => $datos['ids'] ?? []];
        unlink($errores);

        return $pintada;
    }
}

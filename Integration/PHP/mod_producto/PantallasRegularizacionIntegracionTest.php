<?php

/**
 * Las pantallas de la regularización de existencias: la lista de regularizaciones
 * (`ListaArticulosRegularizacion.php`) y el modal que la ficha y el listado piden a la petición de
 * fondo de cada módulo para regularizar un producto (`tareas/htmlModalRegularizacionStock.php`).
 *
 * Cada una se pinta en un PHP aparte, desde la carpeta de su módulo, con la sesión del usuario de
 * pruebas, su tienda y los permisos del módulo concedidos. Tras el arranque común se abre una
 * transacción en su conexión, y las clases leen por esa misma conexión: lo que siembra el caso se
 * deshace al terminar el proceso. Los productos que siembra el caso quedan con un nombre —`:p1`,
 * `:p2`…— para el formulario.
 *
 * Lo que hace el modal al grabar es de la petición de fondo, y tiene sus casos con ella.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProducto;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasRegularizacionIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    /** Los estados de una regularización: activa y borrada. */
    private const ACTIVA = 1;

    private const BORRADA = 2;

    private int $idUsuario;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
    }

    /**
     * @comportamiento La lista enseña cada regularización activa con su producto, su fecha, la existencia
     *   de antes, la diferencia y la de después, el usuario, y un botón para el mayor de su producto.
     */
    public function test_laListaEnsenaCadaRegularizacionActiva(): void
    {
        $pantalla = $this->pintar('mod_producto', 'ListaArticulosRegularizacion.php', [], [
            ...$this->producto('Kiwi'),
            $this->regularizacion(10, -3, 7),
        ]);

        self::assertStringContainsString('<td>Kiwi</td>', $pantalla['html']);
        self::assertStringContainsString('<td>2026-03-01 10:00:00</td>', $pantalla['html']);
        self::assertStringContainsString('<td>10.00</td>', $pantalla['html']);
        self::assertStringContainsString('<td>-3.00</td>', $pantalla['html']);
        self::assertStringContainsString('<td>7.00</td>', $pantalla['html']);
        self::assertStringContainsString('<td>' . $this->idUsuario . '</td>', $pantalla['html']);
        self::assertStringContainsString('data-idarticulo="' . $pantalla['ids'][':p1'] . '">Listado Mayor</button>', $pantalla['html']);
    }

    /** @comportamiento Una regularización borrada no sale en la lista. */
    public function test_unaRegularizacionBorradaNoSaleEnLaLista(): void
    {
        $pantalla = $this->pintar('mod_producto', 'ListaArticulosRegularizacion.php', [], [
            ...$this->producto('Kiwi'),
            $this->regularizacion(10, -3, 7, self::BORRADA),
        ]);

        self::assertStringNotContainsString('<td>Kiwi</td>', $pantalla['html']);
        self::assertStringContainsString('Pendientes de regularizar stock', $pantalla['html']);
    }

    /**
     * @comportamiento El modal de un producto enseña su nombre y su existencia, con dos decimales, en la
     *   caja de la actual, que no se puede cambiar, y en la de la real, y guarda el número del producto;
     *   igual desde los dos módulos.
     */
    public function test_elModalEnsenaLaExistenciaDelProductoEnLosDosModulos(): void
    {
        $preparacion = [...$this->producto('Kiwi'), 'INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:p1, :tienda, 5, 0, 0)'];

        $producto = $this->pintar('mod_producto', 'tareas.php', ['pulsado' => 'datosRegularizar', 'idarticulo' => ':p1'], $preparacion);
        $productos = $this->pintar('mod_productos', 'tareas.php', ['accion' => 'datosRegularizar', 'idarticulo' => ':p1'], $preparacion);

        foreach ([$producto, $productos] as $pintado) {
            $html = json_decode($pintado['html'], true)['html'];
            self::assertStringContainsString('<h5>Stock de Kiwi</h5>', $html);
            self::assertStringContainsString('id="stockactual" value="5.00" readonly="readonly"', $html);
            self::assertStringContainsString('id="stockcolocar" value="5.00"', $html);
            self::assertStringContainsString('id="articuloid" value="' . $pintado['ids'][':p1'] . '"', $html);
        }
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/ListaArticulosRegularizacion.php:67
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy La lista escribe el nombre del producto tal cual: un nombre con una etiqueta de
     *   script la ejecuta al abrir la lista.
     * @que-deberia-ocurrir Que el nombre se escriba como texto.
     * @por-que-ocurre El nombre leído se escribe en la celda sin escapar.
     * @como-deberia-funcionar Escapar el nombre al escribirlo.
     */
    public function test_laListaEscribeElNombreDelProductoComoTexto(): void
    {
        $pantalla = $this->pintar('mod_producto', 'ListaArticulosRegularizacion.php', [], [
            ...$this->producto(self::SCRIPT),
            $this->regularizacion(10, -3, 7),
        ]);

        self::assertStringNotContainsString('<td>' . self::SCRIPT . '</td>', $pantalla['html']);
        self::assertStringContainsString(htmlspecialchars(self::SCRIPT), $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareas/htmlModalRegularizacionStock.php:9
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy El modal escribe el nombre del producto tal cual: un nombre con una etiqueta de
     *   script la ejecuta al abrirlo desde la ficha o el listado.
     * @que-deberia-ocurrir Que el nombre se escriba como texto.
     * @por-que-ocurre El nombre leído se escribe en el título del modal sin escapar.
     * @como-deberia-funcionar Escapar el nombre al escribirlo.
     */
    public function test_elModalEscribeElNombreDelProductoComoTexto(): void
    {
        $pintado = $this->pintar('mod_producto', 'tareas.php', ['pulsado' => 'datosRegularizar', 'idarticulo' => ':p1'], [
            ...$this->producto(self::SCRIPT),
            'INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:p1, :tienda, 5, 0, 0)',
        ]);

        $html = json_decode($pintado['html'], true)['html'];
        self::assertStringNotContainsString(self::SCRIPT, $html);
        self::assertStringContainsString(htmlspecialchars(self::SCRIPT), $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_producto/tareas/htmlModalRegularizacionStock.php:2-3
     * @codigo-afectado modulos/mod_producto/tareas/htmlModalRegularizacionStock.php:23
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy El número de producto que llega en la petición se escribe tal cual en el modal: con
     *   un número seguido de un comentario y de una etiqueta de script, el producto se lee y la etiqueta
     *   queda en la página.
     * @que-deberia-ocurrir Que el modal solo escriba el número del producto leído.
     * @por-que-ocurre El modal usa lo recibido para leer el producto, que lo pega a su consulta, y para el
     *   campo oculto, sin convertirlo ni escaparlo.
     * @como-deberia-funcionar Tomar lo recibido como un número, y escribir el del producto leído.
     */
    public function test_elModalSoloEscribeElNumeroDelProductoLeido(): void
    {
        $pintado = $this->pintar('mod_producto', 'tareas.php', ['pulsado' => 'datosRegularizar', 'idarticulo' => ':p1 -- "><script>x=1</script>'], [
            ...$this->producto('Kiwi'),
            'INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:p1, :tienda, 5, 0, 0)',
        ]);

        $html = (string) (json_decode($pintado['html'], true)['html'] ?? '');
        self::assertStringNotContainsString(self::SCRIPT, $html);
    }

    /** @return list<string> */
    private function producto(string $nombre): array
    {
        return [
            "INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, iva, beneficio, tipo) VALUES ('{$nombre}', 'Activo', '2026-01-01', 1, 21, 0, 'unidad')",
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 1.21, 1, :tienda)',
        ];
    }

    private function regularizacion(float $antes, float $diferencia, float $despues, int $estado = self::ACTIVA): string
    {
        return 'INSERT INTO stocksRegularizacion (idArticulo, idTienda, fechaRegularizacion, stockActual, stockModif, stockFinal, stockOperacion, idUsuario, estado)'
            . " VALUES (:pN, :tienda, '2026-03-01 10:00:00', {$antes}, {$diferencia}, {$despues}, 1, :usuario, {$estado})";
    }

    /**
     * Pinta una pantalla, o pide una orden a la petición de fondo, del módulo indicado, en un PHP aparte.
     *
     * @return array{html: string, errores: string, ids: array<string, string>}
     */
    private function pintar(string $modulo, string $fichero, array $formulario = [], array $preparacion = []): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/{$modulo}/{$fichero}", true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . "/modulos/{$modulo}", true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' new ClasePermisos($Usuario, $BDTpv);'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = ' . var_export($modulo, true) . '");'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $productos = 0;'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids));'
            . ' if (str_starts_with($sql, "INSERT INTO articulos ")) { $ids[":p" . ++$productos] = $ids[":pN"] = (string) $BDTpv->insert_id; } }'
            . ' $_POST = array_map(fn ($valor) => strtr((string) $valor, $ids), ' . var_export($formulario, true) . ');'
            . ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);'
            . ' register_shutdown_function(function () use (&$ids) { echo "\n@@IDS@@" . json_encode($ids); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'regularizacion');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $ids] = array_pad(explode('@@IDS@@', implode("\n", $salida), 2), 2, 'null');
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores), 'ids' => json_decode($ids, true) ?? []];
        unlink($errores);

        return $pintada;
    }
}

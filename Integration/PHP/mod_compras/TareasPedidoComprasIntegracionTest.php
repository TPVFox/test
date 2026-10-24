<?php

/**
 * Las órdenes de la petición de fondo de las compras (`modulos/mod_compras/tareas.php`) que escriben el
 * pedido a proveedor: guardar lo que se escribe en su temporal (`tareas/addPedidoTemporal.php`), y lo
 * que queda del pedido al eliminar o cancelar su temporal.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas, en una transacción sobre su conexión que nunca se confirma; lo que necesita se siembra en ella.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModCompras;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasPedidoComprasIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
    }

    /** @comportamiento Lo escrito en un pedido nuevo crea su temporal con el proveedor, la fecha y las líneas, y responde los totales. */
    public function test_loEscritoEnUnPedidoNuevoCreaSuTemporal(): void
    {
        $peticion = $this->ejecutar($this->temporal(['idTemporal' => '0', 'estado' => 'Nuevo']), $this->proveedorYArticulo());

        self::assertSame(10.4, $peticion['respuesta']['total']);
        self::assertStringContainsString('10.40', $peticion['respuesta']['htmlTabla']);
        self::assertSame((string) $peticion['respuesta']['id'], (string) $peticion['temporales'][0]['id']);
        self::assertSame(['Nuevo', '2026-02-04 00:00:00', 10.4, '0.40', null], [$peticion['temporales'][0]['estadoPedPro'], $peticion['temporales'][0]['Fecha'], (float) $peticion['temporales'][0]['total'], $peticion['temporales'][0]['total_ivas'], $peticion['temporales'][0]['idPedpro']]);
        self::assertSame('Pera de compra', json_decode($peticion['temporales'][0]['Productos'], true)[0]['cdetalle']);
    }

    /** @comportamiento Lo escrito en un temporal abierto lo modifica: el estado, la fecha, las líneas y los totales. */
    public function test_loEscritoEnUnTemporalAbiertoLoModifica(): void
    {
        $preparacion = array_merge($this->proveedorYArticulo(), [
            "INSERT INTO pedprotemporales (estadoPedPro, idTienda, idUsuario, Fecha, fechaInicio, idProveedor, total, Productos) VALUES ('Nuevo', :tienda, :usuario, '2026-02-01', NOW(), :p1, 0, '[]')",
        ]);

        $peticion = $this->ejecutar($this->temporal(['idTemporal' => ':tped1', 'estado' => 'Nuevo', 'fecha' => '05-02-2026']), $preparacion);

        self::assertSame($peticion['ids'][':tped1'], (string) $peticion['respuesta']['id']);
        self::assertCount(1, $peticion['temporales']);
        self::assertSame(['2026-02-05 00:00:00', 10.4], [$peticion['temporales'][0]['Fecha'], (float) $peticion['temporales'][0]['total']]);
        self::assertCount(1, json_decode($peticion['temporales'][0]['Productos'], true));
    }

    /** @comportamiento Lo escrito en un pedido guardado crea su temporal «Sin Guardar» relacionado con él, y deja el pedido «Sin Guardar» mientras se edita. */
    public function test_loEscritoEnUnPedidoGuardadoLoDejaSinGuardar(): void
    {
        $peticion = $this->ejecutar($this->temporal(['idTemporal' => '0', 'estado' => 'Guardado', 'idReal' => ':ped1']), $this->pedidoGuardado());

        self::assertSame(['Sin Guardar', $peticion['ids'][':ped1']], [$peticion['temporales'][0]['estadoPedPro'], $peticion['temporales'][0]['idPedpro']]);
        self::assertSame('Sin Guardar', $peticion['pedidos'][0]['estado']);
    }

    /** @comportamiento Una fecha que no existe se responde como error y no crea el temporal. */
    public function test_unaFechaQueNoExisteNoCreaElTemporal(): void
    {
        $peticion = $this->ejecutar($this->temporal(['idTemporal' => '0', 'estado' => 'Nuevo', 'fecha' => '31-02-2026']), $this->proveedorYArticulo());

        self::assertStringContainsString('La fecha es erronea : 31-02-2026', json_encode($peticion['respuesta']['error'], JSON_UNESCAPED_UNICODE) ?: '');
        self::assertSame([], $peticion['temporales']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/tareas/addPedidoTemporal.php:112
     *
     * @group defecto
     * @group seguridad
     * @group medio
     *
     * @que-ocurre-hoy La respuesta de lo escrito en el pedido lleva la sentencia con la que se guardan los
     *   totales del temporal.
     * @que-deberia-ocurrir Que la respuesta no enseñe sentencias de la base.
     * @por-que-ocurre La orden copia la sentencia en la respuesta.
     * @como-deberia-funcionar Quitarla de la respuesta.
     */
    public function test_laRespuestaNoEnsenaLaSentencia(): void
    {
        $peticion = $this->ejecutar($this->temporal(['idTemporal' => '0', 'estado' => 'Nuevo']), $this->proveedorYArticulo());

        self::assertArrayNotHasKey('sqlmodtotal', $peticion['respuesta']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/tareas.php:168-183; modulos/mod_compras/clases/pedidosCompras.php:382-399
     *
     * @group defecto
     * @group integridad
     * @group alto
     *
     * @que-ocurre-hoy Eliminar desde el listado el temporal de un pedido guardado quita el temporal y deja el
     *   pedido «Sin Guardar» (#103): ya no se imprime ni se envía, y el albarán no lo encuentra para
     *   servirlo.
     * @que-deberia-ocurrir Que el pedido vuelva a «Guardado».
     * @por-que-ocurre La orden solo borra el temporal.
     * @como-deberia-funcionar Devolver el pedido a «Guardado» al eliminar su temporal.
     */
    public function test_eliminarElTemporalDeUnPedidoGuardadoLoDevuelveAGuardado(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'eliminarTemporal', 'id_temporal' => ':tped1', 'dedonde' => 'ListadoPedidos'], $this->pedidoEnEdicion());

        self::assertSame([], $peticion['temporales']);
        self::assertSame('Guardado', $peticion['pedidos'][0]['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/funciones.php:742-787
     *
     * @group defecto
     * @group integridad
     * @group alto
     *
     * @que-ocurre-hoy Cancelar desde el pedido el temporal de un pedido guardado quita el temporal y deja el
     *   pedido «Sin Guardar», como al eliminarlo desde el listado (#103).
     * @que-deberia-ocurrir Que el pedido vuelva a «Guardado».
     * @por-que-ocurre Cancelar solo borra el temporal.
     * @como-deberia-funcionar Devolver el pedido a «Guardado» al cancelar su temporal.
     */
    public function test_cancelarElTemporalDeUnPedidoGuardadoLoDevuelveAGuardado(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'cancelarTemporal', 'idTemporal' => ':tped1', 'dedonde' => 'pedido'], $this->pedidoEnEdicion());

        self::assertSame([], $peticion['temporales']);
        self::assertSame('Guardado', $peticion['pedidos'][0]['estado']);
    }

    /** @return list<string> */
    private function proveedorYArticulo(): array
    {
        return [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Frutas compra', 'Frutas compra SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (4, 'Pera de compra', 'Activo', NOW(), 2, 'unidad')",
        ];
    }

    /** @return list<string> */
    private function pedidoGuardado(string $estado = 'Guardado'): array
    {
        return array_merge($this->proveedorYArticulo(), [
            "INSERT INTO pedprot (Numpedpro, Numtemp_pedpro, Fecha, idTienda, idUsuario, idProveedor, estado, total_siniva, total) VALUES (901, 0, '2026-02-03', :tienda, :usuario, :p1, '{$estado}', 10, 10.4)",
        ]);
    }

    /**
     * Un pedido guardado que se está editando: «Sin Guardar», con su temporal.
     *
     * @return list<string>
     */
    private function pedidoEnEdicion(): array
    {
        return array_merge($this->pedidoGuardado('Sin Guardar'), [
            "INSERT INTO pedprotemporales (estadoPedPro, idTienda, idUsuario, Fecha, fechaInicio, idProveedor, total, Productos, idPedpro) VALUES ('Sin Guardar', :tienda, :usuario, '2026-02-04', NOW(), :p1, 10.4, '[]', :ped1)",
        ]);
    }

    /**
     * Lo que envía la pantalla al escribir en el pedido: una línea de pera de 5 unidades a 2 € al 4 %.
     *
     * @param array<string, string> $cambios
     * @return array<string, string>
     */
    private function temporal(array $cambios): array
    {
        return $cambios + [
            'pulsado' => 'addPedidoTemporal', 'idUsuario' => ':usuario', 'idTienda' => ':tienda', 'idReal' => '0', 'fecha' => '04-02-2026', 'idProveedor' => ':p1',
            'productos' => (string) json_encode([['idArticulo' => ':a1', 'cref' => '1001', 'ccodbar' => '', 'ref_prov' => '', 'cdetalle' => 'Pera de compra', 'ncant' => '5', 'nunidades' => '5', 'ultimoCoste' => '2', 'iva' => '4', 'nfila' => 1, 'estado' => 'Activo']]),
        ];
    }

    /**
     * Ejecuta la petición en un PHP aparte, en una transacción que no se confirma, y devuelve su respuesta,
     * lo que escribió, sus avisos y lo que quedó en la base de los pedidos y los temporales del proveedor
     * sembrado. Los marcadores `:usuario` y `:tienda` son los del caso; cada fila sembrada lleva el suyo
     * —`:p1`, `:a1`, `:ped1`, `:tped1`—, que el formulario puede usar.
     *
     * @param array<string, string> $formulario
     * @param list<string> $preparacion
     * @return array<string, mixed>
     */
    private function ejecutar(array $formulario, array $preparacion = []): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas'],
        ];
        $marcas = ['proveedores' => ':p', 'articulos' => ':a', 'pedprot' => ':ped', 'pedprotemporales' => ':tped'];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ' . var_export(dirname(RUTA_TPVFOX), true) . '; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_compras/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_compras', true) . ');'
            . ' $_POST = ["pulsado" => ""]; require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = []; $marcas = ' . var_export($marcas, true) . ';'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids)); $id = $BDTpv->insert_id;'
            . ' if (preg_match("/^INSERT INTO (\\\\w+) /", $sql, $t) && isset($marcas[$t[1]])) { $cuentas[$t[1]] = ($cuentas[$t[1]] ?? 0) + 1; $ids[$marcas[$t[1]] . $cuentas[$t[1]]] = (string) $id; } }'
            . ' $_POST = json_decode(strtr(' . var_export((string) json_encode($formulario), true) . ', $ids), true);'
            . ' $p = (int) ($ids[":p1"] ?? 0);'
            . ' register_shutdown_function(function () use ($BDTpv, &$ids, $p) { echo "\n@@DATOS@@" . json_encode(["ids" => $ids,'
            . ' "pedidos" => $BDTpv->query("SELECT id, estado FROM pedprot WHERE idProveedor = " . $p . " ORDER BY id")->fetch_all(MYSQLI_ASSOC),'
            . ' "temporales" => $BDTpv->query("SELECT id, estadoPedPro, idPedpro, Fecha, total, total_ivas, Productos FROM pedprotemporales WHERE idProveedor = " . $p . " ORDER BY id")->fetch_all(MYSQLI_ASSOC)], JSON_INVALID_UTF8_SUBSTITUTE); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'pedido');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $estado = (json_decode($estado, true) ?? []) + ['ids' => [], 'pedidos' => [], 'temporales' => []];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'escrito' => $escrito, 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}

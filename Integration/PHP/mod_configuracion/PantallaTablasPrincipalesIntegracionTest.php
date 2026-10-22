<?php

/**
 * La pantalla de las tablas principales (`tablasPrincipales.php`) —tipos de IVA, formas de pago y
 * tipos de vencimiento— y su petición de fondo (`tareas.php`) en lo que no es de los tipos de
 * relación: abrir la ventana de una fila y guardarla.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna. Tras el arranque común se abre una transacción en su conexión, y las clases
 * de las tablas escriben por esa misma conexión: lo que siembra el caso y lo que guarda la petición se
 * deshace al terminar el proceso. Antes de terminar, el proceso escribe cuántas filas tienen las tres
 * tablas con la descripción que el caso busca.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModConfiguracion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallaTablasPrincipalesIntegracionTest extends CasoIntegracion
{
    /** Lo que se escribe en la página para ver si un valor guardado llega a ejecutarse; cabe en las tres tablas. */
    private const SCRIPT = '<script>x=1</script>';

    private int $idUsuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idUsuario = (new Siembra($this->db))->usuarioPorDefecto();
    }

    /**
     * @comportamiento La pantalla enseña los tipos de IVA —número, descripción, porcentaje y recargo—,
     *   las formas de pago y los tipos de vencimiento —con sus días—, cada fila con su botón para
     *   editarla y cada tabla con el suyo para añadir.
     */
    public function test_laPantallaEnsenaLasTresTablas(): void
    {
        $pantalla = $this->ejecutar('tablasPrincipales.php', [], [
            "INSERT INTO iva (descripcionIva, iva, recargo) VALUES ('IVA de la pantalla', 21, 5.2)",
            "INSERT INTO formasPago (descripcion) VALUES ('Pago de la pantalla')",
            "INSERT INTO tiposVencimiento (descripcion, dias) VALUES ('Vence pantalla', 30)",
        ]);

        self::assertMatchesRegularExpression("#<td>\d+</td><td>IVA de la pantalla</td><td>21.00</td><td>5.20</td><td><a class=\"glyphicon glyphicon-pencil\" onclick=\"abrirmodal\(\d+, 'iva'\)\"></a></td>#", $pantalla['salida']);
        self::assertMatchesRegularExpression("#<td>Pago de la pantalla</td><td><a class=\"glyphicon glyphicon-pencil\" onclick=\"abrirmodal\(\d+, 'forma'\)\"></a></td>#", $pantalla['salida']);
        self::assertMatchesRegularExpression("#<td>Vence pantalla</td><td>30</td><td><a class=\"glyphicon glyphicon-pencil\" onclick=\"abrirmodal\(\d+, 'vencimiento'\)\"></a></td>#", $pantalla['salida']);
        foreach (['iva', 'forma', 'vencimiento'] as $tabla) {
            self::assertStringContainsString("onclick=\"abrirmodal(0, '$tabla')\"", $pantalla['salida']);
        }
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_configuracion/tablasPrincipales.php:56-63, 82-87, 107-113
     *
     * @group defecto
     * @group listado
     * @group alto
     *
     * @que-ocurre-hoy La pantalla escribe las descripciones tal como están guardadas: una forma de pago
     *   con un script se ejecuta en la página de quien abre las tablas principales.
     * @que-deberia-ocurrir Que los datos se escriban como texto.
     * @por-que-ocurre Cada celda escribe el valor guardado sin escapar.
     * @como-deberia-funcionar Escapar cada valor al escribirlo en la página.
     */
    public function test_laPantallaEscribeLosDatosComoTexto(): void
    {
        $pantalla = $this->ejecutar('tablasPrincipales.php', [], ["INSERT INTO formasPago (descripcion) VALUES ('" . self::SCRIPT . "')"]);

        self::assertFalse(str_contains($pantalla['salida'], self::SCRIPT), 'La pantalla escribe el script tal cual');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_configuracion/tablasPrincipales.php:56, 82, 107
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Si una de las tres tablas está vacía, la pantalla deja un aviso de PHP al
     *   recorrerla.
     * @que-deberia-ocurrir Que pinte la tabla vacía sin avisos.
     * @por-que-ocurre Una lectura sin filas no trae la lista de datos, y la pantalla la recorre sin
     *   comprobar que esté.
     * @como-deberia-funcionar Recorrer una lista vacía cuando no hay filas.
     */
    public function test_conUnaTablaVaciaLaPantallaNoDejaAvisos(): void
    {
        $pantalla = $this->ejecutar('tablasPrincipales.php', [], ['DELETE FROM formasPago']);

        self::assertSame('', $pantalla['errores'], 'Sin avisos de PHP.');
    }

    /**
     * @estado rojo
     * @codigo-afectado head.php:17-26; modulos/mod_configuracion/tablasPrincipales.php:6-17
     *
     * @group defecto
     * @group permisos
     * @group forzado
     * @group alto
     *
     * @que-ocurre-hoy Un usuario con sesión pero sin el permiso de la vista abre las tablas principales
     *   por su dirección y las ve, con sus botones para cambiarlas.
     * @que-deberia-ocurrir Que la pantalla no se sirva sin su permiso.
     * @por-que-ocurre Ni la cabecera común ni la pantalla consultan el permiso de la vista que se
     *   sirve.
     * @como-deberia-funcionar Comprobar el permiso de la vista antes de pintarla, en el arranque común.
     */
    public function test_sinPermisoDeVistaNoSeSirveLaPantalla(): void
    {
        $pantalla = $this->ejecutar('tablasPrincipales.php', [], [
            $this->quitarPermisoDeVista(),
            "INSERT INTO formasPago (descripcion) VALUES ('Solo con permiso')",
        ]);

        self::assertFalse(str_contains($pantalla['salida'], 'Solo con permiso'), 'Sin el permiso de la vista se sirve la pantalla');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_configuracion/tareas.php:1-53
     *
     * @group defecto
     * @group guardado
     * @group forzado
     * @group critico
     *
     * @que-ocurre-hoy Una petición sin sesión a la petición de fondo da de alta un tipo de IVA.
     * @que-deberia-ocurrir Que la petición se rechace sin sesión válida.
     * @por-que-ocurre La petición de fondo no comprueba la sesión: el arranque común atiende como
     *   invitado a quien no la tiene.
     * @como-deberia-funcionar Rechazar la petición sin sesión válida, en el arranque común.
     */
    public function test_sinSesionNoSeGuarda(): void
    {
        $peticion = $this->ejecutar('tareas.php', $this->guardarIva('IVA sin sesion'), [], false);

        self::assertSame(0, $peticion['filas'], 'Se guardó sin sesión');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_configuracion/tareas.php:14-53; modulos/mod_configuracion/acces.xml
     *
     * @group defecto
     * @group permisos
     * @group forzado
     * @group alto
     *
     * @que-ocurre-hoy Un usuario sin el permiso de la vista de tablas principales da de alta un tipo de
     *   IVA con la petición de fondo.
     * @que-deberia-ocurrir Que el guardado se rechace sin permiso.
     * @por-que-ocurre El guardado de las tres tablas no consulta ningún permiso, y el módulo no declara
     *   acciones para ellas: solo las de los tipos de relación.
     * @como-deberia-funcionar Declarar un permiso para cambiar las tablas principales y consultarlo
     *   antes de guardar.
     */
    public function test_sinPermisoNoSeGuarda(): void
    {
        $peticion = $this->ejecutar('tareas.php', $this->guardarIva('IVA sin permiso'), [$this->quitarPermisoDeVista()]);

        self::assertSame(0, $peticion['filas'], 'Se guardó sin permiso');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_configuracion/funciones.php:22-50, 58-79, 85-112
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Abrir la ventana para añadir una fila deja avisos de PHP, en las tres tablas.
     * @que-deberia-ocurrir Que la ventana vacía se componga sin avisos.
     * @por-que-ocurre Sin número no se leen datos, y la ventana los escribe igualmente.
     * @como-deberia-funcionar Dar a los datos un valor de partida vacío.
     */
    public function test_abrirLaVentanaParaAnadirNoDejaAvisos(): void
    {
        $errores = '';
        foreach (['iva', 'forma', 'vencimiento'] as $tabla) {
            $errores .= $this->ejecutar('tareas.php', ['pulsado' => 'abrirModalModificar', 'dedonde' => $tabla, 'id' => '0'])['errores'];
        }

        self::assertSame('', $errores, 'Sin avisos de PHP.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_configuracion/funciones.php:32-50, 68-79, 95-112
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy La ventana de una fila escribe sus datos tal como están guardados: una forma de
     *   pago cuya descripción cierra el campo y trae un script lo ejecuta al abrir la ventana.
     * @que-deberia-ocurrir Que los datos se escriban como texto.
     * @por-que-ocurre La ventana pega los datos en su HTML sin escaparlos.
     * @como-deberia-funcionar Escapar cada valor al componer la ventana.
     */
    public function test_laVentanaEscribeLosDatosComoTexto(): void
    {
        $peticion = $this->ejecutar('tareas.php', ['pulsado' => 'abrirModalModificar', 'dedonde' => 'forma', 'id' => ':ultimaForma'], [
            "INSERT INTO formasPago (descripcion) VALUES ('\"" . '>' . self::SCRIPT . "')",
        ]);

        self::assertFalse(str_contains((string) (json_decode($peticion['salida'], true)['html'] ?? ''), self::SCRIPT), 'La ventana escribe el script tal cual');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_configuracion/tareas.php:9-53, 27-51; modulos/mod_configuracion/funciones.php:9-20
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Una orden que la petición de fondo no conoce, o una tabla que no es ninguna de las
     *   tres, responde `null` y deja avisos de PHP.
     * @que-deberia-ocurrir Que responda un error.
     * @por-que-ocurre La respuesta solo se define dentro de las órdenes y tablas conocidas, y se escribe
     *   al final sea cual sea la orden.
     * @como-deberia-funcionar Responder un error a las demás órdenes y tablas.
     */
    public function test_unaOrdenOUnaTablaDesconocidaRespondeUnError(): void
    {
        $orden = $this->ejecutar('tareas.php', ['pulsado' => 'ordenQueNoExiste']);
        $tabla = $this->ejecutar('tareas.php', ['pulsado' => 'ModificarTabla', 'dedonde' => 'otra', 'id' => '0', 'descripcion' => 'x', 'iva' => '', 'recargo' => '']);

        self::assertNotSame('null', trim($orden['salida']), 'La orden desconocida responde null');
        self::assertNotSame('null', trim($tabla['salida']), 'La tabla desconocida responde null');
    }

    /**
     * Ejecuta una pantalla o la petición de fondo del módulo en un PHP aparte y devuelve lo que escribe,
     * lo que deja en la salida de errores y cuántas filas de las tres tablas tienen la descripción del
     * formulario.
     *
     * Con sesión, el usuario de pruebas recibe todos los permisos del módulo antes de la preparación,
     * y sus permisos se vuelven a leer después. En las sentencias de preparación, `:usuario` es el
     * usuario de pruebas; en el formulario, `:ultimaForma` es la última forma de pago tras la
     * preparación.
     *
     * @param array<string,string> $formulario
     * @param list<string> $preparacion
     * @return array{salida:string, errores:string, filas:int}
     */
    private function ejecutar(string $fichero, array $formulario = [], array $preparacion = [], bool $conSesion = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => 1, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $ruta = '/TPVFox/modulos/mod_configuracion/' . $fichero;
        $descripcion = $formulario['descripcion'] ?? '';
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export($ruta, true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_configuracion', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_configuracion\'");'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, [":usuario" => "' . $this->idUsuario . '"])); }'
            . ' $ultimaForma = (string) $BDTpv->query("SELECT COALESCE(MAX(id), 0) FROM formasPago")->fetch_row()[0];'
            . ' $_POST = array_map(fn ($valor) => strtr($valor, [":ultimaForma" => $ultimaForma]), ' . var_export($formulario, true) . ');'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $descripcion = ' . var_export($descripcion, true) . ';'
            . ' register_shutdown_function(function () use ($BDTpv, $descripcion) { $s = $BDTpv->prepare("SELECT (SELECT COUNT(*) FROM iva WHERE descripcionIva = ?) + (SELECT COUNT(*) FROM formasPago WHERE descripcion = ?) + (SELECT COUNT(*) FROM tiposVencimiento WHERE descripcion = ?)");'
            . ' $s->bind_param("sss", $descripcion, $descripcion, $descripcion); $s->execute(); echo "\n@@FILAS@@" . $s->get_result()->fetch_row()[0]; });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'tablas');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $filas] = array_pad(explode('@@FILAS@@', implode("\n", $salida), 2), 2, '-1');
        $ejecucion = ['salida' => $escrito, 'errores' => (string) file_get_contents($errores), 'filas' => (int) $filas];
        unlink($errores);

        return $ejecucion;
    }

    /** @return array<string,string> La petición de guardar un tipo de IVA nuevo con esa descripción. */
    private function guardarIva(string $descripcion): array
    {
        return ['pulsado' => 'ModificarTabla', 'dedonde' => 'iva', 'id' => '0', 'descripcion' => $descripcion, 'iva' => '4.00', 'recargo' => '0.50'];
    }

    /** La sentencia que quita al usuario de pruebas el permiso de la vista de tablas principales. */
    private function quitarPermisoDeVista(): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_configuracion' AND vista = 'tablasPrincipales.php' AND accion IS NULL";
    }
}

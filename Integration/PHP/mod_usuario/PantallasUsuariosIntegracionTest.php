<?php

/**
 * Las pantallas de `mod_usuario`: el listado de usuarios (`ListaUsuarios.php`) y la ficha de un usuario
 * (`usuario.php`), que da de alta, modifica y guarda los permisos marcados.
 *
 * Cada pantalla se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas, los permisos del módulo concedidos salvo los que el caso quite, y una transacción que se
 * deshace al terminar. Los usuarios que siembra el caso quedan con un nombre —`:u1`, `:u2`…— para la
 * dirección y el formulario. Al terminar, también tras guardar y redirigir, el proceso responde lo que
 * dan las consultas del caso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModUsuario;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasUsuariosIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    private int $idUsuario;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
    }

    /** @comportamiento El listado enseña cada usuario con su casilla, su número, su nombre de acceso, su nombre, su fecha, su grupo y su estado. */
    public function test_elListadoEnsenaCadaUsuario(): void
    {
        $pantalla = $this->pintar('ListaUsuarios.php', [], [], [$this->usuario('ana', 'Ana Pérez')]);
        $id = $pantalla['ids'][':u1'];

        self::assertStringContainsString('value="' . $id . '">', $pantalla['html']);
        self::assertMatchesRegularExpression('#<td>' . $id . '</td>\s*<td>ana</td>\s*<td>Ana Pérez</td>\s*<td>2026-01-01</td>\s*<td>1</td>\s*<td>activo</td>#', $pantalla['html']);
        self::assertStringContainsString("onclick=\"metodoClick('AccionesMultiples');\"", $pantalla['html']);
    }

    /** @comportamiento Sin número, la ficha es la del alta: los datos vacíos, la fecha de hoy y la contraseña en blanco. */
    public function test_laFichaSinNumeroEsLaDelAlta(): void
    {
        $pantalla = $this->pintar('usuario.php');

        self::assertStringContainsString('<h1 class="text-center"> Crear Usuario</h1>', $pantalla['html']);
        self::assertStringContainsString('name="username"  placeholder="usuario/login" value="">', $pantalla['html']);
        self::assertStringContainsString('value="' . date('Y-m-d') . '" readonly>', $pantalla['html']);
        self::assertStringContainsString('name="password" placeholder="contraseña" value="" required>', $pantalla['html']);
    }

    /**
     * @comportamiento Con número, la ficha es la de modificar: el nombre de acceso no se puede cambiar, la
     *   contraseña se enseña como la palabra «password», el estado guardado sale elegido y los permisos van
     *   en su panel.
     */
    public function test_laFichaConNumeroEsLaDeModificar(): void
    {
        $pantalla = $this->pintar('usuario.php', ['id' => ':u1'], [], [$this->usuario('ana', 'Ana Pérez'), "INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES (:tienda, :u1, 0, 0)"]);

        self::assertStringContainsString('<h1 class="text-center"> Modificar Usuario</h1>', $pantalla['html']);
        self::assertStringContainsString('name="username" readonly placeholder="usuario/login" value="ana">', $pantalla['html']);
        self::assertStringContainsString('name="password" placeholder="contraseña" value="password" required>', $pantalla['html']);
        self::assertMatchesRegularExpression('#<option value="activo" selected>\s*activo#', $pantalla['html']);
        self::assertStringContainsString('href="#collapse3">Permisos</a>', $pantalla['html']);
    }

    /** @comportamiento Guardar el alta da de alta el usuario con su índice en la tienda de la sesión. */
    public function test_guardarElAlta(): void
    {
        $alta = $this->pintar('usuario.php', [], ['username' => 'marta', 'nombreEmpleado' => 'Marta Ruiz', 'fecha' => '2026-10-23', 'idUsuario' => '', 'grupo' => '1', 'estado' => 'activo', 'password' => 'Clave-1'], [], [
            'usuario' => "SELECT CONCAT(nombre, '|', estado) FROM usuarios WHERE username = 'marta'",
            'indice' => "SELECT COUNT(*) FROM indices i JOIN usuarios u ON u.id = i.idUsuario WHERE u.username = 'marta' AND i.idTienda = :tienda",
        ]);

        self::assertSame('Marta Ruiz|activo', $alta['consultas']['usuario']);
        self::assertSame('1', $alta['consultas']['indice']);
    }

    /** @comportamiento Guardar la ficha de un usuario cambia sus datos y deja concedidos exactamente los permisos marcados. */
    public function test_guardarLaModificacionConSusPermisos(): void
    {
        $guardar = $this->pintar('usuario.php', ['id' => ':u1'], ['username' => 'ana', 'nombreEmpleado' => 'Ana Gil', 'fecha' => '2026-01-01', 'idUsuario' => ':u1', 'grupo' => '1', 'estado' => 'activo', 'password' => 'password', 'permiso_0' => '1'], [
            $this->usuario('ana', 'Ana Pérez'),
            "INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES (:tienda, :u1, 0, 0)",
        ], [
            'nombre' => 'SELECT nombre FROM usuarios WHERE id = :u1',
            'concedidos' => 'SELECT COUNT(*) FROM permisos WHERE idUsuario = :u1 AND permiso = 1',
        ]);

        self::assertSame('Ana Gil', $guardar['consultas']['nombre']);
        self::assertSame('1', $guardar['consultas']['concedidos']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/ListaUsuarios.php:90-94
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy El listado escribe el nombre de acceso y el nombre del empleado tal cual: un nombre con
     *   una etiqueta de script la ejecuta al abrir el listado.
     * @que-deberia-ocurrir Que cada dato se escriba como texto.
     * @por-que-ocurre El listado escribe lo leído sin escaparlo.
     * @como-deberia-funcionar Escapar cada dato al escribirlo.
     */
    public function test_elListadoEscribeElNombreComoTexto(): void
    {
        $pantalla = $this->pintar('ListaUsuarios.php', [], [], [$this->usuario('ana', self::SCRIPT)]);

        self::assertStringNotContainsString('<td>' . self::SCRIPT . '</td>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/usuario.php:171
     * @codigo-afectado modulos/mod_usuario/usuario.php:185
     * @codigo-afectado modulos/mod_usuario/usuario.php:190
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy La ficha escribe el nombre del empleado tal cual en su título y en su caja: un nombre
     *   con una etiqueta de script la ejecuta al abrir la ficha.
     * @que-deberia-ocurrir Que el nombre se escriba como texto.
     * @por-que-ocurre La ficha escribe lo leído sin escaparlo.
     * @como-deberia-funcionar Escapar cada dato al escribirlo.
     */
    public function test_laFichaEscribeElNombreComoTexto(): void
    {
        $pantalla = $this->pintar('usuario.php', ['id' => ':u1'], [], [$this->usuario('ana', self::SCRIPT), "INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES (:tienda, :u1, 0, 0)"]);

        self::assertStringNotContainsString('<h3>' . self::SCRIPT . '</h3>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/usuario.php:117-125
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Sin el permiso de cambiar permisos, guardar la ficha cambia igual los permisos que
     *   llegan marcados en el formulario: las casillas solo se desactivan en la página.
     * @que-deberia-ocurrir Que sin ese permiso los permisos del usuario no cambien.
     * @por-que-ocurre El guardado recorre los permisos y escribe lo que llega, sin consultar la acción que lo
     *   permite.
     * @como-deberia-funcionar Consultar el permiso de cambiar permisos antes de escribirlos, y no tocarlos
     *   sin él.
     */
    public function test_sinElPermisoDePermisosNoCambianLosPermisos(): void
    {
        $guardar = $this->pintar('usuario.php', ['id' => ':u1'], ['username' => 'ana', 'nombreEmpleado' => 'Ana', 'fecha' => '2026-01-01', 'idUsuario' => ':u1', 'grupo' => '1', 'estado' => 'activo', 'password' => 'password', 'permiso_0' => '1', 'permiso_1' => '1'], [
            $this->usuario('ana', 'Ana'),
            "INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES (:tienda, :u1, 0, 0)",
            "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_usuario' AND accion = 'permiso'",
        ], ['concedidos' => 'SELECT COUNT(*) FROM permisos WHERE idUsuario = :u1 AND permiso = 1'], ['concedidosAntes' => 'SELECT COUNT(*) FROM permisos WHERE idUsuario = :u1 AND permiso = 1']);

        self::assertSame($guardar['antes']['concedidosAntes'], $guardar['consultas']['concedidos']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/usuario.php:103
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Guardar la modificación de un usuario escribe en la página el texto «¿Estás entrando
     *   aqui?» antes de volver a la ficha.
     * @que-deberia-ocurrir Que el guardado no escriba nada antes de la redirección.
     * @por-que-ocurre Queda una línea de depuración en la rama de la modificación.
     * @como-deberia-funcionar Quitar la línea.
     */
    public function test_guardarLaModificacionNoEscribeTextoDeDepuracion(): void
    {
        $guardar = $this->pintar('usuario.php', ['id' => ':u1'], ['username' => 'ana', 'nombreEmpleado' => 'Ana', 'fecha' => '2026-01-01', 'idUsuario' => ':u1', 'grupo' => '1', 'estado' => 'activo', 'password' => 'password'], [
            $this->usuario('ana', 'Ana'),
            "INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES (:tienda, :u1, 0, 0)",
        ]);

        self::assertStringNotContainsString('¿Estás entrando aqui?', $guardar['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/usuario.php:66-69
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy La ficha de un número que no es de ningún usuario avisa del error con la sentencia de
     *   la lectura.
     * @que-deberia-ocurrir Que avise de que el usuario no existe, sin enseñar la sentencia.
     * @por-que-ocurre El aviso pega a su texto la sentencia que devuelve la lectura.
     * @como-deberia-funcionar Enseñar un mensaje para el usuario y dejar la sentencia en el registro de errores.
     */
    public function test_laFichaDeUnNumeroSinUsuarioNoEnsenaLaSentencia(): void
    {
        $pantalla = $this->pintar('usuario.php', ['id' => '999999']);

        self::assertStringContainsString('alert-danger', $pantalla['html']);
        self::assertStringNotContainsString('SELECT', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/usuario.php:58-63
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Abrir la ficha de un número que no es de ningún usuario le siembra los permisos, que
     *   quedan en la base sin usuario.
     * @que-deberia-ocurrir Que la ficha de un usuario que no existe no escriba nada.
     * @por-que-ocurre La ficha lee los permisos del número, y la lectura siembra los que falten, antes de
     *   comprobar que el usuario existe.
     * @como-deberia-funcionar Comprobar primero que el usuario existe, y leer sus permisos solo entonces.
     */
    public function test_laFichaDeUnNumeroSinUsuarioNoLeSiembraPermisos(): void
    {
        $pantalla = $this->pintar('usuario.php', ['id' => '999999'], [], [], ['permisos' => 'SELECT COUNT(*) FROM permisos WHERE idUsuario = 999999']);

        self::assertSame('0', $pantalla['consultas']['permisos']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/usuario.php:91-101
     * @codigo-afectado modulos/mod_usuario/usuario.php:126-129
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Dar de alta un nombre de acceso que ya existe termina la página con un error de la
     *   base, en lugar de avisar.
     * @que-deberia-ocurrir Que la ficha avise de que el nombre ya existe.
     * @por-que-ocurre Tras el rechazo, la ficha sigue con el número del usuario que no se creó y compone
     *   sentencias sin número.
     * @como-deberia-funcionar Ante el rechazo, avisar y volver a pintar la ficha del alta, sin releer nada.
     */
    public function test_unAltaRechazadaAvisaDelNombreRepetido(): void
    {
        $alta = $this->pintar('usuario.php', [], ['username' => 'ana', 'nombreEmpleado' => 'Otra Ana', 'fecha' => '2026-10-23', 'idUsuario' => '', 'grupo' => '1', 'estado' => 'activo', 'password' => 'Clave-1'], [$this->usuario('ana', 'Ana Pérez')]);

        self::assertStringNotContainsString('Fatal error', $alta['errores']);
        self::assertStringContainsString('Nombre de usuario ya existe!', $alta['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/usuario.php:86-90
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy La ficha toma cualquier envío por su guardado: el formulario de acceso, que tras perder
     *   la sesión se envía a la misma dirección, la hace guardar el usuario sin sus datos, y la página
     *   termina con un error de la base.
     * @que-deberia-ocurrir Que solo el botón de la ficha guarde; cualquier otro envío la enseña sin cambiar
     *   nada.
     * @por-que-ocurre La ficha guarda si llega cualquier dato por formulario.
     * @como-deberia-funcionar Guardar solo si llega un campo propio del formulario de la ficha.
     */
    public function test_unEnvioQueNoEsLaFichaNoGuardaNada(): void
    {
        $envio = $this->pintar('usuario.php', ['id' => ':u1'], ['usr' => 'pruebas', 'pwd' => 'Clave-1'], [
            $this->usuario('ana', 'Ana Pérez'),
            "INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES (:tienda, :u1, 0, 0)",
        ], [
            'nombre' => 'SELECT nombre FROM usuarios WHERE id = :u1',
            'concedidos' => 'SELECT COUNT(*) FROM permisos WHERE idUsuario = :u1 AND permiso = 1',
        ], ['concedidosAntes' => 'SELECT COUNT(*) FROM permisos WHERE idUsuario = :u1 AND permiso = 1']);

        self::assertStringNotContainsString('Fatal error', $envio['errores']);
        self::assertSame('Ana Pérez', $envio['consultas']['nombre']);
        self::assertSame($envio['antes']['concedidosAntes'], $envio['consultas']['concedidos']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/usuario.php:14
     * @codigo-afectado modulos/mod_usuario/usuario.php:117-125
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy Guardar la ficha abierta con un número con una condición añadida cambia los permisos de
     *   otros usuarios.
     * @que-deberia-ocurrir Que solo cambien los permisos del usuario de la ficha.
     * @por-que-ocurre La ficha toma el número de la dirección tal como llega, y con él lee y escribe los
     *   permisos.
     * @como-deberia-funcionar Convertir el número de la dirección en entero antes de usarlo.
     */
    public function test_laFichaConUnNumeroConCondicionNoCambiaLosPermisosDeOtros(): void
    {
        $guardar = $this->pintar('usuario.php', ['id' => ':u1 OR 1=1'], ['username' => 'ana', 'nombreEmpleado' => 'Ana', 'fecha' => '2026-01-01', 'idUsuario' => ':u1', 'grupo' => '1', 'estado' => 'activo', 'password' => 'password'], [
            $this->usuario('ana', 'Ana'),
            "INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES (:tienda, :u1, 0, 0)",
        ], ['concedidos' => 'SELECT COUNT(*) FROM permisos WHERE idUsuario = :usuario AND permiso = 1'], ['concedidosAntes' => 'SELECT COUNT(*) FROM permisos WHERE idUsuario = :usuario AND permiso = 1']);

        self::assertSame($guardar['antes']['concedidosAntes'], $guardar['consultas']['concedidos']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_usuario/usuario.php:117-125
     * @codigo-afectado modulos/mod_usuario/funciones.php:221-284
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Si la lista de permisos cambia entre pintar la ficha y guardarla —un permiso sembrado
     *   entre medias—, cada marca se guarda en el permiso que ocupa su posición, no en el que se marcó.
     * @que-deberia-ocurrir Que cada marca se guarde en el permiso de su casilla.
     * @por-que-ocurre Las casillas se nombran por su posición en la lista, y el guardado recorre la lista que
     *   lee al guardar.
     * @como-deberia-funcionar Nombrar cada casilla por su módulo, su vista y su acción.
     */
    public function test_cadaMarcaSeGuardaEnSuPermisoAunqueLaListaCambie(): void
    {
        $primero = "SELECT permiso FROM permisos WHERE idUsuario = :u1 AND modulo <> 'aaa' ORDER BY modulo, vista, accion LIMIT 1";
        $guardar = $this->pintar('usuario.php', ['id' => ':u1'], ['username' => 'ana', 'nombreEmpleado' => 'Ana', 'fecha' => '2026-01-01', 'idUsuario' => ':u1', 'grupo' => '1', 'estado' => 'activo', 'password' => 'password', 'permiso_0' => '1'], [
            $this->usuario('ana', 'Ana'),
            "INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES (:tienda, :u1, 0, 0)",
            "INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES (:u1, 'aaa', NULL, NULL, 0)",
        ], ['primero' => $primero]);

        self::assertSame('1', $guardar['consultas']['primero']);
    }

    private function usuario(string $acceso, string $nombre): string
    {
        return "INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('{$acceso}', MD5('anterior'), '2026-01-01', 1, 'activo', '{$nombre}')";
    }

    /**
     * Pinta una pantalla del módulo en un PHP aparte, con lo que llega por la dirección y por el formulario.
     * Las consultas de `antes` se responden tras sembrar la preparación; las del caso, al terminar.
     *
     * @param array<string, string> $direccion
     * @param array<string, string> $formulario
     * @param list<string> $preparacion
     * @param array<string, string> $consultas
     * @param array<string, string> $antes
     * @return array{html: string, errores: string, ids: array<string, string>, consultas: array<string, ?string>, antes: array<string, ?string>}
     */
    private function pintar(string $fichero, array $direccion = [], array $formulario = [], array $preparacion = [], array $consultas = [], array $antes = []): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_usuario/{$fichero}", true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_usuario', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' new ClasePermisos($Usuario, $BDTpv);'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_usuario\'");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $usuarios = 0;'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' if (str_starts_with($sql, "INSERT INTO usuarios (username")) { $sembrados[":u" . ++$usuarios] = (string) $BDTpv->insert_id; } }'
            . ' if (isset($sembrados[":u1"])) { new ClasePermisos(["id" => $sembrados[":u1"], "group_id" => 1], $BDTpv); }'
            . ' $previos = []; foreach (' . var_export($antes, true) . ' as $nombre => $sql) { $fila = $BDTpv->query(strtr($sql, $sembrados))->fetch_row(); $previos[$nombre] = $fila === null ? null : (string) $fila[0]; }'
            . ' $_GET = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($direccion, true) . ');'
            . ' $_POST = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($formulario, true) . ');'
            . ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);'
            . ' register_shutdown_function(function () use ($BDTpv, &$sembrados, &$previos) { $resultados = [];'
            . ' foreach (' . var_export($consultas, true) . ' as $nombre => $sql) { $fila = $BDTpv->query(strtr($sql, $sembrados))->fetch_row(); $resultados[$nombre] = $fila === null ? null : (string) $fila[0]; }'
            . ' echo "\n@@ESTADO@@" . json_encode(["ids" => $sembrados, "consultas" => $resultados, "antes" => $previos]); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'pantallas');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $estado] = array_pad(explode('@@ESTADO@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['ids' => [], 'consultas' => [], 'antes' => []];
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $pintada;
    }
}

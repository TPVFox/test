<?php

/**
 * Las pantallas de las tiendas: el listado (`ListaTiendas.php`) y la ficha (`tienda.php`), que enseña
 * una tienda, da de alta una nueva y guarda los cambios.
 *
 * Cada pantalla se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas ya correcta. Tras el arranque común se abre una transacción en la conexión de la pantalla, y
 * la clase de las tiendas escribe por esa misma conexión: lo que siembra el caso y lo que guarda la
 * ficha se deshace al terminar el proceso. Antes de terminar, el proceso escribe cómo quedó la tabla
 * de tiendas. Los datos del correo y la clave de la tienda web son de prueba.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModTienda;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasTiendaIntegracionTest extends CasoIntegracion
{
    /** Lo que se escribe en la página para ver si un valor guardado llega a ejecutarse. */
    private const SCRIPT = '<script>window.inyectado=1</script>';

    /** Los datos del servidor de correo de prueba, como los guarda la ficha. */
    private const CORREO = '[{"emailTienda":"tienda@ejemplo.invalid"},{"nombreRemitente":"Tienda de pruebas"},{"host":"smtp.ejemplo.invalid"},'
        . '{"SMTPPort":"465"},{"SMTPAuth":"true"},{"SMTPUsuario":"usuario-de-prueba"},{"SMTPPassword":"clave-de-prueba-1234"}]';

    private int $idUsuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idUsuario = (new Siembra($this->db))->usuarioPorDefecto();
    }

    /**
     * @comportamiento El listado enseña cada tienda con su número, nombre comercial, tipo, razón
     *   social, NIF, dirección, teléfono, año y estado, con una casilla para marcarla, y ofrece añadir
     *   una tienda y modificar la marcada.
     */
    public function test_elListadoEnsenaCadaTiendaConSusDatos(): void
    {
        $pantalla = $this->pintar('ListaTiendas.php', [], [], [$this->tienda('fisica', ['razonsocial' => 'Tienda del listado S.L.'])]);

        self::assertStringContainsString('<td>Tienda del listado S.L.</td>', $pantalla['html']);
        self::assertMatchesRegularExpression('#<input type="checkbox" name="checkUsu\d+" value="' . $pantalla['ultima'] . '">#', $pantalla['html']);
        self::assertStringContainsString("metodoClick('AgregarTienda');", $pantalla['html']);
        self::assertStringContainsString("metodoClick('VerTienda');", $pantalla['html']);
    }

    /** @comportamiento La ficha de una tienda la enseña con sus datos, para modificarla. */
    public function test_laFichaEnsenaLosDatosDeLaTienda(): void
    {
        $pantalla = $this->pintar('tienda.php', ['id' => ':ultima'], [], [$this->tienda('fisica', ['razonsocial' => 'Tienda de la ficha S.L.'])]);

        self::assertStringContainsString('Modificar Tienda:Tienda de pruebas - Tienda de la ficha S.L.', $pantalla['html']);
        self::assertStringContainsString('name="idtienda" value="' . $pantalla['ultima'] . '" readonly', $pantalla['html']);
        self::assertMatchesRegularExpression('/value="fisica"[^>]*checked/', $pantalla['html']);
    }

    /**
     * @comportamiento La ficha sin número es la de crear: propone una tienda física y no deja elegir
     *   el tipo principal ni el web si ya hay una de cada uno.
     */
    public function test_laFichaSinNumeroEsLaDeCrear(): void
    {
        $pantalla = $this->pintar('tienda.php', [], [], [$this->tienda('web')]);

        self::assertStringContainsString('Crear Tienda:', $pantalla['html']);
        self::assertMatchesRegularExpression('/value="fisica"[^>]*checked/', $pantalla['html']);
        self::assertMatchesRegularExpression('/value="principal"[^>]*disabled/', $pantalla['html']);
        self::assertMatchesRegularExpression('/value="web"[^>]*disabled/', $pantalla['html']);
    }

    /**
     * @comportamiento Guardar la ficha sin número da de alta la tienda, lo avisa con su número y pasa
     *   la dirección a la de esa tienda, para seguir modificándola.
     */
    public function test_guardarLaFichaSinNumeroDaDeAltaLaTienda(): void
    {
        $pantalla = $this->pintar('tienda.php', [], $this->formulario(['razonsocial' => 'Alta por la ficha S.L.']));

        $alta = $this->tiendaDe($pantalla, 'Alta por la ficha S.L.');
        self::assertNotNull($alta, 'La tienda no se dio de alta');
        self::assertStringContainsString('Nueva tienda creada con id.' . $alta['idTienda'], $pantalla['html']);
        self::assertStringContainsString("history.pushState(null,'','?id='+" . $alta['idTienda'] . ')', $pantalla['html']);
    }

    /** @comportamiento Guardar la ficha de una tienda escribe sus datos y avisa de que se modificó. */
    public function test_guardarLaFichaDeUnaTiendaLaModifica(): void
    {
        $pantalla = $this->pintar(
            'tienda.php',
            ['id' => ':ultima'],
            $this->formulario(['idtienda' => ':ultima', 'razonsocial' => 'Modificada por la ficha S.L.', 'estado' => 'Activo']),
            [$this->tienda('fisica')]
        );

        $modificada = $this->tiendaDe($pantalla, 'Modificada por la ficha S.L.');
        self::assertSame($pantalla['ultima'], $modificada['idTienda'] ?? null);
        self::assertSame('Activo', $modificada['estado']);
        self::assertStringContainsString('Modificada correctamente la tienda.', $pantalla['html']);
    }

    /**
     * @comportamiento La ficha de la tienda principal solo ofrece su propio tipo, desactivado, y lo
     *   envía en un campo oculto; enseña los datos de su servidor de correo.
     */
    public function test_laFichaDeLaPrincipalNoDejaCambiarSuTipoYEnsenaElCorreo(): void
    {
        $pantalla = $this->pintar('tienda.php', ['id' => ':principal'], [], [$this->correoDeLaPrincipal(self::CORREO)]);

        self::assertStringNotContainsString('value="fisica"', $pantalla['html']);
        self::assertStringContainsString('<input type="hidden" name ="tipoTienda" value="principal">', $pantalla['html']);
        self::assertStringContainsString('value="smtp.ejemplo.invalid"', $pantalla['html']);
        self::assertStringContainsString('value="usuario-de-prueba"', $pantalla['html']);
    }

    /** @comportamiento La ficha de la tienda web enseña su dominio. */
    public function test_laFichaDeLaWebEnsenaSuDominio(): void
    {
        $pantalla = $this->pintar('tienda.php', ['id' => ':ultima'], [], [$this->tienda('web', ['dominio' => 'tienda.ejemplo.invalid'])]);

        self::assertStringContainsString('name="dominio" placeholder="dominio.com" value="tienda.ejemplo.invalid"', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/ListaTiendas.php:78-86
     *
     * @group defecto
     * @group listado
     * @group alto
     *
     * @que-ocurre-hoy El listado escribe los datos de cada tienda tal como están guardados: una razón
     *   social con un script se ejecuta en la página de quien abre el listado.
     * @que-deberia-ocurrir Que los datos se escriban como texto.
     * @por-que-ocurre Cada celda escribe el valor guardado sin escapar.
     * @como-deberia-funcionar Escapar cada valor al escribirlo en la página.
     */
    public function test_elListadoEscribeLosDatosComoTexto(): void
    {
        $pantalla = $this->pintar('ListaTiendas.php', [], [], [$this->tienda('fisica', ['razonsocial' => self::SCRIPT])]);

        self::assertFalse(str_contains($pantalla['html'], self::SCRIPT), 'El listado escribe el script tal cual');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/tienda.php:166-282
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy La ficha escribe los datos de la tienda tal como están guardados, en el título y
     *   en los campos: una razón social con un script se ejecuta al abrir la ficha.
     * @que-deberia-ocurrir Que los datos se escriban como texto.
     * @por-que-ocurre El título y cada campo escriben el valor guardado sin escapar.
     * @como-deberia-funcionar Escapar cada valor al escribirlo en la página.
     */
    public function test_laFichaEscribeLosDatosComoTexto(): void
    {
        $pantalla = $this->pintar('tienda.php', ['id' => ':ultima'], [], [$this->tienda('fisica', ['razonsocial' => self::SCRIPT])]);

        self::assertFalse(str_contains($pantalla['html'], self::SCRIPT), 'La ficha escribe el script tal cual');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/tienda.php:265
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy La ficha de la tienda principal escribe la contraseña del servidor de correo en
     *   la página, como valor de su campo: la lee cualquiera que abra la ficha y mire el código.
     * @que-deberia-ocurrir Que la contraseña guardada no salga en la página: el campo queda vacío y,
     *   si se envía vacío, se conserva la guardada.
     * @por-que-ocurre El campo de la contraseña se rellena con el valor guardado.
     * @como-deberia-funcionar No escribir la contraseña, y no sobrescribirla al guardar si el campo
     *   llega vacío.
     */
    public function test_laFichaNoEscribeLaContrasenaDelCorreo(): void
    {
        $pantalla = $this->pintar('tienda.php', ['id' => ':principal'], [], [$this->correoDeLaPrincipal(self::CORREO)]);

        self::assertFalse(str_contains($pantalla['html'], 'clave-de-prueba-1234'), 'La contraseña del correo sale en la página');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/tienda.php:282
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy La ficha de la tienda web escribe su clave de la API en claro.
     * @que-deberia-ocurrir Que la clave guardada no salga en la página.
     * @por-que-ocurre El campo de la clave es de texto y se rellena con el valor guardado.
     * @como-deberia-funcionar No escribir la clave, y no sobrescribirla al guardar si el campo llega
     *   vacío.
     */
    public function test_laFichaNoEscribeLaClaveDeLaTiendaWeb(): void
    {
        $pantalla = $this->pintar('tienda.php', ['id' => ':ultima'], [], [$this->tienda('web', ['key_api' => 'clave-api-de-prueba'])]);

        self::assertFalse(str_contains($pantalla['html'], 'clave-api-de-prueba'), 'La clave de la API sale en la página');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/tienda.php:42-50, 81-99
     *
     * @group defecto
     * @group guardado
     * @group forzado
     * @group alto
     *
     * @que-ocurre-hoy Una petición de la ficha que pide el tipo principal, habiendo ya una tienda
     *   principal, da de alta una segunda: la ficha solo lo impide desactivando el botón.
     * @que-deberia-ocurrir Que no se cree, y la ficha avise de que ya hay una tienda principal.
     * @por-que-ocurre El guardado no comprueba el tipo que recibe.
     * @como-deberia-funcionar Comprobar al guardar que no haya ya una tienda principal, ni una web si
     *   se pide ese tipo.
     */
    public function test_noSeCreaUnaSegundaTiendaPrincipal(): void
    {
        $pantalla = $this->pintar('tienda.php', [], $this->formulario(['tipoTienda' => 'principal', 'razonsocial' => 'Segunda principal S.L.']));

        self::assertNull($this->tiendaDe($pantalla, 'Segunda principal S.L.'), 'Se creó una segunda tienda principal');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/tienda.php:42-50, 81-99
     *
     * @group defecto
     * @group guardado
     * @group forzado
     * @group alto
     *
     * @que-ocurre-hoy Una petición de la ficha de la tienda principal que pide otro tipo la convierte
     *   en tienda física: la ficha solo lo impide no pintando los otros botones.
     * @que-deberia-ocurrir Que la principal conserve su tipo.
     * @por-que-ocurre El guardado escribe el tipo que recibe sin comprobarlo.
     * @como-deberia-funcionar No cambiar el tipo de la tienda principal al guardar.
     */
    public function test_laPrincipalNoCambiaDeTipo(): void
    {
        $pantalla = $this->pintar('tienda.php', ['id' => ':principal'], ['idtienda' => ':principal', 'tipoTienda' => 'fisica']);

        self::assertSame('principal', $this->tiendaPorNumero($pantalla, $pantalla['principal'])['tipoTienda']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/tienda.php:81-99
     *
     * @group defecto
     * @group permisos
     * @group forzado
     * @group medio
     *
     * @que-ocurre-hoy Un usuario sin el permiso de crear tiendas da de alta una enviando la ficha.
     * @que-deberia-ocurrir Que el alta se rechace sin ese permiso.
     * @por-que-ocurre El módulo declara el permiso, pero ni el listado ni la ficha lo consultan.
     * @como-deberia-funcionar Consultar el permiso de crear antes de dar de alta, y el de modificar
     *   antes de guardar cambios.
     */
    public function test_sinPermisoDeCrearNoSeDaDeAlta(): void
    {
        $pantalla = $this->pintar('tienda.php', [], $this->formulario(['razonsocial' => 'Alta sin permiso S.L.']), [$this->quitarPermiso('crear')]);

        self::assertNull($this->tiendaDe($pantalla, 'Alta sin permiso S.L.'), 'Se dio de alta sin el permiso de crear');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/tienda.php:81-99
     *
     * @group defecto
     * @group permisos
     * @group forzado
     * @group medio
     *
     * @que-ocurre-hoy Un usuario sin el permiso de modificar tiendas cambia los datos de una enviando
     *   su ficha.
     * @que-deberia-ocurrir Que el cambio se rechace sin ese permiso.
     * @por-que-ocurre El módulo declara el permiso, pero ni el listado ni la ficha lo consultan.
     * @como-deberia-funcionar Consultar el permiso de modificar antes de guardar cambios.
     */
    public function test_sinPermisoDeModificarNoSeModifica(): void
    {
        $pantalla = $this->pintar(
            'tienda.php',
            ['id' => ':ultima'],
            $this->formulario(['idtienda' => ':ultima', 'razonsocial' => 'Cambio sin permiso S.L.']),
            [$this->tienda('fisica'), $this->quitarPermiso('modificar')]
        );

        self::assertNull($this->tiendaDe($pantalla, 'Cambio sin permiso S.L.'), 'Se modificó sin el permiso de modificar');
    }

    /**
     * @estado rojo
     * @codigo-afectado head.php:17-26; modulos/mod_tienda/ListaTiendas.php:2-4
     *
     * @group defecto
     * @group permisos
     * @group forzado
     * @group alto
     *
     * @que-ocurre-hoy Un usuario con sesión pero sin el permiso de la vista abre el listado por su
     *   dirección y lee las tiendas, con su NIF, su dirección y su teléfono.
     * @que-deberia-ocurrir Que el listado no se sirva sin su permiso.
     * @por-que-ocurre Ni la cabecera común ni la pantalla consultan el permiso de la vista que se
     *   sirve; solo el menú lo mira para enseñar la entrada.
     * @como-deberia-funcionar Comprobar el permiso de la vista antes de pintarla, en el arranque común.
     */
    public function test_sinPermisoDeVistaNoSeSirveElListado(): void
    {
        $pantalla = $this->pintar('ListaTiendas.php', [], [], [$this->quitarPermisoDeVista('ListaTiendas.php'), $this->tienda('fisica', ['razonsocial' => 'Solo para quien puede verla'])]);

        self::assertFalse(str_contains($pantalla['html'], 'Solo para quien puede verla'), 'Sin el permiso de la vista se sirve el listado');
    }

    /**
     * @estado rojo
     * @codigo-afectado head.php:17-26; modulos/mod_tienda/tienda.php:8-10
     *
     * @group defecto
     * @group permisos
     * @group forzado
     * @group alto
     *
     * @que-ocurre-hoy Un usuario con sesión pero sin el permiso de la vista abre la ficha de una tienda
     *   por su dirección, y puede guardarla.
     * @que-deberia-ocurrir Que la ficha no se sirva sin su permiso.
     * @por-que-ocurre Ni la cabecera común ni la pantalla consultan el permiso de la vista que se
     *   sirve.
     * @como-deberia-funcionar Comprobar el permiso de la vista antes de pintarla, en el arranque común.
     */
    public function test_sinPermisoDeVistaNoSeSirveLaFicha(): void
    {
        $pantalla = $this->pintar('tienda.php', ['id' => ':ultima'], [], [$this->quitarPermisoDeVista('tienda.php'), $this->tienda('fisica', ['razonsocial' => 'Solo para quien puede verla'])]);

        self::assertFalse(str_contains($pantalla['html'], 'Solo para quien puede verla'), 'Sin el permiso de la vista se sirve la ficha');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/tienda.php:19-26, 136-148, 255-265
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy La ficha de una tienda principal sin datos de correo guardados deja avisos de
     *   PHP: recorre un valor vacío y lee del correo por defecto dos datos que no trae.
     * @que-deberia-ocurrir Que avise en la pantalla de que no hay datos de correo, sin avisos de PHP.
     * @por-que-ocurre Se recorre lo leído sin comprobar que lo haya, y el correo por defecto nombra el
     *   usuario con otra clave y no tiene contraseña.
     * @como-deberia-funcionar Comprobar lo leído antes de recorrerlo, y dar al correo por defecto las
     *   mismas claves que la ficha.
     */
    public function test_laFichaDeUnaPrincipalSinCorreoNoDejaAvisos(): void
    {
        $pantalla = $this->pintar('tienda.php', ['id' => ':principal'], [], [$this->correoDeLaPrincipal(null)]);

        self::assertStringContainsString('No se cargo correctamente datos servidor email', $pantalla['html']);
        self::assertSame('', $pantalla['errores'], 'Sin avisos de PHP.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/tienda.php:119-129
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy La ficha de un número de tienda que no existe deja avisos de PHP y se pinta como
     *   la de una tienda vacía.
     * @que-deberia-ocurrir Que avise de que la tienda no existe, sin avisos de PHP.
     * @por-que-ocurre Se toma la primera fila leída sin comprobar que la haya.
     * @como-deberia-funcionar Comprobar que la tienda existe y avisar si no.
     */
    public function test_laFichaDeUnaTiendaQueNoExisteNoDejaAvisos(): void
    {
        $pantalla = $this->pintar('tienda.php', ['id' => '999999']);

        self::assertSame('', $pantalla['errores'], 'Sin avisos de PHP.');
    }

    /**
     * Pinta una pantalla del módulo en un PHP aparte y devuelve su HTML, lo que escribe en la salida de
     * errores y cómo quedó la tabla de tiendas.
     *
     * Tras el arranque común abre una transacción en la conexión de la pantalla y hace que la clase de
     * las tiendas escriba por ella; da al usuario todos los permisos del módulo, ejecuta las sentencias
     * de preparación y vuelve a leer sus permisos; al terminar el proceso la transacción se deshace. En
     * las sentencias, en la dirección y en el formulario, `:usuario` es el usuario de pruebas;
     * `:principal`, la tienda principal; y `:ultima`, la última tienda tras la preparación.
     *
     * @param array<string,string> $direccion
     * @param array<string,mixed> $formulario
     * @param list<string> $preparacion
     * @return array{html:string, errores:string, principal:string, ultima:string, tiendas:list<array<string,?string>>}
     */
    private function pintar(string $pantalla, array $direccion = [], array $formulario = [], array $preparacion = []): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => 1, 'razonsocial' => 'Tienda de pruebas'],
        ];
        $ruta = '/TPVFox/modulos/mod_tienda/' . $pantalla;
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export($ruta, true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_tienda', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $sustituir = [":usuario" => "' . $this->idUsuario . '", ":principal" => (string) $BDTpv->query("SELECT idTienda FROM tiendas WHERE tipoTienda = \'principal\' AND estado = \'Activo\' ORDER BY idTienda LIMIT 1")->fetch_row()[0]];'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_tienda\'");'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sustituir)); }'
            . ' $sustituir[":ultima"] = (string) $BDTpv->query("SELECT MAX(idTienda) FROM tiendas")->fetch_row()[0];'
            . ' $_GET = ' . var_export($direccion, true) . '; $_POST = ' . var_export($formulario, true) . ';'
            . ' array_walk_recursive($_GET, function (&$valor) use ($sustituir) { $valor = strtr($valor, $sustituir); });'
            . ' array_walk_recursive($_POST, function (&$valor) use ($sustituir) { $valor = strtr($valor, $sustituir); });'
            . ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);'
            . ' register_shutdown_function(function () use ($BDTpv, &$sustituir) { echo "\n@@TIENDAS@@" . json_encode(["principal" => $sustituir[":principal"], "ultima" => $sustituir[":ultima"],'
            . ' "tiendas" => $BDTpv->query("SELECT idTienda, tipoTienda, razonsocial, estado FROM tiendas ORDER BY idTienda")->fetch_all(MYSQLI_ASSOC)]); });'
            . ' include ' . var_export($pantalla, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'tiendas');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $estado] = array_pad(explode('@@TIENDAS@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['principal' => '', 'ultima' => '', 'tiendas' => []];
        $pantallaPintada = ['html' => $html, 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $pantallaPintada;
    }

    /**
     * La sentencia que da de alta una tienda del tipo indicado, con datos de prueba y los que se pidan.
     *
     * @param array<string,string> $datos
     */
    private function tienda(string $tipo, array $datos = []): string
    {
        $fila = $datos + [
            'tipoTienda' => $tipo, 'razonsocial' => 'Tienda de pruebas', 'nif' => 'X0000000X', 'telefono' => '000000000', 'estado' => 'cerrado',
            'NombreComercial' => 'Tienda de pruebas', 'direccion' => 'Sin direccion', 'ano' => $this->ano(),
        ];
        $valores = array_map(fn (string $valor): string => "'" . $this->db->real_escape_string($valor) . "'", $fila);

        return 'INSERT INTO tiendas (' . implode(', ', array_keys($fila)) . ') VALUES (' . implode(', ', $valores) . ')';
    }

    /** La sentencia que pone a la tienda principal los datos de correo que se indiquen, o ninguno. */
    private function correoDeLaPrincipal(?string $correo): string
    {
        $valor = $correo === null ? 'NULL' : "'" . $this->db->real_escape_string($correo) . "'";

        return "UPDATE tiendas SET servidor_email = $valor WHERE idTienda = :principal";
    }

    /**
     * El formulario de la ficha con los datos comunes de una tienda física de prueba y los que se pidan.
     *
     * @param array<string,string> $datos
     * @return array<string,string>
     */
    private function formulario(array $datos): array
    {
        return $datos + [
            'idtienda' => '', 'tipoTienda' => 'fisica', 'nif' => 'B00000000', 'razonsocial' => 'Tienda de prueba S.L.', 'NombreComercial' => 'Tienda de prueba',
            'ano' => $this->ano(), 'direccion' => 'Calle de la Prueba 1', 'telefono' => '986000000',
        ];
    }

    /** La sentencia que quita al usuario de pruebas el permiso de una acción del listado. */
    private function quitarPermiso(string $accion): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_tienda' AND vista = 'ListaTiendas.php' AND accion = '$accion'";
    }

    /** La sentencia que quita al usuario de pruebas el permiso de una vista del módulo. */
    private function quitarPermisoDeVista(string $vista): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_tienda' AND vista = '$vista' AND accion IS NULL";
    }

    /**
     * La tienda con esa razón social, como quedó al terminar la pantalla, o null si no hay.
     *
     * @return array<string,?string>|null
     */
    private function tiendaDe(array $pantalla, string $razonSocial): ?array
    {
        foreach ($pantalla['tiendas'] as $tienda) {
            if ($tienda['razonsocial'] === $razonSocial) {
                return $tienda;
            }
        }

        return null;
    }

    /** @return array<string,?string> */
    private function tiendaPorNumero(array $pantalla, string $numero): array
    {
        foreach ($pantalla['tiendas'] as $tienda) {
            if ($tienda['idTienda'] === $numero) {
                return $tienda;
            }
        }
        self::fail('La tienda ' . $numero . ' no está en la tabla');
    }
}

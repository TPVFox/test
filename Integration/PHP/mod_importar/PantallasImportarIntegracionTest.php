<?php

/**
 * Las pantallas de la importación: la de subida (`Importar.php`), que ofrece subir el fichero DBF, y la
 * petición de fondo (`tareas.php`), que borra la tabla de paso o el registro de la última importación.
 *
 * Cada una se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna, y una transacción que se deshace; el caso siembra dentro de esa transacción y,
 * al terminar, el proceso escribe lo que responden sus consultas. No se ejecutan aquí `upload.php`,
 * `importarDBF.php` ni `segundo_plano.php`: lanzan Python y el proceso de fondo contra la base, y se
 * verifican por lectura.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModImportar;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasImportarIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $siembra->tiendaPorDefecto();
    }

    /**
     * `$_FILES` de una subida con el nombre pedido y el `ARTICULO.DBF` de ejemplo como contenido.
     *
     * @return array<string, array<string, mixed>>
     */
    private function ficheroSubido(string $nombre): array
    {
        $ejemplo = dirname(__DIR__, 3) . '/support/siembra/ARTICULO.DBF';

        return ['fichero' => ['name' => $nombre, 'type' => 'application/octet-stream', 'tmp_name' => $ejemplo, 'error' => 0, 'size' => filesize($ejemplo)]];
    }

    /**
     * Ejecuta un fichero del módulo en un PHP aparte y devuelve su HTML, su stderr y lo que responden las
     * consultas del caso. La siembra va dentro de la transacción del proceso, que no se confirma.
     */
    private function ejecutar(string $fichero, array $post = [], array $preparacion = [], array $consultas = [], bool $conSesion = true, array $ficheros = []): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = ' . var_export($post !== [] ? 'POST' : 'GET', true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_importar/{$fichero}", true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_importar', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $sembrado = [];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query($sql); if (stripos($sql, "INSERT") === 0) { $sembrado[":id"] = (string) $BDTpv->insert_id; } }'
            . ' $post = ' . var_export($post, true) . '; foreach ($post as $k => $v) { $_POST[$k] = strtr((string) $v, $sembrado); }'
            . ' $_FILES = ' . var_export($ficheros, true) . ';'
            . ' register_shutdown_function(function () use ($BDTpv, &$sembrado) { $r = [];'
            . ' foreach (' . var_export($consultas, true) . ' as $n => $sql) { $fila = $BDTpv->query(strtr($sql, $sembrado))->fetch_row(); $r[$n] = $fila === null || $fila[0] === null ? null : (string) $fila[0]; }'
            . ' echo "\n@@ESTADO@@" . json_encode(["sembrado" => $sembrado, "consultas" => $r]); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'importar');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $estado] = array_pad(explode('@@ESTADO@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['sembrado' => [], 'consultas' => []];
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $pintada;
    }

    private function registrar(string $estado): string
    {
        return "INSERT INTO modulo_importar_registro (name, datos_fichero, token, type, fecha_inicio, estado, Registros_originales, campos) "
            . "VALUES ('articulos.dbf', '{}', 't', 'application/x-dbf', '2026-10-23 08:00:00', '{$estado}', 0, '[]')";
    }

    /**
     * @comportamiento La pantalla de subida enseña el formulario para subir el fichero DBF, o, si la ruta
     *   de subida no existe, el aviso de que no la hay; con una importación registrada, el enlace para ver
     *   cómo va.
     */
    public function test_laPantallaDeSubidaOfreceSubirElFichero(): void
    {
        $pantalla = $this->ejecutar('Importar.php', [], [$this->registrar('Fusionado')]);

        self::assertStringContainsString('Subir fichero de articulos', $pantalla['html']);
        self::assertStringContainsString('name="fichero"', $pantalla['html']);
        self::assertStringContainsString('importarDBF.php', $pantalla['html']);
    }

    /**
     * @comportamiento Sin sesión, la cabecera común, que la pantalla incluye antes del formulario, pide
     *   entrar: el formulario de subida no se pinta.
     */
    public function test_sinSesionLaPantallaDeSubidaNoSeSirve(): void
    {
        $pantalla = $this->ejecutar('Importar.php', [], [$this->registrar('Fusionado')], [], false);

        self::assertStringNotContainsString('Subir fichero de articulos', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/Importar.php:58-64
     * @codigo-afectado clases/ClaseConexion.php:51-55
     *
     * @group defecto
     * @group importar
     * @group alto
     *
     * @que-ocurre-hoy La pantalla de subida dice que no existe la ruta de subida y no ofrece el botón de
     *   enviar, aunque la configuración la declara y la carpeta existe: la importación no se puede empezar
     *   desde la pantalla.
     * @que-deberia-ocurrir Que con la ruta de subida configurada y existente se ofrezca enviar el fichero.
     * @por-que-ocurre La clase de conexión lee la ruta incluyendo la configuración con `include_once`, y como
     *   ya se incluyó al arrancar, la variable no existe en su ámbito y la ruta sale vacía.
     * @como-deberia-funcionar Que la ruta de subida se lea de la configuración ya cargada.
     */
    public function test_laPantallaDeSubidaOfreceEnviarElFichero(): void
    {
        $pantalla = $this->ejecutar('Importar.php', [], [$this->registrar('Fusionado')]);

        self::assertStringContainsString('name="uploadBtn"', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/Importar.php:25-27
     * @codigo-afectado modulos/mod_importar/clases/ClaseImportarDbf.php:632-643
     *
     * @group defecto
     * @group importar
     * @group bajo
     *
     * @que-ocurre-hoy La primera vez, sin ninguna importación registrada, la pantalla de subida deja avisos
     *   de PHP: monta el nombre del fichero de registro y el enlace de la última importación con un último
     *   registro que no existe.
     * @que-deberia-ocurrir Que sin importaciones la pantalla se pinte sin avisos.
     * @por-que-ocurre Leer el último registro sin filas devuelve sus datos a null, y la pantalla y la clase
     *   toman su primera fila sin comprobar que la haya.
     * @como-deberia-funcionar Comprobar que hay un registro antes de usarlo.
     */
    public function test_sinNingunaImportacionLaPantallaNoDejaAvisos(): void
    {
        $pantalla = $this->ejecutar('Importar.php');

        self::assertStringNotContainsString('Warning', $pantalla['errores']);
    }

    /**
     * @comportamiento La petición de fondo borra el registro de la última importación cuando se le pide por
     *   su id.
     */
    public function test_laPeticionDeFondoBorraElRegistro(): void
    {
        $respuesta = $this->ejecutar(
            'tareas.php',
            ['pulsado' => 'EliminarUltimoRegistro', 'id_ultimo_registro' => ':id'],
            [$this->registrar('Fusionado')],
            ['quedan' => "SELECT COUNT(*) FROM modulo_importar_registro WHERE name = 'articulos.dbf'"]
        );

        self::assertSame('0', $respuesta['consultas']['quedan']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/tareas.php:11-35
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy La petición de fondo borra el registro de la última importación sin sesión: el
     *   proceso sin usuario atiende la orden igual.
     * @que-deberia-ocurrir Que sin sesión no se atienda la orden.
     * @por-que-ocurre `tareas.php` no comprueba la sesión ni ningún permiso antes de atender la orden de
     *   `pulsado`.
     * @como-deberia-funcionar Comprobar la sesión y el permiso antes de atender la orden.
     */
    public function test_sinSesionLaPeticionDeFondoNoBorra(): void
    {
        $respuesta = $this->ejecutar(
            'tareas.php',
            ['pulsado' => 'EliminarUltimoRegistro', 'id_ultimo_registro' => ':id'],
            [$this->registrar('Fusionado')],
            ['quedan' => "SELECT COUNT(*) FROM modulo_importar_registro WHERE name = 'articulos.dbf'"],
            false
        );

        self::assertSame('1', $respuesta['consultas']['quedan']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/tareas.php:11,38
     *
     * @group defecto
     * @group importar
     * @group bajo
     *
     * @que-ocurre-hoy Una petición de fondo sin la orden `pulsado` deja dos avisos de PHP: lee `pulsado` y
     *   responde `$respuesta`, las dos sin definir.
     * @que-deberia-ocurrir Que una petición sin orden conocida responda un error, sin avisos.
     * @por-que-ocurre `tareas.php` toma `$_POST['pulsado']` sin comprobar que viene, y el `switch` no tiene
     *   caso por defecto que defina la respuesta.
     * @como-deberia-funcionar Comprobar que la orden viene y es conocida, y responder un error si no.
     */
    public function test_laPeticionDeFondoSinOrdenNoDejaAvisos(): void
    {
        $respuesta = $this->ejecutar('tareas.php', ['otro' => '1'], [$this->registrar('Fusionado')]);

        self::assertStringNotContainsString('Undefined', $respuesta['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/tareas.php:25-27,38
     *
     * @group defecto
     * @group importar
     * @group bajo
     *
     * @que-ocurre-hoy La orden de borrar el fichero no hace nada y responde una variable sin definir: deja un
     *   aviso de PHP y responde nulo, como si la orden no se conociera.
     * @que-deberia-ocurrir Que la orden de borrar el fichero lo borre y lo diga, o que no se atienda.
     * @por-que-ocurre Su caso del `switch` está vacío, y la respuesta solo se define en los otros dos.
     * @como-deberia-funcionar Borrar el fichero subido y responder el resultado, o retirar la orden.
     */
    public function test_borrarElFicheroRespondeSinAvisos(): void
    {
        $respuesta = $this->ejecutar('tareas.php', ['pulsado' => 'borrar_fichero'], [$this->registrar('Fusionado')]);

        self::assertStringNotContainsString('Undefined', $respuesta['errores']);
    }

    /**
     * @comportamiento La subida sin fichero no mueve nada ni lee estructura alguna: avisa de que no se subió
     *   ningún fichero.
     */
    public function test_laSubidaSinFicheroAvisaDeQueNoSeSubioNinguno(): void
    {
        $pantalla = $this->ejecutar('upload.php', ['uploadBtn' => 'Enviar'], [$this->registrar('Fusionado')]);

        self::assertStringContainsString('No subiste ficheros', $pantalla['html']);
    }

    /** @comportamiento Un fichero que no es `.dbf` no se sube: la pantalla dice que solo se admiten `.dbf`. */
    public function test_laSubidaRechazaUnFicheroQueNoEsDbf(): void
    {
        $pantalla = $this->ejecutar('upload.php', ['uploadBtn' => 'Enviar'], [$this->registrar('Fusionado')], [], true, $this->ficheroSubido('importar.php'));

        self::assertStringContainsString('solo se admiten .dbf', $pantalla['html']);
    }

    /**
     * @comportamiento El `ARTICULO.DBF` de ejemplo pasa la comprobación de la extensión, aunque venga en
     *   mayúsculas. Fuera de un servidor web no se puede mover, y la pantalla lo dice.
     */
    public function test_laSubidaAdmiteUnDbfEnMayusculas(): void
    {
        $pantalla = $this->ejecutar('upload.php', ['uploadBtn' => 'Enviar'], [$this->registrar('Fusionado')], [], true, $this->ficheroSubido('ARTICULO.DBF'));

        self::assertStringNotContainsString('solo se admiten .dbf', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/upload.php:62-89
     *
     * @group defecto
     * @group importar
     * @group alto
     *
     * @que-ocurre-hoy Con la última importación sin terminar, la subida no lo dice ni lo tiene en cuenta: el
     *   aviso de que la importación anterior está en curso o quedó a medias no se enseña, y no impide
     *   empezar otra.
     * @que-deberia-ocurrir Que con la última importación sin terminar se avise y no se suba otra.
     * @por-que-ocurre El estado de la última importación se anota en `$error`, y lo que se suma y se enseña
     *   es `$errores`: la anotación se pierde.
     * @como-deberia-funcionar Anotar el estado de la última importación en `$errores`, con los demás.
     */
    public function test_conLaUltimaImportacionSinTerminarLaSubidaLoAvisa(): void
    {
        $pantalla = $this->ejecutar('upload.php', ['uploadBtn' => 'Enviar'], [$this->registrar('Creado')]);

        self::assertStringContainsString('NO SE ESTA EJECUTANDO', $pantalla['html']);
    }
}

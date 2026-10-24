<?php

/**
 * Las pantallas de las balanzas: el listado (`ListaBalanzas.php`) y la ficha de una balanza
 * (`balanza.php`).
 *
 * Cada una se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna, y los permisos del módulo concedidos o con sus acciones —crear y modificar—
 * quitadas. Tras el arranque común se abre una transacción en su conexión, donde el caso siembra sus
 * balanzas, sus productos y sus teclas; todo se deshace al terminar el proceso.
 *
 * La preparación es una lista de sentencias; cada alta de un producto queda con un nombre —`:a1`,
 * `:a2`…, y `:aN` el último— y cada alta de una balanza también —`:b1`, `:b2`…—, que las sentencias
 * siguientes y la dirección pueden usar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModBalanza;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasBalanzaIntegracionTest extends CasoIntegracion
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

    /** @comportamiento Con permisos, el listado ofrece añadir, modificar y eliminar, y lista las balanzas para marcarlas. */
    public function test_elListadoOfreceSusOpcionesYListaLasBalanzas(): void
    {
        $pantalla = $this->ejecutar('ListaBalanzas.php', [], [$this->balanza('Mostrador'), $this->balanza('Trastienda')]);

        self::assertStringContainsString("metodoClick('AgregarBalanza')", $pantalla['html']);
        self::assertStringContainsString("metodoClick('VerBalanza', 'balanza')", $pantalla['html']);
        self::assertStringContainsString("metodoClick('EliminarBalanza', 'balanza')", $pantalla['html']);
        self::assertStringContainsString('value="' . $pantalla['ids'][':b2'] . '" class="check_balanza"', $pantalla['html']);
        self::assertStringContainsString('mostrarDatosBalanza(' . $pantalla['ids'][':b1'] . ')', $pantalla['html']);
    }

    /** @comportamiento Sin sesión, el listado no se pinta. */
    public function test_sinSesionElListadoNoSePinta(): void
    {
        $pantalla = $this->ejecutar('ListaBalanzas.php', [], [$this->balanza('Mostrador')], false);

        self::assertStringNotContainsString('Balanzas: Editar y Añadir Balanzas', $pantalla['html']);
    }

    /** @comportamiento Sin los permisos de crear y de modificar, el listado no ofrece añadir, modificar ni eliminar. */
    public function test_sinPermisosElListadoNoOfreceSusOpciones(): void
    {
        $pantalla = $this->ejecutar('ListaBalanzas.php', [], [$this->balanza('Mostrador')], true, false);

        self::assertStringContainsString('Balanzas: Editar y Añadir Balanzas', $pantalla['html']);
        self::assertStringNotContainsString('metodoClick(', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/ListaBalanzas.php:94; modulos/mod_balanza/balanza.php:140, :144
     *
     * @group defecto
     * @group pantallas
     * @group medio
     *
     * @que-ocurre-hoy El nombre de una balanza se pinta en el listado y en la ficha tal como está guardado:
     *   un nombre con marcas de HTML cambia la página.
     * @que-deberia-ocurrir Que el nombre se pinte como texto.
     * @por-que-ocurre Se escribe en la página sin escapar.
     * @como-deberia-funcionar Escapar lo que se pinta.
     */
    public function test_elNombreDeUnaBalanzaSePintaComoTexto(): void
    {
        $listado = $this->ejecutar('ListaBalanzas.php', [], [$this->balanza('Mostrador <b>Norte</b>')]);
        $ficha = $this->ejecutar('balanza.php', ['id' => ':b1'], [$this->balanza('Mostrador "Norte"')]);

        self::assertStringContainsString('Mostrador &lt;b&gt;Norte&lt;/b&gt;', $listado['html']);
        self::assertStringContainsString('value="Mostrador &quot;Norte&quot;"', $ficha['html']);
    }

    /** @comportamiento La ficha de una balanza enseña sus datos, su configuración y sus teclas, y deja las teclas a la página para el volcado. */
    public function test_laFichaEnsenaLaBalanzaYSusTeclas(): void
    {
        $pantalla = $this->ejecutar('balanza.php', ['id' => ':b1'], [$this->balanza('Mostrador'), ...$this->producto('Pera conferencia', '1001', '2.30'), $this->teclaSembrada('7', '0', ':a1')]);

        self::assertStringContainsString('<h2 class="text-center mb-4">Modificar Balanza</h2>', $pantalla['html']);
        self::assertStringContainsString('id="nombreBalanza" value="Mostrador"', $pantalla['html']);
        self::assertStringContainsString('id="ipBalanza" value="192.168.1.50"', $pantalla['html']);
        self::assertStringContainsString('id="editPlu_' . $pantalla['ids'][':a1'] . '" value="7"', $pantalla['html']);
        self::assertStringContainsString('var plusBalanza = [{', $pantalla['html']);
        self::assertStringNotContainsString('confirmarYEliminarBalanza(', $pantalla['html']);
    }

    /** @comportamiento Sin el permiso de crear, la ficha no ofrece la configuración de la comunicación ni el volcado. */
    public function test_sinPermisoLaFichaNoOfreceLaComunicacion(): void
    {
        $pantalla = $this->ejecutar('balanza.php', ['id' => ':b1'], [$this->balanza('Mostrador')], true, false);

        self::assertStringContainsString('Modificar Balanza', $pantalla['html']);
        self::assertStringNotContainsString('panelConfigAvanzada', $pantalla['html']);
        self::assertStringNotContainsString('volcadoCompleto(', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/balanza.php:106-129
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Sin los permisos de crear y de modificar, la ficha ofrece igual guardar la balanza y,
     *   si no tiene teclas, eliminarla.
     * @que-deberia-ocurrir Que guardar y eliminar solo se ofrezcan con su permiso, como en el listado.
     * @por-que-ocurre La ficha solo consulta el permiso para la configuración y el volcado.
     * @como-deberia-funcionar Consultar el permiso de cada opción.
     */
    public function test_sinPermisosLaFichaNoOfreceGuardarNiEliminar(): void
    {
        $pantalla = $this->ejecutar('balanza.php', ['id' => ':b1'], [$this->balanza('Mostrador')], true, false);

        self::assertStringNotContainsString('ModificarBalanza(', $pantalla['html']);
        self::assertStringNotContainsString('confirmarYEliminarBalanza(', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_balanza/balanza.php:30-33; modulos/mod_balanza/clases/ClaseBalanza.php:50-55
     *
     * @group defecto
     * @group pantallas
     * @group alto
     *
     * @que-ocurre-hoy Un número de balanza como «0 OR 1=1» enseña la primera balanza: lo que llega en la
     *   dirección forma parte de la consulta.
     * @que-deberia-ocurrir Que un número que no es un número no enseñe ninguna balanza.
     * @por-que-ocurre El número se pega a la consulta sin convertirlo.
     * @como-deberia-funcionar Convertirlo a número, o pasarlo como parámetro.
     */
    public function test_unNumeroDeBalanzaQueNoEsUnNumeroNoEnsenaNinguna(): void
    {
        $pantalla = $this->ejecutar('balanza.php', ['id' => '0 OR 1=1'], [$this->balanza('Mostrador')]);

        self::assertStringNotContainsString('id="nombreBalanza" value="Mostrador"', $pantalla['html']);
    }

    /** @comportamiento La ficha de una balanza con secciones enseña la sección de cada tecla, con su filtro. */
    public function test_laFichaConSeccionesEnsenaLaSeccionDeCadaTecla(): void
    {
        $pantalla = $this->ejecutar('balanza.php', ['id' => ':b1'], [$this->balanza('Mostrador', 'si'), ...$this->producto('Pera conferencia', '1001', '2.30'), $this->teclaSembrada('7', '2', ':a1')]);

        self::assertStringContainsString('<th>Sección</th>', $pantalla['html']);
        self::assertStringContainsString('id="editTecla_' . $pantalla['ids'][':a1'] . '" value="2"', $pantalla['html']);
    }

    private function balanza(string $nombre, string $secciones = 'no'): string
    {
        return 'INSERT INTO modulo_balanza (nombreBalanza, modelo, conSeccion, Grupo, `Dirección`, IP, soloPLUS) VALUES (' . var_export($nombre, true) . ", 'Dibal 500', '{$secciones}', 1, 2, '192.168.1.50', 1)";
    }

    /** @return list<string> */
    private function producto(string $nombre, string $referencia, string $precio): array
    {
        return [
            "INSERT INTO articulos (iva, articulo_name, estado, fecha_creado, ultimoCoste, tipo) VALUES (10.00, '{$nombre}', 'Activo', NOW(), 1, 'peso')",
            "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES (:aN, :tienda, '{$referencia}', 'Activo')",
            "INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:aN, {$precio}, {$precio} / 1.1, :tienda)",
        ];
    }

    private function teclaSembrada(string $plu, string $seccion, string $producto): string
    {
        return "INSERT INTO modulo_balanza_plus (idBalanza, plu, seccion, idArticulo) VALUES (:b1, {$plu}, {$seccion}, {$producto})";
    }

    /**
     * Ejecuta una pantalla en un PHP aparte y devuelve su HTML, sus avisos y los números de lo sembrado.
     *
     * @param array<string, string> $consulta
     * @param list<string> $preparacion
     * @return array{html: string, errores: string, ids: array<string, string>}
     */
    private function ejecutar(string $fichero, array $consulta, array $preparacion = [], bool $conSesion = true, bool $conPermisos = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["DOCUMENT_ROOT"] = "";'
            . ' $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_balanza/{$fichero}", true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_balanza', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = IF(accion IS NULL OR accion = \'\', 1, ' . ($conPermisos ? 1 : 0) . ') WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_balanza\'");'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuentas = ["articulos" => 0, "modulo_balanza" => 0];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids));'
            . ' if (preg_match("/^INSERT INTO (articulos|modulo_balanza) /", $sql, $t)) { $clave = $t[1] === "articulos" ? ":a" : ":b";'
            . ' $ids[$clave . ++$cuentas[$t[1]]] = (string) $BDTpv->insert_id; if ($clave === ":a") { $ids[":aN"] = (string) $BDTpv->insert_id; } } }'
            . ' $_GET = array_map(fn ($valor) => strtr((string) $valor, $ids), ' . var_export($consulta, true) . ');'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use (&$ids) { echo "\n@@DATOS@@" . json_encode(["ids" => $ids]); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'balanzas');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $datos] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $pintada = ['html' => $html, 'errores' => (string) file_get_contents($errores)] + (json_decode($datos, true) ?? ['ids' => []]);
        unlink($errores);

        return $pintada;
    }
}

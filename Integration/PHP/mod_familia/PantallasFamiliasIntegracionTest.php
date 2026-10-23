<?php

/**
 * Las pantallas de las familias: el listado (`ListaFamilias.php`) y la ficha de crear o modificar una
 * familia (`familia.php`), con sus productos, sus hijas y su relación con la tienda web.
 *
 * Cada pantalla se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas y los permisos del módulo concedidos, salvo los que el caso quite o los que el módulo da de
 * partida para la tienda web. Tras el arranque común se abre una transacción en su conexión, y las
 * clases leen por esa misma conexión: lo que siembra el caso se deshace al terminar el proceso. Las
 * familias que siembra el caso quedan con un nombre —`:f1`, `:f2`…— para la dirección.
 *
 * Lo que la ficha hace con una tienda web configurada —leer sus categorías por el complemento— no se
 * pinta aquí: la suite no tiene tienda web.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModFamilia;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasFamiliasIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    private int $idUsuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idUsuario = (new Siembra($this->db))->usuarioPorDefecto();
    }

    /**
     * @comportamiento El listado enseña las familias de la raíz con sus hijas y sus productos, y ofrece
     *   añadir una familia y eliminar las marcadas a quien tiene esos permisos.
     */
    public function test_elListadoEnsenaLasFamiliasDeLaRaiz(): void
    {
        $pantalla = $this->pintar('ListaFamilias.php', [], [
            $this->familia('Frutas'),
            $this->familia('Verduras'),
            $this->familia('Frutas secas', ':f1'),
            $this->producto(':f2'),
        ]);

        self::assertStringContainsString('>Frutas</button>', $pantalla['html']);
        self::assertStringContainsString('>Verduras</button>', $pantalla['html']);
        self::assertStringNotContainsString('>Frutas secas</button>', $pantalla['html'], 'Las hijas se cargan al expandir');
        self::assertStringContainsString('onclick="expandir(' . $pantalla['ids'][':f1'] . ')"', $pantalla['html']);
        self::assertStringContainsString('<td>1 productos </td>', $pantalla['html']);
        self::assertStringContainsString('href="familia.php?id=0">Añadir</a>', $pantalla['html']);
        self::assertStringContainsString('onclick="EliminarFamiliasSeleccionadas()"', $pantalla['html']);
    }

    /** @comportamiento Sin los permisos de crear y de eliminar, el listado no ofrece añadir ni eliminar. */
    public function test_sinPermisosElListadoNoOfreceAnadirNiEliminar(): void
    {
        $pantalla = $this->pintar('ListaFamilias.php', [], [
            $this->familia('Frutas'),
            $this->quitarPermiso('ListaFamilias.php', 'crear'),
            $this->quitarPermiso('ListaFamilias.php', 'eliminar'),
        ]);

        self::assertStringNotContainsString('Añadir</a>', $pantalla['html']);
        self::assertStringNotContainsString('EliminarFamiliasSeleccionadas()', $pantalla['html']);
    }

    /**
     * @comportamiento La ficha de crear propone una familia nueva: sin nombre, con la raíz y las
     *   familias como padres posibles y un beneficio medio de 25.
     */
    public function test_laFichaDeCrearProponeUnaFamiliaNueva(): void
    {
        $pantalla = $this->pintar('familia.php', ['id' => '0'], [$this->familia('Frutas')]);

        self::assertStringContainsString('Familias:Crear', $pantalla['html']);
        self::assertStringContainsString('Datos de la familia con ID:nueva', $pantalla['html']);
        self::assertStringContainsString('<option value=0 selected = "selected" >Raíz: la madre de todas las familias</option>', $pantalla['html']);
        self::assertStringContainsString('>Frutas</option>', $pantalla['html']);
        self::assertMatchesRegularExpression('/id="inputbeneficio"\s+value="25"/', $pantalla['html']);
    }

    /**
     * @comportamiento La ficha de una familia la enseña con su nombre, su padre, sus productos y sus
     *   hijas; sus padres posibles no son ni ella ni sus descendientes.
     */
    public function test_laFichaDeUnaFamiliaLaEnsenaConSusProductosYSusHijas(): void
    {
        $pantalla = $this->pintar('familia.php', ['id' => ':f2'], [
            $this->familia('Frutas'),
            $this->familia('Frutas secas', ':f1'),
            $this->familia('Nueces', ':f2'),
            $this->producto(':f2'),
        ]);
        $html = $pantalla['html'];

        self::assertStringContainsString('Familias:Modificar', $html);
        self::assertMatchesRegularExpression('/id="inputnombre"\s+value="Frutas secas"/', $html);
        self::assertStringContainsString('<option value=' . $pantalla['ids'][':f1'] . ' selected = "selected" >Frutas</option>', $html);
        self::assertStringNotContainsString('>Nueces</option>', $html, 'Una descendiente no puede ser padre');
        self::assertStringContainsString('<tr id="tr_' . $pantalla['ids'][':articulo'] . '"', $html);
        self::assertStringContainsString('<td> Nueces </td>', $html);
        self::assertStringContainsString('id="marcar_tpv" type="checkbox" name="mostrar_tpv" value="1" checked', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/ListaFamilias.php:21-25; modulos/mod_familia/funciones.php:46-50
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Sin ninguna familia —una instalación que aún no tiene ninguna—, el listado termina
     *   con un error fatal a media tabla, tras pintar el botón de añadir.
     * @que-deberia-ocurrir Que el listado se pinte vacío y ofrezca añadir una familia.
     * @por-que-ocurre Cuenta las hijas y los productos de unos datos que, sin filas, no existen, y el
     *   listado cuenta esa lista nula.
     * @como-deberia-funcionar Tomar una lista vacía cuando la lectura no trae filas.
     */
    public function test_sinFamiliasElListadoOfreceAnadirLaPrimera(): void
    {
        $pantalla = $this->pintar('ListaFamilias.php', [], $this->sinFamilias());

        self::assertSame('', $pantalla['errores'], 'Sin avisos ni errores de PHP.');
        self::assertStringContainsString('href="familia.php?id=0">Añadir</a>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/familia.php:22-57; modulos/mod_familia/clases/ClaseFamilias.php:199-213
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Sin ninguna familia, la ficha de crear la primera termina con un error fatal.
     * @que-deberia-ocurrir Que la ficha ofrezca la raíz como único padre.
     * @por-que-ocurre La lista de padres no trae datos sin filas, y la ficha la recorre y la convierte en
     *   columna sin comprobarla.
     * @como-deberia-funcionar Tomar una lista vacía cuando la lectura no trae filas.
     */
    public function test_sinFamiliasLaFichaDeCrearOfreceLaRaiz(): void
    {
        $pantalla = $this->pintar('familia.php', ['id' => '0'], $this->sinFamilias());

        self::assertSame('', $pantalla['errores'], 'Sin avisos ni errores de PHP.');
        self::assertStringContainsString('Raíz: la madre de todas las familias</option>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/familia.php:320-323
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy La ficha de cualquier familia deja avisos de PHP sin el permiso de ver la tienda
     *   web, que el módulo no da de partida: pide la ruta de las categorías a un complemento que no ha
     *   cargado.
     * @que-deberia-ocurrir Que la ficha se pinte sin avisos.
     * @por-que-ocurre La tabla de relaciones con la web se monta siempre, con el complemento que solo se
     *   carga con ese permiso.
     * @como-deberia-funcionar Montar la tabla solo con el complemento cargado, o sin su ruta.
     */
    public function test_laFichaDeUnaFamiliaNoDejaAvisos(): void
    {
        $pantalla = $this->pintar('familia.php', ['id' => ':f1'], [
            $this->familia('Frutas'),
            $this->familia('Verduras'),
            $this->quitarPermiso('familia.php', 'VerFamiliaWeb'),
        ]);

        self::assertSame('', $pantalla['errores'], 'Sin avisos de PHP.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/familia.php:30-57; modulos/mod_familia/clases/ClaseFamilias.php:268-286
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy La ficha de la única familia que hay termina con un error fatal: no queda ninguna
     *   otra que pueda ser su padre.
     * @que-deberia-ocurrir Que la ficha ofrezca la raíz como único padre.
     * @por-que-ocurre La lista de padres posibles no trae datos sin filas, y la ficha la recorre y la
     *   convierte en columna sin comprobarla.
     * @como-deberia-funcionar Tomar una lista vacía cuando la lectura no trae filas.
     */
    public function test_laFichaDeLaUnicaFamiliaOfreceLaRaiz(): void
    {
        $pantalla = $this->pintar('familia.php', ['id' => ':f1'], [...$this->sinFamilias(), $this->familia('Única')]);

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
        self::assertStringContainsString('Raíz: la madre de todas las familias</option>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/familia.php:30-36; modulos/mod_familia/clases/ClaseFamilias.php:424-476
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy La ficha de un número que no es de ninguna familia deja avisos de PHP y se pinta
     *   como la de una familia vacía.
     * @que-deberia-ocurrir Que avise de que la familia no existe, sin avisos de PHP.
     * @por-que-ocurre Toma la primera fila leída sin comprobar que la haya.
     * @como-deberia-funcionar Comprobar que la familia existe y avisar si no.
     */
    public function test_laFichaDeUnaFamiliaQueNoExisteNoDejaAvisos(): void
    {
        $pantalla = $this->pintar('familia.php', ['id' => '999999'], [$this->familia('Frutas')]);

        self::assertStringNotContainsString('Undefined', $pantalla['errores'], 'Sin avisos de PHP.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/funciones.php:53-62
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy El listado escribe el nombre de una familia tal como está guardado: un nombre con
     *   un script se ejecuta en la página de quien abre las familias.
     * @que-deberia-ocurrir Que el nombre se escriba como texto.
     * @por-que-ocurre Cada celda escribe el valor guardado sin escapar.
     * @como-deberia-funcionar Escapar cada valor al escribirlo en la página.
     */
    public function test_elListadoEscribeLosDatosComoTexto(): void
    {
        $pantalla = $this->pintar('ListaFamilias.php', [], [$this->familia(self::SCRIPT)]);

        self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/familia.php:411-413; modulos/mod_familia/clases/ClaseFamilias.php:478-504
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy La ficha escribe el nombre de la familia y el de los padres posibles tal como están
     *   guardados: un nombre con un script se ejecuta al abrir la ficha.
     * @que-deberia-ocurrir Que los datos se escriban como texto.
     * @por-que-ocurre El campo del nombre y el desplegable escriben el valor guardado sin escapar.
     * @como-deberia-funcionar Escapar cada valor al escribirlo en la página.
     */
    public function test_laFichaEscribeLosDatosComoTexto(): void
    {
        $pantalla = $this->pintar('familia.php', ['id' => '0'], [$this->familia(self::SCRIPT)]);

        self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado head.php:17-26; modulos/mod_familia/ListaFamilias.php:9-25
     *
     * @group defecto
     * @group permisos
     * @group forzado
     * @group alto
     *
     * @que-ocurre-hoy Un usuario con sesión pero sin el permiso de la vista abre el listado de familias
     *   por su dirección y lo ve.
     * @que-deberia-ocurrir Que el listado no se sirva sin su permiso.
     * @por-que-ocurre Ni la cabecera común ni la pantalla consultan el permiso de la vista que se sirve.
     * @como-deberia-funcionar Comprobar el permiso de la vista antes de pintarla, en el arranque común.
     */
    public function test_sinPermisoDeVistaNoSeSirveElListado(): void
    {
        $pantalla = $this->pintar('ListaFamilias.php', [], [
            $this->familia('Solo con permiso'),
            "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_familia' AND vista = 'ListaFamilias.php' AND accion IS NULL",
        ]);

        self::assertStringNotContainsString('Solo con permiso', $pantalla['html'], 'Sin el permiso de la vista se sirve el listado');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/familia.php:391-396; modulos/mod_familia/acces.xml:5
     *
     * @group defecto
     * @group permisos
     * @group bajo
     *
     * @que-ocurre-hoy La ficha de una familia ofrece guardarla a un usuario sin el permiso de modificar
     *   familias.
     * @que-deberia-ocurrir Que sin ese permiso la ficha no ofrezca guardar.
     * @por-que-ocurre La ficha pinta el botón de guardar sin consultar ningún permiso.
     * @como-deberia-funcionar Consultar el permiso de modificar antes de pintar el botón.
     */
    public function test_sinPermisoDeModificarLaFichaNoOfreceGuardar(): void
    {
        $pantalla = $this->pintar('familia.php', ['id' => ':f1'], [
            $this->familia('Frutas'),
            $this->familia('Verduras'),
            $this->quitarPermiso('ListaFamilias.php', 'modificar'),
        ]);

        self::assertStringNotContainsString('id="btn-fam-grabar"', $pantalla['html']);
    }

    private function familia(string $nombre, string $padre = '0'): string
    {
        return "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('$nombre', $padre, 0, 1)";
    }

    /** Pone en la familia el primer artículo del catálogo de pruebas, que queda como `:articulo`. */
    private function producto(string $familia): string
    {
        return "INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (:articulo, $familia)";
    }

    private function quitarPermiso(string $vista, string $accion): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_familia' AND vista = '$vista' AND accion = '$accion'";
    }

    /** @return list<string> */
    private function sinFamilias(): array
    {
        return ['DELETE FROM familiasTienda', 'DELETE FROM articulosFamilias', 'DELETE FROM familias'];
    }

    /**
     * Pinta una pantalla del módulo en un PHP aparte y devuelve su página, sus avisos y los números de
     * lo que sembró.
     *
     * @param array<string, string> $direccion
     * @param list<string> $preparacion
     * @return array{html:string, errores:string, ids:array<string, string>}
     */
    private function pintar(string $pantalla, array $direccion = [], array $preparacion = []): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => 1, 'razonsocial' => 'Tienda de pruebas'],
        ];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export('/TPVFox/modulos/mod_familia/' . $pantalla, true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_familia', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' new ClasePermisos($Usuario, $BDTpv);'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_familia\' AND (accion IS NULL OR vista = \'ListaFamilias.php\')");'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":articulo" => (string) $BDTpv->query("SELECT MIN(idArticulo) FROM articulos")->fetch_row()[0]]; $familias = 0;'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids));'
            . ' if (str_starts_with($sql, "INSERT INTO familias ")) { $ids[":f" . ++$familias] = (string) $BDTpv->insert_id; } }'
            . ' $_GET = array_map(fn ($valor) => strtr($valor, $ids), ' . var_export($direccion, true) . ');'
            . ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);'
            . ' register_shutdown_function(function () use (&$ids) { echo "\n@@IDS@@" . json_encode($ids); });'
            . ' include ' . var_export($pantalla, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'familias');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $ids] = array_pad(explode('@@IDS@@', implode("\n", $salida), 2), 2, 'null');
        $pintada = ['html' => $html, 'errores' => (string) file_get_contents($errores), 'ids' => json_decode($ids, true) ?? []];
        unlink($errores);

        return $pintada;
    }
}

<?php

/**
 * La petición de fondo de los informes (`modulos/mod_informes/tareas.php`) y sus órdenes comunes: la
 * imagen de espera, el cálculo de un periodo, la lista de periodos con la ventana de consolidación, las
 * opciones del selector de periodo, la barra de periodos y los catálogos de familias y de proveedores.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas, en una transacción sobre su conexión que nunca se confirma; lo que necesita se siembra en ella,
 * con un nombre (`:fam1`, `:p1`). Las órdenes que dependen del día de hoy se comprueban contra la fecha
 * del sistema.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModInformes;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasInformesIntegracionTest extends CasoIntegracion
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

    /** @comportamiento La imagen de espera se responde con la ruta de la aplicación; una orden que no existe responde vacío. */
    public function test_laImagenDeEsperaYUnaOrdenQueNoExiste(): void
    {
        self::assertSame(['html' => '<img src="/TPVFox/css/img/loading.gif" alt="Esperando">'], $this->ejecutar(['pulsado' => 'obtenerLoading'])['respuesta']);
        self::assertSame([], $this->ejecutar(['pulsado' => 'ordenQueNoExiste'])['respuesta']);
    }

    /** @comportamiento Calcular un periodo responde sus fechas y su etiqueta; un tipo que no existe, el error. */
    public function test_calcularUnPeriodo(): void
    {
        $febrero = $this->ejecutar(['pulsado' => 'calcularPeriodoPosstock', 'tipo' => 'mes', 'numero' => '2', 'anio' => '2025'])['respuesta'];
        $otro = $this->ejecutar(['pulsado' => 'calcularPeriodoPosstock', 'tipo' => 'decada', 'numero' => '1', 'anio' => '2025'])['respuesta'];

        self::assertSame(['2025-02-01', '2025-02-28', 'Febrero 2025'], [$febrero['fecha_inicio_movimientos'], $febrero['fecha_fin_movimientos'], $febrero['label_movimientos']]);
        self::assertSame(['error' => 'Tipo de periodo no válido: decada'], $otro);
    }

    /**
     * @comportamiento Los periodos de un año pasado se listan todos, con su etiqueta, fuera de la ventana;
     *   un tipo que no existe se responde como error, escapado.
     */
    public function test_losPeriodosDeUnAnioPasado(): void
    {
        $trimestres = $this->ejecutar(['pulsado' => 'getInfoPeriodos', 'tipo' => 'trimestre', 'anio' => '2025', 'ventana_dias' => '14'])['respuesta'];
        $otro = $this->ejecutar(['pulsado' => 'getInfoPeriodos', 'tipo' => '<b>x</b>', 'anio' => '2025'])['respuesta'];

        self::assertSame(4, $trimestres['total_periodos']);
        self::assertSame([[1, false, 'T1 2025'], [2, false, 'T2 2025'], [3, false, 'T3 2025'], [4, false, 'T4 2025']], array_map(static fn (array $p): array => [$p['numero'], $p['en_ventana'], $p['label']], $trimestres['periodos']));
        self::assertSame(['error' => 'Tipo de periodo no válido: &lt;b&gt;x&lt;/b&gt;'], $otro);
    }

    /**
     * @comportamiento En el año actual solo se listan los periodos empezados, y está en la ventana el que
     *   termina dentro de los días de consolidación: el año entero, con ventana; sin ventana, ninguno.
     */
    public function test_losPeriodosDelAnioActual(): void
    {
        $anio = date('Y');
        $meses = $this->ejecutar(['pulsado' => 'getInfoPeriodos', 'tipo' => 'mes', 'anio' => $anio, 'ventana_dias' => '0'])['respuesta'];
        $conVentana = $this->ejecutar(['pulsado' => 'getInfoPeriodos', 'tipo' => 'anual', 'anio' => $anio, 'ventana_dias' => '7'])['respuesta'];

        self::assertCount((int) date('n'), $meses['periodos']);
        self::assertSame([false], array_unique(array_column($meses['periodos'], 'en_ventana')));
        self::assertSame([[1, true]], array_map(static fn (array $p): array => [$p['numero'], $p['en_ventana']], $conVentana['periodos']));
    }

    /**
     * @comportamiento Las opciones del selector: sin tipo, el error; de un año pasado, todas; del año actual,
     *   las empezadas, con la del mes en curso deshabilitada si cae en la ventana.
     */
    public function test_lasOpcionesDelSelectorDePeriodo(): void
    {
        $sinTipo = $this->ejecutar(['pulsado' => 'getOpcionesPeriodo', 'anio' => '2025'])['respuesta'];
        $pasado = $this->ejecutar(['pulsado' => 'getOpcionesPeriodo', 'tipo' => 'mes', 'anio' => '2025'])['respuesta'];
        $actual = $this->ejecutar(['pulsado' => 'getOpcionesPeriodo', 'tipo' => 'mes', 'anio' => date('Y'), 'ventana_dias' => '14'])['respuesta'];

        self::assertSame(['error' => 'Tipo de periodo requerido.'], $sinTipo);
        self::assertSame(12, substr_count($pasado['html'], '<option '));
        self::assertSame((int) date('n'), substr_count($actual['html'], '<option '));
        self::assertStringContainsString('<option value="' . (int) date('n') . '" disabled', $actual['html']);
    }

    /**
     * @comportamiento La barra de periodos: del tipo anual, los tipos de incidencia; de un año pasado, un
     *   botón por periodo con el activo resaltado; sin tipo o con uno que no existe, el error.
     */
    public function test_laBarraDePeriodos(): void
    {
        $anual = $this->ejecutar(['pulsado' => 'getVistaBarraPeriodos', 'tipo' => 'anual', 'anio' => '2025', 'numero_activo' => 'caso2'])['respuesta'];
        $trimestres = $this->ejecutar(['pulsado' => 'getVistaBarraPeriodos', 'tipo' => 'trimestre', 'anio' => '2025', 'numero_activo' => '2'])['respuesta'];
        $sinTipo = $this->ejecutar(['pulsado' => 'getVistaBarraPeriodos', 'anio' => '2025'])['respuesta'];
        $otro = $this->ejecutar(['pulsado' => 'getVistaBarraPeriodos', 'tipo' => 'decada', 'anio' => '2025'])['respuesta'];

        self::assertStringContainsString('class="btn btn-primary btn-xs"  onclick="posstockNavegar(\'caso2\')"', $anual['html']);
        self::assertSame(4, substr_count($trimestres['html'], '<button'));
        self::assertStringContainsString('class="btn btn-primary btn-xs"  onclick="posstockNavegar(2)" id="posstockBtn_2">T2</button>', $trimestres['html']);
        self::assertSame(['error' => 'Tipo de periodo requerido.'], $sinTipo);
        self::assertSame(['error' => 'Tipo de periodo no válido: decada'], $otro);
    }

    /**
     * @comportamiento El catálogo de familias pinta las familias jerarquizadas —la que tiene hijas, con su
     *   carpeta— con su nombre como texto y el botón de añadirla al informe.
     */
    public function test_elCatalogoDeFamilias(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'getCatalogoFamilias'], [
            "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('Zz Frutas & verduras', 0, 0, 1)",
            "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) SELECT 'Zz Peras', :fam1, 0, 1",
        ]);
        $html = $peticion['respuesta']['html'];

        self::assertGreaterThanOrEqual(2, $peticion['respuesta']['encontrados']);
        self::assertStringContainsString('<strong>Zz Frutas &amp; verduras</strong>', $html);
        self::assertStringContainsString('agregarFamiliaInforme(' . $peticion['ids'][':fam1'] . ", 'Zz Frutas & verduras')", $html);
        self::assertMatchesRegularExpression('#<span class="badge">' . $peticion['ids'][':fam1'] . '</span>#', $html);
        self::assertStringContainsString('<strong>Zz Peras</strong>', $html);
    }

    /**
     * @comportamiento La lista de proveedores tiene solo los activos, por su nombre, y la ventana pinta los
     *   ya elegidos y marca «todos los artículos» si se pidió.
     */
    public function test_laListaDeProveedores(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'getProveedoresList', 'proveedores_incluir_json' => '[{"id":1,"nombre":"Zz Frutas activo"}]', 'proveedor_todos_productos' => '1'], [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Zz Frutas activo', 'Zz SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Zz Frutas de baja', 'Zz SL', 'X2', 'Sin direccion', NOW(), 'Baja')",
        ]);
        $nombres = array_column($peticion['respuesta']['proveedores'], 'nombre');

        self::assertContains('Zz Frutas activo', $nombres);
        self::assertNotContains('Zz Frutas de baja', $nombres);
        self::assertStringContainsString('<tr data-id="1" data-nombre="Zz Frutas activo">', $peticion['respuesta']['html']);
        self::assertStringContainsString('id="posstockChkTodosProductos" checked', $peticion['respuesta']['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/tareas.php:24-34; modulos/mod_informes/funciones.php:62-197
     *
     * @group defecto
     * @group robustez
     * @group medio
     *
     * @que-ocurre-hoy Pedir un periodo con un número fuera de los del tipo —el mes 13— termina la petición
     *   en una excepción de PHP: no hay respuesta ni aviso.
     * @que-deberia-ocurrir Que se responda el error, como con un tipo que no existe.
     * @por-que-ocurre El cálculo no comprueba el número, y la petición no captura la excepción.
     * @como-deberia-funcionar Comprobar el número y responder el error.
     */
    public function test_unNumeroDePeriodoFueraDeRangoSeResponde(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'calcularPeriodoPosstock', 'tipo' => 'mes', 'numero' => '13', 'anio' => '2025']);

        self::assertArrayHasKey('error', (array) $peticion['respuesta']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/tareas/getCatalogoFamilias.php:76-83
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy El nombre de la familia se escribe en la orden del botón de añadir con las barras de
     *   PHP, dentro de un atributo entre comillas dobles: unas comillas en el nombre cierran el atributo y
     *   lo que sigue entra en la página.
     * @que-deberia-ocurrir Que el nombre llegue a la orden como texto.
     * @por-que-ocurre `addslashes` protege la cadena de JavaScript, no el atributo HTML.
     * @como-deberia-funcionar Escapar el nombre para el atributo, además de para la cadena.
     */
    public function test_elNombreDeUnaFamiliaLlegaComoTextoAlBotonDeAnadir(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'getCatalogoFamilias'], [
            "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('Zz \"Sur\" onmouseover=\"x()', 0, 0, 1)",
        ]);

        self::assertStringNotContainsString('onmouseover=\"x()', $peticion['respuesta']['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_informes/tareas.php:1-12
     *
     * @group defecto
     * @group seguridad
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, la petición de fondo de los informes se atiende: responde los catálogos,
     *   los periodos y las órdenes de los informes, sin mirar la sesión ni el permiso.
     * @que-deberia-ocurrir Que sin sesión no se atienda ninguna orden, y que cada una exija su permiso.
     * @por-que-ocurre El arranque común no corta sin sesión, y la petición no consulta ningún permiso.
     * @como-deberia-funcionar Comprobar la sesión y el permiso al empezar.
     */
    public function test_sinSesionNoSeRespondeLaListaDeProveedores(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'getProveedoresList'], [
            "INSERT INTO proveedores (nombrecomercial, razonsocial, nif, direccion, fecha_creado, estado) VALUES ('Zz Frutas activo', 'Zz SL', 'X1', 'Sin direccion', NOW(), 'Activo')",
        ], false);

        self::assertNotContains('Zz Frutas activo', array_column((array) ($peticion['respuesta']['proveedores'] ?? []), 'nombre'));
    }

    /**
     * Ejecuta la petición en un PHP aparte, en una transacción que no se confirma, y devuelve su respuesta,
     * lo que escribió, sus avisos y los nombres de lo sembrado.
     *
     * @param array<string, string> $formulario
     * @param list<string> $preparacion
     * @return array<string, mixed>
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => (string) $this->idUsuario, 'group_id' => '1', 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => (string) $this->idTienda, 'razonsocial' => 'Tienda de pruebas SL', 'NombreComercial' => 'Tienda de pruebas'],
        ] : [];
        $marcas = ['familias' => ':fam', 'proveedores' => ':p'];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ' . var_export(dirname(RUTA_TPVFOX), true) . '; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_informes/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_informes', true) . ');'
            . ' $_POST = ["pulsado" => ""]; require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' $ids = []; $cuentas = []; $marcas = ' . var_export($marcas, true) . ';'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids)); $id = $BDTpv->insert_id;'
            . ' if (preg_match("/^INSERT INTO (\\\\w+) /", $sql, $t) && isset($marcas[$t[1]])) { $cuentas[$t[1]] = ($cuentas[$t[1]] ?? 0) + 1; $ids[$marcas[$t[1]] . $cuentas[$t[1]]] = (string) $id; } }'
            . ' $_POST = json_decode(strtr(' . var_export((string) json_encode($formulario), true) . ', $ids), true);'
            . ' register_shutdown_function(function () use (&$ids) { echo "\n@@DATOS@@" . json_encode(["ids" => $ids]); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'informes');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -d error_log=/dev/null -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@DATOS@@', implode("\n", $salida), 2), 2, 'null');
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'escrito' => $escrito, 'errores' => (string) file_get_contents($errores)] + ((json_decode($estado, true) ?? []) + ['ids' => []]);
        unlink($errores);

        return $ejecucion;
    }
}

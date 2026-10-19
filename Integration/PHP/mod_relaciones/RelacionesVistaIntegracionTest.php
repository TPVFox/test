<?php
/**
 * Lo que la pantalla de relación pide por `mod_relaciones/tareas.php`: buscar un artículo,
 * componer las líneas con lo que difiere de la versión que rige, guardar, cancelar y activar.
 * Cada petición exige el permiso de su acción en la vista del listado: crear para una relación
 * nueva, modificar para una que rige y activar para ponerla a regir.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModRelaciones;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\DespachoTareas;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

final class RelacionesVistaIntegracionTest extends CasoIntegracion
{
    /** El módulo abre su propia transacción: estos casos limpian lo que escriben. */
    protected bool $aislarPorTransaccion = false;

    protected bool $compartirConexionConElProducto = true;

    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_relaciones/tareas.php';
    private const PREFIJO = 'Prueba de la vista de relación: ';

    private EscenarioRelaciones $escenario;
    private Siembra $siembra;
    private string|false $registroDeErrores = false;
    private int $despiece;
    /** @var array<string,int> */
    private array $articulo = [];
    /** @var list<int> */
    private array $usuariosCreados = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registroDeErrores = ini_set('error_log', '/dev/null');

        $this->escenario = new EscenarioRelaciones($this->db);
        $this->siembra = new Siembra($this->db);
        $this->escenario->limpiar();
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        foreach (['pollo', 'pechuga', 'alas', 'muslo'] as $nombre) {
            $this->articulo[$nombre] = $this->siembra->articulo(self::PREFIJO . $nombre);
        }
    }

    protected function tearDown(): void
    {
        $ids = implode(',', array_values($this->articulo));
        $this->db->query("DELETE FROM articulosCodigoBarras WHERE idArticulo IN ($ids)");
        $this->db->query("DELETE FROM articulosTiendas WHERE idArticulo IN ($ids)");
        $this->db->query("DELETE FROM articulosPrecios WHERE idArticulo IN ($ids)");
        $this->escenario->limpiar(array_values($this->articulo));
        foreach ($this->usuariosCreados as $idUsuario) {
            $this->db->query('DELETE FROM permisos WHERE idUsuario = ' . $idUsuario);
            $this->db->query('DELETE FROM usuarios WHERE id = ' . $idUsuario);
        }
        ini_set('error_log', (string) $this->registroDeErrores);
        parent::tearDown();
    }

    /**
     * @comportamiento El permiso se comprueba antes que nada: quien solo puede ver el listado no
     *   busca, ni compone líneas, ni guarda, ni cancela, ni activa. Y preparar no da derecho a
     *   poner a regir: sin el permiso de activar, la activación se rechaza.
     */
    public function test_T1_cadaPeticionSinElPermisoDeSuAccionSeRechazaSinLeerNiEscribir(): void
    {
        $vigente = $this->despieceDelPollo();
        $temporal = $this->escenario->temporal($this->despiece, $this->lineasDelPollo('0.25'));
        $soloVer = $this->sesion([['ver', 1]]);
        $preparaPeroNoActiva = $this->sesion([['ver', 1], ['Crear', 1], ['Modificar', 1], ['Activar', 0]]);

        $respuestas = [
            'buscar' => $this->despachar(['pulsado' => 'buscarProductos', 'caja' => 'Descripcion', 'texto' => self::PREFIJO . 'pollo'], $soloVer),
            'líneas' => $this->despachar(['pulsado' => 'htmlLineasRelacion', 'numRelacion' => $vigente['numRelacion'], 'idTipo' => $this->despiece, 'lineas' => $this->lineasDelPollo('0.25')], $soloVer),
            'guardar' => $this->despachar($this->guardar($this->lineasDelPollo('0.25')), $soloVer),
            'cancelar' => $this->despachar(['pulsado' => 'cancelarTemporal', 'id' => $temporal], $soloVer),
            'activar' => $this->despachar(['pulsado' => 'activarTemporal', 'id' => $temporal], $soloVer),
            'activar sin su permiso' => $this->despachar(['pulsado' => 'activarTemporal', 'id' => $temporal], $preparaPeroNoActiva),
        ];

        foreach ($respuestas as $caso => $respuesta) {
            self::assertStringContainsString('No tienes permiso', $respuesta['error']['mensaje'] ?? '', $caso);
            self::assertArrayNotHasKey('datos', $respuesta, $caso);
        }
        self::assertSame(1, $this->cuantas('relacionesTemporales'));
        self::assertSame(['1.0 Activo'], $this->versiones($vigente['numRelacion']));
    }

    /**
     * @comportamiento Crear vale para una relación nueva y modificar para una que rige. De un
     *   temporal ya guardado, cuál es lo dice la base, no la petición.
     */
    public function test_T2_crearValeParaLasNuevasYModificarParaLasQueRigenSegunLoGuardado(): void
    {
        $vigente = $this->despieceDelPollo();
        $nuevo = $this->escenario->temporal($this->despiece, $this->lineasDelPollo('0.25'), ['fecha' => '2026-10-01 10:00:00']);
        $soloCrear = $this->sesion([['ver', 1], ['Crear', 1], ['Modificar', 0]]);
        $soloModificar = $this->sesion([['ver', 1], ['Crear', 0], ['Modificar', 1]]);

        $nuevaConCrear = $this->despachar($this->guardar($this->lineasDelPollo('0.25')), $soloCrear);
        $cambioConCrear = $this->despachar($this->guardar($this->lineasDelPollo('0.25'), ['numRelacion' => $vigente['numRelacion']]), $soloCrear);
        $nuevaConModificar = $this->despachar($this->guardar($this->lineasDelPollo('0.25')), $soloModificar);
        $cambioConModificar = $this->despachar($this->guardar($this->lineasDelPollo('0.25'), ['numRelacion' => $vigente['numRelacion'], 'origenAjuste' => 'Pesada']), $soloModificar);
        $nuevoDisfrazado = $this->despachar($this->guardar($this->lineasDelPollo('0.30'), ['id' => $nuevo, 'numRelacion' => $vigente['numRelacion'], 'fechaModificacion' => '2026-10-01 10:00:00']), $soloModificar);
        $cancelarNuevoConModificar = $this->despachar(['pulsado' => 'cancelarTemporal', 'id' => $nuevo], $soloModificar);

        self::assertArrayHasKey('datos', $nuevaConCrear);
        self::assertStringContainsString('No tienes permiso', $cambioConCrear['error']['mensaje']);
        self::assertStringContainsString('No tienes permiso', $nuevaConModificar['error']['mensaje']);
        self::assertArrayHasKey('datos', $cambioConModificar);
        self::assertStringContainsString('No tienes permiso', $nuevoDisfrazado['error']['mensaje']);
        self::assertStringContainsString('No tienes permiso', $cancelarNuevoConModificar['error']['mensaje']);
        self::assertSame('0.25', $this->lineasDelTemporal($nuevo)[1]['cantidad']);
    }

    /** @comportamiento Crear y Modificar se llaman igual en otros listados: los de compras no valen aquí. */
    public function test_T3_losPermisosDelMismoNombreDeOtroModuloNoValen(): void
    {
        $sesion = $this->sesion([['ver', 1]], [
            ['mod_compras', 'albaranesListado.php', 'Crear', 1],
            ['mod_compras', 'albaranesListado.php', 'Modificar', 1],
        ]);

        $respuesta = $this->despachar($this->guardar($this->lineasDelPollo('0.25')), $sesion);

        self::assertStringContainsString('No tienes permiso', $respuesta['error']['mensaje']);
        self::assertSame(0, $this->cuantas('relacionesTemporales'));
    }

    /**
     * @comportamiento Los permisos de crear, modificar y activar se añaden a una pantalla que ya
     *   existe: los reparte «Reorganizar permisos», concedidos al administrador y denegados al resto.
     *
     * Corre en un proceso aparte, como el reparto del permiso de cambiar el estado: la tarea de
     * reorganizar carga una clase que se llama igual que otra del módulo de productos.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T4_reorganizarPermisosDaCrearModificarYActivarAlAdministradorYSeLosNiegaAlResto(): void
    {
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseReorganizar.php');
        $sesion = $this->sesion([], [], 9);
        $this->db->query("DELETE FROM permisos WHERE modulo = 'mod_relaciones' AND accion IN ('Crear', 'Modificar', 'Activar')");
        $usuarios = $this->db->query('SELECT id, group_id FROM usuarios ORDER BY id')->fetch_all(MYSQLI_ASSOC);

        foreach (array_keys($usuarios) as $posicion) {
            DespachoTareas::invocar(RUTA_TPVFOX . '/modulos/mod_reorganizacion/tareas.php', $this->db, ['pulsado' => 'reorganizarPermisosModulos', 'inicial' => (string) $posicion, 'total' => (string) count($usuarios)], $sesion);
        }

        foreach ($usuarios as $usuario) {
            $filas = $this->db->query("SELECT accion, permiso FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_relaciones' AND vista = 'ListaRelaciones.php' AND accion IN ('Crear', 'Modificar', 'Activar') ORDER BY accion")->fetch_all(MYSQLI_ASSOC);
            $esperado = $usuario['group_id'] == 9 ? '1' : '0';

            self::assertSame(['Activar', 'Crear', 'Modificar'], array_column($filas, 'accion'), 'usuario ' . $usuario['id']);
            self::assertSame([$esperado, $esperado, $esperado], array_column($filas, 'permiso'), 'usuario ' . $usuario['id']);
        }
    }

    /** @comportamiento El autor del temporal y de la versión activada es el de la sesión, aunque la petición traiga otro. */
    public function test_T5_elAutorEsElDeLaSesionAunqueLaPeticionTraigaOtro(): void
    {
        $sesion = $this->sesion([['ver', 1], ['Crear', 1], ['Activar', 1]]);
        $otro = $this->escenario->usuario();

        $guardado = $this->despachar($this->guardar($this->lineasDelPollo('0.25'), ['idUsuario' => $otro]), $sesion);
        $idTemporal = $guardado['datos']['id'];
        $autorDelTemporal = (int) $this->db->query("SELECT idUsuario FROM relacionesTemporales WHERE id = $idTemporal")->fetch_assoc()['idUsuario'];
        $activado = $this->despachar(['pulsado' => 'activarTemporal', 'id' => $idTemporal, 'idUsuario' => $otro], $sesion);
        $numRelacion = $activado['datos']['numRelacion'];

        self::assertSame($sesion['Usuario']['id'], $autorDelTemporal);
        self::assertSame($sesion['Usuario']['id'], (int) $this->db->query("SELECT idUsuario FROM relaciones WHERE numRelacion = $numRelacion")->fetch_assoc()['idUsuario']);
    }

    /**
     * @comportamiento El número se convierte a entero antes de usarlo: uno que no es un número,
     *   negativo, cero o que intenta cerrar la sentencia no corresponde a ningún temporal.
     */
    public function test_T6_numerosManipuladosNoCorrespondenANingunTemporalYNoCambianNada(): void
    {
        $temporal = $this->escenario->temporal($this->despiece, $this->lineasDelPollo('0.25'));
        $sesion = $this->sesion([['ver', 1], ['Crear', 1], ['Modificar', 1], ['Activar', 1]]);

        foreach (['abc', '-1', '0', '1e9', "' OR '1'='1", ''] as $numero) {
            foreach (['cancelarTemporal', 'activarTemporal'] as $pulsado) {
                $respuesta = $this->despachar(['pulsado' => $pulsado, 'id' => $numero], $sesion);
                self::assertArrayHasKey('error', $respuesta, "{$pulsado} «{$numero}»");
            }
        }
        self::assertSame(1, $this->cuantas('relacionesTemporales'));
        self::assertSame(0, $this->cuantas('relaciones'));
    }

    /** @comportamiento Se busca primero el valor idéntico en el campo de la caja, como en el albarán. */
    public function test_T7_cadaCajaBuscaEnSuCampoYPrimeroElValorIdentico(): void
    {
        $campero = $this->articuloConDatos('Pollo campero', 'PV-CAMPERO-1', '8400000000017');
        $this->articuloConDatos('Pollo campero grande', 'PV-CAMPERO-2', '8400000000024');
        $sesion = $this->sesion([['ver', 1], ['Crear', 1]]);

        foreach (['idArticulo' => (string) $campero, 'Referencia' => 'PV-CAMPERO-1', 'Codbarras' => '8400000000017', 'Descripcion' => self::PREFIJO . 'Pollo campero'] as $caja => $texto) {
            $productos = $this->buscar($caja, $texto, $sesion);
            self::assertSame([$campero], array_map('intval', array_column($productos, 'idArticulo')), $caja);
            self::assertSame('PV-CAMPERO-1', $productos[0]['crefTienda'], $caja);
            self::assertSame('8400000000017', $productos[0]['codBarras'], $caja);
            self::assertSame(self::PREFIJO . 'Pollo campero', $productos[0]['articulo_name'], $caja);
        }
    }

    /** @comportamiento Sin valor idéntico se busca por palabras, en cualquier orden, y se devuelven 30 como mucho. */
    public function test_T8_sinValorIdenticoSeBuscaPorPalabrasYHasta30(): void
    {
        for ($n = 1; $n <= 31; $n++) {
            $this->articulo["lote $n"] = $this->siembra->articulo(self::PREFIJO . "lote $n zeta");
        }
        $sesion = $this->sesion([['ver', 1], ['Crear', 1]]);

        $productos = $this->buscar('Descripcion', 'zeta lote', $sesion);

        self::assertCount(30, $productos);
    }

    /** @comportamiento Lo escrito se busca como texto: un % no es un comodín y unas comillas no cierran la sentencia. */
    public function test_T9_losComodinesYLasComillasDeLoBuscadoSonTexto(): void
    {
        $porcentaje = $this->articuloConDatos('magro 50%', null, null);
        $this->articuloConDatos('magro 500', null, null);
        $comillas = $this->articuloConDatos("queso O'Brien", null, null);
        $sesion = $this->sesion([['ver', 1], ['Crear', 1]]);

        self::assertSame([$porcentaje], array_map('intval', array_column($this->buscar('Descripcion', '50%', $sesion), 'idArticulo')));
        self::assertSame([$comillas], array_map('intval', array_column($this->buscar('Descripcion', "O'Brien", $sesion), 'idArticulo')));
        self::assertSame([], $this->buscar('Descripcion', "' OR '1'='1", $sesion));
    }

    /** @comportamiento Un artículo de baja no se ofrece: la admisión lo rechazaría al activar. */
    public function test_T10_unArticuloDeBajaNoSeOfrece(): void
    {
        $retirado = $this->articuloConDatos('retirado del catálogo', 'PV-RETIRADO', null);
        $this->db->query("UPDATE articulos SET estado = 'Baja' WHERE idArticulo = $retirado");
        $sesion = $this->sesion([['ver', 1], ['Crear', 1]]);

        self::assertSame([], $this->buscar('Descripcion', 'retirado del catálogo', $sesion));
        self::assertSame([], $this->buscar('Referencia', 'PV-RETIRADO', $sesion));
    }

    /** @comportamiento La referencia es la de la tienda principal; un artículo sin referencia también se encuentra. */
    public function test_T11_laReferenciaEsLaDeLaTiendaPrincipalYSinReferenciaTambienSale(): void
    {
        $conReferencia = $this->articuloConDatos('con referencia', 'PV-PRINCIPAL', null);
        $sinReferencia = $this->articuloConDatos('sin referencia', null, null);
        $sesion = $this->sesion([['ver', 1], ['Crear', 1]]);

        $con = $this->buscar('Descripcion', 'con referencia', $sesion);
        $sin = $this->buscar('Descripcion', 'sin referencia', $sesion);

        self::assertSame([$conReferencia], array_map('intval', array_column($con, 'idArticulo')));
        self::assertSame('PV-PRINCIPAL', $con[0]['crefTienda']);
        self::assertSame([$sinReferencia], array_map('intval', array_column($sin, 'idArticulo')));
        self::assertNull($sin[0]['crefTienda']);
    }

    /**
     * @comportamiento Las líneas se componen en el servidor comparándolas con la versión que
     *   rige: la pieza nueva, la quitada y la cantidad cambiada salen marcadas, con el tipo de
     *   cambio que constará al activar. Componerlas no escribe nada.
     */
    public function test_T12_lasLineasSeComponenConSusMarcasYElTipoDeCambioSinEscribir(): void
    {
        $vigente = $this->despieceDelPollo();
        $sesion = $this->sesion([['ver', 1], ['Modificar', 1]]);
        $lineas = [$this->linea('pollo', 'entrada', '1'), $this->linea('pechuga', 'salida', '0.25'), $this->linea('muslo', 'salida', '0.3')];

        $respuesta = $this->despachar(['pulsado' => 'htmlLineasRelacion', 'numRelacion' => $vigente['numRelacion'], 'idTipo' => $this->despiece, 'lineas' => $lineas], $sesion);

        $marcas = [];
        foreach ($respuesta['datos']['lineas'] as $linea) {
            $marcas[(int) $linea['idArticulo']] = $linea['marca'];
        }
        self::assertSame([
            $this->articulo['pollo'] => '',
            $this->articulo['pechuga'] => 'cambiada',
            $this->articulo['muslo'] => 'nueva',
            $this->articulo['alas'] => 'quitada',
        ], $marcas);
        self::assertSame('composicion', $respuesta['datos']['tipoCambio']);
        self::assertStringContainsString('marca-nueva', $respuesta['datos']['salida']);
        self::assertStringContainsString('marca-quitada', $respuesta['datos']['salida']);
        self::assertStringContainsString(self::PREFIJO . 'pollo', $respuesta['datos']['entrada']);
        self::assertSame(0, $this->cuantas('relacionesTemporales'));
        self::assertSame(['1.0 Activo'], $this->versiones($vigente['numRelacion']));
    }

    /** @comportamiento Una pieza cuyo artículo se dio de baja o se borró después de añadirla sale marcada. */
    public function test_T13_unaPiezaDeUnArticuloDeBajaOInexistenteSaleMarcada(): void
    {
        $sesion = $this->sesion([['ver', 1], ['Crear', 1]]);
        $this->db->query("UPDATE articulos SET estado = 'Baja' WHERE idArticulo = {$this->articulo['muslo']}");
        $lineas = [$this->linea('pollo', 'entrada', '1'), $this->linea('muslo', 'salida', '0.3'), ['idArticulo' => 999999999, 'sentido' => 'salida', 'cantidad' => '0.1', 'unidad' => 'kg']];

        $respuesta = $this->despachar(['pulsado' => 'htmlLineasRelacion', 'numRelacion' => 0, 'idTipo' => $this->despiece, 'lineas' => $lineas], $sesion);

        $catalogo = array_column($respuesta['datos']['lineas'], 'catalogo', 'idArticulo');
        self::assertSame('', $catalogo[$this->articulo['pollo']]);
        self::assertSame('baja', $catalogo[$this->articulo['muslo']]);
        self::assertSame('noExiste', $catalogo[999999999]);
        self::assertStringContainsString('de baja', $respuesta['datos']['salida']);
        self::assertStringContainsString('no existe', $respuesta['datos']['salida']);
    }

    /**
     * @comportamiento Un rechazo de la activación llega como aviso amarillo, que el usuario puede
     *   corregir, y dice qué falla, dónde y cómo resolverlo: la línea sin cantidad por su lado y su
     *   número; el artículo de baja por su nombre.
     */
    public function test_T14_elRechazoDeLaActivacionDiceDondeYComoResolverloConSuGravedad(): void
    {
        $sesion = $this->sesion([['ver', 1], ['Crear', 1], ['Activar', 1]]);
        $sinCantidad = $this->escenario->temporal($this->despiece, [$this->linea('pollo', 'entrada', '1'), $this->linea('pechuga', 'salida', null)]);
        $conBaja = $this->escenario->temporal($this->despiece, [$this->linea('alas', 'entrada', '1'), $this->linea('muslo', 'salida', '0.3')]);
        $this->db->query("UPDATE articulos SET estado = 'Baja' WHERE idArticulo = {$this->articulo['muslo']}");

        $porCantidad = $this->despachar(['pulsado' => 'activarTemporal', 'id' => $sinCantidad], $sesion);
        $porBaja = $this->despachar(['pulsado' => 'activarTemporal', 'id' => $conBaja], $sesion);

        self::assertSame('warning', $porCantidad['error']['tipo']);
        self::assertStringContainsString('salida 1', $porCantidad['error']['mensaje']);
        self::assertStringContainsString('escríbela', $porCantidad['error']['mensaje']);
        self::assertSame('warning', $porBaja['error']['tipo']);
        self::assertStringContainsString(self::PREFIJO . 'muslo', $porBaja['error']['mensaje']);
        self::assertStringContainsString('quítalo', $porBaja['error']['mensaje']);
        self::assertSame(2, $this->cuantas('relacionesTemporales'));
    }

    // --- Ayudantes ---------------------------------------------------------------------------

    /** @return list<array<string,mixed>> los productos que devuelve la búsqueda */
    private function buscar(string $caja, string $texto, array $sesion): array
    {
        $respuesta = $this->despachar(['pulsado' => 'buscarProductos', 'caja' => $caja, 'texto' => $texto], $sesion);
        self::assertArrayHasKey('datos', $respuesta, $caja . ' «' . $texto . '»');

        return $respuesta['datos']['productos'];
    }

    /** Un artículo con su referencia en la tienda principal y su código de barras, si se dan. */
    private function articuloConDatos(string $nombre, ?string $referencia, ?string $codigoDeBarras): int
    {
        $id = $this->siembra->articulo(self::PREFIJO . $nombre);
        $this->articulo[$nombre] = $id;
        if ($referencia !== null) {
            $this->siembra->precioYTienda($id, 1.0, 1.0, null, ['crefTienda' => $referencia]);
        }
        if ($codigoDeBarras !== null) {
            $alta = $this->db->prepare('INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES (?, ?)');
            $alta->bind_param('is', $id, $codigoDeBarras);
            $alta->execute();
        }

        return $id;
    }

    /** Lo que manda «Guardar» para una relación nueva, o lo que se indique. */
    private function guardar(array $lineas, array $opciones = []): array
    {
        return [
            'pulsado' => 'guardarTemporal',
            'id' => $opciones['id'] ?? 0,
            'numRelacion' => $opciones['numRelacion'] ?? 0,
            'idTipo' => $this->despiece,
            'nombre' => '',
            'descripcion' => '',
            'diasViabilidad' => '',
            'origenAjuste' => $opciones['origenAjuste'] ?? '',
            'lineas' => $lineas,
            'fechaModificacion' => $opciones['fechaModificacion'] ?? '',
        ] + (isset($opciones['idUsuario']) ? ['idUsuario' => $opciones['idUsuario']] : []);
    }

    private function linea(string $nombre, string $sentido, ?string $cantidad, ?string $unidad = 'kg'): array
    {
        return ['idArticulo' => $this->articulo[$nombre], 'sentido' => $sentido, 'cantidad' => $cantidad, 'unidad' => $unidad];
    }

    private function lineasDelPollo(string $pechuga): array
    {
        return [$this->linea('pollo', 'entrada', '1'), $this->linea('pechuga', 'salida', $pechuga), $this->linea('alas', 'salida', '0.12')];
    }

    /** @return array{id:int,numRelacion:int} */
    private function despieceDelPollo(): array
    {
        return $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo('0.23'), ['nombre' => 'Pollo entero']);
    }

    private function lineasDelTemporal(int $id): array
    {
        return json_decode($this->db->query("SELECT lineas FROM relacionesTemporales WHERE id = $id")->fetch_assoc()['lineas'], true);
    }

    /**
     * La sesión de un usuario nuevo del grupo indicado —1 por defecto; 9 es el de
     * administradores—, con los permisos que el producto le da al entrar y, encima, los de la
     * vista del listado que fije el caso y los de otros módulos que se indiquen.
     *
     * @param list<array{0:string,1:int}> $delListado acción y valor
     * @param list<array{0:string,1:string,2:string,3:int}> $deOtros módulo, vista, acción y valor
     * @return array{ClasePermisos:object,Usuario:array{id:int,group_id:int}}
     */
    private function sesion(array $delListado, array $deOtros = [], int $grupo = 1): array
    {
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        $nombre = 'prueba_vista_' . $grupo . '_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), $grupo, 'activo', 'Prueba')");
        $usuario = ['id' => (int) $this->db->insert_id, 'group_id' => $grupo];
        $this->usuariosCreados[] = $usuario['id'];

        new \ClasePermisos($usuario, $this->db);
        $fijados = $deOtros;
        foreach ($delListado as [$accion, $valor]) {
            $fijados[] = ['mod_relaciones', 'ListaRelaciones.php', $accion, $valor];
        }
        foreach ($fijados as [$modulo, $vista, $accion, $valor]) {
            $this->db->query("DELETE FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = '$modulo' AND vista = '$vista' AND accion = '$accion'");
            $this->db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ({$usuario['id']}, '$modulo', '$vista', '$accion', $valor)");
        }

        return ['ClasePermisos' => new \ClasePermisos($usuario, $this->db), 'Usuario' => $usuario];
    }

    private function cuantas(string $tabla): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM $tabla")->fetch_assoc()['n'];
    }

    /** @return list<string> cada versión de la relación como «versión estado», por orden de alta */
    private function versiones(int $numRelacion): array
    {
        $filas = $this->db->query("SELECT version, estado FROM relaciones WHERE numRelacion = $numRelacion ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        $versiones = [];
        foreach ($filas as $fila) {
            $versiones[] = $fila['version'] . ' ' . $fila['estado'];
        }

        return $versiones;
    }

    private function despachar(array $post, array $variables = []): mixed
    {
        return DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, $post, $variables);
    }
}

<?php
/**
 * Reactivar una relación dada de baja, y lo que cambia al tener las relaciones de baja a la vista:
 * el listado con sus dos clases, ordenable por estado, y una relación de baja que ninguna
 * operación alcanza. La reactivación es el cambio contrario al de la baja: la misma versión pasa a
 * vigente, sin versión nueva, solo si nada la repite y si sus productos siguen siendo utilizables.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModRelaciones;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\DespachoTareas;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

final class RelacionesReactivacionIntegracionTest extends CasoIntegracion
{
    /** El módulo abre su propia transacción: estos casos limpian lo que escriben. */
    protected bool $aislarPorTransaccion = false;

    protected bool $compartirConexionConElProducto = true;

    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_relaciones/tareas.php';

    private EscenarioRelaciones $escenario;
    private Siembra $siembra;
    private string|false $registroDeErrores = false;
    private int $usuario;
    private int $despiece;
    /** @var array<string,int> */
    private array $articulo = [];
    /** @var list<int> */
    private array $usuariosCreados = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/Relaciones.php');
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/RelacionesTemporales.php');
        $this->registroDeErrores = ini_set('error_log', '/dev/null');

        $this->escenario = new EscenarioRelaciones($this->db);
        $this->siembra = new Siembra($this->db);
        $this->escenario->limpiar();

        $this->usuario = $this->escenario->usuario();
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        foreach (['pollo', 'pechuga', 'alas', 'conejo', 'paletilla', 'lomo'] as $nombre) {
            $this->articulo[$nombre] = $this->siembra->articulo('Prueba de relaciones: ' . $nombre);
        }
    }

    protected function tearDown(): void
    {
        $this->escenario->limpiar(array_values($this->articulo));
        foreach ($this->usuariosCreados as $idUsuario) {
            $this->db->query('DELETE FROM permisos WHERE idUsuario = ' . $idUsuario);
            $this->db->query('DELETE FROM usuarios WHERE id = ' . $idUsuario);
        }
        ini_set('error_log', (string) $this->registroDeErrores);
        parent::tearDown();
    }

    /**
     * @comportamiento Reactivar devuelve la versión de baja a vigente, con su número, sus versiones
     *   y sus líneas, sin crear versión ni cambiar las que ya tiene.
     */
    public function test_T1_reactivarDevuelveLaMismaVersionAVigenteSinVersionNueva(): void
    {
        $pollo = $this->despieceDelPollo();
        $cambio = $this->relaciones()->modificar($pollo, [], $this->lineasDelPollo(0.700), $this->usuario, 'pesada del día');
        self::assertArrayHasKey('datos', $cambio, json_encode($cambio['error'] ?? null, JSON_UNESCAPED_UNICODE));
        $this->relaciones()->darDeBaja($pollo, $this->usuario);
        $lineasAntes = $this->lineasDeLaVersion($pollo, 'Baja');
        $idsAntes = $this->db->query("SELECT id, fechaCreacion FROM relaciones WHERE numRelacion = $pollo ORDER BY id")->fetch_all(MYSQLI_ASSOC);
        self::assertSame(['1.0 Sustituido', '1.1 Baja'], $this->versiones($pollo));

        $respuesta = $this->relaciones()->reactivar($pollo, $this->usuario);

        self::assertArrayHasKey('datos', $respuesta, json_encode($respuesta['error'] ?? null, JSON_UNESCAPED_UNICODE));
        self::assertSame($pollo, $respuesta['datos']['numRelacion']);
        self::assertSame(['1.0 Sustituido', '1.1 Activo'], $this->versiones($pollo));
        self::assertSame($lineasAntes, $this->lineasDeLaVersion($pollo, 'Activo'));
        self::assertSame($idsAntes, $this->db->query("SELECT id, fechaCreacion FROM relaciones WHERE numRelacion = $pollo ORDER BY id")->fetch_all(MYSQLI_ASSOC));
    }

    /** @comportamiento Una relación que no está de baja, o que no existe, no se reactiva. */
    public function test_T2_unaRelacionQueNoEstaDeBajaOQueNoExisteSeRechaza(): void
    {
        $pollo = $this->despieceDelPollo();

        $vigente = $this->relaciones()->reactivar($pollo, $this->usuario);
        $inexistente = $this->relaciones()->reactivar(999999, $this->usuario);

        self::assertArrayHasKey('error', $vigente);
        self::assertStringContainsString('no está de baja', $vigente['error']['mensaje']);
        self::assertArrayHasKey('error', $inexistente);
        self::assertSame(['1.0 Activo'], $this->versiones($pollo));
    }

    /**
     * @comportamiento Si mientras estaba de baja se dio de alta otra relación igual, la reactivación
     *   se rechaza y nombra a la otra. Una igual de otro tipo no cuenta como repetida.
     */
    public function test_T3_reactivarSeRechazaSiYaHayOtraIgualVigente(): void
    {
        $pollo = $this->despieceDelPollo();
        $this->relaciones()->darDeBaja($pollo, $this->usuario);
        $otra = $this->relaciones()->alta($this->despiece, [], $this->lineasDelPollo(), $this->usuario);
        self::assertArrayHasKey('datos', $otra, 'la de baja no cuenta como repetida al dar de alta: ' . json_encode($otra['error'] ?? null, JSON_UNESCAPED_UNICODE));

        $rechazo = $this->relaciones()->reactivar($pollo, $this->usuario);

        self::assertArrayHasKey('error', $rechazo);
        self::assertStringContainsString('la ' . $otra['datos']['numRelacion'], $rechazo['error']['mensaje']);
        self::assertSame(['1.0 Baja'], $this->versiones($pollo));

        $this->relaciones()->darDeBaja($otra['datos']['numRelacion'], $this->usuario);
        $otroTipo = $this->escenario->tipoDeRelacion('Despiece B', '1:N');
        $this->relaciones()->alta($otroTipo, [], $this->lineasDelPollo(), $this->usuario);
        $admitida = $this->relaciones()->reactivar($pollo, $this->usuario);

        self::assertArrayHasKey('datos', $admitida, 'una igual de otro tipo no cuenta');
        self::assertSame(['1.0 Activo'], $this->versiones($pollo));
    }

    /** @comportamiento Si algún producto de sus líneas está de baja, no se reactiva y se nombra. */
    public function test_T4_reactivarSeRechazaSiUnProductoDeSusLineasEstaDeBaja(): void
    {
        $pollo = $this->despieceDelPollo();
        $this->relaciones()->darDeBaja($pollo, $this->usuario);
        $this->db->query("UPDATE articulos SET estado = 'Baja' WHERE idArticulo = {$this->articulo['pechuga']}");

        $rechazo = $this->relaciones()->reactivar($pollo, $this->usuario);

        self::assertArrayHasKey('error', $rechazo);
        self::assertStringContainsString('Prueba de relaciones: pechuga', $rechazo['error']['mensaje']);
        self::assertSame(['1.0 Baja'], $this->versiones($pollo));

        $this->db->query("UPDATE articulos SET estado = 'Activo' WHERE idArticulo = {$this->articulo['pechuga']}");
        self::assertArrayHasKey('datos', $this->relaciones()->reactivar($pollo, $this->usuario));
    }

    /** @comportamiento Reactivar exige un usuario identificado, aunque no lo guarde. */
    public function test_T5_sinUsuarioIdentificadoNoSeReactiva(): void
    {
        $pollo = $this->despieceDelPollo();
        $this->relaciones()->darDeBaja($pollo, $this->usuario);

        $rechazo = $this->relaciones()->reactivar($pollo, 0);

        self::assertArrayHasKey('error', $rechazo);
        self::assertSame(['1.0 Baja'], $this->versiones($pollo));
    }

    /**
     * @comportamiento Mientras se reactiva, otra conexión no puede darla de baja: espera y vence, y
     *   la relación queda con una sola versión vigente.
     */
    public function test_T6_mientrasSeReactivaOtraConexionNoPuedeDarlaDeBaja(): void
    {
        $pollo = $this->despieceDelPollo();
        $this->relaciones()->darDeBaja($pollo, $this->usuario);
        $otra = $this->otraConexion();
        $relaciones = $this->relacionesQueAlLeerConBloqueo($otra, "UPDATE relaciones SET estado = 'Baja' WHERE numRelacion = $pollo");

        $respuesta = $relaciones->reactivar($pollo, $this->usuario);
        $otra->close();

        self::assertSame(1205, $relaciones->errorDeLaOtraConexion);
        self::assertArrayHasKey('datos', $respuesta);
        self::assertSame(['1.0 Activo'], $this->versiones($pollo));
        self::assertSame([], $this->relaciones()->comprobarVersiones()['datos']);
    }

    /**
     * @comportamiento Tras reactivar, la consulta de comprobación no devuelve nada, la consulta por
     *   fecha da la versión que regía y las lecturas de lo vigente la incluyen.
     */
    public function test_T7_trasReactivarLasLecturasLaVenComoCualquierOtra(): void
    {
        $pollo = $this->despieceDelPollo();
        $this->relaciones()->darDeBaja($pollo, $this->usuario);
        self::assertNull($this->relaciones()->vigente($pollo)['datos']);

        $this->relaciones()->reactivar($pollo, $this->usuario);

        self::assertSame([], $this->relaciones()->comprobarVersiones()['datos']);
        self::assertSame('1.0', $this->relaciones()->vigente($pollo)['datos']['version']);
        $fecha = $this->relaciones()->estadoEnFecha($pollo, '2099-01-01')['datos'];
        self::assertSame(['regia', 'Activo'], [$fecha['situacion'], $fecha['version']['estado']]);
        self::assertContains($pollo, array_map('intval', array_column($this->relaciones()->vigentes()['datos'], 'numRelacion')));
    }

    /**
     * @comportamiento El listado trae las vigentes y las de baja, una fila por relación, cada una con
     *   su estado y, en la de baja, la última versión que tuvo. El borrador no cuenta.
     */
    public function test_T8_elListadoTraeLasVigentesYLasDeBajaConSuEstado(): void
    {
        $pollo = $this->despieceDelPollo();
        $this->relaciones()->modificar($pollo, [], $this->lineasDelPollo(0.700), $this->usuario, 'pesada del día');
        $conejo = $this->despieceDelConejo();
        $this->relaciones()->darDeBaja($conejo, $this->usuario);
        $this->relaciones()->crearBorrador($this->despiece, [], [$this->linea('alas', 'entrada')], $this->usuario);

        $todas = $this->relaciones()->listado('', '', '', 1, 40)['datos'];

        self::assertSame(2, $todas['total']);
        $porNumero = [];
        foreach ($todas['filas'] as $fila) {
            $porNumero[$fila['numRelacion']] = $fila;
        }
        self::assertSame(['Activo', '1.1'], [$porNumero[$pollo]['estado'], $porNumero[$pollo]['version']]);
        self::assertSame(['Baja', '1.0', 3], [$porNumero[$conejo]['estado'], $porNumero[$conejo]['version'], $porNumero[$conejo]['numProductos']]);
    }

    /**
     * @comportamiento Ordenar por estado junta las relaciones de baja, de las dos maneras; un orden
     *   que el listado no ofrece se trata como ausente.
     */
    public function test_T9_ordenarPorEstadoJuntaLasDeBaja(): void
    {
        $pollo = $this->despieceDelPollo();
        $conejo = $this->despieceDelConejo();
        $otro = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['alas'], 'sentido' => 'entrada', 'cantidad' => 1.000],
            ['idArticulo' => $this->articulo['lomo'], 'sentido' => 'salida', 'cantidad' => 0.500],
        ], ['nombre' => 'Alas'])['numRelacion'];
        $this->relaciones()->darDeBaja($pollo, $this->usuario);

        $ascendente = array_column($this->relaciones()->listado('', '', 'estado:ASC', 1, 40)['datos']['filas'], 'estado');
        $descendente = array_column($this->relaciones()->listado('', '', 'estado:DESC', 1, 40)['datos']['filas'], 'estado');
        $ordenInventado = array_column($this->relaciones()->listado('', '', 'estado:ASC; DROP TABLE relaciones', 1, 40)['datos']['filas'], 'numRelacion');

        self::assertSame(['Activo', 'Activo', 'Baja'], $ascendente);
        self::assertSame(['Baja', 'Activo', 'Activo'], $descendente);
        self::assertSame([$pollo, $conejo, $otro], $ordenInventado);
    }

    /** @comportamiento Buscar por un producto o por el nombre encuentra la relación de baja, con su estado. */
    public function test_T10_laBusquedaEncuentraLasRelacionesDeBaja(): void
    {
        $conejo = $this->despieceDelConejo();
        $this->relaciones()->darDeBaja($conejo, $this->usuario);

        $porProducto = $this->relaciones()->listado('paletilla', '', '', 1, 40)['datos'];
        $porNombre = $this->relaciones()->listado('Conejo', '', '', 1, 40)['datos'];

        self::assertSame(1, $porProducto['total']);
        self::assertSame('Baja', $porProducto['filas'][0]['estado']);
        self::assertSame(1, $porNombre['total']);
    }

    /**
     * @comportamiento Sobre una relación de baja, crear su borrador, modificarla y guardar un cambio se
     *   rechazan con un aviso que dice que está de baja, sin escribir.
     */
    public function test_T11_unaRelacionDeBajaNoSeAlcanzaPorNingunaOperacion(): void
    {
        $pollo = $this->despieceDelPollo();
        $this->relaciones()->darDeBaja($pollo, $this->usuario);

        $borrador = $this->relaciones()->crearBorradorDeCambio($pollo, $this->usuario);
        $modificada = $this->relaciones()->modificar($pollo, [], $this->lineasDelPollo(0.700), $this->usuario, 'pesada del día');
        $guardado = (new \RelacionesTemporales())->guardar([
            'id' => 0, 'numRelacion' => $pollo, 'idTipo' => $this->despiece, 'nombre' => '', 'descripcion' => '',
            'diasViabilidad' => '', 'origenAjuste' => '', 'lineas' => $this->lineasDelPollo(0.700), 'fechaModificacion' => '',
        ], $this->usuario);

        foreach (['crearBorradorDeCambio' => $borrador, 'modificar' => $modificada, 'guardar un cambio' => $guardado] as $operacion => $respuesta) {
            self::assertArrayHasKey('error', $respuesta, $operacion);
            self::assertStringContainsString('está de baja', $respuesta['error']['mensaje'], $operacion);
        }
        self::assertSame(['1.0 Baja'], $this->versiones($pollo));
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) AS n FROM relacionesTemporales')->fetch_assoc()['n']);
    }

    /** @comportamiento Reactivada, las mismas operaciones se admiten como en cualquier relación vigente. */
    public function test_T12_unaRelacionReactivadaAdmiteLasOperacionesDeCualquierOtra(): void
    {
        $pollo = $this->despieceDelPollo();
        $this->relaciones()->darDeBaja($pollo, $this->usuario);
        $this->relaciones()->reactivar($pollo, $this->usuario);

        $borrador = $this->relaciones()->crearBorradorDeCambio($pollo, $this->usuario);
        self::assertArrayHasKey('datos', $borrador);
        $this->relaciones()->descartarBorrador($borrador['datos']['id']);
        $modificada = $this->relaciones()->modificar($pollo, [], $this->lineasDelPollo(0.700), $this->usuario, 'pesada del día');

        self::assertArrayHasKey('datos', $modificada, json_encode($modificada['error'] ?? null, JSON_UNESCAPED_UNICODE));
        self::assertSame(['1.0 Sustituido', '1.1 Activo'], $this->versiones($pollo));
    }

    /**
     * @comportamiento El aviso distingue una relación de baja, que se puede reactivar, de una que no
     *   está vigente por otro motivo, que mantiene el aviso de siempre.
     */
    public function test_T13_elAvisoDistingueUnaRelacionDeBajaDeUnaSustituida(): void
    {
        $pollo = $this->despieceDelPollo();
        $this->relaciones()->darDeBaja($pollo, $this->usuario);
        $sustituida = (int) $this->db->query('SELECT COALESCE(MAX(numRelacion), 0) + 1 AS n FROM relaciones')->fetch_assoc()['n'];
        $this->escenario->versionDeRelacion($sustituida, $this->despiece, '1.0', 'Sustituido', date('Y-m-d H:i:s'), $this->lineasDelPollo());

        $deBaja = $this->relaciones()->crearBorradorDeCambio($pollo, $this->usuario);
        $otra = $this->relaciones()->crearBorradorDeCambio($sustituida, $this->usuario);

        self::assertStringContainsString('está de baja', $deBaja['error']['mensaje']);
        self::assertStringContainsString('reactív', $deBaja['error']['mensaje']);
        self::assertStringContainsString('no está vigente', $otra['error']['mensaje']);
        self::assertStringNotContainsString('está de baja', $otra['error']['mensaje']);
    }

    /**
     * @comportamiento Con el estado `Activo` y el permiso, la petición reactiva las relaciones de baja
     *   marcadas; la que no se puede reactivar se avisa sin impedir las demás.
     */
    public function test_T14_laPeticionReactivaLasMarcadasYAvisaDeLasQueNoPuede(): void
    {
        $pollo = $this->despieceDelPollo();
        $conejo = $this->despieceDelConejo();
        $this->relaciones()->darDeBaja($pollo, $this->usuario);
        $this->relaciones()->darDeBaja($conejo, $this->usuario);
        $vigente = $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['alas'], 'sentido' => 'entrada', 'cantidad' => 1.000],
            ['idArticulo' => $this->articulo['lomo'], 'sentido' => 'salida', 'cantidad' => 0.500],
        ])['numRelacion'];
        $this->db->query("UPDATE articulos SET estado = 'Baja' WHERE idArticulo = {$this->articulo['lomo']}");
        $sesion = $this->sesionDeUnUsuarioDelGrupo(9);

        $respuesta = $this->cambiarEstado([$pollo, $conejo, $vigente], 'Activo', $sesion);

        self::assertSame([$pollo], $respuesta['datos']['cambiadas']);
        self::assertCount(2, $respuesta['avisos']);
        self::assertStringContainsString('Relación ' . $conejo, $respuesta['avisos'][0]['mensaje']);
        self::assertStringContainsString('Relación ' . $vigente, $respuesta['avisos'][1]['mensaje']);
        self::assertSame(['1.0 Activo'], $this->versiones($pollo));
        self::assertSame(['1.0 Baja'], $this->versiones($conejo));

        $baja = $this->cambiarEstado([$pollo], 'Baja', $sesion);
        self::assertSame([$pollo], $baja['datos']['cambiadas']);
    }

    /**
     * @comportamiento Sin el permiso, con un estado destino que ninguna operación admite, sin lista o
     *   con números manipulados, la petición se rechaza sin escribir.
     */
    public function test_T15_laPeticionSeRechazaSinPermisoOConValoresQueNoCorresponden(): void
    {
        $pollo = $this->despieceDelPollo();
        $this->relaciones()->darDeBaja($pollo, $this->usuario);
        $conPermiso = $this->sesionDeUnUsuarioDelGrupo(9);
        $sinPermiso = $this->sesionDeUnUsuarioDelGrupo(9, [['mod_relaciones', 'ListaRelaciones.php', 'CambiarEstado', 0]]);

        $sin = $this->cambiarEstado([$pollo], 'Activo', $sinPermiso);
        self::assertArrayHasKey('error', $sin);
        foreach (['Sustituido', 'Borrador', '', 'activo'] as $estado) {
            self::assertArrayHasKey('error', $this->cambiarEstado([$pollo], $estado, $conPermiso), 'estado «' . $estado . '»');
        }
        self::assertArrayHasKey('error', $this->cambiarEstado([], 'Activo', $conPermiso));
        $manipulados = $this->cambiarEstado(['abc', '-3', '0', "' OR '1'='1"], 'Activo', $conPermiso);

        self::assertSame([], $manipulados['datos']['cambiadas']);
        self::assertCount(4, $manipulados['avisos']);
        self::assertSame(['1.0 Baja'], $this->versiones($pollo));
    }

    private function relaciones(): \Relaciones
    {
        return new \Relaciones();
    }

    /**
     * La clase del módulo que, justo después de su lectura con bloqueo, hace escribir a otra
     * conexión, como si otra persona lo intentara a la vez, y anota el error que esta recibe.
     */
    private function relacionesQueAlLeerConBloqueo(\mysqli $otra, string $escritura): object
    {
        $relaciones = new class extends \Relaciones {
            public ?\mysqli $otra = null;
            public string $escritura = '';
            public int $errorDeLaOtraConexion = 0;

            protected function consultaPreparada($sql, $valores = array())
            {
                $respuesta = parent::consultaPreparada($sql, $valores);
                if ($this->otra !== null && str_contains($sql, 'FOR UPDATE')) {
                    try {
                        if ($this->otra->query($this->escritura) === false) {
                            $this->errorDeLaOtraConexion = $this->otra->errno;
                        }
                    } catch (\mysqli_sql_exception $e) {
                        $this->errorDeLaOtraConexion = $e->getCode();
                    }
                    $this->otra = null;
                }

                return $respuesta;
            }
        };
        $relaciones->otra = $otra;
        $relaciones->escritura = $escritura;

        return $relaciones;
    }

    /** Una segunda conexión a la misma base, que espera un segundo como mucho a un bloqueo. */
    private function otraConexion(): \mysqli
    {
        $otra = self::conectar($this->ejercicio);
        $otra->query('SET SESSION innodb_lock_wait_timeout = 1');

        return $otra;
    }

    private function despieceDelPollo(): int
    {
        return $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['nombre' => 'Pollo entero'])['numRelacion'];
    }

    private function despieceDelConejo(): int
    {
        return $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['conejo'], 'sentido' => 'entrada', 'cantidad' => 1.200],
            ['idArticulo' => $this->articulo['paletilla'], 'sentido' => 'salida', 'cantidad' => 0.300],
            ['idArticulo' => $this->articulo['lomo'], 'sentido' => 'salida', 'cantidad' => 0.450],
        ], ['nombre' => 'Conejo entero'])['numRelacion'];
    }

    /** @return list<array{idArticulo:int,sentido:string,cantidad:float,unidad:string}> */
    private function lineasDelPollo(float $pechuga = 0.630): array
    {
        return [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 2.045, 'unidad' => 'kg'],
            ['idArticulo' => $this->articulo['pechuga'], 'sentido' => 'salida', 'cantidad' => $pechuga, 'unidad' => 'kg'],
            ['idArticulo' => $this->articulo['alas'], 'sentido' => 'salida', 'cantidad' => 0.215, 'unidad' => 'kg'],
        ];
    }

    /** @return array{idArticulo:int,sentido:string,cantidad:float,unidad:string} */
    private function linea(string $articulo, string $sentido, float $cantidad = 1.0): array
    {
        return ['idArticulo' => $this->articulo[$articulo], 'sentido' => $sentido, 'cantidad' => $cantidad, 'unidad' => 'kg'];
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

    /** @return list<array<string,mixed>> las líneas de la versión de la relación que está en ese estado */
    private function lineasDeLaVersion(int $numRelacion, string $estado): array
    {
        $sql = "SELECT l.idArticulo, l.sentido, l.cantidad, l.unidad, l.estado FROM relacionesLineas l"
            . " JOIN relaciones r ON r.id = l.idRelacion WHERE r.numRelacion = $numRelacion AND r.estado = '$estado' ORDER BY l.id";

        return $this->db->query($sql)->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * La sesión de un usuario nuevo de ese grupo, con los permisos que el producto le da al
     * entrar y, encima, los que fije el caso: módulo, vista, acción y valor.
     *
     * @param list<array{0:string,1:string,2:string,3:int}> $fijados
     * @return array{ClasePermisos:object,Usuario:array{id:int,group_id:int}}
     */
    private function sesionDeUnUsuarioDelGrupo(int $grupo, array $fijados = []): array
    {
        require_once RUTA_TPVFOX . '/clases/ClasePermisos.php';
        $nombre = 'prueba_relaciones_' . $grupo . '_' . bin2hex(random_bytes(3));
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('$nombre', MD5('x'), CURDATE(), $grupo, 'activo', 'Prueba')");
        $usuario = ['id' => (int) $this->db->insert_id, 'group_id' => $grupo];
        $this->usuariosCreados[] = $usuario['id'];

        new \ClasePermisos($usuario, $this->db);
        foreach ($fijados as $permiso) {
            [$modulo, $vista, $accion, $valor] = $permiso;
            $this->db->query("DELETE FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = '$modulo' AND vista = '$vista' AND accion = '$accion'");
            $this->db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ({$usuario['id']}, '$modulo', '$vista', '$accion', $valor)");
        }

        return ['ClasePermisos' => new \ClasePermisos($usuario, $this->db), 'Usuario' => $usuario];
    }

    private function cambiarEstado(array $numRelaciones, string $estado, array $sesion): mixed
    {
        return DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, ['pulsado' => 'cambiarEstadoRelaciones', 'numRelaciones' => $numRelaciones, 'estado' => $estado], $sesion);
    }
}

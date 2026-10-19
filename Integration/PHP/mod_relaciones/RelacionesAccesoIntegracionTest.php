<?php
/**
 * Cambiar el estado de relaciones desde el listado, por `mod_relaciones/tareas.php`: hoy,
 * darlas de baja. La petición exige el permiso de cambiar el estado de la vista del listado, y
 * cada relación marcada se cambia por separado, con su aviso si no se puede.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModRelaciones;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\DespachoTareas;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

final class RelacionesAccesoIntegracionTest extends CasoIntegracion
{
    /** El módulo abre su propia transacción: estos casos limpian lo que escriben. */
    protected bool $aislarPorTransaccion = false;

    protected bool $compartirConexionConElProducto = true;

    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_relaciones/tareas.php';

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
     * @comportamiento El administrador marca dos relaciones y las da de baja. Dar de baja solo
     *   cambia la vigencia: cada relación sigue con su única versión, que pasa a estar de baja.
     */
    public function test_T1_elAdministradorDaDeBajaLasRelacionesMarcadasYSoloCambiaSuEstado(): void
    {
        $pollo = $this->despieceDelPollo();
        $conejo = $this->despieceDelConejo();
        $sesion = $this->sesionDeUnUsuarioDelGrupo(9);

        $respuesta = $this->cambiarEstado([$pollo, $conejo], 'Baja', $sesion);

        self::assertSame([$pollo, $conejo], $respuesta['datos']['cambiadas']);
        self::assertSame([], $respuesta['avisos'] ?? []);
        self::assertSame(['1.0 Baja'], $this->versiones($pollo));
        self::assertSame(['1.0 Baja'], $this->versiones($conejo));
    }

    /**
     * @comportamiento El permiso se comprueba antes que nada: sin él, la respuesta es el
     *   rechazo de permiso aunque el estado no se admita y los números vengan manipulados, y
     *   nada cambia, aunque la petición se haga directamente, sin pasar por el listado.
     */
    public function test_T2_sinElPermisoLaPeticionSeRechazaAunqueLoDemasVengaMal(): void
    {
        $pollo = $this->despieceDelPollo();
        $sesion = $this->sesionDeUnUsuarioDelGrupo(1);

        $bienFormada = $this->cambiarEstado([$pollo], 'Baja', $sesion);
        $malFormada = $this->cambiarEstado(['abc', "' OR '1'='1"], 'Sustituido', $sesion);

        foreach ([$bienFormada, $malFormada] as $respuesta) {
            self::assertStringContainsString('No tienes permiso', $respuesta['error']['mensaje']);
        }
        self::assertSame(['1.0 Activo'], $this->versiones($pollo));
    }

    /** @comportamiento Hoy solo se admite dar de baja: cualquier otro estado, o ninguno, se rechaza sin escribir. */
    public function test_T3_conElPermisoUnEstadoQueNoSeAdmiteSeRechazaSinEscribir(): void
    {
        $pollo = $this->despieceDelPollo();
        $sesion = $this->sesionDeUnUsuarioDelGrupo(9);

        foreach (['Activo', 'Sustituido', 'Borrador', ''] as $estado) {
            $respuesta = $this->cambiarEstado([$pollo], $estado, $sesion);
            self::assertArrayHasKey('error', $respuesta, 'estado «' . $estado . '»');
        }
        $sinEstado = $this->despachar(['pulsado' => 'cambiarEstadoRelaciones', 'numRelaciones' => [$pollo]], $sesion);

        self::assertArrayHasKey('error', $sinEstado);
        self::assertSame(['1.0 Activo'], $this->versiones($pollo));
    }

    public function test_T4_conElPermisoYSinNadaMarcadoSeAvisaYNoSeEscribe(): void
    {
        $pollo = $this->despieceDelPollo();
        $sesion = $this->sesionDeUnUsuarioDelGrupo(9);

        $sinLista = $this->despachar(['pulsado' => 'cambiarEstadoRelaciones', 'estado' => 'Baja'], $sesion);
        $listaVacia = $this->cambiarEstado([], 'Baja', $sesion);

        self::assertArrayHasKey('error', $sinLista);
        self::assertArrayHasKey('error', $listaVacia);
        self::assertSame(['1.0 Activo'], $this->versiones($pollo));
    }

    /**
     * @comportamiento El permiso se llama igual en otros listados: el de cambiar el estado de
     *   los albaranes de compras no sirve para cambiar el de las relaciones.
     */
    public function test_T5_elPermisoDeCambiarEstadoDeOtroModuloNoValeParaLasRelaciones(): void
    {
        $pollo = $this->despieceDelPollo();
        $sesion = $this->sesionDeUnUsuarioDelGrupo(1, [
            ['mod_compras', 'albaranesListado.php', 'CambiarEstado', 1],
            ['mod_relaciones', 'ListaRelaciones.php', 'ver', 1],
        ]);

        $respuesta = $this->cambiarEstado([$pollo], 'Baja', $sesion);

        self::assertStringContainsString('No tienes permiso', $respuesta['error']['mensaje']);
        self::assertSame(['1.0 Activo'], $this->versiones($pollo));
    }

    public function test_T6_quienPuedeVerElListadoPeroNoCambiarElEstadoEsRechazado(): void
    {
        $pollo = $this->despieceDelPollo();
        $sesion = $this->sesionDeUnUsuarioDelGrupo(1, [
            ['mod_relaciones', 'ListaRelaciones.php', 'ver', 1],
            ['mod_relaciones', 'ListaRelaciones.php', 'CambiarEstado', 0],
        ]);

        $respuesta = $this->cambiarEstado([$pollo], 'Baja', $sesion);

        self::assertStringContainsString('No tienes permiso', $respuesta['error']['mensaje']);
        self::assertSame(['1.0 Activo'], $this->versiones($pollo));
    }

    /**
     * @comportamiento Cada permiso decide solo lo suyo: el de cambiar el estado basta para la
     *   petición, aunque quien la hace no pueda abrir el listado.
     */
    public function test_T7_elPermisoDeCambiarEstadoBastaAunqueNoSePuedaVerElListado(): void
    {
        $pollo = $this->despieceDelPollo();
        $sesion = $this->sesionDeUnUsuarioDelGrupo(1, [
            ['mod_relaciones', 'ListaRelaciones.php', 'ver', 0],
            ['mod_relaciones', 'ListaRelaciones.php', 'CambiarEstado', 1],
        ]);

        $respuesta = $this->cambiarEstado([$pollo], 'Baja', $sesion);

        self::assertSame([$pollo], $respuesta['datos']['cambiadas']);
        self::assertSame(['1.0 Baja'], $this->versiones($pollo));
    }

    /**
     * @comportamiento El permiso de cambiar el estado se añade a una pantalla que ya existe, y
     *   a quien ya la tiene no le llega solo al entrar: lo crea «Reorganizar permisos», de
     *   Sistema, Reorganizar. Tras pasarlo, todos los usuarios lo tienen, concedido el
     *   administrador y denegado el resto.
     *
     * Corre en un proceso aparte: la tarea de reorganizar carga una clase de complemento que
     * se llama igual que otra del módulo de productos, y las dos no caben en el mismo proceso.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_T8_reorganizarPermisosDaCambiarEstadoAlAdministradorYSeLoNiegaAlResto(): void
    {
        $this->incluirTPVFox('/modulos/mod_reorganizacion/clases/ClaseReorganizar.php');
        $sesion = $this->sesionDeUnUsuarioDelGrupo(9);
        $this->db->query("DELETE FROM permisos WHERE modulo = 'mod_relaciones' AND accion = 'CambiarEstado'");
        $usuarios = $this->db->query('SELECT id, group_id FROM usuarios ORDER BY id')->fetch_all(MYSQLI_ASSOC);

        foreach (array_keys($usuarios) as $posicion) {
            DespachoTareas::invocar(RUTA_TPVFOX . '/modulos/mod_reorganizacion/tareas.php', $this->db, ['pulsado' => 'reorganizarPermisosModulos', 'inicial' => (string) $posicion, 'total' => (string) count($usuarios)], $sesion);
        }

        foreach ($usuarios as $usuario) {
            $filas = $this->db->query("SELECT permiso FROM permisos WHERE idUsuario = {$usuario['id']} AND modulo = 'mod_relaciones' AND vista = 'ListaRelaciones.php' AND accion = 'CambiarEstado'")->fetch_all(MYSQLI_ASSOC);

            self::assertSame([$usuario['group_id'] == 9 ? '1' : '0'], array_column($filas, 'permiso'), 'usuario ' . $usuario['id']);
        }
    }

    /**
     * @comportamiento Cada número se convierte a entero antes de usarlo: uno que no es un
     *   número, uno negativo o uno que intenta cerrar la sentencia no corresponde a ninguna
     *   relación y devuelve su aviso.
     */
    public function test_T9_numerosManipuladosDevuelvenSuAvisoYNoCambianNada(): void
    {
        $pollo = $this->despieceDelPollo();
        $sesion = $this->sesionDeUnUsuarioDelGrupo(9);

        $respuesta = $this->cambiarEstado(['abc', '-3', '0', "' OR '1'='1"], 'Baja', $sesion);

        self::assertSame([], $respuesta['datos']['cambiadas']);
        self::assertCount(4, $respuesta['avisos']);
        self::assertSame(['1.0 Activo'], $this->versiones($pollo));
    }

    public function test_T10_unNumeroRepetidoCambiaLaPrimeraVezYAvisaLaSegunda(): void
    {
        $pollo = $this->despieceDelPollo();
        $sesion = $this->sesionDeUnUsuarioDelGrupo(9);

        $respuesta = $this->cambiarEstado([$pollo, $pollo], 'Baja', $sesion);

        self::assertSame([$pollo], $respuesta['datos']['cambiadas']);
        self::assertCount(1, $respuesta['avisos']);
        self::assertSame(['1.0 Baja'], $this->versiones($pollo));
    }

    /**
     * @comportamiento Dar de baja no deja autor. Un usuario enviado en la petición no se usa:
     *   la versión sigue a nombre de quien la puso a regir.
     */
    public function test_T11_unUsuarioEnviadoEnLaPeticionNoCambiaElAutorDeLaVersion(): void
    {
        $pollo = $this->despieceDelPollo();
        $autor = $this->autor($pollo);
        $sesion = $this->sesionDeUnUsuarioDelGrupo(9);

        $this->despachar(['pulsado' => 'cambiarEstadoRelaciones', 'numRelaciones' => [$pollo], 'estado' => 'Baja', 'idUsuario' => (string) $sesion['Usuario']['id']], $sesion);

        self::assertSame(['1.0 Baja'], $this->versiones($pollo));
        self::assertSame($autor, $this->autor($pollo));
    }

    /**
     * @comportamiento Cada relación marcada se cambia por separado: la que ya está de baja, la
     *   que solo tiene un borrador y la que no existe devuelven su aviso, y no impiden la baja
     *   de la que rige.
     */
    public function test_T12_cadaRelacionMarcadaSeCambiaOAvisaSegunSuEstado(): void
    {
        $pollo = $this->despieceDelPollo();
        $conejo = $this->despieceDelConejo();
        $this->db->query("UPDATE relaciones SET estado = 'Baja' WHERE numRelacion = $conejo");
        $soloBorrador = $this->unaRelacionQueSoloTieneBorrador();
        $inexistente = $soloBorrador + 1000;
        $sesion = $this->sesionDeUnUsuarioDelGrupo(9);

        $respuesta = $this->cambiarEstado([$conejo, $soloBorrador, $inexistente, $pollo], 'Baja', $sesion);

        self::assertSame([$pollo], $respuesta['datos']['cambiadas']);
        self::assertCount(3, $respuesta['avisos']);
        self::assertSame(['1.0 Baja'], $this->versiones($pollo));
        self::assertSame(['1.0 Baja'], $this->versiones($conejo));
        self::assertSame(['0.0 Borrador'], $this->versiones($soloBorrador));
    }

    /**
     * @comportamiento Una relación con un borrador pendiente no se da de baja: la baja no
     *   descarta el trabajo que alguien está preparando. Se avisa con el motivo, y las demás
     *   marcadas se dan de baja igual.
     */
    public function test_T13_unaRelacionConBorradorPendienteSeRechazaConSuMotivoYLasDemasSeDanDeBaja(): void
    {
        $pollo = $this->despieceDelPollo();
        $conejo = $this->despieceDelConejo();
        $this->escenario->versionDeRelacion($pollo, $this->despiece, '0.0', 'Borrador', date('Y-m-d H:i:s'), $this->lineasDelPollo());
        $sesion = $this->sesionDeUnUsuarioDelGrupo(9);

        $respuesta = $this->cambiarEstado([$pollo, $conejo], 'Baja', $sesion);

        self::assertSame([$conejo], $respuesta['datos']['cambiadas']);
        self::assertCount(1, $respuesta['avisos']);
        self::assertStringContainsString('borrador pendiente', $respuesta['avisos'][0]['mensaje']);
        self::assertSame(['1.0 Activo', '0.0 Borrador'], $this->versiones($pollo));
        self::assertSame(['1.0 Baja'], $this->versiones($conejo));
    }

    /**
     * @comportamiento Cada relación se cambia en su propia transacción: si la base falla al
     *   cambiar una, esa se deshace y avisa, la anterior queda cambiada y la siguiente se
     *   intenta igual.
     *
     * Se llama a la función que atiende la petición con la clase del módulo que le pasaría
     * `tareas.php`, para hacer fallar la escritura de una sola relación.
     */
    public function test_T14_siLaBaseFallaAlCambiarUnaRelacionLasDemasSeCambianIgual(): void
    {
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/Relaciones.php');
        $this->incluirTPVFox('/modulos/mod_relaciones/funciones.php');
        $pollo = $this->despieceDelPollo();
        $troceado = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['nombre' => 'Pollo troceado']);
        $tercera = $this->despieceDelConejo();
        $sesion = $this->sesionDeUnUsuarioDelGrupo(9);

        $respuesta = cambiarEstadoRelaciones([$pollo, $troceado['numRelacion'], $tercera], 'Baja', $this->relacionesQueFallanAlCambiar($troceado['id']), $sesion['ClasePermisos'], $sesion['Usuario']);

        self::assertSame([$pollo, $tercera], $respuesta['datos']['cambiadas']);
        self::assertCount(1, $respuesta['avisos']);
        self::assertSame(['1.0 Baja'], $this->versiones($pollo));
        self::assertSame(['1.0 Activo'], $this->versiones($troceado['numRelacion']));
        self::assertSame(['1.0 Baja'], $this->versiones($tercera));
    }

    /**
     * @comportamiento Una relación con un cambio en preparación tampoco se da de baja: se avisa
     *   de ella, conserva su temporal, y las demás marcadas se dan de baja igual.
     */
    public function test_T15_unaRelacionConUnCambioEnPreparacionSeAvisaYLasDemasSeDanDeBaja(): void
    {
        $pollo = $this->despieceDelPollo();
        $conejo = $this->despieceDelConejo();
        $this->escenario->temporal($this->despiece, $this->lineasDelPollo(), ['numRelacion' => $pollo]);
        $sesion = $this->sesionDeUnUsuarioDelGrupo(9);

        $respuesta = $this->cambiarEstado([$pollo, $conejo], 'Baja', $sesion);

        self::assertSame([$conejo], $respuesta['datos']['cambiadas']);
        self::assertCount(1, $respuesta['avisos']);
        self::assertStringContainsString('Relación ' . $pollo, $respuesta['avisos'][0]['mensaje']);
        self::assertStringContainsString('cambio en preparación', $respuesta['avisos'][0]['mensaje']);
        self::assertSame(['1.0 Activo'], $this->versiones($pollo));
        self::assertSame(1, (int) $this->db->query("SELECT COUNT(*) AS n FROM relacionesTemporales WHERE numRelacion = $pollo")->fetch_assoc()['n']);
    }

    /** La clase del módulo, que hace fallar el cambio de estado de una versión concreta. */
    private function relacionesQueFallanAlCambiar(int $idVersion): object
    {
        $relaciones = new class extends \Relaciones {
            public int $idQueFalla = 0;

            protected function consultaPreparada($sql, $valores = array())
            {
                if (str_contains($sql, "SET estado = 'Baja'") && isset($valores[0]) && (int) $valores[0] === $this->idQueFalla) {
                    return ['error' => $this->montarAdvertencia('danger', 'Fallo provocado por la prueba'), 'consulta' => $sql];
                }

                return parent::consultaPreparada($sql, $valores);
            }
        };
        $relaciones->idQueFalla = $idVersion;

        return $relaciones;
    }

    /** El despiece del pollo, que ya rige. */
    private function despieceDelPollo(): int
    {
        return $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['nombre' => 'Pollo entero'])['numRelacion'];
    }

    /** El despiece del conejo, que ya rige. */
    private function despieceDelConejo(): int
    {
        return $this->escenario->relacionVigente($this->despiece, [
            ['idArticulo' => $this->articulo['conejo'], 'sentido' => 'entrada', 'cantidad' => 1.200],
            ['idArticulo' => $this->articulo['paletilla'], 'sentido' => 'salida', 'cantidad' => 0.300],
            ['idArticulo' => $this->articulo['lomo'], 'sentido' => 'salida', 'cantidad' => 0.450],
        ], ['nombre' => 'Conejo entero'])['numRelacion'];
    }

    private function lineasDelPollo(): array
    {
        return [
            ['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => 2.045],
            ['idArticulo' => $this->articulo['pechuga'], 'sentido' => 'salida', 'cantidad' => 0.630],
            ['idArticulo' => $this->articulo['alas'], 'sentido' => 'salida', 'cantidad' => 0.215],
        ];
    }

    /** Una relación nueva en preparación: solo tiene su borrador y todavía no ha regido. */
    private function unaRelacionQueSoloTieneBorrador(): int
    {
        $numRelacion = (int) $this->db->query('SELECT COALESCE(MAX(numRelacion), 0) + 1 AS n FROM relaciones')->fetch_assoc()['n'];
        $this->escenario->versionDeRelacion($numRelacion, $this->despiece, '0.0', 'Borrador', date('Y-m-d H:i:s'), $this->lineasDelPollo());

        return $numRelacion;
    }

    /**
     * La sesión de un usuario nuevo de ese grupo, con los permisos que el producto le da al
     * entrar y, encima, los que fije el caso: módulo, vista, acción y valor. El grupo 9 es el
     * de administradores.
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

    private function autor(int $numRelacion): int
    {
        return (int) $this->db->query("SELECT idUsuario FROM relaciones WHERE numRelacion = $numRelacion AND version = '1.0'")->fetch_assoc()['idUsuario'];
    }

    private function cambiarEstado(array $numRelaciones, string $estado, array $sesion): mixed
    {
        return $this->despachar(['pulsado' => 'cambiarEstadoRelaciones', 'numRelaciones' => $numRelaciones, 'estado' => $estado], $sesion);
    }

    private function despachar(array $post, array $variables = []): mixed
    {
        return DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, $post, $variables);
    }
}

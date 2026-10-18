<?php
/**
 * El borrador de una relación: se prepara sin que rija, convive con la versión que rige
 * sin tocarla, se edita y se descarta, y solo puede haber uno por relación.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModRelaciones;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

final class RelacionesBorradorIntegracionTest extends CasoIntegracion
{
    /** El módulo abre su propia transacción: estos casos limpian lo que escriben. */
    protected bool $aislarPorTransaccion = false;

    protected bool $compartirConexionConElProducto = true;

    private EscenarioRelaciones $escenario;
    private Siembra $siembra;
    private string|false $registroDeErrores = false;
    private int $usuario;
    private int $despiece;
    /** @var array<string,int> */
    private array $articulo = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/Relaciones.php');
        $this->registroDeErrores = ini_set('error_log', '/dev/null');

        $this->escenario = new EscenarioRelaciones($this->db);
        $this->siembra = new Siembra($this->db);
        $this->escenario->limpiar();

        $this->usuario = $this->escenario->usuario();
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        foreach (['pollo', 'pechuga', 'alas', 'muslos', 'jamoncito'] as $nombre) {
            $this->articulo[$nombre] = $this->siembra->articulo('Prueba de relaciones: ' . $nombre);
        }
    }

    protected function tearDown(): void
    {
        $this->escenario->limpiar(array_values($this->articulo));
        ini_set('error_log', (string) $this->registroDeErrores);
        parent::tearDown();
    }

    public function test_T1_unBorradorNuevoQuedaGuardadoSinRegirConSusLineas(): void
    {
        $respuesta = $this->relaciones()->crearBorrador($this->despiece, ['nombre' => 'Pollo entero'], $this->lineasDelPollo(), $this->usuario);

        $fila = $this->fila($respuesta['datos']['id']);
        self::assertSame('Borrador', $fila['estado']);
        self::assertSame('0.0', $fila['version']);
        self::assertNull($fila['tipoCambio']);
        self::assertSame('Pollo entero', $fila['nombre']);
        self::assertSame($this->usuario, (int) $fila['idUsuario']);
        self::assertSame(['Activo', 'Activo', 'Activo'], $this->estadosDeLasLineas($respuesta['datos']['id']));
    }

    public function test_T2_sinUsuarioIdentificadoNoSeGuardaNada(): void
    {
        $respuesta = $this->relaciones()->crearBorrador($this->despiece, [], $this->lineasDelPollo(), 0);

        self::assertStringContainsString('usuario', $respuesta['error']['mensaje']);
        self::assertSame(0, $this->cuantas('relaciones'));
    }

    public function test_T3_unProductoQueNoExisteSeRechazaYNoQuedaNada(): void
    {
        $lineas = [$this->linea('pollo', 'entrada'), ['idArticulo' => 99999999, 'sentido' => 'salida', 'cantidad' => 1, 'unidad' => 'kg']];

        $respuesta = $this->relaciones()->crearBorrador($this->despiece, [], $lineas, $this->usuario);

        self::assertStringContainsString('no existe', $respuesta['error']['mensaje']);
        self::assertSame(0, $this->cuantas('relaciones'));
    }

    public function test_T4_unProductoRepetidoSeRechaza(): void
    {
        $lineas = [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida'), $this->linea('pechuga', 'salida')];

        $respuesta = $this->relaciones()->crearBorrador($this->despiece, [], $lineas, $this->usuario);

        self::assertStringContainsString('repetido', $respuesta['error']['mensaje']);
        self::assertSame(0, $this->cuantas('relaciones'));
    }

    /**
     * @comportamiento El borrador de un cambio nace como copia de la versión que rige, y
     *   esta sigue rigiendo sin cambio mientras el borrador existe.
     */
    public function test_T5_elBorradorDeUnCambioCopiaLaVersionQueRigeYNoLaToca(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['nombre' => 'Pollo entero']);

        $respuesta = $this->relaciones()->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario);

        $borrador = $this->fila($respuesta['datos']['id']);
        self::assertSame('Borrador', $borrador['estado']);
        self::assertSame($vigente['numRelacion'], (int) $borrador['numRelacion']);
        self::assertSame('Pollo entero', $borrador['nombre']);
        self::assertSame($this->articulosDe($vigente['id']), $this->articulosDe($respuesta['datos']['id']));
        self::assertSame('Activo', $this->fila($vigente['id'])['estado']);
    }

    public function test_T6_unaRelacionNoAdmiteUnSegundoBorrador(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->relaciones()->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario);

        $respuesta = $this->relaciones()->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario);

        self::assertStringContainsString('ya tiene un borrador', $respuesta['error']['mensaje']);
        self::assertSame(2, $this->cuantas('relaciones'));
    }

    public function test_T7_noSePreparaUnCambioSobreUnaRelacionQueNoRige(): void
    {
        $respuesta = $this->relaciones()->crearBorradorDeCambio(424242, $this->usuario);

        self::assertStringContainsString('no está vigente', $respuesta['error']['mensaje']);
        self::assertSame(0, $this->cuantas('relaciones'));
    }

    public function test_T8_alEditarUnProductoAnadidoEsUnaLineaMas(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $id = $this->relaciones()->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario)['datos']['id'];
        $lineas = $this->lineasDelPollo();
        $lineas[] = $this->linea('muslos', 'salida');

        $respuesta = $this->relaciones()->editarBorrador($id, ['nombre' => 'Pollo con muslos'], $lineas);

        self::assertArrayHasKey('datos', $respuesta);
        self::assertSame('Pollo con muslos', $this->fila($id)['nombre']);
        self::assertSame(['Activo', 'Activo', 'Activo', 'Activo'], $this->estadosDeLasLineas($id));
    }

    /**
     * @comportamiento El producto que el cambio quita y que estaba en la versión que rige no
     *   desaparece del borrador: queda marcado como eliminado, para que conste qué se quitó.
     */
    public function test_T9_alEditarElProductoQuitadoQueEstabaEnLaQueRigeQuedaEliminado(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $id = $this->relaciones()->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario)['datos']['id'];
        $sinAlas = [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida')];

        $this->relaciones()->editarBorrador($id, [], $sinAlas);

        self::assertSame('Eliminado', $this->estadoDeLaLinea($id, 'alas'));
        self::assertSame('Activo', $this->estadoDeLaLinea($id, 'pechuga'));
    }

    public function test_T10_alEditarElProductoQuitadoQueNoEstabaEnLaQueRigeDesaparece(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $id = $this->relaciones()->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario)['datos']['id'];
        $conMuslos = $this->lineasDelPollo();
        $conMuslos[] = $this->linea('muslos', 'salida');
        $this->relaciones()->editarBorrador($id, [], $conMuslos);

        $this->relaciones()->editarBorrador($id, [], $this->lineasDelPollo());

        self::assertNull($this->estadoDeLaLinea($id, 'muslos'));
        self::assertSame(['Activo', 'Activo', 'Activo'], $this->estadosDeLasLineas($id));
    }

    /**
     * @comportamiento Lo que ya rige no se edita: la edición solo alcanza a un borrador, y
     *   pedirla sobre otra cosa se rechaza sin cambiar nada.
     */
    public function test_T11_editarUnaVersionQueRigeSeRechazaYNoLaCambia(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['nombre' => 'Original']);

        $respuesta = $this->relaciones()->editarBorrador($vigente['id'], ['nombre' => 'Cambiado'], [$this->linea('pollo', 'entrada')]);

        self::assertStringContainsString('borrador', $respuesta['error']['mensaje']);
        self::assertSame('Original', $this->fila($vigente['id'])['nombre']);
        self::assertSame(['Activo', 'Activo', 'Activo'], $this->estadosDeLasLineas($vigente['id']));
    }

    public function test_T12_descartarUnBorradorNoDejaNiSuFilaNiSusLineasYLaQueRigeSigueIgual(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $id = $this->relaciones()->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario)['datos']['id'];

        $respuesta = $this->relaciones()->descartarBorrador($id);

        self::assertArrayHasKey('datos', $respuesta);
        self::assertNull($this->fila($id));
        self::assertSame([], $this->estadosDeLasLineas($id));
        self::assertSame('Activo', $this->fila($vigente['id'])['estado']);
        self::assertSame(['Activo', 'Activo', 'Activo'], $this->estadosDeLasLineas($vigente['id']));
    }

    public function test_T13_descartarUnaVersionQueRigeSeRechazaYNoLaBorra(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());

        $respuesta = $this->relaciones()->descartarBorrador($vigente['id']);

        self::assertStringContainsString('borrador', $respuesta['error']['mensaje']);
        self::assertSame('Activo', $this->fila($vigente['id'])['estado']);
        self::assertSame(['Activo', 'Activo', 'Activo'], $this->estadosDeLasLineas($vigente['id']));
    }

    /**
     * @comportamiento A un borrador no se le exige la forma completa de su tipo: se puede
     *   guardar a medias y seguir otro día. La forma se comprueba al ponerlo a regir.
     */
    public function test_T14_unBorradorAMediasSePuedeGuardar(): void
    {
        $respuesta = $this->relaciones()->crearBorrador($this->despiece, [], [$this->linea('pollo', 'entrada')], $this->usuario);

        self::assertArrayHasKey('datos', $respuesta);
        self::assertSame(['Activo'], $this->estadosDeLasLineas($respuesta['datos']['id']));
    }

    /**
     * @comportamiento Que solo haya un borrador por relación lo garantiza la propia base,
     *   aunque el código que escribe se equivocase.
     */
    public function test_T15_laBaseNoAdmiteDosBorradoresDeLaMismaRelacion(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $alta = "INSERT INTO relaciones (numRelacion, idTipo, version, idUsuario, fechaCreacion) VALUES ({$vigente['numRelacion']}, {$this->despiece}, '0.0', {$this->usuario}, NOW())";
        $this->db->query($alta);

        $this->expectException(\mysqli_sql_exception::class);
        $this->db->query($alta);
    }

    public function test_T16_siFallaAlGuardarLasLineasNoQuedaLaCabeceraSola(): void
    {
        $relaciones = $this->relaciones();
        $relaciones->fallarEnLaConsulta = 7;

        $respuesta = $relaciones->crearBorrador($this->despiece, [], $this->lineasDelPollo(), $this->usuario);

        self::assertArrayHasKey('error', $respuesta);
        self::assertSame(0, $this->cuantas('relaciones'));
        self::assertSame(0, $this->cuantas('relacionesLineas'));
    }

    public function test_T17_unTipoQueNoExisteSeRechaza(): void
    {
        $respuesta = $this->relaciones()->crearBorrador(999999, [], $this->lineasDelPollo(), $this->usuario);

        self::assertStringContainsString('tipo', $respuesta['error']['mensaje']);
        self::assertSame(0, $this->cuantas('relaciones'));
    }

    /** La clase del módulo, con la posibilidad de hacer fallar la consulta que se le indique. */
    /**
     * @comportamiento Mientras se prepara el borrador de un cambio, la relación queda leída
     *   con bloqueo: una baja que llega a la vez espera, y si la espera se agota no cambia
     *   nada. Sin ese bloqueo, el borrador podría entrar después de la baja.
     */
    public function test_T18_mientrasSeCreaElBorradorDeUnCambioOtraConexionNoPuedeDarDeBajaLaRelacion(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $otra = $this->otraConexion();
        $baja = "UPDATE relaciones SET estado = 'Baja' WHERE numRelacion = {$vigente['numRelacion']} AND estado = 'Activo'";
        $relaciones = $this->relacionesQueAlLeerConBloqueo($otra, $baja);

        $respuesta = $relaciones->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario);
        $otra->close();

        self::assertSame(1205, $relaciones->errorDeLaOtraConexion);
        self::assertArrayHasKey('datos', $respuesta);
        self::assertSame('Activo', $this->fila($vigente['id'])['estado']);
        self::assertSame('Borrador', $this->fila($respuesta['datos']['id'])['estado']);
    }

    private function relaciones(): object
    {
        return new class extends \Relaciones {
            public int $fallarEnLaConsulta = 0;
            private int $consultas = 0;

            protected function consultaPreparada($sql, $valores = array())
            {
                if (++$this->consultas === $this->fallarEnLaConsulta) {
                    return ['error' => $this->montarAdvertencia('danger', 'Fallo provocado por la prueba'), 'consulta' => $sql];
                }

                return parent::consultaPreparada($sql, $valores);
            }
        };
    }

    /** Una segunda conexión a la misma base, que espera un segundo como mucho a un bloqueo. */
    private function otraConexion(): \mysqli
    {
        $otra = self::conectar($this->ejercicio);
        $otra->query('SET SESSION innodb_lock_wait_timeout = 1');

        return $otra;
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

    private function linea(string $nombre, string $sentido): array
    {
        return ['idArticulo' => $this->articulo[$nombre], 'sentido' => $sentido, 'cantidad' => 1, 'unidad' => 'kg'];
    }

    private function lineasDelPollo(): array
    {
        return [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida'), $this->linea('alas', 'salida')];
    }

    private function fila(int $id): ?array
    {
        return $this->db->query("SELECT * FROM relaciones WHERE id = $id")->fetch_assoc();
    }

    /** @return list<string> */
    private function estadosDeLasLineas(int $idRelacion): array
    {
        $r = $this->db->query("SELECT estado FROM relacionesLineas WHERE idRelacion = $idRelacion ORDER BY id");

        return array_column($r->fetch_all(MYSQLI_ASSOC), 'estado');
    }

    private function estadoDeLaLinea(int $idRelacion, string $nombre): ?string
    {
        $fila = $this->db->query("SELECT estado FROM relacionesLineas WHERE idRelacion = $idRelacion AND idArticulo = {$this->articulo[$nombre]}")->fetch_assoc();

        return $fila['estado'] ?? null;
    }

    /** @return list<int> */
    private function articulosDe(int $idRelacion): array
    {
        $r = $this->db->query("SELECT idArticulo FROM relacionesLineas WHERE idRelacion = $idRelacion AND estado = 'Activo' ORDER BY idArticulo");

        return array_map('intval', array_column($r->fetch_all(MYSQLI_ASSOC), 'idArticulo'));
    }

    private function cuantas(string $tabla): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM $tabla")->fetch_assoc()['n'];
    }
}

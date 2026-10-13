<?php
/**
 * Poner a regir un borrador, y lo que eso deja escrito: la versión con su número, la clase
 * de cambio que el sistema dedujo, quién la activó y cuándo, y la versión anterior
 * conservada tal como era.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModRelaciones;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

final class RelacionesHistorialIntegracionTest extends CasoIntegracion
{
    /** El módulo abre su propia transacción: estos casos limpian lo que escriben. */
    protected bool $aislarPorTransaccion = false;

    protected bool $compartirConexionConElProducto = true;

    private EscenarioRelaciones $escenario;
    private Siembra $siembra;
    private string|false $registroDeErrores = false;
    private int $usuario;
    private int $otroUsuario;
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
        $this->db->query("DELETE FROM usuarios WHERE username = 'quien_activa'");

        $this->usuario = $this->escenario->usuario();
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('quien_activa', MD5('x'), CURDATE(), 9, 'activo', 'Quien activa')");
        $this->otroUsuario = (int) $this->db->insert_id;
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        foreach (['pollo', 'pechuga', 'alas', 'muslos', 'jamoncito'] as $nombre) {
            $this->articulo[$nombre] = $this->siembra->articulo('Prueba de relaciones: ' . $nombre);
        }
    }

    protected function tearDown(): void
    {
        $this->escenario->limpiar(array_values($this->articulo));
        $this->db->query("DELETE FROM usuarios WHERE username = 'quien_activa'");
        ini_set('error_log', (string) $this->registroDeErrores);
        parent::tearDown();
    }

    /**
     * @comportamiento La versión lleva quién la puso a regir y desde cuándo, no quién empezó
     *   a prepararla ni qué día: de otro modo «qué regía en una fecha» respondería con una
     *   fórmula que ese día todavía no regía.
     */
    public function test_T1_laPrimeraActivacionDejaLaVersionUnoConElUsuarioYLaFechaDeLaActivacion(): void
    {
        $id = $this->relaciones()->crearBorrador($this->despiece, [], $this->lineasDelPollo(), $this->usuario)['datos']['id'];
        $this->db->query("UPDATE relaciones SET fechaCreacion = '2020-01-01 10:00:00' WHERE id = $id");

        $respuesta = $this->relaciones()->activarBorrador($id, $this->otroUsuario);

        $fila = $this->fila($id);
        self::assertSame('1.0', $respuesta['datos']['version']);
        self::assertSame('Activo', $fila['estado']);
        self::assertSame('1.0', $fila['version']);
        self::assertSame('alta', $fila['tipoCambio']);
        self::assertSame($this->otroUsuario, (int) $fila['idUsuario']);
        self::assertGreaterThan('2026-01-01', $fila['fechaCreacion']);
    }

    public function test_T2_unAjusteDeCantidadesSubeLaVersionMenorGuardaSuOrigenYSustituyeALaAnterior(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $id = $this->borradorConCantidad($vigente['numRelacion'], 'pechuga', 0.25);

        $respuesta = $this->relaciones()->activarBorrador($id, $this->usuario, 'Pesada del 28 de octubre');

        self::assertSame('1.1', $respuesta['datos']['version']);
        self::assertSame('proporciones', $this->fila($id)['tipoCambio']);
        self::assertSame('Pesada del 28 de octubre', $this->fila($id)['origenAjuste']);
        self::assertSame('Activo', $this->fila($id)['estado']);
        self::assertSame('Sustituido', $this->fila($vigente['id'])['estado']);
    }

    public function test_T3_unAjusteDeCantidadesSinDecirDeDondeSaleSeRechazaYElBorradorSeConserva(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $id = $this->borradorConCantidad($vigente['numRelacion'], 'pechuga', 0.25);

        $respuesta = $this->relaciones()->activarBorrador($id, $this->usuario);

        self::assertStringContainsString('origen', $respuesta['error']['mensaje']);
        self::assertSame('Borrador', $this->fila($id)['estado']);
        self::assertSame('Activo', $this->fila($vigente['id'])['estado']);
    }

    public function test_T4_unCambioDePiezasSubeLaVersionMayorYConservaComoEliminadaLaQueSeQuita(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $id = $this->relaciones()->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario)['datos']['id'];
        $sinAlasConMuslos = [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida'), $this->linea('muslos', 'salida')];
        $this->relaciones()->editarBorrador($id, [], $sinAlasConMuslos);

        $respuesta = $this->relaciones()->activarBorrador($id, $this->usuario);

        self::assertSame('2.0', $respuesta['datos']['version']);
        self::assertSame('composicion', $this->fila($id)['tipoCambio']);
        self::assertSame('Eliminado', $this->estadoDeLaLinea($id, 'alas'));
        self::assertSame('Sustituido', $this->fila($vigente['id'])['estado']);
    }

    public function test_T5_unCambioDeNombreSubeLaVersionMenorYNoPideOrigen(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['nombre' => 'Pollo']);
        $id = $this->relaciones()->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario)['datos']['id'];
        $this->relaciones()->editarBorrador($id, ['nombre' => 'Pollo de corral'], $this->lineasDelPollo());

        $respuesta = $this->relaciones()->activarBorrador($id, $this->usuario);

        self::assertSame('1.1', $respuesta['datos']['version']);
        self::assertSame('datos', $this->fila($id)['tipoCambio']);
    }

    public function test_T6_unBorradorIgualALaVersionQueRigeNoSeActivaYSeConserva(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $id = $this->relaciones()->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario)['datos']['id'];

        $respuesta = $this->relaciones()->activarBorrador($id, $this->usuario);

        self::assertStringContainsString('no cambia nada', $respuesta['error']['mensaje']);
        self::assertSame('Borrador', $this->fila($id)['estado']);
        self::assertSame('Activo', $this->fila($vigente['id'])['estado']);
    }

    /**
     * @comportamiento Activar es lo que somete el borrador a la forma de su tipo. Si no la
     *   cumple no empieza a regir, pero tampoco se pierde: sigue como borrador.
     */
    public function test_T7_unBorradorQueNoCumpleLaFormaDeSuTipoNoSeActivaYSigueSiendoBorrador(): void
    {
        $id = $this->relaciones()->crearBorrador($this->despiece, [], [$this->linea('pollo', 'entrada')], $this->usuario)['datos']['id'];

        $respuesta = $this->relaciones()->activarBorrador($id, $this->usuario);

        self::assertStringContainsString('al menos una salida', $respuesta['error']['mensaje']);
        self::assertSame('Borrador', $this->fila($id)['estado']);
    }

    public function test_T8_unaRelacionNuevaNoPuedeNacerSobreUnArticuloDeBaja(): void
    {
        $this->darDeBaja('alas');
        $id = $this->relaciones()->crearBorrador($this->despiece, [], $this->lineasDelPollo(), $this->usuario)['datos']['id'];

        $respuesta = $this->relaciones()->activarBorrador($id, $this->usuario);

        self::assertStringContainsString('de baja', $respuesta['error']['mensaje']);
        self::assertSame('Borrador', $this->fila($id)['estado']);
    }

    /**
     * @comportamiento Que un producto se dé de baja después no bloquea la relación que ya lo
     *   tenía: sus cantidades se pueden seguir ajustando.
     */
    public function test_T9_unaRelacionCuyoProductoSeDioDeBajaDespuesSePuedeSeguirAjustando(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->darDeBaja('alas');
        $id = $this->borradorConCantidad($vigente['numRelacion'], 'pechuga', 0.25);

        $respuesta = $this->relaciones()->activarBorrador($id, $this->usuario, 'Pesada');

        self::assertSame('1.1', $respuesta['datos']['version']);
    }

    public function test_T10_unCambioNoPuedeIncorporarUnArticuloDeBaja(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->darDeBaja('muslos');
        $id = $this->relaciones()->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario)['datos']['id'];
        $conMuslos = $this->lineasDelPollo();
        $conMuslos[] = $this->linea('muslos', 'salida');
        $this->relaciones()->editarBorrador($id, [], $conMuslos);

        $respuesta = $this->relaciones()->activarBorrador($id, $this->usuario);

        self::assertStringContainsString('de baja', $respuesta['error']['mensaje']);
        self::assertSame('Activo', $this->fila($vigente['id'])['estado']);
    }

    public function test_T11_unaRelacionNuevaIgualAOtraQueYaRigeSeRechaza(): void
    {
        $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $id = $this->relaciones()->crearBorrador($this->despiece, [], $this->lineasDelPollo(), $this->usuario)['datos']['id'];

        $respuesta = $this->relaciones()->activarBorrador($id, $this->usuario);

        self::assertStringContainsString('Ya existe', $respuesta['error']['mensaje']);
        self::assertSame('Borrador', $this->fila($id)['estado']);
    }

    /**
     * @comportamiento Los mismos productos con otro tipo son otra relación: un deshuesado y un
     *   despiece del mismo pollo pueden regir a la vez.
     */
    public function test_T12_losMismosProductosConOtroTipoSeAdmiten(): void
    {
        $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $deshuesado = $this->escenario->tipoDeRelacion('Deshuesado', '1:N');
        $id = $this->relaciones()->crearBorrador($deshuesado, [], $this->lineasDelPollo(), $this->usuario)['datos']['id'];

        $respuesta = $this->relaciones()->activarBorrador($id, $this->usuario);

        self::assertSame('1.0', $respuesta['datos']['version']);
    }

    public function test_T13_unCambioDePiezasQueDejaLaRelacionIgualAOtraQueRigeSeRechaza(): void
    {
        $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $otra = $this->escenario->relacionVigente($this->despiece, [$this->linea('pollo', 'entrada'), $this->linea('pechuga', 'salida')]);
        $id = $this->relaciones()->crearBorradorDeCambio($otra['numRelacion'], $this->usuario)['datos']['id'];
        $this->relaciones()->editarBorrador($id, [], $this->lineasDelPollo());

        $respuesta = $this->relaciones()->activarBorrador($id, $this->usuario);

        self::assertStringContainsString('Ya existe', $respuesta['error']['mensaje']);
        self::assertSame('Activo', $this->fila($otra['id'])['estado']);
    }

    /**
     * @comportamiento La versión se guarda como texto, y la décima revisión es la 1.10, que va
     *   después de la 1.9 aunque ordenada como texto iría antes.
     */
    public function test_T14_diezAjustesSeguidosLleganALaVersionUnoPuntoDiez(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());

        $version = '';
        for ($i = 1; $i <= 10; $i++) {
            $id = $this->borradorConCantidad($vigente['numRelacion'], 'pechuga', 1 + $i / 1000);
            $version = $this->relaciones()->activarBorrador($id, $this->usuario, 'Pesada ' . $i)['datos']['version'];
        }

        self::assertSame('1.10', $version);
    }

    public function test_T15_siFallaTrasSustituirLaVersionQueRegiaEstaVuelveARegir(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $id = $this->borradorConCantidad($vigente['numRelacion'], 'pechuga', 0.25);
        $relaciones = $this->relaciones();
        $relaciones->fallarAlEscribirElBorrador = true;

        $respuesta = $relaciones->activarBorrador($id, $this->usuario, 'Pesada');

        self::assertArrayHasKey('error', $respuesta);
        self::assertSame('Activo', $this->fila($vigente['id'])['estado']);
        self::assertSame('Borrador', $this->fila($id)['estado']);
    }

    public function test_T16_loQueYaRigeNoSePuedeVolverAActivar(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());

        $respuesta = $this->relaciones()->activarBorrador($vigente['id'], $this->usuario);

        self::assertStringContainsString('borrador', $respuesta['error']['mensaje']);
        self::assertSame('1.0', $this->fila($vigente['id'])['version']);
    }

    public function test_T17_sinUsuarioIdentificadoNoSeActivaNada(): void
    {
        $id = $this->relaciones()->crearBorrador($this->despiece, [], $this->lineasDelPollo(), $this->usuario)['datos']['id'];

        $respuesta = $this->relaciones()->activarBorrador($id, 0);

        self::assertStringContainsString('usuario', $respuesta['error']['mensaje']);
        self::assertSame('Borrador', $this->fila($id)['estado']);
    }

    public function test_T18_trasVariasActivacionesNuncaHayDosVersionesRigiendoALaVez(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        for ($i = 1; $i <= 3; $i++) {
            $id = $this->borradorConCantidad($vigente['numRelacion'], 'pechuga', 1 + $i / 1000);
            $this->relaciones()->activarBorrador($id, $this->usuario, 'Pesada ' . $i);
        }

        $activas = (int) $this->db->query("SELECT COUNT(*) AS n FROM relaciones WHERE numRelacion = {$vigente['numRelacion']} AND estado = 'Activo'")->fetch_assoc()['n'];
        $sustituidas = (int) $this->db->query("SELECT COUNT(*) AS n FROM relaciones WHERE numRelacion = {$vigente['numRelacion']} AND estado = 'Sustituido'")->fetch_assoc()['n'];

        self::assertSame(1, $activas);
        self::assertSame(3, $sustituidas);
    }

    /**
     * @comportamiento De la versión que deja de regir solo cambia la marca de que ya no rige:
     *   su número, su autor, su fecha y sus líneas quedan como estaban.
     */
    public function test_T19_deLaVersionSustituidaSoloCambiaElEstado(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo(), ['fecha' => '2026-03-15 09:00:00']);
        $antes = $this->fila($vigente['id']);
        $lineasAntes = $this->lineas($vigente['id']);
        $id = $this->borradorConCantidad($vigente['numRelacion'], 'pechuga', 0.25);

        $this->relaciones()->activarBorrador($id, $this->otroUsuario, 'Pesada');

        $despues = $this->fila($vigente['id']);
        self::assertSame('Sustituido', $despues['estado']);
        unset($antes['estado'], $despues['estado']);
        self::assertSame($antes, $despues);
        self::assertSame($lineasAntes, $this->lineas($vigente['id']));
    }

    /**
     * @comportamiento Dar de baja una relación no borra nada: añade una versión que la cierra,
     *   sin líneas, y todo lo que fue queda en su sitio.
     */
    public function test_T20_laBajaAnadeUnaVersionQueCierraLaRelacionYConservaLasAnteriores(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $lineasAntes = $this->lineas($vigente['id']);

        $respuesta = $this->relaciones()->darDeBaja($vigente['numRelacion'], $this->otroUsuario);

        $baja = $this->fila($respuesta['datos']['id']);
        self::assertSame('1.1', $baja['version']);
        self::assertSame('baja', $baja['tipoCambio']);
        self::assertSame('Baja', $baja['estado']);
        self::assertSame($this->otroUsuario, (int) $baja['idUsuario']);
        self::assertSame([], $this->lineas($respuesta['datos']['id']));
        self::assertSame('Sustituido', $this->fila($vigente['id'])['estado']);
        self::assertSame($lineasAntes, $this->lineas($vigente['id']));
    }

    public function test_T21_laBajaDescartaElBorradorQueLaRelacionTuvieraPendiente(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $idBorrador = $this->relaciones()->crearBorradorDeCambio($vigente['numRelacion'], $this->usuario)['datos']['id'];

        $respuesta = $this->relaciones()->darDeBaja($vigente['numRelacion'], $this->usuario);

        self::assertArrayHasKey('datos', $respuesta);
        self::assertNull($this->fila($idBorrador));
        self::assertSame([], $this->lineas($idBorrador));
    }

    /**
     * @comportamiento Una relación dada de baja no vuelve a tener versiones: ni se modifica ni
     *   se da de baja otra vez. Para recuperarla se da de alta de nuevo.
     */
    public function test_T22_unaRelacionDadaDeBajaNoSeModificaNiSeDaDeBajaOtraVez(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->relaciones()->darDeBaja($vigente['numRelacion'], $this->usuario);

        $otraBaja = $this->relaciones()->darDeBaja($vigente['numRelacion'], $this->usuario);
        $modificacion = $this->relaciones()->modificar($vigente['numRelacion'], ['nombre' => 'Otro'], $this->lineasDelPollo(), $this->usuario);

        self::assertStringContainsString('no está vigente', $otraBaja['error']['mensaje']);
        self::assertStringContainsString('no está vigente', $modificacion['error']['mensaje']);
        self::assertSame(2, (int) $this->db->query("SELECT COUNT(*) AS n FROM relaciones WHERE numRelacion = {$vigente['numRelacion']}")->fetch_assoc()['n']);
    }

    public function test_T23_trasLaBajaLaMismaRelacionSePuedeDarDeAltaDeNuevoConOtroNumero(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $this->relaciones()->darDeBaja($vigente['numRelacion'], $this->usuario);

        $respuesta = $this->relaciones()->alta($this->despiece, [], $this->lineasDelPollo(), $this->usuario);

        self::assertSame('1.0', $respuesta['datos']['version']);
        self::assertNotSame($vigente['numRelacion'], $respuesta['datos']['numRelacion']);
    }

    public function test_T24_siLaBajaFallaAMitadLaRelacionSigueRigiendo(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $relaciones = $this->relaciones();
        $relaciones->fallarAlInsertarLaBaja = true;

        $respuesta = $relaciones->darDeBaja($vigente['numRelacion'], $this->usuario);

        self::assertArrayHasKey('error', $respuesta);
        self::assertSame('Activo', $this->fila($vigente['id'])['estado']);
        self::assertSame(1, (int) $this->db->query("SELECT COUNT(*) AS n FROM relaciones WHERE numRelacion = {$vigente['numRelacion']}")->fetch_assoc()['n']);
    }

    public function test_T25_sinUsuarioIdentificadoNoSeDaDeBajaNada(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());

        $respuesta = $this->relaciones()->darDeBaja($vigente['numRelacion'], 0);

        self::assertStringContainsString('usuario', $respuesta['error']['mensaje']);
        self::assertSame('Activo', $this->fila($vigente['id'])['estado']);
    }

    /** La clase del módulo, con la posibilidad de hacer fallar la escritura que activa el borrador. */
    /**
     * @comportamiento Si mientras se activa un cambio otra escritura ya sustituyó la versión
     *   que regía, esta no sigue adelante: se deshace entera y lo dice. Sin esa comprobación
     *   quedarían dos versiones rigiendo a la vez.
     */
    public function test_T26_siOtraEscrituraSeAdelantaLaActivacionSeDeshaceYLoDice(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $id = $this->borradorConCantidad($vigente['numRelacion'], 'pechuga', 1.5);
        $relaciones = $this->relaciones();
        $relaciones->otraEscrituraSeAdelanta = true;

        $respuesta = $relaciones->activarBorrador($id, $this->usuario, 'Pesada');

        self::assertStringContainsString('ya no es la vigente', $respuesta['error']['mensaje']);
        self::assertSame('Borrador', $this->fila($id)['estado']);
        self::assertSame('Activo', $this->fila($vigente['id'])['estado']);
    }

    public function test_T27_siOtraEscrituraSeAdelantaLaBajaSeDeshaceYLoDice(): void
    {
        $vigente = $this->escenario->relacionVigente($this->despiece, $this->lineasDelPollo());
        $relaciones = $this->relaciones();
        $relaciones->otraEscrituraSeAdelanta = true;

        $respuesta = $relaciones->darDeBaja($vigente['numRelacion'], $this->usuario);

        self::assertStringContainsString('ya no es la vigente', $respuesta['error']['mensaje']);
        self::assertSame('Activo', $this->fila($vigente['id'])['estado']);
        self::assertSame(1, (int) $this->db->query("SELECT COUNT(*) AS n FROM relaciones WHERE numRelacion = {$vigente['numRelacion']}")->fetch_assoc()['n']);
    }

    private function relaciones(): object
    {
        return new class extends \Relaciones {
            public bool $fallarAlEscribirElBorrador = false;
            public bool $fallarAlInsertarLaBaja = false;
            /** Otra escritura sustituye la versión que rige justo antes de que lo haga esta. */
            public bool $otraEscrituraSeAdelanta = false;

            protected function consultaPreparada($sql, $valores = array())
            {
                if ($this->fallarAlEscribirElBorrador && str_contains($sql, "estado = 'Activo' WHERE id = ? AND estado = 'Borrador'")) {
                    return ['error' => $this->montarAdvertencia('danger', 'Fallo provocado por la prueba'), 'consulta' => $sql];
                }

                if ($this->otraEscrituraSeAdelanta && str_contains($sql, "SET estado = 'Sustituido'")) {
                    parent::consultaPreparada("UPDATE relaciones SET estado = 'Sustituido' WHERE id = ?", $valores);
                }

                if ($this->fallarAlInsertarLaBaja && str_contains($sql, "'baja'")) {
                    return ['error' => $this->montarAdvertencia('danger', 'Fallo provocado por la prueba'), 'consulta' => $sql];
                }

                return parent::consultaPreparada($sql, $valores);
            }
        };
    }

    /** Un borrador de cambio que solo altera la cantidad de un producto. */
    private function borradorConCantidad(int $numRelacion, string $nombre, float $cantidad): int
    {
        $id = $this->relaciones()->crearBorradorDeCambio($numRelacion, $this->usuario)['datos']['id'];
        $lineas = $this->lineasDelPollo();
        foreach ($lineas as $i => $linea) {
            if ($linea['idArticulo'] === $this->articulo[$nombre]) {
                $lineas[$i]['cantidad'] = $cantidad;
            }
        }
        $this->relaciones()->editarBorrador($id, [], $lineas);

        return $id;
    }

    private function darDeBaja(string $nombre): void
    {
        $this->db->query("UPDATE articulos SET estado = 'Baja' WHERE idArticulo = {$this->articulo[$nombre]}");
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

    private function lineas(int $idRelacion): array
    {
        return $this->db->query("SELECT * FROM relacionesLineas WHERE idRelacion = $idRelacion ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    }

    private function estadoDeLaLinea(int $idRelacion, string $nombre): ?string
    {
        $fila = $this->db->query("SELECT estado FROM relacionesLineas WHERE idRelacion = $idRelacion AND idArticulo = {$this->articulo[$nombre]}")->fetch_assoc();

        return $fila['estado'] ?? null;
    }
}

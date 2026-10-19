<?php
/**
 * Lo que se prepara en la pantalla de relación y todavía no rige: la tabla de temporales.
 * Guardar escribe solo en ella; cancelar la borra; activar pone a regir lo preparado por el
 * camino de siempre —borrador, admisión e historial— y borra el temporal en la misma
 * escritura. Hay como mucho un temporal por relación.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModRelaciones;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

final class RelacionesTemporalesIntegracionTest extends CasoIntegracion
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
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/RelacionesTemporales.php');
        $this->registroDeErrores = ini_set('error_log', '/dev/null');

        $this->escenario = new EscenarioRelaciones($this->db);
        $this->siembra = new Siembra($this->db);
        $this->escenario->limpiar();
        $this->usuario = $this->escenario->usuario();
        $this->despiece = $this->escenario->tipoDeRelacion('Despiece', '1:N');
        foreach (['pollo', 'pechuga', 'alas', 'muslo', 'conejo', 'paletilla'] as $nombre) {
            $this->articulo[$nombre] = $this->siembra->articulo('Prueba de temporales: ' . $nombre);
        }
    }

    protected function tearDown(): void
    {
        $this->escenario->limpiar(array_values($this->articulo));
        ini_set('error_log', (string) $this->registroDeErrores);
        parent::tearDown();
    }

    /**
     * @comportamiento Lo guardado vuelve igual al retomarlo, también las cantidades extremas: la
     *   más pequeña que da una balanza, la mayor que cabe en la columna, una entera y ninguna.
     */
    public function test_T1_unaRelacionNuevaGuardadaVuelveIgualAlLeerla(): void
    {
        $lineas = [
            $this->linea('pollo', 'entrada', '0.001'),
            $this->linea('pechuga', 'salida', '99999999999.999'),
            $this->linea('alas', 'salida', '1', 'ud'),
            $this->linea('muslo', 'salida', null, null),
        ];

        $guardado = $this->temporales()->guardar($this->nuevo($lineas, ['nombre' => 'Pollo de corral']), $this->usuario);
        $leido = $this->temporales()->leer($guardado['datos']['id']);

        self::assertSame($lineas, $leido['datos']['lineas']);
        self::assertSame('Pollo de corral', $leido['datos']['nombre']);
        self::assertSame(0, $this->cuantas('relaciones'));
    }

    /** @comportamiento Guardar no toca lo que rige: solo escribe en la tabla de temporales. */
    public function test_T2_guardarSoloEscribeEnLaTablaDeTemporales(): void
    {
        $vigente = $this->despieceDelPollo();
        $temporales = $this->temporalesQueAnotan();

        $primero = $temporales->guardar($this->cambio($vigente['numRelacion'], $this->lineasDelPolloCon('0.25')), $this->usuario);
        $temporales->guardar($this->cambio($vigente['numRelacion'], $this->lineasDelPolloCon('0.26'), $primero['datos']), $this->usuario);

        $escrituras = array_filter($temporales->sentencias, static fn(string $sql) => !str_starts_with(ltrim($sql), 'SELECT'));
        self::assertNotEmpty($escrituras);
        foreach ($escrituras as $sql) {
            self::assertMatchesRegularExpression('/^(INSERT INTO|UPDATE) relacionesTemporales /', ltrim($sql));
        }
        self::assertSame(['1.0 Activo'], $this->versiones($vigente['numRelacion']));
    }

    public function test_T3_elPrimerGuardadoDeUnCambioRecuerdaLaRelacionYLaVersionDeLaQueParte(): void
    {
        $vigente = $this->despieceDelPollo();
        $otro = $this->otroUsuario();

        $guardado = $this->temporales()->guardar($this->cambio($vigente['numRelacion'], $this->lineasDelPolloCon('0.25')), $otro);

        $fila = $this->db->query("SELECT * FROM relacionesTemporales WHERE id = {$guardado['datos']['id']}")->fetch_assoc();
        self::assertSame($vigente['numRelacion'], (int) $fila['numRelacion']);
        self::assertSame($vigente['id'], (int) $fila['idRelacion']);
        self::assertSame($this->despiece, (int) $fila['idTipo']);
        self::assertSame($otro, (int) $fila['idUsuario']);
        self::assertSame($fila['fechaInicio'], $fila['fechaModificacion']);
        self::assertSame($fila['fechaModificacion'], $guardado['datos']['fechaModificacion']);
    }

    public function test_T4_unSegundoTemporalDeLaMismaRelacionAvisaConQuienYCuando(): void
    {
        $vigente = $this->despieceDelPollo();
        $this->escenario->temporal($this->despiece, $this->lineasDelPolloCon('0.25'), ['numRelacion' => $vigente['numRelacion'], 'idRelacion' => $vigente['id'], 'fecha' => '2026-10-01 10:00:00']);

        $respuesta = $this->temporales()->guardar($this->cambio($vigente['numRelacion'], $this->lineasDelPolloCon('0.30')), $this->usuario);

        self::assertStringContainsString('ya tiene un cambio en preparación', $respuesta['error']['mensaje']);
        self::assertStringContainsString('2026-10-01 10:00', $respuesta['error']['mensaje']);
        self::assertSame(1, $this->cuantas('relacionesTemporales'));
    }

    public function test_T5_variasRelacionesNuevasPuedenPrepararseALaVez(): void
    {
        $uno = $this->temporales()->guardar($this->nuevo($this->lineasDelPolloCon('0.23')), $this->usuario);
        $dos = $this->temporales()->guardar($this->nuevo([$this->linea('conejo', 'entrada', '1'), $this->linea('paletilla', 'salida', '0.3')]), $this->usuario);

        self::assertArrayHasKey('datos', $uno);
        self::assertArrayHasKey('datos', $dos);
        self::assertSame(2, (int) $this->db->query('SELECT COUNT(*) AS n FROM relacionesTemporales WHERE numRelacion IS NULL')->fetch_assoc()['n']);
    }

    /**
     * @comportamiento Si alguien guardó el temporal después de que la pantalla lo leyera, guardar
     *   avisa y no escribe: el último en guardar no pisa sin saberlo lo que guardó el otro.
     */
    public function test_T6_guardarSobreUnTemporalQueOtroGuardoDespuesAvisaYNoEscribe(): void
    {
        $id = $this->escenario->temporal($this->despiece, $this->lineasDelPolloCon('0.25'), ['fecha' => '2026-10-01 10:00:00']);

        $conLaFechaVieja = $this->temporales()->guardar($this->nuevo($this->lineasDelPolloCon('0.40'), ['id' => $id, 'fechaModificacion' => '2026-10-01 09:00:00']), $this->usuario);
        $leido = $this->temporales()->leer($id);
        $conLaFechaQueLeyo = $this->temporales()->guardar($this->nuevo($this->lineasDelPolloCon('0.40'), ['id' => $id, 'fechaModificacion' => '2026-10-01 10:00:00']), $this->usuario);

        self::assertStringContainsString('Otra persona guardó', $conLaFechaVieja['error']['mensaje']);
        self::assertSame('0.25', $leido['datos']['lineas'][1]['cantidad']);
        self::assertArrayHasKey('datos', $conLaFechaQueLeyo);
        self::assertSame('0.40', $this->temporales()->leer($id)['datos']['lineas'][1]['cantidad']);
    }

    public function test_T7_elPrimerGuardadoDeUnCambioDeUnaRelacionQueYaNoRigeSeRechaza(): void
    {
        $vigente = $this->despieceDelPollo();
        $this->db->query("UPDATE relaciones SET estado = 'Baja' WHERE id = {$vigente['id']}");

        $respuesta = $this->temporales()->guardar($this->cambio($vigente['numRelacion'], $this->lineasDelPolloCon('0.25')), $this->usuario);

        self::assertStringContainsString('no está vigente', $respuesta['error']['mensaje']);
        self::assertSame(0, $this->cuantas('relacionesTemporales'));
    }

    /** @comportamiento Guardar no exige la forma completa, pero sí líneas legibles, de artículos que existen y sin repetir. */
    public function test_T8_unaLineaIlegibleUnArticuloInexistenteOUnoRepetidoSeRechazanSinEscribir(): void
    {
        $malas = [
            'sentido desconocido' => [['idArticulo' => $this->articulo['pollo'], 'sentido' => 'arriba', 'cantidad' => '1', 'unidad' => 'kg']],
            'cantidad que no es un número' => [['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => '1,5', 'unidad' => 'kg']],
            'unidad desconocida' => [['idArticulo' => $this->articulo['pollo'], 'sentido' => 'entrada', 'cantidad' => '1', 'unidad' => 'lb']],
            'artículo inexistente' => [['idArticulo' => 999999999, 'sentido' => 'entrada', 'cantidad' => '1', 'unidad' => 'kg']],
            'artículo repetido' => [$this->linea('pollo', 'entrada', '1'), $this->linea('pollo', 'salida', '1')],
            'líneas que no son una lista' => 'pollo',
        ];

        foreach ($malas as $caso => $lineas) {
            $respuesta = $this->temporales()->guardar($this->nuevo($lineas), $this->usuario);
            self::assertArrayHasKey('error', $respuesta, $caso);
        }
        self::assertSame(0, $this->cuantas('relacionesTemporales'));
    }

    public function test_T9_cancelarBorraElTemporalYUnoQueYaNoExisteAvisa(): void
    {
        $id = $this->escenario->temporal($this->despiece, $this->lineasDelPolloCon('0.25'));

        $cancelado = $this->temporales()->cancelar($id);
        $otraVez = $this->temporales()->cancelar($id);

        self::assertArrayHasKey('datos', $cancelado);
        self::assertSame(0, $this->cuantas('relacionesTemporales'));
        self::assertStringContainsString('ya no existe', $otraVez['error']['mensaje']);
    }

    /** @comportamiento La relación nueva rige en la 1.0, a nombre de quien activa y no de quien preparó. */
    public function test_T10_activarUnaRelacionNuevaLaPoneARegirYBorraElTemporal(): void
    {
        $preparo = $this->otroUsuario();
        $id = $this->escenario->temporal($this->despiece, $this->lineasDelPolloCon('0.23'), ['nombre' => 'Pollo de corral', 'idUsuario' => $preparo, 'fecha' => '2026-10-01 10:00:00']);

        $respuesta = $this->temporales()->activar($id, $this->usuario, $this->relaciones());

        self::assertSame('1.0', $respuesta['datos']['version']);
        self::assertSame('alta', $respuesta['datos']['tipoCambio']);
        $numRelacion = $respuesta['datos']['numRelacion'];
        self::assertSame(['1.0 Activo'], $this->versiones($numRelacion));
        $fila = $this->db->query("SELECT nombre, idUsuario, fechaCreacion FROM relaciones WHERE numRelacion = $numRelacion")->fetch_assoc();
        self::assertSame('Pollo de corral', $fila['nombre']);
        self::assertSame($this->usuario, (int) $fila['idUsuario']);
        self::assertGreaterThan('2026-10-01 10:00:00', $fila['fechaCreacion']);
        self::assertSame(0, $this->cuantas('relacionesTemporales'));
    }

    /** @comportamiento Los guardados intermedios no son historial: solo consta la versión activada. */
    public function test_T11_activarUnCambioGuardadoVariasVecesDejaUnaSolaVersionNueva(): void
    {
        $vigente = $this->despieceDelPollo();
        $temporales = $this->temporales();
        $primero = $temporales->guardar($this->cambio($vigente['numRelacion'], $this->lineasDelPolloCon('0.25')), $this->usuario);
        $segundo = $temporales->guardar($this->cambio($vigente['numRelacion'], $this->lineasDelPolloCon('0.26'), $primero['datos'], 'Pesada del lunes'), $this->usuario);

        $respuesta = $temporales->activar($primero['datos']['id'], $this->usuario, $this->relaciones());

        self::assertArrayHasKey('datos', $segundo);
        self::assertSame('1.1', $respuesta['datos']['version']);
        self::assertSame('proporciones', $respuesta['datos']['tipoCambio']);
        self::assertSame(['1.0 Sustituido', '1.1 Activo'], $this->versiones($vigente['numRelacion']));
        $origen = $this->db->query("SELECT origenAjuste FROM relaciones WHERE numRelacion = {$vigente['numRelacion']} AND version = '1.1'")->fetch_assoc()['origenAjuste'];
        self::assertSame('Pesada del lunes', $origen);
        self::assertSame(0, (int) $this->db->query("SELECT COUNT(*) AS n FROM relaciones WHERE estado = 'Borrador'")->fetch_assoc()['n']);
        self::assertSame(0, $this->cuantas('relacionesTemporales'));
    }

    public function test_T12_unaActivacionQueLaAdmisionRechazaDejaElTemporalComoEstaba(): void
    {
        $dosEntradas = [$this->linea('pollo', 'entrada', '1'), $this->linea('conejo', 'entrada', '1'), $this->linea('pechuga', 'salida', '0.2')];
        $id = $this->escenario->temporal($this->despiece, $dosEntradas, ['fecha' => '2026-10-01 10:00:00']);
        $antes = $this->filaDelTemporal($id);

        $respuesta = $this->temporales()->activar($id, $this->usuario, $this->relaciones());

        self::assertArrayHasKey('error', $respuesta);
        self::assertSame($antes, $this->filaDelTemporal($id));
        self::assertSame(0, $this->cuantas('relaciones'));
    }

    public function test_T13_unAjusteSinOrigenSeRechazaYConOrigenRige(): void
    {
        $vigente = $this->despieceDelPollo();
        $sinOrigen = $this->escenario->temporal($this->despiece, $this->lineasDelPolloCon('0.25'), ['numRelacion' => $vigente['numRelacion'], 'idRelacion' => $vigente['id']]);

        $rechazo = $this->temporales()->activar($sinOrigen, $this->usuario, $this->relaciones());
        $this->db->query("UPDATE relacionesTemporales SET origenAjuste = 'Pesada del lunes' WHERE id = $sinOrigen");
        $activada = $this->temporales()->activar($sinOrigen, $this->usuario, $this->relaciones());

        self::assertStringContainsString('origen', $rechazo['error']['mensaje']);
        self::assertSame('1.1', $activada['datos']['version']);
    }

    /**
     * @comportamiento Lo preparado sobre una versión que ya no rige no se activa: el historial
     *   registraría un cambio que nadie hizo así. Tampoco si esa versión ya no existe.
     */
    public function test_T14_unTemporalDeUnaVersionQueYaNoRigeONoExisteSeRechazaYSeConserva(): void
    {
        $vigente = $this->despieceDelPollo();
        $id = $this->escenario->temporal($this->despiece, $this->lineasDelPolloCon('0.25'), ['numRelacion' => $vigente['numRelacion'], 'idRelacion' => $vigente['id'], 'origenAjuste' => 'Pesada']);
        $otra = $this->relaciones()->modificar($vigente['numRelacion'], [], $this->lineasDelPolloCon('0.30'), $this->usuario, 'Otra pesada');
        $huerfano = $this->escenario->temporal($this->despiece, $this->lineasDelPolloCon('0.25'), ['numRelacion' => 999999, 'idRelacion' => 999999, 'origenAjuste' => 'Pesada']);

        $sustituida = $this->temporales()->activar($id, $this->usuario, $this->relaciones());
        $inexistente = $this->temporales()->activar($huerfano, $this->usuario, $this->relaciones());

        self::assertSame('1.1', $otra['datos']['version']);
        self::assertStringContainsString('cambió desde que se preparó', $sustituida['error']['mensaje']);
        self::assertStringContainsString('cambió desde que se preparó', $inexistente['error']['mensaje']);
        self::assertSame(['1.0 Sustituido', '1.1 Activo'], $this->versiones($vigente['numRelacion']));
        self::assertSame(2, $this->cuantas('relacionesTemporales'));
    }

    /**
     * @comportamiento Poner a regir y borrar el temporal van juntos: si falla el borrado del
     *   temporal, tras un alta o tras una modificación, o falla la propia activación, todo queda
     *   como antes y el temporal sigue.
     */
    public function test_T15_unFalloEnCualquierPasoDeLaActivacionDejaTodoComoAntes(): void
    {
        $nueva = $this->escenario->temporal($this->despiece, $this->lineasDelPolloCon('0.23'));
        $vigente = $this->despieceDelConejo();
        $cambio = $this->escenario->temporal($this->despiece, [$this->linea('conejo', 'entrada', '1'), $this->linea('paletilla', 'salida', '0.35')], ['numRelacion' => $vigente['numRelacion'], 'idRelacion' => $vigente['id'], 'origenAjuste' => 'Pesada']);

        $trasElAlta = $this->temporalesQueFallanAlBorrar()->activar($nueva, $this->usuario, $this->relaciones());
        $trasLaModificacion = $this->temporalesQueFallanAlBorrar()->activar($cambio, $this->usuario, $this->relaciones());
        $alActivar = $this->temporales()->activar($cambio, $this->usuario, $this->relacionesQueFallanAlActivar());

        foreach ([$trasElAlta, $trasLaModificacion, $alActivar] as $respuesta) {
            self::assertArrayHasKey('error', $respuesta);
        }
        self::assertSame(['1.0 Activo'], $this->versiones($vigente['numRelacion']));
        self::assertSame(1, $this->cuantas('relaciones'));
        self::assertSame(2, $this->cuantas('relacionesTemporales'));
    }

    /**
     * @comportamiento Dos activaciones del mismo temporal: la segunda espera a la primera, que
     *   lo tiene bloqueado, y después ya no lo encuentra. Queda una sola versión nueva.
     */
    public function test_T16_dosActivacionesDelMismoTemporalDejanUnaSolaVersionNueva(): void
    {
        $id = $this->escenario->temporal($this->despiece, $this->lineasDelPolloCon('0.23'));
        $otra = $this->otraConexion();
        $temporales = $this->temporalesQueAlLeerConBloqueo($otra, "DELETE FROM relacionesTemporales WHERE id = $id");

        $primera = $temporales->activar($id, $this->usuario, $this->relaciones());
        $otra->close();
        $segunda = $this->temporales()->activar($id, $this->usuario, $this->relaciones());

        self::assertSame(1205, $temporales->errorDeLaOtraConexion);
        self::assertArrayHasKey('datos', $primera);
        self::assertStringContainsString('ya no existe', $segunda['error']['mensaje']);
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(DISTINCT numRelacion) AS n FROM relaciones')->fetch_assoc()['n']);
    }

    /**
     * @comportamiento Un temporal es trabajo en curso: no impide dar de baja o borrar un artículo
     *   del catálogo. Al activar, la admisión lo rechaza con su motivo y el temporal sigue.
     */
    public function test_T17_unArticuloDadoDeBajaOBorradoDespuesDeGuardarSeRechazaAlActivar(): void
    {
        $deBaja = $this->siembra->articulo('Prueba de temporales: cuello');
        $borrado = $this->siembra->articulo('Prueba de temporales: higadillo');
        $conDeBaja = $this->escenario->temporal($this->despiece, [$this->linea('pollo', 'entrada', '1'), ['idArticulo' => $deBaja, 'sentido' => 'salida', 'cantidad' => '0.1', 'unidad' => 'kg']]);
        $conBorrado = $this->escenario->temporal($this->despiece, [$this->linea('conejo', 'entrada', '1'), ['idArticulo' => $borrado, 'sentido' => 'salida', 'cantidad' => '0.1', 'unidad' => 'kg']]);
        $this->db->query("UPDATE articulos SET estado = 'Baja' WHERE idArticulo = $deBaja");
        $this->db->query("DELETE FROM articulos WHERE idArticulo = $borrado");

        $porBaja = $this->temporales()->activar($conDeBaja, $this->usuario, $this->relaciones());
        $porBorrado = $this->temporales()->activar($conBorrado, $this->usuario, $this->relaciones());
        $this->articulo['cuello'] = $deBaja;

        self::assertStringContainsString('de baja', $porBaja['error']['mensaje']);
        self::assertStringContainsString('no existe', $porBorrado['error']['mensaje']);
        self::assertSame(2, $this->cuantas('relacionesTemporales'));
        self::assertSame(0, $this->cuantas('relaciones'));
    }

    public function test_T18_lasRelacionesAbiertasDicenQuienYCuandoYDesaparecenAlActivarOCancelar(): void
    {
        $vigente = $this->despieceDelPollo();
        $otro = $this->otroUsuario();
        $nueva = $this->escenario->temporal($this->despiece, [$this->linea('conejo', 'entrada', '1'), $this->linea('paletilla', 'salida', '0.3')], ['nombre' => 'Conejo', 'idUsuario' => $otro, 'fecha' => '2026-10-02 11:00:00']);
        $cambio = $this->escenario->temporal($this->despiece, $this->lineasDelPolloCon('0.25'), ['numRelacion' => $vigente['numRelacion'], 'idRelacion' => $vigente['id'], 'origenAjuste' => 'Pesada', 'fecha' => '2026-10-01 10:00:00']);

        $abiertas = $this->temporales()->listarAbiertos()['datos'];
        $this->temporales()->activar($cambio, $this->usuario, $this->relaciones());
        $this->temporales()->cancelar($nueva);

        self::assertSame([$nueva, $cambio], array_map('intval', array_column($abiertas, 'id')));
        self::assertNull($abiertas[0]['numRelacion']);
        self::assertSame('Conejo', $abiertas[0]['nombre']);
        self::assertSame('Despiece', $abiertas[0]['tipo']);
        self::assertSame($this->nombreDeUsuario($otro), $abiertas[0]['usuario']);
        self::assertSame('2026-10-02 11:00:00', $abiertas[0]['fechaModificacion']);
        self::assertSame($vigente['numRelacion'], (int) $abiertas[1]['numRelacion']);
        self::assertSame([], $this->temporales()->listarAbiertos()['datos']);
    }

    /** @comportamiento Lo que solo está en un temporal no aparece en ninguna lectura de lo que rige. */
    public function test_T19_loQueSoloEstaEnUnTemporalNoApareceEnLoQueRige(): void
    {
        $vigente = $this->despieceDelPollo();
        $this->escenario->temporal($this->despiece, $this->lineasDelPolloCon('0.25'), ['numRelacion' => $vigente['numRelacion'], 'idRelacion' => $vigente['id']]);
        $this->escenario->temporal($this->despiece, [$this->linea('conejo', 'entrada', '1'), $this->linea('paletilla', 'salida', '0.3')]);
        $relaciones = $this->relaciones();

        $vigentes = $relaciones->vigentes()['datos'];
        $delConejo = $relaciones->vigentes($this->articulo['conejo'])['datos'];
        $listado = $relaciones->listado('', '', '', 1, 30)['datos'];
        $enFecha = $relaciones->estadoEnFecha($vigente['numRelacion'], date('Y-m-d'))['datos'];

        self::assertCount(1, $vigentes);
        self::assertEquals(0.23, $vigentes[0]['lineas'][1]['cantidad']);
        self::assertSame([], $delConejo);
        self::assertSame(1, $listado['total']);
        self::assertEquals(0.23, $enFecha['version']['lineas'][1]['cantidad']);
    }

    /**
     * @comportamiento El primer guardado del temporal de un cambio lee la relación con bloqueo:
     *   una baja que llegue a la vez espera a que termine, y después se rechaza por el temporal.
     */
    public function test_T20_mientrasSeGuardaElPrimerTemporalOtraConexionNoPuedeDarDeBajaLaRelacion(): void
    {
        $vigente = $this->despieceDelPollo();
        $otra = $this->otraConexion();
        $temporales = $this->temporalesQueAlLeerConBloqueo($otra, "UPDATE relaciones SET estado = 'Baja' WHERE numRelacion = {$vigente['numRelacion']} AND estado = 'Activo'");

        $guardado = $temporales->guardar($this->cambio($vigente['numRelacion'], $this->lineasDelPolloCon('0.25')), $this->usuario);
        $otra->close();
        $baja = $this->relaciones()->darDeBaja($vigente['numRelacion'], $this->usuario);

        self::assertSame(1205, $temporales->errorDeLaOtraConexion);
        self::assertArrayHasKey('datos', $guardado);
        self::assertStringContainsString('cambio en preparación', $baja['error']['mensaje']);
        self::assertSame(['1.0 Activo'], $this->versiones($vigente['numRelacion']));
    }

    /** @comportamiento Dos primeros guardados de la misma relación a la vez: el segundo espera y avisa. */
    public function test_T21_dosPrimerosGuardadosDeLaMismaRelacionALaVezDejanUnSoloTemporal(): void
    {
        $vigente = $this->despieceDelPollo();
        $otra = $this->otraConexion();
        $temporales = $this->temporalesQueAlLeerConBloqueo($otra, "SELECT id FROM relaciones WHERE numRelacion = {$vigente['numRelacion']} FOR UPDATE");

        $primero = $temporales->guardar($this->cambio($vigente['numRelacion'], $this->lineasDelPolloCon('0.25')), $this->usuario);
        $otra->close();
        $segundo = $this->temporales()->guardar($this->cambio($vigente['numRelacion'], $this->lineasDelPolloCon('0.30')), $this->usuario);

        self::assertSame(1205, $temporales->errorDeLaOtraConexion);
        self::assertArrayHasKey('datos', $primero);
        self::assertStringContainsString('ya tiene un cambio en preparación', $segundo['error']['mensaje']);
        self::assertSame(1, $this->cuantas('relacionesTemporales'));
    }

    // --- Ayudantes ---------------------------------------------------------------------------

    private function temporales(): object
    {
        return new \RelacionesTemporales();
    }

    private function relaciones(): object
    {
        return new \Relaciones();
    }

    /** La clase de temporales, que anota cada sentencia que ejecuta. */
    private function temporalesQueAnotan(): object
    {
        return new class extends \RelacionesTemporales {
            /** @var list<string> */
            public array $sentencias = [];

            protected function consultaPreparada($sql, $valores = array())
            {
                $this->sentencias[] = $sql;

                return parent::consultaPreparada($sql, $valores);
            }
        };
    }

    /** La clase de temporales, que falla al borrar el temporal que se activa. */
    private function temporalesQueFallanAlBorrar(): object
    {
        return new class extends \RelacionesTemporales {
            protected function consultaPreparada($sql, $valores = array())
            {
                if (str_starts_with(ltrim($sql), 'DELETE FROM relacionesTemporales')) {
                    return ['error' => $this->montarAdvertencia('danger', 'Fallo provocado por la prueba'), 'consulta' => $sql];
                }

                return parent::consultaPreparada($sql, $valores);
            }
        };
    }

    /** La clase de relaciones, que falla al poner a regir el borrador. */
    private function relacionesQueFallanAlActivar(): object
    {
        return new class extends \Relaciones {
            protected function consultaPreparada($sql, $valores = array())
            {
                if (str_contains($sql, "estado = 'Activo' WHERE id = ? AND estado = 'Borrador'")) {
                    return ['error' => $this->montarAdvertencia('danger', 'Fallo provocado por la prueba'), 'consulta' => $sql];
                }

                return parent::consultaPreparada($sql, $valores);
            }
        };
    }

    /**
     * La clase de temporales que, justo después de su primera lectura con bloqueo, hace escribir
     * a otra conexión, como si otra persona lo intentara a la vez, y anota el error que recibe.
     */
    private function temporalesQueAlLeerConBloqueo(\mysqli $otra, string $escritura): object
    {
        $temporales = new class extends \RelacionesTemporales {
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
        $temporales->otra = $otra;
        $temporales->escritura = $escritura;

        return $temporales;
    }

    /** Una segunda conexión, que espera un segundo como mucho a que se suelte un bloqueo. */
    private function otraConexion(): \mysqli
    {
        $otra = self::conectar($this->ejercicio);
        $otra->query('SET SESSION innodb_lock_wait_timeout = 1');

        return $otra;
    }

    /** Lo que la pantalla manda para una relación nueva. */
    private function nuevo($lineas, array $opciones = []): array
    {
        return [
            'id' => $opciones['id'] ?? 0,
            'numRelacion' => 0,
            'idTipo' => $this->despiece,
            'nombre' => $opciones['nombre'] ?? '',
            'descripcion' => '',
            'diasViabilidad' => '',
            'origenAjuste' => '',
            'lineas' => $lineas,
            'fechaModificacion' => $opciones['fechaModificacion'] ?? '',
        ];
    }

    /**
     * Lo que la pantalla manda para un cambio de una relación que rige: la primera vez sin
     * temporal; después, con el que devolvió el guardado anterior.
     */
    private function cambio(int $numRelacion, array $lineas, array $guardado = [], string $origenAjuste = ''): array
    {
        return [
            'id' => $guardado['id'] ?? 0,
            'numRelacion' => $numRelacion,
            'idTipo' => $this->despiece,
            'nombre' => '',
            'descripcion' => '',
            'diasViabilidad' => '',
            'origenAjuste' => $origenAjuste,
            'lineas' => $lineas,
            'fechaModificacion' => $guardado['fechaModificacion'] ?? '',
        ];
    }

    private function linea(string $nombre, string $sentido, ?string $cantidad, ?string $unidad = 'kg'): array
    {
        return ['idArticulo' => $this->articulo[$nombre], 'sentido' => $sentido, 'cantidad' => $cantidad, 'unidad' => $unidad];
    }

    /** El despiece del pollo, con la cantidad de pechuga que se indique. */
    private function lineasDelPolloCon(string $pechuga): array
    {
        return [$this->linea('pollo', 'entrada', '1'), $this->linea('pechuga', 'salida', $pechuga), $this->linea('alas', 'salida', '0.12')];
    }

    /** @return array{id:int,numRelacion:int} */
    private function despieceDelPollo(): array
    {
        return $this->escenario->relacionVigente($this->despiece, $this->lineasDelPolloCon('0.23'), ['nombre' => 'Pollo entero']);
    }

    /** @return array{id:int,numRelacion:int} */
    private function despieceDelConejo(): array
    {
        return $this->escenario->relacionVigente($this->despiece, [$this->linea('conejo', 'entrada', '1'), $this->linea('paletilla', 'salida', '0.30')]);
    }

    private function otroUsuario(): int
    {
        return (int) $this->db->query('SELECT id FROM usuarios ORDER BY id DESC LIMIT 1')->fetch_assoc()['id'];
    }

    private function nombreDeUsuario(int $id): string
    {
        return $this->db->query("SELECT username FROM usuarios WHERE id = $id")->fetch_assoc()['username'];
    }

    private function cuantas(string $tabla): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM $tabla")->fetch_assoc()['n'];
    }

    private function filaDelTemporal(int $id): ?array
    {
        return $this->db->query("SELECT * FROM relacionesTemporales WHERE id = $id")->fetch_assoc();
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
}

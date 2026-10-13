<?php
/**
 * Los tipos de relación: el vocabulario que cada tienda define sobre las tres formas.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModRelaciones;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\EscenarioRelaciones;

final class RelacionesTiposIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private EscenarioRelaciones $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/RelacionesTipos.php');
        $this->siembra = new EscenarioRelaciones($this->db);
        // Los recorridos de navegador dejan tipos sembrados en la base: aquí se parte de ninguno.
        $this->siembra->limpiar();
    }

    public function test_T1_unTipoSeLeeConSuFormaSiLlevaCantidadYSuEstado(): void
    {
        $id = $this->siembra->tipoDeRelacion('Deshuesado', '1:N', 1, 'Activo');

        $respuesta = (new \RelacionesTipos())->leerTipo($id);

        self::assertSame('Deshuesado', $respuesta['datos']['nombre']);
        self::assertSame('1:N', $respuesta['datos']['forma']);
        self::assertSame(1, (int) $respuesta['datos']['llevaCantidad']);
        self::assertSame('Activo', $respuesta['datos']['estado']);
    }

    public function test_T2_leerUnTipoQueNoExisteNoEsUnErrorYNoDevuelveNinguno(): void
    {
        $respuesta = (new \RelacionesTipos())->leerTipo(999999);

        self::assertArrayNotHasKey('error', $respuesta);
        self::assertNull($respuesta['datos']);
    }

    public function test_T3_elListadoDeVigentesDejaFueraLosDeBajaYElCompletoLosTrae(): void
    {
        $this->siembra->tipoDeRelacion('Cruce', '1:1', 0, 'Activo');
        $this->siembra->tipoDeRelacion('Pack retirado', 'N:1', 1, 'Baja');
        $tipos = new \RelacionesTipos();

        $vigentes = array_column($tipos->listarTipos(true)['datos'], 'nombre');
        $todos = array_column($tipos->listarTipos(false)['datos'], 'nombre');

        self::assertContains('Cruce', $vigentes);
        self::assertNotContains('Pack retirado', $vigentes);
        self::assertContains('Pack retirado', $todos);
    }

    public function test_T4_unTipoCreadoQuedaVigenteConSuFormaYSiLlevaCantidad(): void
    {
        $respuesta = $this->tipos()->crearTipo('Deshuesado', '1:N', 0);

        $guardado = $this->tipos()->leerTipo($respuesta['datos']['id'])['datos'];
        self::assertSame(['Deshuesado', '1:N', 0, 'Activo'], [$guardado['nombre'], $guardado['forma'], (int) $guardado['llevaCantidad'], $guardado['estado']]);
    }

    /**
     * @comportamiento No puede haber dos tipos vigentes con el mismo nombre: quien elige el
     *   tipo de una relación no sabría cuál es cuál. Uno dado de baja no cuenta, y las
     *   mayúsculas no distinguen.
     */
    public function test_T5_elNombreDeUnTipoVigenteNoSePuedeRepetirYElDeUnoDeBajaSi(): void
    {
        $this->siembra->tipoDeRelacion('Despiece', '1:N', 1, 'Activo');
        $this->siembra->tipoDeRelacion('Pack retirado', 'N:1', 1, 'Baja');

        $repetido = $this->tipos()->crearTipo('DESPIECE', 'N:1', 1);
        $comoElDeBaja = $this->tipos()->crearTipo('Pack retirado', 'N:1', 1);

        self::assertStringContainsString('Ya hay un tipo vigente', $repetido['error']['mensaje']);
        self::assertSame(1, $this->cuantosSeLlaman('Despiece'));
        self::assertArrayHasKey('datos', $comoElDeBaja);
    }

    /**
     * @comportamiento El nombre de un tipo se pinta en desplegables y listados de todo el
     *   módulo, así que solo admite letras, números, espacios y guiones.
     */
    public function test_T6_unNombreVacioDemasiadoLargoOConOtrosCaracteresSeRechaza(): void
    {
        $antes = $this->cuantosTipos();

        foreach (['', '   ', str_repeat('a', 51), 'Pack "regalo"', '<b>Pack</b>', "Pack'; DROP TABLE relaciones; --", 'Pack & cesta'] as $nombre) {
            $respuesta = $this->tipos()->crearTipo($nombre, 'N:1', 1);

            self::assertSame('warning', $respuesta['error']['tipo'], $nombre);
        }
        self::assertArrayHasKey('datos', $this->tipos()->crearTipo('Pack 2-en-1 de montaña', 'N:1', 1));
        self::assertSame($antes + 1, $this->cuantosTipos());
    }

    public function test_T7_unaFormaQueNoEsNingunaDeLasTresSeRechaza(): void
    {
        $antes = $this->cuantosTipos();

        foreach (['2:2', 'N:N', '', "1:N' OR '1'='1"] as $forma) {
            self::assertStringContainsString('forma', $this->tipos()->crearTipo('Tipo de prueba', $forma, 1)['error']['mensaje'], $forma);
        }
        self::assertSame($antes, $this->cuantosTipos());
    }

    public function test_T8_unTipoSeRenombraAunqueTengaRelacionesYNoAlNombreDeOtroVigente(): void
    {
        $despiece = $this->siembra->tipoDeRelacion('Despiece', '1:N');
        $this->siembra->tipoDeRelacion('Elaboración', 'N:1');
        $this->relacionEn($despiece, 'Activo');

        $renombrado = $this->tipos()->renombrarTipo($despiece, 'Despiece de ave');
        $repetido = $this->tipos()->renombrarTipo($despiece, 'elaboración');
        $soloMayusculas = $this->tipos()->renombrarTipo($despiece, 'DESPIECE DE AVE');

        self::assertArrayHasKey('datos', $renombrado);
        self::assertStringContainsString('Ya hay un tipo vigente', $repetido['error']['mensaje']);
        self::assertArrayHasKey('datos', $soloMayusculas);
        self::assertSame('DESPIECE DE AVE', $this->tipos()->leerTipo($despiece)['datos']['nombre']);
    }

    public function test_T9_laFormaYSiLlevaCantidadSeCambianEnUnTipoSinRelaciones(): void
    {
        $tipo = $this->siembra->tipoDeRelacion('Despiece', '1:N', 1);

        $respuesta = $this->tipos()->modificarTipo($tipo, 'N:1', 0);

        $guardado = $this->tipos()->leerTipo($tipo)['datos'];
        self::assertArrayHasKey('datos', $respuesta);
        self::assertSame(['N:1', 0], [$guardado['forma'], (int) $guardado['llevaCantidad']]);
    }

    /**
     * @comportamiento La forma de un tipo, y si sus líneas llevan cantidad, es lo que se
     *   comprobó al admitir cada relación. Si se cambiasen con relaciones ya guardadas, esas
     *   relaciones dejarían de cumplir su propio tipo. Cuenta cualquier relación, también
     *   un borrador o una dada de baja.
     */
    public function test_T10_niLaFormaNiLaCantidadSeCambianSiElTipoTieneUnaRelacionEnCualquierEstado(): void
    {
        foreach (['Borrador', 'Activo', 'Sustituido', 'Baja'] as $estado) {
            $tipo = $this->siembra->tipoDeRelacion('Tipo con relacion ' . $estado, '1:N', 1);
            $this->relacionEn($tipo, $estado);

            $otraForma = $this->tipos()->modificarTipo($tipo, 'N:1', 1);
            $sinCantidad = $this->tipos()->modificarTipo($tipo, '1:N', 0);

            $guardado = $this->tipos()->leerTipo($tipo)['datos'];
            self::assertStringContainsString('tiene relaciones', $otraForma['error']['mensaje'], $estado);
            self::assertStringContainsString('tiene relaciones', $sinCantidad['error']['mensaje'], $estado);
            self::assertSame(['1:N', 1], [$guardado['forma'], (int) $guardado['llevaCantidad']], $estado);
        }
    }

    public function test_T11_unTipoSeBorraSoloSiNoTieneNingunaRelacion(): void
    {
        $libre = $this->siembra->tipoDeRelacion('Tipo libre', '1:1');
        $borrado = $this->tipos()->borrarTipo($libre);

        self::assertArrayHasKey('datos', $borrado);
        self::assertNull($this->tipos()->leerTipo($libre)['datos']);

        foreach (['Borrador', 'Activo', 'Sustituido', 'Baja'] as $estado) {
            $tipo = $this->siembra->tipoDeRelacion('Tipo con relacion ' . $estado, '1:N');
            $this->relacionEn($tipo, $estado);

            $respuesta = $this->tipos()->borrarTipo($tipo);

            self::assertStringContainsString('tiene relaciones', $respuesta['error']['mensaje'], $estado);
            self::assertNotNull($this->tipos()->leerTipo($tipo)['datos'], $estado);
        }
    }

    public function test_T12_unTipoSeDaDeBajaAunqueTengaRelacionesYSoloUnaVez(): void
    {
        $tipo = $this->siembra->tipoDeRelacion('Despiece', '1:N');
        $this->relacionEn($tipo, 'Activo');

        $baja = $this->tipos()->darDeBajaTipo($tipo);
        $otraVez = $this->tipos()->darDeBajaTipo($tipo);

        self::assertArrayHasKey('datos', $baja);
        self::assertSame('Baja', $this->tipos()->leerTipo($tipo)['datos']['estado']);
        self::assertStringContainsString('ya está de baja', $otraVez['error']['mensaje']);
    }

    public function test_T13_unTipoDeBajaSeReactivaSiNoHayOtroVigenteConSuNombre(): void
    {
        $retirado = $this->siembra->tipoDeRelacion('Pack', 'N:1', 1, 'Baja');
        $otroRetirado = $this->siembra->tipoDeRelacion('Cesta', 'N:1', 1, 'Baja');
        $this->siembra->tipoDeRelacion('cesta', 'N:1', 1, 'Activo');
        $vigente = $this->siembra->tipoDeRelacion('Despiece', '1:N');

        $reactivado = $this->tipos()->reactivarTipo($retirado);
        $conNombreOcupado = $this->tipos()->reactivarTipo($otroRetirado);
        $queNoEstabaDeBaja = $this->tipos()->reactivarTipo($vigente);

        self::assertArrayHasKey('datos', $reactivado);
        self::assertSame('Activo', $this->tipos()->leerTipo($retirado)['datos']['estado']);
        self::assertStringContainsString('Ya hay un tipo vigente', $conNombreOcupado['error']['mensaje']);
        self::assertSame('Baja', $this->tipos()->leerTipo($otroRetirado)['datos']['estado']);
        self::assertStringContainsString('no está de baja', $queNoEstabaDeBaja['error']['mensaje']);
    }

    public function test_T14_lasOperacionesSobreUnTipoQueNoExisteSeRechazan(): void
    {
        $tipos = $this->tipos();

        foreach ([$tipos->renombrarTipo(999999, 'Otro'), $tipos->modificarTipo(999999, '1:N', 1), $tipos->darDeBajaTipo(999999), $tipos->reactivarTipo(999999), $tipos->borrarTipo(999999), $tipos->borrarTipo("1' OR '1'='1")] as $respuesta) {
            self::assertStringContainsString('no existe', $respuesta['error']['mensaje']);
        }
    }

    /**
     * @comportamiento Un tipo es un dato, no código: el que crea el administrador sirve
     *   para admitir relaciones y aparece en su lectura sin tocar nada más.
     */
    public function test_T15_unTipoRecienCreadoSirveParaAdmitirYSeLeeConSusRelaciones(): void
    {
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/Relaciones.php');
        $id = $this->tipos()->crearTipo('Deshuesado', '1:N', 1)['datos']['id'];
        $siembra = new \TPVFox\Test\Siembra\Siembra($this->db);
        $lineas = [
            ['idArticulo' => $siembra->articulo('Prueba de tipos: jamón'), 'sentido' => 'entrada', 'cantidad' => 1, 'unidad' => 'kg', 'estado' => 'Activo'],
            ['idArticulo' => $siembra->articulo('Prueba de tipos: hueso'), 'sentido' => 'salida', 'cantidad' => 0.2, 'unidad' => 'kg', 'estado' => 'Activo'],
        ];

        $admision = (new \RelacionesFormas())->admitir($this->tipos()->leerTipo($id)['datos'], $lineas, true);
        $this->siembra->relacionVigente($id, $lineas);
        $leidas = (new \Relaciones())->vigentes();

        self::assertSame('admitida', $admision['datos']);
        self::assertContains('Deshuesado', array_column($leidas['datos'], 'tipo'));
    }

    public function test_T16_siLaBaseFallaCadaOperacionDevuelveElFalloYNoLoLanza(): void
    {
        $tipo = $this->siembra->tipoDeRelacion('Despiece', '1:N');
        $tipos = $this->tipos();
        $tipos->fallar = true;

        foreach ([$tipos->crearTipo('Otro', '1:N', 1), $tipos->renombrarTipo($tipo, 'Otro'), $tipos->modificarTipo($tipo, 'N:1', 1), $tipos->darDeBajaTipo($tipo), $tipos->reactivarTipo($tipo), $tipos->borrarTipo($tipo)] as $respuesta) {
            self::assertArrayNotHasKey('datos', $respuesta);
            self::assertNotSame('', $respuesta['error']['mensaje']);
        }
    }

    /** La clase del módulo, con la posibilidad de hacer fallar sus consultas desde la prueba. */
    private function tipos(): object
    {
        return new class extends \RelacionesTipos {
            public bool $fallar = false;

            protected function consultaPreparada($sql, $valores = array())
            {
                if ($this->fallar) {
                    return ['error' => $this->montarAdvertencia('danger', 'Fallo provocado por la prueba'), 'consulta' => $sql];
                }

                return parent::consultaPreparada($sql, $valores);
            }
        };
    }

    /** Una relación de ese tipo cuya única versión está en el estado indicado. */
    private function relacionEn(int $idTipo, string $estado): void
    {
        $numRelacion = (int) $this->db->query('SELECT COALESCE(MAX(numRelacion), 0) + 1 AS n FROM relaciones')->fetch_assoc()['n'];
        $this->siembra->versionDeRelacion($numRelacion, $idTipo, $estado === 'Borrador' ? '0.0' : '1.0', $estado, '2026-01-10 09:00:00');
    }

    private function cuantosTipos(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) AS n FROM relacionesTipos')->fetch_assoc()['n'];
    }

    private function cuantosSeLlaman(string $nombre): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM relacionesTipos WHERE nombre = '$nombre'")->fetch_assoc()['n'];
    }
}

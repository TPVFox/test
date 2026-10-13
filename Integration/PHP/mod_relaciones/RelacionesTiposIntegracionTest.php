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
}

<?php

/**
 * `incidenciasAdjuntas()` de `funciones.php` envuelve
 * `ClaseIncidencia::incidenciasAdjuntas()`, que busca por `LIKE` dentro del JSON de la
 * columna `datos` — no por una columna propia de documento.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CargaAislada;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class IncidenciasAdjuntasIntegracionTest extends CasoIntegracion
{
    private Siembra $siembra;
    private int $idAlbaran;

    protected function setUp(): void
    {
        parent::setUp();
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
        $this->incluirTPVFox('/modulos/mod_incidencias/clases/ClaseIncidencia.php');

        $this->siembra = new Siembra($this->db);
        $idArticulo = $this->siembra->articulo('Articulo de incidencia');
        $this->idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-15');
    }

    public function test_encuentraLaIncidenciaDeEseDocumentoYEsaVista(): void
    {
        $this->siembra->incidencia(
            'mod_ventas',
            ['vista' => 'albaran', 'idReal' => (string) $this->idAlbaran],
            'incidencia del albaran'
        );

        $resultado = CargaAislada::llamar(
            fn () => \incidenciasAdjuntas($this->idAlbaran, 'mod_ventas', $this->db, 'albaran')
        );

        self::assertCount(1, $resultado['datos']);
        self::assertSame('incidencia del albaran', $resultado['datos'][0]['mensaje']);
    }

    public function test_noConfundeLaVistaDeOtroDocumento(): void
    {
        $this->siembra->incidencia(
            'mod_ventas',
            ['vista' => 'factura', 'idReal' => (string) $this->idAlbaran], // mismo id, otra vista
            'incidencia de una factura con el mismo id'
        );

        $resultado = CargaAislada::llamar(
            fn () => \incidenciasAdjuntas($this->idAlbaran, 'mod_ventas', $this->db, 'albaran')
        );

        self::assertSame([], $resultado['datos']);
    }

    /**
     * Defecto: la busqueda solo encuentra la incidencia si `idReal` se codifico como
     * cadena JSON (`"idReal":"828"`), no como numero (`"idReal":828`).
     *
     * Sintoma: una incidencia sembrada con `idReal` entero no aparece en el resultado,
     * sin ningun error — el resultado sale vacio como si no hubiera incidencias. Causa
     * raiz: `ClaseIncidencia::incidenciasAdjuntas()` (mod_incidencias/clases/ClaseIncidencia.php)
     * compara con `LIKE '%"idReal":"828"%'`, con comillas alrededor del valor; si quien
     * generó `datos` lo codificó con `json_encode(['idReal' => $entero])` en vez de
     * `(string) $entero`, la cadena resultante no lleva esas comillas y el `LIKE` no
     * coincide. El caso real es la respuesta 'abririncidencia' de `tareas.php`, que
     * construye `datos` en PHP con `$idReal` tal como llega de `$_POST` (siempre cadena,
     * asi que ahi no se manifiesta); el riesgo esta en cualquier composicion de `datos`
     * que no pase por POST, incluida la que compone el propio JavaScript del cliente antes
     * de mandarlo (`JSON.stringify` con un numero no lleva comillas). Corrección propuesta:
     * comparar sobre el valor decodificado (`JSON_EXTRACT` o filtrar en PHP tras decodificar
     * `datos`), no sobre el texto crudo del JSON. Evidencia: este test, en rojo mientras el
     * defecto siga sin corregirse por CC.
     */
    public function test_defecto_noEncuentraLaIncidenciaSiIdRealSeGuardoComoNumeroEnElJson(): void
    {
        $this->siembra->incidencia(
            'mod_ventas',
            ['vista' => 'albaran', 'idReal' => $this->idAlbaran], // entero, no cadena
            'incidencia con idReal numerico'
        );

        $resultado = CargaAislada::llamar(
            fn () => \incidenciasAdjuntas($this->idAlbaran, 'mod_ventas', $this->db, 'albaran')
        );

        self::assertCount(
            1,
            $resultado['datos'],
            'La incidencia existe y es del documento correcto: deberia encontrarse con independencia de como se tipo idReal al guardarla'
        );
    }
}

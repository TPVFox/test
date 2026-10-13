<?php
/**
 * Lo que el módulo de relaciones sabe de su propia instalación: si sus tres tablas no
 * están, si están a medias, si no son las que el código espera, o si están bien. Y el
 * fichero que las crea: repetible, y sin datos.
 *
 * El código y ese fichero llegan a una instalación por caminos distintos, de modo que el
 * módulo no puede dar por hecho que sus tablas existen.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModRelaciones;

use TPVFox\Test\CasoIntegracion;

final class RelacionesEstructuraIntegracionTest extends CasoIntegracion
{
    private const TABLAS = ['relacionesLineas', 'relaciones', 'relacionesTipos'];

    /**
     * Crear y borrar tablas confirma la transacción en curso sin avisar: estos casos no
     * pueden aislarse deshaciendo, así que dejan la estructura instalada al terminar.
     */
    protected bool $aislarPorTransaccion = false;

    protected bool $compartirConexionConElProducto = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/RelacionesModelo.php');
        $this->quitarLasTablas();
    }

    protected function tearDown(): void
    {
        $this->quitarLasTablas();
        $this->aplicarElFicheroDeInstalacion();
        parent::tearDown();
    }

    public function test_T1_sinNingunaDeLasTresTablasLaEstructuraEstaAusente(): void
    {
        self::assertSame('ausente', $this->estado());
    }

    public function test_T2_siFaltaAlgunaDeLasTresTablasLaEstructuraEstaIncompleta(): void
    {
        $this->aplicarElFicheroDeInstalacion();
        $this->db->query('DROP TABLE relacionesLineas');

        self::assertSame('incompleta', $this->estado());
    }

    public function test_T3_conUnaColumnaDeMenosLaEstructuraEsDeOtraVersion(): void
    {
        $this->aplicarElFicheroDeInstalacion();
        $this->db->query('ALTER TABLE relaciones DROP COLUMN diasViabilidad');

        self::assertSame('otraVersion', $this->estado());
    }

    public function test_T4_conUnaColumnaDeMasLaEstructuraEsDeOtraVersion(): void
    {
        // Un fichero de instalación posterior aplicado sin actualizar el código: las
        // tablas tienen algo que este código no conoce, y operar sobre ellas sería leer y
        // escribir a ciegas.
        $this->aplicarElFicheroDeInstalacion();
        $this->db->query('ALTER TABLE relacionesTipos ADD COLUMN momento VARCHAR(10) NULL');

        self::assertSame('otraVersion', $this->estado());
    }

    public function test_T5_trasInstalarLaEstructuraEstaInstaladaYLasTresTablasVacias(): void
    {
        $this->aplicarElFicheroDeInstalacion();

        self::assertSame('instalada', $this->estado());
        foreach (self::TABLAS as $tabla) {
            $filas = (int) $this->db->query("SELECT COUNT(*) AS n FROM $tabla")->fetch_assoc()['n'];
            self::assertSame(0, $filas, "$tabla llega sin datos");
        }
    }

    public function test_T6_instalarDosVecesDejaElMismoEsquemaQueUna(): void
    {
        $this->aplicarElFicheroDeInstalacion();
        $unaVez = $this->esquema();

        $this->aplicarElFicheroDeInstalacion();

        self::assertSame($unaVez, $this->esquema());
        self::assertSame('instalada', $this->estado());
    }

    public function test_T7_instalarDeNuevoNoTocaLoQueYaHabiaGuardado(): void
    {
        $this->aplicarElFicheroDeInstalacion();
        $this->db->query("INSERT INTO relacionesTipos (nombre, forma) VALUES ('Despiece', '1:N')");

        $this->aplicarElFicheroDeInstalacion();

        $filas = (int) $this->db->query('SELECT COUNT(*) AS n FROM relacionesTipos')->fetch_assoc()['n'];
        self::assertSame(1, $filas);
    }

    public function test_T8_lasLineasNoAdmitenUnArticuloNiUnaRelacionQueNoExistan(): void
    {
        // Las dos claves ajenas son la segunda barrera: aunque el código que escribe se
        // equivocase, la base no guarda una línea que apunte a nada.
        $this->aplicarElFicheroDeInstalacion();

        $this->expectException(\mysqli_sql_exception::class);
        $this->db->query("INSERT INTO relacionesLineas (idRelacion, idArticulo, sentido) VALUES (999999, 999999, 'entrada')");
    }

    public function test_T9_siLaBaseNoSePuedeLeerElFalloSeDevuelveYNoSeLanza(): void
    {
        // Quien pregunta por la instalación es una pantalla: tiene que recibir un aviso que
        // pueda enseñar, no una excepción que la deje en blanco.
        $cerrada = self::conectar($this->ejercicio);
        $cerrada->close();
        $anterior = \ModeloP::usarConexion($cerrada);
        $registro = ini_set('error_log', '/dev/null');

        try {
            $respuesta = (new \RelacionesModelo())->estadoEstructura();
        } finally {
            ini_set('error_log', (string) $registro);
            \ModeloP::usarConexion($anterior);
        }

        self::assertArrayNotHasKey('datos', $respuesta);
        self::assertSame('danger', $respuesta['error']['tipo']);
        self::assertStringContainsString('information_schema', $respuesta['consulta']);
    }

    private function estado(): string
    {
        $respuesta = (new \RelacionesModelo())->estadoEstructura();
        self::assertArrayNotHasKey('error', $respuesta);

        return $respuesta['datos'];
    }

    /** @return array<string,string> La definición de cada tabla, tal como la guarda la base. */
    private function esquema(): array
    {
        $definiciones = [];
        foreach (self::TABLAS as $tabla) {
            $definiciones[$tabla] = $this->db->query("SHOW CREATE TABLE $tabla")->fetch_row()[1];
        }

        return $definiciones;
    }

    private function quitarLasTablas(): void
    {
        foreach (self::TABLAS as $tabla) {
            $this->db->query("DROP TABLE IF EXISTS $tabla");
        }
    }

    private function aplicarElFicheroDeInstalacion(): void
    {
        $sql = file_get_contents(RUTA_TPVFOX . '/BD/Update/install_update_relaciones.sql');
        self::assertNotFalse($sql);

        $this->db->multi_query($sql);
        while ($this->db->more_results()) {
            $this->db->next_result();
        }
    }
}

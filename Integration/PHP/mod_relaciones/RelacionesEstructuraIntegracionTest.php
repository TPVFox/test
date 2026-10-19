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
    // La de temporales primero: así se retiran en un orden que ninguna clave ajena impide.
    private const TABLAS = ['relacionesTemporales', 'relacionesLineas', 'relaciones', 'relacionesTipos'];

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

    /**
     * @comportamiento Si las tablas tienen una columna que este código no conoce —un fichero de
     *   instalación posterior aplicado sin actualizar el código—, el módulo lo declara y no opera:
     *   hacerlo sería leer y escribir a ciegas.
     */
    public function test_T4_conUnaColumnaDeMasLaEstructuraEsDeOtraVersion(): void
    {
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

    /**
     * @comportamiento La base rechaza una línea que apunte a un artículo o a una relación que no
     *   existen. Es la segunda barrera: aunque el código que escribe se equivocase, no queda una
     *   línea que apunte a nada.
     */
    public function test_T8_lasLineasNoAdmitenUnArticuloNiUnaRelacionQueNoExistan(): void
    {
        $this->aplicarElFicheroDeInstalacion();

        $this->expectException(\mysqli_sql_exception::class);
        $this->db->query("INSERT INTO relacionesLineas (idRelacion, idArticulo, sentido) VALUES (999999, 999999, 'entrada')");
    }

    /**
     * @comportamiento La base no deja borrar al usuario que puso a regir una versión. El historial
     *   dice quién lo hizo, y si ese usuario se pudiera borrar quedaría una versión a nombre de nadie.
     */
    public function test_T10_noSePuedeBorrarElUsuarioQueTieneUnaVersionASuNombre(): void
    {
        $this->aplicarElFicheroDeInstalacion();
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('autor_de_relacion', MD5('x'), CURDATE(), 1, 'activo', 'Autor')");
        $idUsuario = (int) $this->db->insert_id;
        $this->db->query("INSERT INTO relacionesTipos (nombre, forma) VALUES ('Despiece', '1:N')");
        $idTipo = (int) $this->db->insert_id;
        $this->db->query("INSERT INTO relaciones (numRelacion, idTipo, version, idUsuario, fechaCreacion, estado) VALUES (1, $idTipo, '1.0', $idUsuario, NOW(), 'Activo')");

        try {
            $this->db->query("DELETE FROM usuarios WHERE id = $idUsuario");
            self::fail('La base dejó borrar al autor de una versión');
        } catch (\mysqli_sql_exception $e) {
            self::assertStringContainsString('foreign key constraint', strtolower($e->getMessage()));
        } finally {
            // Las tablas del módulo las retira tearDown; el usuario hay que quitarlo aquí.
            $this->quitarLasTablas();
            $this->db->query("DELETE FROM usuarios WHERE id = $idUsuario");
        }
    }

    /**
     * @comportamiento Si no se puede leer la base, la comprobación de la instalación devuelve un
     *   aviso en vez de lanzar el fallo. Quien pregunta es una pantalla: necesita algo que pueda
     *   enseñar, no una excepción que la deje en blanco.
     */
    public function test_T9_siLaBaseNoSePuedeLeerElFalloSeDevuelveYNoSeLanza(): void
    {
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

    /**
     * @comportamiento Con el código que espera la tabla de temporales y sin su fichero de
     *   instalación, el módulo declara la estructura incompleta y no opera.
     */
    public function test_T11_conSoloElPrimerFicheroDeInstalacionLaEstructuraEstaIncompleta(): void
    {
        $this->aplicar('install_update_v0.4.4.54.sql');

        self::assertSame('incompleta', $this->estado());
    }

    public function test_T12_conUnaColumnaDeMenosEnLaTablaDeTemporalesLaEstructuraEsDeOtraVersion(): void
    {
        $this->aplicarElFicheroDeInstalacion();
        $this->db->query('ALTER TABLE relacionesTemporales DROP COLUMN origenAjuste');

        self::assertSame('otraVersion', $this->estado());
    }

    /** @comportamiento Aplicar otra vez el fichero de los temporales no borra lo que alguien está preparando. */
    public function test_T13_instalarDeNuevoLaTablaDeTemporalesConservaSusFilas(): void
    {
        $this->aplicarElFicheroDeInstalacion();
        $idUsuario = (int) $this->db->query('SELECT id FROM usuarios ORDER BY id LIMIT 1')->fetch_assoc()['id'];
        $this->db->query("INSERT INTO relacionesTemporales (idTipo, lineas, idUsuario, fechaInicio, fechaModificacion) VALUES (1, '[]', $idUsuario, NOW(), NOW())");
        $unaVez = $this->esquema();

        $this->aplicar('install_update_v0.4.4.55.sql');

        self::assertSame($unaVez, $this->esquema());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) AS n FROM relacionesTemporales')->fetch_assoc()['n']);
    }

    /**
     * @comportamiento La tabla de temporales no depende de las otras tres: con un temporal
     *   guardado, las tablas de la relación se pueden retirar y volver a instalar.
     */
    public function test_T14_lasTablasDeLaRelacionSeRetiranYSeReponenConLaDeTemporalesPresente(): void
    {
        $this->aplicarElFicheroDeInstalacion();
        $idUsuario = (int) $this->db->query('SELECT id FROM usuarios ORDER BY id LIMIT 1')->fetch_assoc()['id'];
        $this->db->query("INSERT INTO relacionesTemporales (numRelacion, idRelacion, idTipo, lineas, idUsuario, fechaInicio, fechaModificacion) VALUES (7, 999999, 999999, '[]', $idUsuario, NOW(), NOW())");

        foreach (['relacionesLineas', 'relaciones', 'relacionesTipos'] as $tabla) {
            $this->db->query("DROP TABLE $tabla");
        }
        $this->aplicar('install_update_v0.4.4.54.sql');

        self::assertSame('instalada', $this->estado());
        self::assertSame(1, (int) $this->db->query('SELECT COUNT(*) AS n FROM relacionesTemporales')->fetch_assoc()['n']);
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

    /** Los dos ficheros de instalación del módulo: las tres tablas de la relación y la de temporales. */
    private function aplicarElFicheroDeInstalacion(): void
    {
        $this->aplicar('install_update_v0.4.4.54.sql');
        $this->aplicar('install_update_v0.4.4.55.sql');
    }

    private function aplicar(string $fichero): void
    {
        $sql = file_get_contents(RUTA_TPVFOX . '/BD/Update/' . $fichero);
        self::assertNotFalse($sql);

        $this->db->multi_query($sql);
        while ($this->db->more_results()) {
            $this->db->next_result();
        }
    }
}

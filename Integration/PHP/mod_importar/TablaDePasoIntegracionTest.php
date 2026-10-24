<?php

/**
 * La tabla de paso de la importación y el registro de cada importación
 * (`modulos/mod_importar/clases/ClaseImportarDbf.php`): registrar el inicio y leer el último, copiar un
 * artículo del fichero a `modulo_importar_ARTICULO`, leer los que faltan por fusionar, marcar su estado,
 * contar por estado, anotar nulos y errores, y borrar la tabla de paso o el registro.
 *
 * `modulo_importar_ARTICULO` se crea y se borra con DDL, que no se deshace con la transacción de la
 * suite; por eso este caso no aísla por transacción: crea la tabla al empezar y la borra al terminar, y
 * limpia los registros que siembra. El registro de importación lleva un nombre único del caso para
 * poder retirarlo sin tocar otros.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModImportar;

use TPVFox\Test\CasoIntegracion;

final class TablaDePasoIntegracionTest extends CasoIntegracion
{
    protected bool $aislarPorTransaccion = false;
    protected bool $compartirConexionConElProducto = true;

    private string $rutaRegistro = '';
    private string $nombreFichero = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/app/helpers.php');
        $this->incluirTPVFox('/clases/ClaseTFModelo.php');
        $this->nombreFichero = 'paso-' . bin2hex(random_bytes(3)) . '.dbf';
        $this->rutaRegistro = sys_get_temp_dir() . '/importar-' . bin2hex(random_bytes(3));
        @mkdir($this->rutaRegistro);
        $this->db->query('DROP TABLE IF EXISTS modulo_importar_ARTICULO');
        $this->db->query(
            'CREATE TABLE modulo_importar_ARTICULO (LINEA varchar(8), CODIGO varchar(20), NOMBRE varchar(100), '
            . 'STOCK varchar(20), CODE_BAR varchar(20), PCOSTE varchar(20), PVENTA varchar(20), PVP varchar(20), '
            . 'BENEFICIO varchar(20), IVA varchar(20), NULO tinyint(1), id_tpvfox int(11), estado_tpvfox varchar(11))'
        );
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS modulo_importar_ARTICULO');
        $this->db->query("DELETE FROM modulo_importar_registro WHERE name = '{$this->nombreFichero}'");
        foreach (glob($this->rutaRegistro . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        @rmdir($this->rutaRegistro);
        parent::tearDown();
    }

    private function clase(): \ImportarDbf
    {
        if (!class_exists('ImportarDbf')) {
            $this->incluirTPVFox('/modulos/mod_importar/clases/ClaseImportarDbf.php');
        }
        $this->registrar('Creado');

        return new \ImportarDbf($this->rutaRegistro);
    }

    /** Siembra un registro de importación del caso y devuelve su id. */
    private function registrar(string $estado, int $originales = 0): int
    {
        $this->db->query(
            "INSERT INTO modulo_importar_registro (name, datos_fichero, token, type, fecha_inicio, estado, Registros_originales, campos) "
            . "VALUES ('{$this->nombreFichero}', '{}', 't', 'application/x-dbf', '2026-10-23 08:00:00', '{$estado}', {$originales}, '[]')"
        );

        return (int) $this->db->insert_id;
    }

    private function filaDePaso(array $cambios = []): array
    {
        return $cambios + [
            'LINEA' => '1', 'CODIGO' => '5001', 'NOMBRE' => 'Sal', 'STOCK' => '7', 'CODE_BAR' => '84001',
            'PCOSTE' => '0.30', 'PVENTA' => '0.50', 'PVP' => '0.55', 'BENEFICIO' => '10', 'IVA' => '10', 'NULO' => 0,
        ];
    }

    /** @comportamiento Registrar una importación devuelve su id, y leer el último registro trae el recién creado. */
    public function test_elRegistroSeGuardaYSeLeeElUltimo(): void
    {
        $importar = $this->clase();
        $id = $importar->registroImportar([
            'name' => $this->nombreFichero, 'datos_fichero' => '{}', 'token' => 't', 'type' => 'x',
            'fecha_inicio' => '2026-10-23 09:00:00', 'estado' => 'Creado', 'Registros_originales' => 3, 'campos' => '[]',
        ]);

        self::assertIsInt($id);
        $ultimo = $importar->ultimoRegistro();
        self::assertSame($id, (int) $ultimo['datos'][0]['id']);
        self::assertSame('3', (string) $ultimo['datos'][0]['Registros_originales']);
    }

    /** @comportamiento Un artículo del fichero se copia a la tabla de paso y se lee entre los que faltan por fusionar. */
    public function test_unArticuloSeCopiaALaTablaDePasoYSeLee(): void
    {
        $importar = $this->clase();

        $importar->insertarDbf('modulo_importar_ARTICULO', $this->filaDePaso());

        $pendientes = $importar->leerTodos_mod_articulo();
        self::assertCount(1, $pendientes);
        self::assertSame('5001', $pendientes[0]['CODIGO']);
        self::assertSame('Sal', $pendientes[0]['NOMBRE']);
    }

    /** @comportamiento Marcar el estado de un artículo de la tabla de paso lo saca de los pendientes y lo cuenta por su estado. */
    public function test_marcarElEstadoSacaDeLosPendientesYCuenta(): void
    {
        $importar = $this->clase();
        $importar->insertarDbf('modulo_importar_ARTICULO', $this->filaDePaso());
        $importar->insertarDbf('modulo_importar_ARTICULO', $this->filaDePaso(['CODIGO' => '5002', 'NOMBRE' => 'Azúcar']));

        $importar->cambioEstadoImportado('5001', 'nuevo');

        self::assertCount(1, $importar->leerTodos_mod_articulo());
        $conteo = $importar->contarRegistrosPorEstado();
        self::assertSame('1', (string) $conteo['nuevo']);
        self::assertSame('1', (string) $conteo['NULL']);
    }

    /** @comportamiento Anotar los nulos y los errores de una importación, y pasar su estado, los deja en el registro. */
    public function test_losNulosLosErroresYElEstadoSeAnotanEnElRegistro(): void
    {
        $importar = $this->clase();
        $id = (int) $importar->ultimoRegistro()['datos'][0]['id'];

        $importar->anhadirNulosErrores($id, 4, 2);
        $importar->cambioEstado($id, 'Importado');

        $registro = $importar->ultimoRegistro()['datos'][0];
        self::assertSame('4', (string) $registro['nulos']);
        self::assertSame('2', (string) $registro['errores']);
        self::assertSame('Importado', $registro['estado']);
    }

    /** @comportamiento Borrar la tabla de paso la quita, y el registro deja de encontrarla. */
    public function test_borrarLaTablaDePasoLaQuita(): void
    {
        $importar = $this->clase();

        $respuesta = $importar->EliminarTabla();

        self::assertSame(0, $respuesta);
        self::assertArrayHasKey('error', $importar->InfoTabla('modulo_importar_ARTICULO'));
    }

    /** @comportamiento Borrar un registro de importación por su id lo quita. */
    public function test_borrarUnRegistroDeImportacionLoQuita(): void
    {
        $importar = $this->clase();
        $id = $this->registrar('Fusionado');

        $afectados = $importar->EliminarRegistroTabla($id);

        self::assertSame(1, $afectados);
    }

    /** @comportamiento Sin ningún proceso en marcha, la comprobación del segundo plano cuenta cero. */
    public function test_sinProcesoEnMarchaElSegundoPlanoCuentaCero(): void
    {
        $importar = $this->clase();

        self::assertSame(0, $importar->comprobarSiEjecutaSegundoplano());
    }
}

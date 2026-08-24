<?php
/**
 * Base de los casos de integracion PHP.
 *
 * Resuelve tres cosas que ningun caso debe repetir: de donde salen las credenciales,
 * la garantia de que la base es de pruebas y no una instalacion en uso, y el aislamiento
 * entre casos.
 */

declare(strict_types=1);

namespace TPVFox\Test;

use mysqli;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class CasoIntegracion extends TestCase
{
    /**
     * Solo se admiten bases cuyo nombre empiece por este prefijo.
     *
     * Un mismo motor puede alojar bases que no son de pruebas. Sin esta comprobacion,
     * una variable de entorno mal puesta bastaria para que la suite sembrase datos
     * sobre una de ellas.
     */
    private const PREFIJO_ADMITIDO = 'tpvfox_test';

    /** Papel del ejercicio sobre el que corre el caso: 'vigente' o 'anterior'. */
    protected string $ejercicio = 'vigente';

    /**
     * Aislamiento por transaccion.
     *
     * Los casos que ejerciten la apertura de transaccion del propio sistema lo ponen a
     * false y limpian lo que siembren: en MySQL y MariaDB un START TRANSACTION dentro de
     * otro confirma el anterior de forma implicita, de modo que la transaccion de la
     * suite dejaria de aislar sin avisar.
     */
    protected bool $aislarPorTransaccion = true;

    protected ?mysqli $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = self::conectar($this->ejercicio);

        if ($this->aislarPorTransaccion) {
            $this->db->begin_transaction();
        }
    }

    protected function tearDown(): void
    {
        if ($this->db instanceof mysqli) {
            if ($this->aislarPorTransaccion) {
                $this->db->rollback();
            }
            $this->db->close();
            $this->db = null;
        }
        parent::tearDown();
    }

    /**
     * Abre la conexion contra la base del papel indicado.
     *
     * @param string $papel 'vigente' o 'anterior'
     */
    protected static function conectar(string $papel): mysqli
    {
        $variable = $papel === 'anterior' ? 'TPVFOX_TEST_DB_ANTERIOR' : 'TPVFOX_TEST_DB_VIGENTE';
        $base = Entorno::valor($variable);

        if ($base === '') {
            throw new RuntimeException(
                "Falta $variable. Las pruebas de integracion no adivinan la base contra la " .
                "que corren: se declara en test/.env o en el entorno."
            );
        }

        if (strpos($base, self::PREFIJO_ADMITIDO) !== 0) {
            throw new RuntimeException(
                "La base «{$base}» no empieza por «" . self::PREFIJO_ADMITIDO . "». La suite no " .
                "corre contra bases que no sean de pruebas."
            );
        }

        $db = @new mysqli(
            Entorno::valor('TPVFOX_TEST_DB_HOST', 'localhost'),
            Entorno::valor('TPVFOX_TEST_DB_USER'),
            Entorno::valor('TPVFOX_TEST_DB_PASS'),
            $base
        );

        if ($db->connect_errno) {
            throw new RuntimeException("No se pudo conectar con «{$base}»: error {$db->connect_errno}.");
        }

        $db->set_charset('utf8mb4');

        return $db;
    }

    /**
     * Incluye un fichero de TPVFox resolviendo las variables globales que su
     * convencion de includes exige (RutaServidor, HostNombre, URLCom), definidas una
     * vez en bootstrap.php. TPVFox no tiene autocarga: cada fichero las necesita ya
     * puestas en el ambito desde el que se incluye.
     */
    protected function incluirTPVFox(string $rutaRelativa): void
    {
        global $RutaServidor, $HostNombre, $URLCom;
        require_once RUTA_TPVFOX . $rutaRelativa;
    }
}

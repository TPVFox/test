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
use ReflectionProperty;
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

    /**
     * Si es true, el codigo bajo prueba —cualquier clase que herede de TFModelo, via
     * ModeloP::getDbo()— usa esta misma conexion en vez de abrir la suya propia. Sin
     * esto, lo que Siembra inserta en esta conexion es invisible para el codigo bajo
     * prueba hasta el commit, y el commit nunca llega porque el aislamiento es por
     * ROLLBACK.
     *
     * No lo activan los casos que ademas ejercitan que el propio codigo bajo prueba
     * abre su propia transaccion de solo lectura: un START TRANSACTION dentro de otro
     * confirma el anterior de forma implicita, y el aislamiento por ROLLBACK dejaria
     * de proteger.
     */
    protected bool $compartirConexionConElProducto = false;

    protected ?mysqli $db = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = self::conectar($this->ejercicio);

        if ($this->aislarPorTransaccion) {
            $this->db->begin_transaction();
        }

        if ($this->compartirConexionConElProducto) {
            $this->fijarConexionDelProducto($this->db);
        }
    }

    protected function tearDown(): void
    {
        if ($this->compartirConexionConElProducto) {
            $this->fijarConexionDelProducto(null);
        }

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
     * Inyecta (o libera, con null) la conexion que usara ModeloP::getDbo() a partir de
     * ahora, sobre la propiedad estatica que TPVFox comparte entre todas las clases
     * que heredan de TFModelo. Incluye claseModeloP.php si todavia no estaba cargada.
     */
    private function fijarConexionDelProducto(?mysqli $conexion): void
    {
        if (!class_exists('ModeloP')) {
            $this->incluirTPVFox('/modulos/claseModeloP.php');
        }

        $propiedad = new ReflectionProperty('ModeloP', 'db');
        $propiedad->setAccessible(true);
        $propiedad->setValue(null, $conexion);
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

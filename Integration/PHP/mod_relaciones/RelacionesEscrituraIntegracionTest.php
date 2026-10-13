<?php
/**
 * Cómo escribe el módulo de relaciones: los valores viajan aparte de la sentencia, una
 * escritura de varios pasos queda entera o no queda, y el fallo llega siempre a quien la
 * pidió como un aviso, nunca como una excepción ni como texto en pantalla.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModRelaciones;

use TPVFox\Test\CasoIntegracion;

final class RelacionesEscrituraIntegracionTest extends CasoIntegracion
{
    /**
     * Lo que se prueba es que el propio módulo abre y cierra su transacción. Dentro de la
     * de la suite, esa apertura la confirmaría sin avisar: estos casos limpian lo que escriben.
     */
    protected bool $aislarPorTransaccion = false;

    protected bool $compartirConexionConElProducto = true;

    private string|false $registroDeErrores = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_relaciones/clases/RelacionesModelo.php');
        // Cada fallo provocado se apunta en el registro del servidor, que en una prueba es
        // la propia salida.
        $this->registroDeErrores = ini_set('error_log', '/dev/null');
        $this->limpiar();
    }

    protected function tearDown(): void
    {
        $this->limpiar();
        ini_set('error_log', (string) $this->registroDeErrores);
        parent::tearDown();
    }

    public function test_T1_unaLecturaDevuelveLasFilasQueCasanConElValorRecibido(): void
    {
        $modelo = $this->modelo();
        $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba despiece']);
        $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, 'N:1')", ['prueba elaboracion']);

        $respuesta = $modelo->consultar('SELECT nombre, forma FROM relacionesTipos WHERE nombre = ?', ['prueba despiece']);

        self::assertSame([['nombre' => 'prueba despiece', 'forma' => '1:N']], $respuesta['datos']);
    }

    public function test_T2_unValorQueIntentaCerrarLaSentenciaSeGuardaComoTextoYNoLaAltera(): void
    {
        $modelo = $this->modelo();
        $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:1')", ['prueba cruce']);
        $hostil = "prueba' OR '1'='1";

        $alLeer = $modelo->consultar('SELECT nombre FROM relacionesTipos WHERE nombre = ?', [$hostil]);
        $alEscribir = $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:1')", [$hostil]);
        $guardado = $modelo->consultar('SELECT nombre FROM relacionesTipos WHERE id = ?', [$alEscribir['datos']['idInsertado']]);

        self::assertSame([], $alLeer['datos'], 'El valor no amplía lo que la lectura devuelve');
        self::assertSame($hostil, $guardado['datos'][0]['nombre']);
    }

    public function test_T3_unaEscrituraDevuelveCuantasFilasAfectoYElIdentificadorCreado(): void
    {
        $modelo = $this->modelo();

        $alta = $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba alta']);
        $cambio = $modelo->consultar('UPDATE relacionesTipos SET llevaCantidad = ? WHERE id = ?', [0, $alta['datos']['idInsertado']]);
        $sinEfecto = $modelo->consultar('UPDATE relacionesTipos SET llevaCantidad = ? WHERE id = ?', [0, 0]);

        self::assertSame(1, $alta['datos']['afectadas']);
        self::assertGreaterThan(0, $alta['datos']['idInsertado']);
        self::assertSame(1, $cambio['datos']['afectadas']);
        self::assertSame(0, $sinEfecto['datos']['afectadas']);
    }

    public function test_T4_unFalloDeLaBaseSeDevuelveConSuAvisoYSuSentenciaSinLanzarNiImprimir(): void
    {
        $this->expectOutputString('');
        $sentencia = 'SELECT * FROM tablaQueNoExiste WHERE id = ?';

        $respuesta = $this->modelo()->consultar($sentencia, [1]);

        self::assertArrayNotHasKey('datos', $respuesta);
        self::assertSame('danger', $respuesta['error']['tipo']);
        self::assertSame($sentencia, $respuesta['consulta']);
    }

    public function test_T5_unaEscrituraDeVariosPasosQueTerminaBienQuedaGuardadaEntera(): void
    {
        $modelo = $this->modelo();

        $respuesta = $modelo->transaccion(function () use ($modelo) {
            $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba uno']);

            return $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, 'N:1')", ['prueba dos']);
        });

        self::assertArrayHasKey('datos', $respuesta);
        self::assertSame(2, $this->filasDePrueba());
    }

    public function test_T6_siUnPasoLanzaUnFalloNoQuedaNadaDeLosPasosAnteriores(): void
    {
        $modelo = $this->modelo();
        $modelo->fallarEnLaConsulta = 2;

        $respuesta = $modelo->transaccion(function () use ($modelo) {
            $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba uno']);

            return $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, 'N:1')", ['prueba dos']);
        });

        self::assertSame('danger', $respuesta['error']['tipo']);
        self::assertSame(0, $this->filasDePrueba());
    }

    public function test_T7_siUnPasoDevuelveUnErrorSinLanzarloTampocoQuedaNada(): void
    {
        $modelo = $this->modelo();

        $respuesta = $modelo->transaccion(function () use ($modelo) {
            $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba uno']);

            // La forma no es ninguna de las tres: la base la rechaza y la respuesta lo dice.
            return $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, ?)", ['prueba dos', '2:2']);
        });

        self::assertArrayHasKey('error', $respuesta);
        self::assertSame(0, $this->filasDePrueba());
    }

    /**
     * @comportamiento Una operación que se ejecuta dentro de otra no confirma lo suyo por su cuenta:
     *   si la de fuera falla después, lo que hizo la de dentro tampoco queda. Es lo que permite que un
     *   alta directa sea «preparar» y «poner a regir» sin que pueda quedar a medias.
     */
    public function test_T8_unaOperacionDentroDeOtraNoConfirmaPorSuCuenta(): void
    {
        $modelo = $this->modelo();
        $modelo->fallarEnLaConsulta = 2;

        $respuesta = $modelo->transaccion(function () use ($modelo) {
            // La de dentro termina bien…
            $modelo->transaccion(fn() => $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba dentro']));

            // …y la de fuera falla después: lo de dentro tampoco puede quedar.
            return $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, 'N:1')", ['prueba fuera']);
        });

        self::assertArrayHasKey('error', $respuesta);
        self::assertSame(0, $this->filasDePrueba());
    }

    public function test_T9_unaOperacionDentroDeOtraQuedaGuardadaCuandoLaDeFueraTerminaBien(): void
    {
        $modelo = $this->modelo();

        $respuesta = $modelo->transaccion(function () use ($modelo) {
            $modelo->transaccion(fn() => $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba dentro']));

            return $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, 'N:1')", ['prueba fuera']);
        });

        self::assertArrayHasKey('datos', $respuesta);
        self::assertSame(2, $this->filasDePrueba());
    }

    /**
     * @comportamiento Si falla una operación de dentro y la de fuera no mira su respuesta y contesta
     *   que todo fue bien, la escritura se deshace igual y la respuesta final es un fallo. Sin esto se
     *   confirmaría una escritura con un paso sin hacer.
     */
    public function test_T10_elFalloDeLaOperacionDeDentroDeshaceTodoAunqueLaDeFueraRespondaBien(): void
    {
        $modelo = $this->modelo();
        $modelo->fallarEnLaConsulta = 2;

        $respuesta = $modelo->transaccion(function () use ($modelo) {
            $primera = $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba fuera']);
            // La de dentro falla y la de fuera no mira su respuesta.
            $modelo->transaccion(fn() => $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, 'N:1')", ['prueba dentro']));

            return $primera;
        });

        self::assertArrayHasKey('error', $respuesta, 'Una escritura con un paso fallido no puede darse por buena');
        self::assertSame(0, $this->filasDePrueba());
    }

    public function test_T11_trasUnaEscrituraFallidaLaSiguienteVuelveAConfirmar(): void
    {
        $modelo = $this->modelo();
        $modelo->fallarEnLaConsulta = 1;
        $modelo->transaccion(fn() => $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba fallida']));

        $respuesta = $modelo->transaccion(fn() => $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, '1:N')", ['prueba siguiente']));

        // Se cuenta por otra conexión: lo que esta viera sin confirmar no demostraría nada.
        $otra = self::conectar($this->ejercicio);
        $confirmadas = (int) $otra->query("SELECT COUNT(*) AS n FROM relacionesTipos WHERE nombre = 'prueba siguiente'")->fetch_assoc()['n'];
        $otra->close();

        self::assertArrayHasKey('datos', $respuesta);
        self::assertSame(1, $confirmadas);
    }

    /**
     * @comportamiento El fallo de la base se devuelve como aviso también en un servidor que no lanza
     *   excepciones. TPVFox no fija cómo avisa la base de un fallo: según el servidor llega como
     *   excepción o como un «falso», y los dos acaban en la misma respuesta.
     */
    public function test_T12_elFalloSeDevuelveIgualCuandoLaInstalacionNoLanzaLosErroresDeLaBase(): void
    {
        $modelo = $this->modelo();
        mysqli_report(MYSQLI_REPORT_OFF);

        try {
            $alPreparar = $modelo->consultar('SELECT * FROM tablaQueNoExiste WHERE id = ?', [1]);
            $alEjecutar = $modelo->consultar("INSERT INTO relacionesTipos (nombre, forma) VALUES (?, ?)", ['prueba forma', '2:2']);
        } finally {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        }

        self::assertArrayHasKey('error', $alPreparar);
        self::assertArrayHasKey('error', $alEjecutar);
        self::assertSame(0, $this->filasDePrueba());
    }

    public function test_T13_siLaEscrituraNoPuedeNiEmpezarElFalloSeDevuelve(): void
    {
        $modelo = $this->modelo();
        $cerrada = self::conectar($this->ejercicio);
        $cerrada->close();
        $anterior = \ModeloP::usarConexion($cerrada);

        try {
            $respuesta = $modelo->transaccion(fn() => ['datos' => 'no debería llegar aquí']);
        } finally {
            \ModeloP::usarConexion($anterior);
        }

        self::assertSame('danger', $respuesta['error']['tipo']);
    }

    /**
     * La clase del módulo con sus dos operaciones internas a la vista, y la posibilidad de
     * hacer fallar la consulta que se le indique. El código del producto no lleva nada
     * pensado para pruebas: el fallo se provoca desde aquí.
     */
    private function modelo(): object
    {
        return new class extends \RelacionesModelo {
            public int $fallarEnLaConsulta = 0;
            private int $consultas = 0;

            public function consultar(string $sql, array $valores = []): array
            {
                return $this->consultaPreparada($sql, $valores);
            }

            public function transaccion(callable $pasos): array
            {
                return $this->enTransaccion($pasos);
            }

            protected function consultaPreparada($sql, $valores = array())
            {
                if (++$this->consultas === $this->fallarEnLaConsulta) {
                    throw new \RuntimeException('Fallo provocado por la prueba');
                }

                return parent::consultaPreparada($sql, $valores);
            }
        };
    }

    private function filasDePrueba(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS n FROM relacionesTipos WHERE nombre LIKE 'prueba%'")->fetch_assoc()['n'];
    }

    private function limpiar(): void
    {
        $this->db->query("DELETE FROM relacionesTipos WHERE nombre LIKE 'prueba%'");
    }
}

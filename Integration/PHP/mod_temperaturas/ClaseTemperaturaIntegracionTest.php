<?php

/**
 * La clase de las temperaturas (`modulos/mod_temperaturas/clases/ClaseTemperatura.php`): el alta, la
 * modificación y la lectura de los dispositivos, sus estados y sus estadísticas, y el registro y la
 * lectura de las temperaturas.
 *
 * La clase hereda de la clase base de acceso a datos: el caso le presta su conexión, para que vea lo
 * que siembra y su escritura se deshaga al terminar. La siembra de la suite no tiene dispositivos ni
 * temperaturas: cada caso da de alta los suyos.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModTemperaturas;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ClaseTemperaturaIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private int $idUsuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_temperaturas/clases/ClaseTemperatura.php');
        $this->idUsuario = (new Siembra($this->db))->usuarioPorDefecto();
    }

    /**
     * @comportamiento Un dispositivo se da de alta con su nombre, su ubicación, su estado y su máximo, y se
     *   lee por su número y en la lista de todos; sus estadísticas quedan vacías. El alta devuelve la
     *   respuesta de la clase base, con su clave de error a cero.
     */
    public function test_unDispositivoSeDaDeAltaYSeLee(): void
    {
        self::assertSame('0', $this->temperaturas()->addDispositivo($this->datos('Cámara de carne', 'Obrador', 'activo', '4'))['error']);
        $id = (int) $this->db->insert_id;

        $leido = $this->temperaturas()->getDispositivo($id);

        self::assertSame(['Cámara de carne', 'Obrador', 'activo', '4.00', null, null, null],
            [$leido['nombre'], $leido['ubicacion'], $leido['estado'], $leido['temp_max'], $leido['media'], $leido['sd'], $leido['temp_min']]);
        self::assertContains((string) $id, array_column($this->temperaturas()->getDispositivos(), 'idDispositivo'));
    }

    /** @comportamiento Un dispositivo se modifica entero: nombre, ubicación, estado y máximo. */
    public function test_unDispositivoSeModifica(): void
    {
        $id = $this->dispositivo('Congelador', '-18');

        self::assertSame('0', $this->temperaturas()->updateDispositivo($id, $this->datos('Congelador 2', 'Almacén', 'inactivo', '-20'))['error']);

        $leido = $this->temperaturas()->getDispositivo($id);
        self::assertSame(['Congelador 2', 'Almacén', 'inactivo', '-20.00'], [$leido['nombre'], $leido['ubicacion'], $leido['estado'], $leido['temp_max']]);
    }

    /** @comportamiento Los estados de un dispositivo son los que admite la tabla: activo, inactivo y automático. */
    public function test_losEstadosSonLosDeLaTabla(): void
    {
        self::assertSame(['datos' => ['activo', 'inactivo', 'automatico']], $this->temperaturas()->getEstadosDispositivos());
    }

    /**
     * @comportamiento Las estadísticas de un dispositivo —media, desviación y mínimo— se guardan con dos
     *   decimales; la respuesta lleva la clave de error de la clase base, a cero.
     */
    public function test_lasEstadisticasSeGuardanConDosDecimales(): void
    {
        $id = $this->dispositivo('Vitrina', '8');

        self::assertSame(['error' => '0'], $this->temperaturas()->updateDispositivoEstadisticas(['idDispositivo' => $id, 'media' => 5.126, 'sd' => 0.5, 'temp_min' => 5.5]));

        $leido = $this->temperaturas()->getDispositivo($id);
        self::assertSame(['5.13', '0.50', '5.50'], [$leido['media'], $leido['sd'], $leido['temp_min']]);
    }

    /**
     * @comportamiento Las temperaturas se anotan de varios dispositivos a la vez, cada una con quien la anota
     *   y la hora de la base, y se leen todas o las de un dispositivo, de la más antigua a la más reciente.
     */
    public function test_lasTemperaturasSeAnotanYSeLeen(): void
    {
        $camara = $this->dispositivo('Cámara', '4');
        $congelador = $this->dispositivo('Congelador', '-18');
        $this->db->query("INSERT INTO temperaturas (idDispositivo, temperatura, idUsuario, fechaRegistro) VALUES ({$camara}, 2.5, {$this->idUsuario}, '2026-01-01 08:00:00')");

        $this->temperaturas()->addTemperaturas([
            ['idDispositivo' => $camara, 'temperatura' => 3.4, 'idUsuario' => $this->idUsuario],
            ['idDispositivo' => $congelador, 'temperatura' => -19.5, 'idUsuario' => $this->idUsuario],
        ]);

        $deLaCamara = $this->temperaturas()->getTemperaturasDispositivo($camara)['datos'];
        self::assertSame(['2.50', '3.40'], array_column($deLaCamara, 'temperatura'));
        self::assertSame((string) $this->idUsuario, $deLaCamara[1]['idUsuario']);
        $ahora = (string) $this->db->query('SELECT NOW()')->fetch_row()[0];
        self::assertEqualsWithDelta(strtotime($ahora), strtotime($deLaCamara[1]['fechaRegistro']), 120);
        self::assertContains('-19.50', array_column($this->temperaturas()->getTemperaturas(), 'temperatura'));
    }

    /** @comportamiento Un dispositivo sin temperaturas devuelve una lista vacía y el aviso de que no tiene registros. */
    public function test_unDispositivoSinTemperaturasLoAvisa(): void
    {
        $id = $this->dispositivo('Cámara', '4');

        self::assertSame(['error' => 'No se encontraron registros para el dispositivo especificado.', 'datos' => []],
            $this->temperaturas()->getTemperaturasDispositivo($id));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/clases/ClaseTemperatura.php:14-19
     * @codigo-afectado modulos/mod_temperaturas/clases/ClaseTemperatura.php:28-33
     *
     * @group defecto
     * @group entrada
     * @group medio
     *
     * @que-ocurre-hoy Un nombre o una ubicación con un apóstrofo —«Cámara d'Ou»— no se guarda: la base
     *   rechaza la sentencia, al dar de alta y al modificar el dispositivo.
     * @que-deberia-ocurrir Que el dispositivo se guarde con el nombre y la ubicación tal como se escribieron.
     * @por-que-ocurre El alta y la modificación pegan el nombre, la ubicación y el estado a la sentencia sin
     *   escaparlos.
     * @como-deberia-funcionar Pasar los valores a la sentencia como datos, con su escape o una consulta
     *   preparada.
     */
    public function test_unNombreConApostrofoSeGuarda(): void
    {
        $id = $this->dispositivo('Cámara', '4');
        $datos = $this->datos("Cámara d'Ou", "Almacén d'abaixo", 'activo', '4');

        $alta = $this->intentar(fn () => $this->temperaturas()->addDispositivo($datos));
        $modificacion = $this->intentar(fn () => $this->temperaturas()->updateDispositivo($id, $datos));

        self::assertIsArray($alta);
        self::assertIsArray($modificacion);
        self::assertSame("Cámara d'Ou", $this->temperaturas()->getDispositivo($id)['nombre']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/clases/ClaseTemperatura.php:47
     * @codigo-afectado modulos/mod_temperaturas/clases/ClaseTemperatura.php:57
     * @codigo-afectado modulos/mod_temperaturas/clases/ClaseTemperatura.php:80
     *
     * @group defecto
     * @group listado
     * @group bajo
     *
     * @que-ocurre-hoy Sin dispositivos o sin temperaturas, o con un dispositivo que no existe, las lecturas
     *   de la clase dan avisos de PHP y devuelven nulo en lugar de una lista vacía o de decir que no
     *   existe.
     * @que-deberia-ocurrir Que una lectura sin filas devuelva una lista vacía, y la de un dispositivo que no
     *   existe, que no existe.
     * @por-que-ocurre La clase base no trae la lista de datos cuando la consulta no tiene filas, y las tres
     *   lecturas toman esa lista sin mirar si está.
     * @como-deberia-funcionar Devolver una lista vacía, o el aviso, cuando la consulta no trae filas.
     */
    public function test_unaLecturaSinFilasNoDaAvisos(): void
    {
        $this->db->query('DELETE FROM temperaturas');
        $this->db->query('DELETE FROM dispositivos');
        $avisos = [];
        set_error_handler(function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;
            return true;
        });

        try {
            $dispositivos = $this->temperaturas()->getDispositivos();
            $temperaturas = $this->temperaturas()->getTemperaturas();
            $this->temperaturas()->getDispositivo(999999);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $avisos);
        self::assertSame([], $dispositivos);
        self::assertSame([], $temperaturas);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/clases/ClaseTemperatura.php:60-71
     *
     * @group defecto
     * @group guardado
     * @group alto
     *
     * @que-ocurre-hoy Si la base rechaza una de las temperaturas que se anotan —la de un dispositivo que no
     *   existe, una fuera del rango de la columna o una sin usuario—, la anotación termina con una
     *   excepción, y las anotadas antes en la misma llamada quedan guardadas.
     * @que-deberia-ocurrir Que la anotación diga cuáles no se guardaron, sin terminar, y que las de una
     *   llamada se guarden todas o ninguna.
     * @por-que-ocurre La clase anota cada temperatura con su propia sentencia, sin transacción, y no recoge
     *   el rechazo de la base, que llega como excepción.
     * @como-deberia-funcionar Anotar las de una llamada en una transacción, recoger el rechazo y devolverlo
     *   como error.
     */
    public function test_unaTemperaturaRechazadaSeDevuelveComoErrorSinGuardarLasDemas(): void
    {
        $camara = $this->dispositivo('Cámara', '4');

        $respuesta = $this->intentar(fn () => $this->temperaturas()->addTemperaturas([
            ['idDispositivo' => $camara, 'temperatura' => 3.4, 'idUsuario' => $this->idUsuario],
            ['idDispositivo' => 999999, 'temperatura' => 3.5, 'idUsuario' => $this->idUsuario],
        ]));

        self::assertIsArray($respuesta);
        self::assertArrayHasKey('error', $respuesta);
        self::assertSame([], $this->temperaturas()->getTemperaturasDispositivo($camara)['datos']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/clases/ClaseTemperatura.php:60-71
     *
     * @group defecto
     * @group guardado
     * @group alto
     *
     * @que-ocurre-hoy Una temperatura fuera del rango de la columna —mil grados, que la caja del navegador no
     *   deja teclear pero el formulario puede enviar— la rechaza la base: la anotación termina con una
     *   excepción, y las anotadas antes en la misma llamada quedan guardadas.
     * @que-deberia-ocurrir Que la anotación diga cuáles no se guardaron, sin terminar, y que las de una
     *   llamada se guarden todas o ninguna.
     * @por-que-ocurre La clase anota cada temperatura con su propia sentencia, sin transacción, y no recoge
     *   el rechazo de la base, que llega como excepción.
     * @como-deberia-funcionar Anotar las de una llamada en una transacción, recoger el rechazo y devolverlo
     *   como error.
     */
    public function test_unaTemperaturaFueraDelRangoDeLaColumnaSeDevuelveComoError(): void
    {
        $camara = $this->dispositivo('Cámara', '4');
        $vitrina = $this->dispositivo('Vitrina', '8');

        $respuesta = $this->intentar(fn () => $this->temperaturas()->addTemperaturas([
            ['idDispositivo' => $camara, 'temperatura' => 3.4, 'idUsuario' => $this->idUsuario],
            ['idDispositivo' => $vitrina, 'temperatura' => 1000, 'idUsuario' => $this->idUsuario],
        ]));

        self::assertIsArray($respuesta);
        self::assertArrayHasKey('error', $respuesta);
        self::assertSame([], $this->temperaturas()->getTemperaturasDispositivo($camara)['datos']);
    }

    private function temperaturas(): \ClaseTemperatura
    {
        return new \ClaseTemperatura($this->db);
    }

    /** @return array<string, string> */
    private function datos(string $nombre, string $ubicacion, string $estado, string $maximo): array
    {
        return ['nombre' => $nombre, 'ubicacion' => $ubicacion, 'estado' => $estado, 'temp_max' => $maximo];
    }

    private function dispositivo(string $nombre, string $maximo): int
    {
        $this->db->query("INSERT INTO dispositivos (nombre, ubicacion, estado, temp_max) VALUES ('{$nombre}', 'Tienda', 'activo', {$maximo})");

        return (int) $this->db->insert_id;
    }

    /** Lo que devuelve la llamada, o la excepción que lanza. */
    private function intentar(callable $llamada): mixed
    {
        try {
            return $llamada();
        } catch (\Throwable $excepcion) {
            return $excepcion;
        }
    }
}

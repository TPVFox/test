<?php

/**
 * Las pantallas de las temperaturas: la del módulo (`temperatura.php`), con la lista de dispositivos, el
 * histórico, la ficha de un dispositivo y la configuración de los parámetros (`vistas/`), y el análisis
 * de las lecturas de un dispositivo (`temperaturasListado.php`). Las dos atienden ellas mismas los
 * formularios que envían.
 *
 * Cada una se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de pruebas o
 * sin ninguna, todos los permisos del módulo concedidos salvo los que el caso quite, y una transacción que
 * se deshace al terminar. Los dispositivos que siembra el caso quedan con un nombre —`:d1`, `:d2`…— para
 * la dirección y el formulario; al terminar, el proceso escribe lo que responden las consultas del caso.
 *
 * Los parámetros del módulo se guardan en la copia de `cache/` de su carpeta, que git no sigue: el caso la
 * guarda antes y la repone al terminar, y escribe la suya cuando necesita otros valores.
 *
 * Tras atender un formulario, las pantallas mandan a sí mismas y terminan: el proceso no recoge la
 * cabecera de la redirección, y el caso mira lo que escriben antes y lo que queda en la base.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModTemperaturas;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasTemperaturasIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    private int $idUsuario;

    private int $idTienda;

    private ?string $parametrosAntes = null;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $copia = $this->copiaDeParametros();
        $this->parametrosAntes = is_file($copia) ? (string) file_get_contents($copia) : null;
        if (is_file($copia)) {
            unlink($copia);
        }
    }

    protected function tearDown(): void
    {
        $copia = $this->copiaDeParametros();
        if ($this->parametrosAntes === null) {
            if (is_file($copia)) {
                unlink($copia);
            }
        } else {
            file_put_contents($copia, $this->parametrosAntes);
        }
        parent::tearDown();
    }

    /**
     * @comportamiento La pantalla enseña cada dispositivo con su ubicación, su estado, su última lectura y
     *   la caja de la nueva; los automáticos, el aviso de que los anota una aplicación externa, sin caja.
     *   Debajo, el histórico de las lecturas con el dispositivo y su ubicación, el valor y la fecha.
     */
    public function test_laPantallaEnsenaLosDispositivosConSuUltimaLecturaYElHistorico(): void
    {
        $pantalla = $this->pintar('temperatura.php', [], [], [
            $this->dispositivo('Cámara', 'activo', 4),
            $this->lectura(2.5, '2026-10-20 08:00:00'),
            $this->lectura(3.4, '2026-10-21 08:00:00'),
            $this->dispositivo('Sonda', 'automatico', 4),
        ]);
        $camara = $pantalla['ids'][':d1'];
        $sonda = $pantalla['ids'][':d2'];

        self::assertStringContainsString('<td>Cámara</td><td>Obrador</td><td>activo</td><td>2026-10-21 08:00:00</td><td>3.40 °C</td>', $pantalla['html']);
        self::assertStringContainsString("name='temperatura[{$camara}]'", $pantalla['html']);
        self::assertStringContainsString("<td>Sonda</td><td>Obrador</td><td>automatico</td><td colspan='4'><i>El registro automático se realiza mediante una aplicación externa.</i></td>", $pantalla['html']);
        self::assertStringNotContainsString("name='temperatura[{$sonda}]'", $pantalla['html']);
        self::assertStringContainsString('<td>Cámara (Obrador)</td><td>2.50 °C</td><td>2026-10-20 08:00:00</td>', $pantalla['html']);
        self::assertStringContainsString('Actualizar Temperaturas', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /** @comportamiento Un dispositivo se da de alta desde su ficha con su nombre, su ubicación, su estado y su máximo. */
    public function test_unDispositivoSeDaDeAlta(): void
    {
        $alta = $this->pintar('temperatura.php', [], $this->ficha('Vitrina', 'Tienda', 'inactivo', '8'), [], [
            'guardado' => "SELECT CONCAT_WS('|', nombre, ubicacion, estado, temp_max) FROM dispositivos WHERE nombre = 'Vitrina'",
        ]);

        self::assertSame('Vitrina|Tienda|inactivo|8.00', $alta['consultas']['guardado']);
    }

    /** @comportamiento Un dispositivo se modifica desde su ficha, y la pantalla lo confirma. */
    public function test_unDispositivoSeModifica(): void
    {
        $modificacion = $this->pintar('temperatura.php', [], ['action' => 'update_dispositivo', 'idDispositivo' => ':d1'] + $this->ficha('Cámara 2', 'Almacén', 'inactivo', '6'), [
            $this->dispositivo('Cámara', 'activo', 4),
        ], ['guardado' => "SELECT CONCAT_WS('|', nombre, ubicacion, estado, temp_max) FROM dispositivos WHERE idDispositivo = :d1"]);

        self::assertSame('Cámara 2|Almacén|inactivo|6.00', $modificacion['consultas']['guardado']);
        self::assertStringContainsString('Dispositivo actualizado correctamente.', $modificacion['html']);
    }

    /**
     * @comportamiento Actualizar las temperaturas anota las cajas rellenas, cada una con el usuario de la
     *   sesión, y deja sin lectura los dispositivos de caja vacía.
     */
    public function test_seAnotanLasTemperaturasRellenas(): void
    {
        $anotacion = $this->pintar('temperatura.php', [], ['action' => 'update_temperaturas', 'temperatura' => [':d1' => '3.4', ':d2' => '']], [
            $this->dispositivo('Cámara', 'activo', 4),
            $this->dispositivo('Vitrina', 'activo', 8),
        ], [
            'camara' => "SELECT CONCAT_WS('|', temperatura, idUsuario) FROM temperaturas WHERE idDispositivo = :d1",
            'vitrina' => 'SELECT COUNT(*) FROM temperaturas WHERE idDispositivo = :d2',
        ]);

        self::assertSame('3.40|' . $this->idUsuario, $anotacion['consultas']['camara']);
        self::assertSame('0', $anotacion['consultas']['vitrina']);
        self::assertStringContainsString('Temperaturas actualizadas correctamente.', $anotacion['html']);
    }

    /**
     * @comportamiento Con el forzado de los congeladores, una lectura positiva mayor que el máximo de un
     *   dispositivo de máximo negativo se anota con el signo cambiado; una menor, o la de un dispositivo de
     *   máximo positivo, tal cual.
     */
    public function test_conElForzadoDeCongeladoresSeCambiaElSigno(): void
    {
        $this->guardarParametros(['forzar_registro_congeladores' => 'Si']);

        $anotacion = $this->pintar('temperatura.php', [], ['action' => 'update_temperaturas', 'temperatura' => [':d1' => '20', ':d2' => '5', ':d3' => '20']], [
            $this->dispositivo('Congelador', 'activo', -18),
            $this->dispositivo('Arcón', 'activo', -18),
            $this->dispositivo('Vitrina', 'activo', 8),
        ], [
            'congelador' => 'SELECT temperatura FROM temperaturas WHERE idDispositivo = :d1',
            'arcon' => 'SELECT temperatura FROM temperaturas WHERE idDispositivo = :d2',
            'vitrina' => 'SELECT temperatura FROM temperaturas WHERE idDispositivo = :d3',
        ]);

        self::assertSame(['congelador' => '-20.00', 'arcon' => '5.00', 'vitrina' => '20.00'], $anotacion['consultas']);
    }

    /**
     * @comportamiento La ficha de alta ofrece los estados de la tabla; la de edición trae los datos del
     *   dispositivo y enseña su media, su desviación y el valor medio ideal: el máximo menos dos
     *   desviaciones.
     */
    public function test_laFichaDeAltaYLaDeEdicion(): void
    {
        $preparacion = [$this->dispositivo('Cámara', 'activo', 8), 'UPDATE dispositivos SET media = 5, sd = 0.5 WHERE idDispositivo = :d1', $this->lectura(5, '2026-10-20 08:00:00')];
        $alta = $this->pintar('temperatura.php', ['new' => ''], [], $preparacion);
        $edicion = $this->pintar('temperatura.php', ['edit' => ':d1'], [], $preparacion);

        self::assertStringContainsString('<input type="hidden" name="action" value="save_configuracion">', $alta['html']);
        self::assertStringContainsString("<option value='automatico'>Automatico</option>", $alta['html']);
        self::assertStringContainsString('<input type="hidden" name="action" value="update_dispositivo">', $edicion['html']);
        self::assertStringContainsString('<input type="hidden" name="idDispositivo" value="' . $edicion['ids'][':d1'] . '">', $edicion['html']);
        self::assertStringContainsString('name="deviceName" required value="Cámara"', $edicion['html']);
        self::assertStringContainsString('name="maxTemperature" value="8"', $edicion['html']);
        self::assertStringContainsString('<label>Valor Medio Ideal (°C): </label> 7', $edicion['html']);
        self::assertSame('', $alta['errores'] . $edicion['errores']);
    }

    /** @comportamiento La configuración enseña los parámetros del módulo con sus valores. */
    public function test_laConfiguracionEnsenaLosParametros(): void
    {
        $pantalla = $this->pintar('temperatura.php', ['configurar' => '']);

        self::assertStringContainsString('Configuración de Parámetros - Temperaturas', $pantalla['html']);
        self::assertMatchesRegularExpression('/name="registros_diarios"\s+class="form-control"\s+value="2"/', $pantalla['html']);
        self::assertMatchesRegularExpression('/name="registro1_hora"\s+class="form-control"\s+value="08:00"/', $pantalla['html']);
        self::assertMatchesRegularExpression('/name="alerta_maxima"\s+class="form-control"\s+value="25"/', $pantalla['html']);
    }

    /** @comportamiento Guardar la configuración escribe los parámetros en la copia de `cache/` del módulo, que manda desde entonces. */
    public function test_guardarLaConfiguracionEscribeLaCopiaDelModulo(): void
    {
        $pantalla = $this->pintar('temperatura.php', ['configurar' => ''], $this->configuracion(['registros_diarios' => '3', 'alerta_maxima' => '12', 'dias_historico' => '15']), [], [], true, 'POST');

        $guardada = simplexml_load_file($this->copiaDeParametros());
        self::assertSame('3', (string) $guardada->configuracion->registros_diarios);
        self::assertSame('12', (string) $guardada->configuracion->alerta_maxima['valor']);
        self::assertSame('15', (string) $guardada->configuracion->dias_historico['valor']);
        self::assertStringContainsString('Configuración guardada correctamente', $pantalla['html']);
    }

    /**
     * @comportamiento El análisis de un dispositivo enseña su media, su desviación y el rango normal, y sus
     *   lecturas de la más reciente a la más antigua con su regla, su tipo, su acción y quién la anotó —el
     *   sistema si no la anotó nadie—, y ofrece subir la media y la desviación a la ficha.
     */
    public function test_elAnalisisEnsenaLasLecturasConSuReglaYQuienLasAnoto(): void
    {
        $pantalla = $this->pintar('temperaturasListado.php', ['id' => ':d1', 'modo' => 'media'], [], [
            $this->dispositivo('Cámara', 'activo', 8),
            $this->lectura(3, '2026-10-20 08:00:00'),
            $this->lectura(5, '2026-10-21 08:00:00'),
            "INSERT INTO temperaturas (idDispositivo, temperatura, idUsuario, fechaRegistro) VALUES (:dN, 4, NULL, '2026-10-22 08:00:00')",
        ]);
        $usuario = (string) $this->db->query("SELECT nombre FROM usuarios WHERE id = {$this->idUsuario}")->fetch_row()[0];

        self::assertStringContainsString('Historial de Temperaturas del Dispositivo: Cámara', $pantalla['html']);
        self::assertStringContainsString('<strong>Media:</strong> 4 ', $pantalla['html']);
        self::assertStringContainsString('<strong>Desviación Estándar:</strong> 0.82', $pantalla['html']);
        self::assertLessThan(strpos($pantalla['html'], '2026-10-21 08:00:00'), strpos($pantalla['html'], '2026-10-22 08:00:00'));
        self::assertStringContainsString("<td class=''>Sistema</td>", $pantalla['html']);
        self::assertStringContainsString("<td class=''>" . htmlspecialchars($usuario) . '</td>', $pantalla['html']);
        self::assertStringContainsString("<td class=''>Normal</td>", $pantalla['html']);
        self::assertStringContainsString('value="actualizar_parametros_ds"', $pantalla['html']);
        self::assertStringNotContainsString('modo=limite', $pantalla['html']);
        self::assertSame('', $pantalla['errores']);
    }

    /**
     * @comportamiento Con sus estadísticas subidas, el análisis ofrece el modo del límite, que toma como
     *   media el máximo menos dos desviaciones y media; sin máximo, el modo del límite manda al de la
     *   media y no pinta nada.
     */
    public function test_elModoDelLimite(): void
    {
        $lecturas = [$this->lectura(5, '2026-10-20 08:00:00'), $this->lectura(6, '2026-10-21 08:00:00')];
        $media = $this->pintar('temperaturasListado.php', ['id' => ':d1', 'modo' => 'media'], [], [$this->dispositivo('Cámara', 'activo', 8), 'UPDATE dispositivos SET media = 5, sd = 0.5, temp_min = 5.5 WHERE idDispositivo = :d1', ...$lecturas]);
        $limite = $this->pintar('temperaturasListado.php', ['id' => ':d1', 'modo' => 'limite'], [], [$this->dispositivo('Cámara', 'activo', 8), 'UPDATE dispositivos SET media = 5, sd = 0.5, temp_min = 5.5 WHERE idDispositivo = :d1', ...$lecturas]);
        $sinMaximo = $this->pintar('temperaturasListado.php', ['id' => ':d1', 'modo' => 'limite'], [], ["INSERT INTO dispositivos (nombre, ubicacion, estado) VALUES ('Sin máximo', 'Obrador', 'activo')", ...$lecturas]);

        self::assertStringContainsString('&modo=limite" class="btn btn-default btn-sm">Ver Rango Límite Crítico</a>', $media['html']);
        self::assertStringContainsString('<strong>Media Límite Crítico:</strong> 6.75', $limite['html']);
        self::assertStringContainsString('&modo=media" class="btn btn-default btn-sm">Ver Análisis de Desviaciones</a>', $limite['html']);
        self::assertSame('', $sinMaximo['html']);
    }

    /**
     * @comportamiento Subir los parámetros guarda en la ficha la media y la desviación enviadas, y como
     *   mínimo el máximo menos cinco desviaciones.
     */
    public function test_subirLosParametrosLosGuardaEnLaFicha(): void
    {
        $subida = $this->pintar('temperaturasListado.php', ['id' => ':d1', 'modo' => 'media'], ['action' => 'actualizar_parametros_ds', 'idDispositivo' => ':d1', 'media' => '5', 'desviacionEstandar' => '0.5'], [
            $this->dispositivo('Cámara', 'activo', 8),
            $this->lectura(5, '2026-10-20 08:00:00'),
        ], ['ficha' => "SELECT CONCAT_WS('|', media, sd, temp_min) FROM dispositivos WHERE idDispositivo = :d1"]);

        self::assertSame('5.00|0.50|5.50', $subida['consultas']['ficha']);
    }

    /** @comportamiento Sin dispositivo, el análisis lo dice; un dispositivo sin lecturas manda a la pantalla del módulo y no pinta nada. */
    public function test_elAnalisisSinDispositivoOSinLecturas(): void
    {
        $sinDispositivo = $this->pintar('temperaturasListado.php');
        $sinLecturas = $this->pintar('temperaturasListado.php', ['id' => ':d1', 'modo' => 'media'], [], [$this->dispositivo('Cámara', 'activo', 8)]);

        self::assertSame("<div class='alert alert-danger'>No se ha especificado un dispositivo.</div>", $sinDispositivo['html']);
        self::assertSame('', $sinLecturas['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/temperatura.php:37
     *
     * @group defecto
     * @group guardado
     * @group bajo
     *
     * @que-ocurre-hoy Dar de alta un dispositivo escribe «Dispositivo 'Array' añadido correctamente», con un
     *   aviso de PHP.
     * @que-deberia-ocurrir Que el aviso nombre el dispositivo.
     * @por-que-ocurre El aviso escribe el array con los datos del dispositivo en lugar de su nombre.
     * @como-deberia-funcionar Escribir el nombre del dispositivo, como texto.
     */
    public function test_elAvisoDelAltaNombraElDispositivo(): void
    {
        $alta = $this->pintar('temperatura.php', [], $this->ficha('Vitrina', 'Tienda', 'activo', '8'));

        self::assertStringContainsString("Dispositivo 'Vitrina' añadido correctamente.", $alta['html']);
        self::assertSame('', $alta['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/vistas/temperaturaDispositivo.php:6
     * @codigo-afectado modulos/mod_temperaturas/vistas/temperaturaDispositivo.php:50
     *
     * @group defecto
     * @group guardado
     * @group medio
     *
     * @que-ocurre-hoy La ficha de edición no marca el estado del dispositivo: marca el primero, activo, y
     *   guardarla sin tocarlo deja activo un dispositivo inactivo o automático.
     * @que-deberia-ocurrir Que la ficha traiga marcado el estado que tiene el dispositivo.
     * @por-que-ocurre La ficha convierte el estado leído en un número y lo compara, como número, con el
     *   texto de cada opción: no coincide con ninguna.
     * @como-deberia-funcionar Comparar el estado leído, como texto, con el de cada opción.
     */
    public function test_laFichaDeEdicionMarcaElEstadoDelDispositivo(): void
    {
        $edicion = $this->pintar('temperatura.php', ['edit' => ':d1'], [], [$this->dispositivo('Sonda', 'automatico', 4)]);

        self::assertStringContainsString("<option value='automatico' selected>", $edicion['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/temperatura.php:25-90
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin los permisos de crear y de modificar dispositivos y de registrar temperaturas, el
     *   formulario enviado por su cuenta da de alta el dispositivo, lo modifica y anota la temperatura: los
     *   permisos solo esconden los botones.
     * @que-deberia-ocurrir Que sin el permiso de cada operación no se haga.
     * @por-que-ocurre La pantalla atiende los formularios sin consultar ningún permiso; los consulta al pintar
     *   los botones y las cajas.
     * @como-deberia-funcionar Consultar el permiso de la operación antes de atender su formulario.
     */
    public function test_sinPermisoNoSeGuardaNiSeAnota(): void
    {
        $preparacion = [
            $this->dispositivo('Cámara', 'activo', 4),
            $this->quitarPermiso('crearDispositivo'), $this->quitarPermiso('modificarDispositivo'), $this->quitarPermiso('registrarTemperatura'),
        ];

        $alta = $this->pintar('temperatura.php', [], $this->ficha('Vitrina', 'Tienda', 'activo', '8'), $preparacion, ['alta' => "SELECT COUNT(*) FROM dispositivos WHERE nombre = 'Vitrina'"]);
        $modificacion = $this->pintar('temperatura.php', [], ['action' => 'update_dispositivo', 'idDispositivo' => ':d1'] + $this->ficha('Cámara 2', 'Obrador', 'activo', '4'), $preparacion, ['nombre' => 'SELECT nombre FROM dispositivos WHERE idDispositivo = :d1']);
        $anotacion = $this->pintar('temperatura.php', [], ['action' => 'update_temperaturas', 'temperatura' => [':d1' => '3.4']], $preparacion, ['lecturas' => 'SELECT COUNT(*) FROM temperaturas WHERE idDispositivo = :d1']);

        self::assertSame('0', $alta['consultas']['alta']);
        self::assertSame('Cámara', $modificacion['consultas']['nombre']);
        self::assertSame('0', $anotacion['consultas']['lecturas']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/temperatura.php:25-90
     * @codigo-afectado modulos/mod_temperaturas/temperaturasListado.php:85-97
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin sesión, la pantalla del módulo da de alta un dispositivo y el análisis sube los
     *   parámetros de otro: las dos atienden el formulario antes de la cabecera, que es la que pide la
     *   sesión.
     * @que-deberia-ocurrir Que sin sesión no se atienda ningún formulario.
     * @por-que-ocurre Las dos pantallas atienden sus formularios al principio, y la cabecera común, que es la
     *   que comprueba la sesión, la cargan después, al pintar.
     * @como-deberia-funcionar Comprobar la sesión antes de atender el formulario.
     */
    public function test_sinSesionLasPantallasNoAtiendenSusFormularios(): void
    {
        $alta = $this->pintar('temperatura.php', [], $this->ficha('Vitrina', 'Tienda', 'activo', '8'), [], ['alta' => "SELECT COUNT(*) FROM dispositivos WHERE nombre = 'Vitrina'"], false);
        $subida = $this->pintar('temperaturasListado.php', ['id' => ':d1', 'modo' => 'media'], ['action' => 'actualizar_parametros_ds', 'idDispositivo' => ':d1', 'media' => '5', 'desviacionEstandar' => '0.5'], [
            $this->dispositivo('Cámara', 'activo', 8),
            $this->lectura(5, '2026-10-20 08:00:00'),
        ], ['media' => 'SELECT media FROM dispositivos WHERE idDispositivo = :d1'], false);

        self::assertSame('0', $alta['consultas']['alta']);
        self::assertNull($subida['consultas']['media']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/vistas/configurar_parametros.php:12-37
     * @codigo-afectado modulos/mod_temperaturas/temperatura.php:114
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Sin ningún permiso de acción del módulo, la configuración se abre y guarda los
     *   parámetros: no hay permiso de configurar.
     * @que-deberia-ocurrir Que solo quien tiene el permiso de configurar los parámetros los cambie.
     * @por-que-ocurre El botón de configurar y el guardado no consultan ningún permiso, y el módulo no
     *   declara uno para configurar.
     * @como-deberia-funcionar Declarar el permiso de configurar y consultarlo al enseñar el botón y al
     *   guardar.
     */
    public function test_sinPermisoNoSeConfigura(): void
    {
        $this->pintar('temperatura.php', ['configurar' => ''], $this->configuracion(['alerta_maxima' => '12']), [
            "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_temperaturas' AND accion IS NOT NULL",
        ], [], true, 'POST');

        self::assertFileDoesNotExist($this->copiaDeParametros());
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/temperatura.php:18
     * @codigo-afectado modulos/mod_temperaturas/temperatura.php:144-161
     * @codigo-afectado modulos/mod_temperaturas/vistas/temperaturaListaDispositivos.php:27-28
     *
     * @group defecto
     * @group validacion
     * @group medio
     *
     * @que-ocurre-hoy Los parámetros de registros diarios y sus horas, las alertas máxima y mínima, los días
     *   de histórico y el autoguardado se guardan pero no tienen efecto: el histórico enseña todas las
     *   lecturas desde siempre, y una lectura por encima de la alerta máxima no se distingue.
     * @que-deberia-ocurrir Que cada parámetro configurado tenga efecto: el histórico, de los días indicados,
     *   y la lectura fuera de las alertas, señalada.
     * @por-que-ocurre Fuera de la configuración, solo el forzado de los congeladores lee los parámetros del
     *   módulo.
     * @como-deberia-funcionar Aplicar cada parámetro donde se registra o se enseña lo que regula, o retirar
     *   los que no se quieran usar.
     */
    public function test_losParametrosConfiguradosTienenEfecto(): void
    {
        $this->guardarParametros(['dias_historico' => '30', 'alerta_maxima' => '8']);

        $pantalla = $this->pintar('temperatura.php', [], [], [
            $this->dispositivo('Cámara', 'activo', 10),
            $this->lectura(4, date('Y-m-d H:i:s', strtotime('-60 days'))),
            $this->lectura(12, date('Y-m-d H:i:s', strtotime('-1 day'))),
        ]);

        self::assertStringNotContainsString('<td>4.00 °C</td>', $pantalla['html']);
        self::assertMatchesRegularExpression('/<tr[^>]*(danger|warning|alerta)[^>]*>\s*<td>Cámara<\/td>/', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/vistas/configurar_parametros.php:187-188
     *
     * @group defecto
     * @group instalacion
     * @group bajo
     *
     * @que-ocurre-hoy La configuración carga de internet otra versión de jQuery y Bootstrap, encima de las que
     *   sirve el producto: sin conexión no las carga, y con ella cambia el jQuery de la página.
     * @que-deberia-ocurrir Que la página use solo lo que sirve el producto.
     * @por-que-ocurre La vista de la configuración enlaza dos ficheros de servidores externos.
     * @como-deberia-funcionar Quitar los dos enlaces: la cabecera común ya carga jQuery y Bootstrap.
     */
    public function test_laConfiguracionNoCargaCodigoDeInternet(): void
    {
        $pantalla = $this->pintar('temperatura.php', ['configurar' => '']);

        self::assertDoesNotMatchRegularExpression('/<script src="https?:\/\//', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/vistas/configurar_parametros.php:71
     * @codigo-afectado modulos/mod_temperaturas/vistas/configurar_parametros.php:108
     *
     * @group defecto
     * @group salida
     * @group medio
     *
     * @que-ocurre-hoy La configuración escribe tal cual los textos guardados de los parámetros: una
     *   descripción con una etiqueta de script la ejecuta al abrir la configuración.
     * @que-deberia-ocurrir Que los textos de los parámetros se escriban como texto.
     * @por-que-ocurre La vista escribe las descripciones y los valores leídos sin escaparlos.
     * @como-deberia-funcionar Escapar cada texto leído al escribirlo.
     */
    public function test_laConfiguracionEscribeLasDescripcionesComoTexto(): void
    {
        $this->guardarParametros([], ['registro1' => '">' . self::SCRIPT]);

        $pantalla = $this->pintar('temperatura.php', ['configurar' => '']);

        self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/vistas/temperaturaListaDispositivos.php:35-36
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Con los permisos de partida, la lista de dispositivos no lleva al análisis de ninguno:
     *   el enlace solo sale con el permiso de modificar dispositivos, que de partida no se tiene.
     * @que-deberia-ocurrir Que quien puede ver el análisis llegue a él desde la lista.
     * @por-que-ocurre El enlace va en la columna de acciones, que la lista pinta con el permiso de modificar
     *   dispositivos y no con el de ver el análisis.
     * @como-deberia-funcionar Enseñar el enlace con el permiso de ver el análisis.
     */
    public function test_conLosPermisosDePartidaElAnalisisEsAlcanzable(): void
    {
        $pantalla = $this->pintar('temperatura.php', [], [], [
            $this->dispositivo('Cámara', 'activo', 4),
            $this->quitarPermiso('modificarDispositivo'),
        ]);

        self::assertStringContainsString("temperaturasListado.php?id={$pantalla['ids'][':d1']}", $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/vistas/temperaturaListaDispositivos.php:37-39
     *
     * @group defecto
     * @group permisos
     * @group bajo
     *
     * @que-ocurre-hoy Con el permiso de borrar dispositivos, la lista ofrece «Eliminar», que avisa de que se
     *   borran el dispositivo y sus registros, y la pantalla no lo atiende: no se borra nada.
     * @que-deberia-ocurrir Que la lista no ofrezca una operación que la pantalla no hace.
     * @por-que-ocurre El botón lleva a la pantalla con el dispositivo que borrar, y la pantalla no atiende
     *   esa petición.
     * @como-deberia-funcionar Quitar el botón, o atender la baja del dispositivo sin borrar sus lecturas.
     */
    public function test_laListaNoOfreceUnBorradoQueNoSeAtiende(): void
    {
        $pantalla = $this->pintar('temperatura.php', [], [], [$this->dispositivo('Cámara', 'activo', 4)]);

        self::assertStringNotContainsString('temperatura.php?delete=', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/temperaturasListado.php:182
     * @codigo-afectado modulos/mod_temperaturas/temperaturasListado.php:202-208
     *
     * @group defecto
     * @group validacion
     * @group alto
     *
     * @que-ocurre-hoy En el desglose por desviaciones, que es la vista de partida del análisis, una lectura a
     *   cuatro desviaciones o más de la media no sale en ninguna columna: su fila enseña la regla, pero no
     *   el valor.
     * @que-deberia-ocurrir Que el valor de cada lectura se vea en el desglose, también el de las más
     *   desviadas.
     * @por-que-ocurre El desglose pone el valor en la columna de su desviación entera, y solo tiene columnas
     *   de menos tres a más tres.
     * @como-deberia-funcionar Llevar a la columna del extremo las lecturas que pasan de tres desviaciones.
     */
    public function test_unaLecturaMuyDesviadaSeVeEnElDesglose(): void
    {
        $preparacion = [$this->dispositivo('Cámara', 'activo', 8)];
        for ($dia = 1; $dia <= 19; $dia++) {
            $preparacion[] = $this->lectura(4, sprintf('2026-09-%02d 08:00:00', $dia));
        }
        $preparacion[] = $this->lectura(14, '2026-09-20 08:00:00');

        $pantalla = $this->pintar('temperaturasListado.php', ['id' => ':d1', 'modo' => 'media'], [], $preparacion);

        self::assertMatchesRegularExpression("/<td class='ds ds-?[0-9][^']*'>14\\.00 ºC<\\/td>/", $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/temperaturasListado.php:19-30
     * @codigo-afectado modulos/mod_temperaturas/temperaturasListado.php:42
     *
     * @group defecto
     * @group listado
     * @group bajo
     *
     * @que-ocurre-hoy El análisis pedido sin modo, o con uno que no conoce, da avisos de PHP y no enseña ni la
     *   media ni el límite.
     * @que-deberia-ocurrir Que sin modo, o con uno desconocido, el análisis tome el de la media.
     * @por-que-ocurre El modo solo se fija si es uno de los dos conocidos, y el análisis lo usa sin mirar si
     *   está.
     * @como-deberia-funcionar Tomar el modo de la media cuando no llega uno conocido.
     */
    public function test_elAnalisisSinModoTomaElDeLaMedia(): void
    {
        $pantalla = $this->pintar('temperaturasListado.php', ['id' => ':d1'], [], [
            $this->dispositivo('Cámara', 'activo', 8),
            $this->lectura(5, '2026-10-20 08:00:00'),
        ]);

        self::assertSame('', $pantalla['errores']);
        self::assertStringContainsString('<strong>Media:</strong>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/temperaturasListado.php:36-39
     * @codigo-afectado modulos/mod_temperaturas/temperatura.php:92-169
     *
     * @group defecto
     * @group listado
     * @group bajo
     *
     * @que-ocurre-hoy El análisis de un dispositivo sin lecturas manda a la pantalla del módulo con un aviso,
     *   y la pantalla no lo enseña: el usuario vuelve a la lista sin saber por qué.
     * @que-deberia-ocurrir Que la pantalla enseñe el aviso que recibe, como texto.
     * @por-que-ocurre La pantalla del módulo no lee el aviso que le llega en la dirección.
     * @como-deberia-funcionar Enseñar el aviso recibido, escapado.
     */
    public function test_elAvisoDeUnDispositivoSinLecturasSeEnsena(): void
    {
        $pantalla = $this->pintar('temperatura.php', ['error' => 'No se encontraron registros para el dispositivo especificado.']);

        self::assertStringContainsString('No se encontraron registros para el dispositivo especificado.', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/temperaturasListado.php:85-97
     * @codigo-afectado modulos/mod_temperaturas/temperaturasListado.php:135-147
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Sin el permiso de modificar dispositivos, el análisis ofrece subir la media y la
     *   desviación a la ficha del dispositivo, y las sube.
     * @que-deberia-ocurrir Que solo quien puede modificar el dispositivo cambie su ficha.
     * @por-que-ocurre El análisis enseña el botón y atiende el formulario sin consultar ningún permiso.
     * @como-deberia-funcionar Consultar el permiso de modificar el dispositivo al enseñar el botón y al
     *   atender el formulario.
     */
    public function test_sinPermisoDeModificarNoSeSubenLosParametros(): void
    {
        $subida = $this->pintar('temperaturasListado.php', ['id' => ':d1', 'modo' => 'media'], ['action' => 'actualizar_parametros_ds', 'idDispositivo' => ':d1', 'media' => '5', 'desviacionEstandar' => '0.5'], [
            $this->dispositivo('Cámara', 'activo', 8),
            $this->lectura(5, '2026-10-20 08:00:00'),
            $this->quitarPermiso('modificarDispositivo'),
        ], ['media' => 'SELECT media FROM dispositivos WHERE idDispositivo = :d1']);

        self::assertNull($subida['consultas']['media']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_temperaturas/temperatura.php:72-79
     *
     * @group defecto
     * @group entrada
     * @group medio
     *
     * @que-ocurre-hoy Una temperatura que no es un número, enviada en el formulario, se anota como 0 °C.
     * @que-deberia-ocurrir Que una temperatura que no es un número no se anote, y se diga.
     * @por-que-ocurre La pantalla convierte lo recibido en número sin mirar si lo es: lo que no lo es vale
     *   cero. Solo la caja del navegador limita lo que se teclea.
     * @como-deberia-funcionar Comprobar que lo recibido es un número antes de anotarlo.
     */
    public function test_unaTemperaturaQueNoEsUnNumeroNoSeAnota(): void
    {
        $anotacion = $this->pintar('temperatura.php', [], ['action' => 'update_temperaturas', 'temperatura' => [':d1' => 'abc']], [
            $this->dispositivo('Cámara', 'activo', 4),
        ], ['lecturas' => 'SELECT COUNT(*) FROM temperaturas WHERE idDispositivo = :d1']);

        self::assertSame('0', $anotacion['consultas']['lecturas']);
    }

    private function dispositivo(string $nombre, string $estado, float $maximo): string
    {
        return "INSERT INTO dispositivos (nombre, ubicacion, estado, temp_max) VALUES ('{$nombre}', 'Obrador', '{$estado}', {$maximo})";
    }

    /** Una lectura del último dispositivo sembrado, anotada por el usuario de pruebas. */
    private function lectura(float $valor, string $fecha): string
    {
        return "INSERT INTO temperaturas (idDispositivo, temperatura, idUsuario, fechaRegistro) VALUES (:dN, {$valor}, :usuario, '{$fecha}')";
    }

    /** @return array<string, string> */
    private function ficha(string $nombre, string $ubicacion, string $estado, string $maximo): array
    {
        return ['action' => 'save_configuracion', 'deviceName' => $nombre, 'deviceLocation' => $ubicacion, 'deviceStatus' => $estado, 'maxTemperature' => $maximo];
    }

    /**
     * El formulario de la configuración con los valores del `parametros.xml` del módulo, cambiados los del
     * caso.
     *
     * @param array<string, string> $cambios
     * @return array<string, string>
     */
    private function configuracion(array $cambios): array
    {
        $formulario = [
            'registros_diarios' => '2', 'alerta_maxima' => '25', 'alerta_minima' => '18',
            'forzar_registro_congeladores' => 'No', 'dias_historico' => '30', 'auto_guardar' => 'Si',
        ];
        for ($i = 1; $i <= 4; $i++) {
            $formulario["registro{$i}_hora"] = $i <= 2 ? ($i === 1 ? '08:00' : '20:00') : '';
            $formulario["registro{$i}_forzar"] = $i <= 2 ? 'Si' : 'No';
            $formulario["registro{$i}_descripcion"] = 'Registro ' . $i;
        }

        return array_merge($formulario, $cambios);
    }

    private function quitarPermiso(string $accion): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_temperaturas' AND accion = '{$accion}'";
    }

    private function copiaDeParametros(): string
    {
        return RUTA_TPVFOX . '/modulos/mod_temperaturas/cache/parametros.xml';
    }

    /**
     * Escribe la copia de `cache/` con el `parametros.xml` del módulo, cambiado el atributo `valor` de los
     * parámetros indicados y la descripción de los registros indicados.
     *
     * @param array<string, string> $valores
     * @param array<string, string> $descripciones
     */
    private function guardarParametros(array $valores, array $descripciones = []): void
    {
        $parametros = simplexml_load_file(RUTA_TPVFOX . '/modulos/mod_temperaturas/parametros.xml');
        foreach ($valores as $nombre => $valor) {
            $parametros->configuracion->{$nombre}['valor'] = $valor;
        }
        foreach ($descripciones as $registro => $descripcion) {
            $parametros->configuracion->{$registro}['descripcion'] = $descripcion;
        }
        if (!is_dir(dirname($this->copiaDeParametros()))) {
            mkdir(dirname($this->copiaDeParametros()), 0755, true);
        }
        $parametros->asXML($this->copiaDeParametros());
    }

    /**
     * Pinta una pantalla del módulo en un PHP aparte, con la dirección, el formulario y el método del caso.
     * Los nombres de la preparación se sustituyen también en las claves del formulario.
     *
     * @param array<string, string> $consulta
     * @param array<string, mixed> $formulario
     * @param list<string> $preparacion
     * @param array<string, string> $consultas
     * @return array{html: string, errores: string, ids: array<string, string>, consultas: array<string, ?string>}
     */
    private function pintar(string $fichero, array $consulta = [], array $formulario = [], array $preparacion = [], array $consultas = [], bool $conSesion = true, string $metodo = 'GET'): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_temperaturas/{$fichero}", true) . ';'
            . ' $_SERVER["REQUEST_METHOD"] = ' . var_export($formulario !== [] ? 'POST' : $metodo, true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_temperaturas', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_temperaturas\'");'
            . ' $sembradosDelCaso = [":usuario" => "' . $this->idUsuario . '"]; $dispositivosDelCaso = 0;'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembradosDelCaso));'
            . ' if (str_starts_with($sql, "INSERT INTO dispositivos ")) { $sembradosDelCaso[":d" . ++$dispositivosDelCaso] = $sembradosDelCaso[":dN"] = (string) $BDTpv->insert_id; } }'
            . ' $nombrarDelCaso = function ($valor) use (&$nombrarDelCaso, &$sembradosDelCaso) { if (!is_array($valor)) { return strtr((string) $valor, $sembradosDelCaso); }'
            . ' $nombrado = []; foreach ($valor as $clave => $dentro) { $nombrado[strtr((string) $clave, $sembradosDelCaso)] = $nombrarDelCaso($dentro); } return $nombrado; };'
            . ' $_GET = $nombrarDelCaso(' . var_export($consulta, true) . '); $_POST = $nombrarDelCaso(' . var_export($formulario, true) . ');'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use ($BDTpv, &$sembradosDelCaso) { $resultados = [];'
            . ' foreach (' . var_export($consultas, true) . ' as $nombre => $sql) { $fila = $BDTpv->query(strtr($sql, $sembradosDelCaso))->fetch_row(); $resultados[$nombre] = $fila === null || $fila[0] === null ? null : (string) $fila[0]; }'
            . ' echo "\n@@ESTADO@@" . json_encode(["ids" => $sembradosDelCaso, "consultas" => $resultados]); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'temperaturas');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $estado] = array_pad(explode('@@ESTADO@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['ids' => [], 'consultas' => []];
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $pintada;
    }
}

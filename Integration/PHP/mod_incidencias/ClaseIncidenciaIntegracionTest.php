<?php

/**
 * La clase de incidencias (`modulos/mod_incidencias/clases/ClaseIncidencia.php`): el alta de una
 * incidencia o de una respuesta, su numeración, las consultas del listado, de un número, de un
 * identificador y de las que un usuario tiene sin resolver, y la ventana con que se abren.
 *
 * El alta toma el usuario de la sesión: cada caso la deja con el usuario de pruebas que da la
 * siembra. Las incidencias adjuntas a un documento se prueban desde la venta.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModIncidencias;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ClaseIncidenciaIntegracionTest extends CasoIntegracion
{
    private Siembra $siembra;
    private int $idUsuario;
    private ?array $sesionOriginal = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_incidencias/clases/ClaseIncidencia.php');
        $this->siembra = new Siembra($this->db);
        $this->idUsuario = $this->siembra->usuarioPorDefecto();
        $this->sesionOriginal = $_SESSION ?? null;
        $_SESSION = ['usuarioTpv' => ['id' => $this->idUsuario, 'nombre' => 'Persona de pruebas']];
    }

    protected function tearDown(): void
    {
        if ($this->sesionOriginal === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->sesionOriginal;
        }
        parent::tearDown();
    }

    /** @comportamiento Una incidencia nueva toma el número siguiente al mayor que había al crear la clase. */
    public function test_unaIncidenciaNuevaTomaElNumeroSiguienteAlMayor(): void
    {
        $existente = $this->siembra->incidencia('mod_incidencia', ['vista' => 'listado'], 'La primera');
        $mayor = $this->numeroDe($existente);

        $respuesta = $this->incidencias()->addIncidencia('mod_incidencia', '{"vista":"listado"}', 'La segunda', '0', 0);

        self::assertSame($mayor + 1, $this->numeroDe($respuesta['id']));
    }

    /** @comportamiento Una respuesta se guarda con el número de la incidencia a la que responde. */
    public function test_unaRespuestaConservaElNumeroDeLaIncidencia(): void
    {
        $numero = $this->numeroDe($this->siembra->incidencia('mod_incidencia', ['vista' => 'listado'], 'La incidencia'));

        $respuesta = $this->incidencias()->addIncidencia('mod_incidencia', '{"vista":"incidencia"}', 'La respuesta', '2', $numero);

        self::assertSame($numero, $this->numeroDe($respuesta['id']));
    }

    /**
     * @comportamiento El estado llega como 0, 1 o 2 y se guarda con su nombre: «No resuelto»,
     *   «Resuelto» o «Pendiente»; cualquier otro valor se guarda como «No resuelto».
     */
    public function test_elEstadoSeGuardaPorSuNombre(): void
    {
        $incidencias = $this->incidencias();
        $estados = [];
        foreach (['0', '1', '2', '9'] as $codigo) {
            $respuesta = $incidencias->addIncidencia('mod_incidencia', '{}', 'Estado ' . $codigo, $codigo, 0);
            $estados[$codigo] = $this->fila($respuesta['id'])['estado'];
        }

        self::assertSame(['0' => 'No resuelto', '1' => 'Resuelto', '2' => 'Pendiente', '9' => 'No resuelto'], $estados);
    }

    /** @comportamiento Dos incidencias nuevas dadas de alta con la misma clase toman números seguidos. */
    public function test_dosAltasConLaMismaClaseTomanNumerosSeguidos(): void
    {
        $incidencias = $this->incidencias();
        $primera = $this->numeroDe($incidencias->addIncidencia('mod_incidencia', '{}', 'La primera', '0', 0)['id']);
        $segunda = $this->numeroDe($incidencias->addIncidencia('mod_incidencia', '{}', 'La segunda', '0', 0)['id']);

        self::assertSame($primera + 1, $segunda);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:118-119
     *
     * @group defecto
     * @group numeracion
     * @group bajo
     *
     * @que-ocurre-hoy Una respuesta a un número de incidencia que no existe se guarda con ese número:
     *   abre una incidencia fuera de la numeración, y el listado cuenta las que no hay.
     * @que-deberia-ocurrir Que una respuesta solo se guarde si su incidencia existe.
     * @por-que-ocurre El alta usa el número recibido sin comprobar que tenga alguna anotación.
     * @como-deberia-funcionar Comprobar que el número existe antes de guardar la respuesta.
     */
    public function test_unaRespuestaAUnNumeroQueNoExisteNoSeGuarda(): void
    {
        $inexistente = 100 + (int) $this->db->query('SELECT COALESCE(MAX(num_incidencia), 0) FROM modulo_incidencia')->fetch_row()[0];
        $incidencias = $this->incidencias();

        self::capturando(fn () => $incidencias->addIncidencia('mod_incidencia', '{}', 'Respuesta sin incidencia', '1', $inexistente));

        $guardadas = (int) $this->db->query('SELECT COUNT(*) FROM modulo_incidencia WHERE num_incidencia = ' . $inexistente)->fetch_row()[0];
        self::assertSame(0, $guardadas, 'La respuesta a un número que no existe se guardó');
    }

    /** @comportamiento El alta guarda el usuario de la sesión, el origen, el mensaje, los datos y la fecha del momento. */
    public function test_elAltaGuardaElUsuarioDeLaSesionYLoRecibido(): void
    {
        $respuesta = $this->incidencias()->addIncidencia('mod_ventas', '{"vista":"albaran","idReal":"7"}', 'Falta una caja', '0', 0);
        $fila = $this->fila($respuesta['id']);

        self::assertSame(
            [(string) $this->idUsuario, 'mod_ventas', 'Falta una caja', '{"vista":"albaran","idReal":"7"}'],
            [$fila['id_usuario'], $fila['dedonde'], $fila['mensaje'], $fila['datos']]
        );
        self::assertNotEmpty($fila['fecha_creacion']);
    }

    /** @comportamiento El mayor número de incidencia es el que da la clase como número de registros. */
    public function test_elNumeroDeRegistrosEsElMayorNumero(): void
    {
        $this->siembra->incidencia('mod_incidencia', [], 'Una');
        $ultima = $this->siembra->incidencia('mod_incidencia', [], 'Otra');

        self::assertSame($this->numeroDe($ultima), (int) $this->incidencias()->GetNumRows());
    }

    /**
     * @comportamiento El listado da una fila por número: la última anotación de cada incidencia,
     *   con el nombre de quien la escribió y su estado.
     */
    public function test_elListadoEnsenaLaUltimaAnotacionDeCadaNumero(): void
    {
        $numero = $this->numeroDe($this->siembra->incidencia('mod_incidencia', [], 'Abierta'));
        $incidencias = $this->incidencias();
        $incidencias->addIncidencia('mod_incidencia', '{}', 'Resuelta ya', '1', $numero);
        $otro = $this->numeroDe($this->siembra->incidencia('mod_incidencia', [], 'Otra abierta'));

        $listado = $incidencias->todasIncidenciasLimite('');

        // Solo las de esta prueba: los recorridos dejan incidencias guardadas en la base.
        $deEstaPrueba = array_filter($listado['NItems'], static fn ($fila) => in_array((int) $fila['num_incidencia'], [$numero, $otro], true));
        self::assertCount(2, $deEstaPrueba);
        $deLaPrimera = array_values(array_filter($deEstaPrueba, static fn ($fila) => (int) $fila['num_incidencia'] === $numero));
        self::assertSame(['Resuelta ya', 'Resuelto'], [$deLaPrimera[0]['mensaje'], $deLaPrimera[0]['estado']]);
        self::assertArrayHasKey('nombre', $deLaPrimera[0]);
    }

    /** @comportamiento Un número devuelve todas sus anotaciones, con el usuario de cada una. */
    public function test_unNumeroDevuelveTodasSusAnotaciones(): void
    {
        $numero = $this->numeroDe($this->siembra->incidencia('mod_incidencia', [], 'Abierta'));
        $this->incidencias()->addIncidencia('mod_incidencia', '{}', 'Respuesta', '2', $numero);
        $this->siembra->incidencia('mod_incidencia', [], 'De otro número');

        $anotaciones = $this->incidencias()->incidenciasNumero($numero);

        self::assertSame(['Abierta', 'Respuesta'], array_column($anotaciones, 'mensaje'));
        self::assertSame(['pruebas', 'pruebas'], array_column($anotaciones, 'username'));
    }

    /** @comportamiento Un identificador devuelve su anotación. */
    public function test_unIdentificadorDevuelveSuAnotacion(): void
    {
        $id = $this->siembra->incidencia('mod_incidencia', ['vista' => 'listado'], 'Por identificador');

        self::assertSame('Por identificador', $this->incidencias()->datosIncidencia($id)['mensaje']);
    }

    /** @comportamiento Las incidencias sin resolver de un usuario son las suyas en estado «No resuelto». */
    public function test_lasSinResolverSonLasDelUsuarioEnEseEstado(): void
    {
        $this->siembra->incidencia('mod_incidencia', [], 'Sin resolver');
        $this->siembra->incidencia('mod_incidencia', [], 'Resuelta', ['estado' => 'Resuelto']);

        $sinResolver = $this->incidencias()->incidenciasSinResolverUsuario($this->idUsuario);

        self::assertSame(['Sin resolver'], array_column($sinResolver, 'mensaje'));
    }

    /**
     * @comportamiento Con el selector configurado, la ventana ofrece los usuarios para asignar la
     *   incidencia y marca el configurado por defecto; sin número, el estado va fijo.
     */
    public function test_laVentanaOfreceLosUsuariosYMarcaElConfigurado(): void
    {
        $html = $this->incidencias()->htmlModalIncidencia('{"vista":"listado"}', 'mod_incidencia', $this->configuracion('Si', $this->idUsuario), 'No resuelto', 0);

        self::assertStringContainsString('<select name="usuarioSelec" id="usuarioSelec">', $html);
        self::assertStringContainsString('<option value=' . $this->idUsuario . ' selected>pruebas</option>', $html);
        self::assertStringContainsString('<input type="text" name="inci_estado" id="inci_estado" value="No resuelto" readonly="">', $html);
        self::assertStringContainsString('<input type="hidden" name="inci_usuario" id="inci_usuario" value="' . $this->idUsuario . '">', $html);
    }

    /** @comportamiento Sin el selector configurado, la ventana no ofrece usuarios. */
    public function test_sinSelectorLaVentanaNoOfreceUsuarios(): void
    {
        $html = $this->incidencias()->htmlModalIncidencia('{}', 'mod_incidencia', $this->configuracion('No', $this->idUsuario), 'No resuelto', 0);

        self::assertStringNotContainsString('usuarioSelec', $html);
    }

    /** @comportamiento Al responder a una incidencia, la ventana deja elegir el estado. */
    public function test_alResponderLaVentanaDejaElegirElEstado(): void
    {
        $html = $this->incidencias()->htmlModalIncidencia('{}', 'incidencia', $this->configuracion('No', $this->idUsuario), 'No resuelto', 3);

        self::assertStringContainsString('<select name="inci_estado" id="inci_estado">', $html);
        self::assertStringContainsString('<input type="hidden" name="numIncidencia" id="numIncidencia" value=3>', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:124-125
     *
     * @group defecto
     * @group guardado
     * @group alto
     *
     * @que-ocurre-hoy Un mensaje con una comilla simple —«D'Ancona»— rompe la sentencia del alta:
     *   la base la rechaza y la petición termina con un error, sin guardar la incidencia.
     * @que-deberia-ocurrir Que el mensaje se guarde tal como se escribió.
     * @por-que-ocurre La sentencia se compone pegando el origen, el mensaje, los datos y el
     *   estado entre comillas simples, sin escaparlos.
     * @como-deberia-funcionar Pasar los valores a la sentencia como parámetros.
     */
    public function test_unMensajeConComillasSeGuardaTalCual(): void
    {
        [$respuesta, $error] = self::capturando(fn () => $this->incidencias()->addIncidencia('mod_incidencia', '{}', "Llamar a D'Ancona", '0', 0));

        self::assertNull($error, 'El alta no puede terminar en error.');
        self::assertSame("Llamar a D'Ancona", $this->fila($respuesta['id'])['mensaje']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:82-84
     *
     * @group defecto
     * @group listado
     * @group alto
     *
     * @que-ocurre-hoy Un número de incidencia con una condición añadida —«0 OR 1=1»— devuelve las
     *   anotaciones de todas las incidencias. La ficha lo toma de la dirección.
     * @que-deberia-ocurrir Que solo devuelva las anotaciones de ese número, que aquí no existe.
     * @por-que-ocurre El número se pega a la sentencia sin comprobar que sea un número.
     * @como-deberia-funcionar Tomar el número como entero o pasarlo como parámetro.
     */
    public function test_unNumeroManipuladoNoAmpliaLaConsulta(): void
    {
        $this->siembra->incidencia('mod_incidencia', [], 'Una');
        $this->siembra->incidencia('mod_incidencia', [], 'Otra');

        self::assertSame([], $this->incidencias()->incidenciasNumero('0 OR 1=1'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:11-13
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:118-123
     *
     * @group defecto
     * @group numeracion
     * @group medio
     *
     * @que-ocurre-hoy Dos altas que se preparan a la vez —dos peticiones que crean la clase antes de
     *   que ninguna guarde— dan a dos incidencias distintas el mismo número, y quedan mezcladas
     *   como anotaciones de una sola.
     * @que-deberia-ocurrir Que cada incidencia nueva tenga su número.
     * @por-que-ocurre El número siguiente se calcula con el mayor guardado al crear la clase, y
     *   no se vuelve a comprobar al guardar.
     * @como-deberia-funcionar Que la base dé el número al guardar, o reservarlo con un bloqueo.
     */
    public function test_dosAltasALaVezNoCompartenNumero(): void
    {
        $una = $this->incidencias();
        $otra = $this->incidencias();

        $primera = $una->addIncidencia('mod_incidencia', '{}', 'Desde la caja', '0', 0);
        $segunda = $otra->addIncidencia('mod_incidencia', '{}', 'Desde compras', '0', 0);

        self::assertNotSame($this->numeroDe($primera['id']), $this->numeroDe($segunda['id']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:124-136
     *
     * @group defecto
     * @group guardado
     * @group medio
     *
     * @que-ocurre-hoy Un alta que la base rechaza —un mensaje más largo que la columna— termina la
     *   petición con un error sin recoger, en lugar de devolverlo a quien la pidió.
     * @que-deberia-ocurrir Que el alta devuelva el error y la petición siga.
     * @por-que-ocurre La base avisa del error con una excepción, y el alta solo contempla que la
     *   consulta devuelva falso; la rama que lo trataría, además, habla de artículos y usa una
     *   variable que no existe.
     * @como-deberia-funcionar Recoger la excepción y devolver un error propio de la incidencia.
     */
    public function test_unAltaQueLaBaseRechazaDevuelveElError(): void
    {
        [$respuesta, $error] = self::capturando(fn () => $this->incidencias()->addIncidencia('mod_incidencia', '{}', str_repeat('a', 300), '0', 0));

        self::assertNull($error, 'El alta no puede terminar la petición.');
        self::assertArrayHasKey('error', $respuesta);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:15-27
     *
     * @group defecto
     * @group listado
     * @group bajo
     *
     * @que-ocurre-hoy Una consulta que la base rechaza termina la petición con un error sin
     *   recoger; las consultas de la clase comprueban si les llegó un error, pero nunca llega.
     * @que-deberia-ocurrir Que la consulta devuelva el error y la sentencia, como declara.
     * @por-que-ocurre La base avisa del error con una excepción, y la consulta solo contempla que
     *   devuelva falso.
     * @como-deberia-funcionar Recoger la excepción en la consulta y devolver el error.
     */
    public function test_unaConsultaQueFallaDevuelveElError(): void
    {
        [$respuesta, $error] = self::capturando(fn () => $this->incidencias()->consulta('SELECT * FROM tabla_que_no_existe'));

        self::assertNull($error, 'La consulta no puede terminar la petición.');
        self::assertArrayHasKey('error', $respuesta);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:224
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Los datos de la incidencia se escriben en la ventana tal cual: unos datos que
     *   cierran el área de texto y traen un script lo ejecutan en la página de quien la abre.
     * @que-deberia-ocurrir Que los datos se escriban como texto.
     * @por-que-ocurre La ventana compone su HTML pegando los datos, el origen y el estado sin
     *   escaparlos.
     * @como-deberia-funcionar Escapar cada valor que se escribe en la ventana.
     */
    public function test_losDatosDeLaVentanaSeEscribenComoTexto(): void
    {
        $html = $this->incidencias()->htmlModalIncidencia('{"vista":"</textarea><script>alert(1)</script>"}', 'mod_incidencia', $this->configuracion('No', $this->idUsuario), 'No resuelto', 0);

        self::assertStringNotContainsString('<script>', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:160-167
     *
     * @group defecto
     * @group entrada
     * @group bajo
     *
     * @que-ocurre-hoy Si la configuración trae el usuario por defecto antes que el selector, la
     *   ventana no marca ningún usuario: el valor del selector pisa al del usuario por defecto.
     * @que-deberia-ocurrir Que la ventana marque el usuario configurado, venga en el orden que venga.
     * @por-que-ocurre El caso del selector no termina con `break` y sigue en el del usuario por
     *   defecto, al que asigna su propio valor.
     * @como-deberia-funcionar Terminar cada caso con su `break`.
     */
    public function test_conLaConfiguracionEnOtroOrdenSeMarcaElUsuarioConfigurado(): void
    {
        $configuracion = array_reverse($this->configuracion('Si', $this->idUsuario));

        $html = $this->incidencias()->htmlModalIncidencia('{}', 'mod_incidencia', $configuracion, 'No resuelto', 0);

        self::assertStringContainsString('<option value=' . $this->idUsuario . ' selected>pruebas</option>', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:194-198
     *
     * @group defecto
     * @group estados
     * @group bajo
     *
     * @que-ocurre-hoy Al responder, el selector de estado marca las tres opciones a la vez, y el
     *   navegador propone la última, «Pendiente», sea cual sea el estado de la incidencia.
     * @que-deberia-ocurrir Que proponga un solo estado.
     * @por-que-ocurre Las tres opciones del selector llevan `selected`.
     * @como-deberia-funcionar Marcar solo la opción del estado que se propone.
     */
    public function test_alResponderSeProponeUnSoloEstado(): void
    {
        $html = $this->incidencias()->htmlModalIncidencia('{}', 'incidencia', $this->configuracion('No', $this->idUsuario), 'No resuelto', 3);

        self::assertSame(1, substr_count($html, ' selected>'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:9
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:146
     *
     * @group defecto
     * @group guardado
     * @group bajo
     *
     * @que-ocurre-hoy Crear la clase y dar un alta dejan avisos de obsolescencia de PHP: la clase
     *   guarda la conexión y el usuario en propiedades que no declara.
     * @que-deberia-ocurrir Que no deje avisos.
     * @por-que-ocurre Desde PHP 8.2 crear una propiedad sin declararla está obsoleto.
     * @como-deberia-funcionar Declarar las dos propiedades en la clase.
     */
    public function test_laClaseNoDejaAvisosDeObsolescencia(): void
    {
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        }, E_DEPRECATED);
        try {
            $this->incidencias()->addIncidencia('mod_incidencia', '{}', 'Sin avisos', '0', 0);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $avisos);
    }

    /**
     * @estado rojo
     * @codigo-afectado BD/BDtpv/tpvfox_V_0_4-2.84.sql:1206-1210
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:124-125
     *
     * @group defecto
     * @group guardado
     * @group medio
     *
     * @que-ocurre-hoy Un mensaje con un emoji, o con cualquier carácter de cuatro bytes, no se guarda:
     *   la base lo rechaza y la petición termina con un error.
     * @que-deberia-ocurrir Que el mensaje se guarde tal como se escribió.
     * @por-que-ocurre La tabla de incidencias guarda el texto con un juego de caracteres de tres
     *   bytes, y la conexión envía los de cuatro.
     * @como-deberia-funcionar Guardar el texto de la tabla con el juego de caracteres de cuatro bytes.
     */
    public function test_unMensajeConUnEmojiSeGuardaTalCual(): void
    {
        [$respuesta, $error] = self::capturando(fn () => $this->incidencias()->addIncidencia('mod_incidencia', '{}', 'Caja rota 😀', '0', 0));

        self::assertNull($error, 'La base no puede rechazar el mensaje.');
        self::assertSame('Caja rota 😀', $this->fila((int) $respuesta['id'])['mensaje']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:218-221
     * @codigo-afectado BD/BDtpv/tpvfox_V_0_4-2.84.sql:1206
     *
     * @group defecto
     * @group entrada
     * @group medio
     *
     * @que-ocurre-hoy La ventana deja escribir un mensaje de cualquier largo, y la base solo admite
     *   255 caracteres: uno más largo no se guarda, y la petición termina con un error.
     * @que-deberia-ocurrir Que un mensaje que la ventana deja escribir se guarde.
     * @por-que-ocurre El área de texto del mensaje no tiene límite, y la columna sí.
     * @como-deberia-funcionar Que la ventana limite el mensaje a lo que admite la columna, o que la
     *   columna admita lo que la ventana deja escribir.
     */
    public function test_unMensajeQueLaVentanaDejaEscribirSeGuarda(): void
    {
        $ventana = $this->incidencias()->htmlModalIncidencia('{}', 'mod_incidencia', $this->configuracion('No', 1), 'No resuelto', 0);
        preg_match('#<textarea[^>]*id="inci_mensaje"[^>]*>#', $ventana, $areaDeTexto);
        // Sin límite en el área de texto, el mensaje más largo que se prueba es de 300 caracteres.
        $largo = preg_match('#maxlength="(\d+)"#', $areaDeTexto[0], $limite) === 1 ? (int) $limite[1] : 300;
        $mensaje = str_repeat('a', $largo);

        [$respuesta, $error] = self::capturando(fn () => $this->incidencias()->addIncidencia('mod_incidencia', '{}', $mensaje, '0', 0));

        self::assertNull($error, 'Un mensaje que la ventana admite no puede rechazarse.');
        self::assertSame($mensaje, $this->fila((int) $respuesta['id'])['mensaje']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:168-175
     *
     * @group defecto
     * @group entrada
     * @group medio
     *
     * @que-ocurre-hoy La ventana ofrece para asignar la incidencia a usuarios dados de baja: se le
     *   puede asignar a quien ya no trabaja, y nadie la atiende.
     * @que-deberia-ocurrir Que solo ofrezca usuarios activos.
     * @por-que-ocurre La lista del selector lee todos los usuarios, sin mirar su estado.
     * @como-deberia-funcionar Leer solo los usuarios activos.
     */
    public function test_laVentanaSoloOfreceUsuariosActivos(): void
    {
        $this->db->query("INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES ('de_baja_incidencias', 'sin-uso', CURDATE(), 1, 'inactivo', 'Usuario de baja')");

        $ventana = $this->incidencias()->htmlModalIncidencia('{}', 'mod_incidencia', $this->configuracion('Si', 1), 'No resuelto', 0);

        self::assertFalse(str_contains($ventana, 'de_baja_incidencias'), 'La ventana ofrece un usuario dado de baja');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:181
     *
     * @group defecto
     * @group entrada
     * @group bajo
     *
     * @que-ocurre-hoy La ventana enseña la fecha vacía: el campo es de fecha, y se le da la fecha con
     *   la hora, que el navegador no admite.
     * @que-deberia-ocurrir Que la ventana enseñe la fecha de hoy.
     * @por-que-ocurre El valor se compone con la fecha y la hora para un campo que solo admite la
     *   fecha.
     * @como-deberia-funcionar Dar al campo solo la fecha.
     */
    public function test_laVentanaEnsenaLaFechaEnElFormatoDelCampo(): void
    {
        $ventana = $this->incidencias()->htmlModalIncidencia('{}', 'mod_incidencia', $this->configuracion('No', 1), 'No resuelto', 0);

        preg_match('#id="inci_fecha" value="([^"]*)"#', $ventana, $fecha);
        self::assertMatchesRegularExpression('#^\d{4}-\d{2}-\d{2}$#', $fecha[1]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:168-175
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:204-215
     *
     * @group defecto
     * @group entrada
     * @group bajo
     *
     * @que-ocurre-hoy Con el selector de usuarios configurado y un usuario por defecto negativo, la
     *   ventana pinta el selector vacío y deja dos avisos de PHP.
     * @que-deberia-ocurrir Que el selector, si se pinta, ofrezca los usuarios, sin avisos.
     * @por-que-ocurre La lista de usuarios solo se lee si el usuario por defecto no es negativo, y el
     *   selector se pinta siempre que esté configurado.
     * @como-deberia-funcionar Leer la lista siempre que se pinte el selector.
     */
    public function test_conUnUsuarioPorDefectoNegativoElSelectorOfreceLosUsuarios(): void
    {
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        }, E_WARNING);
        try {
            $ventana = $this->incidencias()->htmlModalIncidencia('{}', 'mod_incidencia', $this->configuracion('Si', -1), 'No resuelto', 0);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $avisos);
        self::assertStringContainsString('<option value=', $ventana);
    }

    /** @comportamiento Un documento enseña las incidencias de su origen, y no las de otro con la misma vista y el mismo identificador. */
    public function test_unDocumentoEnsenaSoloLasIncidenciasDeSuOrigen(): void
    {
        $this->siembra->incidencia('mod_compras', ['vista' => 'albaran', 'idReal' => '98764'], 'De compras');
        $this->siembra->incidencia('mod_venta', ['vista' => 'albaran', 'idReal' => '98764'], 'De ventas');

        $adjuntas = $this->incidencias()->incidenciasAdjuntas('98764', 'mod_compras', 'albaran');

        self::assertSame(['De compras'], array_column($adjuntas, 'mensaje'));
    }

    /** @comportamiento Un documento no enseña las incidencias de otro cuyo identificador empieza igual. */
    public function test_unDocumentoNoEnsenaLasDeOtroQueEmpiezaIgual(): void
    {
        $this->siembra->incidencia('mod_compras', ['vista' => 'albaran', 'idReal' => '98765'], 'Del documento');
        $this->siembra->incidencia('mod_compras', ['vista' => 'albaran', 'idReal' => '987650'], 'De otro que empieza igual');

        $adjuntas = $this->incidencias()->incidenciasAdjuntas('98765', 'mod_compras', 'albaran');

        self::assertSame(['Del documento'], array_column($adjuntas, 'mensaje'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:242
     *
     * @group defecto
     * @group adjuntos
     * @group alto
     *
     * @que-ocurre-hoy Una incidencia de un documento cuyos datos llevan el identificador antes que la
     *   vista no aparece en el documento: el resultado sale vacío, sin error.
     * @que-deberia-ocurrir Que se encuentre, porque la vista y el identificador son los mismos.
     * @por-que-ocurre La búsqueda compara el texto de los datos con la vista seguida del
     *   identificador, en ese orden y sin nada entre medias.
     * @como-deberia-funcionar Comparar sobre los valores decodificados, no sobre el texto.
     */
    public function test_unDocumentoEnsenaSuIncidenciaConLosDatosEnOtroOrden(): void
    {
        $this->siembra->incidencia('mod_compras', ['idReal' => '98766', 'vista' => 'albaran'], 'Datos en otro orden');

        $adjuntas = $this->incidencias()->incidenciasAdjuntas('98766', 'mod_compras', 'albaran');

        self::assertSame(['Datos en otro orden'], array_column($adjuntas, 'mensaje'));
    }

    private function incidencias(): \ClaseIncidencia
    {
        return new \ClaseIncidencia($this->db);
    }

    /** @return list<array{descripcion:string, valor:string}> */
    private function configuracion(string $mostrarSelector, int $usuarioPorDefecto): array
    {
        return [
            ['descripcion' => 'mostrar_Select', 'valor' => $mostrarSelector],
            ['descripcion' => 'usuario_Defecto', 'valor' => (string) $usuarioPorDefecto],
        ];
    }

    /** @return array<string, string> */
    private function fila(int $id): array
    {
        return $this->db->query('SELECT * FROM modulo_incidencia WHERE id = ' . $id)->fetch_assoc();
    }

    private function numeroDe(int $id): int
    {
        return (int) $this->fila($id)['num_incidencia'];
    }

    /**
     * Ejecuta la llamada y devuelve su resultado y, si termina con un error, su mensaje, para que
     * el caso pueda afirmar que no lo hay.
     *
     * @return array{0:mixed, 1:?string}
     */
    private static function capturando(callable $llamada): array
    {
        try {
            return [$llamada(), null];
        } catch (\Throwable $error) {
            return [null, $error->getMessage()];
        }
    }
}

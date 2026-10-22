<?php

/**
 * Las pantallas de las incidencias: el listado (`ListadoIncidencias.php`) y la ficha de un número
 * (`incidencia.php`).
 *
 * Cada pantalla se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas ya correcta: el arranque común abre su propia conexión. Las incidencias de cada caso se
 * dan de alta en esa conexión, dentro de una transacción que se deshace al terminar el proceso, así
 * que no quedan en la base.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModIncidencias;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasIncidenciasIntegracionTest extends CasoIntegracion
{
    /** Lo que se escribe en la página para ver si un valor guardado llega a ejecutarse. */
    private const SCRIPT = '<script>window.inyectado=1</script>';

    private int $idUsuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idUsuario = (new Siembra($this->db))->usuarioPorDefecto();
    }

    /**
     * @comportamiento El listado enseña cada número de incidencia una vez, con su última anotación
     *   —fecha, usuario, origen, mensaje y estado— y, si tiene más de una, cuántas; ofrece añadir una
     *   incidencia con la configuración del módulo y abrir la marcada.
     */
    public function test_elListadoEnsenaLaUltimaAnotacionDeCadaNumero(): void
    {
        $pantalla = $this->pintar('ListadoIncidencias.php', [], [
            $this->alta('La incidencia'),
            $this->alta('Su respuesta', '{}', 'Resuelto'),
        ]);

        self::assertMatchesRegularExpression('#<td>\d+\s*<div style="float:right">\s*<a class="glyphicon glyphicon-envelope"></a> 2\s*</div>#', $pantalla['html']);
        self::assertStringContainsString('<td>Su respuesta</td>', $pantalla['html']);
        self::assertStringNotContainsString('<td>La incidencia</td>', $pantalla['html']);
        self::assertStringContainsString("abrirModalIndicencia('listado Incidencias' , configuracion);", $pantalla['html']);
        self::assertStringContainsString("metodoClick('Ver','incidencia');", $pantalla['html']);
        self::assertStringContainsString('var configuracion=[{"descripcion":"mostrar_Select","valor":"Si"},{"descripcion":"usuario_Defecto","valor":"1"}];', $pantalla['html']);
    }

    /**
     * @comportamiento La ficha de un número enseña todas sus anotaciones, cada una con su fecha,
     *   estado, origen, datos, usuario que la creó y mensaje, y el usuario asignado si los datos lo
     *   traen; ofrece responder con ese número.
     */
    public function test_laFichaEnsenaTodasLasAnotacionesDelNumero(): void
    {
        $pantalla = $this->pintar('incidencia.php', ['id' => ':numero'], [
            $this->alta('La incidencia', '{"vista":"listado Incidencias","usuarioSelec":"' . $this->idUsuario . '"}'),
            $this->alta('Su respuesta', '{"vista":"incidencia"}', 'Pendiente'),
        ]);

        self::assertMatchesRegularExpression('#Datos de la incidencia Nº (\d+)</h2>#', $pantalla['html']);
        self::assertMatchesRegularExpression("#abrirModalIndicencia\('incidencia' , configuracion, \d+\);#", $pantalla['html']);
        self::assertStringContainsString('<textarea rows="4" cols="60" readonly>La incidencia</textarea>', $pantalla['html']);
        self::assertStringContainsString('<textarea rows="4" cols="60" readonly>Su respuesta</textarea>', $pantalla['html']);
        self::assertStringContainsString('id="Estado" name="Estado" value="Pendiente"', $pantalla['html']);
        self::assertStringContainsString('id="usuarioAsig" name="usuarioAsig" value="pruebas"', $pantalla['html']);
        self::assertSame(2, substr_count($pantalla['html'], 'id="usuario" name="usuario" value="pruebas"'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/ListadoIncidencias.php:25-32
     * @codigo-afectado modulos/mod_incidencias/ListadoIncidencias.php:49
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:40-45
     *
     * @group defecto
     * @group busqueda
     * @group medio
     *
     * @que-ocurre-hoy Buscar en el listado de incidencias termina la página con un error de la base:
     *   no se puede buscar ninguna incidencia.
     * @que-deberia-ocurrir Que la búsqueda liste las incidencias que tienen lo buscado.
     * @por-que-ocurre El listado busca en los campos del listado de albaranes de compra, que la
     *   consulta de incidencias no tiene, y su condición se pega detrás de la que ya trae la consulta,
     *   con un segundo `WHERE`. Aunque la consulta valiera, el recuento con búsqueda cuenta las claves
     *   de la respuesta, no las incidencias.
     * @como-deberia-funcionar Buscar en el mensaje y el usuario de la incidencia, unir la condición a
     *   la de la consulta y contar las filas.
     */
    public function test_buscarEnElListadoEncuentraLaIncidencia(): void
    {
        $pantalla = $this->pintar('ListadoIncidencias.php', ['buscar' => 'buscada'], [$this->alta('Incidencia buscada')]);

        self::assertTrue(str_contains($pantalla['html'], '<td>Incidencia buscada</td>'), 'La búsqueda tiene que listar la incidencia');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/ListadoIncidencias.php:33-36
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:29-32
     *
     * @group defecto
     * @group listado
     * @group bajo
     *
     * @que-ocurre-hoy El listado dice que hay tantas incidencias como el mayor número dado: si los
     *   números tienen un salto —una respuesta a un número que no existía—, el recuento y las páginas
     *   no corresponden a lo que hay.
     * @que-deberia-ocurrir Que el recuento sea el de incidencias distintas.
     * @por-que-ocurre Sin búsqueda, el recuento es el mayor número que leyó la clase al crearse.
     * @como-deberia-funcionar Contar los números distintos.
     */
    public function test_elRecuentoDelListadoEsElDeLasIncidencias(): void
    {
        $esperado = 1 + (int) $this->db->query('SELECT COUNT(DISTINCT num_incidencia) FROM modulo_incidencia')->fetch_row()[0];

        $pantalla = $this->pintar('ListadoIncidencias.php', [], [$this->alta('Con un salto en la numeración', '{}', 'No resuelto', ':numero + 49')]);

        self::assertSame((string) $esperado, $this->recuento($pantalla['html']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/ListadoIncidencias.php:127
     * @codigo-afectado modulos/mod_incidencias/clases/ClaseIncidencia.php:51-53
     *
     * @group defecto
     * @group listado
     * @group bajo
     *
     * @que-ocurre-hoy Sin ninguna incidencia, el listado deja avisos de PHP y no dice cuántas hay.
     * @que-deberia-ocurrir Que el listado salga vacío, con cero incidencias y sin avisos.
     * @por-que-ocurre La lista de filas solo se crea si hay alguna, y el listado la recorre siempre;
     *   el recuento es el mayor número, que sin filas es nulo.
     * @como-deberia-funcionar Devolver una lista vacía y contar cero.
     */
    public function test_sinIncidenciasElListadoSaleVacioSinAvisos(): void
    {
        $pantalla = $this->pintar('ListadoIncidencias.php', [], ['DELETE FROM modulo_incidencia']);

        self::assertStringNotContainsString('Undefined array key "NItems"', $pantalla['errores']);
        self::assertSame('0', $this->recuento($pantalla['html']));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/ListadoIncidencias.php:148-151
     *
     * @group defecto
     * @group listado
     * @group alto
     *
     * @que-ocurre-hoy Un mensaje de incidencia con un script se ejecuta en el listado, con la sesión
     *   de quien lo abre: cualquier usuario puede dejarlo escrito en una incidencia.
     * @que-deberia-ocurrir Que el mensaje se escriba como texto.
     * @por-que-ocurre El listado escribe el usuario, el origen, el mensaje y el estado tal como están
     *   guardados, sin escaparlos.
     * @como-deberia-funcionar `htmlspecialchars()` en cada valor.
     */
    public function test_elListadoEscribeLoGuardadoComoTexto(): void
    {
        $pantalla = $this->pintar('ListadoIncidencias.php', [], [$this->alta(self::SCRIPT)]);

        self::assertFalse(str_contains($pantalla['html'], self::SCRIPT), 'El mensaje se escribe tal cual en el listado');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/incidencia.php:29-33
     * @codigo-afectado modulos/mod_incidencias/incidencia.php:50-53
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Un enlace a la ficha con un script en el número —detrás de un comentario de la
     *   base, para que la consulta valga— lo ejecuta en la página de quien lo abre.
     * @que-deberia-ocurrir Que el número se tome como número y no se escriba nada más.
     * @por-que-ocurre La ficha escribe el número de la dirección tal como llega, en el título y en la
     *   llamada de «Responder incidencia».
     * @como-deberia-funcionar Tomar el número como entero antes de usarlo.
     */
    public function test_elNumeroDeLaDireccionNoSeEscribeTalCual(): void
    {
        $pantalla = $this->pintar('incidencia.php', ['id' => ':numero -- ' . self::SCRIPT], [$this->alta('La incidencia')]);

        self::assertFalse(str_contains($pantalla['html'], self::SCRIPT), 'El número de la dirección se escribe tal cual en la ficha');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/incidencia.php:78-108
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Un mensaje que cierra el área de texto y trae un script se ejecuta en la ficha,
     *   con la sesión de quien la abre.
     * @que-deberia-ocurrir Que el mensaje se escriba como texto.
     * @por-que-ocurre La ficha escribe el estado, el origen, el usuario, los datos y el mensaje tal
     *   como están guardados, sin escaparlos.
     * @como-deberia-funcionar `htmlspecialchars()` en cada valor.
     */
    public function test_laFichaEscribeLoGuardadoComoTexto(): void
    {
        $pantalla = $this->pintar('incidencia.php', ['id' => ':numero'], [$this->alta('</textarea>' . self::SCRIPT)]);

        self::assertFalse(str_contains($pantalla['html'], self::SCRIPT), 'El mensaje se escribe tal cual en la ficha');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/incidencia.php:62-65
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy El usuario asignado que traen los datos de una incidencia se pega a una consulta
     *   de usuarios: unos datos con una condición añadida —«-1 OR 1=1»— enseñan como asignado un
     *   usuario que no lo es. Los datos los envía el navegador al guardar.
     * @que-deberia-ocurrir Que el usuario asignado se tome como número: con uno que no existe, no hay
     *   asignado.
     * @por-que-ocurre El valor de los datos se pega a la sentencia sin comprobar que sea un número.
     * @como-deberia-funcionar Tomarlo como entero o pasarlo como parámetro.
     */
    public function test_elUsuarioAsignadoSeTomaComoNumero(): void
    {
        $pantalla = $this->pintar('incidencia.php', ['id' => ':numero'], [$this->alta('Con datos manipulados', '{"usuarioSelec":"-1 OR 1=1"}')]);

        self::assertFalse(str_contains($pantalla['html'], 'id="usuarioAsig"'), 'Con un usuario que no existe no hay asignado');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/incidencia.php:58-69
     * @codigo-afectado modulos/mod_incidencias/incidencia.php:87-94
     *
     * @group defecto
     * @group entrada
     * @group bajo
     *
     * @que-ocurre-hoy Las anotaciones que siguen a una con usuario asignado enseñan ese mismo usuario
     *   como asignado, aunque no lo tengan.
     * @que-deberia-ocurrir Que cada anotación enseñe solo su usuario asignado.
     * @por-que-ocurre El usuario asignado se guarda en una variable que no se vacía en cada anotación.
     * @como-deberia-funcionar Vaciarla al empezar cada anotación.
     */
    public function test_elUsuarioAsignadoNoPasaALasAnotacionesSiguientes(): void
    {
        $pantalla = $this->pintar('incidencia.php', ['id' => ':numero'], [
            $this->alta('Asignada', '{"usuarioSelec":"' . $this->idUsuario . '"}'),
            $this->alta('Respuesta sin asignar'),
        ]);

        self::assertSame(1, substr_count($pantalla['html'], 'id="usuarioAsig"'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/incidencia.php:26-33
     * @codigo-afectado modulos/mod_incidencias/incidencia.php:58
     *
     * @group defecto
     * @group entrada
     * @group bajo
     *
     * @que-ocurre-hoy Abrir la ficha sin número deja avisos de PHP y una ficha vacía.
     * @que-deberia-ocurrir Que sin número no se pinte una ficha: volver al listado, o avisar.
     * @por-que-ocurre Las anotaciones solo se consultan si llega el número, pero se recorren siempre.
     * @como-deberia-funcionar Comprobar el número antes de pintar la ficha.
     */
    public function test_laFichaSinNumeroNoDejaAvisos(): void
    {
        $pantalla = $this->pintar('incidencia.php');

        self::assertStringNotContainsString('Undefined variable $datosIncidencias', $pantalla['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/ListadoIncidencias.php:90
     * @codigo-afectado modulos/mod_incidencias/acces.xml:4
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Un usuario sin el permiso de crear incidencias ve «Añadir» en el listado y puede
     *   darlas de alta: el permiso se declara en el módulo, pero nadie lo comprueba.
     * @que-deberia-ocurrir Que sin el permiso no se ofrezca añadir.
     * @por-que-ocurre Ni el listado ni las tareas del módulo consultan los permisos de sus acciones.
     * @como-deberia-funcionar Ofrecer cada acción según su permiso, y comprobarlo también al guardar.
     */
    public function test_sinPermisoDeCrearNoSeOfreceAnadir(): void
    {
        $pantalla = $this->pintar('ListadoIncidencias.php', [], [$this->quitarPermiso('ListadoIncidencias.php', 'crear')]);

        self::assertFalse(str_contains($pantalla['html'], "abrirModalIndicencia('listado Incidencias'"), 'Sin el permiso de crear se ofrece «Añadir»');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/incidencia.php:53
     * @codigo-afectado modulos/mod_incidencias/acces.xml:8
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Un usuario sin el permiso de responder ve «Responder incidencia» en la ficha y
     *   puede responder: el permiso se declara en el módulo, pero nadie lo comprueba.
     * @que-deberia-ocurrir Que sin el permiso no se ofrezca responder.
     * @por-que-ocurre Ni la ficha ni las tareas del módulo consultan los permisos de sus acciones.
     * @como-deberia-funcionar Ofrecer cada acción según su permiso, y comprobarlo también al guardar.
     */
    public function test_sinPermisoDeResponderNoSeOfreceResponder(): void
    {
        $pantalla = $this->pintar('incidencia.php', ['id' => ':numero'], [
            $this->alta('La incidencia'),
            $this->quitarPermiso('incidencia.php', 'responder'),
        ]);

        self::assertFalse(str_contains($pantalla['html'], "abrirModalIndicencia('incidencia'"), 'Sin el permiso de responder se ofrece «Responder incidencia»');
    }

    /** @comportamiento El listado pinta 40 incidencias por página: con 41, la segunda página tiene una. */
    public function test_laSegundaPaginaDelListadoTieneLasQueNoCabenEnLaPrimera(): void
    {
        $preparacion = ['DELETE FROM modulo_incidencia'];
        for ($i = 0; $i < 41; $i++) {
            $preparacion[] = $this->alta('Incidencia ' . $i, '{}', 'No resuelto', ':numero + ' . $i);
        }

        $pantalla = $this->pintar('ListadoIncidencias.php', ['pagina' => '2'], $preparacion);

        self::assertSame(1, substr_count($pantalla['html'], '<td class="rowUsuario">'));
    }

    /** @comportamiento La ficha de un número sin anotaciones sale vacía, sin avisos. */
    public function test_laFichaDeUnNumeroSinAnotacionesSaleVacia(): void
    {
        $pantalla = $this->pintar('incidencia.php', ['id' => ':numero']);

        self::assertMatchesRegularExpression('#Datos de la incidencia Nº \d+</h2>#', $pantalla['html']);
        self::assertStringNotContainsString('id="Estado"', $pantalla['html']);
        self::assertStringNotContainsString('Warning', $pantalla['errores']);
    }

    /** @comportamiento Un usuario asignado que no existe no se enseña en la ficha. */
    public function test_unAsignadoQueNoExisteNoSeEnsena(): void
    {
        $pantalla = $this->pintar('incidencia.php', ['id' => ':numero'], [$this->alta('Asignada a nadie', '{"usuarioSelec":"-1"}')]);

        self::assertStringContainsString('Asignada a nadie', $pantalla['html']);
        self::assertStringNotContainsString('id="usuarioAsig"', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_incidencias/ListadoIncidencias.php:92
     * @codigo-afectado modulos/mod_incidencias/acces.xml:5
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Un usuario sin el permiso de modificar incidencias ve «Modificar» en el listado:
     *   el permiso se declara en el módulo, pero nadie lo comprueba.
     * @que-deberia-ocurrir Que sin el permiso no se ofrezca modificar.
     * @por-que-ocurre El listado no consulta los permisos de sus acciones.
     * @como-deberia-funcionar Ofrecer cada acción según su permiso.
     */
    public function test_sinPermisoDeModificarNoSeOfreceModificar(): void
    {
        $pantalla = $this->pintar('ListadoIncidencias.php', [], [$this->quitarPermiso('ListadoIncidencias.php', 'modificar')]);

        self::assertFalse(str_contains($pantalla['html'], "metodoClick('Ver','incidencia')"), 'Sin el permiso de modificar se ofrece «Modificar»');
    }

    /**
     * @estado rojo
     * @codigo-afectado head.php:17-26
     * @codigo-afectado modulos/mod_incidencias/ListadoIncidencias.php:6-7
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Un usuario con sesión pero sin el permiso de la vista del listado lo abre por su
     *   dirección y lee las incidencias, con el nombre de los trabajadores y el texto libre.
     * @que-deberia-ocurrir Que el listado no se sirva sin su permiso de vista.
     * @por-que-ocurre Ni la cabecera común ni el listado consultan el permiso de la vista servida.
     * @como-deberia-funcionar Comprobar el permiso de la vista antes de pintarla.
     */
    public function test_sinPermisoDeVistaNoSeSirveElListado(): void
    {
        $pantalla = $this->pintar('ListadoIncidencias.php', [], [$this->quitarPermisoDeVista('ListadoIncidencias.php'), $this->alta('Solo para quien puede verla')]);

        self::assertFalse(str_contains($pantalla['html'], 'Solo para quien puede verla'), 'Sin el permiso de la vista se sirve el listado');
    }

    /**
     * @estado rojo
     * @codigo-afectado head.php:17-26
     * @codigo-afectado modulos/mod_incidencias/incidencia.php:6-7
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Un usuario con sesión pero sin el permiso de la vista de la ficha la abre por su
     *   dirección y lee todas las anotaciones de un número.
     * @que-deberia-ocurrir Que la ficha no se sirva sin su permiso de vista.
     * @por-que-ocurre Ni la cabecera común ni la ficha consultan el permiso de la vista servida.
     * @como-deberia-funcionar Comprobar el permiso de la vista antes de pintarla.
     */
    public function test_sinPermisoDeVistaNoSeSirveLaFicha(): void
    {
        $pantalla = $this->pintar('incidencia.php', ['id' => ':numero'], [$this->quitarPermisoDeVista('incidencia.php'), $this->alta('Solo para quien puede verla')]);

        self::assertFalse(str_contains($pantalla['html'], 'Solo para quien puede verla'), 'Sin el permiso de la vista se sirve la ficha');
    }

    /**
     * Pinta una pantalla del módulo en un PHP aparte y devuelve su HTML y lo que escribe en la salida
     * de errores.
     *
     * Tras el arranque común abre una transacción en la conexión de la pantalla, da al usuario todos
     * los permisos del módulo —los de quien tiene acceso a sus pantallas, para que lo que se
     * comprueba no dependa de que hoy se pinten también sin ellos—, ejecuta las sentencias de
     * preparación y vuelve a leer sus permisos; al terminar el proceso la transacción se deshace. En las sentencias y en la dirección, `:numero` es el siguiente número
     * de incidencia libre y `:usuario`, el usuario de pruebas.
     *
     * @param array<string,string> $direccion
     * @param list<string> $preparacion
     * @return array{html:string, errores:string}
     */
    private function pintar(string $pantalla, array $direccion = [], array $preparacion = []): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => 1, 'razonsocial' => 'Tienda de pruebas'],
        ];
        $ruta = '/TPVFox/modulos/mod_incidencias/' . $pantalla;
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export($ruta, true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_incidencias', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' $sustituir = [":numero" => (string) $BDTpv->query("SELECT COALESCE(MAX(num_incidencia), 0) + 1 FROM modulo_incidencia")->fetch_row()[0], ":usuario" => "' . $this->idUsuario . '"];'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_incidencias\'");'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sustituir)); }'
            . ' $_GET = array_map(fn ($valor) => strtr($valor, $sustituir), ' . var_export($direccion, true) . ');'
            . ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);'
            . ' include ' . var_export($pantalla, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'incidencias');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        $pantallaPintada = ['html' => implode("\n", $salida), 'errores' => (string) file_get_contents($errores)];
        unlink($errores);

        return $pantallaPintada;
    }

    /** La sentencia que da de alta una anotación del usuario de pruebas con el número que se indique. */
    private function alta(string $mensaje, string $datos = '{}', string $estado = 'No resuelto', string $numero = ':numero'): string
    {
        return sprintf(
            "INSERT INTO modulo_incidencia (num_incidencia, fecha_creacion, id_usuario, dedonde, mensaje, datos, estado) VALUES (%s, NOW(), :usuario, 'mod_incidencia', '%s', '%s', '%s')",
            $numero,
            $this->db->real_escape_string($mensaje),
            $this->db->real_escape_string($datos),
            $this->db->real_escape_string($estado)
        );
    }

    /** La sentencia que quita al usuario de pruebas el permiso de una vista del módulo. */
    private function quitarPermisoDeVista(string $vista): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_incidencias' AND vista = '$vista' AND accion IS NULL";
    }

    /** La sentencia que quita al usuario de pruebas el permiso de una acción del módulo. */
    private function quitarPermiso(string $vista, string $accion): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_incidencias' AND vista = '$vista' AND accion = '$accion'";
    }

    /** El número de incidencias que el listado dice haber encontrado. */
    private function recuento(string $html): string
    {
        return preg_match('#filtrados:\s*(\S*)\s*</p>#', $html, $coincidencia) === 1 ? $coincidencia[1] : '(sin recuento)';
    }
}

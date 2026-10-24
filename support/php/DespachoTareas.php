<?php

/**
 * Invoca un `tareas.php` (endpoint unico por modulo, switch sobre `$_POST['pulsado']`,
 * `echo json_encode($respuesta)`) como lo haria una peticion real, sin pasar por HTTP.
 *
 * Tres cosas que `tareas.php` da por hechas y que aqui hay que resolver a mano:
 *
 *  - Usa `$BDTpv` como variable global, no como parametro: hay que ponerla en el ambito
 *    global antes de incluir el fichero.
 *  - Su primera linea es `include_once("./../../inicial.php")` (mismo patron que
 *    `funciones.php`, ver `CargaAislada`): se aisla igual, para que no abra una segunda
 *    conexion real via `ClaseSession`. El resto de sus includes usan `$URLCom` (absoluto,
 *    ya fijado por `bootstrap.php`), asi que no les afecta.
 *  - Termina con `echo json_encode($respuesta); return $respuesta;`: el `echo` se captura
 *    y se descarta (un test no debe imprimir), y el valor util es el que `require` devuelve.
 *
 * `require` (no `require_once`) porque cada caso de esta clase es una peticion distinta:
 * el fichero debe re-ejecutar su `switch` en cada llamada. Los `include_once` que hace
 * `tareas.php` internamente (funciones.php, las clases del modulo) solo se cargan una vez
 * por proceso, como en una peticion real.
 *
 * Una trampa que costo encontrar: `tareas.php` tambien hace `include_once
 * $URLCom.'/configuracion.php'`. Sea cual sea el ambito donde esa inclusion ocurra POR
 * PRIMERA VEZ en todo el proceso de PHPUnit —esta clase u otra parte cualquiera de la
 * suite—, las variables de nivel superior que asigna (`$rutatmp`, `$ruta_upload`...)
 * quedan atadas al ambito local de ESA llamada si no estaban declaradas `global` alli. En
 * cualquier invocacion posterior, con un ambito nuevo, `include_once` ya no vuelve a
 * ejecutar el fichero — y esas variables no llegan a existir en absoluto para el caso de
 * despacho que las necesite (`datosImprimir` con `$rutatmp`), que escribe donde no toca,
 * en silencio. Depender de que la primera vez ocurra en un sitio que ya las declare
 * `global` es fragil (afecto que otro test se ejecute antes). Por eso `configuracion.php`
 * se carga aqui explicitamente, con `require` —no `require_once`— cada vez: el fichero
 * solo asigna variables, repetirlo no tiene efecto colateral, y con estas ya declaradas
 * `global` antes de esa linea la asignacion va siempre al ambito global de verdad.
 */

declare(strict_types=1);

namespace TPVFox\Test;

final class DespachoTareas
{
    /**
     * Sin tipo de retorno declarado a proposito: un caso de despacho hacia un fichero de
     * `tareas/` borrado deja `$respuesta` sin asignar, y `tareas.php` devuelve `null`. Esa
     * es la propia evidencia de ese defecto — forzar aqui un tipo `array` la enmascararia
     * con un `TypeError` ajeno.
     *
     * `$variables` son las que `inicial.php` dejaria definidas y el caso necesita, como los
     * permisos del usuario de la sesion (`ClasePermisos`): se declaran con su nombre en el
     * ambito en que corre `tareas.php`.
     *
     * @param array<string,mixed> $post
     * @param array<string,mixed> $variables
     */
    public static function invocar(string $rutaAbsolutaTareasPhp, \mysqli $conexion, array $post, array $variables = []): mixed
    {
        // require dentro de un metodo ejecuta el fichero en el ambito local del metodo, no
        // en el global: sin declararlas aqui, $RutaServidor/$HostNombre/$URLCom (fijadas
        // por bootstrap.php) serian invisibles para tareas.php y sus include_once con
        // $URLCom fallarian en silencio (el error_handler de abajo los enmascara).
        global $RutaServidor, $HostNombre, $URLCom, $BDTpv;
        global $RutaDatos, $servidorMysql, $nombrebdMysql, $usuarioMysql, $passwordMysql;
        global $rutatmp, $ruta_upload, $ruta_segura, $CONF_campoPeso;
        global $email_direccion_origen, $email_usuario_origen, $PHPMAILER_CONF;
        $BDTpv = $conexion;
        require RUTA_TPVFOX . '/configuracion.php';

        $_POST = $post;
        extract($variables);

        // tareas.php corta con `exit` la petición que llega sin sesión iniciada, y ese `exit` cerraría
        // PHPUnit. Un caso que no fija `estadoTpv` despacha como un usuario que ha entrado, que es como
        // despachaba antes de la puerta; el que lo fija lo conserva. Al terminar se deja como estaba.
        $sinEstadoDeSesion = !isset($_SESSION['estadoTpv']);
        if ($sinEstadoDeSesion) {
            $_SESSION['estadoTpv'] = 'Correcto';
        }

        $cwdOriginal = getcwd();
        chdir(sys_get_temp_dir());
        set_error_handler(static fn (): bool => true);
        ob_start();
        try {
            $respuesta = require $rutaAbsolutaTareasPhp;
        } finally {
            ob_end_clean();
            restore_error_handler();
            chdir($cwdOriginal);
            $_POST = [];
            if ($sinEstadoDeSesion) {
                unset($_SESSION['estadoTpv']);
            }
        }

        return $respuesta;
    }
}

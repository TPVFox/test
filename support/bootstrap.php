<?php
/**
 * Arranque de la suite PHPUnit.
 *
 * No abre ninguna conexion ni carga codigo de TPVFox: solo deja resuelta la ruta del
 * codigo bajo prueba y el autocargador de los apoyos de la propia suite. TPVFox no
 * tiene autocarga, de modo que cada caso incluye explicitamente los ficheros que
 * necesita a partir de RUTA_TPVFOX.
 */

declare(strict_types=1);

$autocargador = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autocargador)) {
    fwrite(STDERR, "Falta vendor/: ejecuta «composer install» antes de lanzar la suite.\n");
    exit(1);
}
require_once $autocargador;

// Ruta del clon de TPVFox contra el que se prueba. Por defecto, repositorio hermano.
$rutaCodigo = getenv('TPVFOX_PATH') ?: __DIR__ . '/../../TPVFox';
$rutaCodigo = realpath($rutaCodigo);

if ($rutaCodigo === false || !is_dir($rutaCodigo . '/modulos')) {
    fwrite(STDERR,
        "No se encuentra el codigo bajo prueba.\n" .
        "Se esperaba un clon de TPVFox en ../TPVFox o en la ruta de TPVFOX_PATH.\n");
    exit(1);
}

define('RUTA_TPVFOX', $rutaCodigo);

// TPVFox no tiene autocarga: cada fichero que se incluye resuelve sus propios includes
// con estas tres variables globales, tal como hace inicial.php en producción. Sin
// definirlas aquí, cada caso de integración tendría que repetirlas.
$RutaServidor = '';
$HostNombre = RUTA_TPVFOX;
$URLCom = RUTA_TPVFOX;

// Formatos de fecha que inicial.php define en produccion antes de cargar nada mas. La
// suite no incluye inicial.php —abre conexion y sesion—, asi que los repite aqui con el
// mismo valor: sin ellos, cualquier fichero del producto que feche un registro falla por
// constante indefinida en cuanto un caso lo alcanza.
define('FORMATO_FECHA_ES', 'd-m-Y H:i:s');
define('FORMATO_FECHA_MYSQL', 'Y-m-d H:i:s');
define('FORMATO_FECHA_INPUT', 'Y-m-d');

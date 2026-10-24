<?php
/**
 * Lee de la base una tarea programada por su nombre.
 *
 * Existe para los recorridos de navegador: la lista no enseña todo lo que se guarda de una tarea,
 * y lo que una petición sin sesión deja escrito solo se puede comprobar leyéndolo de la base. Solo
 * lee, y solo de la base de pruebas.
 *
 * Uso:  php support/leer-tarea.php <nombre>
 *
 * Devuelve en JSON la última tarea con ese nombre —`null` si no hay ninguna—.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;

$nombre = $argv[1] ?? '';
if ($nombre === '') {
    fwrite(STDERR, "Uso: php support/leer-tarea.php <nombre>\n");
    exit(1);
}

$base = Entorno::valor('TPVFOX_TEST_DB_VIGENTE');
if (strpos($base, 'tpvfox_test') !== 0) {
    fwrite(STDERR, "La base «{$base}» no empieza por «tpvfox_test»: no se lee.\n");
    exit(1);
}

$db = new mysqli(
    Entorno::valor('TPVFOX_TEST_DB_HOST', 'localhost'),
    Entorno::valor('TPVFOX_TEST_DB_USER'),
    Entorno::valor('TPVFOX_TEST_DB_PASS'),
    $base
);
$db->set_charset('utf8mb4');

$sentencia = $db->prepare(
    'SELECT id, nombre, cantidad_periodo, tipo_periodo, nombre_clase, inicio_ejecucion, ultima_ejecucion, estado'
    . ' FROM tareas_cron WHERE nombre = ? ORDER BY id DESC LIMIT 1'
);
$sentencia->bind_param('s', $nombre);
$sentencia->execute();
$fila = $sentencia->get_result()->fetch_assoc();

if ($fila !== null) {
    foreach (['id', 'cantidad_periodo', 'tipo_periodo', 'estado'] as $campo) {
        $fila[$campo] = (int) $fila[$campo];
    }
}

echo json_encode(['tarea' => $fila], JSON_UNESCAPED_UNICODE) . "\n";

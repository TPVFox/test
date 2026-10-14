<?php
/**
 * Siembra persistente para los recorridos E2E de las tareas programadas.
 *
 * Borra las tareas con el prefijo `[E2E tareas]`, con sus entradas del diario, y vuelve a crear
 * las que usan los recorridos: los recorridos dan de alta, modifican y borran tareas, y cada
 * pasada tiene que partir de las mismas.
 *
 * Uso: php support/sembrar-e2e-tareas.php
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\EscenarioTareasCron;

const PREFIJO = '[E2E tareas] ';

$base = Entorno::valor('TPVFOX_TEST_DB_VIGENTE');
if ($base === '' || strpos($base, 'tpvfox_test') !== 0) {
    fwrite(STDERR, "Falta TPVFOX_TEST_DB_VIGENTE o no empieza por «tpvfox_test»: no se toca.\n");
    exit(1);
}

$db = new mysqli(
    Entorno::valor('TPVFOX_TEST_DB_HOST', 'localhost'),
    Entorno::valor('TPVFOX_TEST_DB_USER'),
    Entorno::valor('TPVFOX_TEST_DB_PASS'),
    $base
);
if ($db->connect_errno) {
    fwrite(STDERR, "No se pudo conectar con «{$base}»: error {$db->connect_errno}.\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$patron = PREFIJO . '%';
$borrado = $db->prepare('DELETE d FROM diario_cron d JOIN tareas_cron t ON t.id = d.tarea_id WHERE t.nombre LIKE ?');
$borrado->bind_param('s', $patron);
$borrado->execute();
$borrado = $db->prepare('DELETE FROM tareas_cron WHERE nombre LIKE ?');
$borrado->bind_param('s', $patron);
$borrado->execute();

$tareas = new EscenarioTareasCron($db);
$hoy = date('Y-m-d');
$pasado = '2020-01-01';

// La lista y sus botones.
$tareas->tarea(PREFIJO . 'Para listar', 'AcumuladoComprasTarea', ['cantidad' => 1, 'tipo' => 3, 'inicio' => $pasado]);
$tareas->tarea(PREFIJO . 'De baja', 'UltimoPrecioCompraTarea', ['inicio' => $pasado, 'estado' => 0]);
$tareas->tarea(PREFIJO . 'Para borrar', 'AcumuladoComprasTarea', ['inicio' => $pasado]);
$tareas->tarea(PREFIJO . 'Para borrar sin sesión', 'AcumuladoComprasTarea', ['inicio' => $pasado]);
$tareas->tarea(PREFIJO . '<b id=e2e-inyectado>x</b>', 'AcumuladoComprasTarea', ['inicio' => $pasado]);

// La ficha. Guardada sin tocar nada, la ficha envía siempre minutos como tipo de período y hoy
// como fecha de inicio. La primera ya tiene los dos; las otras dos se apartan en uno cada una.
$tareas->tarea(PREFIJO . 'Guardada sin cambios', 'AcumuladoComprasTarea', ['cantidad' => 60, 'tipo' => 1, 'inicio' => $hoy]);
$tareas->tarea(PREFIJO . 'Con inicio pasado', 'AcumuladoComprasTarea', ['cantidad' => 60, 'tipo' => 1, 'inicio' => $pasado]);
$tareas->tarea(PREFIJO . 'Cada dos horas', 'AcumuladoComprasTarea', ['cantidad' => 2, 'tipo' => 2, 'inicio' => $hoy]);

echo "Tareas de los recorridos sembradas.\n";

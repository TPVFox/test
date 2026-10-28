<?php
/**
 * Prepara el recorrido de una cadena de recetas, sobre la siembra de
 * `preparar-e2e-aplicar-relacion.php escenario`, que hay que lanzar antes.
 *
 * Uso: php support/preparar-e2e-aplicar-relacion-cadena.php [vigente|anterior]
 *
 * Siembra el muslo deshuesado, el hueso y el filete, con su coste de proveedor, y dos relaciones del
 * mismo tipo que el despiece del pollo de los recorridos: el deshuesado del muslo, del que salen 0,7 kg
 * de muslo deshuesado y 0,25 kg de hueso por kilo, y el fileteado del muslo deshuesado, del que salen
 * 0,9 kg de filete. Todo lleva el prefijo de los recorridos, así que lo retira el escenario de la
 * pasada siguiente.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/siembra/Siembra.php';
require_once __DIR__ . '/siembra/EscenarioRelaciones.php';

use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

const PREFIJO = '[E2E aplicación] ';

$ejercicio = $argv[1] ?? 'vigente';
$variable = $ejercicio === 'anterior' ? 'TPVFOX_TEST_DB_ANTERIOR' : 'TPVFOX_TEST_DB_VIGENTE';
$base = Entorno::valor($variable);
if ($base === '' || strpos($base, 'tpvfox_test') !== 0) {
    fwrite(STDERR, "Falta $variable o no empieza por «tpvfox_test»: no se toca.\n");
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

function idDe(mysqli $db, string $tabla, string $columnaId, string $columnaNombre, string $nombre): int
{
    $buscar = $db->prepare("SELECT $columnaId FROM $tabla WHERE $columnaNombre = ? ORDER BY $columnaId DESC LIMIT 1");
    $buscar->bind_param('s', $nombre);
    $buscar->execute();
    $fila = $buscar->get_result()->fetch_row();
    if ($fila === null) {
        fwrite(STDERR, "No existe «{$nombre}» en {$tabla}: siembra antes el escenario.\n");
        exit(1);
    }

    return (int) $fila[0];
}

$siembra = new Siembra($db);
$relaciones = new EscenarioRelaciones($db);
$granja = idDe($db, 'proveedores', 'idProveedor', 'razonsocial', PREFIJO . 'Granja');
$tipo = idDe($db, 'relacionesTipos', 'id', 'nombre', PREFIJO . 'Despiece');

$muslo = idDe($db, 'articulos', 'idArticulo', 'articulo_name', PREFIJO . 'Muslo');
$ids = [];
foreach (['Muslo deshuesado' => 7.0, 'Hueso' => 0.2, 'Filete' => 9.0] as $nombre => $precio) {
    $ids[$nombre] = $siembra->articulo(PREFIJO . $nombre, ['iva' => 10]);
    $siembra->articuloProveedor($ids[$nombre], $granja, $precio);
}
$deshuesado = $relaciones->relacionVigente($tipo, [
    ['idArticulo' => $muslo, 'sentido' => 'entrada', 'cantidad' => 1],
    ['idArticulo' => $ids['Muslo deshuesado'], 'sentido' => 'salida', 'cantidad' => 0.7],
    ['idArticulo' => $ids['Hueso'], 'sentido' => 'salida', 'cantidad' => 0.25],
], ['nombre' => PREFIJO . 'Deshuesado del muslo', 'fecha' => '2026-01-10 09:00:00']);
$fileteado = $relaciones->relacionVigente($tipo, [
    ['idArticulo' => $ids['Muslo deshuesado'], 'sentido' => 'entrada', 'cantidad' => 1],
    ['idArticulo' => $ids['Filete'], 'sentido' => 'salida', 'cantidad' => 0.9],
], ['nombre' => PREFIJO . 'Fileteado', 'fecha' => '2026-01-10 09:00:00']);

echo json_encode(['numDeshuesado' => $deshuesado['numRelacion'], 'numFileteado' => $fileteado['numRelacion']]);

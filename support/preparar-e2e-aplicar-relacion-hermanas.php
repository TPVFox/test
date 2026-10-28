<?php
/**
 * Prepara los recorridos de las recetas hermanas, sobre la siembra de
 * `preparar-e2e-aplicar-relacion.php escenario`, que hay que lanzar antes.
 *
 * Uso: php support/preparar-e2e-aplicar-relacion-hermanas.php [vigente|anterior]
 *
 * Siembra la picada, con su coste de proveedor, y el pollo para picada: una relación del mismo tipo
 * que el despiece del pollo de los recorridos, con el mismo producto de partida, de la que sale
 * 0,98 kg de picada por kilo. Todo lleva el prefijo de los recorridos, así que lo retira el escenario
 * de la pasada siguiente.
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
$pollo = idDe($db, 'articulos', 'idArticulo', 'articulo_name', PREFIJO . 'Pollo entero');
$granja = idDe($db, 'proveedores', 'idProveedor', 'razonsocial', PREFIJO . 'Granja');
$tipo = idDe($db, 'relacionesTipos', 'id', 'nombre', PREFIJO . 'Despiece');

$picada = $siembra->articulo(PREFIJO . 'Picada', ['iva' => 10]);
$siembra->articuloProveedor($picada, $granja, 5.0);
$relacion = $relaciones->relacionVigente($tipo, [
    ['idArticulo' => $pollo, 'sentido' => 'entrada', 'cantidad' => 1],
    ['idArticulo' => $picada, 'sentido' => 'salida', 'cantidad' => 0.98],
], ['nombre' => PREFIJO . 'Pollo para picada', 'fecha' => '2026-01-10 09:00:00']);

echo json_encode(['numPicada' => $relacion['numRelacion']]);

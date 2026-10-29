<?php
/**
 * Prepara el recorrido de una pieza sin precio de mercado, sobre la siembra de
 * `preparar-e2e-aplicar-relacion.php escenario`, que hay que lanzar antes.
 *
 * Uso: php support/preparar-e2e-aplicar-relacion-sin-precio.php [vigente|anterior]
 *
 * Quita los costes de proveedor de las alas del despiece del pollo de los recorridos: se quedan sin
 * precio de mercado. El escenario de cada recorrido las vuelve a sembrar con su coste.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;

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

$alas = idDe($db, 'articulos', 'idArticulo', 'articulo_name', PREFIJO . 'Alas');
$db->query("DELETE FROM articulosProveedores WHERE idArticulo = $alas");
echo json_encode(['alas' => $alas]);

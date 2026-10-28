<?php
/**
 * Prepara el recorrido de una pieza que queda en negativo, sobre la siembra de
 * `preparar-e2e-aplicar-relacion.php escenario`, que hay que lanzar antes.
 *
 * Uso: php support/preparar-e2e-aplicar-relacion-desajuste.php [vigente|anterior]
 *
 * Siembra un ticket cerrado del 22 de febrero de 2026 con 40 kg de la pechuga de los recorridos, que
 * no tuvo ninguna entrada: con 100 kg de pollo la receta da 25 kg y la pechuga queda en negativo.
 * El ticket lleva un artículo con el prefijo de los recorridos, así que lo retira el escenario de la
 * pasada siguiente.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/siembra/Siembra.php';

use TPVFox\Test\Entorno;
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

$nombre = PREFIJO . 'Pechuga';
$buscar = $db->prepare('SELECT idArticulo FROM articulos WHERE articulo_name = ? ORDER BY idArticulo DESC LIMIT 1');
$buscar->bind_param('s', $nombre);
$buscar->execute();
$fila = $buscar->get_result()->fetch_row();
if ($fila === null) {
    fwrite(STDERR, "No existe «{$nombre}»: siembra antes el escenario.\n");
    exit(1);
}

(new Siembra($db))->ventaTicket((int) $fila[0], 40, '2026-02-22');

echo "40 kg de pechuga vendidos el 22 de febrero, sin entradas.\n";

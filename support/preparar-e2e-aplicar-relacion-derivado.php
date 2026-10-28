<?php
/**
 * Prepara el recorrido del pollo como derivado de otra aplicación, sobre la siembra de
 * `preparar-e2e-aplicar-relacion.php escenario`, que hay que lanzar antes.
 *
 * Uso: php support/preparar-e2e-aplicar-relacion-derivado.php [vigente|anterior]
 *
 * Siembra una canal de la que sale el pollo, su despiece, y un albarán guardado del proveedor
 * especial de los recorridos en febrero de 2026 con la canal despiezada y el pollo que sale de
 * ella, con la relación y la versión en el detalle de cada línea, como lo deja una aplicación.
 * Todo lleva el prefijo de los recorridos, así que lo retira el escenario de la pasada siguiente.
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
$especial = idDe($db, 'proveedores', 'idProveedor', 'razonsocial', PREFIJO . 'Despiece del obrador');
$tipo = idDe($db, 'relacionesTipos', 'id', 'nombre', PREFIJO . 'Despiece');

$canal = $siembra->articulo(PREFIJO . 'Canal', ['iva' => 10]);
$deLaCanal = $relaciones->relacionVigente($tipo, [
    ['idArticulo' => $canal, 'sentido' => 'entrada', 'cantidad' => 1],
    ['idArticulo' => $pollo, 'sentido' => 'salida', 'cantidad' => 0.8],
], ['fecha' => '2026-01-10 09:00:00']);
$albaran = $siembra->entradaProveedor($canal, -12.5, '2026-02-15', ['idProveedor' => $especial]);
$siembra->lineaEntrada($albaran, $pollo, 10.0);
$etiqueta = $db->real_escape_string(' · REL ' . $deLaCanal['numRelacion'] . ' v1.0');
$db->query("UPDATE albprolinea SET cdetalle = CONCAT('Línea', '$etiqueta') WHERE idalbpro = $albaran");

echo "El pollo, derivado de la canal en un albarán del especial de febrero.\n";

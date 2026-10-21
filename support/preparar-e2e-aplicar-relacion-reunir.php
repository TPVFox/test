<?php
/**
 * Prepara los recorridos de reunir despieces en el albarán del mes, sobre la siembra de
 * `preparar-e2e-aplicar-relacion.php escenario`.
 *
 * Uso: php support/preparar-e2e-aplicar-relacion-reunir.php temporal [vigente|anterior]
 *
 *   temporal   deja un albarán temporal abierto del proveedor especial, de fin de febrero de 2026
 *              y con el número del tipo de relación del escenario cortado a 20 caracteres, con una
 *              línea de otro producto. Escribe en JSON su identificador
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/siembra/Siembra.php';

use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\Siembra;

const PREFIJO = '[E2E aplicación] ';

$accion = $argv[1] ?? '';
if ($accion !== 'temporal') {
    fwrite(STDERR, "Uso: php support/preparar-e2e-aplicar-relacion-reunir.php temporal [vigente|anterior]\n");
    exit(1);
}
$variable = ($argv[2] ?? 'vigente') === 'anterior' ? 'TPVFOX_TEST_DB_ANTERIOR' : 'TPVFOX_TEST_DB_VIGENTE';
$base = Entorno::valor($variable);
if ($base === '' || strpos($base, 'tpvfox_test') !== 0) {
    fwrite(STDERR, "Falta $variable o no empieza por «tpvfox_test»: no se toca.\n");
    exit(1);
}
$db = new mysqli(Entorno::valor('TPVFOX_TEST_DB_HOST', 'localhost'), Entorno::valor('TPVFOX_TEST_DB_USER'), Entorno::valor('TPVFOX_TEST_DB_PASS'), $base);
if ($db->connect_errno) {
    fwrite(STDERR, "No se pudo conectar con «{$base}»: error {$db->connect_errno}.\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$proveedor = $db->query("SELECT idProveedor FROM proveedores WHERE nombrecomercial = '" . PREFIJO . "Despiece del obrador' ORDER BY idProveedor DESC LIMIT 1")->fetch_row();
if ($proveedor === null) {
    fwrite(STDERR, "Siembra antes el escenario de la aplicación.\n");
    exit(1);
}
$siembra = new Siembra($db);
$ternera = $siembra->articulo(PREFIJO . 'Ternera', ['iva' => 10]);
$numero = mb_substr(PREFIJO . 'Despiece', 0, 20);
$linea = [
    'ccodbar' => '', 'cdetalle' => PREFIJO . 'Ternera', 'cref' => '', 'ref_prov' => '', 'estado' => 'Activo', 'idArticulo' => $ternera,
    'importe' => -40.0, 'iva' => 10, 'ncant' => -10.0, 'nfila' => 1, 'nunidades' => -10.0, 'ultimoCoste' => 4.0,
];
$productos = $db->real_escape_string(json_encode([$linea]));
$usuario = $siembra->usuarioPorDefecto();
$tienda = $siembra->tiendaPorDefecto();
$db->query('INSERT INTO albproltemporales (idUsuario, idTienda, estadoAlbPro, Fecha, Productos, Pedidos, Su_numero, idProveedor)'
    . " VALUES ($usuario, $tienda, 'Nuevo', '2026-02-28', '$productos', '[]', '" . $db->real_escape_string($numero) . "', {$proveedor[0]})");
echo json_encode(['idTemporal' => (int) $db->insert_id, 'numero' => $numero]);

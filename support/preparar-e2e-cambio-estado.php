<?php
/**
 * Prepara una relación de la siembra de `sembrar-e2e-relaciones.php` para los recorridos del
 * cambio de estado desde el listado.
 *
 * Uso: php support/preparar-e2e-cambio-estado.php borrador <numRelacion> [vigente|anterior]
 *      php support/preparar-e2e-cambio-estado.php nombre <numRelacion> <nombre> [vigente|anterior]
 *
 *   borrador   le deja un borrador pendiente, copia de la versión que rige, como si alguien
 *              estuviera preparando un cambio
 *   nombre     le pone ese nombre a la versión que rige
 *   baja       la deja de baja, como si alguien la hubiera dado de baja desde el listado
 *   repetida   da de alta otra relación vigente igual a esta, aunque esta esté de baja
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;

$accion = $argv[1] ?? '';
$numRelacion = (int) ($argv[2] ?? 0);
if (!in_array($accion, ['borrador', 'nombre', 'baja', 'repetida'], true) || $numRelacion <= 0) {
    fwrite(STDERR, "Uso: php support/preparar-e2e-cambio-estado.php borrador|nombre <numRelacion> ...\n");
    exit(1);
}
$nombre = $accion === 'nombre' ? (string) ($argv[3] ?? '') : '';
$ejercicio = $accion === 'nombre' ? ($argv[4] ?? 'vigente') : ($argv[3] ?? 'vigente');

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

$rige = $db->query("SELECT id, idTipo, nombre, idUsuario FROM relaciones WHERE numRelacion = $numRelacion AND estado IN ('Activo', 'Baja')")->fetch_assoc();
if ($rige === null) {
    fwrite(STDERR, "La relación $numRelacion no rige.\n");
    exit(1);
}

if ($accion === 'baja') {
    $db->query("UPDATE relaciones SET estado = 'Baja' WHERE id = {$rige['id']}");
    echo "Relación $numRelacion de baja.\n";
    exit(0);
}

if ($accion === 'repetida') {
    $nuevo = (int) $db->query('SELECT MAX(numRelacion) + 1 AS n FROM relaciones')->fetch_assoc()['n'];
    $alta = $db->prepare("INSERT INTO relaciones (numRelacion, idTipo, nombre, version, idUsuario, fechaCreacion, estado) VALUES (?, ?, ?, '1.0', ?, NOW(), 'Activo')");
    $alta->bind_param('iisi', $nuevo, $rige['idTipo'], $rige['nombre'], $rige['idUsuario']);
    $alta->execute();
    $idNueva = (int) $db->insert_id;
    $db->query("INSERT INTO relacionesLineas (idRelacion, idArticulo, sentido, cantidad, unidad, estado)"
        . " SELECT $idNueva, idArticulo, sentido, cantidad, unidad, 'Activo' FROM relacionesLineas"
        . " WHERE idRelacion = {$rige['id']} AND estado = 'Activo'");
    echo "Relación $nuevo igual a la $numRelacion.\n";
    exit(0);
}

if ($accion === 'nombre') {
    $cambio = $db->prepare('UPDATE relaciones SET nombre = ? WHERE id = ?');
    $cambio->bind_param('si', $nombre, $rige['id']);
    $cambio->execute();
    echo "Relación $numRelacion con el nombre cambiado.\n";
    exit(0);
}

$alta = $db->prepare("INSERT INTO relaciones (numRelacion, idTipo, nombre, version, idUsuario, fechaCreacion, estado) VALUES (?, ?, ?, '0.0', ?, NOW(), 'Borrador')");
$alta->bind_param('iisi', $numRelacion, $rige['idTipo'], $rige['nombre'], $rige['idUsuario']);
$alta->execute();
$idBorrador = (int) $db->insert_id;
$db->query("INSERT INTO relacionesLineas (idRelacion, idArticulo, sentido, cantidad, unidad, estado)"
    . " SELECT $idBorrador, idArticulo, sentido, cantidad, unidad, 'Activo' FROM relacionesLineas"
    . " WHERE idRelacion = {$rige['id']} AND estado = 'Activo'");
echo "Relación $numRelacion con un borrador pendiente.\n";

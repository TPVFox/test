<?php
/**
 * Lee de la base el permiso que un usuario tiene sobre una vista o sobre una accion.
 *
 * Existe para los recorridos de navegador que comprueban que un permiso denegado no impide la
 * escritura: antes de pedirla, el recorrido tiene que demostrar que el permiso esta denegado de
 * verdad, o no mediria nada. Solo lee, y solo de la base de pruebas.
 *
 * Uso:  php support/leer-permiso-de-usuario.php <usuario> <modulo> <vista> [accion]
 *
 * Devuelve en JSON el permiso guardado —`null` si el usuario no tiene la fila—. Sin accion, el de
 * la vista.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;

[, $usuario, $modulo, $vista] = $argv + [null, '', '', ''];
$accion = $argv[4] ?? null;

if ($usuario === '' || $modulo === '' || $vista === '') {
    fwrite(STDERR, "Uso: php support/leer-permiso-de-usuario.php <usuario> <modulo> <vista> [accion]\n");
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

// El producto puede dejar la misma fila repetida; se toma la primera, que es la que lee al
// comprobar el permiso.
$sql = 'SELECT p.permiso FROM permisos p JOIN usuarios u ON u.id = p.idUsuario'
    . ' WHERE u.username = ? AND p.modulo = ? AND p.vista = ? AND '
    . ($accion === null ? 'p.accion IS NULL' : 'p.accion = ?')
    . ' ORDER BY p.id LIMIT 1';
$sentencia = $db->prepare($sql);
if ($accion === null) {
    $sentencia->bind_param('sss', $usuario, $modulo, $vista);
} else {
    $sentencia->bind_param('ssss', $usuario, $modulo, $vista, $accion);
}
$sentencia->execute();
$fila = $sentencia->get_result()->fetch_row();

echo json_encode(['permiso' => $fila === null ? null : (int) $fila[0]]) . "\n";

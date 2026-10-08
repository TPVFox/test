<?php
/**
 * Lee de la base quien consta como creador de un documento de venta, sus dos fechas, su estado
 * y su importe, y cuantos borradores quedan de ese cliente.
 *
 * Existe para los recorridos de navegador: la pantalla no ensena ni el creador ni las fechas de
 * creacion y de modificacion, y lo que una peticion deja escrito sin respuesta visible —un
 * cambio de estado, un documento borrado, un borrador que sobrevive— solo se puede comprobar
 * leyendolo de la base. Solo lee, y solo de la base de pruebas.
 *
 * Uso:  php support/leer-traza-de-documento.php <pedido|albaran|factura> <idCliente>
 *
 * Devuelve en JSON el ultimo documento de ese cliente con su numero de lineas y de tramos de su
 * desglose de impuestos —`null` si no le queda ninguno—, el numero de borradores de ese tipo que tiene abiertos, y el identificador del
 * usuario con el que entran los recorridos, para poder decir si el creador es otro.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;

const TABLAS = [
    'pedido'  => ['pedclit', 'idUsuario, estado, total, fechaCreacion, fechaModificacion', 'pedcliltemporales', 'pedclilinea', 'idpedcli', 'pedcliIva'],
    'albaran' => ['albclit', 'idUsuario, estado, total', 'albcliltemporales', 'albclilinea', 'idalbcli', 'albcliIva'],
    'factura' => ['facclit', 'idUsuario, estado, total, fechaCreacion, fechaModificacion', 'faccliltemporales', 'facclilinea', 'idfaccli', 'faccliIva'],
];

$documento = $argv[1] ?? '';
$idCliente = (int) ($argv[2] ?? 0);

if (!isset(TABLAS[$documento]) || $idCliente <= 0) {
    fwrite(STDERR, "Uso: php support/leer-traza-de-documento.php <pedido|albaran|factura> <idCliente>\n");
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

[$tabla, $columnas, $tablaDeBorradores, $tablaDeLineas, $columnaDelDocumento, $tablaDeImpuestos] = TABLAS[$documento];
$fila = $db->query("SELECT id, {$columnas} FROM {$tabla} WHERE idCliente = {$idCliente} ORDER BY id DESC LIMIT 1")
    ->fetch_assoc();
if ($fila !== null) {
    $fila['lineas'] = (int) $db->query("SELECT COUNT(*) FROM {$tablaDeLineas} WHERE {$columnaDelDocumento} = {$fila['id']}")
        ->fetch_row()[0];
    $fila['desglose'] = (int) $db->query("SELECT COUNT(*) FROM {$tablaDeImpuestos} WHERE {$columnaDelDocumento} = {$fila['id']}")
        ->fetch_row()[0];
}
$borradores = (int) $db->query("SELECT COUNT(*) FROM {$tablaDeBorradores} WHERE idCliente = {$idCliente}")
    ->fetch_row()[0];

$sentencia = $db->prepare('SELECT id FROM usuarios WHERE username = ? LIMIT 1');
$usuario = Entorno::valor('TPVFOX_E2E_USUARIO');
$sentencia->bind_param('s', $usuario);
$sentencia->execute();
$recorrido = $sentencia->get_result()->fetch_row();

echo json_encode([
    'documento'          => $fila,
    'borradores'         => $borradores,
    'usuarioDeRecorrido' => $recorrido === null ? null : (int) $recorrido[0],
]) . "\n";

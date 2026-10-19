<?php
/**
 * Prepara la siembra de `sembrar-e2e-relaciones.php` para los recorridos de la pantalla de
 * relación.
 *
 * Uso: php support/preparar-e2e-vista-relacion.php referencias [vigente|anterior]
 *      php support/preparar-e2e-vista-relacion.php temporal <numRelacion> [vigente|anterior]
 *      php support/preparar-e2e-vista-relacion.php permiso <accion> <0|1> [vigente|anterior]
 *
 *   referencias   da referencia en la tienda principal y código de barras a cuatro artículos
 *                 de la siembra, para buscarlos por esos datos
 *   temporal      deja preparado, sin activar, un cambio de esa relación: copia de la versión
 *                 que rige con la segunda línea a 0,27, guardado por el usuario de los
 *                 recorridos el 2026-10-01 a las 10:00
 *   permiso       fija una sola acción de la vista del listado para el usuario de los recorridos
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;

const PREFIJO = '[E2E relaciones] ';
const REFERENCIAS = [
    'Pavo' => ['E2E-PAVO-REF', 'E2E-PAVO-CB'],
    'Muslo de pavo' => ['E2E-MUSLO-REF', 'E2E-MUSLO-CB'],
    'Conejo' => ['E2E-CONEJO-REF', 'E2E-CONEJO-CB'],
    'Paletilla de conejo' => ['E2E-PALETILLA-REF', 'E2E-PALETILLA-CB'],
];

$accion = $argv[1] ?? '';
$argumentos = array_slice($argv, 2);
$validos = ['referencias' => 0, 'temporal' => 1, 'permiso' => 2];
if (!isset($validos[$accion]) || count($argumentos) < $validos[$accion]) {
    fwrite(STDERR, "Uso: php support/preparar-e2e-vista-relacion.php referencias|temporal|permiso ...\n");
    exit(1);
}
$ejercicio = $argumentos[$validos[$accion]] ?? 'vigente';

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

$usuarioDeLosRecorridos = function () use ($db): int {
    $login = Entorno::valor('TPVFOX_E2E_USUARIO');
    $buscar = $db->prepare('SELECT id FROM usuarios WHERE username = ? LIMIT 1');
    $buscar->bind_param('s', $login);
    $buscar->execute();
    $fila = $buscar->get_result()->fetch_row();
    if ($fila === null) {
        fwrite(STDERR, "Sin usuario de recorridos «{$login}».\n");
        exit(1);
    }

    return (int) $fila[0];
};

if ($accion === 'referencias') {
    $tienda = (int) $db->query("SELECT idTienda FROM tiendas WHERE tipoTienda = 'principal' LIMIT 1")->fetch_assoc()['idTienda'];
    foreach (REFERENCIAS as $nombre => [$referencia, $codigo]) {
        $buscar = $db->prepare('SELECT idArticulo FROM articulos WHERE articulo_name = ? LIMIT 1');
        $completo = PREFIJO . $nombre;
        $buscar->bind_param('s', $completo);
        $buscar->execute();
        $idArticulo = (int) $buscar->get_result()->fetch_row()[0];
        $db->query("DELETE FROM articulosTiendas WHERE idArticulo = $idArticulo");
        $db->query("DELETE FROM articulosCodigoBarras WHERE idArticulo = $idArticulo");
        $alta = $db->prepare("INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES (?, ?, ?, 'Activo')");
        $alta->bind_param('iis', $idArticulo, $tienda, $referencia);
        $alta->execute();
        $alta = $db->prepare('INSERT INTO articulosCodigoBarras (idArticulo, codBarras) VALUES (?, ?)');
        $alta->bind_param('is', $idArticulo, $codigo);
        $alta->execute();
    }
    echo "Referencias y códigos de barras de " . count(REFERENCIAS) . " artículos.\n";
    exit(0);
}

if ($accion === 'permiso') {
    [$nombreAccion, $valor] = [$argumentos[0], (int) $argumentos[1]];
    if (!in_array($nombreAccion, ['CambiarEstado', 'Crear', 'Modificar', 'Activar'], true)) {
        fwrite(STDERR, "Acción desconocida: {$nombreAccion}\n");
        exit(1);
    }
    $idUsuario = $usuarioDeLosRecorridos();
    $db->query("DELETE FROM permisos WHERE idUsuario = $idUsuario AND modulo = 'mod_relaciones' AND vista = 'ListaRelaciones.php' AND accion = '$nombreAccion'");
    $db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ($idUsuario, 'mod_relaciones', 'ListaRelaciones.php', '$nombreAccion', $valor)");
    echo "Permiso {$nombreAccion} a {$valor}.\n";
    exit(0);
}

$numRelacion = (int) $argumentos[0];
$rige = $db->query("SELECT id, idTipo FROM relaciones WHERE numRelacion = $numRelacion AND estado = 'Activo'")->fetch_assoc();
if ($rige === null) {
    fwrite(STDERR, "La relación $numRelacion no rige.\n");
    exit(1);
}
$lineas = [];
foreach ($db->query("SELECT idArticulo, sentido, cantidad, unidad FROM relacionesLineas WHERE idRelacion = {$rige['id']} AND estado = 'Activo' ORDER BY id")->fetch_all(MYSQLI_ASSOC) as $fila) {
    $lineas[] = [
        'idArticulo' => (int) $fila['idArticulo'],
        'sentido' => $fila['sentido'],
        'cantidad' => $fila['cantidad'] === null ? null : rtrim(rtrim($fila['cantidad'], '0'), '.'),
        'unidad' => $fila['unidad'],
    ];
}
if (isset($lineas[1]) && $lineas[1]['cantidad'] !== null) {
    $lineas[1]['cantidad'] = '0.27';
}
$json = (string) json_encode($lineas);
$idUsuario = $usuarioDeLosRecorridos();
$db->query("DELETE FROM relacionesTemporales WHERE numRelacion = $numRelacion");
$alta = $db->prepare("INSERT INTO relacionesTemporales (numRelacion, idRelacion, idTipo, lineas, idUsuario, fechaInicio, fechaModificacion) VALUES (?, ?, ?, ?, ?, '2026-10-01 10:00:00', '2026-10-01 10:00:00')");
$alta->bind_param('iiisi', $numRelacion, $rige['id'], $rige['idTipo'], $json, $idUsuario);
$alta->execute();
echo "Relación $numRelacion con un cambio en preparación.\n";

<?php
/**
 * Siembra persistente para los recorridos E2E de mod_venta (PCP-TPY, componente 1).
 *
 * A diferencia de la siembra que usan los casos de Integration/PHP —dentro de una
 * transaccion que se deshace al terminar—, un recorrido de navegador corre contra el
 * despliegue real de `localhost:8080/TPVFox` y no ve ninguna transaccion abierta por la
 * suite: lo que este guion inserta queda en la base para siempre, hasta que alguien lo
 * borre a mano.
 *
 * Los articulos y el cliente llevan el prefijo `[E2E venta]` en el nombre, precisamente
 * para que se distingan a simple vista de cualquier dato real y de los fixtures de otros
 * PCP (los tres articulos de PCP-TPX ya presentes en esta base no sirven aqui: sus nombres
 * son de comprobacion de stock entre ejercicios, no de venta).
 *
 * Uso: php support/sembrar-e2e-venta.php
 *
 * Sin opcion de --rehacer: los articulos llevan nombre fijo y el guion no vuelve a
 * insertarlos si ya existen (idempotente por nombre, no por identificador).
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\Siembra;

const PREFIJO = '[E2E venta] ';

$base = Entorno::valor('TPVFOX_TEST_DB_VIGENTE');
if ($base === '' || strpos($base, 'tpvfox_test') !== 0) {
    fwrite(STDERR, "Falta TPVFOX_TEST_DB_VIGENTE o no empieza por «tpvfox_test»: no se toca.\n");
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

$siembra = new Siembra($db);

$articulos = [
    ['nombre' => PREFIJO . 'Manzana Golden', 'coste' => 1.0, 'beneficio' => 50],
    ['nombre' => PREFIJO . 'Manzana Reineta', 'coste' => 1.2, 'beneficio' => 50],
];

$idsArticulo = [];
foreach ($articulos as $datos) {
    $existente = existente($db, 'articulos', 'articulo_name', $datos['nombre']);
    if ($existente !== null) {
        echo "Articulo ya sembrado: {$datos['nombre']} (id {$existente})\n";
        $idsArticulo[] = $existente;
        continue;
    }

    $id = $siembra->articulo($datos['nombre'], ['ultimoCoste' => $datos['coste'], 'beneficio' => $datos['beneficio'], 'iva' => 21]);
    $precioCiva = round($datos['coste'] * (1 + $datos['beneficio'] / 100) * 1.21, 2);
    $precioSiva = round($datos['coste'] * (1 + $datos['beneficio'] / 100), 2);
    $siembra->precioYTienda($id, $precioCiva, $precioSiva);
    echo "Articulo sembrado: {$datos['nombre']} (id {$id}), pvpCiva={$precioCiva}\n";
    $idsArticulo[] = $id;
}

// Un cliente por spec, no uno compartido: pedido.php guarda en el servidor un temporal
// "actual" ligado al cliente seleccionado, y Playwright corre los ficheros de spec en
// paralelo por defecto (sin workers:1 en playwright.config.js). Dos specs manipulando el
// mismo cliente a la vez corren la carrera de verse el temporal el uno al otro.
$nombresCliente = [
    'teclado'         => PREFIJO . 'Cliente teclado',
    'raton'           => PREFIJO . 'Cliente raton',
    'defecto'         => PREFIJO . 'Cliente defecto comprobar adjuntos',
    'navegacion'      => PREFIJO . 'Cliente navegacion teclado',
    'eliminar'        => PREFIJO . 'Cliente eliminar raton',
    'albaran'         => PREFIJO . 'Cliente albaran teclado',
    'albaran_adjunto' => PREFIJO . 'Cliente albaran adjunto pedido',
    'factura'         => PREFIJO . 'Cliente factura teclado',
    'factura_adjunto' => PREFIJO . 'Cliente factura adjunto albaran',
];

$idsCliente = [];
foreach ($nombresCliente as $clave => $nombreCliente) {
    $idCliente = existente($db, 'clientes', 'Nombre', $nombreCliente);
    if ($idCliente === null) {
        $idCliente = insertarCliente($db, $nombreCliente);
        echo "Cliente sembrado: {$nombreCliente} (id {$idCliente})\n";
    } else {
        echo "Cliente ya sembrado: {$nombreCliente} (id {$idCliente})\n";
    }
    $idsCliente[$clave] = $idCliente;
}

// Un pedido 'Guardado' para el cliente que prueba adjuntar pedido -> albaran
// (BuscarAdjunto.php busca por Numpedcli, idCliente y estado="Guardado"). Idempotente por
// idCliente: si ya tiene un pedido Guardado, no siembra otro.
$filaPedido = $db->query(
    'SELECT Numpedcli FROM pedclit WHERE idCliente = ' . (int) $idsCliente['albaran_adjunto']
    . ' AND estado = "Guardado" LIMIT 1'
)->fetch_assoc();

if ($filaPedido !== null) {
    $numPedidoGuardado = (int) $filaPedido['Numpedcli'];
    echo "Pedido Guardado ya sembrado (cliente {$idsCliente['albaran_adjunto']}): Numpedcli={$numPedidoGuardado}\n";
} else {
    $idPedidoGuardado = $siembra->pedidoVentaCliente($idsArticulo[0], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idsCliente['albaran_adjunto'],
    ]);
    $numPedidoGuardado = (int) $db->query('SELECT Numpedcli FROM pedclit WHERE id = ' . (int) $idPedidoGuardado)
        ->fetch_assoc()['Numpedcli'];
    echo "Pedido Guardado sembrado (cliente {$idsCliente['albaran_adjunto']}): Numpedcli={$numPedidoGuardado}\n";
}

// Mismo patron, un albaran 'Guardado' para adjuntar albaran -> factura
// (AlbaranClienteGuardado() busca por Numalbcli, idCliente y estado="Guardado").
$filaAlbaran = $db->query(
    'SELECT Numalbcli FROM albclit WHERE idCliente = ' . (int) $idsCliente['factura_adjunto']
    . ' AND estado = "Guardado" LIMIT 1'
)->fetch_assoc();

if ($filaAlbaran !== null) {
    $numAlbaranGuardado = (int) $filaAlbaran['Numalbcli'];
    echo "Albaran Guardado ya sembrado (cliente {$idsCliente['factura_adjunto']}): Numalbcli={$numAlbaranGuardado}\n";
} else {
    $idAlbaranGuardado = $siembra->ventaAlbaranCliente($idsArticulo[0], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idsCliente['factura_adjunto'],
    ]);
    $numAlbaranGuardado = (int) $db->query('SELECT Numalbcli FROM albclit WHERE id = ' . (int) $idAlbaranGuardado)
        ->fetch_assoc()['Numalbcli'];
    echo "Albaran Guardado sembrado (cliente {$idsCliente['factura_adjunto']}): Numalbcli={$numAlbaranGuardado}\n";
}

echo "\nArticulos disponibles para los recorridos: " . implode(', ', $idsArticulo) . "\n";
foreach ($idsCliente as $clave => $id) {
    echo "Cliente ($clave): $id\n";
}

$db->close();

// --- Apoyos -----------------------------------------------------------------

function existente(mysqli $db, string $tabla, string $campo, string $valor): ?int
{
    $columnaId = $tabla === 'clientes' ? 'idClientes' : 'id' . ucfirst(rtrim($tabla, 's'));
    if ($tabla === 'articulos') {
        $columnaId = 'idArticulo';
    }
    $sentencia = $db->prepare("SELECT `$columnaId` FROM `$tabla` WHERE `$campo` = ? LIMIT 1");
    $sentencia->bind_param('s', $valor);
    $sentencia->execute();
    $fila = $sentencia->get_result()->fetch_row();

    return $fila === null ? null : (int) $fila[0];
}

function insertarCliente(mysqli $db, string $nombre): int
{
    $sentencia = $db->prepare('INSERT INTO clientes (Nombre, estado) VALUES (?, ?)');
    $activo = 'Activo';
    $sentencia->bind_param('ss', $nombre, $activo);
    $sentencia->execute();

    return (int) $db->insert_id;
}

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
 * Los articulos y los clientes se siembran con **identificador fijo**, no por
 * auto-incremento: los recorridos referencian el cliente y el articulo por su id (no hay
 * conexion a la base desde Playwright para resolverlo por nombre), de modo que un id que
 * cambiara al rehacer la base dejaria los specs apuntando a datos que ya no existen. Con id
 * fijo la siembra es reproducible: tras `preparar-entorno.php --rehacer` vuelve a caer en
 * los mismos numeros. Es idempotente por identificador —si ya existe, no se reinserta—, el
 * mismo criterio que `Siembra::proveedorConId` y `sembrar-escenarios.php` aplican a los
 * datos persistentes que cruzan a los recorridos de navegador.
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
    ['id' => 14678, 'nombre' => PREFIJO . 'Manzana Golden', 'coste' => 1.0, 'beneficio' => 50],
    ['id' => 14679, 'nombre' => PREFIJO . 'Manzana Reineta', 'coste' => 1.2, 'beneficio' => 50],
];

$idsArticulo = [];
foreach ($articulos as $datos) {
    $existente = existente($db, 'articulos', 'articulo_name', $datos['nombre']);
    if ($existente !== null) {
        echo "Articulo ya sembrado: {$datos['nombre']} (id {$existente})\n";
        $idsArticulo[] = $existente;
        continue;
    }

    $id = $siembra->articulo($datos['nombre'], ['id' => $datos['id'], 'ultimoCoste' => $datos['coste'], 'beneficio' => $datos['beneficio'], 'iva' => 21]);
    $precioCiva = round($datos['coste'] * (1 + $datos['beneficio'] / 100) * 1.21, 2);
    $precioSiva = round($datos['coste'] * (1 + $datos['beneficio'] / 100), 2);
    // El precio se escribe en la tienda 1, no en la principal del entorno: la busqueda de
    // producto de la capa compartida fija `idTienda = 1` en la consulta (DS-TPY-COM-018,
    // funciones.php:49-50), de modo que un precio en otra tienda no lo encontraria. La tienda
    // 1 no tiene por que existir en `tiendas` —la consulta no la une—, basta la fila de precio.
    $siembra->precioYTienda($id, $precioCiva, $precioSiva, 1);
    echo "Articulo sembrado: {$datos['nombre']} (id {$id}), pvpCiva={$precioCiva} en tienda 1\n";
    $idsArticulo[] = $id;
}

// Un cliente por spec, no uno compartido: pedido.php guarda en el servidor un temporal
// "actual" ligado al cliente seleccionado, y Playwright corre los ficheros de spec en
// paralelo por defecto (sin workers:1 en playwright.config.js). Dos specs manipulando el
// mismo cliente a la vez corren la carrera de verse el temporal el uno al otro.
// Id fijo por cliente: es el que cada spec referencia en su constante ID_CLIENTE. El orden
// no basta para fijarlo —el auto-incremento cambia al rehacer la base—, asi que el numero
// es explicito y vive aqui, junto al nombre que lo identifica.
$nombresCliente = [
    'teclado'         => [921, PREFIJO . 'Cliente teclado'],
    'raton'           => [922, PREFIJO . 'Cliente raton'],
    'defecto'         => [923, PREFIJO . 'Cliente defecto comprobar adjuntos'],
    'navegacion'      => [924, PREFIJO . 'Cliente navegacion teclado'],
    'eliminar'        => [925, PREFIJO . 'Cliente eliminar raton'],
    'albaran'         => [926, PREFIJO . 'Cliente albaran teclado'],
    'albaran_adjunto' => [927, PREFIJO . 'Cliente albaran adjunto pedido'],
    'factura'         => [928, PREFIJO . 'Cliente factura teclado'],
    'factura_adjunto' => [929, PREFIJO . 'Cliente factura adjunto albaran'],
    'albaran_entradas' => [930, PREFIJO . 'Cliente albaran entradas'],
    'albaran_guardar'  => [931, PREFIJO . 'Cliente albaran guardar'],
    'albaran_listado'  => [932, PREFIJO . 'Cliente albaran listado'],
];

$idsCliente = [];
foreach ($nombresCliente as $clave => [$idFijo, $nombreCliente]) {
    $idCliente = existente($db, 'clientes', 'Nombre', $nombreCliente);
    if ($idCliente === null) {
        $idCliente = insertarCliente($db, $idFijo, $nombreCliente);
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

// Los recorridos del componente 2 llegan al albaran desde el listado, no por su id en la
// URL: `albclit` no admite id fijo en la siembra y el auto-incremento cambia al rehacer la
// base, de modo que un id escrito en el spec dejaria de valer. Basta con que cada uno de
// esos clientes tenga un albaran 'Guardado' con el que aparecer en el listado.
foreach (['albaran_entradas', 'albaran_listado'] as $clave) {
    $idCliente = (int) $idsCliente[$clave];
    $fila = $db->query(
        'SELECT Numalbcli FROM albclit WHERE idCliente = ' . $idCliente . ' AND estado = "Guardado" LIMIT 1'
    )->fetch_assoc();

    if ($fila !== null) {
        echo "Albaran Guardado ya sembrado (cliente {$idCliente}): Numalbcli={$fila['Numalbcli']}\n";
        continue;
    }

    $idAlbaran = $siembra->ventaAlbaranCliente($idsArticulo[0], 2.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idCliente,
    ]);
    $numero = (int) $db->query('SELECT Numalbcli FROM albclit WHERE id = ' . (int) $idAlbaran)
        ->fetch_assoc()['Numalbcli'];
    echo "Albaran Guardado sembrado (cliente {$idCliente}): id={$idAlbaran}, Numalbcli={$numero}\n";
}

// El recorrido del listado necesita ademas un albaran en un estado distinto, o el filtro
// por estado no se puede verificar: filtrar por el unico estado que existe no distingue un
// filtro que funciona de uno que se ignora. Se llega a 'Procesado' facturandolo, que es el
// unico camino por el que el producto lo deja asi.
$idClienteListado = (int) $idsCliente['albaran_listado'];
$filaProcesado = $db->query(
    'SELECT Numalbcli FROM albclit WHERE idCliente = ' . $idClienteListado . ' AND estado = "Procesado" LIMIT 1'
)->fetch_assoc();

if ($filaProcesado !== null) {
    echo "Albaran Procesado ya sembrado (cliente {$idClienteListado}): Numalbcli={$filaProcesado['Numalbcli']}\n";
} else {
    $idAlbaranAFacturar = $siembra->ventaAlbaranCliente($idsArticulo[1], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteListado,
    ]);
    $siembra->facturarAlbaranCliente($idAlbaranAFacturar);
    echo "Albaran Procesado sembrado (cliente {$idClienteListado}): id={$idAlbaranAFacturar}\n";
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

function insertarCliente(mysqli $db, int $idFijo, string $nombre): int
{
    $sentencia = $db->prepare('INSERT INTO clientes (idClientes, Nombre, estado) VALUES (?, ?, ?)');
    $activo = 'Activo';
    $sentencia->bind_param('iss', $idFijo, $nombre, $activo);
    $sentencia->execute();

    return (int) $db->insert_id;
}

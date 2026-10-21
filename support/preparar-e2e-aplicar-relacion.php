<?php
/**
 * Prepara los recorridos de la pantalla de aplicar una relación, sobre el despliegue del
 * ejercicio vigente.
 *
 * Uso: php support/preparar-e2e-aplicar-relacion.php escenario [vigente|anterior]
 *      php support/preparar-e2e-aplicar-relacion.php permiso <ver|Preparar> <0|1> [vigente|anterior]
 *      php support/preparar-e2e-aplicar-relacion.php albaran <rojo|amarillo> [vigente|anterior]
 *      php support/preparar-e2e-aplicar-relacion.php comillas [vigente|anterior]
 *      php support/preparar-e2e-aplicar-relacion.php marcas [vigente|anterior]
 *      php support/preparar-e2e-aplicar-relacion.php existencias [vigente|anterior]
 *
 *   escenario     borra lo que dejó una pasada anterior y siembra el despiece del pollo: un
 *                 proveedor especial, otro especial y la granja habitual; el pollo y sus cuatro
 *                 piezas con su coste; el despiece, que rige desde el 10 de enero; y febrero
 *                 de 2026 con 200 kg entrados y 20 kg vendidos enteros. Da al usuario de los
 *                 recorridos los dos permisos de la pantalla. Escribe en la salida, en JSON, los
 *                 identificadores que necesita el recorrido
 *   permiso       fija una sola acción de la pantalla para el usuario de los recorridos
 *   albaran       un albarán del pollo de un proveedor especial: el mismo proveedor en febrero
 *                 (rojo) u otro en marzo (amarillo)
 *   comillas      pone comillas en el nombre del muslo
 *   marcas        pone marcas de HTML en el nombre de la carcasa y de un proveedor especial
 *   existencias   escribe en JSON las existencias registradas del pollo y sus piezas
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/siembra/Siembra.php';
require_once __DIR__ . '/siembra/EscenarioRelaciones.php';

use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

const PREFIJO = '[E2E aplicación] ';
const VISTA = 'AplicarRelacion.php';

$accion = $argv[1] ?? '';
$argumentos = array_slice($argv, 2);
$validos = ['escenario' => 0, 'permiso' => 2, 'albaran' => 1, 'comillas' => 0, 'marcas' => 0, 'existencias' => 0];
if (!isset($validos[$accion]) || count($argumentos) < $validos[$accion]) {
    fwrite(STDERR, "Uso: php support/preparar-e2e-aplicar-relacion.php escenario|permiso|albaran|comillas|marcas|existencias ...\n");
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

/** @return list<int> */
function idsDe(mysqli $db, string $sql): array
{
    $ids = [];
    foreach ($db->query($sql)->fetch_all(MYSQLI_NUM) as $fila) {
        $ids[] = (int) $fila[0];
    }

    return $ids;
}

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

$fijarPermiso = function (string $nombreAccion, int $valor) use ($db, $usuarioDeLosRecorridos): void {
    $idUsuario = $usuarioDeLosRecorridos();
    // La fila de la vista, sin acción, tiene que existir: si falta, el producto la crea al entrar
    // junto con las de todas sus acciones, aunque ya estén, y la acción quedaría repetida.
    $vista = $db->query("SELECT id FROM permisos WHERE idUsuario = $idUsuario AND modulo = 'mod_reorganizacion' AND vista = '" . VISTA . "' AND accion IS NULL")->fetch_row();
    if ($vista === null) {
        $db->query("INSERT INTO permisos (idUsuario, modulo, vista, permiso) VALUES ($idUsuario, 'mod_reorganizacion', '" . VISTA . "', 1)");
    }
    $db->query("DELETE FROM permisos WHERE idUsuario = $idUsuario AND modulo = 'mod_reorganizacion' AND vista = '" . VISTA . "' AND accion = '$nombreAccion'");
    $db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ($idUsuario, 'mod_reorganizacion', '" . VISTA . "', '$nombreAccion', $valor)");
};

if ($accion === 'permiso') {
    [$nombreAccion, $valor] = [$argumentos[0], (int) $argumentos[1]];
    if (!in_array($nombreAccion, ['ver', 'Preparar'], true)) {
        fwrite(STDERR, "Acción desconocida: {$nombreAccion}\n");
        exit(1);
    }
    $fijarPermiso($nombreAccion, $valor);
    echo "Permiso {$nombreAccion} a {$valor}.\n";
    exit(0);
}

if ($accion === 'existencias') {
    $tienda = (int) $db->query("SELECT idTienda FROM tiendas WHERE tipoTienda = 'principal' LIMIT 1")->fetch_assoc()['idTienda'];
    $existencias = [];
    foreach (['Pollo entero', 'Pechuga', 'Muslo', 'Alas', 'Carcasa'] as $nombre) {
        $id = idDe($db, 'articulos', 'idArticulo', 'articulo_name', PREFIJO . $nombre);
        $fila = $db->query("SELECT stockOn FROM articulosStocks WHERE idArticulo = $id AND idTienda = $tienda")->fetch_assoc();
        $existencias[$nombre] = $fila === null ? 0.0 : (float) $fila['stockOn'];
    }
    echo json_encode($existencias);
    exit(0);
}

$siembra = new Siembra($db);

if ($accion === 'albaran') {
    $pollo = idDe($db, 'articulos', 'idArticulo', 'articulo_name', PREFIJO . 'Pollo entero');
    if ($argumentos[0] === 'rojo') {
        $proveedor = idDe($db, 'proveedores', 'idProveedor', 'razonsocial', PREFIJO . 'Despiece del obrador');
        $siembra->entradaProveedor($pollo, -10, '2026-02-15', ['idProveedor' => $proveedor]);
    } else {
        $proveedor = idDe($db, 'proveedores', 'idProveedor', 'razonsocial', PREFIJO . 'Otro especial');
        $siembra->entradaProveedor($pollo, -10, '2026-03-10', ['idProveedor' => $proveedor]);
    }
    echo "Albarán {$argumentos[0]} del pollo.\n";
    exit(0);
}

if ($accion === 'comillas') {
    $muslo = idDe($db, 'articulos', 'idArticulo', 'articulo_name', PREFIJO . 'Muslo');
    $db->query("UPDATE articulos SET articulo_name = '" . $db->real_escape_string(PREFIJO . 'Muslo "de corral"') . "' WHERE idArticulo = $muslo");
    echo "El muslo lleva comillas en su nombre.\n";
    exit(0);
}

if ($accion === 'marcas') {
    $carcasa = idDe($db, 'articulos', 'idArticulo', 'articulo_name', PREFIJO . 'Carcasa');
    $otro = idDe($db, 'proveedores', 'idProveedor', 'razonsocial', PREFIJO . 'Otro especial');
    $db->query("UPDATE articulos SET articulo_name = '" . $db->real_escape_string(PREFIJO . 'Carcasa <i>marcada</i> & Cía') . "' WHERE idArticulo = $carcasa");
    $db->query("UPDATE proveedores SET nombrecomercial = '" . $db->real_escape_string(PREFIJO . '<b>Otro</b> especial & Cía') . "' WHERE idProveedor = $otro");
    echo "Marcas en los nombres de la carcasa y de otro especial.\n";
    exit(0);
}

// escenario: lo de una pasada anterior fuera, antes de sembrar.
$like = $db->real_escape_string(PREFIJO) . '%';
$articulos = idsDe($db, "SELECT idArticulo FROM articulos WHERE articulo_name LIKE '$like'");
$proveedores = idsDe($db, "SELECT idProveedor FROM proveedores WHERE razonsocial LIKE '$like'");
$tipos = idsDe($db, "SELECT id FROM relacionesTipos WHERE nombre LIKE '$like'");
if ($articulos !== []) {
    $lista = implode(',', $articulos);
    $albaranes = idsDe($db, "SELECT DISTINCT idalbpro FROM albprolinea WHERE idArticulo IN ($lista)");
    if ($albaranes !== []) {
        $deAlbaranes = implode(',', $albaranes);
        $db->query("DELETE FROM albproIva WHERE idalbpro IN ($deAlbaranes)");
        $db->query("DELETE FROM albprolinea WHERE idalbpro IN ($deAlbaranes)");
        $db->query("DELETE FROM albprot WHERE id IN ($deAlbaranes)");
    }
    $tickets = idsDe($db, "SELECT DISTINCT idticketst FROM ticketslinea WHERE idArticulo IN ($lista)");
    if ($tickets !== []) {
        $deTickets = implode(',', $tickets);
        $db->query("DELETE FROM ticketstIva WHERE idticketst IN ($deTickets)");
        $db->query("DELETE FROM ticketslinea WHERE idticketst IN ($deTickets)");
        $db->query("DELETE FROM ticketst WHERE id IN ($deTickets)");
    }
    $deCliente = idsDe($db, "SELECT DISTINCT idalbcli FROM albclilinea WHERE idArticulo IN ($lista)");
    if ($deCliente !== []) {
        $deAlbCli = implode(',', $deCliente);
        $db->query("DELETE FROM albcliIva WHERE idalbcli IN ($deAlbCli)");
        $db->query("DELETE FROM albclilinea WHERE idalbcli IN ($deAlbCli)");
        $db->query("DELETE FROM albclit WHERE id IN ($deAlbCli)");
    }
    $db->query("DELETE FROM relacionesLineas WHERE idArticulo IN ($lista)");
    foreach (['articulosStocks', 'articulosProveedores', 'articulosTiendas', 'articulosCodigoBarras', 'articulosPrecios', 'articulosFamilias', 'historico_precios', 'acumulado_compras'] as $tabla) {
        $db->query("DELETE FROM $tabla WHERE idArticulo IN ($lista)");
    }
    $db->query("DELETE FROM articulos WHERE idArticulo IN ($lista)");
}
if ($proveedores !== []) {
    $deProveedores = implode(',', $proveedores);
    $db->query("DELETE FROM albproltemporales WHERE idProveedor IN ($deProveedores)");
    $db->query("DELETE FROM articulosProveedores WHERE idProveedor IN ($deProveedores)");
    $db->query("DELETE FROM proveedores WHERE idProveedor IN ($deProveedores)");
}
if ($tipos !== []) {
    $deTipos = implode(',', $tipos);
    $db->query("DELETE FROM relacionesLineas WHERE idRelacion IN (SELECT id FROM relaciones WHERE idTipo IN ($deTipos))");
    $db->query("DELETE FROM relacionesTemporales WHERE idTipo IN ($deTipos)");
    $db->query("DELETE FROM relaciones WHERE idTipo IN ($deTipos)");
    $db->query("DELETE FROM relacionesTipos WHERE id IN ($deTipos)");
}
// El cliente de la venta por albarán es propio: el de pruebas lo usan otras pruebas, que cuentan
// sus albaranes.
$db->query("DELETE FROM clientes WHERE Nombre LIKE '$like'");

$proveedor = function (string $nombre, string $estado) use ($db, $siembra): int {
    $id = $siembra->proveedor(PREFIJO . $nombre);
    $comercial = $db->real_escape_string(PREFIJO . $nombre);
    $db->query("UPDATE proveedores SET estado = '$estado', nombrecomercial = '$comercial' WHERE idProveedor = $id");

    return $id;
};
$especial = $proveedor('Despiece del obrador', 'Especial');
$otroEspecial = $proveedor('Otro especial', 'Especial');
$granja = $proveedor('Granja', 'Activo');

$ids = ['pollo' => $siembra->articulo(PREFIJO . 'Pollo entero', ['iva' => 10, 'idProveedor' => $granja])];
$siembra->articuloProveedor($ids['pollo'], $granja, 2.9, ['fecha' => '2026-01-15']);
foreach (['Pechuga' => 6.5, 'Muslo' => 3.0, 'Alas' => 2.5, 'Carcasa' => 0.5] as $nombre => $precio) {
    $ids[strtolower($nombre)] = $siembra->articulo(PREFIJO . $nombre, ['iva' => 10]);
    $siembra->articuloProveedor($ids[strtolower($nombre)], $granja, $precio);
}
$relaciones = new EscenarioRelaciones($db);
$tipo = $relaciones->tipoDeRelacion(PREFIJO . 'Despiece', '1:N', 1);
$relacion = $relaciones->relacionVigente($tipo, [
    ['idArticulo' => $ids['pollo'], 'sentido' => 'entrada', 'cantidad' => 1],
    ['idArticulo' => $ids['pechuga'], 'sentido' => 'salida', 'cantidad' => 0.25],
    ['idArticulo' => $ids['muslo'], 'sentido' => 'salida', 'cantidad' => 0.35],
    ['idArticulo' => $ids['alas'], 'sentido' => 'salida', 'cantidad' => 0.12],
    ['idArticulo' => $ids['carcasa'], 'sentido' => 'salida', 'cantidad' => 0.24],
], ['nombre' => PREFIJO . 'Despiece del pollo', 'fecha' => '2026-01-10 09:00:00']);
// Entradas de enero y de diciembre: la apertura del ejercicio y una compra corriente, para los periodos que ofrece la pantalla.
$siembra->entradaProveedor($ids['pollo'], 50, '2026-01-01', ['idProveedor' => $granja]);
$siembra->entradaProveedor($ids['pollo'], 30, '2026-12-10', ['idProveedor' => $granja]);
$siembra->entradaProveedor($ids['pollo'], 100, '2026-02-03', ['idProveedor' => $granja]);
$siembra->entradaProveedor($ids['pollo'], 100, '2026-02-17', ['idProveedor' => $granja]);
$siembra->ventaTicket($ids['pollo'], 15, '2026-02-20');
$siembra->ventaAlbaranCliente($ids['pollo'], 5, '2026-02-25', ['idCliente' => $siembra->cliente(PREFIJO . 'Cliente')]);
$fijarPermiso('ver', 1);
$fijarPermiso('Preparar', 1);

echo json_encode(['numRelacion' => $relacion['numRelacion'], 'especial' => $especial, 'otroEspecial' => $otroEspecial, 'granja' => $granja, 'articulos' => $ids]);

<?php
/**
 * Siembra en la base de pruebas un ejemplo de despiece del pollo para probar a mano la pantalla de
 * aplicar relación: los productos, sus costes, las recetas encadenadas y una pila de albaranes de pollo
 * entero de febrero y marzo de 2026, con ventas de algunas piezas.
 *
 * Uso: php support/preparar-demo-despiece-pollo.php [vigente|anterior]
 *
 * Borra antes lo que dejó una pasada anterior: todo lleva el prefijo «[Demo pollo] ». Las bases de
 * prueba se rehacen con cada pasada completa de la suite, así que hay que relanzarlo después.
 *
 * Las recetas, todas del tipo «[Demo pollo] Despiece», en kg de salida por kg de entrada; lo que falta
 * hasta 1 es merma:
 *   Pollo entero        → alas 0,11 · pechuga con hueso 0,40 · traseros 0,47
 *   Pechuga con hueso   → pechuga 0,72 · espinazo 0,26
 *   Traseros            → muslos 0,85
 *   Muslos              → jamoncitos 0,38 · contramuslos 0,61
 *   Muslos              → muslos deshuesados 0,70 (el hueso es merma)
 *   Pechuga             → picada 0,98
 *   Muslos deshuesados  → picada 0,97
 *   Jamoncitos          → picada 0,62 (el hueso es merma)
 *   Contramuslos        → picada 0,70 (el hueso es merma)
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/siembra/Siembra.php';
require_once __DIR__ . '/siembra/EscenarioRelaciones.php';

use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

const PREFIJO = '[Demo pollo] ';

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
    fwrite(STDERR, "No se pudo conectar con la base de pruebas: error {$db->connect_errno}.\n");
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

// Lo de una pasada anterior fuera, antes de sembrar.
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

$siembra = new Siembra($db);
$proveedor = function (string $nombre, string $estado) use ($db, $siembra): int {
    $id = $siembra->proveedor(PREFIJO . $nombre);
    $comercial = $db->real_escape_string(PREFIJO . $nombre);
    $db->query("UPDATE proveedores SET estado = '$estado', nombrecomercial = '$comercial' WHERE idProveedor = $id");

    return $id;
};
$obrador = $proveedor('Despiece del obrador', 'Especial');
$granjaNorte = $proveedor('Granja Norte', 'Activo');
$granjaSur = $proveedor('Granja Sur', 'Activo');

// Los productos con su coste de proveedor, en €/kg: el de la granja principal es el coste de partida
// cuando se aplican; los de las dos granjas dan el precio de mercado de las piezas.
$costes = [
    'Pollo entero' => [2.90, 3.00],
    'Alas' => [3.20, 3.40],
    'Pechuga con hueso' => [5.50, 5.70],
    'Traseros de pollo' => [3.00, 3.10],
    'Pechuga' => [8.50, 8.90],
    'Espinazo' => [0.60, 0.70],
    'Muslos' => [3.40, 3.60],
    'Jamoncitos' => [3.60, 3.80],
    'Contramuslos' => [4.20, 4.40],
    'Muslos deshuesados' => [6.50, 6.80],
    'Picada de pollo' => [6.00, 6.20],
];
$id = [];
foreach ($costes as $nombre => [$norte, $sur]) {
    $id[$nombre] = $siembra->articulo(PREFIJO . $nombre, ['iva' => 10, 'idProveedor' => $granjaNorte]);
    $siembra->articuloProveedor($id[$nombre], $granjaNorte, $norte, ['fecha' => '2026-01-15']);
    $siembra->articuloProveedor($id[$nombre], $granjaSur, $sur, ['fecha' => '2026-01-15']);
}

$relaciones = new EscenarioRelaciones($db);
$tipo = $relaciones->tipoDeRelacion(PREFIJO . 'Despiece', '1:N', 1);
$receta = function (string $nombre, string $entra, array $salen) use ($relaciones, $tipo, $id): int {
    $lineas = [['idArticulo' => $id[$entra], 'sentido' => 'entrada', 'cantidad' => 1]];
    foreach ($salen as $pieza => $cantidad) {
        $lineas[] = ['idArticulo' => $id[$pieza], 'sentido' => 'salida', 'cantidad' => $cantidad];
    }

    return $relaciones->relacionVigente($tipo, $lineas, ['nombre' => PREFIJO . $nombre, 'fecha' => '2026-01-10 09:00:00'])['numRelacion'];
};
$recetas = [
    $receta('Despiece del pollo entero', 'Pollo entero', ['Alas' => 0.11, 'Pechuga con hueso' => 0.40, 'Traseros de pollo' => 0.47]),
    $receta('Despiece de la pechuga con hueso', 'Pechuga con hueso', ['Pechuga' => 0.72, 'Espinazo' => 0.26]),
    $receta('Despiece de los traseros', 'Traseros de pollo', ['Muslos' => 0.85]),
    $receta('Despiece de los muslos', 'Muslos', ['Jamoncitos' => 0.38, 'Contramuslos' => 0.61]),
    $receta('Deshuesado de los muslos', 'Muslos', ['Muslos deshuesados' => 0.70]),
    $receta('Picada de pechuga', 'Pechuga', ['Picada de pollo' => 0.98]),
    $receta('Picada de muslo deshuesado', 'Muslos deshuesados', ['Picada de pollo' => 0.97]),
    $receta('Picada de jamoncito', 'Jamoncitos', ['Picada de pollo' => 0.62]),
    $receta('Picada de contramuslo', 'Contramuslos', ['Picada de pollo' => 0.70]),
];

// La pila de albaranes de pollo entero, de las dos granjas, en febrero y marzo.
$entradas = [
    ['2026-02-02', 120, $granjaNorte], ['2026-02-09', 90, $granjaSur], ['2026-02-16', 150, $granjaNorte], ['2026-02-23', 75, $granjaSur],
    ['2026-03-02', 110, $granjaNorte], ['2026-03-09', 60, $granjaSur], ['2026-03-16', 140, $granjaNorte], ['2026-03-23', 95, $granjaSur],
];
foreach ($entradas as [$fecha, $kg, $granja]) {
    $siembra->entradaProveedor($id['Pollo entero'], $kg, $fecha, ['idProveedor' => $granja]);
}
// Muslos comprados aparte en febrero: lo disponible de un derivado es lo generado más lo del periodo.
$siembra->entradaProveedor($id['Muslos'], 10, '2026-02-12', ['idProveedor' => $granjaSur]);

// Ventas por ticket: pollo entero, y piezas que salen de los despieces, para el aviso de desajuste.
$ventas = [
    ['Pollo entero', 20, '2026-02-20'], ['Pechuga', 15, '2026-02-21'], ['Muslos', 10, '2026-02-22'], ['Picada de pollo', 25, '2026-02-25'],
    ['Pollo entero', 15, '2026-03-20'], ['Pechuga', 12, '2026-03-21'], ['Alas', 8, '2026-03-22'], ['Picada de pollo', 30, '2026-03-25'],
];
foreach ($ventas as [$nombre, $kg, $fecha]) {
    $siembra->ventaTicket($id[$nombre], $kg, $fecha);
}

echo json_encode(['obrador' => $obrador, 'granjaNorte' => $granjaNorte, 'granjaSur' => $granjaSur, 'recetas' => $recetas], JSON_UNESCAPED_UNICODE) . "\n";
echo "Sembrado el despiece del pollo de ejemplo: 11 productos, 9 recetas, 8 albaranes de pollo entero y 8 ventas.\n";

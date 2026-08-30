<?php
/**
 * Genera el fichero de intercambio de ejemplo que suben los recorridos E2E de la
 * comprobación de existencias. Usa el propio código de producción para componerlo y
 * emitirlo, en vez de reinventar el XML o el resumen SHA-256 a mano.
 *
 * Uso: php support/generar-fixture-e2e.php [ano-vigente] [idTienda]
 *
 * ano-vigente es el ejercicio del despliegue E2E que sube el fichero (el vigente,
 * Recorrido 1). El recorrido 2 debe correr contra el despliegue del ejercicio
 * anterior a ese, con la misma tienda: es lo que exige ClaseComprobacionStockAdmision.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once RUTA_TPVFOX . '/modulos/mod_reorganizacion/clases/ClaseComprobacionStockEmision.php';

$anoVigente = $argv[1] ?? '2026';
$idTienda = isset($argv[2]) ? (int) $argv[2] : 1;

$_SESSION['usuarioTpv'] = ['id' => 1];

$estadoProducto = [
    [
        'idArticulo' => 9001,
        'saldoAlCorte' => -5.0,
        'minimoAlcanzado' => -8.0,
        'saldoDeApertura' => 3.0,
        'marcado' => true,
        'tipoIncidencia' => null,
        'condicionesConocidas' => [],
    ],
];

// Una sola lectura del reloj, como la que hace el contexto de operación real: de ahí
// salen el momento de la emisión y la fecha hasta la que se leyó.
$instante = time();

$contextoOperacion = [
    'ano' => $anoVigente,
    'idTienda' => $idTienda,
    'momento' => date('c', $instante),
    'fechaCorte' => date('Y-m-d', $instante),
    'ventanaDias' => 7,
    'umbralFraccionado' => 0.05,
    'umbralMagnitud' => 0.5,
    'umbralPorVenta' => 0.010,
    'timingVentanaDias' => 1,
    'proveedorCierre' => 112,
    'familiasExcluidas' => [],
];

$emision = new ClaseComprobacionStockEmision();
$composicion = $emision->componer($estadoProducto, $contextoOperacion, false);

$destino = __DIR__ . '/../E2E/fixtures/comprobacion-vigente-ejemplo.xml';
if (!$emision->emitir($composicion, $destino)) {
    fwrite(STDERR, "No se pudo generar el fixture.\n");
    exit(1);
}

fwrite(STDOUT, "Fixture generado en {$destino}\n");
fwrite(STDOUT, "Declara el ejercicio vigente {$anoVigente}, tienda {$idTienda}.\n");
fwrite(STDOUT, "El recorrido E2E del anterior debe correr contra el ejercicio " . ((int) $anoVigente - 1) . ".\n");
fwrite(STDOUT, "Artículo de ejemplo: 9001.\n");

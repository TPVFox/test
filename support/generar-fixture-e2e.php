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
 *
 * **El producto que declara el fichero es el del puente**, el mismo que la siembra
 * persistente pone en las dos bases con idéntico identificador. Ni el identificador ni
 * la trayectoria se escriben aquí a mano: el primero lo da el catálogo de escenarios, y
 * la segunda es la que ese escenario siembra. Un fichero que declarase un producto que
 * no está en la base del ejercicio anterior probaría la falta de contraparte, que es
 * justo lo contrario de lo que estos recorridos recorren.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once RUTA_TPVFOX . '/modulos/mod_reorganizacion/clases/ClaseComprobacionStockEmision.php';

use TPVFox\Test\Siembra\EscenarioComprobacionStock;

const ESCENARIO_DEL_PUENTE = 'E55';

$anoVigente = $argv[1] ?? date('Y');
$idTienda = isset($argv[2]) ? (int) $argv[2] : 1;

// El ejercicio viaja dentro del fichero y es una de las dos dimensiones por las que el
// otro extremo decide si lo admite. Sin esta comprobación, cualquier cadena pasa: el
// fichero se genera igual, declara un ejercicio que no existe, y el rechazo aparece
// mucho después, en la admisión, como si el problema fuera del sistema.
if (!preg_match('/^\d{4}$/', (string) $anoVigente)) {
    fwrite(STDERR, "El ejercicio vigente se indica con cuatro cifras, no «{$anoVigente}».\n");
    exit(1);
}

$idArticulo = EscenarioComprobacionStock::identificadorFijoDe(ESCENARIO_DEL_PUENTE);

$_SESSION['usuarioTpv'] = ['id' => 1];

// Los tres números son los que produce la historia del producto del puente en el
// ejercicio vigente: abre con 3, baja a −8 y se recupera hasta −5 al corte. Si esa
// historia cambia en el catálogo, estos tienen que cambiar con ella.
$estadoProducto = [
    [
        'idArticulo' => $idArticulo,
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

// El recorrido del ejercicio anterior busca en pantalla la fila de este producto. Que el
// número esté escrito en dos sitios —aquí y en el recorrido— es una costura que se rompe
// en silencio: el recorrido lo lee de aquí.
$declaracion = __DIR__ . '/../E2E/fixtures/comprobacion-vigente-ejemplo.json';
file_put_contents($declaracion, json_encode([
    'idArticulo' => $idArticulo,
    'anoVigente' => $anoVigente,
    'anoAnterior' => (string) ((int) $anoVigente - 1),
    'idTienda' => $idTienda,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

fwrite(STDOUT, "Fixture generado en {$destino}\n");
fwrite(STDOUT, "Declara el ejercicio vigente {$anoVigente}, tienda {$idTienda}.\n");
fwrite(STDOUT, "El recorrido E2E del anterior debe correr contra el ejercicio " . ((int) $anoVigente - 1) . ".\n");
fwrite(STDOUT, "Producto del puente: {$idArticulo} (escenario " . ESCENARIO_DEL_PUENTE . ").\n");

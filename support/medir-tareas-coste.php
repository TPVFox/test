<?php
/**
 * Cuanto tardan las dos tareas de coste segun crece el volumen de compras.
 *
 * Responde a si pueden correr dentro de una peticion: la ejecucion manual las lanza en la propia
 * peticion web, y cualquier disparo por uso haria lo mismo. Por eso no busca el tamano de ninguna
 * tienda concreta, sino a partir de que tamano cada tarea pasa del tiempo de una peticion.
 *
 * El volumen es inventado y lo fija este guion, en una escalera de tres tamanos con la misma forma:
 * doce meses de compras, cada articulo compra en seis de ellos, y cada articulo y mes lleva tres
 * lineas. Lo que cambia de un tamano a otro es el numero de articulos.
 *
 * Todo ocurre dentro de una transaccion que se deshace al acabar cada tamano: la base queda como
 * estaba.
 *
 * Uso:  php support/medir-tareas-coste.php [--tamanos=pequeno,mediano,grande]
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;
use TPVFox\Test\PrecargaInicial;
use TPVFox\Test\Siembra\EscenarioTareasCron;
use TPVFox\Test\Siembra\Siembra;

const MESES = 12;
const MESES_CON_COMPRA_POR_ARTICULO = 6;
const LINEAS_POR_ARTICULO_Y_MES = 3;
const TAMANOS = ['pequeno' => 1000, 'mediano' => 5000, 'grande' => 20000];

$tamanos = tamanosPedidos($argv);

$base = Entorno::valor('TPVFOX_TEST_DB_VIGENTE');
if ($base === '' || strpos($base, 'tpvfox_test') !== 0) {
    fwrite(STDERR, "Falta TPVFOX_TEST_DB_VIGENTE o no empieza por «tpvfox_test»: no se toca.\n");
    exit(1);
}
if (!preg_match('/(\d{4})$/', $base, $coincidencia)) {
    fwrite(STDERR, "La base «{$base}» no termina en un ejercicio de cuatro cifras.\n");
    exit(1);
}
$ano = (int) $coincidencia[1];

// Las tareas, como las lanza el planificador: con inicial.php ya cargado y la conexion del modelo
// puesta en la de este guion, que es la de la transaccion.
require_once RUTA_TPVFOX . '/modulos/claseModeloP.php';
PrecargaInicial::hacer();
require_once RUTA_TPVFOX . '/modulos/mod_tareas_cron/tareas/AcumuladoComprasTarea.php';
require_once RUTA_TPVFOX . '/modulos/mod_tareas_cron/tareas/UltimoPrecioCompraTarea.php';

printf("Medida de las tareas de coste sobre «%s». PHP %s.\n", $base, PHP_VERSION);
printf(
    "Forma del volumen: %d meses, cada articulo compra en %d, %d lineas por articulo y mes.\n\n",
    MESES,
    MESES_CON_COMPRA_POR_ARTICULO,
    LINEAS_POR_ARTICULO_Y_MES
);

$filas = [];
foreach ($tamanos as $tamano) {
    $articulos = TAMANOS[$tamano];
    $db = conectar($base);
    $db->begin_transaction();
    ModeloP::usarConexion($db);

    try {
        printf("%s: sembrando %d articulos...\n", ucfirst($tamano), $articulos);
        $arranque = microtime(true);
        sembrarVolumen($db, $ano, $articulos);
        $siembra = microtime(true) - $arranque;
        $volumen = volumenDeLaBase($db);
        printf("  sembrado en %s: %d lineas. Midiendo el coste medio...\n", duracion($siembra), $volumen['lineas']);

        $tareas = new EscenarioTareasCron($db);
        $costeMedio = medir(new AcumuladoComprasTarea($tareas->tarea('Medida: coste medio', 'AcumuladoComprasTarea')));
        printf("  coste medio en %s. Midiendo el ultimo coste...\n", duracion($costeMedio['segundos']));
        $ultimoCoste = medir(new UltimoPrecioCompraTarea($tareas->tarea('Medida: ultimo coste', 'UltimoPrecioCompraTarea')));
        printf("  ultimo coste en %s. Deshaciendo la transaccion...\n", duracion($ultimoCoste['segundos']));
    } finally {
        ModeloP::usarConexion(null);
        $db->rollback();
        $db->close();
    }

    $filas[] = [$tamano, $volumen, $costeMedio, $ultimoCoste];
}

echo "\n";
$formato = "%-8s %9s %9s %6s %10s %12s %12s %10s\n";
printf($formato, 'Tamano', 'Lineas', 'Articulos', 'Meses', 'Art.-mes', 'Coste medio', 'Ultimo coste', 'Memoria');
printf($formato, ...array_map(static fn (int $ancho): string => str_repeat('-', $ancho), [8, 9, 9, 6, 10, 12, 12, 10]));
foreach ($filas as [$tamano, $volumen, $costeMedio, $ultimoCoste]) {
    printf(
        $formato,
        $tamano,
        $volumen['lineas'],
        $volumen['articulos'],
        $volumen['meses'],
        $volumen['articulos_mes'],
        duracion($costeMedio['segundos']),
        duracion($ultimoCoste['segundos']),
        memoria(max($costeMedio['bytes'], $ultimoCoste['bytes']))
    );
}
echo "\nEl volumen incluye las compras que ya tuviera la base. Las transacciones se han deshecho: la base queda como estaba.\n";
echo "Terminado.\n";

/**
 * Siembra un tamano de la escalera: los articulos, un albaran por mes y las lineas de cada
 * articulo en sus meses.
 */
function sembrarVolumen(mysqli $db, int $ano, int $articulos): void
{
    $siembra = new Siembra($db);

    $ids = [];
    for ($n = 1; $n <= $articulos; $n++) {
        $ids[] = $siembra->articulo(sprintf('Medida de tareas %06d', $n));
    }

    // Un albaran por mes, el dia 15, de enero a diciembre del ejercicio.
    $tienda = $siembra->tiendaPorDefecto();
    $usuario = $siembra->usuarioPorDefecto();
    $proveedor = $siembra->proveedor('Proveedor de la medida');
    $albaranes = [];
    for ($m = 0; $m < MESES; $m++) {
        $fecha = sprintf('%04d-%02d-15 00:00:00', $ano, $m + 1);
        $alta = $db->prepare(
            'INSERT INTO albprot (Fecha, idTienda, idUsuario, idProveedor, estado, formaPago, total_siniva, total)'
            . " VALUES (?, ?, ?, ?, 'Guardado', 'Efectivo', 0, 0)"
        );
        $alta->bind_param('siii', $fecha, $tienda, $usuario, $proveedor);
        $alta->execute();
        $albaranes[$m] = (int) $db->insert_id;
    }

    // Cada articulo compra en seis meses seguidos, empezando en uno distinto segun su posicion.
    $valores = [];
    foreach ($ids as $posicion => $idArticulo) {
        for ($j = 0; $j < MESES_CON_COMPRA_POR_ARTICULO; $j++) {
            $m = ($posicion + $j) % MESES;
            for ($k = 0; $k < LINEAS_POR_ARTICULO_Y_MES; $k++) {
                $coste = 1 + (($idArticulo * 7 + $m * 3 + $k) % 100) / 10;
                $valores[] = sprintf("(%d, %d, %d, 1, 1, %.4F, 0, 'Activo', '')", $albaranes[$m], $albaranes[$m], $idArticulo, $coste);
                if (count($valores) === 1000) {
                    insertarLineas($db, $valores);
                    $valores = [];
                }
            }
        }
    }
    insertarLineas($db, $valores);
}

/** @param list<string> $valores */
function insertarLineas(mysqli $db, array $valores): void
{
    if ($valores === []) {
        return;
    }

    $db->query(
        'INSERT INTO albprolinea (idalbpro, Numalbpro, idArticulo, ncant, nunidades, costeSiva, iva, estadoLinea, ref_prov) VALUES '
        . implode(', ', $valores)
    );
}

/**
 * Lineas, articulos, meses y pares articulo-mes de las compras que hay en la base.
 *
 * @return array{lineas:int,articulos:int,meses:int,articulos_mes:int}
 */
function volumenDeLaBase(mysqli $db): array
{
    $fila = $db->query(
        'SELECT COUNT(*) AS lineas, COUNT(DISTINCT l.idArticulo) AS articulos,'
        . ' COUNT(DISTINCT YEAR(a.Fecha), MONTH(a.Fecha)) AS meses,'
        . ' COUNT(DISTINCT l.idArticulo, YEAR(a.Fecha), MONTH(a.Fecha)) AS articulos_mes'
        . ' FROM albprolinea l JOIN albprot a ON a.id = l.idalbpro'
    )->fetch_assoc();

    return array_map('intval', $fila);
}

/**
 * El tiempo que tarda una ejecucion de la tarea y la memoria que llega a pedir.
 *
 * @return array{segundos:float,bytes:int}
 */
function medir(object $tarea): array
{
    memory_reset_peak_usage();
    $arranque = microtime(true);
    $tarea->execute();

    return ['segundos' => microtime(true) - $arranque, 'bytes' => memory_get_peak_usage(true)];
}

/** @return list<string> */
function tamanosPedidos(array $argumentos): array
{
    foreach ($argumentos as $argumento) {
        if (strpos($argumento, '--tamanos=') === 0) {
            $tamanos = explode(',', substr($argumento, strlen('--tamanos=')));
            $desconocidos = array_diff($tamanos, array_keys(TAMANOS));
            if ($desconocidos !== []) {
                fwrite(STDERR, 'Tamanos desconocidos: ' . implode(', ', $desconocidos) . '. Son: ' . implode(', ', array_keys(TAMANOS)) . ".\n");
                exit(1);
            }

            return $tamanos;
        }
    }

    return array_keys(TAMANOS);
}

function conectar(string $base): mysqli
{
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

    return $db;
}

function duracion(float $segundos): string
{
    return $segundos < 1 ? sprintf('%.0f ms', $segundos * 1000) : sprintf('%.2f s', $segundos);
}

function memoria(int $bytes): string
{
    if ($bytes >= 1048576) {
        return sprintf('%.1f MB', $bytes / 1048576);
    }

    return sprintf('%.0f KB', $bytes / 1024);
}

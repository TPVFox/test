<?php
/**
 * Siembra de esfuerzo y medida de los tres tramos que la soportan.
 *
 * Responde a tres preguntas que ningun caso de prueba puede responder, porque todos
 * trabajan sobre un punado de productos:
 *
 *   1. Cuanto tarda y cuanta memoria pide la extraccion del catalogo completo.
 *   2. Cuanto tarda la reconstruccion del ejercicio anterior, que emite cuatro consultas
 *      **por producto admitido** y que es la deuda de rendimiento conocida del modulo.
 *   3. Cuanto tarda componer y emitir el fichero de intercambio con todas las filas.
 *
 * El volumen no imita a ninguna instalacion concreta: reparte los productos en tres
 * tramos —unos pocos con mucho movimiento, la mayoria con poco— porque el coste de la
 * reconstruccion no crece con el numero de productos sino con los movimientos de los que
 * se admiten, y un reparto uniforme no lo manifestaria.
 *
 * Uso:  php support/medir-esfuerzo.php [--productos=4000]
 *
 * **Esta siembra invalida el estado cualificado.** Deja miles de productos permanentes en
 * las dos bases, de modo que se lanza despues de haber ejecutado lo que haya que ejecutar
 * sobre el entorno cualificado, y se deshace rehaciendo:
 *
 *     npm run entorno:preparar -- --rehacer
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\Siembra;

/**
 * Los tres tramos del catalogo: que parte del total, cuantos movimientos cada uno y que
 * parte de ellos termina el ejercicio debiendo existencias.
 */
const TRAMOS = [
    'mucha rotacion' => ['parte' => 0.05, 'movimientos' => 40, 'enNegativo' => 0.30],
    'rotacion media' => ['parte' => 0.25, 'movimientos' => 10, 'enNegativo' => 0.15],
    'poca rotacion'  => ['parte' => 0.70, 'movimientos' => 3,  'enNegativo' => 0.10],
];

$productos = productosPedidos($argv);

echo "Siembra de esfuerzo: {$productos} productos en las dos bases.\n";
echo "Esto deja de ser el entorno cualificado. Para volver: npm run entorno:preparar -- --rehacer\n";

$sembrado = [];
foreach (['vigente', 'anterior'] as $papel) {
    $base = nombreDeLaBase($papel);
    $ano = ejercicioDe($base);
    $db = conectar($base);

    $arranque = microtime(true);
    $sembrado[$papel] = sembrarVolumen($db, $ano, $productos);
    $tardanza = microtime(true) - $arranque;

    printf(
        "  %-8s (%s): %d productos, %d movimientos, %d en negativo — %s\n",
        $papel,
        $base,
        $sembrado[$papel]['productos'],
        $sembrado[$papel]['movimientos'],
        count($sembrado[$papel]['negativos']),
        duracion($tardanza)
    );

    $db->close();
}

echo "\n== Medida\n";

$medidas = [];
$medidas[] = medirExtraccion($productos);
$medidas[] = medirReconstruccion($sembrado['anterior']['negativos']);
$medidas[] = medirEmision($productos);

echo "\n";
printf("%-46s %12s %12s\n", 'Tramo', 'Tiempo', 'Memoria');
printf("%-46s %12s %12s\n", str_repeat('-', 46), str_repeat('-', 12), str_repeat('-', 12));
foreach ($medidas as $medida) {
    printf("%-46s %12s %12s\n", $medida['tramo'], duracion($medida['segundos']), memoria($medida['bytes']));
}

echo "\nLos limites del servidor bajo los que se ha medido:\n";
foreach (['memory_limit', 'max_execution_time', 'post_max_size', 'upload_max_filesize', 'max_input_vars'] as $limite) {
    printf("  %-20s %s\n", $limite, ini_get($limite) === false ? '(sin valor)' : ini_get($limite));
}
echo "\nLos de la linea de ordenes no son los del servidor web, que es donde corre el modulo:\n";
echo "la cualificacion del entorno registra los de Apache, no estos.\n";

// --- Siembra --------------------------------------------------------------

/**
 * Reparte los productos en los tres tramos y los siembra.
 *
 * @return array{productos:int,movimientos:int,negativos:int[]}
 */
function sembrarVolumen(mysqli $db, string $ano, int $productos): array
{
    $siembra = new Siembra($db);
    $proveedor = $siembra->proveedor('Proveedor de volumen');
    $siembra->tiendaPorDefecto();

    // Todo en una transaccion: con confirmacion por sentencia, sembrar decenas de miles
    // de documentos convierte la preparacion en el cuello de botella de la medida.
    $db->begin_transaction();

    $movimientos = 0;
    $negativos = [];
    $puestos = 0;

    foreach (TRAMOS as $tramo) {
        $cuantos = (int) round($productos * $tramo['parte']);
        for ($n = 0; $n < $cuantos; $n++) {
            $puestos++;
            $debe = ($n / max($cuantos, 1)) < $tramo['enNegativo'];
            $id = $siembra->articulo(
                sprintf('Producto de volumen %05d', $puestos),
                ['tipo' => $puestos % 7 === 0 ? 'peso' : 'unidad', 'ultimoCoste' => 2.5, 'iva' => 21, 'beneficio' => 30]
            );

            $movimientos += sembrarHistoria($siembra, $id, $ano, $proveedor, $tramo['movimientos'], $debe);

            if ($debe) {
                $negativos[] = $id;
            }
        }
    }

    $db->commit();

    return ['productos' => $puestos, 'movimientos' => $movimientos, 'negativos' => $negativos];
}

/**
 * Una historia de recepciones y ventas repartida por el ejercicio.
 *
 * Cuando el producto ha de terminar debiendo existencias, la ultima venta se lleva mas de
 * lo que hay: es la unica forma de que la extraccion tenga trabajo real que hacer y de que
 * la reconstruccion reciba filas que reconstruir.
 */
function sembrarHistoria(
    Siembra $siembra,
    int $idArticulo,
    string $ano,
    int $proveedor,
    int $movimientos,
    bool $debe
): int {
    $recibido = 0.0;
    $puestos = 0;

    for ($n = 0; $n < $movimientos; $n++) {
        $dia = sprintf('%s-%02d-%02d', $ano, ($n % 11) + 2, (($n * 3) % 27) + 1);

        if ($n % 2 === 0) {
            $siembra->entradaProveedor($idArticulo, 20.0, $dia, ['idProveedor' => $proveedor]);
            $recibido += 20.0;
        } else {
            $siembra->ventaTicket($idArticulo, 8.0, $dia);
            $recibido -= 8.0;
        }
        $puestos++;
    }

    if ($debe) {
        $siembra->ventaTicket($idArticulo, $recibido + 5.0, sprintf('%s-12-15', $ano));
        $puestos++;
    }

    return $puestos;
}

// --- Medida ---------------------------------------------------------------

/** La extraccion del estado del ejercicio vigente, sobre el catalogo completo. */
function medirExtraccion(int $productos): array
{
    $db = conectar(nombreDeLaBase('vigente'));
    $ano = ejercicioDe(nombreDeLaBase('vigente'));
    prepararProducto($db, '/modulos/mod_reorganizacion/clases/ClaseComprobacionStockExtraccion.php');

    $contexto = contextoDe($ano, $db);

    $antes = memoriaMaxima();
    $arranque = microtime(true);
    $estado = (new ClaseComprobacionStockExtraccion())->extraer($contexto);
    $segundos = microtime(true) - $arranque;

    $GLOBALS['estadoDelVigente'] = $estado;
    $db->close();

    return [
        'tramo' => sprintf('Extraccion del vigente (%d productos, %d salen)', $productos, count($estado)),
        'segundos' => $segundos,
        'bytes' => memoriaMaxima() - $antes,
    ];
}

/** La reconstruccion del ejercicio anterior, que emite cuatro consultas por producto. */
function medirReconstruccion(array $negativos): array
{
    $db = conectar(nombreDeLaBase('anterior'));
    $ano = ejercicioDe(nombreDeLaBase('anterior'));
    prepararProducto($db, '/modulos/mod_reorganizacion/clases/ClaseComprobacionStockMinimo.php');

    $filas = [];
    foreach ($negativos as $idArticulo) {
        $filas[] = ['idArticulo' => $idArticulo, 'comparable' => true, 'condicionesConocidas' => []];
    }

    $antes = memoriaMaxima();
    $arranque = microtime(true);
    (new ClaseComprobacionStockMinimo())->calcular($filas, ['ano' => $ano, 'idTienda' => 1], 1);
    $segundos = microtime(true) - $arranque;

    $db->close();

    return [
        'tramo' => sprintf('Reconstruccion del anterior (%d filas admitidas)', count($filas)),
        'segundos' => $segundos,
        'bytes' => memoriaMaxima() - $antes,
    ];
}

/** Componer y escribir el fichero de intercambio con todas las filas. */
function medirEmision(int $productos): array
{
    $db = conectar(nombreDeLaBase('vigente'));
    $ano = ejercicioDe(nombreDeLaBase('vigente'));
    prepararProducto($db, '/modulos/mod_reorganizacion/clases/ClaseComprobacionStockEmision.php');

    $_SESSION['usuarioTpv'] = ['id' => 1];
    $estado = $GLOBALS['estadoDelVigente'] ?? [];
    $destino = sys_get_temp_dir() . '/medida-esfuerzo-' . getmypid() . '.xml';

    $antes = memoriaMaxima();
    $arranque = microtime(true);
    $emision = new ClaseComprobacionStockEmision();
    $emision->emitir($emision->componer($estado, contextoDe($ano, $db), false), $destino);
    $segundos = microtime(true) - $arranque;

    $tamano = is_file($destino) ? filesize($destino) : 0;
    @unlink($destino);
    $db->close();

    return [
        'tramo' => sprintf('Emision del fichero (%d filas, %s)', count($estado), memoria($tamano)),
        'segundos' => $segundos,
        'bytes' => memoriaMaxima() - $antes,
    ];
}

// --- Apoyos ---------------------------------------------------------------

/**
 * Carga una clase del modulo y le hace usar esta conexion.
 *
 * El codigo del producto abre la suya propia desde `configuracion.php`, que apunta a una
 * sola base. Aqui hacen falta las dos, de modo que se inyecta la conexion en la propiedad
 * estatica que comparten todas las clases que heredan de `TFModelo` — el mismo camino que
 * usa la suite de integracion.
 */
function prepararProducto(mysqli $db, string $ruta): void
{
    global $RutaServidor, $HostNombre, $URLCom;

    if (!class_exists('ModeloP')) {
        require_once RUTA_TPVFOX . '/modulos/claseModeloP.php';
    }

    $propiedad = new ReflectionProperty('ModeloP', 'db');
    $propiedad->setAccessible(true);
    $propiedad->setValue(null, $db);

    require_once RUTA_TPVFOX . $ruta;
}

function contextoDe(string $ano, mysqli $db): array
{
    return [
        'ano' => $ano,
        'idTienda' => '1',
        'momento' => date('c'),
        'fechaCorte' => $ano . '-12-31',
        'ventanaDias' => 7,
        'umbralFraccionado' => 0.05,
        'umbralMagnitud' => 0.5,
        'umbralPorVenta' => 0.010,
        'timingVentanaDias' => 1,
        'proveedorCierre' => 1,
        'familiasExcluidas' => [],
    ];
}

function productosPedidos(array $argumentos): int
{
    foreach ($argumentos as $argumento) {
        if (strpos($argumento, '--productos=') === 0) {
            $valor = (int) substr($argumento, strlen('--productos='));
            if ($valor < 1) {
                fwrite(STDERR, "--productos pide un numero mayor que cero.\n");
                exit(1);
            }

            return $valor;
        }
    }

    return 4000;
}

function nombreDeLaBase(string $papel): string
{
    $variable = $papel === 'anterior' ? 'TPVFOX_TEST_DB_ANTERIOR' : 'TPVFOX_TEST_DB_VIGENTE';
    $base = Entorno::valor($variable);

    if (strpos($base, 'tpvfox_test') !== 0) {
        fwrite(STDERR, "La base «{$base}» no empieza por «tpvfox_test»: no se toca.\n");
        exit(1);
    }

    return $base;
}

function ejercicioDe(string $base): string
{
    if (!preg_match('/(\d{4})$/', $base, $coincidencia)) {
        fwrite(STDERR, "La base «{$base}» no termina en un ejercicio de cuatro cifras.\n");
        exit(1);
    }

    return $coincidencia[1];
}

function conectar(string $base): mysqli
{
    $db = @new mysqli(
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

function memoriaMaxima(): int
{
    return memory_get_peak_usage(true);
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

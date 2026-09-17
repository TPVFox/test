<?php
/**
 * Genera el informe consultable de las pruebas unitarias y de integracion, PHP y JS.
 *
 * El problema que resuelve: la salida de PHPUnit son puntos en un terminal. Lo que cada caso
 * comprueba, por que existe y que codigo recorre vive en su docblock y no llega a ninguna
 * parte. Este guion orquesta la ejecucion instrumentada y emite los datos que la vista sirve:
 *
 *   1. El resultado real de cada caso            -> XML de JUnit  (--log-junit)
 *   2. Las lineas de producto que cada caso toco -> volcado pcov  (--coverage-php)
 *   3. Lo que el dato hizo, paso a paso          -> bitacora de la conexion observada
 *   4. La cadena de llamadas                     -> traza de Xdebug, salvo con --sin-traza
 *   5. Lo que el caso dice de si mismo           -> docblock del metodo, por Reflection
 *
 * Y con todo ello hace tres contrastes que ninguna fuente puede hacer sola:
 *
 *   a. El estado que el caso declara contra el que de verdad tiene.
 *   b. El rango de codigo que declara cubrir contra las lineas que de verdad ejecuta.
 *   c. Que ningun fichero de pruebas cite identificadores del sistema de calidad.
 *
 * Anotaciones que el guion lee del docblock, todas opcionales:
 *
 *   @codigo-afectado modulos/mod_venta/tareas/AddTemporal.php:18-24   (repetible)
 *   @estado rojo | verde
 *
 * Uso:
 *   php support/informe-pruebas.php                        las dos suites de PHP y las de JS
 *   php support/informe-pruebas.php --suites=unit-php
 *   php support/informe-pruebas.php --salida=/ruta/informe
 *   php support/informe-pruebas.php --sin-js
 *   php support/informe-pruebas.php --sin-traza            mas rapido, sin cadena de llamadas
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Informe\Datos;

const SALIDA_POR_DEFECTO = __DIR__ . '/../informe-pruebas';

// Identificadores del sistema de calidad que no pueden figurar en el repositorio de pruebas.
const PATRON_CODIGOS = '/\b(CV-\d{2}|DS-[A-Z]{3}-[A-Z]{3}-\d{3}|DS-\d{3}|FS-\d{3}|UR-\d{3}|CQA-\d|CG-\d|DEV-\d{2,3}|RD-\d{4}-\d{3}|CC-\d{4}-\d{3}|OQ-\d{4}|IQ-\d{4}|PCP|MCT)\b/';

/**
 * Las seis anotaciones con que un caso se explica, las mismas que usan los recorridos de
 * navegador. Un caso de defecto lleva las cuatro primeras; uno de comportamiento correcto,
 * `comportamiento`; un control anade `para-que-sirve`.
 */
const ANOTACIONES = [
    'que-ocurre-hoy' => 'Qué ocurre hoy',
    'que-deberia-ocurrir' => 'Qué debería ocurrir',
    'por-que-ocurre' => 'Por qué ocurre',
    'como-deberia-funcionar' => 'Cómo debería funcionar',
    'comportamiento' => 'Comportamiento',
    'para-que-sirve' => 'Para qué sirve',
];

[$suites, $salida, $conJs, $conTraza] = leerArgumentos($argv);

$tmp = sys_get_temp_dir() . '/informe-pruebas-' . getmypid();
@mkdir($tmp, 0777, true);
$junit = "$tmp/junit.xml";
$cobertura = "$tmp/cobertura.cov";

// El flujo del dato se registra siempre: cuesta medio segundo sobre la suite entera. La traza
// de llamadas tambien, porque es la unica fuente que da **orden**: sin ella el informe sabe
// que ficheros se recorrieron pero no en que secuencia, y la mitad de la gracia se pierde.
// Cuesta disco temporal —un solo caso llega a 67 MB— y tiempo, y se quita con `--sin-traza`.
$flujo = "$tmp/flujo";
@mkdir($flujo . '/traza', 0777, true);

echo "Ejecutando {$suites}...\n";
$orden = sprintf(
    '%s TPVFOX_BITACORA=%s %s%s -d pcov.enabled=1 -d pcov.directory=%s %s --testsuite %s --log-junit %s --coverage-php %s',
    $conTraza ? 'TPVFOX_TRAZA=' . escapeshellarg($flujo . '/traza') : '',
    escapeshellarg($flujo),
    escapeshellarg(PHP_BINARY),
    $conTraza ? ' -d xdebug.mode=trace,coverage -d xdebug.trace_format=1 -d xdebug.collect_return=1 -d xdebug.use_compression=0' : '',
    escapeshellarg(dirname(RUTA_TPVFOX)),
    escapeshellarg(__DIR__ . '/../vendor/bin/phpunit'),
    escapeshellarg($suites),
    escapeshellarg($junit),
    escapeshellarg($cobertura)
);
exec($orden . ' 2>&1', $salidaOrden, $codigo);

if (!is_file($junit)) {
    fwrite(STDERR, "No se genero el XML de resultados. Salida de PHPUnit:\n" . implode("\n", $salidaOrden) . "\n");
    exit(1);
}

$casos = leerResultados($junit);
$lineasPorCaso = is_file($cobertura) ? leerCobertura($cobertura) : [];

// Jest emite su propio JSON sin necesidad de ningun reporter aparte, de modo que las suites
// de JS entran en el mismo informe sin anadir dependencias.
if ($conJs) {
    echo "Ejecutando las suites de JS...\n";
    $casos += leerResultadosJest($tmp . '/jest.json');
}

$casos = enriquecerConDocblocks($casos);
$citas = buscarCodigosDeCalidad(__DIR__ . '/..');

foreach ($casos as $id => $caso) {
    $casos[$id]['cobertura'] = $caso['cobertura'] ?? ($lineasPorCaso[$id] ?? []);
    $casos[$id]['avisos'] = contrastar($casos[$id]);
}

@mkdir($salida, 0777, true);

$emitidos = Datos::emitir($casos, $citas, $salida, $flujo);

// La vista es estatica y no depende de los datos: se copia tal cual junto a ellos.
foreach (glob(__DIR__ . '/informe/plantilla/*') ?: [] as $pieza) {
    copy($pieza, $salida . '/' . basename($pieza));
}

borrarArbol($tmp);

informarPorTerminal($casos, $citas, $salida);

exit(0);


/** @return array{0:string,1:string,2:bool,3:bool} */
function leerArgumentos(array $argv): array
{
    $suites = 'unit-php,integration-php';
    $salida = SALIDA_POR_DEFECTO;
    $conJs = true;
    $conTraza = true;

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--suites=')) {
            $suites = substr($arg, 9);
        } elseif (str_starts_with($arg, '--salida=')) {
            $salida = substr($arg, 9);
        } elseif ($arg === '--sin-js') {
            $conJs = false;
        } elseif ($arg === '--sin-traza') {
            $conTraza = false;
        }
    }

    return [$suites, $salida, $conJs, $conTraza];
}

/**
 * Cada `<testcase>` del XML de JUnit, indexado por `Clase::metodo`.
 *
 * Un caso sin hijos es verde. `<failure>` es una asercion que no se cumplio y `<error>` una
 * excepcion o un aviso de PHP que escapo: se distinguen porque no significan lo mismo.
 */
function leerResultados(string $ruta): array
{
    $xml = simplexml_load_file($ruta);
    $casos = [];

    foreach ($xml->xpath('//testcase') ?: [] as $nodo) {
        $clase = (string) $nodo['class'];
        $nombre = (string) $nodo['name'];
        if ($clase === '' || $nombre === '') {
            continue;
        }

        $estado = 'verde';
        $mensaje = '';
        if (isset($nodo->failure)) {
            $estado = 'fallo';
            $mensaje = trim((string) $nodo->failure);
        } elseif (isset($nodo->error)) {
            $estado = 'error';
            $mensaje = trim((string) $nodo->error);
        } elseif (isset($nodo->skipped)) {
            $estado = 'omitido';
        }

        // Un caso con `@dataProvider` repite nombre con el juego de datos entre parentesis.
        $base = preg_replace('/ with data set .*$/', '', $nombre) ?? $nombre;

        $casos["{$clase}::{$base}"] = [
            'clase' => $clase,
            'metodo' => $base,
            'fichero' => (string) $nodo['file'],
            'linea' => (int) $nodo['line'],
            'estado' => $estado,
            'mensaje' => $mensaje,
            'tiempo' => (float) $nodo['time'],
        ];
    }

    return $casos;
}

/**
 * Los casos de las suites de JS, en la misma forma que los de PHP.
 *
 * Jest no da cobertura por caso como `pcov`, de modo que estos casos no traen lineas de
 * producto; lo que si se lee es su estado y, si el fichero lo declara encima del caso, su
 * `@estado`. La prosa del caso vive en el nombre, que en Jest es una frase, no un metodo.
 */
function leerResultadosJest(string $ruta): array
{
    $orden = sprintf('npx jest --json --testLocationInResults --outputFile=%s', escapeshellarg($ruta));
    exec($orden . ' 2>/dev/null', $salida, $codigo);

    if (!is_file($ruta)) {
        fwrite(STDERR, "No se pudieron leer las suites de JS; el informe sale solo con PHP.\n");

        return [];
    }

    $datos = json_decode((string) file_get_contents($ruta), true);
    $casos = [];

    foreach ($datos['testResults'] ?? [] as $fichero) {
        $rutaFichero = (string) ($fichero['name'] ?? '');
        $nombreCorto = basename($rutaFichero);
        $fuente = is_file($rutaFichero) ? file($rutaFichero) : [];
        [$rutaProducto, $fuenteProducto] = scriptQuePrueba($rutaFichero, $fuente);

        foreach ($fichero['assertionResults'] ?? [] as $caso) {
            $estado = match ($caso['status'] ?? '') {
                'passed' => 'verde',
                'failed' => 'fallo',
                'pending', 'skipped', 'todo' => 'omitido',
                default => 'omitido',
            };

            $linea = (int) ($caso['location']['line'] ?? 0);
            $leido = anotacionesEnJs($fuente, $linea);

            // Jest no da cobertura por caso, pero el nombre del grupo es el de la funcion de
            // producto que el caso prueba: con eso se ensena su codigo, declarado y no medido.
            $cobertura = [];
            $grupo = (string) ($caso['ancestorTitles'][0] ?? '');
            if ($rutaProducto !== null && $grupo !== '') {
                foreach (preg_split('/\s+y\s+/', $grupo) ?: [] as $nombreFuncion) {
                    $rango = rangoDeFuncionJs($fuenteProducto, trim($nombreFuncion));
                    if ($rango !== null) {
                        $cobertura[$rutaProducto] = array_merge($cobertura[$rutaProducto] ?? [], $rango);
                    }
                }
            }

            $casos[$nombreCorto . '::' . $caso['fullName']] = [
                'clase' => $nombreCorto,
                'metodo' => (string) $caso['fullName'],
                'fichero' => $rutaFichero,
                'linea' => $linea,
                'estado' => $estado,
                'mensaje' => trim(implode("\n", $caso['failureMessages'] ?? [])),
                'tiempo' => ((int) ($caso['duration'] ?? 0)) / 1000,
                'prosa' => '',
                'declarado' => [],
                'estadoDeclarado' => $leido['estado'],
                'anotaciones' => $leido['anotaciones'],
                'etiquetas' => $leido['etiquetas'],
                'cobertura' => $cobertura,
                'medido' => false,
            ];
        }
    }

    return $casos;
}

/**
 * El script de producto que prueba un fichero de JS, y su contenido.
 *
 * Los ficheros de prueba declaran en una linea cual cargan. De ahi sale que codigo revisan sus
 * casos, sin que nadie tenga que escribirlo aparte.
 *
 * @param list<string> $fuente
 * @return array{0:?string, 1:list<string>}
 */
function scriptQuePrueba(string $rutaPrueba, array $fuente): array
{
    foreach ($fuente as $linea) {
        if (preg_match("/RUTA_SCRIPT\s*=\s*path\.resolve\(__dirname,\s*'([^']+)'/", $linea, $m) !== 1) {
            continue;
        }

        $ruta = realpath(dirname($rutaPrueba) . '/' . $m[1]);
        if ($ruta !== false && is_file($ruta)) {
            return [$ruta, file($ruta) ?: []];
        }
    }

    return [null, []];
}

/**
 * Las lineas de una funcion del script de producto, de su declaracion a la siguiente.
 *
 * @param list<string> $fuente
 * @return list<int>|null
 */
function rangoDeFuncionJs(array $fuente, string $nombre): ?array
{
    $total = count($fuente);

    foreach ($fuente as $i => $linea) {
        if (preg_match('/^\s*function\s+' . preg_quote($nombre, '/') . '\s*\(/', $linea) !== 1) {
            continue;
        }

        for ($j = $i + 1; $j < $total; $j++) {
            if (preg_match('/^function\s+\w+\s*\(/', $fuente[$j]) === 1) {
                return range($i + 1, $j);
            }
        }

        return range($i + 1, $total);
    }

    return null;
}

/**
 * Lo que el fichero de JS declara en el bloque de comentario inmediatamente anterior al caso:
 * su estado, sus anotaciones y sus etiquetas. Sin numero de linea no se busca: es preferible
 * no contrastar a dar un aviso falso.
 *
 * Jest no tiene `@group`, de modo que las etiquetas se declaran en una linea `@etiquetas`.
 *
 * @param list<string> $fuente
 * @return array{estado:?string, anotaciones:array<string,string>, etiquetas:list<string>}
 */
function anotacionesEnJs(array $fuente, int $linea): array
{
    $vacio = ['estado' => null, 'anotaciones' => [], 'etiquetas' => []];

    if ($linea <= 0 || $fuente === []) {
        return $vacio;
    }

    // Solo cuenta el bloque de comentario que precede al caso. Subiendo desde la linea que
    // Jest reporta se saltan las lineas en blanco y las del propio caso —un `test.each` abre
    // su tabla de datos varias lineas antes del titulo, de modo que el comentario no queda
    // pegado—, y se para en seco al llegar al final de otro caso o a la apertura de un grupo:
    // sin esa parada, un caso sin comentario heredaria el `@estado` del anterior y produciria
    // un aviso falso.
    $limite = max(0, $linea - 16);
    $i = $linea - 2;

    while ($i >= $limite) {
        $texto = trim($fuente[$i] ?? '');

        if ($texto === '') {
            $i--;
            continue;
        }
        if (str_starts_with($texto, '*/')) {
            break;
        }
        if (str_starts_with($texto, '}') || str_starts_with($texto, 'describe(')) {
            return $vacio;
        }

        $i--;
    }

    if ($i < $limite || !str_starts_with(trim($fuente[$i] ?? ''), '*/')) {
        return $vacio;
    }

    // El bloque se recoge entero y se interpreta con el mismo lector que el de PHP, para que
    // una anotacion signifique lo mismo en los dos lenguajes.
    $bloque = [];
    for ($j = $i; $j >= 0 && $j > $linea - 60; $j--) {
        $bloque[] = $fuente[$j];
        if (str_starts_with(trim($fuente[$j]), '/**')) {
            break;
        }
    }

    $leido = interpretarDocblock(implode('', array_reverse($bloque)));
    $etiquetas = $leido['etiquetas'];

    foreach (preg_split('/\R/', implode('', array_reverse($bloque))) ?: [] as $texto) {
        if (preg_match('/@etiquetas\s+(.+)$/', $texto, $m) === 1) {
            $etiquetas = array_merge($etiquetas, preg_split('/[\s,]+/', trim($m[1])) ?: []);
        }
    }

    return [
        'estado' => $leido['estadoDeclarado'],
        'anotaciones' => $leido['anotaciones'],
        'etiquetas' => array_values(array_filter(array_unique($etiquetas))),
    ];
}

/**
 * Lineas de producto que ejecuto cada caso, indexadas igual que los resultados.
 *
 * El volcado de `--coverage-php` devuelve el objeto de cobertura entero; su mapa por linea da,
 * para cada fichero y cada linea, la lista de casos que pasaron por ella. Se invierte.
 *
 * @return array<string, array<string, list<int>>>
 */
function leerCobertura(string $ruta): array
{
    $cobertura = require $ruta;
    $porCaso = [];

    foreach ($cobertura->getData()->lineCoverage() as $fichero => $lineas) {
        foreach ($lineas as $numero => $casos) {
            if (!is_array($casos)) {
                continue;
            }
            foreach ($casos as $caso) {
                $base = preg_replace('/ with data set .*$/', '', $caso) ?? $caso;
                $porCaso[$base][$fichero][] = $numero;
            }
        }
    }

    foreach ($porCaso as $caso => $ficheros) {
        foreach ($ficheros as $f => $lineas) {
            sort($lineas);
            $porCaso[$caso][$f] = $lineas;
        }
        // El fichero con mas lineas ejecutadas primero: suele ser el que el caso ejercita.
        uasort($porCaso[$caso], fn(array $a, array $b) => count($b) <=> count($a));
    }

    return $porCaso;
}

/**
 * Lee el docblock de cada metodo y extrae su prosa y sus anotaciones.
 *
 * PHPUnit corre en un subproceso, de modo que aqui no hay ninguna clase de prueba cargada y
 * `ReflectionMethod` no encuentra ninguna. El XML de JUnit trae el fichero de cada caso: se
 * carga antes de preguntar. Cargar un fichero de prueba solo define su clase —no ejecuta
 * nada—, y si dos casos comparten fichero el `require_once` lo carga una sola vez.
 */
function enriquecerConDocblocks(array $casos): array
{
    foreach ($casos as $id => $caso) {
        if (array_key_exists('prosa', $caso)) {
            continue; // Un caso de JS, que ya llega leido de su propio fichero.
        }

        $caso['prosa'] = '';
        $caso['declarado'] = [];
        $caso['estadoDeclarado'] = null;
        $caso['anotaciones'] = [];
        $caso['etiquetas'] = [];

        if (!class_exists($caso['clase'], false) && is_file($caso['fichero'])) {
            try {
                require_once $caso['fichero'];
            } catch (Throwable) {
                // Un fichero que no carga deja su caso sin prosa, no tumba el informe.
            }
        }

        try {
            $metodo = new ReflectionMethod($caso['clase'], $caso['metodo']);
            $doc = $metodo->getDocComment();
        } catch (Throwable) {
            $doc = false;
        }

        if ($doc !== false) {
            $caso = array_merge($caso, interpretarDocblock($doc));
        }

        $casos[$id] = $caso;
    }

    return $casos;
}

/**
 * @return array{
 *   prosa:string,
 *   declarado:list<array{ruta:string,desde:int,hasta:int}>,
 *   estadoDeclarado:?string,
 *   anotaciones:array<string,string>,
 *   etiquetas:list<string>
 * }
 */
function interpretarDocblock(string $doc): array
{
    $lineas = preg_split('/\R/', $doc) ?: [];
    $prosa = [];
    $declarado = [];
    $anotaciones = [];
    $etiquetas = [];
    $estado = null;
    $abierta = null;

    foreach ($lineas as $linea) {
        $texto = trim(preg_replace('#^\s*/?\*+/?#', '', $linea) ?? '');
        if ($texto === '' || $texto === '/') {
            // Una linea en blanco cierra la anotacion abierta, no la prosa.
            $abierta = null;
            continue;
        }

        if (preg_match('/^@codigo-afectado\s+(\S+?):(\d+)(?:-(\d+))?/', $texto, $m)) {
            $declarado[] = ['ruta' => $m[1], 'desde' => (int) $m[2], 'hasta' => (int) ($m[3] ?? $m[2])];
            $abierta = null;
            continue;
        }

        if (preg_match('/^@estado\s+(\w[\w-]*)/', $texto, $m)) {
            $estado = strtolower($m[1]);
            $abierta = null;
            continue;
        }

        // `@group` es la anotacion de PHPUnit, de modo que declarar una etiqueta sirve
        // ademas para filtrar por linea de ordenes con `--group`.
        if (preg_match('/^@group\s+(\S+)/', $texto, $m)) {
            $etiquetas[] = $m[1];
            $abierta = null;
            continue;
        }

        if (preg_match('/^@([a-z-]+)\s*(.*)$/', $texto, $m) && isset(ANOTACIONES[$m[1]])) {
            $abierta = $m[1];
            $anotaciones[$abierta] = trim($m[2]);
            continue;
        }

        if (str_starts_with($texto, '@')) {
            $abierta = null;
            continue;
        }

        // Una anotacion abierta se prolonga hasta la siguiente: asi puede ocupar parrafos.
        if ($abierta !== null) {
            $anotaciones[$abierta] = trim($anotaciones[$abierta] . ' ' . $texto);
            continue;
        }

        $prosa[] = $texto;
    }

    return [
        'prosa' => trim(implode(' ', $prosa)),
        'declarado' => $declarado,
        'estadoDeclarado' => $estado,
        'anotaciones' => array_filter($anotaciones, static fn(string $v): bool => $v !== ''),
        'etiquetas' => $etiquetas,
    ];
}

/**
 * Los tres contrastes. Devuelve la lista de avisos de un caso; vacia si todo cuadra.
 *
 * @return list<string>
 */
function contrastar(array $caso): array
{
    $avisos = [];
    $enRojo = in_array($caso['estado'], ['fallo', 'error'], true);

    if (($caso['estadoDeclarado'] ?? null) === 'rojo' && !$enRojo) {
        $avisos[] = 'Declara estar en rojo y pasa.';
    }
    if (($caso['estadoDeclarado'] ?? null) === 'verde' && $enRojo) {
        $avisos[] = 'Declara pasar y esta en rojo.';
    }
    if ($enRojo && ($caso['estadoDeclarado'] ?? null) === null) {
        $avisos[] = 'Esta en rojo y no lo declara.';
    }

    // Un caso que termina en error no deja cobertura: PHPUnit la descarta. Medido sobre la
    // suite: los 518 verdes y los 11 fallidos la traen, y los 3 con error no. Sin esta
    // salvedad el contraste diria que el fichero declarado «no se registra», que es cierto
    // pero enganoso: no es que no se recorriera, es que no hay con que comprobarlo.
    if (($caso['declarado'] ?? []) !== [] && ($caso['cobertura'] ?? []) === [] && $caso['estado'] === 'error') {
        $avisos[] = 'Declara codigo afectado y no se puede contrastar: un caso que termina en error no deja cobertura.';

        return $avisos;
    }

    foreach ($caso['declarado'] ?? [] as $rango) {
        $ejecutadas = lineasEjecutadasDe($caso, $rango['ruta']);
        if ($ejecutadas === null) {
            $avisos[] = "Declara cubrir {$rango['ruta']} y la cobertura no registra ese fichero.";
            continue;
        }
        $dentro = array_filter($ejecutadas, fn(int $l) => $l >= $rango['desde'] && $l <= $rango['hasta']);
        if ($dentro === []) {
            $avisos[] = "Declara cubrir {$rango['ruta']}:{$rango['desde']}-{$rango['hasta']} y no ejecuta ninguna de esas lineas.";
        }
    }

    return $avisos;
}

/** @return list<int>|null */
function lineasEjecutadasDe(array $caso, string $rutaRelativa): ?array
{
    foreach ($caso['cobertura'] ?? [] as $fichero => $lineas) {
        if (str_contains(str_replace('\\', '/', $fichero), trim($rutaRelativa, '/'))) {
            return $lineas;
        }
    }

    return null;
}

/**
 * Ficheros del repositorio de pruebas que citan identificadores del sistema de calidad.
 *
 * @return array<string, list<array{linea:int,texto:string}>>
 */
function buscarCodigosDeCalidad(string $raiz): array
{
    $hallazgos = [];
    $iterador = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f): bool {
                $nombre = $f->getFilename();
                if ($f->isDir()) {
                    return !in_array($nombre, ['node_modules', 'vendor', '.git', 'test-results', 'informe-pruebas'], true);
                }

                return (bool) preg_match('/\.(php|js)$/', $nombre);
            }
        )
    );

    foreach ($iterador as $fichero) {
        $ruta = $fichero->getPathname();
        if (str_contains($ruta, 'support/informe-pruebas.php')) {
            continue; // Este guion nombra los patrones por necesidad.
        }
        foreach (file($ruta) ?: [] as $i => $linea) {
            if (preg_match(PATRON_CODIGOS, $linea)) {
                $hallazgos[relativa($ruta, $raiz)][] = ['linea' => $i + 1, 'texto' => trim($linea)];
            }
        }
    }

    ksort($hallazgos);

    return $hallazgos;
}

function relativa(string $ruta, string $raiz): string
{
    $raiz = realpath($raiz) ?: $raiz;
    $ruta = realpath($ruta) ?: $ruta;

    return ltrim(str_replace($raiz, '', $ruta), '/');
}

/** Borra un arbol de ficheros temporales, incluidos los subdirectorios del flujo. */
function borrarArbol(string $ruta): void
{
    if (!is_dir($ruta)) {
        return;
    }

    foreach (scandir($ruta) ?: [] as $entrada) {
        if ($entrada === '.' || $entrada === '..') {
            continue;
        }
        $hijo = $ruta . '/' . $entrada;
        is_dir($hijo) ? borrarArbol($hijo) : @unlink($hijo);
    }

    @rmdir($ruta);
}

function informarPorTerminal(array $casos, array $citas, string $salida): void
{
    $rojos = count(array_filter($casos, fn(array $c) => in_array($c['estado'], ['fallo', 'error'], true)));
    $avisos = array_sum(array_map(fn(array $c) => count($c['avisos']), $casos));
    $citasTotal = array_sum(array_map('count', $citas));

    echo "\n";
    echo "Casos: " . count($casos) . "  |  en rojo: {$rojos}  |  avisos: {$avisos}  |  citas de calidad: {$citasTotal}\n";
    if (isset($GLOBALS['emitidos'])) {
        printf("Datos: %d casos, %d fuentes, %d con flujo registrado\n",
            $GLOBALS['emitidos']['casos'], $GLOBALS['emitidos']['fuentes'], $GLOBALS['emitidos']['conFlujo']);
        printf("Codigo: %d ficheros de producto, %d tablas\n",
            $GLOBALS['emitidos']['ficherosProducto'], $GLOBALS['emitidos']['tablas']);
    }
    echo "Informe: " . realpath($salida) . "/index.html\n\n";
}

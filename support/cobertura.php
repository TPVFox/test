<?php
/**
 * Mide la cobertura sobre el ambito que se le declare y la contrasta con el umbral.
 *
 * El umbral del 70% es el objetivo real, pero un porcentaje solo significa algo si se
 * dice sobre que se mide. Medir siempre sobre todo el producto convierte el numero en
 * ruido mientras haya codigo sin pruebas; medirlo sobre lo que una entrega produce dice
 * si esa entrega esta verificada.
 *
 * El ambito es obligatorio y no tiene valor por defecto: este repositorio prueba TPVFox
 * entero, y un ambito por defecto acabaria midiendo siempre lo de una entrega concreta.
 *
 * Uso:
 *   php support/cobertura.php modulos/mod_reorganizacion        una carpeta
 *   php support/cobertura.php clases/ClaseTFModelo.php          un fichero
 *   php support/cobertura.php mod_reorganizacion/clases/ClaseComprobacionStock   un prefijo
 *   php support/cobertura.php <ambito> --umbral=80
 *   php support/cobertura.php <ambito> --suites=unit-php
 *   php support/cobertura.php <ambito> --detalle                 que falta, linea a linea
 *
 * Con --detalle no basta el porcentaje: lista las lineas y los metodos que ningun
 * caso ejecuta. Un umbral cumplido no dice si lo que queda fuera es accesorio o es
 * justo la rama que nadie probo, y esa es la diferencia entre una entrega verificada
 * y una que solo lo parece.
 *
 * El ambito es una parte de la ruta: se conserva todo fichero medido que la contenga.
 * Un prefijo de nombre de fichero sirve para medir solo el codigo nuevo de un modulo que
 * ya tenia codigo antes.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const UMBRAL_POR_DEFECTO = 70.0;

[$ambito, $umbral, $suites, $detalle] = leerArgumentos($argv);

if ($ambito === '') {
    fwrite(STDERR,
        "Falta el ambito. Un porcentaje solo significa algo si se dice sobre que se mide.\n\n" .
        "  php support/cobertura.php modulos/mod_reorganizacion\n" .
        "  php support/cobertura.php clases/ClaseTFModelo.php\n");
    exit(1);
}

// pcov solo instrumenta su pcov.directory, y por defecto suele apuntar a este propio
// repositorio. El código bajo prueba vive en el hermano ../TPVFox, fuera de ese árbol,
// así que con el valor por defecto la cobertura sale siempre a 0 sin avisar. Se
// redirige aquí a la carpeta que contiene a los dos, sin tocar la configuración de la
// máquina.
$clover = sys_get_temp_dir() . '/cobertura-tpvfox-' . getmypid() . '.xml';
$orden  = sprintf(
    '%s -d pcov.directory=%s %s --testsuite %s --coverage-clover %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg(dirname(RUTA_TPVFOX)),
    escapeshellarg(__DIR__ . '/../vendor/bin/phpunit'),
    escapeshellarg($suites),
    escapeshellarg($clover)
);

passthru($orden, $salidaPhpunit);

if (!is_file($clover)) {
    fwrite(STDERR, "\nNo se genero informe de cobertura. Hace falta pcov o xdebug.\n");
    exit(1);
}

$medida = medir($clover, $ambito, $detalle);
@unlink($clover);

if ($medida['ficheros'] === 0) {
    fwrite(STDERR, "\nEl ambito «{$ambito}» no casa con ningun fichero medido.\n");
    fwrite(STDERR, "Comprueba la ruta, o el ambito de <coverage> en phpunit.xml.\n");
    exit(1);
}

informar($ambito, $umbral, $medida);

// Un fallo de la suite manda sobre el resultado de cobertura.
if ($salidaPhpunit !== 0) {
    fwrite(STDERR, "\nLa suite no paso: la cobertura no se da por buena.\n");
    exit($salidaPhpunit);
}

exit($medida['lineas']['porcentaje'] >= $umbral && $medida['metodos']['porcentaje'] >= $umbral ? 0 : 1);


/** @return array{0:string,1:float,2:string,3:bool} */
function leerArgumentos(array $argv): array
{
    $ambito = '';
    $umbral = UMBRAL_POR_DEFECTO;
    $suites = 'unit-php,integration-php';
    $detalle = false;

    foreach (array_slice($argv, 1) as $argumento) {
        if (str_starts_with($argumento, '--umbral=')) {
            $umbral = (float) substr($argumento, 9);
        } elseif (str_starts_with($argumento, '--suites=')) {
            $suites = substr($argumento, 9);
        } elseif ($argumento === '--detalle') {
            $detalle = true;
        } elseif (!str_starts_with($argumento, '--')) {
            $ambito = trim($argumento, '/');
        }
    }

    return [$ambito, $umbral, $suites, $detalle];
}

/**
 * Agrega las metricas de los ficheros cuya ruta contenga el ambito.
 *
 * @return array{ficheros:int,lineas:array,metodos:array,peores:array,sinCubrir:array}
 */
function medir(string $clover, string $ambito, bool $conDetalle = false): array
{
    $xml = simplexml_load_file($clover);

    $lineas = ['cubiertas' => 0, 'total' => 0];
    $metodos = ['cubiertos' => 0, 'total' => 0];
    $ficheros = 0;
    $porFichero = [];
    $sinCubrir = [];

    foreach ($xml->xpath('//file') ?: [] as $fichero) {
        $ruta = str_replace('\\', '/', (string) $fichero['name']);
        if ($ambito !== '' && !str_contains($ruta, $ambito)) {
            continue;
        }

        $m = $fichero->metrics;
        $ficheros++;
        $lineas['cubiertas']  += (int) $m['coveredstatements'];
        $lineas['total']      += (int) $m['statements'];
        $metodos['cubiertos'] += (int) $m['coveredmethods'];
        $metodos['total']     += (int) $m['methods'];

        if ((int) $m['statements'] > 0) {
            $porFichero[] = [
                'ruta'       => $ruta,
                'porcentaje' => 100 * (int) $m['coveredstatements'] / (int) $m['statements'],
                'total'      => (int) $m['statements'],
            ];
        }

        if ($conDetalle) {
            $huecos = sinCubrirDe($fichero);
            if ($huecos !== []) {
                $sinCubrir[$ruta] = $huecos;
            }
        }
    }

    usort($porFichero, fn(array $a, array $b) => $a['porcentaje'] <=> $b['porcentaje']);

    return [
        'ficheros'  => $ficheros,
        'lineas'    => $lineas + ['porcentaje' => porcentaje($lineas['cubiertas'], $lineas['total'])],
        'metodos'   => $metodos + ['porcentaje' => porcentaje($metodos['cubiertos'], $metodos['total'])],
        'peores'    => array_slice($porFichero, 0, 5),
        'sinCubrir' => $sinCubrir,
    ];
}

/**
 * Lineas y metodos de un fichero que ningun caso ejecuta.
 *
 * En Clover cada <line> lleva su contador de ejecuciones: a cero es que no se
 * ejecuto. Las de type="method" se separan de las demas porque un metodo entero sin
 * ejecutar y una rama suelta sin ejecutar no significan lo mismo.
 *
 * @return array{metodos:list<string>,lineas:list<int>}
 */
function sinCubrirDe(SimpleXMLElement $fichero): array
{
    $metodos = [];
    $lineas = [];

    foreach ($fichero->line ?: [] as $linea) {
        if ((int) $linea['count'] !== 0) {
            continue;
        }
        if ((string) $linea['type'] === 'method') {
            $metodos[] = (string) $linea['name'] . '()  linea ' . (int) $linea['num'];
        } else {
            $lineas[] = (int) $linea['num'];
        }
    }

    return ($metodos === [] && $lineas === []) ? [] : ['metodos' => $metodos, 'lineas' => $lineas];
}

function porcentaje(int $parte, int $total): float
{
    return $total === 0 ? 100.0 : round(100 * $parte / $total, 2);
}

function informar(string $ambito, float $umbral, array $medida): void
{
    $lineas  = $medida['lineas'];
    $metodos = $medida['metodos'];

    echo "\n";
    echo "Cobertura sobre «{$ambito}» — {$medida['ficheros']} ficheros medidos\n";
    printf("  Lineas   %6.2f%%  (%d/%d)   umbral %.0f%%  %s\n",
        $lineas['porcentaje'], $lineas['cubiertas'], $lineas['total'], $umbral,
        $lineas['porcentaje'] >= $umbral ? 'cumple' : 'NO CUMPLE');
    printf("  Metodos  %6.2f%%  (%d/%d)   umbral %.0f%%  %s\n",
        $metodos['porcentaje'], $metodos['cubiertos'], $metodos['total'], $umbral,
        $metodos['porcentaje'] >= $umbral ? 'cumple' : 'NO CUMPLE');

    if ($lineas['porcentaje'] < $umbral && $medida['peores'] !== []) {
        echo "\n  Lo que mas lastra:\n";
        foreach ($medida['peores'] as $f) {
            printf("    %6.2f%%  %s  (%d sentencias)\n", $f['porcentaje'], $f['ruta'], $f['total']);
        }
    }

    if ($medida['sinCubrir'] !== []) {
        echo "\n  Lo que ningun caso ejecuta:\n";
        foreach ($medida['sinCubrir'] as $ruta => $huecos) {
            echo "\n    " . basename($ruta) . "\n";
            foreach ($huecos['metodos'] as $metodo) {
                echo "      metodo  $metodo\n";
            }
            if ($huecos['lineas'] !== []) {
                echo '      lineas  ' . implode(', ', $huecos['lineas']) . "\n";
            }
        }
    }

    echo "\n";
}

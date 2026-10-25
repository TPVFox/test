<?php

/**
 * Genera `ARTICULO.DBF`, el fichero de ejemplo de la importación de `mod_importar`: un dBase III con las
 * columnas que la importación lee (`CODIGO`, `NOMBRE`, `STOCK`, `CODE_BAR`, `PCOSTE`, `PVENTA`, `PVP`,
 * `BENEFICIO`, `IVA` y `NULO`) y tres artículos inventados.
 *
 * Uso: php support/siembra/generar-articulo-dbf.php [ruta de salida]
 * Sin ruta, lo deja junto a este guion.
 */

declare(strict_types=1);

$salida = $argv[1] ?? __DIR__ . '/ARTICULO.DBF';

$campos = [
    ['CODIGO', 'C', 10, 0],
    ['NOMBRE', 'C', 40, 0],
    ['STOCK', 'N', 10, 3],
    ['CODE_BAR', 'C', 13, 0],
    ['PCOSTE', 'N', 10, 4],
    ['PVENTA', 'N', 10, 4],
    ['PVP', 'N', 10, 2],
    ['BENEFICIO', 'N', 6, 2],
    ['IVA', 'N', 5, 2],
    ['NULO', 'L', 1, 0],
];

$articulos = [
    ['PRU001', 'Articulo de prueba uno', 12.5, '8400000000017', 1.2, 1.6529, 2.0, 37.74, 21.0, false],
    ['PRU002', 'Articulo de prueba dos', 3.0, '8400000000024', 0.75, 0.9615, 1.0, 28.2, 4.0, false],
    ['PRU003', 'Articulo de prueba tres', 0.0, '', 2.1, 2.7273, 3.0, 29.87, 10.0, true],
];

$longitudRegistro = 1;
foreach ($campos as $campo) {
    $longitudRegistro += $campo[2];
}
$longitudCabecera = 32 + 32 * count($campos) + 1;

// Cabecera: versión dBase III, fecha de la última modificación, registros y longitudes.
$dbf = pack('CCCCVvv', 0x03, 126, 10, 25, count($articulos), $longitudCabecera, $longitudRegistro) . str_repeat("\0", 20);
foreach ($campos as [$nombre, $tipo, $longitud, $decimales]) {
    $dbf .= str_pad($nombre, 11, "\0") . $tipo . str_repeat("\0", 4) . pack('CC', $longitud, $decimales) . str_repeat("\0", 14);
}
$dbf .= "\r";

foreach ($articulos as $articulo) {
    $registro = ' ';
    foreach ($campos as $i => [$nombre, $tipo, $longitud, $decimales]) {
        $valor = $articulo[$i];
        if ($tipo === 'C') {
            $registro .= str_pad(substr($valor, 0, $longitud), $longitud);
        } elseif ($tipo === 'N') {
            $registro .= str_pad(number_format($valor, $decimales, '.', ''), $longitud, ' ', STR_PAD_LEFT);
        } else {
            $registro .= $valor ? 'T' : 'F';
        }
    }
    $dbf .= $registro;
}
$dbf .= "\x1A";

file_put_contents($salida, $dbf);
echo $salida . PHP_EOL;

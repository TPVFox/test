<?php

/**
 * Lee una traza de ejecucion y la convierte en la sucesion de llamadas que el informe pinta.
 *
 * ## El formato, verificado sobre trazas reales
 *
 * Cabecera de tres lineas (`Version:`, `File format: 4`, `TRACE START [...]`), cierre con
 * `TRACE END [...]`, y entre medias una linea por evento, con columnas separadas por
 * tabulador. Hay tres clases de registro, y la tercera columna dice cual es:
 *
 * | Col | Entrada (`0`)            | Salida (`1`) | Retorno (`R`) |
 * | --- | ------------------------ | ------------ | ------------- |
 * |  1  | nivel de anidamiento     | nivel        | nivel         |
 * |  2  | numero de llamada        | idem         | idem          |
 * |  3  | `0`                      | `1`          | `R`           |
 * |  4  | tiempo                   | tiempo       | vacia         |
 * |  5  | memoria                  | memoria      | vacia         |
 * |  6  | nombre de la funcion     | —            | valor devuelto|
 * |  7  | `1` de usuario, `0` interna | —          | —             |
 * |  8  | fichero incluido         | —            | —             |
 * |  9  | fichero que la llama     | —            | —             |
 * | 10  | linea que la llama       | —            | —             |
 * | 11  | numero de argumentos     | —            | —             |
 * | 12+ | un argumento por columna | —            | —             |
 *
 * El numero de llamada de la columna 2 es lo que empareja la entrada con su salida y su
 * retorno, y por eso se puede leer en un solo paso sin volver atras.
 *
 * **En una funcion interna, las columnas 9 y 10 traen el fichero y la linea de quien la
 * llamo**, no las suyas. Es lo que permite situar una consulta en el codigo del producto que
 * la emitio.
 *
 * ## Por que se descartan las internas
 *
 * Medido sobre el peor caso de la suite: de 888.252 lineas, 224.057 eran entradas a funciones
 * internas y, con sus salidas y retornos, sumaban el **75,7 %** del fichero. Son `rtrim`,
 * `sprintf`, `round` y companía dentro de un bucle: ruido que tapa el flujo. Se descartan
 * todas salvo la familia `mysqli`, que es justamente la que cuenta lo que el dato hizo.
 *
 * ## Por que hay tope
 *
 * La mediana de una traza filtrada son 540 lineas, pero un caso que emite el catalogo entero
 * llega a 216.000 despues de descartar internas. Se recorta, y **el recorte se declara**:
 * una traza cortada en silencio haria creer que el caso hizo menos de lo que hizo.
 */

declare(strict_types=1);

namespace TPVFox\Test\Informe;

final class LectorDeTraza
{
    /** Llamadas que se conservan por caso antes de recortar. */
    public const TOPE_POR_DEFECTO = 2000;

    /** Funciones internas que se conservan pese a serlo, porque son el flujo del dato. */
    private const INTERNAS_QUE_IMPORTAN = 'mysqli';

    /**
     * @return array{llamadas: list<array<string,mixed>>, total: int, recortado: bool, descartadas: int}
     */
    public static function leer(string $ruta, int $tope = self::TOPE_POR_DEFECTO): array
    {
        $vacio = ['llamadas' => [], 'total' => 0, 'recortado' => false, 'descartadas' => 0];

        if (!is_file($ruta)) {
            return $vacio;
        }

        // `gzopen` lee tambien ficheros sin comprimir, de modo que sirve para los dos casos
        // sin tener que mirar antes la extension.
        $fichero = @gzopen($ruta, 'rb');
        if ($fichero === false) {
            return $vacio;
        }

        $llamadas = [];
        $abiertas = [];
        $descartables = [];
        $total = 0;
        $descartadas = 0;

        while (($linea = gzgets($fichero)) !== false) {
            $columnas = explode("\t", rtrim($linea, "\r\n"));
            if (count($columnas) < 3) {
                continue;
            }

            $numero = $columnas[1];
            $tipo = $columnas[2];

            if ($tipo === '0') {
                if (count($columnas) < 11) {
                    continue;
                }

                $nombre = $columnas[5];
                $esDeUsuario = ($columnas[6] ?? '0') === '1';

                if (!$esDeUsuario && !str_starts_with($nombre, self::INTERNAS_QUE_IMPORTAN)) {
                    $descartables[$numero] = true;
                    $descartadas++;
                    continue;
                }

                $total++;

                if (count($llamadas) >= $tope) {
                    continue;
                }

                $indice = count($llamadas);
                $llamadas[$indice] = [
                    'nivel' => (int) $columnas[0],
                    'nombre' => $nombre,
                    'propia' => $esDeUsuario,
                    'fichero' => $columnas[8] ?? '',
                    'linea' => (int) ($columnas[9] ?? 0),
                    'argumentos' => array_slice($columnas, 11),
                    'retorno' => null,
                    'ms' => null,
                ];
                $abiertas[$numero] = ['indice' => $indice, 'tiempo' => (float) $columnas[3]];

                continue;
            }

            if (isset($descartables[$numero])) {
                continue;
            }

            if ($tipo === '1' && isset($abiertas[$numero])) {
                $llamadas[$abiertas[$numero]['indice']]['ms'] =
                    round(((float) ($columnas[3] ?? 0) - $abiertas[$numero]['tiempo']) * 1000, 3);

                continue;
            }

            if ($tipo === 'R' && isset($abiertas[$numero])) {
                $llamadas[$abiertas[$numero]['indice']]['retorno'] = $columnas[5] ?? null;
                unset($abiertas[$numero]);
            }
        }

        gzclose($fichero);

        return [
            'llamadas' => $llamadas,
            'total' => $total,
            'recortado' => $total > count($llamadas),
            'descartadas' => $descartadas,
        ];
    }
}

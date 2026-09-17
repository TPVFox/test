<?php

/**
 * Lo que le ha pasado a cada caso a lo largo de las ejecuciones.
 *
 * Hasta ahora cada generacion pisaba a la anterior, y el informe solo sabia hablar en presente:
 * «este caso esta en rojo». Lo que hace falta para llevar un registro de defectos mientras
 * esperan correccion es la otra mitad: **desde cuando**. Un defecto que lleva en rojo desde
 * agosto y uno que se puso en rojo esta manana no son el mismo asunto.
 *
 * **Que se guarda.** Una linea por caso y ejecucion —identificador, estado y milisegundos—, con
 * la fecha y los dos commits que la produjeron. Son unos 25 KB por pasada con 551 casos.
 *
 * **Que no se borra.** Nada. Un registro que se poda deja de servir justo para lo que se
 * guardo. Para componer la vista se leen las ultimas ejecuciones y no todas, que es otra cosa:
 * el fichero viejo sigue ahi aunque esta vista no lo mire.
 *
 * **Donde vive.** En un directorio propio, no dentro del informe emitido. El informe se genera
 * donde le digan —`--salida` sirve justamente para conservar copias— y atar la historia a la
 * carpeta de salida significaria que cada copia arranca su propio registro desde cero mientras
 * el de verdad se queda en otra parte.
 */

declare(strict_types=1);

namespace TPVFox\Test\Informe;

final class Historia
{
    /** Cuantas ejecuciones se leen para componer la vista. Las demas se conservan sin mirarse. */
    private const VENTANA = 60;

    /**
     * Apunta esta ejecucion y devuelve lo que la historia dice de cada caso.
     *
     * @param list<array<string,mixed>> $indice
     * @return array<string,mixed>
     */
    public static function registrar(string $dir, array $indice): array
    {
        @mkdir($dir, 0777, true);

        $ahora = [
            'fecha' => date('c'),
            'producto' => self::commitDe(defined('RUTA_TPVFOX') ? (string) constant('RUTA_TPVFOX') : ''),
            'pruebas' => self::commitDe(dirname(__DIR__, 2)),
            'casos' => array_map(
                static fn(array $c): array => [$c['id'], $c['estado'], (int) round(((float) $c['tiempo']) * 1000)],
                $indice
            ),
        ];

        $nombre = sprintf('%s-%s.json', date('Ymd-His'), $ahora['producto'] ?: 'sincommit');
        file_put_contents(
            rtrim($dir, '/') . '/' . $nombre,
            json_encode($ahora, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return self::componer($dir);
    }

    /**
     * La historia legible: que ejecuciones hay y desde cuando esta cada caso como esta.
     *
     * @return array<string,mixed>
     */
    private static function componer(string $dir): array
    {
        $ficheros = glob(rtrim($dir, '/') . '/*.json') ?: [];
        sort($ficheros);
        $guardadas = count($ficheros);
        $ficheros = array_slice($ficheros, -self::VENTANA);

        $ejecuciones = [];

        foreach ($ficheros as $ruta) {
            $pasada = json_decode((string) file_get_contents($ruta), true);

            if (is_array($pasada) && isset($pasada['casos'])) {
                $ejecuciones[] = $pasada;
            }
        }

        return [
            'guardadas' => $guardadas,
            'leidas' => count($ejecuciones),
            'pasadas' => array_map(self::resumirPasada(...), $ejecuciones),
            'porCaso' => self::porCaso($ejecuciones),
        ];
    }

    /**
     * Cuantos casos y cuantos rojos tuvo una ejecucion.
     *
     * @param array<string,mixed> $pasada
     * @return array<string,mixed>
     */
    private static function resumirPasada(array $pasada): array
    {
        $rojos = 0;

        foreach ($pasada['casos'] as [, $estado]) {
            if ($estado === 'fallo' || $estado === 'error') {
                $rojos++;
            }
        }

        return [
            'fecha' => $pasada['fecha'],
            'producto' => $pasada['producto'],
            'casos' => count($pasada['casos']),
            'rojos' => $rojos,
        ];
    }

    /**
     * Desde cuando esta cada caso en el estado en que esta.
     *
     * Se recorre hacia atras desde la ultima ejecucion hasta dar con una en que el caso estuviera
     * de otro color; la siguiente a esa es cuando empezo lo de ahora. Si en toda la ventana
     * estuvo igual, se dice eso y no se inventa una fecha: puede venir de antes.
     *
     * **Intermitente** es un caso que cambio de color sin que cambiara el commit del producto.
     * En una suite determinista no deberia pasar; cuando pasa, lo que falla es la prueba.
     *
     * @param list<array<string,mixed>> $ejecuciones
     * @return array<string,array<string,mixed>>
     */
    private static function porCaso(array $ejecuciones): array
    {
        if ($ejecuciones === []) {
            return [];
        }

        // Cada caso, con su estado y el commit de cada ejecucion, de la mas vieja a la mas nueva.
        $linea = [];

        foreach ($ejecuciones as $pasada) {
            foreach ($pasada['casos'] as [$id, $estado]) {
                $linea[$id][] = [$estado, $pasada['fecha'], $pasada['producto']];
            }
        }

        $porCaso = [];

        foreach ($linea as $id => $pasos) {
            $ultimo = end($pasos);
            $estado = $ultimo[0];
            $desde = $ultimo[1];
            $seguidas = 0;
            $completa = true;

            for ($i = count($pasos) - 1; $i >= 0; $i--) {
                if ($pasos[$i][0] !== $estado) {
                    $completa = false;
                    break;
                }

                $desde = $pasos[$i][1];
                $seguidas++;
            }

            $porCaso[$id] = [
                'estado' => $estado,
                'desde' => $completa ? null : $desde,
                'seguidas' => $seguidas,
                'ejecuciones' => count($pasos),
                'intermitente' => self::esIntermitente($pasos),
            ];
        }

        return $porCaso;
    }

    /**
     * Si el caso dio resultados distintos sin que cambiara el commit del producto.
     *
     * @param list<array{0:string,1:string,2:string}> $pasos
     */
    private static function esIntermitente(array $pasos): bool
    {
        $porCommit = [];

        foreach ($pasos as [$estado, , $commit]) {
            if ($commit === '') {
                continue;
            }

            $porCommit[$commit][$estado] = true;

            if (count($porCommit[$commit]) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * El commit corto de un repositorio, o cadena vacia si no se puede saber.
     *
     * `file_exists` y no `is_dir`: tanto TPVFox como esta suite son submodulos, y en un submodulo
     * `.git` es un fichero que apunta al repositorio de verdad, no un directorio.
     */
    private static function commitDe(string $dir): string
    {
        if ($dir === '' || !file_exists($dir . '/.git')) {
            return '';
        }

        $salida = [];
        @exec(sprintf('git -C %s rev-parse --short HEAD 2>/dev/null', escapeshellarg($dir)), $salida);

        return trim((string) ($salida[0] ?? ''));
    }
}

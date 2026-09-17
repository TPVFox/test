<?php

/**
 * Que ficheros del producto tocan los recorridos de navegador.
 *
 * El informe de pruebas PHP mide con precision lo que ejecuta, y por eso mismo deja fuera una
 * capa entera: las pantallas. `albaran.php`, `factura.php` y los listados suman 2.607 lineas al
 * 0,00 %, y leido a secas parece codigo sin probar. No lo esta —los recorridos de navegador
 * entran justo por ahi—, pero esa evidencia vive en otro informe que nunca se cruzaba con este.
 *
 * **De donde sale el dato, y con que limite.** De dos sitios:
 *
 * - **Del fuente de los recorridos**: la URL a la que navegan, que se escribe literal
 *   (`page.goto('modulos/mod_venta/pedido.php?...')`). Eso dice **por donde entran**, no todo lo
 *   que la peticion acaba tocando: un `tareas.php` invocado por AJAX desde la pantalla no se ve
 *   aqui. Es lo que el recorrido **nombra**, no lo que se midio, y asi se declara en la vista.
 * - **Del resultado de la ultima pasada**, si existe `E2E/resultados.json`. Sin el, la vista
 *   sigue funcionando y dice que no sabe como fue la ultima ejecucion.
 *
 * Medir de verdad lo que una peticion de navegador ejecuta exigiria cobertura en el servidor
 * durante la pasada E2E. Es la respuesta buena y es otra obra; hasta entonces, esto no se
 * presenta como cobertura sino como lo que es: por donde entra cada recorrido.
 */

declare(strict_types=1);

namespace TPVFox\Test\Informe;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class Recorridos
{
    /** Ficheros de producto que un recorrido puede nombrar al navegar. */
    private const PATRON_RUTA = '#\b((?:modulos|controllers|app)/[A-Za-z0-9_/]+\.php)#';

    /**
     * Que recorridos nombran cada fichero del producto.
     *
     * @return array<string, list<array{spec:string, veces:int}>>
     */
    public static function porFichero(string $dirSpecs): array
    {
        if (!is_dir($dirSpecs)) {
            return [];
        }

        $porFichero = [];
        $arbol = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirSpecs, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($arbol as $fichero) {
            if (!str_ends_with($fichero->getFilename(), '.spec.js')) {
                continue;
            }

            $spec = self::relativa($fichero->getPathname(), $dirSpecs);
            $fuente = (string) @file_get_contents($fichero->getPathname());

            if (!preg_match_all(self::PATRON_RUTA, $fuente, $m)) {
                continue;
            }

            $veces = array_count_values($m[1]);

            foreach ($veces as $ruta => $cuantas) {
                $porFichero[$ruta][] = ['spec' => $spec, 'veces' => $cuantas];
            }
        }

        foreach ($porFichero as $ruta => $specs) {
            usort($specs, static fn(array $a, array $b) => $b['veces'] <=> $a['veces']);
            $porFichero[$ruta] = $specs;
        }

        return $porFichero;
    }

    /**
     * Que ficheros de JavaScript carga cada pantalla por la que entra un recorrido.
     *
     * Una pantalla declara sus scripts con `<script src>`, de modo que si un recorrido entra por
     * `albaran.php` el navegador carga y ejecuta `funciones.js` y `AccionesDirectas.js` con él.
     * Es la misma clase de dato que el resto de esta vista: constancia de paso, no medida.
     *
     * @param array<string, list<array{spec:string, veces:int}>> $porPantalla
     * @return array<string, list<array{spec:string, veces:int}>>
     */
    public static function scripts(array $porPantalla, string $raizProducto): array
    {
        $porScript = [];

        foreach ($porPantalla as $pantalla => $specs) {
            $absoluta = rtrim($raizProducto, '/') . '/' . $pantalla;

            if (!is_file($absoluta)) {
                continue;
            }

            $fuente = (string) @file_get_contents($absoluta);

            if (!preg_match_all('#src=["\'][^"\']*?((?:modulos|controllers|app|clases)/[A-Za-z0-9_/.-]+\.js)#', $fuente, $m)) {
                continue;
            }

            foreach (array_unique($m[1]) as $script) {
                foreach ($specs as $spec) {
                    $porScript[$script][] = $spec;
                }
            }
        }

        // Una misma pantalla y un mismo recorrido pueden llegar por varios caminos: se funden.
        foreach ($porScript as $script => $specs) {
            $porNombre = [];

            foreach ($specs as $spec) {
                $porNombre[$spec['spec']] = ($porNombre[$spec['spec']] ?? 0) + $spec['veces'];
            }

            arsort($porNombre);
            $porScript[$script] = array_map(
                static fn(string $nombre, int $veces): array => ['spec' => $nombre, 'veces' => $veces],
                array_keys($porNombre),
                $porNombre
            );
        }

        return $porScript;
    }

    /**
     * Como le fue a cada recorrido en la ultima pasada, si quedo registrada.
     *
     * @return array{hay:bool, fecha:string, specs:array<string,array{casos:int, rojos:int}>}
     */
    public static function ultimaPasada(string $ruta): array
    {
        $vacio = ['hay' => false, 'fecha' => '', 'specs' => []];

        if (!is_file($ruta)) {
            return $vacio;
        }

        $json = json_decode((string) file_get_contents($ruta), true);

        if (!is_array($json) || !isset($json['suites'])) {
            return $vacio;
        }

        $specs = [];
        self::recorrerSuites($json['suites'], $specs);

        // Un listado deja el mismo fichero que una ejecucion. Medido: `playwright test --list`
        // escribe 45 ficheros con sus 102 casos, todos en `skipped` y sin un solo resultado.
        // Contarlo como pasada seria declarar en verde algo que nunca corrio.
        $ejecutados = array_sum(array_column($specs, 'casos'));

        return [
            'hay' => $ejecutados > 0,
            'fecha' => $ejecutados > 0 ? (string) ($json['stats']['startTime'] ?? '') : '',
            'specs' => $specs,
        ];
    }

    /**
     * Baja por el arbol de suites de Playwright sumando cada caso a su fichero.
     *
     * Las suites anidan —fichero, `describe`, `describe` dentro de otro—, y el nombre del
     * fichero solo esta en la de arriba, de modo que se arrastra hacia abajo.
     *
     * @param list<array<string,mixed>> $suites
     * @param array<string,array{casos:int, rojos:int}> $specs
     */
    private static function recorrerSuites(array $suites, array &$specs, string $fichero = ''): void
    {
        foreach ($suites as $suite) {
            $suyo = (string) ($suite['file'] ?? $fichero);

            foreach ($suite['specs'] ?? [] as $spec) {
                foreach ($spec['tests'] ?? [] as $caso) {
                    // Sin resultados no hubo ejecucion: el caso se listo, no corrio.
                    if (($caso['results'] ?? []) === []) {
                        continue;
                    }

                    $specs[$suyo] ??= ['casos' => 0, 'rojos' => 0];
                    $specs[$suyo]['casos']++;

                    if (!in_array((string) ($caso['status'] ?? ''), ['expected', 'skipped'], true)) {
                        $specs[$suyo]['rojos']++;
                    }
                }
            }

            if (($suite['suites'] ?? []) !== []) {
                self::recorrerSuites($suite['suites'], $specs, $suyo);
            }
        }
    }

    private static function relativa(string $ruta, string $raiz): string
    {
        $raiz = rtrim($raiz, '/') . '/';

        return str_starts_with($ruta, $raiz) ? substr($ruta, strlen($raiz)) : $ruta;
    }
}

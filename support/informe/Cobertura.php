<?php

/**
 * Quien cubre cada fichero del producto, cruzando los dos niveles de prueba.
 *
 * Un fichero al 0,00 % no significa lo mismo si nadie lo prueba que si lo prueban los recorridos
 * de navegador. Medido: `albaran.php` y `factura.php` suman 810 sentencias sin una sola linea de
 * cobertura PHP, y sin embargo ocho y doce recorridos entran por ellos. Leer solo el porcentaje
 * lleva a la conclusion contraria a la verdadera.
 *
 * Esta vista pone los dos al lado y clasifica cada fichero en uno de cuatro sitios:
 *
 * - **ambos** — lo miden las pruebas PHP y lo recorre el navegador.
 * - **php** — solo lo miden las pruebas PHP.
 * - **recorrido** — solo entran por el los recorridos. No hay medida, hay constancia de paso.
 * - **nadie** — ningun nivel lo toca. Este es el hueco de verdad.
 *
 * **El ambito se lee de `phpunit.xml`**, del mismo bloque `<coverage>` que usa la medida de
 * cobertura. Copiarlo aqui habria dejado dos listas que se separan en cuanto alguien toque una.
 *
 * **Lo que no es.** La columna de recorridos dice por donde **entra** un recorrido, no todo lo
 * que su peticion ejecuta: lo que la pantalla llame despues por AJAX no aparece. Medirlo de
 * verdad exigiria cobertura en el servidor durante la pasada de navegador, que es otra obra.
 */

declare(strict_types=1);

namespace TPVFox\Test\Informe;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;

final class Cobertura
{
    /**
     * El cruce entero, agrupado por modulo.
     *
     * @param array<string, array<int,bool>> $cubiertos Ruta absoluta a lineas ejecutadas.
     * @param array<string, list<array{spec:string, veces:int}>> $recorridos
     * @return array<string,mixed>
     */
    public static function componer(string $phpunitXml, array $cubiertos, array $recorridos, array $pasada): array
    {
        $ambito = self::ambitoDe($phpunitXml);
        $porModulo = [];
        $resumen = ['ambos' => 0, 'php' => 0, 'recorrido' => 0, 'nadie' => 0];

        foreach (self::ficherosDelAmbito($ambito) as $absoluta) {
            $ruta = self::relativa($absoluta);
            $lineasPhp = count($cubiertos[$absoluta] ?? []);
            $specs = $recorridos[$ruta] ?? [];

            $quien = match (true) {
                $lineasPhp > 0 && $specs !== [] => 'ambos',
                $lineasPhp > 0 => 'php',
                $specs !== [] => 'recorrido',
                default => 'nadie',
            };

            $resumen[$quien]++;
            $modulo = self::moduloDe($ruta);

            $porModulo[$modulo][] = [
                'ruta' => $ruta,
                'lineas' => self::cuantasLineas($absoluta),
                'phpLineas' => $lineasPhp,
                'specs' => $specs,
                'quien' => $quien,
            ];
        }

        $modulos = [];

        foreach ($porModulo as $nombre => $ficheros) {
            usort($ficheros, static fn(array $a, array $b) => [self::ORDEN[$b['quien']], $b['lineas']]
                <=> [self::ORDEN[$a['quien']], $a['lineas']]);

            $modulos[] = [
                'nombre' => $nombre,
                'total' => count($ficheros),
                'tocados' => count(array_filter($ficheros, static fn(array $f): bool => $f['quien'] !== 'nadie')),
                'ficheros' => $ficheros,
            ];
        }

        usort($modulos, static fn(array $a, array $b) => $b['tocados'] <=> $a['tocados']);

        return [
            'resumen' => $resumen + ['total' => array_sum($resumen)],
            'modulos' => $modulos,
            'pasada' => $pasada,
        ];
    }

    /** Lo cubierto primero, y el hueco al final: es el orden en que se lee. */
    private const ORDEN = ['ambos' => 4, 'php' => 3, 'recorrido' => 2, 'nadie' => 1];

    /**
     * El ambito de medida declarado en `phpunit.xml`.
     *
     * @return array{incluye:list<string>, excluye:list<string>}
     */
    private static function ambitoDe(string $ruta): array
    {
        $vacio = ['incluye' => [], 'excluye' => []];

        if (!is_file($ruta)) {
            return $vacio;
        }

        $xml = @simplexml_load_file($ruta);

        if (!$xml instanceof SimpleXMLElement || !isset($xml->coverage)) {
            return $vacio;
        }

        $base = dirname($ruta);
        $leer = static function ($nodos) use ($base): array {
            $rutas = [];

            foreach ($nodos ?? [] as $nodo) {
                $camino = realpath($base . '/' . trim((string) $nodo));
                if ($camino !== false) {
                    $rutas[] = $camino;
                }
            }

            return $rutas;
        };

        return [
            'incluye' => $leer($xml->coverage->include->directory ?? null),
            'excluye' => $leer($xml->coverage->exclude->directory ?? null),
        ];
    }

    /**
     * Los ficheros de producto que el ambito alcanza.
     *
     * @param array{incluye:list<string>, excluye:list<string>} $ambito
     * @return list<string>
     */
    private static function ficherosDelAmbito(array $ambito): array
    {
        $ficheros = [];

        foreach ($ambito['incluye'] as $directorio) {
            if (!is_dir($directorio)) {
                continue;
            }

            $arbol = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directorio, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($arbol as $fichero) {
                if ($fichero->getExtension() !== 'php') {
                    continue;
                }

                $ruta = $fichero->getPathname();

                foreach ($ambito['excluye'] as $fuera) {
                    if (str_starts_with($ruta, $fuera . '/')) {
                        continue 2;
                    }
                }

                $ficheros[] = $ruta;
            }
        }

        sort($ficheros);

        return $ficheros;
    }

    /** A que modulo pertenece un fichero, con nombre legible para los que no son de modulo. */
    private static function moduloDe(string $ruta): string
    {
        if (preg_match('#^modulos/([a-z_]+)/#', $ruta, $m) === 1) {
            return $m[1];
        }

        if (str_starts_with($ruta, 'modulos/')) {
            return '(común a los módulos)';
        }

        if (str_starts_with($ruta, 'clases/')) {
            return '(clases compartidas)';
        }

        return '(' . (explode('/', $ruta)[0] ?: 'otro') . ')';
    }

    private static function cuantasLineas(string $ruta): int
    {
        return is_file($ruta) ? count(file($ruta) ?: []) : 0;
    }

    private static function relativa(string $ruta): string
    {
        $producto = defined('RUTA_TPVFOX') ? rtrim((string) constant('RUTA_TPVFOX'), '/') . '/' : '';

        return $producto !== '' && str_starts_with($ruta, $producto)
            ? substr($ruta, strlen($producto))
            : $ruta;
    }
}

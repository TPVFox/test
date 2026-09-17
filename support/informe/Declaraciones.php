<?php

/**
 * Donde se declara cada clase y cada funcion del producto.
 *
 * Nace de un error medido. Antes, esto se resolvia leyendo solo los ficheros que la traza de
 * un caso mencionaba, y cuando una clase no aparecia entre ellos se daba por buena la del
 * llamante. El resultado: `ClaseComprobacionStockExtraccion->extraer` acababa listado dentro
 * de `PosstockQueryRepository.php`, y `ClaseComprobacionStockCantidad::normalizar` dentro de
 * `ClaseComprobacionStockExtraccion.php`. Adivinar la ubicacion de una funcion es peor que no
 * decirla: quien lee el informe no tiene como saber que es mentira.
 *
 * Leer el producto entero cuesta **0,4 s y 1.908 ficheros** para 1.366 clases, una sola vez
 * por informe, y a cambio no hay nada que adivinar. Frente al minuto que tarda la generacion,
 * no se nota.
 *
 * Las funciones sueltas se buscan sin sangria a proposito: en TPVFox las globales se declaran
 * en la primera columna y los metodos van indentados dentro de su clase, de modo que la
 * columna basta para distinguirlos sin analizar el fichero.
 */

declare(strict_types=1);

namespace TPVFox\Test\Informe;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class Declaraciones
{
    /** @var array<string,string>|null Clase a ruta absoluta. */
    private static ?array $clases = null;

    /** @var array<string,string>|null Funcion suelta, en minusculas, a ruta absoluta. */
    private static ?array $funciones = null;

    /** @var array<string,list<array{0:string,1:int}>>|null Metodo a los sitios donde se le nombra. */
    private static ?array $usos = null;

    /** Cuantos sitios se guardan por metodo: los suficientes para contar de donde viene. */
    private const USOS_POR_METODO = 12;

    /** @return array<string,string> */
    public static function clases(): array
    {
        self::cargar();

        return self::$clases ?? [];
    }

    /** @return array<string,string> */
    public static function funciones(): array
    {
        self::cargar();

        return self::$funciones ?? [];
    }

    /**
     * Donde se nombra un metodo dentro del producto.
     *
     * Sirve para decir de donde llega TPVFox a un sitio por el que una prueba entra. Es una
     * busqueda **por nombre**: que `consulta` aparezca en otra clase no prueba que sea la misma,
     * y el despacho dinamico —`$objeto->$metodo()`— se le escapa. El dato que sostiene es el
     * negativo: si un nombre no aparece en ningun sitio salvo su propia clase, nadie lo llama
     * desde fuera.
     *
     * @return list<array{0:string,1:int}> Ruta absoluta y linea.
     */
    public static function usos(string $metodo): array
    {
        self::cargar();

        return self::$usos[$metodo] ?? [];
    }

    private static function cargar(): void
    {
        if (self::$clases !== null) {
            return;
        }

        self::$clases = [];
        self::$funciones = [];
        self::$usos = [];

        $raiz = defined('RUTA_TPVFOX') ? (string) constant('RUTA_TPVFOX') : '';

        if ($raiz === '' || !is_dir($raiz)) {
            return;
        }

        $arbol = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($arbol as $fichero) {
            if ($fichero->getExtension() !== 'php') {
                continue;
            }

            $ruta = $fichero->getPathname();
            $fuente = (string) @file_get_contents($ruta);

            if (preg_match_all('/^\s*(?:abstract\s+|final\s+)?class\s+(\w+)/mi', $fuente, $m)) {
                foreach ($m[1] as $clase) {
                    self::$clases[$clase] = $ruta;
                }
            }

            if (preg_match_all('/^function\s+&?(\w+)\s*\(/mi', $fuente, $m)) {
                foreach ($m[1] as $funcion) {
                    self::$funciones[strtolower($funcion)] = $ruta;
                }
            }

            self::anotarUsos($ruta, $fuente);
        }
    }

    /** Los sitios de un fichero donde se invoca a algo, hasta el tope por metodo. */
    private static function anotarUsos(string $ruta, string $fuente): void
    {
        foreach (explode("\n", $fuente) as $numero => $linea) {
            if (!preg_match_all('/(?:->|::)(\w+)\s*\(/', $linea, $m)) {
                continue;
            }

            foreach ($m[1] as $metodo) {
                if (count(self::$usos[$metodo] ?? []) >= self::USOS_POR_METODO) {
                    continue;
                }

                self::$usos[$metodo][] = [$ruta, $numero + 1];
            }
        }
    }
}

<?php

/**
 * Funde en una sola sucesion lo que un caso hizo, para que el informe lo cuente como una
 * historia y no como dos listas sueltas.
 *
 * Hay dos instrumentos y ninguno basta solo:
 *
 * - **Las consultas**, que la conexion observada anota con su SQL, su emisor `fichero:linea`
 *   y lo que devolvieron. Cuentan el flujo del dato en todo lo que escribe en la base.
 * - **La traza de llamadas**, que dice en que orden se llamo a que, con que argumentos y que
 *   se devolvio. Es lo unico que hay en las clases de calculo puro, que no consultan nada:
 *   medido, 47 casos de la suite no emiten ni una sola consulta.
 *
 * La sucesion se ordena en los tres momentos que cualquiera reconoce —lo que se preparo, lo
 * que se ejercito y lo que se comprobo—, y esa division no sale del reloj sino de quien emite
 * cada paso: la siembra escribe con sentencias preparadas y el producto consulta, de modo que
 * se distinguen sin adivinar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Informe;

final class Flujo
{
    /** Consultas identicas seguidas que se agrupan en una sola linea con su contador. */
    private const AGRUPAR_REPETIDAS = true;

    /**
     * Tramos del camino a partir de los cuales deja de leerse y pasa a resumirse.
     *
     * Medido: un caso que emite el catalogo entero alterna dos ficheros en un bucle y produce
     * 1.980 tramos. Enumerarlos es peor que no decir nada; lo que sirve entonces es por que
     * ficheros paso y cuanto estuvo en cada uno.
     */
    private const TRAMOS_LEGIBLES = 40;

    /**
     * Marcos que no cuentan nada del caso y solo estorban: el armazon de pruebas y la propia
     * instrumentacion, que aparece en la traza por estar en medio de cada consulta.
     */
    private const ARMAZON = [
        'PHPUnit\\',
        'SebastianBergmann\\',
        'TPVFox\\Test\\Instrumentacion\\',
    ];

    /**
     * @return array{
     *   dadoQue: string,
     *   pasos: list<array<string,mixed>>,
     *   llamadas: list<array<string,mixed>>,
     *   consultas: int,
     *   recortado: bool
     * }
     */
    public static function componer(string $rutaPasos, ?string $rutaTraza = null, int $tope = 400): array
    {
        $pasos = self::leerPasos($rutaPasos);
        $siembra = [];

        foreach ($pasos as $paso) {
            if (($paso['fase'] ?? '') === 'preparacion') {
                // El origen, no la funcion: `funcion` es el metodo interno que acabo
                // ejecutando la escritura (`insertar`), y lo que cuenta el escenario es el
                // metodo publico que lo pidio (`articulo`, `ventaAlbaranCliente`).
                $metodo = self::metodoDeSiembra((string) (($paso['origen'] ?? '') ?: ($paso['funcion'] ?? '')));
                if ($metodo !== '') {
                    $siembra[] = $metodo;
                }
            }
        }

        $visibles = array_values(array_filter($pasos, static fn(array $p) => ($p['fase'] ?? '') !== 'preparacion'));
        $total = count($visibles);
        $visibles = array_slice($visibles, 0, $tope);

        if (self::AGRUPAR_REPETIDAS) {
            $visibles = self::agrupar($visibles);
        }

        $traza = $rutaTraza !== null ? LectorDeTraza::leer($rutaTraza) : ['llamadas' => [], 'recortado' => false];

        $llamadas = self::sinArmazon($traza['llamadas']);

        return [
            'dadoQue' => Vocabulario::dadoQue($siembra),
            'pasos' => $visibles,
            'llamadas' => $llamadas,
            'camino' => self::camino($llamadas, $pasos),
            'caminoResumen' => self::resumenDelCamino(self::camino($llamadas, $pasos)),
            'consultas' => $total,
            'recortado' => $total > $tope || ($traza['recortado'] ?? false),
        ];
    }

    /**
     * El camino por ficheros: por donde paso el caso, en orden y sin repetir.
     *
     * La cobertura pinta medio fichero en verde sin decir por que serie de entradas se llego
     * hasta ahi. Esto lo dice: agrupa llamadas consecutivas del mismo fichero en un tramo, de
     * modo que `tareas.php -> albaranesVentas.php -> claseModeloP.php` se lee de un vistazo.
     *
     * Sale de la traza cuando la hay, y si no de los emisores de las propias consultas: asi el
     * camino existe tambien en el informe de todos los dias, que no lleva traza.
     *
     * @param list<array<string,mixed>> $llamadas
     * @param list<array<string,mixed>> $pasos
     * @return list<array{fichero:string, funciones:list<string>, llamadas:int, fase:string}>
     */
    private static function camino(array $llamadas, array $pasos): array
    {
        $fuente = [];
        $declara = self::dondeSeDeclaraCadaClase($llamadas);

        foreach ($llamadas as $llamada) {
            $nombre = (string) ($llamada['nombre'] ?? '');

            // El fichero que declara la clase, no el que hizo la llamada. La traza apunta el
            // sitio del llamante, de modo que `ClaseVentas->__construct` invocado desde
            // `albaranesVentas.php` contaria como albaranesVentas y ClaseVentas quedaria fuera
            // del recorrido aunque su codigo se ejecutara. Lo que se quiere saber es por donde
            // paso el dato, no desde donde se le llamo.
            $clase = self::claseDe($nombre);

            $fuente[] = [
                'fichero' => $declara[$clase] ?? (string) ($llamada['fichero'] ?? ''),
                'funcion' => $nombre,
            ];
        }

        if ($fuente === []) {
            foreach ($pasos as $paso) {
                $fuente[] = [
                    'fichero' => (string) ($paso['fichero'] ?? ''),
                    'funcion' => (string) (($paso['origen'] ?? '') ?: ($paso['funcion'] ?? '')),
                ];
            }
        }

        $camino = [];

        foreach ($fuente as $punto) {
            // La instrumentacion no es un sitio por el que el dato pase: es por donde se mira.
            if ($punto['fichero'] === '' || str_contains($punto['fichero'], '/Instrumentacion/')) {
                continue;
            }

            $fase = self::faseDe($punto['fichero']);

            // Lo que no es producto no se detalla fichero a fichero: la pregunta del camino es
            // por donde va el dato **en el producto**, y sin esto la siembra mete decenas de
            // saltos por sus propias sentencias preparadas.
            $fichero = $fase === 'ejercicio' ? $punto['fichero'] : $fase;
            $ultimo = $camino === [] ? null : array_key_last($camino);

            if ($ultimo !== null && $camino[$ultimo]['fichero'] === $fichero) {
                $camino[$ultimo]['llamadas']++;
                if ($fase === 'ejercicio'
                    && $punto['funcion'] !== ''
                    && !in_array($punto['funcion'], $camino[$ultimo]['funciones'], true)
                ) {
                    $camino[$ultimo]['funciones'][] = $punto['funcion'];
                }
                continue;
            }

            $camino[] = [
                'fichero' => $fichero,
                'funciones' => $fase === 'ejercicio' && $punto['funcion'] !== '' ? [$punto['funcion']] : [],
                'llamadas' => 1,
                'fase' => $fase,
            ];
        }

        return $camino;
    }

    /**
     * Los ficheros del camino, cada uno una vez, en el orden en que aparecieron.
     *
     * Es la vista que sirve cuando el camino es largo: un bucle que salta entre dos ficheros
     * no cuenta una historia distinta cada vuelta, cuenta la misma muchas veces.
     *
     * @param list<array{fichero:string, funciones:list<string>, llamadas:int, fase:string}> $camino
     * @return array{tramos:list<array<string,mixed>>, resumido:bool}
     */
    private static function resumenDelCamino(array $camino): array
    {
        if (count($camino) <= self::TRAMOS_LEGIBLES) {
            return ['tramos' => [], 'resumido' => false];
        }

        $porFichero = [];

        foreach ($camino as $tramo) {
            $clave = $tramo['fichero'];

            if (!isset($porFichero[$clave])) {
                $porFichero[$clave] = [
                    'fichero' => $clave,
                    'fase' => $tramo['fase'],
                    'llamadas' => 0,
                    'visitas' => 0,
                    'funciones' => [],
                ];
            }

            $porFichero[$clave]['llamadas'] += $tramo['llamadas'];
            $porFichero[$clave]['visitas']++;

            foreach ($tramo['funciones'] as $funcion) {
                if (!in_array($funcion, $porFichero[$clave]['funciones'], true)) {
                    $porFichero[$clave]['funciones'][] = $funcion;
                }
            }
        }

        return ['tramos' => array_values($porFichero), 'resumido' => true];
    }

    /**
     * En que fichero se declara cada clase que aparece en la traza.
     *
     * Se leen solo los ficheros que la propia traza menciona: son unas pocas decenas, y evita
     * recorrer el producto entero para resolver un punado de nombres.
     *
     * @param list<array<string,mixed>> $llamadas
     * @return array<string,string>
     */
    private static function dondeSeDeclaraCadaClase(array $llamadas): array
    {
        $declara = [];
        $vistos = [];

        foreach ($llamadas as $llamada) {
            $ruta = (string) ($llamada['fichero'] ?? '');

            if ($ruta === '' || isset($vistos[$ruta]) || !is_file($ruta)) {
                continue;
            }

            $vistos[$ruta] = true;

            if (preg_match_all('/^\s*(?:abstract\s+|final\s+)?class\s+(\w+)/mi', (string) file_get_contents($ruta), $m)) {
                foreach ($m[1] as $clase) {
                    $declara[$clase] = $ruta;
                }
            }
        }

        return $declara;
    }

    /** La clase de un nombre `Clase->metodo` o `Clase::metodo`, si lo lleva. */
    private static function claseDe(string $funcion): string
    {
        $partes = preg_split('/::|->/', $funcion) ?: [];

        return count($partes) > 1 ? (string) $partes[0] : '';
    }

    /**
     * En que momento del caso cae un fichero: la misma division que usan los pasos de SQL.
     */
    private static function faseDe(string $fichero): string
    {
        $producto = defined('RUTA_TPVFOX') ? rtrim((string) constant('RUTA_TPVFOX'), '/') . '/' : null;

        if ($producto !== null && str_starts_with($fichero, $producto)) {
            return 'ejercicio';
        }

        return str_contains($fichero, '/support/siembra/') ? 'preparacion' : 'comprobacion';
    }

    /**
     * Los pasos que la bitacora dejo, uno por linea de JSON.
     *
     * Una linea ilegible se salta en vez de tumbar el informe: el fichero se escribe segun
     * ocurren las cosas, y un proceso que muere a mitad puede dejar la ultima a medias.
     *
     * @return list<array<string,mixed>>
     */
    private static function leerPasos(string $ruta): array
    {
        if (!is_file($ruta)) {
            return [];
        }

        $pasos = [];
        $fichero = @fopen($ruta, 'rb');
        if ($fichero === false) {
            return [];
        }

        while (($linea = fgets($fichero)) !== false) {
            $paso = json_decode(trim($linea), true);
            if (is_array($paso)) {
                $pasos[] = $paso;
            }
        }

        fclose($fichero);

        return $pasos;
    }

    /**
     * Agrupa consultas identicas seguidas en una sola, con las veces que se repitio.
     *
     * Un bucle que pide lo mismo dieciocho veces no aporta dieciocho lineas de informacion;
     * aporta una y un numero.
     *
     * @param list<array<string,mixed>> $pasos
     * @return list<array<string,mixed>>
     */
    private static function agrupar(array $pasos): array
    {
        $agrupados = [];

        foreach ($pasos as $paso) {
            $ultimo = $agrupados === [] ? null : array_key_last($agrupados);

            if ($ultimo !== null
                && ($agrupados[$ultimo]['sql'] ?? null) === ($paso['sql'] ?? null)
                && ($agrupados[$ultimo]['linea'] ?? null) === ($paso['linea'] ?? null)
            ) {
                $agrupados[$ultimo]['veces'] = ($agrupados[$ultimo]['veces'] ?? 1) + 1;
                continue;
            }

            $paso['veces'] = 1;
            $agrupados[] = $paso;
        }

        return $agrupados;
    }

    /**
     * El nombre del metodo de siembra de una funcion emisora, si lo es.
     *
     * La funcion llega como `Espacio\Siembra::articulo`; aqui interesa solo `articulo`, que
     * es lo que el vocabulario sabe traducir.
     */
    private static function metodoDeSiembra(string $funcion): string
    {
        if (!str_contains($funcion, 'Siembra')) {
            return '';
        }

        $partes = preg_split('/::|->/', $funcion) ?: [];

        return count($partes) > 1 ? (string) end($partes) : '';
    }

    /**
     * Quita de la traza los marcos del armazon de pruebas.
     *
     * Medido: son cuatro por caso —`setUp`, `toString`, el manejador de errores y
     * `tearDown`—, siempre los mismos, y no dicen nada de lo que el caso hace.
     *
     * @param list<array<string,mixed>> $llamadas
     * @return list<array<string,mixed>>
     */
    private static function sinArmazon(array $llamadas): array
    {
        return array_values(array_filter($llamadas, static function (array $llamada): bool {
            foreach (self::ARMAZON as $prefijo) {
                if (str_starts_with((string) ($llamada['nombre'] ?? ''), $prefijo)) {
                    return false;
                }
            }

            return true;
        }));
    }
}

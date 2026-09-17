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
     * Marcos que se tiran: el corredor de pruebas y el propio instrumento.
     *
     * Las tripas de PHPUnit no son andamiaje de este repositorio. Y la instrumentacion aparece
     * en la traza por estar en medio de cada consulta —medido en un caso de despacho, 97 de
     * sus 217 marcos de armazon eran la conexion observada y la bitacora mirandose a si
     * mismas—: es por donde se mira, no un sitio por donde el dato pase.
     */
    private const FUERA = [
        'PHPUnit\\',
        'SebastianBergmann\\',
        'TPVFox\\Test\\Instrumentacion\\',
    ];

    /**
     * Marcos que se apartan pero se conservan: el andamiaje propio de la suite.
     *
     * Cuenta como se monto el caso —la conexion, el entorno, el despacho—, que es util cuando
     * se duda de la propia prueba, pero no es lo que el caso valida.
     */
    private const ARMAZON = [
        'TPVFox\\Test\\',
    ];

    /** Construcciones del lenguaje que la traza presenta como funciones. */
    private const CONSTRUCCIONES_DEL_LENGUAJE = ['require', 'require_once', 'include', 'include_once', 'eval'];

    /** Cuantos marcos del armazon se conservan para poder consultarlos sin inflar la ficha. */
    private const ARMAZON_VISIBLE = 60;

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

        $visibles = array_map(static function (array $paso): array {
            $paso['montaje'] = self::esMontaje((string) (($paso['origen'] ?? '') ?: ($paso['funcion'] ?? '')));

            return $paso;
        }, $visibles);

        $traza = $rutaTraza !== null ? LectorDeTraza::leer($rutaTraza) : ['llamadas' => [], 'recortado' => false];

        // Donde se declara cada cosa se resuelve una vez y sirve para las dos preguntas: si un
        // marco es del producto o del armazon, y a que fichero pertenece cada tramo del camino.
        $declara = self::dondeSeDeclara($traza['llamadas']);
        $reparto = self::repartir($traza['llamadas'], $declara);
        $llamadas = $reparto['producto'];
        $camino = self::camino($llamadas, $pasos, $declara['clases']);

        return [
            'dadoQue' => Vocabulario::dadoQue($siembra),
            'pasos' => $visibles,
            'llamadas' => $llamadas,
            'armazon' => array_slice($reparto['armazon'], 0, self::ARMAZON_VISIBLE),
            'armazonTotal' => count($reparto['armazon']),
            'avisos' => $reparto['avisos'],
            'camino' => $camino,
            'caminoResumen' => self::resumenDelCamino($camino),
            'consultas' => $total,
            'montaje' => count(array_filter($visibles, static fn(array $p) => ($p['montaje'] ?? false) === true)),
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
    private static function camino(array $llamadas, array $pasos, array $declara): array
    {
        $fuente = [];

        foreach ($llamadas as $llamada) {
            $nombre = (string) ($llamada['nombre'] ?? '');
            $desde = (string) ($llamada['fichero'] ?? '');

            // El fichero que declara la clase, no el que hizo la llamada. La traza apunta el
            // sitio del llamante, de modo que `ClaseVentas->__construct` invocado desde
            // `albaranesVentas.php` contaria como albaranesVentas y ClaseVentas quedaria fuera
            // del recorrido aunque su codigo se ejecutara. Lo que se quiere saber es por donde
            // paso el dato, no desde donde se le llamo.
            $clase = self::claseDe($nombre);
            $declarado = $declara[$clase] ?? $desde;

            // Pero de donde se llamo tambien es parte del recorrido, y si no se anota se
            // pierde el punto de entrada: un despacho como `tareas.php` no declara ninguna
            // clase, de modo que atribuyendo solo a la clase que declara desaparecia del
            // camino justo el fichero por el que el caso entra.
            if ($desde !== '' && $desde !== $declarado) {
                $fuente[] = ['fichero' => $desde, 'funcion' => ''];
            }

            $fuente[] = ['fichero' => $declarado, 'funcion' => $nombre];
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
            // Relativa al producto: es como se nombra el mismo fichero en la cobertura y en la
            // vista por codigo, y una ruta absoluta de esta maquina no dice nada a quien lee.
            $fichero = $fase === 'ejercicio' ? self::relativa($punto['fichero']) : $fase;
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

        // Un tramo donde solo se construyen objetos no es un sitio por donde el dato pasara:
        // es la cabecera de un despacho montando lo que quiza use despues. Decirlo evita leer
        // `pedidosVentas.php -> albaranesVentas.php -> cliente.php` como un recorrido cuando
        // fueron cinco `new` seguidos.
        foreach ($camino as $indice => $tramo) {
            $camino[$indice]['soloConstruye'] = $tramo['funciones'] !== [] && array_reduce(
                $tramo['funciones'],
                static fn(bool $lleva, string $funcion): bool => $lleva && self::esMontaje($funcion),
                true
            );
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
     * Donde se declara cada clase y cada funcion suelta que aparece en la traza.
     *
     * Se leen solo los ficheros de producto que la propia traza menciona: son unas pocas
     * decenas, y evita recorrer TPVFox entero para resolver un punado de nombres. Sirve para
     * dos cosas distintas: saber a que fichero pertenece un tramo del camino, y decidir si un
     * marco es del producto o del armazon de pruebas.
     *
     * @param list<array<string,mixed>> $llamadas
     * @return array{clases:array<string,string>, funciones:array<string,string>}
     */
    private static function dondeSeDeclara(array $llamadas): array
    {
        $clases = [];
        $funciones = [];
        $vistos = [];

        foreach ($llamadas as $llamada) {
            $ruta = (string) ($llamada['fichero'] ?? '');

            if ($ruta === '' || isset($vistos[$ruta]) || self::faseDe($ruta) !== 'ejercicio' || !is_file($ruta)) {
                continue;
            }

            $vistos[$ruta] = true;
            $fuente = (string) file_get_contents($ruta);

            if (preg_match_all('/^\s*(?:abstract\s+|final\s+)?class\s+(\w+)/mi', $fuente, $m)) {
                foreach ($m[1] as $clase) {
                    $clases[$clase] = $ruta;
                }
            }

            if (preg_match_all('/^\s*function\s+&?(\w+)\s*\(/mi', $fuente, $m)) {
                foreach ($m[1] as $funcion) {
                    $funciones[strtolower($funcion)] = $ruta;
                }
            }
        }

        return ['clases' => $clases, 'funciones' => $funciones];
    }

    /** La ruta sin el prefijo del producto, que es como se nombra el fichero en todo el informe. */
    private static function relativa(string $ruta): string
    {
        $producto = defined('RUTA_TPVFOX') ? rtrim((string) constant('RUTA_TPVFOX'), '/') . '/' : '';

        return $producto !== '' && str_starts_with($ruta, $producto)
            ? substr($ruta, strlen($producto))
            : $ruta;
    }

    /** Si una funcion es un constructor, es decir, montaje y no trabajo del caso. */
    private static function esMontaje(string $funcion): bool
    {
        return str_ends_with($funcion, '__construct');
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
     * Reparte la traza en las tres cosas distintas que contiene.
     *
     * Una traza en crudo mezcla lo que hizo TPVFox con lo que hizo la prueba para poder
     * llamarlo, y eso descoloca a quien lee: medido en un caso corriente de despacho, de 52
     * marcos solo 17 eran del producto. Aqui se separan:
     *
     * - **producto**: lo que de verdad ejecuto TPVFox.
     * - **armazon**: el andamiaje de la prueba. No se tira, se aparta: sigue estando para
     *   quien quiera ver como se monto el caso.
     * - **avisos**: los avisos de PHP. Aparecen porque el despacho instala un manejador de
     *   errores que los silencia, y la traza registra cada llamada al manejador con su
     *   mensaje, su fichero y su linea. Es la unica forma de ver lo que el producto avisa.
     *
     * @param list<array<string,mixed>> $llamadas
     * @param array{clases:array<string,string>, funciones:array<string,string>} $declara
     * @return array{producto:list<array<string,mixed>>, armazon:list<array<string,mixed>>, avisos:list<array<string,mixed>>}
     */
    private static function repartir(array $llamadas, array $declara): array
    {
        $producto = [];
        $armazon = [];
        $avisos = [];

        foreach ($llamadas as $llamada) {
            if (self::empiezaPor((string) ($llamada['nombre'] ?? ''), self::FUERA)) {
                continue;
            }

            $aviso = self::avisoDe($llamada);

            if ($aviso !== null) {
                self::sumarAviso($avisos, $aviso);
                continue;
            }

            if (self::esDelProducto($llamada, $declara)) {
                $producto[] = $llamada;
            } else {
                $armazon[] = $llamada;
            }
        }

        return ['producto' => $producto, 'armazon' => $armazon, 'avisos' => array_values($avisos)];
    }

    /**
     * Si un marco de la traza es codigo de TPVFox.
     *
     * Por donde se declara, no por donde se llamo: la traza apunta el sitio del llamante, de
     * modo que un metodo del producto invocado desde una prueba tiene fichero de prueba. Y al
     * reves, el armazon aparece con fichero del producto cuando es el producto quien lo
     * dispara. Cuando no se sabe donde se declara algo —una funcion interna de PHP—, vale el
     * sitio desde el que se llamo: si lo llamo el producto, el dato paso por ahi.
     *
     * @param array<string,mixed> $llamada
     * @param array{clases:array<string,string>, funciones:array<string,string>} $declara
     */
    private static function esDelProducto(array $llamada, array $declara): bool
    {
        $nombre = (string) ($llamada['nombre'] ?? '');
        $fichero = (string) ($llamada['fichero'] ?? '');

        if (self::empiezaPor($nombre, self::ARMAZON)) {
            return false;
        }

        // La instrumentacion no es un sitio por el que el dato pase: es por donde se mira.
        if (str_contains($fichero, '/Instrumentacion/')) {
            return false;
        }

        $clase = self::claseDe($nombre);

        if ($clase !== '') {
            return isset($declara['clases'][$clase]) || self::faseDe($fichero) === 'ejercicio';
        }

        if (isset($declara['funciones'][strtolower($nombre)])) {
            return true;
        }

        if (in_array($nombre, self::CONSTRUCCIONES_DEL_LENGUAJE, true)) {
            return self::faseDe($fichero) === 'ejercicio';
        }

        return self::faseDe($fichero) === 'ejercicio';
    }

    /**
     * El aviso de PHP que hay detras de una llamada al manejador de errores, si lo es.
     *
     * Se reconoce por la firma: cuatro argumentos, el primero y el ultimo numericos, que son
     * el nivel y la linea. Los argumentos llegan de la traza como literales de PHP, con sus
     * comillas y sus escapes.
     *
     * @param array<string,mixed> $llamada
     * @return array<string,mixed>|null
     */
    private static function avisoDe(array $llamada): ?array
    {
        $argumentos = $llamada['argumentos'] ?? [];

        if (!is_array($argumentos) || count($argumentos) !== 4) {
            return null;
        }

        $nivel = trim((string) $argumentos[0]);
        $linea = trim((string) $argumentos[3]);

        if (!ctype_digit($nivel) || !ctype_digit($linea)) {
            return null;
        }

        $mensaje = self::literal((string) $argumentos[1]);
        $fichero = self::literal((string) $argumentos[2]);

        if ($mensaje === '') {
            return null;
        }

        $aviso = [
            'nivel' => self::nombreDelNivel((int) $nivel),
            'mensaje' => $mensaje,
            'fichero' => self::relativa($fichero),
            'linea' => (int) $linea,
            'veces' => 1,
        ];

        // Un include que falla deja dos avisos seguidos con la misma linea, uno con la ruta y
        // otro sin ella. Se funden en uno, y se dice si el fichero existe: un caso que apunta
        // a un fichero borrado se explica solo, y hasta ahora no se explicaba en ninguna parte.
        if (preg_match('/^(include|include_once|require|require_once)\((.*?)\)\s*:/', $mensaje, $m) === 1) {
            $aviso['construccion'] = $m[1];

            if ($m[2] !== '') {
                $aviso['incluye'] = $m[2];
                $aviso['existe'] = str_starts_with($m[2], '/') ? is_file($m[2]) : null;
            }
        }

        return $aviso;
    }

    /**
     * Suma un aviso a los que ya hay, fundiendo los que cuentan el mismo suceso.
     *
     * @param array<string,array<string,mixed>> $avisos
     * @param array<string,mixed> $aviso
     */
    private static function sumarAviso(array &$avisos, array $aviso): void
    {
        $clave = $aviso['fichero'] . ':' . $aviso['linea'] . ':' . ($aviso['construccion'] ?? $aviso['mensaje']);

        if (!isset($avisos[$clave])) {
            $avisos[$clave] = $aviso;

            return;
        }

        $avisos[$clave]['veces']++;

        // De los dos mensajes de un include fallido vale el que trae la ruta.
        if (isset($aviso['incluye']) && !isset($avisos[$clave]['incluye'])) {
            $avisos[$clave]['incluye'] = $aviso['incluye'];
            $avisos[$clave]['existe'] = $aviso['existe'];
            $avisos[$clave]['mensaje'] = $aviso['mensaje'];
        }
    }

    /** Si un nombre empieza por alguno de unos prefijos. */
    private static function empiezaPor(string $nombre, array $prefijos): bool
    {
        foreach ($prefijos as $prefijo) {
            if (str_starts_with($nombre, $prefijo)) {
                return true;
            }
        }

        return false;
    }

    /** El valor de un literal de PHP tal como lo escribe la traza. */
    private static function literal(string $bruto): string
    {
        $bruto = trim($bruto);

        if (strlen($bruto) >= 2 && $bruto[0] === "'" && str_ends_with($bruto, "'")) {
            $bruto = substr($bruto, 1, -1);
        }

        return str_replace(["\\'", '\\\\'], ["'", '\\'], $bruto);
    }

    /** El nombre corriente de un nivel de error de PHP. */
    private static function nombreDelNivel(int $nivel): string
    {
        return match ($nivel) {
            E_WARNING, E_USER_WARNING => 'aviso',
            E_NOTICE, E_USER_NOTICE => 'apunte',
            E_DEPRECATED, E_USER_DEPRECATED => 'obsoleto',
            E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'error',
            default => 'aviso',
        };
    }
}

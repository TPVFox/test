<?php

/**
 * Invierte el eje del informe: de «que hace este caso» a «que le pasa a esta funcion».
 *
 * El informe nacio contando casos, y eso responde a una pregunta: si un test esta bien y que
 * comprueba. Pero los mismos datos responden a otra que importa mas al mantener el producto:
 * **por donde se ejercita una funcion, que tablas mueve y que casos la cubren**. Un fichero de
 * TPVFox deja de ser una lista de lineas verdes y pasa a ser una lista de funciones, cada una
 * con lo que hace y con quien la vigila.
 *
 * No hace falta instrumentacion nueva: los pasos ya traen el fichero, la funcion de negocio
 * que pidio cada consulta y el SQL. De ahi salen las tres agrupaciones.
 *
 * **Como se decide si una funcion lee o escribe una tabla.** Por el verbo de la sentencia:
 * `SELECT` lee, y `INSERT`, `UPDATE`, `DELETE` y `REPLACE` escriben. Una sentencia que mezcle
 * las dos cosas —un `INSERT ... SELECT`— se cuenta entera como escritura; es una aproximacion,
 * y se prefiere pecar de declarar escritura que de ocultarla.
 */

declare(strict_types=1);

namespace TPVFox\Test\Informe;

final class Codigo
{
    /** Palabras que siguen a FROM o JOIN sin ser una tabla. */
    private const NO_SON_TABLAS = ['dual', 'select'];

    /**
     * Construcciones del lenguaje que la pila presenta como funciones.
     *
     * No son codigo de nadie: son como el fichero entro en memoria. Agruparlas dejaba una
     * funcion llamada `require` con dieciseis casos a su nombre.
     */
    private const NO_SON_FUNCIONES = ['require', 'require_once', 'include', 'include_once', 'eval'];

    /**
     * Clases que no son del producto aunque aparezcan en su recorrido.
     *
     * La traza apunta el sitio del llamante, de modo que un `fetch_object` sobre un resultado
     * de MariaDB queda atribuido al fichero de TPVFox que lo pidio. Es cierto, pero no es
     * codigo del producto y no se lista como tal.
     */
    private const NO_SON_DEL_PRODUCTO = ['mysqli', 'mysqli_result', 'mysqli_stmt', 'mysqli_driver'];

    /** Cuantas funciones se listan por fichero antes de resumir el resto en una linea. */
    private const TOPE_FUNCIONES = 40;

    /** @var array<string, array{casos:array<string,bool>, funciones:array<string,bool>}> */
    private array $ficheros = [];

    /** @var array<string, array{fichero:string, casos:array<string,bool>, tablas:array<string,string>}> */
    private array $funciones = [];

    /** @var array<string, array{casos:array<string,bool>, funciones:array<string,bool>, acceso:string}> */
    private array $tablas = [];

    /**
     * Suma lo que un caso hizo a las tres agrupaciones.
     *
     * @param array<string,mixed>|null $flujo
     */
    public function anotar(string $idCaso, ?array $flujo): void
    {
        // Del recorrido, no solo de las consultas. Una funcion que calcula, valida o compone
        // no emite SQL y hasta ahora no existia en esta vista: medido, la vista conocia 11
        // ficheros de los 37 que las pruebas recorren de verdad, y 122 funciones de 282.
        foreach ($flujo['camino'] ?? [] as $tramo) {
            if (($tramo['fase'] ?? '') !== 'ejercicio') {
                continue;
            }

            $fichero = (string) ($tramo['fichero'] ?? '');

            if ($fichero === '') {
                continue;
            }

            foreach ($tramo['funciones'] ?? [] as $funcion) {
                if (!self::esDelProducto((string) $funcion)) {
                    continue;
                }

                // El fichero del recorrido manda: ya viene resuelto a donde se declara la
                // clase, que es lo mismo que esta vista quiere saber.
                $this->funciones[$funcion]['fichero'] = $fichero;
                $this->funciones[$funcion]['casos'][$idCaso] = true;
            }
        }

        foreach ($flujo['pasos'] ?? [] as $paso) {
            if (($paso['fase'] ?? '') !== 'ejercicio') {
                continue;
            }

            // Ruta entera, no el nombre a secas: hay dos `funciones.php` en modulos distintos
            // y con el nombre solo se fundirian en una entrada que no existe.
            $fichero = self::relativa((string) ($paso['fichero'] ?? ''));
            $funcion = (string) (($paso['origen'] ?? '') ?: ($paso['funcion'] ?? ''));
            $sql = (string) ($paso['sql'] ?? '');

            if ($fichero === '' || $funcion === '' || in_array($funcion, self::NO_SON_FUNCIONES, true)) {
                continue;
            }

            $this->funciones[$funcion]['emisor'] = $fichero;
            $this->funciones[$funcion]['casos'][$idCaso] = true;
            $this->funciones[$funcion]['casosPorFichero'][$idCaso] = true;

            [$acceso, $tablas] = self::leerSql($sql);

            foreach ($tablas as $tabla) {
                $this->funciones[$funcion]['tablas'][$tabla] =
                    self::fundirAcceso($this->funciones[$funcion]['tablas'][$tabla] ?? '', $acceso);

                $this->tablas[$tabla]['casos'][$idCaso] = true;
                $this->tablas[$tabla]['funciones'][$funcion] = true;
                $this->tablas[$tabla]['acceso'] =
                    self::fundirAcceso($this->tablas[$tabla]['acceso'] ?? '', $acceso);
            }
        }
    }

    /**
     * Las tres agrupaciones, ordenadas por cuanto se ejercitan.
     *
     * @return array{ficheros:list<array<string,mixed>>, tablas:list<array<string,mixed>>}
     */
    public function resultado(): array
    {
        $clases = Declaraciones::clases();
        $funciones = Declaraciones::funciones();
        $porFichero = [];

        foreach ($this->funciones as $nombre => $suyo) {
            // El fichero de una funcion es donde esta declarada, no donde su consulta acabo
            // saliendo ni desde donde se la llamo: el producto canaliza las consultas por su
            // clase base, y agrupar por el emisor colocaria `PedidosVentas->AddPedidoGuardado`
            // bajo `ClaseVentas.php`. Lo que el recorrido supone solo vale cuando la
            // declaracion no aparece por ninguna parte, y entonces es lo unico que hay.
            $clase = self::claseDe($nombre);
            $declarada = $clase !== ''
                ? ($clases[$clase] ?? null)
                : ($funciones[strtolower($nombre)] ?? null);

            $ruta = $declarada !== null
                ? self::relativa($declarada)
                : ($suyo['fichero'] ?? $suyo['emisor'] ?? '');

            if ($ruta === '') {
                continue;
            }

            $porFichero[$ruta]['funciones'][] = [
                'nombre' => $nombre,
                'casos' => array_keys($suyo['casos']),
                'tablas' => $suyo['tablas'] ?? [],
            ];

            foreach (array_keys($suyo['casos']) as $idCaso) {
                $porFichero[$ruta]['casos'][$idCaso] = true;
            }
        }

        $ficheros = [];

        foreach ($porFichero as $ruta => $datos) {
            $funciones = $datos['funciones'];
            usort($funciones, static fn(array $a, array $b) => count($b['casos']) <=> count($a['casos']));

            [$modulo, $tipo] = self::ubicacion($ruta);
            $cuantas = count($funciones);

            $ficheros[] = [
                'ruta' => $ruta,
                'modulo' => $modulo,
                'tipo' => $tipo,
                'casos' => count($datos['casos']),
                'funciones' => array_slice($funciones, 0, self::TOPE_FUNCIONES),
                'masFunciones' => max(0, $cuantas - self::TOPE_FUNCIONES),
            ];
        }

        usort($ficheros, static fn(array $a, array $b) => $b['casos'] <=> $a['casos']);

        $tablas = [];

        foreach ($this->tablas as $nombre => $datos) {
            $tablas[] = [
                'nombre' => $nombre,
                'acceso' => $datos['acceso'],
                'casos' => array_keys($datos['casos']),
                'funciones' => array_keys($datos['funciones']),
            ];
        }

        usort($tablas, static fn(array $a, array $b) => count($b['casos']) <=> count($a['casos']));

        return ['ficheros' => $ficheros, 'tablas' => $tablas];
    }

    /** La ruta sin el prefijo del producto, que es la que identifica un fichero sin ambiguedad. */
    private static function relativa(string $ruta): string
    {
        $producto = defined('RUTA_TPVFOX') ? rtrim((string) constant('RUTA_TPVFOX'), '/') . '/' : '';

        return $producto !== '' && str_starts_with($ruta, $producto)
            ? substr($ruta, strlen($producto))
            : $ruta;
    }

    /**
     * De que modulo es un fichero y que papel cumple.
     *
     * Saber que `funciones.php` es de venta y no de reorganizacion, o que un fichero es el
     * despacho del modulo y no una clase, cambia como se lee todo lo demas. La convencion del
     * producto lo dice en la ruta.
     *
     * @return array{0:string, 1:string}
     */
    private static function ubicacion(string $ruta): array
    {
        if (preg_match('#^modulos/([a-z_]+)/(.+)$#', $ruta, $m) === 1) {
            $modulo = $m[1];
            $resto = $m[2];

            if (str_starts_with($resto, 'clases/')) {
                return [$modulo, 'clase'];
            }
            if (str_starts_with($resto, 'tareas/')) {
                return [$modulo, 'tarea'];
            }
            if ($resto === 'tareas.php') {
                return [$modulo, 'despacho'];
            }
            if ($resto === 'funciones.php') {
                return [$modulo, 'funciones'];
            }
            if (str_starts_with($resto, 'template/')) {
                return [$modulo, 'vista'];
            }

            return [$modulo, 'pantalla'];
        }

        if (str_starts_with($ruta, 'modulos/')) {
            return ['(común)', 'base'];
        }
        if (str_starts_with($ruta, 'clases/')) {
            return ['(compartido)', 'clase'];
        }

        // Empaquetada dentro de TPVFox pero escrita fuera: se lista, porque el recorrido pasa
        // de verdad por ella, y se dice lo que es para que no se lea como codigo propio.
        if (str_starts_with($ruta, 'lib/')) {
            return ['(librería)', 'libreria'];
        }

        if (str_starts_with($ruta, 'controllers/')) {
            return ['(común)', 'control'];
        }

        return ['(otro)', 'otro'];
    }

    /** Si un nombre de funcion del recorrido es codigo del producto y no del entorno. */
    private static function esDelProducto(string $funcion): bool
    {
        if ($funcion === '' || in_array($funcion, self::NO_SON_FUNCIONES, true)) {
            return false;
        }

        if (str_contains($funcion, '{closure')) {
            return false;
        }

        return !in_array(self::claseDe($funcion), self::NO_SON_DEL_PRODUCTO, true);
    }

    /** La clase de un nombre `Clase->metodo` o `Clase::metodo`. */
    private static function claseDe(string $funcion): string
    {
        $partes = preg_split('/::|->/', $funcion) ?: [];

        return count($partes) > 1 ? (string) $partes[0] : '';
    }

    /**
     * El acceso y las tablas de una sentencia.
     *
     * @return array{0:string, 1:list<string>}
     */
    private static function leerSql(string $sql): array
    {
        $verbo = strtoupper(substr(ltrim($sql), 0, 6));
        $acceso = str_starts_with($verbo, 'SELECT') ? 'R' : 'W';

        preg_match_all('/\b(?:FROM|INTO|UPDATE|JOIN)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $sql, $m);

        if (($m[1] ?? []) === []) {
            return [$acceso, []];
        }

        $tablas = [];

        foreach ($m[1] as $tabla) {
            $tabla = strtolower($tabla);
            if (!in_array($tabla, self::NO_SON_TABLAS, true) && !in_array($tabla, $tablas, true)) {
                $tablas[] = $tabla;
            }
        }

        return [$acceso, $tablas];
    }

    /** Una funcion que lee y escribe una tabla hace las dos cosas, no la ultima. */
    private static function fundirAcceso(string $tenia, string $nuevo): string
    {
        if ($tenia === '' || $tenia === $nuevo) {
            return $nuevo;
        }

        return 'RW';
    }
}

<?php

/**
 * Emite los datos del informe como JSON, en tres piezas que se piden por separado.
 *
 * El informe no cabe en un solo fichero. Hoy, sin flujo ni trazas, el HTML son 404 KB con 610
 * casos; anadiendo por caso el SQL que emitio, su cadena de llamadas y el fuente del producto
 * que recorre, se va a decenas de megas, y un navegador tendria que analizarlo entero antes de
 * pintar nada. Por eso se separa en:
 *
 * - **`indice.json`**, una fila por caso con lo justo para buscar y filtrar. Es lo unico que
 *   se carga al abrir, y de su tamano depende lo que tarda en aparecer la lista.
 * - **`casos/<id>.json`**, todo lo del caso: su flujo, su traza, su mensaje de fallo. Se pide
 *   al abrirlo.
 * - **`fuentes/<id>.json`**, el fichero de producto entero con las lineas que cada caso
 *   ejecuto. Se piden bajo demanda y el navegador los reutiliza, porque muchos casos
 *   comparten los mismos pocos ficheros.
 *
 * El identificador de un caso es el mismo que usa la instrumentacion, de modo que el flujo se
 * encuentra sin ningun mapa intermedio.
 */

declare(strict_types=1);

namespace TPVFox\Test\Informe;

use TPVFox\Test\Instrumentacion\Identidad;

final class Datos
{
    /**
     * Escribe las tres piezas bajo `<salida>/datos/`.
     *
     * @param array<string,array<string,mixed>> $casos
     * @param array<string,list<array{linea:int,texto:string}>> $citas
     * @return array{casos:int, fuentes:int, conFlujo:int}
     */
    public static function emitir(array $casos, array $citas, string $salida, ?string $dirFlujo = null): array
    {
        $base = rtrim($salida, '/') . '/datos';
        foreach (['', '/casos', '/fuentes'] as $sub) {
            @mkdir($base . $sub, 0777, true);
        }

        $indice = [];
        $ficheros = [];
        $conFlujo = 0;
        $codigo = new Codigo();

        foreach ($casos as $id => $caso) {
            $hash = Identidad::hash($id);
            $flujo = self::flujoDe($id, $hash, $dirFlujo);

            if ($flujo !== null && ($flujo['pasos'] !== [] || $flujo['llamadas'] !== [] || ($flujo['armazon'] ?? []) !== [])) {
                $conFlujo++;
            }

            $codigo->anotar($hash, $flujo);

            $etiquetas = self::etiquetasDe($caso);

            $indice[] = [
                'id' => $hash,
                'clase' => Vocabulario::nombreCorto($caso['clase']),
                'metodo' => $caso['metodo'],
                'frase' => self::fraseDe($caso),
                'estado' => $caso['estado'],
                'tiempo' => $caso['tiempo'],
                'etiquetas' => $etiquetas,
                'anotado' => ($caso['prosa'] ?? '') !== '' || ($caso['anotaciones'] ?? []) !== [],
                'declara' => $caso['estadoDeclarado'] ?? null,
                'avisos' => count($caso['avisos'] ?? []),
                'consultas' => $flujo['consultas'] ?? 0,
                'montaje' => $flujo['montaje'] ?? 0,
                'llamadas' => $flujo === null ? 0 : count($flujo['llamadas']),
                'avisosPhp' => $flujo === null ? 0 : count($flujo['avisos'] ?? []),
                'ficheros' => array_map(
                    static fn(string $f): string => basename($f),
                    array_keys($caso['cobertura'] ?? [])
                ),
            ];

            file_put_contents(
                $base . '/casos/' . $hash . '.json',
                self::json([
                    'id' => $hash,
                    'clase' => $caso['clase'],
                    'metodo' => $caso['metodo'],
                    'frase' => self::fraseDe($caso),
                    'estado' => $caso['estado'],
                    'tiempo' => $caso['tiempo'],
                    'etiquetas' => $etiquetas,
                    'prosa' => $caso['prosa'] ?? '',
                    'anotaciones' => $caso['anotaciones'] ?? [],
                    'mensaje' => $caso['mensaje'] ?? '',
                    'declarado' => $caso['declarado'] ?? [],
                    'estadoDeclarado' => $caso['estadoDeclarado'] ?? null,
                    'avisos' => $caso['avisos'] ?? [],
                    'cobertura' => self::coberturaDe($caso, $ficheros),
                    'medido' => $caso['medido'] ?? true,
                    'flujo' => $flujo,
                ])
            );
        }

        foreach ($ficheros as $ruta => $casosDeLaLinea) {
            $hash = substr(sha1($ruta), 0, 16);
            file_put_contents($base . '/fuentes/' . $hash . '.json', self::json([
                'ruta' => self::relativa($ruta),
                'lineas' => is_file($ruta) ? array_map(
                    static fn(string $l): string => rtrim($l, "\r\n"),
                    file($ruta) ?: []
                ) : [],
            ]));
        }

        // La vista por codigo se pide al abrir su pestana, no al arrancar.
        $porCodigo = $codigo->resultado();
        file_put_contents($base . '/codigo.json', self::json($porCodigo));

        file_put_contents($base . '/indice.json', self::json([
            'generado' => date('c'),
            'casos' => $indice,
            'citas' => $citas,
        ]));

        return [
            'casos' => count($indice),
            'fuentes' => count($ficheros),
            'conFlujo' => $conFlujo,
            'ficherosProducto' => count($porCodigo['ficheros']),
            'tablas' => count($porCodigo['tablas']),
        ];
    }

    /**
     * El flujo de un caso, si la instrumentacion dejo rastro.
     *
     * @return array<string,mixed>|null
     */
    private static function flujoDe(string $id, string $hash, ?string $dirFlujo): ?array
    {
        if ($dirFlujo === null) {
            return null;
        }

        $pasos = rtrim($dirFlujo, '/') . '/pasos/' . $hash . '.jsonl';
        $traza = rtrim($dirFlujo, '/') . '/traza/' . $hash . '.xt';

        if (!is_file($pasos) && !is_file($traza)) {
            return null;
        }

        return Flujo::componer($pasos, is_file($traza) ? $traza : null);
    }

    /**
     * Las etiquetas del caso: las que declare, y si no las que se deducen de su nombre.
     *
     * @param array<string,mixed> $caso
     * @return list<string>
     */
    private static function etiquetasDe(array $caso): array
    {
        $declaradas = $caso['etiquetas'] ?? [];

        $derivadas = Vocabulario::etiquetas(
            (string) $caso['clase'],
            (string) $caso['metodo'],
            (string) ($caso['fichero'] ?? '')
        );

        if (($caso['estadoDeclarado'] ?? null) === 'rojo') {
            $derivadas[] = 'esperado';
        }

        return array_values(array_unique(array_merge($declaradas, $derivadas)));
    }

    /**
     * La frase que encabeza el caso: la suya si la escribio, la deducida si no.
     *
     * @param array<string,mixed> $caso
     */
    private static function fraseDe(array $caso): string
    {
        // Los casos de JS ya llevan una frase por nombre; los de PHP, un nombre de metodo.
        if (!str_starts_with((string) $caso['metodo'], 'test')) {
            return (string) $caso['metodo'];
        }

        return Vocabulario::frase((string) $caso['metodo']);
    }

    /**
     * La cobertura del caso, y de paso apunta que ficheros hay que emitir.
     *
     * @param array<string,mixed> $caso
     * @param array<string,bool> $ficheros
     * @return array<string,array{id:string, lineas:list<int>}>
     */
    private static function coberturaDe(array $caso, array &$ficheros): array
    {
        $cobertura = [];

        foreach ($caso['cobertura'] ?? [] as $ruta => $lineas) {
            $ficheros[$ruta] = true;
            $cobertura[self::relativa($ruta)] = [
                'id' => substr(sha1($ruta), 0, 16),
                'lineas' => $lineas,
            ];
        }

        return $cobertura;
    }

    /** La ruta sin el prefijo del producto, que no aporta nada y alarga todo. */
    private static function relativa(string $ruta): string
    {
        $producto = defined('RUTA_TPVFOX') ? (string) constant('RUTA_TPVFOX') : '';

        return $producto !== '' ? ltrim(str_replace($producto, '', $ruta), '/') : $ruta;
    }

    /** @param array<string,mixed> $datos */
    private static function json(array $datos): string
    {
        return (string) json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }
}

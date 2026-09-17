<?php

/**
 * Una conexion que, ademas de consultar, anota lo que se le pide.
 *
 * Es el instrumento con el que el informe cuenta el flujo del dato, y funciona por una
 * particularidad del producto: TPVFox nunca abre su propia conexion cuando la suite le
 * entrega una, y consulta siempre con `query()` sobre ella. Sustituyendo esa conexion por
 * esta subclase se captura el SQL real, con el fichero y la linea que lo emitieron, sin
 * tocar una sola linea del producto y sin dependencias nuevas.
 *
 * La siembra, en cambio, escribe siempre con sentencias preparadas. Esa diferencia basta
 * para separar el «dado que» del «cuando» sin mirar el reloj ni adivinar.
 *
 * Cuando la bitacora no esta activa, cada llamada delega de inmediato: la instrumentacion no
 * puede encarecer la ejecucion normal de la suite.
 */

declare(strict_types=1);

namespace TPVFox\Test\Instrumentacion;

use mysqli;
use mysqli_result;
use mysqli_stmt;
use Throwable;

final class ConexionObservada extends mysqli
{
    /** Cuanto SQL se conserva por paso; lo que pase de aqui se recorta y se declara. */
    private const LIMITE_SQL = 4000;

    /**
     * Construcciones del lenguaje que aparecen en la pila como si fueran funciones.
     *
     * No son quien pidio el dato, son como el fichero entro en memoria. Medido: aceptarlas
     * dejaba las consultas que lanzan los constructores en la cabecera de un despacho
     * atribuidas a una funcion llamada `require`, en dieciseis casos.
     */
    private const CONSTRUCCIONES_DEL_LENGUAJE = ['require', 'require_once', 'include', 'include_once', 'eval'];

    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
    {
        if (!Bitacora::estaActiva() || Bitacora::casoAbierto() === null) {
            return parent::query($query, $result_mode);
        }

        $inicio = hrtime(true);
        $fallo = null;

        try {
            $resultado = parent::query($query, $result_mode);
        } catch (Throwable $e) {
            $fallo = $e->getMessage();
            $this->anotar('query', $query, $inicio, null, $fallo);

            throw $e;
        }

        $this->anotar('query', $query, $inicio, $resultado, null);

        return $resultado;
    }

    public function prepare(string $query): mysqli_stmt|false
    {
        if (!Bitacora::estaActiva() || Bitacora::casoAbierto() === null) {
            return parent::prepare($query);
        }

        $inicio = hrtime(true);

        try {
            $sentencia = parent::prepare($query);
        } catch (Throwable $e) {
            $this->anotar('prepare', $query, $inicio, null, $e->getMessage());

            throw $e;
        }

        $this->anotar('prepare', $query, $inicio, null, null);

        return $sentencia;
    }

    /**
     * Compone el paso y lo entrega a la bitacora.
     *
     * `affected_rows` e `insert_id` se leen de la propia conexion despues de consultar, que
     * es de donde el producto los lee tambien: asi el informe ensena el mismo dato con el
     * que el producto decidio.
     */
    private function anotar(
        string $tipo,
        string $sql,
        int $inicio,
        mysqli_result|bool|null $resultado,
        ?string $error
    ): void {
        $emisor = $this->emisor();

        $paso = [
            'tipo' => $tipo,
            'sql' => $this->recortar($sql),
            'recortado' => strlen($sql) > self::LIMITE_SQL,
            'fase' => $this->fase($emisor['fichero']),
            'fichero' => $emisor['fichero'],
            'linea' => $emisor['linea'],
            'funcion' => $emisor['funcion'],
            'origen' => $emisor['origen'],
            'ms' => round((hrtime(true) - $inicio) / 1_000_000, 3),
        ];

        if ($error !== null) {
            $paso['error'] = $error;
        } else {
            $paso['filasAfectadas'] = $this->affected_rows;
            $paso['idInsertado'] = $this->insert_id;
            if ($resultado instanceof mysqli_result) {
                $paso['filasDevueltas'] = $resultado->num_rows;
            }
        }

        Bitacora::anotar($paso);
    }

    /**
     * Los dos extremos de la llamada: donde se emitio y quien la pidio.
     *
     * Hacen falta los dos. `debug_backtrace()` pone en cada marco la funcion **llamada**, no
     * la que contiene la llamada, de modo que el fichero y la linea salen de un marco y el
     * nombre de la funcion del siguiente. Y el sitio de emision por si solo no cuenta gran
     * cosa: el producto canaliza sus consultas por unos pocos embudos —medido, 179 pasos de
     * un caso salian de solo tres lineas—, asi que lo que hace legible la historia es el
     * metodo de negocio que las pidio, el marco mas externo de la misma capa.
     *
     * @return array{fichero:string, linea:int, funcion:string, origen:string}
     */
    private function emisor(): array
    {
        $pila = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 24);
        $fichero = '';
        $linea = 0;
        $funcion = '';
        $origen = '';
        $capa = null;

        foreach ($pila as $indice => $marco) {
            $suyo = $marco['file'] ?? '';
            if ($suyo === '' || $suyo === __FILE__) {
                continue;
            }

            if ($fichero === '') {
                $fichero = $suyo;
                $linea = (int) ($marco['line'] ?? 0);
                $funcion = self::nombreDe($pila[$indice + 1] ?? []);
                $capa = $this->fase($suyo);
            }

            // El marco mas externo que siga en la misma capa es quien pidio el dato: el
            // metodo de negocio, no el embudo por el que su consulta acabo pasando.
            if ($this->fase($suyo) === $capa) {
                $nombre = self::nombreDe($pila[$indice + 1] ?? []);
                if ($nombre !== '' && !in_array($nombre, self::CONSTRUCCIONES_DEL_LENGUAJE, true)) {
                    $origen = $nombre;
                }
            }
        }

        return [
            'fichero' => $fichero,
            'linea' => $linea,
            'funcion' => $funcion,
            'origen' => $origen === $funcion ? '' : $origen,
        ];
    }

    /** El nombre `Clase::metodo` de un marco de la pila, o cadena vacia si no lo tiene. */
    private static function nombreDe(array $marco): string
    {
        if (($marco['function'] ?? '') === '') {
            return '';
        }

        return ($marco['class'] ?? '') !== ''
            ? $marco['class'] . ($marco['type'] ?? '::') . $marco['function']
            : (string) $marco['function'];
    }

    /**
     * En que momento del caso ocurre el paso, deducido de quien lo emite.
     *
     * No se mide por tiempo: un caso puede volver a sembrar a mitad, y el orden de reloj no
     * distinguiria eso de una escritura del producto.
     */
    private function fase(string $fichero): string
    {
        if ($fichero === '') {
            return 'desconocida';
        }

        $rutaProducto = defined('RUTA_TPVFOX') ? rtrim((string) constant('RUTA_TPVFOX'), '/') . '/' : null;

        if ($rutaProducto !== null && str_starts_with($fichero, $rutaProducto)) {
            return 'ejercicio';
        }

        if (str_contains($fichero, '/support/siembra/')) {
            return 'preparacion';
        }

        return 'comprobacion';
    }

    private function recortar(string $sql): string
    {
        $sql = trim(preg_replace('/\s+/', ' ', $sql) ?? $sql);

        return strlen($sql) > self::LIMITE_SQL ? substr($sql, 0, self::LIMITE_SQL) . ' …' : $sql;
    }
}

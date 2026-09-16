<?php

/**
 * Registro de los pasos que da un caso de prueba, para que el informe pueda contar el flujo
 * del dato.
 *
 * **Escribe en el acto, no acumula en memoria.** Un caso de la suite corre en un proceso
 * aparte, y ese proceso hijo muere con lo que tuviera sin volcar; escribiendo cada paso
 * cuando ocurre, ese caso tambien deja rastro. Por el mismo motivo la activacion se lee del
 * entorno y no de una llamada: el proceso hijo hereda las variables de entorno, pero no el
 * estado que el padre tuviera en memoria.
 *
 * El formato es una linea de JSON por paso (`.jsonl`): se puede anadir sin releer lo ya
 * escrito, y una linea corrupta por un final abrupto no invalida las anteriores.
 */

declare(strict_types=1);

namespace TPVFox\Test\Instrumentacion;

final class Bitacora
{
    /** La variable de entorno que lleva el directorio de salida; sin ella, no se registra nada. */
    public const VARIABLE = 'TPVFOX_BITACORA';

    private static ?string $directorio = null;
    private static bool $resuelta = false;
    private static ?string $caso = null;
    private static int $orden = 0;

    /**
     * El directorio de registro, o null si la bitacora no esta activa.
     *
     * Se resuelve una sola vez: la comprobacion ocurre en cada consulta del producto y no
     * puede costar una lectura de entorno cada vez.
     */
    public static function directorio(): ?string
    {
        if (!self::$resuelta) {
            $valor = getenv(self::VARIABLE);
            self::$directorio = is_string($valor) && $valor !== '' ? rtrim($valor, '/') : null;
            self::$resuelta = true;

            if (self::$directorio !== null && !is_dir(self::$directorio . '/pasos')) {
                @mkdir(self::$directorio . '/pasos', 0777, true);
            }
        }

        return self::$directorio;
    }

    public static function estaActiva(): bool
    {
        return self::directorio() !== null;
    }

    /**
     * Abre el registro de un caso. Los pasos que se anoten a partir de aqui son suyos.
     *
     * Reabrir un caso ya registrado **no** borra lo anterior: un caso que corre en proceso
     * aparte deja sus pasos desde el hijo, y el padre no debe pisarlos.
     */
    public static function empezarCaso(string $caso): void
    {
        self::$caso = Identidad::normalizar($caso);
        self::$orden = 0;
    }

    public static function terminarCaso(): void
    {
        self::$caso = null;
        self::$orden = 0;
    }

    /** El caso abierto, o null si ninguno lo esta. */
    public static function casoAbierto(): ?string
    {
        return self::$caso;
    }

    /**
     * Anota un paso del caso abierto.
     *
     * Sin bitacora activa o sin caso abierto no hace nada: la instrumentacion tiene que ser
     * inocua cuando el informe no se esta generando.
     *
     * @param array<string,mixed> $paso
     */
    public static function anotar(array $paso): void
    {
        $directorio = self::directorio();
        if ($directorio === null || self::$caso === null) {
            return;
        }

        $paso = ['orden' => ++self::$orden] + $paso;
        $linea = json_encode($paso, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if ($linea === false) {
            return;
        }

        @file_put_contents(self::rutaDe(self::$caso), $linea . "\n", FILE_APPEND | LOCK_EX);
    }

    /** El fichero de pasos de un caso, se haya escrito ya o no. */
    public static function rutaDe(string $caso): string
    {
        return (self::directorio() ?? sys_get_temp_dir()) . '/pasos/' . Identidad::hash($caso) . '.jsonl';
    }

    /**
     * Devuelve el estado a como estaba, para las pruebas de la propia instrumentacion.
     */
    public static function olvidar(): void
    {
        self::$directorio = null;
        self::$resuelta = false;
        self::$caso = null;
        self::$orden = 0;
    }
}

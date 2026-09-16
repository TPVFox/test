<?php

/**
 * El identificador estable de un caso de prueba, compartido por la instrumentacion y por el
 * generador del informe.
 *
 * PHPUnit nombra cada caso `Espacio\Clase::metodo`, y le anade ` with data set …` cuando el
 * caso viene de un proveedor de datos. La instrumentacion escribe un fichero por caso y el
 * generador lo busca despues por ese mismo nombre: si cada uno normalizara por su cuenta,
 * bastaria una diferencia de criterio para que el informe perdiera el flujo de un caso sin
 * decir por que. Por eso la normalizacion vive aqui y no duplicada en los dos sitios.
 */

declare(strict_types=1);

namespace TPVFox\Test\Instrumentacion;

final class Identidad
{
    /**
     * El nombre del caso sin el sufijo del juego de datos.
     *
     * Los casos de un proveedor de datos comparten codigo y docblock, de modo que se
     * agrupan bajo un solo identificador: el informe los presenta juntos.
     */
    public static function normalizar(string $caso): string
    {
        return preg_replace('/ with data set .*$/', '', $caso) ?? $caso;
    }

    /**
     * Nombre de fichero corto y estable para ese caso.
     *
     * Los nombres de caso llevan barras invertidas del espacio de nombres y llegan a los 120
     * caracteres, asi que no sirven como nombre de fichero. Dieciseis cifras hexadecimales
     * dan margen de sobra para los ~600 casos de la suite.
     */
    public static function hash(string $caso): string
    {
        return substr(sha1(self::normalizar($caso)), 0, 16);
    }
}

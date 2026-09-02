<?php

/**
 * `fechaVencimiento()` calcula la fecha de pago de una factura a partir de los dias de la
 * forma de pago del cliente, y tambien se usa (`factura.php:356`, `$dias = 0`) para
 * renormalizar una fecha ya guardada al formato `Y-m-d`.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModVenta;

use PHPUnit\Framework\TestCase;
use TPVFox\Test\CargaAislada;

final class FechaVencimientoTest extends TestCase
{
    private static function cargar(): void
    {
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
    }

    /**
     * `funciones.php:231-232` hace `$fecha = date($fecha)`: usa la propia fecha de entrada
     * como FORMATO de `date()`, no como marca de tiempo — casi con toda seguridad una
     * confusion con `date('Y-m-d', strtotime($fecha))`. Con una fecha `Y-m-d` (solo digitos
     * y guiones, que es todo lo que puede llegar de una columna `date`/`datetime` de la
     * base) ningun caracter es un especificador de formato reconocido, asi que `date()`
     * los devuelve literales y la fecha sobrevive intacta por coincidencia, no por diseno.
     * No es un test en rojo: con los datos que la base puede producir hoy, el resultado es
     * correcto. Queda anotado como fragil para el FMEA — un valor con alguna letra
     * (p.ej. un formato distinto de `Y-m-d`) rompería el calculo en silencio.
     */
    public function test_T1_sumaLosDiasALaFecha(): void
    {
        self::cargar();

        self::assertSame('2026-02-14', \fechaVencimiento('2026-01-15', 30));
    }

    public function test_T2_conDiasCeroDevuelveLaMismaFechaRenormalizada(): void
    {
        self::cargar();

        self::assertSame('2026-01-15', \fechaVencimiento('2026-01-15', 0));
    }

    public function test_T3_conFechaNoPositivaDevuelveHoy(): void
    {
        self::cargar();

        self::assertSame(date('Y-m-d'), \fechaVencimiento(0, 30));
    }
}

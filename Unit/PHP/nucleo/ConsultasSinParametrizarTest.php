<?php

/**
 * Las consultas que todavía se hacen con `->query(` directo, con el SQL montado a mano, en `modulos/`,
 * `clases/`, `controllers/` y `plugins/`. El código nuevo usa la capa de datos `clases/DB.php`, que
 * lleva los valores aparte: el número de las que quedan no puede crecer. Cuando un módulo pasa a la
 * capa y el número baja, se baja también aquí, para que no vuelva a subir.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\Nucleo;

use PHPUnit\Framework\TestCase;

final class ConsultasSinParametrizarTest extends TestCase
{
    /** Las que quedan con la rama de seguridad integrada: 74 en `mod_informes` y 3 en `mod_reorganizacion`. */
    private const MAXIMO = 77;

    /** @comportamiento Las consultas montadas a mano no aumentan. */
    public function test_lasConsultasMontadasAManoNoAumentan(): void
    {
        $total = 0;
        foreach (['modulos', 'clases', 'controllers', 'plugins'] as $carpeta) {
            $ficheros = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(RUTA_TPVFOX . '/' . $carpeta, \FilesystemIterator::SKIP_DOTS));
            foreach ($ficheros as $fichero) {
                $esDeLaCapa = str_ends_with($fichero->getPathname(), '/clases/DB.php');
                if ($fichero->getExtension() === 'php' && !$esDeLaCapa) {
                    $total += substr_count((string) file_get_contents($fichero->getPathname()), '->query(');
                }
            }
        }

        self::assertLessThanOrEqual(self::MAXIMO, $total, "Hay $total consultas montadas a mano; el máximo es " . self::MAXIMO . '.');
    }
}

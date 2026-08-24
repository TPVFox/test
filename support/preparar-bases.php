<?php
/**
 * Prepara las dos bases de ejercicios consecutivos sobre las que corre la integracion.
 *
 * No crea las bases: crearlas exige privilegios de administracion del motor y este guion
 * no los pide. Espera que existan vacias y concedidas, y las llena con el esquema de
 * referencia de TPVFox.
 *
 * Uso:  php support/preparar-bases.php [--rehacer]
 *
 * Sin --rehacer, una base que ya tenga tablas se deja como esta.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;

const ESQUEMA = '/BD/BDtpv/tpvfox_V_0_4-2.84.sql';

$rehacer = in_array('--rehacer', $argv, true);
$ficheroEsquema = RUTA_TPVFOX . ESQUEMA;

if (!is_file($ficheroEsquema)) {
    fwrite(STDERR, "No se encuentra el esquema de referencia en $ficheroEsquema\n");
    exit(1);
}

$sql = file_get_contents($ficheroEsquema);

foreach (['vigente' => 'TPVFOX_TEST_DB_VIGENTE', 'anterior' => 'TPVFOX_TEST_DB_ANTERIOR'] as $papel => $variable) {
    $base = Entorno::valor($variable);

    if ($base === '') {
        fwrite(STDERR, "Falta $variable en test/.env o en el entorno.\n");
        exit(1);
    }
    if (strpos($base, 'tpvfox_test') !== 0) {
        fwrite(STDERR, "La base «{$base}» no empieza por «tpvfox_test»: no se toca.\n");
        exit(1);
    }

    $db = @new mysqli(
        Entorno::valor('TPVFOX_TEST_DB_HOST', 'localhost'),
        Entorno::valor('TPVFOX_TEST_DB_USER'),
        Entorno::valor('TPVFOX_TEST_DB_PASS'),
        $base
    );

    if ($db->connect_errno) {
        fwrite(STDERR, "No se pudo conectar con «{$base}»: error {$db->connect_errno}.\n");
        fwrite(STDERR, "Crea la base y concedela antes de ejecutar este guion (README, «Preparar las bases»).\n");
        exit(1);
    }

    $db->set_charset('utf8mb4');

    $tablas = (int) $db->query("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE()")
        ->fetch_assoc()['c'];

    if ($tablas > 0 && !$rehacer) {
        echo "$papel ($base): ya tiene $tablas objetos. Se deja como esta — usa --rehacer para rehacerla.\n";
        $db->close();
        continue;
    }

    if ($tablas > 0) {
        vaciar($db);
    }

    cargar($db, $sql, $base);

    $resumen = $db->query(
        "SELECT SUM(table_type = 'BASE TABLE') t, SUM(table_type = 'VIEW') v
           FROM information_schema.tables WHERE table_schema = DATABASE()"
    )->fetch_assoc();

    echo "$papel ($base): {$resumen['t']} tablas y {$resumen['v']} vistas.\n";
    $db->close();
}

/** Elimina todos los objetos de la base, sin mirar el orden de las claves foraneas. */
function vaciar(mysqli $db): void
{
    $db->query('SET FOREIGN_KEY_CHECKS = 0');

    $r = $db->query(
        "SELECT table_name n, table_type t FROM information_schema.tables WHERE table_schema = DATABASE()"
    );
    while ($f = $r->fetch_assoc()) {
        $orden = $f['t'] === 'VIEW' ? 'DROP VIEW' : 'DROP TABLE';
        $db->query("$orden IF EXISTS `{$f['n']}`");
    }

    $db->query('SET FOREIGN_KEY_CHECKS = 1');
}

/** Carga el volcado de esquema completo. */
function cargar(mysqli $db, string $sql, string $base): void
{
    if (!$db->multi_query($sql)) {
        fwrite(STDERR, "Fallo al cargar el esquema en «{$base}»: {$db->error}\n");
        exit(1);
    }

    do {
        if ($resultado = $db->store_result()) {
            $resultado->free();
        }
        if ($db->errno) {
            fwrite(STDERR, "Fallo al cargar el esquema en «{$base}»: {$db->error}\n");
            exit(1);
        }
    } while ($db->more_results() && $db->next_result());
}

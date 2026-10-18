<?php
/**
 * Siembra persistente para los recorridos E2E del listado de productos relacionados.
 *
 * Deja las tres tablas de relaciones en un estado conocido. Los articulos llevan el prefijo
 * `[E2E relaciones]` y se reutilizan entre pasadas; las relaciones se borran y se vuelven a
 * sembrar cada vez, porque los casos de integracion del modulo vacian esas tablas.
 *
 * Uso: php support/sembrar-e2e-relaciones.php [estado] [vigente|anterior] [conPermiso|sinPermiso]
 *
 *   completo        tres tipos y cuarenta y cinco relaciones, en dos paginas (por defecto)
 *   sinRelaciones   los tres tipos y ninguna relacion
 *   sinTipos        las tres tablas vacias, como llegan tras instalar
 *   sinEstructura   las tres tablas retiradas de la base
 *
 * El tercer argumento deja al usuario de los recorridos (TPVFOX_E2E_USUARIO) con el permiso
 * de cambiar el estado de las relaciones desde el listado concedido (por defecto) o denegado.
 * Es lo que haria «Reorganizar permisos»: el permiso es de una pantalla que ya existia, y a
 * quien ya la tenia no le llega solo al entrar.
 *
 * Los tres ultimos estados dejan el listado sin datos, y los recorridos que los necesitan
 * los piden sobre la base del ejercicio anterior: asi no se los quitan a los demas
 * recorridos, que corren a la vez contra la del vigente.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\EscenarioRelaciones;
use TPVFox\Test\Siembra\Siembra;

const PREFIJO = '[E2E relaciones] ';

$estado = $argv[1] ?? 'completo';
if (!in_array($estado, ['completo', 'sinRelaciones', 'sinTipos', 'sinEstructura'], true)) {
    fwrite(STDERR, "Estado desconocido: {$estado}\n");
    exit(1);
}

$permiso = $argv[3] ?? 'conPermiso';
if (!in_array($permiso, ['conPermiso', 'sinPermiso'], true)) {
    fwrite(STDERR, "Permiso desconocido: {$permiso}\n");
    exit(1);
}

$variable = ($argv[2] ?? 'vigente') === 'anterior' ? 'TPVFOX_TEST_DB_ANTERIOR' : 'TPVFOX_TEST_DB_VIGENTE';
$base = Entorno::valor($variable);
if ($base === '' || strpos($base, 'tpvfox_test') !== 0) {
    fwrite(STDERR, "Falta $variable o no empieza por «tpvfox_test»: no se toca.\n");
    exit(1);
}

$db = new mysqli(
    Entorno::valor('TPVFOX_TEST_DB_HOST', 'localhost'),
    Entorno::valor('TPVFOX_TEST_DB_USER'),
    Entorno::valor('TPVFOX_TEST_DB_PASS'),
    $base
);
if ($db->connect_errno) {
    fwrite(STDERR, "No se pudo conectar con «{$base}»: error {$db->connect_errno}.\n");
    exit(1);
}
$db->set_charset('utf8mb4');

if ($estado === 'sinEstructura') {
    foreach (['relacionesLineas', 'relaciones', 'relacionesTipos'] as $tabla) {
        $db->query("DROP TABLE IF EXISTS $tabla");
    }
    echo "Tablas de relaciones retiradas.\n";
    exit(0);
}

// Las tablas se crean si faltan: el fichero de instalacion no toca las que ya existen.
$db->multi_query((string) file_get_contents(RUTA_TPVFOX . '/BD/Update/install_update_v0.4.4.54.sql'));
while ($db->more_results()) {
    $db->next_result();
}

$escenario = new EscenarioRelaciones($db);
$escenario->limpiar();
if ($estado === 'sinTipos') {
    echo "Tablas de relaciones vacias.\n";
    exit(0);
}

$despiece = $escenario->tipoDeRelacion('Despiece', '1:N');
$elaboracion = $escenario->tipoDeRelacion('Elaboración', 'N:1');
$cruce = $escenario->tipoDeRelacion('Cruce', '1:1', 0);
if ($estado === 'sinRelaciones') {
    echo "Tres tipos de relacion y ninguna relacion.\n";
    exit(0);
}

$siembra = new Siembra($db);
$articulo = function (string $nombre) use ($db, $siembra): int {
    $sentencia = $db->prepare('SELECT idArticulo FROM articulos WHERE articulo_name = ? LIMIT 1');
    $completo = PREFIJO . $nombre;
    $sentencia->bind_param('s', $completo);
    $sentencia->execute();
    $fila = $sentencia->get_result()->fetch_row();

    return $fila === null ? $siembra->articulo($completo) : (int) $fila[0];
};
$linea = fn(string $nombre, string $sentido, ?float $cantidad = 1, ?string $unidad = 'kg'): array
    => ['idArticulo' => $articulo($nombre), 'sentido' => $sentido, 'cantidad' => $cantidad, 'unidad' => $unidad];

// Un despiece con nombre, una elaboracion sin el y un cruce.
$escenario->relacionVigente($despiece, [$linea('Pollo', 'entrada'), $linea('Pechuga', 'salida', 0.23), $linea('Alas', 'salida', 0.12)], ['nombre' => 'Pollo entero', 'fecha' => '2026-03-01 09:00:00']);
$escenario->relacionVigente($elaboracion, [$linea('Cerdo picado', 'entrada', 0.4), $linea('Vacuno picado', 'entrada', 0.6), $linea('Hamburguesa', 'salida')], ['fecha' => '2026-01-10 09:00:00']);
$escenario->relacionVigente($cruce, [$linea('Naranja de mesa', 'entrada', null, null), $linea('Naranja de zumo', 'salida', null, null)], ['fecha' => '2026-02-01 09:00:00']);

// Una relacion dada de baja y otra que solo tiene un borrador: sus productos no se listan.
$retirada = $escenario->relacionVigente($despiece, [$linea('Conejo', 'entrada'), $linea('Paletilla de conejo', 'salida')], ['fecha' => '2026-01-05 09:00:00']);
$db->query("UPDATE relaciones SET estado = 'Baja' WHERE id = {$retirada['id']}");
$numeroLibre = (int) $db->query('SELECT MAX(numRelacion) + 1 AS n FROM relaciones')->fetch_assoc()['n'];
$escenario->versionDeRelacion($numeroLibre, $despiece, '0.0', 'Borrador', '2026-03-10 09:00:00', [$linea('Pavo', 'entrada'), $linea('Muslo de pavo', 'salida')]);

// Cuarenta y dos despieces mas, para que el listado tenga dos paginas.
for ($n = 1; $n <= 42; $n++) {
    $numero = str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    $escenario->relacionVigente(
        $despiece,
        [$linea("Pieza $numero", 'entrada'), $linea("Corte $numero A", 'salida', 0.5), $linea("Corte $numero B", 'salida', 0.5)],
        ['nombre' => "Lote $numero", 'fecha' => date('Y-m-d', strtotime("2026-04-01 +$n days")) . ' 09:00:00']
    );
}

$filas = (int) $db->query("SELECT COUNT(*) AS n FROM relaciones WHERE estado = 'Activo'")->fetch_assoc()['n'];
echo "Relaciones sembradas: {$filas} que rigen.\n";

// El permiso de cambiar el estado desde el listado, para el usuario de los recorridos.
$login = Entorno::valor('TPVFOX_E2E_USUARIO');
$buscar = $db->prepare('SELECT id FROM usuarios WHERE username = ? LIMIT 1');
$buscar->bind_param('s', $login);
$buscar->execute();
$usuario = $buscar->get_result()->fetch_row();
if ($usuario === null) {
    echo "Sin usuario de recorridos «{$login}»: el permiso no se toca.\n";
    exit(0);
}
$idUsuario = (int) $usuario[0];
$concedido = $permiso === 'conPermiso' ? 1 : 0;
$db->query("DELETE FROM permisos WHERE idUsuario = $idUsuario AND modulo = 'mod_relaciones' AND vista = 'ListaRelaciones.php' AND accion = 'CambiarEstado'");
$db->query("INSERT INTO permisos (idUsuario, modulo, vista, accion, permiso) VALUES ($idUsuario, 'mod_relaciones', 'ListaRelaciones.php', 'CambiarEstado', $concedido)");
echo $concedido === 1 ? "Permiso de cambiar el estado concedido.\n" : "Permiso de cambiar el estado denegado.\n";

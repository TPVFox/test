<?php
/**
 * Siembra persistente de los escenarios que cruzan de un ejercicio a otro.
 *
 * La mayor parte de los escenarios se aplican dentro de la transaccion de un caso de
 * prueba, que la deshace al terminar. Unos pocos no pueden: los que describen a un mismo
 * producto en las dos bases de ejercicios consecutivos, y los que atraviesan los
 * recorridos de navegador, que corren contra un despliegue real y no ven ninguna
 * transaccion abierta por la suite.
 *
 * Esos son los que aplica este guion, y los aplica **con identificador fijo**: el
 * emparejamiento entre ejercicios es por identificador de producto, y dos bases con
 * auto-incremento propio no coinciden por si solas. Es lo que hace que las dos siembras
 * queden sincronizadas sin coordinarlas a mano.
 *
 * Uso:  php support/sembrar-escenarios.php [--rehacer]
 *
 * Sin --rehacer, una base que ya lleve la siembra se deja como esta: los identificadores
 * son fijos, de modo que sembrar dos veces chocaria contra la clave del primer producto.
 *
 * El proveedor con que se identifica el traspaso no se elige aqui: se lee de la
 * configuracion que gobierna la instalacion, por el mismo camino que la lee el propio
 * modulo. Ponerlo a mano sembraria el traspaso con un proveedor que el sistema no busca.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once RUTA_TPVFOX . '/controllers/parametros.php';

use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\EscenarioComprobacionStock;
use TPVFox\Test\Siembra\Siembra;

const RUTA_PARAMETROS_MODULO = '/modulos/mod_reorganizacion/parametros.xml';
const RUTA_PROVEEDOR_CIERRE = 'configuracion/cierre_stock_anual/ajustes_globales/proveedor';

/** Lo que se siembra en cada base, por papel. */
const REPARTO = [
    'vigente'  => ['E53', 'E54', 'E55'],
    'anterior' => ['E55'],
];

$rehacer = in_array('--rehacer', $argv, true);
$idProveedorCierre = proveedorDeCierreDeLaConfiguracion();

echo "Proveedor de cierre segun la configuracion que gobierna: {$idProveedorCierre}\n";

foreach (REPARTO as $papel => $escenarios) {
    $base = nombreDeLaBase($papel);
    $ano = ejercicioDe($base);
    $db = conectar($base);

    $siembra = new Siembra($db);
    $catalogo = new EscenarioComprobacionStock($siembra, $papel, $ano, true);

    if (yaSembrada($db) && !$rehacer) {
        echo "$papel ($base, ejercicio $ano): ya sembrada. Se deja como esta.\n";
        echo "  Para partir de cero: npm run bases:preparar -- --rehacer\n";
        $db->close();
        continue;
    }

    $siembra->proveedorConId($idProveedorCierre, 'Proveedor de cierre de stock anual');

    $sembrados = [];
    foreach ($escenarios as $escenario) {
        $resultado = $catalogo->aplicar($escenario, $idProveedorCierre);
        $sembrados[$escenario] = $resultado['idArticulo'] ?? '(sin producto)';
    }

    echo "$papel ($base, ejercicio $ano): " . count($escenarios) . " escenarios.\n";
    foreach ($sembrados as $escenario => $idArticulo) {
        echo "  $escenario -> articulo $idArticulo\n";
    }

    $db->close();
}

echo "\nLos identificadores de producto coinciden en las dos bases: es lo que hace\n";
echo "que el emparejamiento entre ejercicios signifique algo.\n";

// --- Apoyos ---------------------------------------------------------------

/**
 * El proveedor de cierre, leido como lo lee el modulo.
 *
 * `ClaseParametros` antepone al fichero del repositorio una copia en `cache/` del propio
 * modulo, que no se versiona y que es la que gobierna. Leer el del repositorio daria un
 * proveedor distinto del que el sistema busca, y el traspaso sembrado no lo veria nadie.
 */
function proveedorDeCierreDeLaConfiguracion(): int
{
    $parametros = new ClaseParametros(RUTA_TPVFOX . RUTA_PARAMETROS_MODULO);
    $nodo = $parametros->getNode(RUTA_PROVEEDOR_CIERRE);

    if ($nodo === null) {
        fwrite(STDERR, "La configuracion no declara proveedor de cierre; sin el no hay traspaso que sembrar.\n");
        exit(1);
    }

    $atributos = $nodo->attributes();
    if (!isset($atributos['id'])) {
        fwrite(STDERR, "El proveedor de cierre no declara identificador.\n");
        exit(1);
    }

    return (int) $atributos['id'];
}

function nombreDeLaBase(string $papel): string
{
    $variable = $papel === 'anterior' ? 'TPVFOX_TEST_DB_ANTERIOR' : 'TPVFOX_TEST_DB_VIGENTE';
    $base = Entorno::valor($variable);

    if ($base === '') {
        fwrite(STDERR, "Falta $variable en test/.env o en el entorno.\n");
        exit(1);
    }
    if (strpos($base, 'tpvfox_test') !== 0) {
        fwrite(STDERR, "La base «{$base}» no empieza por «tpvfox_test»: no se toca.\n");
        exit(1);
    }

    return $base;
}

function ejercicioDe(string $base): string
{
    if (!preg_match('/(\d{4})$/', $base, $coincidencia)) {
        fwrite(STDERR, "La base «{$base}» no termina en un ejercicio de cuatro cifras.\n");
        exit(1);
    }

    return $coincidencia[1];
}

function conectar(string $base): mysqli
{
    $db = @new mysqli(
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

    return $db;
}

/**
 * Si la base ya lleva esta siembra.
 *
 * Se pregunta por el producto del puente, que es el unico que existe en las dos bases con
 * el mismo identificador: si esta, la siembra se hizo. Volver a sembrar chocaria contra su
 * clave, y el error del motor no diria que el problema es haberlo hecho dos veces.
 */
function yaSembrada(mysqli $db): bool
{
    $sentencia = $db->prepare('SELECT COUNT(*) FROM articulos WHERE idArticulo BETWEEN 9001 AND 9199');
    $sentencia->execute();

    return (int) $sentencia->get_result()->fetch_row()[0] > 0;
}

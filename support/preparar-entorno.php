<?php
/**
 * Deja la maquina lista para ejecutar la suite entera, de forma repetible.
 *
 * Recoge en un solo guion lo que hasta ahora habia que rehacer a mano cada vez que las
 * bases se rehacian: el esquema, la tienda por la que selecciona el cierre, el usuario con
 * que entran los recorridos de navegador, su fila de indice, el fichero de configuracion
 * del propio TPVFox y la siembra de los escenarios que cruzan.
 *
 * Uso:  php support/preparar-entorno.php [--rehacer] [--ejercicio=vigente|anterior]
 *
 *   --rehacer     tira los objetos de las dos bases y vuelve a cargar el esquema
 *   --ejercicio   a que base apunta el TPVFox desplegado; los recorridos de navegador
 *                 corren contra un despliegue cada vez, y entre los dos se cambia esto
 *
 * **Lo que este guion no toca, a proposito.** La copia de `cache/parametros.xml` que
 * `ClaseParametros` antepone al fichero del repositorio, y que es la que gobierna el
 * calculo. No se escribe ni se borra: la cualificacion del entorno registra lo que haya,
 * y un guion que la fijara estaria cualificando una configuracion que la instalacion real
 * no tiene por que compartir.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;

const PREFIJO_ADMITIDO = 'tpvfox_test';

/** La tienda por la que el cierre del ejercicio selecciona los productos. */
const TIENDA_DEL_CIERRE = 1;

$rehacer = in_array('--rehacer', $argv, true);
$ejercicio = ejercicioPedido($argv);

paso('Esquema de las dos bases');
lanzar(__DIR__ . '/preparar-bases.php', $rehacer ? ['--rehacer'] : []);

$usuario = Entorno::valor('TPVFOX_E2E_USUARIO');
$clave = Entorno::valor('TPVFOX_E2E_CLAVE');

if ($usuario === '' || $clave === '') {
    fwrite(STDERR,
        "Faltan TPVFOX_E2E_USUARIO y TPVFOX_E2E_CLAVE en test/.env o en el entorno.\n" .
        "Son las credenciales con que entran los recorridos de navegador, y las mismas que\n" .
        "este guion da de alta en las dos bases.\n");
    exit(1);
}

paso('Tienda, usuario y su indice, en las dos bases');
foreach (['vigente', 'anterior'] as $papel) {
    $base = nombreDeLaBase($papel);
    $db = conectar($base);

    $idTienda = tiendaPrincipal($db, ejercicioDe($base));
    $idUsuario = usuarioDeRecorrido($db, $usuario, $clave);
    indiceDelUsuario($db, $idTienda, $idUsuario);

    echo "  $papel ($base): tienda $idTienda, usuario $idUsuario con su indice.\n";
    $db->close();
}

paso('Configuracion del TPVFox desplegado');
escribirConfiguracion($ejercicio);

paso('Escenarios que cruzan de un ejercicio a otro');
lanzar(__DIR__ . '/sembrar-escenarios.php', []);

paso('Listo');
echo "  El TPVFox de " . RUTA_TPVFOX . " apunta al ejercicio «{$ejercicio}».\n";
echo "  Para el otro recorrido de navegador: php support/preparar-entorno.php --ejercicio=" .
    ($ejercicio === 'vigente' ? 'anterior' : 'vigente') . "\n";
echo "  La configuracion del modulo (cache/parametros.xml) no se ha tocado: es la que gobierna\n";
echo "  el calculo y la cualificacion del entorno registra la que haya.\n";

// --- Piezas ---------------------------------------------------------------

/**
 * La tienda por la que selecciona el cierre, activa y principal.
 *
 * Con identificador fijo porque el propio producto pregunta por el numero, y solo una:
 * la aplicacion toma la primera principal activa que encuentra, de modo que dos serian
 * una ambiguedad que decide el orden de la tabla.
 */
function tiendaPrincipal(mysqli $db, string $ano): int
{
    $existente = unValor($db, 'SELECT idTienda FROM tiendas WHERE tipoTienda = ? AND estado = ? LIMIT 1', ['principal', 'Activo']);
    if ($existente !== null) {
        return $existente;
    }

    ejecutar($db,
        'INSERT INTO tiendas (idTienda, tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [TIENDA_DEL_CIERRE, 'principal', 'Tienda de pruebas', 'X0000000X', '000000000', 'Activo',
         'Tienda de pruebas', 'Sin direccion', $ano]
    );

    return TIENDA_DEL_CIERRE;
}

/**
 * El usuario con que entran los recorridos de navegador.
 *
 * Tres cosas que el codigo de acceso exige y que no se pueden suponer: la contrasena se
 * compara contra su resumen MD5, el estado se busca en minuscula —al reves que el de las
 * tiendas— y el grupo 9 resuelve todos los permisos a la vez, de modo que no hay que dar
 * de alta ninguno fila a fila.
 */
function usuarioDeRecorrido(mysqli $db, string $usuario, string $clave): int
{
    $existente = unValor($db, 'SELECT id FROM usuarios WHERE username = ? LIMIT 1', [$usuario]);

    if ($existente !== null) {
        ejecutar($db, 'UPDATE usuarios SET password = ?, group_id = 9, estado = ? WHERE id = ?',
            [md5($clave), 'activo', $existente]);

        return $existente;
    }

    return ejecutar($db,
        'INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES (?, ?, ?, ?, ?, ?)',
        [$usuario, md5($clave), date('Y-m-d'), 9, 'activo', 'Usuario de recorridos']
    );
}

/**
 * La fila de indice del usuario, que ha de ser exactamente una.
 *
 * El acceso no comprueba que exista sino que haya una sola: con ninguna o con dos, la
 * sesion no llega a establecerse y el recorrido falla en el formulario sin decir por que.
 */
function indiceDelUsuario(mysqli $db, int $idTienda, int $idUsuario): void
{
    ejecutar($db, 'DELETE FROM indices WHERE idUsuario = ?', [$idUsuario]);
    ejecutar($db,
        'INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES (?, ?, 0, 0)',
        [$idTienda, $idUsuario]
    );
}

/**
 * Apunta el TPVFox desplegado a la base del ejercicio indicado.
 *
 * **Cambia una linea y nada mas.** La configuracion de un despliegue declara mucho mas que
 * la base de datos —las rutas del servidor con las que arranca la aplicacion, el directorio
 * temporal por el que pasan el fichero que se exporta y el que se sube, el correo—, y este
 * guion no las conoce todas. Reescribir el fichero entero con lo que si conoce deja una
 * instalacion que arranca a medias: la aplicacion se queda sin las rutas y ninguna pantalla
 * llega a componerse.
 *
 * De modo que aqui solo se sustituye el nombre de la base. Si no hay fichero, este guion no
 * lo inventa: lo dice y remite al ejemplo que el propio producto trae.
 */
function escribirConfiguracion(string $ejercicio): void
{
    $ruta = RUTA_TPVFOX . '/configuracion.php';
    $base = nombreDeLaBase($ejercicio);

    if (!is_file($ruta)) {
        fwrite(STDERR,
            "  No hay configuracion de despliegue en el clon de TPVFox.\n" .
            "  Este guion no la redacta: declara rutas del servidor que solo conoce quien instalo.\n" .
            "  Copia configuracion_sample.php, rellenala, y vuelve a lanzar esto.\n");
        exit(1);
    }

    $actual = file_get_contents($ruta);

    if (!preg_match('/\$nombrebdMysql\s*=\s*[\'"]([^\'"]*)[\'"]\s*;/', $actual, $coincidencia)) {
        fwrite(STDERR, "  {$ruta} no declara \$nombrebdMysql: no se toca.\n");
        exit(1);
    }

    if ($coincidencia[1] !== '' && strpos($coincidencia[1], PREFIJO_ADMITIDO) !== 0) {
        fwrite(STDERR,
            "  {$ruta} apunta a «{$coincidencia[1]}», que no es una base de pruebas.\n" .
            "  No se toca: podria ser la configuracion de una instalacion real.\n");
        exit(1);
    }

    $nuevo = str_replace($coincidencia[0], "\$nombrebdMysql = '{$base}';", $actual);

    if (file_put_contents($ruta, $nuevo) === false) {
        fwrite(STDERR, "  No se pudo escribir {$ruta}.\n");
        exit(1);
    }

    echo "  ejercicio del despliegue: {$ejercicio} · el resto de la configuracion, intacta\n";
}

// --- Apoyos ---------------------------------------------------------------

function ejercicioPedido(array $argumentos): string
{
    foreach ($argumentos as $argumento) {
        if (strpos($argumento, '--ejercicio=') === 0) {
            $valor = substr($argumento, strlen('--ejercicio='));
            if ($valor !== 'vigente' && $valor !== 'anterior') {
                fwrite(STDERR, "--ejercicio admite «vigente» o «anterior», no «{$valor}».\n");
                exit(1);
            }

            return $valor;
        }
    }

    return 'vigente';
}

function nombreDeLaBase(string $papel): string
{
    $variable = $papel === 'anterior' ? 'TPVFOX_TEST_DB_ANTERIOR' : 'TPVFOX_TEST_DB_VIGENTE';
    $base = Entorno::valor($variable);

    if ($base === '') {
        fwrite(STDERR, "Falta $variable en test/.env o en el entorno.\n");
        exit(1);
    }
    if (strpos($base, PREFIJO_ADMITIDO) !== 0) {
        fwrite(STDERR, "La base «{$base}» no empieza por «" . PREFIJO_ADMITIDO . "»: no se toca.\n");
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

/** @param array<int,scalar> $valores @return int Identificador insertado, si lo hubo */
function ejecutar(mysqli $db, string $sql, array $valores): int
{
    $sentencia = $db->prepare($sql);
    if ($sentencia === false) {
        fwrite(STDERR, "No se pudo preparar «{$sql}»: {$db->error}\n");
        exit(1);
    }

    if ($valores !== []) {
        $sentencia->bind_param(str_repeat('s', count($valores)), ...$valores);
    }
    if (!$sentencia->execute()) {
        fwrite(STDERR, "Fallo al ejecutar «{$sql}»: {$sentencia->error}\n");
        exit(1);
    }

    return (int) $db->insert_id;
}

/** @param array<int,scalar> $valores */
function unValor(mysqli $db, string $sql, array $valores): ?int
{
    $sentencia = $db->prepare($sql);
    $sentencia->bind_param(str_repeat('s', count($valores)), ...$valores);
    $sentencia->execute();
    $fila = $sentencia->get_result()->fetch_row();

    return $fila === null ? null : (int) $fila[0];
}

/** @param string[] $argumentos */
function lanzar(string $guion, array $argumentos): void
{
    $orden = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($guion);
    foreach ($argumentos as $argumento) {
        $orden .= ' ' . escapeshellarg($argumento);
    }

    passthru($orden, $salida);
    if ($salida !== 0) {
        fwrite(STDERR, "  Fallo en " . basename($guion) . ".\n");
        exit($salida);
    }
}

function paso(string $titulo): void
{
    echo "\n== {$titulo}\n";
}

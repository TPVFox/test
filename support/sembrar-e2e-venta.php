<?php
/**
 * Siembra persistente para los recorridos E2E de mod_venta.
 *
 * A diferencia de la siembra que usan los casos de Integration/PHP —dentro de una
 * transaccion que se deshace al terminar—, un recorrido de navegador corre contra el
 * despliegue real de `localhost:8080/TPVFox` y no ve ninguna transaccion abierta por la
 * suite: lo que este guion inserta queda en la base para siempre, hasta que alguien lo
 * borre a mano.
 *
 * Los articulos y el cliente llevan el prefijo `[E2E venta]` en el nombre, precisamente
 * para que se distingan a simple vista de cualquier dato real y de los fixtures de otros
 * modulos (los tres articulos de la comprobacion de stock entre ejercicios ya presentes en
 * esta base no sirven aqui: sus nombres son de esa comprobacion, no de venta).
 *
 * Uso: php support/sembrar-e2e-venta.php
 *
 * Los articulos y los clientes se siembran con **identificador fijo**, no por
 * auto-incremento: los recorridos referencian el cliente y el articulo por su id (no hay
 * conexion a la base desde Playwright para resolverlo por nombre), de modo que un id que
 * cambiara al rehacer la base dejaria los specs apuntando a datos que ya no existen. Con id
 * fijo la siembra es reproducible: tras `preparar-entorno.php --rehacer` vuelve a caer en
 * los mismos numeros. Es idempotente por identificador —si ya existe, no se reinserta—, el
 * mismo criterio que `Siembra::proveedorConId` y `sembrar-escenarios.php` aplican a los
 * datos persistentes que cruzan a los recorridos de navegador.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use TPVFox\Test\Entorno;
use TPVFox\Test\Siembra\Siembra;

const PREFIJO = '[E2E venta] ';

$base = Entorno::valor('TPVFOX_TEST_DB_VIGENTE');
if ($base === '' || strpos($base, 'tpvfox_test') !== 0) {
    fwrite(STDERR, "Falta TPVFOX_TEST_DB_VIGENTE o no empieza por «tpvfox_test»: no se toca.\n");
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

$siembra = new Siembra($db);

$articulos = [
    ['id' => 14678, 'nombre' => PREFIJO . 'Manzana Golden', 'coste' => 1.0, 'beneficio' => 50],
    ['id' => 14679, 'nombre' => PREFIJO . 'Manzana Reineta', 'coste' => 1.2, 'beneficio' => 50],
];

$idsArticulo = [];
foreach ($articulos as $datos) {
    $existente = existente($db, 'articulos', 'articulo_name', $datos['nombre']);
    if ($existente !== null) {
        echo "Articulo ya sembrado: {$datos['nombre']} (id {$existente})\n";
        $idsArticulo[] = $existente;
        continue;
    }

    $id = $siembra->articulo($datos['nombre'], ['id' => $datos['id'], 'ultimoCoste' => $datos['coste'], 'beneficio' => $datos['beneficio'], 'iva' => 21]);
    $precioCiva = round($datos['coste'] * (1 + $datos['beneficio'] / 100) * 1.21, 2);
    $precioSiva = round($datos['coste'] * (1 + $datos['beneficio'] / 100), 2);
    // El precio se escribe en la tienda 1, no en la principal del entorno: la busqueda de
    // producto de la capa compartida fija `idTienda = 1` en la consulta
    // (funciones.php:49-50), de modo que un precio en otra tienda no lo encontraria. La tienda
    // 1 no tiene por que existir en `tiendas` —la consulta no la une—, basta la fila de precio.
    $siembra->precioYTienda($id, $precioCiva, $precioSiva, 1);
    echo "Articulo sembrado: {$datos['nombre']} (id {$id}), pvpCiva={$precioCiva} en tienda 1\n";
    $idsArticulo[] = $id;
}

// Un cliente por spec, no uno compartido: pedido.php guarda en el servidor un temporal
// "actual" ligado al cliente seleccionado, y Playwright corre los ficheros de spec en
// paralelo por defecto (sin workers:1 en playwright.config.js). Dos specs manipulando el
// mismo cliente a la vez corren la carrera de verse el temporal el uno al otro.
// Id fijo por cliente: es el que cada spec referencia en su constante ID_CLIENTE. El orden
// no basta para fijarlo —el auto-incremento cambia al rehacer la base—, asi que el numero
// es explicito y vive aqui, junto al nombre que lo identifica.
$nombresCliente = [
    'teclado'         => [921, PREFIJO . 'Cliente teclado'],
    'raton'           => [922, PREFIJO . 'Cliente raton'],
    'defecto'         => [923, PREFIJO . 'Cliente defecto comprobar adjuntos'],
    'navegacion'      => [924, PREFIJO . 'Cliente navegacion teclado'],
    'eliminar'        => [925, PREFIJO . 'Cliente eliminar raton'],
    'albaran'         => [926, PREFIJO . 'Cliente albaran teclado'],
    'albaran_adjunto' => [927, PREFIJO . 'Cliente albaran adjunto pedido'],
    'factura'         => [928, PREFIJO . 'Cliente factura teclado'],
    'factura_adjunto' => [929, PREFIJO . 'Cliente factura adjunto albaran'],
    'albaran_entradas' => [930, PREFIJO . 'Cliente albaran entradas'],
    'albaran_guardar'  => [931, PREFIJO . 'Cliente albaran guardar'],
    'albaran_listado'  => [932, PREFIJO . 'Cliente albaran listado'],
    'pedido_entradas'  => [933, PREFIJO . 'Cliente pedido entradas'],
    'pedido_listado'   => [934, PREFIJO . 'Cliente pedido listado'],
    'pedido_guardar'   => [935, PREFIJO . 'Cliente pedido guardar'],
    'pedido_estados'   => [936, PREFIJO . 'Cliente pedido estados'],
    'factura_entradas' => [937, PREFIJO . 'Cliente factura entradas'],
    'factura_listado'  => [938, PREFIJO . 'Cliente factura relacion'],
    'factura_guardar'  => [939, PREFIJO . 'Cliente factura guardar'],
    'factura_sinvenci' => [940, PREFIJO . 'Cliente factura sin vencimiento'],
    'factura_desdealb' => [941, PREFIJO . 'Cliente factura desde albaran'],
    'factura_duplicado' => [942, PREFIJO . 'Cliente factura duplicidad'],
    'borrador_producto'  => [943, PREFIJO . 'Borrador entra producto'],
    'borrador_albaran'   => [944, PREFIJO . 'Borrador entra albaran'],
    'borrador_linea'     => [945, PREFIJO . 'Borrador entra linea'],
    'borrador_fecha'     => [946, PREFIJO . 'Borrador entra fecha'],
    'borrador_guardar'   => [947, PREFIJO . 'Borrador sale guardar'],
    'borrador_cancelar'  => [948, PREFIJO . 'Borrador sale cancelar'],
    'borrador_abandona'  => [949, PREFIJO . 'Borrador sale abandona'],
    'borrador_fallo'     => [950, PREFIJO . 'Borrador sale fallo'],
    'borrador_fechamal'  => [951, PREFIJO . 'Borrador sale fecha'],
    'borrador_estado'    => [952, PREFIJO . 'Borrador sale estado'],
    'borrador_otro'      => [953, PREFIJO . 'Borrador otro documento'],
    'borrador_contraste' => [954, PREFIJO . 'Borrador contraste'],

    // Comportamiento esperado: un cliente por recorrido, con su propia limpieza mas abajo.
    // Los recorridos de esta tanda afirman el comportamiento correcto y hoy fallan, pero
    // llegan a componer documentos igual, de modo que necesitan quedar limpios cada pasada.
    'esperado_inyeccion'       => [955, PREFIJO . 'Esperado inyeccion en busqueda'],
    'esperado_sinlineas_ped'   => [956, PREFIJO . 'Esperado sin lineas pedido'],
    'esperado_sinlineas_alb'   => [957, PREFIJO . 'Esperado sin lineas albaran'],
    'esperado_sinlineas_fac'   => [958, PREFIJO . 'Esperado sin lineas factura'],
    'esperado_huerfano'        => [959, PREFIJO . 'Esperado borrador huerfano'],
    'esperado_albfacturado'    => [960, PREFIJO . 'Esperado albaran dos facturas'],
    'esperado_existencias'     => [961, PREFIJO . 'Esperado suelo existencias'],
    'esperado_estadocruzado'   => [962, PREFIJO . 'Esperado estado cruzado'],
    // Guardado atomico: clientes propios, no los de «sin lineas». Los dos recorridos corren en
    // paralelo y, compartiendo cliente, uno encontraba el borrador del otro y se quedaba parado.
    'esperado_atomico_ped'     => [963, PREFIJO . 'Esperado guardado atomico pedido'],
    'esperado_atomico_alb'     => [964, PREFIJO . 'Esperado guardado atomico albaran'],
    'esperado_atomico_fac'     => [965, PREFIJO . 'Esperado guardado atomico factura'],
    // Numeracion: el recorrido emite una factura en cada pasada, de modo que necesita su
    // propio cliente y su propio albaran, no los del recorrido de guardado.
    'esperado_numeracion'      => [966, PREFIJO . 'Esperado numeracion factura'],
    // Impreso: una factura con dos albaranes, uno con lineas y otro sin ninguna.
    'esperado_impreso'         => [967, PREFIJO . 'Esperado impreso cabeceras'],
    // Adjuntar un documento cuya linea lleva una cantidad o un precio de mil o mas. Un cliente
    // por recorrido: cada uno consume su propio documento de origen al adjuntarlo.
    'esperado_adjunto_mil'     => [968, PREFIJO . 'Esperado adjunto mil unidades'],
    'esperado_adjunto_recarga' => [969, PREFIJO . 'Esperado adjunto se recarga'],
    'esperado_adjunto_precio'  => [970, PREFIJO . 'Esperado adjunto precio mil'],
    'esperado_adjunto_control' => [971, PREFIJO . 'Esperado adjunto control 999'],
    'esperado_adjunto_factura' => [972, PREFIJO . 'Esperado adjunto albaran mil'],
    // Retomar un documento en curso con una linea de mil unidades. No parten de nada sembrado:
    // cada recorrido compone su albaran, y se limpia con los demas.
    'esperado_retomar_caja'    => [973, PREFIJO . 'Esperado retomar caja de mil'],
    'esperado_retomar_guardar' => [974, PREFIJO . 'Esperado retomar y guardar mil'],
    // Volver a guardar un documento que creo otro usuario, en otra fecha.
    'traza_pedido'             => [975, PREFIJO . 'Traza de autoria pedido'],
    'traza_albaran'            => [976, PREFIJO . 'Traza de autoria albaran'],
    'traza_factura'            => [977, PREFIJO . 'Traza de autoria factura'],
    // Lo que el servidor hace con una peticion que llega sin sesion, o con la sesion de un
    // usuario sin permiso. Un documento por recorrido: cada uno lo deja cambiado o borrado.
    'sesion_albaran'           => [978, PREFIJO . 'Guardar albaran sin sesion'],
    'sesion_pedido'            => [979, PREFIJO . 'Guardar pedido sin sesion'],
    'sesion_estado'            => [980, PREFIJO . 'Cambiar estado sin sesion'],
    'permiso_estado'           => [981, PREFIJO . 'Cambiar estado sin permiso'],
    // Abrir un documento guardado para editarlo, cambiarlo y cancelar.
    'cancelar_albaran'         => [982, PREFIJO . 'Cancelar edicion albaran'],
    'cancelar_pedido'          => [983, PREFIJO . 'Cancelar edicion pedido'],
    // Volver a guardar un documento con una linea que no se puede escribir, y guardar un albaran
    // cuyo movimiento de existencias falla. El 986 lo ocupa el cliente de las pruebas de integracion.
    'esperado_reguardado_alb'  => [984, PREFIJO . 'Esperado reguardado atomico albaran'],
    'esperado_reguardado_ped'  => [985, PREFIJO . 'Esperado reguardado atomico pedido'],
    'esperado_stock_falla'     => [987, PREFIJO . 'Esperado albaran con stock que falla'],
];

$idsCliente = [];
foreach ($nombresCliente as $clave => [$idFijo, $nombreCliente]) {
    $idCliente = existente($db, 'clientes', 'Nombre', $nombreCliente);
    // Si el nombre cambio entre versiones de la siembra, el identificador fijo ya existe: se
    // renombra en vez de insertar, que chocaria con la clave primaria.
    if ($idCliente === null && $db->query("SELECT 1 FROM clientes WHERE idClientes = {$idFijo}")->num_rows > 0) {
        $renombrar = $db->prepare('UPDATE clientes SET Nombre = ? WHERE idClientes = ?');
        $renombrar->bind_param('si', $nombreCliente, $idFijo);
        $renombrar->execute();
        $idCliente = $idFijo;
        echo "Cliente renombrado: {$nombreCliente} (id {$idCliente})\n";
    }
    if ($idCliente === null) {
        $idCliente = insertarCliente($db, $idFijo, $nombreCliente);
        echo "Cliente sembrado: {$nombreCliente} (id {$idCliente})\n";
    } else {
        echo "Cliente ya sembrado: {$nombreCliente} (id {$idCliente})\n";
    }
    $idsCliente[$clave] = $idCliente;
}

// --- Lo que dejan tras de si los recorridos de guardado ---------------------------------
//
// Los tres recorridos que componen y guardan un documento desde la pantalla dejan uno nuevo
// en cada pasada, y nada lo retiraba. El listado de albaranes llego asi a pasar de las 40
// filas de su primera pagina, y los recorridos que buscan ahi su documento dejaron de
// encontrarlo. Se retira lo acumulado antes de cada pasada: cada recorrido vuelve a componer
// el suyo, y el albaran del que parte el de factura se siembra de nuevo mas abajo.
borrarDocumentosDeCliente($db, (int) $idsCliente['albaran_guardar']);
borrarPedidosDeCliente($db, (int) $idsCliente['pedido_guardar']);
borrarDocumentosDeCliente($db, (int) $idsCliente['factura_guardar']);
echo 'Documentos de los recorridos de guardado retirados (clientes '
    . "{$idsCliente['albaran_guardar']}, {$idsCliente['pedido_guardar']}, {$idsCliente['factura_guardar']})\n";

// --- Los pedidos que el recorrido de entradas deja en cada pasada ------------------------
//
// Ese recorrido deja un pedido nuevo sin guardar cada vez, y nada lo retiraba. El listado de
// pedidos enseña una pagina: al pasar de sus filas, los dos pedidos sembrados de este cliente
// —los mas antiguos— dejaron de aparecer y los recorridos que los buscan ahi dejaron de
// encontrarlos. Se conservan sus dos primeros pedidos, que son los sembrados.
$idClienteEntradasPed = (int) $idsCliente['pedido_entradas'];
$sobrantes = [];
$r = $db->query("SELECT id FROM pedclit WHERE idCliente = {$idClienteEntradasPed} ORDER BY id LIMIT 2, 100000");
while ($fila = $r->fetch_assoc()) {
    $sobrantes[] = (int) $fila['id'];
}
if ($sobrantes !== []) {
    $lista = implode(',', $sobrantes);
    $db->query("DELETE FROM pedcliltemporales WHERE Numpedcli IN ({$lista})");
    $db->query("DELETE FROM pedcliAlb WHERE idPedido IN ({$lista})");
    $db->query("DELETE FROM pedclilinea WHERE idpedcli IN ({$lista})");
    $db->query("DELETE FROM pedcliIva WHERE idpedcli IN ({$lista})");
    $db->query("DELETE FROM pedclit WHERE id IN ({$lista})");
    echo 'Pedidos acumulados del recorrido de entradas retirados: ' . count($sobrantes) . "\n";
}

// --- Lo mismo para los recorridos de comportamiento esperado -----------------------------
//
// Son los que afirman el comportamiento correcto y hoy fallan. Fallar no les impide dejar
// rastro: componen su documento, crean su borrador y algunos intentan un guardado que la
// base rechaza a medias. Sin esta limpieza la segunda pasada parte de lo que dejo la
// primera y el recorrido deja de medir lo que dice medir.
$clientesEsperado = [
    'esperado_inyeccion',
    'esperado_sinlineas_ped',
    'esperado_sinlineas_alb',
    'esperado_sinlineas_fac',
    'esperado_huerfano',
    'esperado_albfacturado',
    'esperado_numeracion',
    'esperado_existencias',
    'esperado_estadocruzado',
    'esperado_atomico_ped',
    'esperado_atomico_alb',
    'esperado_atomico_fac',
    'esperado_adjunto_mil',
    'esperado_adjunto_recarga',
    'esperado_adjunto_precio',
    'esperado_adjunto_control',
    'esperado_adjunto_factura',
    'esperado_retomar_caja',
    'esperado_retomar_guardar',
    'traza_pedido',
    'traza_albaran',
    'traza_factura',
    'sesion_albaran',
    'sesion_pedido',
    'sesion_estado',
    'permiso_estado',
    'cancelar_albaran',
    'cancelar_pedido',
    'esperado_reguardado_alb',
    'esperado_reguardado_ped',
    'esperado_stock_falla',
];
foreach ($clientesEsperado as $clave) {
    borrarDocumentosDeCliente($db, (int) $idsCliente[$clave]);
    borrarPedidosDeCliente($db, (int) $idsCliente[$clave]);
}
// Guardar sin sesion borra el albaran y deja vivo su borrador, que `borrarDocumentosDeCliente`
// no alcanza: ese borra los de factura, no los de albaran.
foreach (['sesion_albaran', 'sesion_estado', 'permiso_estado', 'cancelar_albaran', 'esperado_reguardado_alb', 'esperado_stock_falla'] as $clave) {
    $db->query('DELETE FROM albcliltemporales WHERE idCliente = ' . (int) $idsCliente[$clave]);
}
echo 'Documentos de los recorridos de comportamiento esperado retirados (clientes 955-965)' . "\n";

// Un pedido 'Guardado' para el cliente que prueba adjuntar pedido -> albaran
// (BuscarAdjunto.php busca por Numpedcli, idCliente y estado="Guardado"). Idempotente por
// idCliente: si ya tiene un pedido Guardado, no siembra otro.
$filaPedido = $db->query(
    'SELECT Numpedcli FROM pedclit WHERE idCliente = ' . (int) $idsCliente['albaran_adjunto']
    . ' AND estado = "Guardado" LIMIT 1'
)->fetch_assoc();

if ($filaPedido !== null) {
    $numPedidoGuardado = (int) $filaPedido['Numpedcli'];
    echo "Pedido Guardado ya sembrado (cliente {$idsCliente['albaran_adjunto']}): Numpedcli={$numPedidoGuardado}\n";
} else {
    $idPedidoGuardado = $siembra->pedidoVentaCliente($idsArticulo[0], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idsCliente['albaran_adjunto'],
    ]);
    $numPedidoGuardado = (int) $db->query('SELECT Numpedcli FROM pedclit WHERE id = ' . (int) $idPedidoGuardado)
        ->fetch_assoc()['Numpedcli'];
    echo "Pedido Guardado sembrado (cliente {$idsCliente['albaran_adjunto']}): Numpedcli={$numPedidoGuardado}\n";
}

// Mismo patron, un albaran 'Guardado' para adjuntar albaran -> factura
// (AlbaranClienteGuardado() busca por Numalbcli, idCliente y estado="Guardado").
$filaAlbaran = $db->query(
    'SELECT Numalbcli FROM albclit WHERE idCliente = ' . (int) $idsCliente['factura_adjunto']
    . ' AND estado = "Guardado" LIMIT 1'
)->fetch_assoc();

if ($filaAlbaran !== null) {
    $numAlbaranGuardado = (int) $filaAlbaran['Numalbcli'];
    echo "Albaran Guardado ya sembrado (cliente {$idsCliente['factura_adjunto']}): Numalbcli={$numAlbaranGuardado}\n";
} else {
    $idAlbaranGuardado = $siembra->ventaAlbaranCliente($idsArticulo[0], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idsCliente['factura_adjunto'],
    ]);
    $numAlbaranGuardado = (int) $db->query('SELECT Numalbcli FROM albclit WHERE id = ' . (int) $idAlbaranGuardado)
        ->fetch_assoc()['Numalbcli'];
    echo "Albaran Guardado sembrado (cliente {$idsCliente['factura_adjunto']}): Numalbcli={$numAlbaranGuardado}\n";
}

// Los recorridos del componente 2 llegan al albaran desde el listado, no por su id en la
// URL: `albclit` no admite id fijo en la siembra y el auto-incremento cambia al rehacer la
// base, de modo que un id escrito en el spec dejaria de valer. Basta con que cada uno de
// esos clientes tenga un albaran 'Guardado' con el que aparecer en el listado.
foreach (['albaran_entradas', 'albaran_listado'] as $clave) {
    $idCliente = (int) $idsCliente[$clave];
    $fila = $db->query(
        'SELECT Numalbcli FROM albclit WHERE idCliente = ' . $idCliente . ' AND estado = "Guardado" LIMIT 1'
    )->fetch_assoc();

    if ($fila !== null) {
        echo "Albaran Guardado ya sembrado (cliente {$idCliente}): Numalbcli={$fila['Numalbcli']}\n";
        continue;
    }

    $idAlbaran = $siembra->ventaAlbaranCliente($idsArticulo[0], 2.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idCliente,
    ]);
    $numero = (int) $db->query('SELECT Numalbcli FROM albclit WHERE id = ' . (int) $idAlbaran)
        ->fetch_assoc()['Numalbcli'];
    echo "Albaran Guardado sembrado (cliente {$idCliente}): id={$idAlbaran}, Numalbcli={$numero}\n";
}

// El recorrido del listado necesita ademas un albaran en un estado distinto, o el filtro
// por estado no se puede verificar: filtrar por el unico estado que existe no distingue un
// filtro que funciona de uno que se ignora. Se llega a 'Procesado' facturandolo, que es el
// unico camino por el que el producto lo deja asi.
$idClienteListado = (int) $idsCliente['albaran_listado'];
$filaProcesado = $db->query(
    'SELECT Numalbcli FROM albclit WHERE idCliente = ' . $idClienteListado . ' AND estado = "Procesado" LIMIT 1'
)->fetch_assoc();

if ($filaProcesado !== null) {
    echo "Albaran Procesado ya sembrado (cliente {$idClienteListado}): Numalbcli={$filaProcesado['Numalbcli']}\n";
} else {
    $idAlbaranAFacturar = $siembra->ventaAlbaranCliente($idsArticulo[1], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteListado,
    ]);
    $siembra->facturarAlbaranCliente($idAlbaranAFacturar);
    echo "Albaran Procesado sembrado (cliente {$idClienteListado}): id={$idAlbaranAFacturar}\n";
}

// Los recorridos del componente 3 llegan al pedido desde su listado, con el mismo criterio
// que los del componente 2: cada uno de esos clientes necesita un pedido 'Guardado' con el
// que aparecer.
foreach (['pedido_entradas', 'pedido_listado'] as $clave) {
    $idCliente = (int) $idsCliente[$clave];
    $fila = $db->query(
        'SELECT Numpedcli FROM pedclit WHERE idCliente = ' . $idCliente . ' AND estado = "Guardado" LIMIT 1'
    )->fetch_assoc();

    if ($fila !== null) {
        echo "Pedido Guardado ya sembrado (cliente {$idCliente}): Numpedcli={$fila['Numpedcli']}\n";
        continue;
    }

    $idPedido = $siembra->pedidoVentaCliente($idsArticulo[0], 2.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idCliente,
    ]);
    $numero = (int) $db->query('SELECT Numpedcli FROM pedclit WHERE id = ' . (int) $idPedido)
        ->fetch_assoc()['Numpedcli'];
    echo "Pedido Guardado sembrado (cliente {$idCliente}): id={$idPedido}, Numpedcli={$numero}\n";
}

// Y el listado necesita ademas un pedido en otro estado, o filtrar por el unico estado que
// existe no distingue un filtro que funciona de uno que se ignora. Se llega a 'Procesado'
// sirviendolo con un albaran, que es el camino por el que el producto lo deja asi.
// Se siembra para los dos clientes y no solo para el del listado: el recorrido de entradas
// necesita tambien un pedido Procesado para comprobar que la pantalla rechaza editarlo, y
// compartir el documento entre dos ficheros de spec los pondria a competir por el.
foreach (['pedido_entradas', 'pedido_listado'] as $clave) {
    $idClientePedidos = (int) $idsCliente[$clave];
    $filaPedidoProcesado = $db->query(
        'SELECT Numpedcli FROM pedclit WHERE idCliente = ' . $idClientePedidos . ' AND estado = "Procesado" LIMIT 1'
    )->fetch_assoc();

    if ($filaPedidoProcesado !== null) {
        echo "Pedido Procesado ya sembrado (cliente {$idClientePedidos}): Numpedcli={$filaPedidoProcesado['Numpedcli']}\n";
        continue;
    }

    $idPedidoServido = $siembra->pedidoVentaCliente($idsArticulo[1], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Procesado',
        'idCliente' => $idClientePedidos,
    ]);
    $idAlbaranQueSirve = $siembra->ventaAlbaranCliente($idsArticulo[1], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClientePedidos,
    ]);
    $siembra->adjuntarPedidoAAlbaran($idAlbaranQueSirve, $idPedidoServido);
    echo "Pedido Procesado sembrado (cliente {$idClientePedidos}): id={$idPedidoServido}\n";
}

// El recorrido de estados del componente 3 necesita cuatro pedidos en situaciones que la
// base admite y el codigo no impide. Dos de ellos hacen falta con el numero desplazado
// respecto del identificador: es la condicion sin la cual el marcado del pedido servido
// acierta por casualidad y el recorrido no demuestra nada.
$idClienteEstados = (int) $idsCliente['pedido_estados'];
$yaSembrado = (int) $db->query('SELECT COUNT(*) as n FROM pedclit WHERE idCliente = ' . $idClienteEstados)
    ->fetch_assoc()['n'];

if ($yaSembrado > 0) {
    // A diferencia del resto de la siembra, aqui no basta con no repetir: el recorrido de
    // T1 cambia el estado de dos de estos pedidos, y sin restituirlo la segunda ejecucion
    // partiria de un escenario que ya no es el que el caso necesita. Se devuelven a
    // 'Guardado' los dos del par —el que se sirve y el que queda marcado en su lugar—,
    // identificados por la propia relacion que los define: uno lleva como numero el
    // identificador del otro.
    $par = $db->query(
        'SELECT a.id AS servido, b.id AS ajeno
           FROM pedclit a JOIN pedclit b ON a.Numpedcli = b.id AND a.id <> b.id
          WHERE a.idCliente = ' . $idClienteEstados . ' AND b.idCliente = ' . $idClienteEstados
    )->fetch_assoc();

    if ($par !== null) {
        $db->query("UPDATE pedclit SET estado='Guardado' WHERE id IN ({$par['servido']}, {$par['ajeno']})");
        echo "Pedidos de estados restituidos (cliente {$idClienteEstados}): "
            . "servido id={$par['servido']}, marcado id={$par['ajeno']}, los dos a Guardado\n";
    } else {
        echo "Pedidos de estados ya sembrados (cliente {$idClienteEstados}): {$yaSembrado}, sin par que restituir\n";
    }
} else {
    // El que se va a servir, y el que quedara marcado en su lugar.
    $elQueSeSirve = $siembra->pedidoVentaCliente($idsArticulo[0], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteEstados,
    ]);
    $elQueSeMarcara = $siembra->pedidoVentaCliente($idsArticulo[1], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteEstados,
    ]);
    // El numero del primero pasa a ser el identificador del segundo: al adjuntar el primero,
    // el navegador enviara ese numero y el servidor lo aplicara al segundo.
    $db->query("UPDATE pedclit SET Numpedcli={$elQueSeMarcara} WHERE id={$elQueSeSirve}");

    // Procesado sin relacion: la pantalla lo detecta y avisa.
    $huerfano = $siembra->pedidoVentaCliente($idsArticulo[0], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Procesado',
        'idCliente' => $idClienteEstados,
    ]);

    // Guardado con relacion: la pantalla avisa en el sentido contrario.
    $enlazado = $siembra->pedidoVentaCliente($idsArticulo[1], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteEstados,
    ]);
    $albaranDelEnlazado = $siembra->ventaAlbaranCliente($idsArticulo[1], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteEstados,
    ]);
    $siembra->adjuntarPedidoAAlbaran($albaranDelEnlazado, $enlazado);

    echo "Pedidos de estados sembrados (cliente {$idClienteEstados}):"
        . " se sirve id={$elQueSeSirve} con numero {$elQueSeMarcara},"
        . " se marcara id={$elQueSeMarcara}, huerfano id={$huerfano}, enlazado id={$enlazado}\n";
}

// --- Componente 4: factura de cliente ----------------------------------------

// La vista de factura pide a la ficha del cliente su tipo de vencimiento y consulta con el
// la tabla de tipos. Sin tipos no hay desplegable, asi que se siembra el catalogo minimo.
$hayTiposVencimiento = (int) $db->query('SELECT COUNT(*) as n FROM tiposVencimiento')->fetch_assoc()['n'];
if ($hayTiposVencimiento === 0) {
    foreach ([['Contado', 0], ['30 dias', 30], ['60 dias', 60]] as [$descripcion, $dias]) {
        $sentencia = $db->prepare('INSERT INTO tiposVencimiento (descripcion, dias) VALUES (?, ?)');
        $sentencia->bind_param('si', $descripcion, $dias);
        $sentencia->execute();
    }
    echo "Tipos de vencimiento sembrados: Contado, 30 dias, 60 dias\n";
} else {
    echo "Tipos de vencimiento ya sembrados: {$hayTiposVencimiento}\n";
}

// Los tres clientes cuyos recorridos abren facturas guardadas necesitan su forma de
// vencimiento puesta: sin ella la pantalla no llega a montarse. El cuarto se deja sin ella
// a proposito, que es lo que el recorrido de ese defecto observa.
$primerTipo = (int) $db->query('SELECT id FROM tiposVencimiento ORDER BY id LIMIT 1')->fetch_assoc()['id'];
foreach (['factura_entradas', 'factura_listado', 'factura_guardar', 'factura_adjunto', 'factura'] as $clave) {
    $db->query(
        'UPDATE clientes SET formasVenci = \'{"vencimiento":"' . $primerTipo . '"}\''
        . ' WHERE idClientes = ' . (int) $idsCliente[$clave] . ' AND formasVenci IS NULL'
    );
}
echo "Forma de vencimiento asignada a los clientes de factura (tipo {$primerTipo})\n";

// El recorrido de entradas necesita dos facturas guardadas: una limpia, con la que
// comprobar que 'editar' abre el formulario, y otra con un borrador colgando, con la que
// comprobar que la pantalla lo detecta y degrada la accion a 'ver'.
$idClienteEntradas = (int) $idsCliente['factura_entradas'];
$yaHayFacturas = (int) $db->query('SELECT COUNT(*) as n FROM facclit WHERE idCliente = ' . $idClienteEntradas)
    ->fetch_assoc()['n'];

if ($yaHayFacturas > 0) {
    echo "Facturas de entradas ya sembradas (cliente {$idClienteEntradas}): {$yaHayFacturas}\n";
} else {
    $albaranLimpio = $siembra->ventaAlbaranCliente($idsArticulo[0], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteEntradas,
    ]);
    $facturaLimpia = $siembra->facturarAlbaranCliente($albaranLimpio);

    $albaranConBorrador = $siembra->ventaAlbaranCliente($idsArticulo[1], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteEntradas,
    ]);
    $facturaConBorrador = $siembra->facturarAlbaranCliente($albaranConBorrador);

    // El borrador se ata a la factura por su identificador, que es lo que la pantalla
    // envia y lo que la clase escribe en la columna del numero.
    $siembra->facturaTemporal(
        [['idArticulo' => $idsArticulo[1], 'estadoLinea' => 'Activo']],
        [],
        ['idCliente' => $idClienteEntradas, 'Numfaccli' => $facturaConBorrador]
    );

    echo "Facturas de entradas sembradas (cliente {$idClienteEntradas}):"
        . " limpia id={$facturaLimpia}, con borrador id={$facturaConBorrador}\n";
}

// El listado necesita una factura con la que aparecer y otra en distinto estado, o filtrar
// por el unico estado que existe no distingue un filtro que funciona de uno que se ignora.
$idClienteListadoFac = (int) $idsCliente['factura_listado'];
$yaHayListado = (int) $db->query('SELECT COUNT(*) as n FROM facclit WHERE idCliente = ' . $idClienteListadoFac)
    ->fetch_assoc()['n'];

if ($yaHayListado > 0) {
    echo "Facturas de listado ya sembradas (cliente {$idClienteListadoFac}): {$yaHayListado}\n";
} else {
    foreach ([0, 1] as $i) {
        $albaran = $siembra->ventaAlbaranCliente($idsArticulo[$i], 1.0 + $i, date('Y-m-d'), [
            'idTienda'  => $siembra->tiendaPorDefecto(),
            'estado'    => 'Guardado',
            'idCliente' => $idClienteListadoFac,
        ]);
        $idFactura = $siembra->facturarAlbaranCliente($albaran);
        if ($i === 1) {
            $db->query("UPDATE facclit SET estado='Procesado' WHERE id={$idFactura}");
        }
    }
    echo "Facturas de listado sembradas (cliente {$idClienteListadoFac}): una Guardado y otra Procesado\n";
}

// El recorrido completo de guardado parte de un albaran 'Guardado' que el operador
// incorpora a una factura nueva. Su numero y su identificador se igualan a proposito: sin
// esa coincidencia el enlace factura-albaran lo rechaza la clave foranea y el recorrido no
// llegaria a guardar.
$idClienteGuardarFac = (int) $idsCliente['factura_guardar'];
$filaAlbaranFactura = $db->query(
    'SELECT Numalbcli FROM albclit WHERE idCliente = ' . $idClienteGuardarFac . ' AND estado = "Guardado" LIMIT 1'
)->fetch_assoc();

if ($filaAlbaranFactura !== null) {
    echo "Albaran de guardado de factura ya sembrado (cliente {$idClienteGuardarFac}):"
        . " Numalbcli={$filaAlbaranFactura['Numalbcli']}\n";
} elseif (($fila = $db->query(
    'SELECT Numalbcli FROM albclit WHERE idCliente = ' . $idClienteGuardarFac . ' LIMIT 1'
)->fetch_assoc()) !== null) {
    // Incorporar el albaran a una factura lo deja 'Procesado', y la busqueda de adjuntos
    // solo encuentra los 'Guardado': sin restituirlo, la segunda ejecucion del recorrido
    // partiria de un escenario que ya no es el que el caso necesita. La pantalla no ofrece
    // ninguna accion que lo devuelva, asi que se restituye aqui.
    $db->query(
        'UPDATE albclit SET estado = "Guardado" WHERE idCliente = ' . $idClienteGuardarFac
    );
    echo "Albaran de guardado de factura restituido (cliente {$idClienteGuardarFac}):"
        . " Numalbcli={$fila['Numalbcli']}, de vuelta a Guardado\n";
} else {
    $idAlbaranParaFacturar = $siembra->ventaAlbaranCliente($idsArticulo[0], 3.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteGuardarFac,
    ]);
    $db->query("UPDATE albclit SET Numalbcli={$idAlbaranParaFacturar} WHERE id={$idAlbaranParaFacturar}");
    echo "Albaran de guardado de factura sembrado (cliente {$idClienteGuardarFac}):"
        . " id={$idAlbaranParaFacturar}, con numero igualado al identificador\n";
}

// El recorrido que documenta el fallo al abrir necesita una factura de un cliente cuya
// ficha no tiene forma de vencimiento, que es el estado en que la tabla deja a todo cliente
// recien creado: la columna admite nulo y nada obliga a rellenarla.
$idClienteSinVenci = (int) $idsCliente['factura_sinvenci'];
$yaHaySinVenci = (int) $db->query('SELECT COUNT(*) as n FROM facclit WHERE idCliente = ' . $idClienteSinVenci)
    ->fetch_assoc()['n'];

if ($yaHaySinVenci > 0) {
    echo "Factura sin vencimiento ya sembrada (cliente {$idClienteSinVenci}): {$yaHaySinVenci}\n";
} else {
    $albaranSinVenci = $siembra->ventaAlbaranCliente($idsArticulo[0], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteSinVenci,
    ]);
    $facturaSinVenci = $siembra->facturarAlbaranCliente($albaranSinVenci);
    echo "Factura sin vencimiento sembrada (cliente {$idClienteSinVenci}): id={$facturaSinVenci}\n";
}

// Y se garantiza que su ficha sigue sin forma de vencimiento, por si otra siembra la puso.
$db->query('UPDATE clientes SET formasVenci = NULL WHERE idClientes = ' . $idClienteSinVenci);

// El recorrido de «crear factura desde albaran» necesita un albaran cuyo numero no sea su
// identificador: es la condicion sin la cual la accion acierta por casualidad, porque envia
// el identificador y la busqueda que lo recibe consulta por el numero.
$idClienteDesdeAlb = (int) $idsCliente['factura_desdealb'];
$filaDesdeAlb = $db->query(
    'SELECT id, Numalbcli FROM albclit WHERE idCliente = ' . $idClienteDesdeAlb . ' LIMIT 1'
)->fetch_assoc();

if ($filaDesdeAlb !== null) {
    // La accion no llega a incorporar nada, asi que el albaran no cambia de estado; basta
    // con asegurar que sigue 'Guardado' y con el numero separado del identificador.
    $db->query(
        'UPDATE albclit SET estado = "Guardado", Numalbcli = id + 700000'
        . ' WHERE idCliente = ' . $idClienteDesdeAlb
    );
    echo "Albaran para crear factura desde albaran ya sembrado (cliente {$idClienteDesdeAlb}):"
        . " id={$filaDesdeAlb['id']}\n";
} else {
    $idAlbaranDesde = $siembra->ventaAlbaranCliente($idsArticulo[1], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteDesdeAlb,
    ]);
    $db->query("UPDATE albclit SET Numalbcli = id + 700000 WHERE id = {$idAlbaranDesde}");
    echo "Albaran para crear factura desde albaran sembrado (cliente {$idClienteDesdeAlb}):"
        . " id={$idAlbaranDesde}, con numero separado del identificador\n";
}

$db->query(
    'UPDATE clientes SET formasVenci = \'{"vencimiento":"' . $primerTipo . '"}\''
    . ' WHERE idClientes = ' . $idClienteDesdeAlb . ' AND formasVenci IS NULL'
);

// El recorrido del borrador sin cliente necesita uno tal como lo deja cambiar la fecha en
// una factura nueva antes de asignar el cliente (issue TPVFox #116). Se le da un total
// reconocible porque es lo unico por lo que el listado permite localizarlo: su columna de
// cliente sale vacia, que es justo el sintoma.
$borradorSinCliente = $db->query(
    'SELECT id FROM faccliltemporales WHERE idCliente = 0 AND total = 12345.67 LIMIT 1'
)->fetch_assoc();

if ($borradorSinCliente !== null) {
    echo "Borrador de factura sin cliente ya sembrado: id={$borradorSinCliente['id']}\n";
} else {
    $idSinCliente = $siembra->facturaTemporal([], [], ['idCliente' => 0, 'total' => 12345.67]);
    echo "Borrador de factura sin cliente sembrado: id={$idSinCliente}\n";
}

// Los huecos que las matrices de condiciones de test encontraron necesitan dos escenarios
// mas. El primero: una factura con dos borradores abiertos, que es el caso para el que la
// pantalla tiene un aviso propio —«existen varios»— y que ninguna prueba recorria.
$idClienteDup = (int) $idsCliente['factura_duplicado'];
$yaHayDup = (int) $db->query('SELECT COUNT(*) as n FROM facclit WHERE idCliente = ' . $idClienteDup)
    ->fetch_assoc()['n'];

if ($yaHayDup > 0) {
    echo "Factura con borradores duplicados ya sembrada (cliente {$idClienteDup})\n";
} else {
    $albaranDup = $siembra->ventaAlbaranCliente($idsArticulo[0], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteDup,
    ]);
    $facturaDup = $siembra->facturarAlbaranCliente($albaranDup);
    foreach ([0, 1] as $i) {
        $siembra->facturaTemporal(
            [['idArticulo' => $idsArticulo[0], 'estadoLinea' => 'Activo']],
            [],
            ['idCliente' => $idClienteDup, 'Numfaccli' => $facturaDup]
        );
    }
    echo "Factura con dos borradores sembrada (cliente {$idClienteDup}): id={$facturaDup}\n";
}
$db->query(
    'UPDATE clientes SET formasVenci = \'{"vencimiento":"' . $primerTipo . '"}\''
    . ' WHERE idClientes = ' . $idClienteDup . ' AND formasVenci IS NULL'
);

// El segundo: un albaran 'Guardado' del cliente sin forma de vencimiento, para poder pulsar
// «crear factura desde albaran» sobre el y observar a que pantalla se llega.
$albaranSinVenci = $db->query(
    'SELECT id FROM albclit WHERE idCliente = ' . $idClienteSinVenci . ' AND estado = "Guardado" LIMIT 1'
)->fetch_assoc();

if ($albaranSinVenci !== null) {
    echo "Albaran Guardado del cliente sin vencimiento ya sembrado: id={$albaranSinVenci['id']}\n";
} else {
    $idAlbSinVenci = $siembra->ventaAlbaranCliente($idsArticulo[1], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteSinVenci,
    ]);
    echo "Albaran Guardado del cliente sin vencimiento sembrado: id={$idAlbSinVenci}\n";
}

// Los recorridos de guardado y de «crear factura desde albaran» cambian el numero de su
// albaran despues de crearlo. Se alinean sus lineas y su desglose en cada siembra, lo que
// repara tambien los que quedaron desalineados en siembras anteriores.
alinearNumerosDeAlbaranes($db, (int) $idsCliente['factura_guardar']);
alinearNumerosDeAlbaranes($db, (int) $idsCliente['factura_desdealb']);

// --- Borrador de una factura emitida: un escenario por entrada y por salida -------------
//
// Estos recorridos cambian el estado de su factura —la marcan como no guardada, la
// reescriben, la dejan a medias o le crean borradores de mas—, de modo que el escenario no
// se restituye campo a campo: se rehace entero en cada siembra.
//
// Cada factura lleva un identificador fijo en un rango que ningun pedido ni albaran ocupa.
// Editar una factura cambia tambien el estado del pedido y del albaran que compartan su
// identificador, y con identificadores del rango habitual estos recorridos alterarian
// documentos de otros recorridos.
$idArticuloBorrador = $idsArticulo[0];
$formaVencimiento = '{"vencimiento":"' . $primerTipo . '"}';

$prepararClienteDeBorrador = function (int $idCliente) use ($db, $formaVencimiento): void {
    borrarDocumentosDeCliente($db, $idCliente);
    $sentencia = $db->prepare('UPDATE clientes SET formasVenci = ? WHERE idClientes = ?');
    $sentencia->bind_param('si', $formaVencimiento, $idCliente);
    $sentencia->execute();
};

// Un albaran 'Guardado' del cliente. Sin numero indicado lleva como numero su propio
// identificador, que es la unica condicion en que reguardar una factura que lo incluya
// supera la clave foranea del enlace con el albaran.
$albaranDisponible = function (int $idCliente, ?int $numero = null) use ($db, $siembra, $idArticuloBorrador): int {
    $idAlbaran = $siembra->ventaAlbaranCliente($idArticuloBorrador, 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idCliente,
    ]);
    $numero ??= $idAlbaran;
    $db->query("UPDATE albclit SET Numalbcli = {$numero} WHERE id = {$idAlbaran}");
    alinearNumerosDeAlbaranes($db, $idCliente);

    return $idAlbaran;
};

$rehacerFacturaDeBorrador = function (string $clave, int $idFactura, ?int $numeroAlbaran = null) use ($siembra, $idsCliente, $prepararClienteDeBorrador, $albaranDisponible): void {
    $idCliente = (int) $idsCliente[$clave];
    $prepararClienteDeBorrador($idCliente);
    $idAlbaran = $albaranDisponible($idCliente, $numeroAlbaran);
    $siembra->facturarAlbaranCliente($idAlbaran, null, $idFactura);

    $numero = $numeroAlbaran ?? 'su identificador';
    echo "Factura de borrador rehecha ({$clave}, cliente {$idCliente}): id={$idFactura}, albaran con numero {$numero}\n";
};

$rehacerFacturaDeBorrador('borrador_producto', 810001, 830943);

$rehacerFacturaDeBorrador('borrador_albaran', 810002, 830944);
// Y un segundo albaran del mismo cliente, disponible para incorporarlo desde la edicion.
$albaranDisponible((int) $idsCliente['borrador_albaran'], 820944);

// Las lineas que proceden de un albaran se muestran bloqueadas en edicion: esta factura
// conserva su linea como directa, sin albaran, para que se pueda tocar.
$rehacerFacturaDeBorrador('borrador_linea', 810003, 830945);
$db->query('UPDATE facclilinea SET NumalbCli = 0 WHERE idfaccli = 810003');
$db->query('DELETE FROM albclifac WHERE idFactura = 810003');

$rehacerFacturaDeBorrador('borrador_fecha', 810004, 830946);
$rehacerFacturaDeBorrador('borrador_guardar', 810005);
$rehacerFacturaDeBorrador('borrador_cancelar', 810006, 830948);
$rehacerFacturaDeBorrador('borrador_abandona', 810007, 830949);
// Un numero de albaran que no es el identificador de ningun albaran: al reguardar la
// factura, el enlace con su albaran choca con la clave foranea.
$rehacerFacturaDeBorrador('borrador_fallo', 810008, 990950);
$rehacerFacturaDeBorrador('borrador_fechamal', 810009, 830951);
$rehacerFacturaDeBorrador('borrador_estado', 810010, 830952);
$rehacerFacturaDeBorrador('borrador_contraste', 810011, 830954);

// El otro documento del recorrido de cambio de estado: un albaran disponible de otro
// cliente cuyo numero es el identificador de la factura anterior.
$idClienteOtroDocumento = (int) $idsCliente['borrador_otro'];
$prepararClienteDeBorrador($idClienteOtroDocumento);
$albaranDisponible($idClienteOtroDocumento, 810010);
echo "Albaran del otro documento rehecho (cliente {$idClienteOtroDocumento}): numero 810010\n";

// --- Comportamiento esperado: el estado no se contagia entre tipos de documento ----------
//
// El despacho de cambio de estado distingue el tipo con asignacion en vez de comparacion, de
// modo que las tres ramas se ejecutan siempre y un cambio alcanza al pedido, al albaran y a
// la factura que compartan ese identificador.
//
// Para observarlo hace falta que el numero de un albaran coincida con el identificador de una
// factura ajena: al incorporar ese albaran a otro documento, la peticion viaja con el numero y
// el despacho la aplica tambien por identificador sobre la factura.
//
// Escenario propio, separado del de 'borrador_estado' (factura 810010), porque aquel lo
// consume su recorrido y los dos correrian en paralelo sobre la misma fila.
$idClienteEstadoCruzado = (int) $idsCliente['esperado_estadocruzado'];
$rehacerFacturaDeBorrador('esperado_estadocruzado', 810012, 830962);
$albaranDisponible($idClienteEstadoCruzado, 810012);
echo "Estado cruzado sembrado (cliente {$idClienteEstadoCruzado}): factura id=810012 y albaran con numero 810012\n";

// --- Comportamiento esperado: un albaran no se incorpora a dos facturas ------------------
//
// Al incorporar un albaran a una factura, el navegador lo marca como procesado para que ninguna
// otra lo encuentre: la busqueda por numero solo ofrece albaranes en «Guardado». Pero la marca
// viaja con el NUMERO del albaran y el servidor la aplica por IDENTIFICADOR. Cuando los dos no
// coinciden, la marca no alcanza al albaran incorporado, que sigue en «Guardado» y vuelve a
// aparecer para la siguiente factura: la misma mercancia facturada dos veces.
//
// Dos albaranes del mismo cliente, en «Guardado» y sin facturar:
// - el 830960, cuyo numero no es el identificador de ningun documento: la marca no le llega;
// - otro con numero igual a su identificador: la marca si le llega. Es el control positivo.
//
// El cliente se renombra por identificador si su nombre cambia entre versiones de la siembra.
// Su limpieza en cada pasada ya la hace el bloque de clientes de comportamiento esperado.
$idClienteDobleFactura = (int) $idsCliente['esperado_albfacturado'];
$albaranDisponible($idClienteDobleFactura, 830960);
$idAlbaranAlineado = $albaranDisponible($idClienteDobleFactura);
echo "Albaranes para facturar dos veces (cliente {$idClienteDobleFactura}): numero 830960 desalineado, "
    . "y el {$idAlbaranAlineado} con numero igual a su identificador\n";

// --- Comportamiento esperado: no se vende por debajo de cero -----------------------------
//
// El movimiento de existencias no tiene suelo: con dos disponibles se venden cinco y el saldo
// queda en -3, sin aviso. Hace falta un articulo propio, porque el recorrido deja su ficha en
// negativo y hay que reponerla en cada pasada; sin eso, la segunda pasada partiria de un saldo
// ya negativo y dejaria de medir lo que dice medir.
//
// Se siembra aparte del bucle de articulos porque necesita dos cosas que aquel no hace: la
// reposicion de existencias, y la fila de precio de la tienda 1 escrita a mano. La tienda 1 no
// existe en `tiendas` y `articulosTiendas` tiene clave ajena contra ella, de modo que
// `precioYTienda()` no puede usarse con ese identificador: escribe las dos tablas y la segunda
// la rechaza la base. Los articulos 14678 y 14679 tienen exactamente esta forma —fila de
// precio en la tienda por defecto y en la 1, fila de `articulosTiendas` solo en la primera—,
// asi que se replica.
$ID_ARTICULO_ESCASO = 14680;
// Los nombres no empiezan por «Manzana»: los recorridos de busqueda por descripcion teclean
// «[E2E venta] Manzana» y cuentan exactamente dos coincidencias, Golden y Reineta.
$nombreEscaso = PREFIJO . 'Pera escasa';
// Cada pieza comprueba su propia tabla: si una pasada anterior quedo a medias, la siguiente
// completa lo que falte en vez de darlo por hecho porque el articulo ya exista.
// Se comprueba por identificador, no por nombre: el nombre ha cambiado entre versiones de la
// siembra y buscarlo por nombre intentaria insertar de nuevo un id que ya existe.
if ($db->query("SELECT 1 FROM articulos WHERE idArticulo = {$ID_ARTICULO_ESCASO}")->num_rows === 0) {
    $siembra->articulo($nombreEscaso, [
        'id'          => $ID_ARTICULO_ESCASO,
        'ultimoCoste' => 1.0,
        'beneficio'   => 50,
        'iva'         => 21,
    ]);
    echo "Articulo escaso sembrado: {$nombreEscaso} (id {$ID_ARTICULO_ESCASO})\n";
}
$db->query("UPDATE articulos SET articulo_name = '" . $db->real_escape_string($nombreEscaso) . "' WHERE idArticulo = {$ID_ARTICULO_ESCASO}");

$preciosEscaso = (int) $db
    ->query("SELECT COUNT(*) AS n FROM articulosPrecios WHERE idArticulo = {$ID_ARTICULO_ESCASO}")
    ->fetch_assoc()['n'];
if ($preciosEscaso === 0) {
    $siembra->precioYTienda($ID_ARTICULO_ESCASO, 1.82, 1.50);
    $db->query(
        'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) '
        . "VALUES ({$ID_ARTICULO_ESCASO}, 1.82, 1.50, 1)"
    );
    echo "Precio del articulo escaso sembrado en la tienda por defecto y en la 1\n";
}

$db->query("DELETE FROM articulosStocks WHERE idArticulo = {$ID_ARTICULO_ESCASO}");
$siembra->existenciaRegistrada($ID_ARTICULO_ESCASO, 2.0);
echo "Existencias del articulo escaso repuestas: {$ID_ARTICULO_ESCASO} con 2 unidades\n";

// --- Comportamiento esperado: el guardado se completa entero o no deja rastro ------------
//
// Las tres clases de venta escriben la descripcion de la linea concatenandola entre comillas
// dobles, sin escapar. Un articulo cuyo nombre lleve una comilla doble rompe la sentencia de
// la linea, y lo hace DESPUES de que la cabecera ya se haya escrito: queda un documento con
// su numero y su importe, y sin ninguna linea.
//
// Es la unica via que llega a ese estado desde la pantalla: se teclea el identificador del
// articulo como cualquier otro. El resto de vectores conocidos —reguardar un albaran ya
// facturado, o un pedido ya servido— los bloquea la propia pantalla, que abre esos documentos
// en solo lectura.
$ID_ARTICULO_COMILLA = 14681;
$nombreComilla = PREFIJO . 'Pera 5" premium';
if ($db->query("SELECT 1 FROM articulos WHERE idArticulo = {$ID_ARTICULO_COMILLA}")->num_rows === 0) {
    $siembra->articulo($nombreComilla, [
        'id'          => $ID_ARTICULO_COMILLA,
        'ultimoCoste' => 1.0,
        'beneficio'   => 50,
        'iva'         => 21,
    ]);
    echo "Articulo con comilla sembrado: {$nombreComilla} (id {$ID_ARTICULO_COMILLA})\n";
}
$db->query("UPDATE articulos SET articulo_name = '" . $db->real_escape_string($nombreComilla) . "' WHERE idArticulo = {$ID_ARTICULO_COMILLA}");

$preciosComilla = (int) $db
    ->query("SELECT COUNT(*) AS n FROM articulosPrecios WHERE idArticulo = {$ID_ARTICULO_COMILLA}")
    ->fetch_assoc()['n'];
if ($preciosComilla === 0) {
    $siembra->precioYTienda($ID_ARTICULO_COMILLA, 1.82, 1.50);
    $db->query(
        'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) '
        . "VALUES ({$ID_ARTICULO_COMILLA}, 1.82, 1.50, 1)"
    );
    echo "Precio del articulo con comilla sembrado\n";
}

// --- Numeracion: un albaran Guardado que el recorrido incorporara y emitira -------------
//
// Su numero se iguala al identificador porque la clave foranea del enlace factura-albaran
// lo exige; lo que el recorrido observa despues es el numero que el producto pone a la
// factura que el mismo emite, no el de este albaran.
$idClienteNumeracion = (int) $idsCliente['esperado_numeracion'];
$yaHayAlbaranNum = $db->query(
    'SELECT id FROM albclit WHERE idCliente = ' . $idClienteNumeracion . ' AND estado = "Guardado" LIMIT 1'
)->fetch_assoc();

if ($yaHayAlbaranNum !== null) {
    echo "Albaran de numeracion ya sembrado (cliente {$idClienteNumeracion}): id={$yaHayAlbaranNum['id']}\n";
} else {
    $db->query('UPDATE albclit SET estado = "Guardado" WHERE idCliente = ' . $idClienteNumeracion);
    $idAlbaranNum = $siembra->ventaAlbaranCliente($idsArticulo[0], 2.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteNumeracion,
    ]);
    $db->query("UPDATE albclit SET Numalbcli={$idAlbaranNum} WHERE id={$idAlbaranNum}");
    alinearNumerosDeAlbaranes($db, $idClienteNumeracion);
    echo "Albaran de numeracion sembrado (cliente {$idClienteNumeracion}): id={$idAlbaranNum}\n";
}

// --- Impreso: una factura con dos albaranes, uno con lineas y otro sin ninguna ----------
//
// El imprimible de la factura agrupa las lineas en bloques bajo una cabecera por albaran.
// Con un albaran vacio de por medio, cabeceras y bloques dejan de ir en el mismo orden, que
// es lo que el recorrido del impreso observa. No hay ningun albaran sin lineas en la base,
// asi que se construye aqui: se siembra con su linea y se le retira despues.
$idClienteImpreso = (int) $idsCliente['esperado_impreso'];
$yaHayImpreso = (int) $db->query(
    'SELECT COUNT(*) n FROM facclit WHERE idCliente = ' . $idClienteImpreso
)->fetch_assoc()['n'];

if ($yaHayImpreso > 0) {
    echo "Factura de impreso ya sembrada (cliente {$idClienteImpreso})\n";
} else {
    $albaranConLineas = $siembra->ventaAlbaranCliente($idsArticulo[0], 3.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteImpreso,
    ]);
    $albaranVacio = $siembra->ventaAlbaranCliente($idsArticulo[1], 1.0, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idClienteImpreso,
    ]);
    $db->query("DELETE FROM albclilinea WHERE idalbcli = {$albaranVacio}");
    $db->query("DELETE FROM albcliIva WHERE idalbcli = {$albaranVacio}");

    $idFacturaImpreso = $siembra->facturarAlbaranCliente($albaranConLineas);

    // El segundo albaran se enlaza a mano: `facturarAlbaranCliente` crea una factura por
    // albaran, y aqui hacen falta dos albaranes en la misma.
    $filaVacio = $db->query("SELECT Numalbcli FROM albclit WHERE id = {$albaranVacio}")->fetch_assoc();
    $filaFactura = $db->query("SELECT Numfaccli FROM facclit WHERE id = {$idFacturaImpreso}")->fetch_assoc();
    $db->query(
        'INSERT INTO albclifac (idFactura, numFactura, idAlbaran, numAlbaran) VALUES ('
        . "{$idFacturaImpreso}, {$filaFactura['Numfaccli']}, {$albaranVacio}, {$filaVacio['Numalbcli']})"
    );
    $db->query("UPDATE albclit SET estado = 'Procesado' WHERE id = {$albaranVacio}");

    echo "Factura de impreso sembrada (cliente {$idClienteImpreso}): factura id={$idFacturaImpreso}"
        . " con albaran con lineas id={$albaranConLineas} y albaran vacio id={$albaranVacio}\n";
}

// --- Comportamiento esperado: adjuntar un documento con cantidad o precio de mil o mas ----
//
// Al traer las lineas de un pedido a un albaran, o de un albaran a una factura, el producto
// formatea la cantidad y el precio con separador de miles. El valor vuelve asi al guardar y
// rompe la sentencia de la linea. Cada recorrido parte de un documento de origen 'Guardado'
// recien sembrado, porque adjuntarlo lo consume: su cliente se limpia mas arriba, con los
// demas de comportamiento esperado, y aqui se vuelve a sembrar.
//
// El numero del documento se iguala a su identificador. El navegador pide el cambio de
// estado del adjunto por numero y el servidor lo aplica por identificador: con numeros
// distintos el cambio caeria en otro documento, y el recorrido mediria esa otra cosa.
$ID_ARTICULO_CARO = 14682;
$nombreCaro = PREFIJO . 'Camara frigorifica';
if ($db->query("SELECT 1 FROM articulos WHERE idArticulo = {$ID_ARTICULO_CARO}")->num_rows === 0) {
    $siembra->articulo($nombreCaro, ['id' => $ID_ARTICULO_CARO, 'ultimoCoste' => 1500.0, 'beneficio' => 0, 'iva' => 21]);
    // Sin fila de precio: el pedido lleva el suyo en la linea, y es el que viaja al albaran.
    echo "Articulo de precio alto sembrado: {$nombreCaro} (id {$ID_ARTICULO_CARO})\n";
}
// Sin precio de catalogo, a proposito: un articulo de mil o mas hace que el listado de
// coincidencias de la busqueda responda 500, y lo haria en los recorridos de busqueda que
// nada tienen que ver con este. El pedido lleva su precio en la linea, que es el que viaja.
$db->query("DELETE FROM articulosPrecios WHERE idArticulo = {$ID_ARTICULO_CARO}");

$pedidosParaAdjuntar = [
    'esperado_adjunto_mil'     => [$idsArticulo[0], 1000.0],
    'esperado_adjunto_recarga' => [$idsArticulo[0], 1000.0],
    'esperado_adjunto_precio'  => [$ID_ARTICULO_CARO, 1.0],
    'esperado_adjunto_control' => [$idsArticulo[0], 999.0],
];
foreach ($pedidosParaAdjuntar as $clave => [$idArticuloDelPedido, $unidades]) {
    $idPedido = $siembra->pedidoVentaCliente($idArticuloDelPedido, $unidades, date('Y-m-d'), [
        'idTienda'  => $siembra->tiendaPorDefecto(),
        'estado'    => 'Guardado',
        'idCliente' => $idsCliente[$clave],
    ]);
    $db->query("UPDATE pedclit SET Numpedcli = id WHERE id = {$idPedido}");
    $db->query("UPDATE pedclilinea SET Numpedcli = {$idPedido} WHERE idpedcli = {$idPedido}");
    $db->query("UPDATE pedcliIva SET Numpedcli = {$idPedido} WHERE idpedcli = {$idPedido}");
    echo "Pedido para adjuntar sembrado ({$clave}): id y numero {$idPedido}, {$unidades} unidades\n";
}

$idClienteAdjuntoFactura = (int) $idsCliente['esperado_adjunto_factura'];
$idAlbaranDeMil = $siembra->ventaAlbaranCliente($idsArticulo[0], 1000.0, date('Y-m-d'), [
    'idTienda'  => $siembra->tiendaPorDefecto(),
    'estado'    => 'Guardado',
    'idCliente' => $idClienteAdjuntoFactura,
]);
$db->query("UPDATE albclit SET Numalbcli = id WHERE id = {$idAlbaranDeMil}");
alinearNumerosDeAlbaranes($db, $idClienteAdjuntoFactura);
// Con forma de vencimiento: sin ella la pantalla de la factura guardada responde 500 por un
// defecto distinto, y este recorrido fallaria por ese motivo y no por el suyo.
$db->query("UPDATE clientes SET formasVenci = '{\"vencimiento\":\"0\"}' WHERE idClientes = {$idClienteAdjuntoFactura}");
echo "Albaran para facturar sembrado (cliente {$idClienteAdjuntoFactura}): id y numero {$idAlbaranDeMil}, 1000 unidades\n";

// --- La traza de autoria: un documento de otro usuario y de otra fecha, por tipo ----------
//
// Los crea la siembra, de modo que su creador es el usuario de la siembra y no el de los
// recorridos. El recorrido los abre, les añade una linea y los guarda: el creador y la fecha
// de creacion tienen que seguir siendo estos.
$FECHA_DE_CREACION = '2026-01-15 10:00:00';

$idPedidoTraza = $siembra->pedidoVentaCliente($idsArticulo[0], 1.0, '2026-01-15', [
    'idTienda' => $siembra->tiendaPorDefecto(), 'estado' => 'Guardado', 'idCliente' => $idsCliente['traza_pedido'],
]);
$db->query("UPDATE pedclit SET Numpedcli = id, fechaCreacion = '{$FECHA_DE_CREACION}', fechaModificacion = '{$FECHA_DE_CREACION}' WHERE id = {$idPedidoTraza}");
$db->query("UPDATE pedclilinea SET Numpedcli = {$idPedidoTraza} WHERE idpedcli = {$idPedidoTraza}");
$db->query("UPDATE pedcliIva SET Numpedcli = {$idPedidoTraza} WHERE idpedcli = {$idPedidoTraza}");

$idAlbaranTraza = $siembra->ventaAlbaranCliente($idsArticulo[0], 1.0, '2026-01-15', [
    'idTienda' => $siembra->tiendaPorDefecto(), 'estado' => 'Guardado', 'idCliente' => $idsCliente['traza_albaran'],
]);
$db->query("UPDATE albclit SET Numalbcli = id WHERE id = {$idAlbaranTraza}");
alinearNumerosDeAlbaranes($db, (int) $idsCliente['traza_albaran']);

// --- Comportamiento esperado: volver a guardar se completa entero o no cambia nada ---------
//
// Un albaran y un pedido ya guardados, con una linea. El recorrido los abre para editar, les
// añade el articulo de la comilla y guarda: la linea nueva no se puede escribir, y para entonces
// el documento anterior ya se ha borrado y su cabecera se ha reescrito con el importe nuevo.
$idAlbaranReguardado = $siembra->ventaAlbaranCliente($idsArticulo[0], 1.0, '2026-01-15', [
    'idTienda' => $siembra->tiendaPorDefecto(), 'estado' => 'Guardado', 'idCliente' => $idsCliente['esperado_reguardado_alb'],
]);
$db->query("UPDATE albclit SET Numalbcli = id WHERE id = {$idAlbaranReguardado}");
alinearNumerosDeAlbaranes($db, (int) $idsCliente['esperado_reguardado_alb']);

$idPedidoReguardado = $siembra->pedidoVentaCliente($idsArticulo[0], 1.0, '2026-01-15', [
    'idTienda' => $siembra->tiendaPorDefecto(), 'estado' => 'Guardado', 'idCliente' => $idsCliente['esperado_reguardado_ped'],
]);
$db->query("UPDATE pedclit SET Numpedcli = id WHERE id = {$idPedidoReguardado}");
$db->query("UPDATE pedclilinea SET Numpedcli = {$idPedidoReguardado} WHERE idpedcli = {$idPedidoReguardado}");
$db->query("UPDATE pedcliIva SET Numpedcli = {$idPedidoReguardado} WHERE idpedcli = {$idPedidoReguardado}");
echo "Documentos para volver a guardar sembrados: albaran {$idAlbaranReguardado}, pedido {$idPedidoReguardado}\n";

// --- Comportamiento esperado: si el movimiento de existencias falla, no queda albaran ------
//
// Un articulo con el saldo en el limite inferior de la columna: restarle una unidad lo saca de
// rango y la base rechaza la sentencia. Falla el movimiento de existencias, no el albaran, que
// para entonces ya tiene escritas la cabecera y la linea. El saldo se repone en cada pasada.
$ID_ARTICULO_SALDO_LIMITE = 14683;
$nombreSaldoLimite = PREFIJO . 'Kiwi con el saldo en el limite';
if ($db->query("SELECT 1 FROM articulos WHERE idArticulo = {$ID_ARTICULO_SALDO_LIMITE}")->num_rows === 0) {
    $siembra->articulo($nombreSaldoLimite, [
        'id'          => $ID_ARTICULO_SALDO_LIMITE,
        'ultimoCoste' => 1.0,
        'beneficio'   => 50,
        'iva'         => 21,
    ]);
    echo "Articulo con el saldo en el limite sembrado: {$nombreSaldoLimite} (id {$ID_ARTICULO_SALDO_LIMITE})\n";
}
if ((int) $db->query("SELECT COUNT(*) AS n FROM articulosPrecios WHERE idArticulo = {$ID_ARTICULO_SALDO_LIMITE}")->fetch_assoc()['n'] === 0) {
    $siembra->precioYTienda($ID_ARTICULO_SALDO_LIMITE, 1.82, 1.50);
    $db->query(
        'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) '
        . "VALUES ({$ID_ARTICULO_SALDO_LIMITE}, 1.82, 1.50, 1)"
    );
}
$db->query("DELETE FROM articulosStocks WHERE idArticulo = {$ID_ARTICULO_SALDO_LIMITE}");
$siembra->existenciaRegistrada($ID_ARTICULO_SALDO_LIMITE, -99999999999.0);

$idAlbaranDeLaFacturaTraza = $siembra->ventaAlbaranCliente($idsArticulo[0], 1.0, '2026-01-15', [
    'idTienda' => $siembra->tiendaPorDefecto(), 'estado' => 'Guardado', 'idCliente' => $idsCliente['traza_factura'],
]);
$idFacturaTraza = $siembra->facturarAlbaranCliente($idAlbaranDeLaFacturaTraza);
$db->query("UPDATE facclit SET Numfaccli = id, fechaCreacion = '{$FECHA_DE_CREACION}', fechaModificacion = '{$FECHA_DE_CREACION}' WHERE id = {$idFacturaTraza}");
$db->query("UPDATE facclilinea SET Numfaccli = {$idFacturaTraza} WHERE idfaccli = {$idFacturaTraza}");
$db->query("UPDATE faccliIva SET Numfaccli = {$idFacturaTraza} WHERE idfaccli = {$idFacturaTraza}");
$db->query("UPDATE albclifac SET numFactura = {$idFacturaTraza} WHERE idFactura = {$idFacturaTraza}");
echo "Documentos de traza sembrados: pedido {$idPedidoTraza}, albaran {$idAlbaranTraza}, factura {$idFacturaTraza}\n";

// --- Sin sesion y sin permiso: un documento guardado por recorrido -------------------------
//
// Cada recorrido deja su documento cambiado de estado o borrado, de modo que se rehacen en cada
// pasada. Numero igual al identificador, como los que crea la pantalla.
$albaranGuardadoDe = function (string $clave) use ($db, $siembra, $idsArticulo, $idsCliente): int {
    $idAlbaran = $siembra->ventaAlbaranCliente($idsArticulo[0], 1.0, '2026-01-20', [
        'idTienda' => $siembra->tiendaPorDefecto(), 'estado' => 'Guardado', 'idCliente' => $idsCliente[$clave],
    ]);
    $db->query("UPDATE albclit SET Numalbcli = id WHERE id = {$idAlbaran}");
    alinearNumerosDeAlbaranes($db, (int) $idsCliente[$clave]);

    return $idAlbaran;
};
$idAlbaranSinSesion = $albaranGuardadoDe('sesion_albaran');
$idAlbaranEstadoSinSesion = $albaranGuardadoDe('sesion_estado');
$idAlbaranEstadoSinPermiso = $albaranGuardadoDe('permiso_estado');
$idAlbaranCancelado = $albaranGuardadoDe('cancelar_albaran');

$pedidoGuardadoDe = function (string $clave) use ($db, $siembra, $idsArticulo, $idsCliente): int {
    $idPedido = $siembra->pedidoVentaCliente($idsArticulo[0], 1.0, '2026-01-20', [
        'idTienda' => $siembra->tiendaPorDefecto(), 'estado' => 'Guardado', 'idCliente' => $idsCliente[$clave],
    ]);
    $db->query("UPDATE pedclit SET Numpedcli = id WHERE id = {$idPedido}");
    $db->query("UPDATE pedclilinea SET Numpedcli = {$idPedido} WHERE idpedcli = {$idPedido}");
    $db->query("UPDATE pedcliIva SET Numpedcli = {$idPedido} WHERE idpedcli = {$idPedido}");

    return $idPedido;
};
$idPedidoSinSesion = $pedidoGuardadoDe('sesion_pedido');
$idPedidoCancelado = $pedidoGuardadoDe('cancelar_pedido');
echo "Documentos sin sesion, sin permiso y de edicion cancelada sembrados: albaranes {$idAlbaranSinSesion},"
    . " {$idAlbaranEstadoSinSesion}, {$idAlbaranEstadoSinPermiso}, {$idAlbaranCancelado};"
    . " pedidos {$idPedidoSinSesion}, {$idPedidoCancelado}\n";

// Un usuario que no es administrador. Entra con la misma clave que el de los recorridos, y sus
// permisos se borran para que el producto los cree de nuevo al entrar, con los valores por
// defecto de `acces.xml`: sin permiso para cambiar el estado de un albaran ni para su pantalla.
$usuarioSinPermisos = 'e2e_sin_permisos';
$claveSinPermisos = md5(Entorno::valor('TPVFOX_E2E_CLAVE'));
$existe = $db->prepare('SELECT id FROM usuarios WHERE username = ? LIMIT 1');
$existe->bind_param('s', $usuarioSinPermisos);
$existe->execute();
$fila = $existe->get_result()->fetch_row();
if ($fila === null) {
    $alta = $db->prepare(
        "INSERT INTO usuarios (username, password, fecha, group_id, estado, nombre) VALUES (?, ?, CURDATE(), 1, 'activo', ?)"
    );
    $nombreSinPermisos = PREFIJO . 'Usuario sin permisos';
    $alta->bind_param('sss', $usuarioSinPermisos, $claveSinPermisos, $nombreSinPermisos);
    $alta->execute();
    $idUsuarioSinPermisos = $db->insert_id;
} else {
    $idUsuarioSinPermisos = (int) $fila[0];
    $db->query("UPDATE usuarios SET password = '{$claveSinPermisos}', group_id = 1, estado = 'activo' WHERE id = {$idUsuarioSinPermisos}");
}
// El acceso exige exactamente una fila de indice: con ninguna o con dos, la sesion no se abre.
$db->query("DELETE FROM indices WHERE idUsuario = {$idUsuarioSinPermisos}");
$db->query('INSERT INTO indices (idTienda, idUsuario, numticket, tempticket) VALUES ('
    . $siembra->tiendaPorDefecto() . ", {$idUsuarioSinPermisos}, 0, 0)");
$db->query("DELETE FROM permisos WHERE idUsuario = {$idUsuarioSinPermisos}");
echo "Usuario sin permisos: {$usuarioSinPermisos} (id {$idUsuarioSinPermisos})\n";

echo "\nArticulos disponibles para los recorridos: " . implode(', ', $idsArticulo) . "\n";
foreach ($idsCliente as $clave => $id) {
    echo "Cliente ($clave): $id\n";
}

$db->close();

// --- Apoyos -----------------------------------------------------------------



function existente(mysqli $db, string $tabla, string $campo, string $valor): ?int
{
    $columnaId = $tabla === 'clientes' ? 'idClientes' : 'id' . ucfirst(rtrim($tabla, 's'));
    if ($tabla === 'articulos') {
        $columnaId = 'idArticulo';
    }
    $sentencia = $db->prepare("SELECT `$columnaId` FROM `$tabla` WHERE `$campo` = ? LIMIT 1");
    $sentencia->bind_param('s', $valor);
    $sentencia->execute();
    $fila = $sentencia->get_result()->fetch_row();

    return $fila === null ? null : (int) $fila[0];
}

function insertarCliente(mysqli $db, int $idFijo, string $nombre): int
{
    $sentencia = $db->prepare('INSERT INTO clientes (idClientes, Nombre, estado) VALUES (?, ?, ?)');
    $activo = 'Activo';
    $sentencia->bind_param('iss', $idFijo, $nombre, $activo);
    $sentencia->execute();

    return (int) $db->insert_id;
}

/**
 * Borra todo lo que un cliente tiene en facturas, borradores de factura y albaranes, para
 * rehacer su escenario desde cero. Solo para los clientes de los recorridos del borrador de
 * factura, que no tienen pedidos.
 */
function borrarDocumentosDeCliente(mysqli $db, int $idCliente): void
{
    $facturas = "SELECT id FROM facclit WHERE idCliente = {$idCliente}";
    $albaranes = "SELECT id FROM albclit WHERE idCliente = {$idCliente}";

    $db->query("DELETE FROM faccliltemporales WHERE idCliente = {$idCliente} OR Numfaccli IN ({$facturas})");
    $db->query("DELETE FROM facclilinea WHERE idfaccli IN ({$facturas})");
    $db->query("DELETE FROM faccliIva WHERE idfaccli IN ({$facturas})");
    $db->query("DELETE FROM albclifac WHERE idFactura IN ({$facturas}) OR idAlbaran IN ({$albaranes})");
    $db->query("DELETE FROM pedcliAlb WHERE idAlbaran IN ({$albaranes})");
    $db->query("DELETE FROM facclit WHERE idCliente = {$idCliente}");
    $db->query("DELETE FROM albclilinea WHERE idalbcli IN ({$albaranes})");
    $db->query("DELETE FROM albcliIva WHERE idalbcli IN ({$albaranes})");
    $db->query("DELETE FROM albclit WHERE idCliente = {$idCliente}");
}

/**
 * Pone en las lineas y en el desglose de impuestos de los albaranes de un cliente el numero
 * que su cabecera lleva ahora. La siembra cambia el numero de algunos albaranes despues de
 * crearlos; sin esto sus lineas y su desglose se quedan con el numero anterior, y el
 * siguiente albaran que reciba ese numero suma como suyo un desglose que no lo es.
 */
function alinearNumerosDeAlbaranes(mysqli $db, int $idCliente): void
{
    foreach (['albclilinea', 'albcliIva'] as $tabla) {
        $db->query(
            "UPDATE {$tabla} d JOIN albclit a ON a.id = d.idalbcli"
            . ' SET d.Numalbcli = a.Numalbcli'
            . " WHERE a.idCliente = {$idCliente} AND d.Numalbcli <> a.Numalbcli"
        );
    }
}

/**
 * Borra los pedidos de un cliente con todo lo que cuelga de ellos, incluida su relacion con
 * albaranes. Para los clientes de los recorridos que componen un pedido en cada pasada.
 */
function borrarPedidosDeCliente(mysqli $db, int $idCliente): void
{
    $pedidos = "SELECT id FROM pedclit WHERE idCliente = {$idCliente}";

    $db->query("DELETE FROM pedcliltemporales WHERE idCliente = {$idCliente} OR Numpedcli IN ({$pedidos})");
    $db->query("DELETE FROM pedcliAlb WHERE idPedido IN ({$pedidos})");
    $db->query("DELETE FROM pedclilinea WHERE idpedcli IN ({$pedidos})");
    $db->query("DELETE FROM pedcliIva WHERE idpedcli IN ({$pedidos})");
    $db->query("DELETE FROM pedclit WHERE idCliente = {$idCliente}");
}

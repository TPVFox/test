<?php

/**
 * La API de una tienda web de pega, para las pruebas de la conexión de TPVFox con la tienda web.
 *
 * La sirve el servidor de pruebas en la dirección local: la tienda web que siembra un caso tiene como
 * dominio la carpeta `web/`, y como clave de su API una propia del caso. Solo atiende las claves que
 * tienen su carpeta en `web/casos/` —que crea el caso, borra al terminar y git ignora—: en ella apunta
 * cada petición que recibe, una por línea, y de ella toma la respuesta de cada acción —`<acción>.json`—
 * y, si el caso la prepara, su código HTTP —`<acción>.http`—. Sin respuesta preparada, responde un
 * objeto vacío.
 *
 * No conecta con nada ni guarda nada fuera de esa carpeta.
 */

declare(strict_types=1);

$clave = (string) ($_POST['key'] ?? '');
$carpeta = dirname(__DIR__, 2) . '/casos/' . $clave;
if (preg_match('/^[a-z0-9]{8,30}$/', $clave) !== 1 || !is_dir($carpeta)) {
    http_response_code(403);
    echo '{"error":"Clave no válida"}';

    return;
}

file_put_contents($carpeta . '/peticiones.jsonl', json_encode($_POST, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

$accion = preg_replace('/[^A-Za-z]/', '', (string) ($_POST['action'] ?? ''));
if (is_file($carpeta . '/' . $accion . '.http')) {
    http_response_code((int) file_get_contents($carpeta . '/' . $accion . '.http'));
}
header('Content-Type: application/json');
echo is_file($carpeta . '/' . $accion . '.json') ? file_get_contents($carpeta . '/' . $accion . '.json') : '{}';

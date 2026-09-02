/**
 * Selecciona un cliente por id en una pantalla de venta (pedido/albarán/factura) y espera
 * a que la petición `comprobarAlbaran` responda de verdad, no un temporizador fijo.
 *
 * `buscarClientes()` dispara dos AJAX en cadena en su propio `success`: primero la
 * búsqueda del cliente, y luego `comprobarAdjuntosExis()` (que despacha al caso
 * `comprobarAlbaran` de `tareas.php`, sea cual sea `dedonde`). El resto del flujo
 * (`buscarAdjunto`, `buscarProductos`...) lee estado que ese primer tramo deja asentado
 * (`cabecera.idCliente`); sin esperar la segunda respuesta, el siguiente paso puede
 * disparar antes de que ese estado esté listo — carrera intermitente, más visible cuando
 * varios specs corren en paralelo y compiten por CPU con el servidor de desarrollo.
 */

async function seleccionarCliente(page, idCliente) {
  await page.fill('#id_cliente', String(idCliente));
  await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('comprobarAlbaran')
    ),
    page.locator('#id_cliente').press('Enter'),
  ]);
}

module.exports = { seleccionarCliente };

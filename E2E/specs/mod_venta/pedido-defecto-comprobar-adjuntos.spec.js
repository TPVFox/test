/**
 * Defecto: seleccionar un cliente en un pedido nuevo lanza un TypeError sin capturar en
 * el navegador.
 *
 * Síntoma: justo después de `buscarClientes()`, `pedido.php` llama a
 * `comprobarAdjuntosExis('pedido')`, cuyo éxito hace `if (resultado.error)` — y
 * `resultado` es `null`. Causa raíz: la petición va al caso `comprobarAlbaran` de
 * `tareas.php`, que incluye `tareas/comprobarAdjunto.php`; ese fichero solo tiene rama
 * para `$dedonde == 'factura'` o `$dedonde == 'albaran'` — nunca para `'pedido'`
 * (`pedido.php:22` fija `$dedonde = "pedido";` a secas). Con ninguna rama tomada,
 * `$comprobar` queda sin definir y `$respuesta = $comprobar;` la asigna como `null`;
 * `tareas.php` responde el JSON literal `null`, y `$.parseJSON("null")` en el cliente
 * también da `null` — de ahí el `TypeError` al leer `resultado.error`. No detiene el
 * resto del flujo (los pasos siguientes de la pantalla siguen funcionando), pero es un
 * error de JavaScript real, en la consola del navegador, en la acción más común de la
 * pantalla de pedido. Corrección propuesta: añadir la rama `$dedonde == 'pedido'` en
 * `comprobarAdjunto.php` (comprobando qué hay que comprobar para un pedido, si algo), o
 * dejar de llamar a `comprobarAdjuntosExis()` desde la pantalla de pedido si no aplica.
 *
 * El id de cliente es el que deja `support/sembrar-e2e-venta.php` (fijo por nombre, no
 * por identificador: si la base se rehace, hay que sembrar de nuevo y actualizar este
 * número — mismo patrón que los identificadores fijos de `EscenarioComprobacionStock`).
 * Cliente propio (no compartido con los otros dos specs de `pedido-*`): `pedido.php`
 * guarda en servidor un temporal "actual" ligado al cliente, y Playwright corre los
 * ficheros de spec en paralelo por defecto.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const ID_CLIENTE = 923; // '[E2E venta] Cliente defecto comprobar adjuntos'

test.describe('Pedido — defecto: comprobar adjuntos tras seleccionar cliente', () => {
  test('T1 seleccionar cliente lanza un TypeError sin capturar', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedido.php');

    const erroresDePagina = [];
    page.on('pageerror', (error) => erroresDePagina.push(error.message));

    await page.fill('#id_cliente', String(ID_CLIENTE));
    await page.locator('#id_cliente').press('Enter');
    await page.waitForTimeout(1000);

    expect(erroresDePagina.some((m) => m.includes("reading 'error'"))).toBe(true);
  });
});

/**
 * El recorrido completo de guardar un pedido, de pantalla vacía a pedido en el listado.
 *
 * Es el flujo que ata las dos mitades del componente: la pantalla compone un temporal por
 * AJAX y, al pulsar Guardar, el POST entra en la rama que llama a `eliminarPedidoTablas()`
 * y `AddPedidoGuardado()` de la clase, ya verificadas por separado en `Integration/PHP`.
 *
 * Como en el albarán, al crear el temporal el cliente no recarga la página sino que
 * reescribe la URL con `history.pushState` para añadirle `?tActual=<id>`. El formulario
 * postea a la URL actual (`action=""`), así que es ese empujón el que hace que el servidor
 * vea el temporal al recibir el POST.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 935; // '[E2E venta] Cliente pedido guardar'
const NOMBRE_CLIENTE = '[E2E venta] Cliente pedido guardar';
const ID_ARTICULO = 14678;

test.describe('Pedido — recorrido completo de guardado', () => {
  test('T1 componer y guardar deja el pedido en el listado', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedido.php');

    const erroresDePagina = [];
    page.on('pageerror', (error) => erroresDePagina.push(error.message));

    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

    // Anadir la linea dispara `addTemporal()`, que crea el temporal en el servidor.
    await page.fill('#idArticulo', String(ID_ARTICULO));
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
      ),
      page.locator('#idArticulo').press('Enter'),
    ]);

    await expect(page.locator(`#tabla tr:has-text("${ID_ARTICULO}")`).first()).toBeVisible({ timeout: 10000 });

    // El temporal existe: la URL lo lleva y los dos botones aparecen.
    await expect(page).toHaveURL(/tActual=\d+/);
    await expect(page.locator('#Guardar')).toBeVisible();
    await expect(page.locator('#Cancelar')).toBeVisible();

    await page.locator('#Guardar').click();

    // Guardar sin errores redirige al listado.
    await expect(page).toHaveURL(/pedidosListado\.php/, { timeout: 15000 });

    const fila = page.locator('table tbody tr').filter({ hasText: NOMBRE_CLIENTE }).first();
    await expect(fila).toBeVisible();
    await expect(fila).toContainText('Guardado');

    // El recorrido del pedido no llega a completarse sin un error de JavaScript, a
    // diferencia del mismo recorrido sobre el albaran. Al seleccionar cliente, la pantalla
    // pregunta al servidor si hay documentos que adjuntar; el despacho no tiene rama para
    // el pedido, de modo que responde el valor nulo y el navegador lee `error` sobre el.
    // No impide guardar —el pedido queda escrito y aparece en el listado—, pero deja la
    // ejecucion del cliente rota en ese punto y cualquier codigo posterior de esa cadena no
    // se ejecuta. Se documenta aqui como parte del recorrido; el defecto es de la capa
    // compartida y no de este componente.
    expect(erroresDePagina).toEqual(["Cannot read properties of null (reading 'error')"]);
  });
});

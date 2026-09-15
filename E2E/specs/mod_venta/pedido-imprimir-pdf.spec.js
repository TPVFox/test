/**
 * `datosImprimir`: el icono de imprimir del listado llama a `imprimir()`
 * (`funciones.js`), que hace `window.open(resultado)` con la ruta que devuelve
 * `tareas.php`. Verifica el recorrido completo hasta el PDF real, no solo la llamada AJAX
 * ya cubierta en `Integration/PHP/mod_venta/TareasDespachoIntegracionTest.php`.
 *
 * No se comprueba la URL final de la pestaña emergente: Chromium abre un PDF con su
 * visor integrado, que en modo automatizado no siempre expone `page.url()` de forma
 * fiable tras la navegación (`window.open()` dispara la pestaña, pero leer su URL justo
 * despues puede devolver un valor vacio incluso con el PDF ya servido). Se verifica en su
 * lugar, por separado, que la petición HTTP a esa misma ruta devuelve un PDF real.
 *
 * Usa el pedido `Guardado` que deja `support/sembrar-e2e-venta.php` para
 * `albaran-adjuntar-pedido-teclado.spec.js` (cliente 927, Numpedcli=1): cualquier pedido
 * guardado sirve, no hace falta uno propio para esto.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

test.describe('Pedido — imprimir PDF', () => {
  test('T1 el icono de imprimir abre una pestaña nueva con un PDF real', {
    tag: ['@estado-actual', '@pedido', '@listado', '@impreso'],
    annotation: [
      { type: 'Comportamiento', description: 'El icono de imprimir del listado abre una pestaña nueva, y la ruta que devuelve el servidor sirve un PDF real.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/pedidosListado.php');

    const icono = page.locator('a.glyphicon-print').first();
    await expect(icono).toBeVisible({ timeout: 10000 });

    const [respuestaAjax, popup] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('datosImprimir')),
      page.context().waitForEvent('page'),
      icono.click(),
    ]);

    const rutaPdf = JSON.parse(await respuestaAjax.text());
    expect(rutaPdf).toMatch(/ventas\.pdf$/);

    const descarga = await page.request.get(new URL(rutaPdf, page.url()).toString());
    expect(descarga.ok()).toBeTruthy();
    expect(descarga.headers()['content-type']).toContain('application/pdf');
    const cuerpo = await descarga.body();
    expect(cuerpo.subarray(0, 4).toString('latin1')).toBe('%PDF');

    await popup.close();
  });
});

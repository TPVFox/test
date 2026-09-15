/**
 * El recorrido completo de emitir una factura, de pantalla vacía a factura en el listado.
 *
 * Es el flujo que ata las dos mitades del componente: la pantalla compone un temporal por
 * AJAX incorporando un albarán guardado y, al pulsar Guardar, el POST entra en la rama que
 * llama a `eliminarFacturasTablas()` y `AddFacturaGuardado()` de la clase, ya verificadas
 * por separado en `Integration/PHP`.
 *
 * El albarán de este recorrido tiene su número igualado a su identificador a propósito: sin
 * esa coincidencia, la clave foránea de `albclifac` rechaza el enlace entre la factura y el
 * albarán que factura, y el recorrido no llegaría a guardar.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');

const ID_CLIENTE = 939; // '[E2E venta] Cliente factura guardar'
const NOMBRE_CLIENTE = '[E2E venta] Cliente factura guardar';

test.describe('Factura — recorrido completo de emisión', () => {
  test('T1 incorporar un albarán y guardar deja la factura en el listado', {
    tag: ['@estado-actual', '@factura', '@albaran', '@guardado', '@adjuntos'],
    annotation: [
      { type: 'Comportamiento', description: 'Incorporar un albarán guardado a una factura nueva y guardarla la deja en el listado como «Guardado».' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/factura.php');

    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#numAdjunto')).toBeVisible({ timeout: 10000 });

    // El número del albarán del cliente, leído de su propio listado, no escrito en el spec.
    const numeroAlbaran = await page.evaluate(async () => {
      const respuesta = await fetch('tareas.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ pulsado: 'buscarAdjunto', busqueda: '', idCliente: '939', dedonde: 'factura' }),
      });
      const datos = JSON.parse(await respuesta.text());
      return JSON.parse(datos.res).datos[0].NumalbCli;
    });

    await page.fill('#numAdjunto', String(numeroAlbaran));
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
      ),
      page.locator('#numAdjunto').press('Enter'),
    ]);

    // El albarán trae sus líneas y el temporal existe: la URL lo lleva y aparecen los botones.
    await expect(page.locator('#tabla tr[id^="Row"]:not(#Row0)').first()).toBeVisible({ timeout: 10000 });
    await expect(page).toHaveURL(/tActual=\d+/);
    await expect(page.locator('#Guardar')).toBeVisible();
    await expect(page.locator('#Cancelar')).toBeVisible();

    await page.locator('#Guardar').click();

    // Guardar sin errores redirige al listado.
    await expect(page).toHaveURL(/facturasListado\.php/, { timeout: 15000 });

    const fila = page.locator('table.table-bordered tbody tr').filter({ hasText: NOMBRE_CLIENTE }).first();
    await expect(fila).toBeVisible();
    await expect(fila).toContainText('Guardado');
  });

  /**
   * La factura recién emitida se numera con el identificador que la tabla acaba de asignar,
   * no con el siguiente de la serie. En un listado con facturas anteriores el salto es
   * visible: conviven números de dos órdenes de magnitud distintos, y el más alto no es el
   * más reciente por serie sino por posición en la tabla.
   */
  test('T2 el número de la factura emitida es su identificador, no el siguiente de la serie', {
    tag: ['@estado-actual', '@defecto', '@factura', '@guardado', '@numeracion', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'La factura recién emitida se numera con el identificador que la tabla acaba de asignar, no con el siguiente número de la serie.' },
      { type: 'Qué debería ocurrir', description: 'Que cada factura emitida lleve el número siguiente de su serie.' },
      { type: 'Por qué ocurre', description: 'El guardado copia el identificador de la fila en el número de la factura: no hay serie propia.' },
      { type: 'Cómo debería funcionar', description: 'Llevar una serie de numeración propia, independiente del identificador, y migrar lo ya emitido.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const fila = page.locator('table.table-bordered tbody tr').filter({ hasText: NOMBRE_CLIENTE }).first();
    await expect(fila).toBeVisible();

    const numeroEnElListado = (await fila.locator('td').nth(3).innerText()).trim();
    const enlace = await fila.locator('a[title="Ver factura"]').getAttribute('href');
    const idEnLaUrl = new URL(enlace, 'http://x/').searchParams.get('id');

    expect(numeroEnElListado).toBe(idEnLaUrl);
  });
});

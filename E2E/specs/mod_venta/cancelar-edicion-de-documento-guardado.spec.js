/**
 * Cancelar la edición de un albarán o de un pedido ya guardado.
 *
 * Al primer cambio sobre un documento guardado, la pantalla crea su borrador y pide pasar el
 * documento a «Sin guardar». Cancelar borra el borrador y vuelve al listado, pero no devuelve el
 * documento a su estado: se queda en «Sin guardar» sin borrador ninguno. La factura solo ofrece
 * para incorporar albaranes en «Guardado», y el albarán solo pedidos en «Guardado», de modo que el
 * documento sale del circuito sin que nada lo diga. Que dejen de ofrecerse lo demuestra
 * `EstadoDelDocumentoEnEdicionIntegracionTest`; aquí se recorre la pantalla.
 *
 * El estado se lee de la base con `support/leer-traza-de-documento.php`: el listado no lo
 * enseña para el documento ya guardado.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const ID_ARTICULO = 14679; // '[E2E venta] Manzana Reineta'

function traza(documento, idCliente) {
  const guion = path.resolve(__dirname, '../../../support/leer-traza-de-documento.php');

  return JSON.parse(execFileSync('php', [guion, documento, String(idCliente)], { encoding: 'utf-8' }));
}

for (const caso of [
  { documento: 'albaran', elDocumento: 'el albarán', delDocumento: 'del albarán', pantalla: 'albaran.php', listado: 'albaranesListado.php', idCliente: 982,
    consecuencia: 'deja de ofrecerse al facturar al cliente' },
  { documento: 'pedido', elDocumento: 'el pedido', delDocumento: 'del pedido', pantalla: 'pedido.php', listado: 'pedidosListado.php', idCliente: 983,
    consecuencia: 'deja de ofrecerse al hacer el albarán del cliente' },
]) {
  test.describe(`Venta — cancelar la edición ${caso.delDocumento} guardado`, () => {
    test(`T1 cancelar deja ${caso.elDocumento} en «Sin guardar», sin borrador`, {
      tag: ['@estado-actual', '@defecto', `@${caso.documento}`, '@borrador', '@estados', '@alto'],
      annotation: [
        { type: 'Qué ocurre hoy', description: `Abrir ${caso.elDocumento} guardado para editarlo, cambiar algo y cancelar lo deja en «Sin guardar», y desde ese momento ${caso.consecuencia}.` },
        { type: 'Qué debería ocurrir', description: `Que cancelar deje ${caso.elDocumento} como estaba antes de editarlo.` },
        { type: 'Por qué ocurre', description: 'El estado lo cambia el navegador al primer cambio, y la cancelación solo borra el borrador: ninguna de las dos guarda ni restituye el estado anterior.' },
        { type: 'Cómo debería funcionar', description: 'Que la cancelación devuelva el documento a su estado anterior, o que el documento real no cambie de estado mientras la edición no se guarde.' },
      ],
    }, async ({ page }) => {
      test.setTimeout(60000);

      const antes = traza(caso.documento, caso.idCliente);
      expect(antes.documento, `La siembra tiene que dejar ${caso.elDocumento}`).not.toBeNull();
      expect(antes.documento.estado).toBe('Guardado');

      await iniciarSesion(page, `modulos/mod_venta/${caso.pantalla}?id=${antes.documento.id}&accion=editar`);
      await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

      await page.fill('#idArticulo', String(ID_ARTICULO));
      await Promise.all([
        page.waitForResponse(
          (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('modificarEstadoDocumento')
        ),
        page.locator('#idArticulo').press('Enter'),
      ]);
      await expect(page).toHaveURL(/tActual=\d+/);
      expect(traza(caso.documento, caso.idCliente).documento.estado, 'Al primer cambio pasa a «Sin guardar»').toBe('Sin guardar');

      page.on('dialog', (dialogo) => dialogo.accept());
      await page.locator('#Cancelar').click();
      await expect(page).toHaveURL(new RegExp(caso.listado.replace('.', '\\.')), { timeout: 15000 });

      const despues = traza(caso.documento, caso.idCliente);
      expect(despues.borradores, 'El borrador ya no existe...').toBe(0);
      expect(despues.documento.lineas, '... el documento no ha cambiado...').toBe(1);
      expect(despues.documento.estado, '... pero se queda en «Sin guardar»').toBe('Sin guardar');
    });
  });
}

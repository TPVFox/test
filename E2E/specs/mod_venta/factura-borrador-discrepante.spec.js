/**
 * El aviso grave de la vista de factura cuando su borrador y la factura no concuerdan.
 *
 * Al abrir un borrador atado a una factura, `factura.php` comprueba que la factura esté en
 * «Sin guardar» y, si no lo está, emite un aviso grave. Cualquier aviso grave oculta los
 * botones de Guardar y Cancelar, sea cual sea su motivo, de modo que el borrador queda sin
 * salida desde su pantalla.
 *
 * La comprobación se puede cumplir: el navegador marca la factura como «Sin guardar» en
 * cuanto se crea el borrador desde su edición, y con esa marca no hay aviso. La discrepancia
 * aparece cuando el borrador sigue vivo y la factura deja de estar marcada, que es lo que
 * ocurre cuando falla la reescritura o cuando otro documento con el mismo identificador
 * cambia de estado (`factura-borrador-salidas.spec.js`).
 *
 * T1 parte de un borrador sembrado sin esa marca, que reproduce el estado en que esos dos
 * caminos dejan la factura. T2 contrasta ese estado con el que deja el flujo de edición.
 */

const { test } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const {
  comprobarBorradorSinAviso,
  comprobarBorradorSinSalida,
  crearBorradorAnadiendoProducto,
} = require('../../fixtures/borradorFactura');

const NOMBRE_CLIENTE = '[E2E venta] Cliente factura entradas';
const FACTURA_CONTRASTE = 810011; // support/sembrar-e2e-venta.php

/** El borrador sembrado del cliente de entradas, atado a una factura que sigue en «Guardado». */
async function idDelBorradorSembrado(page) {
  await page.goto('modulos/mod_venta/facturasListado.php');
  const enlace = await page
    .locator('table.table-striped tbody tr')
    .filter({ hasText: NOMBRE_CLIENTE })
    .first()
    .locator('a[href*="tActual="]')
    .getAttribute('href');

  return new URL(enlace, 'http://x/').searchParams.get('tActual');
}

test.describe('Factura — borrador que no concuerda con su factura', () => {
  test('T1 abrir el borrador avisa de estado discrepante y deja la pantalla sin botones', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    await comprobarBorradorSinSalida(page, await idDelBorradorSembrado(page));
  });

  /**
   * El aviso no depende de que el borrador tenga factura, sino del estado en que esté: sin la
   * marca del navegador aparece, y con ella no.
   */
  test('T2 el aviso depende del estado de la factura: con la marca del navegador no aparece', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    await comprobarBorradorSinSalida(page, await idDelBorradorSembrado(page));

    const idTemporal = await crearBorradorAnadiendoProducto(page, FACTURA_CONTRASTE);
    await comprobarBorradorSinAviso(page, idTemporal);
  });
});

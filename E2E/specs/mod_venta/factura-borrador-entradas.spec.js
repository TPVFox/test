/**
 * Las cuatro formas de empezar a modificar una factura ya emitida, y lo que todas dejan igual.
 *
 * Cualquier cambio sobre una factura emitida crea su borrador y, al crearlo, el navegador
 * marca la factura como «Sin guardar». La marca la pide el navegador, no el servidor, y la
 * vuelve a pedir en cada cambio posterior. Con la factura así marcada, el borrador se abre
 * sin ningún aviso y deja guardar y cancelar.
 *
 * Los cuatro caminos se recorren por separado porque cada uno llega al borrador con su
 * propia condición previa: añadir un producto solo necesita la fila de entrada; incorporar un
 * albarán necesita uno disponible del mismo cliente; tocar una línea exige que sea editable
 * —las que proceden de un albarán no lo son—; y cambiar la fecha solo pide el borrador en
 * edición y con cliente.
 *
 * Cada recorrido usa su propia factura, con identificador fijo, y la deja marcada y con
 * borrador: la siembra rehace el escenario antes de cada ejecución.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const {
  abrirFacturaParaEditar,
  comprobarBorradorSinAviso,
  crearBorradorAnadiendoProducto,
  esperarBorradorMarcado,
  estadoDeLaFactura,
} = require('../../fixtures/borradorFactura');

// Identificadores fijos: support/sembrar-e2e-venta.php
const FACTURA_PRODUCTO = 810001;
const FACTURA_ALBARAN = 810002;
const ALBARAN_INCORPORABLE = 820944;
const FACTURA_LINEA = 810003;
const FACTURA_FECHA = 810004;

test.describe('Factura emitida — cómo nace su borrador', () => {
  test('T1 añadir un producto crea el borrador y marca la factura', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const idTemporal = await crearBorradorAnadiendoProducto(page, FACTURA_PRODUCTO);

    await comprobarBorradorSinAviso(page, idTemporal);
    expect(await estadoDeLaFactura(page, FACTURA_PRODUCTO)).toBe('Sin guardar');
  });

  test('T2 incorporar un albarán crea el borrador y marca la factura', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    await abrirFacturaParaEditar(page, FACTURA_ALBARAN);

    await page.fill('#numAdjunto', String(ALBARAN_INCORPORABLE));
    const idTemporal = await esperarBorradorMarcado(page, () => page.locator('#numAdjunto').press('Enter'));

    await comprobarBorradorSinAviso(page, idTemporal);
    expect(await estadoDeLaFactura(page, FACTURA_ALBARAN)).toBe('Sin guardar');
  });

  /**
   * Las líneas que proceden de un albarán se muestran bloqueadas en edición; esta factura se
   * siembra con su línea como directa para que se pueda tocar.
   */
  test('T3 retirar una línea existente crea el borrador y marca la factura', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    await abrirFacturaParaEditar(page, FACTURA_LINEA);

    const fila = page.locator('#tabla tr[id^="Row"]:not(#Row0)').first();
    const idTemporal = await esperarBorradorMarcado(page, () => fila.locator('.eliminar a').click());

    await comprobarBorradorSinAviso(page, idTemporal);
    expect(await estadoDeLaFactura(page, FACTURA_LINEA)).toBe('Sin guardar');
  });

  /**
   * Cambiar la fecha tiene su propia guarda en el navegador: solo pide el borrador si la
   * pantalla está en edición y la factura tiene cliente.
   */
  test('T4 cambiar la fecha crea el borrador y marca la factura', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    await abrirFacturaParaEditar(page, FACTURA_FECHA);

    await page.fill('#fecha', '01-01-2026');
    const idTemporal = await esperarBorradorMarcado(page, () => page.locator('#fecha').press('Enter'));

    await comprobarBorradorSinAviso(page, idTemporal);
    expect(await estadoDeLaFactura(page, FACTURA_FECHA)).toBe('Sin guardar');
  });
});

/**
 * Los tipos de IVA, las formas de pago y los tipos de vencimiento en la pantalla de tablas
 * principales, en el navegador: la pantalla y la ventana con que se edita o se añade una fila.
 *
 * Los recorridos solo leen: abren la ventana y no la guardan, para no dejar filas en la base de los
 * recorridos. El guardado se prueba en la integración, dentro de una transacción.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const VISTA = 'modulos/mod_configuracion/tablasPrincipales.php';

test.describe('Tablas principales — IVA, formas de pago y vencimientos', () => {
  test('T1 la pantalla enseña las tres tablas con su botón de añadir', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'La pantalla de tablas principales enseña la tabla de IVA, la de formas de pago y la de tipos de vencimiento, cada una con su botón de añadir.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);

    await expect(page.getByRole('heading', { name: 'Tablas Principales de la BD' })).toBeVisible();
    for (const [titulo, tabla] of [['Tabla IVAS', 'iva'], ['Formas de Pago', 'forma'], ['Tipos de Vencimiento', 'vencimiento']]) {
      await expect(page.getByRole('heading', { name: titulo })).toBeVisible();
      await expect(page.locator(`a[onclick="abrirmodal(0, '${tabla}')"]`)).toHaveCount(1);
    }
  });

  test('T2 el lápiz de un tipo de IVA abre su ventana con sus datos', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'Pulsar el lápiz de un tipo de IVA abre la ventana «Modificar Elemento de tabla iva» con su número, su descripción, su porcentaje y su recargo.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);

    await page.locator("a.glyphicon-pencil[onclick$=\", 'iva')\"]").first().click();

    const ventana = page.locator('#ventanaModal');
    await expect(ventana).toBeVisible();
    await expect(ventana.locator('.modal-title')).toHaveText('Modificar Elemento de tabla iva');
    await expect(ventana.locator('#id')).not.toHaveValue('');
    await expect(ventana.locator('#iva')).not.toHaveValue('');
  });

  test('T3 el botón de añadir abre la ventana vacía', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'Pulsar el botón de añadir de las formas de pago abre la ventana con la descripción vacía y sin número.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, VISTA);

    await page.locator("a[onclick=\"abrirmodal(0, 'forma')\"]").click();

    const ventana = page.locator('#ventanaModal');
    await expect(ventana).toBeVisible();
    await expect(ventana.locator('.modal-title')).toHaveText('Modificar Elemento de tabla forma');
    await expect(ventana.locator('#id')).toHaveValue('');
    await expect(ventana.locator('#descripcion')).toHaveValue('');
  });
});

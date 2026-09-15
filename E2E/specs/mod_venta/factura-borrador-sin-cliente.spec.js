/**
 * Un borrador de factura sin cliente se compone y no se puede emitir.
 *
 * El servidor escribe el borrador con el cliente que reciba, sin comprobar que sea alguno:
 * el único control es de navegador. Con cliente cero el borrador queda escrito, el listado
 * lo muestra con la columna de cliente vacía, y su pantalla lo abre con normalidad y ofrece
 * el botón de Guardar. Lo que hay detrás de ese botón no puede terminar: `facclit` tiene
 * clave foránea contra el cliente y rechaza la escritura.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const TOTAL_RECONOCIBLE = '12,345.67'; // support/sembrar-e2e-venta.php

test.describe('Factura — borrador sin cliente', () => {
  test('T1 el borrador está en el listado sin cliente y la pantalla ofrece guardarlo igual', {
    tag: ['@estado-actual', '@defecto', '@factura', '@borrador', '@validacion', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Un borrador de factura sin cliente aparece en el listado con la columna de cliente vacía, se abre con normalidad y ofrece Guardar, aunque la base va a rechazar la factura.' },
      { type: 'Qué debería ocurrir', description: 'Que no pueda existir un borrador sin cliente, o que la pantalla no ofrezca emitirlo.' },
      { type: 'Por qué ocurre', description: 'El servidor escribe el borrador con el cliente que reciba sin comprobar que sea alguno; el único control está en el navegador.' },
      { type: 'Cómo debería funcionar', description: 'Comprobar el cliente en el servidor antes de escribir el borrador y antes de ofrecer la emisión.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const fila = page
      .locator('table.table-striped tbody tr')
      .filter({ hasText: TOTAL_RECONOCIBLE })
      .first();
    await expect(fila).toBeVisible();

    // La columna de cliente sale vacía: el borrador no tiene ninguno.
    await expect(fila.locator('td').nth(2)).toHaveText('');

    await fila.locator('a[href*="tActual="]').click();

    // La pantalla lo abre como cualquier otro borrador, con los campos de cliente vacíos...
    await expect(page.locator('#id_cliente')).toHaveValue('');
    await expect(page.locator('#Cliente')).toHaveValue('');

    // ... y ofreciendo emitir la factura, que es lo que la base no va a aceptar.
    await expect(page.locator('#Guardar')).toBeVisible();
  });
});

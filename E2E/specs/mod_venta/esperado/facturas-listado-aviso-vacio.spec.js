/**
 * Comportamiento esperado: el aviso de listado vacío de facturas debe hablar de facturas.
 *
 * Síntoma: al buscar algo que no existe, el listado de facturas avisa «No tienes albaranes
 * guardados!». Causa raíz: `facturasListado.php` se escribió copiando `albaranesListado.php`
 * y ese literal no se adaptó —el de pedidos sí dice «pedidos», de modo que la factura es la
 * única de las tres pantallas con el texto de otro documento—. Corrección: una palabra.
 *
 * Este recorrido afirma el comportamiento correcto y por eso hoy falla: está declarado con
 * `test.fail()`. El día que el cambio se aplique, Playwright avisará de que pasa cuando no
 * debería, y ahí se retira la marca y queda como guardia de regresión.
 *
 * El «antes» sigue documentado, sin tocar, en `facturas-listado-flujo.spec.js` T5.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');

/** Un término que no casa con ningún cliente ni número: deja el listado sin resultados. */
const BUSQUEDA_SIN_RESULTADOS = 'zzz-no-existe-zzz';

test.describe('Facturas — aviso de listado vacío', () => {
  test.fail('T1 el aviso de listado vacío nombra facturas, no albaranes', {
    tag: ['@esperado', '@factura', '@listado', '@directo', '@bajo'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Buscando algo que no existe, el aviso de listado vacío de facturas dice «No tienes albaranes guardados!».' },
      { type: 'Qué debería ocurrir', description: 'Que el aviso hable de facturas.' },
      { type: 'Por qué ocurre', description: 'La pantalla se escribió copiando la del albarán y ese texto no se adaptó.' },
      { type: 'Cómo debería funcionar', description: 'Cambiar la palabra del aviso.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    await page.fill('form[name="formBuscar"] input[name="buscar"]', BUSQUEDA_SIN_RESULTADOS);
    await Promise.all([
      page.waitForURL(/buscar=/),
      page.locator('form[name="formBuscar"] input[type="submit"]').click(),
    ]);

    const aviso = page.locator('.alert-warning');
    await expect(aviso).toBeVisible();

    // Timeout corto a propósito: el aviso ya está pintado cuando se llega aquí, así que
    // reintentar no lo va a cambiar. Mientras el defecto viva, esto falla de inmediato en
    // vez de agotar los cinco segundos por defecto en cada pasada.
    await expect(aviso).toContainText('facturas', { timeout: 2000 });
    await expect(aviso).not.toContainText('albaranes', { timeout: 2000 });
  });
});

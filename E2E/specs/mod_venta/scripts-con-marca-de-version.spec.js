/**
 * Las seis pantallas de `mod_venta` cargan su JavaScript con una marca de versión.
 *
 * Sin marca, el navegador sirve de su caché el fichero anterior tras un despliegue, de modo
 * que una corrección que viva en `funciones.js` o en `js/AccionesDirectas.js` puede no llegar
 * al puesto de caja. La marca es la fecha de modificación del fichero: cambia sola cuando el
 * fichero cambia, sin que nadie tenga que acordarse de subir un número.
 *
 * Lo que no se puede recorrer aquí es el síntoma —el navegador sirviendo el fichero viejo
 * entre dos despliegues—; lo que sí, que la marca está y es la del fichero.
 */

const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const MODULO = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_venta');
const PANTALLAS = ['pedidosListado.php', 'pedido.php', 'albaranesListado.php', 'albaran.php', 'facturasListado.php', 'factura.php'];
const SCRIPTS = ['funciones.js', 'js/AccionesDirectas.js'];

test.describe('Venta — los scripts del módulo llevan marca de versión', () => {
  for (const pantalla of PANTALLAS) {
    test(`${pantalla} carga sus dos scripts con la fecha de modificación de cada fichero`, {
      tag: ['@estado-actual', '@entrada'],
      annotation: [
        { type: 'Comportamiento', description: 'La pantalla carga el JavaScript del módulo con una marca de versión que es la fecha de modificación del fichero, de modo que el navegador pide el fichero nuevo tras cada cambio.' },
      ],
    }, async ({ page }) => {
      await iniciarSesion(page, `modulos/mod_venta/${pantalla}`);

      for (const script of SCRIPTS) {
        const etiqueta = page.locator(`script[src*="/modulos/mod_venta/${script}"]`);
        await expect(etiqueta, `${pantalla} tiene que cargar ${script} una vez`).toHaveCount(1);

        const marca = new URL(await etiqueta.getAttribute('src'), 'http://x/').searchParams.get('v');
        const esperada = String(Math.floor(fs.statSync(path.join(MODULO, script)).mtimeMs / 1000));

        expect(marca, `${script} en ${pantalla}`).toBe(esperada);
      }
    });
  }
});

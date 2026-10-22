/**
 * Lo que debería pasar cuando una pantalla con enlace «Volver» se abre con un parámetro que lleva un
 * script.
 *
 * El enlace «Volver» de las pantallas lo compone el controlador común, que reescribe la dirección
 * actual en el historial con un script en el que pega los parámetros tal cual. El recorrido abre la
 * ficha de un artículo sembrado con un parámetro que, si se ejecutara, dejaría una marca en la página.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');

const ID_ARTICULO = 14678; // '[E2E venta] Manzana Golden'

test.describe('Núcleo — enlace Volver', () => {
  test('T1 un parámetro con un script no se ejecuta en una pantalla con Volver', {
    tag: ['@esperado', '@pantalla', '@forzado', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Abrir la ficha de un artículo con un parámetro que lleva una etiqueta de script ejecuta ese script en la página, con la sesión de quien la abre.' },
      { type: 'Qué debería ocurrir', description: 'Que el parámetro se trate como texto y no se ejecute.' },
      { type: 'Por qué ocurre', description: 'El enlace Volver reescribe la dirección actual con un script en el que pega los parámetros sin escaparlos.' },
      { type: 'Cómo debería funcionar', description: 'Componer la dirección con http_build_query() y escaparla antes de insertarla en el script.' },
    ],
  }, async ({ page }) => {
    const parametro = encodeURIComponent('</script><script>window.marcaInyectada = 1</script>');

    await iniciarSesion(page, `modulos/mod_producto/producto.php?id=${ID_ARTICULO}&origen=${parametro}`);

    expect(await page.evaluate(() => window.marcaInyectada), 'El parámetro no puede ejecutarse').toBeUndefined();
  });
});

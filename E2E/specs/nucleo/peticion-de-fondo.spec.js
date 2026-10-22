/**
 * Una petición de fondo con la sesión abierta: llega al `tareas.php` de su módulo y se atiende con el
 * usuario de la sesión.
 *
 * Es la pareja del recorrido que pide la misma lectura sin sesión: aquí la sesión existe y la
 * respuesta trae los datos. El artículo lo deja la siembra de los recorridos de venta.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const ARTICULO_SEMBRADO = '[E2E venta] Manzana Golden';

test.describe('Núcleo — petición de fondo', () => {
  test('T1 con la sesión abierta, la petición de fondo devuelve los datos pedidos', {
    tag: ['@estado-actual'],
    annotation: [
      { type: 'Comportamiento', description: 'Con la sesión abierta, una petición a las tareas del módulo de clientes devuelve los artículos que coinciden con la búsqueda.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'index.php');

    const respuesta = await page.request.post('modulos/mod_cliente/tareas.php', {
      form: { pulsado: 'leerArticulo', caja: 'Descripcion', valor: 'Manzana Golden' },
    });

    const cuerpo = await respuesta.json();
    const nombres = (cuerpo.datos || []).map((articulo) => articulo.articulo_name);
    expect(nombres).toContain(ARTICULO_SEMBRADO);
  });
});

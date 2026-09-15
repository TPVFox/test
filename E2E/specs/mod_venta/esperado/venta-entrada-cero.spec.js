/**
 * Comportamiento esperado: el cero es un valor legítimo en cantidad y en precio.
 *
 * Síntoma: teclear `0` en la cantidad de una línea abre un aviso del navegador —«No es correcto
 * el numero»— y el campo vuelve solo a `1`. En el precio ocurre lo mismo y el campo vuelve al
 * valor anterior. El operador no puede registrar una línea de obsequio, una corrección a cero ni
 * un artículo de precio cero sin pelearse con la pantalla.
 *
 * Causa raíz: la validación numérica del navegador rechaza toda cadena cuyo carácter en esa
 * posición sea `0` salvo que le siga un punto decimal, de modo que `0` no pasa pero `0.0` sí. La
 * expresión regular que hay debajo sí acepta el cero: el rechazo lo añade la comprobación previa.
 *
 * Corrección esperada: que el cero pase la validación, como ya pasa `0.0`.
 *
 * Declarado con `test.fail()`. No hay «antes» en la suite E2E: el defecto solo estaba cubierto
 * por pruebas unitarias de JavaScript.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');

const ID_CLIENTE = 921; // '[E2E venta] Cliente teclado'
const ID_ARTICULO = 14678; // '[E2E venta] Manzana Golden'

/** Abre un pedido nuevo del cliente y le añade una línea con el artículo de siempre. */
async function pedidoConUnaLinea(page) {
  await iniciarSesion(page, 'modulos/mod_venta/pedido.php');
  await seleccionarCliente(page, ID_CLIENTE);

  await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });
  await page.fill('#idArticulo', String(ID_ARTICULO));
  await page.locator('#idArticulo').press('Enter');

  await expect(page.locator('#Unidad_Fila_1')).toBeVisible({ timeout: 10000 });
}

test.describe('Venta — el cero como valor de entrada', () => {

  test.fail('T1 teclear cantidad 0 deja el cero, sin aviso ni reposición', {
    tag: ['@esperado', '@pedido', '@entrada', '@validacion', '@directo', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Teclear 0 en la cantidad de una línea y pulsar Intro abre el aviso «No es correcto el numero» y el campo vuelve a 1.' },
      { type: 'Qué debería ocurrir', description: 'Que el cero se acepte: es legítimo para una línea de obsequio o una corrección.' },
      { type: 'Por qué ocurre', description: 'La validación numérica del navegador rechaza toda cadena que empiece por 0 salvo que le siga un punto decimal: 0 no pasa y 0.0 sí.' },
      { type: 'Cómo debería funcionar', description: 'Que el cero pase la validación, como ya pasa 0.0.' },
    ],
  }, async ({ page }) => {
    await pedidoConUnaLinea(page);

    // Si la validación rechaza el cero, el navegador abre un alert. Se recogen en vez de
    // aceptarlos automáticamente: que no aparezca ninguno es parte del criterio.
    const avisos = [];
    page.on('dialog', (dialogo) => {
      avisos.push(dialogo.message());
      dialogo.accept();
    });

    // Con Intro, no con blur: la configuración de teclado de este campo solo tiene acción
    // para Intro y las flechas, de modo que salir del campo no dispara la validación.
    await page.fill('#Unidad_Fila_1', '0');
    await page.locator('#Unidad_Fila_1').press('Enter');

    expect(avisos, 'El cero no debería disparar ningún aviso').toHaveLength(0);
    await expect(page.locator('#Unidad_Fila_1')).toHaveValue('0', { timeout: 2000 });
  });

  test.fail('T2 teclear precio 0 deja el cero, sin aviso ni reposición', {
    tag: ['@esperado', '@pedido', '@entrada', '@validacion', '@directo', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Teclear 0 en el precio de una línea y pulsar Intro abre el mismo aviso y el campo vuelve al precio anterior.' },
      { type: 'Qué debería ocurrir', description: 'Que el precio cero se acepte.' },
      { type: 'Por qué ocurre', description: 'La validación numérica del navegador rechaza toda cadena que empiece por 0 salvo que le siga un punto decimal: 0 no pasa y 0.0 sí.' },
      { type: 'Cómo debería funcionar', description: 'Que el cero pase la validación, como ya pasa 0.0.' },
    ],
  }, async ({ page }) => {
    await pedidoConUnaLinea(page);

    const avisos = [];
    page.on('dialog', (dialogo) => {
      avisos.push(dialogo.message());
      dialogo.accept();
    });

    await page.fill('#precioCiva_Fila_1', '0');
    await page.locator('#precioCiva_Fila_1').press('Enter');

    expect(avisos, 'El cero no debería disparar ningún aviso').toHaveLength(0);
    await expect(page.locator('#precioCiva_Fila_1')).toHaveValue('0', { timeout: 2000 });
  });
});

/**
 * Comportamiento esperado: cambiar el estado de un documento no toca a los de otro tipo.
 *
 * Síntoma: incorporar un albarán a otro documento marca ese albarán como procesado —correcto—
 * y, de paso, aplica el mismo cambio al pedido y a la factura cuyo identificador coincida por
 * casualidad con el número de ese albarán. Documentos de otro tipo, de otro cliente y de otro
 * flujo quedan alterados en la misma llamada, sin que nada lo señale.
 *
 * Causa raíz: las tres condiciones que distinguen el tipo de documento en el despacho de
 * cambio de estado usan asignación en vez de comparación. Una asignación siempre es cierta, de
 * modo que las tres ramas se ejecutan en toda petición, sea cual sea el tipo que se pidió.
 *
 * Corrección esperada: comparar en vez de asignar, en las tres. Y revisar el resto del
 * despacho por si el patrón se repite.
 *
 * Tres caracteres bastan para corregirlo, y el defecto alcanza a los tres documentos de venta.
 *
 * Declarado con `test.fail()`. El «antes» queda documentado, sin tocar, en
 * `factura-borrador-salidas.spec.js` T6, que afirma el estado corrompido tal como es hoy.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');
const { esperarTarea, estadoDeLaFactura } = require('../../../fixtures/borradorFactura');

// Sembrados en support/sembrar-e2e-venta.php: la factura lleva este identificador y el
// albarán del mismo cliente lleva ese mismo valor como número visible.
const FACTURA_AJENA = 810012;
const ID_CLIENTE = 962; // '[E2E venta] Esperado estado cruzado'

test.describe('Venta — el cambio de estado no se contagia entre tipos de documento', () => {
  test.fail('T1 incorporar un albarán no cambia el estado de la factura con ese identificador', {
    tag: ['@esperado', '@factura', '@albaran', '@estados', '@directo', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Incorporar a una factura nueva un albarán cuyo número coincide con el identificador de otra factura deja esa otra factura en «Procesado».' },
      { type: 'Qué debería ocurrir', description: 'Que la otra factura siga «Guardado»: no participa en la operación.' },
      { type: 'Por qué ocurre', description: 'El despacho de cambio de estado distingue el tipo de documento con asignación en vez de comparación, así que aplica el cambio a pedido, albarán y factura con ese identificador a la vez.' },
      { type: 'Cómo debería funcionar', description: 'Comparar en vez de asignar en las tres condiciones del despacho.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const estadoInicial = await estadoDeLaFactura(page, FACTURA_AJENA);
    expect(estadoInicial, 'La siembra deja esta factura guardada').toBe('Guardado');

    // Se compone una factura nueva y se le incorpora el albarán por su número, que es la vía
    // normal del operador. Esa acción pide marcar el albarán como procesado.
    await page.goto('modulos/mod_venta/factura.php');
    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#numAdjunto')).toBeVisible({ timeout: 10000 });

    await page.fill('#numAdjunto', String(FACTURA_AJENA));
    await Promise.all([
      esperarTarea(page, 'modificarEstadoDocumento', 'Procesado'),
      page.locator('#numAdjunto').press('Enter'),
    ]);

    // La factura que comparte identificador con el número del albarán no participa en esta
    // operación y tiene que seguir como estaba.
    expect(await estadoDeLaFactura(page, FACTURA_AJENA)).toBe('Guardado');
  });
});

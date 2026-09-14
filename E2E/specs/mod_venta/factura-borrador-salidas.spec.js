/**
 * Las formas en que termina el borrador de una factura ya emitida, y lo que deja cada una.
 *
 * El borrador nace marcando la factura como «Sin guardar» (`factura-borrador-entradas.spec.js`).
 * Lo que ocurre después depende de cómo se salga de él:
 *
 * - guardar con éxito devuelve la factura a «Guardado» y borra el borrador;
 * - cancelar borra el borrador y no retira la marca;
 * - abandonar la pantalla deja borrador y marca, y el borrador se retoma sin problema;
 * - si la reescritura falla, la página responde 500 con la factura ya reescrita en «Guardado»
 *   y el borrador todavía atado a ella, que ya no se puede cerrar;
 * - una fecha imposible pasa el filtro del campo y llega al servidor, que en vez de guardar
 *   crea un segundo borrador, avisa como error grave y deja la pantalla a medio pintar;
 * - si otro documento con el mismo identificador cambia de estado mientras el borrador sigue
 *   vivo, la factura pierde la marca y el borrador tampoco se puede cerrar.
 *
 * Cada recorrido usa su propia factura, con identificador fijo, y la deja alterada: la
 * siembra rehace el escenario antes de cada ejecución.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../fixtures/seleccionarCliente');
const {
  borradoresDeLaFactura,
  comprobarBorradorSinAviso,
  comprobarBorradorSinSalida,
  crearBorradorAnadiendoProducto,
  esperarTarea,
  estadoDeLaFactura,
} = require('../../fixtures/borradorFactura');

// Identificadores fijos: support/sembrar-e2e-venta.php
const FACTURA_GUARDAR = 810005;
const FACTURA_CANCELAR = 810006;
const FACTURA_ABANDONA = 810007;
const FACTURA_FALLO = 810008;
const FACTURA_FECHA_IMPOSIBLE = 810009;
const FACTURA_ESTADO = 810010;
const CLIENTE_OTRO_DOCUMENTO = 953;

/** Envía el formulario con Guardar y devuelve la respuesta del servidor a ese envío. */
async function pulsarGuardar(page) {
  const [respuesta] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('factura.php') && r.request().method() === 'POST'),
    page.locator('#Guardar').click(),
  ]);

  return respuesta;
}

test.describe('Factura emitida — cómo termina su borrador', () => {
  test('T1 guardar devuelve la factura a guardada y borra el borrador', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    await crearBorradorAnadiendoProducto(page, FACTURA_GUARDAR);

    await Promise.all([
      page.waitForURL(/facturasListado\.php/, { timeout: 15000 }),
      page.locator('#Guardar').click(),
    ]);

    expect(await borradoresDeLaFactura(page, FACTURA_GUARDAR)).toHaveLength(0);
    expect(await estadoDeLaFactura(page, FACTURA_GUARDAR)).toBe('Guardado');
  });

  /**
   * Cancelar borra el borrador y no retira la marca que el navegador pidió al crearlo: la
   * factura queda como no guardada sin ningún borrador que lo justifique, y nada en la
   * pantalla la devuelve a su estado.
   */
  test('T2 cancelar borra el borrador y deja la factura marcada como no guardada', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    await crearBorradorAnadiendoProducto(page, FACTURA_CANCELAR);

    page.on('dialog', (dialogo) => dialogo.accept());
    await Promise.all([
      page.waitForURL(/facturasListado\.php/, { timeout: 15000 }),
      page.locator('#Cancelar').click(),
    ]);

    expect(await borradoresDeLaFactura(page, FACTURA_CANCELAR)).toHaveLength(0);
    expect(await estadoDeLaFactura(page, FACTURA_CANCELAR)).toBe('Sin guardar');
  });

  test('T3 abandonar la pantalla deja el borrador vivo y se retoma sin aviso', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    const idTemporal = await crearBorradorAnadiendoProducto(page, FACTURA_ABANDONA);

    expect(await borradoresDeLaFactura(page, FACTURA_ABANDONA)).toEqual([idTemporal]);
    await comprobarBorradorSinAviso(page, idTemporal);

    // Mientras exista, la factura no se deja editar directamente: remite al borrador.
    await page.goto(`modulos/mod_venta/factura.php?id=${FACTURA_ABANDONA}&accion=editar`);
    await expect(page.locator('body')).toContainText('Existe un temporal');
    await expect(page.locator('#Row0')).toBeHidden();
  });

  /**
   * Reguardar la factura borra sus tablas y las vuelve a escribir. Aquí el enlace con su
   * albarán lleva un número que no es el identificador de ningún albarán, y la clave foránea
   * lo rechaza: la escritura lanza, nadie la captura y la página responde 500. Para entonces
   * la factura ya se ha vuelto a escribir en «Guardado», y el borrador sigue atado a ella.
   */
  test('T4 si la reescritura falla, el borrador queda atado a una factura sin marca y no se puede cerrar', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    const idTemporal = await crearBorradorAnadiendoProducto(page, FACTURA_FALLO);

    const respuesta = await pulsarGuardar(page);
    expect(respuesta.status()).toBe(500);

    expect(await borradoresDeLaFactura(page, FACTURA_FALLO)).toEqual([idTemporal]);
    expect(await estadoDeLaFactura(page, FACTURA_FALLO)).toBe('Guardado');
    await comprobarBorradorSinSalida(page, idTemporal);
  });

  /**
   * El campo de fecha solo comprueba el formato, así que una fecha que no existe llega al
   * servidor. Allí no pasa la comprobación de calendario y no se guarda nada; en su lugar se
   * entra en la rama que crea un borrador de recuperación, sin los albaranes —se le pasan con
   * un nombre que no lee— y con la fecha convertida al día que resulta de desbordar el mes.
   *
   * Esa rama incluye dentro de la propia vista el guion que crea borradores, y el guion
   * reescribe variables de la vista con los datos del borrador nuevo: la pantalla que responde
   * ya apunta al borrador de recuperación, muestra su fecha en otro formato y muere al pintar
   * las líneas, antes del pie del documento, aunque el servidor responda 200.
   */
  test('T5 una fecha imposible no guarda: crea un segundo borrador y la pantalla muere a medio pintar', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    const idTemporal = await crearBorradorAnadiendoProducto(page, FACTURA_FECHA_IMPOSIBLE);
    await expect(page.locator('#tablaAdjunto tbody tr')).not.toHaveCount(0);

    await page.fill('#fecha', '31-02-2026');
    const respuesta = await pulsarGuardar(page);

    // El servidor responde 200 con los dos avisos y los botones ocultos...
    expect(respuesta.status()).toBe(200);
    await expect(page.locator('body')).toContainText('La fecha que envia POST no es correcta');
    await expect(page.locator('body')).toContainText('HUBO ERROR AL GRABAR');
    await expect(page.locator('#Guardar')).toBeHidden();
    await expect(page.locator('#Cancelar')).toBeHidden();

    // ... pero la pantalla ya es la del borrador nuevo, con su fecha en otro formato, y se corta
    // al pintar las líneas: ni líneas ni pie del documento.
    await expect(page.locator('#fecha')).toHaveValue('2026-03-03');
    await expect(page.locator('#tabla tbody tr')).toHaveCount(0);
    await expect(page.locator('#tabla-pie')).toHaveCount(0);
    const idEnLaPantalla = String(await page.evaluate(() => window.cabecera.idTemporal));
    expect(idEnLaPantalla).not.toBe(idTemporal);

    const borradores = await borradoresDeLaFactura(page, FACTURA_FECHA_IMPOSIBLE);
    expect(borradores).toHaveLength(2);
    const idRecuperacion = borradores.find((id) => id !== idTemporal);
    expect(idEnLaPantalla).toBe(idRecuperacion);

    await page.goto(`modulos/mod_venta/factura.php?tActual=${idRecuperacion}`);
    await expect(page.locator('#fecha')).toHaveValue('03-03-2026');
    await expect(page.locator('#tablaAdjunto tbody tr')).toHaveCount(0);
  });

  /**
   * El despacho compartido aplica cualquier cambio de estado a los tres tipos de documento
   * que compartan identificador. Aquí otro documento, de otro cliente, incorpora un albarán
   * cuyo número es el identificador de esta factura: el cambio de estado que pide ese
   * albarán alcanza a la factura, que pierde la marca con el borrador todavía vivo.
   */
  test('T6 si otro documento cambia el estado de la factura, su borrador ya no se puede cerrar', async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');
    const idTemporal = await crearBorradorAnadiendoProducto(page, FACTURA_ESTADO);

    await page.goto('modulos/mod_venta/factura.php');
    await seleccionarCliente(page, CLIENTE_OTRO_DOCUMENTO);
    await expect(page.locator('#numAdjunto')).toBeVisible({ timeout: 10000 });
    await page.fill('#numAdjunto', String(FACTURA_ESTADO));
    await Promise.all([
      esperarTarea(page, 'modificarEstadoDocumento', 'Procesado'),
      page.locator('#numAdjunto').press('Enter'),
    ]);

    expect(await estadoDeLaFactura(page, FACTURA_ESTADO)).toBe('Procesado');
    await comprobarBorradorSinSalida(page, idTemporal);
  });
});

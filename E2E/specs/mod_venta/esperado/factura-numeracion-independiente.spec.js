/**
 * Comportamiento esperado: el número de una factura emitida es el siguiente de su serie, no
 * el identificador de su fila.
 *
 * Síntoma: al emitir una factura desde la pantalla, el número que queda en el listado es el
 * identificador que la tabla acaba de asignar. Mientras número e identificador coinciden nada
 * se nota; en cuanto dejan de coincidir —y ya no coinciden en buena parte de los documentos
 * ya escritos— las acciones que envían uno y consultan el otro dejan de encontrarse. «Crear
 * factura desde albarán» es el caso visible: manda el identificador del albarán y la búsqueda
 * consulta por su número, de modo que la factura se abre sin el albarán y sin aviso.
 *
 * Causa raíz: no hay serie de numeración propia. El guardado copia el identificador de la
 * fila en la columna del número.
 *
 * Corrección: llevar una serie propia por documento, independiente del identificador, y
 * decidir aparte qué se hace con lo ya emitido —corregir la escritura sin migrar cambia lo
 * que el listado muestra de documentos antiguos—.
 *
 * **La factura la emite este recorrido.** Los documentos que la siembra crea llevan número de
 * una serie propia, de modo que en ellos número e identificador ya difieren y el defecto no
 * se ve: solo aparece en una factura que emita el producto. Por eso T1 compone y guarda la
 * suya, contra un cliente y un albarán propios, y no mira las de otros recorridos.
 *
 * Estos recorridos afirman el comportamiento correcto y por eso **se conservan en rojo**: el
 * motivo de cada fallo es su propia aserción. El día que el cambio se aplique pasarán a verde y
 * quedarán como guardia de regresión.
 *
 * El «antes» sigue documentado, sin tocar, en `factura-guardar-recorrido-completo.spec.js`
 * T2 y en `factura-crear-desde-albaran.spec.js` T1.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');

const ID_CLIENTE_NUMERACION = 966;
const NOMBRE_CLIENTE_NUMERACION = '[E2E venta] Esperado numeracion factura';
const NOMBRE_CLIENTE_DESDE_ALBARAN = '[E2E venta] Cliente factura desde albaran';

/** La fila del listado de un cliente, que los recorridos leen por su nombre. */
function filaDe(page, nombre) {
  return page.locator('table.table-bordered tbody tr').filter({ hasText: nombre }).first();
}

test.describe('Factura — numeración independiente del identificador', () => {
  test('T1 el número de la factura que se acaba de emitir no es su identificador', {
    tag: ['@esperado', '@factura', '@numeracion', '@guardado', '@directo', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'La factura que la pantalla acaba de emitir se numera con el identificador que la tabla asignó: el número del listado y el id de la URL son el mismo valor.' },
      { type: 'Qué debería ocurrir', description: 'Que el número venga de una serie propia de facturas, independiente del identificador de la fila.' },
      { type: 'Por qué ocurre', description: 'El guardado copia el identificador de la fila en la columna del número; no existe ninguna serie.' },
      { type: 'Cómo debería funcionar', description: 'Llevar una serie de numeración por documento y asignar el siguiente número al emitir, sin mirar el identificador.' },
    ],
  }, async ({ page }) => {
    test.setTimeout(90_000);

    await iniciarSesion(page, 'modulos/mod_venta/factura.php');
    await seleccionarCliente(page, ID_CLIENTE_NUMERACION);
    await expect(page.locator('#numAdjunto')).toBeVisible({ timeout: 15000 });

    // El número del albarán del cliente, leído del propio buscador del producto.
    const numeroAlbaran = await page.evaluate(async (idCliente) => {
      const respuesta = await fetch('tareas.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          pulsado: 'buscarAdjunto',
          busqueda: '',
          idCliente: String(idCliente),
          dedonde: 'factura',
        }),
      });
      const datos = JSON.parse(await respuesta.text());
      return JSON.parse(datos.res).datos[0].NumalbCli;
    }, ID_CLIENTE_NUMERACION);

    await page.fill('#numAdjunto', String(numeroAlbaran));
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
      ),
      page.locator('#numAdjunto').press('Enter'),
    ]);

    await expect(page.locator('#tabla tr[id^="Row"]:not(#Row0)').first()).toBeVisible({ timeout: 15000 });
    await page.locator('#Guardar').click();
    await expect(page).toHaveURL(/facturasListado\.php/, { timeout: 20000 });

    // La factura recién emitida, en el listado de su cliente.
    const fila = filaDe(page, NOMBRE_CLIENTE_NUMERACION);
    await expect(fila).toBeVisible({ timeout: 15000 });

    const numeroEmitido = (await fila.locator('td').nth(3).innerText()).trim();
    const enlace = await fila.locator('a[title="Ver factura"]').getAttribute('href');
    const idDeLaFila = new URL(enlace, 'http://x/').searchParams.get('id');

    expect(numeroEmitido, 'el número de una factura emitida no debe ser el identificador de su fila')
      .not.toBe(idDeLaFila);
  });

  test('T2 crear una factura desde un albarán la abre con ese albarán incorporado', {
    tag: ['@esperado', '@factura', '@albaran', '@numeracion', '@adjuntos', '@directo', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'La acción abre la factura con el cliente puesto pero sin el albarán que se pidió facturar, y sin ningún aviso.' },
      { type: 'Qué debería ocurrir', description: 'Que la factura se abra con ese albarán ya incorporado, con su fila de adjunto.' },
      { type: 'Por qué ocurre', description: 'El formulario envía el identificador del albarán y la búsqueda que lo recibe consulta por el número; cuando no coinciden, no lo encuentra.' },
      { type: 'Cómo debería funcionar', description: 'Que la acción y la búsqueda usen el mismo dato. Con una serie propia el identificador deja de viajar como número y la confusión desaparece.' },
    ],
  }, async ({ page }) => {
    await iniciarSesion(page, 'modulos/mod_venta/albaranesListado.php');

    const fila = filaDe(page, NOMBRE_CLIENTE_DESDE_ALBARAN);
    await expect(fila).toBeVisible({ timeout: 15000 });

    // Este albarán está sembrado con número distinto de identificador a propósito: es la
    // condición que hace visible el defecto.
    const idEnviado = await fila.locator('input[name="albaranes[]"]').inputValue();
    const numeroVisible = (await fila.locator('td').nth(3).innerText()).trim();
    expect(idEnviado, 'el escenario exige número distinto de identificador').not.toBe(numeroVisible);

    await Promise.all([
      page.waitForURL(/factura\.php/),
      fila.locator('a[title="Crear factura desde albarán"]').click(),
    ]);

    await expect(page.locator('#id_cliente')).not.toHaveValue('', { timeout: 15000 });

    await expect(
      page.locator('#tablaAdjunto .eliminar a[onclick*="eliminarAdjunto"]'),
      'la factura debería abrirse con el albarán incorporado'
    ).toHaveCount(1, { timeout: 5000 });
  });
});

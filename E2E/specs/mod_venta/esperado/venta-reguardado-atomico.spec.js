/**
 * Comportamiento esperado: volver a guardar un documento se completa entero o no cambia nada.
 *
 * Síntoma: se abre para editar un documento ya guardado, se le añade un artículo cuyo nombre
 * lleva una comilla doble y se guarda. La línea nueva no se puede escribir. Para entonces el
 * documento anterior ya se ha borrado y su cabecera se ha reescrito: queda con el importe nuevo,
 * que incluye una línea que no existe, y sin su desglose de impuestos.
 *
 * Causa raíz: guardar un documento que ya existe es borrarlo entero y volver a escribirlo, y esa
 * secuencia no va en ninguna transacción. Lo que se borró y lo que se llegó a reescribir antes
 * del fallo ya está confirmado.
 *
 * Corrección esperada: la secuencia entera en una transacción que se deshaga al primer fallo, de
 * modo que el documento quede como estaba antes de pulsar Guardar.
 *
 * El documento lo deja la siembra, con una línea, y se lee de la base con
 * `support/leer-traza-de-documento.php`: la pantalla responde con un error y no enseña en qué
 * estado ha quedado. La factura no tiene caso: su guardado necesita antes numeración propia.
 *
 * **Se conserva en rojo** hasta que el guardado vaya en una transacción.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion } = require('../../../fixtures/autenticacion');

const ID_ARTICULO_CON_COMILLA = 14681; // '[E2E venta] Pera 5" premium'

const DOCUMENTOS = [
  { documento: 'albaran', elDocumento: 'el albarán', etiqueta: '@albaran', pantalla: 'albaran.php', idCliente: 984 },
  { documento: 'pedido', elDocumento: 'el pedido', etiqueta: '@pedido', pantalla: 'pedido.php', idCliente: 985 },
];

/** Lo que la base dice del último documento de ese cliente. */
function traza(documento, idCliente) {
  const guion = path.resolve(__dirname, '../../../../support/leer-traza-de-documento.php');

  return JSON.parse(execFileSync('php', [guion, documento, String(idCliente)], { encoding: 'utf-8' }));
}

for (const caso of DOCUMENTOS) {
  test.describe(`Venta — volver a guardar ${caso.elDocumento} es atómico`, () => {
    test.setTimeout(90000);

    test(`T1 si la línea nueva no se puede escribir, ${caso.elDocumento} queda como estaba`, {
      tag: ['@esperado', caso.etiqueta, '@guardado', '@directo', '@critico'],
      annotation: [
        { type: 'Qué ocurre hoy', description: 'Volver a guardar un documento añadiéndole un artículo cuyo nombre lleva una comilla doble lo deja con el importe nuevo, que cuenta una línea que no se escribió, y sin su desglose de impuestos.' },
        { type: 'Qué debería ocurrir', description: 'Que el documento quede exactamente como estaba antes de pulsar Guardar.' },
        { type: 'Por qué ocurre', description: 'Volver a guardar es borrar el documento entero y reescribirlo, sin transacción: cuando la línea nueva falla, el borrado y la cabecera reescrita ya están confirmados.' },
        { type: 'Cómo debería funcionar', description: 'Toda la secuencia en una transacción que se deshaga al primer fallo.' },
      ],
    }, async ({ page }) => {
      const antes = traza(caso.documento, caso.idCliente);
      expect(antes.documento, 'La siembra tiene que dejar el documento').not.toBeNull();
      expect(antes.documento.lineas, 'El documento parte con una línea').toBe(1);
      expect(antes.documento.desglose, 'Y con su desglose de impuestos').toBeGreaterThan(0);

      await iniciarSesion(page, `modulos/mod_venta/${caso.pantalla}?id=${antes.documento.id}&accion=editar`);
      await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

      await page.fill('#idArticulo', String(ID_ARTICULO_CON_COMILLA));
      await Promise.all([
        page.waitForResponse(
          (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
        ),
        page.locator('#idArticulo').press('Enter'),
      ]);

      await expect(page.locator('#Guardar')).toBeVisible({ timeout: 10000 });
      await page.locator('#Guardar').click();
      await page.waitForLoadState('load');

      const despues = traza(caso.documento, caso.idCliente);

      expect(despues.documento, `${caso.elDocumento} tiene que seguir existiendo`).not.toBeNull();
      expect(despues.documento.id, 'Y ser el mismo documento').toBe(antes.documento.id);
      expect(despues.documento.lineas, 'Con la línea que tenía').toBe(antes.documento.lineas);
      expect(despues.documento.total, 'Con el importe que tenía, no el de una línea que no se escribió').toBe(antes.documento.total);
      expect(despues.documento.desglose, 'Y con su desglose de impuestos').toBe(antes.documento.desglose);
      expect(despues.borradores, 'El documento en curso sobrevive, para no perder lo que el operador compuso').toBeGreaterThan(0);
    });
  });
}

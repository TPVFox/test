/**
 * Volver a guardar un documento de venta conserva quién lo creó y cuándo.
 *
 * Guardar un documento que ya existe es borrarlo y reescribirlo. La reescritura ponía como
 * creador al usuario que guardaba, y como fecha de creación la del día: reguardar borraba la
 * traza de quién había hecho el documento. Ahora la pantalla lee esos dos datos del documento
 * que existe, antes de borrarlo, y los devuelve al guardado.
 *
 * Cada recorrido parte de un documento que crea la siembra —con el usuario de la siembra, no
 * el de los recorridos, y con fecha de enero—, lo abre para editar, le añade una línea y lo
 * guarda. La pantalla no enseña ni el creador ni las fechas, así que se leen de la base con
 * `support/leer-traza-de-documento.php`.
 *
 * El albarán solo conserva al creador: su tabla no tiene columnas de fecha de creación ni de
 * modificación, a diferencia de las de pedido y factura.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const ID_ARTICULO = 14679; // '[E2E venta] Manzana Reineta'
const FECHA_DE_CREACION = '2026-01-15 10:00:00'; // la que deja la siembra

const DOCUMENTOS = [
  { documento: 'pedido', elDocumento: 'el pedido', pantalla: 'pedido.php', idCliente: 975, conFechas: true },
  { documento: 'albaran', elDocumento: 'el albarán', pantalla: 'albaran.php', idCliente: 976, conFechas: false },
  { documento: 'factura', elDocumento: 'la factura', pantalla: 'factura.php', idCliente: 977, conFechas: true },
];

/** Lo que la base dice del último documento de ese cliente. */
function traza(documento, idCliente) {
  const guion = path.resolve(__dirname, '../../../support/leer-traza-de-documento.php');

  return JSON.parse(execFileSync('php', [guion, documento, String(idCliente)], { encoding: 'utf-8' }));
}

for (const caso of DOCUMENTOS) {
  test.describe(`Venta — reguardar ${caso.elDocumento} conserva su traza de autoría`, () => {
    test.setTimeout(60000);

    test(`T1 reguardar ${caso.elDocumento} con otro usuario no cambia quién lo creó${caso.conFechas ? ' ni cuándo' : ''}`, {
      tag: ['@estado-actual', `@${caso.documento}`, '@guardado'],
      annotation: [
        { type: 'Comportamiento', description: caso.conFechas
          ? 'Al volver a guardar el documento con otro usuario, sigue constando el creador original y su fecha de creación, y la fecha de modificación pasa a ser la del guardado.'
          : 'Al volver a guardar el albarán con otro usuario, sigue constando el creador original. La tabla del albarán no tiene columnas de fecha de creación ni de modificación.' },
      ],
    }, async ({ page }) => {
      const antes = traza(caso.documento, caso.idCliente);
      expect(antes.documento, 'La siembra tiene que dejar el documento').not.toBeNull();
      expect(
        Number(antes.documento.idUsuario),
        'El documento tiene que ser de otro usuario, o el recorrido no mide nada'
      ).not.toBe(antes.usuarioDeRecorrido);

      await iniciarSesion(page, `modulos/mod_venta/${caso.pantalla}?id=${antes.documento.id}&accion=editar`);
      await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

      await page.fill('#idArticulo', String(ID_ARTICULO));
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

      expect(despues.documento.id, 'Reguardar no crea un documento distinto').toBe(antes.documento.id);
      expect(despues.documento.idUsuario, 'El creador no se sustituye por quien reguarda').toBe(antes.documento.idUsuario);

      if (caso.conFechas) {
        expect(despues.documento.fechaCreacion, 'La fecha de creación no cambia').toBe(FECHA_DE_CREACION);
        expect(
          despues.documento.fechaModificacion > FECHA_DE_CREACION,
          `La fecha de modificación pasa a ser la del guardado, y es ${despues.documento.fechaModificacion}`
        ).toBe(true);
      }
    });
  });
}

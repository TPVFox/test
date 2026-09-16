/**
 * Comportamiento esperado: el documento impreso de una factura pone cada bloque de líneas
 * bajo la cabecera del albarán al que pertenece, y cada cabecera lleva su fecha.
 *
 * Síntoma, medido sobre una factura con dos albaranes —uno con líneas y otro sin ninguna—:
 * el impreso saca **una sola cabecera**, la del albarán que tiene líneas. El albarán vacío no
 * aparece en el documento y nada indica que la factura lo incluye. Y la cabecera que sí sale,
 * «Nun Alb:<numero>», va **sin fecha**.
 *
 * Causa raíz: las cabeceras no se componen a partir de los albaranes de la factura sino de los
 * grupos de líneas, de modo que un albarán sin líneas no produce grupo y por tanto no produce
 * cabecera. La fecha, por su parte, se lee de una clave que la consulta que compone la cabecera
 * no trae.
 *
 * Corrección: componer una cabecera por cada albarán de la factura, tenga líneas o no, y traer
 * su fecha en esa misma consulta.
 *
 * Estos recorridos afirman el comportamiento correcto y por eso **se conservan en rojo**: el
 * motivo de cada fallo es su propia aserción.
 *
 * El texto del PDF lo extrae `pdftotext`; si falta, el fixture lo dice con ese nombre para que
 * no se confunda con un defecto del producto.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { abrirImpreso, leerTextoPdf, lineasDe } = require('../../../fixtures/documentoImpreso');

const NOMBRE_CLIENTE = '[E2E venta] Esperado impreso cabeceras';

/** Como el producto imprime la cabecera de cada albarán dentro de la factura. */
const CABECERA_ALBARAN = /Nun\s*Alb\s*:/i;

/** El icono de imprimir de la fila de este cliente en el listado de facturas. */
function iconoImprimirDe(page, nombre) {
  return page
    .locator('table.table-bordered tbody tr')
    .filter({ hasText: nombre })
    .first()
    .locator('a.glyphicon-print');
}

test.describe('Factura — cabeceras del documento impreso', () => {
  test('T1 la fecha de la cabecera de albarán aparece en el impreso', {
    tag: ['@esperado', '@factura', '@albaran', '@impreso', '@forzado', '@medio'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'La cabecera de cada albarán del impreso de la factura sale con su fecha vacía.' },
      { type: 'Qué debería ocurrir', description: 'Que cada cabecera de albarán lleve la fecha de ese albarán.' },
      { type: 'Por qué ocurre', description: 'La fecha se lee de una clave que la consulta que compone la cabecera no trae, de modo que llega siempre vacía.' },
      { type: 'Cómo debería funcionar', description: 'Traer la fecha del albarán en esa consulta y pintarla en la cabecera.' },
    ],
  }, async ({ page }) => {
    test.setTimeout(90_000);

    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const icono = iconoImprimirDe(page, NOMBRE_CLIENTE);
    await expect(icono).toBeVisible({ timeout: 15000 });

    const { ruta, popup } = await abrirImpreso(page, icono);
    try {
      const texto = await leerTextoPdf(page, ruta);

      // La cabecera de cada albarán la imprime el producto como «Nun Alb:<numero>».
      const lineaCabecera = lineasDe(texto).find((l) => CABECERA_ALBARAN.test(l));
      expect(lineaCabecera, 'el impreso debería traer la cabecera del albarán').toBeDefined();

      // El impreso fecha la factura en la esquina, con dos dígitos y guiones; la cabecera
      // del albarán debería fechar el albarán igual, y hoy sale sin ninguna fecha.
      expect(lineaCabecera, 'la cabecera del albarán debería llevar su fecha')
        .toMatch(/\d{2}-\d{2}-\d{4}/);
    } finally {
      await popup.close();
    }
  });

  test('T2 el impreso trae una cabecera por cada albarán de la factura', {
    tag: ['@esperado', '@factura', '@albaran', '@impreso', '@forzado', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Una factura con dos albaranes, uno de ellos sin líneas, se imprime con una sola cabecera: el albarán vacío no aparece y nada indica que la factura lo incluye.' },
      { type: 'Qué debería ocurrir', description: 'Que el impreso traiga una cabecera por cada albarán de la factura, también por el que no tiene líneas.' },
      { type: 'Por qué ocurre', description: 'Las cabeceras se componen a partir de los grupos de líneas, no de los albaranes de la factura: un albarán sin líneas no produce grupo y por tanto no produce cabecera.' },
      { type: 'Cómo debería funcionar', description: 'Recorrer los albaranes de la factura para componer las cabeceras, y colgar de cada una su grupo de líneas emparejado por identificador.' },
    ],
  }, async ({ page }) => {
    test.setTimeout(90_000);

    await iniciarSesion(page, 'modulos/mod_venta/facturasListado.php');

    const icono = iconoImprimirDe(page, NOMBRE_CLIENTE);
    await expect(icono).toBeVisible({ timeout: 15000 });

    const { ruta, popup } = await abrirImpreso(page, icono);
    try {
      const texto = await leerTextoPdf(page, ruta);
      const cabeceras = lineasDe(texto).filter((l) => CABECERA_ALBARAN.test(l));

      // La factura de este escenario lleva dos albaranes: uno con líneas y otro sin ninguna.
      // El impreso saca una cabecera por cada grupo de líneas, de modo que el albarán vacío
      // no produce ninguna y desaparece del documento sin que nada lo señale.
      expect(
        cabeceras.length,
        'el impreso debería traer una cabecera por cada albarán de la factura, también por el que no tiene líneas'
      ).toBe(2);
    } finally {
      await popup.close();
    }
  });
});

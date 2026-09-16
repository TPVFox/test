/**
 * Comportamiento esperado: el listado de documentos en curso muestra solo los del propio
 * usuario y su tienda.
 *
 * Síntoma: la consulta que alimenta el listado de borradores no filtra ni por usuario ni por
 * tienda, de modo que cualquiera que entre ve los documentos a medio componer de los demás y
 * puede abrirlos. En un puesto de caja compartido eso significa que un cajero puede seguir
 * —o emitir— el documento que otro dejó empezado.
 *
 * Causa raíz: la consulta de documentos temporales selecciona por tipo de documento y nada
 * más; las columnas de usuario y tienda existen en la tabla y no entran en el `where`.
 *
 * Corrección: acotar la consulta por usuario y por tienda.
 *
 * Este recorrido necesita **dos usuarios**. Si el entorno no declara el segundo
 * (`TPVFOX_E2E_USUARIO2` y `TPVFOX_E2E_CLAVE2`), se salta solo en vez de fallar por una
 * causa que no es el defecto.
 *
 * Afirma el comportamiento correcto y por eso **se conserva en rojo**: el motivo del fallo es su
 * propia aserción. El día que el cambio se aplique pasará a verde.
 *
 * El «antes» sigue documentado, sin tocar, en `facturas-listado-flujo.spec.js` T6.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion, segundoUsuario } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');

const ID_CLIENTE = 939;

/** Marca reconocible para encontrar después el borrador que deja este recorrido. */
const REFERENCIA = `acotado-${Date.now()}`;

test.describe('Documentos en curso — acotados a quien los mira', () => {
  test('T1 el borrador que abre un usuario no aparece en el listado del otro', {
    tag: ['@esperado', '@factura', '@listado', '@borrador', '@directo', '@alto'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'El listado de documentos en curso no filtra por usuario ni por tienda: un cajero ve los borradores que otro dejó a medias y puede abrirlos.' },
      { type: 'Qué debería ocurrir', description: 'Que cada usuario vea solo sus propios documentos en curso, y solo los de su tienda.' },
      { type: 'Por qué ocurre', description: 'La consulta de documentos temporales selecciona por tipo de documento y nada más; las columnas de usuario y tienda no entran en la condición.' },
      { type: 'Cómo debería funcionar', description: 'Añadir usuario y tienda a la condición de la consulta que alimenta el listado.' },
    ],
  }, async ({ page, browser }) => {
    const otro = segundoUsuario();
    test.skip(otro === null, 'El entorno no declara TPVFOX_E2E_USUARIO2/TPVFOX_E2E_CLAVE2.');

    // El primer usuario deja una factura a medio componer.
    await iniciarSesion(page, 'modulos/mod_venta/factura.php');
    await seleccionarCliente(page, ID_CLIENTE);
    await expect(page.locator('#numAdjunto')).toBeVisible({ timeout: 15000 });
    await page.fill('#referencia', REFERENCIA).catch(() => {});

    // El segundo entra en una sesión limpia y mira el mismo listado.
    const contexto = await browser.newContext();
    const paginaOtro = await contexto.newPage();

    try {
      await iniciarSesion(paginaOtro, 'modulos/mod_venta/facturasListado.php', otro);

      const abiertos = paginaOtro.locator('#tablaTemporales tr, table.table-bordered tbody tr');
      await expect(
        abiertos.filter({ hasText: REFERENCIA }),
        'el documento en curso de otro usuario no debería aparecer en este listado'
      ).toHaveCount(0, { timeout: 5000 });
    } finally {
      await contexto.close();
    }
  });
});

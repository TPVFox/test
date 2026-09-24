/**
 * Recorrido 2: admisión del fichero, clasificación en pantalla y
 * descarga del informe final, en el despliegue del ejercicio anterior.
 *
 * Sube el fixture generado con «php support/generar-fixture-e2e.php»: hay que
 * regenerarlo con el ejercicio vigente real del par de despliegues contra el que
 * corre este recorrido (ver la cabecera de ese guion).
 *
 * **Este recorrido no corre contra el despliegue de los demás.** La pantalla del
 * ejercicio anterior solo admite un fichero que declare el ejercicio *siguiente* al suyo
 * (`ClaseComprobacionStockAdmision`), de modo que necesita el despliegue del anterior
 * mientras el resto de la suite corre contra el vigente. Por eso toma su propia
 * `TPVFOX_URL_ANTERIOR`: es el mismo árbol de ficheros servido en otro puerto, con la otra
 * base. Sin esa variable el recorrido se salta y lo dice — antes fallaba con «element not
 * found», que es indistinguible de un defecto del producto.
 */

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const URL_ANTERIOR = process.env.TPVFOX_URL_ANTERIOR;

// Misma razón que en playwright.config.js: sin barra final, URL() se come el último tramo
// de la base y «modulos/…» acabaría pidiéndose fuera de la aplicación.
function conBarraFinal(url) {
  return url.endsWith('/') ? url : url + '/';
}

const FICHERO_EJEMPLO = path.join(__dirname, '..', '..', 'fixtures', 'comprobacion-vigente-ejemplo.xml');
// Qué producto declara ese fichero lo dice el propio generador, junto al fichero. Antes
// estaba escrito aquí a mano: si la siembra cambiaba de producto, el recorrido seguía
// buscando el anterior y fallaba sin decir por qué.
const DECLARACION = path.join(__dirname, '..', '..', 'fixtures', 'comprobacion-vigente-ejemplo.json');
const producto = fs.existsSync(DECLARACION)
  ? String(JSON.parse(fs.readFileSync(DECLARACION, 'utf-8')).idArticulo)
  : null;

test.describe('Comprobación de existencias — ejercicio anterior', () => {
  // Las rutas de este fichero se resuelven contra el despliegue del ejercicio anterior,
  // no contra el que usa el resto de la suite.
  test.use({ baseURL: URL_ANTERIOR ? conBarraFinal(URL_ANTERIOR) : undefined });

  test.beforeEach(async ({ page }) => {
    test.skip(
      !URL_ANTERIOR,
      'Falta TPVFOX_URL_ANTERIOR: este recorrido necesita el despliegue del ejercicio anterior, no el vigente.'
    );
    test.skip(
      !fs.existsSync(FICHERO_EJEMPLO) || producto === null,
      'Falta el fixture: genera «php support/generar-fixture-e2e.php» antes de correr este recorrido.'
    );
    await iniciarSesion(page, 'modulos/mod_reorganizacion/ComprobacionStockAnterior.php');
  });

  /**
   * Admite el fichero y deja dicho por qué no, si no.
   *
   * El rechazo de la admisión es una respuesta legítima de la pantalla, y si se deja pasar
   * el recorrido muere después en un `toBeVisible` que no nombra ninguna causa. El caso que
   * más se da —el despliegue sirve el ejercicio equivocado— se distingue aquí por su
   * mensaje, porque es de entorno y no del producto.
   */
  async function admitirElFichero(page) {
    await page.setInputFiles('#ficheroComprobacionStock', FICHERO_EJEMPLO);
    await page.click('#btnComprobacionStockAnteriorAdmitir');

    const tabla = page.locator('#areaComprobacionStockAnterior table');
    const rechazo = page.getByText('no corresponde a este ejercicio y tienda');

    await expect(tabla.or(rechazo).first()).toBeVisible({ timeout: 15000 });

    if (await rechazo.isVisible()) {
      const anoAnterior = JSON.parse(fs.readFileSync(DECLARACION, 'utf-8')).anoAnterior;
      throw new Error(
        `La pantalla rechazó el fichero por ejercicio o tienda. TPVFOX_URL_ANTERIOR apunta a ` +
        `«${URL_ANTERIOR}», que debe servir el ejercicio ${anoAnterior}. Es un fallo de entorno, ` +
        `no del producto: revisa a qué base apunta ese puerto en configuracion.php.`
      );
    }

    return tabla;
  }

  test('T1 admite el fichero de intercambio y clasifica el producto en pantalla', async ({ page }) => {
    await admitirElFichero(page);

    const fila = page.locator('#areaComprobacionStockAnterior table tbody tr', { hasText: producto });
    await expect(fila).toBeVisible({ timeout: 15000 });
    // El estado nunca viaja solo: siempre va acompañado de la existencia exigida.
    await expect(fila.locator('td').nth(4)).not.toHaveText('');

    // Y la pantalla dice de dónde vino lo que muestra. Es el único recorrido en que
    // ese bloque se compone de un fichero realmente subido y admitido: en todo lo
    // demás el contexto llega puesto a mano.
    await expect(page.locator('#contextoComprobacionStockAnteriorOrigen')).toBeVisible();
    await expect(page.locator('#contextoComprobacionStockAnteriorOrigen')).toContainText('Emitido el');
    await expect(page.locator('#contextoComprobacionStockAnteriorOrigen')).toContainText('Autor');
  });

  test('T2 descarga el informe final con los dos contextos de cálculo', async ({ page }) => {
    await admitirElFichero(page);

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.click('#btnComprobacionStockAnteriorExportar'),
    ]);
    const ruta = await download.path();
    let contenido = fs.readFileSync(ruta, 'utf-8');
    if (contenido.charCodeAt(0) === 0xfeff) {
      contenido = contenido.slice(1);
    }

    expect(contenido).toContain('Contexto;Anterior');
    expect(contenido).toContain('Contexto;Vigente');
    // Un informe que se archiva tiene que decir si el resultado del otro ejercicio
    // era todo o una parte, y con qué proveedor se identificó el traspaso.
    expect(contenido).toContain('ConjuntoPedido;');
    expect(contenido).toContain('ProveedorCierre;');
    // Dos veces: hasta cuándo se leyó aquí y hasta cuándo alcanzaba el fichero admitido.
    expect(contenido.match(/FechaCorte;/g)).toHaveLength(2);
    expect(contenido).toContain(producto);
  });

  test('T3 no entrega el informe si el resultado no vuelve como salió', async ({ page }) => {
    await admitirElFichero(page);

    // Es el único recorrido donde el resultado sale de verdad del servidor, viaja al
    // navegador y vuelve en otra petición. Tocarlo aquí es tocarlo donde puede
    // tocarse: entre las dos peticiones no hay nada en servidor que lo recuerde.
    await page.evaluate(() => {
      window.comprobacionStockAnteriorComposicion.filas[0].stockJustificado = 99999;
    });

    await page.click('#btnComprobacionStockAnteriorExportar');

    // No hay descarga: la respuesta es el motivo, y llega en lugar del documento.
    await expect(page.locator('body')).toContainText('no es el que se calculó', { timeout: 15000 });
  });
});

/**
 * Abrir el documento impreso de una pantalla de venta y leer su texto.
 *
 * Imprimir no descarga un fichero ni navega: la pantalla pide el documento por AJAX, el
 * servidor lo compone y devuelve su ruta, y el navegador la abre en una pestaña nueva. Para
 * comprobar lo que el documento dice hay que interceptar esa respuesta, descargar el PDF y
 * extraerle el texto — leer el DOM no sirve, porque el defecto vive en el papel.
 *
 * La extraccion la hace `pdftotext`, de poppler, que ya esta en la maquina. Se prefiere a una
 * dependencia de npm para no ampliar un arbol deliberadamente escueto, a cambio de exigir una
 * herramienta del sistema: cuando falta, `leerTextoPdf` lo dice con ese nombre en vez de
 * fallar de una forma que parezca un defecto del producto.
 */

const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

/**
 * Pulsa el icono de imprimir de una fila y devuelve la ruta del PDF que el servidor compuso.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} icono El icono de imprimir, ya localizado.
 * @returns {Promise<{ruta: string, popup: import('@playwright/test').Page}>}
 */
async function abrirImpreso(page, icono) {
  const [respuesta, popup] = await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('datosImprimir')
    ),
    page.context().waitForEvent('page'),
    icono.click(),
  ]);

  const ruta = JSON.parse(await respuesta.text());

  return { ruta, popup };
}

/**
 * Descarga el PDF de esa ruta y devuelve su texto.
 *
 * `-layout` conserva la disposicion en columnas, que es justo lo que importa cuando el
 * defecto es que un bloque de lineas sale bajo la cabecera equivocada.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} ruta Ruta relativa que devolvio el servidor.
 * @returns {Promise<string>}
 */
async function leerTextoPdf(page, ruta) {
  const descarga = await page.request.get(new URL(ruta, page.url()).toString());
  if (!descarga.ok()) {
    throw new Error(`El servidor no sirvio el documento impreso: ${descarga.status()} en ${ruta}`);
  }

  const cuerpo = await descarga.body();
  if (cuerpo.subarray(0, 4).toString('latin1') !== '%PDF') {
    throw new Error(`Lo que sirvio ${ruta} no empieza por %PDF: no es un PDF.`);
  }

  const temporal = path.join(os.tmpdir(), `impreso-${process.pid}-${Date.now()}.pdf`);
  fs.writeFileSync(temporal, cuerpo);

  try {
    return execFileSync('pdftotext', ['-layout', temporal, '-'], {
      encoding: 'utf8',
      maxBuffer: 16 * 1024 * 1024,
    });
  } catch (error) {
    if (error.code === 'ENOENT') {
      throw new Error(
        'Falta `pdftotext` (paquete poppler-utils). Es lo que lee el texto del documento impreso; ' +
          'sin el, este recorrido no puede comprobar lo que el papel dice.'
      );
    }
    throw error;
  } finally {
    fs.unlinkSync(temporal);
  }
}

/** Las lineas con texto del documento, sin las vacias, para comparar bloques. */
function lineasDe(texto) {
  return texto
    .split(/\r?\n/)
    .map((l) => l.trimEnd())
    .filter((l) => l.trim() !== '');
}

module.exports = { abrirImpreso, leerTextoPdf, lineasDe };

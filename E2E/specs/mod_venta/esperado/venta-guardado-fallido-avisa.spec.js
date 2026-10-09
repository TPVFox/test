/**
 * Comportamiento esperado: un guardado que falla se lo dice al operador y le deja su trabajo.
 *
 * Síntoma: se compone un documento con un artículo cuyo nombre lleva una comilla doble y se
 * guarda. La línea no se puede escribir y el guardado se deshace entero: no queda nada a medias.
 * Pero el operador ve una página en blanco. No sabe qué ha pasado, ni si ha perdido lo que
 * había compuesto.
 *
 * Causa raíz: el fallo llega como una excepción que nadie recoge, y la petición muere con un
 * error 500 antes de pintar nada.
 *
 * Corrección esperada: recoger el fallo, apuntarlo entero en el registro del servidor y volver a
 * pintar la pantalla con el documento tal como estaba y un aviso legible.
 *
 * Dos recorridos por documento, cada uno con su cliente:
 *
 * - T1, lo que ve el operador. **Se conserva en rojo** hasta que la pantalla avise.
 * - T2, lo que queda apuntado en el servidor. Es un control: hoy ya se apunta —el servidor
 *   registra la excepción que nadie recogió— y tiene que seguir apuntándose cuando la pantalla
 *   la recoja para avisar. Un aviso sin apunte dejaría el fallo sin rastro en cuanto el operador
 *   lo cierre.
 *
 * T2 necesita leer el registro de errores de PHP del servidor de pruebas, y este tiene que
 * escribirlo en un fichero: `TPVFOX_E2E_LOG` dice cuál (docs/instalacion.md). Sin esa variable,
 * T2 se salta solo y dice por qué. La factura no tiene caso: su guardado todavía no se deshace.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { iniciarSesion } = require('../../../fixtures/autenticacion');
const { seleccionarCliente } = require('../../../fixtures/seleccionarCliente');

const ID_ARTICULO_CON_COMILLA = 14681; // '[E2E venta] Pera 5" premium'
const LOG_DEL_SERVIDOR = process.env.TPVFOX_E2E_LOG;

const DOCUMENTOS = [
  {
    documento: 'albaran', elDocumento: 'el albarán', delDocumento: 'del albarán', etiqueta: '@albaran', pantalla: 'albaran.php',
    clase: 'albaranesVentas.php', clienteDelAviso: 988, clienteDelRegistro: 990,
  },
  {
    documento: 'pedido', elDocumento: 'el pedido', delDocumento: 'del pedido', etiqueta: '@pedido', pantalla: 'pedido.php',
    clase: 'pedidosVentas.php', clienteDelAviso: 989, clienteDelRegistro: 991,
  },
];

/** Lo que la base dice del último documento de ese cliente. */
function traza(documento, idCliente) {
  const guion = path.resolve(__dirname, '../../../../support/leer-traza-de-documento.php');

  return JSON.parse(execFileSync('php', [guion, documento, String(idCliente)], { encoding: 'utf-8' }));
}

/** Compone un documento con el artículo que no se puede escribir y pulsa Guardar. */
async function guardarElDocumentoQueFalla(page, caso, idCliente) {
  await iniciarSesion(page, `modulos/mod_venta/${caso.pantalla}`);
  await seleccionarCliente(page, idCliente);
  await expect(page.locator('#idArticulo')).toBeVisible({ timeout: 10000 });

  await page.fill('#idArticulo', String(ID_ARTICULO_CON_COMILLA));
  await Promise.all([
    page.waitForResponse(
      (r) => r.url().includes('tareas.php') && (r.request().postData() || '').includes('anhadirTemporal')
    ),
    page.locator('#idArticulo').press('Enter'),
  ]);

  await expect(page.locator('#Guardar')).toBeVisible({ timeout: 10000 });
  const [respuesta] = await Promise.all([
    page.waitForResponse((r) => r.url().includes(caso.pantalla) && r.request().method() === 'POST'),
    page.locator('#Guardar').click(),
  ]);
  await page.waitForLoadState('load');

  return respuesta;
}

for (const caso of DOCUMENTOS) {
  test.describe(`Venta — un guardado fallido ${caso.delDocumento} avisa y no se pierde`, () => {
    test.setTimeout(90000);

    test(`T1 si ${caso.elDocumento} no se puede guardar, el operador lo ve y conserva su trabajo`, {
      tag: ['@esperado', caso.etiqueta, '@guardado', '@directo', '@medio'],
      annotation: [
        { type: 'Qué ocurre hoy', description: 'Cuando el guardado falla, el operador ve una página en blanco: no sabe qué ha pasado ni si ha perdido lo que había compuesto.' },
        { type: 'Qué debería ocurrir', description: 'Que la pantalla diga que no se ha podido guardar, que no se ha cambiado nada, y siga mostrando el documento tal como estaba.' },
        { type: 'Por qué ocurre', description: 'El fallo llega como una excepción que nadie recoge y la petición muere con un error 500 antes de pintar nada.' },
        { type: 'Cómo debería funcionar', description: 'Recoger el fallo, apuntarlo en el registro del servidor y volver a pintar la pantalla con un aviso.' },
      ],
    }, async ({ page }) => {
      const respuesta = await guardarElDocumentoQueFalla(page, caso, caso.clienteDelAviso);

      expect(respuesta.status(), 'La pantalla responde, no se rompe').toBe(200);
      await expect(page.locator('.alert-danger'), 'Y dice que no se ha podido guardar').toContainText('No se ha podido guardar');
      await expect(
        page.locator('#tabla tr[id^="Row"]:not(#Row0)'),
        'El documento sigue en pantalla con su línea'
      ).toHaveCount(1);
      await expect(page.locator('#Guardar'), 'Y se puede volver a guardar sin salir de la pantalla').toBeVisible();

      const despues = traza(caso.documento, caso.clienteDelAviso);
      expect(despues.documento, 'No queda documento escrito').toBeNull();
      expect(despues.borradores, 'Y queda un solo documento en curso: el del operador, no una copia').toBe(1);
    });

    test(`T2 el guardado fallido ${caso.delDocumento} queda apuntado en el servidor con su traza`, {
      tag: ['@estado-actual', caso.etiqueta, '@guardado'],
      annotation: [
        { type: 'Comportamiento', description: 'Un guardado que falla deja en el registro de errores del servidor el error de la base y la traza que lleva hasta la sentencia que falló.' },
      ],
    }, async ({ page }) => {
      test.skip(
        !LOG_DEL_SERVIDOR,
        'Falta TPVFOX_E2E_LOG: este recorrido lee el registro de errores de PHP del servidor de pruebas, que tiene que escribirlo en un fichero.'
      );

      const tamanoPrevio = fs.existsSync(LOG_DEL_SERVIDOR) ? fs.statSync(LOG_DEL_SERVIDOR).size : 0;

      await guardarElDocumentoQueFalla(page, caso, caso.clienteDelRegistro);

      const apuntado = fs.readFileSync(LOG_DEL_SERVIDOR, 'utf-8').slice(tamanoPrevio);
      expect(apuntado, 'El error de la base queda apuntado').toContain('mysqli_sql_exception');
      expect(apuntado, 'Con la sentencia que no se pudo escribir').toContain('premium"');
      expect(apuntado, 'Y con la traza hasta la clase que la lanzó').toContain(caso.clase);
    });
  });
}

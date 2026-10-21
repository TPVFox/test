/**
 * La pantalla de relación: una sola para las tres formas, al estilo de la de albarán. Se crea
 * una relación eligiendo el tipo y buscando cada producto; se prepara un cambio viendo a la vez
 * la versión que rige y lo que difiere; «Guardar» deja lo preparado sin que rija, «Cancelar» lo
 * borra y «Activar» lo pone a regir.
 *
 * Todo escribe, así que se hace sobre el despliegue del ejercicio anterior, partiendo de la
 * siembra de `support/sembrar-e2e-relaciones.php`: la relación 1 es el despiece del pollo, con
 * el pollo que entra y la pechuga y las alas que salen.
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { iniciarSesion } = require('../../fixtures/autenticacion');

const VISTA = 'modulos/mod_relaciones/relacion.php';
const LISTADO = 'modulos/mod_relaciones/ListaRelaciones.php';
const PREFIJO = '[E2E relaciones] ';
const URL_ANTERIOR = process.env.TPVFOX_URL_ANTERIOR;

function conBarraFinal(url) {
  return url.endsWith('/') ? url : url + '/';
}

function enElAnterior(ruta) {
  return new URL(ruta, conBarraFinal(URL_ANTERIOR)).toString();
}

function dejarLasTablas(estado, permiso = 'conPermiso') {
  execFileSync('php', [path.resolve(__dirname, '../../../support/sembrar-e2e-relaciones.php'), estado, 'anterior', permiso], { stdio: 'pipe' });
}

function preparar(argumentos) {
  execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-vista-relacion.php'), ...argumentos, 'anterior'], { stdio: 'pipe' });
}

function ponerNombre(numRelacion, nombre) {
  execFileSync('php', [path.resolve(__dirname, '../../../support/preparar-e2e-cambio-estado.php'), 'nombre', String(numRelacion), nombre, 'anterior'], { stdio: 'pipe' });
}

/** Acepta los avisos y las confirmaciones, salvo que se diga otra cosa; devuelve el texto de cada diálogo. */
function atenderDialogos(page, confirmar = true) {
  const mensajes = [];
  page.on('dialog', async (dialogo) => {
    mensajes.push(dialogo.message());
    if (dialogo.type() === 'confirm' && !confirmar) {
      await dialogo.dismiss();
    } else {
      await dialogo.accept();
    }
  });
  return mensajes;
}

function filas(page, tabla) {
  return page.locator(`#${tabla} tbody tr.linea`);
}

function filaDe(page, tabla, nombre) {
  return filas(page, tabla).filter({ hasText: PREFIJO + nombre });
}

/** Escribe en una caja de búsqueda y pulsa Intro, como en el albarán. */
async function buscar(page, caja, texto) {
  await page.locator('#' + caja).fill(texto);
  await page.locator('#' + caja).press('Enter');
}

/** Cambia la cantidad de una línea y sale de la caja, que es cuando la pantalla la recoge. */
async function ponerCantidad(page, tabla, nombre, cantidad) {
  const caja = filaDe(page, tabla, nombre).locator('input.cantidad');
  await caja.fill(cantidad);
  await caja.press('Tab');
}

test.describe.configure({ mode: 'serial' });

test.describe('Productos relacionados — vista de relación', () => {
  test.beforeEach(() => {
    test.skip(!URL_ANTERIOR, 'El entorno no declara TPVFOX_URL_ANTERIOR.');
  });

  test.afterAll(() => {
    if (URL_ANTERIOR) {
      dejarLasTablas('sinTipos');
    }
  });

  test('T1 sin el permiso de lo que se pide, la vista lleva al listado', {
    tag: ['@estado-actual', '@relaciones', '@vista', '@permisos'],
    annotation: [
      { type: 'Comportamiento', description: 'Añadir exige el permiso de crear, y modificar el de modificar, también abriendo la dirección a mano. Ver solo exige poder ver el listado.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo', 'sinPermiso');
    await iniciarSesion(page, enElAnterior(LISTADO));

    await page.goto(enElAnterior(VISTA));
    await expect(page).toHaveURL(/ListaRelaciones\.php/);
    await page.goto(enElAnterior(VISTA + '?id=1&accion=editar'));
    await expect(page).toHaveURL(/ListaRelaciones\.php/);
    await page.goto(enElAnterior(VISTA + '?id=1&accion=ver'));
    await expect(page).toHaveURL(/relacion\.php/);
    await expect(filas(page, 'vigenteSalida')).toHaveCount(2);
  });

  test('T2 se crea un despiece buscando cada producto por su código de barras, su referencia o su nombre', {
    tag: ['@estado-actual', '@relaciones', '@vista', '@busqueda'],
    annotation: [
      { type: 'Comportamiento', description: 'Como en el albarán: se escribe en una caja y se pulsa Intro. Un resultado se añade al lado elegido; varios abren una lista para elegir. Guardar no pone a regir nada; Activar sí, y vuelve al listado, donde aparece la relación.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    preparar(['referencias']);
    await iniciarSesion(page, enElAnterior(VISTA));
    atenderDialogos(page);

    await page.locator('#idTipo').selectOption({ label: 'Despiece' });
    await page.locator('#lado').selectOption('entrada');
    await buscar(page, 'Codbarras', 'E2E-PAVO-CB');
    await expect(filaDe(page, 'tablaEntrada', 'Pavo')).toHaveCount(1);
    await expect(page.locator('#lado')).toHaveValue('salida');
    await buscar(page, 'Referencia', 'E2E-MUSLO-REF');
    await expect(filaDe(page, 'tablaSalida', 'Muslo de pavo')).toHaveCount(1);
    await buscar(page, 'Descripcion', 'conejo');
    await expect(page.locator('#ventanaModal')).toBeVisible();
    await page.locator('#ventanaModal tr', { hasText: PREFIJO + 'Paletilla de conejo' }).click();
    await expect(filaDe(page, 'tablaSalida', 'Paletilla de conejo')).toHaveCount(1);
    await expect(filaDe(page, 'tablaSalida', 'Paletilla de conejo')).toContainText('E2E-PALETILLA-REF');
    await ponerCantidad(page, 'tablaEntrada', 'Pavo', '1');
    await ponerCantidad(page, 'tablaSalida', 'Muslo de pavo', '0.4');
    await ponerCantidad(page, 'tablaSalida', 'Paletilla de conejo', '0.2');
    await page.locator('#nombre').fill('Pavo para asar');

    await page.locator('#botonGuardar').click();
    await expect(page.locator('#avisosRelacion .alert-success')).toContainText('Guardado');
    await page.locator('#botonActivar').click();

    await expect(page).toHaveURL(/ListaRelaciones\.php/);
    await expect(page.locator('.alert-success')).toContainText('ya rige');
    await page.goto(enElAnterior(LISTADO + '?buscar=Pavo+para+asar'));
    await expect(page.locator('#tablaRelaciones tbody tr')).toHaveCount(1);
    await expect(page.locator('#tablaRelaciones tbody tr td').nth(5)).toHaveText('Despiece');
    await expect(page.locator('#tablaRelaciones tbody tr td').nth(9)).toHaveText('3');
  });

  test('T3 la misma pantalla crea una elaboración, con varias entradas, y un cruce, sin cantidades', {
    tag: ['@estado-actual', '@relaciones', '@vista'],
    annotation: [
      { type: 'Comportamiento', description: 'La pantalla es una para las tres formas. En una elaboración entran varios productos y sale uno; en un cruce entra uno y sale otro, y como el tipo no lleva cantidad, no se piden.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    preparar(['referencias']);
    await iniciarSesion(page, enElAnterior(VISTA));
    atenderDialogos(page);

    await page.locator('#idTipo').selectOption({ label: 'Elaboración' });
    await page.locator('#lado').selectOption('entrada');
    await buscar(page, 'Codbarras', 'E2E-PAVO-CB');
    await expect(filaDe(page, 'tablaEntrada', 'Pavo')).toHaveCount(1);
    await buscar(page, 'Codbarras', 'E2E-CONEJO-CB');
    await expect(filaDe(page, 'tablaEntrada', 'Conejo')).toHaveCount(1);
    await page.locator('#lado').selectOption('salida');
    await buscar(page, 'Codbarras', 'E2E-MUSLO-CB');
    await expect(filaDe(page, 'tablaSalida', 'Muslo de pavo')).toHaveCount(1);
    await ponerCantidad(page, 'tablaEntrada', 'Pavo', '0.6');
    await ponerCantidad(page, 'tablaEntrada', 'Conejo', '0.4');
    await ponerCantidad(page, 'tablaSalida', 'Muslo de pavo', '1');
    await page.locator('#botonActivar').click();
    await expect(page).toHaveURL(/ListaRelaciones\.php/);

    await page.goto(enElAnterior(VISTA));
    await page.locator('#idTipo').selectOption({ label: 'Cruce' });
    await expect(page.locator('#tablaEntrada input.cantidad')).toHaveCount(0);
    await page.locator('#lado').selectOption('entrada');
    await buscar(page, 'Codbarras', 'E2E-PAVO-CB');
    await expect(filaDe(page, 'tablaEntrada', 'Pavo')).toHaveCount(1);
    await expect(page.locator('#lado')).toHaveValue('salida');
    await buscar(page, 'Codbarras', 'E2E-PALETILLA-CB');
    await expect(filaDe(page, 'tablaSalida', 'Paletilla de conejo')).toHaveCount(1);
    await expect(page.locator('#tablaSalida input.cantidad')).toHaveCount(0);
    await page.locator('#botonActivar').click();
    await expect(page).toHaveURL(/ListaRelaciones\.php/);

    await page.goto(enElAnterior(LISTADO + '?filtro=Elaboraci%C3%B3n'));
    await expect(page.locator('#tablaRelaciones tbody tr', { hasText: PREFIJO + 'Muslo de pavo' })).toHaveCount(1);
    await page.goto(enElAnterior(LISTADO + '?filtro=Cruce'));
    await expect(page.locator('#tablaRelaciones tbody tr')).toHaveCount(2);
  });

  test('T4 un lado que solo admite un producto y ya lo tiene no se puede elegir', {
    tag: ['@estado-actual', '@relaciones', '@vista', '@validacion'],
    annotation: [
      { type: 'Comportamiento', description: 'El límite sale de la forma del tipo: en un despiece entra uno, en una elaboración sale uno y en un cruce, uno y uno. Cuando un lado está lleno, el selector se fija en el otro; si están llenos los dos, no se puede añadir nada.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    preparar(['referencias']);
    await iniciarSesion(page, enElAnterior(VISTA));
    // Cada vuelta a la pantalla sale de una con cambios sin guardar: se acepta el aviso.
    atenderDialogos(page);

    await page.locator('#idTipo').selectOption({ label: 'Despiece' });
    await page.locator('#lado').selectOption('entrada');
    await buscar(page, 'Codbarras', 'E2E-PAVO-CB');
    await expect(page.locator('#lado option[value="entrada"]')).toBeDisabled();
    await expect(page.locator('#lado')).toHaveValue('salida');

    await page.goto(enElAnterior(VISTA));
    await page.locator('#idTipo').selectOption({ label: 'Elaboración' });
    await page.locator('#lado').selectOption('salida');
    await buscar(page, 'Codbarras', 'E2E-MUSLO-CB');
    await expect(page.locator('#lado option[value="salida"]')).toBeDisabled();
    await expect(page.locator('#lado')).toHaveValue('entrada');

    await page.goto(enElAnterior(VISTA));
    await page.locator('#idTipo').selectOption({ label: 'Cruce' });
    await page.locator('#lado').selectOption('entrada');
    await buscar(page, 'Codbarras', 'E2E-PAVO-CB');
    await expect(page.locator('#lado')).toHaveValue('salida');
    await buscar(page, 'Codbarras', 'E2E-MUSLO-CB');
    await expect(filaDe(page, 'tablaSalida', 'Muslo de pavo')).toHaveCount(1);
    await expect(page.locator('#lado')).toBeDisabled();
    await expect(page.locator('#Codbarras')).toBeDisabled();
  });

  test('T5 un producto que ya está en la relación no se añade otra vez', {
    tag: ['@estado-actual', '@relaciones', '@vista', '@validacion'],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    preparar(['referencias']);
    await iniciarSesion(page, enElAnterior(VISTA));
    const mensajes = atenderDialogos(page);

    await page.locator('#idTipo').selectOption({ label: 'Despiece' });
    await page.locator('#lado').selectOption('entrada');
    await buscar(page, 'Codbarras', 'E2E-PAVO-CB');
    await expect(filaDe(page, 'tablaEntrada', 'Pavo')).toHaveCount(1);
    await buscar(page, 'Codbarras', 'E2E-PAVO-CB');

    await expect(page.locator('#avisosRelacion .alert-warning')).toContainText('ya está');
    await expect(filas(page, 'tablaSalida')).toHaveCount(0);
    expect(mensajes).toEqual([]);
    await expect(filas(page, 'tablaEntrada')).toHaveCount(1);
  });

  test('T6 no se puede cambiar a un tipo cuya forma no admite las líneas puestas', {
    tag: ['@estado-actual', '@relaciones', '@vista', '@validacion'],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    preparar(['referencias']);
    await iniciarSesion(page, enElAnterior(VISTA));
    const mensajes = atenderDialogos(page);

    await page.locator('#idTipo').selectOption({ label: 'Elaboración' });
    await page.locator('#lado').selectOption('entrada');
    await buscar(page, 'Codbarras', 'E2E-PAVO-CB');
    await expect(filaDe(page, 'tablaEntrada', 'Pavo')).toHaveCount(1);
    await buscar(page, 'Codbarras', 'E2E-CONEJO-CB');
    await expect(filaDe(page, 'tablaEntrada', 'Conejo')).toHaveCount(1);
    await page.locator('#idTipo').selectOption({ label: 'Despiece' });

    await expect(page.locator('#avisosRelacion .alert-warning')).toContainText('sobra');
    expect(mensajes).toEqual([]);
    await expect(page.locator('#idTipo option:checked')).toHaveText('Elaboración');
    await expect(filas(page, 'tablaEntrada')).toHaveCount(2);
  });

  test('T7 al modificar se ve la versión que rige y se marca lo que cambia, con el tipo de cambio', {
    tag: ['@estado-actual', '@relaciones', '@vista'],
    annotation: [
      { type: 'Comportamiento', description: 'Debajo, la versión que rige, solo de lectura. Arriba, lo que se prepara: una cantidad distinta, una pieza nueva y una pieza quitada salen marcadas, y la pantalla dice cómo constará el cambio en el historial.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    preparar(['referencias']);
    await iniciarSesion(page, enElAnterior(VISTA + '?id=1&accion=editar'));

    await expect(filas(page, 'vigenteEntrada')).toHaveCount(1);
    await expect(filas(page, 'vigenteSalida')).toHaveCount(2);
    await expect(page.locator('#tablaSalida tr.marca-nueva, #tablaSalida tr.marca-cambiada, #tablaSalida tr.marca-quitada')).toHaveCount(0);

    await ponerCantidad(page, 'tablaSalida', 'Pechuga', '0.25');
    await expect(filaDe(page, 'tablaSalida', 'Pechuga')).toHaveClass(/marca-cambiada/);
    await expect(page.locator('#tipoCambio')).toContainText('proporciones');

    await page.locator('#lado').selectOption('salida');
    await buscar(page, 'Codbarras', 'E2E-MUSLO-CB');
    await expect(filaDe(page, 'tablaSalida', 'Muslo de pavo')).toHaveClass(/marca-nueva/);
    await filaDe(page, 'tablaSalida', 'Alas').locator('a.quitar').click();
    await expect(filaDe(page, 'tablaSalida', 'Alas')).toHaveClass(/marca-quitada/);
    await expect(page.locator('#tipoCambio')).toContainText('composición');
  });

  test('T8 lo guardado se retoma y lo que rige no cambia', {
    tag: ['@estado-actual', '@relaciones', '@vista'],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    await iniciarSesion(page, enElAnterior(VISTA + '?id=1&accion=editar'));

    await ponerCantidad(page, 'tablaSalida', 'Pechuga', '0.25');
    await page.locator('#origenAjuste').fill('Pesada del lunes');
    await page.locator('#botonGuardar').click();
    await expect(page.locator('#avisosRelacion .alert-success')).toContainText('Guardado');

    await page.goto(enElAnterior(VISTA + '?id=1&accion=editar'));
    await expect(filaDe(page, 'tablaSalida', 'Pechuga').locator('input.cantidad')).toHaveValue('0.25');
    await expect(page.locator('#origenAjuste')).toHaveValue('Pesada del lunes');
    await expect(filaDe(page, 'vigenteSalida', 'Pechuga')).toContainText('0.23');
  });

  test('T9 modificar una relación con un cambio en preparación abre ese cambio', {
    tag: ['@estado-actual', '@relaciones', '@vista'],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    preparar(['temporal', '1']);
    await iniciarSesion(page, enElAnterior(VISTA + '?id=1&accion=editar'));

    await expect(filaDe(page, 'tablaSalida', 'Pechuga').locator('input.cantidad')).toHaveValue('0.27');
  });

  test('T10 ver enseña solo la versión que rige, y avisa si hay un cambio en preparación', {
    tag: ['@estado-actual', '@relaciones', '@vista'],
    annotation: [
      { type: 'Comportamiento', description: 'Ver es consulta: ni botones, ni búsqueda, ni lo que se está preparando. Si alguien prepara un cambio, lo dice, con quién y cuándo.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    await iniciarSesion(page, enElAnterior(VISTA + '?id=1&accion=ver'));

    await expect(filas(page, 'vigenteSalida')).toHaveCount(2);
    await expect(page.locator('#botonGuardar, #botonActivar, #botonCancelar, #Descripcion, #tablaEntrada')).toHaveCount(0);
    await expect(page.locator('.alert')).toHaveCount(0);

    preparar(['temporal', '1']);
    await page.reload();
    await expect(page.locator('.alert-info')).toContainText('cambio en preparación');
    await expect(page.locator('.alert-info')).toContainText(process.env.TPVFOX_E2E_USUARIO);
    await expect(page.locator('.alert-info')).toContainText('2026-10-01 10:00');
    await expect(filaDe(page, 'vigenteSalida', 'Pechuga')).toContainText('0.23');
  });

  test('T11 cancelar pide confirmación y, confirmado, borra lo preparado', {
    tag: ['@estado-actual', '@relaciones', '@vista'],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    preparar(['temporal', '1']);
    await iniciarSesion(page, enElAnterior(VISTA + '?id=1&accion=editar'));

    page.once('dialog', (dialogo) => dialogo.dismiss());
    await page.locator('#botonCancelar').click();
    await expect(page).toHaveURL(/relacion\.php/);
    await expect(filaDe(page, 'tablaSalida', 'Pechuga').locator('input.cantidad')).toHaveValue('0.27');

    page.once('dialog', (dialogo) => dialogo.accept());
    await page.locator('#botonCancelar').click();
    await expect(page).toHaveURL(/ListaRelaciones\.php/);
    await expect(page.locator('#relacionesAbiertas tbody tr')).toHaveCount(0);
  });

  test('T12 salir con cambios sin guardar pide confirmación; sin cambios, no', {
    tag: ['@estado-actual', '@relaciones', '@vista'],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    await iniciarSesion(page, enElAnterior(LISTADO));
    await page.goto(enElAnterior(VISTA + '?id=1&accion=editar'));
    const alSalirConCambios = [];
    page.once('dialog', async (dialogo) => {
      alSalirConCambios.push(dialogo.type());
      await dialogo.dismiss();
    });

    await ponerCantidad(page, 'tablaSalida', 'Pechuga', '0.3');
    await page.locator('a.glyphicon-circle-arrow-left').click();
    await expect.poll(() => alSalirConCambios).toEqual(['beforeunload']);
    await expect(page).toHaveURL(/relacion\.php/);

    // Para volver a abrirla se acepta salir de la que tiene cambios; después ya no debe haber aviso.
    const alSalirSinCambios = [];
    page.on('dialog', async (dialogo) => {
      alSalirSinCambios.push(dialogo.type());
      await dialogo.accept();
    });
    await page.goto(enElAnterior(VISTA + '?id=1&accion=editar'));
    alSalirSinCambios.length = 0;
    await page.locator('a.glyphicon-circle-arrow-left').click();
    await expect(page).toHaveURL(/ListaRelaciones\.php/);
    expect(alSalirSinCambios).toEqual([]);
  });

  test('T13 sin el permiso de activar no aparece «Activar»', {
    tag: ['@estado-actual', '@relaciones', '@vista', '@permisos'],
    annotation: [
      { type: 'Comportamiento', description: 'Quien prepara no tiene por qué poder poner a regir: puede guardar y cancelar, y otro activa.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    preparar(['permiso', 'Activar', '0']);
    await iniciarSesion(page, enElAnterior(VISTA + '?id=1&accion=editar'));

    await expect(page.locator('#botonGuardar')).toBeVisible();
    await expect(page.locator('#botonCancelar')).toBeVisible();
    await expect(page.locator('#botonActivar')).toHaveCount(0);
  });

  test('T14 una activación que no se admite muestra el motivo y sigue en la pantalla', {
    tag: ['@estado-actual', '@relaciones', '@vista', '@validacion'],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    await iniciarSesion(page, enElAnterior(VISTA + '?id=1&accion=editar'));
    const mensajes = atenderDialogos(page);

    await ponerCantidad(page, 'tablaSalida', 'Pechuga', '0.25');
    await page.locator('#botonActivar').click();

    await expect(page.locator('#avisosRelacion .alert-warning')).toContainText('origen');
    expect(mensajes).toHaveLength(1);
    expect(mensajes[0]).toContain('proporciones');
    await expect(page.locator('#avisosRelacion .alert-success')).toHaveCount(0);
    await expect(page).toHaveURL(/relacion\.php/);
    await expect(filaDe(page, 'tablaSalida', 'Pechuga').locator('input.cantidad')).toHaveValue('0.25');
  });

  test('T15 los nombres con comillas o marcas, y una dirección manipulada, se leen como texto', {
    tag: ['@estado-actual', '@relaciones', '@vista', '@validacion'],
    annotation: [
      { type: 'Comportamiento', description: 'Lo que escribe el usuario se pinta escapado. La dirección de la pantalla solo conserva sus propios parámetros: lo que se le añada no llega al enlace de volver.' },
    ],
  }, async ({ page }) => {
    const nombre = 'Pollo "entero" <b>&';
    dejarLasTablas('completo');
    ponerNombre(1, nombre);
    const mensajes = atenderDialogos(page);
    await iniciarSesion(page, enElAnterior(VISTA + '?id=1&accion=ver'));

    await expect(page.locator('#datosRelacion')).toContainText(nombre);
    await expect(page.locator('#datosRelacion b')).toHaveCount(0);

    await page.goto(enElAnterior(VISTA + "?id=1&accion=ver&x=');alert('inyectado');//"));
    await expect(page.locator('#datosRelacion')).toContainText(nombre);
    expect(mensajes).toEqual([]);
  });

  test('T16 hasta elegir el tipo no se puede buscar ningún producto', {
    tag: ['@estado-actual', '@relaciones', '@vista', '@validacion'],
    annotation: [
      { type: 'Comportamiento', description: 'Como el albarán hasta elegir el proveedor: sin tipo no hay forma que limite los lados, así que la búsqueda y el selector de lado están cerrados.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    await iniciarSesion(page, enElAnterior(VISTA));

    for (const caja of ['#lado', '#idArticulo', '#Referencia', '#Codbarras', '#Descripcion']) {
      await expect(page.locator(caja)).toBeDisabled();
    }
    await page.locator('#idTipo').selectOption({ label: 'Despiece' });
    for (const caja of ['#lado', '#idArticulo', '#Referencia', '#Codbarras', '#Descripcion']) {
      await expect(page.locator(caja)).toBeEnabled();
    }
  });

  test('T17 la cabecera enseña los datos de lo que se prepara, con el número de temporal', {
    tag: ['@estado-actual', '@relaciones', '@vista'],
    annotation: [
      { type: 'Comportamiento', description: 'Como el albarán, en cajas de solo lectura: número de relación, versión que rige con el identificador de su fila, número de temporal, cuándo se empezó y quién lo guardó por última vez y cuándo. Con ellos el operario y el administrador encuentran el registro en la base.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    preparar(['referencias']);
    await iniciarSesion(page, enElAnterior(VISTA));

    await expect(page.locator('#datoRelacion')).toHaveValue('Nueva');
    await expect(page.locator('#datoTemporal')).toHaveValue('Sin guardar');
    await page.locator('#idTipo').selectOption({ label: 'Despiece' });
    await page.locator('#lado').selectOption('entrada');
    await buscar(page, 'Codbarras', 'E2E-PAVO-CB');
    await expect(filaDe(page, 'tablaEntrada', 'Pavo')).toHaveCount(1);
    await page.locator('#botonGuardar').click();
    await expect(page.locator('#datoTemporal')).toHaveValue(/^\d+$/);
    await expect(page.locator('#datoGuardado')).toHaveValue(new RegExp(process.env.TPVFOX_E2E_USUARIO));

    preparar(['temporal', '1']);
    await page.goto(enElAnterior(VISTA + '?id=1&accion=editar'));
    await expect(page.locator('#datoRelacion')).toHaveValue('1');
    await expect(page.locator('#datoVersion')).toHaveValue(/^1\.0 \(fila \d+\)$/);
    await expect(page.locator('#datoTemporal')).toHaveValue(/^\d+$/);
    await expect(page.locator('#datoInicio')).toHaveValue('2026-10-01 10:00:00');
    await expect(page.locator('#datoGuardado')).toHaveValue(process.env.TPVFOX_E2E_USUARIO + ', 2026-10-01 10:00:00');
  });

  test('T18 activar sin cantidades deja un solo aviso, amarillo, que dice qué línea y cómo resolverlo', {
    tag: ['@estado-actual', '@relaciones', '@vista', '@validacion'],
    annotation: [
      { type: 'Comportamiento', description: 'Un rechazo no sale nunca como éxito: «Activar» guarda antes, pero no enseña ese «Guardado». El aviso nombra la línea por su lado y su número, el de la columna L, y dice qué hacer.' },
    ],
  }, async ({ page }) => {
    dejarLasTablas('completo');
    preparar(['referencias']);
    await iniciarSesion(page, enElAnterior(VISTA));
    atenderDialogos(page);

    await page.locator('#idTipo').selectOption({ label: 'Despiece' });
    await page.locator('#lado').selectOption('entrada');
    await buscar(page, 'Codbarras', 'E2E-PAVO-CB');
    await expect(filaDe(page, 'tablaEntrada', 'Pavo')).toHaveCount(1);
    await buscar(page, 'Codbarras', 'E2E-MUSLO-CB');
    await expect(filaDe(page, 'tablaSalida', 'Muslo de pavo')).toHaveCount(1);
    await page.locator('#botonActivar').click();

    await expect(page.locator('#avisosRelacion .alert-warning')).toContainText('entrada 1');
    await expect(page.locator('#avisosRelacion .alert-warning')).toContainText('escríbela');
    await expect(page.locator('#avisosRelacion .alert')).toHaveCount(1);
    await expect(page).toHaveURL(/relacion\.php/);
  });
});

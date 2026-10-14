/**
 * Lo que las comprobaciones de acceso de las tareas programadas dejan pasar.
 *
 * El menú solo enseña la entrada al administrador, y sin sesión las pantallas piden iniciarla
 * (`tareas-acceso.spec.js`). Pero ninguna pantalla comprueba el permiso; las dos procesan lo que
 * reciben —el borrado y el guardado— antes de comprobar la sesión, y `tareas.php` no comprueba ni
 * la sesión ni el permiso.
 *
 * Las peticiones sin sesión se hacen sin navegador. Lo que queda escrito se lee de la base con
 * `support/leer-tarea.php`. El usuario sin permiso es el que declara el entorno
 * (`TPVFOX_E2E_USUARIO_SIN_PERMISO`); si no está declarado, sus recorridos se saltan.
 */

const { test, expect } = require('@playwright/test');
const { iniciarSesion, usuarioSinPermiso } = require('../../../fixtures/autenticacion');
const { LISTA, FICHA, EJECUCION, PREFIJO, leerTarea } = require('../../../fixtures/tareasCron');

const SIN_USUARIO = 'El entorno no declara TPVFOX_E2E_USUARIO_SIN_PERMISO/TPVFOX_E2E_CLAVE_SIN_PERMISO.';

// La respuesta de tareas.php cuando no encuentra la tarea. Dice que atendió la petición sin
// lanzar nada, así que estos recorridos no tocan la base de los demás.
const NO_ENCONTRADA = '{"data":null,"error":2}';

test.describe('Tareas programadas — lo que el acceso deja pasar', () => {
  for (const pantalla of [
    { numero: 1, nombre: 'la lista', ruta: LISTA, titulo: 'Listados de tareas' },
    { numero: 2, nombre: 'la ficha', ruta: FICHA, titulo: 'Crear Tarea' },
  ]) {
    test(`T${pantalla.numero} quien no es administrador y escribe la dirección de ${pantalla.nombre} vuelve al inicio`, {
      tag: ['@esperado', '@tareas', '@permisos', '@directo', '@alto'],
      annotation: [
        { type: 'Qué ocurre hoy', description: `Un usuario sin permiso sobre las tareas no ve la entrada en el menú, pero escribiendo la dirección de ${pantalla.nombre} se le sirve entera: puede dar de alta, modificar, borrar y ejecutar tareas.` },
        { type: 'Qué debería ocurrir', description: 'Que la pantalla compruebe el permiso del módulo y devuelva al inicio a quien no lo tiene, sin enseñar nada.' },
        { type: 'Por qué ocurre', description: 'El permiso del módulo solo lo consulta el menú para decidir si enseña la entrada; las pantallas no lo consultan.' },
        { type: 'Cómo debería funcionar', description: 'Comprobar el permiso del módulo al cargar cada pantalla, antes de procesar nada.' },
      ],
    }, async ({ page }) => {
      const sinPermiso = usuarioSinPermiso();
      test.skip(sinPermiso === null, SIN_USUARIO);

      await iniciarSesion(page, 'index.php', sinPermiso);
      await expect(page.locator('#usr'), 'La sesión del usuario sin permiso tiene que estar abierta').toHaveCount(0);
      await page.goto(pantalla.ruta);

      await expect(page).toHaveURL(/index\.php$/);
      await expect(page.getByRole('heading', { name: pantalla.titulo })).toHaveCount(0);
    });
  }

  test('T3 sin sesión, pedir el borrado de una tarea por su dirección no la borra', {
    tag: ['@esperado', '@tareas', '@permisos', '@directo', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Quien no ha iniciado sesión borra cualquier tarea pidiendo la dirección del borrado con su identificador. La respuesta es el formulario de acceso, pero la tarea ya no está.' },
      { type: 'Qué debería ocurrir', description: 'Que sin sesión no se borre nada.' },
      { type: 'Por qué ocurre', description: 'La lista procesa el borrado antes de incluir la cabecera que comprueba la sesión.' },
      { type: 'Cómo debería funcionar', description: 'Comprobar la sesión y el permiso antes de procesar nada de lo que se recibe.' },
    ],
  }, async ({ request }) => {
    const nombre = PREFIJO + 'Para borrar sin sesión';
    const antes = leerTarea(nombre);
    expect(antes, 'La siembra tiene que dejar la tarea').not.toBeNull();

    await request.get(`${LISTA}?accion=eliminar&tareaid=${antes.id}`);

    expect(leerTarea(nombre), 'Sin sesión no se puede borrar una tarea').not.toBeNull();
  });

  test('T4 sin sesión, enviar la ficha no da de alta la tarea', {
    tag: ['@esperado', '@tareas', '@permisos', '@directo', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Quien no ha iniciado sesión da de alta una tarea enviando la ficha, con la clase que quiera: también una ruta a otro fichero, que la ejecución incluiría después.' },
      { type: 'Qué debería ocurrir', description: 'Que sin sesión no se guarde nada.' },
      { type: 'Por qué ocurre', description: 'La ficha procesa el guardado antes de incluir la cabecera que comprueba la sesión.' },
      { type: 'Cómo debería funcionar', description: 'Comprobar la sesión y el permiso antes de procesar nada de lo que se recibe.' },
    ],
  }, async ({ request }) => {
    const nombre = PREFIJO + 'Creada sin sesión';

    // De baja, para que nada la lance si llega a guardarse.
    await request.post(`${FICHA}?accion=guardar`, {
      form: { id: '', nombre, cantidad_periodo: '60', tipo_periodo: '1', inicio_ejecucion: '2030-01-15', nombre_clase: 'AcumuladoComprasTarea', estado: '0' },
    });

    expect(leerTarea(nombre), 'Sin sesión no se puede dar de alta una tarea').toBeNull();
  });

  for (const caso of [
    { numero: 5, quien: 'quien no ha iniciado sesión', conUsuarioSinPermiso: false, gravedad: '@critico' },
    { numero: 6, quien: 'quien no es administrador', conUsuarioSinPermiso: true, gravedad: '@alto' },
  ]) {
    test(`T${caso.numero} tareas.php no atiende la petición de ejecutar una tarea de ${caso.quien}`, {
      tag: ['@esperado', '@tareas', '@permisos', '@directo', caso.gravedad],
      annotation: [
        { type: 'Qué ocurre hoy', description: `La petición de ejecutar una tarea de ${caso.quien} se atiende: tareas.php busca la tarea y, si existe, la lanza. Una tarea de coste recalcula en esa petición el coste de todos los artículos con compras.` },
        { type: 'Qué debería ocurrir', description: 'Que la petición se rechace sin buscar ni lanzar nada.' },
        { type: 'Por qué ocurre', description: 'tareas.php no incluye la cabecera que comprueba la sesión ni consulta el permiso.' },
        { type: 'Cómo debería funcionar', description: 'Comprobar la sesión y el permiso en tareas.php antes de nada.' },
      ],
    }, async ({ page, request }) => {
      let peticion = request;
      if (caso.conUsuarioSinPermiso) {
        const sinPermiso = usuarioSinPermiso();
        test.skip(sinPermiso === null, SIN_USUARIO);
        await iniciarSesion(page, 'index.php', sinPermiso);
        await expect(page.locator('#usr'), 'La sesión del usuario sin permiso tiene que estar abierta').toHaveCount(0);
        peticion = page.request;
      }

      // Un identificador que no es de ninguna tarea: la respuesta dice si se atendió, sin lanzar
      // nada.
      const respuesta = await peticion.post(EJECUCION, { form: { tareaid: '999999999' } });

      expect((await respuesta.text()).trim(), 'tareas.php no puede atender la petición').not.toBe(NO_ENCONTRADA);
    });
  }
});

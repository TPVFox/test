/**
 * Lo que debería pasar con una petición de fondo que llega sin sesión, en cualquier módulo.
 *
 * Toda petición de fondo pasa por el `tareas.php` de su módulo, que carga `inicial.php` y no la
 * cabecera de las pantallas, que es la única que comprueba la sesión. Sin ella, `inicial.php` sigue
 * con el usuario invitado. Los recorridos de venta ya documentan lo que ocurre allí; este pide la
 * misma lectura a otro módulo, el de clientes, para que se vea que no depende del módulo.
 *
 * El artículo lo deja la siembra de los recorridos de venta.
 */

const { test, expect } = require('@playwright/test');

const ARTICULO_SEMBRADO = '[E2E venta] Manzana Golden';

test.describe('Núcleo — petición sin sesión', () => {
  test('T1 una petición de fondo sin sesión no devuelve datos del negocio', {
    tag: ['@esperado', '@sesion', '@forzado', '@critico'],
    annotation: [
      { type: 'Qué ocurre hoy', description: 'Una petición sin sesión a las tareas del módulo de clientes devuelve la ficha de los artículos que coinciden con la búsqueda, con su precio.' },
      { type: 'Qué debería ocurrir', description: 'Que una petición sin sesión válida se rechace en el arranque, antes de llegar a ningún módulo.' },
      { type: 'Por qué ocurre', description: 'El arranque común de las peticiones deja seguir sin sesión, con el usuario invitado, y solo la cabecera de las pantallas corta el paso; tareas.php no la carga.' },
      { type: 'Cómo debería funcionar', description: 'Que el arranque común rechace la petición sin sesión válida, salvo las que la abren.' },
    ],
  }, async ({ request }) => {
    const respuesta = await request.post('modulos/mod_cliente/tareas.php', {
      form: { pulsado: 'leerArticulo', caja: 'Descripcion', valor: 'Manzana Golden' },
    });

    const cuerpo = await respuesta.json().catch(() => ({}));
    const nombres = (cuerpo.datos || []).map((articulo) => articulo.articulo_name);
    expect(nombres, 'Sin sesión no se entregan artículos').not.toContain(ARTICULO_SEMBRADO);
  });
});

/**
 * El JavaScript de los usuarios (`modulos/mod_usuario/funciones.js`): abrir la ficha del marcado o la
 * del alta, las acciones sobre varios usuarios y sus confirmaciones, quitar una configuración de módulo
 * y copiar los permisos de otro usuario en las casillas de la ficha.
 *
 * El fichero se ejecuta en un contexto propio de Node, con un jQuery de prueba que guarda el estado de
 * cada caja y apunta las peticiones sin enviarlas: el caso responde por la petición. La lista de
 * marcados, que en la página llena una función común, la da el caso.
 */

'use strict';

const path = require('path');
const { cargarScriptGlobal } = require('../../../support/js/cargarScriptGlobal');

const FUNCIONES = path.resolve(__dirname, '../../../../TPVFox/modulos/mod_usuario/funciones.js');

/** Una página de prueba, con los usuarios marcados y el valor de las cajas por su `#id`. */
function paginaDePrueba({ marcados = [], cajas = {}, confirma = true } = {}) {
  const pagina = { peticiones: [], avisos: [], modales: [], html: {}, casillas: {}, recargas: 0 };
  const $ = (selector) => ({
    val: () => cajas[selector.replace(/^#/, '')],
    html: (contenido) => { pagina.html[selector] = contenido; },
    prop: (nombre, valor) => { pagina.casillas[selector] = valor; },
  });
  $.ajax = (opciones) => { pagina.peticiones.push(opciones); };
  $.parseJSON = JSON.parse;
  pagina.contexto = cargarScriptGlobal(FUNCIONES, {
    $,
    checkID: [],
    alert: (texto) => { pagina.avisos.push(texto); },
    confirm: () => confirma,
    abrirModal: (titulo, html) => { pagina.modales.push({ titulo, html }); },
    location: { reload: () => { pagina.recargas += 1; } },
    window: { location: { href: '' } },
    console: { log() {} },
  });
  pagina.contexto.VerIdSeleccionado = () => { pagina.contexto.checkID = [...marcados]; };
  pagina.responder = (respuesta) => pagina.peticiones[pagina.peticiones.length - 1].success(JSON.stringify(respuesta));
  return pagina;
}

describe('en el listado de usuarios', () => {
  test('modificar abre la ficha del único usuario marcado', () => {
    const pagina = paginaDePrueba({ marcados: ['7'] });

    pagina.contexto.metodoClick('VerUsuario');

    expect(pagina.contexto.window.location.href).toBe('./usuario.php?id=7');
  });

  test('modificar sin ningún usuario marcado, o con dos, avisa y no abre nada', () => {
    const ninguno = paginaDePrueba();
    const dos = paginaDePrueba({ marcados: ['7', '8'] });

    ninguno.contexto.metodoClick('VerUsuario');
    dos.contexto.metodoClick('VerUsuario');

    expect(ninguno.avisos).toHaveLength(1);
    expect(dos.avisos).toHaveLength(1);
    expect(dos.contexto.window.location.href).toBe('');
  });

  test('añadir abre la ficha del alta', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.metodoClick('AgregarUsuario');

    expect(pagina.contexto.window.location.href).toBe('./usuario.php');
  });

  test('las acciones sobre varios usuarios envían los marcados y abren el modal con lo que responde la petición', () => {
    const pagina = paginaDePrueba({ marcados: ['7', '8'] });

    pagina.contexto.metodoClick('AccionesMultiples');
    pagina.responder({ html: '<h4>Acciones</h4>' });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'accionesMultiplesUsuarios', idsSeleccionados: ['7', '8'] });
    expect(pagina.peticiones[0].url).toBe('tareas.php');
    expect(pagina.modales).toEqual([{ titulo: 'Acciones Múltiples Usuarios', html: '<h4>Acciones</h4>' }]);
  });

  test('cada acción pide a la petición a quién alcanza y lo pinta en su submenú', () => {
    ['cambiarAnoUsuarios', 'inactivarUsuarios', 'eliminarUsuarios', 'activarUsuarios'].forEach((accion) => {
      const pagina = paginaDePrueba();
      pagina.contexto.checkID = ['7'];

      pagina.contexto[accion]();
      pagina.responder({ html: `<p>${accion}</p>` });

      expect(pagina.peticiones[0].data).toEqual({ pulsado: accion, idsSeleccionados: ['7'] });
      expect(pagina.html['#submenuAccionesMultiplesUsuarios']).toBe(`<p>${accion}</p>`);
    });
  });

  test('confirmar inactivar, borrar o activar envía los marcados, enseña el mensaje y recarga', () => {
    ['confirmarInactivarUsuarios', 'confirmarEliminarUsuarios', 'confirmarActivarUsuarios'].forEach((accion) => {
      const pagina = paginaDePrueba();
      pagina.contexto.checkID = ['7'];

      pagina.contexto[accion]();
      pagina.responder({ mensaje: 'Operación realizada con éxito.' });

      expect(pagina.peticiones[0].data).toEqual({ pulsado: accion, idsSeleccionados: ['7'] });
      expect(pagina.avisos).toEqual(['Operación realizada con éxito.']);
      expect(pagina.recargas).toBe(1);
    });
  });

  test('confirmar el cambio de año exige la contraseña nueva, y la envía con el administrador delante', () => {
    const sinClave = paginaDePrueba({ cajas: { nuevaContrasena: '  ' } });
    const conClave = paginaDePrueba({ cajas: { nuevaContrasena: 'Ano-2027' } });
    conClave.contexto.checkID = [7];

    sinClave.contexto.confirmarCambiarAnoUsuarios();
    conClave.contexto.confirmarCambiarAnoUsuarios();
    conClave.responder({ mensaje: 'Operación realizada con éxito.' });

    expect(sinClave.peticiones).toHaveLength(0);
    expect(sinClave.avisos).toHaveLength(1);
    expect(conClave.peticiones[0].data).toEqual({ pulsado: 'confirmarCambiarAnoUsuarios', idsSeleccionados: [1, 7], nuevaContrasena: 'Ano-2027' });
    expect(conClave.recargas).toBe(1);
  });

  /**
   * @etiquetas esperado medio
   * @estado rojo
   * @codigo-afectado modulos/mod_usuario/funciones.js:195-294
   *
   * @que-ocurre-hoy Si la petición de una confirmación falla, la pantalla no avisa: el modal se queda como
   *   estaba y no se sabe si la acción se hizo.
   * @que-deberia-ocurrir Que avise de que la acción no se pudo hacer.
   * @por-que-ocurre Las confirmaciones solo atienden la respuesta correcta de la petición.
   * @como-deberia-funcionar Atender también el fallo de la petición y avisar con un mensaje.
   */
  test('confirmar, si la petición falla, avisa de que no se hizo', () => {
    const pagina = paginaDePrueba();
    pagina.contexto.checkID = ['7'];

    pagina.contexto.confirmarEliminarUsuarios();
    if (pagina.peticiones[0].error) {
      pagina.peticiones[0].error({ status: 500 }, 'error', 'Internal Server Error');
    }

    expect(pagina.avisos).toHaveLength(1);
  });
});

describe('en la ficha del usuario', () => {
  test('quitar una configuración pide confirmación, la pide a la petición y recarga', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.eliminarConfiguracionModulo(7, 'mod_producto');
    pagina.responder([]);

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'eliminarConfigModulo', idUsuario: 7, modulo: 'mod_producto' });
    expect(pagina.recargas).toBe(1);
  });

  test('sin confirmar, no se quita la configuración', () => {
    const pagina = paginaDePrueba({ confirma: false });

    pagina.contexto.eliminarConfiguracionModulo(7, 'mod_producto');

    expect(pagina.peticiones).toHaveLength(0);
  });

  test('copiar los permisos de otro usuario marca y desmarca las casillas por su posición', () => {
    const pagina = paginaDePrueba({ cajas: { usuario: '8' } });

    pagina.contexto.copiarPermisosUsuario();
    pagina.responder({ permisosUsuario: { resultado: [{ permiso: 1 }, { permiso: 0 }, { permiso: '1' }] } });

    expect(pagina.peticiones[0].data).toEqual({ pulsado: 'copiarPermisosUsuario', usuarioNuevo: '8' });
    expect(pagina.casillas).toEqual({ '.permiso_0': true, '.permiso_1': false, '.permiso_2': true });
  });

  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_usuario/funciones.js:70-78
   *
   * @que-ocurre-hoy Si la base rechaza quitar una configuración, la ficha enseña la sentencia en un aviso.
   * @que-deberia-ocurrir Que diga que no se pudo quitar, sin enseñar la sentencia.
   * @por-que-ocurre El aviso escribe la sentencia que devuelve la petición.
   * @como-deberia-funcionar Enseñar un mensaje para el usuario y dejar la sentencia en el registro de errores.
   */
  test('un error al quitar la configuración no enseña la sentencia', () => {
    const pagina = paginaDePrueba();

    pagina.contexto.eliminarConfiguracionModulo(7, 'mod_producto');
    pagina.responder({ error: '1064', consulta: 'DELETE FROM `modulos_configuracion` WHERE idusuario=7' });

    expect(pagina.avisos.join(' ')).not.toContain('DELETE FROM');
  });
});

describe('en las acciones sobre varios usuarios', () => {
  /**
   * @etiquetas esperado bajo
   * @estado rojo
   * @codigo-afectado modulos/mod_usuario/tareas/AccionesMultiplesUsuarios.php:22
   * @codigo-afectado modulos/mod_usuario/funciones.js:115-121
   *
   * @que-ocurre-hoy El botón «Asignar Grupo» de las acciones sobre varios usuarios no hace nada al pulsarlo:
   *   la función que llama no existe.
   * @que-deberia-ocurrir Que asigne un grupo a los usuarios marcados, o que no se ofrezca.
   * @por-que-ocurre La petición pinta el botón con una función que el fichero anuncia en un comentario y no
   *   define.
   * @como-deberia-funcionar Definir la función y su orden en la petición, o quitar el botón.
   */
  test('el botón de asignar grupo llama a una función que existe', () => {
    const pagina = paginaDePrueba();

    expect(typeof pagina.contexto.asignarGrupoUsuarios).toBe('function');
  });
});

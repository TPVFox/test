/**
 * El motor de teclado de las pantallas (`lib/js/teclado.js`): cada caja de la pantalla declara, en
 * una variable global con su nombre, sus teclas y sus acciones; al pulsar una tecla, perder el foco o
 * hacer clic, `controlEventos()` construye la caja, busca la acción de la tecla y la lanza.
 *
 * Se carga en una página de jsdom con el jQuery del propio producto, como lo cargan las pantallas.
 */

'use strict';

const fs = require('fs');
const path = require('path');

const TPVFOX = path.resolve(__dirname, '../../../../TPVFox');

/** Ejecuta un fichero del producto en la página, como si lo cargara una etiqueta <script>. */
function cargarEnLaPagina(relativa) {
  window.eval(fs.readFileSync(path.join(TPVFOX, relativa), 'utf8'));
}

/** Un evento sobre la caja de la página, con la tecla que se pida. */
function evento(tipo, tecla) {
  return { type: tipo, keyCode: tecla, target: document.getElementById('campo') };
}

beforeAll(() => {
  jest.spyOn(console, 'log').mockImplementation(() => {});
  cargarEnLaPagina('jquery/jquery-2.2.5-pre.min.js');
  cargarEnLaPagina('lib/js/teclado.js');
});

beforeEach(() => {
  document.body.innerHTML = '<input id="campo" name="Codbarras" data-obj="cajaPrueba" value="8412345">';
  window.cajaPrueba = { id_input: 'campo', acciones: { 13: 'buscarProducto', 40: 'AccionBajar' } };
  window.controladorAcciones = jest.fn();
  window.AccionBajar = jest.fn();
  window.alert = jest.fn();
  delete window.after_constructor;
  delete window.before_constructor;
});

describe('la caja', () => {
  /**
   * @etiquetas estado-actual teclado
   *
   * @comportamiento La caja da la acción de una tecla declarada, «error» para una que no lo está,
   *   sus parámetros si los tiene y el valor de su campo en la página.
   */
  test('da la acción de cada tecla, sus parámetros y el valor del campo', () => {
    const caja = new window.cajainput({ ...window.cajaPrueba, parametros: { dedonde: 'tpv' } });

    expect(caja.darAccion(13)).toBe('buscarProducto');
    expect(caja.darAccion(27)).toBe('error');
    expect(caja.darParametro('dedonde')).toBe('tpv');
    expect(caja.darParametro('otro')).toBeUndefined();
    expect(new window.cajainput(window.cajaPrueba).darParametro('dedonde')).toBeUndefined();
    expect(caja.darValor()).toBe('8412345');
  });
});

describe('pulsar una tecla', () => {
  /**
   * @etiquetas estado-actual teclado
   *
   * @comportamiento Con una tecla declarada se llama al controlador de acciones de la pantalla con
   *   la caja, la acción y la tecla; la caja lleva el nombre del campo, el tipo de evento y la tecla.
   */
  test('lanza la acción de la tecla en el controlador de la pantalla', () => {
    window.controlEventos(evento('keydown', 13));

    expect(window.controladorAcciones).toHaveBeenCalledTimes(1);
    const [caja, accion, tecla] = window.controladorAcciones.mock.calls[0];
    expect([accion, tecla]).toEqual(['buscarProducto', 13]);
    expect([caja.name_cja, caja.tipo_event, caja.tecla]).toEqual(['Codbarras', 'keydown', 13]);
  });

  /**
   * @etiquetas estado-actual teclado
   *
   * @comportamiento Una acción cuyo nombre contiene «Accion» se lanza directamente, como función
   *   global con la caja y la tecla, sin pasar por el controlador de la pantalla.
   */
  test('una acción con «Accion» en el nombre se lanza directamente', () => {
    window.controlEventos(evento('keydown', 40));

    expect(window.AccionBajar).toHaveBeenCalledTimes(1);
    expect(window.AccionBajar.mock.calls[0][1]).toBe(40);
    expect(window.controladorAcciones).not.toHaveBeenCalled();
  });

  /**
   * @etiquetas estado-actual teclado
   *
   * @comportamiento Una tecla sin acción declarada llega al controlador como «error».
   */
  test('una tecla sin acción llega al controlador como error', () => {
    window.controlEventos(evento('keydown', 27));

    expect(window.controladorAcciones.mock.calls[0][1]).toBe('error');
  });

  /**
   * @etiquetas estado-actual teclado
   *
   * @comportamiento Si la caja declara los ganchos, `after_constructor` corre antes de construirla
   *   —con la declaración y el evento— y `before_constructor` después, con la caja ya construida: los
   *   nombres van al revés de lo que hacen.
   */
  test('los ganchos corren antes y después de construir la caja', () => {
    const orden = [];
    window.cajaPrueba.after_constructor = 'Si';
    window.cajaPrueba.before_constructor = 'Si';
    window.after_constructor = jest.fn((declaracion) => { orden.push('after'); return declaracion; });
    window.before_constructor = jest.fn((caja) => { orden.push('before'); return caja; });

    window.controlEventos(evento('keydown', 13));

    expect(orden).toEqual(['after', 'before']);
    expect(window.before_constructor.mock.calls[0][0]).toBeInstanceOf(window.cajainput);
  });
});

describe('perder el foco o hacer clic', () => {
  /**
   * @etiquetas estado-actual teclado
   *
   * @comportamiento Perder el foco, hacer clic o cambiar el valor hace lo mismo que Intro. Con el
   *   parámetro `pulsado_intro`, la primera vez lo marca y la acción se lanza; con él ya marcado, no se
   *   repite.
   */
  test('hace lo mismo que Intro, una sola vez si la caja lo controla', () => {
    window.cajaPrueba.parametros = { pulsado_intro: 'No' };

    window.controlEventos(evento('blur'));
    expect(window.controladorAcciones).toHaveBeenCalledTimes(1);
    expect(window.controladorAcciones.mock.calls[0][1]).toBe('buscarProducto');
    expect(window.cajaPrueba.parametros.pulsado_intro).toBe('Si');

    window.controlEventos(evento('click'));
    expect(window.controladorAcciones).toHaveBeenCalledTimes(1);
  });

  /**
   * @etiquetas estado-actual teclado
   *
   * @comportamiento El control de Intro solo lo leen y lo marcan perder el foco y el clic: pulsar
   *   Intro lanza la acción sin marcarlo, y perder el foco después la vuelve a lanzar. Sin el
   *   control, cada clic y cada pérdida del foco la lanzan.
   */
  test('el control de Intro solo lo marcan perder el foco y el clic', () => {
    window.cajaPrueba.parametros = { pulsado_intro: 'No' };

    window.controlEventos(evento('keydown', 13));
    expect(window.cajaPrueba.parametros.pulsado_intro).toBe('No');
    window.controlEventos(evento('blur'));
    expect(window.controladorAcciones).toHaveBeenCalledTimes(2);
    expect(window.cajaPrueba.parametros.pulsado_intro).toBe('Si');

    delete window.cajaPrueba.parametros;
    window.controladorAcciones.mockClear();
    window.controlEventos(evento('click'));
    window.controlEventos(evento('blur'));
    expect(window.controladorAcciones).toHaveBeenCalledTimes(2);
  });
});

describe('una caja que la pantalla no declara', () => {
  /**
   * @etiquetas esperado teclado bajo
   * @estado rojo
   * @codigo-afectado lib/js/teclado.js:81-100
   *
   * @que-ocurre-hoy Perder el foco en un campo cuya caja no está declarada avisa de que el objeto no
   *   se reconoce y, después, falla con un error de JavaScript al buscar sus parámetros.
   * @que-deberia-ocurrir Que avise y no haga nada más.
   * @por-que-ocurre Tras el aviso, el caso de perder el foco usa la caja, que no llegó a construirse.
   * @como-deberia-funcionar Salir tras el aviso cuando la caja no existe.
   */
  test('avisa y no hace nada más', () => {
    document.getElementById('campo').dataset.obj = 'cajaQueNoExiste';

    expect(() => window.controlEventos(evento('blur'))).not.toThrow();
    expect(window.alert).toHaveBeenCalledWith(' Objeto a crear no reconocido ');
  });

  /**
   * @etiquetas estado-actual teclado
   *
   * @comportamiento Pulsar una tecla en un campo cuya caja no está declarada avisa de que el objeto
   *   no se reconoce y no lanza nada.
   */
  test('con una tecla, avisa y no lanza nada', () => {
    document.getElementById('campo').dataset.obj = 'cajaQueNoExiste';

    window.controlEventos(evento('keydown', 13));

    expect(window.alert).toHaveBeenCalledWith(' Objeto a crear no reconocido ');
    expect(window.controladorAcciones).not.toHaveBeenCalled();
  });
});

describe('una acción con «Accion» que trae código', () => {
  /**
   * @etiquetas esperado teclado bajo
   * @estado rojo
   * @codigo-afectado lib/js/teclado.js:108-113
   *
   * @que-ocurre-hoy El nombre de una acción con «Accion» se ejecuta como código: si el nombre que
   *   llega de los parámetros del módulo trae algo más que el nombre de una función, eso también se
   *   ejecuta.
   * @que-deberia-ocurrir Que solo se llame a una función declarada con ese nombre, y que un nombre
   *   que no lo es no ejecute nada.
   * @por-que-ocurre La llamada se compone pegando el nombre y se lanza con `eval`.
   * @como-deberia-funcionar Buscar la función por su nombre en la página y llamarla, sin `eval`.
   */
  test('no ejecuta el código del nombre', () => {
    window.cajaPrueba.acciones[40] = "AccionBajar;window.marca='ejecutado';AccionBajar";
    delete window.marca;

    window.controlEventos(evento('keydown', 40));

    expect(window.marca).toBeUndefined();
  });
});

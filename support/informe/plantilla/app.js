'use strict';

/**
 * La vista del informe de pruebas.
 *
 * Carga solo el indice al abrir —una fila por caso, unos cientos de KB— y pide la ficha de un
 * caso y el fuente que recorre cuando hacen falta. Asi el arranque no depende de cuantos
 * casos haya ni de cuanta traza lleven.
 *
 * Sin dependencias y sin paso de compilacion: se sirve tal cual.
 */

const estado = {
  casos: [],
  citas: {},
  texto: '',
  resultado: 'todos',
  etiquetas: new Set(),
  abierto: null,
  fuentes: new Map(),
  codigo: null,
  ids: null,
};

const $ = (id) => document.getElementById(id);

init();

async function init() {
  const datos = await (await fetch('datos/indice.json')).json();
  estado.casos = datos.casos;
  estado.citas = datos.citas || {};

  $('resumen').textContent =
    `${datos.casos.length} casos · generado el ${new Date(datos.generado).toLocaleString('es-ES')}`;

  pintarTarjetas();
  pintarResultados();
  pintarEtiquetas();
  pintarLista();

  $('pestanas').addEventListener('click', (ev) => {
    const boton = ev.target.closest('.pestana');
    if (boton) abrirPestana(boton.dataset.panel);
  });

  $('buscador').addEventListener('input', (e) => {
    estado.texto = e.target.value.toLowerCase().trim();
    pintarLista();
  });
}

function pintarTarjetas() {
  const rojos = estado.casos.filter((c) => c.estado === 'fallo' || c.estado === 'error').length;
  const avisos = estado.casos.reduce((n, c) => n + c.avisos, 0);
  const conFlujo = estado.casos.filter((c) => c.consultas > 0).length;
  const citas = Object.values(estado.citas).reduce((n, l) => n + l.length, 0);

  $('tarjetas').innerHTML = [
    tarjeta('Casos', estado.casos.length),
    tarjeta('En rojo', rojos, rojos ? 'rojo' : ''),
    tarjeta('Con flujo del dato', conFlujo),
    tarjeta('Con aviso', avisos, avisos ? 'aviso' : ''),
    tarjeta('Citas de calidad', citas, citas ? 'aviso' : ''),
  ].join('');
}

const tarjeta = (titulo, valor, clase = '') =>
  `<div class="tarjeta ${clase}"><span class="valor">${valor}</span><span class="titulo">${esc(titulo)}</span></div>`;

/**
 * Los filtros por resultado, con su recuento.
 *
 * Mismos cuatro que el informe de navegador y en el mismo orden, para que quien use los dos no
 * tenga que aprender nada nuevo.
 */
function pintarResultados() {
  const cuenta = {
    todos: estado.casos.length,
    pasan: estado.casos.filter((c) => c.estado === 'verde').length,
    rojo: estado.casos.filter((c) => c.estado === 'fallo' || c.estado === 'error').length,
    omitidos: estado.casos.filter((c) => c.estado === 'omitido').length,
  };

  const nombres = { todos: 'Todos', pasan: 'Pasan', rojo: 'En rojo', omitidos: 'Omitidos' };

  $('resultados').innerHTML = Object.entries(nombres)
    .map(([clave, nombre]) =>
      `<button class="chip ${clave === estado.resultado ? 'activa' : ''}" data-resultado="${clave}">${nombre} <span class="tenue">${cuenta[clave]}</span></button>`)
    .join('');

  $('resultados').addEventListener('click', (ev) => {
    const boton = ev.target.closest('.chip');
    if (!boton) return;
    estado.resultado = boton.dataset.resultado;
    pintarResultados();
    pintarLista();
  });
}

/** Las etiquetas que existen de verdad, con cuantos casos las llevan. */
function pintarEtiquetas() {
  const cuenta = new Map();
  for (const caso of estado.casos) {
    for (const etiqueta of caso.etiquetas) {
      cuenta.set(etiqueta, (cuenta.get(etiqueta) || 0) + 1);
    }
  }

  const ordenadas = [...cuenta.entries()].sort((a, b) => b[1] - a[1]);
  $('etiquetas').innerHTML = ordenadas
    .map(([e, n]) => `<button class="chip" data-etiqueta="${esc(e)}">@${esc(e)} <span class="tenue">${n}</span></button>`)
    .join('');

  $('etiquetas').addEventListener('click', (ev) => {
    const boton = ev.target.closest('.chip');
    if (!boton) return;
    const etiqueta = boton.dataset.etiqueta;
    estado.etiquetas.has(etiqueta) ? estado.etiquetas.delete(etiqueta) : estado.etiquetas.add(etiqueta);
    boton.classList.toggle('activa');
    pintarLista();
  });
}

function casosVisibles() {
  return estado.casos.filter((caso) => {
    if (estado.ids && !estado.ids.has(caso.id)) return false;

    const rojo = caso.estado === 'fallo' || caso.estado === 'error';
    if (estado.resultado === 'pasan' && caso.estado !== 'verde') return false;
    if (estado.resultado === 'rojo' && !rojo) return false;
    if (estado.resultado === 'omitidos' && caso.estado !== 'omitido') return false;

    for (const etiqueta of estado.etiquetas) {
      if (!caso.etiquetas.includes(etiqueta)) return false;
    }
    if (estado.texto === '') return true;

    const heno = [caso.clase, caso.metodo, caso.frase, caso.etiquetas.join(' '), caso.ficheros.join(' ')]
      .join(' ')
      .toLowerCase();

    return estado.texto.split(/\s+/).every((aguja) => heno.includes(aguja.replace(/^@/, '')));
  });
}

function pintarLista() {
  const visibles = casosVisibles();
  $('cuenta').innerHTML = estado.ids
    ? `${visibles.length} casos que ejercitan <b>${esc(estado.motivo || '')}</b> · <button class="chip" id="quitar">quitar filtro</button>`
    : `${visibles.length} de ${estado.casos.length} casos`;

  const quitar = $('quitar');
  if (quitar) {
    quitar.addEventListener('click', () => {
      estado.ids = null;
      estado.motivo = '';
      pintarLista();
    });
  }

  $('lista').innerHTML = visibles
    .map((caso) => {
      const rojo = caso.estado === 'fallo' || caso.estado === 'error';
      const marcas = [];
      if (caso.avisos) marcas.push('⚠ aviso');
      if (caso.consultas) marcas.push(`${caso.consultas} consultas`);
      if (caso.declara) marcas.push(`declara ${caso.declara}`);

      return `<button class="fila ${rojo ? 'rojo' : 'verde'} ${estado.abierto === caso.id ? 'abierta' : ''}" data-id="${caso.id}">
        <span class="frase">${esc(caso.frase)}</span>
        <span class="meta"><span>${esc(caso.clase)}</span><span>${(caso.tiempo * 1000).toFixed(0)} ms</span></span>
        ${marcas.length ? `<span class="marcas">${esc(marcas.join(' · '))}</span>` : ''}
      </button>`;
    })
    .join('');
}

$('lista').addEventListener('click', (ev) => {
  const fila = ev.target.closest('.fila');
  if (fila) abrirCaso(fila.dataset.id);
});

async function abrirCaso(id) {
  estado.abierto = id;
  pintarLista();

  const caso = await (await fetch(`datos/casos/${id}.json`)).json();
  const rojo = caso.estado === 'fallo' || caso.estado === 'error';

  let html = `<h2>${esc(caso.frase)} <span class="estado ${rojo ? 'rojo' : 'verde'}">${rojo ? caso.estado : 'pasa'}</span></h2>
    <p class="metodo">${esc(caso.clase)}::${esc(caso.metodo)}</p>
    <p>${caso.etiquetas.map((e) => `<span class="etiqueta">@${esc(e)}</span>`).join('')}</p>`;

  for (const aviso of caso.avisos) {
    html += `<div class="aviso-caja">⚠ ${esc(aviso)}</div>`;
  }

  html += pintarAnotaciones(caso);
  if (caso.mensaje) html += `<h3>Por qué falla</h3><pre class="mensaje">${esc(caso.mensaje.slice(0, 2000))}</pre>`;

  html += pintarCamino(caso.flujo);
  html += pintarFlujo(caso.flujo);
  html += pintarArbol(caso.flujo);
  html += pintarCobertura(caso, caso.flujo);

  $('ficha').innerHTML = html;
  $('ficha').querySelectorAll('[data-fuente]').forEach((el) => {
    el.addEventListener('toggle', () => cargarFuente(el));
  });
}

/** Los nombres de las seis anotaciones, en el orden en que se leen. */
const ANOTACIONES = [
  ['que-ocurre-hoy', 'Qué ocurre hoy'],
  ['que-deberia-ocurrir', 'Qué debería ocurrir'],
  ['por-que-ocurre', 'Por qué ocurre'],
  ['como-deberia-funcionar', 'Cómo debería funcionar'],
  ['comportamiento', 'Comportamiento'],
  ['para-que-sirve', 'Para qué sirve'],
];

/**
 * Lo que el caso dice de si mismo: las anotaciones si las declara, y si no su prosa.
 *
 * Las anotaciones mandan sobre la prosa porque son lo mismo mejor ordenado; cuando hay
 * ambas, la prosa queda debajo como contexto.
 */
function pintarAnotaciones(caso) {
  const puestas = ANOTACIONES.filter(([clave]) => caso.anotaciones && caso.anotaciones[clave]);

  if (!puestas.length) {
    return caso.prosa ? `<h3>Qué valida</h3><p>${esc(caso.prosa)}</p>` : '';
  }

  let html = '<h3>Qué valida</h3><dl class="anotaciones">';
  for (const [clave, titulo] of puestas) {
    html += `<dt>${esc(titulo)}</dt><dd>${esc(caso.anotaciones[clave])}</dd>`;
  }
  html += '</dl>';

  if (caso.prosa) html += `<p class="tenue">${esc(caso.prosa)}</p>`;

  return html;
}

/**
 * Por donde paso el dato, fichero a fichero.
 *
 * Responde a la pregunta que la cobertura no responde: un fichero sale medio verde y no dice
 * por que serie de entradas se llego hasta ahi. Aqui se ve el orden.
 */
function pintarCamino(flujo) {
  if (!flujo || !flujo.camino || !flujo.camino.length) return '';

  const resumen = flujo.caminoResumen;

  // Un bucle que salta entre dos ficheros no cuenta una historia distinta cada vuelta: cuenta
  // la misma muchas veces. Cuando eso pasa se ensena cada fichero una vez, con sus visitas.
  if (resumen && resumen.resumido) {
    const filas = resumen.tramos
      .map((tramo) => `<li class="tramo fase-${esc(tramo.fase)}">
          <span class="nombre">${esc(tramo.fase === 'ejercicio' ? cortar(tramo.fichero) : tramo.fichero)}</span>
          <span class="cuantas">${tramo.llamadas} en ${tramo.visitas} visitas</span>
        </li>`)
      .join('');

    return `<h3>Por dónde pasó — ${resumen.tramos.length} ficheros</h3>
      <p class="tenue">El caso entra y sale de los mismos ficheros muchas veces
      (${flujo.camino.length} tramos), así que van una vez cada uno, en el orden de aparición.</p>
      <ol class="camino">${filas}</ol>`;
  }

  const tramos = flujo.camino
    .map((tramo) => {
      const esProducto = tramo.fase === 'ejercicio';
      const nombre = esProducto ? cortar(tramo.fichero) : tramo.fichero;
      const detalle = tramo.funciones.length ? tramo.funciones.map(cortarFuncion).join(', ') : '';

      return `<li class="tramo fase-${esc(tramo.fase)}" ${detalle ? `title="${esc(detalle)}"` : ''}>
        <span class="nombre">${esc(nombre)}</span>
        <span class="cuantas">${tramo.llamadas}</span>
      </li>`;
    })
    .join('');

  return `<h3>Por dónde pasó — ${flujo.camino.length} tramos</h3><ol class="camino">${tramos}</ol>`;
}

/** El ultimo tramo de un nombre de funcion: `Clase->metodo` sin su espacio de nombres. */
const cortarFuncion = (nombre) => String(nombre).split('\\').pop();

/**
 * La cadena de llamadas como arbol, con su profundidad y su duracion.
 *
 * Es el detalle completo debajo del camino: el camino dice por donde, y esto dice como.
 */
function pintarArbol(flujo) {
  if (!flujo || !flujo.llamadas || !flujo.llamadas.length) return '';

  const minimo = Math.min(...flujo.llamadas.map((l) => l.nivel));
  const filas = flujo.llamadas
    .slice(0, 300)
    .map((l) => {
      const sangria = Math.min(l.nivel - minimo, 10);
      const args = (l.argumentos || []).join(', ');

      return `<li style="padding-left:${sangria * 14}px">
        <code>${esc(cortarFuncion(l.nombre))}</code>${args ? `<span class="args">(${esc(args.slice(0, 70))})</span>` : ''}
        <span class="tenue"> ${esc(cortar(l.fichero))}:${l.linea}${l.ms !== null ? ` · ${l.ms} ms` : ''}</span>
        ${l.retorno ? `<span class="retorno">→ ${esc(String(l.retorno).slice(0, 60))}</span>` : ''}
      </li>`;
    })
    .join('');

  return `<details class="arbol"><summary>Pasos, uno a uno (${flujo.llamadas.length})</summary><ul>${filas}</ul></details>`;
}

function pintarFlujo(flujo) {
  if (!flujo || (!flujo.pasos.length && !flujo.llamadas.length)) {
    return '<h3>Flujo del dato</h3><p class="tenue">Este caso no consulta la base: lo que hace es cálculo, y su recorrido está en la cadena de llamadas.</p>';
  }

  let html = '';

  if (flujo.dadoQue) html += `<h3>Dado que</h3><p>${esc(flujo.dadoQue)}</p>`;

  if (flujo.pasos.length) {
    html += `<h3>Qué hizo el dato — ${flujo.consultas} consultas</h3>`;
    if (flujo.recortado) html += '<div class="aviso-caja">La sucesión está recortada: el caso hizo más de lo que se muestra.</div>';

    for (const paso of flujo.pasos) {
      html += `<div class="paso fase-${esc(paso.fase)}">
        ${paso.veces > 1 ? `<span class="veces">${paso.veces}× </span>` : ''}<code>${esc(paso.sql)}</code>
        <span class="quien">${esc(paso.origen || paso.funcion)} · ${esc(cortar(paso.fichero))}:${paso.linea}${
          paso.error ? ` · <b>error:</b> ${esc(paso.error.slice(0, 90))}` : ` · ${describirSalida(paso)}`
        }</span>
      </div>`;
    }
  }

  return html;
}

const describirSalida = (paso) => {
  const partes = [];
  if (paso.filasDevueltas !== undefined) partes.push(`${paso.filasDevueltas} filas`);
  if (paso.filasAfectadas !== undefined && paso.filasAfectadas >= 0) partes.push(`afecta ${paso.filasAfectadas}`);
  if (paso.idInsertado) partes.push(`id ${paso.idInsertado}`);
  return partes.join(', ');
};

function pintarCobertura(caso, flujo) {
  const rutas = Object.entries(caso.cobertura || {});
  if (!rutas.length) return '';

  // Ordenado como el recorrido, no por cuantas lineas tiene cada fichero. Una lista suelta de
  // ficheros con su recuento no cuenta nada; lo que se quiere saber es donde empieza el
  // recorrido, por donde sigue y donde acaba. Lo que la cobertura ve y el recorrido no toca
  // va detras, dicho.
  const posicion = new Map();
  for (const tramo of (flujo && flujo.camino) || []) {
    if (tramo.fase === 'ejercicio' && !posicion.has(tramo.fichero)) posicion.set(tramo.fichero, posicion.size);
  }

  const lugarDe = (ruta) => {
    for (const [fichero, donde] of posicion) {
      if (fichero.endsWith(ruta) || ruta.endsWith(fichero)) return donde;
    }
    return null;
  };

  const enOrden = [...rutas].sort((a, b) => {
    const pa = lugarDe(a[0]);
    const pb = lugarDe(b[0]);
    if (pa !== null && pb !== null) return pa - pb;
    if (pa !== null) return -1;
    if (pb !== null) return 1;
    return b[1].lineas.length - a[1].lineas.length;
  });

  // En JS no hay cobertura por caso: lo que se ensena es la funcion que el caso prueba.
  let html = caso.medido === false
    ? '<h3>Código que revisa</h3><p class="tenue">Sin cobertura por caso: lo que se marca es la función que el caso prueba, no lo que se midió al ejecutarlo.</p>'
    : `<h3>Código que recorre — ${rutas.length} ficheros, en el orden del recorrido</h3>`;

  let paso = 0;
  for (const [ruta, datos] of enOrden) {
    const enElRecorrido = lugarDe(ruta) !== null;
    if (enElRecorrido) paso++;

    html += `<details class="paso-codigo" data-fuente="${datos.id}" data-lineas="${datos.lineas.join(',')}">
      <summary>
        <span class="orden">${enElRecorrido ? paso : '·'}</span>
        <code>${esc(ruta)}</code>
        <span class="tenue">${datos.lineas.length} líneas${enElRecorrido ? '' : ' · fuera del recorrido'}</span>
      </summary>
      <div class="cuerpo tenue">Cargando…</div>
    </details>`;
  }

  return html;
}

/** Trae el fuente de TPVFox y lo pinta marcando lo que el caso ejecuto. */
async function cargarFuente(detalle) {
  if (!detalle.open || detalle.dataset.cargado) return;
  detalle.dataset.cargado = '1';

  const id = detalle.dataset.fuente;
  if (!estado.fuentes.has(id)) {
    estado.fuentes.set(id, await (await fetch(`datos/fuentes/${id}.json`)).json());
  }

  const fuente = estado.fuentes.get(id);
  const ejecutadas = new Set(detalle.dataset.lineas.split(',').map(Number));
  const primera = Math.max(1, Math.min(...ejecutadas) - 4);
  const ultima = Math.min(fuente.lineas.length, Math.max(...ejecutadas) + 4);

  let filas = '';
  for (let n = primera; n <= ultima; n++) {
    filas += `<tr class="${ejecutadas.has(n) ? 'ejecutada' : ''}"><td class="n">${n}</td><td>${esc(fuente.lineas[n - 1] || '')}</td></tr>`;
  }

  detalle.querySelector('.cuerpo').outerHTML = `<table class="fuente">${filas}</table>`;
}

const cortar = (ruta) => String(ruta).split('/').pop();

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}


// ── Las otras dos pestañas: el código y los datos ───────────────────────────────────────

/**
 * Cambia de pestaña, cargando la vista por código la primera vez que hace falta.
 *
 * Los datos del código van en su propio fichero y no se piden al arrancar: quien solo quiere
 * mirar un caso no debe pagar por una vista que no ha abierto.
 */
async function abrirPestana(cual) {
  for (const boton of document.querySelectorAll('.pestana')) {
    boton.classList.toggle('activa', boton.dataset.panel === cual);
  }
  for (const panel of ['casos', 'codigo', 'datos']) {
    $(`panel-${panel}`).classList.toggle('oculto', panel !== cual);
  }

  if (cual === 'casos') return;

  if (!estado.codigo) {
    estado.codigo = await (await fetch('datos/codigo.json')).json();
  }

  if (cual === 'codigo' && !$('panel-codigo').innerHTML) pintarCodigo();
  if (cual === 'datos' && !$('panel-datos').innerHTML) pintarDatos();
}

/** Una barra de proporción: lo que se compara de un vistazo sin leer números. */
function barra(texto, cuantos, maximo, datos = '') {
  const ancho = maximo ? Math.max(2, Math.round((cuantos / maximo) * 100)) : 0;

  return `<div class="barra ${datos ? 'pulsable' : ''}" ${datos}>
    <div class="relleno"><span style="width:${ancho}%"></span><div class="texto">${texto}</div></div>
    <div class="cuenta">${cuantos}</div>
  </div>`;
}

const chipTabla = (nombre, acceso) =>
  `<span class="tabla-chip ${acceso.toLowerCase()}" title="${acceso === 'R' ? 'solo lectura' : acceso === 'W' ? 'escritura' : 'lectura y escritura'}">${esc(nombre)}</span>`;

/** Qué código de TPVFox ejercitan las pruebas, fichero a fichero y función a función. */
function pintarCodigo() {
  const ficheros = estado.codigo.ficheros;
  const tope = Math.max(...ficheros.map((f) => f.casos));

  let html = `<p class="tenue">${ficheros.length} ficheros de producto ejercitados. Cada función dice qué tablas mueve y con cuántos casos cuenta; al pulsarla se ven esos casos.</p>`;

  for (const fichero of ficheros) {
    const funciones = fichero.funciones
      .map((fn) => {
        const tablas = Object.entries(fn.tablas)
          .map(([nombre, acceso]) => chipTabla(nombre, acceso))
          .join('');

        return barra(
          `${esc(cortarFuncion(fn.nombre))} ${tablas}`,
          fn.casos.length,
          tope,
          `data-casos="${fn.casos.join(',')}" data-motivo="${esc(cortarFuncion(fn.nombre))}"`
        );
      })
      .join('');

    html += `<details class="grupo">
      <summary><code>${esc(fichero.ruta)}</code><span class="ubicacion">${esc(fichero.tipo)} · ${esc(fichero.modulo)}</span> <span class="tenue">· ${fichero.casos} casos · ${fichero.funciones.length} funciones</span></summary>
      <div class="cuerpo">${funciones}${matriz(fichero.funciones)}</div>
    </details>`;
  }

  $('panel-codigo').innerHTML = html;
}

/** Qué datos mueve el producto: una tabla por fila, con quién la toca. */
function pintarDatos() {
  const tablas = estado.codigo.tablas;
  const tope = Math.max(...tablas.map((t) => t.casos.length));

  let html = `<p class="tenue">${tablas.length} tablas tocadas por las pruebas. El color dice si solo se lee, si se escribe, o las dos cosas.</p>`;

  for (const tabla of tablas) {
    html += `<details class="grupo">
      <summary>${chipTabla(tabla.nombre, tabla.acceso)} <span class="tenue">· ${tabla.casos.length} casos · ${tabla.funciones.length} funciones</span></summary>
      <div class="cuerpo">
        ${barra(`ver los casos que la tocan`, tabla.casos.length, tope, `data-casos="${tabla.casos.join(',')}" data-motivo="${esc(tabla.nombre)}"`)}
        <p class="tenue">Funciones que la mueven:</p>
        <ul class="llana">${tabla.funciones.map((f) => `<li><code>${esc(cortarFuncion(f))}</code></li>`).join('')}</ul>
      </div>
    </details>`;
  }

  $('panel-datos').innerHTML = html;
}

/**
 * La rejilla función × tabla de un fichero: qué lee y qué escribe cada parte.
 *
 * Es la vista que de un vistazo dice dónde está el poder de escritura de un fichero, que es
 * donde se concentra el riesgo.
 */
function matriz(funciones) {
  const columnas = [...new Set(funciones.flatMap((fn) => Object.keys(fn.tablas)))].slice(0, 14);
  const filas = funciones.filter((fn) => Object.keys(fn.tablas).length).slice(0, 20);

  if (columnas.length < 2 || filas.length < 2) return '';

  const cabecera = columnas.map((c) => `<th class="vertical">${esc(c)}</th>`).join('');
  const cuerpo = filas
    .map((fn) => {
      const celdas = columnas
        .map((c) => {
          const acceso = fn.tablas[c];
          return acceso ? `<td class="${acceso.toLowerCase()}">${acceso}</td>` : '<td>·</td>';
        })
        .join('');

      return `<tr><th class="fila">${esc(cortarFuncion(fn.nombre))}</th>${celdas}</tr>`;
    })
    .join('');

  return `<table class="matriz"><tr><th></th>${cabecera}</tr>${cuerpo}</table>`;
}

/** Pulsar una función o una tabla lleva a sus casos, en la pestaña de casos. */
for (const panel of ['panel-codigo', 'panel-datos']) {
  $(panel).addEventListener('click', (ev) => {
    const barraPulsada = ev.target.closest('.barra.pulsable');
    if (!barraPulsada) return;

    estado.ids = new Set(barraPulsada.dataset.casos.split(','));
    estado.motivo = barraPulsada.dataset.motivo;
    estado.resultado = 'todos';
    abrirPestana('casos');
    pintarResultados();
    pintarLista();
  });
}

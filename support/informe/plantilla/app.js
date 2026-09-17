'use strict';

/**
 * La vista del informe de pruebas.
 *
 * Carga solo el indice al abrir —una fila por caso, unos cientos de KB— y pide la ficha de un
 * caso y el fuente que recorre cuando hacen falta. Asi el arranque no depende de cuantos
 * casos haya ni de cuanta traza lleven.
 *
 * **Se navega por paginas, no por paneles.** Cada vista tiene su direccion —`#/`, `#/codigo`,
 * `#/caso/<id>`— con sus migas para volver. Cuesta un enrutador de veinte lineas y a cambio
 * un caso concreto se puede enlazar, el boton de atras del navegador funciona, y una seccion
 * nueva es una ruta nueva en vez de otra cosa apilada en el mismo panel.
 *
 * Sin dependencias y sin paso de compilacion: se sirve tal cual.
 */

const estado = {
  casos: [],
  citas: {},
  texto: '',
  resultado: 'todos',
  etiquetas: new Set(),
  fuentes: new Map(),
  codigo: null,
  ids: null,
  motivo: '',
  clase: null,
  claseMontada: null,
  vista: null,
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
  escuchar();

  window.addEventListener('hashchange', enrutar);
  enrutar();
}


// ── El enrutador ────────────────────────────────────────────────────────────────────────

/** Lleva a la vista que pide la direccion. Sin direccion, la lista de casos. */
function enrutar() {
  const partes = location.hash.replace(/^#\/?/, '').split('/').filter(Boolean);
  const seccion = partes[0] || 'casos';

  for (const enlace of document.querySelectorAll('.pestana')) {
    const suya = seccion === 'caso' || seccion === 'clase' ? 'casos' : seccion;
    enlace.classList.toggle('activa', enlace.dataset.seccion === suya);
  }

  if (seccion === 'caso' && partes[1]) return vistaCaso(partes[1]);
  if (seccion === 'codigo') return vistaCodigo();
  if (seccion === 'datos') return vistaDatos();

  // Una clase de prueba es el nivel intermedio, como el fichero en el informe de navegador: la
  // lista entera es demasiado y un caso suelto es demasiado poco.
  estado.clase = seccion === 'clase' && partes[1] ? decodeURIComponent(partes[1]) : null;

  return vistaCasos();
}

/** Las migas: donde estoy y como vuelvo. */
function pintarMigas(tramos) {
  $('migas').innerHTML = tramos
    .map((t, i) =>
      i === tramos.length - 1
        ? `<span class="miga actual">${esc(t.texto)}</span>`
        : `<a class="miga" href="${t.href}">${esc(t.texto)}</a>`)
    .join('<span class="separador">›</span>');
}

/** Los escuchadores se ponen una vez sobre el contenedor: las vistas se repintan enteras. */
function escuchar() {
  $('vista').addEventListener('click', (ev) => {
    const resultado = ev.target.closest('[data-resultado]');
    if (resultado) {
      estado.resultado = resultado.dataset.resultado;
      pintarResultados();
      pintarLista();
      return;
    }

    const etiqueta = ev.target.closest('[data-etiqueta]');
    if (etiqueta) {
      const cual = etiqueta.dataset.etiqueta;
      estado.etiquetas.has(cual) ? estado.etiquetas.delete(cual) : estado.etiquetas.add(cual);
      etiqueta.classList.toggle('activa');
      pintarLista();
      return;
    }

    if (ev.target.closest('#quitar')) {
      estado.ids = null;
      estado.motivo = '';
      pintarLista();
      return;
    }

    const entero = ev.target.closest('.ver-entero');
    if (entero) {
      const detalle = entero.closest('[data-fuente]');
      detalle.dataset.entero = '1';
      detalle.dataset.cargado = '';
      pintarFuente(detalle);
      return;
    }

    const barraPulsada = ev.target.closest('.barra.pulsable');
    if (barraPulsada) {
      estado.ids = new Set(barraPulsada.dataset.casos.split(','));
      estado.motivo = barraPulsada.dataset.motivo;
      estado.resultado = 'todos';
      estado.vista = null;
      location.hash = '#/';
    }
  });

  $('vista').addEventListener('input', (ev) => {
    if (ev.target.id !== 'buscador') return;
    estado.texto = ev.target.value.toLowerCase().trim();
    pintarLista();
  });
}


// ── Las tarjetas de cabecera ────────────────────────────────────────────────────────────

function pintarTarjetas() {
  const rojos = estado.casos.filter((c) => c.estado === 'fallo' || c.estado === 'error').length;
  const avisos = estado.casos.reduce((n, c) => n + c.avisos, 0);
  const avisosPhp = estado.casos.reduce((n, c) => n + (c.avisosPhp || 0), 0);
  const citas = Object.values(estado.citas).reduce((n, l) => n + l.length, 0);

  $('tarjetas').innerHTML = [
    tarjeta('Casos', estado.casos.length),
    tarjeta('En rojo', rojos, rojos ? 'rojo' : ''),
    tarjeta('Avisos de PHP', avisosPhp, avisosPhp ? 'aviso' : ''),
    tarjeta('Con aviso', avisos, avisos ? 'aviso' : ''),
    tarjeta('Citas de calidad', citas, citas ? 'aviso' : ''),
  ].join('');
}

const tarjeta = (titulo, valor, clase = '') =>
  `<div class="tarjeta ${clase}"><span class="valor">${valor}</span><span class="titulo">${esc(titulo)}</span></div>`;


// ── Vista: la lista de casos ────────────────────────────────────────────────────────────

function vistaCasos() {
  pintarMigas(estado.clase
    ? [{ texto: 'Casos', href: '#/' }, { texto: estado.clase }]
    : [{ texto: 'Casos' }]);

  // El armazon de la vista se monta una vez: repintarlo en cada tecla del buscador perderia
  // el foco del campo.
  if (estado.vista !== 'casos' || estado.claseMontada !== estado.clase) {
    estado.claseMontada = estado.clase;

    $('vista').innerHTML = `
      <section class="filtros">
        <input type="search" id="buscador" placeholder="Buscar por nombre, frase, fichero o etiqueta…" autocomplete="off">
        <div id="resultados" class="chips resultados"></div>
        <div id="etiquetas" class="chips"></div>
        <p id="cuenta" class="tenue"></p>
      </section>
      <div id="lista" class="lista"></div>`;

    estado.vista = 'casos';
    $('buscador').value = estado.texto;
    pintarResultados();
    pintarEtiquetas();
  }

  pintarLista();
  window.scrollTo(0, 0);
}

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
    .map(([e, n]) =>
      `<button class="chip ${estado.etiquetas.has(e) ? 'activa' : ''}" data-etiqueta="${esc(e)}">@${esc(e)} <span class="tenue">${n}</span></button>`)
    .join('');
}

function casosVisibles() {
  return estado.casos.filter((caso) => {
    if (estado.ids && !estado.ids.has(caso.id)) return false;
    if (estado.clase && caso.clase !== estado.clase) return false;

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
    : estado.clase
      ? `${visibles.length} casos de <b>${esc(estado.clase)}</b> · <a class="chip" href="#/">ver todos</a>`
      : `${visibles.length} de ${estado.casos.length} casos`;

  $('lista').innerHTML = visibles
    .map((caso) => {
      const rojo = caso.estado === 'fallo' || caso.estado === 'error';
      const marcas = [];
      if (caso.avisos) marcas.push('⚠ aviso');
      if (caso.avisosPhp) marcas.push(`${caso.avisosPhp} avisos de PHP`);
      if (caso.consultas) marcas.push(`${caso.consultas} consultas`);
      if (caso.declara) marcas.push(`declara ${caso.declara}`);

      return `<a class="fila ${rojo ? 'rojo' : 'verde'}" href="#/caso/${caso.id}">
        <span class="frase">${esc(caso.frase)}</span>
        <span class="meta"><span>${esc(caso.clase)}</span><span>${(caso.tiempo * 1000).toFixed(0)} ms</span></span>
        ${marcas.length ? `<span class="marcas">${esc(marcas.join(' · '))}</span>` : ''}
      </a>`;
    })
    .join('');
}


// ── Vista: un caso ──────────────────────────────────────────────────────────────────────

async function vistaCaso(id) {
  estado.vista = null;
  $('vista').innerHTML = '<p class="tenue">Cargando el caso…</p>';

  const caso = await (await fetch(`datos/casos/${id}.json`)).json();
  const rojo = caso.estado === 'fallo' || caso.estado === 'error';

  const clase = caso.clase.split('\\').pop();

  pintarMigas([
    { texto: 'Casos', href: '#/' },
    { texto: clase, href: '#/clase/' + encodeURIComponent(clase) },
    { texto: caso.frase },
  ]);

  let html = `<article class="ficha">
    <h2>${esc(caso.frase)} <span class="estado ${rojo ? 'rojo' : 'verde'}">${rojo ? caso.estado : 'pasa'}</span></h2>
    <p class="metodo">${esc(caso.clase)}::${esc(caso.metodo)}</p>
    <p>${caso.etiquetas.map((e) => `<span class="etiqueta">@${esc(e)}</span>`).join('')}</p>`;

  for (const aviso of caso.avisos) {
    html += `<div class="aviso-caja">⚠ ${esc(aviso)}</div>`;
  }

  html += pintarAnotaciones(caso);
  if (caso.mensaje) html += `<h3>Por qué falla</h3><pre class="mensaje">${esc(caso.mensaje.slice(0, 2000))}</pre>`;

  html += pintarAvisosDePhp(caso.flujo);
  html += pintarCamino(caso.flujo);
  html += pintarFlujo(caso.flujo);
  html += pintarArbol(caso.flujo);
  html += pintarCobertura(caso, caso.flujo);
  html += '</article>';

  $('vista').innerHTML = html;

  // `toggle` no burbujea, de modo que este es el unico escuchador que va por elemento.
  $('vista').querySelectorAll('[data-fuente]').forEach((el) => {
    el.addEventListener('toggle', () => pintarFuente(el));
  });

  window.scrollTo(0, 0);
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
 * Lo que PHP avisó mientras el caso corría.
 *
 * El despacho de tareas instala un manejador que los silencia para que la prueba pueda seguir,
 * y sin esta sección no aparecerían en ninguna parte. Son los que explican, por ejemplo, que un
 * fichero que el producto incluye no exista: eso no deja rastro ni en la cobertura ni en el SQL.
 */
function pintarAvisosDePhp(flujo) {
  const avisos = (flujo && flujo.avisos) || [];
  if (!avisos.length) return '';

  let html = `<h3>Lo que PHP avisó — ${avisos.length}</h3>
    <p class="tenue">El andamiaje silencia estos avisos para que el caso pueda terminar; aquí salen porque la traza registra cada llamada al manejador de errores.</p>`;

  for (const aviso of avisos) {
    let nota = '';
    if (aviso.incluye !== undefined) {
      nota = aviso.existe === false
        ? ` · <b class="rojo">el fichero no existe</b>`
        : aviso.existe === null
          ? ` · ruta relativa al directorio de trabajo, que el andamiaje cambia`
          : '';
    }

    html += `<div class="aviso-php nivel-${esc(aviso.nivel)}">
      <code>${esc(aviso.mensaje)}</code>
      <span class="quien">${esc(aviso.nivel)} · ${esc(aviso.fichero)}:${aviso.linea}${aviso.veces > 1 ? ` · ${aviso.veces}×` : ''}${nota}</span>
    </div>`;
  }

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

      return `<li class="tramo fase-${esc(tramo.fase)} ${tramo.soloConstruye ? 'solo-construye' : ''}" ${detalle ? `title="${esc(detalle)}"` : ''}>
        <span class="nombre">${esc(nombre)}</span>
        <span class="cuantas">${tramo.soloConstruye ? 'se construye' : tramo.llamadas}</span>
      </li>`;
    })
    .join('');

  const construidos = flujo.camino.filter((t) => t.soloConstruye).length;

  return `<h3>Por dónde pasó — ${flujo.camino.length} tramos</h3>
    ${construidos ? `<p class="tenue">${construidos} de esos tramos solo construyen un objeto: el dato no pasa por ellos, se montan por estar en la cabecera del fichero.</p>` : ''}
    <ol class="camino">${tramos}</ol>`;
}

/** El ultimo tramo de un nombre de funcion: `Clase->metodo` sin su espacio de nombres. */
const cortarFuncion = (nombre) => String(nombre).split('\\').pop();

/**
 * La cadena de llamadas como arbol, con su profundidad y su duracion.
 *
 * Solo TPVFox. El andamiaje de la prueba va aparte y plegado: medido en un caso de despacho,
 * de 52 marcos solo 17 eran del producto, y leerlos mezclados hacia pasar por recorrido lo que
 * era el montaje de la propia prueba.
 */
function pintarArbol(flujo) {
  if (!flujo) return '';

  const producto = flujo.llamadas || [];
  const armazon = flujo.armazon || [];

  if (!producto.length && !armazon.length) return '';

  let html = '';

  if (producto.length) {
    html += `<details class="arbol"><summary>Pasos, uno a uno — ${producto.length} en TPVFox</summary><ul>${ramas(producto)}</ul></details>`;
  } else {
    html += '<p class="tenue">La traza no registró ninguna llamada dentro de TPVFox: lo que hay es el montaje de la prueba.</p>';
  }

  if (armazon.length) {
    const total = flujo.armazonTotal || armazon.length;
    html += `<details class="arbol andamiaje">
      <summary>Y ${total} pasos del andamiaje de la prueba${total > armazon.length ? `, de los que se guardan ${armazon.length}` : ''}</summary>
      <ul>${ramas(armazon)}</ul></details>`;
  }

  return html;
}

/** Las filas de un arbol de llamadas, sangradas por su profundidad. */
function ramas(llamadas) {
  const minimo = Math.min(...llamadas.map((l) => l.nivel));

  return llamadas
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
}

/**
 * Que hizo el dato, consulta a consulta.
 *
 * Las consultas que lanza un constructor van aparte: son montaje, no trabajo del caso. Sin
 * separarlas, un caso que no llega a hacer nada aparenta haber consultado cinco tablas.
 */
function pintarFlujo(flujo) {
  if (!flujo || (!flujo.pasos.length && !flujo.llamadas.length)) {
    return '<h3>Flujo del dato</h3><p class="tenue">Este caso no consulta la base: lo que hace es cálculo, y su recorrido está en la cadena de llamadas.</p>';
  }

  let html = '';

  if (flujo.dadoQue) html += `<h3>Dado que</h3><p>${esc(flujo.dadoQue)}</p>`;

  if (flujo.pasos.length) {
    const montaje = flujo.pasos.filter((p) => p.montaje);
    const trabajo = flujo.pasos.filter((p) => !p.montaje);

    html += `<h3>Qué hizo el dato — ${flujo.consultas} consultas</h3>`;
    if (flujo.recortado) html += '<div class="aviso-caja">La sucesión está recortada: el caso hizo más de lo que se muestra.</div>';

    if (montaje.length) {
      html += `<details class="montaje">
        <summary>${montaje.length} de esas consultas son de constructores, antes de que el caso empiece a trabajar</summary>
        <div>${montaje.map(paso).join('')}</div>
      </details>`;
    }

    if (trabajo.length) {
      html += trabajo.map(paso).join('');
    } else {
      html += `<p class="tenue">La tarea en sí no consultó nada: todas las consultas son del montaje de arriba.</p>`;
    }
  }

  return html;
}

const paso = (p) => `<div class="paso fase-${esc(p.fase)}">
  ${p.veces > 1 ? `<span class="veces">${p.veces}× </span>` : ''}<code>${esc(p.sql)}</code>
  <span class="quien">${esc(p.origen || p.funcion)} · ${esc(cortar(p.fichero))}:${p.linea}${
    p.error ? ` · <b>error:</b> ${esc(p.error.slice(0, 90))}` : ` · ${describirSalida(p)}`
  }</span>
</div>`;

const describirSalida = (p) => {
  const partes = [];
  if (p.filasDevueltas !== undefined) partes.push(`${p.filasDevueltas} filas`);
  if (p.filasAfectadas !== undefined && p.filasAfectadas >= 0) partes.push(`afecta ${p.filasAfectadas}`);
  if (p.idInsertado) partes.push(`id ${p.idInsertado}`);
  return partes.join(', ');
};

function pintarCobertura(caso, flujo) {
  const rutas = Object.entries(caso.cobertura || {});

  // Un caso que termina en error no deja cobertura: PHPUnit la descarta. Decirlo evita que
  // el apartado se lea como «este caso no toca código», que seria falso.
  if (!rutas.length) {
    return caso.estado === 'error'
      ? '<h3>Código que recorre</h3><p class="tenue">No hay cobertura de este caso: los que terminan en error no la dejan registrada. El recorrido y el flujo de abajo sí valen.</p>'
      : '';
  }

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

  let cuenta = 0;
  for (const [ruta, datos] of enOrden) {
    const enElRecorrido = lugarDe(ruta) !== null;
    if (enElRecorrido) cuenta++;

    html += `<details class="paso-codigo" data-fuente="${datos.id}" data-lineas="${datos.lineas.join(',')}">
      <summary>
        <span class="orden">${enElRecorrido ? cuenta : '·'}</span>
        <code>${esc(ruta)}</code>
        <span class="tenue">${datos.lineas.length} líneas${enElRecorrido ? '' : ' · fuera del recorrido'}</span>
      </summary>
      <div class="cuerpo tenue">Cargando…</div>
    </details>`;
  }

  return html;
}


// ── El fuente: los tramos que se ejecutaron, no el fichero entero ────────────────────────

/** Cuantas lineas de contexto acompanan a cada tramo ejecutado. */
const CONTEXTO = 3;

/** Una etiqueta de `switch`, que PHP evalua al buscar la rama aunque no entre en ella. */
const ES_RAMA = /^\s*(case\s.+|default)\s*:\s*$/;

/**
 * Trae el fuente de TPVFox y pinta lo que el caso ejecuto.
 *
 * Por tramos, no entero. Medido sobre la suite: pintar el fichero completo son 10.817 lineas
 * para ensenar 1.819 ejecutadas, el 17 %; en `PosstockQueryRepository.php` son 114 de 1.891,
 * el 6 %. Quien quiera el fichero entero lo tiene a un clic.
 */
async function pintarFuente(detalle) {
  if (!detalle.open || detalle.dataset.cargado) return;
  detalle.dataset.cargado = '1';

  const id = detalle.dataset.fuente;
  if (!estado.fuentes.has(id)) {
    estado.fuentes.set(id, await (await fetch(`datos/fuentes/${id}.json`)).json());
  }

  const fuente = estado.fuentes.get(id);
  const lineas = fuente.lineas;
  const ejecutadas = new Set(detalle.dataset.lineas.split(',').map(Number));
  const entero = detalle.dataset.entero === '1';

  // Una etiqueta de `switch` que se evaluo sin entrar en su cuerpo no es codigo recorrido: es
  // el interprete buscando la rama. Contarlas como recorrido hacia leer «paso por
  // abrirIncidencia, por buscarProductos, por buscarClientes» un caso que no entro en ninguna.
  const descartadas = new Set();
  for (const n of ejecutadas) {
    if (ES_RAMA.test(lineas[n - 1] || '') && !ejecutadas.has(n + 1)) descartadas.add(n);
  }

  const reales = [...ejecutadas].filter((n) => !descartadas.has(n));
  const tramos = entero
    ? [{ desde: 1, hasta: lineas.length }]
    : tramosDe(reales, lineas.length);

  let html = '';

  if (descartadas.size) {
    const cuales = [...descartadas].sort((a, b) => a - b);
    html += `<p class="nota-ramas">${cuales.length} ${cuales.length === 1 ? 'rama' : 'ramas'} de <code>switch</code>
      que PHP evaluó sin entrar en ${cuales.length === 1 ? 'ella' : 'ellas'}
      ${cuales.length === 1 ? '(línea' : '(líneas'} ${cuales.join(', ')})</p>`;
  }

  for (const [i, tramo] of tramos.entries()) {
    const anterior = tramos[i - 1];
    if (anterior) {
      const salto = tramo.desde - anterior.hasta - 1;
      if (salto > 0) html += `<p class="salto">⋯ ${salto} líneas sin ejecutar</p>`;
    }

    let filas = '';
    for (let n = tramo.desde; n <= tramo.hasta; n++) {
      const clase = descartadas.has(n) ? 'rama' : ejecutadas.has(n) ? 'ejecutada' : '';
      filas += `<tr class="${clase}"><td class="n">${n}</td><td>${esc(lineas[n - 1] || '')}</td></tr>`;
    }

    html += `<table class="fuente">${filas}</table>`;
  }

  if (!entero) {
    const pintadas = tramos.reduce((n, t) => n + (t.hasta - t.desde + 1), 0);
    html += `<p class="tenue">${pintadas} de ${lineas.length} líneas · <button class="chip ver-entero">ver el fichero entero</button></p>`;
  }

  const cuerpo = detalle.querySelector('.cuerpo') || detalle.querySelector('.cuerpo-fuente');
  cuerpo.outerHTML = `<div class="cuerpo-fuente">${html}</div>`;
}

/**
 * Agrupa lineas sueltas en tramos contiguos con su contexto.
 *
 * Dos lineas separadas por menos contexto del que se pintaria dos veces van al mismo tramo:
 * partirlas seria ensenar lo mismo con una linea de corte en medio.
 */
function tramosDe(lineas, total) {
  const orden = [...lineas].sort((a, b) => a - b);
  const tramos = [];

  for (const n of orden) {
    const ultimo = tramos[tramos.length - 1];
    if (ultimo && n - ultimo.hasta <= CONTEXTO * 2 + 1) {
      ultimo.hasta = n;
      continue;
    }
    tramos.push({ desde: n, hasta: n });
  }

  return tramos.map((t) => ({
    desde: Math.max(1, t.desde - CONTEXTO),
    hasta: Math.min(total, t.hasta + CONTEXTO),
  }));
}


// ── Vistas: el código y los datos ───────────────────────────────────────────────────────

/** Trae los datos de las vistas por código y por datos, que van en su propio fichero. */
async function traerCodigo() {
  if (!estado.codigo) {
    estado.codigo = await (await fetch('datos/codigo.json')).json();
  }
  return estado.codigo;
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
async function vistaCodigo() {
  estado.vista = 'codigo';
  pintarMigas([{ texto: 'Código' }]);
  $('vista').innerHTML = '<p class="tenue">Cargando…</p>';

  const ficheros = (await traerCodigo()).ficheros;
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

  $('vista').innerHTML = html;
  window.scrollTo(0, 0);
}

/** Qué datos mueve el producto: una tabla por fila, con quién la toca. */
async function vistaDatos() {
  estado.vista = 'datos';
  pintarMigas([{ texto: 'Datos' }]);
  $('vista').innerHTML = '<p class="tenue">Cargando…</p>';

  const tablas = (await traerCodigo()).tablas;
  const tope = Math.max(...tablas.map((t) => t.casos.length));

  let html = `<p class="tenue">${tablas.length} tablas tocadas por las pruebas. El color dice si solo se lee, si se escribe, o las dos cosas.</p>`;

  for (const tabla of tablas) {
    html += `<details class="grupo">
      <summary>${chipTabla(tabla.nombre, tabla.acceso)} <span class="tenue">· ${tabla.casos.length} casos · ${tabla.funciones.length} funciones</span></summary>
      <div class="cuerpo">
        ${barra('ver los casos que la tocan', tabla.casos.length, tope, `data-casos="${tabla.casos.join(',')}" data-motivo="${esc(tabla.nombre)}"`)}
        <p class="tenue">Funciones que la mueven:</p>
        <ul class="llana">${tabla.funciones.map((f) => `<li><code>${esc(cortarFuncion(f))}</code></li>`).join('')}</ul>
      </div>
    </details>`;
  }

  $('vista').innerHTML = html;
  window.scrollTo(0, 0);
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

const cortar = (ruta) => String(ruta).split('/').pop();

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

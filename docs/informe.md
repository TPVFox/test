# El informe

La salida de PHPUnit son puntos en un terminal. Este informe cuenta, por cada caso, **qué valida**,
**qué código de TPVFox recorre** y **qué hizo el dato**, con los tres niveles en una sola lista.

```bash
npm run informe:pruebas     # genera en informe-pruebas/ (~1 min)
npm run informe:ver         # lo sirve en http://127.0.0.1:8090
```

Hace falta servirlo: el navegador bloquea las peticiones de datos desde `file://`. Para conservar
una copia, genérala aparte y sirve esa carpeta:

```bash
php support/informe-pruebas.php --salida=$HOME/informes/$(date +%F)
php -S 127.0.0.1:8090 -t $HOME/informes/$(date +%F)
```

| Opción | Para qué |
| --- | --- |
| `--suites=unit-php` | Solo una de las suites de PHP |
| `--sin-js` | Se salta las suites de Jest |
| `--salida=<ruta>` | Genera en otro sitio |
| `--sin-traza` | La mitad de tiempo, sin cadena de llamadas y con el recorrido incompleto |

**La traza viene puesta** porque es la única fuente que da **orden**: sin ella el informe sabe qué
ficheros se recorrieron pero no en qué secuencia, y además aparecen las clases que no consultan
nada. Cuesta el doble de tiempo y disco temporal que se limpia al terminar.

## Las seis vistas

| Ruta | Qué responde |
| --- | --- |
| `#/` | Qué valida cada caso. Filtros por resultado, nivel, puerta de entrada y etiqueta |
| `#/caso/<id>` | Todo sobre un caso: qué valida, por dónde entró, qué hizo el dato, qué código recorre, qué avisó PHP, desde cuándo está así |
| `#/defectos` | Los defectos que la suite documenta, vivos y corregidos. Exportable a Markdown |
| `#/cobertura` | Quién cubre cada fichero del producto: las pruebas, los recorridos, ambos o nadie |
| `#/codigo` | Qué le pasa a cada función: qué casos la cubren y qué tablas mueve |
| `#/datos` | Qué tabla toca quién, y si la lee o la escribe |

Se navega por páginas con migas, así que un caso concreto se puede enlazar y el botón de atrás
funciona.

## Qué sale solo y qué hay que escribir

| En la ficha | De dónde | Hace falta escribirlo |
| --- | --- | --- |
| La frase del caso | Del nombre del método o del título del recorrido | No |
| Las etiquetas | Del nombre de la clase y de la carpeta | No |
| «Dado que…» | De los métodos de siembra que el caso llamó | No |
| «Qué hizo el dato» | De las consultas reales, con quién las pidió y su `fichero:línea` | No |
| «Por dónde pasó» | De la traza, resuelta a la clase que declara cada función | No |
| El código que recorre | De la cobertura por caso, sobre el fuente real | No |
| Por qué puerta entra | Del primer marco de producto de la traza | No |
| Desde cuándo está así | Del registro de ejecuciones anteriores | No |
| Las seis anotaciones | Declaradas en el comentario o en el `test()` | Sí |
| `@estado` y `@codigo-afectado` | Declarados en el comentario | Sí |

Lo derivado no pisa lo escrito: si el caso declara algo, manda lo suyo.
**→ [escribir-pruebas.md](escribir-pruebas.md#anotar-un-caso)**

## Los tres contrastes

Son lo que el informe aporta sobre leer el fuente, y salen solos:

1. **El estado declarado contra el real.** Si un caso dice estar en rojo y pasa, o al revés.
2. **El código declarado contra el ejecutado.** Si dice cubrir unas líneas por las que no pasa.
3. **Ningún fichero de pruebas cita identificadores del sistema de calidad**, porque este
   repositorio es público y tiene que sostenerse solo.

## Lo que el informe no puede decir

Todos los límites juntos, porque conocerlos cambia cómo se lee cada cifra.

**El cruce de cobertura se queda corto por abajo, nunca por arriba.** De un recorrido se sabe la
URL que su fuente nombra, no todo lo que la petición acaba ejecutando: `funciones.js` llama a
`tareas.php` once veces por AJAX y ese salto no se atribuye a nadie. Lo que figura como alcanzado
lo está de verdad; lo que figura como «ambos» está por debajo de la realidad. Cerrarlo exigiría
cobertura en el servidor durante la pasada de navegador.

**Del JavaScript se dice que lo prueban, nunca cuánto.** `jest.config.js` declara un
`coverageThreshold` del 70 % y al ejecutarlo mide `0/0`: los casos cargan el script del producto
leyéndolo y evaluándolo con `vm.runInContext`, y la cobertura de V8 solo ve lo que pasa por el
sistema de módulos. **Es un umbral que no puede fallar porque no hay nada que medir.**

**Un caso que termina en error no deja cobertura.** PHPUnit la descarta —en error, no en fallo de
aserción—. Esos casos muestran su recorrido y su flujo pero no su código, y la ficha lo dice.

**De dónde llega el producto a un punto es una búsqueda por nombre de método.** No distingue dos
clases con el mismo método ni ve el despacho dinámico. Lo que sostiene es el negativo: si un nombre
no aparece en ningún sitio salvo su propia clase, nadie lo llama desde fuera.

**Un recorrido sin ejecutar no es verde, ni rojo, ni omitido.** Sale como `sin ejecutar`, con su
propio filtro: el volcado de Playwright trae el vocabulario de todos los recorridos aunque no se
hayan ejecutado, y confundir eso con una pasada declararía un verde que no ocurrió.

**Los recorridos quedan fuera del primer contraste.** Declaran su estado con el vocabulario de
etiquetas y no con `@estado`, y traducir una cosa en la otra es una decisión que no está tomada.

## Cobertura, por separado

El objetivo es **70 %** en líneas y en métodos. Lo que se declara en cada ejecución no es el umbral
sino **el ámbito**: un porcentaje solo significa algo si se dice sobre qué se mide.

```bash
npm run cobertura -- modulos/mod_reorganizacion       # una carpeta
npm run cobertura -- clases/ClaseTFModelo.php         # un fichero
npm run cobertura -- modulos/mod_informes --umbral=80
npm run cobertura -- <ámbito> --detalle               # qué queda fuera, línea a línea
```

El ámbito **no tiene valor por defecto**, a propósito: uno por defecto acabaría midiendo siempre lo
de una entrega concreta. El guion sale con error si el ámbito no casa con nada, si la suite no pasa
o si no se alcanza el umbral, y entonces lista los cinco ficheros que más lastran.

**Qué entra** (`phpunit.xml`, bloque `<coverage>`): `modulos/`, `clases/`, `controllers/` y `app/`.
Queda fuera `lib/`, que es de terceros, y `plugins/`, `jquery/` y `estatico/`.

**Un módulo con código anterior a las pruebas** arrastra su cobertura hacia abajo aunque lo nuevo
esté verificado del todo. Ahí el ámbito se declara por prefijo de ruta, para medir el código que la
entrega produce; la cobertura del código anterior es un objetivo aparte.

# Escribir una prueba

## Dónde va

Un caso baja al nivel más simple que pueda demostrarlo.

| Si necesita… | Va en | Se llama |
| --- | --- | --- |
| Nada | `Unit/PHP/<modulo>/` | `<Asunto>Test.php` |
| Base de datos | `Integration/PHP/<modulo>/` | `<Asunto>IntegracionTest.php` |
| Solo Node | `Unit/JS/<modulo>/` | `<asunto>.test.js` |
| DOM sin navegador | `Integration/JS/<modulo>/` | `<asunto>.test.js` |
| Navegador real | `E2E/specs/<modulo>/` | `<asunto>.spec.js` |

Un caso que afirma lo que el producto **debería** hacer y hoy no hace va en
`E2E/specs/<modulo>/esperado/`.

## Sembrar datos

`support/siembra/` reúne todo lo que genera datos. Está separado de `support/php/` —los apoyos de
la suite: `CasoIntegracion`, `Entorno`— porque la siembra no la consume solo la integración:
también la necesitan los recorridos, que corren contra un despliegue real.

| Qué | Dónde |
| --- | --- |
| Primitivas: artículo, familia, proveedor, tienda, movimientos | `support/siembra/Siembra.php` |
| Escenarios de un módulo, y en qué ejercicio ocurren | `support/siembra/Escenario*.php` |

Una primitiva no sabe de ningún módulo; un escenario sí. Esa separación es lo que permite sembrar
dos ejercicios consecutivos desde un mismo guion en vez de coordinarlos a mano.

## Aislamiento

Cada caso de integración se envuelve en transacción con `ROLLBACK`: **ningún dato persiste**.

La excepción son los casos que ejercitan la apertura de transacción del propio producto: en MySQL y
MariaDB un `START TRANSACTION` dentro de otro confirma el anterior de forma implícita, así que ahí
la transacción de la suite dejaría de aislar sin avisar. Esos casos ponen
`$this->aislarPorTransaccion = false` y limpian lo que siembren.

## Anotar un caso

Las anotaciones son **las mismas seis en los tres niveles**, para que lo que se lee en un informe
se lea igual en el otro:

| Anotación | Cuándo |
| --- | --- |
| `que-ocurre-hoy` · `que-deberia-ocurrir` · `por-que-ocurre` · `como-deberia-funcionar` | Las cuatro juntas, en un caso que documenta un defecto |
| `comportamiento` | Un caso que documenta algo que funciona bien |
| `para-que-sirve` | Un control que acompaña a un caso de defecto |

**En PHP**, en el docblock del método:

```php
/**
 * @estado rojo
 * @group defecto
 * @group pedido
 * @group critico
 *
 * @que-ocurre-hoy Pedir el cambio de estado de un pedido cambia también el de un albarán
 *   que no tiene nada que ver, por compartir el mismo identificador.
 * @que-deberia-ocurrir Que el cambio alcance únicamente al documento del tipo pedido.
 * @por-que-ocurre Las tres condiciones que eligen el tipo usan asignación en vez de
 *   comparación, de modo que las tres se cumplen siempre.
 * @como-deberia-funcionar Comparar en vez de asignar en las tres condiciones.
 */
public function test_defecto_modificarEstadoDocumento_pedidoModificaTambienUnAlbaran(): void
```

Cada anotación se prolonga hasta la siguiente, así que puede ocupar párrafos. `@group` es de
PHPUnit, de modo que la etiqueta sirve además para filtrar sin el informe:
`vendor/bin/phpunit --group defecto`.

**En un recorrido**, en la propia llamada a `test()`:

```js
test('T1 teclear el número del pedido y pulsar Intro trae sus líneas al albarán', {
  tag: ['@estado-actual', '@albaran', '@pedido', '@teclado'],
  annotation: [
    { type: 'Comportamiento', description: 'Tecleando el número de un pedido guardado…' },
  ],
}, async ({ page }) => {
```

**En JS**, Jest no tiene `@group`: las etiquetas van en una línea `@etiquetas defecto entrada medio`
dentro del comentario del caso.

## Vocabulario de etiquetas

| Grupo | Etiquetas | Qué significa |
| --- | --- | --- |
| Tipo | `estado-actual` | Documenta lo que el producto hace hoy, y pasa |
| | `defecto` | Acompaña a `estado-actual` cuando lo documentado es un defecto: congela el comportamiento incorrecto tal como es |
| | `esperado` | Afirma lo que debería hacer y hoy no hace: **está en rojo a propósito** |
| | `control` | Acompaña a un `esperado`: comprueba lo que ya funciona y debe seguir funcionando tras la corrección |
| Documento | `pedido`, `albaran`, `factura` | Lo que el caso recorre |
| Área | `entrada`, `teclado`, `raton`, `listado`, `busqueda`, `borrador`, `adjuntos`, `guardado`, `estados`, `numeracion`, `existencias`, `importes`, `impreso`, `vencimiento`, `validacion` | La parte del flujo |
| Gravedad | `critico`, `alto`, `medio`, `bajo` | Solo en `defecto` y `esperado` |
| Camino | `directo` · `forzado` | Solo en `esperado`: si el defecto se alcanza navegando con normalidad o hay que intervenir la petición |

## Estado y código afectado

```php
 * @estado rojo                                        el caso está en rojo a propósito
 * @codigo-afectado modulos/mod_venta/tareas.php:77-78  qué líneas cubre el defecto
```

El informe **contrasta las dos declaraciones con la realidad**: avisa si un caso dice estar en rojo
y pasa, o al revés, y avisa si declara cubrir unas líneas por las que no pasa. Declarar de más no
es gratis, y ese es el punto.

**Un caso `esperado` no se marca como fallo esperado.** Una marca de fallo esperado da por buena
cualquier causa —unas credenciales que faltan, un selector que ya no existe— y deja la suite en
verde mientras el recorrido no demuestra nada. En rojo, cada uno enseña su motivo, y ese motivo es
la aserción del defecto.

La suite que **sí** debe estar siempre entera en verde:

```bash
npx playwright test E2E/specs/mod_venta --grep-invert @esperado --reporter=line
```

## No hace falta anotarlo todo

Lo derivado ya da frase, etiquetas, flujo y código a todos los casos. Lo que se escribe a mano son
aquellos donde la causa raíz importa. **La falta de anotación no genera aviso**: los avisos quedan
para las contradicciones, o dejarían de servir.

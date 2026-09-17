<?php

/**
 * Los tres casos de despacho de `tareas.php` con comportamiento defectuoso. Ver
 * `TareasDespachoIntegracionTest` para el resto y para `DespachoTareas`.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\DespachoTareas;
use TPVFox\Test\Siembra\Siembra;

final class TareasDefectosIntegracionTest extends CasoIntegracion
{
    private const RUTA_TAREAS = RUTA_TPVFOX . '/modulos/mod_venta/tareas.php';

    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siembra = new Siembra($this->db);
    }

    private function despachar(array $post): mixed
    {
        return DespachoTareas::invocar(self::RUTA_TAREAS, $this->db, $post);
    }

    /**
     * Defecto de mayor impacto encontrado en todo el componente: pedir el cambio de
     * estado de un PEDIDO modifica también un ALBARÁN no relacionado que solo comparte el
     * mismo identificador numérico.
     *
     * Síntoma: con `dedonde=pedido` e `idModificar` igual al id de un albarán existente
     * (sin que exista ningún pedido con ese id), el albarán cambia de estado igual.
     * Causa raíz: `tareas.php:143,146,149` (caso `modificarEstadoDocumento`) escribe
     * `if ($dedonde = 'pedido')`, `if ($dedonde = 'factura')`, `if ($dedonde = 'albaran')`
     * — asignación, no comparación (`==`/`===`), en los tres. Cada asignación es truthy
     * para una cadena no vacía, así que las tres condiciones son siempre ciertas: las tres
     * ramas se ejecutan siempre, sobre el mismo `$idDocumento`, y solo sobrevive en
     * `$modEstado` el resultado de la última (`albarán`). Sin relación con `idPedido`
     * versus `idAlbaran`: `pedclit`, `albclit` y `facclit` tienen secuencias de id
     * independientes, así que un id que por coincidencia exista en más de una de las tres
     * tablas ve las tres modificadas a la vez, sea cual sea el documento que el usuario
     * quería tocar. Corrección propuesta: cambiar los tres `=` por `==` (o mejor, un único
     * `switch ($dedonde)`). Evidencia: este test, en rojo mientras el defecto siga sin
     * corregirse por CC.
     *
     * @estado rojo
     * @group defecto
     * @group pedido
     * @group albaran
     * @group estados
     * @group critico
     *
     * @que-ocurre-hoy Pedir el cambio de estado de un pedido cambia tambien el estado de un
     *   albaran que no tiene nada que ver con el, por el solo hecho de compartir el mismo
     *   numero de identificador.
     * @que-deberia-ocurrir Que el cambio alcance unicamente al documento del tipo pedido.
     * @por-que-ocurre Las tres condiciones que eligen el tipo de documento usan asignacion en
     *   vez de comparacion, de modo que las tres se cumplen siempre y la orden se aplica a los
     *   tres tipos a la vez.
     * @como-deberia-funcionar Comparar en vez de asignar en las tres condiciones, o resolver
     *   el tipo de documento con una sola eleccion.
          *
     * @codigo-afectado modulos/mod_venta/tareas.php:143-149
    */
    public function test_defecto_modificarEstadoDocumento_pedidoModificaTambienUnAlbaranConElMismoId(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo estado cruzado');
        $idAlbaran = $this->siembra->ventaAlbaranCliente($idArticulo, 1.0, '2026-01-10', ['estado' => 'Guardado']);

        $this->despachar([
            'pulsado' => 'modificarEstadoDocumento',
            'dedonde' => 'pedido', // se pide modificar un PEDIDO
            'idModificar' => $idAlbaran, // que no existe con este id; el albaran si
            'estado' => 'Procesado',
        ]);

        $fila = $this->db->query("SELECT estado FROM albclit WHERE id=$idAlbaran")->fetch_assoc();
        self::assertSame(
            'Guardado',
            $fila['estado'],
            'Pedir el cambio de un pedido no deberia tocar un albaran, aunque comparta el numero de id'
        );
    }

    /**
     * Defecto: el caso de despacho apunta a un fichero que ya no existe.
     *
     * Síntoma: `anhadirPedidoTemp` no hace nada — `tareas.php` devuelve `null` en vez de un
     * array, con un aviso de PHP de variable indefinida silenciado por la ejecución. Causa
     * raíz: `tareas.php:61` incluye `tareas/AddPedidoTemporal.php`, borrado en el commit
     * `0f486fa7` («Limpiado codigo innecesario y corrigiendo errores modulo ventas») sin
     * retirar el `case` que lo referencia. Como es `include_once` (no `require_once`), el
     * fallo no detiene la ejecución — el caso simplemente no hace nada, sin avisar al
     * cliente de que la operación no se realizó. Corrección propuesta: retirar el `case`
     * si la funcionalidad ya no hace falta, o restaurar el fichero si sí. Evidencia: este
     * test, en rojo mientras el defecto siga sin corregirse por CC.
     *
     * @estado rojo
          *
     * @group defecto
     * @group pedido
     * @group borrador
     * @group medio
     * @codigo-afectado modulos/mod_venta/tareas.php:61-61
     *
     * @que-ocurre-hoy El caso de despacho no hace nada y no avisa: devuelve nulo en vez de una
     *   respuesta, y quien lo llamo no se entera de que la operacion no se hizo.
     * @que-deberia-ocurrir Que haga su trabajo, o que diga que no puede hacerlo.
     * @por-que-ocurre El despacho incluye un fichero que se borro sin retirar el caso que lo
     *   referencia, y al incluirse de forma no obligatoria el fallo no detiene nada.
     * @como-deberia-funcionar Retirar el caso si la funcionalidad ya no hace falta, o restaurar
     *   el fichero si si.
    */
    public function test_defecto_anhadirPedidoTemp_apuntaAUnFicheroBorrado(): void
    {
        $resultado = $this->despachar(['pulsado' => 'anhadirPedidoTemp']);

        self::assertIsArray($resultado, 'El caso no hace nada porque tareas/AddPedidoTemporal.php no existe');
    }

    /**
     * Mismo defecto que `anhadirPedidoTemp`, mismo commit de origen: `tareas.php:77`
     * incluye `tareas/BuscarPedido.php`, también borrado en `0f486fa7`.
     *
     * @estado rojo
          *
     * @group defecto
     * @group pedido
     * @group busqueda
     * @group medio
     * @codigo-afectado modulos/mod_venta/tareas.php:77-77
     *
     * @que-ocurre-hoy Mismo silencio que el caso anterior, en la busqueda de pedido.
     * @que-deberia-ocurrir Que busque, o que declare que no puede.
     * @por-que-ocurre El mismo fichero borrado en el mismo cambio, con su caso sin retirar.
     * @como-deberia-funcionar El mismo remedio: retirar el caso o restaurar el fichero.
    */
    public function test_defecto_buscarPedido_apuntaAUnFicheroBorrado(): void
    {
        $resultado = $this->despachar(['pulsado' => 'buscarPedido']);

        self::assertIsArray($resultado, 'El caso no hace nada porque tareas/BuscarPedido.php no existe');
    }
}

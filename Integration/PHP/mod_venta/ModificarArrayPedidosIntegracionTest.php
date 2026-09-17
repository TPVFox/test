<?php

/**
 * `modificarArrayPedidos()` de `funciones.php`.
 *
 * Hallazgo previo al de comportamiento: no tiene ningun llamador en todo el repositorio de
 * TPVFox (`grep -rn "modificarArrayPedidos" .` sobre PHP y JS no encuentra mas que su
 * propia definicion). Se prueba igual, porque F3 documenta el código tal como está y una
 * correccion futura puede tanto arreglarla como retirarla — esa decision queda fuera
 * del alcance de estas pruebas.
 *
 * También lee `$pedido['numPedido']` sin `isset()` (funciones.php:595): cualquier fila de
 * entrada que no traiga esa clave dispara el mismo tipo de aviso que el defecto de abajo.
 * Se documenta aquí, sin un test propio — sería una tercera prueba sobre la misma función
 * sin llamadores, por el mismo motivo raíz (nada comprueba lo que asume antes de leerlo).
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CargaAislada;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ModificarArrayPedidosIntegracionTest extends CasoIntegracion
{
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
        $this->siembra = new Siembra($this->db);
    }

    public function test_conUnPedidoExistenteTraeSusDatosPrincipales(): void
    {
        $idArticulo = $this->siembra->articulo('Articulo de pedido');
        $idPedido = $this->siembra->pedidoVentaCliente($idArticulo, 3.0, '2026-01-10');

        $resultado = \modificarArrayPedidos([['idPedido' => $idPedido, 'Numpedcli' => 0, 'numPedido' => 0]], $this->db);

        self::assertSame((string) $idPedido, $resultado[0]['idPedido'], 'mysqli::fetch_assoc devuelve todo como cadena');
        self::assertSame('Activo', $resultado[0]['estado']);
        self::assertSame(1, $resultado[0]['nfila']);
    }

    /**
     * Defecto: con un `idPedido` que no existe en `pedclit`, la funcion no detecta el
     * fallo y devuelve una fila con `estado: 'Activo'` y el resto de campos en `null`.
     *
     * Sintoma: `$ped` nunca se asigna dentro del `while` (la consulta no devuelve filas) y
     * el codigo que sigue lo usa igual, sin comprobar. PHP avisa "Undefined variable $ped"
     * y "Trying to access array offset on null" en cada uno de los cuatro accesos
     * (`funciones.php:599-602`), pero el valor final no es un error: es una fila con
     * apariencia valida. Causa raiz: `$BDTpv->query(...)` mas `while ($fila =
     * $datosPedido->fetch_assoc())` no comprueba el caso de cero filas antes de leer `$ped`
     * fuera del bucle. Corrección propuesta: comprobar que la consulta devolvió alguna fila
     * antes de construir la respuesta, y devolver un error explícito si no. Evidencia: este
     * test, en rojo mientras el defecto siga sin corregirse por CC (o la función se retire,
     * dado que no tiene llamadores) — no por una aserción fallida, sino por el propio
     * E_WARNING de PHP convertido en error de test: es más fiel al síntoma real que
     * envolverlo para poder afirmar algo sobre un valor que no debería llegar a existir.
     *
     * @estado rojo
          *
     * @group defecto
     * @group pedido
     * @group medio
     * @codigo-afectado modulos/mod_venta/funciones.php:599-602
     *
     * @que-ocurre-hoy Con un identificador de pedido que no existe, la funcion devuelve una
     *   fila con estado «Activo» y el resto de campos nulos: tiene apariencia de dato valido.
     * @que-deberia-ocurrir Que diga explicitamente que no encontro el pedido.
     * @por-que-ocurre La variable de la fila nunca se asigna porque la consulta no devuelve
     *   nada, y el codigo que sigue la usa igual sin comprobarlo.
     * @como-deberia-funcionar Comprobar que la consulta devolvio alguna fila antes de componer
     *   la respuesta, y devolver un error explicito si no.
    */
    public function test_defecto_conIdPedidoInexistenteDevuelveUnaFilaConDatosNulosMarcadaActiva(): void
    {
        \modificarArrayPedidos([['idPedido' => 999999, 'Numpedcli' => 1, 'numPedido' => 0]], $this->db);

        self::fail('No debería llegar aquí: la línea anterior debe fallar por "Undefined variable $ped".');
    }
}

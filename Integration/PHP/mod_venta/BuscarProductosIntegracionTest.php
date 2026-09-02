<?php

/**
 * `BuscarProductos()` de `funciones.php` busca por Referencia/Codbarras/Descripcion.
 * Cuando `idcaja` no es `cajaBusqueda` intenta primero una coincidencia identica
 * (`campo = valor`) y solo si esa falla cae a `campo LIKE %valor%`.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CargaAislada;
use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class BuscarProductosIntegracionTest extends CasoIntegracion
{
    private Siembra $siembra;
    private int $idCliente;

    protected function setUp(): void
    {
        parent::setUp();
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
        $this->siembra = new Siembra($this->db);
        $this->idCliente = $this->siembra->clientePorDefecto();
    }

    public function test_cajaBusqueda_conVariosResultadosDaListado(): void
    {
        $this->siembra->articulo('Manzana Golden');
        $this->siembra->articulo('Manzana Reineta');

        $resultado = \BuscarProductos('cajaBusqueda', 'a.articulo_name', 'Manzana', $this->db, $this->idCliente);

        self::assertSame('Listado', $resultado['Estado']);
        self::assertSame(2, $resultado['Nitems']);
    }

    public function test_cajaBusqueda_sinResultadosDaNoexiste(): void
    {
        $resultado = \BuscarProductos('cajaBusqueda', 'a.articulo_name', 'Inexistente123', $this->db, $this->idCliente);

        self::assertSame('Noexiste', $resultado['Estado']);
        self::assertSame(0, $resultado['Nitems']);
    }

    public function test_idcajaDistinta_conUnaSolaPalabraCoincidenciaExactaDaCorrecto(): void
    {
        $this->siembra->articulo('Golden');

        $resultado = \BuscarProductos('idArticulo', 'a.articulo_name', 'Golden', $this->db, $this->idCliente);

        self::assertSame('Correcto', $resultado['Estado']);
        self::assertSame(1, $resultado['Nitems']);
    }

    /**
     * Defecto: con una busqueda de varias palabras e `idcaja` distinta de `cajaBusqueda`,
     * `Estado` puede quedar sin definir.
     *
     * Sintoma: `BuscarProductos('idArticulo', ..., 'Manzana Golden', ...)` con un solo
     * articulo que coincide devuelve `Nitems=1` pero sin la clave `Estado` en absoluto (PHP
     * avisa de "Undefined array key Estado"). Causa raiz: para `idcaja !== 'cajaBusqueda'`,
     * `funciones.php` arma primero una busqueda "identica" que encadena con `and` una
     * igualdad por cada palabra sobre la MISMA columna (`campo = "Manzana" and campo =
     * "Golden"`) — una condicion que ninguna fila puede cumplir nunca con mas de una
     * palabra, asi que ese primer intento siempre da cero filas. El codigo solo marca
     * `Estado = 'Correcto'` cuando el primer intento (`$i === 0`) encuentra algo, y solo
     * marca `Estado = 'Listado'` cuando el `Nitems` final es mayor que uno: el hueco entre
     * ambos —el segundo intento (LIKE) encuentra exactamente una fila— no fija `Estado` en
     * ningun punto del codigo. `tareas/BuscarProductos.php` lee `$res['Nitems'] === 1` para
     * decidir si devuelve un producto unico, sin mirar `Estado`, asi que el efecto visible
     * es solo el aviso de PHP, no una respuesta incorrecta — pero el contrato de la funcion
     * (documentado como "1: Un producto unico. 2: Un listado. 3: O nada un error.") queda
     * incumplido para este caso. Correccion propuesta: la busqueda "identica" solo tiene
     * sentido con una palabra; con varias, se debe generar como frase completa
     * (`campo = "Manzana Golden"`) en vez de encadenar igualdades por palabra. Evidencia:
     * este test, en rojo mientras el defecto siga sin corregirse por CC.
     */
    public function test_defecto_conVariasPalabrasYUnSoloResultadoPorLikeElEstadoQuedaSinDefinir(): void
    {
        $this->siembra->articulo('Manzana Golden');

        $resultado = \BuscarProductos('idArticulo', 'a.articulo_name', 'Manzana Golden', $this->db, $this->idCliente);

        self::assertArrayHasKey(
            'Estado',
            $resultado,
            'Nitems=1 deberia venir siempre acompanado de un Estado, sea Correcto o el que corresponda'
        );
    }
}

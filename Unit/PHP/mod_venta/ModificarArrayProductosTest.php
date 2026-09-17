<?php

/**
 * `modificarArrayProductos()` normaliza una linea de producto tal como llega de la base
 * (con los alias `NumalbCli`/`Numalbcli` y `NumpedCli`/`Numpedcli` que arrastra el esquema)
 * a la forma que `htmlLineaProductos()` y el PDF esperan.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModVenta;

use PHPUnit\Framework\TestCase;
use TPVFox\Test\CargaAislada;

final class ModificarArrayProductosTest extends TestCase
{
    private static function cargar(): void
    {
        CargaAislada::requerir(RUTA_TPVFOX . '/modulos/mod_venta/funciones.php');
    }

    /** @param array<string,mixed> $extra */
    private static function producto(int $idArticulo, int $nfila, array $extra = []): array
    {
        return array_merge([
            'idArticulo'  => $idArticulo,
            'cref'        => 'REF' . $idArticulo,
            'cdetalle'    => 'Producto ' . $idArticulo,
            'precioCiva'  => 12.10,
            'iva'         => 21,
            'ccodbar'     => '',
            'nfila'       => $nfila,
            'estadoLinea' => 'Activo',
            'ncant'       => 1,
            'nunidades'   => 1,
        ], $extra);
    }

    public function test_T1_conPvpSivaYaPresenteLoConservaYCalculaElImporte(): void
    {
        self::cargar();

        $resultado = \modificarArrayProductos([
            self::producto(1, 1, ['pvpSiva' => 10.00, 'nunidades' => 2]),
        ]);

        self::assertSame('10.00', $resultado[0]['pvpSiva']);
        self::assertSame(20.0, $resultado[0]['importe']);
    }

    public function test_T2_sinPvpSivaLoCalculaDesdePrecioCivaYIva(): void
    {
        self::cargar();

        // precioCiva=12.10, iva=21% -> sinIva = 12.10 - 12.10*0.21 = 9.559 -> 9.56
        $resultado = \modificarArrayProductos([
            self::producto(1, 1),
        ]);

        self::assertSame('9.56', $resultado[0]['pvpSiva']);
    }

    /**
     * Defecto: `$product` no se reinicia en cada vuelta del `foreach`, de modo que una
     * clave que solo se asigna bajo `isset()` —`NumalbCli`, `Numalbcli`, `NumpedCli` y
     * `Numpedcli`, las cuatro comparten el mismo patron— sobrevive de una linea a la
     * siguiente si esta no la trae.
     *
     * Sintoma: una linea sin `NumalbCli` en la entrada sale con el `NumalbCli` de la linea
     * anterior en la salida. Causa raiz: `funciones.php` declara `$product` una sola vez,
     * fuera de cualquier reinicio, y los cuatro `if (isset($producto[...]))` (funciones.php,
     * dentro de `modificarArrayProductos()`) solo escriben la clave cuando esta presente,
     * nunca la borran cuando no lo esta. `htmlLineaProductos()` usa `NumalbCli`/`NumpedCli`
     * para decidir si bloquea el input de cantidad de esa linea (`$Control_btn_input`), asi
     * que el arrastre puede bloquear -o numerar con el adjunto equivocado- una linea que no
     * pertenece a ningun adjunto. Correccion propuesta: inicializar `$product = [];` al
     * principio de cada vuelta del `foreach`. Evidencia: este test, en rojo mientras el
     * defecto siga sin corregirse por CC.
     *
     * @estado rojo
          *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Una linea que no trae clave de adjunto sale con la de la linea anterior,
     *   de modo que puede quedar bloqueada o numerada con el adjunto equivocado.
     * @que-deberia-ocurrir Que cada linea salga solo con lo suyo.
     * @por-que-ocurre La variable que compone la linea se declara una sola vez fuera del bucle y
     *   nunca se reinicia; las claves que solo se escriben cuando existen no se borran cuando no.
     * @como-deberia-funcionar Reiniciarla al principio de cada vuelta.
    */
    public function test_T3_unaClaveDeAdjuntoSobreviveAUnaLineaQueNoLaTrae(): void
    {
        self::cargar();

        $resultado = \modificarArrayProductos([
            self::producto(1, 1, ['NumalbCli' => 777]),
            self::producto(2, 2), // sin NumalbCli
        ]);

        self::assertArrayNotHasKey(
            'NumalbCli',
            $resultado[1],
            'La linea 2 no llevaba NumalbCli en la entrada y no deberia tenerlo en la salida'
        );
    }
}

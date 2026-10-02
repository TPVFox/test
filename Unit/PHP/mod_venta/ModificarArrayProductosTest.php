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

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:672
     *
     * @group esperado
     * @group pedido
     * @group albaran
     * @group adjuntos
     * @group critico
     *
     * @que-ocurre-hoy Una cantidad de mil o mas sale con separador de miles: 1000 se convierte
     *   en «1,000». Es el valor que el navegador recibe al traer las lineas de un pedido a un
     *   albaran, o de un albaran a una factura, y el que devuelve al guardar.
     * @que-deberia-ocurrir Que la cantidad salga como el mismo numero que entro, sin separador.
     * @por-que-ocurre La cantidad se formatea para presentarla, con el separador de miles por
     *   defecto, y ese texto es el que viaja como dato.
     * @como-deberia-funcionar Sin separador de miles: lo que sale tiene que seguir siendo un
     *   numero que una sentencia pueda escribir.
     *
     * @dataProvider cantidadesDeMilOMas
     */
    public function test_T4_unaCantidadDeMilOMasSaleSinSeparadorDeMiles(float $cantidad): void
    {
        self::cargar();

        $resultado = \modificarArrayProductos([
            self::producto(1, 1, ['pvpSiva' => 1.00, 'ncant' => $cantidad, 'nunidades' => $cantidad]),
        ]);

        self::assertStringNotContainsString(',', (string) $resultado[0]['ncant']);
        self::assertEqualsWithDelta($cantidad, (float) $resultado[0]['ncant'], 0.000001);
    }

    /** @return array<string,array{float}> */
    public static function cantidadesDeMilOMas(): array
    {
        return [
            'mil justo'                 => [1000.0],
            'cuatro cifras'             => [2500.0],
            'un millon, dos separadores' => [1000000.0],
            'devolucion de mil'         => [-1000.0],
        ];
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:672
     *
     * @group esperado
     * @group pedido
     * @group albaran
     * @group adjuntos
     * @group existencias
     * @group alto
     *
     * @que-ocurre-hoy Una cantidad con decimales pierde los decimales: 0,420 sale como 0 y 1,5
     *   como 2. No rompe nada, de modo que pasa desapercibido, y esa cantidad es con la que
     *   despues se mueven las existencias.
     * @que-deberia-ocurrir Que la cantidad conserve sus decimales.
     * @por-que-ocurre El formato de presentacion pide cero decimales.
     * @como-deberia-funcionar No redondear una cantidad que el producto admite con decimales.
     *
     * @dataProvider cantidadesConDecimales
     */
    public function test_T5_unaCantidadConDecimalesLosConserva(float $cantidad): void
    {
        self::cargar();

        $resultado = \modificarArrayProductos([
            self::producto(1, 1, ['pvpSiva' => 1.00, 'ncant' => $cantidad, 'nunidades' => $cantidad]),
        ]);

        self::assertEqualsWithDelta($cantidad, (float) $resultado[0]['ncant'], 0.000001);
    }

    /** @return array<string,array{float}> */
    public static function cantidadesConDecimales(): array
    {
        return [
            'peso menor que la unidad' => [0.420],
            'unidad y media'           => [1.5],
            'mil con decimales'        => [1000.250],
        ];
    }

    /**
     * @group control
     * @group adjuntos
     *
     * @para-que-sirve Acota el defecto de las cantidades: por debajo de mil y sin decimales la
     *   cantidad sale intacta, y tiene que seguir saliendo asi cuando se corrija lo demas.
     *
     * @dataProvider cantidadesEnterasPorDebajoDeMil
     */
    public function test_T6_unaCantidadEnteraPorDebajoDeMilSaleIntacta(float $cantidad): void
    {
        self::cargar();

        $resultado = \modificarArrayProductos([
            self::producto(1, 1, ['pvpSiva' => 1.00, 'ncant' => $cantidad, 'nunidades' => $cantidad]),
        ]);

        self::assertStringNotContainsString(',', (string) $resultado[0]['ncant']);
        self::assertEqualsWithDelta($cantidad, (float) $resultado[0]['ncant'], 0.000001);
    }

    /** @return array<string,array{float}> */
    public static function cantidadesEnterasPorDebajoDeMil(): array
    {
        return [
            'una unidad'            => [1.0],
            'el ultimo sin separador' => [999.0],
            'devolucion de una'     => [-1.0],
        ];
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:660-665
     *
     * @group esperado
     * @group pedido
     * @group albaran
     * @group adjuntos
     * @group importes
     * @group critico
     *
     * @que-ocurre-hoy Un precio sin impuestos de mil o mas sale con separador de miles, tanto si
     *   la linea ya lo traia como si se calcula desde el precio con impuestos.
     * @que-deberia-ocurrir Que el precio salga sin separador.
     * @por-que-ocurre Es el mismo formato de presentacion que el de la cantidad, aplicado al
     *   precio en las dos ramas.
     * @como-deberia-funcionar Sin separador de miles en ninguna de las dos ramas.
     *
     * @dataProvider lineasConPrecioDeMilOMas
     *
     * @param array<string,mixed> $linea
     */
    public function test_T7_unPrecioDeMilOMasSaleSinSeparadorDeMiles(array $linea, float $esperado): void
    {
        self::cargar();

        // El producto avisa de «valor no numerico» al multiplicar el precio ya formateado. Se
        // llama con los avisos acallados para que el caso falle por su asercion y no por el aviso.
        $resultado = CargaAislada::llamar(
            static fn () => \modificarArrayProductos([self::producto(1, 1, $linea)])
        );

        self::assertStringNotContainsString(',', (string) $resultado[0]['pvpSiva']);
        self::assertEqualsWithDelta($esperado, (float) $resultado[0]['pvpSiva'], 0.005);
    }

    /** @return array<string,array{array<string,mixed>,float}> */
    public static function lineasConPrecioDeMilOMas(): array
    {
        return [
            'la linea ya trae el precio sin impuestos' => [['pvpSiva' => 1234.50], 1234.50],
            // 2420 con el 21 %, calculado como lo calcula la funcion: 2420 - 2420*0.21
            'se calcula desde el precio con impuestos' => [['precioCiva' => 2420.00, 'iva' => 21], 1911.80],
        ];
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_venta/funciones.php:660-687
     *
     * @group esperado
     * @group adjuntos
     * @group importes
     * @group alto
     *
     * @que-ocurre-hoy El importe de una linea con precio de mil o mas se calcula sobre el texto
     *   con separador, del que solo cuenta lo que hay antes de la coma: 1234,50 por 2 da 2.
     * @que-deberia-ocurrir Que el importe sea el precio por las unidades.
     * @por-que-ocurre El importe se multiplica despues de formatear el precio, no antes.
     * @como-deberia-funcionar Calcular con el numero y formatear, si acaso, al presentar.
     */
    public function test_T8_elImporteDeUnaLineaConPrecioDeMilOMasEsPrecioPorUnidades(): void
    {
        self::cargar();

        $resultado = CargaAislada::llamar(static fn () => \modificarArrayProductos([
            self::producto(1, 1, ['pvpSiva' => 1234.50, 'nunidades' => 2]),
        ]));

        self::assertEqualsWithDelta(2469.00, (float) $resultado[0]['importe'], 0.005);
    }
}

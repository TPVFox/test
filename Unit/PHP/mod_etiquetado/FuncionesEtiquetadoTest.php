<?php

/**
 * Las funciones de `modulos/mod_etiquetado/funciones.php` que no consultan la base: el código de barras
 * de precio y el de peso de cada etiqueta, su dígito de control, el pintado de las líneas de un lote y
 * el de la búsqueda de productos.
 *
 * Otros módulos declaran funciones con el mismo nombre que las de este fichero: cada caso corre en su
 * propio proceso. El dígito de control esperado se calcula aquí con la regla del EAN-13, sin usar la
 * función que se prueba.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModEtiquetado;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesEtiquetadoTest extends TestCase
{
    private const SCRIPT = '<script>x=1</script>';

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_etiquetado/funciones.php';
    }

    /**
     * @comportamiento El código de precio empieza por 21, sigue con la referencia del producto en la
     *   tienda, el precio en tres cifras de euros y dos de céntimos, y termina con su dígito de control.
     */
    public function test_elCodigoDePrecioLlevaLaReferenciaElPrecioYSuDigito(): void
    {
        self::assertSame('211234500350' . $this->digito('211234500350'), \codigoBarrasUnidades('12345', 3.5));
        self::assertSame('211234512399' . $this->digito('211234512399'), \codigoBarrasUnidades('12345', 123.99));
    }

    /**
     * @comportamiento El código de peso empieza por 20, sigue con la referencia, el peso en dos cifras de
     *   kilos y tres de gramos, y termina con su dígito de control.
     */
    public function test_elCodigoDePesoLlevaLaReferenciaElPesoYSuDigito(): void
    {
        self::assertSame('201234501250' . $this->digito('201234501250'), \codigoBarrasPeso('12345', 1.25));
        self::assertSame('201234500100' . $this->digito('201234500100'), \codigoBarrasPeso('12345', 0.1));
    }

    /** @comportamiento El dígito de control es el del EAN-13 para cualquier código de doce cifras. */
    public function test_elDigitoDeControlEsElDelEan13(): void
    {
        foreach (['400638133393', '590123412345', '211234500350', '000000000000', '999999999999'] as $codigo) {
            self::assertSame((int) $this->digito($codigo), \calcularDigitoControl($codigo), $codigo);
        }
    }

    /**
     * @comportamiento Las líneas de un lote se pintan de la última a la primera, cada una con su número,
     *   su nombre, su cantidad, su precio con dos decimales, su fecha, su albarán y su código; la
     *   activa con la papelera y la quitada, tachada y con el botón de recuperarla.
     */
    public function test_lasLineasDeUnLoteSePintanDeLaUltimaALaPrimera(): void
    {
        $html = \lineasProductos([
            $this->linea(1, 'Activo', 'Pan de molde'),
            $this->linea(2, 'Eliminado', 'Pan de molde'),
        ]);

        self::assertLessThan(strpos($html, 'id="Row1"'), strpos($html, 'id="Row2"'));
        self::assertStringContainsString('<tr id="Row2"  class="tachado" >', $html);
        self::assertStringContainsString("retornarFila(2, 'etiquetas');", $html);
        self::assertStringContainsString("eliminarFila(1 , 'etiquetas');", $html);
        self::assertStringContainsString('<td>3.50</td><td>2026-11-01</td>', $html);
        self::assertStringContainsString('<td  id="codigoBarras_1">2112345003504</td>', $html);
    }

    /**
     * @comportamiento La búsqueda de productos pinta la caja con lo buscado y hasta diez productos, cada
     *   uno con su número, su nombre, su precio con IVA y su referencia, que al pulsarlo se elige; con más
     *   de diez, dice cuántos hay.
     */
    public function test_laBusquedaPintaHastaDiezProductos(): void
    {
        $productos = array_map(fn ($i) => ['idArticulo' => $i, 'articulo_name' => "Pan {$i}", 'pvpCiva' => 1.2, 'crefTienda' => '1234' . ($i % 10)], range(1, 12));

        $html = \htmlProductos('pan', $productos)['html'];

        self::assertStringContainsString('value="pan"', $html);
        self::assertStringContainsString('<span>10 Productos de 12</span>', $html);
        self::assertSame(10, substr_count($html, 'class="FilaModal"'));
        self::assertStringContainsString("onclick=\"buscarProducto(1, 'id_producto')\"", $html);
        self::assertStringContainsString('<td>1</td><td>Pan 1</td><td>1.20</td><td>12341</td>', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/funciones.php:164-186
     * @codigo-afectado modulos/mod_etiquetado/funciones.php:187-209
     *
     * @group defecto
     * @group impreso
     * @group alto
     *
     * @que-ocurre-hoy Una referencia de la tienda que no tiene cinco cifras da un código de otra longitud:
     *   no es un EAN-13, y el TPV no lo desglosa.
     * @que-deberia-ocurrir Que un código de precio o de peso tenga siempre trece cifras, o no se dé.
     * @por-que-ocurre El código pega la referencia tal como está guardada, sin comprobar su longitud.
     * @como-deberia-funcionar Exigir una referencia de cinco cifras para las etiquetas, y avisar si no la
     *   tiene.
     */
    public function test_unaReferenciaQueNoTieneCincoCifrasNoDaUnCodigoDeOtraLongitud(): void
    {
        self::assertThat(\codigoBarrasUnidades('123', 1.0), $this->vacioOTreceCifras());
        self::assertThat(\codigoBarrasPeso('1234567', 1.0), $this->vacioOTreceCifras());
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/funciones.php:172-182
     *
     * @group defecto
     * @group impreso
     * @group bajo
     *
     * @que-ocurre-hoy Un precio de mil euros o más termina con un error de PHP: la coma de los millares
     *   entra en el código, y el dígito de control no puede sumarla.
     * @que-deberia-ocurrir Que un precio que no cabe en el código no dé un código, sin error.
     * @por-que-ocurre El precio se formatea con la coma de los millares y se parte por el punto, sin
     *   comprobar que la parte entera quepa en tres cifras.
     * @como-deberia-funcionar Formatear sin separador de millares y rechazar el precio que no cabe.
     */
    public function test_unPrecioDeMilEurosOMasNoDaUnCodigoDeOtraLongitud(): void
    {
        try {
            $codigo = \codigoBarrasUnidades('12345', 1234.5);
        } catch (\Throwable $error) {
            self::fail('El código termina con un error: ' . get_class($error));
        }

        self::assertThat($codigo, $this->vacioOTreceCifras());
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/funciones.php:195-205
     *
     * @group defecto
     * @group impreso
     * @group bajo
     *
     * @que-ocurre-hoy Un peso con más de tres decimales da un código de otra longitud.
     * @que-deberia-ocurrir Que el peso se lleve al gramo, o no se dé un código.
     * @por-que-ocurre El peso se parte por el punto y se pegan todos sus decimales.
     * @como-deberia-funcionar Redondear el peso a tres decimales antes de formar el código.
     */
    public function test_unPesoConMasDeTresDecimalesNoDaUnCodigoDeOtraLongitud(): void
    {
        self::assertThat(\codigoBarrasPeso('12345', 1.2345), $this->vacioOTreceCifras());
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/funciones.php:92-117
     *
     * @group defecto
     * @group busqueda
     * @group bajo
     *
     * @que-ocurre-hoy Una búsqueda sin productos no pinta nada: ni la caja con lo buscado ni la tabla.
     * @que-deberia-ocurrir Que pinte la caja, para buscar otra cosa, y diga que no hay productos.
     * @por-que-ocurre La función solo devuelve el resultado cuando hay productos.
     * @como-deberia-funcionar Devolver la caja y un aviso también sin productos.
     */
    public function test_unaBusquedaSinProductosPintaLaCaja(): void
    {
        $resultado = \htmlProductos('pan', []);

        self::assertIsArray($resultado);
        self::assertStringContainsString('id="cajaBusquedaproductos"', $resultado['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/funciones.php:143-158
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy Las líneas de un lote escriben el nombre tal cual: un nombre con una etiqueta de
     *   script la ejecuta al abrir la ficha.
     * @que-deberia-ocurrir Que el nombre se escriba como texto.
     * @por-que-ocurre Las líneas escriben el nombre guardado sin escaparlo.
     * @como-deberia-funcionar Escapar cada dato al escribirlo.
     */
    public function test_lasLineasEscribenElNombreComoTexto(): void
    {
        $html = \lineasProductos([$this->linea(1, 'Activo', '"><' . substr(self::SCRIPT, 1))]);

        self::assertStringNotContainsString(self::SCRIPT, $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/funciones.php:88-109
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy La búsqueda escribe lo buscado y el nombre de cada producto tal cual: una etiqueta de
     *   script en ellos se ejecuta al abrir la búsqueda.
     * @que-deberia-ocurrir Que lo buscado y los nombres se escriban como texto.
     * @por-que-ocurre La búsqueda escribe los datos sin escaparlos.
     * @como-deberia-funcionar Escapar cada dato al escribirlo.
     */
    public function test_laBusquedaEscribeLoBuscadoYElNombreComoTexto(): void
    {
        $html = \htmlProductos('pan', [['idArticulo' => 1, 'articulo_name' => self::SCRIPT, 'pvpCiva' => 1.2, 'crefTienda' => '12345']])['html'];

        self::assertStringNotContainsString(self::SCRIPT, $html);
    }

    /** El dígito de control de un EAN-13: pesos 1 y 3 alternos desde la izquierda. */
    private function digito(string $doce): string
    {
        $suma = 0;
        foreach (str_split($doce) as $posicion => $cifra) {
            $suma += (int) $cifra * ($posicion % 2 === 0 ? 1 : 3);
        }

        return (string) ((10 - $suma % 10) % 10);
    }

    private function vacioOTreceCifras(): \PHPUnit\Framework\Constraint\Constraint
    {
        return self::logicalOr(self::isEmpty(), self::matchesRegularExpression('/^\d{13}$/'));
    }

    /** @return array<string, mixed> */
    private function linea(int $fila, string $estado, string $nombre): array
    {
        return ['Nfila' => $fila, 'estado' => $estado, 'nombre' => $nombre, 'peso' => 1, 'precio' => 3.5, 'Fecha' => '2026-11-01', 'NumAlb' => '', 'codBarras' => '2112345003504', 'crefTienda' => '12345'];
    }
}

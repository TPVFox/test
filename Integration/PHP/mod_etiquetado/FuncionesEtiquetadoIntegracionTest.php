<?php

/**
 * Repetir las líneas de un producto en un lote (`repetirLineasProducto()` de
 * `modulos/mod_etiquetado/funciones.php`): lee el producto con su precio y su referencia en la tienda, y
 * da tantas líneas como envases, cada una con su código de barras, y su pintado.
 *
 * Otros módulos declaran funciones con el mismo nombre que las de este fichero: cada caso corre en su
 * propio proceso, con la conexión del caso, dentro de su transacción. La clase de artículos se carga
 * antes, como la carga la petición de fondo. El producto lo da de alta el propio caso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModEtiquetado;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesEtiquetadoIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    private Siembra $siembra;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/clases/articulos.php');
        $this->incluirTPVFox('/modulos/mod_etiquetado/funciones.php');
        $this->siembra = new Siembra($this->db);
        $this->idTienda = $this->siembra->tiendaPorDefecto();
    }

    /**
     * @comportamiento Repetir por unidad da una línea por envase, numerada detrás de las que ya había, con
     *   el nombre, el precio con IVA de la tienda, la fecha de caducidad, cantidad 1 y el código de precio;
     *   y pinta una fila por línea.
     */
    public function test_repetirPorUnidadDaUnaLineaPorEnvaseConElCodigoDePrecio(): void
    {
        $id = $this->producto('Pan de molde');

        $resultado = \repetirLineasProducto(3, $id, $this->db, $this->idTienda, '2026-11-01', 2, 1);

        self::assertSame([3, 4, 5], array_column($resultado['productos'], 'Nfila'));
        $linea = $resultado['productos'][0];
        self::assertSame(['Pan de molde', 1, 3.5, '2026-11-01', 'Activo', '12345'], [$linea['nombre'], $linea['peso'], (float) $linea['precio'], $linea['Fecha'], $linea['estado'], $linea['crefTienda']]);
        self::assertSame('211234500350' . $this->digito('211234500350'), $linea['codBarras']);
        self::assertSame(3, substr_count($resultado['html'], '<tr id="Row'));
        self::assertStringContainsString('<tr id="Row5"', $resultado['html']);
    }

    /** @comportamiento Repetir por peso da las líneas con el código de peso de un kilo. */
    public function test_repetirPorPesoDaElCodigoDeUnKilo(): void
    {
        $id = $this->producto('Queso tierno');

        $resultado = \repetirLineasProducto(2, $id, $this->db, $this->idTienda, '2026-11-01', 0, 2);

        self::assertSame([1, 2], array_column($resultado['productos'], 'Nfila'));
        self::assertSame('201234501000' . $this->digito('201234501000'), $resultado['productos'][1]['codBarras']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/funciones.php:62-77
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy Las líneas repetidas escriben el nombre del producto tal cual: un nombre con una
     *   etiqueta de script la ejecuta al añadirlas.
     * @que-deberia-ocurrir Que el nombre se escriba como texto.
     * @por-que-ocurre Las filas se componen con el nombre leído sin escaparlo.
     * @como-deberia-funcionar Escapar cada dato al escribirlo.
     */
    public function test_repetirEscribeElNombreComoTexto(): void
    {
        $id = $this->producto(self::SCRIPT);

        $resultado = \repetirLineasProducto(1, $id, $this->db, $this->idTienda, '2026-11-01', 0, 1);

        self::assertStringNotContainsString(self::SCRIPT, $resultado['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/funciones.php:15-46
     *
     * @group defecto
     * @group impreso
     * @group medio
     *
     * @que-ocurre-hoy Repetir un producto sin precio o sin referencia en la tienda da líneas sin nombre, sin
     *   precio y con un código de barras incompleto, con avisos de PHP.
     * @que-deberia-ocurrir Que no dé ninguna línea, y se diga que el producto no tiene precio o referencia
     *   en la tienda.
     * @por-que-ocurre La lectura del producto no trae filas, y las líneas se arman sin comprobarlo.
     * @como-deberia-funcionar Comprobar que la lectura trae el producto antes de repetirlo.
     */
    public function test_repetirUnProductoSinPrecioEnLaTiendaNoDaLineas(): void
    {
        $id = $this->siembra->articulo('Sin precio');

        $resultado = @\repetirLineasProducto(2, $id, $this->db, $this->idTienda, '2026-11-01', 0, 1);

        self::assertSame([], $resultado['productos']);
    }

    private function producto(string $nombre): int
    {
        $id = $this->siembra->articulo($nombre);
        $this->siembra->precioYTienda($id, 3.5, 3.18, $this->idTienda, ['crefTienda' => '12345']);

        return $id;
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
}

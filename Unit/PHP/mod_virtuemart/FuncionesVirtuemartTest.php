<?php

/**
 * Las funciones de la importación de la tienda web (`modulos/mod_virtuemart/funciones.php`): comparar un
 * producto de la web con el del TPV según las opciones elegidas, y pintar las líneas de los productos
 * nuevos y de los modificados.
 *
 * Ninguna consulta la base: reciben los datos ya leídos, y aquí se les dan los del caso. La comparación
 * anota cada diferencia en el registro de errores de PHP, que durante el caso va a la nada.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModVirtuemart;

use PHPUnit\Framework\TestCase;

final class FuncionesVirtuemartTest extends TestCase
{
    private string $registro;

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_virtuemart/funciones.php';
        $this->registro = (string) ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->registro);
        parent::tearDown();
    }

    /** @param array<string, mixed> $cambios */
    private function web(array $cambios = []): array
    {
        return $cambios + ['id' => 31, 'nombre' => 'Pera conferencia', 'refTienda' => 'PER-1', 'iva' => '10.00', 'precioSiva' => '2.000000', 'codBarra' => '8400000000017'];
    }

    /** @param array<string, mixed> $cambios */
    private function tpv(array $cambios = []): array
    {
        return $cambios + ['idArticulo' => '5', 'articulo_name' => 'Pera conferencia', 'cref_tienda_principal' => 'PER-1', 'iva' => '10.00', 'pvpSiva' => '2.00', 'codBarras' => ['8400000000017'], 'ref_tiendas' => [['idTienda' => '9', 'crefTienda' => 'PER-WEB']]];
    }

    /** @return array<string, string> */
    private function opciones(string $codBarras = '1', string $referencia = '1'): array
    {
        return ['Sel_codBarras' => $codBarras, 'Sel_referencia' => $referencia];
    }

    /** @comportamiento Un producto de la web igual al del TPV —nombre, referencia, precio y códigos— no se marca como modificado. */
    public function test_unProductoIgualNoSeMarcaModificado(): void
    {
        self::assertSame(0, \comparacionesProductos($this->web(), $this->tpv(), 9, $this->opciones()));
    }

    /** @comportamiento El nombre, la referencia, el precio o un código de barras distinto marcan el producto como modificado. */
    public function test_cadaDiferenciaMarcaElProductoModificado(): void
    {
        $nombre = \comparacionesProductos($this->web(['nombre' => 'Pera limonera']), $this->tpv(), 9, $this->opciones());
        $referencia = \comparacionesProductos($this->web(['refTienda' => 'PER-2']), $this->tpv(), 9, $this->opciones());
        $precio = \comparacionesProductos($this->web(['precioSiva' => '2.10']), $this->tpv(), 9, $this->opciones());
        $codigo = \comparacionesProductos($this->web(['codBarra' => '8400000000017;8400000000024']), $this->tpv(), 9, $this->opciones());

        self::assertSame([1, 1, 1, 1], [$nombre, $referencia, $precio, $codigo]);
    }

    /** @comportamiento Con la opción de ignorarlos, la referencia y los códigos de barras de la web no cuentan como diferencia. */
    public function test_conLasOpcionesDeIgnorarNoCuentanLaReferenciaNiLosCodigos(): void
    {
        $web = $this->web(['refTienda' => 'PER-2', 'codBarra' => '8400000000024']);

        self::assertSame(0, \comparacionesProductos($web, $this->tpv(), 9, $this->opciones('2', '3')));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_virtuemart/funciones.php:18-25
     *
     * @group defecto
     * @group errores
     * @group bajo
     *
     * @que-ocurre-hoy Un IVA distinto marca el producto, pero deja un aviso de PHP: la anotación lee una
     *   clave que no existe.
     * @que-deberia-ocurrir Que la diferencia de IVA se anote sin avisos.
     * @por-que-ocurre La anotación usa la clave `'$ivaWeb'`, entre comillas simples, en lugar del IVA.
     * @como-deberia-funcionar Anotar el IVA de la web.
     */
    public function test_unIvaDistintoSeMarcaSinAvisos(): void
    {
        $avisos = [];
        set_error_handler(static function (int $nivel, string $mensaje) use (&$avisos): bool {
            $avisos[] = $mensaje;

            return true;
        });
        try {
            $comprobacion = \comparacionesProductos($this->web(['iva' => '21.00']), $this->tpv(), 9, $this->opciones());
        } finally {
            restore_error_handler();
        }

        self::assertSame(1, $comprobacion);
        self::assertSame([], $avisos);
    }

    /**
     * @comportamiento Cada producto nuevo se pinta en una fila numerada desde la cuenta recibida, con sus
     *   datos y el botón que lo inserta en el TPV con ellos.
     */
    public function test_losProductosNuevosSePintanConSuBoton(): void
    {
        $html = \lineaProductosNuevos([$this->web(), $this->web(['id' => 32, 'nombre' => 'Uva'])], '3');
        $sinCuenta = \lineaProductosNuevos([$this->web()], '');

        self::assertStringContainsString('<tr id="nuevo_3">', $html);
        self::assertStringContainsString('<tr id="nuevo_4">', $html);
        self::assertStringContainsString('<b>Nombre:</b>Pera conferencia<br>', $html);
        self::assertMatchesRegularExpression("#onclick=\"addProductoWeb\\('Pera conferencia',\\s*'PER-1','10.00', '2.000000',\\s*'8400000000017', 31, 3\\)\"#", $html);
        self::assertStringContainsString('<tr id="nuevo_0">', $sinCuenta);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_virtuemart/funciones.php:59-70,83-121
     *
     * @group defecto
     * @group seguridad
     * @group medio
     *
     * @que-ocurre-hoy El nombre de un producto de la web se pega en la página y en la llamada del botón sin
     *   escapar: un apóstrofo corta la llamada, y unas marcas se interpretan como página.
     * @que-deberia-ocurrir Que los datos de la web se pinten como texto y viajen como dato en la llamada.
     * @por-que-ocurre Las filas concatenan los datos tal como llegan de la web.
     * @como-deberia-funcionar Escapar al pintar, y pasar los datos a la llamada codificados.
     */
    public function test_losDatosDeLaWebSePintanComoTexto(): void
    {
        $html = \lineaProductosNuevos([$this->web(['nombre' => "Pera d'Anjou <b>extra</b>"])], 0);

        self::assertStringNotContainsString('<b>extra</b>', $html);
        self::assertStringNotContainsString("addProductoWeb('Pera d'Anjou", $html);
    }

    /**
     * @comportamiento Cada producto modificado se pinta con sus datos de la web y los del TPV —la referencia
     *   de esa tienda web y sus códigos—, y el botón que cambia el TPV con los de la web.
     */
    public function test_losProductosModificadosSePintanConLosDatosDeLasDos(): void
    {
        $html = \lineaProductosModificador([[$this->web(['codBarra' => '8400000000017;8400000000024']), $this->tpv()]], 9, '');

        self::assertStringContainsString('<tr id="mod_0">', $html);
        self::assertStringContainsString('<b>Cod Barras:</b>8400000000017  8400000000024  </td>', $html);
        self::assertStringContainsString('<b>Referencia Tienda Web :</b> PER-WEB <br>', $html);
        self::assertMatchesRegularExpression("#onclick=\"modificarProductosTpvWeb\\('Pera conferencia',\\s*'PER-1','10.00', '2.000000',\\s*'8400000000017;8400000000024', '5', 0\\)\"#", $html);
    }
}

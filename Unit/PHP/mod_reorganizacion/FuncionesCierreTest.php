<?php

/**
 * Las funciones de `modulos/mod_reorganizacion/funciones.php` que no consultan la base: quitar los
 * productos repetidos de una lista, guardar la configuración del cierre de stock anual sobre su XML y
 * pintar la búsqueda de familias.
 *
 * Otros módulos declaran funciones con el mismo nombre que las de este fichero: cada caso corre en su
 * propio proceso. La configuración es una copia en memoria de la del módulo.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModReorganizacion;

use PHPUnit\Framework\TestCase;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class FuncionesCierreTest extends TestCase
{
    private const SCRIPT = '<script>x=1</script>';

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_reorganizacion/funciones.php';
    }

    /** @comportamiento Quitar los repetidos de una lista de productos deja el primero de cada uno, en orden. */
    public function test_quitarLosRepetidosDejaElPrimeroDeCadaProducto(): void
    {
        $lista = [['idArticulo' => '3', 'stockOn' => '1'], ['idArticulo' => '1', 'stockOn' => '2'], ['idArticulo' => '3', 'stockOn' => '9']];

        self::assertSame([['idArticulo' => '3', 'stockOn' => '1'], ['idArticulo' => '1', 'stockOn' => '2']], \normalizarProductos($lista));
    }

    /**
     * @comportamiento Guardar la configuración del cierre cambia el proveedor, la reescritura, las series y
     *   los productos por albarán, y deja como familias excluidas exactamente las que llegan.
     */
    public function test_guardarLaConfiguracionCambiaSusValoresYSusFamilias(): void
    {
        $xml = $this->seccion();
        \guardarConfiguracionXML($xml, $this->datos(), 'cierre_stock_anual');

        self::assertSame(['7', 'Proveedor de cierre', 'false', 'B', 'K', '50'], [
            (string) $xml->ajustes_globales->proveedor['id'], (string) $xml->ajustes_globales->proveedor,
            (string) $xml->ajustes_globales->reescribir_albaran, (string) $xml->ajustes_globales->serie_albaran->apertura,
            (string) $xml->ajustes_globales->serie_albaran->cierre, (string) $xml->ajustes_globales->num_productos,
        ]);
        self::assertSame([['3', 'Envases'], ['4', 'Bolsas']], $this->familias($xml));
    }

    /** @comportamiento Una sección de la configuración que no es la del cierre no se guarda. */
    public function test_unaSeccionQueNoEsLaDelCierreNoSeGuarda(): void
    {
        $xml = $this->seccion();

        self::assertFalse(\guardarConfiguracionXML($xml, $this->datos(), 'otra'));
        self::assertSame('100', (string) $xml->ajustes_globales->num_productos);
    }

    /** @comportamiento Guardar una configuración a la que le falta un campo se rechaza con su motivo. */
    public function test_unaConfiguracionIncompletaSeRechaza(): void
    {
        $datos = $this->datos();
        unset($datos['serieCierre']);

        $this->expectExceptionMessage('serie_albaran');
        \guardarConfiguracionXML($this->seccion(), $datos, 'cierre_stock_anual');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/funciones.php:217-223
     *
     * @group defecto
     * @group guardado
     * @group bajo
     *
     * @que-ocurre-hoy Una familia excluida con un «&» en su nombre se guarda en la configuración sin su
     *   nombre, con un aviso de PHP; al validar la configuración, esa familia sale como no válida.
     * @que-deberia-ocurrir Que el nombre se guarde tal como es.
     * @por-que-ocurre El nombre se pasa como contenido del nodo nuevo, que el XML interpreta como
     *   referencia de entidad.
     * @como-deberia-funcionar Escapar el nombre al crear el nodo, o asignarlo como texto después.
     */
    public function test_unaFamiliaConAmpersandSeGuardaConSuNombre(): void
    {
        $xml = $this->seccion();
        $datos = ['familiasExcluidas' => [['id' => '3', 'nombre' => 'Frutas & verduras']]] + $this->datos();
        @\guardarConfiguracionXML($xml, $datos, 'cierre_stock_anual');

        self::assertSame([['3', 'Frutas & verduras']], $this->familias($xml));
    }

    /**
     * @comportamiento La búsqueda de familias pinta la caja con lo buscado y una fila por familia, cada
     *   una con la orden de elegirla; sin familias, un aviso.
     */
    public function test_laBusquedaDeFamiliasPintaLaCajaYUnaFilaPorFamilia(): void
    {
        $con = \htmlFamilias('frut', 'reorganizarTPV', 'Familia', [$this->familia(12, 'Frutas')]);
        $sin = \htmlFamilias('nada', 'reorganizarTPV', 'Familia', []);

        self::assertSame(1, $con['encontrados']);
        self::assertStringContainsString('value="frut"', $con['html']);
        self::assertStringContainsString("onclick=\"buscarFamilia('reorganizarTPV' , 'id_familia', 12, 'popup');\"", $con['html']);
        self::assertStringContainsString('<td>Frutas</td>', $con['html']);
        self::assertStringContainsString('No se encontro ninguna familia', $sin['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/funciones.php:101-125
     *
     * @group defecto
     * @group busqueda
     * @group bajo
     *
     * @que-ocurre-hoy La búsqueda de familias avisa de que enseña diez, y pinta once.
     * @que-deberia-ocurrir Que pinte como mucho diez familias.
     * @por-que-ocurre El recorrido corta después de pintar la fila de índice diez, que es la undécima.
     * @como-deberia-funcionar Cortar antes de pintar la undécima.
     */
    public function test_laBusquedaDeFamiliasPintaComoMuchoDiez(): void
    {
        $familias = array_map(fn (int $i) => $this->familia($i, "Familia {$i}"), range(1, 12));
        $html = \htmlFamilias('Familia', 'reorganizarTPV', 'Familia', $familias)['html'];

        self::assertStringContainsString('Se muestra 10 familias de 12', $html);
        self::assertSame(10, substr_count($html, 'class="FilaModal"'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/funciones.php:96-99
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Lo buscado se escribe tal cual en la caja de la búsqueda de familias: una búsqueda
     *   preparada inserta su propio código en la página.
     * @que-deberia-ocurrir Que lo buscado se escriba como texto.
     * @por-que-ocurre Se pega en el atributo de la caja sin escaparlo.
     * @como-deberia-funcionar Escapar lo buscado al escribirlo.
     */
    public function test_laBusquedaDeFamiliasEscribeLoBuscadoComoTexto(): void
    {
        $html = \htmlFamilias('"><script>x=1</script>', 'reorganizarTPV', 'Familia', [])['html'];

        self::assertStringNotContainsString(self::SCRIPT, $html);
    }

    /** La sección del cierre de la configuración del módulo, como la lee la petición de fondo. */
    private function seccion(): \SimpleXMLElement
    {
        $xml = simplexml_load_file(RUTA_TPVFOX . '/modulos/mod_reorganizacion/parametros.xml');

        return $xml->configuracion->cierre_stock_anual;
    }

    /** @return array<string, mixed> */
    private function datos(): array
    {
        return [
            'idProveedor' => '7', 'proveedor' => 'Proveedor de cierre', 'reescribirAlbaran' => false,
            'serieApertura' => 'B', 'serieCierre' => 'K', 'numProductos' => '50',
            'familiasExcluidas' => [['id' => '3', 'nombre' => 'Envases'], ['id' => '4', 'nombre' => 'Bolsas']],
        ];
    }

    /** @return list<array{0:string,1:string}> */
    private function familias(\SimpleXMLElement $xml): array
    {
        $familias = [];
        foreach ($xml->familias_excluidas->familia as $familia) {
            $familias[] = [(string) $familia['id'], (string) $familia];
        }

        return $familias;
    }

    /** @return array<string, mixed> */
    private function familia(int $id, string $nombre): array
    {
        return ['idFamilia' => $id, 'familiaNombre' => $nombre, 'familiaPadre' => 0, 'beneficiomedio' => '0.00'];
    }
}

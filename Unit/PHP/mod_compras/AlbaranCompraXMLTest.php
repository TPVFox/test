<?php

/**
 * La conversión del albarán de proveedor a XML y de vuelta (`modulos/mod_compras/clases/ClaseAlbaranCompraXML.php`)
 * —también la del albarán de cierre del año anterior al de apertura del actual— contra su esquema
 * (`albaran_compra_v1.xsd`), y las ventanas de importar albaranes (`importarAlbaranes.php`).
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModCompras;

use PHPUnit\Framework\TestCase;

final class AlbaranCompraXMLTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/modulos/mod_compras/clases/ClaseAlbaranCompraXML.php';
        require_once RUTA_TPVFOX . '/modulos/mod_compras/importarAlbaranes.php';
    }

    /**
     * Un albarán guardado como lo lee la clase del albarán: una línea de pera de 10 € al 4 %.
     *
     * @param array<string, mixed> $cambios
     * @return array<string, mixed>
     */
    private function albaran(array $cambios = []): array
    {
        return $cambios + [
            'Numalbpro' => '901', 'Su_numero' => 'A-7', 'Fecha' => '2026-02-03 10:00:00', 'idTienda' => '55', 'idProveedor' => '7',
            'estado' => 'Guardado', 'FechaVencimiento' => '2026-03-05',
            'Productos' => [['id' => '31', 'idArticulo' => '3', 'cref' => '1001', 'cdetalle' => 'Pera "buena" & <dulce>', 'ncant' => '5.000',
                'nunidades' => '5.000', 'ultimoCoste' => '2.0000', 'iva' => '4.00', 'nfila' => '1', 'ccodbar' => '8400000000001', 'ref_prov' => 'RP-1']],
            'Datostotales' => ['desglose' => ['4.00' => ['base' => 10, 'iva' => 0.4, 'BaseYiva' => 10.4]], 'subivas' => 0.4, 'total' => 10.4],
        ];
    }

    private function cumpleElEsquema(\SimpleXMLElement $xml): bool
    {
        $documento = new \DOMDocument();
        $documento->loadXML((string) $xml->asXML());

        return @$documento->schemaValidate(RUTA_TPVFOX . '/modulos/mod_compras/albaran_compra_v1.xsd');
    }

    /** @comportamiento El albarán se escribe con su cabecera, sus líneas y su desglose, y cumple el esquema. */
    public function test_unAlbaranSeEscribeEnXml(): void
    {
        $xml = \ClaseAlbaranCompraXML::arrayToSimpleXML($this->albaran());

        self::assertSame('A-7', (string) $xml['idOrigen']);
        self::assertSame('COMPRA', (string) $xml->Meta->Tipo);
        self::assertSame('901', (string) $xml->Cabecera->Numero);
        self::assertSame('A-7', (string) $xml->Cabecera->SuNumero);
        self::assertSame('2026-03-05', (string) $xml->Cabecera->FechaVencimiento);
        self::assertSame('Pera "buena" & <dulce>', (string) $xml->Lineas->Linea->Descripcion);
        self::assertSame('31', (string) $xml->Lineas->Linea['idInterno']);
        self::assertSame('8400000000001', (string) $xml->Lineas->Linea->CodigoBarras);
        self::assertSame('RP-1', (string) $xml->Lineas->Linea->ReferenciaProveedor);
        self::assertSame('4.00', (string) $xml->Totales->DesgloseIVA->Tipo);
        self::assertSame('10.4', (string) $xml->Totales->TotalDocumento);
        self::assertTrue($this->cumpleElEsquema($xml));
    }

    /** @comportamiento Sin vencimiento —la fecha a ceros— el XML no lo lleva. */
    public function test_sinVencimientoNoLoLleva(): void
    {
        $xml = \ClaseAlbaranCompraXML::arrayToSimpleXML($this->albaran(['FechaVencimiento' => '0000-00-00']));

        self::assertFalse(isset($xml->Cabecera->FechaVencimiento));
        self::assertTrue($this->cumpleElEsquema($xml));
    }

    /** @comportamiento El XML se lee de vuelta en el mismo albarán: cabecera, líneas y desglose. */
    public function test_elXmlSeLeeDeVuelta(): void
    {
        $leido = \ClaseAlbaranCompraXML::simpleXMLToArray(\ClaseAlbaranCompraXML::arrayToSimpleXML($this->albaran()));

        self::assertSame(['901', 'A-7', 55, 7, 'Guardado', '2026-03-05'], [$leido['Numalbpro'], $leido['Su_numero'], $leido['idTienda'], $leido['idProveedor'], $leido['estado'], $leido['FechaVencimiento']]);
        self::assertSame(['id' => 31, 'idArticulo' => 3, 'cdetalle' => 'Pera "buena" & <dulce>', 'ncant' => 5.0, 'nunidades' => '5.000', 'ultimoCoste' => 2.0, 'iva' => 4.0, 'nfila' => 1, 'ccodbar' => '8400000000001', 'ref_prov' => 'RP-1'], $leido['Productos'][0]);
        self::assertSame(['desglose' => [4 => ['base' => 10.0, 'iva' => 0.4, 'BaseYiva' => 10.4]], 'subivas' => 0.4, 'total' => 10.4], $leido['Datostotales']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/ClaseAlbaranCompraXML.php:25
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy Un número del proveedor con un «&» se escribe vacío en la cabecera del XML, con un aviso
     *   de PHP: el albarán exportado pierde su número del proveedor.
     * @que-deberia-ocurrir Que el número del proveedor llegue entero al XML.
     * @por-que-ocurre El valor se pasa a `addChild` sin escapar, y `addChild` lee el «&» como el principio de
     *   una entidad.
     * @como-deberia-funcionar Escribir el valor como texto del elemento.
     */
    public function test_unNumeroDelProveedorConAmpersandSeEscribeEntero(): void
    {
        $xml = @\ClaseAlbaranCompraXML::arrayToSimpleXML($this->albaran(['Su_numero' => 'A&B-7']));

        self::assertSame('A&B-7', (string) $xml->Cabecera->SuNumero);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/albaran_compra_v1.xsd:91-97
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy Un albarán con una línea al IVA del cero no cumple el esquema: el esquema solo admite el
     *   4, el 10 y el 21 %, y la tabla de IVA de TPVFox tiene también el del cero.
     * @que-deberia-ocurrir Que un albarán con cualquier tipo de IVA de TPVFox se pueda exportar.
     * @por-que-ocurre El tipo de IVA del esquema es una lista cerrada de tres valores.
     * @como-deberia-funcionar Admitir los tipos de IVA de TPVFox.
     */
    public function test_unAlbaranConIvaAlCeroCumpleElEsquema(): void
    {
        $albaran = $this->albaran();
        $albaran['Productos'][0]['iva'] = '0.00';
        $albaran['Datostotales'] = ['desglose' => ['0.00' => ['base' => 10, 'iva' => 0, 'BaseYiva' => 10]], 'subivas' => 0, 'total' => 10];

        self::assertTrue($this->cumpleElEsquema(\ClaseAlbaranCompraXML::arrayToSimpleXML($albaran)));
    }

    /**
     * @comportamiento El albarán de cierre se lee como el de apertura del año actual: «#C» pasa a «#A» en sus
     *   números, la fecha es el 1 de enero, «CIERRE» pasa a «APERT.» en las líneas, las cantidades y el desglose
     *   van en positivo, el estado es «Importado» y los totales se calculan de las líneas.
     */
    public function test_elAlbaranDeCierreSeLeeComoElDeApertura(): void
    {
        $cierre = \ClaseAlbaranCompraXML::arrayToSimpleXML($this->albaran(['Numalbpro' => '2025#C1', 'Su_numero' => 'P#C7']));
        $cierre->Lineas->Linea->Descripcion = 'CIERRE Pera';
        $cierre->Lineas->Linea->Cantidad = '-5.000';
        $cierre->Lineas->Linea->Unidades = '-5.000';
        $cierre->Totales->DesgloseIVA->Base = '-10';

        $apertura = \ClaseAlbaranCompraXML::simpleXMLToArrayCambioAno($cierre);

        self::assertSame(['2025#A1', 'P#A7', date('Y') . '-01-01 00:00:00', 'Importado', '2026-03-05'], [$apertura['Numalbpro'], $apertura['suNumero'], $apertura['fecha'], $apertura['estado'], $apertura['fechaVenci']]);
        self::assertSame([10.0, 10.4], [$apertura['total_siniva'], round($apertura['total'], 2)]);
        $linea = json_decode($apertura['productos'], true)[0];
        self::assertSame(['APERT. Pera', 5, 5, 'Activo'], [$linea['cdetalle'], $linea['ncant'], $linea['nunidades'], $linea['estado']]);
        self::assertSame(10.0, $apertura['DatosTotales']['desglose'][4]['base']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_compras/clases/ClaseAlbaranCompraXML.php:176-180
     *
     * @group defecto
     * @group integridad
     * @group medio
     *
     * @que-ocurre-hoy Al leer el albarán de cierre, la referencia del proveedor de cada línea se guarda como
     *   la referencia del producto, y la referencia del proveedor queda vacía.
     * @que-deberia-ocurrir Que la referencia del proveedor vuelva como referencia del proveedor.
     * @por-que-ocurre La lectura pone `ReferenciaProveedor` en `cref`; la referencia del producto no se exporta.
     * @como-deberia-funcionar Leerla en `ref_prov`, y exportar y leer también la del producto.
     */
    public function test_laReferenciaDelProveedorVuelveComoReferenciaDelProveedor(): void
    {
        $apertura = \ClaseAlbaranCompraXML::simpleXMLToArrayCambioAno(\ClaseAlbaranCompraXML::arrayToSimpleXML($this->albaran()));
        $linea = json_decode($apertura['productos'], true)[0];

        self::assertSame('RP-1', $linea['ref_prov'] ?? null);
    }

    /** @comportamiento La ventana de importar ofrece el cambio de año, el XML y el CSV; la del cambio de año pide el fichero XML. */
    public function test_lasVentanasDeImportar(): void
    {
        $metodos = modalImportarAlbaranes();
        $cambioAno = modalCambioAno();

        self::assertStringContainsString("ampliarInformacionImportar('CambioAno')", $metodos);
        self::assertStringContainsString("ampliarInformacionImportar('ImportarAlbaran')", $metodos);
        self::assertStringContainsString("ampliarInformacionImportar('ImportarCSV')", $metodos);
        self::assertStringContainsString('id="areaImportacionAlbaranes"', $metodos);
        self::assertStringContainsString('name="inputAlbaranCierreAno" accept=".xml" required', $cambioAno);
        self::assertStringContainsString('onclick="importarAlbaranCierreAno()"', $cambioAno);
    }
}

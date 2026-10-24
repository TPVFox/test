<?php

/**
 * El cierre de stock anual por la petición de fondo de `mod_reorganizacion` (`tareas.php`): contar las
 * familias que cerrar, cerrar una familia —en modo manual o automático— generando sus albaranes de
 * compra, guardar y validar su configuración, y las ventanas y búsquedas de familias que la acompañan.
 *
 * Cada petición se ejecuta en un PHP aparte, con la sesión del usuario de pruebas o sin ninguna, los
 * permisos del módulo concedidos salvo los que el caso quite, y una transacción que se deshace al
 * terminar. La configuración del cierre es una copia de la del módulo en una carpeta propia del caso,
 * que es desde donde corre la petición: el módulo no se toca. El cierre toma las existencias de la
 * tienda 1: la sesión es de una tienda con ese número, que el caso da de alta si no existe, salvo donde
 * el caso prueba otra. Las familias, los productos y sus
 * existencias que siembra el caso quedan con un nombre —`:f1`, `:p1`…— para el formulario y las
 * consultas. Al terminar, el proceso responde lo que dan las consultas del caso, también si la
 * petición acaba con un error.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModReorganizacion;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class CierreStockIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    private int $idUsuario;

    private int $idTienda;

    private string $carpeta = '';

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
        $this->carpeta = sys_get_temp_dir() . '/tpvfox-cierre-' . bin2hex(random_bytes(4));
        mkdir($this->carpeta);
        copy(RUTA_TPVFOX . '/modulos/mod_reorganizacion/parametros.xml', $this->carpeta . '/parametros.xml');
    }

    protected function tearDown(): void
    {
        foreach (array_merge(glob($this->carpeta . '/cache/*') ?: [], glob($this->carpeta . '/*') ?: []) as $fichero) {
            is_dir($fichero) ? @rmdir($fichero) : unlink($fichero);
        }
        @rmdir($this->carpeta);
        parent::tearDown();
    }

    /**
     * @comportamiento Sin familia elegida, contar las familias da las de primer nivel con algún producto
     *   con existencias en la tienda, sin las excluidas en la configuración; con una elegida, solo esa.
     */
    public function test_contarFamiliasDaLasDePrimerNivelConExistencias(): void
    {
        $preparacion = [...$this->arbol(), ...$this->producto('Naranja', ':f3', 5), ...$this->producto('Leche', ':f4', 2), ...$this->producto('Agua', ':f5', 0)];
        $this->configuracion(fn (\SimpleXMLElement $xml) => $this->excluir($xml, '%f4%'));
        $todas = $this->ejecutar(['pulsado' => 'contarfamilias', 'idFamiliaCierreStock' => '""'], $preparacion);
        $elegida = $this->ejecutar(['pulsado' => 'contarfamilias', 'idFamiliaCierreStock' => '"' . 7 . '"'], $preparacion);

        self::assertSame([$todas['ids'][':f1']], array_values(array_intersect((array) $todas['respuesta'], array_values($todas['ids']))));
        self::assertSame(['7'], $elegida['respuesta']);
    }

    /**
     * @comportamiento Cerrar a mano una familia de primer nivel guarda un albarán de compra contra el
     *   proveedor elegido, fechado el 31 de diciembre del año de la sesión, con el número de la serie de
     *   cierre, una línea por producto con su existencia en negativo, su último coste y su IVA, la base y
     *   el IVA del albarán, y deja sus existencias a cero.
     */
    public function test_cerrarUnaFamiliaAManoGuardaSuAlbaranYDejaLasExistenciasACero(): void
    {
        $cierre = $this->cerrar('manual', ':f1', [...$this->arbol(), ...$this->producto('Naranja', ':f3', 5, 2.0, 10), ...$this->producto('Limón', ':f2', 3, 1.0, 10)], [
            'albaran' => "SELECT CONCAT(Fecha, '|', idProveedor, '|', Su_numero, '|', estado, '|', ROUND(total_siniva, 2), '|', ROUND(total, 2)) FROM albprot WHERE Su_numero LIKE 'ID-:f1#%'",
            'lineas' => "SELECT GROUP_CONCAT(CONCAT(l.idArticulo, ':', ROUND(l.nunidades, 2), ':', ROUND(l.costeSiva, 2), ':', l.iva) ORDER BY l.idArticulo) FROM albprolinea l JOIN albprot a ON a.id = l.idalbpro WHERE a.Su_numero LIKE 'ID-:f1#%'",
            'existencias' => 'SELECT GROUP_CONCAT(ROUND(stockOn, 2) ORDER BY idArticulo) FROM articulosStocks WHERE idArticulo IN (:p1, :p2)',
        ]);

        self::assertSame("2026-12-31 00:00:00|112|ID-{$cierre['ids'][':f1']}#C|Guardado|-13.00|-14.30", $cierre['consultas']['albaran']);
        self::assertSame("{$cierre['ids'][':p1']}:-5.00:2.00:10.00,{$cierre['ids'][':p2']}:-3.00:1.00:10.00", $cierre['consultas']['lineas']);
        self::assertSame('0.00,0.00', $cierre['consultas']['existencias']);
    }

    /**
     * @comportamiento El albarán de cierre queda a nombre del usuario de la sesión, en la tienda de la
     *   sesión.
     */
    public function test_elAlbaranDeCierreQuedaANombreDelUsuarioDeLaSesion(): void
    {
        $cierre = $this->cerrar('manual', ':f1', [...$this->arbol(), ...$this->producto('Naranja', ':f3', 5, 2.0, 10)], [
            'autor' => "SELECT CONCAT(idUsuario, '|', idTienda) FROM albprot WHERE Su_numero LIKE 'ID-:f1#%'",
        ]);

        self::assertSame($this->idUsuario . '|1', $cierre['consultas']['autor']);
    }

    /**
     * @comportamiento La línea de cierre de cada producto lleva su nombre, recortado a ochenta caracteres,
     *   y el número de la familia que se cierra.
     */
    public function test_laLineaDeCierreLlevaElNombreYLaFamilia(): void
    {
        $largo = str_repeat('a', 90);
        $cierre = $this->cerrar('manual', ':f1', [...$this->arbol(), ...$this->producto('Naranja', ':f1', 5), ...$this->producto($largo, ':f1', 1)], [
            'corto' => 'SELECT cdetalle FROM albprolinea WHERE idArticulo = :p1',
            'largo' => 'SELECT cdetalle FROM albprolinea WHERE idArticulo = :p2',
        ]);
        $f1 = $cierre['ids'][':f1'];

        self::assertSame("Naranja CIERRE ID: {$f1}", $cierre['consultas']['corto']);
        self::assertSame(str_repeat('a', 80) . " CIERRE ID: {$f1}", $cierre['consultas']['largo']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/funciones.php:16-20
     *
     * @group defecto
     * @group guardado
     * @group medio
     *
     * @que-ocurre-hoy Si el nombre de un producto tiene una letra con tilde justo en el carácter ochenta, el
     *   albarán de cierre de su familia se guarda con su total y sin ninguna línea: sus existencias no se
     *   cierran, y la pantalla da el cierre por hecho.
     * @que-deberia-ocurrir Que el producto se cierre con su línea, con el nombre recortado por una letra
     *   entera.
     * @por-que-ocurre El nombre se recorta por bytes y queda con media letra; la lista de productos que se
     *   pasa al guardado del albarán no se puede codificar, y el guardado la recibe vacía.
     * @como-deberia-funcionar Recortar por letras, y no guardar un albarán cuyas líneas no se guardan.
     */
    public function test_unNombreLargoConTildeSeCierraConSuLinea(): void
    {
        $cierre = $this->cerrar('manual', ':f1', [...$this->arbol(), ...$this->producto(str_repeat('a', 79) . 'ñandú', ':f1', 4)], [
            'lineas' => 'SELECT COUNT(*) FROM albprolinea WHERE idArticulo = :p1',
            'existencia' => 'SELECT ROUND(stockOn, 2) FROM articulosStocks WHERE idArticulo = :p1',
            'albaranes' => "SELECT CONCAT(COUNT(*), '|', IFNULL(MAX(ROUND(total, 2)), '-')) FROM albprot WHERE Su_numero LIKE 'ID-:f1#%'",
        ]);

        self::assertSame(['1', '0.00'], [$cierre['consultas']['lineas'], $cierre['consultas']['existencia']]);
    }

    /**
     * @comportamiento Cerrar a mano una familia con más productos que los permitidos por albarán reparte
     *   sus productos en varios albaranes, numerados como partes de la familia.
     */
    public function test_cerrarAManoUnaFamiliaGrandeLaRepartaEnVariosAlbaranes(): void
    {
        $productos = [];
        foreach (range(1, 5) as $i) {
            $productos = [...$productos, ...$this->producto("Producto {$i}", ':f1', 1)];
        }
        $this->configuracion(fn (\SimpleXMLElement $xml) => $xml->ajustes_globales->num_productos = '2');
        $cierre = $this->cerrar('manual', ':f1', [...$this->arbol(), ...$productos], [
            'partes' => "SELECT GROUP_CONCAT(CONCAT(a.Su_numero, '=', (SELECT COUNT(*) FROM albprolinea l WHERE l.idalbpro = a.id)) ORDER BY a.Su_numero) FROM albprot a WHERE a.Su_numero LIKE 'ID-:f1-P%'",
        ]);
        $f1 = $cierre['ids'][':f1'];

        self::assertSame("ID-{$f1}-P1#C=2,ID-{$f1}-P2#C=2,ID-{$f1}-P3#C=1", $cierre['consultas']['partes']);
    }

    /**
     * @comportamiento Cerrar a mano una familia de segundo o de tercer nivel cierra los productos de esa
     *   familia y de las que cuelgan de ella.
     */
    public function test_cerrarAManoUnaSubfamiliaCierraSusProductos(): void
    {
        $preparacion = [...$this->arbol(), ...$this->producto('Naranja', ':f3', 5), ...$this->producto('Limón', ':f2', 3), ...$this->producto('Manzana', ':f1', 4)];
        $segundo = $this->cerrar('manual', ':f2', $preparacion, ['cerrados' => 'SELECT GROUP_CONCAT(idArticulo ORDER BY idArticulo) FROM articulosStocks WHERE idArticulo IN (:p1, :p2, :p3) AND stockOn = 0']);
        $tercero = $this->cerrar('manual', ':f3', $preparacion, ['cerrados' => 'SELECT GROUP_CONCAT(idArticulo ORDER BY idArticulo) FROM articulosStocks WHERE idArticulo IN (:p1, :p2, :p3) AND stockOn = 0']);

        self::assertSame($segundo['ids'][':p1'] . ',' . $segundo['ids'][':p2'], $segundo['consultas']['cerrados']);
        self::assertSame($tercero['ids'][':p1'], $tercero['consultas']['cerrados']);
    }

    /**
     * @comportamiento El cierre automático de la última familia guarda su albarán y, después, uno más con
     *   los productos con existencias que no están en ninguna familia.
     */
    public function test_elCierreAutomaticoCierraLaFamiliaYAlFinalLosProductosSinFamilia(): void
    {
        $cierre = $this->cerrar('automatico', ':f1', [...$this->arbol(), ...$this->producto('Naranja', ':f3', 5), ...$this->producto('Suelto', null, 2)], [
            'familia' => "SELECT COUNT(*) FROM albprot WHERE Su_numero = 'ID-:f1#C'",
            'suelto' => "SELECT COUNT(*) FROM albprolinea l JOIN albprot a ON a.id = l.idalbpro WHERE a.Su_numero LIKE 'ID-SINID#%' AND l.idArticulo = :p2",
        ]);

        self::assertSame(['1', '1'], [$cierre['consultas']['familia'], $cierre['consultas']['suelto']]);
    }

    /**
     * @comportamiento En el cierre automático, una familia con más productos que los permitidos por
     *   albarán se cierra por subfamilias, de la más grande a la más pequeña, hasta que el resto cabe en
     *   uno, que se cierra con el número de la familia.
     */
    public function test_elCierreAutomaticoDeUnaFamiliaGrandeLaCierraPorSubfamilias(): void
    {
        $preparacion = [...$this->arbol(), "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('Pomelos', :f1, 0, 1)"];
        foreach (range(1, 3) as $i) {
            $preparacion = [...$preparacion, ...$this->producto("Cítrico {$i}", ':f2', 1)];
        }
        $preparacion = [...$preparacion, ...$this->producto('Pomelo', ':f6', 1)];
        $this->configuracion(fn (\SimpleXMLElement $xml) => $xml->ajustes_globales->num_productos = '3');
        $cierre = $this->cerrar('automatico', ':f1', $preparacion, [
            'albaranes' => "SELECT GROUP_CONCAT(CONCAT(a.Su_numero, '=', (SELECT COUNT(*) FROM albprolinea l WHERE l.idalbpro = a.id)) ORDER BY a.id) FROM albprot a WHERE a.Su_numero LIKE 'ID-%'",
        ]);

        self::assertSame("ID-{$cierre['ids'][':f2']}#C=3,ID-{$cierre['ids'][':f1']}#C=1", $cierre['consultas']['albaranes']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/clases/ClaseReorganizar.php:145-165
     *
     * @group defecto
     * @group numeracion
     * @group bajo
     *
     * @que-ocurre-hoy Cuando el cierre automático reparte una familia por subfamilias, un producto que
     *   cuelga directamente de la familia, sin subfamilia, no sale en el albarán de su familia: sale al
     *   final, en el de los productos sin familia.
     * @que-deberia-ocurrir Que salga en el albarán de su familia.
     * @por-que-ocurre El resto de la familia se lee quitando las subfamilias ya cerradas con un «NOT IN»
     *   sobre la subfamilia, y para un producto sin subfamilia esa comparación no es cierta.
     * @como-deberia-funcionar Quitar las subfamilias cerradas sin dejar fuera los productos que no tienen
     *   subfamilia.
     */
    public function test_unProductoSinSubfamiliaSaleEnElAlbaranDeSuFamilia(): void
    {
        $preparacion = $this->arbol();
        foreach (range(1, 3) as $i) {
            $preparacion = [...$preparacion, ...$this->producto("Cítrico {$i}", ':f2', 1)];
        }
        $preparacion = [...$preparacion, ...$this->producto('Manzana', ':f1', 1)];
        $this->configuracion(fn (\SimpleXMLElement $xml) => $xml->ajustes_globales->num_productos = '3');
        $cierre = $this->cerrar('automatico', ':f1', $preparacion, [
            'albaran' => 'SELECT a.Su_numero FROM albprolinea l JOIN albprot a ON a.id = l.idalbpro WHERE l.idArticulo = :p4',
        ]);

        self::assertSame("ID-{$cierre['ids'][':f1']}#C", $cierre['consultas']['albaran']);
    }

    /** @comportamiento Los productos de una familia excluida en la configuración no se cierran. */
    public function test_losProductosDeUnaFamiliaExcluidaNoSeCierran(): void
    {
        $this->configuracion(fn (\SimpleXMLElement $xml) => $this->excluir($xml, '%f3%'));
        $cierre = $this->cerrar('automatico', ':f1', [...$this->arbol(), ...$this->producto('Naranja', ':f3', 5), ...$this->producto('Limón', ':f2', 3)], [
            'existencias' => 'SELECT GROUP_CONCAT(ROUND(stockOn, 2) ORDER BY idArticulo) FROM articulosStocks WHERE idArticulo IN (:p1, :p2)',
        ]);

        self::assertSame('5.00,0.00', $cierre['consultas']['existencias']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:247-254
     *
     * @group defecto
     * @group existencias
     * @group medio
     *
     * @que-ocurre-hoy Cerrar una familia a mano cierra también los productos de una familia excluida en la
     *   configuración, y deja a cero sus existencias.
     * @que-deberia-ocurrir Que el cierre a mano deje fuera las familias excluidas, como el automático.
     * @por-que-ocurre El cierre a mano lee los productos de la familia sin pasar las familias excluidas,
     *   que solo recibe el automático.
     * @como-deberia-funcionar Pasar las familias excluidas también a las lecturas del cierre a mano.
     */
    public function test_cerrarAManoTampocoCierraLosProductosDeUnaFamiliaExcluida(): void
    {
        $this->configuracion(fn (\SimpleXMLElement $xml) => $this->excluir($xml, '%f3%'));
        $cierre = $this->cerrar('manual', ':f1', [...$this->arbol(), ...$this->producto('Naranja', ':f3', 5), ...$this->producto('Limón', ':f2', 3)], [
            'existencias' => 'SELECT GROUP_CONCAT(ROUND(stockOn, 2) ORDER BY idArticulo) FROM articulosStocks WHERE idArticulo IN (:p1, :p2)',
        ]);

        self::assertSame('5.00,0.00', $cierre['consultas']['existencias']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/clases/ClaseReorganizar.php:85-266
     * @codigo-afectado modulos/mod_reorganizacion/funciones.php:40-84
     *
     * @group defecto
     * @group existencias
     * @group medio
     *
     * @que-ocurre-hoy En una tienda que no tiene el número 1, el cierre no cierra ninguno de sus productos:
     *   busca las existencias en la tienda 1, y descuenta las que encuentre en la tienda de la sesión.
     * @que-deberia-ocurrir Que el cierre deje a cero las existencias de la tienda de la sesión.
     * @por-que-ocurre Las consultas del cierre tienen la tienda 1 escrita, y el albarán se guarda en la de
     *   la sesión.
     * @como-deberia-funcionar Tomar la tienda de la sesión en las consultas del cierre.
     */
    public function test_elCierreCierraLasExistenciasDeLaTiendaDeLaSesion(): void
    {
        $cierre = $this->ejecutar($this->formularioCierre('manual', ':f1'), [...$this->arbol(), ...$this->producto('Naranja', ':f1', 5)], true, [
            'existencia' => 'SELECT ROUND(stockOn, 2) FROM articulosStocks WHERE idArticulo = :p1 AND idTienda = :tienda',
        ], $this->idTienda);

        self::assertSame('0.00', $cierre['consultas']['existencia']);
    }

    /** @comportamiento Un producto que está en dos familias se cierra una sola vez. */
    public function test_unProductoEnDosFamiliasSeCierraUnaVez(): void
    {
        $cierre = $this->cerrar('automatico', ':f1', [...$this->arbol(), ...$this->producto('Naranja', ':f3', 5), 'INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (:p1, :f2)'], [
            'lineas' => 'SELECT COUNT(*) FROM albprolinea WHERE idArticulo = :p1',
        ]);

        self::assertSame('1', $cierre['consultas']['lineas']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:298-305
     *
     * @group defecto
     * @group numeracion
     * @group medio
     *
     * @que-ocurre-hoy El cierre automático numera el albarán del resto de una familia con la serie «C»,
     *   aunque la configuración diga otra; solo los albaranes de sus subfamilias y de los productos sin
     *   familia llevan la configurada.
     * @que-deberia-ocurrir Que todos los albaranes del cierre lleven la serie de cierre configurada.
     * @por-que-ocurre El albarán del resto se genera sin pasarle la serie, y la función toma «C» por
     *   defecto.
     * @como-deberia-funcionar Pasar la serie configurada también al albarán del resto de la familia.
     */
    public function test_elCierreAutomaticoNumeraConLaSerieConfigurada(): void
    {
        $this->configuracion(fn (\SimpleXMLElement $xml) => $xml->ajustes_globales->serie_albaran->cierre = 'Z');
        $cierre = $this->cerrar('automatico', ':f1', [...$this->arbol(), ...$this->producto('Naranja', ':f3', 5)], [
            'numero' => "SELECT Su_numero FROM albprot WHERE Su_numero LIKE 'ID-:f1#%'",
        ]);

        self::assertSame("ID-{$cierre['ids'][':f1']}#Z", $cierre['consultas']['numero']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/funciones.php:7-38
     * @codigo-afectado modulos/mod_compras/clases/albaranesCompras.php:276-297
     *
     * @group defecto
     * @group guardado
     * @group alto
     *
     * @que-ocurre-hoy Cerrar una familia con un producto cuyo nombre lleva comillas dobles termina con un
     *   error de la base después de haber dejado sus existencias a cero: el albarán queda sin líneas y el
     *   inventario de la familia, perdido.
     * @que-deberia-ocurrir Que el producto se cierre con su línea, o que no se cierre nada.
     * @por-que-ocurre El cierre pasa el nombre del producto tal cual al guardado del albarán, que lo pega
     *   entre comillas en la sentencia de las líneas después de haber descontado las existencias.
     * @como-deberia-funcionar Guardar el albarán con los valores como datos, y descontar las existencias
     *   solo si sus líneas se guardan.
     */
    public function test_unProductoConComillasEnElNombreSeCierraConSuLinea(): void
    {
        $cierre = $this->cerrar('manual', ':f1', [...$this->arbol(), ...$this->producto('Pan "rústico"', ':f1', 4)], [
            'lineas' => 'SELECT COUNT(*) FROM albprolinea WHERE idArticulo = :p1',
            'existencia' => 'SELECT ROUND(stockOn, 2) FROM articulosStocks WHERE idArticulo = :p1',
        ]);

        self::assertContains([$cierre['consultas']['lineas'], $cierre['consultas']['existencia']], [['1', '0.00'], ['0', '4.00']]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:1-42
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Una petición sin sesión atiende el cierre: lee la familia y sus productos y empieza a
     *   guardar el albarán, sin usuario, hasta que la base lo rechaza y la petición termina con un error.
     * @que-deberia-ocurrir Que sin sesión la petición se niegue, sin tocar nada y diciéndolo.
     * @por-que-ocurre La petición carga el arranque común sin la cabecera, que es la que pide la sesión, y no
     *   la comprueba; la causa común es la del núcleo.
     * @como-deberia-funcionar Comprobar la sesión antes de atender la orden.
     */
    public function test_sinSesionNoSeCierraElStock(): void
    {
        $cierre = $this->ejecutar($this->formularioCierre('manual', ':f1'), [...$this->arbol(), ...$this->producto('Naranja', ':f1', 5)], false, ['existencia' => 'SELECT ROUND(stockOn, 2) FROM articulosStocks WHERE idArticulo = :p1']);

        self::assertSame('5.00', $cierre['consultas']['existencia']);
        self::assertStringNotContainsString('Fatal error', $cierre['errores']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:213-326
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Un usuario sin el permiso de cerrar el stock anual lo cierra por la petición de fondo.
     * @que-deberia-ocurrir Que sin ese permiso no se cierre nada.
     * @por-que-ocurre La petición no consulta ningún permiso: solo la pantalla esconde el botón.
     * @como-deberia-funcionar Consultar el permiso de la acción antes de cerrar.
     */
    public function test_sinPermisoNoSeCierraElStock(): void
    {
        $cierre = $this->ejecutar($this->formularioCierre('manual', ':f1'), [
            ...$this->arbol(), ...$this->producto('Naranja', ':f1', 5),
            "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_reorganizacion'",
        ], true, ['existencia' => 'SELECT ROUND(stockOn, 2) FROM articulosStocks WHERE idArticulo = :p1']);

        self::assertSame('5.00', $cierre['consultas']['existencia']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/clases/ClaseReorganizar.php:268-277
     * @codigo-afectado modulos/mod_reorganizacion/clases/ClaseReorganizar.php:145-178
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Cerrar a mano una familia cuyo número llega con una condición añadida cierra los
     *   productos con existencias de todas las familias.
     * @que-deberia-ocurrir Que no se cierre ninguna familia que no sea la elegida.
     * @por-que-ocurre El número de la familia se pega a las consultas sin convertirlo.
     * @como-deberia-funcionar Convertir el número en entero, o pasarlo como dato.
     */
    public function test_unaFamiliaConUnaCondicionNoCierraOtras(): void
    {
        $formulario = ['familias' => json_encode([':f1 OR 1=1'])] + $this->formularioCierre('manual', ':f1');
        $cierre = $this->ejecutar($formulario, [...$this->arbol(), ...$this->producto('Naranja', ':f1', 5), ...$this->producto('Leche', ':f4', 2)], true, [
            'otra' => 'SELECT ROUND(stockOn, 2) FROM articulosStocks WHERE idArticulo = :p2',
        ]);

        self::assertSame('2.00', $cierre['consultas']['otra']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/funciones.php:425-433
     *
     * @group defecto
     * @group validacion
     * @group medio
     *
     * @que-ocurre-hoy Con cero productos por albarán en la configuración, cerrar a mano una familia termina
     *   con un error de división por cero y no dice nada.
     * @que-deberia-ocurrir Que el cierre se niegue con un aviso, o que la configuración no lo admita.
     * @por-que-ocurre El reparto en albaranes divide por el número de productos configurado sin
     *   comprobarlo.
     * @como-deberia-funcionar Rechazar al guardar un número de productos que no sea un entero positivo, y
     *   comprobarlo antes de repartir.
     */
    public function test_ceroProductosPorAlbaranNoTerminaElCierre(): void
    {
        $this->configuracion(fn (\SimpleXMLElement $xml) => $xml->ajustes_globales->num_productos = '0');
        $cierre = $this->cerrar('manual', ':f1', [...$this->arbol(), ...$this->producto('Naranja', ':f1', 5)]);

        self::assertStringNotContainsString('Fatal error', $cierre['errores']);
        self::assertIsArray($cierre['respuesta']);
    }

    /**
     * @comportamiento Guardar la configuración del cierre la escribe en la carpeta de la configuración, y
     *   la siguiente lectura la toma: el proveedor, los productos por albarán, las series y las familias
     *   excluidas.
     */
    public function test_guardarLaConfiguracionLaDejaParaLaSiguienteLectura(): void
    {
        $guardar = $this->ejecutar(['pulsado' => 'guardarConfiguracionXML', 'seccion' => 'cierre_stock_anual', 'datos' => json_encode($this->datosConfiguracion())]);
        $guardada = simplexml_load_file($this->carpeta . '/cache/parametros.xml');
        $seccion = $guardada->configuracion->cierre_stock_anual;

        self::assertSame(['guardado' => true], $guardar['respuesta']);
        self::assertSame(['7', 'Proveedor de cierre', '50', 'false', 'B', 'K'], [
            (string) $seccion->ajustes_globales->proveedor['id'], (string) $seccion->ajustes_globales->proveedor,
            (string) $seccion->ajustes_globales->num_productos, (string) $seccion->ajustes_globales->reescribir_albaran,
            (string) $seccion->ajustes_globales->serie_albaran->apertura, (string) $seccion->ajustes_globales->serie_albaran->cierre,
        ]);
        self::assertSame(['3' => 'Envases'], [(string) $seccion->familias_excluidas->familia['id'] => (string) $seccion->familias_excluidas->familia]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:354-362
     * @codigo-afectado modulos/mod_reorganizacion/funciones.php:136-224
     *
     * @group defecto
     * @group validacion
     * @group medio
     *
     * @que-ocurre-hoy Guardar la configuración con un número de productos por albarán que no es un entero
     *   positivo, o con un proveedor que no existe, la guarda igual y responde que se guardó.
     * @que-deberia-ocurrir Que una configuración no válida no se guarde, y se diga por qué.
     * @por-que-ocurre El guardado solo comprueba que lleguen los campos, no lo que valen; la validación de
     *   los valores es otra orden que nadie llama antes de guardar.
     * @como-deberia-funcionar Validar los valores al guardar, y responder el motivo si no son válidos.
     */
    public function test_unaConfiguracionNoValidaNoSeGuarda(): void
    {
        $guardar = $this->ejecutar(['pulsado' => 'guardarConfiguracionXML', 'seccion' => 'cierre_stock_anual', 'datos' => json_encode(['numProductos' => '0', 'idProveedor' => '999999'] + $this->datosConfiguracion())]);

        self::assertFileDoesNotExist($this->carpeta . '/cache/parametros.xml');
        self::assertArrayNotHasKey('guardado', (array) $guardar['respuesta']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/funciones.php:147-192
     *
     * @group defecto
     * @group validacion
     * @group medio
     *
     * @que-ocurre-hoy Guardar la configuración sin uno de sus campos termina la petición con una excepción
     *   que nadie recoge, y la pantalla no recibe respuesta.
     * @que-deberia-ocurrir Que responda que la configuración está incompleta.
     * @por-que-ocurre La comprobación de los campos lanza una excepción, y la petición no la recoge.
     * @como-deberia-funcionar Recoger la excepción y responder su motivo.
     */
    public function test_unaConfiguracionIncompletaRespondeQueFaltaUnCampo(): void
    {
        $datos = $this->datosConfiguracion();
        unset($datos['numProductos']);
        $guardar = $this->ejecutar(['pulsado' => 'guardarConfiguracionXML', 'seccion' => 'cierre_stock_anual', 'datos' => json_encode($datos)]);

        self::assertStringNotContainsString('Fatal error', $guardar['errores']);
        self::assertIsArray($guardar['respuesta']);
    }

    /**
     * @comportamiento Validar la configuración guardada da por buena la que tiene un proveedor y unas
     *   familias excluidas que existen con ese nombre, y avisa de los que no.
     */
    public function test_validarLaConfiguracionCompruebaElProveedorYLasFamilias(): void
    {
        $preparacion = ["INSERT INTO proveedores (razonsocial, nombrecomercial, nif, direccion, fecha_creado, estado) VALUES ('Cierre SL', 'Cierre', 'X0000000X', 'Sin direccion', '2020-01-01 00:00:00', 'Activo')", ...$this->arbol()];
        $this->configuracion(function (\SimpleXMLElement $xml): void {
            $xml->ajustes_globales->proveedor['id'] = '%v1%';
            $xml->ajustes_globales->proveedor = 'Cierre';
            unset($xml->familias_excluidas->familia);
            $familia = $xml->familias_excluidas->addChild('familia', 'Frutas');
            $familia->addAttribute('id', '%f1%');
        });
        $valida = $this->ejecutar(['pulsado' => 'validarConfiguracionXML', 'seccion' => 'cierre_stock_anual'], $preparacion);
        $this->configuracion(fn (\SimpleXMLElement $xml) => $xml->ajustes_globales->proveedor = 'Otro nombre');
        $noValida = $this->ejecutar(['pulsado' => 'validarConfiguracionXML', 'seccion' => 'cierre_stock_anual'], $preparacion);

        self::assertFalse($valida['respuesta']['error']);
        self::assertTrue($noValida['respuesta']['error']);
        self::assertStringContainsString('no es válido', $noValida['respuesta']['mensaje']);
    }

    /**
     * @comportamiento Validar los datos del cierre a mano exige una familia, y comprueba que el proveedor y
     *   la familia existen con ese nombre.
     */
    public function test_validarElFormularioExigeUnaFamiliaYUnProveedorQueExistan(): void
    {
        $preparacion = ["INSERT INTO proveedores (razonsocial, nombrecomercial, nif, direccion, fecha_creado, estado) VALUES ('Cierre SL', 'Cierre', 'X0000000X', 'Sin direccion', '2020-01-01 00:00:00', 'Activo')", ...$this->arbol()];
        $bueno = $this->ejecutar(['pulsado' => 'validarDatosFormulario', 'seccion' => 'cierre_stock_anual', 'datos' => json_encode(['idProveedor' => ':v1', 'proveedor' => 'Cierre', 'idFamilia' => ':f1', 'familia' => 'Frutas'])], $preparacion);
        $sinFamilia = $this->ejecutar(['pulsado' => 'validarDatosFormulario', 'seccion' => 'cierre_stock_anual', 'datos' => json_encode(['idProveedor' => ':v1', 'proveedor' => 'Cierre', 'idFamilia' => '', 'familia' => ''])], $preparacion);
        $otroProveedor = $this->ejecutar(['pulsado' => 'validarDatosFormulario', 'seccion' => 'cierre_stock_anual', 'datos' => json_encode(['idProveedor' => ':v1', 'proveedor' => 'Otro', 'idFamilia' => ':f1', 'familia' => 'Frutas'])], $preparacion);

        self::assertFalse($bueno['respuesta']['error']);
        self::assertStringContainsString('No se ha seleccionado ninguna familia', $sinFamilia['respuesta']['mensaje']);
        self::assertStringContainsString('proveedor seleccionado', $otroProveedor['respuesta']['mensaje']);
    }

    /**
     * @comportamiento La ventana del cierre enseña el proveedor y el número de familias excluidas de la
     *   configuración, y ofrece el cierre base y el manual, con su botón de validar.
     */
    public function test_laVentanaDelCierreEnsenaLaConfiguracion(): void
    {
        $ventana = $this->ejecutar(['pulsado' => 'modalCerrarStock', 'titulo' => 'Cierre Stock Anual', 'dedonde' => 'reorganizarTPV']);
        $html = (string) $ventana['respuesta']['html'];

        self::assertStringContainsString('id="proveedorPredefinido" value="112"', $html);
        self::assertStringContainsString('Familias excluidas: 4', $html);
        self::assertStringContainsString('id="modoManual"', $html);
        self::assertStringContainsString('id="btnValidarCierre"', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas/modalCerrarStock.php:26
     * @codigo-afectado modulos/mod_reorganizacion/tareas/modalCerrarStock.php:124-125
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy El título que llega en la petición se escribe tal cual dentro del JavaScript de los
     *   botones de la ventana del cierre: un título preparado ejecuta su propio código.
     * @que-deberia-ocurrir Que el título se escriba como texto.
     * @por-que-ocurre La ventana pega el título en el atributo de los botones sin escaparlo.
     * @como-deberia-funcionar Escapar el título, o no tomarlo de la petición.
     */
    public function test_laVentanaDelCierreEscribeElTituloComoTexto(): void
    {
        $ventana = $this->ejecutar(['pulsado' => 'modalCerrarStock', 'titulo' => "x'); alert('y", 'dedonde' => 'reorganizarTPV']);

        self::assertStringNotContainsString("x'); alert('y", (string) $ventana['respuesta']['html']);
    }

    /** @comportamiento La ventana de la configuración del cierre se pinta con la configuración guardada. */
    public function test_laVentanaDeLaConfiguracionSePintaConLaGuardada(): void
    {
        $ventana = $this->ejecutar(['pulsado' => 'abrirConfiguracionXML', 'seccion' => 'cierre_stock_anual', 'dedonde' => 'reorganizarTPV']);
        $html = (string) $ventana['respuesta']['html'];

        self::assertStringContainsString('112', $html);
        self::assertStringContainsString('Servicios', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas.php:337-347
     *
     * @group defecto
     * @group validacion
     * @group bajo
     *
     * @que-ocurre-hoy Pedir la ventana de configuración de una sección que no existe responde vacío, con
     *   avisos de PHP.
     * @que-deberia-ocurrir Que responda un error.
     * @por-que-ocurre La sección se pega al nombre del fichero que se incluye sin comprobarla.
     * @como-deberia-funcionar Atender solo las secciones del módulo y responder un error para las demás.
     */
    public function test_laConfiguracionDeUnaSeccionQueNoExisteRespondeUnError(): void
    {
        $ventana = $this->ejecutar(['pulsado' => 'abrirConfiguracionXML', 'seccion' => 'otra', 'dedonde' => 'reorganizarTPV']);

        self::assertArrayHasKey('error', (array) $ventana['respuesta']);
    }

    /**
     * @comportamiento Buscar familias sin nada escrito pinta todas; por su nombre, pinta las que lo
     *   contienen; por el número de una familia que el TPV no enseña, la elige.
     */
    public function test_buscarFamiliasPorSuNombreOTodas(): void
    {
        $preparacion = [...$this->arbol(), 'UPDATE familias SET mostrar_tpv = 0 WHERE idFamilia = :f5'];
        $porNombre = $this->ejecutar(['pulsado' => 'buscarFamilias', 'busqueda' => 'Cítr', 'idcaja' => 'Familia', 'dedonde' => 'reorganizarTPV'], $preparacion);
        $todas = $this->ejecutar(['pulsado' => 'buscarFamilias', 'busqueda' => '', 'idcaja' => 'Familia', 'dedonde' => 'reorganizarTPV'], $preparacion);
        $oculta = $this->ejecutar(['pulsado' => 'buscarFamilias', 'busqueda' => ':f5', 'idcaja' => 'id_familia', 'dedonde' => 'reorganizarTPV'], $preparacion);

        self::assertStringContainsString('Cítricos', html_entity_decode((string) $porNombre['respuesta']['html']['html']));
        self::assertGreaterThanOrEqual(5, $todas['respuesta']['Nitems']);
        self::assertSame([(int) $oculta['ids'][':f5'], 'Bebidas', 1], [(int) $oculta['respuesta']['id'], $oculta['respuesta']['nombre'], $oculta['respuesta']['Nitems']]);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_reorganizacion/tareas/buscarFamilias.php:40-52
     *
     * @group defecto
     * @group busqueda
     * @group bajo
     *
     * @que-ocurre-hoy Buscar por su número una familia que el TPV enseña no la elige: abre la lista de
     *   búsqueda como si estuviera inactiva. Solo se elige así una familia que el TPV no enseña.
     * @que-deberia-ocurrir Que buscar una familia por su número la elija.
     * @por-que-ocurre La búsqueda toma por inactiva la familia marcada para enseñarse en el TPV: la
     *   condición está al revés.
     * @como-deberia-funcionar Elegir la familia que existe, o comprobar la marca en el sentido que le da
     *   el módulo de familias.
     */
    public function test_buscarPorSuNumeroUnaFamiliaQueElTpvEnsenaLaElige(): void
    {
        $porNumero = $this->ejecutar(['pulsado' => 'buscarFamilias', 'busqueda' => ':f2', 'idcaja' => 'id_familia', 'dedonde' => 'reorganizarTPV'], $this->arbol());

        self::assertSame([(int) $porNumero['ids'][':f2'], 1], [(int) $porNumero['respuesta']['id'], $porNumero['respuesta']['Nitems']]);
    }

    /**
     * @comportamiento El catálogo de familias las pinta jerarquizadas, con las de primer nivel a la vista y
     *   las demás ocultas, cada una con su botón para elegirla.
     */
    public function test_elCatalogoPintaLasFamiliasJerarquizadas(): void
    {
        $catalogo = $this->ejecutar(['pulsado' => 'catalogoFamilias'], $this->arbol());
        $html = (string) $catalogo['respuesta']['html'];

        self::assertMatchesRegularExpression('/<tr class="FilaFamilia nivel-1" data-ruta="Frutas"\s*>/', $html);
        self::assertMatchesRegularExpression('/<tr class="FilaFamilia nivel-2" data-ruta="Frutas-Cítricos" style="display: none;">/', $html);
        self::assertStringContainsString('seleccionarFamilia(' . $catalogo['ids'][':f3'], $html);
    }

    /**
     * El árbol de familias del caso: Frutas (`:f1`) con Cítricos (`:f2`) y, debajo, Naranjas (`:f3`);
     * Lácteos (`:f4`) y Bebidas (`:f5`), de primer nivel.
     *
     * @return list<string>
     */
    private function arbol(): array
    {
        return [
            "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('Frutas', 0, 0, 1)",
            "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('Cítricos', :f1, 0, 1)",
            "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('Naranjas', :f2, 0, 1)",
            "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('Lácteos', 0, 0, 1)",
            "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('Bebidas', 0, 0, 1)",
        ];
    }

    /**
     * Un producto con su familia, si la tiene, y sus existencias en la tienda de pruebas.
     *
     * @return list<string>
     */
    private function producto(string $nombre, ?string $familia, float $existencias, float $coste = 1.0, float $iva = 0.0): array
    {
        $sql = ["INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, tipo, iva, costepromedio, beneficio) VALUES ('" . addslashes($nombre) . "', 'Activo', '2020-01-01 00:00:00', {$coste}, 'unidad', {$iva}, {$coste}, 0)"];
        if ($familia !== null) {
            $sql[] = "INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (:pN, {$familia})";
        }
        $sql[] = "INSERT INTO articulosStocks (idArticulo, idTienda, stockOn, stockMin, stockMax) VALUES (:pN, :tienda, {$existencias}, 0, 0)";

        return $sql;
    }

    /** Pone en la configuración del caso una sola familia excluida, la del número que se le dé. */
    private function excluir(\SimpleXMLElement $xml, string $familia): void
    {
        unset($xml->familias_excluidas->familia);
        $nodo = $xml->familias_excluidas->addChild('familia', 'Excluida');
        $nodo->addAttribute('id', $familia);
    }

    /**
     * Cambia la configuración del cierre de la carpeta del caso. Los números de lo sembrado se escriben
     * como `%f1%`, y la petición los sustituye antes de leerla.
     */
    private function configuracion(callable $cambio): void
    {
        $xml = simplexml_load_file($this->carpeta . '/parametros.xml');
        $cambio($xml->configuracion->cierre_stock_anual);
        $xml->asXML($this->carpeta . '/parametros.xml');
    }

    /** @return array<string, mixed> */
    private function datosConfiguracion(): array
    {
        return [
            'idProveedor' => '7', 'proveedor' => 'Proveedor de cierre', 'reescribirAlbaran' => false,
            'serieApertura' => 'B', 'serieCierre' => 'K', 'numProductos' => '50',
            'familiasExcluidas' => [['id' => '3', 'nombre' => 'Envases']],
        ];
    }

    /** @return array<string, string> */
    private function formularioCierre(string $modo, string $familia): array
    {
        return [
            'pulsado' => 'cerrarStockAnoActual', 'inicial' => '0', 'pagina' => '1',
            'familias' => json_encode([$familia]), 'idProveedor' => '112',
            'configuracion' => json_encode(['modo' => $modo, 'familiaSeleccionada' => $familia]),
        ];
    }

    /**
     * @param list<string> $preparacion
     * @param array<string, string> $consultas
     * @return array{respuesta:mixed, errores:string, ids:array<string, string>, consultas:array<string, ?string>}
     */
    private function cerrar(string $modo, string $familia, array $preparacion, array $consultas = []): array
    {
        return $this->ejecutar($this->formularioCierre($modo, $familia), $preparacion, true, $consultas);
    }

    /**
     * Ejecuta la petición de fondo en un PHP aparte, desde la carpeta del caso, y devuelve su respuesta,
     * sus avisos, los números de lo sembrado y lo que responden las consultas del caso al terminar.
     *
     * @param array<string, mixed> $formulario
     * @param list<string> $preparacion
     * @param array<string, string> $consultas
     * @return array{respuesta:mixed, errores:string, ids:array<string, string>, consultas:array<string, ?string>}
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true, array $consultas = [], int $tienda = 1): array
    {
        $modulo = RUTA_TPVFOX . '/modulos/mod_reorganizacion';
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $tienda, 'razonsocial' => 'Tienda de pruebas', 'ano' => '2026'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_reorganizacion/tareas.php";'
            . ' chdir(' . var_export($modulo, true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_reorganizacion\'");'
            . ' $BDTpv->query("INSERT IGNORE INTO tiendas (idTienda, tipoTienda, razonsocial, nif, telefono, estado, NombreComercial, direccion, ano) VALUES (1, \'principal\', \'Tienda uno\', \'X0000000X\', \'000000000\', \'Activo\', \'Tienda uno\', \'Sin direccion\', \'2026\')");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $tienda . '"]; $cuenta = ["f" => 0, "p" => 0, "v" => 0];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' foreach (["INSERT INTO familias " => "f", "INSERT INTO articulos " => "p", "INSERT INTO proveedores " => "v"] as $inicio => $letra) {'
            . ' if (str_starts_with($sql, $inicio)) { $sembrados[":" . $letra . ++$cuenta[$letra]] = $sembrados[":" . $letra . "N"] = (string) $BDTpv->insert_id; } } }'
            . ' foreach (["clases/ClaseReorganizar.php", "funciones.php", "clases/ClaseComprobacionStockContexto.php", "clases/ClaseComprobacionStockExtraccion.php", "clases/ClaseComprobacionStockEmision.php", "clases/ClaseComprobacionStockAdmision.php", "clases/ClaseComprobacionStockMinimo.php", "clases/ClaseComprobacionStockClasificacion.php", "../mod_producto/clases/ClaseArticulos.php", "../mod_producto/clases/ClaseArticulosStocks.php", "../mod_compras/clases/albaranesCompras.php"] as $fichero) { include_once ' . var_export($modulo . '/', true) . ' . $fichero; }'
            . ' $configuracion = file_get_contents(' . var_export($this->carpeta . '/parametros.xml', true) . ');'
            . ' foreach ($sembrados as $nombre => $valor) { $configuracion = str_replace("%" . substr($nombre, 1) . "%", $valor, $configuracion); }'
            . ' file_put_contents(' . var_export($this->carpeta . '/parametros.xml', true) . ', $configuracion);'
            . ' chdir(' . var_export($this->carpeta, true) . ');'
            . ' $_POST = ' . var_export($formulario, true) . ';'
            . ' array_walk_recursive($_POST, function (&$valor) use ($sembrados) { $valor = strtr((string) $valor, $sembrados); });'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use ($BDTpv, &$sembrados) { $resultados = [];'
            . ' foreach (' . var_export($consultas, true) . ' as $nombre => $sql) { $fila = $BDTpv->query(strtr($sql, $sembrados))->fetch_row(); $resultados[$nombre] = $fila === null ? null : (string) $fila[0]; }'
            . ' echo "\n@@ESTADO@@" . json_encode(["ids" => $sembrados, "consultas" => $resultados]); });'
            . ' include ' . var_export($modulo . '/tareas.php', true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'cierre');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@ESTADO@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['ids' => [], 'consultas' => []];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}

<?php

/**
 * La fusión de artículos importados con el catálogo de TPVFox
 * (`modulos/mod_importar/clases/ClaseImportarDbf.php`): dado un artículo leído del fichero —su código,
 * nombre, precios, IVA, coste, stock y código de barras—, la clase busca si ya existe por su código en
 * la tienda 1 y lo crea en las cinco tablas del catálogo o actualiza su nombre, sus precios y su stock.
 *
 * El caso le presta su conexión dentro de una transacción que se deshace, y le pasa cada artículo como
 * array, sin la tabla de paso: así la fusión se ejerce sin crear ni borrar tablas (DDL), que no sería
 * reversible. La lectura del fichero DBF y la creación de la tabla de paso dependen de Python y de la
 * librería `dbfread`, que la cualificación del entorno comprueba aparte.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModImportar;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ImportarDbfIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private Siembra $siembra;
    private string $rutaRegistro = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/app/helpers.php');
        $this->incluirTPVFox('/clases/ClaseTFModelo.php');
        $this->siembra = new Siembra($this->db);
        // La fusión escribe siempre en la tienda 1; la base de pruebas no la trae con ese id, y la principal
        // de la instalación es otra: el caso siembra la 1 como tienda aparte.
        $this->siembra->tiendaConId(1, '2026', 'otra');
        $this->siembra->usuarioPorDefecto();
        $this->rutaRegistro = (string) sys_get_temp_dir() . '/importar-' . bin2hex(random_bytes(3));
        @mkdir($this->rutaRegistro);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->rutaRegistro . '/*') ?: [] as $fichero) {
            unlink($fichero);
        }
        @rmdir($this->rutaRegistro);
        parent::tearDown();
    }

    /** Un `ImportarDbf` listo: necesita un registro de importación para construirse (monta su log de él). */
    private function clase(): \ImportarDbf
    {
        if (!class_exists('ImportarDbf')) {
            $this->incluirTPVFox('/modulos/mod_importar/clases/ClaseImportarDbf.php');
        }
        $this->db->query(
            "INSERT INTO modulo_importar_registro (name, datos_fichero, token, type, fecha_inicio, estado, Registros_originales, campos) "
            . "VALUES ('articulos.dbf', '{}', 't', 'application/x-dbf', '2026-10-23 08:00:00', 'Creado', 0, '[]')"
        );

        return new \ImportarDbf($this->rutaRegistro);
    }

    /** Un artículo leído del fichero, con los campos que la fusión usa. */
    private function articuloDbf(array $cambios = []): array
    {
        return $cambios + [
            'CODIGO'    => '1001',
            'NOMBRE'    => 'Aceite de oliva',
            'STOCK'     => '12',
            'CODE_BAR'  => '8400000000017',
            'PCOSTE'    => '3.50',
            'PVENTA'    => '4.00',
            'PVP'       => '4.40',
            'BENEFICIO' => '10',
            'IVA'       => '10',
            'NULO'      => 0,
        ];
    }

    /** Ejecuta la llamada y devuelve su resultado, o la excepción si la lanza (la suite pone mysqli en modo excepción). */
    private function intentar(callable $llamada): mixed
    {
        try {
            return $llamada();
        } catch (\Throwable $excepcion) {
            return $excepcion;
        }
    }

    /** El artículo del catálogo con ese código en la tienda 1, con su precio y su stock, o null. */
    private function catalogo(string $codigo): ?array
    {
        $fila = $this->db->query(
            "SELECT a.articulo_name, a.estado, a.ultimoCoste, a.beneficio, a.iva, p.pvpCiva, p.pvpSiva, s.stockOn, b.codBarras "
            . "FROM articulosTiendas t JOIN articulos a ON a.idArticulo = t.idArticulo "
            . "LEFT JOIN articulosPrecios p ON p.idArticulo = t.idArticulo AND p.idTienda = 1 "
            . "LEFT JOIN articulosStocks s ON s.idArticulo = t.idArticulo AND s.idTienda = 1 "
            . "LEFT JOIN articulosCodigoBarras b ON b.idArticulo = t.idArticulo "
            . "WHERE t.crefTienda = '{$codigo}' AND t.idTienda = 1"
        )->fetch_assoc();

        return $fila ?: null;
    }

    /**
     * @comportamiento Un artículo con un código que no está en el catálogo se crea en las cinco tablas
     *   —ficha, tienda, precio, stock y código de barras— con el nombre, los precios, el IVA, el coste y el
     *   stock leídos, y la fusión lo marca como nuevo.
     */
    public function test_unArticuloQueNoExisteSeCreaEnElCatalogo(): void
    {
        $importar = $this->clase();

        $estado = $importar->ControllerNewUpdate($this->articuloDbf());

        self::assertSame('nuevo', $estado);
        $articulo = $this->catalogo('1001');
        self::assertSame('Aceite de oliva', $articulo['articulo_name']);
        self::assertSame('importado', $articulo['estado']);
        self::assertSame('4.400000', $articulo['pvpCiva']);
        self::assertSame('12.000000', $articulo['stockOn']);
        self::assertSame('8400000000017', $articulo['codBarras']);
    }

    /**
     * @comportamiento Un artículo cuyo código ya está en el catálogo con un precio distinto se actualiza:
     *   cambian el nombre, los precios y el stock, y la fusión lo marca como actualizado.
     */
    public function test_unArticuloQueYaExisteConOtroPrecioSeActualiza(): void
    {
        $importar = $this->clase();
        $idArticulo = $this->siembra->articulo('Aceite viejo', ['iva' => 10, 'ultimoCoste' => 3.0, 'beneficio' => 10]);
        $this->siembra->precioYTienda($idArticulo, 4.00, 3.50, 1, ['crefTienda' => '1001']);
        $this->siembra->existenciaRegistrada($idArticulo, 5.0, 1);

        $estado = $importar->ControllerNewUpdate($this->articuloDbf());

        self::assertSame('actualizado', $estado);
        $articulo = $this->catalogo('1001');
        self::assertSame('Aceite de oliva', $articulo['articulo_name']);
        self::assertSame('actualizado', $articulo['estado']);
        self::assertSame('4.400000', $articulo['pvpCiva']);
        self::assertSame('12.000000', $articulo['stockOn']);
    }

    /**
     * @comportamiento Un artículo cuyo código ya está en el catálogo con los mismos nombre y precios, y el
     *   stock en el formato de tres decimales con que la fusión lo compara, se deja igual.
     */
    public function test_unArticuloIgualNoSeToca(): void
    {
        $importar = $this->clase();
        $idArticulo = $this->siembra->articulo('Aceite de oliva', ['iva' => 10, 'ultimoCoste' => 3.5, 'beneficio' => 10]);
        $this->siembra->precioYTienda($idArticulo, 4.40, 4.00, 1, ['crefTienda' => '1001']);
        $this->siembra->existenciaRegistrada($idArticulo, 12.0, 1);

        $estado = $importar->ControllerNewUpdate($this->articuloDbf(['STOCK' => '12.000']));

        self::assertSame('igual', $estado);
        self::assertSame('Activo', $this->catalogo('1001')['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/clases/ClaseImportarDbf.php:362-368
     *
     * @group defecto
     * @group importar
     * @group medio
     *
     * @que-ocurre-hoy Un artículo que no ha cambiado se marca como actualizado y se reescribe cuando su
     *   stock no viene con tres decimales: la comparación formatea el stock del catálogo a tres decimales
     *   pero toma el del fichero tal cual, de modo que «12» y «12.000» no coinciden.
     * @que-deberia-ocurrir Que un artículo sin cambios reales se deje igual, venga su stock como venga.
     * @por-que-ocurre La comparación da por distinto el stock porque enfrenta «12.000» del catálogo con «12»
     *   del fichero, sin llevar los dos al mismo formato.
     * @como-deberia-funcionar Comparar el stock como número, o formatear los dos lados igual.
     */
    public function test_unArticuloSinCambiosConElStockEnOtroFormatoSeMarcaActualizado(): void
    {
        $importar = $this->clase();
        $idArticulo = $this->siembra->articulo('Aceite de oliva', ['iva' => 10, 'ultimoCoste' => 3.5, 'beneficio' => 10]);
        $this->siembra->precioYTienda($idArticulo, 4.40, 4.00, 1, ['crefTienda' => '1001']);
        $this->siembra->existenciaRegistrada($idArticulo, 12.0, 1);

        $estado = $importar->ControllerNewUpdate($this->articuloDbf(['STOCK' => '12']));

        self::assertSame('igual', $estado);
    }

    /**
     * @comportamiento Un artículo nuevo sin nombre no se crea, y la fusión lo marca como error.
     */
    public function test_unArticuloNuevoSinNombreNoSeCrea(): void
    {
        $importar = $this->clase();

        $estado = $importar->ControllerNewUpdate($this->articuloDbf(['NOMBRE' => '', 'CODIGO' => '2002']));

        self::assertSame('error', $estado);
        self::assertNull($this->catalogo('2002'));
    }

    /**
     * @comportamiento Un artículo marcado como nulo no se crea cuando la configuración filtra la creación de
     *   los nulos, y la fusión lo marca como filtrado.
     */
    public function test_unArticuloNuloNoSeCrea(): void
    {
        $importar = $this->clase();

        $estado = $importar->ControllerNewUpdate($this->articuloDbf(['NULO' => '1', 'CODIGO' => '3003']));

        self::assertSame('filtrado', $estado);
        self::assertNull($this->catalogo('3003'));
    }

    /**
     * @comportamiento Un artículo marcado como nulo que ya está en el catálogo se actualiza igual que los
     *   demás: la configuración solo filtra los nulos al crear.
     */
    public function test_unArticuloNuloQueYaExisteSeActualiza(): void
    {
        $importar = $this->clase();
        $idArticulo = $this->siembra->articulo('Aceite viejo', ['iva' => 10, 'ultimoCoste' => 3.0, 'beneficio' => 10]);
        $this->siembra->precioYTienda($idArticulo, 4.00, 3.50, 1, ['crefTienda' => '1001']);
        $this->siembra->existenciaRegistrada($idArticulo, 5.0, 1);

        $estado = $importar->ControllerNewUpdate($this->articuloDbf(['NULO' => '1']));

        self::assertSame('actualizado', $estado);
        self::assertSame('Aceite de oliva', $this->catalogo('1001')['articulo_name']);
    }

    /** @comportamiento Un artículo nuevo sin precio, con o sin IVA, no se crea, y la fusión lo marca como error. */
    public function test_unArticuloNuevoSinPrecioNoSeCrea(): void
    {
        $importar = $this->clase();

        $estado = $importar->ControllerNewUpdate($this->articuloDbf(['CODIGO' => '8008', 'PVENTA' => '0', 'PVP' => '0']));

        self::assertSame('error', $estado);
        self::assertNull($this->catalogo('8008'));
    }

    /**
     * @comportamiento Un artículo que ya está en el catálogo de la tienda 1 pero sin precio en ella no se
     *   compara ni se actualiza, y la fusión lo marca como error.
     */
    public function test_unArticuloExistenteSinPrecioEnLaTiendaSeMarcaComoError(): void
    {
        $importar = $this->clase();
        $idArticulo = $this->siembra->articulo('Sin precio', ['iva' => 10]);
        $this->db->query("INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES ({$idArticulo}, 1, '9009', 'Activo')");

        $estado = $importar->ControllerNewUpdate($this->articuloDbf(['CODIGO' => '9009']));

        self::assertSame('error', $estado);
        self::assertSame('Sin precio', $this->catalogo('9009')['articulo_name']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/clases/ClaseImportarDbf.php:410-427
     *
     * @group defecto
     * @group importar
     * @group medio
     *
     * @que-ocurre-hoy Un artículo nuevo se crea con el IVA que trae el fichero aunque ese tipo no esté en la
     *   tabla de IVA de la instalación: la venta copiará a sus líneas un IVA que la tienda no tiene.
     * @que-deberia-ocurrir Que un artículo nuevo con un IVA que no está en la tabla de IVA no se cree, y se
     *   marque como error.
     * @por-que-ocurre Antes de crear, la fusión comprueba el nombre y los precios; el IVA no, como anuncia el
     *   comentario que lo deja pendiente.
     * @como-deberia-funcionar Comprobar que el IVA del artículo está en la tabla de IVA antes de crearlo.
     */
    public function test_unArticuloNuevoConUnIvaQueNoExisteNoSeCrea(): void
    {
        $importar = $this->clase();

        $estado = $importar->ControllerNewUpdate($this->articuloDbf(['CODIGO' => '7007', 'IVA' => '7']));

        self::assertSame('error', $estado);
        self::assertNull($this->catalogo('7007'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/clases/ClaseImportarDbf.php:440-447
     *
     * @group defecto
     * @group importar
     * @group alto
     *
     * @que-ocurre-hoy La búsqueda del artículo por su código pega el código en el texto de la consulta: un
     *   código con una comilla simple rompe la consulta, que devuelve null, y la fusión da el artículo por
     *   nuevo y lo crea con el código roto.
     * @que-deberia-ocurrir Que un código con una comilla no altere la consulta ni cree un artículo espurio.
     * @por-que-ocurre `consultaExiste()` compone `... crefTienda="<codigo>" ...` concatenando el valor.
     * @como-deberia-funcionar Pasar el código como dato de una consulta preparada, o escaparlo.
     */
    public function test_unCodigoConComillaNoRompeLaBusqueda(): void
    {
        $importar = $this->clase();

        $resultado = $this->intentar(fn () => $importar->ControllerNewUpdate($this->articuloDbf(['CODIGO' => '10"05', 'NOMBRE' => 'Con comilla'])));

        self::assertNotInstanceOf(\Throwable::class, $resultado);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/clases/ClaseImportarDbf.php:449-544
     *
     * @group defecto
     * @group importar
     * @group alto
     *
     * @que-ocurre-hoy El alta de un artículo nuevo escribe en las cinco tablas una tras otra, sin
     *   transacción: si una falla —un código de barras más largo de lo que admite la columna—, la ficha del
     *   artículo queda creada sin su precio ni su stock.
     * @que-deberia-ocurrir Que un alta que no puede completarse no deje el artículo a medias en el catálogo.
     * @por-que-ocurre `insertarNuevo()` encadena cinco inserciones sin envolverlas en una transacción, y la
     *   comprobación del fallo de la primera compara con la cadena 'false' en vez del booleano.
     * @como-deberia-funcionar Insertar las cinco tablas en una transacción y deshacerla si una falla.
     */
    public function test_unAltaQueFallaAMedioCaminoNoDejaElArticuloAMedias(): void
    {
        $importar = $this->clase();
        $codigoBarrasLargo = str_repeat('9', 40);

        $this->intentar(fn () => $importar->ControllerNewUpdate($this->articuloDbf(['CODIGO' => '4004', 'CODE_BAR' => $codigoBarrasLargo])));

        $aMedias = $this->db->query(
            "SELECT COUNT(*) FROM articulos a LEFT JOIN articulosPrecios p ON p.idArticulo = a.idArticulo AND p.idTienda = 1 "
            . "WHERE a.articulo_name = 'Aceite de oliva' AND p.id IS NULL"
        )->fetch_row()[0];
        self::assertSame('0', (string) $aMedias);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/clases/ClaseImportarDbf.php:440-447
     * @codigo-afectado modulos/mod_importar/clases/ClaseImportarDbf.php:495-528
     *
     * @group defecto
     * @group importar
     * @group alto
     *
     * @que-ocurre-hoy Un artículo nuevo se crea con su precio, su ficha de tienda y su stock en la tienda 1,
     *   aunque la principal de la instalación sea otra; donde no existe una tienda 1, el alta falla a medias.
     * @que-deberia-ocurrir Que el artículo se cree en la tienda principal de la instalación.
     * @por-que-ocurre La búsqueda y las inserciones de precio, tienda y stock llevan `idTienda=1` escrito en
     *   el código.
     * @como-deberia-funcionar Tomar la tienda principal de la sesión o de la configuración.
     */
    public function test_unArticuloNuevoSeCreaEnLaTiendaPrincipal(): void
    {
        $importar = $this->clase();
        $principal = (int) $this->db->query("SELECT idTienda FROM tiendas WHERE tipoTienda = 'principal' AND estado = 'Activo' LIMIT 1")->fetch_row()[0];

        $importar->ControllerNewUpdate($this->articuloDbf(['CODIGO' => '6006']));

        $enLaPrincipal = $this->db->query("SELECT COUNT(*) FROM articulosTiendas WHERE crefTienda = '6006' AND idTienda = {$principal}")->fetch_row()[0];
        self::assertSame('1', (string) $enLaPrincipal);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/clases/ClaseImportarDbf.php:341-397
     *
     * @group defecto
     * @group importar
     * @group medio
     *
     * @que-ocurre-hoy Un código que está en dos artículos del catálogo no se marca como error: la fusión
     *   devuelve un estado vacío, que no cuenta como ninguno de los estados de la importación.
     * @que-deberia-ocurrir Que un código que no identifica un solo artículo se marque como error.
     * @por-que-ocurre El estado de error solo se asigna dentro de la rama de un único artículo; con dos o
     *   más, la rama que lo anota en el registro no lo asigna.
     * @como-deberia-funcionar Asignar el estado de error cuando el código no da exactamente un artículo.
     */
    public function test_unCodigoRepetidoEnElCatalogoSeMarcaComoError(): void
    {
        $importar = $this->clase();
        foreach (['Primero', 'Segundo'] as $nombre) {
            $idArticulo = $this->siembra->articulo($nombre, ['iva' => 10]);
            $this->siembra->precioYTienda($idArticulo, 4.40, 4.00, 1, ['crefTienda' => '7007']);
        }

        $estado = $importar->ControllerNewUpdate($this->articuloDbf(['CODIGO' => '7007']));

        self::assertSame('error', $estado);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_importar/clases/ClaseImportarDbf.php:619-630
     *
     * @group defecto
     * @group importar
     * @group medio
     *
     * @que-ocurre-hoy Borrar el registro de una importación pega el id recibido en el texto de la sentencia:
     *   un id que no es un número rompe la sentencia, que la base rechaza con una excepción.
     * @que-deberia-ocurrir Que un id que no es un número se rechace como error, sin llegar a la base.
     * @por-que-ocurre `EliminarRegistroTabla()` compone `... WHERE id=<id>` concatenando el valor, sin
     *   comprobar que sea un número.
     * @como-deberia-funcionar Comprobar que el id es un número entero, o pasarlo como dato de la sentencia.
     */
    public function test_unIdQueNoEsUnNumeroNoLlegaALaBase(): void
    {
        $importar = $this->clase();

        $resultado = $this->intentar(fn () => $importar->EliminarRegistroTabla('abc'));

        self::assertNotInstanceOf(\Throwable::class, $resultado);
    }
}

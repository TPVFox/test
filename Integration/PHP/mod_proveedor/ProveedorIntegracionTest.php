<?php

/**
 * La ficha de un proveedor en `ClaseProveedor`: leerlo con sus últimos documentos, guardarlo —alta y
 * modificación, con sus comprobaciones— y el listado.
 *
 * Contra la base, en una transacción que se deshace, con la conexión del caso compartida con la clase
 * (`ModeloP`). Cada caso siembra sus proveedores con un NIF propio: la siembra común da a todos el mismo.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModProveedor;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ProveedorIntegracionTest extends CasoIntegracion
{
    protected bool $compartirConexionConElProducto = true;

    private \ClaseProveedor $proveedores;
    private Siembra $siembra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_proveedor/clases/ClaseProveedor.php');
        $this->proveedores = new \ClaseProveedor();
        $this->siembra = new Siembra($this->db);
    }

    private function insertar(string $tabla, array $valores): int
    {
        $sentencia = $this->db->prepare(
            "INSERT INTO `$tabla` (`" . implode('`, `', array_keys($valores)) . '`) VALUES (' . implode(', ', array_fill(0, count($valores), '?')) . ')'
        );
        $sentencia->bind_param(str_repeat('s', count($valores)), ...array_map('strval', array_values($valores)));
        $sentencia->execute();

        return (int) $this->db->insert_id;
    }

    /** Un proveedor con todos sus datos y un NIF que no tiene ningún otro. */
    private function proveedor(string $nif, array $datos = []): int
    {
        return $this->insertar('proveedores', $datos + [
            'nombrecomercial' => 'Huerta Prueba', 'razonsocial' => 'Huerta Prueba SL', 'nif' => $nif, 'direccion' => 'Rúa do Porto 1',
            'telefono' => '986000001', 'fax' => '986000002', 'movil' => '600000001', 'email' => 'huerta@prueba.test',
            'fecha_creado' => '2020-01-01 00:00:00', 'estado' => 'Activo', 'registro_sanitario' => 'RS-1',
        ]);
    }

    /** Lo que envía la ficha al guardar: todos sus campos, con «Guardar». */
    private function enviado(int|string $idProveedor, array $cambios = []): array
    {
        return $cambios + [
            'Guardar' => 'Guardar', 'idProveedor' => (string) $idProveedor, 'nombrecomercial' => 'Huerta Prueba', 'razonsocial' => 'Huerta Prueba SL',
            'nif' => 'B70000001', 'direccion' => 'Rúa do Porto 1', 'telefono' => '986000001', 'registro_sanitario' => 'RS-1', 'movil' => '600000001',
            'email' => 'huerta@prueba.test', 'fax' => '986000002', 'fecha_creado' => '2020-01-01 00:00:00', 'estado' => 'Activo',
        ];
    }

    private function fila(int $id): array
    {
        return $this->db->query("SELECT * FROM proveedores WHERE idProveedor = $id")->fetch_assoc();
    }

    private function intentar(callable $llamada): mixed
    {
        try {
            return $llamada();
        } catch (\Throwable $error) {
            return get_class($error) . ': ' . $error->getMessage();
        }
    }

    /**
     * @comportamiento Un proveedor se lee con sus datos y sus últimas facturas, albaranes y pedidos, del
     *   número más alto al más bajo; sin documentos de un tipo, una lista vacía.
     */
    public function test_unProveedorSeLeeConSusUltimosDocumentos(): void
    {
        $id = $this->proveedor('B70000001');
        $articulo = $this->siembra->articulo('Tomate', ['ultimoCoste' => 2.0, 'iva' => 10]);
        $primero = $this->siembra->entradaProveedor($articulo, 5, '2031-05-10 12:00:00', ['idProveedor' => $id]);
        $segundo = $this->siembra->entradaProveedor($articulo, 3, '2031-05-11 12:00:00', ['idProveedor' => $id]);
        $factura = $this->siembra->facturarAlbaranProveedor($primero);

        $proveedor = $this->proveedores->getProveedorCompleto($id);

        self::assertSame('Huerta Prueba SL', $proveedor['razonsocial']);
        self::assertSame([(string) $segundo, (string) $primero], array_column($proveedor['adjuntos']['albaranes']['datos'], 'id'));
        self::assertSame([(string) $factura], array_column($proveedor['adjuntos']['facturas']['datos'], 'id'));
        self::assertSame([], $proveedor['adjuntos']['pedidos']['datos']);
    }

    /** @comportamiento Sin número, la ficha recibe un proveedor vacío con las listas de documentos vacías. */
    public function test_sinNumeroSeOfreceUnProveedorVacio(): void
    {
        $proveedor = $this->proveedores->getProveedorCompleto(0);

        self::assertSame('', $proveedor['nif']);
        self::assertSame(['datos' => []], $proveedor['adjuntos']['facturas']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/clases/ClaseProveedor.php:68-86
     *
     * @group defecto
     * @group proveedor
     * @group bajo
     *
     * @que-ocurre-hoy Leer un proveedor con un número que no existe termina con un error de tipo de PHP, en
     *   lugar del aviso «Para este id no hay datos» que el código prepara.
     * @que-deberia-ocurrir Que devuelva el aviso.
     * @por-que-ocurre Sin filas, la clase base devuelve los datos a nulo, y `count()` de un nulo es un
     *   error de tipo en PHP 8.
     * @como-deberia-funcionar Comprobar que hay datos antes de contarlos.
     */
    public function test_unNumeroQueNoExisteSeAvisa(): void
    {
        $resultado = $this->intentar(fn () => $this->proveedores->getProveedorCompleto(999999999));

        self::assertIsArray($resultado);
        self::assertArrayHasKey('error', $resultado);
    }

    /**
     * @comportamiento Guardar un proveedor sin número lo crea con lo enviado y la fecha del momento, y
     *   devuelve la ficha completa del nuevo.
     */
    public function test_crearUnProveedorLoGuardaConLaFechaDelMomento(): void
    {
        $respuesta = $this->proveedores->guardarProveedor($this->enviado(''));

        self::assertSame('OK', $respuesta['estado']);
        $nuevo = $this->fila((int) $respuesta['datos']['idProveedor']);
        self::assertSame(['B70000001', 'Huerta Prueba SL', '986000002', 'Activo'], [$nuevo['nif'], $nuevo['razonsocial'], $nuevo['fax'], $nuevo['estado']]);
        self::assertSame(date('Y-m-d'), substr($nuevo['fecha_creado'], 0, 10));
        self::assertSame([], $respuesta['comprobaciones']);
    }

    /** @comportamiento Guardar un proveedor con número modifica sus datos, también el estado, y devuelve la ficha. */
    public function test_modificarUnProveedorCambiaSusDatos(): void
    {
        $id = $this->proveedor('B70000001');

        $respuesta = $this->proveedores->guardarProveedor($this->enviado($id, ['telefono' => '986999999', 'estado' => 'Especial']));

        self::assertSame('OK', $respuesta['estado']);
        self::assertSame(['986999999', 'Especial'], [$this->fila($id)['telefono'], $this->fila($id)['estado']]);
        self::assertSame('986999999', $respuesta['datos']['telefono']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/clases/ClaseProveedor.php:154-157
     *
     * @group defecto
     * @group proveedor
     * @group medio
     *
     * @que-ocurre-hoy Al modificar un proveedor, el fax que se teclea no se guarda: la ficha dice que se
     *   guardó, y el fax sigue siendo el anterior.
     * @que-deberia-ocurrir Que se guarde, como en el alta.
     * @por-que-ocurre La sentencia de modificar no lleva el campo `fax`; la de alta, sí.
     * @como-deberia-funcionar Añadir el fax a la modificación.
     */
    public function test_modificarElFaxLoGuarda(): void
    {
        $id = $this->proveedor('B70000001');

        $this->proveedores->guardarProveedor($this->enviado($id, ['fax' => '986777777', 'telefono' => '986111111']));

        self::assertSame('986777777', $this->fila($id)['fax']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/clases/ClaseProveedor.php:163-166,354-357
     *
     * @group defecto
     * @group proveedor
     * @group medio
     *
     * @que-ocurre-hoy Guardar un proveedor sin haber cambiado nada —o cambiando solo el fax— responde un
     *   error grave: «Se ha modificado los datos de 0 proveedores».
     * @que-deberia-ocurrir Que guardar lo mismo se dé por bueno.
     * @por-que-ocurre El guardado exige que la base cuente una fila cambiada, y la base no cuenta la fila
     *   si los valores no cambian; el comentario del código lo advierte.
     * @como-deberia-funcionar Comprobar que el proveedor existe, no las filas cambiadas.
     */
    public function test_guardarSinCambiosNoEsUnError(): void
    {
        $id = $this->proveedor('B70000001');

        $respuesta = $this->proveedores->guardarProveedor($this->enviado($id));

        self::assertSame('OK', $respuesta['estado']);
    }

    /**
     * @comportamiento Un NIF que ya tiene otro proveedor no se guarda: estado «KO» con el error, y nada
     *   cambia; un teléfono que ya tiene otro solo se avisa, y se guarda.
     */
    public function test_unNifRepetidoNoSeGuardaYUnTelefonoRepetidoSeAvisa(): void
    {
        $otro = $this->proveedor('B79999999', ['telefono' => '986555555']);
        $id = $this->proveedor('B70000001');

        $nifRepetido = $this->proveedores->guardarProveedor($this->enviado($id, ['nif' => 'B79999999', 'telefono' => '986222222']));
        $telefonoRepetido = $this->proveedores->guardarProveedor($this->enviado($id, ['telefono' => '986555555']));

        self::assertSame('KO', $nifRepetido['estado']);
        self::assertSame('danger', $nifRepetido['error'][0]['tipo']);
        self::assertSame('OK', $telefonoRepetido['estado']);
        self::assertSame('warning', $telefonoRepetido['comprobaciones'][0]['tipo']);
        self::assertSame(['B70000001', '986555555'], [$this->fila($id)['nif'], $this->fila($id)['telefono']]);
        self::assertSame('B79999999', $this->fila($otro)['nif']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/clases/ClaseProveedor.php:403-412
     *
     * @group defecto
     * @group proveedor
     * @group bajo
     *
     * @que-ocurre-hoy El aviso de NIF repetido no es un texto: es la lista de filas de la base con el número
     *   del otro proveedor, que la ficha pinta con `print_r()`.
     * @que-deberia-ocurrir Que diga, como texto, que el NIF ya lo tiene otro proveedor, y cuál.
     * @por-que-ocurre El aviso se monta con el resultado de la consulta en lugar de un mensaje.
     * @como-deberia-funcionar Componer el mensaje con el número del proveedor que lo tiene.
     */
    public function test_elAvisoDelNifRepetidoEsUnTexto(): void
    {
        $this->proveedor('B79999999');
        $id = $this->proveedor('B70000001');

        $respuesta = $this->proveedores->guardarProveedor($this->enviado($id, ['nif' => 'B79999999']));

        self::assertIsString($respuesta['error'][0]['mensaje']);
    }

    /** @comportamiento Sin nombre comercial ni razón social, el proveedor no se guarda. */
    public function test_sinNombreNiRazonSocialNoSeGuarda(): void
    {
        $respuesta = $this->proveedores->guardarProveedor($this->enviado('', ['nombrecomercial' => '', 'razonsocial' => '']));

        self::assertSame('KO', $respuesta['estado']);
        self::assertSame('Debe poner nombre comercial o razon social', $respuesta['error'][0]['mensaje']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/clases/ClaseProveedor.php:154-157,175-179,483-486
     *
     * @group defecto
     * @group proveedor
     * @group alto
     *
     * @que-ocurre-hoy Un nombre con comillas dobles —«Bodegas "La Ría"»— rompe la sentencia de la
     *   comprobación y la del guardado: el proveedor no se guarda y la petición termina con un error de la
     *   base.
     * @que-deberia-ocurrir Que se guarde tal como se tecleó.
     * @por-que-ocurre Los valores se pegan entre comillas dobles en el texto de las sentencias, sin
     *   escaparlos.
     * @como-deberia-funcionar Pasar los valores como parámetros de una sentencia preparada.
     */
    public function test_unNombreConComillasSeGuarda(): void
    {
        $respuesta = $this->intentar(fn () => $this->proveedores->guardarProveedor($this->enviado('', ['nombrecomercial' => 'Bodegas "La Ría"'])));

        self::assertIsArray($respuesta);
        self::assertSame('OK', $respuesta['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/clases/ClaseProveedor.php:381-423
     *
     * @group defecto
     * @group proveedor
     * @group medio
     *
     * @que-ocurre-hoy Un teléfono con su prefijo internacional —«+34 986 123 456», 15 caracteres— no cabe en
     *   su campo, de 11: la base lo rechaza y la petición termina con un error, sin decir qué campo sobra.
     * @que-deberia-ocurrir Que la comprobación avise del campo demasiado largo antes de guardar.
     * @por-que-ocurre Las comprobaciones miran el NIF, el teléfono repetido y el nombre, no la longitud de
     *   cada campo, y la base, en modo estricto, rechaza el valor.
     * @como-deberia-funcionar Comprobar la longitud de cada campo contra su columna.
     */
    public function test_unTelefonoMasLargoQueSuCampoSeAvisa(): void
    {
        $respuesta = $this->intentar(fn () => $this->proveedores->guardarProveedor($this->enviado('', ['telefono' => '+34 986 123 456'])));

        self::assertIsArray($respuesta);
        self::assertSame('KO', $respuesta['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_proveedor/clases/ClaseProveedor.php:381-423
     * @codigo-afectado modulos/mod_proveedor/proveedor.php:19
     *
     * @group defecto
     * @group proveedor
     * @group medio
     *
     * @que-ocurre-hoy El guardado acepta cualquier estado que llegue, aunque la ficha solo ofrece «Activo»,
     *   «inactivo» y «Especial»: un proveedor puede quedar en un estado que nadie conoce.
     * @que-deberia-ocurrir Que solo se guarde uno de los estados que el sistema conoce.
     * @por-que-ocurre Las comprobaciones no miran el estado.
     * @como-deberia-funcionar Comprobar el estado contra la lista de estados.
     */
    public function test_unEstadoDesconocidoNoSeGuarda(): void
    {
        $id = $this->proveedor('B70000001');

        $respuesta = $this->proveedores->guardarProveedor($this->enviado($id, ['estado' => 'Cualquiera', 'telefono' => '986111111']));

        self::assertSame('KO', $respuesta['estado']);
        self::assertSame('Activo', $this->fila($id)['estado']);
    }

    /**
     * @comportamiento El listado devuelve los proveedores del filtro que recibe, con su límite, y el recuento
     *   cuenta los mismos; sin ninguno, una lista vacía. La comprobación de un valor repetido descarta el
     *   propio proveedor.
     */
    public function test_elListadoElRecuentoYLaComprobacionDeRepetidos(): void
    {
        $uno = $this->proveedor('B70000001');
        $dos = $this->proveedor('B70000002');
        $filtro = "WHERE idProveedor IN ($uno, $dos)";

        self::assertSame([(string) $uno, (string) $dos], array_column($this->proveedores->obtenerProveedores($filtro), 'idProveedor'));
        self::assertSame(2, $this->proveedores->contarRegistros($filtro));
        self::assertCount(1, $this->proveedores->obtenerProveedores($filtro . ' LIMIT 1'));
        self::assertSame([], $this->proveedores->obtenerProveedores('WHERE idProveedor = 0'));
        $repetidos = $this->proveedores->comprobarExisteValorCampo(['tabla' => 'proveedores', 'campo_obtener' => 'idProveedor', 'limite' => 15], ['nif' => 'B70000001'], ['idProveedor' => $uno]);
        self::assertSame([], $repetidos['nif']['datos']);
    }
}

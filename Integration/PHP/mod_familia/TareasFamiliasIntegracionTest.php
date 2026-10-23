<?php

/**
 * La petición de fondo de las familias (`modulos/mod_familia/tareas.php`): leer las hijas de una
 * familia y la lista de todas, grabar una familia, borrar familias, quitar productos de una familia y
 * relacionar o desrelacionar una familia con la tienda web.
 *
 * Cada petición se ejecuta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas o sin ninguna y los permisos del módulo concedidos, salvo el que el caso quite. Tras el
 * arranque común se abre una transacción en su conexión, y las clases escriben por esa misma conexión:
 * lo que siembra el caso y lo que guarda la petición se deshace al terminar el proceso. Antes de
 * terminar, el proceso escribe las familias, sus productos y sus relaciones con la tienda web.
 *
 * La preparación es una lista de sentencias; cada alta de una familia queda con un nombre —`:f1`,
 * `:f2`…— que las sentencias siguientes y el formulario pueden usar.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModFamilia;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class TareasFamiliasIntegracionTest extends CasoIntegracion
{
    private int $idUsuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->idUsuario = (new Siembra($this->db))->usuarioPorDefecto();
    }

    /** @comportamiento Leer las familias de un padre devuelve sus hijas, con sus hijas y sus productos contados, y su listado. */
    public function test_leerUnPadreDevuelveSusHijasConSusRecuentos(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'leerFamilias', 'idpadre' => ':f1'], [
            $this->familia('Frutas'),
            $this->familia('Frutas secas', ':f1'),
            $this->familia('Nueces', ':f2'),
            $this->producto(':f2'),
        ]);

        $respuesta = $peticion['respuesta'];
        self::assertSame($peticion['ids'][':f1'], (string) $respuesta['padre']);
        self::assertSame([$peticion['ids'][':f2']], array_column($respuesta['datos'], 'idFamilia'));
        self::assertSame('1', $respuesta['datos'][0]['hijos']);
        self::assertSame('1', $respuesta['datos'][0]['productos']);
        self::assertStringContainsString('Frutas secas', $respuesta['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/tareas.php:36-54; modulos/mod_familia/familia.php:430-432
     *
     * @group defecto
     * @group guardado
     * @group bajo
     *
     * @que-ocurre-hoy La ficha anuncia un beneficio medio de 0,01 como mínimo, y una petición con un
     *   beneficio negativo graba la familia.
     * @que-deberia-ocurrir Que el guardado rechace un beneficio por debajo del mínimo que anuncia la
     *   ficha.
     * @por-que-ocurre Nadie comprueba el beneficio: ni el navegador ni el guardado.
     * @como-deberia-funcionar Comprobar el beneficio al guardar.
     */
    public function test_grabarConUnBeneficioMenorQueElMinimoNoGraba(): void
    {
        $peticion = $this->ejecutar($this->grabar(['nombrefamilia' => 'Con pérdida', 'beneficiomedio' => '-5']));

        self::assertNull($this->familiaDe($peticion, 'Con pérdida'), 'Se grabó un beneficio negativo');
    }

    /** @comportamiento Las hijas de un padre negativo son ninguna: se responden vacías, sin consultar. */
    public function test_lasHijasDeUnPadreNegativoSonNinguna(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'leerFamilias', 'idpadre' => '-1'], [$this->familia('Frutas')]);

        self::assertSame([], $peticion['respuesta']['datos']);
        self::assertSame('', $peticion['respuesta']['html']);
        self::assertSame('', $peticion['errores']);
    }

    /** @comportamiento La lista de todas las familias empieza por la raíz. */
    public function test_laListaDeTodasLasFamiliasEmpiezaPorLaRaiz(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'leerTodasFamilias'], [$this->familia('Frutas'), $this->familia('Verduras')]);

        self::assertSame(0, $peticion['respuesta'][0]['idFamilia']);
        self::assertContains('Verduras', array_column($peticion['respuesta'], 'familiaNombre'));
    }

    /** @comportamiento Grabar con el número 0 da de alta la familia y responde sin error. */
    public function test_grabarConNumeroCeroDaDeAltaLaFamilia(): void
    {
        $peticion = $this->ejecutar($this->grabar(['nombrefamilia' => 'Lácteos', 'idpadre' => '0']));

        self::assertSame('0', $peticion['respuesta']['error']);
        self::assertNotNull($this->familiaDe($peticion, 'Lácteos'));
    }

    /** @comportamiento Grabar con el número de una familia la modifica: su nombre, su padre, su beneficio y su marca del TPV. */
    public function test_grabarConSuNumeroModificaLaFamilia(): void
    {
        $peticion = $this->ejecutar(
            $this->grabar(['id' => ':f2', 'nombrefamilia' => 'Frutos secos', 'idpadre' => ':f1', 'beneficiomedio' => '40', 'mostrar_tpv' => '0']),
            [$this->familia('Frutas'), $this->familia('Secas')]
        );

        $modificada = $this->familiaDe($peticion, 'Frutos secos');
        self::assertSame($peticion['ids'][':f2'], $modificada['idFamilia']);
        self::assertSame($peticion['ids'][':f1'], $modificada['familiaPadre']);
        self::assertSame(['40.00', '0'], [$modificada['beneficiomedio'], $modificada['mostrar_tpv']]);
    }

    /** @comportamiento Grabar con un padre negativo —la ficha sin padre elegido— no graba nada y responde vacío. */
    public function test_grabarConPadreNegativoNoGraba(): void
    {
        $peticion = $this->ejecutar($this->grabar(['nombrefamilia' => 'Sin padre', 'idpadre' => '-1']));

        self::assertSame([], $peticion['respuesta']);
        self::assertNull($this->familiaDe($peticion, 'Sin padre'));
    }

    /** @comportamiento Borrar familias quita las que no tienen productos ni hijas, y devuelve las demás con sus recuentos. */
    public function test_borrarFamiliasQuitaSoloLasVacias(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'borrarFamilias', 'idsfamilias' => [':f1', ':f2']], [
            $this->familia('Vacía'),
            $this->familia('Con hija'),
            $this->familia('Hija', ':f2'),
        ]);

        self::assertNull($this->familiaDe($peticion, 'Vacía'));
        self::assertNotNull($this->familiaDe($peticion, 'Con hija'));
        self::assertTrue($peticion['respuesta']['error']);
        self::assertSame([[$peticion['ids'][':f2'], '0', '1']], $peticion['respuesta']['listaError']);
    }

    /** @comportamiento Borrar no quita una familia sin hijas que tiene productos, y la devuelve con sus recuentos. */
    public function test_borrarFamiliasNoQuitaLasQueTienenProductos(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'borrarFamilias', 'idsfamilias' => [':f1']], [
            $this->familia('Con producto'),
            $this->producto(':f1'),
        ]);

        self::assertNotNull($this->familiaDe($peticion, 'Con producto'));
        self::assertTrue($peticion['respuesta']['error']);
        self::assertSame([[$peticion['ids'][':f1'], '1', '0']], $peticion['respuesta']['listaError']);
    }

    /**
     * @comportamiento Borrar con un número que trae una condición añadida no quita ninguna familia: los
     *   recuentos de productos y de hijas, con la condición, cuentan todas las familias.
     */
    public function test_borrarConUnNumeroConCondicionNoQuitaNinguna(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'borrarFamilias', 'idsfamilias' => [':f1 OR 1=1']], [
            $this->familia('Una'),
            $this->familia('Otra'),
        ]);

        self::assertNotNull($this->familiaDe($peticion, 'Una'));
        self::assertNotNull($this->familiaDe($peticion, 'Otra'));
        self::assertTrue($peticion['respuesta']['error']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/tareas.php:71-90; modulos/mod_familia/clases/ClaseFamilias.php:314-320
     *
     * @group defecto
     * @group borrado
     * @group medio
     *
     * @que-ocurre-hoy Borrar una familia sin productos ni hijas que está relacionada con la tienda web
     *   termina la petición con un error de la base: la relación impide el borrado.
     * @que-deberia-ocurrir Que el borrado responda que esa familia no se borró, y por qué, sin terminar la
     *   petición.
     * @por-que-ocurre El borrado no mira la relación con la web, que la base exige quitar antes, y nadie
     *   recoge el rechazo.
     * @como-deberia-funcionar Comprobar la relación antes de borrar —o quitarla— y recoger el fallo de la
     *   base.
     */
    public function test_borrarUnaFamiliaRelacionadaConLaWebNoTerminaLaPeticion(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'borrarFamilias', 'idsfamilias' => [':f1']], [
            $this->familia('Relacionada'),
            'INSERT INTO familiasTienda (idFamilia, idTienda, idFamilia_tienda) VALUES (:f1, :tienda, 77)',
        ]);

        self::assertNotNull($this->familiaDe($peticion, 'Relacionada'));
        self::assertStringNotContainsString('Fatal error', $peticion['errores']);
        self::assertTrue($peticion['respuesta']['error'] ?? null, 'La petición termina sin responder');
    }

    /** @comportamiento Quitar productos de una familia los saca de ella y devuelve su tabla de productos. */
    public function test_quitarProductosDeUnaFamiliaLosSaca(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'borrarFamiliaProducto', 'idfamilia' => ':f1', 'idsproductos' => [':articulo']], [
            $this->familia('Conservas'),
            $this->producto(':f1'),
        ]);

        self::assertSame(0, $peticion['productos']);
        self::assertFalse($peticion['respuesta']['error']);
        self::assertIsString($peticion['respuesta']['html']);
    }

    /** @comportamiento Eliminar la relación de una familia con una tienda web la borra. */
    public function test_eliminarLaRelacionConLaTiendaWebLaBorra(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'eliminarReferenciaFamiliaTienda', 'idFamilia' => ':f1', 'idTienda' => ':tienda'], [
            $this->familia('Frutas'),
            'INSERT INTO familiasTienda (idFamilia, idTienda, idFamilia_tienda) VALUES (:f1, :tienda, 77)',
        ]);

        self::assertSame([], $peticion['relaciones']);
        self::assertSame('0', $peticion['respuesta']['error']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/clases/ClaseFamilias.php:307-313; modulos/mod_familia/tareas.php:92-94
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Quitar la relación con la web de una familia con un número que trae una condición
     *   añadida —`5 OR 1=1`— quita las relaciones de todas las familias con esa tienda.
     * @que-deberia-ocurrir Que quite como mucho la relación de esa familia.
     * @por-que-ocurre El número se pega a la condición del borrado sin convertirlo, y la condición
     *   añadida se impone a la de la tienda.
     * @como-deberia-funcionar Tomar el número como entero, en una sentencia preparada.
     */
    public function test_quitarLaRelacionConUnNumeroConCondicionSoloQuitaLaSuya(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'eliminarReferenciaFamiliaTienda', 'idFamilia' => ':f1 OR 1=1', 'idTienda' => ':tienda'], [
            $this->familia('Una'),
            $this->familia('Otra'),
            'INSERT INTO familiasTienda (idFamilia, idTienda, idFamilia_tienda) VALUES (:f1, :tienda, 77)',
            'INSERT INTO familiasTienda (idFamilia, idTienda, idFamilia_tienda) VALUES (:f2, :tienda, 78)',
        ]);

        self::assertContains($peticion['ids'][':f2'], array_column($peticion['relaciones'], 'idFamilia'), 'Se quitó la relación de otra familia');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/tareas.php:92-94
     *
     * @group defecto
     * @group permisos
     * @group medio
     *
     * @que-ocurre-hoy Un usuario sin el permiso de la tienda web quita la relación de una familia con
     *   ella por la petición de fondo.
     * @que-deberia-ocurrir Que quitar una relación con la web pida el permiso de la web, como lo pide
     *   relacionarla.
     * @por-que-ocurre La orden de quitar la relación no consulta ningún permiso.
     * @como-deberia-funcionar Consultar el permiso de la web antes de quitar la relación.
     */
    public function test_sinPermisoDeVerLaWebNoSeQuitaLaRelacion(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'eliminarReferenciaFamiliaTienda', 'idFamilia' => ':f1', 'idTienda' => ':tienda'], [
            $this->familia('Frutas'),
            'INSERT INTO familiasTienda (idFamilia, idTienda, idFamilia_tienda) VALUES (:f1, :tienda, 77)',
            $this->quitarPermiso('familia.php', 'VerFamiliaWeb'),
        ]);

        self::assertCount(1, $peticion['relaciones'], 'Se quitó la relación sin el permiso de la web');
    }

    /** @comportamiento Sin el permiso de ver la tienda web, relacionar una familia con ella no se comprueba ni se guarda. */
    public function test_sinPermisoDeVerLaWebNoSeRelaciona(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'anhadirRefTiendaWebDirecta', 'idFamilia' => ':f1', 'idFamiliaTienda' => '77', 'idTienda' => ':tienda'],
            [$this->familia('Frutas'), $this->quitarPermiso('familia.php', 'VerFamiliaWeb')]
        );

        self::assertSame(['comprobacion' => 'KO'], $peticion['respuesta']);
        self::assertSame([], $peticion['relaciones']);
    }

    /** @comportamiento Con el permiso de ver la web pero sin familia web elegida, la relación no se comprueba ni se guarda. */
    public function test_sinFamiliaWebElegidaNoSeRelaciona(): void
    {
        $peticion = $this->ejecutar(
            ['pulsado' => 'anhadirRefTiendaWebDirecta', 'idFamilia' => ':f1', 'idFamiliaTienda' => '0', 'idTienda' => ':tienda'],
            [$this->familia('Frutas')]
        );

        self::assertSame(['comprobacion' => 'KO'], $peticion['respuesta']);
        self::assertSame([], $peticion['relaciones']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/tareas.php:36-54; modulos/claseModelo.php:50-55
     *
     * @group defecto
     * @group respuesta
     * @group medio
     *
     * @que-ocurre-hoy Grabar una familia responde con la sentencia ejecutada, con los datos, y el
     *   navegador la enseña en el aviso si hay error.
     * @que-deberia-ocurrir Que responda si se grabó y, si no, un error propio, sin la sentencia.
     * @por-que-ocurre El alta y la modificación de la clase base devuelven la sentencia, y la petición
     *   la envía entera.
     * @como-deberia-funcionar Responder el número de la familia o el error, sin la sentencia.
     */
    public function test_grabarNoRespondeLaSentencia(): void
    {
        $peticion = $this->ejecutar($this->grabar(['nombrefamilia' => 'Lácteos', 'idpadre' => '0']));

        self::assertStringNotContainsString('familiaNombre', json_encode($peticion['respuesta']), 'La respuesta lleva la sentencia');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/tareas.php:36-54
     *
     * @group defecto
     * @group guardado
     * @group medio
     *
     * @que-ocurre-hoy Una petición sin nombre da de alta una familia sin nombre: solo el navegador lo
     *   exige, aunque el comentario del guardado dice que hay que comprobarlo.
     * @que-deberia-ocurrir Que el guardado rechace una familia sin nombre.
     * @por-que-ocurre El guardado solo comprueba que el padre no sea negativo.
     * @como-deberia-funcionar Comprobar al guardar que llega el nombre.
     */
    public function test_grabarSinNombreNoGraba(): void
    {
        $peticion = $this->ejecutar($this->grabar(['nombrefamilia' => '', 'idpadre' => '0']));

        self::assertNull($this->familiaDe($peticion, ''), 'Se grabó una familia sin nombre');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/tareas.php:36-54
     *
     * @group defecto
     * @group arbol
     * @group medio
     *
     * @que-ocurre-hoy Una petición con un padre que no es ninguna familia graba la familia colgada de
     *   él: queda fuera del árbol, y ni el listado ni la raíz la enseñan.
     * @que-deberia-ocurrir Que el guardado rechace un padre que no existe, como dice su comentario.
     * @por-que-ocurre El guardado solo comprueba que el padre no sea negativo.
     * @como-deberia-funcionar Comprobar al guardar que el padre es la raíz o una familia existente.
     */
    public function test_grabarConUnPadreQueNoExisteNoGraba(): void
    {
        $peticion = $this->ejecutar($this->grabar(['nombrefamilia' => 'Huérfana', 'idpadre' => '999999']));

        self::assertNull($this->familiaDe($peticion, 'Huérfana'), 'Se grabó colgada de un padre que no existe');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/tareas.php:20-26
     *
     * @group defecto
     * @group sesion
     * @group alto
     *
     * @que-ocurre-hoy Una petición sin sesión graba una familia.
     * @que-deberia-ocurrir Que la petición se rechace sin una sesión válida.
     * @por-que-ocurre La petición de fondo no comprueba la sesión, y el arranque común atiende como
     *   invitado a quien no la tiene.
     * @como-deberia-funcionar Rechazar la petición sin sesión válida, en el arranque común.
     */
    public function test_sinSesionNoSeGraba(): void
    {
        $peticion = $this->ejecutar($this->grabar(['nombrefamilia' => 'Sin sesión', 'idpadre' => '0']), [], false);

        self::assertNull($this->familiaDe($peticion, 'Sin sesión'), 'Se grabó sin sesión');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/tareas.php:36-54; modulos/mod_familia/acces.xml:4
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Un usuario sin el permiso de crear familias da de alta una con la petición de
     *   fondo.
     * @que-deberia-ocurrir Que el alta se rechace sin ese permiso.
     * @por-que-ocurre El módulo declara los permisos de crear, modificar y eliminar, y la petición de
     *   fondo no consulta ninguno.
     * @como-deberia-funcionar Consultar el permiso de la acción antes de grabar o de borrar.
     */
    public function test_sinPermisoDeCrearNoSeGraba(): void
    {
        $peticion = $this->ejecutar($this->grabar(['nombrefamilia' => 'Sin permiso', 'idpadre' => '0']), [$this->quitarPermiso('ListaFamilias.php', 'crear')]);

        self::assertNull($this->familiaDe($peticion, 'Sin permiso'), 'Se grabó sin el permiso de crear');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/tareas.php:36-54; modulos/mod_familia/acces.xml:5
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Un usuario sin el permiso de modificar familias cambia una con la petición de
     *   fondo.
     * @que-deberia-ocurrir Que la modificación se rechace sin ese permiso.
     * @por-que-ocurre La petición de fondo no consulta el permiso de modificar, y ninguna otra parte del
     *   módulo lo consulta.
     * @como-deberia-funcionar Consultar el permiso de la acción antes de grabar.
     */
    public function test_sinPermisoDeModificarNoSeModifica(): void
    {
        $peticion = $this->ejecutar($this->grabar(['id' => ':f1', 'nombrefamilia' => 'Cambiada']), [
            $this->familia('Protegida'),
            $this->quitarPermiso('ListaFamilias.php', 'modificar'),
        ]);

        self::assertNull($this->familiaDe($peticion, 'Cambiada'), 'Se modificó sin el permiso de modificar');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/tareas.php:71-90; modulos/mod_familia/acces.xml:6
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Un usuario sin el permiso de eliminar familias las borra con la petición de fondo.
     * @que-deberia-ocurrir Que el borrado se rechace sin ese permiso.
     * @por-que-ocurre La petición de fondo no consulta el permiso de eliminar.
     * @como-deberia-funcionar Consultar el permiso de la acción antes de borrar.
     */
    public function test_sinPermisoDeEliminarNoSeBorra(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'borrarFamilias', 'idsfamilias' => [':f1']], [
            $this->familia('Protegida'),
            $this->quitarPermiso('ListaFamilias.php', 'eliminar'),
        ]);

        self::assertNotNull($this->familiaDe($peticion, 'Protegida'), 'Se borró sin el permiso de eliminar');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/tareas.php:26-131
     *
     * @group defecto
     * @group respuesta
     * @group bajo
     *
     * @que-ocurre-hoy Una orden que la petición de fondo no conoce responde `null` y deja un aviso de
     *   PHP.
     * @que-deberia-ocurrir Que responda un error.
     * @por-que-ocurre La respuesta solo se define dentro de las órdenes conocidas, y se escribe sea cual
     *   sea la orden.
     * @como-deberia-funcionar Responder un error a las demás órdenes.
     */
    public function test_unaOrdenDesconocidaRespondeUnError(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'ordenQueNoExiste']);

        self::assertNotNull($peticion['respuesta'], 'Responde null');
        self::assertSame('', $peticion['errores'], 'Sin avisos de PHP.');
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/clases/ClaseFamilias.php:199-213; modulos/mod_familia/tareas.php:32-35
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Sin ninguna familia, la lista de todas responde `null` y deja un aviso de PHP.
     * @que-deberia-ocurrir Que responda la lista con la raíz sola, sin avisos.
     * @por-que-ocurre Una lectura sin filas no trae la lista de datos, y la clase y la petición la leen
     *   sin comprobarla.
     * @como-deberia-funcionar Tomar una lista vacía cuando no hay filas.
     */
    public function test_sinFamiliasLaListaRespondeLaRaizSinAvisos(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'leerTodasFamilias'], ['DELETE FROM familiasTienda', 'DELETE FROM articulosFamilias', 'DELETE FROM familias']);

        self::assertSame('', $peticion['errores'], 'Sin avisos de PHP.');
        self::assertSame(0, $peticion['respuesta'][0]['idFamilia'] ?? null);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_familia/funciones.php:11-50; modulos/mod_familia/clases/ClaseFamilias.php:140-166
     *
     * @group defecto
     * @group medio
     *
     * @que-ocurre-hoy Leer las hijas de una familia que no tiene ninguna termina la petición con un
     *   error fatal, tras varios avisos de PHP.
     * @que-deberia-ocurrir Que responda una lista vacía, sin avisos.
     * @por-que-ocurre Cuenta las hijas y los productos de unos datos que, sin filas, no existen, y el
     *   listado cuenta esa lista nula.
     * @como-deberia-funcionar Tomar una lista vacía cuando la lectura no trae filas.
     */
    public function test_leerLasHijasDeUnaFamiliaSinHijasRespondeVacio(): void
    {
        $peticion = $this->ejecutar(['pulsado' => 'leerFamilias', 'idpadre' => ':f1'], [$this->familia('Sin hijas')]);

        self::assertSame('', $peticion['errores'], 'Sin avisos de PHP.');
        self::assertSame([], $peticion['respuesta']['datos'] ?? null);
    }

    private function familia(string $nombre, string $padre = '0'): string
    {
        return "INSERT INTO familias (familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv) VALUES ('$nombre', $padre, 0, 1)";
    }

    /** Pone en la familia el primer artículo del catálogo de pruebas, que queda como `:articulo`. */
    private function producto(string $familia): string
    {
        return "INSERT INTO articulosFamilias (idArticulo, idFamilia) VALUES (:articulo, $familia)";
    }

    private function quitarPermiso(string $vista, string $accion): string
    {
        return "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_familia' AND vista = '$vista' AND accion = '$accion'";
    }

    /** @return array<string, string> */
    private function grabar(array $datos): array
    {
        return $datos + ['pulsado' => 'grabarFamilia', 'id' => '0', 'nombrefamilia' => 'Familia', 'idpadre' => '0', 'beneficiomedio' => '25', 'mostrar_tpv' => '1'];
    }

    /** @return array<string, ?string>|null */
    private function familiaDe(array $peticion, string $nombre): ?array
    {
        foreach ($peticion['familias'] as $familia) {
            if ($familia['familiaNombre'] === $nombre) {
                return $familia;
            }
        }

        return null;
    }

    /**
     * Ejecuta la petición de fondo en un PHP aparte y devuelve su respuesta, sus avisos y lo que quedó
     * en las tablas.
     *
     * @param array<string, mixed> $formulario
     * @param list<string> $preparacion
     * @return array{respuesta:mixed, errores:string, familias:list<array<string, ?string>>, productos:int, relaciones:list<array<string, string>>, ids:array<string, string>}
     */
    private function ejecutar(array $formulario, array $preparacion = [], bool $conSesion = true): array
    {
        $sesion = $conSesion ? [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => 1, 'razonsocial' => 'Tienda de pruebas'],
        ] : [];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = "/TPVFox/modulos/mod_familia/tareas.php";'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_familia', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ($conSesion ? ' new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_familia\'");'
            . ' $ids = [":usuario" => "' . $this->idUsuario . '", ":tienda" => (string) $BDTpv->query("SELECT MIN(idTienda) FROM tiendas")->fetch_row()[0],'
            . ' ":articulo" => (string) $BDTpv->query("SELECT MIN(idArticulo) FROM articulos")->fetch_row()[0]]; $familias = 0;'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $ids));'
            . ' if (str_starts_with($sql, "INSERT INTO familias ")) { $ids[":f" . ++$familias] = (string) $BDTpv->insert_id; } }'
            . ' $_POST = ' . var_export($formulario, true) . ';'
            . ' array_walk_recursive($_POST, function (&$valor) use ($ids) { $valor = strtr((string) $valor, $ids); });'
            . ($conSesion ? ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);' : '')
            . ' register_shutdown_function(function () use ($BDTpv, &$ids) { echo "\n@@ESTADO@@" . json_encode(["ids" => $ids,'
            . ' "familias" => $BDTpv->query("SELECT idFamilia, familiaNombre, familiaPadre, beneficiomedio, mostrar_tpv FROM familias ORDER BY idFamilia")->fetch_all(MYSQLI_ASSOC),'
            . ' "productos" => (int) $BDTpv->query("SELECT COUNT(*) FROM articulosFamilias")->fetch_row()[0],'
            . ' "relaciones" => $BDTpv->query("SELECT idFamilia, idTienda, idFamilia_tienda FROM familiasTienda")->fetch_all(MYSQLI_ASSOC)]); });'
            . ' include "tareas.php";';

        $errores = (string) tempnam(sys_get_temp_dir(), 'familias');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$escrito, $estado] = array_pad(explode('@@ESTADO@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['ids' => [], 'familias' => [], 'productos' => -1, 'relaciones' => []];
        $ejecucion = ['respuesta' => json_decode(trim($escrito), true), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $ejecucion;
    }
}

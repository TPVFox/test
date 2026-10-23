<?php

/**
 * Las pantallas de `mod_etiquetado`: el listado de los lotes (`ListadoEtiquetas.php`) y la ficha de un
 * lote (`etiquetaCodBarras.php`), que lo arma en un temporal, lo guarda como lote o lo cancela.
 *
 * Cada pantalla se pinta en un PHP aparte, desde la carpeta del módulo, con la sesión del usuario de
 * pruebas, los permisos del módulo concedidos salvo los que el caso quite, y una transacción que se
 * deshace al terminar. Los productos, los lotes y los temporales que siembra el caso quedan con un
 * nombre —`:p1`, `:l1`, `:t1`…— para la dirección y el formulario. Al terminar, también tras guardar,
 * el proceso responde lo que dan las consultas del caso. Lo que la ficha pasa a su JavaScript se lee
 * ejecutando ese trozo de la página con Node.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModEtiquetado;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class PantallasEtiquetadoIntegracionTest extends CasoIntegracion
{
    private const SCRIPT = '<script>x=1</script>';

    private int $idUsuario;

    private int $idTienda;

    protected function setUp(): void
    {
        parent::setUp();
        $siembra = new Siembra($this->db);
        $this->idUsuario = $siembra->usuarioPorDefecto();
        $this->idTienda = $siembra->tiendaPorDefecto();
    }

    /**
     * @comportamiento El listado enseña cada lote con su casilla, su número, su producto, sus fechas, su
     *   estado y cuántas etiquetas tiene; los temporales abiertos, con el enlace a su ficha; y las opciones
     *   de añadir, modificar e imprimir.
     */
    public function test_elListadoEnsenaLosLotesYLosTemporales(): void
    {
        $pantalla = $this->pintar('ListadoEtiquetas.php', [], [], [...$this->producto('Pan de molde'), ...$this->lote([$this->linea('Pan'), $this->linea('Pan')]), $this->temporal([$this->linea('Pan')])]);
        $lote = $pantalla['ids'][':l1'];

        self::assertMatchesRegularExpression("#value=\"{$lote}\"></td>\\s*<td>{$lote}</td>\\s*<td>Pan de molde</td>\\s*<td>2026-10-23 10:00:00</td>\\s*<td>2026-11-01</td>\\s*<td>Guardado</td>\\s*<td>2</td>#", $pantalla['html']);
        self::assertStringContainsString('href="etiquetaCodBarras.php?tActual=' . $pantalla['ids'][':t1'] . '"', $pantalla['html']);
        foreach (["metodoClick('Agregar' , 'etiquetaCodBarras')", "metodoClick('Ver','etiquetaCodBarras')", "metodoClick('Imprimir','etiquetaCodBarras')"] as $opcion) {
            self::assertStringContainsString($opcion, $pantalla['html']);
        }
    }

    /** @comportamiento Sin lote, la ficha es la de crear: envasado hoy, caducidad dentro de siete días y el tipo por elegir. */
    public function test_laFichaSinLoteEsLaDeCrear(): void
    {
        $pantalla = $this->pintar('etiquetaCodBarras.php');

        self::assertStringContainsString('<h2 class="text-center"> Crear Etiquetas de Código Barras</h2>', $pantalla['html']);
        self::assertStringContainsString('id="fechaCad" size="10" data-obj="cajaFechaCad" value="' . date('Y-m-d', strtotime('+7 day')) . '"', $pantalla['html']);
        self::assertStringContainsString('id="fechaEnv" size="17" value="' . date('Y-m-d'), $pantalla['html']);
        self::assertStringContainsString('<select name="tipo" id="tipo" onchange="modificarTipo(value);">', $pantalla['html']);
    }

    /**
     * @comportamiento La ficha de un lote guardado enseña su producto, su tipo sin poder cambiarlo, su
     *   caducidad bloqueada y una fila por línea; y pasa sus datos al JavaScript.
     */
    public function test_laFichaDeUnLoteGuardadoLoEnsena(): void
    {
        $pantalla = $this->pintar('etiquetaCodBarras.php', ['id' => ':l1'], [], [...$this->producto('Pan de molde'), ...$this->lote([$this->linea('Pan')])]);

        self::assertStringContainsString("id=\"producto\" name=\"producto\" value='Pan de molde'", $pantalla['html']);
        self::assertStringContainsString('<input type="text" name="tipo" id="tipo" value="Por unidad" readonly>', $pantalla['html']);
        self::assertStringContainsString("$('#fechaCad').prop('disabled', true);", $pantalla['html']);
        self::assertSame(1, substr_count($pantalla['html'], '<tr id="Row'));
        self::assertStringContainsString("cabecera['idReal'] = " . $pantalla['ids'][':l1'] . ';', $pantalla['html']);
        self::assertSame(['Pan'], array_column($this->productosDelJavaScript($pantalla['html']), 'nombre'));
    }

    /** @comportamiento La ficha de un lote guardado por peso enseña su tipo, sin poder cambiarlo. */
    public function test_laFichaDeUnLotePorPesoEnsenaSuTipo(): void
    {
        $pantalla = $this->pintar('etiquetaCodBarras.php', ['id' => ':l1'], [], [...$this->producto('Queso tierno'), ...$this->lote([$this->linea('Queso')]), "UPDATE modulo_etiquetado SET tipo = '2' WHERE id = :l1"]);

        self::assertStringContainsString('<input type="text" name="tipo" id="tipo" value="Por peso" readonly>', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:268
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:404-424
     *
     * @group defecto
     * @group listado
     * @group medio
     *
     * @que-ocurre-hoy La ficha de un lote guardado deja la columna de las cantidades como «Tipo», sin decir
     *   si son unidades o pesos, y la de un temporal no marca su tipo en el desplegable; su JavaScript
     *   recibe un nombre que no existe en lugar del tipo.
     * @que-deberia-ocurrir Que la columna diga «Unidad» o «Peso», y que el desplegable marque el tipo del
     *   temporal.
     * @por-que-ocurre La ficha incluye el menú a mitad de la página, y el menú usa una variable con el mismo
     *   nombre que la del tipo: lo que la ficha escribe después ya no tiene el tipo del lote.
     * @como-deberia-funcionar Leer el tipo en una variable que el menú no toque, o escribir lo que depende
     *   del tipo antes de incluir el menú.
     */
    public function test_laFichaTitulaLaColumnaYMarcaElTipoDelLote(): void
    {
        $lote = $this->pintar('etiquetaCodBarras.php', ['id' => ':l1'], [], [...$this->producto('Queso tierno'), ...$this->lote([$this->linea('Queso')]), "UPDATE modulo_etiquetado SET tipo = '2' WHERE id = :l1"]);
        $temporal = $this->pintar('etiquetaCodBarras.php', ['tActual' => ':t1'], [], [...$this->producto('Pan de molde'), $this->temporal([$this->linea('Pan')])]);

        self::assertStringContainsString("$('#tipoTabla').html(\"Peso\");", $lote['html']);
        self::assertMatchesRegularExpression('/option\[value=" \+ 1 \+ "\]|<option value=.1. selected/', $temporal['html']);
    }

    /** @comportamiento La ficha de un temporal lo enseña con su número, para seguir armándolo. */
    public function test_laFichaDeUnTemporalLoEnsena(): void
    {
        $pantalla = $this->pintar('etiquetaCodBarras.php', ['tActual' => ':t1'], [], [...$this->producto('Pan de molde'), $this->temporal([$this->linea('Pan')])]);

        self::assertStringContainsString("cabecera['idTemporal'] = " . $pantalla['ids'][':t1'] . ';', $pantalla['html']);
        self::assertSame(1, substr_count($pantalla['html'], '<tr id="Row'));
    }

    /**
     * @comportamiento Guardar pasa el temporal a un lote guardado, a nombre del usuario de la sesión, con su
     *   caducidad y sus líneas, y borra el temporal.
     */
    public function test_guardarPasaElTemporalALote(): void
    {
        $guardar = $this->pintar('etiquetaCodBarras.php', ['tActual' => ':t1'], ['Guardar' => 'Guardar', 'fechaCad' => '2026-11-15'], [...$this->producto('Pan de molde'), $this->temporal([$this->linea('Pan'), $this->linea('Pan')])], [
            'lote' => "SELECT CONCAT(estado, '|', fecha_cad, '|', idUsuario) FROM modulo_etiquetado WHERE idArticulo = :p1",
            'lineas' => 'SELECT productos FROM modulo_etiquetado WHERE idArticulo = :p1',
            'temporal' => 'SELECT COUNT(*) FROM modulo_etiquetado_temporal WHERE id = :t1',
        ]);

        self::assertSame("Guardado|2026-11-15|{$this->idUsuario}", $guardar['consultas']['lote']);
        self::assertCount(2, json_decode((string) $guardar['consultas']['lineas'], true));
        self::assertSame('0', $guardar['consultas']['temporal']);
    }

    /** @comportamiento Guardar el temporal de un lote ya guardado lo actualiza, sin crear otro. */
    public function test_guardarUnLoteModificadoLoActualiza(): void
    {
        $guardar = $this->pintar('etiquetaCodBarras.php', ['tActual' => ':t1'], ['Guardar' => 'Guardar', 'fechaCad' => '2026-11-15'], [...$this->producto('Pan de molde'), ...$this->lote([$this->linea('Pan')]), $this->temporal([$this->linea('Pan'), $this->linea('Pan')], ':l1')], [
            'lotes' => 'SELECT COUNT(*) FROM modulo_etiquetado WHERE idArticulo = :p1',
            'caducidad' => 'SELECT fecha_cad FROM modulo_etiquetado WHERE id = :l1',
        ]);

        self::assertSame(['1', '2026-11-15'], [$guardar['consultas']['lotes'], $guardar['consultas']['caducidad']]);
    }

    /**
     * @comportamiento Cancelar borra el temporal; sin temporal, guardar y cancelar avisan de que no hay
     *   nada que hacer.
     */
    public function test_cancelarBorraElTemporalYSinTemporalSeAvisa(): void
    {
        $cancelar = $this->pintar('etiquetaCodBarras.php', ['tActual' => ':t1'], ['Cancelar' => 'Cancelar'], [...$this->producto('Pan de molde'), $this->temporal([$this->linea('Pan')])], ['temporal' => 'SELECT COUNT(*) FROM modulo_etiquetado_temporal WHERE id = :t1']);
        $guardarSinTemporal = $this->pintar('etiquetaCodBarras.php', ['id' => ':l1'], ['Guardar' => 'Guardar'], [...$this->producto('Pan de molde'), ...$this->lote([$this->linea('Pan')])]);
        $cancelarSinTemporal = $this->pintar('etiquetaCodBarras.php', ['id' => ':l1'], ['Cancelar' => 'Cancelar'], [...$this->producto('Pan de molde'), ...$this->lote([$this->linea('Pan')])]);

        self::assertSame('0', $cancelar['consultas']['temporal']);
        self::assertStringContainsString('No se puede guardar ya que no tiene ninguna modificación!', $guardarSinTemporal['html']);
        self::assertStringContainsString('No puedes cancelar un lote que ya está guardado!', $cancelarSinTemporal['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:127-135
     *
     * @group defecto
     * @group guardado
     * @group alto
     *
     * @que-ocurre-hoy Guardar un lote con una línea quitada detrás de una activa guarda la quitada y pierde
     *   la activa: se imprime la etiqueta que se quitó, y no la que se dejó.
     * @que-deberia-ocurrir Que el lote guardado tenga las líneas activas, y solo esas.
     * @por-que-ocurre Al quitar las líneas, el índice que se borra solo avanza con cada línea quitada, de modo
     *   que borra las primeras líneas del lote en lugar de las quitadas.
     * @como-deberia-funcionar Borrar cada línea por su propio índice, o quedarse con las activas.
     */
    public function test_guardarSoloDejaLasLineasActivas(): void
    {
        $guardar = $this->pintar('etiquetaCodBarras.php', ['tActual' => ':t1'], ['Guardar' => 'Guardar', 'fechaCad' => '2026-11-15'], [...$this->producto('Pan de molde'), $this->temporal([$this->linea('Activa'), $this->linea('Quitada', 'Eliminado')])], ['lineas' => 'SELECT productos FROM modulo_etiquetado WHERE idArticulo = :p1']);

        self::assertSame(['Activa'], array_column(array_values((array) json_decode((string) $guardar['consultas']['lineas'], true)), 'nombre'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:110-153
     *
     * @group defecto
     * @group guardado
     * @group bajo
     *
     * @que-ocurre-hoy Guardar un lote con todas sus líneas quitadas lo guarda sin ninguna etiqueta, sin
     *   avisar, aunque el guardado dice que en ese caso muestra un error.
     * @que-deberia-ocurrir Que no se guarde y se avise de que el lote no tiene líneas.
     * @por-que-ocurre Guardar quita las líneas quitadas y no mira si queda alguna.
     * @como-deberia-funcionar Comprobar que queda alguna línea activa antes de guardar, y avisar si no.
     */
    public function test_guardarUnLoteSinLineasActivasAvisa(): void
    {
        $guardar = $this->pintar('etiquetaCodBarras.php', ['tActual' => ':t1'], ['Guardar' => 'Guardar', 'fechaCad' => '2026-11-15'], [...$this->producto('Pan de molde'), $this->temporal([$this->linea('Quitada', 'Eliminado'), $this->linea('Quitada', 'Eliminado')])], ['lotes' => 'SELECT COUNT(*) FROM modulo_etiquetado WHERE idArticulo = :p1']);

        self::assertSame('0', $guardar['consultas']['lotes']);
        self::assertStringContainsString('alert-', $guardar['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:235-253
     *
     * @group defecto
     * @group guardado
     * @group alto
     *
     * @que-ocurre-hoy La ficha de un lote con varias líneas pasa al JavaScript todas iguales, con los datos
     *   de la última: el primer cambio que se haga guarda el lote con todas sus líneas iguales.
     * @que-deberia-ocurrir Que cada línea llegue al JavaScript con sus propios datos.
     * @por-que-ocurre El objeto de cada línea se crea una sola vez, antes de recorrerlas, y se añade el mismo
     *   a la lista en cada vuelta.
     * @como-deberia-funcionar Crear el objeto dentro del recorrido, o pasar las líneas con `json_encode()`.
     */
    public function test_laFichaPasaCadaLineaAlJavaScriptConSusDatos(): void
    {
        $pantalla = $this->pintar('etiquetaCodBarras.php', ['id' => ':l1'], [], [...$this->producto('Pan de molde'), ...$this->lote([$this->linea('Primera'), $this->linea('Segunda')])]);

        self::assertSame(['Primera', 'Segunda'], array_column($this->productosDelJavaScript($pantalla['html']), 'nombre'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:245-248
     *
     * @group defecto
     * @group guardado
     * @group medio
     *
     * @que-ocurre-hoy La ficha pasa al JavaScript el albarán de cada línea como 0 si lo tiene, y vacío si no:
     *   el primer cambio guarda las líneas sin su albarán.
     * @que-deberia-ocurrir Que cada línea llegue con su albarán.
     * @por-que-ocurre La condición está al revés: pone 0 cuando el albarán no está vacío.
     * @como-deberia-funcionar Pasar el albarán tal como está guardado.
     */
    public function test_laFichaConservaElAlbaranDeCadaLinea(): void
    {
        $pantalla = $this->pintar('etiquetaCodBarras.php', ['id' => ':l1'], [], [...$this->producto('Pan de molde'), ...$this->lote([['NumAlb' => '15'] + $this->linea('Pan')])]);

        self::assertSame(['15'], array_column($this->productosDelJavaScript($pantalla['html']), 'NumAlb'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:241
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:356
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy La ficha escribe el nombre del producto tal cual en su caja y en el JavaScript: un
     *   nombre con una etiqueta de script la ejecuta al abrir la ficha.
     * @que-deberia-ocurrir Que el nombre se escriba como texto.
     * @por-que-ocurre La ficha escribe lo leído sin escaparlo.
     * @como-deberia-funcionar Escapar cada dato al escribirlo, y pasar los del JavaScript con `json_encode()`.
     */
    public function test_laFichaEscribeElNombreComoTexto(): void
    {
        $pantalla = $this->pintar('etiquetaCodBarras.php', ['id' => ':l1'], [], [...$this->producto(self::SCRIPT), ...$this->lote([$this->linea('Pan')])]);

        self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/ListadoEtiquetas.php:106
     * @codigo-afectado modulos/mod_etiquetado/ListadoEtiquetas.php:151
     *
     * @group defecto
     * @group salida
     * @group alto
     *
     * @que-ocurre-hoy El listado escribe el nombre del producto de cada lote y de cada temporal tal cual: un
     *   nombre con una etiqueta de script la ejecuta al abrir el listado.
     * @que-deberia-ocurrir Que el nombre se escriba como texto.
     * @por-que-ocurre El listado escribe lo leído sin escaparlo.
     * @como-deberia-funcionar Escapar cada dato al escribirlo.
     */
    public function test_elListadoEscribeElNombreComoTexto(): void
    {
        $pantalla = $this->pintar('ListadoEtiquetas.php', [], [], [...$this->producto(self::SCRIPT), ...$this->lote([$this->linea('Pan')])]);

        self::assertStringNotContainsString(self::SCRIPT, $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:42-62
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:378-382
     *
     * @group defecto
     * @group listado
     * @group medio
     *
     * @que-ocurre-hoy La ficha de un número que no es de ningún lote termina con un error de PHP, sin
     *   pintarse.
     * @que-deberia-ocurrir Que avise de que el lote no existe.
     * @por-que-ocurre La lectura no trae el lote, y la ficha cuenta sus líneas sin comprobarlo.
     * @como-deberia-funcionar Comprobar que la lectura trae el lote antes de usarlo.
     */
    public function test_laFichaDeUnLoteQueNoExisteAvisa(): void
    {
        $pantalla = $this->pintar('etiquetaCodBarras.php', ['id' => '999999']);

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
        self::assertStringContainsString('alert-', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:84-109
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:378-382
     *
     * @group defecto
     * @group listado
     * @group medio
     *
     * @que-ocurre-hoy La ficha de un número que no es de ningún temporal —uno que ya se guardó o se canceló
     *   en otra ventana— termina con un error de PHP, sin pintarse.
     * @que-deberia-ocurrir Que avise de que el temporal no existe.
     * @por-que-ocurre La lectura no trae el temporal, y la ficha cuenta sus líneas sin comprobarlo.
     * @como-deberia-funcionar Comprobar que la lectura trae el temporal antes de usarlo.
     */
    public function test_laFichaDeUnTemporalQueNoExisteAvisa(): void
    {
        $pantalla = $this->pintar('etiquetaCodBarras.php', ['tActual' => '999999']);

        self::assertStringNotContainsString('Fatal error', $pantalla['errores']);
        self::assertStringContainsString('alert-', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:186-198
     *
     * @group defecto
     * @group estados
     * @group bajo
     *
     * @que-ocurre-hoy Cancelar los cambios de un lote guardado borra el temporal y deja el lote «Sin
     *   guardar», aunque no cambió.
     * @que-deberia-ocurrir Que el lote vuelva a «Guardado».
     * @por-que-ocurre El primer cambio deja el lote «Sin guardar», y cancelar solo borra el temporal.
     * @como-deberia-funcionar Devolver el lote del que sale el temporal a «Guardado» al cancelar.
     */
    public function test_cancelarLosCambiosDeUnLoteLoDejaGuardado(): void
    {
        $cancelar = $this->pintar('etiquetaCodBarras.php', ['tActual' => ':t1'], ['Cancelar' => 'Cancelar'], [
            ...$this->producto('Pan de molde'),
            ...$this->lote([$this->linea('Pan')]),
            "UPDATE modulo_etiquetado SET estado = 'Sin guardar' WHERE id = :l1",
            $this->temporal([$this->linea('Pan'), $this->linea('Pan')], ':l1'),
        ], ['estado' => 'SELECT estado FROM modulo_etiquetado WHERE id = :l1']);

        self::assertSame('Guardado', $cancelar['consultas']['estado']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:117-153
     *
     * @group defecto
     * @group importes
     * @group medio
     *
     * @que-ocurre-hoy Guardar un lote cuya línea trae el código de otro precio —el de 1 € en una línea de
     *   3,50 €— lo guarda así, y la etiqueta se imprime con ese código.
     * @que-deberia-ocurrir Que el lote guardado lleve en cada línea el código de su precio.
     * @por-que-ocurre Las líneas se guardan tal como las manda el navegador, sin recalcular su código.
     * @como-deberia-funcionar Calcular el precio y el código de cada línea en el servidor al guardar.
     */
    public function test_guardarNoDejaUnCodigoQueNoEsElDeSuPrecio(): void
    {
        $guardar = $this->pintar('etiquetaCodBarras.php', ['tActual' => ':t1'], ['Guardar' => 'Guardar', 'fechaCad' => '2026-11-15'], [
            ...$this->producto('Pan de molde'),
            $this->temporal([['codBarras' => '2112345001005'] + $this->linea('Pan')]),
        ], ['lineas' => 'SELECT productos FROM modulo_etiquetado WHERE idArticulo = :p1']);

        self::assertNotContains('2112345001005', array_column((array) json_decode((string) $guardar['consultas']['lineas'], true), 'codBarras'));
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:42-44
     * @codigo-afectado modulos/mod_etiquetado/clases/modulo_etiquetado.php:212-214
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy La ficha abierta con un número de lote con una condición añadida enseña otro lote.
     * @que-deberia-ocurrir Que un número que no es de ningún lote no enseñe ninguno.
     * @por-que-ocurre El número de la dirección se pega a la consulta sin convertirlo.
     * @como-deberia-funcionar Convertir el número en entero antes de usarlo.
     */
    public function test_laFichaConUnNumeroConCondicionNoEnsenaOtroLote(): void
    {
        $pantalla = $this->pintar('etiquetaCodBarras.php', ['id' => '0 OR 1=1'], [], [...$this->producto('Pan de molde'), ...$this->lote([$this->linea('Pan')])]);

        self::assertStringContainsString('name="id_producto" data-obj="cajaIdProducto" value=""', $pantalla['html']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/etiquetaCodBarras.php:110-185
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin los permisos de crear ni de modificar lotes, la ficha guarda el lote igual.
     * @que-deberia-ocurrir Que sin ellos el lote no se guarde.
     * @por-que-ocurre El módulo declara crear y modificar, y ninguna pantalla los consulta.
     * @como-deberia-funcionar Consultar el permiso de la acción antes de guardar.
     */
    public function test_sinPermisoDeCrearNiModificarNoSeGuarda(): void
    {
        $guardar = $this->pintar('etiquetaCodBarras.php', ['tActual' => ':t1'], ['Guardar' => 'Guardar', 'fechaCad' => '2026-11-15'], [
            ...$this->producto('Pan de molde'),
            $this->temporal([$this->linea('Pan')]),
            "UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_etiquetado' AND accion IN ('crear', 'modificar')",
        ], ['lotes' => 'SELECT COUNT(*) FROM modulo_etiquetado WHERE idArticulo = :p1']);

        self::assertSame('0', $guardar['consultas']['lotes']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/ListadoEtiquetas.php:85-89
     *
     * @group defecto
     * @group permisos
     * @group alto
     *
     * @que-ocurre-hoy Sin los permisos de crear, modificar e imprimir, el listado ofrece añadir, modificar e
     *   imprimir.
     * @que-deberia-ocurrir Que el listado solo ofrezca lo que el usuario puede hacer.
     * @por-que-ocurre El listado pinta las tres opciones sin consultar sus permisos.
     * @como-deberia-funcionar Pintar cada opción solo con su permiso.
     */
    public function test_sinPermisosElListadoNoOfreceAnadirModificarNiImprimir(): void
    {
        $pantalla = $this->pintar('ListadoEtiquetas.php', [], [], ["UPDATE permisos SET permiso = 0 WHERE idUsuario = :usuario AND modulo = 'mod_etiquetado' AND accion IN ('crear', 'modificar', 'imprimir')"]);

        self::assertStringNotContainsString("metodoClick('Agregar'", $pantalla['html']);
        self::assertStringNotContainsString("metodoClick('Imprimir'", $pantalla['html']);
    }

    /** @return list<string> */
    private function producto(string $nombre): array
    {
        return [
            "INSERT INTO articulos (articulo_name, estado, fecha_creado, ultimoCoste, iva, beneficio, tipo) VALUES ('{$nombre}', 'Activo', '2026-01-01', 1, 10, 0, 'unidad')",
            'INSERT INTO articulosPrecios (idArticulo, pvpCiva, pvpSiva, idTienda) VALUES (:pN, 3.5, 3.18, :tienda)',
            "INSERT INTO articulosTiendas (idArticulo, idTienda, crefTienda, estado) VALUES (:pN, :tienda, '12345', 'Activo')",
        ];
    }

    /**
     * Un lote guardado del último producto sembrado, con su número de lote igual a su número, como lo
     * guarda la ficha.
     *
     * @param list<array<string, string>> $lineas
     * @return list<string>
     */
    private function lote(array $lineas): array
    {
        return [
            "INSERT INTO modulo_etiquetado (num_lote, tipo, fecha_env, fecha_cad, idArticulo, numAlb, estado, productos, idUsuario) VALUES (0, '1', '2026-10-23 10:00:00', '2026-11-01', :pN, 0, 'Guardado', '" . addslashes($this->lineas($lineas)) . "', :usuario)",
            'UPDATE modulo_etiquetado SET num_lote = id WHERE id = :lN',
        ];
    }

    /** @param list<array<string, string>> $lineas */
    private function temporal(array $lineas, string $numLote = '0'): string
    {
        return "INSERT INTO modulo_etiquetado_temporal (num_lote, tipo, fecha_env, fecha_cad, idArticulo, numAlb, estado, productos, idUsuario) VALUES ({$numLote}, '1', '2026-10-23 10:00:00', '2026-11-01', :pN, 0, 'Activo', '" . addslashes($this->lineas($lineas)) . "', :usuario)";
    }

    /** @param list<array<string, string>> $lineas */
    private function lineas(array $lineas): string
    {
        foreach ($lineas as $i => $linea) {
            $lineas[$i]['Nfila'] = (string) ($i + 1);
        }

        return (string) json_encode($lineas);
    }

    /** @return array<string, string> */
    private function linea(string $nombre, string $estado = 'Activo'): array
    {
        return ['nombre' => $nombre, 'peso' => '1', 'precio' => '3.5', 'Fecha' => '2026-11-01', 'NumAlb' => '', 'codBarras' => '2112345003504', 'estado' => $estado, 'Nfila' => '1', 'crefTienda' => '12345'];
    }

    /**
     * Ejecuta con Node el trozo de la ficha que da a su JavaScript las líneas del lote, y devuelve la
     * lista que deja; si no se puede ejecutar, lo que respondió Node.
     *
     * @return array<int|string, mixed>
     */
    private function productosDelJavaScript(string $html): array
    {
        $inicio = (int) strpos($html, 'var productos = [];');
        $codigo = substr($html, $inicio, (int) strpos($html, '</script>', $inicio) - $inicio) . "\nprocess.stdout.write(JSON.stringify(productos));";
        $salida = (string) shell_exec('node -e ' . escapeshellarg($codigo) . ' 2>&1');

        return json_decode($salida, true) ?? ['node' => $salida];
    }

    /**
     * Pinta una pantalla del módulo en un PHP aparte, con lo que llega por la dirección y por el formulario.
     * Las consultas del caso se responden al terminar.
     *
     * @param array<string, string> $direccion
     * @param array<string, string> $formulario
     * @param list<string> $preparacion
     * @param array<string, string> $consultas
     * @return array{html: string, errores: string, ids: array<string, string>, consultas: array<string, ?string>}
     */
    private function pintar(string $fichero, array $direccion = [], array $formulario = [], array $preparacion = [], array $consultas = []): array
    {
        $sesion = [
            'estadoTpv' => 'Correcto',
            'usuarioTpv' => ['id' => $this->idUsuario, 'group_id' => 1, 'login' => 'pruebas', 'nombre' => 'Usuario de pruebas'],
            'tiendaTpv' => ['idTienda' => $this->idTienda, 'razonsocial' => 'Tienda de pruebas'],
        ];
        $guion = '$_SESSION = ' . var_export($sesion, true) . ';'
            . ' $_SERVER["DOCUMENT_ROOT"] = ""; $_SERVER["PHP_SELF"] = $_SERVER["REQUEST_URI"] = ' . var_export("/TPVFox/modulos/mod_etiquetado/{$fichero}", true) . ';'
            . ' chdir(' . var_export(RUTA_TPVFOX . '/modulos/mod_etiquetado', true) . ');'
            . ' require "./../../inicial.php";'
            . ' $BDTpv->begin_transaction();'
            . ' require_once $RutaServidor . $HostNombre . "/modulos/claseModeloP.php";'
            . ' ModeloP::usarConexion($BDTpv);'
            . ' new ClasePermisos($Usuario, $BDTpv);'
            . ' $BDTpv->query("UPDATE permisos SET permiso = 1 WHERE idUsuario = ' . $this->idUsuario . ' AND modulo = \'mod_etiquetado\'");'
            . ' $sembrados = [":usuario" => "' . $this->idUsuario . '", ":tienda" => "' . $this->idTienda . '"]; $cuenta = ["p" => 0, "l" => 0, "t" => 0];'
            . ' foreach (' . var_export($preparacion, true) . ' as $sql) { $BDTpv->query(strtr($sql, $sembrados));'
            . ' foreach (["INSERT INTO articulos " => "p", "INSERT INTO modulo_etiquetado " => "l", "INSERT INTO modulo_etiquetado_temporal " => "t"] as $inicio => $letra) {'
            . ' if (str_starts_with($sql, $inicio)) { $sembrados[":" . $letra . ++$cuenta[$letra]] = $sembrados[":" . $letra . "N"] = (string) $BDTpv->insert_id; } } }'
            . ' $_GET = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($direccion, true) . ');'
            . ' $_POST = array_map(fn ($valor) => strtr((string) $valor, $sembrados), ' . var_export($formulario, true) . ');'
            . ' $ClasePermisos = $thisTpv->permisos = new ClasePermisos($Usuario, $BDTpv);'
            . ' register_shutdown_function(function () use ($BDTpv, &$sembrados) { $resultados = [];'
            . ' foreach (' . var_export($consultas, true) . ' as $nombre => $sql) { $fila = $BDTpv->query(strtr($sql, $sembrados))->fetch_row(); $resultados[$nombre] = $fila === null ? null : (string) $fila[0]; }'
            . ' echo "\n@@ESTADO@@" . json_encode(["ids" => $sembrados, "consultas" => $resultados]); });'
            . ' include ' . var_export($fichero, true) . ';';

        $errores = (string) tempnam(sys_get_temp_dir(), 'pantallas');
        exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -d log_errors=0 -r ' . escapeshellarg($guion) . ' 2>' . escapeshellarg($errores), $salida);
        [$html, $estado] = array_pad(explode('@@ESTADO@@', implode("\n", $salida), 2), 2, 'null');
        $estado = json_decode($estado, true) ?? ['ids' => [], 'consultas' => []];
        $pintada = ['html' => trim($html), 'errores' => (string) file_get_contents($errores)] + $estado;
        unlink($errores);

        return $pintada;
    }
}

<?php

/**
 * Las funciones de pintado de la ficha de tienda (`modulos/mod_tienda/funciones.php`): los botones
 * del tipo de tienda, los selectores del estado y de la autenticación del correo, los avisos de la
 * pantalla y la lectura de la tienda principal y de la web.
 *
 * Ninguna consulta la base por sí misma: la lectura de las tiendas recibe la clase que las lee, y aquí
 * se le da una de prueba que devuelve lo que el caso pide.
 */

declare(strict_types=1);

namespace TPVFox\Test\Unit\ModTienda;

use PHPUnit\Framework\TestCase;

final class FuncionesTiendaTest extends TestCase
{
    private const RUTA_FUNCIONES = RUTA_TPVFOX . '/modulos/mod_tienda/funciones.php';

    protected function setUp(): void
    {
        parent::setUp();
        require_once self::RUTA_FUNCIONES;
    }

    /** @comportamiento Solo se pintan los tipos que se piden, y el de la tienda va marcado. */
    public function test_losBotonesDelTipoMarcanElDeLaTienda(): void
    {
        $tipos = $this->tipos();
        $tipos['principal']['montar_radio'] = 'No';

        $html = \htmlInputRatio($tipos, 'web');

        self::assertStringContainsString('value="fisica"', $html);
        self::assertStringNotContainsString('value="principal"', $html);
        self::assertMatchesRegularExpression('/value="web"[^>]*checked/', $html);
        self::assertDoesNotMatchRegularExpression('/value="fisica"[^>]*checked/', $html);
    }

    /**
     * @comportamiento Si el tipo de la tienda está marcado y desactivado, viaja en un campo oculto,
     *   porque un botón desactivado no se envía con el formulario.
     */
    public function test_elTipoMarcadoYDesactivadoViajaEnUnCampoOculto(): void
    {
        $tipos = $this->tipos();
        $tipos['principal']['disabled'] = 'disabled';

        $html = \htmlInputRatio($tipos, 'principal');

        self::assertStringContainsString('<input type="hidden" name ="tipoTienda" value="principal">', $html);
    }

    /** @comportamiento El estado de una tienda sale marcado en su selector. */
    public function test_elEstadoDeLaTiendaSaleMarcado(): void
    {
        $html = \htmlEstados('Activo');

        self::assertMatchesRegularExpression('/<option value="Activo" selected>Activo<\/option>/', $html);
        self::assertStringContainsString('name="estado"', $html);
    }

    /**
     * @comportamiento Una tienda guardada sin estado —como queda tras el alta— recibe en su ficha el
     *   selector del estado, con «cerrado» marcado por defecto: así se puede activar después.
     */
    public function test_unaTiendaSinEstadoGuardadoRecibeElSelector(): void
    {
        $html = \htmlEstados(null);

        self::assertStringContainsString('<option value="cerrado" selected>cerrado</option>', $html);
        self::assertStringContainsString('<option value="Activo" >Activo</option>', $html);
    }

    /** @comportamiento Un selector con su valor entre las opciones lo marca, con el texto de cada opción. */
    public function test_unSelectorMarcaSuValor(): void
    {
        $html = \htmlSelect($this->opcionesSiNo(), ['label' => 'Autentificación SMTP', 'name' => 'auth'], 'false');

        self::assertStringContainsString('<option value="false" selected>No</option>', $html);
        self::assertStringContainsString('<option value="true" >Si</option>', $html);
        self::assertStringStartsWith('<label>Autentificación SMTP:', $html);
    }

    /** @comportamiento Cada aviso de la pantalla es una alerta con su tipo y su mensaje. */
    public function test_cadaAvisoEsUnaAlertaConSuTipo(): void
    {
        $avisos = [\matrizError('info', 'Modificada'), \matrizError('danger', 'Fallo')];

        self::assertSame(['tipo' => 'info', 'mensaje' => 'Modificada'], $avisos[0]);
        self::assertSame(
            '<div class="alert alert-info">Modificada</div><div class="alert alert-danger">Fallo</div>',
            \htmlAdvertencias($avisos)
        );
        self::assertSame('', \htmlAdvertencias([]));
    }

    /** @comportamiento Se devuelven la tienda principal y la web que haya, y solo las que hay. */
    public function test_seDevuelvenLaPrincipalYLaWebQueHaya(): void
    {
        $conLas2 = \obtenerTiendaPrincipalYWeb($this->tiendas(['idTienda' => '1'], ['idTienda' => '4']));
        $sinWeb = \obtenerTiendaPrincipalYWeb($this->tiendas(['idTienda' => '1'], null));

        self::assertSame(['web' => ['idTienda' => '4'], 'principal' => ['idTienda' => '1']], $conLas2);
        self::assertSame(['principal' => ['idTienda' => '1']], $sinWeb);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/funciones.php:120-127
     *
     * @group defecto
     * @group alto
     *
     * @que-ocurre-hoy La ficha de una tienda nueva, que aún no tiene estado, no ofrece el selector
     *   del estado: en su lugar escribe «Mandas valor seleccionado pero no coincide con ninguna
     *   opcion».
     * @que-deberia-ocurrir Que ofrezca el selector, con «cerrado» marcado por defecto.
     * @por-que-ocurre La comprobación del selector asigna en vez de comparar, y esa rama se toma
     *   justo cuando no se pide valor.
     * @como-deberia-funcionar Comparar, y avisar solo si se pide un valor que no está entre las
     *   opciones.
     */
    public function test_unaTiendaNuevaOfreceElSelectorDelEstado(): void
    {
        $html = \htmlEstados('');

        self::assertStringContainsString('<select', $html);
        self::assertStringContainsString('<option value="cerrado" selected>cerrado</option>', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/funciones.php:120-127
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy Un selector al que se pide un valor que no está entre sus opciones se pinta sin
     *   ninguna marcada, sin aviso: los datos del correo con una autenticación que no es «true» ni
     *   «false» se enseñan como si fuera «Si».
     * @que-deberia-ocurrir Que avise de que el valor no está entre las opciones, como dice la función.
     * @por-que-ocurre El aviso se toma cuando no se pide valor, y nunca cuando el pedido no coincide.
     * @como-deberia-funcionar Avisar cuando se pide un valor y ninguna opción coincide.
     */
    public function test_unSelectorConUnValorQueNoEstaAvisa(): void
    {
        $html = \htmlSelect($this->opcionesSiNo(), ['name' => 'auth'], 'quizas');

        self::assertStringNotContainsString('<select', $html);
        self::assertStringContainsString('no coincide con ninguna opcion', $html);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_tienda/funciones.php:103-104
     *
     * @group defecto
     * @group bajo
     *
     * @que-ocurre-hoy El selector se abre con unas comillas de más detrás de su identificador
     *   (`id="sel1"">`): la etiqueta queda mal formada.
     * @que-deberia-ocurrir Que la etiqueta del selector esté bien formada.
     * @por-que-ocurre La cadena que cierra la etiqueta añade unas comillas que ya pone el atributo.
     * @como-deberia-funcionar Cerrar la etiqueta solo con `>`.
     */
    public function test_elSelectorEstaBienFormado(): void
    {
        $html = \htmlSelect($this->opcionesSiNo(), ['id' => 'sel2', 'name' => 'auth'], 'true');

        self::assertStringContainsString('id="sel2">', $html);
    }

    /** @return array<string, array<string, string>> */
    private function tipos(): array
    {
        $tipo = static fn (string $titulo): array => [
            'title' => $titulo, 'texto_title' => '', 'checked' => '', 'display' => '', 'disabled' => '', 'montar_radio' => 'Si',
        ];

        return ['fisica' => $tipo('Tienda fisica'), 'web' => $tipo('Tienda Web'), 'principal' => $tipo('Tienda Principal')];
    }

    /** @return list<array<string, string>> */
    private function opcionesSiNo(): array
    {
        return [['valor' => 'true', 'texto' => 'Si'], ['valor' => 'false', 'texto' => 'No']];
    }

    /** Una clase de tiendas de prueba: devuelve la principal y la web que se le den. */
    private function tiendas(?array $principal, ?array $web): object
    {
        return new class ($principal, $web) {
            public function __construct(private ?array $principal, private ?array $web)
            {
            }

            public function tiendaPrincipal(): array
            {
                return ['datos' => $this->principal === null ? [] : [$this->principal]];
            }

            public function tiendasWeb(): array
            {
                return ['datos' => $this->web === null ? [] : [$this->web]];
            }
        };
    }
}

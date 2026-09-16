<?php

/**
 * Convierte en lenguaje llano lo que las pruebas ya dicen de si mismas sin proponerselo.
 *
 * Solo 159 de los 532 casos PHP llevan comentario propio. Esperar a que alguien escriba los
 * otros 373 seria dejar el informe medio vacio durante meses, y no hace falta: el nombre del
 * metodo, el de su clase, la carpeta en la que vive y los metodos de siembra que invoca ya
 * contienen casi todo. Aqui se traduce eso a la frase y las etiquetas que el informe pinta,
 * de modo que un caso sin una linea de documentacion se entienda igual.
 *
 * Lo derivado nunca pisa lo escrito: si el caso declara su propia anotacion, manda la suya.
 */

declare(strict_types=1);

namespace TPVFox\Test\Informe;

final class Vocabulario
{
    /**
     * Los metodos de siembra, traducidos a la frase del «dado que».
     *
     * Son el vocabulario con el que la suite monta sus escenarios; el rastro de la conexion
     * observada dice cuales se llamaron, y con esta tabla eso se convierte en una frase.
     */
    private const SIEMBRA = [
        'articulo' => 'un articulo',
        'familia' => 'una familia de articulos',
        'cliente' => 'un cliente',
        'proveedor' => 'un proveedor',
        'tipoIva' => 'un tipo impositivo',
        'precioYTienda' => 'un precio en su tienda',
        'existenciaRegistrada' => 'una existencia registrada',
        'entradaProveedor' => 'una entrada de proveedor',
        'ventaTicket' => 'una venta de ticket',
        'ventaAlbaranCliente' => 'un albaran de cliente',
        'pedidoVentaCliente' => 'un pedido de cliente',
        'facturarAlbaranCliente' => 'una factura hecha desde un albaran',
        'adjuntarPedidoAAlbaran' => 'un pedido adjuntado a un albaran',
        'pedidoTemporal' => 'un pedido a medio componer',
        'albaranTemporal' => 'un albaran a medio componer',
        'facturaTemporal' => 'una factura a medio componer',
        'incidencia' => 'una incidencia',
        'regularizacion' => 'una regularizacion de existencias',
        'historicoPrecio' => 'un historico de precios',
    ];

    /** Trozos del nombre de la clase que revelan sobre que documento trabaja el caso. */
    private const DOCUMENTOS = [
        'Albaranes' => 'albaran',
        'Pedidos' => 'pedido',
        'Facturas' => 'factura',
        'Clientes' => 'cliente',
        'Articulos' => 'articulo',
    ];

    /** Trozos del nombre de la clase que revelan que parte del flujo ejercita. */
    private const AREAS = [
        'Guardado' => 'guardado',
        'Temporal' => 'borrador',
        'Estados' => 'estados',
        'Lectura' => 'listado',
        'Composicion' => 'importes',
        'Identidad' => 'numeracion',
        'Existencias' => 'existencias',
        'Busqueda' => 'busqueda',
        'Impresion' => 'impreso',
        'Adjuntas' => 'adjuntos',
        'Atomicidad' => 'guardado',
        'Despacho' => 'despacho',
        'Alcance' => 'busqueda',
    ];

    /**
     * El nombre del metodo convertido en frase.
     *
     * `test_defecto_venderMasDeLoDisponibleDejaElSaldoEnNegativo` queda en «vender mas de lo
     * disponible deja el saldo en negativo». Cuando el nombre empieza por el metodo del
     * producto que ejercita —`test_addAlbaranGuardado_creaLaCabecera`— ese metodo se conserva
     * delante, porque dice sobre que actua el caso.
     */
    public static function frase(string $metodo): string
    {
        $resto = preg_replace('/^test_/', '', $metodo) ?? $metodo;
        $resto = preg_replace('/^defecto_/', '', $resto) ?? $resto;
        $resto = preg_replace('/^T\d+[a-z]?_/', '', $resto) ?? $resto;

        // Un primer tramo que parece nombre de metodo del producto se conserva tal cual. Se
        // distingue de media frase porque una frase empieza por articulo o pronombre: el
        // nombre `elTotalDeLaCabecera…` no es un metodo, y presentarlo como tal lo afea.
        $prefijo = '';
        if (preg_match('/^([a-z][A-Za-z0-9]*)_(.+)$/', $resto, $c) === 1 && !self::pareceFrase($c[1])) {
            $prefijo = $c[1] . ': ';
            $resto = $c[2];
        }

        $palabras = preg_replace('/(?<!^)(?=[A-Z])/', ' ', $resto) ?? $resto;
        $palabras = strtolower(str_replace('_', ' ', $palabras));

        return $prefijo . trim(preg_replace('/\s+/', ' ', $palabras) ?? $palabras);
    }

    /**
     * Las etiquetas que se deducen del caso sin que nadie las escriba.
     *
     * @return list<string>
     */
    public static function etiquetas(string $clase, string $metodo, string $fichero): array
    {
        $etiquetas = [];

        if (str_starts_with($metodo, 'test_defecto_')) {
            $etiquetas[] = 'defecto';
        }

        $corta = self::nombreCorto($clase);

        foreach (self::DOCUMENTOS as $trozo => $etiqueta) {
            if (str_contains($corta, $trozo)) {
                $etiquetas[] = $etiqueta;
            }
        }

        foreach (self::AREAS as $trozo => $etiqueta) {
            if (str_contains($corta, $trozo)) {
                $etiquetas[] = $etiqueta;
            }
        }

        if (str_contains($fichero, '/mod_venta/')) {
            $etiquetas[] = 'venta';
        }
        if (str_contains($fichero, '/mod_reorganizacion/')) {
            $etiquetas[] = 'reorganizacion';
        }
        // Por tramo de ruta y no por `/Unit/` a secas: la ruta puede llegar relativa, sin
        // barra inicial, y entonces la comparacion literal no casaria.
        if (preg_match('#(^|/)Unit/#', $fichero) === 1) {
            $etiquetas[] = 'unitario';
        }
        if (preg_match('#(^|/)Integration/#', $fichero) === 1) {
            $etiquetas[] = 'integracion';
        }

        return array_values(array_unique($etiquetas));
    }

    /**
     * El «dado que» de un caso, a partir de los metodos de siembra que llamo.
     *
     * @param list<string> $metodos Nombres de metodo de siembra, en orden de llamada.
     */
    public static function dadoQue(array $metodos): string
    {
        $cuenta = [];
        foreach ($metodos as $metodo) {
            if (isset(self::SIEMBRA[$metodo])) {
                $cuenta[$metodo] = ($cuenta[$metodo] ?? 0) + 1;
            }
        }

        if ($cuenta === []) {
            return '';
        }

        $partes = [];
        foreach ($cuenta as $metodo => $veces) {
            $partes[] = $veces > 1 ? $veces . ' × ' . self::SIEMBRA[$metodo] : self::SIEMBRA[$metodo];
        }

        $ultima = array_pop($partes);

        return 'Se siembra ' . ($partes === [] ? $ultima : implode(', ', $partes) . ' y ' . $ultima) . '.';
    }

    /**
     * Si un tramo del nombre parece el principio de una frase y no un nombre de metodo.
     *
     * Los metodos del producto empiezan por verbo —`addAlbaranGuardado`, `buscarPedido`—;
     * las frases, por articulo o pronombre.
     */
    private static function pareceFrase(string $tramo): bool
    {
        $arranques = ['el', 'la', 'los', 'las', 'un', 'una', 'unos', 'unas', 'se', 'no', 'con',
                      'sin', 'por', 'para', 'dos', 'tres', 'cada', 'lo', 'al', 'del', 'mismo',
                      'cuando', 'si', 'que', 'ninguna', 'ningun', 'todo', 'toda'];

        foreach ($arranques as $arranque) {
            // El arranque cuenta solo si acaba ahi: `una` en `unaLinea` si, pero `un` en
            // `unificar` no, porque lo que sigue no empieza por mayuscula.
            if (preg_match('/^' . $arranque . '(?=[A-Z])/', $tramo) === 1) {
                return true;
            }
        }

        return false;
    }

    /** El nombre de la clase sin su espacio de nombres. */
    public static function nombreCorto(string $clase): string
    {
        $partes = explode('\\', $clase);

        return end($partes) ?: $clase;
    }
}

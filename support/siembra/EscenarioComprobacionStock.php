<?php
/**
 * Escenarios de la comprobacion de existencias en el cambio de ano.
 *
 * Un escenario declara **una historia de producto**: lo que le ocurre en el ejercicio en
 * que se aplica, con sus movimientos, sus fechas y sus estados. Cada uno se identifica
 * por un codigo neutro —`E01`, `E02`…— y ese codigo es toda su identidad: la traduccion
 * del escenario a lo que significa vive fuera de este repositorio, no aqui.
 *
 * Por que existe este fichero. Hasta ahora cada caso de prueba componia su propia
 * historia dentro de si mismo. Eso funciona mientras la historia empieza y acaba en una
 * base, y deja de funcionar en cuanto el sistema compara dos ejercicios que viven en
 * bases distintas: las dos siembras habria que coordinarlas a mano, y nada garantizaria
 * que siguen coordinadas manana. Declarando la historia una vez, las dos siembras se
 * derivan de la misma fuente.
 *
 * **Los dos modos de aplicacion.** Un escenario se aplica de dos maneras, y el que llama
 * decide cual:
 *
 *  - Dentro de la transaccion de un caso de prueba, que la deshace al terminar. El
 *    identificador del producto lo pone el auto-incremento: al caso le da igual cual sea.
 *  - De forma persistente sobre las dos bases, para los recorridos de navegador y para el
 *    puente entre ejercicios. Ahi el identificador **se fija**, porque el emparejamiento
 *    entre ejercicios es por identificador y dos bases con auto-incremento propio no
 *    coinciden por si solas.
 *
 * Solo se aplican de forma persistente los escenarios que cruzan de un ejercicio a otro.
 * Los demas se aplican en transaccion, y por eso no colisionan nunca consigo mismos.
 *
 * **El ejercicio no se escribe en las fechas.** Lo trae el constructor, de modo que el
 * mismo escenario vale para cualquier par de anos consecutivos. Ninguna fecha sale de los
 * dos ejercicios que las bases representan.
 */

declare(strict_types=1);

namespace TPVFox\Test\Siembra;

use RuntimeException;

final class EscenarioComprobacionStock
{
    /**
     * Los escenarios que se siembran de forma persistente en las dos bases.
     *
     * Son tres: el producto del puente, que existe en las dos con el mismo identificador;
     * el que se da de alta entre ejercicios, que existe en una y en la otra no; y el
     * traspaso duplicado. Todos los demas empiezan y acaban en una base, se aplican dentro
     * de la transaccion de un caso y no necesitan identificador estable.
     */
    public const QUE_CRUZAN = ['E53', 'E54', 'E55'];

    /**
     * La tienda por la que el cierre del ejercicio selecciona los productos.
     *
     * No es la tienda en que se opera: el cierre pregunta siempre por la primera, y por
     * eso un escenario que quiera decir «el cierre lo habria tomado» —o lo contrario—
     * tiene que poner o dejar de poner la existencia justo ahi.
     */
    private const TIENDA_DEL_CIERRE = 1;

    /** De donde salen los identificadores fijos, por debajo del auto-incremento del esquema. */
    private const IDENTIFICADOR_BASE = 9000;
    private const IDENTIFICADOR_SEGUNDO = 9100;

    /**
     * @param string $papel             'vigente' o 'anterior'; un escenario del otro se niega
     * @param string $ano               Ejercicio de la base sobre la que se siembra, en cuatro cifras
     * @param bool   $identificadorFijo Solo en la siembra persistente
     */
    public function __construct(
        private Siembra $siembra,
        private string $papel,
        private string $ano,
        private bool $identificadorFijo = false
    ) {
    }

    // =========================================================================
    // Ejercicio vigente — la trayectoria y sus extremos
    // =========================================================================

    /** Abre el ejercicio ya debiendo existencias y no se mueve en todo el periodo. */
    public function E01(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E01', 'Producto con arrastre negativo');
        $this->siembra->ventaTicket($id, 5.0, $this->dia('01-01'));

        return ['idArticulo' => $id];
    }

    /**
     * Recibe del proveedor de cierre dentro y fuera de la frontera de apertura.
     *
     * Lo de dentro es saldo de partida y lo de fuera es movimiento del ejercicio: quien
     * lo decide es la fecha, no el proveedor.
     */
    public function E02(int $idProveedorCierre): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E02', 'Producto con traspaso y compra tardia');
        $this->siembra->albaranDeApertura([$id => 8.0], $this->ano, $idProveedorCierre);
        $this->siembra->entradaProveedor($id, 3.0, $this->dia('01-05'), ['idProveedor' => $idProveedorCierre]);
        $this->siembra->ventaTicket($id, 20.0, $this->dia('01-10'));

        return ['idArticulo' => $id];
    }

    /** Pertenece a una familia que la configuracion deja fuera del cierre. */
    public function E03(): array
    {
        $this->exigePapel('vigente');
        $idFamilia = $this->siembra->familia('Familia de prueba excluida');
        $id = $this->articulo('E03', 'Producto de familia excluida', ['familia' => $idFamilia]);
        $this->siembra->ventaTicket($id, 4.0, $this->dia('01-01'));

        return ['idArticulo' => $id, 'idFamilia' => $idFamilia];
    }

    /** Sin familia alguna, para cuando la configuracion nombra una que no existe. */
    public function E04(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E04', 'Producto de familia sin configurar');
        $this->siembra->ventaTicket($id, 4.0, $this->dia('01-01'));

        return ['idArticulo' => $id];
    }

    /** Sin existencia registrada en la tienda principal: el cierre nunca lo habria tomado. */
    public function E05(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E05', 'Producto nunca incluido en el cierre');
        $this->siembra->ventaTicket($id, 4.0, $this->dia('01-01'));

        return ['idArticulo' => $id];
    }

    /** Con existencia registrada en la tienda principal: el cierre si lo habria tomado. */
    public function E06(): array
    {
        $this->exigePapel('vigente');
        $this->siembra->tiendaConId(self::TIENDA_DEL_CIERRE, $this->ano);
        $id = $this->articulo('E06', 'Producto si incluido en el cierre');
        $this->siembra->ventaTicket($id, 40.0, $this->dia('01-01'));
        $this->siembra->existenciaRegistrada($id, 5.0, self::TIENDA_DEL_CIERRE);

        return ['idArticulo' => $id];
    }

    /** Su punto mas bajo cae pocos dias antes del corte. */
    public function E07(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E07', 'Producto con minimo reciente');
        $this->siembra->ventaTicket($id, 6.0, $this->dia('01-20'));

        return ['idArticulo' => $id];
    }

    /** Le regularizaron existencias a mitad del periodo. */
    public function E08(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E08', 'Producto regularizado en el periodo');
        $this->siembra->ventaTicket($id, 4.0, $this->dia('01-01'));
        $this->siembra->regularizacion($id, $this->dia('01-15') . ' 10:00:00', -2.0);

        return ['idArticulo' => $id];
    }

    /** No llega a deber existencias en ningun momento. */
    public function E09(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E09', 'Producto siempre en positivo');
        $this->siembra->entradaProveedor($id, 100.0, $this->dia('01-05'));
        $this->siembra->ventaTicket($id, 10.0, $this->dia('01-10'));

        return ['idArticulo' => $id];
    }

    /** Lo que recibio al abrir sostiene el saldo por encima de cero durante todo el periodo. */
    public function E10(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E10', 'Producto sostenido por la apertura');
        $this->siembra->entradaProveedor($id, 50.0, $this->dia('01-01'));
        $this->siembra->ventaTicket($id, 10.0, $this->dia('01-05'));

        return ['idArticulo' => $id];
    }

    /** Su recepcion quedo marcada como exportada, y sigue siendo una recepcion real. */
    public function E11(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E11', 'Producto con recepcion exportada');
        $this->siembra->entradaProveedor($id, 10.0, $this->dia('03-01'), ['estado' => 'Exportado']);
        $this->siembra->ventaTicket($id, 12.0, $this->dia('03-05'));

        return ['idArticulo' => $id];
    }

    /** Baja al negativo, para poder alterarle despues la existencia registrada. */
    public function E12(): array
    {
        $this->exigePapel('vigente');
        $this->siembra->tiendaConId(self::TIENDA_DEL_CIERRE, $this->ano);
        $id = $this->articulo('E12', 'Producto con existencia registrada enganosa');
        $this->siembra->ventaTicket($id, 5.0, $this->dia('01-05'));

        return ['idArticulo' => $id, 'idTiendaDelCierre' => self::TIENDA_DEL_CIERRE];
    }

    /**
     * Dos productos iguales cuya apertura se fecha con las dos convenciones que se usan.
     *
     * Unas instalaciones la fechan el ultimo dia del ejercicio anterior y otras el primero
     * del vigente; la trayectoria tiene que arrancar igual con las dos.
     */
    public function E13(int $idProveedorCierre): array
    {
        $this->exigePapel('vigente');
        $conCierre = $this->articulo('E13', 'Producto con apertura fechada el ultimo dia');
        $conApertura = $this->articuloSecundario('E13', 'Producto con apertura fechada el primer dia');

        $this->siembra->albaranDeCierre([$conCierre => 6.0], $this->anoAnterior(), $idProveedorCierre);
        $this->siembra->albaranDeApertura([$conApertura => 6.0], $this->ano, $idProveedorCierre);

        foreach ([$conCierre, $conApertura] as $id) {
            $this->siembra->ventaTicket($id, 9.0, $this->dia('02-10'));
        }

        return ['idArticulos' => [$conCierre, $conApertura]];
    }

    /** Dado de baja en el catalogo, y con trayectoria negativa igualmente. */
    public function E14(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E14', 'Producto dado de baja', ['estado' => 'Baja']);
        $this->siembra->ventaTicket($id, 3.0, $this->dia('01-05'));

        return ['idArticulo' => $id];
    }

    /** Abre debiendo existencias y a partir de ahi solo recibe: su punto mas bajo es la apertura. */
    public function E15(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E15', 'Producto que abre en negativo y solo recibe');
        $this->siembra->ventaTicket($id, 3.0, $this->dia('01-01'));
        $this->siembra->entradaProveedor($id, 10.0, $this->dia('03-01'));

        return ['idArticulo' => $id];
    }

    /** Su punto mas bajo es la apertura, sin ningun movimiento del periodo que lo explique. */
    public function E16(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E16', 'Producto con minimo en la apertura');
        $this->siembra->ventaTicket($id, 4.0, $this->dia('01-01'));
        $this->siembra->entradaProveedor($id, 1.0, $this->dia('01-21'));

        return ['idArticulo' => $id];
    }

    /** Le regularizaron existencias el primer dia del ejercicio. */
    public function E17(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E17', 'Producto regularizado el primer dia');
        $this->siembra->ventaTicket($id, 4.0, $this->dia('01-05'));
        $this->siembra->regularizacion($id, $this->dia('01-01') . ' 09:00:00', -2.0);

        return ['idArticulo' => $id];
    }

    /**
     * Reune las cuatro circunstancias conocidas sobre un mismo producto.
     *
     * De familia excluida, sin existencia registrada en la principal, con el minimo
     * dentro de la ventana y regularizado en el periodo.
     */
    public function E18(): array
    {
        $this->exigePapel('vigente');
        $idFamilia = $this->siembra->familia('Familia excluida con producto en negativo');
        $id = $this->articulo('E18', 'Producto con las cuatro condiciones', ['familia' => $idFamilia]);
        $this->siembra->ventaTicket($id, 6.0, $this->dia('01-20'));
        $this->siembra->regularizacion($id, $this->dia('01-15') . ' 10:00:00', -1.0);

        return ['idArticulo' => $id, 'idFamilia' => $idFamilia];
    }

    /** Tiene existencias registradas, pero solo en una tienda que no es la principal. */
    public function E19(): array
    {
        $this->exigePapel('vigente');
        // La tienda del cierre existe y este producto no tiene existencia en ella; la
        // que tiene esta en otra, que es exactamente lo que el cierre no habria tomado.
        $this->siembra->tiendaConId(self::TIENDA_DEL_CIERRE, $this->ano);
        $otra = $this->siembra->tienda($this->ano, 'secundaria');
        $id = $this->articulo('E19', 'Producto con existencia solo en la segunda tienda');
        $this->siembra->ventaTicket($id, 4.0, $this->dia('01-05'));
        $this->siembra->existenciaRegistrada($id, 50.0, $otra);

        return ['idArticulo' => $id, 'idTienda' => $otra];
    }

    // =========================================================================
    // Ejercicio vigente — la frontera con el componente que detecta negativos
    // =========================================================================

    /** Debe existencias sin mas: el caso mas simple que el componente consumido examina. */
    public function E20(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E20', 'Producto con hallazgo del componente consumido');
        $this->siembra->ventaTicket($id, 5.0, $this->dia('03-05'));

        return ['idArticulo' => $id];
    }

    /**
     * El componente consumido lo senala y la trayectoria de aqui nunca baja de cero.
     *
     * La recepcion exportada cuenta aqui y no alli, de modo que las dos curvas discrepan.
     */
    public function E21(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E21', 'Producto que solo el componente consumido senala');
        $this->siembra->entradaProveedor($id, 20.0, $this->dia('03-01'), ['estado' => 'Exportado']);
        $this->siembra->ventaTicket($id, 12.0, $this->dia('03-05'));

        return ['idArticulo' => $id];
    }

    /** La misma discrepancia, pero terminando en positivo tras haber estado en negativo. */
    public function E22(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E22', 'Producto con dos lecturas de la misma trayectoria');
        $this->siembra->entradaProveedor($id, 10.0, $this->dia('03-01'), ['estado' => 'Exportado']);
        $this->siembra->ventaTicket($id, 12.0, $this->dia('03-05'));
        $this->siembra->entradaProveedor($id, 5.0, $this->dia('03-10'));

        return ['idArticulo' => $id];
    }

    /** Un unico punto bajo, para leerlo hasta dos fechas de corte distintas. */
    public function E23(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E23', 'Producto leido hasta dos fechas distintas');
        $this->siembra->ventaTicket($id, 6.0, $this->dia('01-20'));

        return ['idArticulo' => $id];
    }

    /** Una familia sin producto alguno, para las lecturas que no llegan a preguntar. */
    public function E24(): array
    {
        $this->exigePapel('vigente');

        return ['idFamilia' => $this->siembra->familia('Familia excluida de la comprobacion')];
    }

    /** Se registra por peso y no tiene ningun movimiento. */
    public function E25(): array
    {
        $this->exigePapel('vigente');

        return ['idArticulo' => $this->articulo('E25', 'Producto de peso para la consulta', ['tipo' => 'peso'])];
    }

    /** Cierra el periodo debiendo tres decimas, con una sola venta. */
    public function E26(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E26', 'Producto con negativo decimal');
        $this->siembra->entradaProveedor($id, 0.7, $this->dia('03-01'));
        $this->siembra->ventaTicket($id, 1.0, $this->dia('03-02'));

        return ['idArticulo' => $id];
    }

    /** Toca el fondo el segundo dia y se repone el quinto. */
    public function E27(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E27', 'Producto que toca negativo y se repone tres dias despues');
        $this->siembra->entradaProveedor($id, 5.0, $this->dia('03-01'));
        $this->siembra->ventaTicket($id, 8.0, $this->dia('03-02'));
        $this->siembra->entradaProveedor($id, 5.0, $this->dia('03-05'));

        return ['idArticulo' => $id];
    }

    /** Toca el fondo el segundo dia y se repone al siguiente. */
    public function E28(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E28', 'Producto que toca negativo y se recupera');
        $this->siembra->entradaProveedor($id, 5.0, $this->dia('03-01'));
        $this->siembra->ventaTicket($id, 8.0, $this->dia('03-02'));
        $this->siembra->entradaProveedor($id, 5.0, $this->dia('03-03'));

        return ['idArticulo' => $id];
    }

    // =========================================================================
    // Ejercicio anterior — la reconstruccion del historico
    // =========================================================================

    /**
     * Dos lotes de recepcion, el primero de los cuales cierra en negativo.
     *
     * Con los dos albaranes de frontera puestos, que quedan fuera de la reconstruccion.
     */
    public function E29(int $idProveedorCierre, int $idProveedorHabitual): array
    {
        $this->exigePapel('anterior');
        $id = $this->articulo('E29', 'Producto con lote negativo intermedio');

        $this->siembra->albaranDeApertura([$id => 999.0], $this->ano, $idProveedorCierre);
        $this->siembra->albaranDeCierre([$id => -999.0], $this->ano, $idProveedorCierre);

        $this->dosLotes($id, $idProveedorHabitual);

        return ['idArticulo' => $id];
    }

    /** No recibio nada en todo el ejercicio: no hay lote que reconstruir. */
    public function E30(): array
    {
        $this->exigePapel('anterior');
        $id = $this->articulo('E30', 'Producto sin recepciones en el anterior');
        $this->siembra->ventaTicket($id, 3.0, $this->dia('04-01'));

        return ['idArticulo' => $id];
    }

    /** Se registra por peso y sale en tres ventas del mismo lote. */
    public function E31(int $idProveedorHabitual): array
    {
        $this->exigePapel('anterior');
        $id = $this->articulo('E31', 'Producto de peso', ['tipo' => 'peso']);
        $this->siembra->entradaProveedor($id, 30.0, $this->dia('03-01'), ['idProveedor' => $idProveedorHabitual]);
        foreach (['03-02', '03-03', '03-04'] as $dia) {
            $this->siembra->ventaTicket($id, 5.0, $this->dia($dia));
        }

        return ['idArticulo' => $id];
    }

    /** No se registra por peso: no hay margen que absorba diferencia alguna. */
    public function E32(int $idProveedorHabitual): array
    {
        $this->exigePapel('anterior');
        $id = $this->articulo('E32', 'Producto por unidad', ['tipo' => 'unidad']);
        $this->siembra->entradaProveedor($id, 10.0, $this->dia('03-01'), ['idProveedor' => $idProveedorHabitual]);
        $this->siembra->ventaTicket($id, 5.0, $this->dia('03-02'));

        return ['idArticulo' => $id];
    }

    /** Su unica salida es por albaran de cliente y no por ticket. */
    public function E33(int $idProveedorHabitual): array
    {
        $this->exigePapel('anterior');
        $id = $this->articulo('E33', 'Producto con salida por albaran de cliente');
        $this->siembra->entradaProveedor($id, 20.0, $this->dia('03-01'), ['idProveedor' => $idProveedorHabitual]);
        $this->siembra->ventaAlbaranCliente($id, 5.0, $this->dia('03-05'));

        return ['idArticulo' => $id];
    }

    /**
     * Se le compra corrientemente al mismo proveedor con el que se identifica el traspaso.
     *
     * Los dos albaranes de frontera quedan fuera y la compra de mitad de ejercicio no: si
     * la exclusion barriera al proveedor entero, este producto no tendria ninguna recepcion.
     */
    public function E34(int $idProveedorCierre): array
    {
        $this->exigePapel('anterior');
        $id = $this->articulo('E34', 'Producto comprado tambien al proveedor del traspaso');

        $this->siembra->albaranDeApertura([$id => 999.0], $this->ano, $idProveedorCierre);
        $this->siembra->albaranDeCierre([$id => -999.0], $this->ano, $idProveedorCierre);

        $this->siembra->entradaProveedor($id, 20.0, $this->dia('05-10'), ['idProveedor' => $idProveedorCierre]);
        $this->siembra->ventaTicket($id, 5.0, $this->dia('05-20'));

        return ['idArticulo' => $id];
    }

    /** Entra por la tienda principal y sale por otra. */
    public function E35(int $idProveedorHabitual): array
    {
        $this->exigePapel('anterior');
        $principal = $this->siembra->tiendaPorDefecto();
        $otra = $this->siembra->tienda($this->ano, 'secundaria');
        $id = $this->articulo('E35', 'Producto con movimiento en dos tiendas');

        $this->siembra->entradaProveedor($id, 30.0, $this->dia('03-01'), [
            'idProveedor' => $idProveedorHabitual,
            'idTienda'    => $principal,
        ]);
        $this->siembra->ventaTicket($id, 12.0, $this->dia('03-10'), ['idTienda' => $otra]);

        return ['idArticulo' => $id, 'idTiendaPrincipal' => $principal, 'idTiendaSecundaria' => $otra];
    }

    /** Sin recepciones, para la fila que ademas llega con circunstancias del otro ejercicio. */
    public function E36(): array
    {
        $this->exigePapel('anterior');
        $id = $this->articulo('E36', 'Producto con condicion traida del otro ejercicio');
        $this->siembra->ventaTicket($id, 3.0, $this->dia('04-01'));

        return ['idArticulo' => $id];
    }

    // =========================================================================
    // Ejercicio anterior — la clasificacion
    // =========================================================================

    /** Sin recepciones, para el estado que no se puede comparar. */
    public function E37(): array
    {
        $this->exigePapel('anterior');
        $id = $this->articulo('E37', 'Producto sin recepciones para clasificar');
        $this->siembra->ventaTicket($id, 3.0, $this->dia('04-01'));

        return ['idArticulo' => $id];
    }

    /** Dos lotes que reconstruyen diez, para contrastarlos con lo que se le exige. */
    public function E38(int $idProveedorHabitual): array
    {
        $this->exigePapel('anterior');
        $id = $this->articulo('E38', 'Producto con lote negativo intermedio para clasificar');
        $this->dosLotes($id, $idProveedorHabitual);

        return ['idArticulo' => $id];
    }

    /** La misma reconstruccion, para el caso en que se le exige menos de lo que traspaso. */
    public function E39(int $idProveedorHabitual): array
    {
        $this->exigePapel('anterior');
        $id = $this->articulo('E39', 'Producto que traspaso mas de lo que se le exige');
        $this->dosLotes($id, $idProveedorHabitual);

        return ['idArticulo' => $id];
    }

    /** La misma reconstruccion, para el caso en que se le exige mas de lo que sostiene. */
    public function E40(int $idProveedorHabitual): array
    {
        $this->exigePapel('anterior');
        $id = $this->articulo('E40', 'Producto al que se le exige mas de lo reconstruido');
        $this->dosLotes($id, $idProveedorHabitual);

        return ['idArticulo' => $id];
    }

    /**
     * Dos productos de peso sembrados exactamente igual.
     *
     * Los dos reconstruyen quince con el mismo margen; lo unico que va a cambiar es
     * cuanto se les exige, de modo que quien decida entre ellos sera el margen.
     */
    public function E41(int $idProveedorHabitual): array
    {
        $this->exigePapel('anterior');
        $dentro = $this->articulo('E41', 'Producto de peso dentro del margen', ['tipo' => 'peso']);
        $fuera = $this->articuloSecundario('E41', 'Producto de peso fuera del margen', ['tipo' => 'peso']);

        foreach ([$dentro, $fuera] as $id) {
            $this->siembra->entradaProveedor($id, 30.0, $this->dia('03-01'), ['idProveedor' => $idProveedorHabitual]);
            foreach (['03-02', '03-03', '03-04'] as $dia) {
                $this->siembra->ventaTicket($id, 5.0, $this->dia($dia));
            }
        }

        return ['idArticulos' => [$dentro, $fuera]];
    }

    /**
     * Esta en el catalogo, sin movimiento alguno: el que llega del otro lado tiene contraparte.
     *
     * Sin guarda de papel a proposito. Lo que declara no es una historia de un ejercicio
     * sino una presencia en el catalogo, y el emparejamiento se comprueba mirando eso y
     * nada mas.
     */
    public function E42(): array
    {
        return ['idArticulo' => $this->articulo('E42', 'Producto con contraparte en el catalogo')];
    }

    // =========================================================================
    // Los estados que deciden si un movimiento cuenta
    // =========================================================================

    /**
     * Su recepcion esta facturada, que es uno de los estados que si cuentan.
     *
     * La factura se emite de verdad, con sus lineas y su enlace: es el unico camino por
     * el que el producto deja un albaran en ese estado.
     */
    public function E43(): array
    {
        $id = $this->articulo('E43', 'Producto con recepcion facturada');
        $idAlbaran = $this->siembra->entradaProveedor($id, 10.0, $this->dia('03-01'));
        $this->siembra->facturarAlbaranProveedor($idAlbaran);
        $this->siembra->ventaTicket($id, 12.0, $this->dia('03-05'));

        return ['idArticulo' => $id, 'idAlbaran' => $idAlbaran];
    }

    /** Su salida va por un albaran de cliente ya procesado, que es el otro estado que cuenta. */
    public function E44(): array
    {
        $id = $this->articulo('E44', 'Producto con salida en albaran procesado');
        $this->siembra->entradaProveedor($id, 20.0, $this->dia('03-01'));
        $idAlbaran = $this->siembra->ventaAlbaranCliente($id, 25.0, $this->dia('03-05'));
        $this->siembra->facturarAlbaranCliente($idAlbaran);

        return ['idArticulo' => $id, 'idAlbaran' => $idAlbaran];
    }

    /** Su recepcion quedo sin guardar: no cuenta, y sin ella el producto queda debiendo. */
    public function E45(): array
    {
        $id = $this->articulo('E45', 'Producto con recepcion sin guardar');
        $this->siembra->entradaProveedor($id, 100.0, $this->dia('03-01'), ['estado' => 'Sin Guardar']);
        $this->siembra->ventaTicket($id, 5.0, $this->dia('03-05'));

        return ['idArticulo' => $id];
    }

    /** Su venta esta en un ticket abierto: no cuenta, y sin ella el producto no debe nada. */
    public function E46(): array
    {
        $id = $this->articulo('E46', 'Producto con venta en ticket abierto');
        $this->siembra->entradaProveedor($id, 10.0, $this->dia('03-01'));
        $this->siembra->ventaTicket($id, 50.0, $this->dia('03-05'), ['estado' => 'Abierto']);

        return ['idArticulo' => $id];
    }

    /** Su salida va por un albaran de cliente sin guardar: no cuenta. */
    public function E47(): array
    {
        $id = $this->articulo('E47', 'Producto con salida en albaran sin guardar');
        $this->siembra->entradaProveedor($id, 10.0, $this->dia('03-01'));
        $this->siembra->ventaAlbaranCliente($id, 50.0, $this->dia('03-05'), ['estado' => 'Sin Guardar']);

        return ['idArticulo' => $id];
    }

    /** La linea de su recepcion esta eliminada: no cuenta, aunque el albaran si este. */
    public function E48(): array
    {
        $id = $this->articulo('E48', 'Producto con linea de recepcion eliminada');
        $this->siembra->entradaProveedor($id, 100.0, $this->dia('03-01'), ['estadoLinea' => 'Eliminado']);
        $this->siembra->ventaTicket($id, 5.0, $this->dia('03-05'));

        return ['idArticulo' => $id];
    }

    /** La linea de su venta esta eliminada: no cuenta, aunque el ticket este cerrado. */
    public function E49(): array
    {
        $id = $this->articulo('E49', 'Producto con linea de venta eliminada');
        $this->siembra->entradaProveedor($id, 10.0, $this->dia('03-01'));
        $this->siembra->ventaTicket($id, 50.0, $this->dia('03-05'), ['estadoLinea' => 'Eliminado']);

        return ['idArticulo' => $id];
    }

    /** La linea de su albaran de cliente esta eliminada: no cuenta. */
    public function E50(): array
    {
        $id = $this->articulo('E50', 'Producto con linea de albaran de cliente eliminada');
        $this->siembra->entradaProveedor($id, 10.0, $this->dia('03-01'));
        $this->siembra->ventaAlbaranCliente($id, 50.0, $this->dia('03-05'), ['estadoLinea' => 'Eliminado']);

        return ['idArticulo' => $id];
    }

    /**
     * Le devuelven mercancia sin que a su alrededor haya ninguna venta.
     *
     * Es una entrada por un origen de salida, y llega sin lote abierto al que imputarse.
     */
    public function E51(): array
    {
        $id = $this->articulo('E51', 'Producto con devolucion aislada');
        $this->siembra->entradaProveedor($id, 10.0, $this->dia('03-01'));
        $this->siembra->devolucionTicket($id, 3.0, $this->dia('03-10'));

        return ['idArticulo' => $id];
    }

    /** Se registra por peso y sale en muchas operaciones pequenas. */
    public function E52(int $idProveedorHabitual): array
    {
        $id = $this->articulo('E52', 'Producto de peso con muchas ventas', ['tipo' => 'peso']);
        $this->siembra->entradaProveedor($id, 50.0, $this->dia('03-01'), ['idProveedor' => $idProveedorHabitual]);
        for ($dia = 2; $dia <= 21; $dia++) {
            $this->siembra->ventaTicket($id, 1.0, $this->dia(sprintf('03-%02d', $dia)));
        }

        return ['idArticulo' => $id];
    }

    // =========================================================================
    // Los que cruzan de un ejercicio a otro
    // =========================================================================

    /**
     * Se da de alta entre ejercicios: existe en el vigente y no en el anterior.
     *
     * Solo se siembra en el vigente. Al otro lado su ausencia es lo que se comprueba, y
     * por eso el identificador tiene que ser el mismo en las dos bases.
     */
    public function E53(): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E53', 'Producto dado de alta entre ejercicios');
        $this->siembra->ventaTicket($id, 4.0, $this->dia('03-05'));

        return ['idArticulo' => $id];
    }

    /** Recibe dos aperturas del mismo traspaso, que es lo que no deberia poder ocurrir. */
    public function E54(int $idProveedorCierre): array
    {
        $this->exigePapel('vigente');
        $id = $this->articulo('E54', 'Producto con traspaso duplicado');
        $this->siembra->albaranDeApertura([$id => 12.0], $this->ano, $idProveedorCierre);
        $this->siembra->albaranDeApertura([$id => 12.0], $this->ano, $idProveedorCierre);
        $this->siembra->ventaTicket($id, 30.0, $this->dia('03-05'));

        return ['idArticulo' => $id];
    }

    /**
     * El producto del puente: el que recorre los dos ejercicios de extremo a extremo.
     *
     * En el vigente debe existencias al corte, y en el anterior tiene un historico
     * reconstruible con el que compararlo. Es el que atraviesan los dos recorridos de
     * navegador, y el unico cuya identidad tiene que coincidir en las dos bases para que
     * el emparejamiento signifique algo.
     */
    public function E55(int $idProveedorCierre, ?int $idProveedorHabitual = null): array
    {
        $id = $this->articulo('E55', 'Producto del puente entre ejercicios');

        if ($this->papel === 'vigente') {
            $this->siembra->albaranDeApertura([$id => 3.0], $this->ano, $idProveedorCierre);
            $this->siembra->ventaTicket($id, 11.0, $this->dia('03-05'));
            $this->siembra->entradaProveedor($id, 3.0, $this->dia('03-20'));
            $this->siembra->existenciaRegistrada($id, 3.0);

            return ['idArticulo' => $id];
        }

        $habitual = $idProveedorHabitual ?? $this->siembra->proveedor('Proveedor habitual del puente');
        $this->siembra->entradaProveedor($id, 20.0, $this->dia('03-01'), ['idProveedor' => $habitual]);
        $this->siembra->ventaTicket($id, 12.0, $this->dia('03-10'));
        $this->siembra->albaranDeCierre([$id => 8.0], $this->ano, $idProveedorCierre);

        return ['idArticulo' => $id, 'proveedorHabitual' => $habitual];
    }

    /**
     * Aplica un escenario por su codigo.
     *
     * Lo necesita quien recorre una lista de codigos —la siembra persistente— en lugar de
     * nombrar cada escenario. Un caso de prueba llama al metodo directamente, que se lee
     * mejor y falla antes si el codigo no existe.
     *
     * @param scalar ...$argumentos Lo que el escenario pida, en su orden
     */
    public function aplicar(string $escenario, ...$argumentos): array
    {
        if (!method_exists($this, $escenario) || !preg_match('/^E\d{2}$/', $escenario)) {
            throw new RuntimeException("No hay ningun escenario «{$escenario}».");
        }

        // Cada escenario pide lo que necesita y no todos piden lo mismo. Quien recorre una
        // lista los llama a todos igual, de modo que aqui se recortan los argumentos a los
        // que el escenario declara en vez de confiar en que sobren sin consecuencia.
        $cuantos = (new \ReflectionMethod($this, $escenario))->getNumberOfParameters();

        return $this->$escenario(...array_slice($argumentos, 0, $cuantos));
    }

    // --- Interno ------------------------------------------------------------

    /**
     * Dos lotes de recepcion: el primero cierra debiendo una unidad y el segundo con diez.
     *
     * Es la historia que sostiene el corte de la reconstruccion, y la comparten varios
     * escenarios porque lo que cambia entre ellos no es el historico sino lo que se les
     * exige desde el otro ejercicio.
     */
    private function dosLotes(int $idArticulo, int $idProveedorHabitual): void
    {
        $this->siembra->entradaProveedor($idArticulo, 24.0, $this->dia('06-25'), ['idProveedor' => $idProveedorHabitual]);
        $this->siembra->ventaTicket($idArticulo, 25.0, $this->dia('07-15'));
        $this->siembra->entradaProveedor($idArticulo, 24.0, $this->dia('11-12'), ['idProveedor' => $idProveedorHabitual]);
        $this->siembra->ventaTicket($idArticulo, 14.0, $this->dia('12-01'));
    }

    /**
     * El identificador que un escenario da a su producto cuando la siembra es persistente.
     *
     * Publico porque fuera de la siembra hay quien necesita nombrar ese producto sin
     * sembrarlo: el fichero de ejemplo que suben los recorridos de navegador declara un
     * producto concreto, y tiene que ser el mismo que hay en la base.
     */
    public static function identificadorFijoDe(string $escenario): int
    {
        return self::IDENTIFICADOR_BASE + (int) substr($escenario, 1);
    }

    /** El articulo del escenario, con identificador fijo solo si la siembra es persistente. */
    private function articulo(string $escenario, string $nombre, array $opciones = []): int
    {
        if ($this->identificadorFijo) {
            $opciones['id'] = self::identificadorFijoDe($escenario);
        }

        return $this->siembra->articulo($nombre, $opciones);
    }

    /** El segundo articulo de un escenario que necesita dos iguales. */
    private function articuloSecundario(string $escenario, string $nombre, array $opciones = []): int
    {
        if ($this->identificadorFijo) {
            $opciones['id'] = self::IDENTIFICADOR_SEGUNDO + $this->ordinal($escenario);
        }

        return $this->siembra->articulo($nombre, $opciones);
    }

    private function ordinal(string $escenario): int
    {
        return (int) substr($escenario, 1);
    }

    /** Una fecha del ejercicio sobre el que se siembra, dado el dia en formato «mm-dd». */
    private function dia(string $mesYDia): string
    {
        return $this->ano . '-' . $mesYDia;
    }

    private function anoAnterior(): string
    {
        return (string) ((int) $this->ano - 1);
    }

    /**
     * Un escenario del otro ejercicio no se siembra por descuido.
     *
     * Aplicar en el vigente una historia que describe al anterior no falla: siembra algo
     * coherente en el sitio equivocado, y el caso que lo consuma afirma sobre datos que
     * no son los suyos.
     */
    private function exigePapel(string $papel): void
    {
        if ($this->papel !== $papel) {
            throw new RuntimeException(
                "Este escenario describe el ejercicio «{$papel}» y se esta sembrando sobre «{$this->papel}»."
            );
        }
    }
}

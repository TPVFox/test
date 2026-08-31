<?php
/**
 * Generador de datos de siembra: las primitivas, comunes a todo el repositorio.
 *
 * Los datos se generan, nunca se extraen de una instalacion real. Cada caso compone el
 * escenario que necesita en lugar de partir de un juego de datos comun: asi una prueba
 * no depende de lo que otra sembro, y el escenario se lee en el propio caso.
 *
 * Los valores por defecto colocan cada movimiento en los estados que el calculo de saldo
 * de TPVFox cuenta —proveedor en 'Guardado', ticket en 'Cerrado', albaran de cliente en
 * 'Guardado', y siempre 'Activo' en la linea—. Un caso que necesite lo contrario lo pide
 * por el arreglo de opciones.
 *
 * Esta clase no sabe de ningun modulo. Lo que sabe de un modulo son los escenarios que
 * la acompanan en esta misma carpeta, y que la usan para componerse.
 *
 * **Los documentos se siembran completos.** Un albaran no es su cabecera y una linea: es
 * la cabecera con sus totales cuadrados, las lineas numeradas por su orden, el desglose
 * de bases e impuestos, y —cuando el estado lo implica— la factura que lo dejo en ese
 * estado con su enlace. Un estado puesto a mano que el producto no puede producir por
 * ningun camino no prueba nada de lo que se le pida probar.
 *
 * Tres reglas que sostienen casos concretos y que no se pueden romper sin romperlos:
 *
 *  - Ninguna siembra fecha nada fuera de los ejercicios que la base representa. Hay
 *    casos que usan un periodo lejano —1990— para afirmar que una lectura sin
 *    movimientos devuelve el conjunto vacio, y sembrar ahi los invalidaria.
 *  - Los apoyos por defecto resuelven antes de crear. La base puede llevar datos
 *    sembrados de antemano, de modo que ni la tienda ni el usuario ni el cliente
 *    pueden darse por creados en esta ejecucion ni por identificados con el numero 1.
 *  - Cada tipo de documento lleva su propia numeracion, y arranca donde la base la
 *    dejo. Una secuencia compartida entre albaranes y tickets produce documentos que
 *    ningun terminal habria emitido asi.
 */

declare(strict_types=1);

namespace TPVFox\Test\Siembra;

use mysqli;
use RuntimeException;

final class Siembra
{
    /** Referencias de apoyo, resueltas o creadas la primera vez que hacen falta. */
    private ?int $idTienda = null;
    private ?int $idUsuario = null;
    private ?int $idCliente = null;

    /**
     * Lo que esta instancia ha insertado, por tabla y en orden de insercion.
     *
     * Solo lo insertado: lo que un apoyo por defecto encontro ya puesto no entra. Es lo
     * que necesita un caso que no se aisla por transaccion y limpia lo suyo a mano, para
     * no llevarse por delante datos que ya estaban en la base.
     *
     * @var array<string,int[]>
     */
    private array $insertado = [];

    /** Ultimo numero usado por tipo de documento, sembrado desde el maximo de la tabla. */
    private array $numeracion = [];

    /** Numero de documento por identificador, para no volver a preguntarlo. */
    private array $numeroDeDocumento = [];

    /** Lineas ya puestas en cada documento, para numerarlas por su orden. */
    private array $filasDe = [];

    /** Fila de desglose de impuestos por documento y tipo, para acumular sobre ella. */
    private array $desgloseDe = [];

    /** Coste, impuesto y beneficio de cada articulo, que es de donde salen los importes. */
    private array $articulos = [];

    public function __construct(private mysqli $db)
    {
    }

    // --- Estructura ---------------------------------------------------------

    /**
     * Crea la tienda sobre la que opera el ejercicio.
     *
     * @param string $ano Ejercicio que declara la tienda, en cuatro cifras
     */
    public function tienda(string $ano, string $tipo = 'principal'): int
    {
        $id = $this->insertar('tiendas', [
            'tipoTienda'      => $tipo,
            'razonsocial'     => 'Tienda de pruebas',
            'nif'             => 'X0000000X',
            'telefono'        => '000000000',
            'estado'          => 'Activo',
            'NombreComercial' => 'Tienda de pruebas',
            'direccion'       => 'Sin direccion',
            'ano'             => $ano,
        ]);

        if ($tipo === 'principal') {
            $this->idTienda = $id;
        }

        return $id;
    }

    /**
     * La tienda con el identificador que se le pida, o la que ya lo tenga.
     *
     * Hay comportamiento del producto que pregunta por una tienda concreta en vez de por
     * la que este activa, de modo que un escenario que lo ejercite necesita poder poner
     * datos justo ahi.
     */
    public function tiendaConId(int $idTienda, string $ano, string $tipo = 'principal'): int
    {
        $existente = $this->existente(
            'SELECT idTienda FROM tiendas WHERE idTienda = ? LIMIT 1',
            [$idTienda]
        );
        if ($existente !== null) {
            return $existente;
        }

        $this->insertar('tiendas', [
            'idTienda'        => $idTienda,
            'tipoTienda'      => $tipo,
            'razonsocial'     => 'Tienda de pruebas',
            'nif'             => 'X0000000X',
            'telefono'        => '000000000',
            'estado'          => 'Activo',
            'NombreComercial' => 'Tienda de pruebas',
            'direccion'       => 'Sin direccion',
            'ano'             => $ano,
        ], $idTienda);

        return $idTienda;
    }

    public function familia(string $nombre, int $padre = 0): int
    {
        return $this->insertar('familias', [
            'familiaNombre'  => $nombre,
            'familiaPadre'   => $padre,
            'beneficiomedio' => 0,
            'mostrar_tpv'    => 1,
        ]);
    }

    /**
     * Crea un articulo del catalogo.
     *
     * La opcion `id` fija el identificador en lugar de dejarlo al auto-incremento. Solo
     * hace falta donde el mismo producto tiene que ser el mismo en las dos bases de
     * ejercicios consecutivos: el emparejamiento entre ejercicios es por identificador,
     * y dos bases con auto-incremento propio no coinciden por si solas.
     *
     * `ultimoCoste`, `iva` y `beneficio` son de donde salen los importes de toda linea en
     * que el articulo aparezca: el coste en las de entrada, y el coste mas el beneficio
     * en las de salida.
     *
     * @param array{id?:int,familia?:int,tipo?:string,estado?:string,ultimoCoste?:float,iva?:float,beneficio?:float,idProveedor?:string} $opciones
     */
    public function articulo(string $nombre, array $opciones = []): int
    {
        $coste = (float) ($opciones['ultimoCoste'] ?? 1.0);
        $iva = (float) ($opciones['iva'] ?? 0);
        $beneficio = (float) ($opciones['beneficio'] ?? 0);

        $valores = [
            'articulo_name' => $nombre,
            'estado'        => $opciones['estado'] ?? 'Activo',
            'fecha_creado'  => '2020-01-01 00:00:00',
            'ultimoCoste'   => $coste,
            'tipo'          => $opciones['tipo'] ?? 'unidad',
            'iva'           => $iva,
            'idProveedor'   => $opciones['idProveedor'] ?? null,
            'costepromedio' => $coste,
            'beneficio'     => $beneficio,
        ];

        if (isset($opciones['id'])) {
            $valores = ['idArticulo' => (int) $opciones['id']] + $valores;
        }

        $id = $this->insertar('articulos', $valores, isset($opciones['id']) ? (int) $opciones['id'] : null);

        $this->articulos[$id] = ['coste' => $coste, 'iva' => $iva, 'beneficio' => $beneficio];

        if (isset($opciones['familia'])) {
            $this->insertar('articulosFamilias', [
                'idArticulo' => $id,
                'idFamilia'  => $opciones['familia'],
            ]);
        }

        return $id;
    }

    /**
     * Fija la existencia registrada del articulo, en la tienda que se indique.
     *
     * El sistema bajo prueba no la lee, de modo que sirve para comprobar precisamente
     * que alterarla no cambia el resultado. La tienda es explicita porque hay
     * escenarios que distinguen tener existencias en la principal de tenerlas en otra.
     *
     * @param int|null $idTienda Sin valor, la tienda principal.
     */
    public function existenciaRegistrada(int $idArticulo, float $stockOn, ?int $idTienda = null): int
    {
        return $this->insertar('articulosStocks', [
            'idArticulo' => $idArticulo,
            'idTienda'   => $idTienda ?? $this->tiendaPorDefecto(),
            'stockOn'    => $stockOn,
            'stockMin'   => 0,
            'stockMax'   => 0,
        ]);
    }

    /**
     * Una regularizacion de existencias, fechada donde se indique.
     *
     * Es el tercer origen que el sistema mira para saber si un producto se toco en el
     * periodo, y el unico que no es un movimiento: no suma ni resta en la trayectoria,
     * solo consta.
     *
     * @param float    $stockModif Magnitud regularizada, con su signo
     * @param int|null $idTienda   Sin valor, la tienda principal
     */
    public function regularizacion(
        int $idArticulo,
        string $fecha,
        float $stockModif,
        ?int $idTienda = null
    ): int {
        return $this->insertar('stocksRegularizacion', [
            'idArticulo'          => $idArticulo,
            'idTienda'            => $idTienda ?? $this->tiendaPorDefecto(),
            'fechaRegularizacion' => $this->momento($fecha),
            'stockActual'         => 0,
            'stockModif'          => $stockModif,
            'stockFinal'          => $stockModif,
            'stockOperacion'      => 0,
            'idUsuario'           => $this->usuarioPorDefecto(),
            'estado'              => 1,
        ]);
    }

    // --- Movimientos --------------------------------------------------------

    /**
     * Entrada de proveedor: suma existencias.
     *
     * @param array{estado?:string,estadoLinea?:string,idProveedor?:int,idTienda?:int,suNumero?:string} $opciones
     * @return int Identificador del albaran, para anadirle mas lineas
     */
    public function entradaProveedor(int $idArticulo, float $unidades, string $fecha, array $opciones = []): int
    {
        $numero = $this->siguienteNumero('albprot', 'Numalbpro');

        $idAlbaran = $this->insertar('albprot', [
            'Numalbpro'    => $numero,
            'Su_numero'    => $opciones['suNumero'] ?? '',
            'Fecha'        => $this->momento($fecha),
            'idTienda'     => $opciones['idTienda'] ?? $this->tiendaPorDefecto(),
            'idUsuario'    => $this->usuarioPorDefecto(),
            'idProveedor'  => $opciones['idProveedor'] ?? $this->proveedorPorDefecto(),
            'estado'       => $opciones['estado'] ?? 'Guardado',
            'formaPago'    => 'Efectivo',
            'entregado'    => 0,
            'total_siniva' => 0,
            'total'        => 0,
        ]);

        $this->numeroDeDocumento['albprot:' . $idAlbaran] = $numero;
        $this->lineaEntrada($idAlbaran, $idArticulo, $unidades, $opciones);

        return $idAlbaran;
    }

    /** Anade una linea a un albaran de proveedor ya creado. */
    public function lineaEntrada(int $idAlbaran, int $idArticulo, float $unidades, array $opciones = []): int
    {
        $articulo = $this->datosArticulo($idArticulo);
        $base = $this->dinero($articulo['coste'] * $unidades);

        $id = $this->insertar('albprolinea', [
            'idalbpro'    => $idAlbaran,
            'Numalbpro'   => $this->numeroDe('albprot', 'Numalbpro', $idAlbaran),
            'idArticulo'  => $idArticulo,
            'ncant'       => $unidades,
            'nunidades'   => $unidades,
            'estadoLinea' => $opciones['estadoLinea'] ?? 'Activo',
            'ref_prov'    => '',
            'costeSiva'   => $articulo['coste'],
            'iva'         => $articulo['iva'],
            'nfila'       => $this->siguienteFila('albprot', $idAlbaran),
        ]);

        $this->cuadrar('albprot', $idAlbaran, $base, $articulo['iva']);

        return $id;
    }

    /**
     * Salida por ticket: resta existencias.
     *
     * @param array{estado?:string,estadoLinea?:string,idTienda?:int} $opciones
     * @return int Identificador del ticket, para anadirle mas lineas
     */
    public function ventaTicket(int $idArticulo, float $unidades, string $fecha, array $opciones = []): int
    {
        $numero = $this->siguienteNumero('ticketst', 'Numticket');

        $idTicket = $this->insertar('ticketst', [
            'Numticket'     => $numero,
            'Numtempticket' => $numero,
            'Fecha'         => $this->momento($fecha),
            'idTienda'      => $opciones['idTienda'] ?? $this->tiendaPorDefecto(),
            'idUsuario'     => $this->usuarioPorDefecto(),
            'idCliente'     => $this->clientePorDefecto(),
            'estado'        => $opciones['estado'] ?? 'Cerrado',
            'formaPago'     => 'Efectivo',
            'entregado'     => 0,
            'total'         => 0,
        ]);

        $this->numeroDeDocumento['ticketst:' . $idTicket] = $numero;
        $this->lineaTicket($idTicket, $idArticulo, $unidades, $opciones);

        return $idTicket;
    }

    /** Anade una linea a un ticket ya creado. */
    public function lineaTicket(int $idTicket, int $idArticulo, float $unidades, array $opciones = []): int
    {
        $articulo = $this->datosArticulo($idArticulo);
        $precioSinIva = $this->precioDeVenta($articulo);
        $base = $this->dinero($precioSinIva * $unidades);

        $id = $this->insertar('ticketslinea', [
            'idticketst'  => $idTicket,
            'Numticket'   => $this->numeroDe('ticketst', 'Numticket', $idTicket),
            'idArticulo'  => $idArticulo,
            'cref'        => '',
            'ccodbar'     => '',
            'cdetalle'    => '',
            'ncant'       => $unidades,
            'nunidades'   => $unidades,
            'precioCiva'  => $this->dinero($precioSinIva * (1 + $articulo['iva'] / 100)),
            'iva'         => $articulo['iva'],
            'nfila'       => $this->siguienteFila('ticketst', $idTicket),
            'estadoLinea' => $opciones['estadoLinea'] ?? 'Activo',
        ]);

        $this->cuadrar('ticketst', $idTicket, $base, $articulo['iva']);

        return $id;
    }

    /**
     * Salida por albaran de cliente: resta existencias.
     *
     * @param array{estado?:string,estadoLinea?:string,idTienda?:int} $opciones
     * @return int Identificador del albaran, para anadirle mas lineas
     */
    public function ventaAlbaranCliente(int $idArticulo, float $unidades, string $fecha, array $opciones = []): int
    {
        $numero = $this->siguienteNumero('albclit', 'Numalbcli');

        $idAlbaran = $this->insertar('albclit', [
            'Numalbcli' => $numero,
            'Fecha'     => $this->momento($fecha),
            'idTienda'  => $opciones['idTienda'] ?? $this->tiendaPorDefecto(),
            'idUsuario' => $this->usuarioPorDefecto(),
            'idCliente' => $this->clientePorDefecto(),
            'estado'    => $opciones['estado'] ?? 'Guardado',
            'formaPago' => 'Efectivo',
            'entregado' => 0,
            'total'     => 0,
        ]);

        $this->numeroDeDocumento['albclit:' . $idAlbaran] = $numero;
        $this->lineaAlbaranCliente($idAlbaran, $idArticulo, $unidades, $opciones);

        return $idAlbaran;
    }

    /** Anade una linea a un albaran de cliente ya creado. */
    public function lineaAlbaranCliente(int $idAlbaran, int $idArticulo, float $unidades, array $opciones = []): int
    {
        $articulo = $this->datosArticulo($idArticulo);
        $precioSinIva = $this->precioDeVenta($articulo);
        $base = $this->dinero($precioSinIva * $unidades);

        $id = $this->insertar('albclilinea', [
            'idalbcli'    => $idAlbaran,
            'Numalbcli'   => $this->numeroDe('albclit', 'Numalbcli', $idAlbaran),
            'idArticulo'  => $idArticulo,
            'ncant'       => $unidades,
            'nunidades'   => $unidades,
            'estadoLinea' => $opciones['estadoLinea'] ?? 'Activo',
            'pvpSiva'     => $precioSinIva,
            'precioCiva'  => $this->dinero($precioSinIva * (1 + $articulo['iva'] / 100)),
            'iva'         => $articulo['iva'],
            'nfila'       => $this->siguienteFila('albclit', $idAlbaran),
        ]);

        $this->cuadrar('albclit', $idAlbaran, $base, $articulo['iva']);

        return $id;
    }

    // --- Devoluciones -------------------------------------------------------
    //
    // Una devolucion no es un documento propio: es una linea de cantidad negativa en el
    // mismo origen por el que salio o entro la mercancia. Tienen nombre porque el
    // escenario se lee mejor asi, y porque una cantidad negativa suelta en una llamada
    // de venta parece un error de quien la escribio.

    /** Devolucion de un cliente, por ticket: entra mercancia por donde salio. */
    public function devolucionTicket(int $idArticulo, float $unidades, string $fecha, array $opciones = []): int
    {
        return $this->ventaTicket($idArticulo, -abs($unidades), $fecha, $opciones);
    }

    /** Devolucion de un cliente, por albaran. */
    public function devolucionAlbaranCliente(int $idArticulo, float $unidades, string $fecha, array $opciones = []): int
    {
        return $this->ventaAlbaranCliente($idArticulo, -abs($unidades), $fecha, $opciones);
    }

    /** Devolucion a un proveedor: sale mercancia por donde entro. */
    public function devolucionProveedor(int $idArticulo, float $unidades, string $fecha, array $opciones = []): int
    {
        return $this->entradaProveedor($idArticulo, -abs($unidades), $fecha, $opciones);
    }

    // --- El traspaso entre ejercicios ---------------------------------------

    /**
     * El albaran de cierre del ejercicio, tal como lo emite el producto.
     *
     * Ultimo dia del ejercicio, al proveedor de cierre, guardado, y con la serie en el
     * campo de numero propio —que es donde vive: no hay columna de serie—.
     *
     * @param array<int,float> $cantidades Unidades por identificador de articulo
     * @param int|null         $idFamilia  Cuando el cierre se reparte por familias
     */
    public function albaranDeCierre(
        array $cantidades,
        string $ano,
        int $idProveedorCierre,
        string $serie = 'C',
        ?int $idFamilia = null
    ): int {
        return $this->albaranDeFrontera(
            $cantidades,
            $ano . '-12-31',
            $idProveedorCierre,
            'Guardado',
            $serie,
            $idFamilia
        );
    }

    /**
     * El albaran de apertura del ejercicio siguiente: el cierre del anterior, importado.
     *
     * @param array<int,float> $cantidades Unidades por identificador de articulo
     */
    public function albaranDeApertura(
        array $cantidades,
        string $ano,
        int $idProveedorCierre,
        string $serie = 'A',
        ?int $idFamilia = null
    ): int {
        return $this->albaranDeFrontera(
            $cantidades,
            $ano . '-01-01',
            $idProveedorCierre,
            'Importado',
            $serie,
            $idFamilia
        );
    }

    // --- Facturacion --------------------------------------------------------

    /**
     * Factura un albaran de proveedor, que es el unico camino por el que el producto lo
     * deja en 'Facturado'.
     *
     * Crea la factura con las mismas lineas del albaran, las enlaza y cambia el estado.
     * Sembrar el estado a secas dejaria un albaran facturado sin factura: un estado que
     * ninguna instalacion puede tener, y por tanto un caso que no prueba lo que dice.
     *
     * @return int Identificador de la factura
     */
    public function facturarAlbaranProveedor(int $idAlbaran, ?string $fecha = null): int
    {
        $albaran = $this->filaDe('albprot', 'id', $idAlbaran);
        $numeroFactura = $this->siguienteNumero('facprot', 'Numfacpro');

        $idFactura = $this->insertar('facprot', [
            'Numfacpro'    => $numeroFactura,
            'Fecha'        => $this->momento($fecha ?? $albaran['Fecha']),
            'idTienda'     => (int) $albaran['idTienda'],
            'idUsuario'    => (int) $albaran['idUsuario'],
            'idProveedor'  => (int) $albaran['idProveedor'],
            'estado'       => 'Guardado',
            'formaPago'    => 'Efectivo',
            'entregado'    => 0,
            'total_siniva' => $albaran['total_siniva'],
            'total'        => $albaran['total'],
        ]);

        foreach ($this->lineasDe('albprolinea', 'idalbpro', $idAlbaran) as $linea) {
            $this->insertar('facprolinea', [
                'idfacpro'    => $idFactura,
                'Numfacpro'   => $numeroFactura,
                'idArticulo'  => (int) $linea['idArticulo'],
                'ncant'       => $linea['ncant'],
                'nunidades'   => $linea['nunidades'],
                'costeSiva'   => $linea['costeSiva'],
                'iva'         => $linea['iva'],
                'nfila'       => (int) $linea['nfila'],
                'estadoLinea' => $linea['estadoLinea'],
                'ref_prov'    => '',
                'idalbpro'    => $idAlbaran,
            ]);
        }

        $this->insertar('albprofac', [
            'idFactura'  => $idFactura,
            'numFactura' => $numeroFactura,
            'idAlbaran'  => $idAlbaran,
            'numAlbaran' => (int) $albaran['Numalbpro'],
        ]);

        $this->actualizar('albprot', 'id', $idAlbaran, ['estado' => 'Facturado']);

        return $idFactura;
    }

    /**
     * Factura un albaran de cliente, que es el unico camino por el que el producto lo
     * deja en 'Procesado'.
     *
     * @return int Identificador de la factura
     */
    public function facturarAlbaranCliente(int $idAlbaran, ?string $fecha = null): int
    {
        $albaran = $this->filaDe('albclit', 'id', $idAlbaran);
        $numeroFactura = $this->siguienteNumero('facclit', 'Numfaccli');
        $momento = $this->momento($fecha ?? $albaran['Fecha']);

        $idFactura = $this->insertar('facclit', [
            'Numfaccli'     => $numeroFactura,
            'Fecha'         => $momento,
            'idTienda'      => (int) $albaran['idTienda'],
            'idUsuario'     => (int) $albaran['idUsuario'],
            'idCliente'     => (int) $albaran['idCliente'],
            'estado'        => 'Guardado',
            'total'         => $albaran['total'],
            'fechaCreacion' => $momento,
        ]);

        foreach ($this->lineasDe('albclilinea', 'idalbcli', $idAlbaran) as $linea) {
            $this->insertar('facclilinea', [
                'idfaccli'    => $idFactura,
                'Numfaccli'   => $numeroFactura,
                'idArticulo'  => (int) $linea['idArticulo'],
                'ncant'       => $linea['ncant'],
                'nunidades'   => $linea['nunidades'],
                'pvpSiva'     => $linea['pvpSiva'],
                'precioCiva'  => $linea['precioCiva'],
                'iva'         => $linea['iva'],
                'nfila'       => (int) $linea['nfila'],
                'estadoLinea' => $linea['estadoLinea'],
                'NumalbCli'   => (int) $albaran['Numalbcli'],
            ]);
        }

        $this->insertar('albclifac', [
            'idFactura'  => $idFactura,
            'numFactura' => $numeroFactura,
            'idAlbaran'  => $idAlbaran,
            'numAlbaran' => (int) $albaran['Numalbcli'],
        ]);

        $this->actualizar('albclit', 'id', $idAlbaran, ['estado' => 'Procesado']);

        return $idFactura;
    }

    // --- Apoyos -------------------------------------------------------------

    /**
     * La tienda principal del ejercicio: la que ya haya, o una nueva si no hay ninguna.
     *
     * Los apoyos por defecto resuelven antes de crear. Insertar siempre solo funciona
     * mientras la tabla este vacia —el primer identificador es entonces el 1, que es el
     * que un caso da por supuesto—, y deja de funcionar en cuanto la base lleva datos
     * sembrados de antemano: el caso operaria sobre una tienda distinta de la que su
     * contexto nombra, sin sintoma de ninguna clase. Ademas solo puede haber una tienda
     * principal activa, de modo que crear una segunda seria un estado que la aplicacion
     * no admite.
     */
    public function tiendaPorDefecto(): int
    {
        return $this->idTienda ??= $this->existente(
            'SELECT idTienda FROM tiendas WHERE tipoTienda = ? AND estado = ? LIMIT 1',
            ['principal', 'Activo']
        ) ?? $this->tienda('2026');
    }

    public function usuarioPorDefecto(): int
    {
        return $this->idUsuario ??= $this->existente(
            'SELECT id FROM usuarios WHERE username = ? LIMIT 1',
            ['pruebas']
        ) ?? $this->insertar('usuarios', [
            'username' => 'pruebas',
            'password' => 'sin-uso',
            'fecha'    => '2020-01-01',
            'group_id' => 1,
            'estado'   => 'Activo',
            'nombre'   => 'Usuario de pruebas',
        ]);
    }

    public function clientePorDefecto(): int
    {
        return $this->idCliente ??= $this->existente(
            'SELECT idClientes FROM clientes WHERE Nombre = ? LIMIT 1',
            ['Cliente de pruebas']
        ) ?? $this->insertar('clientes', [
            'Nombre' => 'Cliente de pruebas',
            'estado' => 'Activo',
        ]);
    }

    /** Crea un proveedor y devuelve su identificador. */
    public function proveedor(string $razonSocial = 'Proveedor de pruebas'): int
    {
        return $this->insertar('proveedores', [
            'razonsocial'  => $razonSocial,
            'nif'          => 'X0000000X',
            'direccion'    => 'Sin direccion',
            'fecha_creado' => '2020-01-01 00:00:00',
            'estado'       => 'Activo',
        ]);
    }

    /**
     * El proveedor de cierre, con el identificador que se le pida.
     *
     * Este es el unico proveedor cuyo identificador no lo elige quien siembra: lo fija
     * la configuracion que gobierna la instalacion, y el sistema busca ese numero. Si
     * ya existe, se reutiliza.
     */
    public function proveedorConId(int $idProveedor, string $razonSocial = 'Proveedor de cierre'): int
    {
        $existente = $this->existente(
            'SELECT idProveedor FROM proveedores WHERE idProveedor = ? LIMIT 1',
            [$idProveedor]
        );
        if ($existente !== null) {
            return $existente;
        }

        $this->insertar('proveedores', [
            'idProveedor'  => $idProveedor,
            'razonsocial'  => $razonSocial,
            'nif'          => 'X0000000X',
            'direccion'    => 'Sin direccion',
            'fecha_creado' => '2020-01-01 00:00:00',
            'estado'       => 'Activo',
        ], $idProveedor);

        return $idProveedor;
    }

    /**
     * Los identificadores que esta instancia ha insertado en la tabla indicada.
     *
     * Lo que un apoyo por defecto encontro ya puesto no aparece aqui: un caso que
     * limpia lo suyo a mano borra por esta lista y no por la que el mismo lleve, que no
     * distingue lo creado de lo encontrado.
     *
     * @return int[] En orden de insercion; vacio si no inserto ninguno
     */
    public function insertadoEn(string $tabla): array
    {
        return $this->insertado[$tabla] ?? [];
    }

    // --- Interno ------------------------------------------------------------

    /** Un albaran de proveedor con varias lineas, en un borde del ejercicio. */
    private function albaranDeFrontera(
        array $cantidades,
        string $fecha,
        int $idProveedorCierre,
        string $estado,
        string $serie,
        ?int $idFamilia
    ): int {
        $opciones = [
            'idProveedor' => $idProveedorCierre,
            'estado'      => $estado,
            'suNumero'    => ($idFamilia !== null ? 'ID-' . $idFamilia : 'SINID') . '#' . $serie,
        ];

        $idAlbaran = null;
        foreach ($cantidades as $idArticulo => $unidades) {
            if ($idAlbaran === null) {
                $idAlbaran = $this->entradaProveedor((int) $idArticulo, (float) $unidades, $fecha, $opciones);
                continue;
            }
            $this->lineaEntrada($idAlbaran, (int) $idArticulo, (float) $unidades);
        }

        if ($idAlbaran === null) {
            throw new RuntimeException('Un albaran de frontera sin ninguna cantidad no es un documento.');
        }

        return $idAlbaran;
    }

    /** El proveedor que se usa cuando un movimiento de entrada no nombra ninguno. */
    private function proveedorPorDefecto(): int
    {
        return $this->existente(
            'SELECT idProveedor FROM proveedores WHERE razonsocial = ? LIMIT 1',
            ['Proveedor de pruebas']
        ) ?? $this->proveedor();
    }

    /** Coste, impuesto y beneficio del articulo, leidos una sola vez. */
    private function datosArticulo(int $idArticulo): array
    {
        if (isset($this->articulos[$idArticulo])) {
            return $this->articulos[$idArticulo];
        }

        $fila = $this->filaDe('articulos', 'idArticulo', $idArticulo);

        return $this->articulos[$idArticulo] = [
            'coste'     => (float) $fila['ultimoCoste'],
            'iva'       => (float) $fila['iva'],
            'beneficio' => (float) $fila['beneficio'],
        ];
    }

    /** El precio al que sale, sin impuesto: el coste mas el beneficio del articulo. */
    private function precioDeVenta(array $articulo): float
    {
        return $this->dinero($articulo['coste'] * (1 + $articulo['beneficio'] / 100));
    }

    private function dinero(float $importe): float
    {
        return round($importe, 2);
    }

    /**
     * El siguiente numero de este tipo de documento.
     *
     * Arranca donde la base lo dejo, no en uno: la numeracion es del ejercicio, y un
     * caso que siembre sobre una base con documentos no vuelve a empezar la serie.
     */
    private function siguienteNumero(string $tabla, string $campo): int
    {
        $this->numeracion[$tabla] ??= $this->existente(
            "SELECT COALESCE(MAX(`$campo`), 0) FROM `$tabla`",
            []
        ) ?? 0;

        return ++$this->numeracion[$tabla];
    }

    /** El numero del documento, del que se acaba de crear o de la base. */
    private function numeroDe(string $tabla, string $campo, int $id): int
    {
        return $this->numeroDeDocumento[$tabla . ':' . $id]
            ??= (int) $this->filaDe($tabla, 'id', $id)[$campo];
    }

    /** La posicion de la siguiente linea del documento, empezando por uno. */
    private function siguienteFila(string $tabla, int $id): int
    {
        $clave = $tabla . ':' . $id;

        return $this->filasDe[$clave] = ($this->filasDe[$clave] ?? 0) + 1;
    }

    /**
     * Suma la linea a los totales del documento y a su desglose de impuestos.
     *
     * Se hace linea a linea y de forma acumulativa en lugar de recomponer el documento
     * entero cada vez: la siembra de esfuerzo pone miles de lineas y rehacer el desglose
     * en cada una convertiria la preparacion en el cuello de botella de la medida.
     */
    private function cuadrar(string $tabla, int $id, float $base, float $iva): void
    {
        $cuota = $this->dinero($base * $iva / 100);

        $campos = $tabla === 'albprot'
            ? ['total_siniva' => $base, 'total' => $base + $cuota]
            : ['total' => $base + $cuota];

        $this->sumar($tabla, 'id', $id, $campos);
        $this->desglosar($tabla, $id, (int) $iva, $base, $cuota);
    }

    /** Acumula base y cuota en la fila de desglose del documento para ese impuesto. */
    private function desglosar(string $tabla, int $id, int $iva, float $base, float $cuota): void
    {
        [$tablaDesglose, $campoDocumento, $campoNumero] = match ($tabla) {
            'albprot'  => ['albproIva', 'idalbpro', 'Numalbpro'],
            'ticketst' => ['ticketstIva', 'idticketst', 'Numticket'],
            'albclit'  => ['albcliIva', 'idalbcli', 'Numalbcli'],
        };

        $clave = $tabla . ':' . $id . ':' . $iva;

        if (isset($this->desgloseDe[$clave])) {
            $this->sumar($tablaDesglose, 'id', $this->desgloseDe[$clave], [
                'totalbase'  => $base,
                'importeIva' => $cuota,
            ]);

            return;
        }

        $this->desgloseDe[$clave] = $this->insertar($tablaDesglose, [
            $campoDocumento => $id,
            $campoNumero    => $this->numeroDe($tabla, $campoNumero, $id),
            'iva'           => $iva,
            'importeIva'    => $cuota,
            'totalbase'     => $base,
        ]);
    }

    /** Admite tanto '2026-01-31' como '2026-01-31 13:45:00'. */
    private function momento(string $fecha): string
    {
        return strlen($fecha) === 10 ? "$fecha 00:00:00" : $fecha;
    }

    /**
     * El primer valor de la primera fila, o null.
     *
     * @param array<int,scalar> $valores
     */
    private function existente(string $sql, array $valores): ?int
    {
        $sentencia = $this->db->prepare($sql);
        if ($sentencia === false) {
            throw new RuntimeException("No se pudo preparar la consulta de apoyo: {$this->db->error}");
        }

        if ($valores !== []) {
            $sentencia->bind_param(str_repeat('s', count($valores)), ...$valores);
        }
        $sentencia->execute();
        $fila = $sentencia->get_result()->fetch_row();

        return $fila === null || $fila[0] === null ? null : (int) $fila[0];
    }

    /** @return array<string,mixed> */
    private function filaDe(string $tabla, string $campoClave, int $id): array
    {
        $sentencia = $this->db->prepare("SELECT * FROM `$tabla` WHERE `$campoClave` = ?");
        $sentencia->bind_param('i', $id);
        $sentencia->execute();
        $fila = $sentencia->get_result()->fetch_assoc();

        if ($fila === null) {
            throw new RuntimeException("No hay ninguna fila con «{$campoClave}» = {$id} en «{$tabla}».");
        }

        return $fila;
    }

    /** @return array<int,array<string,mixed>> */
    private function lineasDe(string $tabla, string $campoDocumento, int $id): array
    {
        $sentencia = $this->db->prepare("SELECT * FROM `$tabla` WHERE `$campoDocumento` = ? ORDER BY `nfila`");
        $sentencia->bind_param('i', $id);
        $sentencia->execute();

        return $sentencia->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** @param array<string,mixed> $valores */
    private function actualizar(string $tabla, string $campoClave, int $id, array $valores): void
    {
        $asignaciones = [];
        foreach (array_keys($valores) as $campo) {
            $asignaciones[] = "`$campo` = ?";
        }

        $sentencia = $this->db->prepare(
            "UPDATE `$tabla` SET " . implode(', ', $asignaciones) . " WHERE `$campoClave` = ?"
        );
        $argumentos = array_values($valores);
        $argumentos[] = $id;
        $sentencia->bind_param(str_repeat('s', count($argumentos)), ...$argumentos);

        if (!$sentencia->execute()) {
            throw new RuntimeException("Fallo al actualizar «{$tabla}»: {$sentencia->error}");
        }
    }

    /** Suma los importes indicados a los que la fila ya tenga. @param array<string,float> $importes */
    private function sumar(string $tabla, string $campoClave, int $id, array $importes): void
    {
        $asignaciones = [];
        foreach (array_keys($importes) as $campo) {
            $asignaciones[] = "`$campo` = COALESCE(`$campo`, 0) + ?";
        }

        $sentencia = $this->db->prepare(
            "UPDATE `$tabla` SET " . implode(', ', $asignaciones) . " WHERE `$campoClave` = ?"
        );
        $argumentos = array_values($importes);
        $argumentos[] = $id;
        $sentencia->bind_param(str_repeat('d', count($importes)) . 'i', ...$argumentos);

        if (!$sentencia->execute()) {
            throw new RuntimeException("Fallo al cuadrar «{$tabla}»: {$sentencia->error}");
        }
    }

    /**
     * @param array<string,mixed> $valores
     * @param int|null            $idExplicito Cuando la fila lleva su clave puesta: el motor
     *                                         no actualiza el ultimo identificado generado, de
     *                                         modo que hay que devolver y anotar el que se dio
     */
    private function insertar(string $tabla, array $valores, ?int $idExplicito = null): int
    {
        $campos = array_keys($valores);
        $huecos = implode(', ', array_fill(0, count($campos), '?'));
        $lista  = '`' . implode('`, `', $campos) . '`';

        $sentencia = $this->db->prepare("INSERT INTO `$tabla` ($lista) VALUES ($huecos)");
        if ($sentencia === false) {
            throw new RuntimeException("No se pudo preparar la insercion en «{$tabla}»: {$this->db->error}");
        }

        $sentencia->bind_param(str_repeat('s', count($valores)), ...array_values($valores));
        if (!$sentencia->execute()) {
            throw new RuntimeException("Fallo al sembrar en «{$tabla}»: {$sentencia->error}");
        }

        $id = $idExplicito ?? (int) $this->db->insert_id;
        $this->insertado[$tabla][] = $id;

        return $id;
    }
}

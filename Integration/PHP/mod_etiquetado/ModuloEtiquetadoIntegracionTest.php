<?php

/**
 * La clase de los lotes de etiquetas (`modulos/mod_etiquetado/clases/modulo_etiquetado.php`,
 * `Modulo_etiquetado`): crear, leer, modificar y borrar un temporal; listar los temporales; guardar un
 * lote nuevo o modificar uno guardado, leerlo, cambiar su estado y listar los lotes.
 *
 * La clase no hereda de la clase base: consulta con la conexión que recibe, que es la del caso, dentro
 * de su transacción. El producto de cada lote lo da de alta el propio caso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModEtiquetado;

use TPVFox\Test\CasoIntegracion;
use TPVFox\Test\Siembra\Siembra;

final class ModuloEtiquetadoIntegracionTest extends CasoIntegracion
{
    private int $idArticulo;

    private int $idUsuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/modulos/mod_etiquetado/clases/modulo_etiquetado.php');
        $siembra = new Siembra($this->db);
        $this->idArticulo = $siembra->articulo('Pan de molde');
        $this->idUsuario = $siembra->usuarioPorDefecto();
    }

    /**
     * @comportamiento Un temporal se crea con su cabecera y sus líneas, se lee con el nombre del producto,
     *   se lista entre los temporales, se modifica y se borra.
     */
    public function test_unTemporalSeCreaSeLeeSeModificaYSeBorra(): void
    {
        $etiquetado = new \Modulo_etiquetado($this->db);

        $id = $etiquetado->addTemporal($this->temporal())['id'];
        $leido = $etiquetado->buscarTemporal($id);
        $listado = array_column($etiquetado->todosTemporal(), 'articulo_name', 'id');
        $modificar = $etiquetado->modificarTemporal(['fechaCad' => '2026-12-31'] + $this->temporal(), $id);

        self::assertSame(['Pan de molde', '2026-11-01', '1', '7'], [$leido['articulo_name'], $leido['fecha_cad'], (string) $leido['tipo'], (string) $leido['numAlb']]);
        self::assertSame([$this->linea(1)], json_decode((string) $leido['productos'], true));
        self::assertSame('Pan de molde', $listado[$id]);
        self::assertSame([], $modificar);
        self::assertSame('2026-12-31', $etiquetado->buscarTemporal($id)['fecha_cad']);
        self::assertNull($etiquetado->eliminarTemporal($id));
        self::assertNull($etiquetado->buscarTemporal($id));
    }

    /** @comportamiento Un temporal sin líneas no se crea: responde que no tiene productos. */
    public function test_unTemporalSinLineasNoSeCrea(): void
    {
        $datos = $this->temporal();
        unset($datos['productos']);

        $respuesta = (new \Modulo_etiquetado($this->db))->addTemporal($datos);

        self::assertSame('No hay productos de temporal', $respuesta['error']);
    }

    /**
     * @comportamiento Un lote nuevo se guarda con su número de lote igual a su número, y se lee con el
     *   nombre del producto y sus líneas.
     */
    public function test_unLoteNuevoSeGuardaConSuNumeroDeLote(): void
    {
        $etiquetado = new \Modulo_etiquetado($this->db);

        self::assertNull($etiquetado->addLoteGuardado($this->lote(0)));
        $id = (int) $this->valor('SELECT MAX(id) FROM modulo_etiquetado');
        $lote = $etiquetado->datosLote($id);

        self::assertSame((string) $id, (string) $lote['num_lote']);
        self::assertSame(['Pan de molde', 'Guardado', '1'], [$lote['articulo_name'], $lote['estado'], (string) $lote['tipo']]);
        self::assertSame([$this->linea(1), $this->linea(2)], json_decode((string) $lote['productos'], true));
    }

    /** @comportamiento Un lote guardado se modifica por su número, y su estado se cambia aparte. */
    public function test_unLoteGuardadoSeModificaYCambiaDeEstado(): void
    {
        $etiquetado = new \Modulo_etiquetado($this->db);
        $etiquetado->addLoteGuardado($this->lote(0));
        $id = (int) $this->valor('SELECT MAX(id) FROM modulo_etiquetado');

        $etiquetado->addLoteGuardado(['fecha_cad' => '2026-12-31', 'productos' => [$this->linea(1)]] + $this->lote($id));
        $etiquetado->modifEstadoReal('Sin guardar', $id);
        $lote = $etiquetado->datosLote($id);

        self::assertSame(['2026-12-31', 'Sin guardar'], [$lote['fecha_cad'], $lote['estado']]);
        self::assertCount(1, json_decode((string) $lote['productos'], true));
    }

    /** @comportamiento Los lotes se listan con su producto y el número de sus líneas, con el límite que se pida. */
    public function test_losLotesSeListanConSuProductoYElLimite(): void
    {
        $etiquetado = new \Modulo_etiquetado($this->db);
        $etiquetado->addLoteGuardado($this->lote(0));
        $etiquetado->addLoteGuardado($this->lote(0));
        $id = (int) $this->valor('SELECT MAX(id) FROM modulo_etiquetado');

        $todos = $etiquetado->todasEtiquetasLimite(' WHERE a.id = ' . $id);
        $limitados = $etiquetado->todasEtiquetasLimite(' LIMIT 0, 1');

        self::assertSame(['Pan de molde', 2], [$todos[0]['articulo_name'], count(json_decode((string) $todos[0]['productos'], true))]);
        self::assertCount(1, $limitados);
    }

    /** @comportamiento Las líneas de un lote guardan el nombre y el albarán tal como se escribieron, con comillas. */
    public function test_unNombreConComillasSeGuardaTalCual(): void
    {
        $etiquetado = new \Modulo_etiquetado($this->db);
        $linea = ['nombre' => 'Pan "de" l\'horno'] + $this->linea(1);
        $etiquetado->addLoteGuardado(['productos' => [$linea]] + $this->lote(0));

        $lote = $etiquetado->datosLote((int) $this->valor('SELECT MAX(id) FROM modulo_etiquetado'));

        self::assertSame('Pan "de" l\'horno', json_decode((string) $lote['productos'], true)[0]['nombre']);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/clases/modulo_etiquetado.php:47-51
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Una fecha de caducidad con unas comillas dobles termina con un error de la base, y el
     *   temporal no se crea.
     * @que-deberia-ocurrir Que lo tecleado se guarde como dato, o se rechace por no ser una fecha.
     * @por-que-ocurre La cabecera se pega a la sentencia entre comillas, sin escaparla ni comprobarla.
     * @como-deberia-funcionar Pasar los datos con una sentencia preparada, y comprobar que la fecha lo es.
     */
    public function test_unaFechaConComillasNoRompeElTemporal(): void
    {
        try {
            (new \Modulo_etiquetado($this->db))->addTemporal(['fechaCad' => '2026-11-01"'] + $this->temporal());
        } catch (\Throwable $error) {
            self::fail('El temporal termina con un error: ' . get_class($error));
        }

        self::addToAssertionCount(1);
    }

    /**
     * @estado rojo
     * @codigo-afectado modulos/mod_etiquetado/clases/modulo_etiquetado.php:155-167
     *
     * @group defecto
     * @group entrada
     * @group alto
     *
     * @que-ocurre-hoy Un número de temporal con una condición añadida borra también los demás temporales.
     * @que-deberia-ocurrir Que solo se borre el temporal cuyo número llega.
     * @por-que-ocurre El número se pega a la sentencia sin convertirlo.
     * @como-deberia-funcionar Convertir el número en entero antes de componer la sentencia, o pasarlo como
     *   dato.
     */
    public function test_unNumeroConUnaCondicionNoBorraOtrosTemporales(): void
    {
        $etiquetado = new \Modulo_etiquetado($this->db);
        $uno = $etiquetado->addTemporal($this->temporal())['id'];
        $otro = $etiquetado->addTemporal($this->temporal())['id'];

        $etiquetado->eliminarTemporal("{$uno} OR 1=1");

        self::assertSame('1', $this->valor("SELECT COUNT(*) FROM modulo_etiquetado_temporal WHERE id = {$otro}"));
    }

    /** @return array<string, mixed> */
    private function temporal(): array
    {
        return [
            'idReal' => 0, 'tipo' => 1, 'fechaEnv' => '2026-10-23 10:00:00', 'fechaCad' => '2026-11-01',
            'idProducto' => $this->idArticulo, 'NumAlb' => 7, 'estado' => 'Activo', 'idUsuario' => $this->idUsuario,
            'productos' => [$this->linea(1)],
        ];
    }

    /** @return array<string, mixed> */
    private function lote(int $idReal): array
    {
        return [
            'idReal' => $idReal, 'tipo' => 1, 'fecha_env' => '2026-10-23 10:00:00', 'fecha_cad' => '2026-11-01',
            'idArticulo' => $this->idArticulo, 'numAlb' => 0, 'estado' => 'Guardado', 'idUsuario' => $this->idUsuario,
            'productos' => [$this->linea(1), $this->linea(2)],
        ];
    }

    /** @return array<string, mixed> */
    private function linea(int $fila): array
    {
        return ['nombre' => 'Pan de molde', 'peso' => '1', 'precio' => '3.5', 'Fecha' => '2026-11-01', 'NumAlb' => '', 'codBarras' => '2112345003504', 'estado' => 'Activo', 'Nfila' => (string) $fila, 'crefTienda' => '12345'];
    }

    private function valor(string $sql): ?string
    {
        $fila = $this->db->query($sql)->fetch_row();

        return $fila === null ? null : (string) $fila[0];
    }
}

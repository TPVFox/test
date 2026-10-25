<?php

/**
 * La capa de datos `clases/DB.php`: las consultas llevan `?` y los valores van aparte, escapados, de
 * modo que un valor nunca cambia la sentencia. Devuelve lo mismo que `query()`, que es lo que espera
 * el resto de TPVFox: los valores leídos como texto, `true` en una escritura y las filas afectadas en
 * la conexión.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\Nucleo;

use TPVFox\Test\CasoIntegracion;

final class CapaDBIntegracionTest extends CasoIntegracion
{
    private \DB $capa;

    protected function setUp(): void
    {
        parent::setUp();
        require_once RUTA_TPVFOX . '/clases/DB.php';
        $this->capa = new \DB($this->db);
    }

    /** @comportamiento Dar de alta devuelve el id, y la lectura por condiciones encuentra la fila. */
    public function test_elAltaDevuelveElIdYSeLeePorSusCondiciones(): void
    {
        $id = $this->capa->insert('clientes', ['Nombre' => 'Cliente de la capa', 'estado' => 'activo']);

        $filas = $this->capa->selectWhere('clientes', ['idClientes' => $id]);

        self::assertGreaterThan(0, $id);
        self::assertSame('Cliente de la capa', $filas[0]['Nombre']);
    }

    /** @comportamiento Modificar devuelve las filas cambiadas, y borrar quita la fila. */
    public function test_modificarDevuelveLasFilasYBorrarLaQuita(): void
    {
        $id = $this->capa->insert('clientes', ['Nombre' => 'Antes', 'estado' => 'activo']);

        $cambiadas = $this->capa->update('clientes', ['Nombre' => 'Después'], ['idClientes' => $id]);
        $nombre = $this->capa->selectWhere('clientes', ['idClientes' => $id])[0]['Nombre'];
        $this->capa->delete('clientes', ['idClientes' => $id]);

        self::assertSame(1, $cambiadas);
        self::assertSame('Después', $nombre);
        self::assertSame([], $this->capa->selectWhere('clientes', ['idClientes' => $id]));
    }

    /** @comportamiento Un valor con comillas y una condición no amplía la búsqueda: se busca como texto. */
    public function test_unValorConUnaCondicionSeBuscaComoTexto(): void
    {
        $this->capa->insert('clientes', ['Nombre' => 'Cliente uno', 'estado' => 'activo']);
        $this->capa->insert('clientes', ['Nombre' => 'Cliente dos', 'estado' => 'activo']);

        $conSelect = $this->capa->select('SELECT * FROM clientes WHERE Nombre = ?', ['Cliente uno" OR "1"="1']);
        $conCondiciones = $this->capa->selectWhere('clientes', ['Nombre' => "x' OR '1'='1"]);
        $conPquery = $this->capa->pquery('SELECT * FROM clientes WHERE Nombre = ?', ["Cliente uno' OR '1'='1"]);

        self::assertSame([], $conSelect);
        self::assertSame([], $conCondiciones);
        self::assertSame(0, $conPquery->num_rows);
    }

    /** @comportamiento Un texto con comillas y barra invertida se guarda y se lee tal cual. */
    public function test_unTextoConComillasYBarraSeGuardaTalCual(): void
    {
        $texto = "O'Donnell \"el de la barra\" \\ fin";
        $id = $this->capa->insert('clientes', ['Nombre' => 'Para modificar', 'estado' => 'activo']);

        $this->capa->execute('UPDATE clientes SET Nombre = ? WHERE idClientes = ?', [$texto, $id]);

        self::assertSame($texto, $this->capa->select('SELECT Nombre FROM clientes WHERE idClientes = ?', [$id])[0]['Nombre']);
    }

    /** @comportamiento Lo leído llega como texto, también los números, igual que con `query()`. */
    public function test_loLeidoLlegaComoTextoComoConQuery(): void
    {
        $id = $this->capa->insert('clientes', ['Nombre' => 'Cliente de tipos', 'estado' => 'activo']);

        $conPquery = $this->capa->pquery('SELECT idClientes FROM clientes WHERE idClientes = ?', [$id])->fetch_assoc();
        $conSelect = $this->capa->select('SELECT idClientes FROM clientes WHERE idClientes = ?', [$id])[0];
        $conQuery = $this->db->query("SELECT idClientes FROM clientes WHERE idClientes = $id")->fetch_assoc();

        self::assertSame($conQuery, $conPquery);
        self::assertSame($conQuery, $conSelect);
        self::assertSame((string) $id, $conPquery['idClientes']);
    }

    /**
     * @comportamiento Una escritura con `pquery()` devuelve true, como `query()`, y deja en la conexión
     *   las filas que cambió; `execute()` devuelve ese mismo número.
     */
    public function test_unaEscrituraDevuelveTrueYDejaLasFilasEnLaConexion(): void
    {
        $id = $this->capa->insert('clientes', ['Nombre' => 'Cliente de escritura', 'estado' => 'activo']);

        $conPquery = $this->capa->pquery('UPDATE clientes SET estado = ? WHERE idClientes = ?', ['inactivo', $id]);
        $filasDePquery = $this->db->affected_rows;
        $conExecute = $this->capa->execute('UPDATE clientes SET estado = ? WHERE idClientes = ?', ['activo', $id]);

        self::assertTrue($conPquery);
        self::assertSame(1, $filasDePquery);
        self::assertSame(1, $conExecute);
        self::assertSame(1, $this->db->affected_rows);
    }

    /** @comportamiento Un valor null se escribe como NULL. */
    public function test_unValorNullSeEscribeComoNull(): void
    {
        $id = $this->capa->insert('clientes', ['Nombre' => 'Cliente sin correo', 'estado' => 'activo', 'email' => 'antes@correo']);

        $this->capa->execute('UPDATE clientes SET email = ? WHERE idClientes = ?', [null, $id]);

        self::assertNull($this->capa->select('SELECT email FROM clientes WHERE idClientes = ?', [$id])[0]['email']);
    }

    /** @comportamiento Con distinto número de `?` que de valores, la capa no ejecuta nada y lo dice. */
    public function test_conDistintoNumeroDeHuecosYValoresNoSeEjecuta(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->capa->pquery('SELECT * FROM clientes WHERE Nombre = ? AND estado = ?', ['solo uno']);
    }
}

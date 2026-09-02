<?php

/**
 * `Articulos::getBalanzaAsociada()` — sin ningún llamador en todo el repositorio, como
 * `funciones.php::modificarArrayPedidos()` y `ClaseVentas::sumarIvaBases()` dentro de
 * `mod_venta`. A diferencia de esos dos, esta no está simplemente inerte: está rota del
 * todo, y lo estaría desde el primer uso.
 */

declare(strict_types=1);

namespace TPVFox\Test\Integration\ModVenta;

use TPVFox\Test\CasoIntegracion;

final class GetBalanzaAsociadaIntegracionTest extends CasoIntegracion
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->incluirTPVFox('/clases/articulos.php');
    }

    /**
     * Defecto: la sentencia SQL nombra una columna que no existe en la tabla.
     *
     * Síntoma: cualquier llamada a `getBalanzaAsociada()` lanza
     * `mysqli_sql_exception: Unknown column 'Tecla' in 'SELECT'`. Causa raíz: el método
     * hace `SELECT idBalanza, PLU, Tecla FROM modulo_balanza_plus`, pero la tabla real
     * (`TPVFox/BD/BDtpv/tpvfox_V_0_4-2.84.sql`) tiene `idBalanza`, `plu`, `seccion` e
     * `idArticulo` — no hay ninguna columna `Tecla`, con ningún nombre parecido. No hay
     * evidencia de que la tabla haya tenido nunca esa columna: no es una migración
     * pendiente, es una sentencia que nunca pudo haber funcionado tal como está escrita.
     * Sin ningún llamador en el repositorio, de modo que el defecto es inerte hoy — pero a
     * diferencia de `modificarArrayPedidos()` o `sumarIvaBases()`, en cuanto alguien la
     * use fallará siempre, no solo en casos borde. Corrección propuesta: sustituir `Tecla`
     * por `seccion`, u otra columna real, según lo que el consumidor futuro necesite.
     * Evidencia: este test, en rojo mientras el defecto siga sin corregirse por CC (o la
     * función se retire).
     */
    public function test_defecto_lanzaExcepcionPorColumnaInexistente(): void
    {
        $articulos = new \Articulos($this->db);

        $this->expectException(\mysqli_sql_exception::class);
        $this->expectExceptionMessageMatches('/Tecla/');

        $articulos->getBalanzaAsociada(1);
    }
}

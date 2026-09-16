// Siembra los escenarios antes de la pasada de recorridos.
//
// Sin esto la suite no es idempotente entre pasadas: los recorridos dejan documentos y
// borradores vivos, y una segunda pasada sobre esos restos no encuentra el punto de partida
// que la primera tuvo. Hasta ahora la siembra se lanzaba a mano, de modo que la dependencia
// era real y no estaba declarada en ninguna parte.
//
// Se salta con TPVFOX_SIN_SIEMBRA=1 para depurar un recorrido suelto sin rehacer los datos.

const { execFileSync } = require('child_process');
const path = require('path');

module.exports = async () => {
  if (process.env.TPVFOX_SIN_SIEMBRA === '1') {
    // Por stderr: stdout es donde los reporters de maquina emiten su salida.
    console.error('Siembra omitida por TPVFOX_SIN_SIEMBRA=1');
    return;
  }

  const guion = path.resolve(__dirname, 'sembrar-e2e-venta.php');
  console.error('Sembrando escenarios de venta...');

  try {
    execFileSync('php', [guion], { stdio: ['ignore', 'pipe', 'pipe'] });
  } catch (error) {
    const detalle = [error.stdout, error.stderr].filter(Boolean).map(String).join('\n');
    throw new Error(`La siembra fallo y los recorridos no pueden partir de un estado conocido:\n${detalle}`);
  }
};

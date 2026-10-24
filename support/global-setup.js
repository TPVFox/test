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

  const guiones = [
    ['venta', 'sembrar-e2e-venta.php'],
    ['productos relacionados', 'sembrar-e2e-relaciones.php'],
    ['tareas programadas', 'sembrar-e2e-tareas.php'],
  ];

  for (const [nombre, fichero] of guiones) {
    console.error(`Sembrando escenarios de ${nombre}...`);

    try {
      execFileSync('php', [path.resolve(__dirname, fichero)], { stdio: ['ignore', 'pipe', 'pipe'] });
    } catch (error) {
      const detalle = [error.stdout, error.stderr].filter(Boolean).map(String).join('\n');
      throw new Error(`La siembra de ${nombre} fallo y los recorridos no pueden partir de un estado conocido:\n${detalle}`);
    }
  }
};

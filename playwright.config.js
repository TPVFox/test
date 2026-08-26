const { defineConfig } = require('@playwright/test');

// La base termina siempre en barra. Los recorridos navegan con rutas relativas
// —«modulos/…», sin barra inicial— y así es como las resuelve URL(): sin la barra
// final, el último tramo de la base se pierde, de modo que una aplicación servida
// en http://host/TPVFox acabaría pidiendo http://host/modulos/… y respondiendo 404
// sin que nada indique por qué.
function baseConBarraFinal(url) {
  return url.endsWith('/') ? url : url + '/';
}

// Requiere la aplicación en marcha. `npm run entorno:up` la levanta con datos sembrados.
module.exports = defineConfig({
  testDir: './E2E/specs',
  timeout: 30_000,
  retries: 0,
  use: {
    baseURL: baseConBarraFinal(process.env.TPVFOX_URL || 'http://localhost:8080'),
    trace: 'on',
    video: 'on',
    screenshot: 'on'
  },
  reporter: [['list'], ['html', { outputFolder: 'E2E/informe', open: 'never' }]]
});

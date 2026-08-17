const { defineConfig } = require('@playwright/test');

// Requiere la aplicación en marcha. `npm run entorno:up` la levanta con datos sembrados.
module.exports = defineConfig({
  testDir: './E2E/specs',
  timeout: 30_000,
  retries: 0,
  use: {
    baseURL: process.env.TPVFOX_URL || 'http://localhost:8080',
    trace: 'on',
    video: 'on',
    screenshot: 'on'
  },
  reporter: [['list'], ['html', { outputFolder: 'E2E/informe', open: 'never' }]]
});

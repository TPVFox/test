/**
 * Lee `test/.env` y lo vuelca en `process.env`, con la misma precedencia que
 * `support/php/Entorno.php`: la variable de entorno manda, el fichero rellena lo que falte.
 *
 * Existe porque hasta ahora solo PHP leía el `.env`. Playwright no, de modo que
 * `TPVFOX_E2E_USUARIO` podía estar escrita en el fichero y la suite fallar diciendo que
 * faltaba — mandando a mirar justo donde sí estaba. El formato es el mismo que lee PHP:
 * `CLAVE=valor`, almohadilla para comentar, comillas opcionales.
 *
 * Sin dependencia nueva a propósito: son doce líneas y evita que las dos mitades de la
 * suite lean su configuración de sitios distintos.
 */

const fs = require('fs');
const path = require('path');

function cargarEntorno(ruta = path.join(__dirname, '..', '.env')) {
  if (!fs.existsSync(ruta)) {
    return;
  }

  for (const linea of fs.readFileSync(ruta, 'utf-8').split('\n')) {
    const limpia = linea.trim();
    if (limpia === '' || limpia.startsWith('#') || !limpia.includes('=')) {
      continue;
    }

    const separador = limpia.indexOf('=');
    const clave = limpia.slice(0, separador).trim();
    const valor = limpia.slice(separador + 1).trim().replace(/^["']|["']$/g, '');

    // La variable de entorno manda: una pasada en integración continua no necesita fichero.
    if (process.env[clave] === undefined || process.env[clave] === '') {
      process.env[clave] = valor;
    }
  }
}

module.exports = { cargarEntorno };

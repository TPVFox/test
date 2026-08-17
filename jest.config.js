// Las pruebas se ejecutan contra un clon de TPVFox situado como repositorio hermano.
const CODIGO = process.env.TPVFOX_PATH || '../TPVFox';

module.exports = {
  projects: [
    { displayName: 'unit-js',        testEnvironment: 'node',      testMatch: ['<rootDir>/Unit/JS/**/*.test.js'] },
    { displayName: 'integration-js', testEnvironment: 'jsdom',     testMatch: ['<rootDir>/Integration/JS/**/*.test.js'] }
  ],
  collectCoverageFrom: [`${CODIGO}/modulos/**/*.js`, `!${CODIGO}/**/vendor/**`],
  coverageProvider: 'v8',
  coverageThreshold: { global: { lines: 70, functions: 70, statements: 70 } }
};

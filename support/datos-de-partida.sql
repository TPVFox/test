-- Datos de partida de una instalacion de TPVFox: los que traia el esquema de referencia
-- tpvfox_V_0_4-2.84.sql en su primera version (TPVFox@35d15185) y que perdio al regenerarse
-- con las claves foraneas. Las pruebas cuentan con ellos: los tipos de IVA, las formas de pago,
-- los tipos de vencimiento, el cliente sin identificar, la tienda principal, el usuario
-- administrador y su indice.
--
-- Son repetibles: INSERT IGNORE no toca una fila que ya exista con el mismo identificador.

INSERT IGNORE INTO `clientes` (`idClientes`, `Nombre`, `razonsocial`, `nif`, `direccion`, `codpostal`, `telefono`, `movil`, `fax`, `email`, `estado`, `formasVenci`, `fecha_creado`, `descuento_ticket`, `requiere_factura`, `recargo_equivalencia`) VALUES
(1, 'Sin identificar', 'Sin identificar', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'activo', NULL, '2022-12-10 21:20:29', '3.00', 0, 0);

INSERT IGNORE INTO `formasPago` (`id`, `descripcion`) VALUES
(1, 'Efectivo'),
(2, 'Tarjeta'),
(3, 'Recibo bancario'),
(4, 'Transferencia bancaria'),
(5, 'Talón');

INSERT IGNORE INTO `iva` (`idIva`, `descripcionIva`, `iva`, `recargo`) VALUES
(1, 'I.V.A. al cero', '0.00', '0.00'),
(2, 'Super Reducido', '4.00', '0.50'),
(3, 'Reducido', '10.00', '1.00'),
(4, 'General', '21.00', '4.00');

INSERT IGNORE INTO `tiendas` (`idTienda`, `tipoTienda`, `razonsocial`, `nif`, `telefono`, `estado`, `NombreComercial`, `direccion`, `ano`, `dominio`, `key_api`, `servidor_email`) VALUES
(1, 'principal', 'Soluciones informaticas Vigo SL', 'B999666999', '886112370', 'Activo', 'Soluciones Vigo', 'Emilia pardo Bazan 52- bajo', '2022', NULL, NULL, 'tuservidor.emailtuyo.com');

INSERT IGNORE INTO `tiposVencimiento` (`id`, `descripcion`, `dias`) VALUES
(1, 'Contado', 0),
(2, 'Semanal', 7),
(3, 'Quincenal', 15),
(4, 'Mensual', 30),
(5, 'Semestral', 181);

INSERT IGNORE INTO `usuarios` (`id`, `username`, `password`, `fecha`, `group_id`, `estado`, `nombre`) VALUES
(1, 'admin', '21232f297a57a5a743894a0e4a801fc3', '2022-12-10', 9, 'activo', 'admin');

INSERT IGNORE INTO `indices` (`id`, `idTienda`, `idUsuario`, `numticket`, `tempticket`) VALUES
(1, 1, 1, 1, 1);

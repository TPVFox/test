-- Datos de partida de una instalacion de TPVFox: los que traia el esquema de referencia
-- tpvfox_V_0_4-2.84.sql en su primera version (TPVFox@35d15185) y que perdio al regenerarse
-- con las claves foraneas. Las pruebas cuentan con ellos: los tipos de IVA, las formas de pago,
-- los tipos de vencimiento y el cliente sin identificar. La tienda principal, el usuario de los
-- recorridos y su indice no van aqui: los crea preparar-entorno.php con el ejercicio de cada base.
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

INSERT IGNORE INTO `tiposVencimiento` (`id`, `descripcion`, `dias`) VALUES
(1, 'Contado', 0),
(2, 'Semanal', 7),
(3, 'Quincenal', 15),
(4, 'Mensual', 30),
(5, 'Semestral', 181);

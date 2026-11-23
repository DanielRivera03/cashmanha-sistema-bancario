-- =====================================================================
-- 033 · Cuenta técnica de condonaciones
-- =====================================================================
--
-- Un arreglo de pago perdona parte de la deuda. Ese perdón tiene que
-- aparecer en algún sitio del libro mayor, porque es una pérdida real
-- de la institución: si solo se registrara el dinero que entra, los
-- $300 cobrados sobre una deuda de $1,000 cuadrarían como si la deuda
-- hubiera sido de $300 desde el principio, y los $700 perdonados
-- desaparecerían sin dejar rastro.
--
-- Con esta cuenta, el asiento de un arreglo tiene tres patas:
--
--     cuenta del cliente      -300   (lo que paga)
--     condonaciones           -700   (lo que la institución pierde)
--     técnica principal     +1,000   (la deuda que se da por saldada)
--
-- El saldo de esta cuenta, siempre negativo, es el acumulado de todo lo
-- condonado. Es la cifra que gerencia necesita para saber cuánto está
-- costando la política de arreglos.
-- ---------------------------------------------------------------------

INSERT INTO cuentas
    (uuid, numero_cuenta, usuario_id, producto_id, moneda,
     saldo_cent, saldo_retenido_cent, estado, es_tecnica, apertura_en)
SELECT UUID(), '0000000003', 1, 1, 'USD', 0, 0, 'activa', 1, NOW()
 WHERE NOT EXISTS (
     SELECT 1 FROM (SELECT numero_cuenta FROM cuentas) c
      WHERE c.numero_cuenta = '0000000003'
 );

INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('contabilidad.cuenta_condonaciones', '0000000003',
   'Cuenta tecnica donde se acumula lo condonado en arreglos de pago')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

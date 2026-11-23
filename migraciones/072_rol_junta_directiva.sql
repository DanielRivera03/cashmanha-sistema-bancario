-- -----------------------------------------------------------------
-- La Junta Directiva: el nivel más alto, y solo de consulta.
--
-- Hasta aquí la cúspide era Presidencia, con setenta y cuatro
-- permisos: puede aprobar, desembolsar y tocar parámetros. Eso está
-- bien para quien dirige la operación del día a día, pero no es lo
-- que hace un consejo. Un director no opera el banco: lo gobierna, y
-- para eso necesita ver, no hacer.
--
-- POR QUÉ NIVEL 95 Y NO 100
--
-- En este sistema el nivel sirve para una cosa concreta: nadie puede
-- asignar un rol de nivel superior al suyo. Si la Junta estuviera por
-- encima del Administrador —que es un rol TÉCNICO, no jerárquico—,
-- nadie podría darle de alta su propia cuenta.
--
-- Así que 95: por encima de Presidencia (90), que es lo que el cargo
-- significa, y por debajo del Administrador (100), que es quien tiene
-- la llave del sistema. Jerarquía del banco y llave de la máquina son
-- dos cosas distintas, y conviene que el modelo no las confunda.
--
-- POR QUÉ TAN POCOS PERMISOS
--
-- Tres. Y es a propósito.
--
-- Un rol que lo puede ver todo acaba usándose para trabajar, y
-- entonces deja de ser un rol de gobierno. Peor: pone los datos
-- personales de cada cliente al alcance de gente que no los necesita
-- para decidir. La pantalla de la Junta enseña agregados —captaciones,
-- cartera, morosidad por tramos— y solo baja al nombre propio en los
-- mayores deudores, porque la concentración de riesgo en pocas manos
-- es justo lo que un consejo tiene que saber.
--
-- `auditoria.ver` está porque un director tiene que poder comprobar
-- quién hizo qué sin pedirle permiso a nadie. Es el contrapeso.
-- -----------------------------------------------------------------

INSERT INTO permisos (clave, descripcion) VALUES
  ('junta.ver', 'Ver el tablero de indicadores de la Junta Directiva')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO roles (clave, nombre, nivel) VALUES
  ('junta', 'Junta Directiva', 95)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), nivel = VALUES(nivel);

-- Solo tres, y los tres de lectura.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN ('junta.ver', 'reporte.ver', 'auditoria.ver')
 WHERE r.clave = 'junta';

-- El Administrador también lo ve: es quien tiene que poder comprobar
-- que la pantalla funciona sin pedirle la contraseña a un director.
INSERT IGNORE INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'junta.ver'
 WHERE r.clave IN ('administrador', 'presidencia');

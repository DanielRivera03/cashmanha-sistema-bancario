-- =====================================================================
-- 083. Los permisos del área de cumplimiento
-- =====================================================================
--
-- QUIÉN VE ESTO, Y POR QUÉ TAN POCOS
--
-- El expediente de cumplimiento de un cliente es lo más sensible que
-- guarda un banco: dice si se sospecha de él, qué se miró y qué se
-- reportó a la Fiscalía.
--
-- Un reporte de operación sospechosa es, además, legalmente
-- confidencial: comunicárselo al cliente —o dejar que se entere— está
-- prohibido por la ley. Si cualquier empleado pudiera abrirlo, bastaría
-- con que uno lo mencionara por cortesía para convertir una obligación
-- cumplida en un delito.
--
-- Por eso estos permisos NO se dan a atención a clientes, ni a caja, ni
-- a banqueros, que son precisamente quienes hablan con el cliente todos
-- los días. Se dan a quien tiene que responder por ellos.
--
-- `cumplimiento.reportar` va aún más arriba: marcar un reporte como
-- enviado a la UIF es afirmar que una obligación legal se cumplió.
-- Eso lo firma la administración, no se delega.
-- =====================================================================
USE cashmanha_v2;

INSERT INTO permisos (clave, descripcion)
SELECT * FROM (
  SELECT 'cumplimiento.ver' AS clave,
         'Ver el panel de prevencion de lavado: alertas, perfiles y reportes' AS descripcion
  UNION ALL SELECT 'cumplimiento.analizar',
         'Analizar alertas: descartarlas con motivo, prorrogarlas y escalarlas a reporte'
  UNION ALL SELECT 'cumplimiento.reportar',
         'Registrar que un reporte se remitio a la UIF, con su acuse'
  UNION ALL SELECT 'cumplimiento.expediente',
         'Editar la ficha de cumplimiento del cliente: riesgo, PEP y debida diligencia'
) AS nuevos
WHERE NOT EXISTS (
  SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave
);

-- Administración y presidencia: todo.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p
    ON p.clave IN ('cumplimiento.ver', 'cumplimiento.analizar',
                   'cumplimiento.reportar', 'cumplimiento.expediente')
 WHERE r.clave IN ('administrador', 'presidencia')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );

-- Gerencia y jurídico: analizan y mantienen expedientes, pero no son
-- quienes afirman que un reporte salió.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p
    ON p.clave IN ('cumplimiento.ver', 'cumplimiento.analizar', 'cumplimiento.expediente')
 WHERE r.clave IN ('gerencia', 'juridico')
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );

-- La junta directiva mira, no opera. Le corresponde saber cómo va el
-- area —es su responsabilidad de gobierno— sin meter mano en los casos.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave = 'cumplimiento.ver'
 WHERE r.clave = 'junta'
   AND NOT EXISTS (
       SELECT 1 FROM rol_permiso rp WHERE rp.rol_id = r.id AND rp.permiso_id = p.id
   );

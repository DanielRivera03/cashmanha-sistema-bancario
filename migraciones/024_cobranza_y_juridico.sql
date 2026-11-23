-- ---------------------------------------------------------------------
-- 024 · Cobranza y departamento jurídico
--
-- Hasta ahora «cartera morosa» era una lista: nombres, montos y días de
-- atraso. Útil para saber cuánto se debe, inservible para cobrarlo. No
-- decía a quién se le había llamado, qué se le dijo, si contestó, ni en
-- qué punto del proceso está cada caso. Con dos deudores se lleva de
-- memoria; con doscientos, no.
--
-- La cobranza en El Salvador recorre etapas, y cada una tiene reglas
-- distintas sobre qué se puede hacer y quién responde:
--
--   1. PREVENTIVA (antes de vencer) — un recordatorio amable.
--   2. ADMINISTRATIVA (1 a 60 días) — llamadas y correos de la agencia.
--   3. EXTRAJUDICIAL (61 a 120 días) — pasa a jurídico. Cartas de
--      requerimiento formal y último llamado antes de demandar.
--   4. JUDICIAL (más de 120) — proceso ejecutivo mercantil: demanda,
--      emplazamiento, sentencia, embargo.
--   5. RECUPERADO o CASTIGADO — el caso cierra, bien o mal.
--
-- Lo que entra aquí:
--
--   · `cobranza_casos` — un expediente por crédito moroso, con su etapa,
--     su gestor asignado y, cuando llega a judicial, los datos del
--     juzgado, el abogado y el número de referencia del proceso.
--
--   · `cobranza_gestiones` — la bitácora: cada llamada, correo, carta,
--     visita o escrito, con su resultado. Es lo que se presenta cuando
--     un cliente dice «a mí nadie me avisó».
--
--   · `cobranza_plantillas` — los textos de los correos de cada etapa.
--     En una tabla y no en el código: quien redacta las cartas de
--     cobro es jurídico, no quien programa.
--
--   · `cobranza_promesas` — los compromisos de pago. Una promesa que no
--     se cumple es la señal más clara de que hay que escalar.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- El expediente de cobro
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cobranza_casos (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)     NOT NULL,
    folio           VARCHAR(24)  NOT NULL,

    credito_id      INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,

    etapa           ENUM('preventiva','administrativa','extrajudicial',
                         'judicial','sentencia','recuperado','castigado')
                    NOT NULL DEFAULT 'administrativa',

    -- Quién lleva el caso. En extrajudicial y judicial es un abogado.
    gestor_id       INT UNSIGNED NULL,

    -- Fotografía de la deuda al abrir o al escalar. Se guarda porque la
    -- deuda sigue creciendo y hace falta saber con cuánto se escaló.
    deuda_cent      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mora_cent       BIGINT UNSIGNED NOT NULL DEFAULT 0,
    dias_mora       INT UNSIGNED NOT NULL DEFAULT 0,
    cuotas_vencidas SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- --- Datos del proceso judicial -------------------------------
    juzgado         VARCHAR(160) NULL,
    referencia_judicial VARCHAR(80) NULL,
    abogado         VARCHAR(160) NULL,
    demanda_en      DATE         NULL,
    emplazamiento_en DATE        NULL,
    sentencia_en    DATE         NULL,
    sentencia_favorable TINYINT(1) NULL,

    -- --- Cierre ----------------------------------------------------
    cerrado_en      DATETIME     NULL,
    cerrado_por     INT UNSIGNED NULL,
    motivo_cierre   VARCHAR(400) NULL,
    recuperado_cent BIGINT UNSIGNED NOT NULL DEFAULT 0,

    abierto_en      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_cob_uuid (uuid),
    UNIQUE KEY uq_cob_folio (folio),
    -- Un crédito, un caso abierto. Dos expedientes del mismo crédito
    -- significan dos gestores pisándose y el cliente recibiendo
    -- mensajes contradictorios.
    UNIQUE KEY uq_cob_credito (credito_id),
    KEY ix_cob_etapa (etapa, dias_mora),
    KEY ix_cob_usuario (usuario_id),
    CONSTRAINT fk_cob_credito FOREIGN KEY (credito_id) REFERENCES creditos (id),
    CONSTRAINT fk_cob_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_cob_gestor  FOREIGN KEY (gestor_id)  REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- La bitácora de gestiones
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cobranza_gestiones (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    caso_id     BIGINT UNSIGNED NOT NULL,

    tipo        ENUM('correo','llamada','mensaje','carta','visita',
                     'requerimiento','demanda','audiencia','escrito',
                     'convenio','nota')
                NOT NULL,

    -- Automática la hizo el sistema; manual, una persona.
    automatica  TINYINT(1)   NOT NULL DEFAULT 0,
    etapa       VARCHAR(20)  NOT NULL,

    asunto      VARCHAR(190) NOT NULL,
    detalle     TEXT         NULL,

    resultado   ENUM('enviada','contactado','no_contesta','numero_equivocado',
                     'promesa_pago','negativa','pago_recibido','sin_resultado')
                NOT NULL DEFAULT 'sin_resultado',

    registrada_por INT UNSIGNED NULL,
    creada_en   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_cobg_caso (caso_id, id),
    KEY ix_cobg_tipo (tipo, creada_en),
    CONSTRAINT fk_cobg_caso FOREIGN KEY (caso_id) REFERENCES cobranza_casos (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_cobg_usuario FOREIGN KEY (registrada_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Promesas de pago
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cobranza_promesas (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    caso_id       BIGINT UNSIGNED NOT NULL,
    monto_cent    BIGINT UNSIGNED NOT NULL,
    fecha_promesa DATE         NOT NULL,
    cumplida_en   DATETIME     NULL,
    monto_pagado_cent BIGINT UNSIGNED NOT NULL DEFAULT 0,
    estado        ENUM('vigente','cumplida','incumplida') NOT NULL DEFAULT 'vigente',
    observaciones VARCHAR(400) NULL,
    registrada_por INT UNSIGNED NULL,
    creada_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_cobp_caso (caso_id, estado),
    KEY ix_cobp_fecha (fecha_promesa, estado),
    CONSTRAINT fk_cobp_caso FOREIGN KEY (caso_id) REFERENCES cobranza_casos (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Las plantillas de los correos de cobro
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cobranza_plantillas (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    clave       VARCHAR(40)  NOT NULL,
    etapa       VARCHAR(20)  NOT NULL,
    -- Desde cuántos días de mora aplica esta plantilla.
    dias_desde  INT UNSIGNED NOT NULL DEFAULT 0,
    dias_hasta  INT UNSIGNED NOT NULL DEFAULT 9999,
    asunto      VARCHAR(190) NOT NULL,
    cuerpo      TEXT         NOT NULL,
    -- El tono sube con la etapa, pero nunca deja de ser respetuoso.
    tono        ENUM('cordial','firme','formal','juridico') NOT NULL DEFAULT 'cordial',
    activa      TINYINT(1)   NOT NULL DEFAULT 1,
    orden       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cobpl_clave (clave),
    KEY ix_cobpl_rango (activa, dias_desde, dias_hasta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Permisos del área de cobranza
-- ---------------------------------------------------------------------
INSERT INTO permisos (clave, descripcion) VALUES
  ('cobranza.ver',       'Consultar los expedientes de cobro'),
  ('cobranza.gestionar', 'Registrar gestiones y promesas de pago'),
  ('cobranza.escalar',   'Mover un caso de etapa, hasta extrajudicial'),
  ('cobranza.juridico',  'Llevar la etapa judicial: demanda, juzgado y sentencia'),
  ('cobranza.cerrar',    'Cerrar un caso como recuperado o castigado'),
  ('cobranza.enviar',    'Disparar los correos de cobranza')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ---------------------------------------------------------------------
-- El rol de jurídico
-- ---------------------------------------------------------------------
INSERT INTO roles (clave, nombre, descripcion, nivel) VALUES
  ('juridico', 'Departamento Jurídico',
   'Lleva la cobranza extrajudicial y judicial: requerimientos, demandas, audiencias y sentencias.',
   60)
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN (
        'cobranza.ver', 'cobranza.gestionar', 'cobranza.escalar',
        'cobranza.juridico', 'cobranza.cerrar', 'cobranza.enviar',
        'cartera.ver_morosos',
        'cliente.ver_expediente',
        'credito.ver_todos',
        'cuenta.ver_todas',
        'contrato.generar',
        'mensaje.enviar', 'mensaje.ver',
        'reporte.ver'
       )
 WHERE r.clave = 'juridico'
ON DUPLICATE KEY UPDATE rol_id = rol_permiso.rol_id;

-- Administración, presidencia y gerencia lo ven todo; atención a
-- clientes gestiona hasta la etapa administrativa, que es la suya.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN (
        'cobranza.ver', 'cobranza.gestionar', 'cobranza.escalar',
        'cobranza.juridico', 'cobranza.cerrar', 'cobranza.enviar'
       )
 WHERE r.clave IN ('administrador', 'presidencia', 'gerencia')
ON DUPLICATE KEY UPDATE rol_id = rol_permiso.rol_id;

INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN ('cobranza.ver', 'cobranza.gestionar')
 WHERE r.clave = 'atencionclientes'
ON DUPLICATE KEY UPDATE rol_id = rol_permiso.rol_id;

-- ---------------------------------------------------------------------
-- Parámetros del proceso
-- ---------------------------------------------------------------------
INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('cobranza.dias_administrativa', '1',
   'Dias de mora desde los que el caso es administrativo'),
  ('cobranza.dias_extrajudicial', '61',
   'Dias de mora desde los que el caso pasa a juridico (extrajudicial)'),
  ('cobranza.dias_judicial', '121',
   'Dias de mora desde los que corresponde la via judicial'),
  ('cobranza.dias_entre_correos', '7',
   'Minimo de dias entre dos correos automaticos al mismo deudor'),
  ('cobranza.cuotas_para_demandar', '3',
   'Cuotas consecutivas impagas que facultan a caducar el plazo')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ---------------------------------------------------------------------
-- Las plantillas de cobro, por etapa
--
-- El tono sube, pero el respeto no baja. Un deudor es un cliente con un
-- problema, y la mayoría de las veces el problema se resuelve hablando.
-- La última carta avisa de la demanda; no amenaza con ella.
-- ---------------------------------------------------------------------
INSERT INTO cobranza_plantillas (clave, etapa, dias_desde, dias_hasta, asunto, cuerpo, tono, orden) VALUES

('recordatorio_previo', 'preventiva', 0, 0,
 'Su cuota de {{folio}} vence pronto',
 'Estimado(a) {{nombre}}:\n\nLe escribimos solo para recordarle, con todo gusto, que la cuota de su credito {{folio}} por {{cuota}} vence el {{vencimiento}}.\n\nSi ya realizo el pago, le agradecemos que haga caso omiso de este mensaje. Si prefiere pagarlo desde la aplicacion, puede hacerlo en cualquier momento desde «Mis creditos».\n\nQuedamos a sus ordenes para lo que necesite.',
 'cordial', 10),

('aviso_1', 'administrativa', 1, 15,
 'Tenemos pendiente su cuota de {{folio}}',
 'Estimado(a) {{nombre}}:\n\nNotamos que la cuota de su credito {{folio}} quedo pendiente desde el {{vencimiento}}. A la fecha el saldo vencido es de {{deuda}}, mas {{mora}} de recargo por {{dias}} dia(s) de atraso.\n\nSabemos que a cualquiera se le pasa una fecha. Puede ponerse al dia desde la aplicacion, en cualquiera de nuestras agencias, o llamarnos si prefiere que le ayudemos a revisar su caso.\n\nSi ya efectuo el pago en estos dias, le pedimos disculpas por el cruce y le agradecemos avisarnos.',
 'cordial', 20),

('aviso_2', 'administrativa', 16, 35,
 'Segundo aviso: su credito {{folio}} presenta atraso',
 'Estimado(a) {{nombre}}:\n\nSu credito {{folio}} acumula {{dias}} dias de atraso, con un saldo vencido de {{deuda}} y {{mora}} en concepto de recargo por mora. El recargo se sigue acumulando por cada dia que pasa, de modo que ponerse al dia cuanto antes le ahorra dinero.\n\nNos gustaria mucho ayudarle a resolverlo. Si en este momento no le es posible cubrir el total, llamenos: podemos revisar juntos un plan de pago que se ajuste a su situacion. Es preferible para ambos acordar algo ahora que dejar que la deuda siga creciendo.\n\nQuedamos atentos a su respuesta.',
 'firme', 30),

('aviso_3', 'administrativa', 36, 60,
 'Importante: su credito {{folio}} esta por pasar a cobro extrajudicial',
 'Estimado(a) {{nombre}}:\n\nSu credito {{folio}} registra {{dias}} dias de mora y un saldo vencido de {{deuda}}, mas {{mora}} de recargo. Hemos intentado comunicarnos con usted en varias ocasiones sin lograrlo.\n\nLe informamos que, de no recibir su pago o una comunicacion suya en los proximos dias, el expediente pasara al departamento juridico para su cobro extrajudicial, conforme a la clausula septima del contrato que usted firmo.\n\nTodavia estamos a tiempo de resolverlo entre nosotros, y es lo que preferimos. Le pedimos que se comunique con nosotros a la brevedad.',
 'firme', 40),

('requerimiento', 'extrajudicial', 61, 120,
 'Requerimiento de pago - credito {{folio}}',
 'Senor(a) {{nombre}}:\n\nPor este medio, el Departamento Juridico de CASHMAN H.A., S.A. DE C.V. le REQUIERE formalmente el pago de la cantidad de {{total}}, correspondiente al saldo vencido de {{deuda}} mas {{mora}} en concepto de clausula penal, derivados del contrato de credito {{folio}} suscrito por usted, el cual registra {{dias}} dias de mora y {{cuotas}} cuota(s) impaga(s).\n\nDe conformidad con la clausula SEPTIMA del referido contrato, el incumplimiento de tres cuotas consecutivas faculta a esta institucion para dar por caducado el plazo y exigir el pago inmediato de la totalidad del saldo adeudado.\n\nSe le concede un plazo de OCHO DIAS HABILES contados a partir de la recepcion de la presente para efectuar el pago o comparecer a nuestras oficinas a suscribir un convenio de pago. Transcurrido dicho plazo sin haber atendido este requerimiento, se procedera a interponer la demanda correspondiente por la via ejecutiva mercantil, quedando a su cargo las costas procesales y los honorarios de ley.\n\nLe reiteramos que nuestra disposicion a llegar a un arreglo se mantiene mientras no se inicie el proceso.',
 'juridico', 50),

('ultimo_llamado', 'extrajudicial', 100, 120,
 'Ultimo llamado antes de la via judicial - credito {{folio}}',
 'Senor(a) {{nombre}}:\n\nHabiendo transcurrido el plazo concedido en el requerimiento de pago que le fue notificado, y persistiendo el incumplimiento del contrato de credito {{folio}} —que a esta fecha registra {{dias}} dias de mora y un adeudo total de {{total}}—, le dirigimos este ULTIMO LLAMADO antes de remitir el expediente a la via judicial.\n\nSi dentro de los proximos TRES DIAS HABILES se presenta a nuestras oficinas a cancelar o a suscribir un convenio de pago, el caso no sera judicializado y se evitara el cobro de costas y honorarios, que incrementarian de forma considerable la cantidad a pagar.\n\nEsta es una oportunidad real y le pedimos que la aproveche. Despues de este plazo, el asunto dejara de estar en nuestras manos.',
 'juridico', 60),

('demanda_interpuesta', 'judicial', 121, 9999,
 'Notificacion: demanda interpuesta - credito {{folio}}',
 'Senor(a) {{nombre}}:\n\nLe notificamos que, ante el incumplimiento del contrato de credito {{folio}} y habiendose agotado las gestiones de cobro administrativo y extrajudicial sin obtener respuesta de su parte, esta institucion ha procedido a interponer la DEMANDA correspondiente en proceso ejecutivo mercantil ante los tribunales competentes, por la cantidad de {{total}}.\n\nEl expediente se tramita bajo la referencia {{referencia}} en el {{juzgado}}. A partir de este momento, el curso del asunto corresponde a la autoridad judicial.\n\nAun en esta etapa es posible llegar a un arreglo conciliatorio que ponga fin al proceso. Si asi lo desea, puede comunicarse con nuestro Departamento Juridico.',
 'juridico', 70)

ON DUPLICATE KEY UPDATE asunto = VALUES(asunto), cuerpo = VALUES(cuerpo);

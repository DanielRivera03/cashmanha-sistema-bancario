-- ---------------------------------------------------------------------
-- 022 · Caja, cajero y notas contables
--
-- Hasta ahora un depósito o el pago de una cuota se registraban sin que
-- nadie respondiera por el efectivo: el asiento cuadraba en el libro
-- mayor, pero no había forma de saber quién recibió los billetes, en qué
-- caja, ni si al final del día esa caja cuadraba. En una institución
-- financiera eso es justo lo que se audita primero.
--
-- Lo que entra aquí:
--
--   · `cajas` — las ventanillas. Una caja es un sitio con un
--     responsable, no un concepto abstracto.
--
--   · `caja_sesiones` — el turno de un cajero en una caja. Se abre con
--     un fondo inicial, se opera, y se cierra contando el efectivo. La
--     diferencia entre lo que el sistema dice que debería haber y lo que
--     el cajero cuenta es el sobrante o faltante, y queda escrito.
--
--   · `caja_movimientos` — cada entrada o salida de efectivo del turno,
--     atada al asiento contable que la respalda. Es el puente entre el
--     dinero físico y la contabilidad.
--
--   · `caja_arqueo` — el desglose por denominación al cerrar. Contar
--     «$1,250 en total» no sirve para encontrar un error; contar
--     «12 de $100, 4 de $10, 2 de $5» sí.
--
--   · `notas_contables` — notas de abono y de cargo. Es como se corrige
--     un error o se aplica un ajuste autorizado SIN tocar un asiento ya
--     registrado: se emite otro asiento que lo compensa y queda dicho
--     quién lo autorizó y por qué.
--
-- Y el rol `cajero`, que hasta ahora no existía: las operaciones de
-- ventanilla las hacía «Atención a Clientes», que es otro trabajo.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- Las ventanillas
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cajas (
    id              SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    codigo          VARCHAR(20)  NOT NULL,
    nombre          VARCHAR(80)  NOT NULL,
    agencia         VARCHAR(80)  NOT NULL DEFAULT 'Oficina central',
    -- Tope de efectivo: pasado este monto hay que hacer una remesa a
    -- bóveda. Una caja con diez mil dólares es un riesgo, no una
    -- comodidad.
    tope_efectivo_cent BIGINT UNSIGNED NOT NULL DEFAULT 500000,
    activa          TINYINT(1)   NOT NULL DEFAULT 1,
    creada_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cajas_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- El turno del cajero
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS caja_sesiones (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid                CHAR(36)        NOT NULL,
    folio               VARCHAR(24)     NOT NULL,
    caja_id             SMALLINT UNSIGNED NOT NULL,
    usuario_id          INT UNSIGNED    NOT NULL,

    fondo_inicial_cent  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    abierta_en          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Lo que el sistema calcula que debe haber al cerrar.
    esperado_cent       BIGINT          NOT NULL DEFAULT 0,
    -- Lo que el cajero cuenta de verdad.
    contado_cent        BIGINT          NULL,
    -- contado - esperado. Positivo es sobrante, negativo faltante.
    diferencia_cent     BIGINT          NULL,

    cerrada_en          DATETIME        NULL,
    arqueada_por        INT UNSIGNED    NULL,
    arqueada_en         DATETIME        NULL,
    observaciones       TEXT            NULL,

    estado              ENUM('abierta','cerrada','cuadrada','descuadrada')
                        NOT NULL DEFAULT 'abierta',

    PRIMARY KEY (id),
    UNIQUE KEY uq_caja_sesiones_uuid (uuid),
    UNIQUE KEY uq_caja_sesiones_folio (folio),
    KEY ix_caja_sesiones_caja (caja_id, estado),
    KEY ix_caja_sesiones_usuario (usuario_id, estado),
    CONSTRAINT fk_caja_sesiones_caja    FOREIGN KEY (caja_id) REFERENCES cajas (id),
    CONSTRAINT fk_caja_sesiones_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_caja_sesiones_arqueo  FOREIGN KEY (arqueada_por) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- El efectivo que entra y sale del turno
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS caja_movimientos (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sesion_id     BIGINT UNSIGNED NOT NULL,

    tipo          ENUM('fondo_inicial','deposito','retiro','pago_credito',
                       'pago_tarjeta','nota_abono','nota_cargo',
                       'remesa_entrada','remesa_salida','ajuste')
                  NOT NULL,

    -- Positivo entra efectivo a la caja, negativo sale. El signo es la
    -- única forma honesta de cuadrarla.
    importe_cent  BIGINT       NOT NULL,

    cuenta_id     INT UNSIGNED NULL,
    cliente_id    INT UNSIGNED NULL,
    credito_id    INT UNSIGNED NULL,
    tarjeta_id    INT UNSIGNED NULL,
    asiento_id    BIGINT UNSIGNED NULL,

    referencia    VARCHAR(40)  NOT NULL,
    concepto      VARCHAR(190) NOT NULL,
    creado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_caja_mov_sesion (sesion_id, id),
    KEY ix_caja_mov_asiento (asiento_id),
    KEY ix_caja_mov_cliente (cliente_id),
    UNIQUE KEY uq_caja_mov_referencia (referencia),
    CONSTRAINT fk_caja_mov_sesion  FOREIGN KEY (sesion_id) REFERENCES caja_sesiones (id),
    CONSTRAINT fk_caja_mov_cuenta  FOREIGN KEY (cuenta_id) REFERENCES cuentas (id),
    CONSTRAINT fk_caja_mov_cliente FOREIGN KEY (cliente_id) REFERENCES usuarios (id),
    CONSTRAINT fk_caja_mov_asiento FOREIGN KEY (asiento_id) REFERENCES asientos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- El arqueo: el desglose por denominación
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS caja_arqueo (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sesion_id         BIGINT UNSIGNED NOT NULL,
    -- En centavos, para que un billete de $20 y una moneda de 25¢ vivan
    -- en la misma columna sin decimales.
    denominacion_cent INT UNSIGNED    NOT NULL,
    cantidad          INT UNSIGNED    NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_caja_arqueo (sesion_id, denominacion_cent),
    CONSTRAINT fk_caja_arqueo_sesion FOREIGN KEY (sesion_id) REFERENCES caja_sesiones (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Notas de abono y de cargo
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notas_contables (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uuid            CHAR(36)     NOT NULL,
    folio           VARCHAR(24)  NOT NULL,

    tipo            ENUM('abono','cargo') NOT NULL,
    cuenta_id       INT UNSIGNED NOT NULL,
    usuario_id      INT UNSIGNED NOT NULL,

    importe_cent    BIGINT UNSIGNED NOT NULL,
    concepto        VARCHAR(190) NOT NULL,
    -- El motivo es para la auditoría, el concepto es para el cliente.
    motivo          VARCHAR(500) NOT NULL,

    sesion_id       BIGINT UNSIGNED NULL,
    asiento_id      BIGINT UNSIGNED NULL,

    emitida_por     INT UNSIGNED NOT NULL,
    -- Una nota la emite el cajero y la autoriza alguien por encima: es
    -- la regla de los cuatro ojos. Sin ella, quien recibe el dinero
    -- podría acreditárselo a quien quisiera.
    autorizada_por  INT UNSIGNED NULL,
    autorizada_en   DATETIME     NULL,
    anulada_por     INT UNSIGNED NULL,
    anulada_en      DATETIME     NULL,
    motivo_anulacion VARCHAR(300) NULL,

    estado          ENUM('pendiente','aplicada','rechazada','anulada')
                    NOT NULL DEFAULT 'pendiente',
    creada_en       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_notas_uuid (uuid),
    UNIQUE KEY uq_notas_folio (folio),
    KEY ix_notas_estado (estado, id),
    KEY ix_notas_cuenta (cuenta_id),
    KEY ix_notas_usuario (usuario_id),
    CONSTRAINT fk_notas_cuenta   FOREIGN KEY (cuenta_id)  REFERENCES cuentas (id),
    CONSTRAINT fk_notas_usuario  FOREIGN KEY (usuario_id) REFERENCES usuarios (id),
    CONSTRAINT fk_notas_emite    FOREIGN KEY (emitida_por) REFERENCES usuarios (id),
    CONSTRAINT fk_notas_autoriza FOREIGN KEY (autorizada_por) REFERENCES usuarios (id),
    CONSTRAINT fk_notas_sesion   FOREIGN KEY (sesion_id) REFERENCES caja_sesiones (id),
    CONSTRAINT fk_notas_asiento  FOREIGN KEY (asiento_id) REFERENCES asientos (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Permisos del área de caja
-- ---------------------------------------------------------------------
INSERT INTO permisos (clave, descripcion) VALUES
  ('caja.abrir', 'Abrir un turno de caja con su fondo inicial'),
  ('caja.operar', 'Recibir y entregar efectivo en ventanilla'),
  ('caja.cerrar', 'Cerrar el turno contando el efectivo'),
  ('caja.arquear', 'Revisar y dar por cuadrado el turno de otro'),
  ('caja.ver_todas', 'Consultar los turnos de cualquier cajero'),
  ('nota.emitir', 'Emitir notas de abono y de cargo'),
  ('nota.autorizar', 'Autorizar o rechazar las notas emitidas'),
  ('nota.anular', 'Anular una nota ya aplicada, con reverso')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- ---------------------------------------------------------------------
-- El rol de cajero
-- ---------------------------------------------------------------------
INSERT INTO roles (clave, nombre, descripcion, nivel) VALUES
  ('cajero', 'Cajero',
   'Atiende la ventanilla: recibe y entrega efectivo, cobra cuotas y tarjetas, emite notas y cuadra su caja.',
   40)
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- Lo que puede hacer un cajero. Deliberadamente NO incluye
-- `nota.autorizar`: quien recibe el dinero no se autoriza a sí mismo.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN (
        'caja.abrir', 'caja.operar', 'caja.cerrar',
        'nota.emitir',
        'deposito.registrar', 'retiro.registrar',
        'cuenta.ver_todas', 'cliente.ver_expediente',
        'credito.ver_todos',
        'tarjeta.ver_todas',
        'estado_cuenta.generar',
        'mensaje.enviar'
       )
 WHERE r.clave = 'cajero'
ON DUPLICATE KEY UPDATE rol_id = rol_permiso.rol_id;

-- Gerencia y administración arquean y autorizan notas.
INSERT INTO rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permisos p ON p.clave IN (
        'caja.arquear', 'caja.ver_todas', 'caja.abrir', 'caja.operar',
        'caja.cerrar', 'nota.emitir', 'nota.autorizar', 'nota.anular'
       )
 WHERE r.clave IN ('administrador', 'presidencia', 'gerencia')
ON DUPLICATE KEY UPDATE rol_id = rol_permiso.rol_id;

-- ---------------------------------------------------------------------
-- Parámetros y la primera caja
-- ---------------------------------------------------------------------
INSERT INTO parametros (clave, valor, descripcion) VALUES
  ('caja.diferencia_tolerada_cent', '100',
   'Diferencia de arqueo que se considera cuadre (centavos)'),
  ('caja.fondo_sugerido_cent', '20000',
   'Fondo inicial sugerido al abrir un turno de caja')
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

INSERT INTO cajas (codigo, nombre, agencia, tope_efectivo_cent) VALUES
  ('CJ-01', 'Ventanilla 1', 'Oficina central', 500000),
  ('CJ-02', 'Ventanilla 2', 'Oficina central', 500000)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

<h1 align="center">🏦 CashMan H.A.</h1>

<p align="center">
  <b>Sistema bancario web completo</b> — banca en línea, créditos con amortización, tarjetas de crédito y débito,
  ventanilla, transferencias, interbancario (Transfer365) y pasarela de pago.<br>
  <sub>Núcleo bancario construido en PHP 8 sobre una arquitectura por capas, sin framework, con seguridad de grado producción.</sub>
</p>

<p align="center">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.2-777BB4?style=for-the-badge&logo=php&logoColor=white">
  <img alt="MariaDB" src="https://img.shields.io/badge/MariaDB%20%2F%20MySQL-003545?style=for-the-badge&logo=mariadb&logoColor=white">
  <img alt="Bootstrap" src="https://img.shields.io/badge/Bootstrap-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white">
  <img alt="JavaScript" src="https://img.shields.io/badge/JavaScript-F7DF1E?style=for-the-badge&logo=javascript&logoColor=black">
  <img alt="Stripe" src="https://img.shields.io/badge/Stripe-635BFF?style=for-the-badge&logo=stripe&logoColor=white">
</p>

<p align="center">
  <img alt="Estado" src="https://img.shields.io/badge/estado-demo%20en%20vivo-success?style=flat-square">
  <img alt="Argon2id" src="https://img.shields.io/badge/passwords-Argon2id%20%2B%20pepper-critical?style=flat-square">
  <img alt="Tablas" src="https://img.shields.io/badge/tablas-111-blue?style=flat-square">
  <img alt="Líneas" src="https://img.shields.io/badge/PHP-~106K%20líneas-informational?style=flat-square">
  <img alt="Licencia" src="https://img.shields.io/badge/licencia-educativa-lightgrey?style=flat-square">
  <img alt="El Salvador" src="https://img.shields.io/badge/hecho%20en-El%20Salvador%20🇸🇻-005cbf?style=flat-square">
</p>

<p align="center">
  🌐 <b>Demo en vivo / Live demo:</b> <a href="https://svcashmanha.unaux.com">https://svcashmanha.unaux.com</a>
</p>

<p align="center">
  <sub>
    🇪🇸 Sistema bancario web completo en PHP 8 y MySQL/MariaDB &nbsp;·&nbsp;
    🇬🇧 A complete web-based <b>core banking system</b> built with PHP 8 &amp; MySQL/MariaDB
  </sub>
</p>

---

## 📋 Tabla de contenidos

- [¿Qué es CashMan H.A.?](#-qué-es-cashman-ha)
- [Características por módulo](#-características-por-módulo)
- [El sitio público](#-el-sitio-público)
- [Roles y permisos](#-roles-y-permisos)
- [Productos, tasas y amortización](#-productos-tasas-y-amortización)
- [Tarjetas y estados de cuenta](#-tarjetas-y-estados-de-cuenta)
- [Procesos y transacciones](#-procesos-y-transacciones)
- [Tienda, pasarela y compras a cuotas](#-tienda-pasarela-y-compras-a-cuotas)
- [Stack tecnológico](#-stack-tecnológico)
- [Arquitectura](#️-arquitectura)
- [Seguridad](#-seguridad)
- [El proyecto en números](#-el-proyecto-en-números)
- [Requisitos](#️-requisitos)
- [Instalación paso a paso](#-instalación-paso-a-paso)
- [Usuarios de prueba](#-usuarios-de-prueba)
- [Cómo probar la demo](#-cómo-probar-la-demo)
- [Estructura del proyecto](#-estructura-del-proyecto)
- [Notas y limitaciones](#️-notas-y-limitaciones)
- [Capturas del sistema](#-capturas-del-sistema)
- [Licencia](#-licencia)
- [Autor](#-autor)

---

## 🏦 ¿Qué es CashMan H.A.?

**CashMan H.A.** es un **núcleo bancario (core banking) web** que simula la operación completa de una institución
financiera salvadoreña: desde el portal del cliente hasta la ventanilla del cajero, la gerencia, el área jurídica y
la junta directiva. No es un prototipo de pantallas: detrás hay un **libro mayor contable**, cálculo real de
**amortización y mora**, **cortes de tarjeta**, **compensación de cartera**, **liquidación interbancaria** y una
**pasarela de pago** funcional.

Está escrito en **PHP 8 puro**, sin framework, sobre una **arquitectura por capas (DDD)**, con decisiones de
**seguridad de grado producción** documentadas en el propio código.

> 💡 Es la versión 2 — una reescritura completa — del sistema bancario original, con foco en integridad de datos,
> seguridad y fidelidad contable.

> 🌍 **In English:** **CashMan H.A.** is a full-featured **web banking / core banking system** built in **PHP 8**
> (no framework) with **MySQL/MariaDB**: bank accounts, **loans with amortization schedules**, **credit & debit cards**,
> wire transfers, **interbank payments** (Transfer365), a **Stripe payment gateway**, teller/branch operations,
> collections and more — engineered with production-grade security (**Argon2id**, AES-256-GCM, CSP, CSRF, rate limiting, 2FA).
> Ideal as a reference **online banking source code** / fintech project.

---

## ✨ Características por módulo

| Módulo | Qué hace |
|---|---|
| 💳 **Cuentas** | Cuentas de ahorro/corriente, depósitos, retiros, estados de cuenta en PDF, intereses. |
| 📈 **Créditos** | Solicitud, aprobación, desembolso, **tabla de amortización**, **mora por día**, seguros obligatorios, reestructuración, arreglos de pago, condonaciones y finiquito. |
| 🪪 **Tarjetas** | Crédito y débito, **cortes mensuales**, pago mínimo/contado, **compras a cuotas (diferido)**, **adelanto de efectivo**, sobregiro, líneas y catálogo de productos. |
| 🔄 **Transferencias** | Entre cuentas propias y a terceros, con verificación por código (OTP) y autorización reforzada por monto. |
| 🏦 **Interbancario** | **Transfer365**, agenda de destinatarios, límites, y **compensación/liquidación** entre instituciones. |
| 💠 **Pasarela de pago** | Cobro con tarjeta (Visa / Mastercard / Amex): **Stripe** (modo prueba) para tarjetas de otros bancos, o la **red adquirente interna** del propio banco (ISO 8583 simulado) para las tarjetas CashMan, sin dependencias externas. |
| 🧾 **Ventanilla (Caja)** | Apertura/cierre de caja por cajero, efectivo y cheque, **notas contables**, asientos. |
| ⚖️ **Cobranza y Jurídico** | Cartera morosa, gestión de cobranza, expediente jurídico. |
| 🛒 **Tienda** | Catálogo público, carrito, pedidos de varias tiendas, cobros y despacho. |
| 💰 **Depósitos a plazo** | Constitución, intereses y vencimiento. |
| 🧰 **Más** | Chequeras, remesas familiares, pago de servicios, constancias, órdenes programadas, cumplimiento/prevención de lavado, banca preferente/segmentos, ofertas preaprobadas, evaluación de riesgo/buró, y panel para la **Junta Directiva**. |
| 🔔 **Tiempo real** | Notificaciones en vivo (SSE), reloj, clima y avisos sin recargar la página. |

---

## 🌐 El sitio público

Todo el tráfico entra por un **único *front controller*** (`public/index.php`) que arranca el sistema, fija las cabeceras de seguridad y la CSP con *nonce*, y registra todas las rutas sobre `CashMan\Http\Enrutador`. Una ruta es **privada solo si su *middleware* incluye `'auth'`**; sin ese guardián, es pública. La portada **no es la pantalla de acceso**, sino el **sitio institucional**, indexable por buscadores (la pantalla de login lleva `noindex`).

### Lo que ve un visitante sin iniciar sesión

La portada (`/`) es una página institucional con arte **100 % SVG en línea** (sin una sola fotografía ni recurso externo, para mantener la CSP cerrada). Sus secciones:

| Sección | Contenido |
|---|---|
| **Hero** | *"Con mucho gusto, le acompañamos"* — CTA **"Abrir mi cuenta en línea"** → `/registro` y **"Ya soy cliente"** → `/entrar`. |
| **¿En qué podemos servirle?** | Ahorro, cuenta corriente, créditos y tarjetas, **con la tasa de hoy leída en vivo de la tabla `productos`** (la más baja para préstamos, la más alta para depósitos). Nada escrito a mano. |
| **Galería de tarjetas** | Un plástico por producto, cada uno con su acabado (clásica/oro/platinum/signature/infinite/obsidiana/cashback) dibujado en SVG, con su "línea desde $X". |
| **Cuenta Digital** | Autoservicio en minutos, sin agencia ni papeles (3 pasos). |
| **Seguridad** | Contraseña solo del cliente, segundo factor opcional, cada movimiento registrado, cierre de sesión automático. |
| **Cifras** | Agregados institucionales reales desde la base (nº de clientes, cuentas, créditos vigentes, sucursales). |

**Datos de la institución** (pie de página): *CashMan H.A. El Salvador, S.A. de C.V.*, San Salvador; teléfono **2222-0000** (L-V 8:00–17:00, Sáb 8:00–12:00); reporte de tarjeta extraviada 24 h **2222-0001**; nota legal: *"Entidad sujeta a la Ley de Bancos y a la supervisión de la Superintendencia del Sistema Financiero"*. (Datos ficticios de demostración.)

### Rutas públicas (sin sesión)

| Ruta | Qué es |
|---|---|
| `GET /` · `/personas` · `/empresas` · `/nosotros` · `/sucursales` · `/preguntas` | Sitio institucional y FAQ. |
| `GET\|POST /contacto` | Formulario de contacto (CSRF + límite 5 msg/hora por IP + campo señuelo *honeypot*). |
| `GET\|POST /entrar` · `/salir` | Acceso / cierre de sesión. |
| `GET\|POST /registro` | Alta de nuevos clientes (ver abajo). |
| `GET\|POST /recuperar` · `/restablecer/{token}` | Recuperación de contraseña. |
| `GET /tienda` · `/tienda/articulo/{codigo}` · `/tienda/carrito` · `/tienda/pagar` · `/tienda/seguimiento` | Tienda pública (navegar, carrito, pagar y seguir el pedido sin cuenta). |
| `GET\|POST /verificar-constancia` | Verificación de constancias por terceros (patrono, embajada, otro banco). |
| `GET\|POST /pagar-enlace/{token}` | Enlace de pago que el cliente abre desde su correo; lo protege el token de un solo uso. |
| `POST /api/stripe` | *Webhook* de Stripe (se autentica por firma, no por sesión). |

### Registro de clientes

El sitio permite el **autoregistro** ("el único camino que empieza con alguien que no es nadie"). Pide **nueve campos**: nombres, apellidos, correo, **tipo de documento** (DUI / NIT / carné de residente) + número con máscara, teléfono, fecha de nacimiento (**mínimo 18 años**, configurable), contraseña y confirmación, con medidor de fuerza (Argon2id). Protecciones: **máx. 10 altas/hora por IP**, CSRF, el registro puede estar **abierto o cerrado**, respuesta **idéntica exista o no el correo/documento** (no filtra quién ya es cliente) y **sin auto-login** (tras crear la cuenta redirige a `/entrar`).

### Acceso (login) y 2FA

Un solo campo **"Usuario o correo"** (acepta ambos), contraseña con ver/ocultar, **"Recordar usuario"** y enlace a recuperación. Si la cuenta tiene **segundo factor (TOTP)** activado, aparece un segundo paso que pide el **código de 6 dígitos** de la app autenticadora (con contador de 30 s) o un **código de respaldo** `XXXXX-XXXXX`. El 2FA es **opcional por usuario**, no global. La recuperación de contraseña también responde igual exista o no el correo (anti-enumeración).

> 🕑 El **reloj, el clima y el saludo** NO están en el sitio público: viven en el **panel ya autenticado**. Los canales de tiempo real (`/eventos`, `/api/pulso`, `/api/notificaciones`) exigen sesión.

---

## 👥 Roles y permisos

El control de acceso es un **RBAC dinámico y granular guardado en base de datos** (`src/Infraestructura/Seguridad/Autorizacion.php`): **130 permisos** repartidos sobre **9 roles** mediante la matriz `rol_permiso`. No hay roles "cableados" por nombre en el código — todo se consulta en caliente — y la matriz es **editable desde el sistema** (pantalla de roles/permisos, solo administrador).

**Dos niveles de autorización:**

1. **¿Puede el *rol* ejecutar la *acción*?** — `Autorizacion::puede('transferencia.ejecutar')`.
2. **¿Puede el *usuario* actuar sobre *este objeto*?** — `exigirCuenta()`, `exigirTarjeta()`, etc. comprueban la **propiedad** del recurso o un permiso de "ver todas". Esto cierra el clásico *IDOR* (operar sobre una cuenta ajena cambiando un id oculto).

El **"mando total"** no lo da poseer todos los permisos, sino un **nivel ≥ 100** (`roles.nivel`), *"porque renombrar un rol no debería abrir ni cerrar nada"*. El **menú se arma por permiso**, no por rol: cada usuario ve exactamente el subconjunto que sus permisos habilitan. Nadie puede crear o elevar un rol de nivel **≥ al suyo**.

| Rol | Nivel | Alcance | Permisos |
|---|---:|---|---:|
| **Administrador** | 100 | Mando total: configura el sistema, productos, límites, usuarios y la propia matriz de roles. | 129/130 |
| **Junta Directiva** | 95 | Gobierno, **solo lectura**: tablero de indicadores, reportes, auditoría, cumplimiento. No opera. | 5 |
| **Presidencia** | 90 | Aprobación de alto valor: etapa final de crédito, desembolso, aprobación de transferencias grandes. | 100 |
| **Gerencia** | 80 | Cartera y crédito: revisión intermedia, aprobar/desembolsar, ajustar tasa, emitir tarjetas, cobranza. | 95 |
| **Jurídico** | 60 | Cobranza judicial y PLD: gestión y escalamiento de mora, vía judicial, contratos, expedientes. | 24 |
| **Atención a Clientes** | 50 | Ventanilla y soporte: captura de solicitudes, depósitos/retiros, remesas, constancias, expedientes. | 53 |
| **Banquero** | 50 | Banca privada: gestiona su **cartera asignada** de clientes; consultivo, no maneja efectivo. | 21 |
| **Cajero** | 40 | Ventanilla de efectivo: abre/opera/cierra su caja, depósitos, retiros, cheques, notas de caja. | 23 |
| **Cliente** | 10 | Portal de banca en línea, **solo sobre lo propio**. | 31 |

<details>
<summary><b>Qué puede hacer cada rol (detalle)</b></summary>

- **Administrador** — único con `sistema.configurar`, `usuario.crear/bloquear`, `parametro.editar`, `mora.devengar/condonar`, `tarjeta.cortar`. Todo lo suyo queda auditado.
- **Junta Directiva** — `junta.ver`, `reporte.ver`, `auditoria.ver`, `comprobante.ver_todos`, `cumplimiento.ver`. Ve el **tablero de gobierno** (indicadores y evolución del banco). No mueve dinero.
- **Presidencia** — casi todo salvo configurar el sistema y crear usuarios. Etapa final del crédito (`credito.aprobar`, `credito.desembolsar`), `transferencia.aprobar`, `reestructuracion.aprobar`. Mayor tope de transferencia: **$200,000/operación**.
- **Gerencia** — revisión intermedia del crédito, `credito.aprobar/desembolsar/ajustar_tasa`, `tarjeta.emitir`, cartera morosa, cobranza, cumplimiento, conciliación de pagos, ofertas preaprobadas.
- **Jurídico** — `cobranza.*` (incluida la **vía judicial**, que casi nadie más tiene), arreglos de pago, generación de contratos, expedientes, reclamos, y analista **PLD/AML** (`cumplimiento.analizar/expediente`).
- **Atención a Clientes** — captura de solicitudes de crédito/tarjeta, `deposito.registrar`, `retiro.registrar`, transferencias a terceros, pago de remesas, constancias, cheques, edición de expediente, verificación de referencias/documentos, atención de reclamos y tickets.
- **Banquero** — gestiona su cartera (`/cartera`, banca preferente), constancias, plazos de terceros, ve tarjetas/créditos/cuentas de sus clientes. Consultivo: no registra efectivo ni aprueba créditos.
- **Cajero** — `caja.abrir/operar/cerrar`, depósitos, retiros, pago de cheques y remesas, **notas de caja** (que un superior autoriza), generación de estados de cuenta. No arquea su propio turno.
- **Cliente** — ver/abrir cuentas propias, transferir (tope **$2,000/operación**), tarjetas (ver datos, pagar, configurar, **diferir**, adelanto, traslado), solicitar crédito, abrir plazo fijo, chequera, constancias, firmar contratos, pagar por pasarela, comprar en la tienda, activar su 2FA.

> **Cumplimiento/PLD, Cobranza, Tienda y Auditoría no son roles separados**: son **permisos** repartidos sobre los roles existentes.

</details>

### Flujos de aprobación (separación de funciones)

- **Crédito — flujo escalonado:** el cliente **solicita** → **Atención** captura y revisa → **Gerencia** revisa → **Presidencia** revisa → se **aprueba** → se **desembolsa**. Cada etapa exige el permiso del revisor de la *siguiente*, y **quien captura no aprueba su propia etapa final**.
- **Tarjetas:** Atención revisa → Gerencia revisa → se emite.
- **Transferencias de alto valor:** sobre el `umbral_doble_control` del rol se dispara **doble control (maker-checker)** — una segunda persona con `transferencia.aprobar` debe confirmar.
- **Notas de caja:** el cajero **emite**, un superior **autoriza** (cuatro ojos); un cargo nunca puede dejar la cuenta en descubierto.
- **Cobranza escalada:** gestión → escalar → **vía judicial** (solo Jurídico/Gerencia/Presidencia/Admin).

---

## 💵 Productos, tasas y amortización

> Convención interna: **todo el dinero en centavos enteros** (nunca `float`) y **las tasas en puntos base** (bp), donde `100 bp = 1 %`. Las tasas de catálogo viven en la tabla `productos` y son **configurables**; las fórmulas viven en `src/Dominio`.

### Cuentas de depósito

| Código | Producto | Tasa anual | Saldo mínimo | Mercado |
|---|---|---:|---:|---|
| `AHO-PER` | Cuenta de Ahorro Personal | **4.50 %** (banda 3.50–4.50) | $1.00 | Persona |
| `AHO-EMP` | Cuenta de Ahorro Empresarial | **3.75 %** (2.75–3.75) | $50.00 | Empresa |
| `AHO-DIG` | Cuenta Digital | **1.00 %** | $0.00 | Ambos |
| `CTE-PER` | Cuenta Corriente (con chequera) | 0 % (transaccional) | $100.00 | Persona |

- **Sin comisión de manejo.** El único requisito de mantenimiento es el **saldo mínimo** del producto.
- **Interés de ahorro:** se calcula sobre el **saldo diario promedio** del mes, base **360 días**; requiere un promedio ≥ **$100** para generar interés; **retención de renta del 10 %** (exenta si el interés mensual < $0.50). Los segmentos preferentes suman **+0.50 %** / **+1.00 %** a la tasa.
- **Cuenta Digital:** apertura sin depósito, con topes operativos de **$1,000/día** y **$3,000 de saldo máximo**.

### Depósitos a plazo fijo (DPF)

Tasa por **tramo de días**, con interés **simple** (`capital × tasa × días / 360`, truncado), **retención del 10 %** sobre los intereses (exenta si el capital ≤ $25,000) y **el capital nunca se toca**:

| Plazo | Tasa anual | | Plazo | Tasa anual |
|---|---:|---|---|---:|
| 30–59 días | 2.25 % | | 270–359 días | 4.75 % |
| 60–89 días | 2.75 % | | 360–539 días | 5.50 % |
| 90–179 días | 3.50 % | | 540–719 días | 6.00 % |
| 180–269 días | 4.25 % | | 720–1080 días | 6.50 % |

**Retiro anticipado:** se pagan los intereses devengados a la fecha **menos una penalidad del 50 %** sobre esos intereses; **el capital siempre vuelve íntegro**.

### Créditos

| Código | Producto | Tasa nominal | Banda ajustable | Monto | Plazo |
|---|---|---:|---|---|---|
| `CRE-PER` | Personal | **22.00 %** | 16–30 % | $300 – $75,000 | 6–72 m |
| `CRE-HIP` | Hipotecario | **12.50 %** | 9–16 % | $15,000 – $3,000,000 | 60–360 m |
| `CRE-VEH` | Vehículo | **15.00 %** | 12–20 % | $5,000 – $75,000 | 12–96 m |
| `CRE-PYM` | Negocio (PYME) | **19.00 %** | 15–26 % | $2,000 – $500,000 | 12–120 m |
| `CRE-EDU` | Educativo | **12.00 %** | 9–16 % | $500 – $50,000 | 12–120 m |

- **Amortización — sistema francés (cuota constante):** `C = P · i / (1 − (1+i)⁻ⁿ)`, con `i` la tasa mensual; todo en centavos enteros y **la última cuota absorbe el redondeo** para cerrar en cero exacto. El **hipotecario** financia hasta el **90 % del valor**.
- **Capacidad de pago:** la cuota no puede exceder el **40 % del ingreso disponible**.
- **Tasa ajustable:** un oficial autorizado mueve la tasa dentro de la banda del producto; la tasa pactada se **congela en el crédito** (`creditos.tasa_bp`) — cambiar el catálogo **no altera contratos vivos**.
- **Mora:** cargo **diario fijo de $5.99 por cuota atrasada** (cláusula penal del contrato, no proporcional al saldo), acotado a que **nunca supere el saldo vencido** de la cuota. Días de gracia configurables.
- **Seguros obligatorios:** mensuales sobre el saldo deudor (**desgravamen/vida $0.90 por millar**, **desempleo $1.10 por millar**, mínimo $0.50), más un **seguro + gasto administrativo** fijo al desembolso por tramo de monto (p. ej. un crédito de consumo de $1,000–2,499 paga $6.95 de seguro + $6.00 de gasto, que se descuentan del monto a recibir).

### Segmentos / banca preferente

Clasificación **automática** (por ingreso, depósitos, línea, riesgo y antigüedad), no una etiqueta manual:

| Beneficio | Estándar | Preferente | Patrimonial |
|---|:--:|:--:|:--:|
| Requisito de ingreso | — | ≥ $2,500/mes | ≥ $8,000/mes |
| Descuento de tasa de crédito | — | **−1.00 %** | **−2.50 %** |
| Bono en tasa de ahorro | — | **+0.50 %** | **+1.00 %** |
| Línea de tarjeta (× ingreso) | 3× | 4× | 5× |
| Compras diferidas simultáneas | 5 | 8 | 12 |
| Descuento de membresía | — | 50 % | 100 % |
| Sobregiros sin comisión / año | 0 | 1 | 3 |
| Banquero asignado | no | no | sí |

El descuento de tasa se aplica también a **créditos ya vivos** (deja constancia de quién y por qué regla, baja la cuota manteniendo el plazo).

---

## 💳 Tarjetas y estados de cuenta

### Catálogo de tarjetas

| Código | Producto | Marca | Tasa corriente | Ingreso mín. | Línea desde | Membresía |
|---|---|---|---:|---:|---:|---:|
| `TAR-INI` | Inicia | Visa | 48 % | $300 | $200 | $0 |
| `TAR-CLA` | Clásica | Visa | 44 % | $450 | $500 | $0 |
| `TAR-CAS` | Cashback | Mastercard | 42 % | $450 | $750 | $0 |
| `TAR-ORO` | Oro | Visa | 38 % | $1,000 | $2,000 | $35 (1er año gratis) |
| `TAR-PLA` | Platinum | Mastercard | 34 % | $2,500 | $5,000 | $95 |
| `TAR-EMP` | Empresarial | Mastercard | 33 % | $4,000 | $500 | $125 |
| `TAR-SIG` | Signature | Amex | 30 % | $5,000 | $15,000 | $195 |
| `TAR-INF` | Infinite | Visa | 27 % | $8,000 | $30,000 | $395 |
| `TAR-OBS` | Obsidiana | Amex | 24 % | $25,000 | $75,000 | $795 |
| `TAR-DEB` | Débito | Visa | — | — | ligada a cuenta | $0 |

El **límite real** no es fijo por producto: `linea_desde` es el piso y al emitir se asigna hasta **3× el ingreso disponible** (más por segmento). La **tarjeta de débito** va ligada a una cuenta de ahorro, sin línea, sin intereses y **nunca entra al corte**.

### Intereses de tarjeta de crédito

- La tasa corriente es la del producto **menos el descuento del segmento** del titular, leída en cada corte (no se congela).
- **Quien paga de contado no paga intereses:** el interés mensual solo recae sobre el **saldo financiado del periodo anterior** (`financiado × tasa/12`). Si el corte anterior quedó en cero, el periodo se financia gratis.
- Los **planes a tasa cero** se **apartan de la base de intereses y de la de seguros**.
- El estado de cuenta imprime además la **tasa efectiva anual** `((1 + nominal/12)¹² − 1)`.

### Ciclo de corte (estado de cuenta)

- **Día de corte** configurable (por defecto 25, fijado entre 1 y 28 para no tropezar con febrero).
- **Fecha límite de pago** = fecha de corte **+ 20 días**.
- Cierra **un mes a la vez** (si la tarjeta lleva meses sin cortarse, no salta huecos).
- **Saldo al corte** = saldo anterior + consumos + cargos (comisión + mora) + intereses − pagos + seguros (vida y desempleo sobre el saldo, $1.20 y $1.40 por millar).

**Pago mínimo** — calculado por un único método compartido por la plataforma, el PDF y el correo:

```
recurrente  = min( saldo, max( 5 % del saldo,  piso $10,  cuota del plazo + interés del mes ) )
excedente   = max( 0, saldo − límite )          // si gastó por encima del cupo, exigible de inmediato
pago_mínimo = min( saldo, recurrente + excedente )
```

El **pago de contado** es el saldo completo; **el excedente sobre el límite** se marca en rojo y, si no se cubre a tiempo, **inhabilita la tarjeta** para nuevos consumos.

### Compras a cuotas, adelantos y sobregiro

| Función | Condición | Costo |
|---|---|---|
| **Diferido a cuotas** | 2 a 48 cuotas (mín. $5/cuota), máx. 5 planes vivos (más por segmento) | **Tasa cero** o **con interés 18 %** (simple, saldos decrecientes) |
| **Adelanto de efectivo** | Hasta el cupo libre (monto + cargos) | 5 % (mín. $5) + $3 de gasto administrativo |
| **Traslado de saldo** | Desde otra tarjeta | 3 % (mín. $3) + $2 fijo |
| **Sobregiro** | Tolerancia hasta 15 % del límite | 10 % del excedido (mín. $25) |
| **Extrafinanciamiento** | ≥ 3 cortes sin mora, uso ≤ 70 %, hasta 50 % de la línea (mín. $200) | **24 % anual** a 12 meses (cuota francesa) |

### Reconciliación (cómo no se descuadra)

- **Tres superficies, una sola cifra:** el **pago mínimo** y los **"meses si paga el mínimo"** deben decir **exactamente lo mismo** en la banca en línea, el PDF y el correo — por eso el cálculo vive en un único método. El recálculo **nunca sube el mínimo de un corte ya vencido** (castigaría a quien pagó a tiempo el mínimo anterior).
- **El saldo manda sobre el arrastre:** `saldo_usado` de una tarjeta **= la suma de sus movimientos liquidados**. Si el saldo arrastrado difiere, se corrige a la cifra de los movimientos (cada uno con fecha y comercio, verificable por el cliente). Esta igualdad es una de las **13 comprobaciones del verificador de cuadre**.

---

## 🔁 Procesos y transacciones

**Todo movimiento de dinero pasa por el `LibroMayor`** (`src/Dominio/Comun/LibroMayor.php`): cada asiento son **≥ 2 movimientos que suman cero** (partida doble), **idempotentes** (doble clic / F5 / reintento devuelven el mismo asiento, no uno nuevo) y **encadenados por hash** (alterar un asiento rompe la cadena). No existe otra forma de alterar un saldo.

- **Códigos de un solo uso (OTP):** 6 dígitos, **un solo uso**, **3 intentos**, **vigencia 5 min**, atados a la operación concreta por un hash de contexto (origen|destino|monto). Siempre caen en la **🔔 campana**; al correo solo si el correo está habilitado.
- **Límites por rol** (`limites_operacion`): por operación, por día, por mes y por nº de operaciones diarias; `requiere_otp` y `umbral_doble_control` (maker-checker).

### Depósitos y retiros

En **ventanilla**, el cajero registra depósitos (efectivo, cheque, transferencia o tarjeta) y retiros (verificando fondos, saldo retenido y saldo mínimo). Un **retiro a distancia** exige OTP; en ventanilla la identidad es presencial. El cliente **no maneja efectivo por el portal**: su "depósito" es con tarjeta externa vía pasarela (entre $1 y $10,000).

### Transferencias internas

Pasos reales de `ServicioTransferencia::ejecutar()`: (1) permiso de rol; (2) monto válido; (3) origen ≠ destino; (4) **propiedad de la cuenta origen** (anti-IDOR); (5) destino activo y misma moneda; (6) límites de servidor (operación, diario, mensual, nº de ops); (7) **OTP** si el rol lo exige; (8) **doble control** si el monto supera el umbral (crea una aprobación que caduca en 24 h); (9) ejecución **atómica** con bloqueo pesimista en orden de id (sin *deadlock*); (10) notificación en vivo a ambas partes; (11) **compensación** sobre el destino.

| Rol | Máx. por operación | Diario | OTP | Doble control |
|---|---:|---:|:--:|---:|
| Cliente | $2,000 | $5,000 | sí | — |
| Cajero | $10,000 | $50,000 | sí | $5,000 |
| Gerencia | — | — | sí | $20,000 |
| Presidencia | — | — | sí | $100,000 |
| Administrador | $500,000 | $2,000,000 | sí | $200,000 |

### Interbancario (Transfer365)

Envíos a otras instituciones por los canales **Transfer365 / Transfer365 Móvil / UNI**. El destino **debe estar en la agenda** de beneficiarios (no se teclea al vuelo) y un destino nuevo tiene un **enfriamiento de 12 h**. Hay **dos techos** (institucional + por cliente), OTP y doble control. Contablemente usa la **cuenta técnica de cámara**; **mismo banco acredita al instante**; otro banco espera a la **ventana** (Transfer365 00:00–22:00 todos los días; UNI 08:40–16:00 hábiles); un rechazo genera un **asiento de reverso** (el original nunca se toca). **Comisión: $0** (gratuito por diseño del BCR). El acuse de la red está **simulado**; lo contable (asiento, cámara, reverso, bitácora) es **real**.

### Compensación de cartera

Cuando entra dinero (depósito, transferencia recibida, nota de abono), el sistema lo aplica automáticamente a la **mora vencida** del receptor — pero solo si la cuenta lo **autorizó por contrato**, respetando un **mínimo intocable**. Reparte lo tomable **en proporción** a lo exigible de cada producto (incluye créditos **castigados** y **tarjetas** vencidas), y dentro de cada crédito ataca la **cuota más antigua primero**. Invariante clave: **un abono parcial va íntegro a la cuota; la mora solo se cobra cuando el abono liquida la cuota completa** (evita que todo el pago se vaya en recargos dejando la deuda igual).

### Ventanilla / caja

El **turno** es quien responde por el efectivo: **una caja = un turno, un cajero = un turno**. Al cerrar, el conteo se hace **denominación por denominación**; el "esperado" suma **solo efectivo** (cheques y tarjeta no dejan billete en la gaveta); la diferencia marca el turno como **cuadrado o descuadrado** y un descuadre avisa a gerencia. El **arqueo lo hace otra persona** (cuatro ojos). Las **notas contables** corrigen sin editar asientos: nacen pendientes, las **autoriza un segundo** y se anulan con un reverso.

### Verificador de cuadre y "latido"

- **`bin/verificar-cuadre.php` — 13 comprobaciones** que el libro mayor no cubre por sí solo: cuota = capital + interés + seguros; capital por cobrar = saldo; la última cuota deja cero; nadie pagó de más; mora diaria uniforme; cuenta = su último movimiento; asientos de partida doble; **saldo de tarjeta = suma de sus movimientos**; mora guardada = la del contrato; **la cámara interbancaria cuadra**; y que **el sistema corra solo**.
- **El "latido" (`bin/latido.php`)** es un *worker* en segundo plano (**uno solo a la vez**, con *lock*) que, sin depender de visitas web ni del Programador de Windows, ejecuta las tareas periódicas que mueven saldos: **devengar mora**, **cortar tarjetas**, abonar intereses, **compensar**, **despachar interbancarias**, **conciliar la pasarela**, ejecutar débitos programados, vencer plazos/remesas, vigilar cumplimiento (AML) y reclamos, revisar segmentos y verificar el cuadre. Se **autolevanta** ante cualquier contacto con el sistema.

---

## 🛒 Tienda, pasarela y compras a cuotas

### La tienda es un marketplace de comercios afiliados

No es un catálogo plano: hay **8 comercios afiliados** (ficticios) de CashMan H.A., cada uno con su rubro, su **envío propio** y los **meses sin intereses** que ofrece. Un mismo carrito, si mezcla comercios, produce **un pedido (y folio) por comercio** pero **un solo cobro** (transacción "todo o nada"). El carrito vive en la sesión y guarda **solo id + cantidad** — los precios se releen del catálogo en cada paso, para que nadie "compre una refrigeradora a tres dólares" editando la sesión.

**Estados del pedido:** `pago_pendiente → pagado → preparando → enviado → entregado` (o `anulado`), con **orden estricto** (no salta pasos ni retrocede); el paso a "enviado" exige transportista y guía. Un invitado recibe los avisos **por correo**; un cliente con sesión, por notificación en el portal. Se puede **seguir un pedido sin cuenta** con folio + clave de consulta.

### Dos pasarelas

- **Red adquirente interna** (para tarjetas CashMan): una simulación fiel de red de tarjetas con **códigos de respuesta ISO 8583 reales** (`00` aprobada, `51` fondos, `54` vencida, `82` CVV…), validación **Luhn**, búsqueda por `pan_hash` (HMAC) y comparación de CVV en **tiempo constante**. Una tarjeta ajena se rechaza con el **mismo código** que un número mal formado, para que el formulario no sea un oráculo de qué tarjetas existen.
- **Stripe** (tarjetas de otros bancos, **modo prueba**): cliente HTTP propio contra la API de Stripe **sin SDK**, con **PaymentIntent** idempotente; la tokenización la hace Stripe.js en el navegador — **el servidor nunca ve el número**. El **webhook se verifica por firma HMAC** (tiempo constante) y el cobro se confirma por **triple vía** (webhook + consulta desde el navegador + barrido de conciliación), todas idempotentes. En el modo por defecto (`simulada`) el pago con tarjeta externa queda deshabilitado; la vía operativa es la red interna.

### Compras a tasa cero y diferido

- **Tasa cero (0 %) en el checkout:** cada comercio publica sus **meses sin intereses** (12, 15, 18, 24…). Al elegir el plazo, se crea un **plan `tasa_cero`** en la tarjeta: el total es **exactamente** el precio de etiqueta, repartido sin centavos sueltos, y el corte lo **aparta de la base de intereses y de seguros** (sin intereses significa *también* sin primas).
- **Diferir un consumo ya hecho:** desde la tarjeta, el cliente difiere una compra a **2–48 cuotas** (el simulador ofrece 3/6/9/12/18/24) y puede elegir **tasa cero o con interés (18 %)**. Las cuotas entran **una por corte mensual** y aparecen en el estado de cuenta de cada periodo.

<details>
<summary><b>Flujo completo de una compra diferida en la tienda (tarjeta de la casa, N cuotas a tasa cero)</b></summary>

Todo dentro de **una sola transacción**:

1. Bloquea la tarjeta (`FOR UPDATE`) y valida que esté activa, no congelada y sea de crédito.
2. Bloquea los artículos, verifica un solo comercio y totaliza subtotal + envío.
3. Verifica el **cupo disponible** (+ tolerancia de sobregiro autorizado).
4. Resuelve el plazo contra el tope del comercio y valida el **máximo de planes diferidos** vivos.
5. Descuenta existencias.
6. Inserta el pedido en estado `pagado` y sus líneas (copiando nombre y precio).
7. Inserta el **consumo en la tarjeta** con nombre y categoría del comercio (igual que un datáfono).
8. Crea el **plan a tasa cero** con su calendario de cuotas y enlaza el movimiento.
9. Sube el `saldo_usado` de la tarjeta.
10. Audita, notifica y envía el correo de compra + el correo del plan con el calendario cuota a cuota.

El consumo aparece en el **estado de cuenta** de la tarjeta; las cuotas del plan entran una por corte. Una compra con tarjeta de la casa **no genera asiento contable hasta el corte**; una pagada por pasarela sí tiene su asiento descargable.

</details>

---

## 🧰 Stack tecnológico

| Capa | Tecnología |
|---|---|
| **Backend** | PHP 8.2 — **sin framework**, autoloader propio (sin Composer) |
| **Base de datos** | MariaDB 10.4+ / MySQL 8+ (PDO, sentencias preparadas **reales**) |
| **Frontend** | HTML5, CSS3, **JavaScript vanilla + jQuery**, Bootstrap 4, Chart.js |
| **Pagos** | Stripe.js (modo prueba) o pasarela simulada propia |
| **Tiempo real** | Server-Sent Events (SSE) |
| **Seguridad** | Argon2id, AES-256-GCM, CSP con *nonce*, CSRF, HMAC |
| **PDF / Correo** | Generación de PDF y cliente SMTP, ambos sin librerías externas |

---

## 🏛️ Arquitectura

Arquitectura **por capas inspirada en DDD**, con dependencias que apuntan siempre hacia el dominio:

```
Http/Controlador  →  Aplicacion/Servicio  →  Dominio          (reglas de negocio puras)
                                           ↘  Infraestructura  (BD, seguridad, correo, PDF…)
```

- **`src/Dominio`** — Reglas de negocio puras: `Monto` (centavos, sin floats), `Mora`, `Amortizacion`, `Pan`, etc.
- **`src/Aplicacion/Servicio`** — Casos de uso (81 servicios): orquestan dominio + infraestructura dentro de transacciones.
- **`src/Infraestructura`** — Conexión PDO única, hashing, cifrado, auditoría, notificaciones, pasarela, PDF.
- **`src/Http/Controlador`** — 54 controladores finos: validan, delegan y responden.
- **`src/Vistas`** — 125 vistas en PHP plano, con navegación parcial en vivo (sin recargar).

> 🧠 **Detalle de ingeniería:** las 15 vistas SQL están *inlineadas* en el único punto por donde pasan todas las
> consultas, de modo que el sistema **funciona en hostings gratuitos que niegan `CREATE VIEW`** sin perder funcionalidad.

---

## 🔐 Seguridad

La seguridad no es un añadido: está incrustada en cada capa.

- 🔑 **Contraseñas con Argon2id + *pepper*** — HMAC-SHA256 con una clave que vive fuera de la base (`.env`). Un dump robado no es atacable sin ella.
- 🛡️ **Inyección SQL imposible** — PDO con sentencias preparadas **reales** (`EMULATE_PREPARES = false`), nunca escapado manual.
- 💳 **Datos de tarjeta cifrados** — número y CVV con **AES-256-GCM**; se revelan una sola vez, con token efímero y segundo factor.
- 🧱 **Content-Security-Policy con *nonce*** por respuesta, anti-XSS.
- 🎫 **CSRF** en cada formulario, **límite de tasa** por IP e identificador, y **bloqueo por intentos**.
- 📲 **Códigos de un solo uso (OTP)** con caducidad, máximo de intentos y atados a la operación concreta.
- 🧾 **Bitácora de auditoría** encadenada para operaciones sensibles.
- 🍪 **Cookies** `HttpOnly` + `SameSite` + prefijo `__Host-`, sesión con inactividad y tope absoluto.
- 🔒 **2FA (TOTP)** disponible por usuario.

> ⚠️ **Los secretos nunca se versionan.** El archivo `.env` está en `.gitignore`; use `.env.ejemplo` como plantilla y genere sus propias claves.

---

## 📊 El proyecto en números

| | |
|---|---:|
| 📄 Líneas de PHP | **~106,000** |
| 🗄️ Tablas en la base | **111** |
| 🧩 Servicios de aplicación | **81** |
| 🎛️ Controladores | **54** |
| 🖼️ Vistas | **125** |
| 🧱 Migraciones SQL | **98** |
| 👥 Usuarios de demostración | **80** |
| 🔧 Scripts de mantenimiento (`bin/`) | **57** |

---

## ⚙️ Requisitos

- **PHP 8.2+** con extensiones: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `gd` y soporte **Argon2id** (`sodium` o PHP compilado con `--with-password-argon2`).
- **MariaDB 10.4+** o **MySQL 8+**.
- **Apache** con `mod_rewrite` (o Nginx equivalente).
- Recomendado: **XAMPP** (trae todo lo anterior).

---

## 🚀 Instalación paso a paso

### 1. Clonar el repositorio
```bash
git clone https://github.com/DanielRivera03/cashmanha-sistema-bancario.git
cd cashmanha-sistema-bancario
```

### 2. Crear la base de datos e importar la demo
```sql
CREATE DATABASE cashmanha_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
```bash
# Importa el sistema YA POBLADO (usuarios, créditos, tarjetas, movimientos…)
mysql -u root cashmanha_v2 < base-datos/cashmanha_v2.sql
```
> ¿Prefiere construir desde cero? Ejecute en orden los archivos de `migraciones/` en lugar de importar la demo.

### 3. Configurar el entorno
```bash
cp .env.ejemplo .env
```
Edite `.env` y:
- Ponga los datos de su base (`DB_USUARIO`, `DB_CLAVE`).
- Genere cada clave criptográfica:
  ```bash
  php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"   # APP_CLAVE, PASSWORD_PEPPER, SESION_SAL, TARJETA_CLAVE, TARJETA_PEPPER
  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"          # AUDITORIA_SEMILLA
  ```

### 4. Publicar el sitio
Apunte el *DocumentRoot* de Apache al directorio **`public/`** (recomendado), o acceda vía
`http://localhost/cashmanha-sistema-bancario/public/`. Asegúrese de tener `mod_rewrite` y `AllowOverride All`.

### 5. Abrir e iniciar sesión
Vaya a `APP_URL` e ingrese con cualquiera de los [usuarios de prueba](#-usuarios-de-prueba).

---

## 🔑 Usuarios de prueba

> 🔐 **Todos los usuarios comparten la misma contraseña:**
>
> ```
> Demo2026$Banco
> ```
>
> Puede iniciar sesión con el **nombre de usuario** (columna `Usuario`) **o** con el correo. El 2FA viene desactivado en la demo.

### Acceso rápido (un usuario por rol)

| Rol | Usuario | Contraseña |
|---|---|---|
| Administrador | `daniel.rivera` | `Demo2026$Banco` |
| Junta Directiva | `junta.directiva` | `Demo2026$Banco` |
| Presidencia | `ester.cuenca` | `Demo2026$Banco` |
| Gerencia | `faustino.padron` | `Demo2026$Banco` |
| Jurídico | `carmen.juridico` | `Demo2026$Banco` |
| Atención a Clientes | `marco.almagro` | `Demo2026$Banco` |
| Banquero | `maria.alvarenga` | `Demo2026$Banco` |
| Cajero | `rosa.cajera` | `Demo2026$Banco` |
| Cliente | `jenifer.abigail` | `Demo2026$Banco` |

<details>
<summary><b>Ver los 80 usuarios (clic para desplegar)</b></summary>

#### Administrador

| Usuario | Nombre | Correo | Estado |
|---|---|---|---|
| `daniel.rivera` | Daniel Rivera | daniel.rivera@demo.cashmanha.sv | ✅ activo |
| `norma.boix` | Norma Boix | norma.boix@demo.cashmanha.sv | ✅ activo |
| `avelino.blanco` | Avelino Blanco | avelino.blanco@demo.cashmanha.sv | ✅ activo |

#### Junta Directiva

| Usuario | Nombre | Correo | Estado |
|---|---|---|---|
| `junta.directiva` | Junta Directiva | junta@cashmanha.local | ✅ activo |

#### Presidencia

| Usuario | Nombre | Correo | Estado |
|---|---|---|---|
| `ester.cuenca` | Ester Cuenca | ester.cuenca@demo.cashmanha.sv | ✅ activo |

#### Gerencia

| Usuario | Nombre | Correo | Estado |
|---|---|---|---|
| `faustino.padron` | Faustino Padron | faustino.padron@demo.cashmanha.sv | ✅ activo |

#### Jurídico

| Usuario | Nombre | Correo | Estado |
|---|---|---|---|
| `carmen.juridico` | Carmen Lisseth Ayala Granados | carmen.juridico@demo.cashmanha.sv | ✅ activo |
| `rodrigo.juridico` | Rodrigo Ernesto Villalta Cornejo | rodrigo.juridico@demo.cashmanha.sv | ✅ activo |

#### Atención a Clientes

| Usuario | Nombre | Correo | Estado |
|---|---|---|---|
| `marco.almagro` | Marco Almagro | marco.almagro@demo.cashmanha.sv | ✅ activo |

#### Banquero

| Usuario | Nombre | Correo | Estado |
|---|---|---|---|
| `maria.alvarenga` | María José Alvarenga Soler | maria.alvarenga@cashmanha.sv | ✅ activo |
| `ricardo.zaldivar` | Ricardo Andrés Zaldívar Umaña | ricardo.zaldivar@cashmanha.sv | ✅ activo |
| `patricia.duran` | Patricia Elena Durán Figueroa | patricia.duran@cashmanha.sv | ✅ activo |

#### Cajero

| Usuario | Nombre | Correo | Estado |
|---|---|---|---|
| `rosa.cajera` | Rosa Elena Mejía Portillo | rosa.cajera@demo.cashmanha.sv | ✅ activo |
| `oscar.cajero` | Óscar Antonio Lemus Hernández | oscar.cajero@demo.cashmanha.sv | ✅ activo |
| `karla.alfaro` | Karla Beatriz Alfaro Rivas | karla.alfaro@cashmanha.sv | ✅ activo |
| `mauricio.ventura` | Mauricio José Ventura Campos | mauricio.ventura@cashmanha.sv | ✅ activo |
| `gabriela.cruz` | Gabriela Lissette Cruz Menjívar | gabriela.cruz@cashmanha.sv | ✅ activo |
| `nelson.ayala` | Nelson Enrique Ayala Deras | nelson.ayala@cashmanha.sv | ✅ activo |
| `wendy.sandoval` | Wendy Carolina Sandoval Pineda | wendy.sandoval@cashmanha.sv | ✅ activo |
| `ana.cornejo` | Ana Gabriela Cornejo Flores | ana.cornejo@cashmanha.sv | ✅ activo |
| `rodrigo.martinez` | Rodrigo Ernesto Martínez Bonilla | rodrigo.martinez@cashmanha.sv | ✅ activo |
| `silvia.quintanilla` | Silvia Margarita Quintanilla Rosa | silvia.quintanilla@cashmanha.sv | ✅ activo |
| `jorge.pena` | Jorge Alberto Peña Castaneda | jorge.pena@cashmanha.sv | ✅ activo |
| `yesenia.molina` | Yesenia del Carmen Molina Serrano | yesenia.molina@cashmanha.sv | ✅ activo |
| `claudia.benitez` | Claudia Verónica Benítez Argueta | claudia.benitez@cashmanha.sv | ✅ activo |
| `hector.chavez` | Héctor Rolando Chávez Zelaya | hector.chavez@cashmanha.sv | ✅ activo |
| `marvin.reyes` | Marvin Alexander Reyes Granados | marvin.reyes@cashmanha.sv | ✅ activo |
| `lorena.granados` | Lorena Isabel Granados Melara | lorena.granados@cashmanha.sv | ✅ activo |
| `carlos.orellana` | Carlos Humberto Orellana Vásquez | carlos.orellana@cashmanha.sv | ✅ activo |
| `sandra.interiano` | Sandra Patricia Interiano Lazo | sandra.interiano@cashmanha.sv | ✅ activo |
| `diana.escobar` | Diana Michelle Escobar Rivera | diana.escobar@cashmanha.sv | ✅ activo |
| `fernando.guevara` | Fernando Josué Guevara Barahona | fernando.guevara@cashmanha.sv | ✅ activo |
| `alejandra.linares` | Alejandra María Linares Trejo | alejandra.linares@cashmanha.sv | ✅ activo |
| `mirna.salguero` | Mirna Guadalupe Salguero Díaz | mirna.salguero@cashmanha.sv | ✅ activo |
| `edgar.villalta` | Édgar Antonio Villalta Ramos | edgar.villalta@cashmanha.sv | ✅ activo |
| `reina.hernandez` | Reina Esperanza Hernández Gómez | reina.hernandez@cashmanha.sv | ✅ activo |
| `julio.mendoza` | Julio César Mendoza Alvarenga | julio.mendoza@cashmanha.sv | ✅ activo |
| `tatiana.rivas` | Tatiana Elizabeth Rivas Contreras | tatiana.rivas@cashmanha.sv | ✅ activo |
| `walter.fuentes` | Walter Giovanni Fuentes Aguilar | walter.fuentes@cashmanha.sv | ✅ activo |

#### Cliente

| Usuario | Nombre | Correo | Estado |
|---|---|---|---|
| `jenifer.abigail` | Jenifer Abigail Castañeda | jenifer.abigail@demo.cashmanha.sv | ✅ activo |
| `maria.frances` | Maria Cristina Frances Rosas | maria.frances@demo.cashmanha.sv | ⏸️ inactivo |
| `luz.mancebo` | Luz Mancebo Llabrés | luz.mancebo@demo.cashmanha.sv | ⛔ bloqueado |
| `harold.garcia` | Harold Osmany García | harold.garcia@demo.cashmanha.sv | ✅ activo |
| `javier.ponce` | Javier Ernesto Ponce Díaz | javier.ponce@demo.cashmanha.sv | ⛔ bloqueado |
| `marta.portillo` | Marta Elena Portillo Guzmán | marta.portillo@demo.cashmanha.sv | ✅ activo |
| `rosa.mejia` | Rosa Elena Mejía Fuentes | rosa.6960d3@ejemplo.test | ✅ activo |
| `rosa.mejia2` | Rosa Elena Mejía Fuentes | rosa.0894ca@ejemplo.test | ✅ activo |
| `javier.pineda` | Javier Pineda | javier.pineda@demo.cashmanha.sv | ✅ activo |
| `sem2026.ana.alvarado0` | Ana Beatriz Alvarado Mejía | sem2026.ana.alvarado0@example.test | ✅ activo |
| `sem2026.carlos.bentez1` | Carlos Alberto Benítez Flores | sem2026.carlos.bentez1@example.test | ✅ activo |
| `sem2026.diana.cceres2` | Diana Carolina Cáceres Rivas | sem2026.diana.cceres2@example.test | ✅ activo |
| `sem2026.eduardo.delgado3` | Eduardo José Delgado Morán | sem2026.eduardo.delgado3@example.test | ✅ activo |
| `sem2026.ftima.escobar4` | Fátima Esperanza Escobar Villalta | sem2026.ftima.escobar4@example.test | ✅ activo |
| `sem2026.gerardo.fuentes5` | Gerardo Antonio Fuentes Marín | sem2026.gerardo.fuentes5@example.test | ✅ activo |
| `sem2026.heidy.guevara6` | Heidy Marisol Guevara Ponce | sem2026.heidy.guevara6@example.test | ✅ activo |
| `sem2026.ivn.hernndez7` | Iván Ernesto Hernández Lazo | sem2026.ivn.hernndez7@example.test | ✅ activo |
| `sem2026.jacqueline.interiano8` | Jacqueline Interiano Ávila | sem2026.jacqueline.interiano8@example.test | ✅ activo |
| `sem2026.kevin.jimnez9` | Kevin Alexander Jiménez Durán | sem2026.kevin.jimnez9@example.test | ✅ activo |
| `sem2026.lorena.lemus10` | Lorena Patricia Lemus Carranza | sem2026.lorena.lemus10@example.test | ✅ activo |
| `sem2026.mauricio.martnez11` | Mauricio Enrique Martínez Sola | sem2026.mauricio.martnez11@example.test | ✅ activo |
| `sem2026.nancy.navarro12` | Nancy Yamileth Navarro Quintanilla | sem2026.nancy.navarro12@example.test | ✅ activo |
| `sem2026.scar.orellana13` | Óscar Armando Orellana Pérez | sem2026.scar.orellana13@example.test | ✅ activo |
| `sem2026.patricia.pea14` | Patricia Elena Peña Zelaya | sem2026.patricia.pea14@example.test | ✅ activo |
| `sem2026.rodrigo.quintana15` | Rodrigo Ernesto Quintana Serrano | sem2026.rodrigo.quintana15@example.test | ✅ activo |
| `sem2026.sandra.ramrez16` | Sandra Lisseth Ramírez Bonilla | sem2026.sandra.ramrez16@example.test | ✅ activo |
| `sem2026.toms.solrzano17` | Tomás Alfredo Solórzano Aguilar | sem2026.toms.solrzano17@example.test | ✅ activo |
| `sem2026.rsula.trejo18` | Úrsula María Trejo Campos | sem2026.rsula.trejo18@example.test | ✅ activo |
| `sem2026.vctor.umaa19` | Víctor Manuel Umaña Rodríguez | sem2026.vctor.umaa19@example.test | ✅ activo |
| `sem2026.wendy.valladares20` | Wendy Carolina Valladares Cruz | sem2026.wendy.valladares20@example.test | ✅ activo |
| `sem2026.xiomara.zepeda21` | Xiomara Beatriz Zepeda Molina | sem2026.xiomara.zepeda21@example.test | ✅ activo |
| `sem2026.yolanda.acosta22` | Yolanda Esther Acosta Linares | sem2026.yolanda.acosta22@example.test | ✅ activo |
| `sem2026.zoila.barrera23` | Zoila Margarita Barrera Pineda | sem2026.zoila.barrera23@example.test | ✅ activo |
| `sem2026.alfonso.castaneda24` | Alfonso Javier Castaneda Rojas | sem2026.alfonso.castaneda24@example.test | ✅ activo |
| `sem2026.brenda.dubn25` | Brenda Lissette Dubón Salazar | sem2026.brenda.dubn25@example.test | ✅ activo |
| `sem2026.csar.elas26` | César Augusto Elías Paniagua | sem2026.csar.elas26@example.test | ✅ activo |
| `sem2026.daniela.figueroa27` | Daniela Sofía Figueroa Nolasco | sem2026.daniela.figueroa27@example.test | ✅ activo |
| `sem2026.ernesto.galdmez28` | Ernesto Rafael Galdámez Rivera | sem2026.ernesto.galdmez28@example.test | ✅ activo |
| `sem2026.fernanda.henrquez29` | Fernanda Lucía Henríquez Portillo | sem2026.fernanda.henrquez29@example.test | ✅ activo |
| `sem2026.gustavo.iraheta30` | Gustavo Adolfo Iraheta Menjívar | sem2026.gustavo.iraheta30@example.test | ✅ activo |
| `sem2026.hilda.jacobo31` | Hilda Concepción Jacobo Larín | sem2026.hilda.jacobo31@example.test | ✅ activo |

</details>

---

## 🧪 Cómo probar la demo

- 📨 **Los correos están apagados.** Cuando el sistema pida un **código de verificación** (ver datos de tarjeta,
  transferencias, etc.), el código aparece en la **🔔 campana de notificaciones**, arriba a la derecha. Use siempre el más reciente.
- 💳 **Pagos con tarjeta.** Por defecto la pasarela es **simulada** (no necesita nada externo). Si configura Stripe
  en modo prueba, use tarjetas de test como `4242 4242 4242 4242`, cualquier fecha futura y cualquier CVC.
- 👤 **Roles.** Entre como `daniel.rivera` (administrador) para verlo todo, o como `jenifer.abigail` (cliente) para el portal de banca en línea.
- ⛔ Algunos clientes aparecen **inactivos/bloqueados** a propósito (demuestran esos estados); use los marcados como ✅ activo.

---

## 📁 Estructura del proyecto

```
cashmanha-sistema-bancario/
├── base-datos/        # Volcado de la base de datos demo (import directo)
├── bin/               # Scripts de mantenimiento y tareas programadas (CLI)
├── config/            # Configuración de la aplicación
├── lib/               # Utilidades de bajo nivel
├── migraciones/       # 98 migraciones SQL (esquema + semilla)
├── public/            # Raíz web: index.php + assets (CSS, JS, imágenes)
├── src/
│   ├── Dominio/           # Reglas de negocio puras
│   ├── Aplicacion/        # Casos de uso (servicios)
│   ├── Infraestructura/   # BD, seguridad, correo, PDF, pasarela
│   ├── Http/              # Controladores y enrutado
│   └── Vistas/            # Plantillas de pantalla
├── storage/           # Datos en ejecución (logs, PDF, caché) — no versionado
├── tests/             # Pruebas
├── .env.ejemplo       # Plantilla de configuración
└── README.md
```

---

## ⚠️ Notas y limitaciones

- Es una **demostración funcional con fines educativos / de portafolio**: los datos (clientes, movimientos, correos)
  son **ficticios**. No use estas claves ni esta configuración en producción real.
- El envío de correo viene **desactivado**; actívelo solo con su propio servidor SMTP.
- Las llaves de Stripe incluidas en la plantilla son de **modo prueba**; no se cobra dinero real.

---

## 📄 Licencia

Proyecto con fines **educativos y de portafolio**. © 2026 Daniel Rivera. Todos los derechos reservados.
Si desea reutilizar el código, abra un *issue* para coordinarlo.

---

## 📸 Capturas del sistema

<sub>Un recorrido por las pantallas reales: sitio público, portal del cliente, ventanilla, cartera, tarjetas, estados de cuenta, tienda y paneles de gestión.</sub>

<img width="1878" height="1029" alt="image" src="https://github.com/user-attachments/assets/1e81bfa1-4373-427d-a858-c6fdb252a11e" />
<img width="1887" height="1025" alt="image" src="https://github.com/user-attachments/assets/749a8c58-0146-423d-be2d-530e85600892" />
<img width="1880" height="1027" alt="image" src="https://github.com/user-attachments/assets/b5d6e6b1-d6ad-457a-a411-022cb42b2806" />
<img width="1880" height="1025" alt="image" src="https://github.com/user-attachments/assets/7395c480-94ff-4335-903d-cee8dece1598" />
<img width="1879" height="1026" alt="image" src="https://github.com/user-attachments/assets/9cfd90ea-7300-4f40-9fb3-4f99334d5ec5" />
<img width="1881" height="1024" alt="image" src="https://github.com/user-attachments/assets/1a812520-ac38-41e5-b87e-d43616e69681" />
<img width="1875" height="1022" alt="image" src="https://github.com/user-attachments/assets/58af51e4-07b4-4b37-a138-a50c648fcc93" />
<img width="1881" height="1030" alt="image" src="https://github.com/user-attachments/assets/35034406-00e3-40eb-a1ae-b30576a3e32d" />
<img width="1884" height="1022" alt="image" src="https://github.com/user-attachments/assets/6b39c01d-212b-4f9a-a97e-e755b494413d" />
<img width="1885" height="1024" alt="image" src="https://github.com/user-attachments/assets/35ff1aa6-059b-491d-85c2-f682c98f8c07" />
<img width="1880" height="1029" alt="image" src="https://github.com/user-attachments/assets/bf5659d6-6d4e-4fd0-8ba9-8a714e1dba71" />
<img width="1885" height="1029" alt="image" src="https://github.com/user-attachments/assets/fcb93fb8-4339-4e23-b4bc-d837798f1222" />
<img width="1876" height="1021" alt="image" src="https://github.com/user-attachments/assets/8310e37a-8fa6-48ba-9d61-58ead5c52f85" />
<img width="1884" height="1025" alt="image" src="https://github.com/user-attachments/assets/b5da1d9a-d74e-4536-8798-a3d2a87f9ef9" />
<img width="1885" height="1031" alt="image" src="https://github.com/user-attachments/assets/9f20d38b-3607-4f84-a092-fc14dfa4e6f8" />
<img width="1875" height="1025" alt="image" src="https://github.com/user-attachments/assets/341ef703-cbb1-4431-99d0-10b5f3f39407" />
<img width="1898" height="1025" alt="image" src="https://github.com/user-attachments/assets/7c8f3302-6ec4-445e-be89-00d8feee86ac" />
<img width="1896" height="1020" alt="image" src="https://github.com/user-attachments/assets/b74fbef9-269b-4045-8b8d-fa807c99d342" />
<img width="1895" height="1021" alt="image" src="https://github.com/user-attachments/assets/5b120457-f644-49c4-926f-95da56081a3c" />
<img width="1885" height="1023" alt="image" src="https://github.com/user-attachments/assets/b436b420-0009-436f-a28b-ab4f6ad92a5e" />
<img width="1891" height="1021" alt="image" src="https://github.com/user-attachments/assets/7b66221e-a13a-44d1-889e-ceb2f9ae0034" />
<img width="1893" height="1019" alt="image" src="https://github.com/user-attachments/assets/ec4b17a1-8f5e-47da-afcb-936b90afe91a" />
<img width="1886" height="1016" alt="image" src="https://github.com/user-attachments/assets/26eeeadf-73fc-4d1a-a8d8-d4020bcb8ffe" />
<img width="1894" height="1022" alt="image" src="https://github.com/user-attachments/assets/28a86980-2fd4-403c-a5c4-2126dfc51134" />
<img width="1891" height="1019" alt="image" src="https://github.com/user-attachments/assets/85b5d144-d9e8-4519-85b8-1735c8968ec4" />
<img width="1887" height="1021" alt="image" src="https://github.com/user-attachments/assets/0cf662ce-e8c5-46fe-a40c-39b22673e435" />
<img width="1892" height="1023" alt="image" src="https://github.com/user-attachments/assets/2e46b97c-9647-422f-8eec-2afed340ddfa" />
<img width="1886" height="1022" alt="image" src="https://github.com/user-attachments/assets/26329bee-e8d7-41ce-8a56-b6b665b34f39" />
<img width="1892" height="1024" alt="image" src="https://github.com/user-attachments/assets/173a8b91-44db-4075-8d76-6f425966a0f9" />
<img width="1894" height="1021" alt="image" src="https://github.com/user-attachments/assets/77b40c09-2f53-44c4-a066-190b7aee898b" />
<img width="1890" height="1025" alt="image" src="https://github.com/user-attachments/assets/446767b2-4f8f-46de-bcf7-58b7ceadb659" />
<img width="1022" height="973" alt="image" src="https://github.com/user-attachments/assets/179f4204-c188-4c71-92f5-a8cbd989abf7" />
<img width="1877" height="1025" alt="image" src="https://github.com/user-attachments/assets/8c75ab49-519b-4a81-a017-2320da3de10c" />
<img width="1901" height="1030" alt="image" src="https://github.com/user-attachments/assets/32ed51a3-4879-4d0e-8e40-e891263b0bea" />
<img width="1894" height="1024" alt="image" src="https://github.com/user-attachments/assets/7cbb685c-cae7-4e16-ba43-b94f825b8a40" />
<img width="1901" height="1019" alt="image" src="https://github.com/user-attachments/assets/c37b0ed1-f0b7-4716-95a6-bbd646415611" />
<img width="1891" height="1026" alt="image" src="https://github.com/user-attachments/assets/6cb89bf5-b4dc-4db9-b6fc-11fc8e1ac904" />
<img width="1891" height="1015" alt="image" src="https://github.com/user-attachments/assets/63010705-88f3-4e09-a1df-b3e0905158b2" />
<img width="1890" height="1025" alt="image" src="https://github.com/user-attachments/assets/720fa8ec-3e66-40e8-9ecd-4afa463b9daa" />
<img width="1888" height="1022" alt="image" src="https://github.com/user-attachments/assets/0d4a94bc-0720-4e54-ad5f-39b3c36cb707" />
<img width="1890" height="1021" alt="image" src="https://github.com/user-attachments/assets/8ec26515-b1e9-4ad5-b6fd-533c3bf15806" />
<img width="1890" height="1021" alt="image" src="https://github.com/user-attachments/assets/b1ae2a01-f989-48e2-a5d1-cc237b0ed4da" />
<img width="1888" height="1021" alt="image" src="https://github.com/user-attachments/assets/86010c94-d954-493e-a88e-3c4b1e5c2968" />
<img width="1891" height="1022" alt="image" src="https://github.com/user-attachments/assets/fac86668-95a4-4139-a7ef-1e8efc39730e" />
<img width="1888" height="1024" alt="image" src="https://github.com/user-attachments/assets/e39a4d02-015c-4787-a39c-cdf5b95c22d0" />
<img width="1020" height="973" alt="image" src="https://github.com/user-attachments/assets/14f4a051-e882-4c0b-b834-2655ed4178f0" />
<img width="1010" height="876" alt="image" src="https://github.com/user-attachments/assets/d3b6cce7-f28e-4e67-90dc-1c3cf888e3c5" />
<img width="1015" height="976" alt="image" src="https://github.com/user-attachments/assets/3d32e3f6-7b5e-4626-a839-3cb27dad5c2f" />
<img width="1900" height="1017" alt="image" src="https://github.com/user-attachments/assets/8899dea8-1dbe-4d16-a47b-32ec9faded55" />
<img width="1896" height="1021" alt="image" src="https://github.com/user-attachments/assets/4de8c719-2c2b-4fe2-98ce-5244d96b880d" />
<img width="1895" height="1022" alt="image" src="https://github.com/user-attachments/assets/5a41af87-f9b8-4f4e-937a-59f7f5c2c1a0" />
<img width="1895" height="1024" alt="image" src="https://github.com/user-attachments/assets/20bcc513-0ca5-4772-8d59-c3468d29a6f5" />
<img width="1888" height="1024" alt="image" src="https://github.com/user-attachments/assets/54e75308-2deb-48dc-a771-7452009b115b" />
<img width="1897" height="1021" alt="image" src="https://github.com/user-attachments/assets/700cc745-2076-4235-a5a7-2b50a9f1af03" />
<img width="1893" height="1020" alt="image" src="https://github.com/user-attachments/assets/0cc9a3b5-9774-48c3-84cd-daa9f3750bbc" />
<img width="1891" height="1022" alt="image" src="https://github.com/user-attachments/assets/4d7d2662-120a-4001-8871-cbaef9891ab7" />
<img width="1894" height="1024" alt="image" src="https://github.com/user-attachments/assets/9c5b6bd5-8731-4893-be8e-15dc312f2597" />
<img width="1897" height="1021" alt="image" src="https://github.com/user-attachments/assets/9b451454-de50-4952-aa91-16363867c942" />
<img width="1891" height="1019" alt="image" src="https://github.com/user-attachments/assets/d7023c37-b31d-4f42-9dc5-c7c01d77e92d" />
<img width="1891" height="1022" alt="image" src="https://github.com/user-attachments/assets/16f5ecb9-b514-4855-93a6-11fd78f2fdf3" />
<img width="1897" height="1024" alt="image" src="https://github.com/user-attachments/assets/974f0087-8b68-4a0b-873f-9581bcf1e62f" />
<img width="1900" height="1022" alt="image" src="https://github.com/user-attachments/assets/b8a89c46-dd83-4f68-9778-30d1f644d8a8" />




## 👤 Autor

**Daniel Rivera** — [@DanielRivera03](https://github.com/DanielRivera03)

<p align="center"><sub>⭐ Si te sirvió o te pareció interesante, dale una estrella al repositorio.</sub></p>

---

<sub>
<b>Palabras clave / Keywords:</b> sistema bancario, sistema bancario en PHP, banking system, core banking, online banking,
banca en línea, PHP banking system, sistema bancario PHP MySQL, MariaDB, créditos, loan management, amortización,
tabla de amortización, tarjetas de crédito, credit card system, débito, transferencias bancarias, money transfer,
interbancario, Transfer365, pasarela de pago, payment gateway, Stripe, fintech, software bancario, core bancario,
proyecto PHP, El Salvador, open source banking, CashMan.
</sub>

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
| 💠 **Pasarela de pago** | Cobro con tarjeta (Visa / Mastercard / Amex) vía **Stripe** o emisión **simulada** propia, sin dependencias. |
| 🧾 **Ventanilla (Caja)** | Apertura/cierre de caja por cajero, efectivo y cheque, **notas contables**, asientos. |
| ⚖️ **Cobranza y Jurídico** | Cartera morosa, gestión de cobranza, expediente jurídico. |
| 🛒 **Tienda** | Catálogo público, carrito, pedidos de varias tiendas, cobros y despacho. |
| 💰 **Depósitos a plazo** | Constitución, intereses y vencimiento. |
| 🧰 **Más** | Chequeras, remesas familiares, pago de servicios, constancias, órdenes programadas, cumplimiento/prevención de lavado, banca preferente/segmentos, ofertas preaprobadas, evaluación de riesgo/buró, y panel para la **Junta Directiva**. |
| 🔔 **Tiempo real** | Notificaciones en vivo (SSE), reloj, clima y avisos sin recargar la página. |

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

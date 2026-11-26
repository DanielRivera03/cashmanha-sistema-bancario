<?php
declare(strict_types=1);

/**
 * Front controller: ÚNICO punto de entrada de la aplicación.
 *
 * Rutas antes y ahora:
 *
 *   /CashManHA/controlador/cIniciosSesionesUsuarios.php?cashmanha=iniciarsesion
 *      →  /entrar
 *
 *   /CashManHA/controlador/cGestionesCashman.php?cashmanhagestion=inicioadministradores
 *      →  /panel
 *
 *   /CashManHA/controlador/cGestionesCashman.php?...&idcuentas=5
 *      →  /cuentas/KHFG9w4N
 *
 * La estructura interna deja de ser visible. No se puede deducir qué
 * archivos existen, ni qué tecnología hay detrás, ni cuántos registros
 * tiene cada tabla.
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CashMan\Http\Enrutador;
use CashMan\Http\Respuesta;
use CashMan\Http\MetodoNoPermitido;
use CashMan\Infraestructura\Config;
use CashMan\Infraestructura\Seguridad\AccesoDenegado;
use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Csp;
use CashMan\Infraestructura\Seguridad\Csrf;
use CashMan\Infraestructura\Seguridad\LimiteExcedido;
use CashMan\Infraestructura\Seguridad\RecursoNoEncontrado;
use CashMan\Infraestructura\Seguridad\Sesion;
use CashMan\Http\Controlador\ControladorAutenticacion;
use CashMan\Http\Controlador\ControladorCajaTurno;
use CashMan\Http\Controlador\ControladorCobranza;
use CashMan\Http\Controlador\ControladorEnlacePago;
use CashMan\Http\Controlador\ControladorTienda;
use CashMan\Http\Controlador\ControladorTiendaPublica;
use CashMan\Http\Controlador\ControladorTiendaAdmin;
use CashMan\Http\Controlador\ControladorSitio;
use CashMan\Http\Controlador\ControladorPasarela;
use CashMan\Http\Controlador\ControladorJunta;
use CashMan\Http\Controlador\ControladorProcesos;
use CashMan\Http\Controlador\ControladorCatalogoTarjetas;
use CashMan\Http\Controlador\ControladorPanel;
use CashMan\Http\Controlador\ControladorCuentas;
use CashMan\Http\Controlador\ControladorReclamos;
use CashMan\Http\Controlador\ControladorProgramadas;
use CashMan\Http\Controlador\ControladorChequeras;
use CashMan\Http\Controlador\ControladorPlazoFijo;
use CashMan\Http\Controlador\ControladorConstancias;
use CashMan\Http\Controlador\ControladorRemesas;
use CashMan\Http\Controlador\ControladorServicios;
use CashMan\Http\Controlador\ControladorCumplimiento;
use CashMan\Http\Controlador\ControladorInterbancario;
use CashMan\Http\Controlador\ControladorTransferencias;
use CashMan\Http\Controlador\ControladorTarjetas;
use CashMan\Http\Controlador\ControladorEstadosCuenta;
use CashMan\Http\Controlador\ControladorCartera;
use CashMan\Http\Controlador\ControladorEventos;
use CashMan\Http\Controlador\ControladorError;
use CashMan\Http\Controlador\ControladorTarjetaAcciones;
use CashMan\Http\Controlador\ControladorCaja;
use CashMan\Http\Controlador\ControladorMensajes;
use CashMan\Http\Controlador\ControladorTickets;
use CashMan\Http\Controlador\ControladorCreditos;
use CashMan\Http\Controlador\ControladorSolicitudes;
use CashMan\Http\Controlador\ControladorClientes;
use CashMan\Http\Controlador\ControladorPreferente;
use CashMan\Http\Controlador\ControladorUsuarios;
use CashMan\Http\Controlador\ControladorBanquero;
use CashMan\Http\Controlador\ControladorAuditoria;
use CashMan\Http\Controlador\ControladorReportes;
use CashMan\Http\Controlador\ControladorPerfil;
use CashMan\Http\Controlador\ControladorReestructuraciones;
use CashMan\Http\Controlador\ControladorSeguridad;
use CashMan\Http\Controlador\ControladorDocumentos;
use CashMan\Http\Controlador\ControladorCatalogos;
use CashMan\Http\Controlador\ControladorCuotas;
use CashMan\Http\Controlador\ControladorNotificaciones;
use CashMan\Http\Controlador\ControladorContratos;
use CashMan\Http\Controlador\ControladorOfertas;
use CashMan\Http\Controlador\ControladorReferencias;
use CashMan\Http\Controlador\ControladorPerfilDeclarado;
use CashMan\Http\Controlador\ControladorRegistro;

// ---------------------------------------------------------------------
// Cabeceras de seguridad por si mod_headers no está disponible
// ---------------------------------------------------------------------
if (!headers_sent()) {
    /* Solo lo que Apache no haya puesto ya.
     *
     * Este bloque es el respaldo para cuando `mod_headers` no está
     * disponible, pero no lo comprobaba: con Apache bien configurado
     * cada cabecera salía DOS veces —«X-Frame-Options: DENY,DENY»— y
     * dos valores en una misma cabecera es, para algunos navegadores,
     * una cabecera malformada que se ignora entera. Es decir: el
     * respaldo podía anular justo lo que venía a respaldar.
     *
     * `headers_list()` dice lo que ya va a salir. */
    $yaPuestas = [];

    foreach (headers_list() as $h) {
        $corte = strpos($h, ':');

        if ($corte !== false) {
            $yaPuestas[strtolower(substr($h, 0, $corte))] = true;
        }
    }

    $ponerSiFalta = static function (string $nombre, string $valor) use ($yaPuestas): void {
        if (!isset($yaPuestas[strtolower($nombre)])) {
            header($nombre . ': ' . $valor);
        }
    };

    $ponerSiFalta('X-Content-Type-Options', 'nosniff');
    $ponerSiFalta('X-Frame-Options', 'DENY');
    $ponerSiFalta('Referrer-Policy', 'strict-origin-when-cross-origin');

    /* Permisos del navegador: todo apagado salvo lo que se use.
       Una banca no necesita la cámara, el micrófono ni la ubicación.
       El mismo valor que `public/.htaccess`, para que no digan cosas
       distintas según quién llegue primero. */
    $ponerSiFalta(
        'Permissions-Policy',
        'geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=()'
    );

    /* HSTS solo cuando la petición YA viene por HTTPS.
     *
     * Por HTTP el navegador la ignora, así que no sirve de nada; y
     * enviarla desde un entorno local deja el dominio marcado en el
     * navegador del desarrollador durante meses, obligándole a HTTPS
     * en un sitio que no lo tiene. En `.htaccess` está comentada por
     * esa misma razón: aquí se decide en cada petición, que es lo que
     * un fichero estático no puede hacer.
     *
     * Medio año, sin subdominios ni precarga. Eso se amplía cuando el
     * certificado esté en firme, no antes: `preload` es casi
     * irreversible. */
    $porHttps = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    if ($porHttps) {
        $ponerSiFalta('Strict-Transport-Security', 'max-age=15552000');
    }

    header_remove('X-Powered-By');
}
// La politica de seguridad de contenido, con el nonce de esta peticion.
// Va antes de cualquier salida: una cabecera tardia no se envia.
Csp::enviar();

Sesion::iniciar();

// ---------------------------------------------------------------------
// Prefijo de instalación
// ---------------------------------------------------------------------
$prefijo = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$r = new Enrutador($prefijo);

// =====================================================================
// RUTAS
// =====================================================================

// --- Sitio público ----------------------------------------------------
// La portada es el sitio institucional, no la pantalla de acceso: quien
// llega sin cuenta necesita saber qué es esto antes de que le pidan un
// usuario y una contraseña. El acceso sigue viviendo en /entrar.
$r->get('/',                [ControladorSitio::class, 'inicio']);
$r->get('/personas',        [ControladorSitio::class, 'personas']);
$r->get('/empresas',        [ControladorSitio::class, 'empresas']);
$r->get('/nosotros',        [ControladorSitio::class, 'nosotros']);
$r->get('/sucursales',      [ControladorSitio::class, 'sucursales']);
$r->get('/preguntas',       [ControladorSitio::class, 'preguntas']);
$r->get('/contacto',        [ControladorSitio::class, 'contacto']);
$r->post('/contacto',       [ControladorSitio::class, 'enviarContacto'], ['csrf']);

// --- Públicas ---------------------------------------------------------
$r->get('/entrar',          [ControladorAutenticacion::class, 'formulario']);
$r->post('/entrar',         [ControladorAutenticacion::class, 'procesar']);
$r->get('/salir',           [ControladorAutenticacion::class, 'salir']);
$r->post('/salir',          [ControladorAutenticacion::class, 'salir']);
/* El registro público.
 *
 * Sin `auth`, obviamente: quien se registra todavía no es nadie. Con
 * `csrf` sí: el formulario lo sirve el propio sitio y el token evita
 * que otra página envíe altas a nuestro nombre. */
$r->get('/registro',        [ControladorRegistro::class, 'formulario']);
$r->post('/registro',       [ControladorRegistro::class, 'registrar'],      ['csrf']);

/* Y abrirse la cuenta, que ya exige haber entrado. */
// La declaración obligatoria del cliente nuevo. Va sin menú y sin más
// salida que llenarla: mientras falte, el guardián trae aquí todo lo demás.
$r->get('/mi-perfil/declarar',  [ControladorPerfilDeclarado::class, 'formulario'], ['auth']);
$r->post('/mi-perfil/declarar', [ControladorPerfilDeclarado::class, 'guardar'], ['auth', 'csrf']);

$r->get('/abrir-cuenta',    [ControladorRegistro::class, 'formularioCuenta'], ['auth']);
$r->post('/abrir-cuenta',   [ControladorRegistro::class, 'abrirCuenta'],      ['auth', 'csrf']);

$r->get('/recuperar',       [ControladorAutenticacion::class, 'recuperar']);
$r->post('/recuperar',      [ControladorAutenticacion::class, 'procesarRecuperacion']);
$r->get('/restablecer/{token}',  [ControladorAutenticacion::class, 'restablecer']);
$r->post('/restablecer/{token}', [ControladorAutenticacion::class, 'procesarRestablecimiento']);

// --- Cambio de contraseña --------------------------------------------
$r->get('/clave',           [ControladorAutenticacion::class, 'formularioClave'],  ['auth']);
$r->post('/clave',          [ControladorAutenticacion::class, 'cambiarClave'],     ['auth']);

// --- Panel principal --------------------------------------------------
$r->get('/panel',           [ControladorPanel::class, 'inicio'],                   ['auth']);

// --- Cuentas ----------------------------------------------------------
// Las rutas ESTÁTICAS van antes que las dinámicas: si /cuentas/{codigo}
// se registrara primero, capturaría "abrir" como si fuera un código.
$r->get('/cuentas',          [ControladorCuentas::class, 'listar'],                ['auth']);
$r->get('/cuentas/abrir',    [ControladorCaja::class, 'formularioApertura'],       ['auth']);
$r->post('/cuentas/abrir',   [ControladorCaja::class, 'abrirCuenta'],              ['auth', 'csrf']);
$r->get('/cuentas/{codigo}', [ControladorCuentas::class, 'detalle'],               ['auth']);
$r->post('/cuentas/{codigo}/tarjeta-debito',
    [ControladorCuentas::class, 'tarjetaDebito'], ['auth', 'csrf']);
$r->get('/comprobante/{codigo}', [ControladorCuentas::class, 'comprobante'], ['auth']);
$r->get('/movimientos',      [ControladorCuentas::class, 'movimientos'],           ['auth']);

// --- Transferencias ---------------------------------------------------
$r->get('/transferir',      [ControladorTransferencias::class, 'formulario'],      ['auth']);
$r->post('/transferir',     [ControladorTransferencias::class, 'ejecutar'],        ['auth', 'csrf']);
$r->post('/transferir/codigo', [ControladorTransferencias::class, 'solicitarCodigo'], ['auth', 'csrf']);
$r->post('/transferir/validar-cuenta', [ControladorTransferencias::class, 'validarCuenta'], ['auth']);

// --- A otros bancos: Transfer365, Transfer365 Móvil y UNI -------------
//
// El orden importa. `/otros-bancos/agenda` tiene que ir ANTES que
// cualquier ruta con comodín que empiece igual, o el enrutador tomaría
// "agenda" por un identificador y respondería 404.
$r->get('/otros-bancos',           [ControladorInterbancario::class, 'formulario'], ['auth']);
$r->get('/otros-bancos/agenda',    [ControladorInterbancario::class, 'agenda'],     ['auth']);
$r->get('/otros-bancos/historial', [ControladorInterbancario::class, 'historial'],  ['auth']);
$r->get('/otros-bancos/limites',   [ControladorInterbancario::class, 'limites'],    ['auth']);

$r->post('/otros-bancos/enviar',   [ControladorInterbancario::class, 'enviar'],          ['auth', 'csrf']);
$r->post('/otros-bancos/codigo',   [ControladorInterbancario::class, 'solicitarCodigo'], ['auth', 'csrf']);

$r->post('/otros-bancos/agenda',          [ControladorInterbancario::class, 'guardarBeneficiario'], ['auth', 'csrf']);
$r->post('/otros-bancos/agenda/codigo',   [ControladorInterbancario::class, 'codigoBeneficiario'],  ['auth', 'csrf']);
$r->post('/otros-bancos/agenda/{uuid}/bloquear', [ControladorInterbancario::class, 'bloquearBeneficiario'], ['auth', 'csrf']);
$r->post('/otros-bancos/agenda/{uuid}/eliminar', [ControladorInterbancario::class, 'eliminarBeneficiario'], ['auth', 'csrf']);

$r->post('/otros-bancos/limites',        [ControladorInterbancario::class, 'guardarLimites'], ['auth', 'csrf']);
$r->post('/otros-bancos/limites/codigo', [ControladorInterbancario::class, 'codigoLimites'],  ['auth', 'csrf']);

// --- Prevención de lavado de dinero -----------------------------------
//
// `/cumplimiento/reportes` va ANTES que cualquier ruta con comodín que
// empiece igual, o el enrutador tomaría "reportes" por un folio.
$r->get('/cumplimiento',          [ControladorCumplimiento::class, 'panel'],    ['auth']);
$r->get('/cumplimiento/reportes', [ControladorCumplimiento::class, 'reportes'], ['auth']);

$r->post('/cumplimiento/alerta/{folio}/descartar', [ControladorCumplimiento::class, 'descartar'], ['auth', 'csrf']);
$r->post('/cumplimiento/alerta/{folio}/escalar',   [ControladorCumplimiento::class, 'escalar'],   ['auth', 'csrf']);
$r->post('/cumplimiento/alerta/{folio}/prorrogar', [ControladorCumplimiento::class, 'prorrogar'], ['auth', 'csrf']);

$r->post('/cumplimiento/reporte/{folio}/enviado',  [ControladorCumplimiento::class, 'marcarEnviado'], ['auth', 'csrf']);

// --- Pago de servicios y colecturías ----------------------------------
//
// `/servicios/historial` va ANTES que cualquier comodín que empiece
// igual, o el enrutador tomaría "historial" por un identificador.
$r->get('/servicios',           [ControladorServicios::class, 'pantalla'],  ['auth']);
$r->get('/servicios/historial', [ControladorServicios::class, 'historial'], ['auth']);

$r->post('/servicios/consultar', [ControladorServicios::class, 'consultar'], ['auth', 'csrf']);
$r->post('/servicios/pagar',     [ControladorServicios::class, 'pagar'],     ['auth', 'csrf']);

$r->post('/servicios/guardado/{uuid}/olvidar', [ControladorServicios::class, 'olvidar'], ['auth', 'csrf']);

// --- Remesas familiares ------------------------------------------------
//
// Las rutas con nombre fijo van ANTES que las de folio, o el enrutador
// tomaría "ventanilla" por un folio de remesa.
$r->get('/remesas',            [ControladorRemesas::class, 'mias'],       ['auth']);
$r->get('/remesas/ventanilla', [ControladorRemesas::class, 'ventanilla'], ['auth']);

$r->post('/remesas/buscar',    [ControladorRemesas::class, 'buscar'],    ['auth', 'csrf']);
$r->post('/remesas/registrar', [ControladorRemesas::class, 'registrar'], ['auth', 'csrf']);

$r->post('/remesas/{folio}/acreditar', [ControladorRemesas::class, 'acreditar'],     ['auth', 'csrf']);
$r->post('/remesas/{folio}/efectivo',  [ControladorRemesas::class, 'pagarEfectivo'], ['auth', 'csrf']);

// --- Constancias, solvencias y referencias bancarias -------------------
//
// La verificación va SIN `auth`: la usa quien recibe el documento —una
// embajada, un patrono, otro banco— y ésos no tienen cuenta aquí.
// Es la única pantalla del sistema que es así, y es a propósito: una
// constancia que solo puede comprobar un cliente del banco no sirve
// para nada, porque quien necesita comprobarla nunca lo es.
$r->get('/verificar-constancia',  [ControladorConstancias::class, 'pantallaVerificar']);
$r->post('/verificar-constancia', [ControladorConstancias::class, 'verificar'], ['csrf']);

$r->get('/constancias',             [ControladorConstancias::class, 'mias'], ['auth']);
$r->post('/constancias/emitir',     [ControladorConstancias::class, 'emitir'], ['auth', 'csrf']);
$r->get('/constancias/{folio}/pdf', [ControladorConstancias::class, 'pdf'], ['auth']);

/* El camino de agencia. Atención al cliente genera la solicitud —no
   cobra: no tiene gaveta— y la caja la cobra y la emite. */
$r->get('/constancias/agencia',     [ControladorConstancias::class, 'agencia'], ['auth']);
$r->post('/constancias/buscar',     [ControladorConstancias::class, 'buscarCliente'], ['auth', 'csrf']);
$r->post('/constancias/solicitar',  [ControladorConstancias::class, 'solicitar'], ['auth', 'csrf']);
$r->get('/caja/constancias',        [ControladorConstancias::class, 'bandejaCaja'], ['auth']);
$r->post('/caja/constancias/cobrar', [ControladorConstancias::class, 'cobrarSolicitud'], ['auth', 'csrf']);
$r->post('/constancias/solicitud/anular', [ControladorConstancias::class, 'anularSolicitud'], ['auth', 'csrf']);

// --- Depósitos a plazo fijo --------------------------------------------
$r->get('/plazos',             [ControladorPlazoFijo::class, 'pantalla'],   ['auth']);
$r->post('/plazos/simular',    [ControladorPlazoFijo::class, 'simular'],    ['auth', 'csrf']);
$r->post('/plazos/constituir', [ControladorPlazoFijo::class, 'constituir'], ['auth', 'csrf']);
$r->post('/plazos/{folio}/cancelar', [ControladorPlazoFijo::class, 'cancelar'], ['auth', 'csrf']);

// --- Chequeras y cheques -----------------------------------------------
//
// Las de nombre fijo ANTES que las de folio, o el enrutador tomaría
// "solicitar" por el folio de una chequera.
$r->get('/chequeras',            [ControladorChequeras::class, 'pantalla'],  ['auth']);
$r->post('/chequeras/solicitar', [ControladorChequeras::class, 'solicitar'], ['auth', 'csrf']);
$r->post('/chequeras/suspender', [ControladorChequeras::class, 'suspender'], ['auth', 'csrf']);
$r->post('/chequeras/presentar', [ControladorChequeras::class, 'presentar'], ['auth', 'csrf']);

$r->get('/chequeras/{folio}/cheques',   [ControladorChequeras::class, 'cheques'],  ['auth']);
$r->post('/chequeras/{folio}/imprimir', [ControladorChequeras::class, 'imprimir'], ['auth', 'csrf']);
$r->post('/chequeras/{folio}/entregar', [ControladorChequeras::class, 'entregar'], ['auth', 'csrf']);

// --- Ordenes programadas y debito automatico ---------------------------
$r->get('/programadas',        [ControladorProgramadas::class, 'pantalla'], ['auth']);
$r->post('/programadas/crear',  [ControladorProgramadas::class, 'crear'],    ['auth', 'csrf']);
$r->post('/programadas/{folio}/pausar',   [ControladorProgramadas::class, 'pausar'],   ['auth', 'csrf']);
$r->post('/programadas/{folio}/reanudar', [ControladorProgramadas::class, 'reanudar'], ['auth', 'csrf']);
$r->post('/programadas/{folio}/cancelar', [ControladorProgramadas::class, 'cancelar'], ['auth', 'csrf']);

// --- Reclamos formales --------------------------------------------------
//
// Las de nombre fijo ANTES que las de folio, o el enrutador tomaria
// "bandeja" por el folio de un reclamo.
$r->get('/reclamos',          [ControladorReclamos::class, 'mios'],    ['auth']);
$r->get('/reclamos/bandeja',  [ControladorReclamos::class, 'bandeja'], ['auth']);
$r->post('/reclamos/presentar', [ControladorReclamos::class, 'presentar'], ['auth', 'csrf']);

$r->get('/reclamos/{folio}/detalle', [ControladorReclamos::class, 'detalle'], ['auth']);
$r->post('/reclamos/{folio}/aportar',   [ControladorReclamos::class, 'aportar'],   ['auth', 'csrf']);
$r->post('/reclamos/{folio}/responder', [ControladorReclamos::class, 'responder'], ['auth', 'csrf']);
$r->post('/reclamos/{folio}/pedir-informacion', [ControladorReclamos::class, 'pedirInformacion'], ['auth', 'csrf']);
$r->post('/reclamos/{folio}/prorrogar', [ControladorReclamos::class, 'prorrogar'], ['auth', 'csrf']);
$r->post('/reclamos/{folio}/resolver',  [ControladorReclamos::class, 'resolver'],  ['auth', 'csrf']);

// --- Tarjetas ---------------------------------------------------------
$r->get('/tarjetas',          [ControladorTarjetas::class, 'listar'],              ['auth']);
// --- Catálogo y solicitudes de tarjeta ---------------------------------
// El catálogo va ANTES que /tarjetas/{codigo}: si no, el enrutador
// tomaría "catalogo" como un código de tarjeta y respondería 404.
$r->get('/tarjetas/catalogo',     [ControladorCatalogoTarjetas::class, 'catalogo'], ['auth']);
$r->post('/tarjetas/solicitar',   [ControladorCatalogoTarjetas::class, 'solicitar'], ['auth', 'csrf']);
// La felicitación por una tarjeta recién emitida. La pide la pantalla
// al cargar y casi siempre responde que no hay nada: es una consulta
// con indice, no una pantalla.
$r->get('/tarjetas/bienvenida',   [ControladorCatalogoTarjetas::class, 'bienvenida'], ['auth']);
// Las solicitudes del propio cliente, con el recorrido de cada una.
// Va antes de '/tarjetas/{codigo}' o el comodin se la come.
$r->get('/tarjetas/mis-solicitudes', [ControladorCatalogoTarjetas::class, 'mias'], ['auth']);
$r->get('/tarjetas/solicitudes',  [ControladorCatalogoTarjetas::class, 'listar'],    ['auth']);
$r->post('/tarjetas/solicitudes/{codigo}/avanzar',  [ControladorCatalogoTarjetas::class, 'avanzar'],  ['auth', 'csrf']);
$r->post('/tarjetas/solicitudes/{codigo}/rechazar', [ControladorCatalogoTarjetas::class, 'rechazar'], ['auth', 'csrf']);
$r->post('/tarjetas/solicitudes/{codigo}/editar',   [ControladorCatalogoTarjetas::class, 'editar'],   ['auth', 'csrf']);
$r->get('/tarjetas/{codigo}', [ControladorTarjetas::class, 'detalle'],             ['auth']);
$r->post('/tarjetas/emitir',  [ControladorTarjetas::class, 'emitir'],              ['auth', 'csrf']);
$r->post('/tarjetas/{codigo}/bloquear', [ControladorTarjetas::class, 'bloquear'],  ['auth', 'csrf']);
$r->get('/tarjetas/{codigo}/pago',  [ControladorTarjetas::class, 'formularioPago'], ['auth']);
$r->post('/tarjetas/{codigo}/pagar',    [ControladorTarjetas::class, 'pagar'],     ['auth', 'csrf']);

// --- Tarjetas: acciones avanzadas -------------------------------------
$r->post('/tarjetas/{codigo}/codigo-datos', [ControladorTarjetaAcciones::class, 'pedirCodigoRevelacion'], ['auth', 'csrf']);
$r->post('/tarjetas/{codigo}/token-datos',  [ControladorTarjetaAcciones::class, 'solicitarRevelacion'],   ['auth', 'csrf']);
$r->post('/tarjetas/{codigo}/datos',        [ControladorTarjetaAcciones::class, 'revelar'],               ['auth', 'csrf']);
$r->post('/tarjetas/{codigo}/simular',      [ControladorTarjetaAcciones::class, 'simular'],               ['auth']);
$r->get('/tarjetas/{codigo}/estado/{corte}',
    [ControladorTarjetaAcciones::class, 'estadoCuenta'], ['auth']);
$r->post('/tarjetas/{codigo}/diferir',      [ControladorTarjetaAcciones::class, 'diferir'],               ['auth', 'csrf']);
$r->post('/tarjetas/{codigo}/configurar',   [ControladorTarjetaAcciones::class, 'configurar'],            ['auth', 'csrf']);
$r->post('/tarjetas/{codigo}/congelar',     [ControladorTarjetaAcciones::class, 'congelar'],              ['auth', 'csrf']);
$r->post('/tarjetas/{codigo}/consumo',      [ControladorTarjetaAcciones::class, 'simularConsumo'],        ['auth', 'csrf']);
$r->post('/traslado/codigo',   [ControladorTarjetaAcciones::class, 'pedirCodigoTraslado'], ['auth', 'csrf']);
$r->post('/traslado',          [ControladorTarjetaAcciones::class, 'trasladar'],           ['auth', 'csrf']);
$r->post('/adelanto/simular',  [ControladorTarjetaAcciones::class, 'simularAdelanto'],     ['auth', 'csrf']);
$r->post('/adelanto',          [ControladorTarjetaAcciones::class, 'adelantar'],           ['auth', 'csrf']);

// --- Caja (ventanilla) ------------------------------------------------
$r->get('/deposito',           [ControladorCaja::class, 'formularioDeposito'],  ['auth']);
$r->post('/deposito',          [ControladorCaja::class, 'depositar'],           ['auth', 'csrf']);
$r->get('/retiro',             [ControladorCaja::class, 'formularioRetiro'],    ['auth']);
$r->post('/retiro',            [ControladorCaja::class, 'retirar'],             ['auth', 'csrf']);
$r->post('/retiro/codigo',     [ControladorCaja::class, 'codigoRetiro'],        ['auth', 'csrf']);
$r->get('/caja/atencion',      [ControladorCaja::class, 'atencion'],            ['auth']);
$r->post('/caja/buscar-cuenta',[ControladorCaja::class, 'buscarCuenta'],        ['auth']);

// --- Mensajería -------------------------------------------------------
$r->get('/notificaciones',                  [ControladorNotificaciones::class, 'listar'],      ['auth']);
$r->post('/notificaciones/leer-todas',      [ControladorNotificaciones::class, 'marcarTodas'], ['auth', 'csrf']);
$r->post('/notificaciones/{codigo}/leer',   [ControladorNotificaciones::class, 'marcar'],      ['auth', 'csrf']);
$r->get('/mensajes',            [ControladorMensajes::class, 'bandeja'],    ['auth']);
$r->get('/mensajes/enviados',   [ControladorMensajes::class, 'enviados'],   ['auth']);
$r->get('/mensajes/nuevo',      [ControladorMensajes::class, 'formulario'], ['auth']);
$r->post('/mensajes/nuevo',     [ControladorMensajes::class, 'enviar'],     ['auth', 'csrf']);
$r->get('/mensajes/{codigo}',   [ControladorMensajes::class, 'ver'],        ['auth']);
$r->post('/mensajes/{codigo}/archivar', [ControladorMensajes::class, 'archivar'], ['auth', 'csrf']);

// --- Créditos ---------------------------------------------------------
$r->get('/creditos',                 [ControladorCreditos::class, 'listar'],     ['auth']);
$r->post('/creditos/simular',        [ControladorCreditos::class, 'simular'],    ['auth']);
$r->post('/creditos/pagar-cuota',    [ControladorCreditos::class, 'pagarCuota'], ['auth', 'csrf']);
$r->get('/creditos/{codigo}',        [ControladorCreditos::class, 'detalle'],    ['auth']);
$r->post('/creditos/{codigo}/desembolsar', [ControladorCreditos::class, 'desembolsar'], ['auth', 'csrf']);

// --- Arreglos de pago y cobro a distancia -----------------------------
//
// Cobranza no debita la cuenta de nadie: pacta un arreglo o manda un
// enlace para que el cliente pague el. Autorizar un arreglo que condona
// por encima del tope es cosa de otra persona, no de quien lo propuso.
$r->post('/creditos/{codigo}/arreglo',     [ControladorCreditos::class, 'proponerArreglo'], ['auth', 'csrf']);
$r->post('/creditos/{codigo}/enlace-pago', [ControladorCreditos::class, 'enlacePago'],      ['auth', 'csrf']);
$r->post('/creditos/{codigo}/arreglo/{arreglo:[0-9]+}/autorizar', [ControladorCreditos::class, 'autorizarArreglo'], ['auth', 'csrf']);
$r->post('/creditos/{codigo}/arreglo/{arreglo:[0-9]+}/anular',    [ControladorCreditos::class, 'anularArreglo'],    ['auth', 'csrf']);
$r->post('/creditos/{codigo}/arreglo/{arreglo:[0-9]+}/enlace',    [ControladorCreditos::class, 'enlaceArreglo'],    ['auth', 'csrf']);

// El enlace lo abre el cliente desde su correo, sin iniciar sesion: por
// eso va fuera de 'auth'. Lo que lo protege es el token, que es de un
// solo uso y caduca.
$r->get('/pagar-enlace/{token:[A-Za-z0-9_-]+}',  [ControladorEnlacePago::class, 'abrir']);
$r->post('/pagar-enlace/{token:[A-Za-z0-9_-]+}', [ControladorEnlacePago::class, 'iniciar'], ['csrf']);
// Cierre del cobro cuando el navegador ve que salio bien. El servidor
// NO se fia: vuelve a preguntarle a Stripe antes de aplicar nada.
$r->post('/pagar-enlace/{token:[A-Za-z0-9_-]+}/confirmar', [ControladorEnlacePago::class, 'confirmar'], ['csrf']);

// --- Solicitudes de crédito -------------------------------------------
// El portal del banquero: su cartera y la ficha de cada cliente, con
// LAS MISMAS gestiones que el cliente ve de su lado. La restriccion a
// «sus» clientes la aplica el controlador, no el rol.
$r->get('/cartera',           [ControladorBanquero::class, 'cartera'], ['auth']);
$r->get('/cartera/{codigo}',  [ControladorBanquero::class, 'cliente'], ['auth']);
// La banca del cliente preferente: su segmento, sus beneficios y
// las lineas que solo el puede pedir.
$r->get('/preferente',              [ControladorPreferente::class, 'ver'],         ['auth']);
$r->get('/solicitudes',              [ControladorSolicitudes::class, 'listar'],     ['auth']);
$r->get('/solicitudes/nueva',        [ControladorSolicitudes::class, 'formulario'], ['auth']);
$r->post('/solicitudes/nueva',       [ControladorSolicitudes::class, 'crear'],      ['auth', 'csrf']);
$r->get('/solicitudes/{codigo}',     [ControladorSolicitudes::class, 'ver'],        ['auth']);
// --- Referencias: pertenecen al expediente del cliente ---
$r->get('/referencias',                    [ControladorReferencias::class, 'expediente'], ['auth']);
$r->post('/referencias',                   [ControladorReferencias::class, 'registrar'],  ['auth', 'csrf']);
$r->get('/clientes/{codigo}/referencias',  [ControladorReferencias::class, 'expediente'], ['auth']);
$r->post('/clientes/{codigo}/referencias', [ControladorReferencias::class, 'registrar'],  ['auth', 'csrf']);
$r->post('/referencias/{codigo}',           [ControladorReferencias::class, 'modificar'], ['auth', 'csrf']);
$r->post('/referencias/{codigo}/verificar', [ControladorReferencias::class, 'verificar'], ['auth', 'csrf']);
$r->post('/referencias/{codigo}/eliminar',  [ControladorReferencias::class, 'eliminar'],  ['auth', 'csrf']);
// La solicitud solo las consulta: no las crea ni las borra.
$r->get('/solicitudes/{codigo}/referencias', [ControladorReferencias::class, 'deSolicitud'], ['auth']);

$r->post('/solicitudes/{codigo}/avanzar',  [ControladorSolicitudes::class, 'avanzar'],  ['auth', 'csrf']);
$r->post('/solicitudes/{codigo}/rechazar', [ControladorSolicitudes::class, 'rechazar'], ['auth', 'csrf']);

// --- Soporte ----------------------------------------------------------
$r->get('/tickets',             [ControladorTickets::class, 'listar'],     ['auth']);
$r->get('/tickets/nuevo',       [ControladorTickets::class, 'formulario'], ['auth']);
$r->post('/tickets/nuevo',      [ControladorTickets::class, 'crear'],      ['auth', 'csrf']);
$r->get('/tickets/{codigo}',    [ControladorTickets::class, 'ver'],        ['auth']);
$r->post('/tickets/{codigo}/responder', [ControladorTickets::class, 'responder'],     ['auth', 'csrf']);
$r->post('/tickets/{codigo}/estado',    [ControladorTickets::class, 'cambiarEstado'], ['auth', 'csrf']);

// --- Estados de cuenta ------------------------------------------------
$r->get('/estados',            [ControladorEstadosCuenta::class, 'listar'],        ['auth']);
$r->get('/estados/{codigo}',   [ControladorEstadosCuenta::class, 'ver'],           ['auth']);
$r->get('/estados/{codigo}/pdf',[ControladorEstadosCuenta::class, 'descargar'],    ['auth']);
$r->post('/estados/generar',   [ControladorEstadosCuenta::class, 'generar'],       ['auth', 'csrf']);
$r->post('/estados/{codigo}/enviar', [ControladorEstadosCuenta::class, 'enviar'],  ['auth', 'csrf']);

// --- Ventanilla: turno de caja y notas contables ----------------------
// `caja/notas` va ANTES que `caja/{codigo}`: si no, el enrutador tomaría
// "notas" por el código de un turno y respondería 404.
$r->get('/caja',                        [ControladorCajaTurno::class, 'panel'],        ['auth']);
$r->post('/caja/abrir',                 [ControladorCajaTurno::class, 'abrir'],        ['auth', 'csrf']);
$r->get('/caja/notas',                  [ControladorCajaTurno::class, 'notas'],        ['auth']);
$r->post('/caja/notas/emitir',          [ControladorCajaTurno::class, 'emitirNota'],   ['auth', 'csrf']);
$r->post('/caja/notas/{codigo}/autorizar', [ControladorCajaTurno::class, 'autorizarNota'], ['auth', 'csrf']);
$r->post('/caja/notas/{codigo}/rechazar',  [ControladorCajaTurno::class, 'rechazarNota'],  ['auth', 'csrf']);
$r->post('/caja/notas/{codigo}/anular',    [ControladorCajaTurno::class, 'anularNota'],    ['auth', 'csrf']);
$r->get('/caja/{codigo}',               [ControladorCajaTurno::class, 'detalle'],      ['auth']);
$r->post('/caja/{codigo}/cerrar',       [ControladorCajaTurno::class, 'cerrar'],       ['auth', 'csrf']);
$r->post('/caja/{codigo}/arquear',      [ControladorCajaTurno::class, 'arquear'],      ['auth', 'csrf']);
// --- Tienda pública ---------------------------------------------------
// SIN `auth`: la tienda es la cara pública de los comercios afiliados.
// El orden importa: las rutas con nombre fijo van antes que las que
// llevan comodín, o el enrutador tomaría "carrito" por el código de un
// artículo.
$r->get('/tienda',                   [ControladorTiendaPublica::class, 'catalogo']);
$r->get('/tienda/carrito',           [ControladorTiendaPublica::class, 'verCarrito']);
$r->post('/tienda/carrito',          [ControladorTiendaPublica::class, 'actualizarCarrito'], ['csrf']);
$r->post('/tienda/agregar',          [ControladorTiendaPublica::class, 'agregar'],           ['csrf']);
$r->post('/tienda/vaciar',           [ControladorTiendaPublica::class, 'vaciar'],            ['csrf']);
$r->get('/tienda/pagar',             [ControladorTiendaPublica::class, 'pagar']);
$r->post('/tienda/pagar/tarjeta',    [ControladorTiendaPublica::class, 'pagarConTarjeta'],   ['auth', 'csrf']);
$r->post('/tienda/pagar/numero',     [ControladorTiendaPublica::class, 'pagarTecleando'],    ['csrf']);
$r->post('/tienda/pagar/pasarela',   [ControladorTiendaPublica::class, 'iniciarPasarela'],   ['csrf']);
// Cierra el cobro sin esperar al aviso de Stripe, que a una
// instalación sin dirección pública no llega nunca.
$r->post('/tienda/pagar/confirmar',  [ControladorTiendaPublica::class, 'confirmarPasarela'],  ['csrf']);
// El patrón lleva el punto a mano: el comodín por defecto no lo
// admite y un nombre de archivo sin extensión no existe.
$r->get('/tienda/imagen/{nombre:[A-Za-z0-9_.-]+}', [ControladorTiendaAdmin::class, 'imagen']);

// Los pedidos del cliente y del personal viven en el panel.
$r->get('/tienda/pedidos',           [ControladorTienda::class, 'pedidos'], ['auth']);
$r->post('/tienda/pedidos/{codigo}/estado', [ControladorTienda::class, 'estado'], ['auth', 'csrf']);

// --- Panel de la tienda -----------------------------------------------
$r->get('/tienda/admin',                    [ControladorTiendaAdmin::class, 'panel'],      ['auth']);
$r->post('/tienda/admin/comercio',          [ControladorTiendaAdmin::class, 'guardarComercio'], ['auth', 'csrf']);
$r->post('/tienda/admin/articulo',          [ControladorTiendaAdmin::class, 'guardarArticulo'], ['auth', 'csrf']);
$r->post('/tienda/admin/articulo/{codigo}/foto', [ControladorTiendaAdmin::class, 'subirFoto'], ['auth', 'csrf']);
$r->post('/tienda/admin/articulo/{codigo}/baja', [ControladorTiendaAdmin::class, 'darBaja'],   ['auth', 'csrf']);

// El seguimiento por folio va al final: su comodín se comería a las
// rutas de arriba si estuviera antes.
$r->get('/tienda/articulo/{codigo}',  [ControladorTiendaPublica::class, 'articulo']);
// Sin `auth` ni `csrf`: es una pantalla de solo mirar, y quien llega
// aquí es precisamente el que no tiene sesión.
$r->get('/tienda/seguimiento',        [ControladorTiendaPublica::class, 'seguimiento']);
$r->get('/tienda/pedido/{folio}',     [ControladorTiendaPublica::class, 'pedido']);
$r->post('/tienda/pedido/{folio}',    [ControladorTiendaPublica::class, 'pedido'], ['csrf']);

// --- Cobranza y departamento juridico ---------------------------------
$r->get('/cobranza',                     [ControladorCobranza::class, 'listar'],    ['auth']);
$r->get('/cobranza/{codigo}',            [ControladorCobranza::class, 'ver'],       ['auth']);
$r->post('/cobranza/{codigo}/gestion',   [ControladorCobranza::class, 'gestionar'], ['auth', 'csrf']);
$r->post('/cobranza/{codigo}/escalar',   [ControladorCobranza::class, 'escalar'],   ['auth', 'csrf']);
$r->post('/cobranza/{codigo}/judicial',  [ControladorCobranza::class, 'judicial'],  ['auth', 'csrf']);
$r->post('/cobranza/{codigo}/cerrar',    [ControladorCobranza::class, 'cerrar'],    ['auth', 'csrf']);

// --- Cartera morosa ---------------------------------------------------
$r->get('/cartera',            [ControladorCartera::class, 'morosos'],             ['auth']);
$r->get('/cartera/exportar',   [ControladorCartera::class, 'exportar'],            ['auth']);

// --- Tiempo real ------------------------------------------------------
$r->get('/eventos',            [ControladorEventos::class, 'stream'],              ['auth']);
$r->get('/api/sesion',         [ControladorEventos::class, 'cuentaAtras'],         ['auth']);
$r->get('/api/pulso',          [ControladorEventos::class, 'pulso'],               ['auth']);
$r->get('/api/notificaciones', [ControladorEventos::class, 'notificaciones'],      ['auth']);
$r->post('/api/notificaciones/leer', [ControladorEventos::class, 'marcarLeidas'],  ['auth', 'csrf']);

// --- Reestructuraciones -----------------------------------------------
// --- Ofertas preaprobadas ---------------------------------------------
// `aceptar` no exige permiso de staff a proposito: la preaprobacion ES
// la autorizacion de la institucion, y el servicio comprueba que quien
// acepta sea el titular de la oferta.
$r->get('/ofertas',                  [ControladorOfertas::class, 'mias'],     ['auth']);
$r->get('/ofertas/cartera',          [ControladorOfertas::class, 'cartera'],  ['auth']);
$r->post('/ofertas/generar',         [ControladorOfertas::class, 'generar'],  ['auth', 'csrf']);
$r->post('/ofertas/{codigo}/aceptar', [ControladorOfertas::class, 'aceptar'], ['auth', 'csrf']);
$r->post('/ofertas/{codigo}/rechazar',[ControladorOfertas::class, 'rechazar'],['auth', 'csrf']);
$r->post('/ofertas/{codigo}/anular',  [ControladorOfertas::class, 'anular'],  ['auth', 'csrf']);

// --- Contratos y finiquitos ---
$r->get('/contratos',                  [ControladorContratos::class, 'listar'],   ['auth']);
$r->get('/contratos/{codigo}/pdf',     [ControladorContratos::class, 'ver'],      ['auth']);
$r->post('/contratos/{codigo}/firmar', [ControladorContratos::class, 'firmar'],   ['auth', 'csrf']);
$r->post('/creditos/{codigo}/contrato',  [ControladorContratos::class, 'generar'],   ['auth', 'csrf']);
$r->post('/creditos/{codigo}/finiquito', [ControladorContratos::class, 'finiquito'], ['auth', 'csrf']);

// --- Pasarela de pago (tarjeta de otro banco) -------------------------
// El webhook NO lleva 'auth' ni 'csrf' a propósito: lo llama Stripe, no
// un navegador, y su autenticación es la firma del propio aviso, que se
// comprueba dentro del controlador antes de mirar su contenido.
$r->get('/pagar',              [ControladorPasarela::class, 'formulario'],   ['auth']);
$r->post('/pagar/iniciar',     [ControladorPasarela::class, 'iniciar'],      ['auth', 'csrf']);
// Sin este paso el cobro se quedaba esperando al webhook, que en una
// instalacion sin acceso desde fuera no llega nunca: el dinero salia
// de la tarjeta del cliente y el saldo no se movia.
$r->post('/pagar/confirmar',   [ControladorPasarela::class, 'confirmar'],    ['auth', 'csrf']);
$r->post('/api/stripe',        [ControladorPasarela::class, 'webhook']);
$r->get('/pagos/conciliacion', [ControladorPasarela::class, 'conciliacion'], ['auth']);
$r->post('/pagos/conciliacion/sincronizar', [ControladorPasarela::class, 'sincronizar'], ['auth', 'csrf']);

// --- Procesos periódicos ----------------------------------------------
// El devengo de mora y el corte de tarjetas corren programados desde
// consola; estas rutas existen para revisarlos y para lanzarlos a mano
// el día que la tarea no se haya ejecutado.
// El tablero de la Junta Directiva. Solo lectura: no hay POST que le
// corresponda, y eso es parte de lo que lo define.
$r->get('/junta',               [ControladorJunta::class, 'tablero'],             ['auth']);
$r->get('/junta/evolucion',     [ControladorJunta::class, 'evolucion'],           ['auth']);

$r->get('/procesos',            [ControladorProcesos::class, 'panel'],            ['auth']);
$r->post('/procesos/mora',      [ControladorProcesos::class, 'devengarMora'],     ['auth', 'csrf']);
$r->post('/procesos/cortes',    [ControladorProcesos::class, 'cortarTarjetas'],   ['auth', 'csrf']);
$r->post('/procesos/parametro', [ControladorProcesos::class, 'guardarParametro'], ['auth', 'csrf']);

// Disparador del cron web para hosting SIN procesos (exec deshabilitado):
// un cron externo (cron-job.org, UptimeRobot) lo visita cada pocos minutos
// y corre UNA tarea vencida en el mismo proceso. Sin 'auth' a propósito: se
// autentica con la clave CRON_CLAVE del .env, como el webhook con su firma.
$r->get('/tareas/correr', [ControladorProcesos::class, 'correrWeb']);

$r->get('/cuotas',           [ControladorCuotas::class, 'listar'],   ['auth']);
$r->get('/reestructuraciones',          [ControladorReestructuraciones::class, 'listar'],    ['auth']);
$r->post('/reestructuraciones/simular', [ControladorReestructuraciones::class, 'simular'],   ['auth', 'csrf']);
$r->post('/reestructuraciones/solicitar',[ControladorReestructuraciones::class, 'solicitar'],['auth', 'csrf']);
$r->post('/reestructuraciones/{codigo}/aprobar',  [ControladorReestructuraciones::class, 'aprobar'],  ['auth', 'csrf']);
$r->post('/reestructuraciones/{codigo}/rechazar', [ControladorReestructuraciones::class, 'rechazar'], ['auth', 'csrf']);

// --- Documentos PDF ---------------------------------------------------
$r->get('/creditos/{codigo}/amortizacion-pdf', [ControladorCreditos::class, 'amortizacionPdf'], ['auth']);

// --- Clientes ---------------------------------------------------------
$r->get('/clientes',             [ControladorClientes::class, 'listar'], ['auth']);
$r->get('/clientes/{codigo}',    [ControladorClientes::class, 'ver'],    ['auth']);
$r->post('/clientes/{codigo}',   [ControladorClientes::class, 'actualizar'], ['auth', 'csrf']);

// --- Usuarios y roles -------------------------------------------------
$r->get('/usuarios',             [ControladorUsuarios::class, 'listar'],     ['auth']);
$r->get('/usuarios/nuevo',       [ControladorUsuarios::class, 'formulario'], ['auth']);
$r->post('/usuarios/nuevo',      [ControladorUsuarios::class, 'crear'],      ['auth', 'csrf']);
// --- Catálogos: productos y límites (el menú ya los enlazaba) ---
$r->get('/productos',                  [ControladorCatalogos::class, 'productos'],        ['auth']);
$r->post('/productos',                 [ControladorCatalogos::class, 'guardarProducto'],  ['auth', 'csrf']);
$r->post('/productos/{codigo}/estado', [ControladorCatalogos::class, 'alternarProducto'], ['auth', 'csrf']);
$r->get('/limites',                    [ControladorCatalogos::class, 'limites'],          ['auth']);
$r->post('/limites/{codigo}',          [ControladorCatalogos::class, 'guardarLimite'],    ['auth', 'csrf']);

$r->post('/roles',                 [ControladorUsuarios::class, 'crearRol'],     ['auth', 'csrf']);
$r->post('/roles/{id:\d+}',            [ControladorUsuarios::class, 'modificarRol'], ['auth', 'csrf']);
$r->post('/roles/{id:\d+}/permiso',    [ControladorUsuarios::class, 'permisoRol'],   ['auth', 'csrf']);
$r->post('/roles/{id:\d+}/eliminar',   [ControladorUsuarios::class, 'eliminarRol'],  ['auth', 'csrf']);
$r->get('/roles',                [ControladorUsuarios::class, 'roles'],      ['auth']);
$r->get('/usuarios/{codigo}',              [ControladorUsuarios::class, 'ver'],         ['auth']);
$r->post('/usuarios/{codigo}',             [ControladorUsuarios::class, 'modificar'],   ['auth', 'csrf']);
$r->post('/usuarios/{codigo}/expediente',  [ControladorUsuarios::class, 'expediente'],  ['auth', 'csrf']);
$r->post('/usuarios/{codigo}/estado', [ControladorUsuarios::class, 'cambiarEstado'],   ['auth', 'csrf']);
$r->post('/usuarios/{codigo}/clave',  [ControladorUsuarios::class, 'restablecerClave'],['auth', 'csrf']);

// --- Auditoría y accesos ----------------------------------------------
$r->get('/auditoria',            [ControladorAuditoria::class, 'listar'],     ['auth']);
$r->get('/auditoria/integridad', [ControladorAuditoria::class, 'integridad'], ['auth']);
$r->get('/accesos',              [ControladorAuditoria::class, 'accesos'],    ['auth']);

// --- Reportes ---------------------------------------------------------
$r->get('/reportes',               [ControladorReportes::class, 'indicadores'],   ['auth']);
$r->get('/reportes/transacciones', [ControladorReportes::class, 'transacciones'], ['auth']);
$r->get('/reportes/cartera',       [ControladorReportes::class, 'cartera'],       ['auth']);

// --- Perfil -----------------------------------------------------------
$r->get('/perfil',                 [ControladorPerfil::class, 'ver'],         ['auth']);
$r->post('/perfil',                [ControladorPerfil::class, 'actualizar'],  ['auth', 'csrf']);
$r->get('/perfil/sesiones',        [ControladorPerfil::class, 'sesiones'],    ['auth']);
$r->post('/perfil/dispositivo/{id}/desconocer', [ControladorPerfil::class, 'desconocerDispositivo'], ['auth', 'csrf']);
$r->post('/perfil/cerrar-otras',   [ControladorPerfil::class, 'cerrarOtras'], ['auth', 'csrf']);
// La apariencia que eligio: se guarda para que le acompane a su
// otro dispositivo.
$r->post('/perfil/tema',           [ControladorPerfil::class, 'tema'],        ['auth', 'csrf']);

// --- Seguridad: segundo factor ----------------------------------------
$r->get('/seguridad',              [ControladorSeguridad::class, 'ver'],              ['auth']);
$r->post('/seguridad/totp/iniciar',[ControladorSeguridad::class, 'iniciarTotp'],      ['auth', 'csrf']);
$r->post('/seguridad/totp/confirmar',[ControladorSeguridad::class, 'confirmarTotp'],  ['auth', 'csrf']);
$r->post('/seguridad/totp/desactivar',[ControladorSeguridad::class, 'desactivarTotp'],['auth', 'csrf']);
$r->post('/seguridad/totp/respaldo',[ControladorSeguridad::class, 'regenerarRespaldo'],['auth', 'csrf']);
$r->get('/api/totp/pulso',         [ControladorSeguridad::class, 'pulsoTotp'],        ['auth']);

// --- Documentos del expediente ----------------------------------------
$r->get('/documentos',              [ControladorDocumentos::class, 'listar'],  ['auth']);
$r->post('/documentos',             [ControladorDocumentos::class, 'subir'],   ['auth', 'csrf']);
$r->get('/documentos/{codigo}',     [ControladorDocumentos::class, 'ver'],     ['auth']);
$r->post('/documentos/{codigo}/verificar', [ControladorDocumentos::class, 'verificar'], ['auth', 'csrf']);
$r->post('/documentos/{codigo}/eliminar',  [ControladorDocumentos::class, 'eliminar'],  ['auth', 'csrf']);

// --- Errores ----------------------------------------------------------
$r->get('/error/{codigo:[0-9]{3}}', [ControladorError::class, 'mostrar']);

// =====================================================================
// DESPACHO
// =====================================================================

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';

try {
    $ruta = $r->resolver($metodo, $uri);

    if ($ruta === null) {
        $permitidos = $r->metodosPermitidos($uri);
        if ($permitidos !== []) {
            header('Allow: ' . implode(', ', $permitidos));
            throw new MetodoNoPermitido('Método no permitido para esta ruta.');
        }
        throw new RecursoNoEncontrado('Página no encontrada.');
    }

    // --- Middleware ---------------------------------------------------
    foreach ($ruta['middleware'] as $mw) {
        switch ($mw) {
            case 'auth':
                if (!Sesion::autenticado()) {
                    if (Respuesta::esperaJson()) {
                        Respuesta::error('Su sesión expiró. Por favor, vuelva a ingresar.', 401);
                    }
                    Respuesta::redirigir($r->url('entrar'));
                }
                break;

            case 'csrf':
                // Doble barrera: token sincronizado + verificación de origen.
                $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

                /* El propósito puede venir por cabecera.
                 *
                 * Las peticiones de fondo —marcar notificaciones como
                 * leídas, por ejemplo— mandan el token en la cabecera y
                 * no tienen formulario donde poner el campo. Sin esta
                 * línea, su propósito se leía como «global», no
                 * coincidía con el que el layout emitió («api») y la
                 * petición fallaba SIEMPRE, no solo la segunda vez. */
                $proposito = $_POST['_csrf_proposito']
                    ?? ($_SERVER['HTTP_X_CSRF_PROPOSITO'] ?? 'global');

                if (!Csrf::validar(is_string($token) ? $token : null, (string) $proposito)) {
                    Auditoria::registrar('csrf.token_invalido', Auditoria::DENEGADO, Sesion::usuarioId());

                    if (Respuesta::esperaJson()) {
                        /* Se devuelve un token NUEVO junto con el error.
                         *
                         * Antes no se devolvía ninguno, y eso dejaba la
                         * pantalla muerta: cada reintento volvía a fallar
                         * y la única salida era recargar. Pedirle al
                         * cliente que recargue es pedirle que arregle a
                         * mano un problema nuestro.
                         *
                         * Darlo no debilita nada. Un sitio ajeno no puede
                         * LEER esta respuesta —la política de origen
                         * cruzado se lo impide— así que jamás llega a ver
                         * el token. Quien sí lo lee es nuestra propia
                         * página, que reintenta la acción que el usuario
                         * ya había pedido. Y la comprobación de origen,
                         * que es independiente, sigue en pie.
                         *
                         * `codigo` es lo que el navegador mira para saber
                         * que puede reintentar: sin esa marca no lo hace,
                         * para no repetir una operación por un fallo que
                         * fuera de otra cosa. */
                        Respuesta::error(
                            'El token de seguridad venció. Vuelva a intentarlo.',
                            403,
                            [
                                'codigo'          => 'csrf_vencido',
                                'csrf'            => Csrf::token((string) $proposito),
                                'csrf_proposito'  => (string) $proposito,
                            ]
                        );
                    }

                    throw new AccesoDenegado('Token de seguridad inválido.');
                }

                if (!Csrf::origenValido(Config::obtener('APP_DOMINIO', 'localhost'))) {
                    Auditoria::registrar('csrf.origen_invalido', Auditoria::DENEGADO, Sesion::usuarioId());
                    throw new AccesoDenegado('Origen de la petición no válido.');
                }
                break;
        }
    }

    /* --- Lo que hay que hacer antes de poder hacer nada más ----------
     *
     * Dos trámites bloquean el resto del sistema mientras estén
     * pendientes, y en este orden:
     *
     *   1. LA CONTRASEÑA. Si está marcada para cambio —porque se la
     *      dieron hecha— o si ya cumplió su plazo, no se entra a
     *      ninguna otra pantalla hasta cambiarla. Antes solo se
     *      miraba la marca; el plazo no lo miraba nadie, así que
     *      «cambiarla cada dos meses» no ocurría nunca.
     *
     *   2. EL PERFIL. Un banco no da productos a quien no ha dicho de
     *      qué vive. El cliente lo declara UNA vez, al entrar, y a
     *      partir de ahí los cambios se hacen en agencia.
     *
     * Las rutas exentas son las que hacen falta para cumplirlos —y
     * para poder salir—: si se bloqueara también la pantalla donde se
     * cambia la clave, no habría manera de desbloquearse.
     *
     * Las peticiones que esperan JSON se dejan pasar: redirigir una
     * llamada de fondo no la lleva a ninguna pantalla, solo rompe la
     * que la hizo con una respuesta que no sabe leer. */
    if (Sesion::autenticado()) {
        $rutaActual = rtrim(parse_url($uri, PHP_URL_PATH) ?: '', '/');

        $exentas  = ['/clave', '/salir', '/mi-perfil/declarar'];
        $esExenta = false;

        foreach ($exentas as $e) {
            if (str_ends_with($rutaActual, $e)) {
                $esExenta = true;
                break;
            }
        }

        if (!$esExenta && !Respuesta::esperaJson()) {
            $u = \CashMan\Infraestructura\Persistencia\BaseDatos::unaFila(
                'SELECT u.id, u.rol_id, u.requiere_cambio_password,
                        u.password_actualizado_en,
                        d.perfil_declarado_en, d.ingreso_mensual_cent,
                        rol.clave AS rol_clave
                   FROM usuarios u
                   JOIN roles rol ON rol.id = u.rol_id
              LEFT JOIN detalle_usuarios d ON d.usuario_id = u.id
                  WHERE u.id = ?',
                [Sesion::usuarioId()]
            );

            if ($u !== null) {
                $deber = (new \CashMan\Aplicacion\Servicio\ServicioPoliticaPassword())->revisar($u);

                if ($deber['debe']) {
                    $_SESSION['motivo_cambio_clave'] = $deber['mensaje'];
                    Respuesta::redirigir($r->url('clave'));
                }

                /* El perfil, solo a los clientes: a un cajero no se le
                   pregunta de qué vive para dejarlo trabajar. */
                $esCliente = (string) $u['rol_clave'] === 'cliente';

                if ($esCliente
                    && \CashMan\Infraestructura\Parametros::booleano('registro.exigir_perfil', true)
                    && $u['perfil_declarado_en'] === null) {
                    Respuesta::redirigir($r->url('mi-perfil/declarar'));
                }
            }
        }
    }

    // --- Ejecutar el controlador --------------------------------------
    [$clase, $metodoControlador] = $ruta['manejador'];
    $controlador = new $clase($r);
    $controlador->{$metodoControlador}($ruta['parametros']);

} catch (RecursoNoEncontrado $e) {
    (new ControladorError($r))->responder(404, $e->getMessage());

} catch (AccesoDenegado $e) {
    (new ControladorError($r))->responder(403, $e->getMessage());

} catch (LimiteExcedido $e) {
    header('Retry-After: ' . $e->reintentarEn());
    (new ControladorError($r))->responder(429, $e->getMessage());

} catch (MetodoNoPermitido $e) {
    (new ControladorError($r))->responder(405, $e->getMessage());

} catch (\Throwable $e) {
    error_log('[despacho] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    if (Config::depuracion()) {
        throw $e;
    }
    (new ControladorError($r))->responder(500, 'Ocurrió un error inesperado.');
}

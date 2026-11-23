<?php
declare(strict_types=1);

namespace CashMan\Http\Controlador;

use CashMan\Http\Respuesta;

final class ControladorError extends ControladorBase
{
    private const MENSAJES = [
        400 => ['Petición incorrecta',   'La solicitud no se pudo interpretar.'],
        401 => ['Sesión requerida',      'Necesitas iniciar sesión para continuar.'],
        403 => ['Acceso denegado',       'No tiene permiso para ver esta sección.'],
        404 => ['Página no encontrada',  'La dirección que buscas no existe.'],
        405 => ['Método no permitido',   'Esa operación no está disponible por esta vía.'],
        419 => ['Sesión vencida',        'Por seguridad, vuelve a cargar la página.'],
        429 => ['Demasiadas solicitudes','Espera un momento antes de volver a intentar.'],
        500 => ['Error del servidor',    'Algo salió mal de nuestro lado. Ya quedó registrado.'],
    ];

    public function mostrar(array $parametros = []): void
    {
        $codigo = (int) ($parametros['codigo'] ?? 404);
        $this->responder($codigo);
    }

    public function responder(int $codigo, string $detalle = ''): void
    {
        if (!isset(self::MENSAJES[$codigo])) {
            $codigo = 500;
        }

        [$titulo, $descripcion] = self::MENSAJES[$codigo];

        if (Respuesta::esperaJson()) {
            Respuesta::error($detalle !== '' ? $detalle : $descripcion, $codigo);
        }

        $this->renderPlano('error', [
            'titulo'      => $titulo . ' · CashMan H.A.',
            'codigo'      => $codigo,
            'encabezado'  => $titulo,
            'descripcion' => $detalle !== '' ? $detalle : $descripcion,
            'autenticado' => $this->usuarioActual() !== null,
        ], $codigo);
    }
}

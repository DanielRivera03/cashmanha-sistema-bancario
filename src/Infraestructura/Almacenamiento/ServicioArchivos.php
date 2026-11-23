<?php
declare(strict_types=1);

namespace CashMan\Infraestructura\Almacenamiento;

use CashMan\Infraestructura\Seguridad\Auditoria;
use CashMan\Infraestructura\Seguridad\Sesion;

/**
 * Subida y entrega de archivos.
 *
 * Esta clase existe para cerrar el agujero más grave del sistema
 * anterior. En v1 el código era, literalmente:
 *
 *     $Directorio = "../vista/images/fotoperfil/" . $fecha . "_"
 *                 . uniqid() . "_" . $_FILES['foto']['name'];
 *     copy($_FILES['foto']['tmp_name'], $Directorio);
 *
 * Cuatro fallos apilados:
 *
 *   1. copy() en vez de move_uploaded_file(): no comprueba que el
 *      archivo venga realmente de una subida HTTP.
 *   2. Conserva el nombre y la EXTENSIÓN del cliente, sin lista blanca
 *      ni verificación del contenido real.
 *   3. El destino estaba DENTRO del webroot, así que Apache ejecutaba
 *      cualquier .php subido.
 *   4. El nombre se concatenaba sin sanear: con ../ se escapaba del
 *      directorio previsto.
 *
 * Subir "foto.php" daba shell en el servidor.
 *
 * Aquí: destino fuera del webroot, nombre generado por el servidor,
 * tipo verificado por CONTENIDO (no por extensión ni por el
 * Content-Type que manda el cliente), re-codificación de imágenes para
 * destruir cualquier carga útil incrustada, y entrega por PHP con
 * verificación de permiso.
 */
final class ServicioArchivos
{
    /** Tipos permitidos: firma binaria real → extensión que asignamos. */
    private const PERMITIDOS = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    private const MAX_BYTES  = 5 * 1024 * 1024;   // 5 MB
    private const MAX_PIXELS = 50_000_000;        // ~50 MP: frena bombas de descompresión

    /**
     * Procesa un archivo subido.
     *
     * @param array<string,mixed> $archivo Entrada de $_FILES
     * @param string $categoria Subcarpeta: 'documentos', 'perfil'…
     * @return array{nombre:string, ruta:string, tipo:string, tamano:int, hash:string}
     */
    public function guardar(array $archivo, string $categoria = 'documentos'): array
    {
        $this->validarSubida($archivo);

        $tmp = (string) $archivo['tmp_name'];

        // Esta comprobación es la que impide que alguien escriba un
        // archivo arbitrario del servidor pasándolo como "subida".
        if (!is_uploaded_file($tmp)) {
            Auditoria::registrar('archivo.subida_falsa', Auditoria::DENEGADO, Sesion::usuarioId());
            throw new ArchivoInvalido('El archivo no proviene de una subida válida.');
        }

        // El tipo se determina por el CONTENIDO, nunca por la extensión
        // ni por el Content-Type que declara el navegador: ambos los
        // controla el atacante.
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);

        if (!isset(self::PERMITIDOS[$mime])) {
            Auditoria::registrar(
                'archivo.tipo_rechazado', Auditoria::DENEGADO, Sesion::usuarioId(),
                'archivo', '', ['mime_detectado' => $mime]
            );
            throw new ArchivoInvalido(
                'Tipo de archivo no permitido. Se aceptan JPG, PNG, WEBP y PDF.'
            );
        }

        $extension = self::PERMITIDOS[$mime];
        $esImagen  = $mime !== 'application/pdf';

        if ($esImagen) {
            $this->validarImagen($tmp);
        } else {
            $this->validarPdf($tmp);
        }

        // Nombre generado por el servidor. El del cliente se descarta
        // por completo: no se usa ni para derivar el nuevo.
        $nombre = date('Ymd') . '_' . bin2hex(random_bytes(16)) . '.' . $extension;

        $dir = $this->directorio($categoria);
        $destino = $dir . DIRECTORY_SEPARATOR . $nombre;

        if ($esImagen) {
            // Re-codificar la imagen: descarta metadatos EXIF y
            // destruye cualquier carga útil incrustada (una imagen
            // puede llevar PHP en un comentario y seguir siendo válida).
            $this->recodificarImagen($tmp, $destino, $extension);
        } else {
            if (!move_uploaded_file($tmp, $destino)) {
                throw new ArchivoInvalido('No se pudo guardar el archivo.');
            }
        }

        // Nunca ejecutable, pase lo que pase con la configuración.
        @chmod($destino, 0640);

        $hash = (string) hash_file('sha256', $destino);

        Auditoria::registrar(
            'archivo.subido', Auditoria::EXITO, Sesion::usuarioId(),
            'archivo', $nombre,
            ['categoria' => $categoria, 'tipo' => $mime, 'bytes' => filesize($destino)]
        );

        return [
            'nombre' => $nombre,
            'ruta'   => $destino,
            'tipo'   => $mime,
            'tamano' => (int) filesize($destino),
            'hash'   => $hash,
        ];
    }

    /**
     * Devuelve la ruta en disco de un archivo ya guardado.
     *
     * El nombre se valida contra un patrón estricto: así, aunque
     * llegara manipulado desde la base o desde una URL, no puede
     * apuntar fuera del directorio.
     */
    public function rutaDe(string $nombre, string $categoria = 'documentos'): ?string
    {
        if (!preg_match('/^\d{8}_[a-f0-9]{32}\.(jpg|png|webp|pdf)$/', $nombre)) {
            return null;
        }

        $ruta = $this->directorio($categoria) . DIRECTORY_SEPARATOR . $nombre;

        // Comprobación final contra travesía de directorios.
        $real = realpath($ruta);
        $base = realpath($this->directorio($categoria));

        if ($real === false || $base === false || !str_starts_with($real, $base)) {
            return null;
        }

        return is_readable($real) ? $real : null;
    }

    public function eliminar(string $nombre, string $categoria = 'documentos'): bool
    {
        $ruta = $this->rutaDe($nombre, $categoria);
        if ($ruta === null) {
            return false;
        }

        Auditoria::registrar('archivo.eliminado', Auditoria::EXITO,
            Sesion::usuarioId(), 'archivo', $nombre);

        return @unlink($ruta);
    }

    public function tipoMime(string $ruta): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($ruta);
        return isset(self::PERMITIDOS[$mime]) ? $mime : 'application/octet-stream';
    }

    // -----------------------------------------------------------------

    /** @param array<string,mixed> $archivo */
    private function validarSubida(array $archivo): void
    {
        $error = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new ArchivoInvalido(match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    'El archivo supera el tamaño máximo permitido.',
                UPLOAD_ERR_PARTIAL   => 'La subida se interrumpió. Intenta de nuevo.',
                UPLOAD_ERR_NO_FILE   => 'No se seleccionó ningún archivo.',
                UPLOAD_ERR_NO_TMP_DIR,
                UPLOAD_ERR_CANT_WRITE => 'Error del servidor al recibir el archivo.',
                UPLOAD_ERR_EXTENSION => 'La subida fue bloqueada por el servidor.',
                default              => 'No se pudo procesar el archivo.',
            });
        }

        $tamano = (int) ($archivo['size'] ?? 0);

        if ($tamano <= 0) {
            throw new ArchivoInvalido('El archivo está vacío.');
        }
        if ($tamano > self::MAX_BYTES) {
            throw new ArchivoInvalido(
                'El archivo supera los ' . (self::MAX_BYTES / 1024 / 1024) . ' MB permitidos.'
            );
        }
    }

    private function validarImagen(string $ruta): void
    {
        $info = @getimagesize($ruta);

        if ($info === false) {
            throw new ArchivoInvalido('El archivo no es una imagen válida.');
        }

        [$ancho, $alto] = $info;

        if ($ancho < 50 || $alto < 50) {
            throw new ArchivoInvalido('La imagen es demasiado pequeña (mínimo 50×50 px).');
        }

        // Una imagen de dimensiones enormes puede agotar la memoria al
        // descomprimirse, aunque el archivo pese poco.
        if ($ancho * $alto > self::MAX_PIXELS) {
            throw new ArchivoInvalido('La imagen tiene una resolución excesiva.');
        }
    }

    private function validarPdf(string $ruta): void
    {
        $cabecera = (string) @file_get_contents($ruta, false, null, 0, 1024);

        if (!str_starts_with($cabecera, '%PDF-')) {
            throw new ArchivoInvalido('El archivo no es un PDF válido.');
        }

        // Un PDF puede llevar JavaScript o acciones automáticas. Para
        // un documento de identidad no hay motivo legítimo para ello.
        $contenido = (string) @file_get_contents($ruta);
        foreach (['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/OpenAction'] as $peligroso) {
            if (stripos($contenido, $peligroso) !== false) {
                Auditoria::registrar(
                    'archivo.pdf_activo_rechazado', Auditoria::DENEGADO,
                    Sesion::usuarioId(), 'archivo', '', ['marcador' => $peligroso]
                );
                throw new ArchivoInvalido(
                    'El PDF contiene elementos activos y no puede aceptarse. '
                    . 'Sube una versión impresa o una imagen del documento.'
                );
            }
        }
    }

    /**
     * Re-codifica la imagen a partir de sus píxeles.
     *
     * Esto es lo que neutraliza el truco del polyglot: un archivo que
     * es imagen válida Y código PHP a la vez. Al reconstruirla desde
     * los píxeles, todo lo que no sea imagen desaparece.
     */
    private function recodificarImagen(string $origen, string $destino, string $extension): void
    {
        if (!extension_loaded('gd')) {
            // Sin GD no se puede re-codificar; mejor rechazar que
            // guardar un archivo sin sanear.
            throw new ArchivoInvalido('El servidor no puede procesar imágenes en este momento.');
        }

        $img = match ($extension) {
            'jpg'   => @imagecreatefromjpeg($origen),
            'png'   => @imagecreatefrompng($origen),
            'webp'  => @imagecreatefromwebp($origen),
            default => false,
        };

        if ($img === false) {
            throw new ArchivoInvalido('No se pudo procesar la imagen.');
        }

        // Reducir si excede el tamaño útil para un documento.
        $ancho = imagesx($img);
        $alto  = imagesy($img);
        $max   = 2200;

        if ($ancho > $max || $alto > $max) {
            $escala = min($max / $ancho, $max / $alto);
            $nuevo  = imagescale($img, (int) round($ancho * $escala), (int) round($alto * $escala));
            if ($nuevo !== false) {
                imagedestroy($img);
                $img = $nuevo;
            }
        }

        $ok = match ($extension) {
            'jpg'   => imagejpeg($img, $destino, 88),
            'png'   => imagepng($img, $destino, 7),
            'webp'  => imagewebp($img, $destino, 88),
            default => false,
        };

        imagedestroy($img);

        if ($ok === false) {
            throw new ArchivoInvalido('No se pudo guardar la imagen procesada.');
        }
    }

    private function directorio(string $categoria): string
    {
        $limpia = preg_replace('/[^a-z0-9_-]/', '', mb_strtolower($categoria)) ?: 'documentos';
        $dir    = CM_STORAGE . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $limpia;

        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        return $dir;
    }
}

final class ArchivoInvalido extends \RuntimeException
{
}

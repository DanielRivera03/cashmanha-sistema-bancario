<?php
/**
 * Iconos SVG en línea.
 *
 * El sistema base cargaba LineIcons desde cdn.lineicons.com. Eso tenía
 * dos problemas: la Content-Security-Policy lo bloqueaba (los iconos
 * salían en blanco) y el panel dependía de que un servidor ajeno
 * estuviera disponible.
 *
 * Con SVG en línea no hay petición externa, no hay parpadeo mientras
 * carga la fuente, y el icono hereda el color del texto.
 */

if (!function_exists('cm_icono')) {
    /**
     * @param string $nombre Clave del icono
     * @param int    $tam    Tamaño en píxeles
     */
    function cm_icono(string $nombre, int $tam = 20, string $clase = ''): string
    {
        static $iconos = [
            'home'      => '<path d="M3 9.5L12 3l9 6.5V20a1 1 0 01-1 1h-5v-7H9v7H4a1 1 0 01-1-1V9.5z"/>',
            'wallet'    => '<rect x="2" y="6" width="20" height="13" rx="2.5"/><path d="M2 10h20M17 14.5h.01"/>',
            'card'      => '<rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20M6 15h3"/>',
            'invest'    => '<path d="M3 17l5.5-5.5 3.5 3.5L21 6"/><path d="M15 6h6v6"/>',
            'files'     => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/>',
            'users'     => '<path d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 00-3-3.87"/>',
            'user'      => '<path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'warning'   => '<path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
            'chart'     => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6"/><rect x="12" y="8" width="3" height="10"/><rect x="17" y="5" width="3" height="13"/>',
            'mail'      => '<rect x="2" y="4" width="20" height="16" rx="2.5"/><path d="M2.5 7l9.5 6.5L21.5 7"/>',
            'support'   => '<circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 015.8 1c0 2-3 2.5-3 4"/><path d="M12 17h.01"/>',
            'package'   => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/>',
            'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'lock'      => '<rect x="3" y="11" width="18" height="11" rx="2.5"/><path d="M7 11V7a5 5 0 0110 0v4"/>',
            'key'       => '<circle cx="7.5" cy="15.5" r="4"/><path d="M10.3 12.7L21 2M17.5 5.5l2.5 2.5M14.5 8.5l2 2"/>',
            'exit'      => '<path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/>',
            'bell'      => '<path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/>',
            'device'    => '<rect x="4" y="3" width="16" height="13" rx="2"/><path d="M2 20h20M9.5 20v-4h5v4"/>',
            'swap'      => '<path d="M3 7h13l-3-3M21 17H8l3 3"/>',
            'up'        => '<path d="M12 19V5M5 12l7-7 7 7"/>',
            'down'      => '<path d="M12 5v14M19 12l-7 7-7-7"/>',
            'check'     => '<path d="M20 6L9 17l-5-5"/>',
            'plus'      => '<path d="M12 5v14M5 12h14"/>',
            'search'    => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
            'download'  => '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
            'print'     => '<path d="M6 9V2h12v7"/><rect x="6" y="14" width="12" height="8"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/>',
            'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 16v-5M12 8h.01"/>',
            'block'     => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
            'reload'    => '<path d="M21 12a9 9 0 11-3-6.7L21 8"/><path d="M21 3v5h-5"/>',
            // Tres puntos verticales: el gesto universal de «aqui hay
            // mas opciones», sin texto que traducir.
            'more'      => '<circle cx="12" cy="5" r="1.5" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/><circle cx="12" cy="19" r="1.5" fill="currentColor" stroke="none"/>',
        ];

        $d = $iconos[$nombre] ?? $iconos['info'];
        $c = $clase !== '' ? ' class="' . htmlspecialchars($clase, ENT_QUOTES, 'UTF-8') . '"' : '';

        return '<svg' . $c . ' width="' . $tam . '" height="' . $tam . '" viewBox="0 0 24 24"'
             . ' fill="none" stroke="currentColor" stroke-width="2"'
             . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             . $d . '</svg>';
    }
}

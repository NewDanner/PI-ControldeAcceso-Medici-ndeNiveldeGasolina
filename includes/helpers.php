<?php
// ============================================================
// Helpers de presentación reutilizados por todas las páginas:
// antes cada archivo repetía su propio htmlspecialchars(),
// formato de fecha y HTML de los badges de estado.
// ============================================================

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function fmtFechaHora(?string $fecha): string {
    return $fecha ? date('d/m/Y H:i', strtotime($fecha)) : '—';
}

function fmtFecha(?string $fecha): string {
    return $fecha ? date('d/m/Y', strtotime($fecha)) : '—';
}

function fmtLitros(float $litros): string {
    return number_format($litros, 2) . ' L';
}

function fmtDinero(?float $monto): string {
    return 'Bs ' . number_format($monto ?? 0, 2);
}

/** HTML del badge de estado (completado/rechazado, activo/inactivo, etc.) */
function badgeEstado(bool $positivo, string $textoPositivo, string $textoNegativo): string {
    $clase = $positivo ? 'estado-completado' : 'estado-rechazado';
    $texto = $positivo ? $textoPositivo : $textoNegativo;
    return '<span class="estado ' . $clase . '">' . h($texto) . '</span>';
}

/** Ícono SVG en línea — evita depender de una librería de íconos externa */
function icono(string $nombre, string $clase = 'icono'): string {
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'rfid'      => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="8" cy="12" r="2.2"/><path d="M13 9.5c1.5 1 1.5 4 0 5M16 8c2.2 1.6 2.2 6.4 0 8"/>',
        'usuarios'  => '<circle cx="9" cy="8" r="3.2"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><circle cx="17.5" cy="8.5" r="2.4"/><path d="M15.5 14.2c2.7.4 4.5 2.4 4.5 5.8"/>',
        'reportes'  => '<path d="M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z"/><path d="M14 3v5h5"/><path d="M8 13h8M8 16.5h8M8 9.5h3"/>',
        'tanque'    => '<path d="M12 2c3 3 6 6.5 6 10.5A6 6 0 0 1 6 12.5C6 8.5 9 5 12 2Z"/>',
        'grafico'   => '<path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/>',
        'lista'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'salir'     => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        'gota'      => '<path d="M12 2.5c3.5 4 7 8.2 7 12.3a7 7 0 1 1-14 0c0-4.1 3.5-8.3 7-12.3Z"/>',
        'candado'   => '<rect x="4" y="10.5" width="16" height="10" rx="2"/><path d="M7.5 10.5V7a4.5 4.5 0 0 1 9 0v3.5"/>',
        'usuario'   => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20c0-4.1 3.4-7 7.5-7s7.5 2.9 7.5 7"/>',
        'reloj'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'mas'       => '<path d="M12 5v14M5 12h14"/>',
        'descarga'  => '<path d="M12 3v12m0 0-4-4m4 4 4-4"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'alerta'    => '<path d="M12 3 2 20h20L12 3Z"/><path d="M12 9v5M12 17h.01"/>',
        'dinero'    => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 6v.01M18 18v.01"/>',
        'cilindro'  => '<ellipse cx="12" cy="5" rx="7" ry="3"/><path d="M5 5v14a7 3 0 0 0 14 0V5"/>',
        'estrella'  => '<path d="M12 3.5l2.4 5 5.5.6-4 3.8 1 5.5L12 15.8 6.9 18.4l1-5.5-4-3.8 5.5-.6Z"/>',
    ];
    $path = $paths[$nombre] ?? '';
    return '<svg class="' . $clase . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
}

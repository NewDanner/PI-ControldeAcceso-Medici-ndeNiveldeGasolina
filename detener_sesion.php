<?php
// ============================================================
// POST /detener_sesion.php
// Lo dispara el botón "Detener despacho" del dashboard. Calcula
// los litros según el tiempo transcurrido hasta este momento,
// registra el despacho y cierra la sesión. El puente USB detecta
// este cierre en su próximo sondeo y apaga la bomba física.
// ============================================================

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/sesion_logic.php';

header('Content-Type: application/json');

$pdo = getConnection();
$sesion = obtenerSesionActiva($pdo);

if (!$sesion) {
    echo json_encode(['ok' => false, 'motivo' => 'No hay ningún despacho en curso']);
    exit;
}

$litros = calcularLitrosActuales($sesion);
$resultado = finalizarSesion($pdo, $sesion, $litros);

echo json_encode(['ok' => true, 'litros' => $resultado['litros'], 'monto' => $resultado['monto']]);

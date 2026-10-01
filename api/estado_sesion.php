<?php
// ============================================================
// GET /api/estado_sesion.php
// Acepta X-Api-Key (puente USB) o sesión web iniciada (dashboard).
//
// Devuelve el estado de la sesión de despacho activa, calculando
// los litros acumulados según el tiempo transcurrido. Si en modo
// crisis se alcanza el límite, la sesión se cierra automáticamente
// aquí mismo (igual que pasaría con un corte automático real).
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sesion_logic.php';

header('Content-Type: application/json');

if (!autenticarApiOSesion()) {
    http_response_code(401);
    echo json_encode(['activa' => false, 'error' => 'No autorizado']);
    exit;
}

$pdo = getConnection();
$sesion = obtenerSesionActiva($pdo);

if (!$sesion) {
    echo json_encode(['activa' => false]);
    exit;
}

$litrosActuales = calcularLitrosActuales($sesion);
$alcanzoLimite = $sesion['limite_litros'] !== null && $litrosActuales >= (float) $sesion['limite_litros'];

if ($alcanzoLimite) {
    $resultado = finalizarSesion($pdo, $sesion, $litrosActuales);
    echo json_encode([
        'activa'          => false,
        'recien_finalizada'=> true,
        'motivo'          => 'Límite de modo crisis alcanzado',
        'titular'         => null,
        'litros'          => $resultado['litros'],
        'monto'           => $resultado['monto'],
    ]);
    exit;
}

$stmt = $pdo->prepare('SELECT titular FROM tarjetas_rfid WHERE id = :id');
$stmt->execute(['id' => $sesion['tarjeta_id']]);
$titular = $stmt->fetch()['titular'] ?? '';

echo json_encode([
    'activa'        => true,
    'sesion_id'     => (int) $sesion['id'],
    'titular'       => $titular,
    'modo'          => $sesion['modo'],
    'litros_actual' => round($litrosActuales, 2),
    'monto_actual'  => round($litrosActuales * (float) $sesion['precio_litro'], 2),
    'limite_litros' => $sesion['limite_litros'] !== null ? (float) $sesion['limite_litros'] : null,
]);

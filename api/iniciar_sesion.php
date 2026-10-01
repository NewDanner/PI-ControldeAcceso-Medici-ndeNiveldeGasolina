<?php
// ============================================================
// POST /api/iniciar_sesion.php
// Header:  X-API-Key: <API_KEY>
// Body (JSON): { "uid_rfid": "A1B2C3D4" }
//
// Lo llama el puente USB cuando el ESP32 lee una tarjeta física.
// Usa el tanque predeterminado (el conectado al sensor/bomba real).
// No hay litros fijos: la sesión queda "en_curso" hasta que alguien
// la detenga desde el dashboard (o se llegue al límite en crisis).
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/api.php';
require_once __DIR__ . '/../includes/sesion_logic.php';

header('Content-Type: application/json');

$headers = getallheaders();
if (($headers['X-Api-Key'] ?? $headers['X-API-Key'] ?? '') !== API_KEY) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'motivo' => 'API key inválida']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$uid = trim($body['uid_rfid'] ?? '');

if ($uid === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'motivo' => 'UID no enviado']);
    exit;
}

$pdo = getConnection();
echo json_encode(validarYCrearSesion($pdo, $uid, null, 'hardware'));

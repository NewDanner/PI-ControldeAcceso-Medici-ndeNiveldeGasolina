<?php
// ============================================================
// POST /api/lectura_tanque.php
// Header:  X-API-Key: <API_KEY>
// Body (JSON): { "nivel_pct": 62.4, "volumen_litros": 6240 }
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/api.php';

header('Content-Type: application/json');

$headers = getallheaders();
if (($headers['X-Api-Key'] ?? $headers['X-API-Key'] ?? '') !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'API key inválida']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);

if (!isset($body['nivel_pct'], $body['volumen_litros'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan nivel_pct o volumen_litros']);
    exit;
}

$nivel   = max(0, min(100, (float) $body['nivel_pct']));
$volumen = max(0, (float) $body['volumen_litros']);

$pdo = getConnection();
$stmt = $pdo->prepare(
    'INSERT INTO lecturas_tanque (nivel_pct, volumen_litros) VALUES (:nivel, :volumen)'
);
$stmt->execute(['nivel' => $nivel, 'volumen' => $volumen]);

echo json_encode(['ok' => true]);

<?php
// ============================================================
// POST /api/lectura_tanque.php
// Header:  X-API-Key: <API_KEY>
// Body (JSON): { "distancia_cm": 84.3 }
//
// El ESP32 solo envía la distancia cruda medida por el HC-SR04.
// El servidor calcula nivel % y volumen en litros usando la
// altura, offset de montaje y capacidad del tanque predeterminado
// (registrados en la página "Tanques"), así el ESP32 no necesita
// saber nada de la geometría del tanque.
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

if (!isset($body['distancia_cm'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Falta distancia_cm']);
    exit;
}

$distanciaCm = (float) $body['distancia_cm'];
if ($distanciaCm <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'distancia_cm inválida (el sensor no obtuvo lectura)']);
    exit;
}

$pdo = getConnection();

$tanque = $pdo->query('SELECT * FROM tanques WHERE predeterminado = 1 LIMIT 1')->fetch();
if (!$tanque) {
    http_response_code(400);
    echo json_encode(['error' => 'No hay ningún tanque marcado como predeterminado']);
    exit;
}

// Nivel de líquido = altura del tanque menos lo que el sensor "ve" de aire,
// descontando el offset de montaje (distancia del sensor a la línea de lleno).
$alturaTanque = (float) $tanque['altura_cm'];
$offsetSensor = (float) $tanque['offset_sensor_cm'];

$nivelLiquidoCm = $alturaTanque - ($distanciaCm - $offsetSensor);
$nivelLiquidoCm = max(0.0, min($alturaTanque, $nivelLiquidoCm));

$nivelPct = $alturaTanque > 0 ? ($nivelLiquidoCm / $alturaTanque) * 100 : 0;
$volumenLitros = ($nivelPct / 100) * (float) $tanque['capacidad_litros'];

$stmt = $pdo->prepare(
    'INSERT INTO lecturas_tanque (tanque_id, nivel_pct, volumen_litros) VALUES (:tanque_id, :nivel, :volumen)'
);
$stmt->execute([
    'tanque_id' => $tanque['id'],
    'nivel'     => round($nivelPct, 2),
    'volumen'   => round($volumenLitros, 2),
]);

echo json_encode([
    'ok'             => true,
    'nivel_pct'      => round($nivelPct, 1),
    'volumen_litros' => round($volumenLitros, 1),
]);

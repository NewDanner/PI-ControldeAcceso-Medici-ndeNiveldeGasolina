<?php
// ============================================================
// POST /api/despacho.php
// Header:  X-API-Key: <API_KEY>
// Body (JSON): { "uid_rfid": "A1B2C3D4" }
//
// Respuesta:
//   { "autorizado": true,  "litros": 5.00, "modo": "crisis" }
//   { "autorizado": false, "motivo": "Nivel de tanque crítico" }
// ============================================================

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/api.php';

header('Content-Type: application/json');

$headers = getallheaders();
if (($headers['X-Api-Key'] ?? $headers['X-API-Key'] ?? '') !== API_KEY) {
    http_response_code(401);
    echo json_encode(['autorizado' => false, 'motivo' => 'API key inválida']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$uid = strtoupper(trim($body['uid_rfid'] ?? ''));

if ($uid === '') {
    http_response_code(400);
    echo json_encode(['autorizado' => false, 'motivo' => 'UID no enviado']);
    exit;
}

$pdo = getConnection();

function rechazar(PDO $pdo, ?int $tarjetaId, string $modo, string $motivo): void {
    if ($tarjetaId !== null) {
        $pdo->prepare(
            'INSERT INTO despachos (tarjeta_id, litros, modo, estado, motivo)
             VALUES (:tarjeta_id, 0, :modo, "rechazado", :motivo)'
        )->execute(['tarjeta_id' => $tarjetaId, 'modo' => $modo, 'motivo' => $motivo]);
    }
    echo json_encode(['autorizado' => false, 'motivo' => $motivo]);
    exit;
}

// 1. Buscar tarjeta
$stmt = $pdo->prepare('SELECT id, activo FROM tarjetas_rfid WHERE uid_rfid = :uid');
$stmt->execute(['uid' => $uid]);
$tarjeta = $stmt->fetch();

$config = $pdo->query('SELECT * FROM configuracion WHERE id = 1')->fetch();
$modo = $config['modo_actual'];

if (!$tarjeta) {
    rechazar($pdo, null, $modo, 'Tarjeta no registrada');
}
if (!$tarjeta['activo']) {
    rechazar($pdo, $tarjeta['id'], $modo, 'Tarjeta inactiva');
}

// 2. Verificar nivel del tanque
$ultimaLectura = $pdo->query(
    'SELECT nivel_pct FROM lecturas_tanque ORDER BY fecha_hora DESC LIMIT 1'
)->fetch();
$nivelActual = $ultimaLectura['nivel_pct'] ?? 0;

if ($nivelActual <= $config['nivel_critico_pct']) {
    rechazar($pdo, $tarjeta['id'], $modo, 'Nivel de tanque crítico');
}

// 3. Determinar litros según el modo
$litros = ($modo === 'crisis')
    ? (float) $config['limite_crisis_litros']
    : (float) $config['litros_normal_max'];

// 4. Registrar despacho autorizado
$pdo->prepare(
    'INSERT INTO despachos (tarjeta_id, litros, modo, estado, motivo)
     VALUES (:tarjeta_id, :litros, :modo, "completado", NULL)'
)->execute([
    'tarjeta_id' => $tarjeta['id'],
    'litros'     => $litros,
    'modo'       => $modo,
]);

echo json_encode(['autorizado' => true, 'litros' => $litros, 'modo' => $modo]);

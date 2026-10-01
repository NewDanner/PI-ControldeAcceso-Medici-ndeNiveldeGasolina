<?php
// ============================================================
// POST /ajax_iniciar_despacho.php
// body: tarjeta_id, tanque_id
//
// Modo de prueba manual: crea una sesión de despacho igual que lo
// haría el ESP32, pero elegido a mano desde el dashboard (para
// cuando no tienes el circuito físico conectado).
// ============================================================

require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/sesion_logic.php';

header('Content-Type: application/json');

$pdo = getConnection();
$tarjetaId = (int) ($_POST['tarjeta_id'] ?? 0);
$tanqueId  = (int) ($_POST['tanque_id'] ?? 0);

$stmt = $pdo->prepare('SELECT uid_rfid FROM tarjetas_rfid WHERE id = :id');
$stmt->execute(['id' => $tarjetaId]);
$tarjeta = $stmt->fetch();

if (!$tarjeta) {
    echo json_encode(['ok' => false, 'motivo' => 'Selecciona una tarjeta válida']);
    exit;
}

echo json_encode(validarYCrearSesion($pdo, $tarjeta['uid_rfid'], $tanqueId, 'manual'));

<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['administrador']);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = getConnection();
$user = currentUser();
$error = '';
$exito = '';

// ------------------------------------------------------------
// Registrar nueva tarjeta
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear') {
    $uid     = strtoupper(trim($_POST['uid_rfid'] ?? ''));
    $titular = trim($_POST['titular'] ?? '');

    if ($uid === '' || $titular === '') {
        $error = 'UID y titular son obligatorios.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO tarjetas_rfid (uid_rfid, titular, registrado_por)
                 VALUES (:uid, :titular, :registrado_por)'
            );
            $stmt->execute([
                'uid'            => $uid,
                'titular'        => $titular,
                'registrado_por' => $user['id'],
            ]);
            $exito = 'Tarjeta registrada correctamente.';
        } catch (PDOException $e) {
            $error = ($e->getCode() === '23000')
                ? 'Ese UID ya está registrado.'
                : 'Error al registrar la tarjeta.';
        }
    }
}

// ------------------------------------------------------------
// Activar / desactivar tarjeta
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'toggle') {
    $id = (int) ($_POST['id'] ?? 0);
    $pdo->prepare('UPDATE tarjetas_rfid SET activo = NOT activo WHERE id = :id')
        ->execute(['id' => $id]);
    header('Location: rfid.php');
    exit;
}

$tarjetas = $pdo->query(
    'SELECT id, uid_rfid, titular, activo, fecha_registro
     FROM tarjetas_rfid
     ORDER BY fecha_registro DESC'
)->fetchAll();
$total_activas = count(array_filter($tarjetas, fn($t) => (bool) $t['activo']));

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><?= icono('rfid') ?> Tarjetas RFID</h1>
        <p class="page-subtitulo"><?= count($tarjetas) ?> registradas · <?= $total_activas ?> activas</p>
    </div>
</div>

<div class="panel panel-form">
    <h3><?= icono('mas') ?> Registrar nueva tarjeta</h3>

    <?php if ($error): ?><div class="alerta-error"><?= icono('alerta') ?> <?= h($error) ?></div><?php endif; ?>
    <?php if ($exito): ?><div class="alerta-exito">✓ <?= h($exito) ?></div><?php endif; ?>

    <form method="POST" class="form-inline">
        <input type="hidden" name="accion" value="crear">
        <div>
            <label for="uid_rfid">UID RFID</label>
            <input type="text" id="uid_rfid" name="uid_rfid" placeholder="Ej: A1B2C3D4" required>
        </div>
        <div>
            <label for="titular">Titular</label>
            <input type="text" id="titular" name="titular" placeholder="Nombre completo" required>
        </div>
        <button type="submit" class="btn-primary"><?= icono('mas') ?> Registrar</button>
    </form>
</div>

<div class="panel panel-tabla">
    <h3><?= icono('lista') ?> Tarjetas registradas</h3>
    <table class="tabla-despachos">
        <thead>
            <tr>
                <th>UID RFID</th>
                <th>Titular</th>
                <th>Estado</th>
                <th>Registrada</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tarjetas)): ?>
                <tr><td colspan="5" class="sin-datos">Sin tarjetas registradas</td></tr>
            <?php endif; ?>
            <?php foreach ($tarjetas as $t): ?>
                <tr>
                    <td><code><?= h($t['uid_rfid']) ?></code></td>
                    <td><?= h($t['titular']) ?></td>
                    <td><?= badgeEstado((bool) $t['activo'], 'Activa', 'Inactiva') ?></td>
                    <td><?= fmtFecha($t['fecha_registro']) ?></td>
                    <td>
                        <form method="POST">
                            <input type="hidden" name="accion" value="toggle">
                            <input type="hidden" name="id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn-link">
                                <?= $t['activo'] ? 'Desactivar' : 'Activar' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

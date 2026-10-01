<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['administrador']);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = getConnection();
$actual = currentUser();
$error = '';
$exito = '';

// ------------------------------------------------------------
// Crear usuario
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear') {
    $nombre   = trim($_POST['nombre_completo'] ?? '');
    $usuario  = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';
    $rol      = ($_POST['rol'] ?? '') === 'administrador' ? 'administrador' : 'operador';

    if ($nombre === '' || $usuario === '' || strlen($password) < 6) {
        $error = 'Complete nombre, usuario y una contraseña de al menos 6 caracteres.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO usuarios (nombre_completo, usuario, password_hash, rol)
                 VALUES (:nombre, :usuario, :hash, :rol)'
            );
            $stmt->execute([
                'nombre'  => $nombre,
                'usuario' => $usuario,
                'hash'    => password_hash($password, PASSWORD_DEFAULT),
                'rol'     => $rol,
            ]);
            $exito = 'Usuario creado correctamente.';
        } catch (PDOException $e) {
            $error = ($e->getCode() === '23000')
                ? 'Ese nombre de usuario ya existe.'
                : 'Error al crear el usuario.';
        }
    }
}

// ------------------------------------------------------------
// Activar / desactivar usuario (no permite auto-desactivarse)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'toggle') {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id !== $actual['id']) {
        $pdo->prepare('UPDATE usuarios SET activo = NOT activo WHERE id = :id')
            ->execute(['id' => $id]);
    }
    header('Location: usuarios.php');
    exit;
}

$usuarios = $pdo->query(
    'SELECT id, nombre_completo, usuario, rol, activo, fecha_creacion, ultimo_login
     FROM usuarios
     ORDER BY fecha_creacion ASC'
)->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1><?= icono('usuarios') ?> Usuarios</h1>
        <p class="page-subtitulo"><?= count($usuarios) ?> cuentas en el sistema</p>
    </div>
</div>

<div class="panel panel-form">
    <h3><?= icono('mas') ?> Crear nuevo usuario</h3>

    <?php if ($error): ?><div class="alerta-error"><?= icono('alerta') ?> <?= h($error) ?></div><?php endif; ?>
    <?php if ($exito): ?><div class="alerta-exito">✓ <?= h($exito) ?></div><?php endif; ?>

    <form method="POST" class="form-inline">
        <input type="hidden" name="accion" value="crear">
        <div>
            <label for="nombre_completo">Nombre completo</label>
            <input type="text" id="nombre_completo" name="nombre_completo" required>
        </div>
        <div>
            <label for="usuario">Usuario</label>
            <input type="text" id="usuario" name="usuario" required>
        </div>
        <div>
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password" required minlength="6">
        </div>
        <div>
            <label for="rol">Rol</label>
            <select id="rol" name="rol">
                <option value="operador">Operador</option>
                <option value="administrador">Administrador</option>
            </select>
        </div>
        <button type="submit" class="btn-primary"><?= icono('mas') ?> Crear</button>
    </form>
</div>

<div class="panel panel-tabla">
    <h3><?= icono('lista') ?> Usuarios del sistema</h3>
    <table class="tabla-despachos">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Usuario</th>
                <th>Rol</th>
                <th>Estado</th>
                <th>Último login</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($usuarios as $u): ?>
                <tr>
                    <td><?= h($u['nombre_completo']) ?></td>
                    <td><?= h($u['usuario']) ?></td>
                    <td><?= $u['rol'] === 'administrador' ? 'Administrador' : 'Operador' ?></td>
                    <td><?= badgeEstado((bool) $u['activo'], 'Activo', 'Inactivo') ?></td>
                    <td><?= $u['ultimo_login'] ? fmtFechaHora($u['ultimo_login']) : '—' ?></td>
                    <td>
                        <?php if ($u['id'] !== $actual['id']): ?>
                            <form method="POST">
                                <input type="hidden" name="accion" value="toggle">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn-link">
                                    <?= $u['activo'] ? 'Desactivar' : 'Activar' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <small>(tú)</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

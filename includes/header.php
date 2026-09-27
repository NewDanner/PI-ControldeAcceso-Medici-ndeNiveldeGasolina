<?php
require_once __DIR__ . '/auth.php';
requireLogin();
$user = currentUser();
$pagina_actual = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ControlFuel</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <h2 class="logo">ControlFuel</h2>
        <nav>
            <a href="dashboard.php" class="<?= $pagina_actual === 'dashboard.php' ? 'activo' : '' ?>">Dashboard</a>

            <?php if ($user['rol'] === 'administrador'): ?>
                <a href="rfid.php" class="<?= $pagina_actual === 'rfid.php' ? 'activo' : '' ?>">Tarjetas RFID</a>
                <a href="usuarios.php" class="<?= $pagina_actual === 'usuarios.php' ? 'activo' : '' ?>">Usuarios</a>
                <a href="reportes.php" class="<?= $pagina_actual === 'reportes.php' ? 'activo' : '' ?>">Reportes</a>
            <?php endif; ?>
        </nav>
        <div class="user-box">
            <span><?= htmlspecialchars($user['nombre']) ?></span>
            <small><?= htmlspecialchars($user['rol']) ?></small>
            <a href="logout.php" class="logout">Cerrar sesión</a>
        </div>
    </aside>
    <main class="content">

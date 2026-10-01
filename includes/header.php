<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
requireLogin();
$user = currentUser();
$pagina_actual = basename($_SERVER['PHP_SELF']);
$inicial = strtoupper(mb_substr($user['nombre'], 0, 1));
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
        <div class="logo">
            <span class="logo-icono"><?= icono('gota') ?></span>
            <span class="logo-texto">Control<strong>Fuel</strong></span>
        </div>

        <nav>
            <a href="dashboard.php" class="<?= $pagina_actual === 'dashboard.php' ? 'activo' : '' ?>">
                <?= icono('dashboard') ?><span>Dashboard</span>
            </a>

            <?php if ($user['rol'] === 'administrador'): ?>
                <a href="rfid.php" class="<?= $pagina_actual === 'rfid.php' ? 'activo' : '' ?>">
                    <?= icono('rfid') ?><span>Tarjetas RFID</span>
                </a>
                <a href="tanques.php" class="<?= $pagina_actual === 'tanques.php' ? 'activo' : '' ?>">
                    <?= icono('cilindro') ?><span>Tanques</span>
                </a>
                <a href="usuarios.php" class="<?= $pagina_actual === 'usuarios.php' ? 'activo' : '' ?>">
                    <?= icono('usuarios') ?><span>Usuarios</span>
                </a>
                <a href="reportes.php" class="<?= $pagina_actual === 'reportes.php' ? 'activo' : '' ?>">
                    <?= icono('reportes') ?><span>Reportes</span>
                </a>
            <?php endif; ?>
        </nav>

        <div class="user-box">
            <div class="user-avatar"><?= h($inicial) ?></div>
            <div class="user-info">
                <span class="user-nombre"><?= h($user['nombre']) ?></span>
                <small class="user-rol"><?= $user['rol'] === 'administrador' ? 'Administrador' : 'Operador' ?></small>
            </div>
            <a href="logout.php" class="logout" title="Cerrar sesión"><?= icono('salir') ?></a>
        </div>
    </aside>
    <main class="content">

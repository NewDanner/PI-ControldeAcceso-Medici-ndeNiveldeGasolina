<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($usuario === '' || $password === '') {
        $error = 'Ingrese usuario y contraseña.';
    } elseif (login($usuario, $password)) {
        header('Location: dashboard.php');
        exit;
    } else {
        $error = 'Usuario o contraseña incorrectos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ControlFuel - Iniciar sesión</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-body">
    <div class="login-wrap">

        <div class="login-marca">
            <span class="login-marca-icono"><?= icono('gota') ?></span>
            <h1>Control<strong>Fuel</strong></h1>
            <p>Control y racionamiento de combustible en tanques subterráneos</p>
            <ul class="login-marca-lista">
                <li><?= icono('tanque') ?> Monitoreo del tanque en tiempo real</li>
                <li><?= icono('rfid') ?> Acceso validado por tarjeta RFID</li>
                <li><?= icono('reportes') ?> Reportes exportables a CSV y PDF</li>
            </ul>
        </div>

        <form class="login-box" method="POST" action="login.php">
            <span class="login-box-icono"><?= icono('candado') ?></span>
            <h2>Iniciar sesión</h2>
            <p class="subtitle">Ingresa tus credenciales para continuar</p>

            <?php if ($error): ?>
                <div class="alerta-error"><?= icono('alerta') ?> <?= h($error) ?></div>
            <?php endif; ?>

            <label for="usuario">Usuario</label>
            <div class="input-icono">
                <?= icono('usuario') ?>
                <input type="text" id="usuario" name="usuario" required autofocus>
            </div>

            <label for="password">Contraseña</label>
            <div class="input-icono">
                <?= icono('candado') ?>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Ingresar</button>
        </form>
    </div>
</body>
</html>

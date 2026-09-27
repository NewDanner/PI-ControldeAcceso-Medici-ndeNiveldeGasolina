<?php
require_once __DIR__ . '/includes/auth.php';

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
    <form class="login-box" method="POST" action="login.php">
        <h1>ControlFuel</h1>
        <p class="subtitle">Control de suministro de combustible</p>

        <?php if ($error): ?>
            <div class="alerta-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <label for="usuario">Usuario</label>
        <input type="text" id="usuario" name="usuario" required autofocus>

        <label for="password">Contraseña</label>
        <input type="password" id="password" name="password" required>

        <button type="submit">Ingresar</button>
    </form>
</body>
</html>

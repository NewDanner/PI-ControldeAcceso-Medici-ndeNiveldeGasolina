<?php
// ============================================================
// Ejecutar UNA sola vez para crear el primer administrador.
// Luego, eliminar este archivo o restringir su acceso.
// Uso: http://localhost/controlfuel/setup_admin.php
// ============================================================

require_once __DIR__ . '/config/database.php';

$pdo = getConnection();

$existe = $pdo->query('SELECT COUNT(*) AS total FROM usuarios')->fetch();

if ($existe['total'] > 0) {
    die('Ya existe al menos un usuario. Este script solo debe usarse en la instalación inicial.');
}

$nombre   = 'Administrador';
$usuario  = 'admin';
$password = 'admin123'; // Cambiar inmediatamente después del primer login

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    'INSERT INTO usuarios (nombre_completo, usuario, password_hash, rol)
     VALUES (:nombre, :usuario, :hash, :rol)'
);
$stmt->execute([
    'nombre'  => $nombre,
    'usuario' => $usuario,
    'hash'    => $hash,
    'rol'     => 'administrador',
]);

echo "Administrador creado correctamente.<br>";
echo "Usuario: $usuario<br>";
echo "Contraseña: $password<br>";
echo "<strong>Elimine este archivo (setup_admin.php) ahora.</strong>";

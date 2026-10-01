<?php
// ============================================================
// Autenticación y control de acceso por rol
// ============================================================

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Verifica credenciales y abre sesión.
 * Devuelve true si el login fue exitoso.
 */
function login(string $usuario, string $password): bool {
    $pdo = getConnection();
    $stmt = $pdo->prepare(
        'SELECT id, nombre_completo, usuario, password_hash, rol
         FROM usuarios
         WHERE usuario = :usuario AND activo = 1'
    );
    $stmt->execute(['usuario' => $usuario]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    $_SESSION['user_id']  = $user['id'];
    $_SESSION['nombre']   = $user['nombre_completo'];
    $_SESSION['usuario']  = $user['usuario'];
    $_SESSION['rol']      = $user['rol'];

    $update = $pdo->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = :id');
    $update->execute(['id' => $user['id']]);

    return true;
}

function logout(): void {
    $_SESSION = [];
    session_destroy();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) {
        return null;
    }
    return [
        'id'      => $_SESSION['user_id'],
        'nombre'  => $_SESSION['nombre'],
        'usuario' => $_SESSION['usuario'],
        'rol'     => $_SESSION['rol'],
    ];
}

/** Redirige a login.php si no hay sesión activa. */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Redirige a login si no hay sesión, y a dashboard.php (403 lógico)
 * si el rol actual no está en la lista de roles permitidos.
 */
function requireRole(array $rolesPermitidos): void {
    requireLogin();
    if (!in_array($_SESSION['rol'], $rolesPermitidos, true)) {
        header('Location: dashboard.php?error=acceso_denegado');
        exit;
    }
}

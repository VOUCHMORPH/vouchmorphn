<?php
session_start();

function requireEnterpriseAuth() {
    if (!isset($_SESSION['enterprise_user'])) {
        header('Location: login.php');
        exit;
    }
    return $_SESSION['enterprise_user'];
}

function hasPermission($permission) {
    $user = $_SESSION['enterprise_user'] ?? null;
    if (!$user) return false;
    if ($user['role'] === 'owner' || $user['role'] === 'admin') return true;
    return in_array($permission, $user['permissions'] ?? []);
}

function requirePermission($permission) {
    if (!hasPermission($permission)) {
        header('HTTP/1.1 403 Forbidden');
        die('Access denied. You need permission: ' . $permission);
    }
}

function getOrganizationId() {
    return $_SESSION['enterprise_user']['organization_id'] ?? null;
}

function getCurrentUser() {
    return $_SESSION['enterprise_user'] ?? null;
}

function logout() {
    session_destroy();
    header('Location: login.php');
    exit;
}

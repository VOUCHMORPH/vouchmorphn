<?php
session_start();
require_once '../../../src/Core/Database/DBConnection.php';
use Core\Database\DBConnection;

$db = DBConnection::getInstance();

// Organization login handler
if ($_POST['action'] === 'login') {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    $stmt = $db->prepare("
        SELECT ou.*, o.name as org_name, o.id as organization_id 
        FROM organization_users ou
        JOIN organizations o ON ou.organization_id = o.id
        JOIN users u ON ou.user_id = u.id
        WHERE u.email = :email AND ou.is_active = true
    ");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['enterprise_user'] = [
            'id' => $user['id'],
            'organization_id' => $user['organization_id'],
            'organization_name' => $user['org_name'],
            'role' => $user['role'],
            'email' => $email
        ];
        header('Location: index.php');
        exit;
    }
}

// Check auth for all other pages
function requireEnterpriseAuth() {
    if (!isset($_SESSION['enterprise_user'])) {
        header('Location: login.php');
        exit;
    }
    return $_SESSION['enterprise_user'];
}

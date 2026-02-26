<?php
session_start();
require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username && $password) {
        $conn = getDBConnection();
        $stmt = $conn->prepare("
            SELECT u.id, u.name, u.username, u.password, r.name AS role_name
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            WHERE u.username = ?
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role_name']; // ← added
            header('Location: index.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    } else {
        $error = 'Please fill in all fields.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Sales Order</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .login-card { width: 380px; border: none; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.10); }
        .login-card .card-header { background: #0d6efd; color: #fff; border-radius: 12px 12px 0 0; text-align: center; padding: 24px; font-size: 1.3rem; font-weight: 600; }
    </style>
</head>
<body>
<div class="card login-card">
    <div class="card-header">Sales Order System</div>
    <div class="card-body p-4">
        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-semibold">Username</label>
                <input type="text" name="username" class="form-control" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>
    </div>
</div>
</body>
</html>

<?php

require_once __DIR__ . '/../app/helpers/view.php';

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login_attempt($email, $password)) {
        header('Location: dashboard.php');
        exit;
    }

    $error = 'Invalid email or password.';
}

render_header('Login');
?>
<div class="auth-page">
    <div class="auth-panel">
        <h1 class="h3 mb-2">CLINiQ</h1>
        <p class="text-secondary mb-4">School clinic information management system</p>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input class="form-control" id="email" name="email" type="email" value="admin@cliniq.local" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <input class="form-control" id="password" name="password" type="password" value="password" required>
            </div>
            <button class="btn btn-primary w-100" type="submit">Sign in</button>
        </form>
    </div>
</div>
<?php render_footer(); ?>

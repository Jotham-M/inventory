<?php
require_once __DIR__ . '/functions.php';

$error = '';
$username = '';
$dbReady = true;
$activeAdminCount = 0;
try {
    if (getActiveAdmin()) {
        redirect('index.php');
    }
} catch (RuntimeException $exception) {
    error_log('Unable to verify current administrator session: ' . $exception->getMessage());
    $dbReady = false;
    $error = 'The database is not ready. Check the MySQL connection settings.';
}
try {
    if ($dbReady) {
        $activeAdminCount = (int)getDB()->query('SELECT COUNT(*) FROM admins WHERE is_active = 1')->fetchColumn();
        if ((int)getDB()->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) {
            redirect('setup-admin.php');
        }
    }
} catch (PDOException $exception) {
    error_log('Unable to prepare administrator sign-in: ' . $exception->getMessage());
    $dbReady = false;
    $error = 'The database is not ready. Import schema.sql and check the MySQL connection settings.';
} catch (RuntimeException $exception) {
    error_log('Unable to prepare administrator sign-in: ' . $exception->getMessage());
    $dbReady = false;
    $error = 'The database is not ready. Import schema.sql and check the MySQL connection settings.';
}

if ($dbReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    try {
        requireLoginCsrfToken();
        $db = getDB();
        $ipHash = getLoginIpHash();
        $cleanup = $db->prepare('DELETE FROM admin_login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
        $cleanup->execute();
        $throttle = $db->prepare('SELECT COUNT(*) FROM admin_login_attempts WHERE ip_hash = :ip_hash AND created_at >= NOW() - INTERVAL 15 MINUTE');
        $throttle->execute([':ip_hash' => $ipHash]);
        $isThrottled = (int)$throttle->fetchColumn() >= 5;

        if ($isThrottled) {
            $error = 'Too many sign-in attempts. Please wait 15 minutes and try again.';
        } else {
            $stmt = $db->prepare('SELECT id, username, display_name, password_hash FROM admins WHERE username = :username AND is_active = 1 LIMIT 1');
            $stmt->execute([':username' => $username]);
            $admin = $stmt->fetch();
            $hash = $admin ? $admin['password_hash'] : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $validPassword = password_verify($password, $hash);

            if ($admin && $validPassword) {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (string)$admin['id'];
                $_SESSION['admin_name'] = $admin['display_name'];
                unset($_SESSION['login_csrf_token']);
                $update = $db->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = :id');
                $update->execute([':id' => $admin['id']]);
                $clearAttempts = $db->prepare('DELETE FROM admin_login_attempts WHERE ip_hash = :ip_hash');
                $clearAttempts->execute([':ip_hash' => $ipHash]);
                redirect('index.php');
            }

            recordLoginAttempt($ipHash);
            $error = 'Username or password is incorrect.';
        }
    } catch (PDOException $exception) {
        error_log('Administrator sign-in failed: ' . $exception->getMessage());
        $error = 'Sign-in is temporarily unavailable. Please try again.';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$content = '';
ob_start();
?>
<div class="auth-kicker"><span></span>ADMINISTRATOR ACCESS</div>
<h1>Welcome<br><span>back.</span></h1>
<p class="auth-intro">Sign in with your NLT administrator account to continue.</p>
<?php if ($error): ?><div class="form-error auth-error" role="alert"><strong>Sign-in couldn’t continue</strong><span><?= e($error) ?></span></div><?php endif; ?>
<?php if (!$dbReady): ?><p class="auth-setup-link">Set up the MySQL database first, then return to sign in.</p>
<?php elseif ($activeAdminCount === 0): ?><p class="auth-setup-link">No active administrator accounts are available. Ask another administrator to reactivate an account.</p>
<?php else: ?>
<form class="auth-form" method="post" action="login.php">
    <input type="hidden" name="csrf_token" value="<?= e(getLoginCsrfToken()) ?>">
    <label class="field"><span>Username <b>*</b></span><input name="username" maxlength="32" autocomplete="username" autocapitalize="none" value="<?= e($username) ?>" required autofocus></label>
    <label class="field"><span>Password <b>*</b></span><input type="password" name="password" maxlength="4096" autocomplete="current-password" required></label>
    <button class="button button-primary auth-submit" type="submit">Sign in securely <span>→</span></button>
</form>
<?php endif; ?>
<?php
$content = ob_get_clean();
renderAuthShell('Administrator sign in', $content);

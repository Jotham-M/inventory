<?php
require_once __DIR__ . '/functions.php';

$error = '';
$displayName = '';
$username = '';

try {
    $adminCount = (int)getDB()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($adminCount > 0) {
        redirect(getActiveAdmin() ? 'index.php' : 'login.php');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
        $displayName = isset($_POST['display_name']) && is_string($_POST['display_name']) ? trim($_POST['display_name']) : '';
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $confirmPassword = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';

        try {
            requireLoginCsrfToken();
            if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
                throw new InvalidArgumentException('Choose a username with 3–32 letters, numbers, dots, underscores or hyphens.');
            }
            if ($displayName === '' || strlen($displayName) > 100) {
                throw new InvalidArgumentException('Enter your name (up to 100 characters).');
            }
            if (strlen($password) < 12 || strlen($password) > 72) {
                throw new InvalidArgumentException('Choose a password with 12–72 characters.');
            }
            if ($password !== $confirmPassword) {
                throw new InvalidArgumentException('The password confirmation does not match.');
            }

            $db = getDB();
            $lock = (int)$db->query("SELECT GET_LOCK('norton_inventory_first_admin', 10)")->fetchColumn();
            if ($lock !== 1) {
                throw new RuntimeException('Administrator setup is busy. Please try again.');
            }

            try {
                if ((int)$db->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
                    redirect('login.php');
                }
                $stmt = $db->prepare('INSERT INTO admins (username, display_name, password_hash) VALUES (:username, :display_name, :password_hash)');
                $stmt->execute([
                    ':username' => $username,
                    ':display_name' => $displayName,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT)
                ]);
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (string)$db->lastInsertId();
                $_SESSION['admin_name'] = $displayName;
                unset($_SESSION['login_csrf_token']);
            } finally {
                $db->query("SELECT RELEASE_LOCK('norton_inventory_first_admin')");
            }
            redirect('index.php');
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log('Initial administrator setup failed: ' . $exception->getMessage());
            $error = $exception->getCode() === '23000'
                ? 'That username is already in use. Choose another.'
                : 'We could not finish administrator setup. Check your database and try again.';
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    }
} catch (PDOException $exception) {
    error_log('Could not inspect administrator setup: ' . $exception->getMessage());
    $error = 'The database is not ready. Import schema.sql and check the MySQL connection settings.';
} catch (RuntimeException $exception) {
    error_log('Could not inspect administrator setup: ' . $exception->getMessage());
    $error = 'The database is not ready. Import schema.sql and check the MySQL connection settings.';
}

$content = '';
ob_start();
?>
<div class="auth-kicker"><span></span>SECURE FIRST-TIME SETUP</div>
<h1>Create your<br><span>administrator account.</span></h1>
<p class="auth-intro">This setup is available only until the first administrator is created. Public registration is disabled.</p>
<?php if ($error): ?><div class="form-error auth-error" role="alert"><strong>Setup couldn’t continue</strong><span><?= e($error) ?></span></div><?php endif; ?>
<form class="auth-form" method="post" action="setup-admin.php">
    <input type="hidden" name="csrf_token" value="<?= e(getLoginCsrfToken()) ?>">
    <label class="field"><span>Your name <b>*</b></span><input name="display_name" maxlength="100" autocomplete="name" value="<?= e($displayName) ?>" required></label>
    <label class="field"><span>Administrator username <b>*</b></span><input name="username" maxlength="32" autocomplete="username" value="<?= e($username) ?>" required></label>
    <label class="field"><span>Password <b>*</b></span><input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required><small>Use 12–72 characters.</small></label>
    <label class="field"><span>Confirm password <b>*</b></span><input type="password" name="confirm_password" minlength="12" maxlength="72" autocomplete="new-password" required></label>
    <button class="button button-primary auth-submit" type="submit">Create administrator <span>→</span></button>
</form>
<?php
$content = ob_get_clean();
renderAuthShell('First administrator setup', $content);

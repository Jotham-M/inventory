<?php
require_once __DIR__ . '/functions.php';
requireAdmin();

$error = '';
$currentAdmin = getActiveAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrfToken();
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        $db = getDB();

        if ($action === 'create') {
            $username = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
            $displayName = isset($_POST['display_name']) && is_string($_POST['display_name']) ? trim($_POST['display_name']) : '';
            $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
            if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
                throw new InvalidArgumentException('Choose a username with 3–32 letters, numbers, dots, underscores or hyphens.');
            }
            if ($displayName === '' || strlen($displayName) > 100) {
                throw new InvalidArgumentException('Enter a display name up to 100 characters long.');
            }
            if (strlen($password) < 12 || strlen($password) > 72) {
                throw new InvalidArgumentException('Choose a password with 12–72 characters.');
            }
            $stmt = $db->prepare('INSERT INTO admins (username, display_name, password_hash) VALUES (:username, :display_name, :password_hash)');
            $stmt->execute([
                ':username' => $username,
                ':display_name' => $displayName,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT)
            ]);
            setFlash('success', 'Administrator account created.');
            redirect('admins.php');
        }

        if ($action === 'set_status') {
            $targetId = isset($_POST['admin_id']) && is_string($_POST['admin_id']) && ctype_digit($_POST['admin_id'])
                ? (int)$_POST['admin_id']
                : 0;
            $active = isset($_POST['active']) && $_POST['active'] === '1';
            if ($targetId < 1) {
                throw new InvalidArgumentException('Choose a valid administrator account.');
            }
            if (!$active && $targetId === (int)$currentAdmin['id']) {
                throw new InvalidArgumentException('You can’t disable your own account while signed in.');
            }

            $db->beginTransaction();
            $db->query('SELECT id FROM admins WHERE is_active = 1 FOR UPDATE')->fetchAll();
            $target = $db->prepare('SELECT id, is_active FROM admins WHERE id = :id');
            $target->execute([':id' => $targetId]);
            $targetAdmin = $target->fetch();
            if (!$targetAdmin) {
                throw new InvalidArgumentException('That administrator account could not be found.');
            }
            if (!$active && (int)$targetAdmin['is_active'] === 1) {
                $activeCount = (int)$db->query('SELECT COUNT(*) FROM admins WHERE is_active = 1')->fetchColumn();
                if ($activeCount <= 1) {
                    throw new InvalidArgumentException('You can’t disable the last active administrator.');
                }
            }
            $update = $db->prepare('UPDATE admins SET is_active = :active WHERE id = :id');
            $update->execute([':active' => $active ? 1 : 0, ':id' => $targetId]);
            $db->commit();
            setFlash('success', $active ? 'Administrator account reactivated.' : 'Administrator account disabled.');
            redirect('admins.php');
        }

        throw new InvalidArgumentException('Choose a valid administrator action.');
    } catch (InvalidArgumentException $exception) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Administrator account management failed: ' . $exception->getMessage());
        $error = $exception->getCode() === '23000'
            ? 'That username is already in use.'
            : 'We could not update administrator accounts. Please try again.';
    } catch (RuntimeException $exception) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $error = $exception->getMessage();
    } catch (Exception $exception) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Administrator account management failed: ' . $exception->getMessage());
        $error = 'We could not update administrator accounts. Please try again.';
    }
}

try {
    $admins = getDB()->query('SELECT id, username, display_name, is_active, created_at, last_login_at FROM admins ORDER BY is_active DESC, display_name ASC')->fetchAll();
} catch (PDOException $exception) {
    error_log('Unable to load administrator accounts: ' . $exception->getMessage());
    $admins = [];
    $error = 'Administrator accounts could not be loaded.';
}

renderHeader('Admin accounts', 'admins');
?>
<section class="history-heading reports-heading">
    <div><div class="eyebrow"><span class="eyebrow-line"></span>ACCESS CONTROL</div><h1>Administrator<br><span>accounts.</span></h1><p class="page-intro">Only active administrators can access inventory, movements, and reports.</p></div>
</section>
<?php if ($error): ?><div class="form-error" role="alert"><strong>Account action couldn’t continue</strong><span><?= e($error) ?></span></div><?php endif; ?>
<div class="admin-layout">
    <section class="panel admin-list">
        <div class="panel-heading"><div><div class="eyebrow">AUTHORIZED USERS</div><h2>Admin accounts <span class="count-pill"><?= count($admins) ?></span></h2></div></div>
        <?php if ($admins): ?>
        <div class="table-wrap">
            <table class="admins-table">
                <thead><tr><th>ADMINISTRATOR</th><th>USERNAME</th><th>STATUS</th><th>LAST SIGN-IN</th><th>ACTION</th></tr></thead>
                <tbody>
                <?php foreach ($admins as $admin): ?>
                <tr>
                    <td><div class="item-cell"><span class="admin-avatar"><?= e(strtoupper(substr($admin['display_name'], 0, 1))) ?></span><span><strong><?= e($admin['display_name']) ?><?= (int)$admin['id'] === (int)$currentAdmin['id'] ? ' · You' : '' ?></strong><small>Added <?= e(date('M j, Y', strtotime($admin['created_at']))) ?></small></span></div></td>
                    <td class="muted-cell"><?= e($admin['username']) ?></td>
                    <td><span class="admin-status <?= (int)$admin['is_active'] === 1 ? 'admin-status-active' : 'admin-status-disabled' ?>"><?= (int)$admin['is_active'] === 1 ? 'Active' : 'Disabled' ?></span></td>
                    <td class="muted-cell"><?= $admin['last_login_at'] ? e(date('M j, Y · g:i a', strtotime($admin['last_login_at']))) : 'Never' ?></td>
                    <td><?php if ((int)$admin['id'] !== (int)$currentAdmin['id']): ?><form method="post" action="admins.php" class="admin-status-form"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>"><input type="hidden" name="active" value="<?= (int)$admin['is_active'] === 1 ? '0' : '1' ?>"><button class="admin-toggle <?= (int)$admin['is_active'] === 1 ? 'admin-toggle-disable' : 'admin-toggle-enable' ?>" type="submit"><?= (int)$admin['is_active'] === 1 ? 'Disable' : 'Reactivate' ?></button></form><?php else: ?><span class="current-admin-note">Current account</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <div class="admin-safety-note"><span>◇</span> You can’t disable your own account or the last active administrator.</div>
    </section>
    <section class="form-card admin-create-card">
        <div class="form-section-heading"><span class="section-number">＋</span><div><h2>Add an administrator</h2><p>New accounts can sign in as soon as they’re created.</p></div></div>
        <form method="post" action="admins.php" class="admin-create-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="create">
            <label class="field"><span>Display name <b>*</b></span><input name="display_name" maxlength="100" autocomplete="name" required></label>
            <label class="field"><span>Username <b>*</b></span><input name="username" maxlength="32" autocomplete="off" required><small>3–32 letters, numbers, dots, underscores or hyphens.</small></label>
            <label class="field"><span>Temporary password <b>*</b></span><input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required><small>Use 12–72 characters; share it securely.</small></label>
            <button class="button button-primary auth-submit" type="submit">Create admin account <span>→</span></button>
        </form>
    </section>
</div>
<?php renderFooter(); ?>

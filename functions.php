<?php
require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax'
    ]);
    session_start();
}

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrfToken()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken()
{
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        throw new RuntimeException('Your session expired. Refresh the page and try again.');
    }
}

function setFlash($type, $message)
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash()
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function getActiveAdmin()
{
    if (empty($_SESSION['admin_id']) || !ctype_digit((string)$_SESSION['admin_id'])) {
        return null;
    }

    $stmt = getDB()->prepare('SELECT id, username, display_name FROM admins WHERE id = :id AND is_active = 1');
    $stmt->execute([':id' => (int)$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    if (!$admin) {
        unset($_SESSION['admin_id']);
        return null;
    }

    return $admin;
}

function requireAdmin()
{
    try {
        if (getActiveAdmin()) {
            return;
        }

        $adminCount = (int)getDB()->query('SELECT COUNT(*) FROM admins WHERE is_active = 1')->fetchColumn();
        if ($adminCount === 0) {
            redirect('setup-admin.php');
        }
    } catch (RuntimeException $exception) {
        error_log('Unable to verify administrator access: ' . $exception->getMessage());
    }

    redirect('login.php');
}

function requireLoginCsrfToken()
{
    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])
        ? $_POST['csrf_token']
        : '';
    $expected = isset($_SESSION['login_csrf_token']) && is_string($_SESSION['login_csrf_token'])
        ? $_SESSION['login_csrf_token']
        : '';
    if ($expected === '' || !hash_equals($expected, $token)) {
        throw new RuntimeException('Your sign-in session expired. Refresh the page and try again.');
    }
}

function getLoginCsrfToken()
{
    if (empty($_SESSION['login_csrf_token'])) {
        $_SESSION['login_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['login_csrf_token'];
}

function recordLoginAttempt($ipHash)
{
    $stmt = getDB()->prepare('INSERT INTO admin_login_attempts (ip_hash) VALUES (:ip_hash)');
    $stmt->execute([':ip_hash' => $ipHash]);
}

function getLoginIpHash()
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
    return hash('sha256', $ip);
}

function renderAuthShell($title, $content)
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#0b1325">
        <title><?= e($title) ?> · NLT Inventory</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body class="auth-page">
        <main class="auth-layout">
            <section class="auth-panel">
                <a class="brand auth-brand" href="index.php"><img class="brand-mark" src="nlt-logo-placeholder.svg" alt=""><span class="brand-copy"><strong>NLT</strong><small>STOCK & ASSETS</small></span></a>
                <?= $content ?>
                <p class="auth-footer">NLT Inventory · Authorized administrators only</p>
            </section>
            <aside class="auth-visual"><span class="auth-visual-kicker">CLARITY IN EVERY COUNT</span><h2>Good records.<br><span>Better decisions.</span></h2><p>Your team's stock, assets, and movement history—in one considered place.</p><img src="nlt-logo-placeholder.svg" alt="" class="auth-watermark"></aside>
        </main>
    </body>
    </html>
    <?php
}

function getDashboardSummary()
{
    $db = getDB();
    $counts = $db->query("
        SELECT
            COUNT(*) AS total_items,
            COALESCE(SUM(category = 'goods'), 0) AS goods,
            COALESCE(SUM(category = 'consumables'), 0) AS consumables,
            COALESCE(SUM(category = 'assets'), 0) AS assets,
            COALESCE(SUM(quantity <= reorder_level), 0) AS low_stock,
            COALESCE(SUM(quantity), 0) AS total_units
        FROM items
    ")->fetch();

    return $counts;
}

function getAllItems($filters = [])
{
    $db = getDB();
    $sql = "
        SELECT i.*, a.asset_tag, a.assigned_to, a.department, a.condition_status
        FROM items i
        LEFT JOIN asset_details a ON i.id = a.item_id
        WHERE 1 = 1
    ";
    $params = [];

    if (isset($filters['search']) && $filters['search'] !== '') {
        $sql .= " AND (i.name LIKE :search_name OR i.item_code LIKE :search_code OR i.location LIKE :search_location)";
        $search = '%' . $filters['search'] . '%';
        $params[':search_name'] = $search;
        $params[':search_code'] = $search;
        $params[':search_location'] = $search;
    }
    if (!empty($filters['category']) && in_array($filters['category'], ['goods', 'consumables', 'assets'], true)) {
        $sql .= " AND i.category = :category";
        $params[':category'] = $filters['category'];
    }
    if (!empty($filters['low_stock'])) {
        $sql .= " AND i.quantity <= i.reorder_level";
    }
    $sql .= " ORDER BY i.created_at DESC, i.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getRecentMovements($limit = 6)
{
    $limit = max(1, min(50, (int)$limit));
    $stmt = getDB()->query("
        SELECT t.*, i.item_code, i.name AS item_name, i.unit
        FROM transactions t
        INNER JOIN items i ON i.id = t.item_id
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT " . $limit
    );
    return $stmt->fetchAll();
}

function getAllMovements($filters = [])
{
    $db = getDB();
    $sql = "
        SELECT t.*, i.item_code, i.name AS item_name, i.unit
        FROM transactions t
        INNER JOIN items i ON i.id = t.item_id
        WHERE 1 = 1
    ";
    $params = [];

    if (!empty($filters['type']) && in_array($filters['type'], ['in', 'out'], true)) {
        $sql .= " AND t.type = :type";
        $params[':type'] = $filters['type'];
    }
    if (isset($filters['search']) && $filters['search'] !== '') {
        $sql .= " AND (i.name LIKE :search_name OR i.item_code LIKE :search_code OR t.issued_to_or_from LIKE :search_party)";
        $search = '%' . $filters['search'] . '%';
        $params[':search_name'] = $search;
        $params[':search_code'] = $search;
        $params[':search_party'] = $search;
    }
    $sql .= " ORDER BY t.created_at DESC, t.id DESC LIMIT 200";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function addItem($data)
{
    $db = getDB();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare("
            INSERT INTO items (item_code, name, category, unit, quantity, reorder_level, location, supplier, description)
            VALUES (:item_code, :name, :category, :unit, :quantity, :reorder_level, :location, :supplier, :description)
        ");
        $stmt->execute([
            ':item_code' => $data['item_code'],
            ':name' => $data['name'],
            ':category' => $data['category'],
            ':unit' => $data['unit'],
            ':quantity' => $data['quantity'],
            ':reorder_level' => $data['reorder_level'],
            ':location' => $data['location'],
            ':supplier' => $data['supplier'] !== '' ? $data['supplier'] : null,
            ':description' => $data['description'] !== '' ? $data['description'] : null
        ]);
        $itemId = $db->lastInsertId();

        if ($data['category'] === 'assets') {
            $assetStmt = $db->prepare("
                INSERT INTO asset_details (item_id, asset_tag, assigned_to, department, condition_status, purchase_date)
                VALUES (:item_id, :asset_tag, :assigned_to, :department, :condition_status, :purchase_date)
            ");
            $assetStmt->execute([
                ':item_id' => $itemId,
                ':asset_tag' => $data['asset_tag'],
                ':assigned_to' => $data['assigned_to'] !== '' ? $data['assigned_to'] : null,
                ':department' => $data['department'] !== '' ? $data['department'] : null,
                ':condition_status' => $data['condition_status'],
                ':purchase_date' => $data['purchase_date'] !== '' ? $data['purchase_date'] : null
            ]);
        }

        if ($data['quantity'] > 0) {
            $movement = $db->prepare("
                INSERT INTO transactions (item_id, type, quantity, issued_to_or_from, notes)
                VALUES (:item_id, 'in', :quantity, :source, 'Opening stock')
            ");
            $movement->execute([
                ':item_id' => $itemId,
                ':quantity' => $data['quantity'],
                ':source' => $data['supplier'] !== '' ? $data['supplier'] : 'Opening balance'
            ]);
        }

        $db->commit();
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function recordStockMovement($itemId, $type, $quantity, $recipient = '', $notes = '')
{
    if (!in_array($type, ['in', 'out'], true) || (int)$quantity < 1) {
        throw new InvalidArgumentException('Choose a valid movement type and quantity.');
    }

    $db = getDB();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare('SELECT quantity FROM items WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => (int)$itemId]);
        $currentStock = $stmt->fetchColumn();

        if ($currentStock === false) {
            throw new RuntimeException('The selected item could not be found.');
        }
        if ($type === 'out' && (int)$currentStock < (int)$quantity) {
            throw new RuntimeException('There is not enough stock available for this issue.');
        }
        if ($type === 'in' && (int)$currentStock > 2147483647 - (int)$quantity) {
            throw new RuntimeException('This quantity would exceed the maximum stock balance.');
        }

        $newStock = $type === 'in'
            ? (int)$currentStock + (int)$quantity
            : (int)$currentStock - (int)$quantity;
        $updateStmt = $db->prepare('UPDATE items SET quantity = :quantity WHERE id = :id');
        $updateStmt->execute([':quantity' => $newStock, ':id' => (int)$itemId]);

        $logStmt = $db->prepare("
            INSERT INTO transactions (item_id, type, quantity, issued_to_or_from, notes)
            VALUES (:item_id, :type, :quantity, :issued_to, :notes)
        ");
        $logStmt->execute([
            ':item_id' => (int)$itemId,
            ':type' => $type,
            ':quantity' => (int)$quantity,
            ':issued_to' => $recipient !== '' ? $recipient : null,
            ':notes' => $notes !== '' ? $notes : null
        ]);

        $db->commit();
        return true;
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function renderHeader($title, $activePage)
{
    $flash = getFlash();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="theme-color" content="#0b1325">
        <title><?= e($title) ?> · NLT Inventory</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand" href="index.php" aria-label="NLT Inventory home">
                <img class="brand-mark" src="nlt-logo-placeholder.svg" alt="NLT logo placeholder">
                <span class="brand-copy"><strong>NLT</strong><small>STOCK & ASSETS</small></span>
            </a>
            <div class="sidebar-label">WORKSPACE</div>
            <nav class="side-nav" aria-label="Main navigation">
                <a class="<?= $activePage === 'dashboard' ? 'active' : '' ?>" href="index.php"><span class="nav-icon">⌂</span>Overview</a>
                <a class="<?= $activePage === 'add' ? 'active' : '' ?>" href="add-item.php"><span class="nav-icon">＋</span>New item</a>
                <a class="<?= $activePage === 'movement' ? 'active' : '' ?>" href="movement.php"><span class="nav-icon">↗</span>Stock movement</a>
                <a class="<?= $activePage === 'history' ? 'active' : '' ?>" href="transactions.php"><span class="nav-icon">≡</span>Movement history</a>
                <a class="<?= $activePage === 'reports' ? 'active' : '' ?>" href="reports.php"><span class="nav-icon">▤</span>Reports</a>
                <a class="<?= $activePage === 'admins' ? 'active' : '' ?>" href="admins.php"><span class="nav-icon">♙</span>Admin accounts</a>
            </nav>
            <div class="sidebar-bottom">
                <div class="workspace-card">
                    <span class="workspace-dot"></span>
                    <div><strong><?= e(isset($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Administrator') ?></strong><small>Administrator</small></div>
                    <form class="logout-form" action="logout.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <button class="logout-link" type="submit">Sign out</button>
                    </form>
                </div>
                <div class="sidebar-footnote">A little more clarity<br>in every count.</div>
            </div>
        </aside>
        <div class="main-column">
            <header class="topbar">
                <div class="breadcrumb"><span>Workspace</span><span class="crumb-slash">/</span><strong><?= e($title) ?></strong></div>
                <div class="topbar-right"><span class="live-indicator"></span><span>Inventory workspace</span><span class="avatar">NLT</span></div>
            </header>
            <main class="page-content">
                <?php if ($flash): ?>
                    <div class="flash flash-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
                <?php endif; ?>
    <?php
}

function renderFooter()
{
    ?>
            </main>
            <footer class="page-footer"><span>NLT Inventory</span><span>Thoughtful stock management, made simple.</span></footer>
        </div>
    </div>
    <script>
    document.addEventListener('click', function (event) {
        var target = event.target.closest('[data-confirm]');
        if (target && !window.confirm(target.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });
    </script>
    </body>
    </html>
    <?php
}

<?php
require_once __DIR__ . '/functions.php';
requireAdmin();

$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : '';
$error = '';
$movements = [];

try {
    $movements = getAllMovements(['search' => $search, 'type' => $type]);
} catch (Exception $exception) {
    error_log('Unable to load movement history: ' . $exception->getMessage());
    $error = $exception instanceof PDOException
        ? 'The inventory database is unavailable. Check the connection settings and try again.'
        : $exception->getMessage();
}

renderHeader('Movement history', 'history');
?>
<section class="history-heading">
    <div>
        <div class="eyebrow"><span class="eyebrow-line"></span>THE PAPER TRAIL</div>
        <h1>Movement<br><span>history.</span></h1>
        <p class="page-intro">Every item in, every item out. Nothing gets lost in the margins.</p>
    </div>
    <a class="button button-primary" href="movement.php">＋ Record movement</a>
</section>
<?php if ($error): ?>
    <div class="page-error" role="alert"><strong>We couldn’t load movement history.</strong><span><?= e($error) ?></span></div>
<?php else: ?>
<section class="panel history-panel">
    <div class="panel-heading">
        <div><div class="eyebrow">AUDIT TRAIL</div><h2>All movements <span class="count-pill"><?= count($movements) ?></span></h2></div>
        <span class="history-limit">Latest 200 records</span>
    </div>
    <form class="filter-bar history-filter" action="transactions.php" method="get">
        <label class="search-field"><span class="search-icon">⌕</span><input type="search" name="q" value="<?= e($search) ?>" placeholder="Search items, codes, people…" aria-label="Search movement history"></label>
        <select name="type" aria-label="Filter by movement type"><option value="">All movements</option><option value="in" <?= $type === 'in' ? 'selected' : '' ?>>Stock in</option><option value="out" <?= $type === 'out' ? 'selected' : '' ?>>Stock out</option></select>
        <button class="button button-soft" type="submit">Filter</button>
        <?php if ($search !== '' || $type !== ''): ?><a class="clear-link" href="transactions.php">Clear</a><?php endif; ?>
    </form>
    <?php if ($movements): ?>
    <div class="table-wrap">
        <table class="history-table">
            <thead><tr><th>MOVEMENT</th><th>ITEM</th><th>QUANTITY</th><th>FROM / TO</th><th>NOTE</th><th>DATE & TIME</th></tr></thead>
            <tbody>
            <?php foreach ($movements as $movement): ?>
                <tr>
                    <td><span class="movement-label movement-label-<?= e($movement['type']) ?>"><i><?= $movement['type'] === 'in' ? '↓' : '↑' ?></i>Stock <?= $movement['type'] === 'in' ? 'in' : 'out' ?></span></td>
                    <td><div class="history-item"><strong><?= e($movement['item_name']) ?></strong><small><?= e($movement['item_code']) ?></small></div></td>
                    <td><strong class="history-quantity <?= $movement['type'] === 'in' ? 'quantity-in' : 'quantity-out' ?>"><?= $movement['type'] === 'in' ? '+' : '−' ?><?= number_format((int)$movement['quantity']) ?></strong> <span class="unit-label"><?= e($movement['unit']) ?></span></td>
                    <td class="muted-cell"><?= e($movement['issued_to_or_from'] ?: '—') ?></td>
                    <td class="note-cell"><?= e($movement['notes'] ?: '—') ?></td>
                    <td><time class="history-date"><?= e(date('M j, Y', strtotime($movement['created_at']))) ?><small><?= e(date('g:i a', strtotime($movement['created_at']))) ?></small></time></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="table-footer"><span>Showing <?= count($movements) ?> <?= count($movements) === 1 ? 'movement' : 'movements' ?></span><span>For earlier records, contact your system administrator.</span></div>
    <?php else: ?>
    <div class="empty-state"><span class="empty-icon">≡</span><strong><?= $search !== '' || $type !== '' ? 'No matching movements' : 'No movements just yet' ?></strong><p><?= $search !== '' || $type !== '' ? 'Try another search or clear the filters.' : 'Once stock starts moving, you’ll find its story here.' ?></p><?php if ($search === '' && $type === ''): ?><a class="button button-primary" href="movement.php">Record a movement</a><?php endif; ?></div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php renderFooter(); ?>

<?php
require_once __DIR__ . '/functions.php';
requireAdmin();

$stats = null;
$items = [];
$movements = [];
$loadError = null;
$search = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$category = isset($_GET['category']) && is_string($_GET['category']) ? $_GET['category'] : '';
$lowStock = isset($_GET['low_stock']) && $_GET['low_stock'] === '1';

try {
    $stats = getDashboardSummary();
    $items = getAllItems([
        'search' => $search,
        'category' => $category,
        'low_stock' => $lowStock
    ]);
    $movements = getRecentMovements();
} catch (Exception $exception) {
    error_log('Dashboard could not load inventory: ' . $exception->getMessage());
    $loadError = $exception instanceof PDOException
        ? 'The inventory database is unavailable. Check the connection settings and try again.'
        : $exception->getMessage();
}

renderHeader('Overview', 'dashboard');
?>
<section class="welcome-row">
    <div>
        <div class="eyebrow"><span class="eyebrow-line"></span><?= strtoupper(date('l, F j, Y')) ?></div>
        <h1>Your stock,<br><span>at a glance.</span></h1>
        <p class="page-intro">A clear view of what you have, what you need, and what’s moving.</p>
    </div>
    <div class="welcome-actions"><a class="button button-soft" href="reports.php">Reports <span>↓</span></a><a class="button button-primary" href="add-item.php"><span class="button-plus">＋</span> Add an item</a></div>
</section>

<?php if ($loadError): ?>
    <div class="page-error" role="alert"><strong>We couldn’t load your inventory.</strong><span><?= e($loadError) ?></span></div>
<?php else: ?>
    <section class="metric-grid" aria-label="Inventory summary">
        <article class="metric-card metric-primary">
            <div class="metric-heading"><span>ITEMS IN CATALOG</span><span class="metric-icon icon-leaf">✳</span></div>
            <strong class="metric-value"><?= number_format((int)$stats['total_items']) ?></strong>
            <div class="metric-caption">Across all categories</div>
            <div class="metric-decoration"></div>
        </article>
        <article class="metric-card">
            <div class="metric-heading"><span>TOTAL UNITS ON HAND</span><span class="metric-icon">▤</span></div>
            <strong class="metric-value"><?= number_format((int)$stats['total_units']) ?></strong>
            <div class="metric-caption">Pieces, packs & tracked assets</div>
        </article>
        <a class="metric-card metric-alert <?= (int)$stats['low_stock'] ? 'has-alert' : '' ?>" href="index.php?low_stock=1">
            <div class="metric-heading"><span>NEED A RESTOCK</span><span class="metric-icon">↗</span></div>
            <strong class="metric-value"><?= number_format((int)$stats['low_stock']) ?></strong>
            <div class="metric-caption"><?= (int)$stats['low_stock'] ? 'Items at or below reorder level' : 'Everything is above its reorder point' ?></div>
        </a>
        <article class="metric-card category-metric">
            <div class="metric-heading"><span>YOUR CATALOG</span><span class="metric-icon">◫</span></div>
            <div class="category-counts">
                <span><i class="category-dot dot-goods"></i><strong><?= (int)$stats['goods'] ?></strong> goods</span>
                <span><i class="category-dot dot-consumables"></i><strong><?= (int)$stats['consumables'] ?></strong> consumables</span>
                <span><i class="category-dot dot-assets"></i><strong><?= (int)$stats['assets'] ?></strong> assets</span>
            </div>
        </article>
    </section>

    <?php if ((int)$stats['low_stock'] > 0): ?>
    <a class="restock-banner" href="index.php?low_stock=1">
        <span class="restock-symbol">!</span>
        <span><strong><?= (int)$stats['low_stock'] ?> <?= (int)$stats['low_stock'] === 1 ? 'item needs' : 'items need' ?> a little attention</strong><small>Check your low-stock list and plan your next restock.</small></span>
        <span class="restock-link">Review items <b>→</b></span>
    </a>
    <?php endif; ?>

    <section class="panel inventory-panel">
        <div class="panel-heading">
            <div><div class="eyebrow">YOUR COLLECTION</div><h2>Inventory register <span class="count-pill"><?= count($items) ?></span></h2></div>
            <a class="text-link" href="transactions.php">View movement history <span>→</span></a>
        </div>
        <form class="filter-bar" action="index.php" method="get">
            <label class="search-field"><span class="search-icon">⌕</span><input type="search" name="q" value="<?= e($search) ?>" placeholder="Search items, codes, locations…" aria-label="Search inventory"></label>
            <select name="category" aria-label="Filter by category">
                <option value="">All categories</option>
                <option value="goods" <?= $category === 'goods' ? 'selected' : '' ?>>Goods</option>
                <option value="consumables" <?= $category === 'consumables' ? 'selected' : '' ?>>Consumables</option>
                <option value="assets" <?= $category === 'assets' ? 'selected' : '' ?>>Assets</option>
            </select>
            <?php if ($lowStock): ?><input type="hidden" name="low_stock" value="1"><?php endif; ?>
            <button class="button button-soft" type="submit">Filter</button>
            <?php if ($search !== '' || $category !== '' || $lowStock): ?><a class="clear-link" href="index.php">Clear</a><?php endif; ?>
        </form>

        <?php if ($items): ?>
        <div class="table-wrap">
            <table class="inventory-table">
                <thead><tr><th>ITEM</th><th>CATEGORY</th><th>IN STOCK</th><th>REORDER AT</th><th>LOCATION</th><th>ASSET / ASSIGNEE</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php $isLow = (int)$item['quantity'] <= (int)$item['reorder_level']; ?>
                    <tr class="<?= $isLow ? 'is-low-stock' : '' ?>">
                        <td><div class="item-cell"><span class="item-thumb thumb-<?= e($item['category']) ?>"><?= $item['category'] === 'assets' ? '◇' : ($item['category'] === 'consumables' ? '◌' : '▧') ?></span><span><strong><?= e($item['name']) ?></strong><small><?= e($item['item_code']) ?></small></span></div></td>
                        <td><span class="category-tag tag-<?= e($item['category']) ?>"><i></i><?= e(ucfirst($item['category'])) ?></span></td>
                        <td><span class="stock-number <?= $isLow ? 'stock-low' : '' ?>"><?= number_format((int)$item['quantity']) ?></span> <span class="unit-label"><?= e($item['unit']) ?></span><?php if ($isLow): ?><span class="low-label">Low</span><?php endif; ?></td>
                        <td class="muted-cell"><?= number_format((int)$item['reorder_level']) ?> <?= e($item['unit']) ?></td>
                        <td class="muted-cell"><?= e($item['location']) ?></td>
                        <td class="muted-cell"><?= isset($item['asset_tag']) && $item['asset_tag'] !== '' ? e($item['asset_tag']) . '<br><small>' . e($item['assigned_to'] !== null && $item['assigned_to'] !== '' ? $item['assigned_to'] : 'Unassigned') . '</small>' : '—' ?></td>
                        <td><a class="row-action" href="movement.php?item=<?= (int)$item['id'] ?>" aria-label="Record movement for <?= e($item['name']) ?>">↗</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="empty-state"><span class="empty-icon">⌕</span><strong><?= $search !== '' || $category !== '' || $lowStock ? 'No matching items' : 'Your inventory starts here' ?></strong><p><?= $search !== '' || $category !== '' || $lowStock ? 'Try another search or clear the filters.' : 'Add your first item and keep everything in one place.' ?></p><?php if ($search === '' && $category === '' && !$lowStock): ?><a class="button button-primary" href="add-item.php">Add your first item</a><?php endif; ?></div>
        <?php endif; ?>
        <div class="table-footer"><span>Showing <?= count($items) ?> <?= count($items) === 1 ? 'item' : 'items' ?></span><span>Stock levels update with every movement</span></div>
    </section>

    <section class="panel activity-panel">
        <div class="panel-heading">
            <div><div class="eyebrow">THE LATEST</div><h2>Recent movements</h2></div>
            <a class="text-link" href="transactions.php">See all movements <span>→</span></a>
        </div>
        <?php if ($movements): ?>
        <div class="activity-list">
            <?php foreach ($movements as $movement): ?>
            <div class="activity-row">
                <span class="movement-icon movement-<?= e($movement['type']) ?>"><?= $movement['type'] === 'in' ? '↓' : '↑' ?></span>
                <div class="activity-main"><strong><?= e($movement['item_name']) ?></strong><small><?= e($movement['item_code']) ?><?php if ($movement['issued_to_or_from'] !== null && $movement['issued_to_or_from'] !== ''): ?> · <?= $movement['type'] === 'in' ? 'From' : 'To' ?> <?= e($movement['issued_to_or_from']) ?><?php endif; ?></small></div>
                <span class="activity-quantity <?= $movement['type'] === 'in' ? 'quantity-in' : 'quantity-out' ?>"><?= $movement['type'] === 'in' ? '+' : '−' ?><?= number_format((int)$movement['quantity']) ?> <?= e($movement['unit']) ?></span>
                <time><?= e(date('M j, g:i a', strtotime($movement['created_at']))) ?></time>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?><div class="activity-empty">Your stock movements will show up here.</div><?php endif; ?>
    </section>
<?php endif; ?>
<?php renderFooter(); ?>

<?php
require_once __DIR__ . '/functions.php';
requireAdmin();

$stats = null;
$itemCount = 0;
$movementCount = 0;
$error = '';

try {
    $stats = getDashboardSummary();
    $itemCount = count(getAllItems());
    $movementCount = count(getAllMovements());
} catch (Exception $exception) {
    error_log('Unable to prepare inventory reports: ' . $exception->getMessage());
    $error = $exception instanceof PDOException
        ? 'The inventory database is unavailable. Check the connection settings and try again.'
        : 'Report details could not be loaded. Please try again.';
}

renderHeader('Reports', 'reports');
?>
<section class="history-heading reports-heading">
    <div>
        <div class="eyebrow"><span class="eyebrow-line"></span>A CLEARER PICTURE</div>
        <h1>Reports,<br><span>ready when you are.</span></h1>
        <p class="page-intro">Take your stock register and movement history with you.</p>
    </div>
</section>
<?php if ($error): ?>
    <div class="page-error" role="alert"><strong>Reports aren’t available right now.</strong><span><?= e($error) ?></span></div>
<?php else: ?>
<section class="report-summary">
    <div><span>CATALOG ITEMS</span><strong><?= number_format($itemCount) ?></strong></div>
    <div><span>UNITS ON HAND</span><strong><?= number_format((int)$stats['total_units']) ?></strong></div>
    <div><span>RESTOCK NEEDED</span><strong><?= number_format((int)$stats['low_stock']) ?></strong></div>
    <div><span>MOVEMENTS IN REPORT</span><strong><?= number_format($movementCount) ?></strong><small>Most recent 200</small></div>
</section>
<section class="report-grid">
    <article class="report-card">
        <div class="report-art report-art-pdf"><span>PDF</span><i>▤</i></div>
        <div class="eyebrow">SHAREABLE SNAPSHOT</div>
        <h2>Inventory report</h2>
        <p>A polished, print-ready overview of your current stock, reorder levels, asset assignments, and recent activity.</p>
        <a class="button button-primary report-download" href="download-report.php?format=pdf">Download PDF <span>↓</span></a>
    </article>
    <article class="report-card">
        <div class="report-art report-art-excel"><span>XLSX</span><i>▦</i></div>
        <div class="eyebrow">READY TO WORK WITH</div>
        <h2>Spreadsheet report</h2>
        <p>Open in Microsoft Excel or Google Sheets. Includes separate summary, inventory register, and movement-history tabs.</p>
        <a class="button button-primary report-download" href="download-report.php?format=xlsx">Download Excel workbook <span>↓</span></a>
    </article>
</section>
<p class="report-footnote">Reports include all catalog items and the latest 200 stock movements. Generated just now from the live inventory register.</p>
<?php endif; ?>
<?php renderFooter(); ?>

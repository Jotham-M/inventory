<?php
require_once __DIR__ . '/functions.php';
requireAdmin();

$items = [];
$error = '';
$selectedId = isset($_GET['item']) && is_string($_GET['item']) && ctype_digit($_GET['item']) ? (int)$_GET['item'] : 0;
$type = 'in';
$quantity = '1';
$recipient = '';
$notes = '';

try {
    $items = getAllItems();
} catch (Exception $exception) {
    error_log('Unable to load items for stock movement: ' . $exception->getMessage());
    $error = $exception instanceof PDOException
        ? 'The inventory database is unavailable. Check the connection settings and try again.'
        : $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedId = isset($_POST['item_id']) && is_string($_POST['item_id']) && ctype_digit($_POST['item_id']) ? (int)$_POST['item_id'] : 0;
    $type = isset($_POST['type']) && is_string($_POST['type']) ? $_POST['type'] : '';
    $quantity = isset($_POST['quantity']) && is_string($_POST['quantity']) ? trim($_POST['quantity']) : '';
    $recipient = isset($_POST['issued_to_or_from']) && is_string($_POST['issued_to_or_from']) ? trim($_POST['issued_to_or_from']) : '';
    $notes = isset($_POST['notes']) && is_string($_POST['notes']) ? trim($_POST['notes']) : '';

    try {
        verifyCsrfToken();
        if ($selectedId < 1) {
            throw new InvalidArgumentException('Choose an item to move.');
        }
        if (!in_array($type, ['in', 'out'], true)) {
            throw new InvalidArgumentException('Choose stock in or stock out.');
        }
        if (!ctype_digit($quantity) || (int)$quantity < 1 || (int)$quantity > 2147483647) {
            throw new InvalidArgumentException('Quantity must be a whole number greater than zero.');
        }
        if (strlen($recipient) > 100) {
            throw new InvalidArgumentException('Supplier or recipient must be 100 characters or fewer.');
        }
        if (strlen($notes) > 60000) {
            throw new InvalidArgumentException('Movement note must be 60,000 characters or fewer.');
        }
        recordStockMovement($selectedId, $type, (int)$quantity, $recipient, $notes);
        setFlash('success', $type === 'in' ? 'Stock received and inventory updated.' : 'Stock issued and inventory updated.');
        redirect('movement.php');
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('Unable to record stock movement: ' . $exception->getMessage());
        $error = 'We could not record this movement. Please check the database and try again.';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Exception $exception) {
        error_log('Unable to record stock movement: ' . $exception->getMessage());
        $error = 'We could not record this movement. Please try again.';
    }
}

renderHeader('Stock movement', 'movement');
?>
<section class="form-page-heading">
    <a class="back-link" href="index.php">← Back to overview</a>
    <div class="eyebrow"><span class="eyebrow-line"></span>KEEP THE RECORD STRAIGHT</div>
    <h1>Every movement<br><span>has a story.</span></h1>
    <p class="page-intro">Record stock coming in or going out. Your inventory and audit trail update together.</p>
</section>
<?php if ($error): ?><div class="form-error" role="alert"><strong>Movement not recorded</strong><span><?= e($error) ?></span></div><?php endif; ?>

<?php if (!$items && !$error): ?>
    <section class="panel empty-state"><span class="empty-icon">↗</span><strong>Add an item before recording a movement</strong><p>Once you have something in your catalog, stock movements are easy to track.</p><a class="button button-primary" href="add-item.php">Add your first item</a></section>
<?php elseif ($items): ?>
<div class="movement-layout">
    <form class="form-card movement-form" action="movement.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
        <div class="form-section-heading movement-heading"><span class="section-number">↗</span><div><h2>Record a movement</h2><p>One entry, one accurate stock level.</p></div></div>
        <div class="movement-type-switch" role="group" aria-label="Movement type">
            <label class="movement-choice choice-in <?= $type === 'in' ? 'selected' : '' ?>"><input type="radio" name="type" value="in" <?= $type === 'in' ? 'checked' : '' ?>><span class="choice-symbol">↓</span><span><strong>Stock in</strong><small>Receive or return stock</small></span></label>
            <label class="movement-choice choice-out <?= $type === 'out' ? 'selected' : '' ?>"><input type="radio" name="type" value="out" <?= $type === 'out' ? 'checked' : '' ?>><span class="choice-symbol">↑</span><span><strong>Stock out</strong><small>Issue or use stock</small></span></label>
        </div>
        <label class="field"><span>Choose an item <b>*</b></span><select name="item_id" id="movement-item" required><option value="">Select an item…</option><?php foreach ($items as $item): ?><option value="<?= (int)$item['id'] ?>" data-quantity="<?= (int)$item['quantity'] ?>" data-unit="<?= e($item['unit']) ?>" <?= $selectedId === (int)$item['id'] ? 'selected' : '' ?>><?= e($item['name']) ?> · <?= e($item['item_code']) ?></option><?php endforeach; ?></select></label>
        <div class="stock-context" id="stock-context"><span class="context-dot"></span><span>Current balance</span><strong id="current-balance">Choose an item</strong></div>
        <label class="field"><span>Quantity <b>*</b></span><div class="input-suffix"><input name="quantity" type="number" min="1" step="1" value="<?= e($quantity) ?>" required><span>units</span></div></label>
        <label class="field"><span id="party-label">Received from</span><input name="issued_to_or_from" value="<?= e($recipient) ?>" maxlength="100" placeholder="Supplier, team or source"></label>
        <label class="field"><span>Note <em>OPTIONAL</em></span><textarea name="notes" rows="3" placeholder="Add a reason or reference…"><?= e($notes) ?></textarea></label>
        <div class="movement-submit-note"><span>↻</span> Your balance and movement history update together.</div>
        <button class="button button-primary button-wide" type="submit">Record movement <span>→</span></button>
    </form>
    <aside class="movement-aside">
        <div class="aside-note">
            <span class="aside-illustration">✳</span>
            <div class="eyebrow">A GOOD HABIT</div>
            <h2>Little entries.<br>Big picture.</h2>
            <p>Every stock movement is saved with the date, quantity, and who it came from or went to. Your team always has the context behind the count.</p>
        </div>
        <a class="aside-link" href="transactions.php"><span class="aside-link-icon">≡</span><span><strong>Movement history</strong><small>See your latest stock activity</small></span><b>→</b></a>
    </aside>
</div>
<script>
(function () {
    var select = document.getElementById('movement-item');
    var balance = document.getElementById('current-balance');
    var typeRadios = document.querySelectorAll('input[name="type"]');
    var partyLabel = document.getElementById('party-label');
    function updateBalance() {
        var option = select.options[select.selectedIndex];
        balance.textContent = option && option.value ? option.dataset.quantity + ' ' + option.dataset.unit + ' on hand' : 'Choose an item';
    }
    function updateType() {
        var selected = document.querySelector('input[name="type"]:checked').value;
        document.querySelectorAll('.movement-choice').forEach(function (choice) { choice.classList.remove('selected'); });
        document.querySelector('input[name="type"]:checked').closest('.movement-choice').classList.add('selected');
        partyLabel.textContent = selected === 'in' ? 'Received from' : 'Issued to';
    }
    select.addEventListener('change', updateBalance);
    typeRadios.forEach(function (radio) { radio.addEventListener('change', updateType); });
    updateBalance();
    updateType();
}());
</script>
<?php endif; ?>
<?php renderFooter(); ?>

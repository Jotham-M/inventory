<?php
require_once __DIR__ . '/functions.php';
requireAdmin();

$values = [
    'item_code' => '',
    'name' => '',
    'category' => 'goods',
    'unit' => 'pcs',
    'quantity' => '0',
    'reorder_level' => '5',
    'location' => 'Main Storeroom',
    'supplier' => '',
    'description' => '',
    'asset_tag' => '',
    'assigned_to' => '',
    'department' => '',
    'condition_status' => 'Good',
    'purchase_date' => ''
];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $values[$key] = trim($_POST[$key]);
        }
    }

    try {
        verifyCsrfToken();
        $allowedCategories = ['goods', 'consumables', 'assets'];
        $allowedConditions = ['Excellent', 'Good', 'Needs Repair', 'Scrapped'];
        if ($values['item_code'] === '' || strlen($values['item_code']) > 50) {
            throw new InvalidArgumentException('Enter an item code up to 50 characters long.');
        }
        if ($values['name'] === '' || strlen($values['name']) > 150) {
            throw new InvalidArgumentException('Enter an item name up to 150 characters long.');
        }
        if (!in_array($values['category'], $allowedCategories, true)) {
            throw new InvalidArgumentException('Choose a valid item category.');
        }
        if ($values['unit'] === '' || strlen($values['unit']) > 20) {
            throw new InvalidArgumentException('Enter a unit up to 20 characters long.');
        }
        if (!ctype_digit($values['quantity']) || (int)$values['quantity'] > 2147483647) {
            throw new InvalidArgumentException('Opening stock must be a whole number of 0 or more.');
        }
        if (!ctype_digit($values['reorder_level']) || (int)$values['reorder_level'] > 2147483647) {
            throw new InvalidArgumentException('Reorder level must be a whole number of 0 or more.');
        }
        if ($values['location'] === '' || strlen($values['location']) > 100) {
            throw new InvalidArgumentException('Enter a storage location up to 100 characters long.');
        }
        if (strlen($values['supplier']) > 100) {
            throw new InvalidArgumentException('Supplier must be 100 characters or fewer.');
        }
        if (strlen($values['description']) > 60000) {
            throw new InvalidArgumentException('Description must be 60,000 characters or fewer.');
        }
        if ($values['category'] === 'assets') {
            if ($values['asset_tag'] === '' || strlen($values['asset_tag']) > 50) {
                throw new InvalidArgumentException('Tracked assets need a unique asset tag (up to 50 characters).');
            }
            if (!in_array($values['condition_status'], $allowedConditions, true)) {
                throw new InvalidArgumentException('Choose a valid asset condition.');
            }
            if (strlen($values['assigned_to']) > 100 || strlen($values['department']) > 100) {
                throw new InvalidArgumentException('Assigned person and department must be 100 characters or fewer.');
            }
        }
        if ($values['purchase_date'] !== '') {
            $date = DateTime::createFromFormat('Y-m-d', $values['purchase_date']);
            if (!$date || $date->format('Y-m-d') !== $values['purchase_date']) {
                throw new InvalidArgumentException('Enter a valid purchase date.');
            }
        }

        $data = $values;
        $data['quantity'] = (int)$values['quantity'];
        $data['reorder_level'] = (int)$values['reorder_level'];
        addItem($data);
        setFlash('success', 'Item added to your inventory.');
        redirect('index.php');
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('Unable to add inventory item: ' . $exception->getMessage());
        $error = $exception->getCode() === '23000'
            ? 'That item code or asset tag is already in use.'
            : 'We could not save this item. Please try again.';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Exception $exception) {
        error_log('Unable to add inventory item: ' . $exception->getMessage());
        $error = 'We could not save this item. Please try again.';
    }
}

renderHeader('New item', 'add');
?>
<section class="form-page-heading">
    <a class="back-link" href="index.php">← Back to overview</a>
    <div class="eyebrow"><span class="eyebrow-line"></span>BUILD YOUR CATALOG</div>
    <h1>Add something<br><span>worth keeping track of.</span></h1>
    <p class="page-intro">Create an item record, set its reorder point, and start with your current stock.</p>
</section>

<?php if ($error): ?><div class="form-error" role="alert"><strong>There’s one thing to fix</strong><span><?= e($error) ?></span></div><?php endif; ?>

<form class="form-card" method="post" action="add-item.php">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <section class="form-section">
        <div class="form-section-heading"><span class="section-number">01</span><div><h2>The essentials</h2><p>Give this item a name and a home in your catalog.</p></div></div>
        <div class="form-grid">
            <label class="field"><span>Item name <b>*</b></span><input name="name" value="<?= e($values['name']) ?>" placeholder="e.g. A4 recycled paper" maxlength="150" required></label>
            <label class="field"><span>Item code <b>*</b></span><input name="item_code" value="<?= e($values['item_code']) ?>" placeholder="e.g. ST-1042" maxlength="50" required><small>A unique reference your team will recognize.</small></label>
            <label class="field"><span>Category <b>*</b></span><select name="category" id="category" required><option value="goods" <?= $values['category'] === 'goods' ? 'selected' : '' ?>>Goods</option><option value="consumables" <?= $values['category'] === 'consumables' ? 'selected' : '' ?>>Consumables</option><option value="assets" <?= $values['category'] === 'assets' ? 'selected' : '' ?>>Tracked asset</option></select></label>
            <label class="field"><span>Unit of measure <b>*</b></span><input name="unit" value="<?= e($values['unit']) ?>" placeholder="pcs, boxes, packs…" maxlength="20" required></label>
            <label class="field"><span>Storage location <b>*</b></span><input name="location" value="<?= e($values['location']) ?>" placeholder="e.g. Storeroom · Shelf B2" maxlength="100" required></label>
            <label class="field"><span>Supplier</span><input name="supplier" value="<?= e($values['supplier']) ?>" placeholder="Supplier or source" maxlength="100"></label>
            <label class="field field-full"><span>Description <em>OPTIONAL</em></span><textarea name="description" rows="3" placeholder="Anything useful to know about this item…"><?= e($values['description']) ?></textarea></label>
        </div>
    </section>
    <section class="form-section">
        <div class="form-section-heading"><span class="section-number">02</span><div><h2>Stock levels</h2><p>Set a starting balance and know when it’s time to reorder.</p></div></div>
        <div class="form-grid form-grid-narrow">
            <label class="field"><span>Opening stock <b>*</b></span><div class="input-suffix"><input name="quantity" type="number" min="0" step="1" value="<?= e($values['quantity']) ?>" required><span>units</span></div><small>Your starting balance is saved in movement history.</small></label>
            <label class="field"><span>Reorder point <b>*</b></span><div class="input-suffix"><input name="reorder_level" type="number" min="0" step="1" value="<?= e($values['reorder_level']) ?>" required><span>units</span></div><small>We’ll flag the item when stock reaches this level.</small></label>
        </div>
    </section>
    <section class="form-section asset-fields" id="asset-fields" <?= $values['category'] !== 'assets' ? 'hidden' : '' ?>>
        <div class="form-section-heading"><span class="section-number">03</span><div><h2>Asset details</h2><p>Keep track of ownership and condition for assigned equipment.</p></div></div>
        <div class="form-grid">
            <label class="field"><span>Asset tag <b>*</b></span><input name="asset_tag" value="<?= e($values['asset_tag']) ?>" placeholder="e.g. NL-LT-0024" maxlength="50"></label>
            <label class="field"><span>Assigned to</span><input name="assigned_to" value="<?= e($values['assigned_to']) ?>" placeholder="Person or team" maxlength="100"></label>
            <label class="field"><span>Department</span><input name="department" value="<?= e($values['department']) ?>" placeholder="e.g. Design" maxlength="100"></label>
            <label class="field"><span>Condition</span><select name="condition_status"><?php foreach (['Excellent', 'Good', 'Needs Repair', 'Scrapped'] as $condition): ?><option value="<?= e($condition) ?>" <?= $values['condition_status'] === $condition ? 'selected' : '' ?>><?= e($condition) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Purchase date</span><input name="purchase_date" type="date" value="<?= e($values['purchase_date']) ?>"></label>
        </div>
    </section>
    <div class="form-actions"><a class="button button-quiet" href="index.php">Cancel</a><button class="button button-primary" type="submit">Save item <span>→</span></button></div>
</form>
<script>
document.getElementById('category').addEventListener('change', function () {
    var assetSection = document.getElementById('asset-fields');
    var assetTag = assetSection.querySelector('[name="asset_tag"]');
    assetSection.hidden = this.value !== 'assets';
    assetTag.required = this.value === 'assets';
});
</script>
<?php renderFooter(); ?>

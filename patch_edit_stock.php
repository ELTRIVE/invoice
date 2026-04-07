<?php
$content = file_get_contents('edit_stock.php');

$search1 = <<<'EOD'
$success = $error = '';
$ref         = $_SERVER['HTTP_REFERER'] ?? 'items_list.php';
$fromInvoice = isset($_GET['_from']) && $_GET['_from'] === 'create_invoice';
$editId      = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['_ref'])) $ref = $_POST['_ref'];
    $fromInvoicePost = !empty($_POST['_from_invoice']);
    $editIdPost      = (int)($_POST['_edit_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            INSERT INTO items (
                item_name, service_code, hsn_sac, material_description, uom, qty,
                delivery_date, unit_price, basic_amount, discount_percent,
                pf_percent, sgst, cgst, igst, tcs_percent, total
            ) VALUES (
                :item_name, :service_code, :hsn_sac, :material_description, :uom, :qty,
                :delivery_date, :unit_price, :basic_amount, :discount_percent,
                :pf_percent, :sgst, :cgst, :igst, :tcs_percent, :total
            )
        ");
        $stmt->execute([
            'item_name'            => $_POST['item_name'] ?? '',
            'service_code'         => $_POST['service_code'],
            'hsn_sac'              => $_POST['hsn_sac'],
            'material_description' => $_POST['material_description'],
            'uom'                  => $_POST['uom'],
            'qty'                  => $_POST['qty'],
            'delivery_date'        => $_POST['delivery_date'],
            'unit_price'           => $_POST['unit_price'],
            'basic_amount'         => $_POST['basic_amount'],
            'discount_percent'     => $_POST['discount_percent'],
            'pf_percent'           => $_POST['pf_percent'],
            'sgst'                 => $_POST['sgst'],
            'cgst'                 => $_POST['cgst'],
            'igst'                 => $_POST['igst'],
            'tcs_percent'          => $_POST['tcs_percent'],
            'total'                => $_POST['total'],
        ]);
        // Return to create_invoice with _restored=1 so draft gets restored
        if ($fromInvoicePost) {
            $back = 'create_invoice.php?_restored=1';
            if ($editIdPost > 0) $back .= '&edit=' . $editIdPost;
            header('Location: ' . $back);
        } else {
            header('Location: ' . $ref . '?stock_saved=1');
        }
        exit;
    } catch (PDOException $e) {
        $error = "Error saving stock: " . $e->getMessage();
    }
}
EOD;

$replace1 = <<<'EOD'
$success = $error = '';
$ref         = $_SERVER['HTTP_REFERER'] ?? 'items_list.php';
$fromInvoice = isset($_GET['_from']) && $_GET['_from'] === 'create_invoice';
$editId      = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;

if ($editId <= 0) die("Invalid Item ID.");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['_ref'])) $ref = $_POST['_ref'];
    $fromInvoicePost = !empty($_POST['_from_invoice']);
    $editIdPost      = (int)($_POST['_edit_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            UPDATE items SET 
                item_name = :item_name, service_code = :service_code, hsn_sac = :hsn_sac,
                material_description = :material_description, uom = :uom, qty = :qty,
                delivery_date = :delivery_date, unit_price = :unit_price, basic_amount = :basic_amount,
                discount_percent = :discount_percent, pf_percent = :pf_percent,
                sgst = :sgst, cgst = :cgst, igst = :igst, tcs_percent = :tcs_percent, total = :total
            WHERE id = :id
        ");
        $stmt->execute([
            'item_name'            => $_POST['item_name'] ?? '',
            'service_code'         => $_POST['service_code'],
            'hsn_sac'              => $_POST['hsn_sac'],
            'material_description' => $_POST['material_description'],
            'uom'                  => $_POST['uom'],
            'qty'                  => $_POST['qty'],
            'delivery_date'        => $_POST['delivery_date'],
            'unit_price'           => $_POST['unit_price'],
            'basic_amount'         => $_POST['basic_amount'],
            'discount_percent'     => $_POST['discount_percent'],
            'pf_percent'           => $_POST['pf_percent'],
            'sgst'                 => $_POST['sgst'],
            'cgst'                 => $_POST['cgst'],
            'igst'                 => $_POST['igst'],
            'tcs_percent'          => $_POST['tcs_percent'],
            'total'                => $_POST['total'],
            'id'                   => $editIdPost
        ]);
        if ($fromInvoicePost) {
            $back = 'create_invoice.php?_restored=1&edit=' . $editIdPost;
            header('Location: ' . $back);
        } else {
            header('Location: ' . $ref . '?stock_updated=1');
        }
        exit;
    } catch (PDOException $e) {
        $error = "Error updating stock: " . $e->getMessage();
    }
}

$stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
$stmt->execute([$editId]);
$itemData = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$itemData) die("Item not found.");
EOD;

$content = str_replace($search1, $replace1, $content);
$content = str_replace('<title>Add Stock Item</title>', '<title>Edit Stock Item</title>', $content);
$content = str_replace('<div class="page-title">Add Stock Item</div>', '<div class="page-title">Edit Stock Item</div>', $content);
$content = str_replace('<div class="page-sub">Fill in the details to add a new stock item</div>', '<div class="page-sub">Update the details of this stock item</div>', $content);
$content = str_replace('<button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Stock</button>', '<button type="submit" class="btn-save"><i class="fas fa-save"></i> Update Stock</button>', $content);

// Replace inputs using regex purely in PHP
$content = preg_replace('/<input type="text" name="([a-zA-Z0-9_]+)"([^>]*)>/', '<input type="text" name="$1"$2 value="<?= htmlspecialchars($itemData[\'$1\'] ?? \'\') ?>">', $content);
$content = preg_replace('/<input type="number"([^>]*)name="([a-zA-Z0-9_]+)"([^>]*)>/', '<input type="number"$1name="$2"$3 value="<?= htmlspecialchars($itemData[\'$2\'] ?? \'\') ?>">', $content);
$content = preg_replace('/<input type="date" name="([a-zA-Z0-9_]+)"([^>]*)>/', '<input type="date" name="$1"$2 value="<?= htmlspecialchars($itemData[\'$1\'] ?? \'\') ?>">', $content);
$content = preg_replace('/<textarea name="([a-zA-Z0-9_]+)"([^>]*)><\/textarea>/', '<textarea name="$1"$2><?= htmlspecialchars($itemData[\'$1\'] ?? \'\') ?></textarea>', $content);

file_put_contents('edit_stock.php', $content);
echo "Successfully patched edit_stock.php";

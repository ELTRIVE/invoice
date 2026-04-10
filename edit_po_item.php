<?php
require_once __DIR__ . '/db.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: po_items.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_po_item'])) {
    $itemName = trim((string)($_POST['item_name'] ?? ''));
    if ($itemName !== '') {
        $stmt = $pdo->prepare("
            UPDATE master_po_items
            SET item_name = :item_name,
                description = :description,
                hsn_sac = :hsn_sac,
                unit = :unit,
                rate = :rate,
                cgst_pct = :cgst_pct,
                sgst_pct = :sgst_pct,
                igst_pct = :igst_pct
            WHERE id = :id
        ");
        $stmt->execute([
            ':item_name' => $itemName,
            ':description' => trim((string)($_POST['description'] ?? '')),
            ':hsn_sac' => trim((string)($_POST['hsn_sac'] ?? '')),
            ':unit' => trim((string)($_POST['unit'] ?? '')),
            ':rate' => (float)($_POST['rate'] ?? 0),
            ':cgst_pct' => (float)($_POST['cgst_pct'] ?? 0),
            ':sgst_pct' => (float)($_POST['sgst_pct'] ?? 0),
            ':igst_pct' => (float)($_POST['igst_pct'] ?? 0),
            ':id' => $id,
        ]);
    }
    header('Location: po_items.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM master_po_items WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$item) {
    header('Location: po_items.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Edit PO Item</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#f0f2f8}
.content{margin-left:220px;padding:58px 18px;min-height:100vh}
.card{max-width:760px;background:#fff;border:1px solid #e8ecf4;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.04);overflow:hidden}
.hdr{padding:10px 14px;border-bottom:1px solid #f0f2f7;font-weight:800}
.body{padding:14px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px}
label{font-size:11px;font-weight:700;color:#374151;display:block;margin-bottom:4px}
input,textarea{width:100%;padding:8px;border:1.5px solid #e4e8f0;border-radius:7px;font-size:13px}
textarea{min-height:70px;resize:vertical}
.actions{display:flex;gap:8px;justify-content:flex-end}
.btn{padding:8px 14px;border-radius:7px;border:1px solid #e4e8f0;text-decoration:none;cursor:pointer}
.btn-save{background:#16a34a;border-color:#16a34a;color:#fff;font-weight:700}
</style>
</head>
<body>
<?php include __DIR__ . '/sidebar.php'; ?>
<?php include __DIR__ . '/header.php'; ?>
<div class="content">
    <form class="card" method="post">
        <div class="hdr"><i class="fas fa-pen" style="color:#f97316"></i> Edit PO Item</div>
        <div class="body">
            <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
            <div style="margin-bottom:10px">
                <label>Item Name</label>
                <input type="text" name="item_name" required value="<?= htmlspecialchars((string)$item['item_name']) ?>">
            </div>
            <div style="margin-bottom:10px">
                <label>Description</label>
                <textarea name="description"><?= htmlspecialchars((string)($item['description'] ?? '')) ?></textarea>
            </div>
            <div class="row">
                <div><label>HSN/SAC</label><input type="text" name="hsn_sac" value="<?= htmlspecialchars((string)($item['hsn_sac'] ?? '')) ?>"></div>
                <div><label>Unit</label><input type="text" name="unit" value="<?= htmlspecialchars((string)($item['unit'] ?? '')) ?>"></div>
                <div><label>Rate</label><input type="number" step="0.01" name="rate" value="<?= htmlspecialchars((string)($item['rate'] ?? '0')) ?>"></div>
                <div><label>CGST %</label><input type="number" step="0.01" name="cgst_pct" value="<?= htmlspecialchars((string)($item['cgst_pct'] ?? '0')) ?>"></div>
                <div><label>SGST %</label><input type="number" step="0.01" name="sgst_pct" value="<?= htmlspecialchars((string)($item['sgst_pct'] ?? '0')) ?>"></div>
                <div><label>IGST %</label><input type="number" step="0.01" name="igst_pct" value="<?= htmlspecialchars((string)($item['igst_pct'] ?? '0')) ?>"></div>
            </div>
            <div class="actions">
                <a class="btn" href="po_items.php">Cancel</a>
                <button class="btn btn-save" type="submit" name="save_po_item" value="1">Save Updates</button>
            </div>
        </div>
    </form>
</div>
</body>
</html>

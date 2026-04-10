<?php
require_once __DIR__ . '/db.php';

function ensureMasterPoItems(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS master_po_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(255) NOT NULL,
            description TEXT DEFAULT NULL,
            hsn_sac VARCHAR(50) DEFAULT '',
            unit VARCHAR(50) DEFAULT '',
            rate DECIMAL(15,2) DEFAULT 0.00,
            cgst_pct DECIMAL(5,2) DEFAULT 0.00,
            sgst_pct DECIMAL(5,2) DEFAULT 0.00,
            igst_pct DECIMAL(5,2) DEFAULT 0.00,
            last_source VARCHAR(50) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_master_po_items_name (item_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function insertMasterPoItemIfMissing(PDO $pdo, array $item, string $source): void {
    $name = trim((string)($item['item_name'] ?? ''));
    if ($name === '') {
        return;
    }
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO master_po_items
            (item_name, description, hsn_sac, unit, rate, cgst_pct, sgst_pct, igst_pct, last_source)
        VALUES
            (:item_name, :description, :hsn_sac, :unit, :rate, :cgst_pct, :sgst_pct, :igst_pct, :last_source)
    ");
    $stmt->execute([
        ':item_name' => $name,
        ':description' => trim((string)($item['description'] ?? '')),
        ':hsn_sac' => trim((string)($item['hsn_sac'] ?? '')),
        ':unit' => trim((string)($item['unit'] ?? '')),
        ':rate' => (float)($item['rate'] ?? 0),
        ':cgst_pct' => (float)($item['cgst_pct'] ?? 0),
        ':sgst_pct' => (float)($item['sgst_pct'] ?? 0),
        ':igst_pct' => (float)($item['igst_pct'] ?? 0),
        ':last_source' => $source,
    ]);
}

function backfillMasterPoItems(PDO $pdo): void {
    // 1) Existing master from PO create flow.
    try {
        $rows = $pdo->query("SELECT item_name, description, hsn_sac, unit, rate, cgst_pct, sgst_pct, igst_pct FROM po_master_items")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            insertMasterPoItemIfMissing($pdo, $r, 'po_master_items');
        }
    } catch (Exception $e) {
        // table may not exist; ignore
    }

    // 2) Existing saved purchase orders (items_json).
    try {
        $rows = $pdo->query("SELECT items_json FROM purchase_orders WHERE items_json IS NOT NULL AND items_json <> ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $items = json_decode((string)$r['items_json'], true);
            if (!is_array($items)) continue;
            foreach ($items as $it) {
                insertMasterPoItemIfMissing($pdo, [
                    'item_name' => trim((string)($it['item_name'] ?? $it['description'] ?? '')),
                    'description' => trim((string)($it['description'] ?? '')),
                    'hsn_sac' => trim((string)($it['hsn_sac'] ?? '')),
                    'unit' => trim((string)($it['unit'] ?? '')),
                    'rate' => (float)($it['rate'] ?? 0),
                    'cgst_pct' => (float)($it['cgst_pct'] ?? 0),
                    'sgst_pct' => (float)($it['sgst_pct'] ?? 0),
                    'igst_pct' => (float)($it['igst_pct'] ?? 0),
                ], 'purchase_orders');
            }
        }
    } catch (Exception $e) {
        // table may not exist; ignore
    }

    // 3) Existing saved supplier invoices (items_json).
    try {
        $rows = $pdo->query("SELECT items_json FROM purchases WHERE items_json IS NOT NULL AND items_json <> ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $items = json_decode((string)$r['items_json'], true);
            if (!is_array($items)) continue;
            foreach ($items as $it) {
                $name = trim((string)($it['item_name'] ?? $it['description'] ?? ''));
                insertMasterPoItemIfMissing($pdo, [
                    'item_name' => $name,
                    'description' => trim((string)($it['description'] ?? $name)),
                    'hsn_sac' => trim((string)($it['hsn_sac'] ?? '')),
                    'unit' => trim((string)($it['unit'] ?? '')),
                    'rate' => (float)($it['rate'] ?? 0),
                    'cgst_pct' => (float)($it['cgst_percent'] ?? $it['cgst_pct'] ?? 0),
                    'sgst_pct' => (float)($it['sgst_percent'] ?? $it['sgst_pct'] ?? 0),
                    'igst_pct' => (float)($it['igst_percent'] ?? $it['igst_pct'] ?? 0),
                ], 'supplier_invoices');
            }
        }
    } catch (Exception $e) {
        // table may not exist; ignore
    }
}

ensureMasterPoItems($pdo);
backfillMasterPoItems($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM master_po_items WHERE id = ?");
        $stmt->execute([$id]);
    }
    header("Location: po_items.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_po_item'])) {
    $id = (int)($_POST['id'] ?? 0);
    $itemName = trim((string)($_POST['item_name'] ?? ''));
    if ($id > 0 && $itemName !== '') {
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
    $query = $_GET;
    unset($query['page']);
    $qs = http_build_query($query);
    header('Location: po_items.php' . ($qs ? ('?' . $qs) : ''));
    exit;
}

$_q = trim((string)($_GET['q'] ?? ''));
$_perPageRaw = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$perPage = in_array($_perPageRaw, [10, 25, 50, 100], true) ? $_perPageRaw : 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$where = '';
$params = [];
if ($_q !== '') {
    $where = "WHERE item_name LIKE :q OR description LIKE :q OR hsn_sac LIKE :q OR unit LIKE :q OR last_source LIKE :q";
    $params[':q'] = '%' . $_q . '%';
}
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM master_po_items $where");
$countStmt->execute($params);
$totalItems = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalItems / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT * FROM master_po_items $where ORDER BY updated_at DESC, id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>PO Items</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#f0f2f8;color:#1a1f2e;font-size:13px;}
.content{margin-left:220px;padding:58px 18px 6px 18px;min-height:100vh;display:flex;flex-direction:column;background:#f0f2f8}
.table-card{background:#fff;border-radius:10px;border:1px solid #e8ecf4;box-shadow:0 1px 4px rgba(0,0,0,.04);overflow:hidden;flex:1;display:flex;flex-direction:column;}
.table-card-header{display:flex;align-items:center;justify-content:space-between;padding:6px 14px;border-bottom:1px solid #f0f2f7;background:#fafbfd;flex-wrap:wrap;gap:6px}
.table-card-header h3{font-size:12px;font-weight:800;color:#1a1f2e;text-transform:uppercase;letter-spacing:.5px;display:flex;align-items:center;gap:6px}
.item-count{background:#fff7f0;color:#f97316;border:1px solid #fed7aa;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700}
.controls-row{display:flex;align-items:center;justify-content:space-between;padding:4px 14px;border-bottom:1px solid #f0f2f7;gap:8px;flex-wrap:wrap}
.search-input{padding:5px 10px 5px 30px;border:1.5px solid #e4e8f0;border-radius:7px;font-size:12px;font-family:inherit;background:#fafafa url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='13' height='13' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat 9px center;outline:none;width:300px;transition:border-color .2s}
.search-input:focus{border-color:#f97316;background-color:#fff}
.show-entries{display:flex;align-items:center;gap:6px;font-size:12px;color:#6b7280}
.show-entries select{padding:4px 8px;border:1.5px solid #e4e8f0;border-radius:6px;font-size:12px;font-family:inherit;cursor:pointer;background:#fff;color:#374151;outline:none}
.table-wrap{overflow-x:hidden;flex:1}
table{width:100%;border-collapse:collapse;font-size:11px;table-layout:fixed}
thead tr{background:#fff7f0}
th{padding:4px 5px;text-align:left;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#f97316;border-bottom:2px solid #fed7aa;white-space:nowrap;cursor:pointer;user-select:none}
th:hover{background:#ffeedd;color:#ea6c00}
.sort-icon{font-size:9px;opacity:.5;margin-left:2px}
td{padding:4px 5px;border-bottom:1px solid #f1f5f9;color:#374151;vertical-align:middle;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
tr:last-child td{border-bottom:none}
tbody tr:hover td{background:#fff7f0}
.amount-cell{font-weight:700;color:#15803d;font-variant-numeric:tabular-nums}
.btn-edit{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;color:#16a34a;text-decoration:none;font-size:11px;transition:all .2s}
.btn-edit:hover{background:#16a34a;color:#fff}
.btn-delete{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;color:#dc2626;cursor:pointer;font-size:11px;transition:all .2s}
.btn-delete:hover{background:#dc2626;color:#fff}
.action-cell{display:flex;gap:4px;align-items:center}
.col-item,.col-desc{white-space:normal;overflow:visible;text-overflow:clip}
.pagination{display:flex;justify-content:center;align-items:center;gap:4px;padding:4px 0 2px}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;padding:0 6px;border-radius:6px;font-size:12px;font-weight:600;text-decoration:none;border:1.5px solid #e4e8f0;color:#374151;background:#fff;transition:all .15s}
.pagination a:hover{border-color:#f97316;color:#f97316;background:#fff7f0}
.pagination span.active{background:#f97316;color:#fff;border-color:#f97316}
.pagination span.dots{border:none;background:none;color:#9ca3af}
.pagination span.disabled{border-color:#e4e8f0;color:#d1d5db;background:#fafafa;cursor:default}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center}
.modal-overlay.show{display:flex}
.modal-box{background:#fff;border-radius:12px;width:820px;max-width:94vw;border:1px solid #e8ecf4;box-shadow:0 20px 60px rgba(0,0,0,.2);font-family:'Times New Roman',Times,serif}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f0f2f7;background:#fafbfd}
.modal-title{font-size:13px;font-weight:800}
.modal-close{border:1px solid #e4e8f0;background:#fff;border-radius:50%;width:30px;height:30px;cursor:pointer}
.modal-body{padding:12px}
.grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px}
.grid .full{grid-column:1/-1}
.field label{display:block;font-size:11px;font-weight:700;color:#374151;margin-bottom:4px}
.field input,.field textarea{width:100%;padding:7px 8px;border:1.5px solid #e4e8f0;border-radius:7px;font-size:12.5px;font-family:'Times New Roman',Times,serif}
.field textarea{min-height:52px;resize:none}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:8px}
.btn-action{padding:7px 12px;border:1px solid #e4e8f0;border-radius:7px;background:#fff;cursor:pointer;font-family:'Times New Roman',Times,serif}
.btn-save{background:#16a34a;border-color:#16a34a;color:#fff;font-weight:700}
</style>
</head>
<body>
<?php include __DIR__ . '/sidebar.php'; ?>
<?php include __DIR__ . '/header.php'; ?>
<div class="content">
    <div class="table-card">
        <div class="table-card-header">
            <h3><i class="fas fa-box-open" style="color:#f97316"></i> PO Item List</h3>
            <span class="item-count"><?= $totalItems ?> items</span>
        </div>
        <div class="controls-row">
            <form method="get" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <input id="searchInput" class="search-input" type="text" name="q" placeholder="Search by item, description, HSN, unit, source..." value="<?= htmlspecialchars($_q) ?>">
                <input type="hidden" name="per_page" value="<?= $perPage ?>">
                <button type="submit" style="display:none"></button>
            </form>
            <div class="show-entries">
                Show
                <select id="perPageSelect" onchange="changePerPage(this.value)">
                    <option value="10" <?= $perPage==10?'selected':'' ?>>10</option>
                    <option value="25" <?= $perPage==25?'selected':'' ?>>25</option>
                    <option value="50" <?= $perPage==50?'selected':'' ?>>50</option>
                    <option value="100" <?= $perPage==100?'selected':'' ?>>100</option>
                </select>
                entries
            </div>
        </div>
        <div class="table-wrap">
            <table id="poItemsTable">
                <colgroup>
                    <col style="width:18%">
                    <col style="width:24%">
                    <col style="width:8%">
                    <col style="width:7%">
                    <col style="width:8%">
                    <col style="width:7%">
                    <col style="width:7%">
                    <col style="width:7%">
                    <col style="width:10%">
                    <col style="width:4%">
                </colgroup>
                <thead>
                    <tr>
                        <th onclick="sortTable(0)">Item Name <span class="sort-icon" data-col="0">⇅</span></th>
                        <th onclick="sortTable(1)">Description <span class="sort-icon" data-col="1">⇅</span></th>
                        <th onclick="sortTable(2)">HSN/SAC <span class="sort-icon" data-col="2">⇅</span></th>
                        <th onclick="sortTable(3)">Unit <span class="sort-icon" data-col="3">⇅</span></th>
                        <th onclick="sortTable(4)">Rate <span class="sort-icon" data-col="4">⇅</span></th>
                        <th onclick="sortTable(5)">CGST% <span class="sort-icon" data-col="5">⇅</span></th>
                        <th onclick="sortTable(6)">SGST% <span class="sort-icon" data-col="6">⇅</span></th>
                        <th onclick="sortTable(7)">IGST% <span class="sort-icon" data-col="7">⇅</span></th>
                        <th onclick="sortTable(8)">Source <span class="sort-icon" data-col="8">⇅</span></th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                    <tr>
                        <td class="col-item"><?= htmlspecialchars((string)$it['item_name']) ?></td>
                        <td class="col-desc"><?= htmlspecialchars((string)($it['description'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($it['hsn_sac'] ?? '')) ?></td>
                        <td><?= htmlspecialchars((string)($it['unit'] ?? '')) ?></td>
                        <td class="amount-cell">₹<?= number_format((float)($it['rate'] ?? 0), 2) ?></td>
                        <td><?= number_format((float)($it['cgst_pct'] ?? 0), 2) ?></td>
                        <td><?= number_format((float)($it['sgst_pct'] ?? 0), 2) ?></td>
                        <td><?= number_format((float)($it['igst_pct'] ?? 0), 2) ?></td>
                        <td><?= htmlspecialchars((string)($it['last_source'] ?? '')) ?></td>
                        <td class="action-cell">
                                <button class="btn-edit" type="button" title="Edit"
                                    onclick='openEditModal(<?= json_encode([
                                        'id' => (int)$it['id'],
                                        'item_name' => (string)$it['item_name'],
                                        'description' => (string)($it['description'] ?? ''),
                                        'hsn_sac' => (string)($it['hsn_sac'] ?? ''),
                                        'unit' => (string)($it['unit'] ?? ''),
                                        'rate' => (float)($it['rate'] ?? 0),
                                        'cgst_pct' => (float)($it['cgst_pct'] ?? 0),
                                        'sgst_pct' => (float)($it['sgst_pct'] ?? 0),
                                        'igst_pct' => (float)($it['igst_pct'] ?? 0),
                                        'last_source' => (string)($it['last_source'] ?? ''),
                                        'updated_at' => (string)($it['updated_at'] ?? ''),
                                    ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                                    <i class="fas fa-edit"></i>
                                </button>
                                <form method="post" onsubmit="return confirm('Delete this PO item?')" style="margin:0;">
                                    <input type="hidden" name="delete_id" value="<?= (int)$it['id'] ?>">
                                    <button class="btn-delete" type="submit" title="Delete"><i class="fas fa-trash"></i></button>
                                </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="pagination">
        <?php
        $qs = $_GET;
        $pages = [];
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i <= 3 || $i == $totalPages || abs($i - $page) <= 1) $pages[] = $i;
        }
        $pages = array_unique($pages); sort($pages);
        $qs['page'] = $page - 1;
        echo $page <= 1 ? '<span class="disabled">&laquo;</span>' : "<a href='".htmlspecialchars('?'.http_build_query($qs))."'>&laquo;</a>";
        $prev = null;
        foreach ($pages as $p) {
            if ($prev !== null && $p - $prev > 1) echo '<span class="dots">...</span>';
            $qs['page'] = $p;
            if ($p == $page) echo '<span class="active">'.$p.'</span>';
            else echo "<a href='".htmlspecialchars('?'.http_build_query($qs))."'>$p</a>";
            $prev = $p;
        }
        $qs['page'] = $page + 1;
        echo $page >= $totalPages ? '<span class="disabled">&raquo;</span>' : "<a href='".htmlspecialchars('?'.http_build_query($qs))."'>&raquo;</a>";
        ?>
    </div>
</div>

<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-head">
            <div class="modal-title"><i class="fas fa-pen" style="color:#f97316"></i> Edit PO Item</div>
            <button class="modal-close" type="button" onclick="closeEditModal()">&times;</button>
        </div>
        <form method="post" class="modal-body">
            <input type="hidden" name="id" id="m_id">
            <div class="grid">
                <div class="field">
                    <label>ID</label>
                    <input type="text" id="m_id_view" readonly>
                </div>
                <div class="field" style="grid-column: span 2;">
                    <label>Item Name</label>
                    <input type="text" name="item_name" id="m_item_name" required>
                </div>
                <div class="field">
                    <label>Source</label>
                    <input type="text" id="m_last_source" readonly>
                </div>
                <div class="field">
                    <label>Updated At</label>
                    <input type="text" id="m_updated_at" readonly>
                </div>
                <div class="field"><label>HSN/SAC</label><input type="text" name="hsn_sac" id="m_hsn_sac"></div>
                <div class="field"><label>Unit</label><input type="text" name="unit" id="m_unit"></div>
                <div class="field"><label>Rate</label><input type="number" step="0.01" name="rate" id="m_rate"></div>
                <div class="field"><label>CGST %</label><input type="number" step="0.01" name="cgst_pct" id="m_cgst_pct"></div>
                <div class="field"><label>SGST %</label><input type="number" step="0.01" name="sgst_pct" id="m_sgst_pct"></div>
                <div class="field"><label>IGST %</label><input type="number" step="0.01" name="igst_pct" id="m_igst_pct"></div>
                <div class="field full">
                    <label>Description</label>
                    <textarea name="description" id="m_description"></textarea>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn-action" type="button" onclick="closeEditModal()">Cancel</button>
                <button class="btn-action btn-save" type="submit" name="save_po_item" value="1">Save Updates</button>
            </div>
        </form>
    </div>
</div>
<script>
function changePerPage(val) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', val);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}
let sortDir = {};
function sortTable(col) {
    const table = document.getElementById('poItemsTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const asc = !sortDir[col];
    sortDir = {};
    sortDir[col] = asc;
    document.querySelectorAll('.sort-icon').forEach(el => el.textContent = '⇅');
    const icon = document.querySelector('.sort-icon[data-col="'+col+'"]');
    if (icon) icon.textContent = asc ? '↑' : '↓';
    rows.sort((a, b) => {
        const aText = (a.querySelectorAll('td')[col]?.textContent || '').trim().replace(/[₹,%]/g, '');
        const bText = (b.querySelectorAll('td')[col]?.textContent || '').trim().replace(/[₹,%]/g, '');
        const aNum = parseFloat(aText);
        const bNum = parseFloat(bText);
        if (!isNaN(aNum) && !isNaN(bNum)) return asc ? aNum - bNum : bNum - aNum;
        return asc ? aText.localeCompare(bText) : bText.localeCompare(aText);
    });
    rows.forEach(r => tbody.appendChild(r));
}
function openEditModal(item){
    document.getElementById('m_id').value = item.id || '';
    document.getElementById('m_id_view').value = item.id || '';
    document.getElementById('m_item_name').value = item.item_name || '';
    document.getElementById('m_description').value = item.description || '';
    document.getElementById('m_hsn_sac').value = item.hsn_sac || '';
    document.getElementById('m_unit').value = item.unit || '';
    document.getElementById('m_rate').value = item.rate ?? 0;
    document.getElementById('m_cgst_pct').value = item.cgst_pct ?? 0;
    document.getElementById('m_sgst_pct').value = item.sgst_pct ?? 0;
    document.getElementById('m_igst_pct').value = item.igst_pct ?? 0;
    document.getElementById('m_last_source').value = item.last_source || '';
    document.getElementById('m_updated_at').value = item.updated_at || '';
    document.getElementById('editModal').classList.add('show');
    document.body.style.overflow = 'hidden';
}
function closeEditModal(){
    document.getElementById('editModal').classList.remove('show');
    document.body.style.overflow = '';
}
document.getElementById('editModal').addEventListener('click', function(e){
    if(e.target === this) closeEditModal();
});

// Live global search (server-side across all pages)
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    let searchTimer;
    const currentQ = new URL(window.location.href).searchParams.get('q') || '';
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            const url = new URL(window.location.href);
            const val = this.value.trim();
            if (val === currentQ) return;
            if (val) url.searchParams.set('q', val);
            else url.searchParams.delete('q');
            url.searchParams.set('per_page', document.getElementById('perPageSelect')?.value || '<?= $perPage ?>');
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }, 700);
    });
}
</script>
</body>
</html>

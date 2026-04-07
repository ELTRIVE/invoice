<?php
require_once 'db.php';

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
    $stmt->execute([$_POST['delete_id']]);
    header("Location: items_list.php");
    exit;
}

// Pagination
$_perPageRaw = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$perPage = in_array($_perPageRaw, [10,25,50,100]) ? $_perPageRaw : 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalItems = (int)$pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
$totalPages = max(1, ceil($totalItems / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Fetch paginated items
$stmt = $pdo->prepare("SELECT * FROM items ORDER BY id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// All items for summary totals
$allItems = $pdo->query("SELECT total, qty FROM items")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Stock Items</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f4f6fb;
    color: #1a1f2e;
}

.content { margin-left: 220px; padding: 68px 24px 28px; min-height: 100vh; background: #f4f6fb; transition: margin-left 0.25s ease; }

/* PAGE HEADER */
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    padding-bottom: 14px;
    border-bottom: 1px solid #e4e8f0;
}
.page-header-left { display: flex; align-items: center; gap: 12px; }
.page-icon {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, #16a34a, #15803d);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 16px;
    box-shadow: 0 4px 12px rgba(22,163,74,0.25);
}
.page-title { font-size: 20px; font-weight: 700; color: #1a1f2e; }
.page-sub   { font-size: 12px; color: #9ca3af; margin-top: 2px; }

.btn-add {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px;
    background: linear-gradient(135deg, #16a34a, #15803d);
    color: #fff;
    border: none;
    border-radius: 9px;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: 0 4px 12px rgba(22,163,74,0.25);
}
.btn-add:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(22,163,74,0.35); }

.btn-back {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 16px;
    background: #fff;
    border: 1.5px solid #e4e8f0;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    color: #374151;
    text-decoration: none;
    transition: all 0.2s;
    margin-right: 8px;
}
.btn-back:hover { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }

/* SUMMARY CARDS */
.summary-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 20px;
}
.summary-card {
    background: #fff;
    border-radius: 12px;
    border: 1px solid #e4e8f0;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
}
.summary-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; color: #fff;
    flex-shrink: 0;
}
.summary-num  { font-size: 22px; font-weight: 700; color: #1a1f2e; }
.summary-label{ font-size: 12px; color: #9ca3af; margin-top: 1px; }

/* TABLE CARD */
.table-card {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e4e8f0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    overflow: hidden;
}
.table-card-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px;
    border-bottom: 1px solid #f1f5f9;
    background: #fafbfc;
}
.table-card-header h3 { font-size: 14px; font-weight: 600; color: #374151; }
.item-count {
    background: #f0fdf4;
    color: #16a34a;
    border: 1px solid #bbf7d0;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

/* SEARCH */
.search-wrap {
    padding: 12px 20px;
    border-bottom: 1px solid #f1f5f9;
}
.search-input {
    width: 100%;
    max-width: 340px;
    padding: 8px 12px 8px 34px;
    border: 1.5px solid #e4e8f0;
    border-radius: 8px;
    font-size: 13px;
    background: #fafafa url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat 10px center;
    outline: none;
    transition: border-color 0.2s;
}
.search-input:focus { border-color: #16a34a; background-color: #fff; }

/* TABLE */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead tr { background: #f8fafc; }
th {
    padding: 10px 14px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #6b7280;
    border-bottom: 1px solid #e4e8f0;
    white-space: nowrap;
}
th:hover { background: #f0fdf4; color: #16a34a; }
.sort-icon { font-size: 10px; opacity: 0.6; margin-left: 3px; }
td {
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #374151;
    vertical-align: middle;
}
tr:last-child td { border-bottom: none; }
tr:hover td { background: #fafbff; }

.badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.badge-code { background: #eff6ff; color: #2563eb; }
.badge-hsn  { background: #fef9c3; color: #a16207; }

.amount-cell { font-weight: 600; color: #15803d; font-family: monospace; }

.btn-delete {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px;
    background: #fef2f2;
    border: 1px solid #fca5a5;
    border-radius: 7px;
    color: #dc2626;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.2s;
}
.btn-delete:hover { background: #dc2626; color: #fff; }

/* EMPTY STATE */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #9ca3af;
}
.empty-state i { font-size: 40px; margin-bottom: 12px; color: #d1d5db; }
.empty-state p { font-size: 14px; }
.empty-state a { color: #16a34a; font-weight: 600; text-decoration: none; }
.pagination{display:flex;justify-content:center;align-items:center;gap:5px;padding:16px 20px}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;
  min-width:32px;height:32px;padding:0 8px;border-radius:7px;font-size:13px;font-weight:600;
  text-decoration:none;border:1.5px solid #e4e8f0;color:#374151;background:#fff;transition:all .15s}
.pagination a:hover{border-color:#16a34a;color:#16a34a;background:#f0fdf4}
.pagination span.active{background:#16a34a;color:#fff;border-color:#16a34a}
.pagination span.dots{border:none;background:none;color:#9ca3af}
.pagination span.disabled{border-color:#e4e8f0;color:#d1d5db;background:#fafafa;cursor:default}

@media (max-width: 768px) {
    .content { margin-left: 80px; padding: 8px 14px 20px; }
    .summary-row { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<?php include 'sidebar.php'; ?>
<?php include 'header.php'; ?>

<div class="content">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon"><i class="fas fa-boxes"></i></div>
            <div>
                <div class="page-title">Stock Items</div>
                <div class="page-sub">All items in your inventory</div>
            </div>
        </div>
        <div>
            <a href="javascript:history.back()" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <a href="add_stock.php" class="btn-add">
                <i class="fas fa-plus"></i> Add Stock
            </a>
        </div>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="summary-row">
        <div class="summary-card">
            <div class="summary-icon" style="background:linear-gradient(135deg,#16a34a,#15803d)">
                <i class="fas fa-boxes"></i>
            </div>
            <div>
                <div class="summary-num"><?= $totalItems ?></div>
                <div class="summary-label">Total Items</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb)">
                <i class="fas fa-rupee-sign"></i>
            </div>
            <div>
                <div class="summary-num">₹<?= number_format(array_sum(array_column($allItems, 'total')), 2) ?></div>
                <div class="summary-label">Total Value</div>
            </div>
        </div>
        <div class="summary-card">
            <div class="summary-icon" style="background:linear-gradient(135deg,#f97316,#fb923c)">
                <i class="fas fa-cubes"></i>
            </div>
            <div>
                <div class="summary-num"><?= number_format(array_sum(array_column($allItems, 'qty')), 0) ?></div>
                <div class="summary-label">Total Qty</div>
            </div>
        </div>
    </div>

    <!-- TABLE CARD -->
    <div class="table-card">
        <div class="table-card-header" style="flex-wrap:wrap;gap:10px">
            <h3><i class="fas fa-list" style="margin-right:8px;color:#16a34a"></i>Item List</h3>
            <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
                <div style="display:flex;align-items:center;gap:6px;font-size:13px;color:#374151">
                    <label for="perPageSelect" style="white-space:nowrap;font-weight:600;margin:0">Show</label>
                    <select id="perPageSelect" onchange="changePerPage(this.value)" style="padding:4px 8px;border:1.5px solid #e4e8f0;border-radius:7px;font-size:13px;cursor:pointer;background:#fff;color:#374151">
                        <option value="10" <?= $perPage==10?'selected':'' ?>>10</option>
                        <option value="25" <?= $perPage==25?'selected':'' ?>>25</option>
                        <option value="50" <?= $perPage==50?'selected':'' ?>>50</option>
                        <option value="100" <?= $perPage==100?'selected':'' ?>>100</option>
                    </select>
                    <span style="white-space:nowrap">entries</span>
                </div>
                <span class="item-count"><?= $totalItems ?> items</span>
            </div>
        </div>

        <div class="search-wrap">
            <input type="text" class="search-input" id="searchInput" placeholder="Search by code, description, HSN...">
        </div>

        <div class="table-wrap">
            <?php if (empty($items)): ?>
            <div class="empty-state">
                <i class="fas fa-box-open"></i>
                <p>No stock items found. <a href="add_stock.php">Add your first item</a></p>
            </div>
            <?php else: ?>
            <table id="itemsTable">
                <thead>
                    <tr>
                        <th onclick="sortTable(0)" style="cursor:pointer;user-select:none"># <span class="sort-icon" data-col="0">⇅</span></th>
                        <th onclick="sortTable(1)" style="cursor:pointer;user-select:none">Service Code <span class="sort-icon" data-col="1">⇅</span></th>
                        <th onclick="sortTable(2)" style="cursor:pointer;user-select:none">HSN/SAC <span class="sort-icon" data-col="2">⇅</span></th>
                        <th onclick="sortTable(3)" style="cursor:pointer;user-select:none">Description <span class="sort-icon" data-col="3">⇅</span></th>
                        <th onclick="sortTable(4)" style="cursor:pointer;user-select:none">UOM <span class="sort-icon" data-col="4">⇅</span></th>
                        <th onclick="sortTable(5)" style="cursor:pointer;user-select:none">Qty <span class="sort-icon" data-col="5">⇅</span></th>
                        <th onclick="sortTable(6)" style="cursor:pointer;user-select:none">Unit Price <span class="sort-icon" data-col="6">⇅</span></th>
                        <th onclick="sortTable(7)" style="cursor:pointer;user-select:none">Discount% <span class="sort-icon" data-col="7">⇅</span></th>
                        <th onclick="sortTable(8)" style="cursor:pointer;user-select:none">SGST% <span class="sort-icon" data-col="8">⇅</span></th>
                        <th onclick="sortTable(9)" style="cursor:pointer;user-select:none">CGST% <span class="sort-icon" data-col="9">⇅</span></th>
                        <th onclick="sortTable(10)" style="cursor:pointer;user-select:none">IGST% <span class="sort-icon" data-col="10">⇅</span></th>
                        <th onclick="sortTable(11)" style="cursor:pointer;user-select:none">Total <span class="sort-icon" data-col="11">⇅</span></th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $i => $item): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><span class="badge badge-code"><?= htmlspecialchars($item['service_code'] ?? '-') ?></span></td>
                    <td><span class="badge badge-hsn"><?= htmlspecialchars($item['hsn_sac'] ?? '-') ?></span></td>
                    <td style="max-width:220px;white-space:normal"><?= htmlspecialchars($item['material_description'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($item['uom'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($item['qty'] ?? 0) ?></td>
                    <td class="amount-cell">₹<?= number_format($item['unit_price'] ?? 0, 2) ?></td>
                    <td><?= htmlspecialchars($item['discount_percent'] ?? 0) ?>%</td>
                    <td><?= htmlspecialchars($item['sgst'] ?? 0) ?>%</td>
                    <td><?= htmlspecialchars($item['cgst'] ?? 0) ?>%</td>
                    <td><?= htmlspecialchars($item['igst'] ?? 0) ?>%</td>
                    <td class="amount-cell">₹<?= number_format($item['total'] ?? 0, 2) ?></td>
                    <td style="display:flex;gap:5px;align-items:center;">
                        <a href="edit_stock.php?edit_id=<?= $item['id'] ?>" class="btn-edit" style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;color:#16a34a;text-decoration:none;" title="Edit">
                            <i class="fas fa-edit"></i>
                        </a>
                        <form method="post" onsubmit="return confirm('Delete this item?')" style="margin:0;">
                            <input type="hidden" name="delete_id" value="<?= $item['id'] ?>">
                            <button type="submit" class="btn-delete" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- PAGINATION -->
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
            if ($prev !== null && $p - $prev > 1) echo '<span class="dots">\u2026</span>';
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

</div>

<script>
// Search
document.getElementById('searchInput').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#itemsTable tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

// Per-page
function changePerPage(val) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', val);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

// Sort
let sortDir = {};
function sortTable(col) {
    const table = document.getElementById('itemsTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const asc = !sortDir[col];
    sortDir = {};
    sortDir[col] = asc;

    // Update icons
    document.querySelectorAll('.sort-icon').forEach(el => el.textContent = '⇅');
    const icon = document.querySelector('.sort-icon[data-col="'+col+'"]');
    if (icon) icon.textContent = asc ? '↑' : '↓';

    rows.sort((a, b) => {
        const aText = (a.querySelectorAll('td')[col]?.textContent || '').trim().replace(/[₹,%]/g, '');
        const bText = (b.querySelectorAll('td')[col]?.textContent || '').trim().replace(/[₹,%]/g, '');
        const aNum = parseFloat(aText);
        const bNum = parseFloat(bText);
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return asc ? aNum - bNum : bNum - aNum;
        }
        return asc ? aText.localeCompare(bText) : bText.localeCompare(aText);
    });

    rows.forEach(r => tbody.appendChild(r));
}
</script>
</body>
</html>
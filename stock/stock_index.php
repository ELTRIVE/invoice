<?php
require_once dirname(__DIR__) . '/db.php';

// ── Auto-create tables ────────────────────────────────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS stock_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    item_name     VARCHAR(255) NOT NULL,
    hsn_code      VARCHAR(50)  NOT NULL DEFAULT '',
    created_by    VARCHAR(255) NOT NULL DEFAULT '',
    incoming_qty  DECIMAL(15,3) NOT NULL DEFAULT 0,
    outgoing_qty  DECIMAL(15,3) NOT NULL DEFAULT 0,
    remaining_qty DECIMAL(15,3) NOT NULL DEFAULT 0,
    incoming_date DATE NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS stock_incoming (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    stock_item_id INT NOT NULL,
    person_name   VARCHAR(255) NOT NULL,
    quantity      DECIMAL(15,3) NOT NULL DEFAULT 0,
    incoming_date DATE NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS stock_outgoing (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    stock_item_id INT NOT NULL,
    person_name   VARCHAR(255) NOT NULL,
    quantity      DECIMAL(15,3) NOT NULL DEFAULT 0,
    outgoing_date DATE NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (stock_item_id) REFERENCES stock_items(id) ON DELETE CASCADE
)");

// ── Migrate: add responsible_person_id columns if not exist ───────────────────
try { $pdo->exec("ALTER TABLE stock_items ADD COLUMN responsible_person_id INT NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE stock_incoming ADD COLUMN responsible_person_id INT NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE stock_incoming ADD COLUMN location VARCHAR(255) NOT NULL DEFAULT ''"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE stock_incoming ADD COLUMN description TEXT NULL DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE stock_outgoing ADD COLUMN location VARCHAR(255) NOT NULL DEFAULT ''"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE stock_outgoing ADD COLUMN description TEXT NULL DEFAULT NULL"); } catch (Exception $e) {}
// Ensure stock_persons table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS stock_persons (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

// ── AJAX: get HSN dropdown list ───────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'hsn_list') {
    header('Content-Type: application/json');
    $rows = $pdo->query("SELECT DISTINCT hsn_code FROM stock_items WHERE hsn_code != '' ORDER BY hsn_code ASC")->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($rows);
    exit;
}

// ── AJAX: get stock persons list ──────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'executives') {
    header('Content-Type: application/json');
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_persons (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $rows = $pdo->query("SELECT id, name FROM stock_persons ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

// ── AJAX: add new responsible person ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_add_person'])) {
    header('Content-Type: application/json');
    $name = trim($_POST['name'] ?? '');
    if (!$name) { echo json_encode(['success' => false, 'message' => 'Name is required.']); exit; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_persons (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $stmt = $pdo->prepare("INSERT INTO stock_persons (name) VALUES (?)");
        $stmt->execute([$name]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'name' => $name]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: filter by HSN ───────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'filter_hsn') {
    header('Content-Type: application/json');
    $hsn = trim($_GET['hsn'] ?? '');
    if ($hsn === '') {
        $rows = $pdo->query("SELECT * FROM stock_items ORDER BY created_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $st = $pdo->prepare("SELECT * FROM stock_items WHERE hsn_code = ? ORDER BY created_at DESC");
        $st->execute([$hsn]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    ob_start();
    if (empty($rows)) {
        echo '<tr><td colspan="8" style="text-align:center;padding:40px;color:#9ca3af;">No stock items found.</td></tr>';
    } else {
        foreach ($rows as $s) { echo _stock_row($s); }
    }
    echo json_encode(['html' => ob_get_clean(), 'count' => count($rows)]);
    exit;
}

// ── AJAX: add incoming to existing item ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_add_incoming'])) {
    header('Content-Type: application/json');
    $id           = (int)($_POST['stock_item_id'] ?? 0);
    $person_name  = trim($_POST['person_name']    ?? '');
    $quantity     = floatval($_POST['quantity']    ?? 0);
    $incoming_date= trim($_POST['incoming_date']   ?? '');
    $responsible_person_id = (int)($_POST['responsible_person_id'] ?? 0) ?: null;
    $location     = trim($_POST['location']        ?? '');
    $description  = trim($_POST['description']     ?? '');

    if (!$id || !$person_name || $quantity <= 0 || !$incoming_date) {
        echo json_encode(['success' => false, 'message' => 'All fields are required and quantity must be > 0.']);
        exit;
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO stock_incoming (stock_item_id, person_name, quantity, incoming_date, responsible_person_id, location, description)
            VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$id, $person_name, $quantity, $incoming_date, $responsible_person_id, $location, $description]);
        $pdo->prepare("UPDATE stock_items SET
            incoming_qty  = incoming_qty  + ?,
            remaining_qty = remaining_qty + ?
            WHERE id = ?")->execute([$quantity, $quantity, $id]);
        $pdo->commit();
        $updated = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
        $updated->execute([$id]);
        echo json_encode(['success' => true, 'item' => $updated->fetch(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: get incoming log ────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_incoming') {
    header('Content-Type: application/json');
    $id   = (int)($_GET['id'] ?? 0);
    $rows = $pdo->prepare("
        SELECT si.*, sp.name AS responsible_name
        FROM stock_incoming si
        LEFT JOIN stock_persons sp ON sp.id = si.responsible_person_id
        WHERE si.stock_item_id = ?
        ORDER BY si.incoming_date ASC
    ");
    $rows->execute([$id]);
    echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── AJAX: get outgoing log ────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_outgoing') {
    header('Content-Type: application/json');
    $id   = (int)($_GET['id'] ?? 0);
    $rows = $pdo->prepare("SELECT id, outgoing_date, person_name, quantity, location, description FROM stock_outgoing WHERE stock_item_id = ? ORDER BY outgoing_date ASC");
    $rows->execute([$id]);
    echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ── AJAX: save outgoing update ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    header('Content-Type: application/json');
    $id           = (int)($_POST['stock_item_id'] ?? 0);
    $person_name  = trim($_POST['person_name']    ?? '');
    $quantity     = floatval($_POST['quantity']    ?? 0);
    $outgoing_date= trim($_POST['outgoing_date']   ?? '');
    $location     = trim($_POST['location']        ?? '');
    $description  = trim($_POST['description']     ?? '');

    if (!$id || !$person_name || $quantity <= 0 || !$outgoing_date) {
        echo json_encode(['success' => false, 'message' => 'All fields are required and quantity must be > 0.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // Check remaining stock
        $item = $pdo->prepare("SELECT remaining_qty FROM stock_items WHERE id = ?");
        $item->execute([$id]);
        $row = $item->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['success' => false, 'message' => 'Stock item not found.']); $pdo->rollBack(); exit; }
        if ($quantity > floatval($row['remaining_qty'])) {
            echo json_encode(['success' => false, 'message' => 'Outgoing quantity exceeds remaining stock (' . floatval($row['remaining_qty']) . ').']);
            $pdo->rollBack(); exit;
        }

        // Insert outgoing log
        $pdo->prepare("INSERT INTO stock_outgoing (stock_item_id, person_name, quantity, outgoing_date, location, description)
            VALUES (?, ?, ?, ?, ?, ?)")->execute([$id, $person_name, $quantity, $outgoing_date, $location, $description]);

        // Update stock_items totals
        $pdo->prepare("UPDATE stock_items SET
            outgoing_qty  = outgoing_qty  + ?,
            remaining_qty = remaining_qty - ?
            WHERE id = ?")->execute([$quantity, $quantity, $id]);

        $pdo->commit();

        // Return updated row
        $updated = $pdo->prepare("SELECT * FROM stock_items WHERE id = ?");
        $updated->execute([$id]);
        echo json_encode(['success' => true, 'item' => $updated->fetch(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── AJAX: live search ─────────────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search') {
    header('Content-Type: application/json');
    $q        = trim($_GET['search'] ?? '');
    $per      = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;
    $pg       = max(1, (int)($_GET['page'] ?? 1));
    $where    = ['1=1']; $params = [];
    if ($q !== '') {
        $where[] = '(item_name LIKE ? OR hsn_code LIKE ? OR created_by LIKE ?)';
        $params  = ["%$q%", "%$q%", "%$q%"];
    }
    $wsql = implode(' AND ', $where);
    $cs = $pdo->prepare("SELECT COUNT(*) FROM stock_items WHERE $wsql");
    $cs->execute($params); $cnt = (int)$cs->fetchColumn();
    $total_pages = max(1, (int)ceil($cnt / $per));
    if ($pg > $total_pages) $pg = $total_pages;
    $offset = ($pg - 1) * $per;
    $rs = $pdo->prepare("SELECT * FROM stock_items WHERE $wsql ORDER BY created_at DESC LIMIT $per OFFSET $offset");
    $rs->execute($params);
    $rows = $rs->fetchAll(PDO::FETCH_ASSOC);
    ob_start();
    if (empty($rows)) {
        echo '<tr><td colspan="8" style="text-align:center;padding:40px;color:#9ca3af;">No stock items found.</td></tr>';
    } else {
        foreach ($rows as $s) { echo _stock_row($s); }
    }
    $html = ob_get_clean();
    echo json_encode(['html' => $html, 'count' => $cnt, 'total_pages' => $total_pages, 'cur_page' => $pg]);
    exit;
}

// ── Helper: format quantity (int if whole, else trimmed decimal) ──────────────
function fmtNum($n) {
    $v = floatval($n);
    return ($v == intval($v)) ? (string)intval($v) : rtrim(rtrim(number_format($v, 3), '0'), '.');
}

// ── Helper: render a single table row ────────────────────────────────────────
function _stock_row($s) {
    global $pdo;
    // Fetch responsible person name via JOIN
    $responsible_name = '';
    if (!empty($s['responsible_person_id'])) {
        try {
            $r = $pdo->prepare("SELECT name FROM stock_persons WHERE id = ?");
            $r->execute([$s['responsible_person_id']]);
            $responsible_name = $r->fetchColumn() ?: '';
        } catch (Exception $e) {}
    }
    $rem = floatval($s['remaining_qty']);
    $remCls = $rem <= 0 ? 'qty-zero' : ($rem < 10 ? 'qty-low' : 'qty-ok');
    $obj = htmlspecialchars(json_encode($s));
    ob_start(); ?>
<tr class="stock-row" data-obj='<?= $obj ?>'>
    <td><strong><?= htmlspecialchars($s['created_by']) ?></strong></td>
    <td><?= htmlspecialchars($s['item_name']) ?></td>
    <td style="color:#6b7280;font-size:13px"><?= htmlspecialchars($s['hsn_code'] ?: '—') ?></td>
    <td>
        <span class="qty-pill qty-in clickable" onclick="showIncoming(<?= $s['id'] ?>, '<?= htmlspecialchars($s['item_name']) ?>')" title="Click to view incoming log">
            <?= fmtNum($s['incoming_qty']) ?>
            <i class="fas fa-info-circle" style="font-size:10px;margin-left:3px;opacity:.6"></i>
        </span>
    </td>
    <td>
        <span class="qty-pill qty-out clickable" onclick="showOutgoing(<?= $s['id'] ?>, '<?= htmlspecialchars($s['item_name']) ?>')" title="Click to view outgoing log">
            <?= fmtNum($s['outgoing_qty']) ?>
            <i class="fas fa-info-circle" style="font-size:10px;margin-left:3px;opacity:.6"></i>
        </span>
    </td>
    <td><span class="qty-pill <?= $remCls ?>"><?= fmtNum($rem) ?></span></td>
    <td style="color:#374151;font-size:13px"><?= $responsible_name ? htmlspecialchars($responsible_name) : '<span style="color:#9ca3af">—</span>' ?></td>
    <td onclick="event.stopPropagation()">
        <button class="action-btn btn-update" onclick="openUpdateModal(<?= $s['id'] ?>, '<?= htmlspecialchars($s['item_name']) ?>', <?= floatval($s['remaining_qty']) ?>)" title="Record Outgoing / Add Incoming">
            <i class="fas fa-exchange-alt"></i> Update
        </button>
    </td>
</tr>
    <?php return ob_get_clean();
}

// ── Fetch stock list ──────────────────────────────────────────────────────────
$search   = trim($_GET['search'] ?? '');
$per_page = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;
$cur_page = max(1, (int)($_GET['page'] ?? 1));
$sort_col_st = $_GET['sort_col'] ?? '';
$sort_dir_st = $_GET['sort_dir'] ?? 'asc';
$allowed_sorts_st = [
    'created_by'    => 'created_by',
    'item_name'     => 'item_name',
    'hsn_code'      => 'hsn_code',
    'incoming_qty'  => 'incoming_qty',
    'outgoing_qty'  => 'outgoing_qty',
    'remaining_qty' => 'remaining_qty',
];
$order_sql_st = 'created_at DESC';
if ($sort_col_st && isset($allowed_sorts_st[$sort_col_st])) {
    $sdir_st = ($sort_dir_st === 'desc') ? 'DESC' : 'ASC';
    $order_sql_st = $allowed_sorts_st[$sort_col_st] . ' ' . $sdir_st . ', created_at DESC';
}
$where    = ['1=1']; $params = [];
if ($search !== '') {
    $where[] = '(item_name LIKE :s OR hsn_code LIKE :s OR created_by LIKE :s)';
    $params[':s'] = "%$search%";
}
$wsql = implode(' AND ', $where);
$cnt_stmt = $pdo->prepare("SELECT COUNT(*) FROM stock_items WHERE $wsql");
$cnt_stmt->execute($params);
$count       = (int)$cnt_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($count / $per_page));
if ($cur_page > $total_pages) $cur_page = $total_pages;
$offset = ($cur_page - 1) * $per_page;
$stmt   = $pdo->prepare("SELECT * FROM stock_items WHERE $wsql ORDER BY $order_sql_st LIMIT $per_page OFFSET $offset");
$stmt->execute($params);
$items  = $stmt->fetchAll(PDO::FETCH_ASSOC);

function pageUrl($pg, $sr, $pp=10, $sc='', $sd='asc') { return '?' . http_build_query(['search' => $sr, 'page' => $pg, 'per_page' => $pp, 'sort_col' => $sc, 'sort_dir' => $sd]); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Stock – Eltrive</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Times New Roman', Times, serif; background: #f4f6fb; color: #1a1f2e; }
.content { margin-left: 220px; padding: 68px 28px 40px; min-height: 100vh; }

/* ── Header ── */
.header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
h2 { font-size: 22px; font-weight: 700; color: #1a1f2e; }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.btn-create {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 18px; border-radius: 8px;
    background: #f97316; color: #fff; text-decoration: none;
    font-size: 14px; font-weight: 600; border: none; cursor: pointer;
    font-family: 'Times New Roman', Times, serif; transition: background .2s;
}
.btn-create:hover { background: #fb923c; }

/* ── Search ── */
.search-wrap { position: relative; width: 230px; }
.search-wrap .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 13px; pointer-events: none; }
.search-wrap input[type=text] {
    width: 100%; padding: 7px 28px 7px 34px;
    border: 1.5px solid #d1d5db; border-radius: 50px;
    font-size: 12.5px; font-family: 'Times New Roman', Times, serif;
    color: #374151; background: #fff; outline: none;
    box-shadow: 0 1px 3px rgba(0,0,0,.06); transition: border-color .2s, box-shadow .2s;
}
.search-wrap input:focus { border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(147,197,253,.2); }
.search-wrap input::placeholder { color: #9ca3af; font-size: 12px; }

/* ── Stat pill ── */
.summary-row { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
.sum-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 16px; border-radius: 8px; border: 1.5px solid;
    background: #fff; font-size: 13px; color: #374151; white-space: nowrap;
}
.sum-pill .label { color: #6b7280; }
.sum-pill .val   { font-weight: 700; }
.sum-pill.orange { border-color: #f97316; } .sum-pill.orange .val { color: #f97316; }
.sum-pill.green  { border-color: #16a34a; } .sum-pill.green  .val { color: #16a34a; }
.sum-pill.red    { border-color: #dc2626; } .sum-pill.red    .val { color: #dc2626; }

/* ── Card / table ── */
.stock-card { background: #fff; border-radius: 14px; padding: 20px; border: 1px solid #e4e8f0; }
.stock-table { width: 100%; border-collapse: collapse; }
.stock-table thead tr { background: #fff; }
.stock-table th {
    text-align: left; font-size: 12px; text-transform: uppercase;
    letter-spacing: .05em; color: #6b7280; padding: 0 12px 12px 0; font-weight: 700;
}
.stock-table tbody tr { cursor: default; transition: background .15s; }
.stock-table tbody tr:hover { background: #fff7f0; }
.stock-table td { padding: 13px 12px 13px 0; border-top: 1px solid #f1f5f9; font-size: 13px; }

/* ── Qty pills ── */
.qty-pill {
    display: inline-flex; align-items: center;
    padding: 4px 12px; border-radius: 20px;
    font-size: 12px; font-weight: 700; white-space: nowrap;
}
.qty-in  { background: #dcfce7; color: #16a34a; border: 1px solid #bbf7d0; }
.qty-out { background: #fff7ed; color: #f97316; border: 1px solid #fed7aa; }
.qty-ok  { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
.qty-low { background: #fef9c3; color: #ca8a04; border: 1px solid #fde68a; }
.qty-zero{ background: #fee2e2; color: #dc2626; border: 1px solid #fecaca; }
.clickable { cursor: pointer; transition: opacity .15s; }
.clickable:hover { opacity: .8; }

/* ── Update button ── */
.action-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 700;
    border: none; cursor: pointer; font-family: 'Times New Roman', Times, serif; transition: all .2s;
}
.btn-update { background: #f97316; color: #fff; }
.btn-update:hover { background: #fb923c; }

/* ── Pagination ── */
.pagination { display: flex; justify-content: center; align-items: center; gap: 4px; padding: 20px 0 8px; }
.pagination a, .pagination span {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 34px; height: 34px; padding: 0 10px; border-radius: 8px;
    font-size: 13px; font-weight: 600; text-decoration: none;
    border: 1.5px solid #e4e8f0; color: #374151; background: #fff; transition: all .15s;
    cursor: pointer;
}
.pagination a:hover { border-color: #f97316; color: #f97316; background: #fff7f0; }
.pagination span.active { background: #16a34a; color: #fff; border-color: #16a34a; }
.pagination span.dots   { border: none; background: none; color: #9ca3af; min-width: 20px; }
.pagination span.disabled { opacity: .4; cursor: not-allowed; pointer-events: none; }

/* ══ MODALS ══ */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.38); backdrop-filter: blur(3px);
    z-index: 2000; align-items: center; justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: #fff; border-radius: 16px; width: 420px; max-width: 95vw; max-height: 90vh;
    overflow: hidden; display: flex; flex-direction: column;
    box-shadow: 0 16px 48px rgba(0,0,0,.15);
    font-family: 'Times New Roman', Times, serif;
}
.modal-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    padding: 20px 22px 14px; border-bottom: 1px solid #e4e8f0; background: #fafbfc;
}
.modal-title  { font-size: 16px; font-weight: 800; color: #1a1f2e; }
.modal-sub    { font-size: 12px; color: #9ca3af; margin-top: 3px; }
.modal-close  {
    background: none; border: none; font-size: 18px; color: #9ca3af;
    cursor: pointer; width: 28px; height: 28px; display: flex;
    align-items: center; justify-content: center; border-radius: 50%; flex-shrink: 0;
}
.modal-close:hover { background: #f1f5f9; color: #374151; }
.modal-body   { padding: 18px 22px; overflow-y: auto; flex: 1; }
.modal-footer { padding: 14px 22px; border-top: 1px solid #e4e8f0; display: flex; gap: 10px; }

/* log table inside modal */
.log-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.log-table th {
    text-align: left; color: #9ca3af; font-size: 11px; text-transform: uppercase;
    letter-spacing: .05em; padding-bottom: 8px; font-weight: 700;
}
.log-table td { padding: 9px 0; border-top: 1px solid #f1f5f9; color: #374151; vertical-align: top; }
.log-table td:last-child { text-align: right; font-weight: 700; }

/* update form fields */
.upd-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
.upd-field label { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; }
.upd-field input, .upd-field textarea, .upd-field select {
    width: 100%; padding: 9px 12px; border: 1.5px solid #e4e8f0; border-radius: 8px;
    font-size: 13px; font-family: 'Times New Roman', Times, serif; color: #1a1f2e; outline: none;
    transition: border-color .2s; background: #fff;
}
.upd-field textarea { resize: vertical; min-height: 64px; }
.upd-field input:focus, .upd-field textarea:focus, .upd-field select:focus { border-color: #f97316; box-shadow: 0 0 0 3px rgba(249,115,22,.1); }
.remaining-note {
    font-size: 12px; color: #6b7280; background: #f8fafc;
    border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 12px;
    margin-bottom: 16px; display: flex; align-items: center; gap: 6px;
}
.remaining-note strong { color: #0369a1; }

.btn-confirm {
    flex: 1; background: #f97316; color: #fff; border: none; border-radius: 10px;
    padding: 11px; font-size: 13px; font-weight: 700; cursor: pointer;
    font-family: 'Times New Roman', Times, serif; transition: background .2s;
    display: flex; align-items: center; justify-content: center; gap: 7px;
}
.btn-confirm:hover { background: #fb923c; }
.btn-confirm:disabled { background: #9ca3af; cursor: not-allowed; }
.btn-cancel {
    flex: 1; background: #f1f5f9; color: #374151; border: 1.5px solid #e2e8f0;
    border-radius: 10px; padding: 11px; font-size: 13px; font-weight: 700; cursor: pointer;
    font-family: 'Times New Roman', Times, serif; transition: all .2s;
}
.btn-cancel:hover { border-color: #f97316; color: #f97316; }

/* ── Toast ── */
#toast {
    position: fixed; top: 24px; left: 50%; transform: translateX(-50%);
    padding: 13px 28px; border-radius: 10px;
    font-size: 13px; font-weight: 600; display: none; align-items: center; gap: 8px;
    z-index: 9999; box-shadow: 0 8px 24px rgba(0,0,0,.18);
    white-space: nowrap;
}
#toast.show  { display: flex; }
#toast.success { background: #16a34a; color: #fff; }
#toast.error   { background: #dc2626; color: #fff; }

/* ── Sort headers ── */
.sort-th { white-space:nowrap; cursor:pointer; user-select:none; }
.sort-th:hover { color:#f97316; }
.sort-th .si { font-size:10px; color:#d1d5db; margin-left:4px; }
.sort-th.asc .si, .sort-th.desc .si { color:#f97316; }
/* Show entries */
.show-entries { display:flex; align-items:center; gap:8px; font-size:13px; color:#374151; margin-bottom:10px; }
.show-entries select { padding:5px 10px; border:1.5px solid #e2e8f0; border-radius:7px; font-size:13px;
    font-family:'Times New Roman',Times,serif; color:#374151; background:#fff; outline:none; cursor:pointer; }
.show-entries select:focus { border-color:#f97316; }
::-webkit-scrollbar { width: 3px; }
::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 99px; }
</style>
</head>
<body>

<?php include dirname(__DIR__) . '/sidebar.php'; ?>
<?php include dirname(__DIR__) . '/header.php'; ?>

<div class="content">

    <!-- TOP BAR -->
    <div class="header-bar">
        <h2><i class="fas fa-boxes" style="color:#f97316;margin-right:8px"></i>Stock</h2>
        <div class="topbar-right">
            <div class="search-wrap">
                <span class="search-icon">🔍</span>
                <input type="text" id="liveSearch" placeholder="Search item, person, HSN…"
                       value="<?= htmlspecialchars($search) ?>"
                       oninput="ajaxSearch(this.value)" autocomplete="off">
            </div>
            <a href="stock_create.php" class="btn-create"><i class="fas fa-plus"></i> Add Stock</a>
        </div>
    </div>

    <!-- SUMMARY -->
    <div class="summary-row">
        <div class="sum-pill orange">
            <span class="label">Total Items</span>
            <span class="val" id="totalCount"><?= $count ?></span>
        </div>
        <select id="hsnFilter" onchange="filterByHsn(this.value)" style="
            padding:6px 14px; border:1.5px solid #f97316; border-radius:8px;
            font-size:12.5px; font-family:'Times New Roman',Times,serif;
            color:#374151; background:#fff; outline:none; cursor:pointer;
            box-shadow:0 1px 3px rgba(0,0,0,.06); min-width:150px;">
            <option value="">All HSN Codes</option>
        </select>
    </div>

    <!-- TABLE -->
    <div class="stock-card">
        <?php
        function stThSort($col, $label, $sort_col, $sort_dir, $get) {
            $active  = $sort_col === $col;
            $nextDir = ($active && $sort_dir === 'asc') ? 'desc' : 'asc';
            $qs = $get; $qs['sort_col'] = $col; $qs['sort_dir'] = $nextDir; unset($qs['page']);
            $url  = '?' . http_build_query($qs);
            $cls  = $active ? $sort_dir : '';
            $icon = $active ? ($sort_dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
            return '<th class="sort-th '.$cls.'" onclick="location.href=\''.htmlspecialchars($url,ENT_QUOTES).'\'">'
                 . $label . '<i class="fas '.$icon.' si"></i></th>';
        }
        ?>
        <div class="show-entries">
            Show
            <form method="GET" id="ppForm" style="display:inline">
                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="sort_col" value="<?= htmlspecialchars($sort_col_st) ?>">
                <input type="hidden" name="sort_dir" value="<?= htmlspecialchars($sort_dir_st) ?>">
                <select name="per_page" onchange="this.form.submit();">
                    <?php foreach([10,25,50,100] as $n): ?>
                    <option value="<?=$n?>" <?=$per_page==$n?'selected':''?>><?=$n?></option>
                    <?php endforeach; ?>
                </select>
            </form>
            entries
        </div>
        <table class="stock-table">
            <thead>
                <tr>
                    <?=stThSort('created_by',   'Person',       $sort_col_st,$sort_dir_st,$_GET)?>
                    <?=stThSort('item_name',     'Item Name',    $sort_col_st,$sort_dir_st,$_GET)?>
                    <?=stThSort('hsn_code',      'HSN Code',     $sort_col_st,$sort_dir_st,$_GET)?>
                    <?=stThSort('incoming_qty',  'Incoming Qty', $sort_col_st,$sort_dir_st,$_GET)?>
                    <?=stThSort('outgoing_qty',  'Outgoing Qty', $sort_col_st,$sort_dir_st,$_GET)?>
                    <?=stThSort('remaining_qty', 'Remaining',    $sort_col_st,$sort_dir_st,$_GET)?>
                    <th>Responsible</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody id="stockTbody">
            <?php if (empty($items)): ?>
                <tr><td colspan="8" style="text-align:center;padding:48px;color:#9ca3af;">
                    <i class="fas fa-boxes" style="font-size:36px;display:block;margin-bottom:10px;opacity:.3"></i>
                    No stock entries yet. <a href="stock_create.php" style="color:#f97316;font-weight:700;text-decoration:none">+ Add first item</a>
                </td></tr>
            <?php else: ?>
                <?php foreach ($items as $s): ?>
                <?= _stock_row($s) ?>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <div class="pagination" id="paginationWrap"></div>

</div><!-- /.content -->

<!-- ══ UPDATE MODAL (tabbed: Outgoing + Incoming) ══ -->
<div class="modal-overlay" id="updateOverlay">
    <div class="modal-box" style="width:640px">
        <div class="modal-header">
            <div>
                <div class="modal-title"><i class="fas fa-exchange-alt" style="color:#f97316;margin-right:6px"></i>Update Stock</div>
                <div class="modal-sub" id="updItemName"></div>
            </div>
            <button class="modal-close" onclick="closeModal('updateOverlay')">✕</button>
        </div>

        <!-- Tabs -->
        <div style="display:flex;border-bottom:2px solid #f0f2f7;background:#fafbfc;">
            <button class="upd-tab active" id="tabOutBtn" onclick="switchTab('out')"
                style="flex:1;padding:12px;border:none;background:none;font-size:13px;font-weight:700;
                       font-family:'Times New Roman',Times,serif;cursor:pointer;color:#f97316;
                       border-bottom:2px solid #f97316;margin-bottom:-2px;transition:all .2s;">
                <i class="fas fa-arrow-up" style="margin-right:5px"></i>Outgoing
            </button>
            <button class="upd-tab" id="tabInBtn" onclick="switchTab('in')"
                style="flex:1;padding:12px;border:none;background:none;font-size:13px;font-weight:700;
                       font-family:'Times New Roman',Times,serif;cursor:pointer;color:#9ca3af;
                       border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .2s;">
                <i class="fas fa-arrow-down" style="margin-right:5px"></i>Incoming
            </button>
        </div>

        <!-- Outgoing Tab -->
        <div id="tabOutPanel" class="modal-body">
            <div class="remaining-note">
                <i class="fas fa-info-circle" style="color:#0369a1"></i>
                Available remaining: <strong id="updRemaining">0</strong> units
            </div>
            <input type="hidden" id="updStockId">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="upd-field">
                    <label>Person Taking Out</label>
                    <input type="text" id="updPerson" placeholder="Enter name of person">
                </div>
                <div class="upd-field">
                    <label>Outgoing Quantity</label>
                    <input type="number" id="updQty" placeholder="0" min="0.001" step="any">
                </div>
                <div class="upd-field">
                    <label>Outgoing Date</label>
                    <input type="date" id="updDate" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="upd-field">
                    <label>Location</label>
                    <input type="text" id="updLocation" placeholder="Where is this going?">
                </div>
            </div>
            <div class="upd-field" style="margin-top:4px">
                <label>Description</label>
                <textarea id="updDescription" placeholder="Additional notes about this outgoing entry" style="min-height:60px"></textarea>
            </div>
        </div>

        <!-- Incoming Tab -->
        <div id="tabInPanel" class="modal-body" style="display:none">
            <div class="remaining-note" style="border-color:#bbf7d0;background:#f0fdf4;">
                <i class="fas fa-info-circle" style="color:#16a34a"></i>
                Adding incoming will increase stock quantity &amp; remaining.
            </div>
            <input type="hidden" id="inStockId">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="upd-field">
                    <label>Person Bringing In</label>
                    <input type="text" id="inPerson" placeholder="Enter name of person">
                </div>
                <div class="upd-field">
                    <label>Incoming Quantity</label>
                    <input type="number" id="inQty" placeholder="0" min="0.001" step="any">
                </div>
                <div class="upd-field">
                    <label>Incoming Date</label>
                    <input type="date" id="inDate" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="upd-field">
                    <label>Location</label>
                    <input type="text" id="inLocation" placeholder="Where is this coming from?">
                </div>
            </div>
            <div class="upd-field" style="margin-top:4px">
                <label>Responsible Person</label>
                <div style="display:flex;gap:8px;align-items:center;">
                    <select id="inResponsible" style="flex:1;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;transition:border-color .2s;background:#fff;">
                        <option value="">— Select Responsible Person —</option>
                    </select>
                    <button type="button" onclick="openAddPersonModalIndex()" title="Add new responsible person"
                        style="padding:9px 14px;border-radius:8px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;cursor:pointer;font-size:15px;font-weight:700;box-shadow:0 2px 6px rgba(249,115,22,.3);transition:all .2s;flex-shrink:0;"
                        onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform=''">+</button>
                </div>
            </div>
            <div class="upd-field">
                <label>Description</label>
                <textarea id="inDescription" placeholder="Additional notes about this incoming entry" style="min-height:60px"></textarea>
            </div>
        </div>

        <div class="modal-footer">
            <button class="btn-confirm" id="updSaveBtn" onclick="saveUpdate()">
                <i class="fas fa-check"></i> Save Outgoing
            </button>
            <button class="btn-cancel" onclick="closeModal('updateOverlay')">Cancel</button>
        </div>
    </div>
</div>

<!-- ══ ADD PERSON MODAL (index) ══ -->
<div id="addPersonOverlayIndex" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.38);backdrop-filter:blur(3px);z-index:3000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;width:440px;max-width:95vw;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.18);font-family:'Times New Roman',Times,serif;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:20px 24px 16px;border-bottom:1px solid #e4e8f0;background:#fafbfc;">
            <div>
                <div style="font-size:16px;font-weight:800;color:#1a1f2e;"><i class="fas fa-user-plus" style="color:#f97316;margin-right:8px"></i>Add Responsible Person</div>
                <div style="font-size:12px;color:#9ca3af;margin-top:3px;">This person will be saved to the persons list</div>
            </div>
            <button onclick="closeAddPersonModalIndex()" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:50%;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='none'">✕</button>
        </div>
        <div style="padding:22px 24px;">
            <div class="upd-field" style="margin-bottom:0">
                <label>Full Name *</label>
                <input type="text" id="newPersonNameIndex" placeholder="Enter person's full name" onkeydown="if(event.key==='Enter')saveNewPersonIndex()">
            </div>
        </div>
        <div style="display:flex;gap:10px;padding:16px 24px;border-top:1px solid #e4e8f0;background:#fafbfc;">
            <button onclick="saveNewPersonIndex()" id="savePersonBtnIndex"
                style="flex:1;padding:11px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif;display:flex;align-items:center;justify-content:center;gap:7px;box-shadow:0 2px 8px rgba(249,115,22,.3);">
                <i class="fas fa-check"></i> Save Person
            </button>
            <button onclick="closeAddPersonModalIndex()"
                style="flex:1;padding:11px;background:#f1f5f9;color:#374151;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif;">
                Cancel
            </button>
        </div>
    </div>
</div>

<!-- ══ INCOMING LOG MODAL ══ -->
<div class="modal-overlay" id="incomingOverlay">
    <div class="modal-box" style="width:720px">
        <div class="modal-header">
            <div>
                <div class="modal-title"><i class="fas fa-arrow-down" style="color:#16a34a;margin-right:6px"></i>Incoming Log</div>
                <div class="modal-sub" id="incomingItemName"></div>
            </div>
            <button class="modal-close" onclick="closeModal('incomingOverlay')">✕</button>
        </div>
        <div class="modal-body">
            <table class="log-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Person</th>
                        <th>Responsible</th>
                        <th>Location</th>
                        <th>Description</th>
                        <th style="text-align:right">Qty</th>
                    </tr>
                </thead>
                <tbody id="incomingLog">
                    <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:20px 0">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" style="flex:none;padding:9px 20px" onclick="closeModal('incomingOverlay')">Close</button>
        </div>
    </div>
</div>

<!-- ══ OUTGOING LOG MODAL ══ -->
<div class="modal-overlay" id="outgoingOverlay">
    <div class="modal-box" style="width:720px">
        <div class="modal-header">
            <div>
                <div class="modal-title"><i class="fas fa-arrow-up" style="color:#f97316;margin-right:6px"></i>Outgoing Log</div>
                <div class="modal-sub" id="outgoingItemName"></div>
            </div>
            <button class="modal-close" onclick="closeModal('outgoingOverlay')">✕</button>
        </div>
        <div class="modal-body">
            <table class="log-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Person</th>
                        <th>Location</th>
                        <th>Description</th>
                        <th style="text-align:right">Qty</th>
                    </tr>
                </thead>
                <tbody id="outgoingLog">
                    <tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:20px 0">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="modal-footer">
            <button class="btn-cancel" style="flex:none;padding:9px 20px" onclick="closeModal('outgoingOverlay')">Close</button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
// ── Close any modal ──────────────────────────────────────────────────────────
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
document.querySelectorAll('.modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.modal-overlay.open').forEach(function(m) { m.classList.remove('open'); });
});

// ── Toast ────────────────────────────────────────────────────────────────────
function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + (type || 'success');
    clearTimeout(t._t);
    t._t = setTimeout(function() { t.className = ''; }, 3500);
}

// ── Format number ────────────────────────────────────────────────────────────
function fmtQty(n) { var v = parseFloat(n || 0); return (v === Math.floor(v)) ? Math.floor(v).toString() : parseFloat(v.toFixed(3)).toString(); }

// ── Escape HTML helper ───────────────────────────────────────────────────────
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── INCOMING LOG popup ───────────────────────────────────────────────────────
function showIncoming(id, itemName) {
    document.getElementById('incomingItemName').textContent = itemName;
    document.getElementById('incomingLog').innerHTML = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:20px 0"><i class="fas fa-spinner fa-spin"></i> Loading…</td></tr>';
    document.getElementById('incomingOverlay').classList.add('open');
    fetch('stock_index.php?ajax=get_incoming&id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(rows) {
            if (!rows.length) {
                document.getElementById('incomingLog').innerHTML = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:20px 0">No incoming records.</td></tr>';
                return;
            }
            var html = '';
            rows.forEach(function(r) {
                html += '<tr>'
                    + '<td style="white-space:nowrap">' + escHtml(r.incoming_date) + '</td>'
                    + '<td>' + escHtml(r.person_name) + '</td>'
                    + '<td>' + escHtml(r.responsible_name || '—') + '</td>'
                    + '<td>' + escHtml(r.location || '—') + '</td>'
                    + '<td style="max-width:160px;color:#6b7280">' + escHtml(r.description || '—') + '</td>'
                    + '<td style="text-align:right;font-weight:700">' + fmtQty(r.quantity) + '</td>'
                    + '</tr>';
            });
            document.getElementById('incomingLog').innerHTML = html;
        }).catch(function() {
            document.getElementById('incomingLog').innerHTML = '<tr><td colspan="6" style="text-align:center;color:#dc2626;padding:20px 0">Failed to load.</td></tr>';
        });
}

// ── OUTGOING LOG popup ───────────────────────────────────────────────────────
function showOutgoing(id, itemName) {
    document.getElementById('outgoingItemName').textContent = itemName;
    document.getElementById('outgoingLog').innerHTML = '<tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:20px 0"><i class="fas fa-spinner fa-spin"></i> Loading…</td></tr>';
    document.getElementById('outgoingOverlay').classList.add('open');
    fetch('stock_index.php?ajax=get_outgoing&id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(rows) {
            if (!rows.length) {
                document.getElementById('outgoingLog').innerHTML = '<tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:20px 0">No outgoing records yet.</td></tr>';
                return;
            }
            var html = '';
            rows.forEach(function(r) {
                html += '<tr>'
                    + '<td style="white-space:nowrap">' + escHtml(r.outgoing_date) + '</td>'
                    + '<td>' + escHtml(r.person_name) + '</td>'
                    + '<td>' + escHtml(r.location || '—') + '</td>'
                    + '<td style="max-width:160px;color:#6b7280">' + escHtml(r.description || '—') + '</td>'
                    + '<td style="text-align:right;font-weight:700">' + fmtQty(r.quantity) + '</td>'
                    + '</tr>';
            });
            document.getElementById('outgoingLog').innerHTML = html;
        }).catch(function() {
            document.getElementById('outgoingLog').innerHTML = '<tr><td colspan="5" style="text-align:center;color:#dc2626;padding:20px 0">Failed to load.</td></tr>';
        });
}

// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(tab) {
    var isOut = tab === 'out';
    document.getElementById('tabOutPanel').style.display = isOut ? '' : 'none';
    document.getElementById('tabInPanel').style.display  = isOut ? 'none' : '';
    document.getElementById('tabOutBtn').style.color        = isOut ? '#f97316' : '#9ca3af';
    document.getElementById('tabOutBtn').style.borderBottomColor = isOut ? '#f97316' : 'transparent';
    document.getElementById('tabInBtn').style.color         = isOut ? '#9ca3af' : '#16a34a';
    document.getElementById('tabInBtn').style.borderBottomColor  = isOut ? 'transparent' : '#16a34a';
    var btn = document.getElementById('updSaveBtn');
    if (isOut) {
        btn.innerHTML = '<i class="fas fa-check"></i> Save Outgoing';
        btn.style.background = 'linear-gradient(135deg,#f97316,#fb923c)';
        btn.onclick = saveUpdate;
    } else {
        btn.innerHTML = '<i class="fas fa-check"></i> Save Incoming';
        btn.style.background = 'linear-gradient(135deg,#16a34a,#22c55e)';
        btn.onclick = saveIncoming;
    }
}

// ── UPDATE MODAL ──────────────────────────────────────────────────────────────
function openUpdateModal(id, itemName, remaining) {
    document.getElementById('updStockId').value       = id;
    document.getElementById('inStockId').value        = id;
    document.getElementById('updItemName').textContent = itemName;
    document.getElementById('updRemaining').textContent = fmtQty(remaining);
    document.getElementById('updPerson').value        = '';
    document.getElementById('updQty').value           = '';
    document.getElementById('updDate').value          = '<?= date('Y-m-d') ?>';
    document.getElementById('updLocation').value      = '';
    document.getElementById('updDescription').value   = '';
    document.getElementById('inPerson').value         = '';
    document.getElementById('inQty').value            = '';
    document.getElementById('inDate').value           = '<?= date('Y-m-d') ?>';
    document.getElementById('inLocation').value       = '';
    document.getElementById('inDescription').value    = '';
    document.getElementById('inResponsible').value    = '';
    switchTab('out');
    document.getElementById('updateOverlay').classList.add('open');
    document.getElementById('updPerson').focus();
}

function saveUpdate() {
    var id          = document.getElementById('updStockId').value;
    var person      = document.getElementById('updPerson').value.trim();
    var qty         = document.getElementById('updQty').value.trim();
    var date        = document.getElementById('updDate').value;
    var location    = document.getElementById('updLocation').value.trim();
    var description = document.getElementById('updDescription').value.trim();

    if (!person) { showToast('Enter person name.', 'error'); return; }
    if (!qty || parseFloat(qty) <= 0) { showToast('Enter a valid quantity.', 'error'); return; }
    if (!date)   { showToast('Select outgoing date.', 'error'); return; }

    var btn = document.getElementById('updSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    var fd = new FormData();
    fd.append('ajax_update',   '1');
    fd.append('stock_item_id', id);
    fd.append('person_name',   person);
    fd.append('quantity',      qty);
    fd.append('outgoing_date', date);
    fd.append('location',      location);
    fd.append('description',   description);

    fetch('stock_index.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Outgoing';
            if (d.success) {
                closeModal('updateOverlay');
                showToast('Outgoing recorded!', 'success');
                updateRowInTable(d.item);
            } else {
                showToast('Error: ' + (d.message || 'Unknown error'), 'error');
            }
        }).catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Outgoing';
            showToast('Network error. Try again.', 'error');
        });
}

function saveIncoming() {
    var id          = document.getElementById('inStockId').value;
    var person      = document.getElementById('inPerson').value.trim();
    var qty         = document.getElementById('inQty').value.trim();
    var date        = document.getElementById('inDate').value;
    var responsible_person_id = document.getElementById('inResponsible').value;
    var location    = document.getElementById('inLocation').value.trim();
    var description = document.getElementById('inDescription').value.trim();

    if (!person) { showToast('Enter person name.', 'error'); return; }
    if (!qty || parseFloat(qty) <= 0) { showToast('Enter a valid quantity.', 'error'); return; }
    if (!date)   { showToast('Select incoming date.', 'error'); return; }

    var btn = document.getElementById('updSaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    var fd = new FormData();
    fd.append('ajax_add_incoming',     '1');
    fd.append('stock_item_id',         id);
    fd.append('person_name',           person);
    fd.append('quantity',              qty);
    fd.append('incoming_date',         date);
    fd.append('responsible_person_id', responsible_person_id);
    fd.append('location',              location);
    fd.append('description',           description);

    fetch('stock_index.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Incoming';
            if (d.success) {
                closeModal('updateOverlay');
                showToast('Incoming added! Stock increased.', 'success');
                updateRowInTable(d.item);
                loadHsnDropdown();
            } else {
                showToast('Error: ' + (d.message || 'Unknown error'), 'error');
            }
        }).catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Incoming';
            showToast('Network error. Try again.', 'error');
        });
}

function updateRowInTable(item) {
    var rows = document.querySelectorAll('#stockTbody tr.stock-row');
    rows.forEach(function(tr) {
        try {
            var obj = JSON.parse(tr.getAttribute('data-obj'));
            if (parseInt(obj.id) === parseInt(item.id)) {
                var rem = parseFloat(item.remaining_qty);
                var remCls = rem <= 0 ? 'qty-zero' : (rem < 10 ? 'qty-low' : 'qty-ok');
                tr.setAttribute('data-obj', JSON.stringify(item));
                var tds = tr.querySelectorAll('td');
                var inPill = tds[3].querySelector('.qty-pill');
                if (inPill) inPill.childNodes[0].textContent = fmtQty(item.incoming_qty) + ' ';
                var outPill = tds[4].querySelector('.qty-pill');
                if (outPill) outPill.childNodes[0].textContent = fmtQty(item.outgoing_qty) + ' ';
                var remPill = tds[5].querySelector('.qty-pill');
                if (remPill) {
                    remPill.textContent = fmtQty(rem);
                    remPill.className = 'qty-pill ' + remCls;
                }
                var updBtn = tds[7].querySelector('.btn-update');
                if (updBtn) {
                    updBtn.setAttribute('onclick',
                        "openUpdateModal(" + item.id + ", '" + escHtml(item.item_name) + "', " + rem + ")");
                }
            }
        } catch(e) {}
    });
}

// ── Add Person Modal (index page) ─────────────────────────────────────────────
function openAddPersonModalIndex() {
    document.getElementById('newPersonNameIndex').value = '';
    document.getElementById('addPersonOverlayIndex').style.display = 'flex';
    setTimeout(function() { document.getElementById('newPersonNameIndex').focus(); }, 80);
}
function closeAddPersonModalIndex() {
    document.getElementById('addPersonOverlayIndex').style.display = 'none';
}
document.getElementById('addPersonOverlayIndex').addEventListener('click', function(e) {
    if (e.target === this) closeAddPersonModalIndex();
});

function saveNewPersonIndex() {
    var name = document.getElementById('newPersonNameIndex').value.trim();
    if (!name) { showToast('Please enter a name.', 'error'); return; }

    var btn = document.getElementById('savePersonBtnIndex');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    var fd = new FormData();
    fd.append('ajax_add_person', '1');
    fd.append('name', name);

    fetch('stock_index.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Person';
            if (d.success) {
                var sel = document.getElementById('inResponsible');
                var opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = name;
                opt.selected = true;
                sel.appendChild(opt);
                closeAddPersonModalIndex();
                showToast('Person "' + name + '" added!', 'success');
            } else {
                showToast('Error: ' + (d.message || 'Could not save.'), 'error');
            }
        }).catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Person';
            showToast('Network error. Try again.', 'error');
        });
}

// ── Stock Persons Dropdown ────────────────────────────────────────────────────
function loadExecutivesDropdown() {
    fetch('stock_index.php?ajax=executives')
        .then(function(r) { return r.json(); })
        .then(function(list) {
            var sel = document.getElementById('inResponsible');
            var cur = sel.value;
            sel.innerHTML = '<option value="">— Select Responsible Person —</option>';
            list.forEach(function(e) {
                var o = document.createElement('option');
                o.value = e.id;
                o.textContent = e.name;
                if (String(e.id) === String(cur)) o.selected = true;
                sel.appendChild(o);
            });
        }).catch(function() {});
}

// ── HSN Dropdown ─────────────────────────────────────────────────────────────
function loadHsnDropdown() {
    fetch('stock_index.php?ajax=hsn_list')
        .then(function(r) { return r.json(); })
        .then(function(list) {
            var sel = document.getElementById('hsnFilter');
            var cur = sel.value;
            sel.innerHTML = '<option value="">All HSN Codes</option>';
            list.forEach(function(h) {
                var o = document.createElement('option');
                o.value = h; o.textContent = h;
                if (h === cur) o.selected = true;
                sel.appendChild(o);
            });
        }).catch(function() {});
}

function filterByHsn(hsn) {
    _curSearch = '';
    document.getElementById('liveSearch').value = '';
    fetch('stock_index.php?ajax=filter_hsn&hsn=' + encodeURIComponent(hsn))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('stockTbody').innerHTML = data.html;
            var pill = document.getElementById('totalCount');
            if (pill) pill.textContent = data.count;
            renderPagination(1, hsn ? 1 : _totalPages);
        }).catch(function() {});
}

// ── Search + Pagination state ─────────────────────────────────────────────────
var _curSearch = '';
var _curPage   = <?= $cur_page ?>;
var _totalPages= <?= $total_pages ?>;
var _perPage   = <?= $per_page ?>;
var _sortCol   = '<?= htmlspecialchars($sort_col_st) ?>';
var _sortDir   = '<?= htmlspecialchars($sort_dir_st) ?>';

function renderPagination(cur, total) {
    if (total <= 1) { document.getElementById('paginationWrap').innerHTML = ''; return; }
    var h = '';
    if (cur <= 1) h += '<span class="disabled">&laquo;</span>';
    else          h += '<a onclick="goPage(' + (cur-1) + ')">&laquo;</a>';

    var pages = [];
    for (var i = 1; i <= total; i++) {
        if (i === 1 || i === total || (i >= cur - 1 && i <= cur + 1)) pages.push(i);
    }
    var prev = null;
    for (var j = 0; j < pages.length; j++) {
        var p = pages[j];
        if (prev !== null && p - prev > 1) h += '<span class="dots">…</span>';
        if (p === cur) h += '<span class="active">' + p + '</span>';
        else           h += '<a onclick="goPage(' + p + ')">' + p + '</a>';
        prev = p;
    }

    if (cur >= total) h += '<span class="disabled">&raquo;</span>';
    else              h += '<a onclick="goPage(' + (cur+1) + ')">&raquo;</a>';

    document.getElementById('paginationWrap').innerHTML = h;
}

function goPage(pg) {
    _curPage = pg;
    doAjaxSearch(_curSearch, pg);
}

// ── AJAX Search ──────────────────────────────────────────────────────────────
var _srTimer;
function ajaxSearch(q) {
    _curSearch = q;
    _curPage   = 1;
    clearTimeout(_srTimer);
    _srTimer = setTimeout(function() { doAjaxSearch(q, 1); }, 300);
}
function doAjaxSearch(q, pg) {
    pg = pg || 1;
    fetch('stock_index.php?ajax=search&search=' + encodeURIComponent(q) + '&page=' + pg + '&per_page=' + _perPage + '&sort_col=' + encodeURIComponent(_sortCol) + '&sort_dir=' + encodeURIComponent(_sortDir))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            document.getElementById('stockTbody').innerHTML = data.html;
            var pill = document.getElementById('totalCount');
            if (pill) pill.textContent = data.count;
            _totalPages = data.total_pages;
            _curPage    = data.cur_page;
            renderPagination(_curPage, _totalPages);
        }).catch(function() {});
}
document.addEventListener('DOMContentLoaded', function() {
    loadHsnDropdown();
    loadExecutivesDropdown();
    renderPagination(_curPage, _totalPages);
    var s = document.getElementById('liveSearch');
    if (s && s.value.trim()) {
        _curSearch = s.value.trim();
        doAjaxSearch(_curSearch, 1);
    }
});
</script>
</body>
</html>
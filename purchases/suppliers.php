<?php
require_once dirname(__DIR__) . '/db.php';

// ── Handle UPDATE ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id']) && !isset($_POST['delete_id'])) {
    header('Content-Type: application/json');
    $uid = intval($_POST['id']);
    try {
        $stmt = $pdo->prepare("UPDATE po_suppliers SET
            supplier_name  = :supplier_name,
            contact_person = :contact_person,
            phone          = :phone,
            email          = :email,
            address        = :address,
            gstin          = :gstin,
            pan            = :pan,
            website        = :website
            WHERE id = :id");
        $stmt->execute([
            ':supplier_name'  => trim($_POST['supplier_name']  ?? ''),
            ':contact_person' => trim($_POST['contact_person'] ?? ''),
            ':phone'          => trim($_POST['phone']          ?? ''),
            ':email'          => trim($_POST['email']          ?? ''),
            ':address'        => trim($_POST['address']        ?? ''),
            ':gstin'          => strtoupper(trim($_POST['gstin'] ?? '')),
            ':pan'            => strtoupper(trim($_POST['pan']   ?? '')),
            ':website'        => trim($_POST['website']        ?? ''),
            ':id'             => $uid,
        ]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Handle DELETE ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    header('Content-Type: application/json');
    try {
        $pdo->prepare("DELETE FROM po_suppliers WHERE id=?")->execute([intval($_POST['delete_id'])]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Handle AJAX live search ────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $search  = trim($_GET['search'] ?? '');
    $where   = ['1=1'];
    $params  = [];
    if ($search !== '') {
        $where[]      = '(supplier_name LIKE :s OR contact_person LIKE :s OR phone LIKE :s OR email LIKE :s)';
        $params[':s'] = "%$search%";
    }
    $wsql = implode(' AND ', $where);
    $cs = $pdo->prepare("SELECT COUNT(*) FROM po_suppliers WHERE $wsql");
    $cs->execute($params);
    $cnt = (int)$cs->fetchColumn();
    $rs = $pdo->prepare("SELECT * FROM po_suppliers WHERE $wsql ORDER BY supplier_name ASC LIMIT 200");
    $rs->execute($params);
    $rows = $rs->fetchAll(PDO::FETCH_ASSOC);
    ob_start();
    if (empty($rows)) {
        echo '<tr><td colspan="3" style="text-align:center;padding:40px;color:#9ca3af;">No suppliers found.</td></tr>';
    } else {
        foreach ($rows as $s) {
            echo '<tr>';
            echo '<td style="font-weight:700;font-size:13px;">'.htmlspecialchars($s['supplier_name']).'</td>';
            echo '<td style="font-size:13px;">'.htmlspecialchars($s['contact_person'] ?? '—').'</td>';
            echo '<td><a href="suppliers.php?edit='.$s['id'].'" class="action-btn btn-edit" title="Edit"><i class="fas fa-pencil-alt"></i></a></td>';
            echo '</tr>';
        }
    }
    $html = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode(['html' => $html, 'count' => $cnt]);
    exit;
}

// ── Determine view: list or edit ───────────────────────────────────────────────
$editId   = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
$isEdit   = $editId > 0;
$supplier = null;

if ($isEdit) {
    $s = $pdo->prepare("SELECT * FROM po_suppliers WHERE id=?");
    $s->execute([$editId]);
    $supplier = $s->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) { $isEdit = false; $editId = 0; }
}

// ── List data ──────────────────────────────────────────────────────────────────
if (!$isEdit) {
    $search    = trim($_GET['search'] ?? '');
    $per_page  = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;
    $cur_page  = max(1, (int)($_GET['page'] ?? 1));
    $sort_col  = $_GET['sort_col'] ?? '';
    $sort_dir  = $_GET['sort_dir'] ?? 'asc';
    $allowed_sorts_s = ['supplier_name'=>'supplier_name','contact_person'=>'contact_person','phone'=>'phone','email'=>'email'];
    $order_sql_s = 'supplier_name ASC';
    if ($sort_col && isset($allowed_sorts_s[$sort_col])) {
        $sdir_s = ($sort_dir === 'desc') ? 'DESC' : 'ASC';
        $order_sql_s = $allowed_sorts_s[$sort_col] . ' ' . $sdir_s;
    }
    $where     = ['1=1'];
    $params    = [];
    if ($search !== '') {
        $where[]      = '(supplier_name LIKE :s OR contact_person LIKE :s OR phone LIKE :s OR email LIKE :s)';
        $params[':s'] = "%$search%";
    }
    $where_sql   = implode(' AND ', $where);
    $cnt_stmt    = $pdo->prepare("SELECT COUNT(*) FROM po_suppliers WHERE $where_sql");
    $cnt_stmt->execute($params);
    $count       = (int)$cnt_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($count / $per_page));
    if ($cur_page > $total_pages) $cur_page = $total_pages;
    $offset      = ($cur_page - 1) * $per_page;
    $stmt        = $pdo->prepare("SELECT * FROM po_suppliers WHERE $where_sql ORDER BY $order_sql_s LIMIT $per_page OFFSET $offset");
    $stmt->execute($params);
    $suppliers   = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title><?= $isEdit ? 'Edit Supplier' : 'Suppliers' ?></title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Times New Roman', Times, serif; background: #f4f6fb; color: #222; }
.content { margin-left: 220px; padding: 58px 18px 6px; min-height:100vh;display:flex;flex-direction:column;background:#f4f6fb;}

/* ════ LIST STYLES ════ */
.header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
h2 { font-weight: 700; color: #1a1f2e; font-size: 18px; }
.topbar-right { display: flex; align-items: center; gap: 8px; }
/* ── SEARCH BAR ── */
.search-wrap { position: relative; width: 230px; }
.search-wrap .search-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 13px; pointer-events: none; font-style: normal; line-height: 1; }
.search-wrap input[type=text] { width: 100%; padding: 7px 28px 7px 34px; border: 1.5px solid #d1d5db; border-radius: 50px; font-size: 12.5px; font-family: 'Times New Roman',Times,serif; color: #374151; background: #fff; outline: none; box-shadow: 0 1px 3px rgba(0,0,0,.06); transition: border-color .2s, box-shadow .2s; }
.search-wrap input[type=text]:focus { border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(147,197,253,.2); }
.search-wrap input[type=text]::placeholder { color: #9ca3af; font-size: 12px; }
.search-clear { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #9ca3af; font-size: 12px; display: none; padding: 2px 3px; line-height: 1; }
.search-clear:hover { color: #374151; }
.summary-row { display: flex; align-items:center; gap: 8px; margin-bottom: 5px; flex-wrap:nowrap; }
.sum-pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px;
    border-radius: 8px; border: 1.5px solid; background: #fff; font-size: 11px; color: #374151; }
.sum-pill .label { color: #6b7280; }
.sum-pill .val { font-weight: 700; }
.sum-pill.orange { border-color: #f97316; }
.sum-pill.orange .val { color: #f97316; }
.po-card { background: #fff; border-radius: 12px; padding: 8px 12px; border: 1px solid #e4e8f0; flex:1;overflow:visible;}
.po-table { width: 100%; border-collapse: collapse; }
.po-table th { text-align: left; font-size: 12px; text-transform: uppercase;
letter-spacing: .05em; color: #6b7280; padding: 0 0 4px 0; font-weight: 700; }
.po-table tbody tr { transition: background .15s; }
.po-table tbody tr:hover { background: #fff7f0; }
.po-table td { padding: 4px 0 4px 0; border-top: 1px solid #f1f5f9; font-size: 12px; color: #1a1f2e; line-height: 1; }
.po-table td:nth-child(1), .po-table th:nth-child(1) { width: 45%; }
.po-table td:nth-child(2), .po-table th:nth-child(2) { width: 45%; }
.po-table td:nth-child(3), .po-table th:nth-child(3) { width: 40px; }
.sup-avatar { width: 34px; height: 34px; border-radius: 50%;
    background: linear-gradient(135deg,#f97316,#fb923c);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 800; color: #fff; flex-shrink: 0; }
.action-btns { display: flex; gap: 6px; }
.action-btn { width: 30px; height: 30px; border-radius: 8px; display: inline-flex;
    align-items: center; justify-content: center; font-size: 13px;
    border: none; cursor: pointer; text-decoration: none; transition: all .2s; }
.btn-edit { background: #f4f6fb; color: #6b7280; border: 1px solid #e2e8f0; }
.btn-edit:hover { background: #f97316; color: #fff; border-color: #f97316; }
.pagination { display: flex; justify-content: center; align-items: center; gap: 5px; padding: 4px 0 2px; }
.pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; padding: 0 8px; border-radius: 7px; font-size: 13px; font-weight: 600;
    text-decoration: none; border: 1.5px solid #e4e8f0; color: #374151; background: #fff; transition: all .15s; }
.pagination a:hover { border-color: #f97316; color: #f97316; background: #fff7f0; }
.pagination span.active { background: #f97316; color: #fff; border-color: #f97316; }

/* ════ EDIT STYLES ════ */
.page-wrap { max-width: 100%; margin: 0; }
.form-card { background: #fff; border-radius: 14px; border: 1px solid #e4e8f0;
    box-shadow: 0 1px 6px rgba(0,0,0,0.05); overflow: hidden; }
.card-header { display: flex; justify-content: space-between; align-items: center;
    padding: 18px 24px; border-bottom: 1px solid #f0f2f7; background: #fafbfc; }
.card-header-left { display: flex; align-items: center; gap: 12px; }
.supplier-avatar { width: 42px; height: 42px; border-radius: 50%;
    background: linear-gradient(135deg,#f97316,#fb923c);
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 18px; color: #fff; }
.card-title { font-size: 16px; font-weight: 800; color: #1a1f2e; }
.card-sub { font-size: 12px; color: #9ca3af; margin-top: 2px; }
.form-body { padding: 20px 24px; }
.field { display: flex; flex-direction: column; gap: 5px; }
.field label { display: flex; align-items: center; gap: 3px; font-size: 11px; font-weight: 600;
    color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }
.req { color: #f97316; }
.field input, .field select, .field textarea {
    width: 100%; padding: 8px 11px; border: 1.5px solid #e4e8f0; border-radius: 8px;
    font-size: 13px; font-family: 'Times New Roman',Times,serif;
    color: #1a1f2e; background: #fff; outline: none;
    transition: border-color 0.2s, box-shadow 0.2s; }
.field input:focus, .field select:focus, .field textarea:focus {
    border-color: #f97316; box-shadow: 0 0 0 3px rgba(249,115,22,0.1); }
.field textarea { resize: vertical; min-height: 64px; }
/* 4-column compact grid */
.form-grid { display: grid; gap: 12px 14px; margin-bottom: 12px; }
.g-4 { grid-template-columns: repeat(4, 1fr); }
.g-3 { grid-template-columns: repeat(3, 1fr); }
.g-2 { grid-template-columns: repeat(2, 1fr); }
.span-2 { grid-column: span 2; }
.span-3 { grid-column: span 3; }
.span-4 { grid-column: span 4; }
/* phone prefix */
.phone-row { display: flex; }
.phone-prefix { padding: 8px 10px; background: #f8f9fc; border: 1.5px solid #e4e8f0;
    border-right: none; border-radius: 8px 0 0 8px; font-size: 13px; font-weight: 600;
    color: #6b7280; display: flex; align-items: center; white-space: nowrap; }
.phone-row input { border-radius: 0 8px 8px 0 !important; }
@media (max-width: 700px) { .g-4,.g-3 { grid-template-columns: 1fr 1fr; } .span-4,.span-3 { grid-column: span 2; } }
.section-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;
    color: #9ca3af; padding: 16px 0 10px; border-top: 1px solid #f0f2f7; margin-top: 8px;
    display: flex; align-items: center; gap: 8px; }
.section-label::after { content: ''; flex: 1; height: 1px; background: #f0f2f7; }
.more-toggle { display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600;
    color: #374151; cursor: pointer; padding: 13px 0; border-top: 1px solid #f0f2f7;
    margin-top: 6px; user-select: none; transition: color 0.15s; }
.more-toggle:hover { color: #f97316; }
.more-arrow { width: 24px; height: 24px; border-radius: 6px; background: #f4f6fb;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; color: #6b7280; transition: all 0.25s; }
.more-arrow.open { background: #fff7f0; color: #f97316; transform: rotate(90deg); }
.more-section { display: block; padding-top: 8px; }
.bottom-actions { display: flex; gap: 10px; align-items: center;
    padding: 18px 24px; border-top: 1px solid #f0f2f7;
    background: #fafbfc; border-radius: 0 0 14px 14px; }
.btn-save { padding: 10px 22px; background: linear-gradient(135deg,#f97316,#fb923c);
    color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 700;
    cursor: pointer; font-family: 'Times New Roman',Times,serif;
    display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(249,115,22,0.3); }
.btn-save:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(249,115,22,0.4); }
.btn-delete { padding: 10px 20px; background: #fff; color: #dc2626; border: 1.5px solid #fecaca;
    border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer;
    font-family: 'Times New Roman',Times,serif;
    display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s; }
.btn-delete:hover { background: #fef2f2; border-color: #dc2626; }
.btn-cancel { padding: 10px 20px; background: #fff; color: #6b7280; border: 1.5px solid #e4e8f0;
    border-radius: 8px; font-size: 13px; font-weight: 700; cursor: pointer;
    font-family: 'Times New Roman',Times,serif;
    display: inline-flex; align-items: center; gap: 7px; transition: all 0.2s; text-decoration: none; }
.btn-cancel:hover { background: #f4f6fb; border-color: #9ca3af; color: #374151; }
.field-error { font-size: 11px; color: #dc2626; margin-top: 3px; display: none; }
.field-error.show { display: block; }
input.invalid { border-color: #dc2626 !important; box-shadow: 0 0 0 3px rgba(220,38,38,0.1) !important; }

/* ════ TOAST & POPUP ════ */
#toast { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: 10px;
    font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 8px;
    opacity: 0; transform: translateY(10px); transition: all 0.3s;
    pointer-events: none; z-index: 9999; box-shadow: 0 8px 24px rgba(0,0,0,0.12); }
#toast.show { opacity: 1; transform: translateY(0); }
#toast.success { background: #16a34a; color: #fff; }
#toast.error   { background: #dc2626; color: #fff; }
#successPopup { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45);
    justify-content: center; align-items: center; z-index: 9999;
    backdrop-filter: blur(4px); pointer-events: none; }
#successPopup[style*="flex"] { pointer-events: all; }
.success-card { background: #fff; padding: 32px 28px; border-radius: 16px; text-align: center;
    min-width: 300px; box-shadow: 0 24px 60px rgba(0,0,0,0.14); border: 1px solid #e4e8f0;
    animation: popIn 0.25s ease; }
.success-icon { width: 56px; height: 56px; background: linear-gradient(135deg,#16a34a,#22c55e);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px; font-size: 24px; color: #fff; }
.success-card h3 { font-size: 18px; font-weight: 800; color: #1a1f2e; margin-bottom: 6px; }
.success-card p  { font-size: 13px; color: #6b7280; margin-bottom: 6px; }
#okBtn { padding: 10px 28px; background: linear-gradient(135deg,#f97316,#fb923c);
    color: #fff; border: none; border-radius: 8px; font-size: 14px; font-weight: 700;
    cursor: pointer; font-family: 'Times New Roman',Times,serif;
    box-shadow: 0 2px 8px rgba(249,115,22,0.3); transition: all 0.2s; }
#okBtn:hover { transform: translateY(-1px); }
@keyframes popIn { from{transform:scale(0.95);opacity:0} to{transform:scale(1);opacity:1} }
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

<?php if ($isEdit): ?>
<!-- ══ EDIT VIEW ══════════════════════════════════════════════════════════════ -->
<div class="page-wrap">
<form id="editForm">
<input type="hidden" name="id" value="<?= $supplier['id'] ?>">
<div class="form-card">

    <div class="card-header">
        <div class="card-header-left">
            <div class="supplier-avatar"><?= strtoupper(substr($supplier['supplier_name'], 0, 1)) ?></div>
            <div>
                <div class="card-title"><?= htmlspecialchars($supplier['supplier_name']) ?></div>
                <div class="card-sub"><?= htmlspecialchars($supplier['contact_person'] ?? 'No contact person') ?></div>
            </div>
        </div>
    </div>

    <div class="form-body">

        <!-- Row 1: Supplier Name (wide) + Contact Person + Phone -->
        <div class="form-grid g-4">
            <div class="field span-2">
                <label>Supplier Name <span class="req">*</span></label>
                <input type="text" name="supplier_name"
                       value="<?= htmlspecialchars($supplier['supplier_name']) ?>"
                       placeholder="Supplier / Company name" required>
            </div>
            <div class="field">
                <label>Contact Person</label>
                <input type="text" name="contact_person"
                       value="<?= htmlspecialchars($supplier['contact_person'] ?? '') ?>"
                       placeholder="Contact name">
            </div>
            <div class="field">
                <label>Phone</label>
                <div class="phone-row">
                    <span class="phone-prefix">+91</span>
                    <input type="text" name="phone"
                           value="<?= htmlspecialchars($supplier['phone'] ?? '') ?>"
                           placeholder="Phone number" maxlength="15">
                </div>
            </div>
        </div>

        <!-- Row 2: Email + Website -->
        <div class="form-grid g-4">
            <div class="field span-2">
                <label>Email</label>
                <input type="email" name="email"
                       value="<?= htmlspecialchars($supplier['email'] ?? '') ?>"
                       placeholder="email@example.com">
            </div>
            <div class="field span-2">
                <label>Website</label>
                <input type="text" name="website"
                       value="<?= htmlspecialchars($supplier['website'] ?? '') ?>"
                       placeholder="www.example.com">
            </div>
        </div>

        <div class="more-section" id="moreSection">
            <div class="section-label">Address</div>
            <!-- Row 3: Address full width -->
            <div class="form-grid g-4">
                <div class="field span-4">
                    <label>Address</label>
                    <textarea name="address" placeholder="Full address"><?= htmlspecialchars($supplier['address'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="section-label">Tax &amp; Legal</div>
            <!-- Row 4: GSTIN + PAN + 2 empty slots -->
            <div class="form-grid g-4">
                <div class="field span-2">
                    <label>GSTIN</label>
                    <input type="text" name="gstin" id="gstin"
                           value="<?= htmlspecialchars($supplier['gstin'] ?? '') ?>"
                           placeholder="22AAAAA0000A1Z5" maxlength="15"
                           style="font-family:monospace;text-transform:uppercase;">
                    <span class="field-error" id="gstin_error"></span>
                </div>
                <div class="field span-2">
                    <label>PAN</label>
                    <input type="text" name="pan" id="pan_no"
                           value="<?= htmlspecialchars($supplier['pan'] ?? '') ?>"
                           placeholder="ABCDE1234F" maxlength="10"
                           style="font-family:monospace;text-transform:uppercase;">
                    <span class="field-error" id="pan_error"></span>
                </div>
            </div>
        </div>

    </div>

    <div class="bottom-actions">
        <button type="button" class="btn-save" onclick="submitEditForm()">
            <i class="fas fa-check"></i> Save Changes
        </button>
        <button type="button" class="btn-delete" onclick="deleteSupplier(<?= $editId ?>)">
            <i class="fas fa-trash"></i> Delete Supplier
        </button>
        <a href="suppliers.php" class="btn-cancel">
            <i class="fas fa-times"></i> Cancel
        </a>
    </div>

</div>
<div style="display:flex;align-items:center;gap:6px;font-size:12px;color:#374151;margin-bottom:4px;">Show
        <form method="GET" id="ppForm" style="display:inline">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="sort_col" value="<?= htmlspecialchars($sort_col) ?>">
            <input type="hidden" name="sort_dir" value="<?= htmlspecialchars($sort_dir) ?>">
            <select name="per_page" onchange="this.form.submit();" style="padding:3px 6px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;font-family:'Times New Roman',Times,serif;">
                <?php foreach([10,25,50,100] as $n): ?>
                <option value="<?=$n?>" <?=$per_page==$n?'selected':''?>><?=$n?></option>
                <?php endforeach; ?>
            </select>
        </form>
        entries</div>

<?php else: ?>
<!-- ══ LIST VIEW ══════════════════════════════════════════════════════════════ -->

    <div class="header-bar">
        <h2><i class="fas fa-truck" style="color:#f97316;margin-right:8px"></i>Suppliers</h2>
        <div class="topbar-right">
            <div class="search-wrap">
                <span class="search-icon">🔍</span>
                <input type="text" id="liveSearch" placeholder="Search suppliers..." value="<?= htmlspecialchars($search) ?>" oninput="ajaxSearch(this.value)" autocomplete="off">
            </div>
        </div>
    </div>

    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:nowrap;">
        <div class="sum-pill orange"><span class="label">Total Suppliers</span><span class="val"><?= $count ?></span></div>
        <span style="width:1px;height:22px;background:#e2e8f0;display:inline-block;margin:0 2px;"></span>
        <span style="font-size:12px;color:#374151;">Show</span>
        <form method="GET" id="ppForm" style="display:inline">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="sort_col" value="<?= htmlspecialchars($sort_col) ?>">
            <input type="hidden" name="sort_dir" value="<?= htmlspecialchars($sort_dir) ?>">
            <select name="per_page" onchange="this.form.submit();" style="padding:3px 6px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;outline:none;">
                <?php foreach([10,25,50,100] as $n): ?>
                <option value="<?=$n?>" <?=$per_page==$n?'selected':''?>><?=$n?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <span style="font-size:12px;color:#374151;">entries</span>
    </div>

    <div class="po-card">
        <?php
        function supThSort($col, $label, $sort_col, $sort_dir, $get) {
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
        <table class="po-table">
            <thead>
                <tr>
                    <?=supThSort('supplier_name', 'Supplier',       $sort_col,$sort_dir,$_GET)?>
                    <?=supThSort('contact_person','Contact Person',  $sort_col,$sort_dir,$_GET)?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($suppliers)): ?>
                <tr><td colspan="3" style="text-align:center;padding:40px;color:#9ca3af;">No suppliers found.</td></tr>
            <?php else: ?>
                <?php foreach ($suppliers as $s): ?>
                <tr data-search="<?= strtolower(htmlspecialchars($s['supplier_name'] . ' ' . ($s['contact_person'] ?? ''))) ?>">
                    <td style="font-weight:700;font-size:13px;"><?= htmlspecialchars($s['supplier_name']) ?></td>
                    <td style="font-size:13px;"><?= htmlspecialchars($s['contact_person'] ?? '—') ?></td>
                    <td>
                        <a href="suppliers.php?edit=<?= $s['id'] ?>" class="action-btn btn-edit" title="Edit">
                            <i class="fas fa-pencil-alt"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
    <?php
        $pages = [];
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i <= 3 || $i == $total_pages || abs($i - $cur_page) <= 1) $pages[] = $i;
        }
        $pages = array_unique($pages); sort($pages);
        $prev_href = '?' . http_build_query(['search' => $search, 'page' => $cur_page - 1, 'per_page' => $per_page, 'sort_col' => $sort_col, 'sort_dir' => $sort_dir]);
        $next_href = '?' . http_build_query(['search' => $search, 'page' => $cur_page + 1, 'per_page' => $per_page, 'sort_col' => $sort_col, 'sort_dir' => $sort_dir]);
        echo $cur_page <= 1 ? '<span class="disabled">&laquo;</span>' : "<a href='".htmlspecialchars($prev_href)."'>&laquo;</a>";
        $prev = null;
        foreach ($pages as $p) {
            if ($prev !== null && $p - $prev > 1) echo '<span class="dots">…</span>';
            $href = '?' . http_build_query(['search' => $search, 'page' => $p, 'per_page' => $per_page, 'sort_col' => $sort_col, 'sort_dir' => $sort_dir]);
            if ($p == $cur_page) echo '<span class="active">'.$p.'</span>';
            else echo "<a href='".htmlspecialchars($href)."'>$p</a>";
            $prev = $p;
        }
        echo $cur_page >= $total_pages ? '<span class="disabled">&raquo;</span>' : "<a href='".htmlspecialchars($next_href)."'>&raquo;</a>";
    ?>
    </div>
    <?php endif; ?>

<?php endif; ?>

</div><!-- /.content -->

<!-- SUCCESS POPUP -->
<div id="successPopup">
    <div class="success-card">
        <div class="success-icon"><i class="fas fa-check"></i></div>
        <h3>Updated Successfully!</h3>
        <p>Supplier details have been saved.</p>
        <button id="okBtn">OK</button>
    </div>
</div>
<div id="toast"></div>

<script>
function submitEditForm() {
    if (!validateGstinPan()) return;
    fetch('suppliers.php', { method: 'POST', body: new FormData(document.getElementById('editForm')) })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.status === 'success') {
                document.getElementById('successPopup').style.display = 'flex';
            } else {
                alert('Update failed: ' + (d.message || 'Unknown error'));
            }
        })
        .catch(function(err) { alert('Network error: ' + err); });
}

function toggleMore() {
    var sec = document.getElementById('moreSection');
    var arrow = document.getElementById('moreArrow');
    if (!sec) return;
    var open = sec.classList.toggle('open');
    arrow.classList.toggle('open', open);
}

function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + (type || 'success');
    setTimeout(function() { t.className = ''; }, 3000);
}

function deleteSupplier(id) {
    if (!confirm('Delete this supplier? This cannot be undone.')) return;
    var fd = new FormData();
    fd.append('delete_id', id);
    fetch('suppliers.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.status === 'success') {
                showToast('Supplier deleted!', 'success');
                setTimeout(function() { window.location.href = 'suppliers.php'; }, 1200);
            } else {
                showToast('Delete failed: ' + (d.message || 'Unknown error'), 'error');
            }
        })
        .catch(function() { showToast('Network error. Try again.', 'error'); });
}

function validateGstinPan() {
    var valid = true;
    var gEl = document.getElementById('gstin');
    var gErr = document.getElementById('gstin_error');
    if (gEl && gEl.value.trim() !== '') {
        if (!/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/.test(gEl.value.trim())) {
            if (gErr) { gErr.textContent = 'Invalid GSTIN. Format: 22AAAAA0000A1Z5'; gErr.classList.add('show'); }
            gEl.classList.add('invalid'); valid = false;
        } else { if (gErr) gErr.classList.remove('show'); gEl.classList.remove('invalid'); }
    }
    var pEl = document.getElementById('pan_no');
    var pErr = document.getElementById('pan_error');
    if (pEl && pEl.value.trim() !== '') {
        if (!/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/.test(pEl.value.trim())) {
            if (pErr) { pErr.textContent = 'Invalid PAN. Format: ABCDE1234F'; pErr.classList.add('show'); }
            pEl.classList.add('invalid'); valid = false;
        } else { if (pErr) pErr.classList.remove('show'); pEl.classList.remove('invalid'); }
    }
    return valid;
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('#gstin, #pan_no').forEach(function(el) {
        el.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
        el.addEventListener('blur', validateGstinPan);
    });
    var okBtn = document.getElementById('okBtn');
    if (okBtn) {
        okBtn.addEventListener('click', function() {
            document.getElementById('successPopup').style.display = 'none';
            window.location.href = 'suppliers.php';
        });
    }
});
</script>
<script>
var _suppTimer;
function ajaxSearch(q) {
    clearTimeout(_suppTimer);
    _suppTimer = setTimeout(function() { doAjaxSearch(q); }, 300);
}
function doAjaxSearch(q) {
    var url = 'suppliers.php?search=' + encodeURIComponent(q) + '&page=1&ajax=1';
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var tbody = document.querySelector('.po-table tbody');
            if (!tbody) return;
            tbody.innerHTML = data.html;
            var pill = document.querySelector('.sum-pill.orange .val');
            if (pill) pill.textContent = data.count;
            var pg = document.querySelector('.pagination');
            if (pg) pg.style.display = q.trim() ? 'none' : '';
        }).catch(function() {});
}
document.addEventListener('DOMContentLoaded', function() {
    var s = document.getElementById('liveSearch');
    if (s && s.value.trim()) doAjaxSearch(s.value);
});
</script>
</body>
</html>
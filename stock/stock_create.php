<?php
require_once dirname(__DIR__) . '/db.php';

// ── Auto-create tables if not exist ──────────────────────────────────────────
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

// ── Fetch invoice numbers from purchases table ────────────────────────────────
$invoiceList = [];
try {
    $invoiceList = $pdo->query("SELECT DISTINCT invoice_number FROM purchases WHERE invoice_number IS NOT NULL AND invoice_number != '' ORDER BY invoice_number DESC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) { $invoiceList = []; }

// ── Fetch executives for responsible person dropdown ──────────────────────────
// ── Fetch all responsible persons ──────────────────────────────
$personsList = [];
try {
    $personsList = $pdo->query("SELECT id, name FROM stock_persons ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $personsList = [];
}
// ── Handle POST: add new responsible person ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_add_person'])) {
    header('Content-Type: application/json');
    $name = trim($_POST['name'] ?? '');
    if (!$name) {
        echo json_encode(['success' => false, 'message' => 'Name is required.']);
        exit;
    }
    try {
        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS stock_persons (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        $stmt = $pdo->prepare("INSERT INTO stock_persons (name) VALUES (?)");
        $stmt->execute([$name]);
        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'name' => $name]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Handle POST save ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $item_name    = trim($_POST['item_name']    ?? '');
    $hsn_code     = trim($_POST['hsn_code']     ?? '');
    $created_by   = trim($_POST['created_by']   ?? '');
    $incoming_qty = floatval($_POST['incoming_qty'] ?? 0);
    $incoming_date= trim($_POST['incoming_date'] ?? '');
    $units        = trim($_POST['units']         ?? '');
    $outgoing_qty = floatval($_POST['outgoing_qty'] ?? 0);
    $outgoing_date= trim($_POST['outgoing_date'] ?? '') ?: null;
    $responsible_person_id = (int)($_POST['responsible_person_id'] ?? 0) ?: null;
    $invoice_no   = trim($_POST['invoice_no']    ?? '');
    $location    = trim($_POST['location'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (!$item_name || !$created_by || !$incoming_date) {
        echo json_encode(['success' => false, 'message' => 'Item name, person name and incoming date are required.']);
        exit;
    }

    $remaining = $incoming_qty - $outgoing_qty;

    try {
        $pdo->beginTransaction();

        // Insert main stock item
        $stmt = $pdo->prepare("INSERT INTO stock_items
            (item_name, hsn_code, created_by, incoming_qty, units, outgoing_qty, remaining_qty, incoming_date, responsible_person_id, invoice_no,location, description)
            VALUES (:item_name, :hsn_code, :created_by, :incoming_qty, :units, :outgoing_qty, :remaining_qty, :incoming_date, :responsible_person_id, :invoice_no,:location, :description)");
        $stmt->execute([
            ':item_name'    => $item_name,
            ':hsn_code'     => $hsn_code,
            ':created_by'   => $created_by,
            ':incoming_qty' => $incoming_qty,
            ':units'        => $units,
            ':outgoing_qty' => $outgoing_qty,
            ':remaining_qty'=> $remaining,
            ':incoming_date'=> $incoming_date,
            ':responsible_person_id'  => $responsible_person_id,
            ':invoice_no'   => $invoice_no,
             ':location' => $location,
             ':description' => $description
        ]);
        $stock_id = (int)$pdo->lastInsertId();

        // Log incoming entry
        $pdo->prepare("INSERT INTO stock_incoming (stock_item_id, person_name, quantity, incoming_date, responsible_person_id, location, description)
            VALUES (?, ?, ?, ?, ?,?,?)")->execute([$stock_id, $created_by, $incoming_qty, $incoming_date, $responsible_person_id,$location, $description]);

        // Log outgoing entry if provided
        if ($outgoing_qty > 0 && $outgoing_date) {
            $pdo->prepare("INSERT INTO stock_outgoing (stock_item_id, person_name, quantity, outgoing_date,location, description)
                VALUES (?, ?, ?, ?,?,?)")->execute([$stock_id, $created_by, $outgoing_qty, $outgoing_date,$location, $description]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'id' => $stock_id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Create Stock Entry – Eltrive</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Times New Roman', Times, serif; background: #f4f6fb; color: #1a1f2e; }
.content {
    margin-left: 220px;
    padding: 68px 24px 40px;
    min-height: 100vh;
    width: calc(100% - 220px);
}

/* ── Header bar ── */
.header-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; }
.header-bar h2 { font-size: 22px; font-weight: 700; color: #1a1f2e; }
.btn-back {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 16px; border-radius: 8px;
    background: #f4f6fb; color: #374151;
    border: 1.5px solid #e2e8f0; text-decoration: none;
    font-size: 13px; font-weight: 600;
    font-family: 'Times New Roman', Times, serif;
    transition: all .2s;
}
.btn-back:hover { border-color: #f97316; color: #f97316; }

/* ── Card ── */
.form-card {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e4e8f0;
    box-shadow: 0 1px 6px rgba(0,0,0,.05);
    overflow: hidden;
    width: 100%;
    padding: 20px;
        margin-right: 0;
}
.card-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding-bottom: 14px;
    margin-bottom: 10px;
    border-bottom: 1px solid #f0f2f7;
}
.card-header > div:last-child {
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.card-icon {
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg, #f97316, #fb923c);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #fff;
    flex-shrink: 0;

}
.card-title { font-size: 16px; font-weight: 800; color: #1a1f2e; }
.card-sub   { font-size: 12px; color: #9ca3af; margin-top: 2px; }

/* ── Form body ── */
.form-body { padding: 20px ; }
.form-grid { display: grid; gap: 16px 18px; }
.g-2 { grid-template-columns: repeat(2, 1fr); }
.g-3 { grid-template-columns: repeat(3, 1fr); }
.span-2 { grid-column: span 2; }

.field { display: flex; flex-direction: column; gap: 5px; }
.field label {
    font-size: 11px; font-weight: 700; color: #6b7280;
    text-transform: uppercase; letter-spacing: .5px;
    display: flex; align-items: center; gap: 4px;
}
.req { color: #f97316; }
.field input, .field select, .field textarea {
    width: 100%; padding: 9px 12px;
    border: 1.5px solid #e4e8f0; border-radius: 8px;
    font-size: 13px; font-family: 'Times New Roman', Times, serif;
    color: #1a1f2e; background: #fff; outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.field textarea { resize: vertical; min-height: 72px; }
.field input:focus, .field select:focus, .field textarea:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249,115,22,.1);
}
.field input::placeholder, .field textarea::placeholder { color: #9ca3af; }

/* invoice combo wrapper */
.combo-wrap { position: relative; }
.combo-wrap input { padding-right: 32px; }
.combo-wrap .combo-arrow {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    pointer-events: none; color: #9ca3af; font-size: 11px;
}
#invoiceDropdown {
    position: absolute; top: calc(100% + 4px); left: 0; right: 0;
    background: #fff; border: 1.5px solid #e4e8f0; border-radius: 8px;
    z-index: 999; max-height: 180px; overflow-y: auto;
    box-shadow: 0 4px 16px rgba(0,0,0,.1); display: none;
}
#invoiceDropdown .inv-opt {
    padding: 9px 12px; font-size: 13px; cursor: pointer; color: #1a1f2e;
    font-family: 'Times New Roman', Times, serif;
}
#invoiceDropdown .inv-opt:hover { background: #fff7ed; color: #f97316; }
#invoiceDropdown .inv-empty { padding: 9px 12px; font-size: 12px; color: #9ca3af; }

.section-divider {
    font-size: 11px; font-weight: 700; color: #9ca3af;
    text-transform: uppercase; letter-spacing: 1px;
    padding: 18px 0 4px;
    border-top: 1px solid #f0f2f7; margin-top: 8px;
    display: flex; align-items: center; gap: 8px;
}
.section-divider::after { content: ''; flex: 1; height: 1px; background: #f0f2f7; }

/* ── Bottom actions ── */
.card-footer {
    display: flex;
    justify-content: flex-end;
    padding-top: 14px;
    border-top: none;
    background: transparent;
}
.btn-save {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 11px 24px; border-radius: 8px;
    background: linear-gradient(135deg, #f97316, #fb923c);
    color: #fff; font-size: 14px; font-weight: 700;
    border: none; cursor: pointer;
    font-family: 'Times New Roman', Times, serif;
    box-shadow: 0 2px 8px rgba(249,115,22,.3);
    transition: all .2s;
}
.btn-save:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(249,115,22,.4); }
.btn-save:disabled { background: #9ca3af; cursor: not-allowed; transform: none; box-shadow: none; }

/* ── Toast ── */
#toast {
    position: fixed; top: 24px; left: 50%; transform: translateX(-50%);
    padding: 13px 28px; border-radius: 10px;
    font-size: 13px; font-weight: 600;
    display: none; align-items: center; gap: 8px;
    z-index: 9999; box-shadow: 0 8px 24px rgba(0,0,0,.18);
    white-space: nowrap;
}
#toast.show { display: flex; }
#toast.success { background: #16a34a; color: #fff; }
#toast.error   { background: #dc2626; color: #fff; }

@media (max-width: 700px) {
    .g-2, .g-3 { grid-template-columns: 1fr; }
    .span-2 { grid-column: span 1; }
    .content { margin-left: 0; padding: 80px 16px 32px; }
}
.field input,
.field textarea,
.field select {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
}
.field {
    overflow: hidden;
}

.field input,
.field textarea {
    word-wrap: break-word;
    overflow-wrap: break-word;
}
.form-card {
    width: 100%;
    overflow: hidden;
}
input, textarea {
    white-space: normal;
}
input {
    overflow: hidden;
    text-overflow: ellipsis;
}
.form-grid {
    display: grid;
    gap: 16px;
    width: 100%;
}

.g-2 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.g-3 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}
/* ===== FINAL OVERFLOW FIX ===== */

/* Fix grid items */
.form-grid > * {
    min-width: 0;
}

/* Fix each field */
.field {
    min-width: 0;
}

/* Fix inputs, textarea, select */
input, textarea, select {
    min-width: 0;
    word-break: break-word;
}

/* Fix labels */
label {
    word-break: break-word;
}
</style>
</head>
<body>

<?php include dirname(__DIR__) . '/sidebar.php'; ?>
<?php include dirname(__DIR__) . '/header.php'; ?>

<div class="content">

    <div class="header-bar">
        <h2><i class="fas fa-boxes" style="color:#f97316;margin-right:8px"></i>Create Stock Entry</h2>
        <a href="stock_index.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Stock</a>
    </div>

    <div class="form-card">
        <div class="card-header">
            <div class="card-icon"><i class="fas fa-plus"></i></div>
            <div>
                <div class="card-title">New Stock Entry</div>
                <div class="card-sub">Fill in the item details and incoming quantity</div>
            </div>
        </div>

        <div class="form-body">

            <!-- Row 1: Person, Item Name, HSN Code -->
            <div class="form-grid g-3">
                <div class="field">
                    <label><i class="fas fa-user"></i> Person Name</label>
                    <input type="text" id="created_by" placeholder="Who is receiving?">
                </div>
                <div class="field">
                    <label><i class="fas fa-tag"></i> Item Name</label>
                    <input type="text" id="item_name" placeholder="e.g. Servo Motor 5A">
                </div>
                <div class="field">
                    <label><i class="fas fa-barcode"></i> HSN Code</label>
                    <input type="text" id="hsn_code" placeholder="e.g. 8501">
                </div>
            </div>

            <!-- Row 2: Incoming Date, Invoice No, Responsible Person -->
            <div class="form-grid g-3" style="margin-top:12px">
                <div class="field">
                    <label><i class="fas fa-calendar-alt"></i> Incoming Date</label>
                    <input type="date" id="incoming_date" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="field">
                    <label><i class="fas fa-file-invoice"></i> Invoice No</label>
                    <select id="invoice_no">
                        <option value="">— Select Invoice —</option>
                        <?php foreach ($invoiceList as $inv): ?>
                            <option value="<?= htmlspecialchars($inv) ?>"><?= htmlspecialchars($inv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label><i class="fas fa-user-tie"></i> Responsible Person</label>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <select id="responsible_person_id" style="flex:1;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;transition:border-color .2s;background:#fff;">
                            <option value="">— Select Person —</option>
                            <?php foreach ($personsList as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" onclick="openAddPersonModal()" title="Add new person"
                            style="padding:9px 13px;border-radius:8px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;cursor:pointer;font-size:15px;font-weight:700;flex-shrink:0;box-shadow:0 2px 6px rgba(249,115,22,.3);transition:all .2s;"
                            onmouseover="this.style.transform='translateY(-1px)'" onmouseout="this.style.transform=''">+</button>
                    </div>
                </div>
            </div>

            <!-- Row 3: Incoming Qty, Units, Location -->
            <div class="form-grid g-3" style="margin-top:12px">
                <div class="field">
                    <label><i class="fas fa-cubes"></i> Incoming Quantity</label>
                    <input type="number" id="incoming_qty" placeholder="0" min="0" step="any">
                </div>
                <div class="field">
                    <label><i class="fas fa-balance-scale"></i> Units</label>
                    <input type="text" id="units" placeholder="e.g. kg, pcs, liters">
                </div>
                <div class="field">
                    <label><i class="fas fa-map-marker-alt"></i> Location</label>
                    <input type="text" id="location" placeholder="Where is this stored?">
                </div>
            </div>

            <!-- Row 4: Description (full width) -->
            <div class="form-grid" style="margin-top:12px">
                <div class="field">
                    <label><i class="fas fa-align-left"></i> Description</label>
                    <input type="text" id="description" placeholder="Additional details about this stock entry">
                </div>
            </div>

        </div><!-- /.form-body -->

        <div class="card-footer">
            <button class="btn-save" id="saveBtn" onclick="saveStock()">
                <i class="fas fa-check"></i> Save Stock Entry
            </button>
            <a href="stock_index.php" class="btn-back">Cancel</a>
        </div>
    </div>
</div><!-- /.content -->

<!-- ══ ADD PERSON MODAL ══ -->
<div id="addPersonOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.38);backdrop-filter:blur(3px);z-index:3000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;width:440px;max-width:95vw;overflow:hidden;box-shadow:0 16px 48px rgba(0,0,0,.18);font-family:'Times New Roman',Times,serif;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:20px 24px 16px;border-bottom:1px solid #e4e8f0;background:#fafbfc;">
            <div>
                <div style="font-size:16px;font-weight:800;color:#1a1f2e;"><i class="fas fa-user-plus" style="color:#f97316;margin-right:8px"></i>Add Responsible Person</div>
                <div style="font-size:12px;color:#9ca3af;margin-top:3px;">This person will be saved to the persons list</div>
            </div>
            <button onclick="closeAddPersonModal()" style="background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:50%;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='none'">✕</button>
        </div>
        <div style="padding:22px 24px;">
            <div class="field" style="margin-bottom:0">
                <label><i class="fas fa-user"></i> Full Name <span class="req">*</span></label>
                <input type="text" id="newPersonName" placeholder="Enter person's full name" onkeydown="if(event.key==='Enter')saveNewPerson()">
            </div>
        </div>
        <div style="display:flex;gap:10px;padding:16px 24px;border-top:1px solid #e4e8f0;background:#fafbfc;">
            <button onclick="saveNewPerson()" id="savePersonBtn"
                style="flex:1;padding:11px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif;display:flex;align-items:center;justify-content:center;gap:7px;box-shadow:0 2px 8px rgba(249,115,22,.3);transition:all .2s;">
                <i class="fas fa-check"></i> Save Person
            </button>
            <button onclick="closeAddPersonModal()"
                style="flex:1;padding:11px;background:#f1f5f9;color:#374151;border:1.5px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif;transition:all .2s;">
                Cancel
            </button>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + (type || 'success');
    setTimeout(function() { t.className = ''; }, 3500);
}

// ── Add Person Modal ──────────────────────────────────────────────────────────
function openAddPersonModal() {
    document.getElementById('newPersonName').value = '';
    document.getElementById('addPersonOverlay').style.display = 'flex';
    setTimeout(function() { document.getElementById('newPersonName').focus(); }, 80);
}
function closeAddPersonModal() {
    document.getElementById('addPersonOverlay').style.display = 'none';
}
document.getElementById('addPersonOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeAddPersonModal();
});

function saveNewPerson() {
    var name = document.getElementById('newPersonName').value.trim();
    if (!name) { showToast('Please enter a name.', 'error'); return; }

    var btn = document.getElementById('savePersonBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    var fd = new FormData();
    fd.append('ajax_add_person', '1');
    fd.append('name', name);

    fetch('stock_create.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Person';
            if (d.success) {
                var sel = document.getElementById('responsible_person_id');
                var opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = name;
                opt.selected = true;
                sel.appendChild(opt);
                closeAddPersonModal();
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

// ── Save Stock ────────────────────────────────────────────────────────────────
function saveStock() {
    var item_name    = document.getElementById('item_name').value.trim();
    var created_by   = document.getElementById('created_by').value.trim();
    var hsn_code     = document.getElementById('hsn_code').value.trim();
    var incoming_qty = document.getElementById('incoming_qty').value.trim();
    var incoming_date= document.getElementById('incoming_date').value;
    var units        = document.getElementById('units').value.trim();
    var responsible_person_id = document.getElementById('responsible_person_id').value;
    var invoice_no   = document.getElementById('invoice_no').value.trim();
    var location     = document.getElementById('location').value.trim();
    var description  = document.getElementById('description').value.trim();
    var outgoing_qty = '0';
    var outgoing_date= '';

    if (!item_name)    { showToast('Please enter item name.', 'error'); return; }
    if (!created_by)   { showToast('Please enter person name.', 'error'); return; }
    if (!incoming_qty) { showToast('Please enter incoming quantity.', 'error'); return; }
    if (!incoming_date){ showToast('Please select incoming date.', 'error'); return; }

    var btn = document.getElementById('saveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    var fd = new FormData();
    fd.append('item_name',    item_name);
    fd.append('created_by',   created_by);
    fd.append('hsn_code',     hsn_code);
    fd.append('incoming_qty', incoming_qty);
    fd.append('incoming_date',incoming_date);
    fd.append('units',        units);
    fd.append('outgoing_qty', outgoing_qty);
    fd.append('outgoing_date',outgoing_date);
    fd.append('responsible_person_id', responsible_person_id);
    fd.append('invoice_no',   invoice_no);
    fd.append('location',     location);
    fd.append('description',  description);

    fetch('stock_create.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Stock Entry';
            if (d.success) {
                showToast('Stock entry saved!', 'success');
                setTimeout(function() { window.location.href = 'stock_index.php'; }, 1200);
            } else {
                showToast('Error: ' + (d.message || 'Unknown error'), 'error');
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Save Stock Entry';
            showToast('Network error. Try again.', 'error');
        });
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>
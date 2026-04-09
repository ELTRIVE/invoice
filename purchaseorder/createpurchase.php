<?php
/* =============================================================
   createpurchase.php  —  Main entry point
   All AJAX / POST handlers and DB migrations are inlined below.
   CSS and JS are inlined further below.
   ============================================================= */

require_once dirname(__DIR__) . '/db.php';

function get_next_prefixed_number(PDO $pdo, string $table, string $column, string $prefix, int $padLength, int $startNumber): array {
    $stmt = $pdo->query("SELECT `$column` AS doc_no FROM `$table`");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $maxNum = $startNumber - 1;
    $lastDoc = '';
    $pattern = '/^' . preg_quote($prefix, '/') . '(\d{' . $padLength . '})$/';
    foreach ($rows as $row) {
        $value = trim((string)($row['doc_no'] ?? ''));
        if ($value === '' || !preg_match($pattern, $value, $m)) continue;
        $num = (int)$m[1];
        if ($num > $maxNum) {
            $maxNum = $num;
            $lastDoc = $value;
        }
    }
    $nextNum = max($startNumber, $maxNum + 1);
    return [$prefix . str_pad((string)$nextNum, $padLength, '0', STR_PAD_LEFT), $lastDoc];
}

// ── Handle Add Company AJAX ──────────────────────────────────────
{
    $rawInput = file_get_contents('php://input');
    $jsonBody  = $rawInput ? json_decode($rawInput, true) : null;
    $_action    = is_array($jsonBody) ? ($jsonBody['action'] ?? '') : '';
    $_action    = $_action ?: ($_POST['action'] ?? '');
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_action === 'add_company') {
        header('Content-Type: application/json');
        $src = is_array($jsonBody) ? $jsonBody : $_POST;
        $coName = trim($src['company_name'] ?? '');
        if (!$coName) { echo json_encode(['success'=>false,'message'=>'Company name required']); exit; }
        try {
            try { $pdo->exec("ALTER TABLE invoice_company ADD COLUMN IF NOT EXISTS cin_number VARCHAR(50) DEFAULT ''"); } catch(Exception $e){}
            try { $pdo->exec("ALTER TABLE invoice_company ADD COLUMN IF NOT EXISTS website VARCHAR(255) DEFAULT ''"); } catch(Exception $e){}
            try { $pdo->exec("ALTER TABLE invoice_company ADD COLUMN IF NOT EXISTS company_logo TEXT DEFAULT NULL"); } catch(Exception $e){}
            $logoPath = trim($src['company_logo_existing'] ?? '');
            $uploadDir = dirname(__DIR__) . '/uploads/company/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            if (!empty($_FILES['company_logo']['name'])) {
                $logoName = time() . '_' . basename($_FILES['company_logo']['name']);
                $destPath = $uploadDir . $logoName;
                move_uploaded_file($_FILES['company_logo']['tmp_name'], $destPath);
                $logoPath = '/invoice/uploads/company/' . $logoName;
            }
            $pdo->prepare("INSERT INTO invoice_company
                (company_name, address_line1, address_line2, city, state, pincode, phone, email, gst_number, cin_number, pan, website, company_logo)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $coName,
                trim($src['address_line1'] ?? ''),
                trim($src['address_line2'] ?? ''),
                trim($src['city']          ?? ''),
                trim($src['state']         ?? ''),
                trim($src['pincode']       ?? ''),
                trim($src['phone']         ?? ''),
                trim($src['email']         ?? ''),
                strtoupper(trim($src['gst_number'] ?? '')),
                strtoupper(trim($src['cin_number'] ?? '')),
                strtoupper(trim($src['pan']        ?? '')),
                trim($src['website']       ?? ''),
                !empty($logoPath) ? $logoPath : null,
            ]);
            echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId(),'name'=>$coName,'company_logo'=>$logoPath]);
        } catch(Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }
}
   // provides $pdo

/* ── Auto-create tables if they don't exist ─────────────────── */
$pdo->exec("
    CREATE TABLE IF NOT EXISTS po_suppliers (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        supplier_name   VARCHAR(255) NOT NULL,
        contact_person  VARCHAR(255),
        phone           VARCHAR(30),
        email           VARCHAR(255),
        address         TEXT,
        gstin           VARCHAR(20),
        pan             VARCHAR(20),
        website         VARCHAR(255),
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS po_master_items (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        item_type       VARCHAR(50)   DEFAULT 'Product',
        item_name       VARCHAR(255)  NOT NULL,
        description     TEXT,
        hsn_sac         VARCHAR(50),
        unit            VARCHAR(30),
        rate            DECIMAL(15,2) DEFAULT 0.00,
        cgst_pct        DECIMAL(5,2)  DEFAULT 0.00,
        sgst_pct        DECIMAL(5,2)  DEFAULT 0.00,
        igst_pct        DECIMAL(5,2)  DEFAULT 0.00,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS po_master_terms (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        term_text  TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

/* ── GET: supplier list ──────────────────────────────────────── */
if (isset($_GET['get_suppliers'])) {
    header('Content-Type: application/json');
    $rows = $pdo->query("
        SELECT id, supplier_name, contact_person, phone, email, address, gstin
        FROM po_suppliers
        ORDER BY supplier_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}

/* ── GET: item (master) list ─────────────────────────────────── */
if (isset($_GET['get_items'])) {
    header('Content-Type: application/json');
    $rows = $pdo->query("
        SELECT id, item_type, item_name, description, hsn_sac, unit,
               rate, cgst_pct, sgst_pct, igst_pct
        FROM po_master_items
        ORDER BY item_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}

/* ── GET: terms list ─────────────────────────────────────────── */
if (isset($_GET['get_terms'])) {
    header('Content-Type: application/json');
    $rows = $pdo->query("
        SELECT term_text FROM po_master_terms ORDER BY id ASC
    ")->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($rows);
    exit;
}

/* ── POST handlers ───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ── POST: save new supplier → po_suppliers ──────────────── */
    if (!empty($_POST['save_supplier_ajax'])) {
        header('Content-Type: application/json');
        $name = trim($_POST['supplier_name'] ?? '');
        if (!$name) {
            echo json_encode(['success' => false, 'message' => 'Supplier name is required']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("
                INSERT INTO po_suppliers
                    (supplier_name, contact_person, phone, email, address, gstin, pan, website)
                VALUES
                    (:supplier_name, :contact_person, :phone, :email, :address, :gstin, :pan, :website)
            ");
            $stmt->execute([
                ':supplier_name'  => $name,
                ':contact_person' => trim($_POST['contact_person'] ?? ''),
                ':phone'          => trim($_POST['phone']          ?? ''),
                ':email'          => trim($_POST['email']          ?? ''),
                ':address'        => trim($_POST['address']        ?? ''),
                ':gstin'          => strtoupper(trim($_POST['gstin'] ?? '')),
                ':pan'            => strtoupper(trim($_POST['pan']  ?? '')),
                ':website'        => trim($_POST['website']        ?? ''),
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    /* ── POST: save new item → po_master_items ───────────────── */
    if (!empty($_POST['save_master_item'])) {
        header('Content-Type: application/json');
        $name = trim($_POST['item_name'] ?? '');
        if (!$name) {
            echo json_encode(['success' => false, 'message' => 'Item name is required']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("
                INSERT INTO po_master_items
                    (item_type, item_name, description, hsn_sac, unit, rate, cgst_pct, sgst_pct, igst_pct)
                VALUES
                    (:item_type, :item_name, :description, :hsn_sac, :unit, :rate, :cgst_pct, :sgst_pct, :igst_pct)
            ");
            $stmt->execute([
                ':item_type'   => trim($_POST['item_type']   ?? 'Product'),
                ':item_name'   => $name,
                ':description' => trim($_POST['description'] ?? ''),
                ':hsn_sac'     => trim($_POST['hsn_sac']     ?? ''),
                ':unit'        => trim($_POST['unit']        ?? ''),
                ':rate'        => (float)($_POST['rate']     ?? 0),
                ':cgst_pct'    => (float)($_POST['cgst_pct'] ?? 0),
                ':sgst_pct'    => (float)($_POST['sgst_pct'] ?? 0),
                ':igst_pct'    => (float)($_POST['igst_pct'] ?? 0),
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    /* ── POST: save new term → po_master_terms ───────────────── */
    if (!empty($_POST['save_term_ajax'])) {
        header('Content-Type: application/json');
        $text = trim($_POST['term_text'] ?? '');
        if (!$text) {
            echo json_encode(['success' => false, 'message' => 'Term text is required']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO po_master_terms (term_text) VALUES (:text)");
            $stmt->execute([':text' => $text]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

} // end POST block

/* ── Generate next PO number ─────────────────────────────────── */

[$next_num, $last] = get_next_prefixed_number($pdo, 'purchase_orders', 'po_number', 'ELT-PO-', 7, 2526001);

/* ── Edit mode ───────────────────────────────────────────────── */

$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$po      = null;
$items   = [];
$terms   = [];
$pageError = trim((string)($_GET['error'] ?? ''));

/* ── Load existing PO for editing ───────────────────────────── */
if ($edit_id) {
    $s = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=?");
    $s->execute([$edit_id]);
    $po = $s->fetch(PDO::FETCH_ASSOC);
    if ($po) {
        // Load items
        if (!empty($po['items_json'])) {
            $decoded = json_decode($po['items_json'], true);
            if (is_array($decoded)) $items = $decoded;
        }
        // Load terms
        if (!empty($po['terms_list'])) {
            $term_ids = json_decode($po['terms_list'], true);
            if (!empty($term_ids) && is_array($term_ids)) {
                $ph = implode(',', array_fill(0, count($term_ids), '?'));
                $ts = $pdo->prepare("SELECT id, term_text FROM po_master_terms WHERE id IN ($ph)");
                $ts->execute($term_ids);
                $terms = $ts->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
}

/* ── Default item rows ───────────────────────────────────────── */

$rows = (!empty($items) && is_array($items)) ? $items : [];

/* ── Company override (invoice_company) for PO ────────────────── */
$allCompanies = [];
try { $allCompanies = $pdo->query("SELECT * FROM invoice_company ORDER BY company_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) { $allCompanies = []; }
$signatures = [];
try { $signatures = $pdo->query("SELECT * FROM signatures ORDER BY signature_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) { $signatures = []; }
$companyBase = $allCompanies[0] ?? [];

$existingCompanyOverride = [];
if (is_array($po) && !empty($po['company_override'])) {
    $existingCompanyOverride = json_decode($po['company_override'], true);
    if (!is_array($existingCompanyOverride)) $existingCompanyOverride = [];
}

$popupCompany = !empty($existingCompanyOverride) ? array_merge($companyBase, $existingCompanyOverride) : $companyBase;
$selectedCompanyId = 0;
if (!empty($popupCompany['company_name'])) {
    foreach ($allCompanies as $co) {
        if (($co['company_name'] ?? '') === ($popupCompany['company_name'] ?? '')) {
            $selectedCompanyId = (int)($co['id'] ?? 0);
            break;
        }
    }
}
if (!$selectedCompanyId && !empty($companyBase['id'])) $selectedCompanyId = (int)$companyBase['id'];
try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN signature_id INT DEFAULT NULL"); } catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
    <title>Create Purchase Order</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
/* =============================================================
   Inline styles — po_style.css
   ============================================================= */
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;background:#f0f2f8;color:#1a1f2e;font-size:15px}
.content{margin-left:220px;padding:60px 16px 10px;min-height:100vh;background:#f0f2f8}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
.page-header-left{display:flex;align-items:center;gap:12px}
.page-icon{width:40px;height:40px;background:linear-gradient(135deg,#16a34a,#15803d);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;box-shadow:0 3px 10px rgba(22,163,74,.3)}
.page-title{font-size:19px;font-weight:800;color:#1a1f2e}
.page-sub{font-size:13px;color:#9ca3af;margin-top:1px}
.form-card{background:#fff;border:1px solid #e8ecf4;border-radius:14px;box-shadow:0 2px 6px rgba(0,0,0,.04);margin-bottom:6px;overflow:hidden}
.form-card-header{display:flex;align-items:center;gap:10px;padding:7px 14px;border-bottom:1px solid #f0f2f7;background:#fafbfd}
.hdr-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;flex-shrink:0}
.form-card-header h3{font-size:15px;font-weight:800;color:#1a1f2e;font-family:'Times New Roman',Times,serif}
.form-card-body{padding:8px 14px}
/* Professional field label style */
.field-section-label{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1px;color:#1f2937;margin-bottom:4px;display:block}
.supplier-section .field-section-label{font-size:10px;font-weight:900;color:#111827;letter-spacing:.6px}
.supplier-section .form-card-header h3{font-weight:900;color:#111827}
.field-select-styled{width:100%;padding:7px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:12px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;outline:none;cursor:pointer;transition:border-color .2s,box-shadow .2s;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center}
.field-select-styled:focus{border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.1)}
.field-input-styled{width:100%;padding:7px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:12px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;outline:none;transition:border-color .2s,box-shadow .2s}
.field-input-styled:focus{border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.1)}
.field-input-styled[readonly]{background:#f8fafc;cursor:pointer}
.supplier-field-wrap{display:flex;gap:6px;align-items:center}
.supplier-field-wrap .field-input-styled{flex:1}
label{display:block;font-size:12px;font-weight:900;color:#1f2937;text-transform:uppercase;letter-spacing:.6px;margin-bottom:5px}
.form-control,.form-select{width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:14px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;background:#fff;outline:none;transition:border-color .2s,box-shadow .2s;height:auto}
.form-control:focus,.form-select:focus{border-color:#16a34a;background:#fff;box-shadow:0 0 0 3px rgba(22,163,74,.08)}
textarea.form-control{height:48px;resize:vertical}
.form-control-sm{padding:5px 8px;font-size:13px}
.row>[class*=col]{margin-bottom:6px}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:6px}
.btn-theme{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:9px;font-size:14px;font-weight:700;font-family:'Times New Roman',Times,serif;cursor:pointer;transition:all .2s;box-shadow:0 3px 10px rgba(22,163,74,.25);text-decoration:none}
.btn-theme:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(22,163,74,.35)}
.btn-outline-theme{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#fff;border:1.5px solid #e4e8f0;border-radius:8px;font-size:14px;font-weight:600;font-family:'Times New Roman',Times,serif;color:#374151;text-decoration:none;cursor:pointer;transition:all .2s}
.btn-outline-theme:hover{border-color:#16a34a;color:#16a34a;background:#f0fdf4}
.btn-add-item-green{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif;transition:all .2s}
.btn-add-item-green:hover{transform:translateY(-1px)}
.btn-plus{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:8px;font-size:19px;cursor:pointer;transition:all .2s;flex-shrink:0}
.btn-plus:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(22,163,74,.3)}
.btn-danger-sm{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;color:#dc2626;cursor:pointer;font-size:12px;transition:all .2s}
.btn-danger-sm:hover{background:#dc2626;color:#fff}
.bottom-actions{display:flex;gap:10px;align-items:center;padding:10px 16px;border-top:1px solid #f0f2f7;background:#fafbfd}
.icon-btn{width:36px;height:36px;border-radius:8px;border:1.5px solid #e4e8f0;background:#f9fafb;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#6b7280;font-size:14px;flex-shrink:0}
.icon-btn:hover{border-color:#16a34a;color:#16a34a}
.supplier-input-row{display:flex;gap:6px}
.supplier-input-row .form-control{flex:1}
.table-wrap{overflow-x:auto}
.item-table{width:100%;border-collapse:collapse;font-size:12px;background:#fff}
.item-table thead tr:first-child th{background:#f0fdf4;color:#15803d;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;padding:6px 5px;border-bottom:2px solid #bbf7d0;white-space:nowrap}
.item-table th{padding:6px 5px;text-align:left;font-weight:700;color:#374151;border-bottom:1px solid #e4e8f0;white-space:nowrap;font-size:10px}
.item-table td{padding:4px 3px;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#374151}
.item-table tbody tr:hover td{background:#fafbff}
.item-table td input{width:100%;border:1.5px solid #e4e8f0;border-radius:5px;padding:4px 5px;font-size:11px;font-family:inherit;background:#fff;outline:none}
.item-table td input:focus{border-color:#16a34a;box-shadow:0 0 0 2px rgba(22,163,74,.1)}
.item-table td.num{text-align:right;padding:4px 6px;font-weight:600;white-space:nowrap}
.item-name-input{font-weight:600;color:#1a1f2e}
.item-desc-input{margin-top:2px;font-size:10px;color:#6b7280 !important;border-top:1px dashed #e4e8f0 !important}
.btn-remove-item{background:#fef2f2;color:#dc2626;border:1px solid #fca5a5;border-radius:5px;width:22px;height:22px;cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center}
.btn-remove-item:hover{background:#dc2626;color:#fff}
.btn-add-item{background:none;border:1.5px solid #16a34a;color:#16a34a;border-radius:7px;padding:5px 12px;cursor:pointer;font-size:11px;font-family:inherit;font-weight:700}
.btn-add-item:hover{background:#f0fdf4}
.total-box{text-align:right;margin-top:8px;font-size:12px;padding:8px 12px;background:#f8fafc;border-radius:8px;border:1px solid #e4e8f0}
.total-box .grand{font-size:14px;font-weight:800;color:#15803d;margin-top:4px;background:#f0fdf4;padding:4px 8px;border-radius:7px;display:inline-block}
.extra-btns{display:flex;gap:8px;margin-top:8px;justify-content:flex-end}
.btn-extra{background:none;border:1.5px solid #e4e8f0;border-radius:8px;padding:6px 12px;cursor:pointer;font-size:12px;font-family:inherit;color:#374151}
.btn-extra:hover{border-color:#16a34a;color:#16a34a}
.term-row{display:flex;align-items:center;gap:8px;background:#fafafa;border:1px solid #f0f2f7;border-radius:7px;padding:7px 10px;margin-bottom:5px;font-size:12px}
.term-row span{flex:1}
.term-actions{display:flex;gap:4px;flex-shrink:0}
.term-btn{width:24px;height:24px;border-radius:5px;border:none;cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center}
.term-btn-edit{background:#fff8e1;color:#f57c00}
.term-btn-del{background:#fef2f2;color:#dc2626}
.btn-add-term{background:none;border:1.5px dashed #16a34a;color:#16a34a;border-radius:8px;padding:7px 14px;cursor:pointer;font-size:12px;font-family:inherit;font-weight:700;margin-top:4px}
.save-row{display:flex;gap:12px;margin-top:10px;flex-wrap:wrap}
.sp-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:3000;align-items:center;justify-content:center}
.sp-overlay.open{display:flex}
.sp-box{background:#fff;border-radius:14px;width:380px;max-width:95vw;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 10px 40px rgba(0,0,0,.2);font-family:'Times New Roman',Times,serif;overflow:hidden}
.sp-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid #f0f2f7}
.sp-header h3{font-size:15px;font-weight:800;color:#1a1f2e}
.sp-close{background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;line-height:1}
.sp-search-wrap{padding:12px 18px;border-bottom:1px solid #f0f2f7}
.sp-search{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 12px 8px 32px;font-size:13px;font-family:inherit;outline:none;background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat 10px center}
.sp-search:focus{border-color:#16a34a}
.sp-list{overflow-y:auto;flex:1}
.sp-item{padding:12px 18px;cursor:pointer;border-bottom:1px solid #f9f9f9;transition:background .1s}
.sp-item:hover{background:#f0fdf4}
.sp-item-name{font-size:13px;font-weight:700;color:#1a1f2e}
.sp-item-sub{font-size:11px;color:#9ca3af;margin-top:2px}
.sp-empty{padding:30px;text-align:center;color:#9ca3af;font-size:13px}
.sp-footer{padding:14px 18px;border-top:1px solid #f0f2f7}
.sp-add-btn{width:100%;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:8px;padding:10px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px}
.sp-add-btn:hover{background:#15803d}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex !important}
.modal-box{background:#fff;border-radius:14px;width:440px;max-width:96vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);font-family:'Times New Roman',Times,serif}
.modal-box.sm{width:360px}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:1px solid #e4e8f0;position:sticky;top:0;background:#fafbfc;z-index:1;border-radius:14px 14px 0 0}
.modal-header h3{font-size:15px;font-weight:800;color:#1a1f2e;font-family:'Times New Roman',Times,serif}
.modal-header-btns{display:flex;gap:8px;align-items:center}
.modal-close{background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;line-height:1}
.modal-body{padding:16px 18px}
.mf-group{margin-bottom:14px}
.mf-label{display:block;font-size:12px;font-weight:900;color:#1f2937;margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px}
.mf-label .req{color:#ef4444}
.mf-input{width:100%;border:1.5px solid #e4e8f0;border-radius:8px;padding:8px 12px;font-size:13px;font-family:inherit;outline:none}
.mf-input:focus{border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.08)}
.mf-input.error{border-color:#ef4444 !important;background:#fff5f5}
.mf-input.valid{border-color:#22c55e !important}
.field-hint{font-size:11px;margin-top:3px;display:none}
.field-hint.error{display:block;color:#ef4444}
.field-hint.valid{display:block;color:#22c55e}
.form-control.error{border-color:#ef4444 !important;background:#fff5f5}
.val-toast{position:fixed;top:72px;left:50%;transform:translateX(-50%);background:#dc2626;color:#fff;padding:12px 24px;border-radius:10px;font-size:13px;font-weight:700;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,.25);display:none;min-width:260px;text-align:center}
.val-toast.show{display:block}
.req-star{color:#ef4444;margin-left:2px}
.mf-row{display:flex;gap:10px;align-items:flex-end}
.mf-row .mf-group{flex:1;margin-bottom:0}
.mf-select{width:100%;border:1.5px solid #e4e8f0;border-radius:8px;padding:8px 12px;font-size:13px;font-family:inherit;background:#fff;outline:none}
.mf-select:focus{border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.08)}
.mf-textarea{width:100%;border:1.5px solid #e4e8f0;border-radius:8px;padding:8px 12px;font-size:13px;font-family:inherit;outline:none;resize:vertical;min-height:70px}
.mf-textarea:focus{border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.08)}
.modal-footer{padding:12px 18px;border-top:1px solid #e4e8f0;display:flex;gap:8px;background:#fafbfc;border-radius:0 0 14px 14px}
.btn-modal-save{background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:8px;padding:9px 22px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px}
.btn-modal-save:hover{background:#15803d}
.btn-modal-cancel{background:#f5f5f5;color:#374151;border:1.5px solid #e4e8f0;border-radius:8px;padding:9px 16px;font-size:13px;cursor:pointer;font-family:inherit}
.btn-gstin{background:#2563eb;color:#fff;border:none;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}
.more-details{color:#16a34a;font-size:12px;font-weight:700;background:none;border:none;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:4px;margin-bottom:10px}
.extra-details{display:none}.extra-details.open{display:block}
.tab-btns{display:flex;gap:8px;margin-bottom:16px}
.tab-btn{padding:7px 16px;border-radius:8px;border:1.5px solid #e4e8f0;background:#f9fafb;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;color:#374151}
.tab-btn.active{background:#1a2940;color:#fff;border-color:#1a2940}
.term-select-list{max-height:260px;overflow-y:auto}
.term-select-item{padding:11px 14px;cursor:pointer;border-bottom:1px solid #f5f5f5;font-size:13px;transition:background .1s}
.term-select-item:hover{background:#f0fdf4;color:#16a34a}
.modal-search{width:100%;border:1.5px solid #e4e8f0;border-radius:8px;padding:8px 12px;font-size:13px;font-family:inherit;outline:none;margin-bottom:10px}
.modal-search:focus{border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.08)}
.prefix-box{display:flex;align-items:center;border:1.5px solid #e4e8f0;border-radius:8px;overflow:hidden}
.prefix-box span{background:#f9fafb;padding:8px 10px;font-size:13px;color:#6b7280;border-right:1px solid #e4e8f0;white-space:nowrap}
.prefix-box input{border:none;padding:8px 10px;font-size:13px;font-family:inherit;outline:none;flex:1;width:100%;min-width:0}
/* Shipping locked state — inputs stay in DOM (submit fine) but look+feel read-only */
.shipping-locked textarea,
.shipping-locked input[type="text"],
.shipping-locked input[type="tel"] {
    background:#f0fdf4 !important;
    color:#374151 !important;
    border-color:#bbf7d0 !important;
    cursor:not-allowed !important;
}
.shipping-locked-badge {
    display:none;
    font-size:11px;color:#16a34a;font-weight:700;
    background:#f0fdf4;border:1px solid #bbf7d0;
    border-radius:6px;padding:3px 8px;margin-bottom:6px;
}
.shipping-locked .shipping-locked-badge { display:inline-flex;align-items:center;gap:4px; }
.item-select-row{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #f5f5f5;cursor:pointer;transition:background .1s}
.item-select-row:hover,.item-select-row.selected{background:#f0fdf4}
.item-select-row.selected{border-left:3px solid #16a34a}
.item-select-name{font-size:13px;font-weight:700;color:#1a1f2e;flex:1}
.item-select-sub{font-size:11px;color:#9ca3af;margin-top:1px}
.item-select-tick{width:20px;height:20px;border-radius:50%;border:2px solid #e4e8f0;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;transition:all .15s}
.item-select-row.selected .item-select-tick{background:#16a34a;border-color:#16a34a;color:#fff}
.add-address-btn{display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;color:#16a34a;border:1.5px dashed #86efac;border-radius:8px;padding:8px 14px;font-size:13px;cursor:pointer;font-family:inherit;font-weight:600}
.bs-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.06),0 4px 16px rgba(0,0,0,.04);margin-bottom:8px;overflow:hidden}
.bs-card-header{display:flex;align-items:center;gap:12px;padding:9px 16px;border-bottom:1px solid #f1f5f9;background:linear-gradient(135deg,#f8faff 0%,#f0f7ff 100%)}
.bs-hdr-icon{width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,#2563eb,#1d4ed8);display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;flex-shrink:0;box-shadow:0 2px 8px rgba(37,99,235,.3)}
.bs-hdr-text h3{font-size:14px;font-weight:800;color:#0f172a;font-family:'Times New Roman',Times,serif;margin:0}
.bs-hdr-text p{font-size:10px;color:#94a3b8;margin:1px 0 0}
.bs-body{padding:0}
.bs-columns{display:grid;grid-template-columns:1fr 1fr;gap:0}
.bs-col-panel{padding:12px 16px}
.bs-col-panel.bill{border-right:1px solid #f1f5f9;background:#fafcff}
.bs-col-panel.ship{background:#fafffe}
.bs-col-label{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.bs-col-label-text{font-size:10px;font-weight:800;letter-spacing:.8px;text-transform:uppercase}
.bs-col-panel.bill .bs-col-label-text{color:#2563eb}
.bs-col-panel.ship .bs-col-label-text{color:#059669}
.bs-col-badge{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:5px;font-size:10px;font-weight:800;flex-shrink:0}
.bs-col-panel.bill .bs-col-badge{background:#dbeafe;color:#1d4ed8}
.bs-col-panel.ship .bs-col-badge{background:#d1fae5;color:#065f46}
.bs-req{color:#f97316;margin-left:2px;font-weight:700}
.bs-fields{display:flex;flex-direction:column;gap:9px}
.bs-field label{display:block;font-size:10px;font-weight:900;color:#1f2937;text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px}
.bs-field .gstin-badge{display:inline-block;font-size:9px;font-weight:800;letter-spacing:.06em;padding:1px 6px;border-radius:20px;background:#dcfce7;color:#166534;vertical-align:middle;margin-left:6px;border:1px solid #bbf7d0}
.bs-field input,.bs-field textarea{width:100%;padding:7px 10px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;font-family:'Times New Roman',Times,serif;color:#0f172a;background:#fff;outline:none;transition:border-color .2s,box-shadow .2s;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.bs-field input:focus,.bs-field textarea:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.bs-field textarea{resize:vertical;min-height:62px;line-height:1.5}
.bs-addr-btn{display:flex;align-items:center;gap:9px;width:100%;padding:10px 14px;background:linear-gradient(135deg,#eff6ff,#e0f2fe);color:#1d4ed8;border:1.5px dashed #93c5fd;border-radius:10px;font-size:13px;font-weight:700;font-family:'Times New Roman',Times,serif;cursor:pointer;transition:all .2s;text-align:left}
.bs-addr-btn:hover{background:linear-gradient(135deg,#dbeafe,#cce4fd);border-style:solid;box-shadow:0 2px 8px rgba(37,99,235,.15)}
.bs-addr-btn i{font-size:14px;color:#3b82f6;flex-shrink:0}
.bs-same-row{display:flex;align-items:center;gap:8px;font-size:12px;color:#64748b;cursor:pointer;user-select:none;margin-top:8px;padding:7px 10px;background:#f8fafc;border-radius:8px;border:1px solid #f1f5f9}
.bs-same-row:hover{background:#f0fdf4;border-color:#bbf7d0;color:#16a34a}
.bs-same-row input[type=checkbox]{width:14px;height:14px;accent-color:#16a34a;cursor:pointer;flex-shrink:0}
.bs-location-strip{padding:10px 16px;border-top:1px solid #f1f5f9;background:#f8fafc}
.bs-location-header{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.bs-location-title{font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.8px}
.bs-location-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:8px}
.bs-location-group{}
.bs-location-group .bs-loc-label{font-size:10px;font-weight:900;color:#1f2937;text-transform:uppercase;letter-spacing:.6px;margin-bottom:4px;display:block}
.bs-location-group input{width:100%;padding:6px 8px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:11px;font-family:'Times New Roman',Times,serif;color:#0f172a;background:#fff;outline:none;transition:border-color .2s,box-shadow .2s}
.bs-location-group input:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.08)}
.bs-loc-divider{display:flex;align-items:center;gap:8px;margin:0 4px;grid-column:span 0}
.bs-bill-loc{position:relative}
.bs-bill-loc::after{content:'';position:absolute;top:0;bottom:0;right:-5px;width:1px;background:#e2e8f0}
::-webkit-scrollbar{width:3px}::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:99px}
@media(max-width:900px){.two-col{grid-template-columns:1fr}.content{margin-left:0!important;padding:70px 12px 20px}}
@media(max-width:700px){.bs-columns{grid-template-columns:1fr}.bs-divider{display:none}.bs-three{grid-template-columns:1fr 1fr}}
    </style>
</head>
<body>
<?php include dirname(__DIR__) . '/sidebar.php'; ?>
<?php include dirname(__DIR__) . '/header.php'; ?>

<div class="content">
<?php if ($pageError !== ''): ?>
    <div style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;padding:8px 12px;border-radius:8px;margin-bottom:8px;font-size:12px;">
        <i class="fas fa-exclamation-circle" style="margin-right:6px"></i><?= htmlspecialchars($pageError) ?>
    </div>
<?php endif; ?>
<form id="poForm" method="POST" action="savepurchase.php">

    <input type="hidden" name="action" id="formAction" value="save">
    <?php if ($edit_id): ?>
        <input type="hidden" name="edit_id" value="<?= $edit_id ?>">
    <?php endif; ?>

    <!-- Company override hidden fields (posted to savepurchase.php) -->
    <input type="hidden" name="co_company_changed" id="co_company_changed" value="1">
    <input type="hidden" name="co_company_name"   id="co_company_name"   value="<?= htmlspecialchars($popupCompany['company_name'] ?? '') ?>">
    <input type="hidden" name="co_company_logo"   id="co_company_logo"   value="<?= htmlspecialchars($popupCompany['company_logo'] ?? '') ?>">
    <input type="hidden" name="co_address_line1" id="co_address_line1" value="<?= htmlspecialchars($popupCompany['address_line1'] ?? '') ?>">
    <input type="hidden" name="co_address_line2" id="co_address_line2" value="<?= htmlspecialchars($popupCompany['address_line2'] ?? '') ?>">
    <input type="hidden" name="co_city"          id="co_city"          value="<?= htmlspecialchars($popupCompany['city'] ?? '') ?>">
    <input type="hidden" name="co_state"         id="co_state"         value="<?= htmlspecialchars($popupCompany['state'] ?? '') ?>">
    <input type="hidden" name="co_pincode"       id="co_pincode"       value="<?= htmlspecialchars($popupCompany['pincode'] ?? '') ?>">
    <input type="hidden" name="co_phone"         id="co_phone"         value="<?= htmlspecialchars($popupCompany['phone'] ?? '') ?>">
    <input type="hidden" name="co_email"         id="co_email"         value="<?= htmlspecialchars($popupCompany['email'] ?? '') ?>">
    <input type="hidden" name="co_gst_number"    id="co_gst_number"    value="<?= htmlspecialchars($popupCompany['gst_number'] ?? '') ?>">
    <input type="hidden" name="co_cin_number"    id="co_cin_number"    value="<?= htmlspecialchars($popupCompany['cin_number'] ?? '') ?>">
    <input type="hidden" name="co_pan"           id="co_pan"           value="<?= htmlspecialchars($popupCompany['pan'] ?? '') ?>">
    <input type="hidden" name="co_website"       id="co_website"       value="<?= htmlspecialchars($popupCompany['website'] ?? '') ?>">

    <!-- ── Page header ─────────────────────────────────────────── -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon"><i class="fas fa-shopping-cart"></i></div>
            <div>
                <div class="page-title">Create Purchase Order</div>
                <div class="page-sub">Fill in the details to generate a new purchase order</div>
            </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
            <a href="pindex.php" class="btn-outline-theme"><i class="fas fa-arrow-left"></i> Back</a>
            <div style="display:flex;gap:8px;align-items:center">
                <select id="company_select" name="selected_company_id" class="field-select-styled" style="min-width:260px">
                    <option value="">-- Select Company --</option>
                    <?php foreach ($allCompanies as $co): ?>
                        <option value="<?= $co['id'] ?>"
                            <?= ($selectedCompanyId === (int)($co['id'] ?? 0)) ? 'selected' : '' ?>
                            data-name="<?= htmlspecialchars($co['company_name'] ?? '') ?>"
                            data-logo="<?= htmlspecialchars($co['company_logo'] ?? '') ?>"
                            data-line1="<?= htmlspecialchars($co['address_line1'] ?? '') ?>"
                            data-line2="<?= htmlspecialchars($co['address_line2'] ?? '') ?>"
                            data-city="<?= htmlspecialchars($co['city'] ?? '') ?>"
                            data-state="<?= htmlspecialchars($co['state'] ?? '') ?>"
                            data-pincode="<?= htmlspecialchars($co['pincode'] ?? '') ?>"
                            data-phone="<?= htmlspecialchars($co['phone'] ?? '') ?>"
                            data-email="<?= htmlspecialchars($co['email'] ?? '') ?>"
                            data-gst="<?= htmlspecialchars($co['gst_number'] ?? '') ?>"
                            data-cin="<?= htmlspecialchars($co['cin_number'] ?? '') ?>"
                            data-pan="<?= htmlspecialchars($co['pan'] ?? '') ?>"
                            data-website="<?= htmlspecialchars($co['website'] ?? '') ?>"
                        ><?= htmlspecialchars($co['company_name'] ?? ('Company #'.($co['id'] ?? ''))) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-plus" onclick="openAddCompanyModal()" title="Add Company">
                    <i class="fas fa-plus"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- ── Supplier ────────────────────────────────────────────── -->
    <div class="form-card supplier-section">
        <div class="form-card-header">
            <div class="hdr-icon" style="background:linear-gradient(135deg,#f97316,#fb923c)"><i class="fas fa-truck"></i></div>
            <h3>Supplier</h3>
        </div>
        <div class="form-card-body">
            <div class="row">
                <div class="col-md-4">
                    <span class="field-section-label">Supplier</span>
                    <div class="supplier-field-wrap">
                        <input class="field-input-styled" type="text" name="supplier_name" id="supplierInput"
                               value="<?= htmlspecialchars($po['supplier_name'] ?? '') ?>"
                               placeholder="— Select Supplier —"
                               onclick="openSupplierPopup()" readonly>
                        <button type="button" class="btn-plus" onclick="openNewSupplierForm()" title="Add Supplier"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
                <div class="col-md-3">
                    <span class="field-section-label">Contact Person</span>
                    <input class="field-input-styled" type="text" name="contact_person" id="contactInput"
                           value="<?= htmlspecialchars($po['contact_person'] ?? '') ?>" placeholder="Contact name">
                </div>
                <div class="col-md-2">
                    <span class="field-section-label">Phone</span>
                    <input class="field-input-styled" type="text" name="contact_phone"
                           value="<?= htmlspecialchars($po['contact_phone'] ?? '') ?>" placeholder="Phone number">
                </div>
                <div class="col-md-3">
                    <span class="field-section-label">GSTIN</span>
                    <input class="field-input-styled" type="text" name="supplier_gstin" id="supplierGstinInput"
                           value="<?= htmlspecialchars($po['supplier_gstin'] ?? '') ?>" placeholder="27AABCS1234A1Z5" style="text-transform:uppercase">
                </div>
            </div>
        </div>
    </div>

    <!-- ── 3-Column Grid ─────────────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-bottom:6px">


    <!-- COL 1: Billing Address -->
    <div class="form-card" style="margin-bottom:0">
        <div class="form-card-header">
            <div class="hdr-icon" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)"><i class="fas fa-file-invoice"></i></div>
            <h3>Billing Address</h3>
        </div>
        <div class="form-card-body">
            <div style="margin-bottom:6px">
                <label>Address</label>
                <textarea class="form-control" name="billing_address" id="sourceAddrHidden" style="height:52px;font-size:12px;padding:5px 8px" placeholder="Enter billing address"><?= htmlspecialchars($po['billing_address'] ?? '') ?></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px;margin-bottom:6px">
                <div>
                    <label>City</label>
                    <input type="text" class="form-control" name="billing_city" id="billing_city" style="font-size:12px;padding:5px 8px" value="<?= htmlspecialchars($po['billing_city'] ?? '') ?>" placeholder="City">
                </div>
                <div>
                    <label>State</label>
                    <input type="text" class="form-control" name="billing_state" id="billing_state" style="font-size:12px;padding:5px 8px" value="<?= htmlspecialchars($po['billing_state'] ?? '') ?>" placeholder="State">
                </div>
                <div>
                    <label>Pincode</label>
                    <input type="text" class="form-control" name="billing_pincode" id="billing_pincode" maxlength="6" style="font-size:12px;padding:5px 8px" value="<?= htmlspecialchars($po['billing_pincode'] ?? '') ?>" placeholder="500001">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px">
                <div>
                    <label>GSTIN</label>
                    <input type="text" class="form-control" name="billing_gstin" id="billing_gstin" style="font-size:12px;padding:5px 8px;text-transform:uppercase" value="<?= htmlspecialchars($po['billing_gstin'] ?? '') ?>" placeholder="22AAAAA0000A1Z5" maxlength="15">
                    <span id="billing_gstin_hint" style="font-size:10px;margin-top:2px;display:none;font-weight:600"></span>
                </div>
                <div>
                    <label>Phone</label>
                    <input type="tel" class="form-control" name="billing_phone" id="billing_phone" style="font-size:12px;padding:5px 8px" value="<?= htmlspecialchars($po['billing_phone'] ?? '') ?>" placeholder="+91 00000 00000" maxlength="10">
                    <span id="billing_phone_hint" style="font-size:10px;margin-top:2px;display:none;font-weight:600"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- COL 2: Shipping Address -->
    <div class="form-card" style="margin-bottom:0">
        <div class="form-card-header">
            <div class="hdr-icon" style="background:linear-gradient(135deg,#059669,#047857)"><i class="fas fa-shipping-fast"></i></div>
            <h3>Shipping Address</h3>
        </div>
        <div class="form-card-body">
            <div style="margin-bottom:6px">
                <label>Address</label>
                <textarea class="form-control" name="shipping_address" id="shippingDetails" style="height:52px;font-size:12px;padding:5px 8px;background:#f0fdf4;border-color:#bbf7d0;color:#374151" placeholder="Enter shipping address"><?= htmlspecialchars($po['shipping_address'] ?? '') ?></textarea>
                <div class="form-check" style="margin-top:3px">
                    <input class="form-check-input" type="checkbox" id="same_as_billing" onchange="bsSyncAddr(this)">
                    <label class="form-check-label" for="same_as_billing" style="text-transform:none;letter-spacing:0;font-size:10px;color:#6b7280;font-weight:400">Same as Billing</label>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px;margin-bottom:6px">
                <div>
                    <label>City</label>
                    <input type="text" class="form-control" name="shipping_city" id="shipping_city" style="font-size:12px;padding:5px 8px" value="<?= htmlspecialchars($po['shipping_city'] ?? '') ?>" placeholder="City">
                </div>
                <div>
                    <label>State</label>
                    <input type="text" class="form-control" name="shipping_state" id="shipping_state" style="font-size:12px;padding:5px 8px" value="<?= htmlspecialchars($po['shipping_state'] ?? '') ?>" placeholder="State">
                </div>
                <div>
                    <label>Pincode</label>
                    <input type="text" class="form-control" name="shipping_pincode" id="shipping_pincode" maxlength="6" style="font-size:12px;padding:5px 8px" value="<?= htmlspecialchars($po['shipping_pincode'] ?? '') ?>" placeholder="500001">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px">
                <div>
                    <label>GSTIN</label>
                    <input type="text" class="form-control" name="shipping_gstin" id="shipping_gstin" style="font-size:12px;padding:5px 8px;text-transform:uppercase" value="<?= htmlspecialchars($po['shipping_gstin'] ?? '') ?>" placeholder="22AAAAA0000A1Z5" maxlength="15">
                    <span id="shipping_gstin_hint" style="font-size:10px;margin-top:2px;display:none;font-weight:600"></span>
                </div>
                <div>
                    <label>Phone</label>
                    <input type="tel" class="form-control" name="shipping_phone" id="shipping_phone" style="font-size:12px;padding:5px 8px" value="<?= htmlspecialchars($po['shipping_phone'] ?? '') ?>" placeholder="+91 00000 00000" maxlength="10">
                    <span id="shipping_phone_hint" style="font-size:10px;margin-top:2px;display:none;font-weight:600"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- COL 3: Document Details -->
    <div class="form-card" style="margin-bottom:0">
        <div class="form-card-header">
            <div class="hdr-icon" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed)"><i class="fas fa-file-alt"></i></div>
            <h3>Document Details</h3>
        </div>
        <div class="form-card-body">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px;margin-bottom:6px">
                <div>
                    <label>PO No.</label>
                    <input class="form-control" type="text" name="po_number" style="font-size:13px;padding:5px 8px"
                           value="<?= htmlspecialchars($po['po_number'] ?? $next_num) ?>">
                </div>
                <div>
                    <label>Reference</label>
                    <input class="form-control" type="text" name="reference" style="font-size:13px;padding:5px 8px"
                           value="<?= htmlspecialchars($po['reference'] ?? '') ?>">
                </div>
                <div>
                    <label>Supplier GSTIN</label>
                    <input class="form-control" type="text" name="supplier_gstin" id="supplierGstinInput" style="font-size:13px;padding:5px 8px;text-transform:uppercase"
                           value="<?= htmlspecialchars($po['supplier_gstin'] ?? '') ?>" placeholder="27AABCS1234A1Z5">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-bottom:6px">
                <div>
                    <label>PO Date</label>
                    <input class="form-control" type="date" name="po_date" style="font-size:13px;padding:5px 8px"
                           value="<?= htmlspecialchars($po['po_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div>
                    <label>Due Date</label>
                    <input class="form-control" type="date" name="due_date" style="font-size:13px;padding:5px 8px"
                           value="<?= htmlspecialchars($po['due_date'] ?? date('Y-m-d')) ?>">
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px">
                <div>
                    <label>Contact Person</label>
                    <input class="form-control" type="text" name="contact_person" id="contactInput" style="font-size:13px;padding:5px 8px"
                           value="<?= htmlspecialchars($po['contact_person'] ?? '') ?>" placeholder="Contact name">
                </div>
                <div>
                    <label>Phone</label>
                    <input class="form-control" type="text" name="contact_phone" style="font-size:13px;padding:5px 8px"
                           value="<?= htmlspecialchars($po['contact_phone'] ?? '') ?>" placeholder="Phone number">
                </div>
            </div>
        </div>
    </div>

    </div><!-- /3-col grid -->

    <!-- ── Item list ────────────────────────────────────────────── -->
    <div class="form-card">
        <div class="form-card-header" style="justify-content:space-between">
            <div style="display:flex;align-items:center;gap:10px">
                <div class="hdr-icon" style="background:linear-gradient(135deg,#16a34a,#15803d)"><i class="fas fa-list"></i></div>
                <h3>Item List</h3>
            </div>
            <div style="display:flex;gap:8px">
            </div>
        </div>
        <div class="form-card-body" style="padding:0">
        <div class="table-wrap">
        <table class="item-table">
            <thead>
                <tr>
                    <th style="width:35px;padding:8px 7px">#</th>
                    <th style="min-width:180px;">Item</th>
                    <th style="width:70px;">HSN/SAC</th>
                    <th style="width:60px;">Qty</th>
                    <th style="width:50px;">Unit</th>
                    <th style="width:85px;">Rate (₹)</th>
                    <th style="width:80px;">Disc (₹)</th>
                    <th style="width:85px;">Taxable (₹)</th>
                    <th style="width:55px;">CGST%</th>
                    <th style="width:75px;">CGST (₹)</th>
                    <th style="width:55px;">SGST%</th>
                    <th style="width:75px;">SGST (₹)</th>
                    <th style="width:55px;">IGST%</th>
                    <th style="width:75px;">IGST (₹)</th>
                    <th style="width:90px;">Amount (₹)</th>
                    <th style="width:32px;"></th>
                </tr>
            </thead>
            <tbody id="itemBody">
            <?php foreach ($rows as $i => $it): ?>
            <tr class="item-row" data-index="<?= $i ?>">
                <td class="num"><?= $i + 1 ?></td>
                <td>
                    <input type="text" class="item-name-input" name="items[<?= $i ?>][item_name]"
                           value="<?= htmlspecialchars($it['item_name']) ?>" placeholder="Item name" required>
                    <input type="text" class="item-desc-input" name="items[<?= $i ?>][description]"
                           value="<?= htmlspecialchars($it['description'] ?? '') ?>" placeholder="Description (optional)">
                </td>
                <td><input type="text"   name="items[<?= $i ?>][hsn_sac]"   value="<?= htmlspecialchars($it['hsn_sac'] ?? '') ?>"></td>
                <td><input type="number" name="items[<?= $i ?>][qty]"        value="<?= $it['qty'] ?>" min="0" step="0.001" oninput="calcRow(this)"></td>
                <td><input type="text"   name="items[<?= $i ?>][unit]"       value="<?= htmlspecialchars($it['unit'] ?? '') ?>" placeholder="pcs"></td>
                <td><input type="number" name="items[<?= $i ?>][rate]"       value="<?= $it['rate'] ?>" min="0" step="0.01" oninput="calcRow(this)"></td>
                <td><input type="number" name="items[<?= $i ?>][discount]"   value="<?= $it['discount'] ?? 0 ?>" min="0" step="0.01" oninput="calcRow(this)"></td>
                <td class="num taxable-cell"><?= number_format($it['taxable'] ?? 0, 2) ?><input type="hidden" name="items[<?= $i ?>][taxable]"  value="<?= $it['taxable'] ?? 0 ?>"></td>
                <td><input type="number" name="items[<?= $i ?>][cgst_pct]"   value="<?= $it['cgst_pct'] ?? 0 ?>" min="0" step="0.01" placeholder="0" oninput="calcRow(this)"></td>
                <td class="num cgst-cell"><?= number_format($it['cgst_amt'] ?? 0, 2) ?><input type="hidden" name="items[<?= $i ?>][cgst_amt]" value="<?= $it['cgst_amt'] ?? 0 ?>"></td>
                <td><input type="number" name="items[<?= $i ?>][sgst_pct]"   value="<?= $it['sgst_pct'] ?? 0 ?>" min="0" step="0.01" placeholder="0" oninput="calcRow(this)"></td>
                <td class="num sgst-cell"><?= number_format($it['sgst_amt'] ?? 0, 2) ?><input type="hidden" name="items[<?= $i ?>][sgst_amt]" value="<?= $it['sgst_amt'] ?? 0 ?>"></td>
                <td><input type="number" name="items[<?= $i ?>][igst_pct]"   value="<?= $it['igst_pct'] ?? 0 ?>" min="0" step="0.01" placeholder="0" oninput="calcRow(this)"></td>
                <td class="num igst-cell"><?= number_format($it['igst_amt'] ?? 0, 2) ?><input type="hidden" name="items[<?= $i ?>][igst_amt]" value="<?= $it['igst_amt'] ?? 0 ?>"></td>
                <td class="num amt-cell"><?= number_format($it['amount'] ?? 0, 2) ?><input type="hidden" name="items[<?= $i ?>][amount]" value="<?= $it['amount'] ?? 0 ?>"></td>
                <td><button type="button" class="btn-remove-item" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div style="padding:8px 14px;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:8px">
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <button type="button" class="btn-add-item" onclick="openSelectItemModal()"><i class="fas fa-plus"></i> Add Item</button>
                <button type="button" class="btn-extra"    onclick="openModal('extraChargeModal')"><i class="fas fa-plus"></i> Add Extra Charge</button>
            </div>
            <div class="total-box" style="min-width:200px">
                <div style="color:#6b7280">Taxable : ₹ <span id="totalTaxable">0.00</span></div>
                <div style="color:#6b7280">CGST : ₹ <span id="totalCgst">0.00</span></div>
                <div style="color:#6b7280">SGST : ₹ <span id="totalSgst">0.00</span></div>
                <div style="color:#6b7280">IGST : ₹ <span id="totalIgst">0.00</span></div>
                <div id="roundOffRow" style="display:none;color:#6b7280">
                    <span style="margin-right:2px;">🗑</span> Round off : ₹ <span id="roundOffAmt">0.00</span>
                    <button type="button" onclick="toggleRoundOff()" title="Remove Round Off"
                        style="margin-left:6px;background:none;border:none;color:#dc2626;cursor:pointer;font-size:11px;padding:0;">✕</button>
                </div>
                <div class="grand">Grand Total : ₹ <span id="grandTotal">0.00</span></div>
                <div style="margin-top:8px;">
                    <button type="button" id="addRoundOffBtn" onclick="toggleRoundOff()"
                        style="padding:6px 14px;border-radius:7px;border:1.5px solid #16a34a;background:#f0fdf4;color:#16a34a;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;">
                        <i class="fas fa-plus"></i> Add Round Off
                    </button>
                </div>
            </div>
        </div>
        </div>
    </div>

    <!-- ── Terms & Conditions ───────────────────────────────────── -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="hdr-icon" style="background:linear-gradient(135deg,#0ea5e9,#0284c7)"><i class="fas fa-file-contract"></i></div>
            <h3>Terms &amp; Conditions</h3>
        </div>
        <div class="form-card-body">
            <div id="termsList">
            <?php foreach ($terms as $term):
                $term_text = is_array($term) ? ($term['term_text'] ?? '') : $term;
            ?>
                <div class="term-row">
                    <span><?= htmlspecialchars($term_text) ?></span>
                    <div class="term-actions">
                        <button type="button" class="term-btn term-btn-edit" onclick="editTerm(this)"><i class="fas fa-pencil-alt"></i></button>
                        <button type="button" class="term-btn term-btn-del"  onclick="removeTerm(this)"><i class="fas fa-times"></i></button>
                    </div>
                    <input type="hidden" name="terms[]" value="<?= htmlspecialchars($term_text) ?>">
                </div>
            <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add-term" onclick="openTermsPopup()"><i class="fas fa-plus"></i> Add Term / Condition</button>
        </div>
    </div>

    <!-- ── Notes ────────────────────────────────────────────────── -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="hdr-icon" style="background:linear-gradient(135deg,#6b7280,#4b5563)"><i class="fas fa-sticky-note"></i></div>
            <h3>Notes</h3>
        </div>
        <div class="form-card-body">
            <textarea class="form-control" name="notes" rows="3"
                      placeholder="Additional notes..."><?= htmlspecialchars($po['notes'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="form-card">
        <div class="form-card-header">
            <div class="hdr-icon" style="background:linear-gradient(135deg,#10b981,#059669)"><i class="fas fa-signature"></i></div>
            <h3>Authorised Signature</h3>
        </div>
        <div class="form-card-body">
            <label>Authorised Signature</label>
            <div style="display:flex;gap:8px;align-items:center">
                <select name="signature_id" id="signature_select" class="form-control">
                    <option value="">-- Select Signature --</option>
                    <?php foreach ($signatures as $sig): ?>
                        <option value="<?= $sig['id']; ?>" data-path="<?= htmlspecialchars($sig['file_path']); ?>" <?= ((string)($po['signature_id'] ?? '') === (string)$sig['id']) ? 'selected' : '' ?>><?= htmlspecialchars($sig['signature_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-plus" onclick="openModal('addSignatureModal')" title="Add Signature"><i class="fas fa-plus"></i></button>
                <img id="signature_preview" src="" style="max-height:32px;max-width:80px;object-fit:contain;display:none;border:1px dashed #ccc;border-radius:4px;padding:2px">
            </div>
        </div>
    </div>

    <!-- ── Bottom actions ───────────────────────────────────────── -->
    <div class="form-card">
        <div class="bottom-actions">
            <button type="button" class="btn-theme" onclick="validateAndSubmit('save')"><i class="fas fa-save"></i> Save PO</button>
            <button type="button" class="btn-theme" style="background:linear-gradient(135deg,#2563eb,#1d4ed8)" onclick="validateAndSubmit('save_another')">
                <i class="fas fa-check"></i> Save &amp; New
            </button>
            <a href="pindex.php" class="btn-outline-theme"><i class="fas fa-times"></i> Cancel</a>
        </div>
    </div>

</form>
</div>

<!-- Validation toast -->
<div class="val-toast" id="valToast"></div>

<!-- ═══════════════════════════════ SUPPLIER POPUP ════════════ -->
<div class="sp-overlay" id="spOverlay" onclick="closeSupplierPopup(event)">
    <div class="sp-box" onclick="event.stopPropagation()">
        <div class="sp-header">
            <h3>Select Supplier</h3>
            <button class="sp-close" onclick="closeSupplierPopup()">✕</button>
        </div>
        <div class="sp-search-wrap">
            <input class="sp-search" id="spSearch" type="text" placeholder="Search suppliers..." oninput="filterSuppliers(this.value)">
        </div>
        <div class="sp-list" id="spList">
            <div class="sp-empty">Loading...</div>
        </div>
        <div class="sp-footer">
            <button class="sp-add-btn" onclick="openNewSupplierForm()"><i class="fas fa-plus"></i> Add New Supplier</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════ MODAL: Add New Supplier ═══ -->
<div class="modal-overlay" id="supplierModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Add New Supplier</h3>
      <div class="modal-header-btns">
        <button class="btn-modal-save" onclick="saveNewSupplier()"><i class="fas fa-check"></i> Save</button>
        <button class="modal-close" onclick="closeModal('supplierModal')">✕</button>
      </div>
    </div>
    <div class="modal-body">
      <div class="mf-group">
        <label class="mf-label">Business / Company Name</label>
        <input class="mf-input" id="ns_business" type="text" placeholder="Company name">
      </div>
      <div class="mf-row">
        <div class="mf-group">
          <label class="mf-label">Contact Person</label>
          <input class="mf-input" id="ns_contact" type="text" placeholder="Full name">
        </div>
        <div class="mf-group">
          <label class="mf-label">Mobile</label>
          <div class="prefix-box"><span>+91</span><input id="ns_mobile" type="text" placeholder="Mobile"></div>
        </div>
      </div>
      <div class="mf-group">
        <label class="mf-label">Email</label>
        <input class="mf-input" id="ns_email" type="email" placeholder="supplier@email.com">
      </div>
      <div class="mf-group">
        <label class="mf-label">Address</label>
        <textarea class="mf-textarea" id="ns_address" placeholder="Full address" style="min-height:60px"></textarea>
      </div>
      <div class="mf-row">
        <div class="mf-group">
          <label class="mf-label">GSTIN</label>
          <input class="mf-input" id="ns_gstin" type="text" placeholder="22AAAAA0000A1Z5" maxlength="15"
                 oninput="validateGSTIN(this,'ns_gstin_hint')" style="text-transform:uppercase;">
          <div class="field-hint" id="ns_gstin_hint"></div>
        </div>
        <div class="mf-group">
          <label class="mf-label">PAN</label>
          <input class="mf-input" id="ns_pan" type="text" placeholder="ABCDE1234F" maxlength="10"
                 oninput="validatePAN(this,'ns_pan_hint')" style="text-transform:uppercase;">
          <div class="field-hint" id="ns_pan_hint"></div>
        </div>
      </div>
      <div class="mf-group">
        <label class="mf-label">Website</label>
        <input class="mf-input" id="ns_website" type="text" placeholder="https://example.com">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-modal-save" onclick="saveNewSupplier()"><i class="fas fa-check"></i> Save</button>
      <button class="btn-modal-cancel" onclick="closeModal('supplierModal')">Cancel</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════ MODAL: Select Item ════════ -->
<div class="modal-overlay" id="selectItemModal">
  <div class="modal-box" style="width:540px;">
    <div class="modal-header">
      <h3>📦 Item Library</h3>
      <button class="modal-close" onclick="closeModal('selectItemModal')">✕</button>
    </div>
    <div class="modal-body" style="padding:12px 16px 6px;">
      <input class="modal-search" id="itemSearch" type="text" placeholder="🔍 Search by name, HSN, description..." oninput="filterItems(this.value)">
      <div id="itemSelectList" style="max-height:400px;overflow-y:auto;border:1px solid #f0f2f7;border-radius:8px;">
        <div style="padding:30px;text-align:center;color:#9ca3af;font-size:13px;">Loading...</div>
      </div>
      <p style="font-size:11px;color:#9ca3af;margin-top:6px;text-align:center;">Click <b>+ Add</b> on any item to add it to the PO. You can add multiple items.</p>
    </div>
    <div class="modal-footer" style="justify-content:space-between;">
      <button class="btn-modal-save" style="background:#1a2940;" onclick="closeModal('selectItemModal');openModal('addItemModal');">
        <i class="fas fa-plus"></i> Create New Item
      </button>
      <button class="btn-modal-save" style="background:#2e7d32;" onclick="closeModal('selectItemModal')">
        <i class="fas fa-check"></i> Done
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════ MODAL: Add Item ══════════ -->
<div class="modal-overlay" id="addItemModal">
  <div class="modal-box sm" style="width:360px;">
    <div class="modal-header" style="padding:12px 16px;">
      <h3>Add Item</h3>
      <button class="modal-close" onclick="closeModal('addItemModal')">✕</button>
    </div>
    <div class="modal-body" style="padding:12px 16px;">
      <div class="mf-row" style="margin-bottom:10px;">
        <div class="mf-group" style="flex:1;margin-bottom:0;">
          <label class="mf-label">Item Name</label>
          <input class="mf-input" id="ai_name" type="text" placeholder="Item name">
        </div>
      </div>
      <div class="mf-row" style="margin-bottom:10px;">
        <div class="mf-group" style="flex:1;margin-bottom:0;">
          <label class="mf-label">Rate</label>
          <div class="prefix-box"><span>₹</span><input id="ai_rate" type="number" value="0" min="0" step="0.01"></div>
        </div>
        <div class="mf-group" style="flex:0 0 100px;margin-bottom:0;">
          <label class="mf-label">Unit</label>
          <select class="mf-select" id="ai_unit">
            <option>no.s</option><option>pcs</option><option>kg</option><option>m</option><option>ltr</option><option>set</option><option>hr</option>
          </select>
        </div>
      </div>
      <div class="mf-group" style="margin-bottom:10px;">
        <label class="mf-label">HSN/SAC</label>
        <input class="mf-input" id="ai_hsn" type="text" placeholder="HSN/SAC code">
      </div>
      <div class="mf-group" style="margin-bottom:10px;">
        <label class="mf-label">Description</label>
        <textarea class="mf-textarea" id="ai_desc" placeholder="Description (optional)" style="min-height:50px;"></textarea>
      </div>
      <div class="mf-row">
        <div class="mf-group" style="margin-bottom:0;">
          <label class="mf-label">CGST %</label>
          <div class="prefix-box"><input id="ai_cgst" type="number" value="0" min="0" step="0.01"><span>%</span></div>
        </div>
        <div class="mf-group" style="margin-bottom:0;">
          <label class="mf-label">SGST %</label>
          <div class="prefix-box"><input id="ai_sgst" type="number" value="0" min="0" step="0.01"><span>%</span></div>
        </div>
        <div class="mf-group" style="margin-bottom:0;">
          <label class="mf-label">IGST %</label>
          <div class="prefix-box"><input id="ai_igst" type="number" value="0" min="0" step="0.01"><span>%</span></div>
        </div>
      </div>
    </div>
    <div class="modal-footer" style="padding:10px 16px;">
      <button class="btn-modal-save" onclick="saveAddItem(true)"><i class="fas fa-bookmark"></i> Save &amp; Add</button>
      <button class="btn-modal-cancel" onclick="closeModal('addItemModal');openModal('selectItemModal');">← Back</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════ MODAL: Extra Charge ══════ -->
<div class="modal-overlay" id="extraChargeModal">
  <div class="modal-box sm">
    <div class="modal-header">
      <h3>Add Extra Charge</h3>
      <button class="modal-close" onclick="closeModal('extraChargeModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="mf-group">
        <label class="mf-label">Charge Name</label>
        <input class="mf-input" id="ec_item" type="text" placeholder="e.g. Freight, Packing">
      </div>
      <div class="mf-group">
        <label class="mf-label">Amount ₹</label>
        <div class="prefix-box"><span>₹</span><input id="ec_amount" type="number" value="0" min="0" step="0.01"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-modal-save" onclick="saveExtraCharge()"><i class="fas fa-check"></i> Add</button>
      <button class="btn-modal-cancel" onclick="closeModal('extraChargeModal')">Cancel</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════ TERMS POPUP ═══════════════ -->
<div class="modal-overlay" id="addSignatureModal">
  <div class="modal-box" style="width:460px">
    <div class="modal-header">
      <h3><i class="fas fa-signature" style="color:#10b981;margin-right:8px"></i>Add Signature</h3>
      <button class="modal-close" onclick="closeModal('addSignatureModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="mf-group"><label class="mf-label">Signature Name</label><input class="mf-input" id="new_signature_name" placeholder="e.g. CEO Signature"></div>
      <div class="mf-group"><label class="mf-label">Signature Image</label><input class="mf-input" id="new_signature_image" type="file" accept="image/png, image/jpeg, image/webp"></div>
      <small style="color:#6b7280;font-size:11px;display:block;margin-bottom:8px;">Upload clear PNG/JPG/WEBP signature.</small>
      <div id="add_sig_error" style="color:#dc2626;font-size:12px;margin-bottom:8px;display:none"></div>
    </div>
    <div class="modal-footer" style="justify-content:flex-end;gap:8px">
      <button type="button" class="btn-modal-save" style="background:linear-gradient(135deg,#10b981,#059669)" onclick="saveNewSignature()"><i class="fas fa-save"></i> Save Signature</button>
      <button type="button" class="btn-modal-cancel" onclick="closeModal('addSignatureModal')">Cancel</button>
    </div>
  </div>
</div>

<div id="termModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:3000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:520px;max-width:95vw;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.2);overflow:hidden;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #f0f2f7;background:linear-gradient(135deg,#f0fdf4,#fff);">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:36px;height:36px;background:linear-gradient(135deg,#16a34a,#15803d);border-radius:9px;display:flex;align-items:center;justify-content:center;">
          <i class="fas fa-file-contract" style="color:#fff;font-size:15px;"></i>
        </div>
        <div>
          <div style="font-weight:800;font-size:15px;color:#1a1f2e;">Terms &amp; Conditions</div>
          <div style="font-size:11px;color:#9ca3af;">Check terms to include in purchase order</div>
        </div>
      </div>
      <button onclick="closeTermsPopup()" style="width:32px;height:32px;border-radius:50%;border:1.5px solid #e4e8f0;background:#fff;cursor:pointer;font-size:16px;color:#6b7280;display:flex;align-items:center;justify-content:center;">✕</button>
    </div>
    <div style="padding:12px 20px;border-bottom:1px solid #f0f2f7;">
      <input id="termSearch" type="text" placeholder="🔍 Search terms..." oninput="filterTerms(this.value)"
        style="width:100%;padding:8px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;outline:none;font-family:inherit;box-sizing:border-box;">
    </div>
    <div id="termSelectList" style="flex:1;overflow-y:auto;padding:8px 0;"></div>
    <!-- Add new term inline input -->
    <div id="newTermBox" style="display:none;padding:10px 20px;border-top:1px solid #f0f2f7;background:#f8faff;">
        <div style="display:flex;gap:8px;align-items:center;">
            <input id="newTermInput" type="text" placeholder="Type new term here..."
                style="flex:1;padding:8px 12px;border:1.5px solid #16a34a;border-radius:8px;font-size:13px;outline:none;font-family:inherit;"
                onkeydown="if(event.key==='Enter')saveNewTermInline();">
            <button type="button" onclick="saveNewTermInline()"
                style="padding:8px 14px;background:#16a34a;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">
                <i class="fas fa-check"></i> Add
            </button>
            <button type="button" onclick="document.getElementById('newTermBox').style.display='none';"
                style="padding:8px 10px;background:#f5f5f5;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-size:13px;cursor:pointer;">
                ✕
            </button>
        </div>
    </div>
    <div style="padding:12px 20px;border-top:1px solid #f0f2f7;display:flex;justify-content:space-between;align-items:center;background:#fafbfd;">
      <button type="button" onclick="openAddNewTerm()"
        style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#fff;border:1.5px solid #16a34a;color:#16a34a;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">
        <i class="fas fa-plus"></i> New Term
      </button>
      <div style="display:flex;gap:8px;">
        <button type="button" onclick="closeTermsPopup()"
          style="padding:8px 16px;background:#f5f5f5;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-size:13px;cursor:pointer;font-family:inherit;">Cancel</button>
        <button type="button" onclick="applyTermsFromPopup()"
          style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">
          <i class="fas fa-check"></i> Apply Selected
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════ MODAL: Billing Address ═══ -->
<div class="modal-overlay" id="addressModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Add Address</h3>
      <button class="modal-close" onclick="closeModal('addressModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="mf-group">
        <label class="mf-label">Address Line 1</label>
        <input class="mf-input" id="addr_line1" type="text" placeholder="Street / Building">
      </div>
      <div class="mf-group">
        <label class="mf-label">Address Line 2</label>
        <input class="mf-input" id="addr_line2" type="text" placeholder="Area / Landmark">
      </div>
      <div class="mf-row">
        <div class="mf-group">
          <label class="mf-label">City</label>
          <input class="mf-input" id="addr_city" type="text">
        </div>
        <div class="mf-group">
          <label class="mf-label">State</label>
          <select class="mf-select" id="addr_state">
            <option value="">Select State</option>
            <option>Andhra Pradesh</option><option selected>Telangana</option><option>Karnataka</option>
            <option>Maharashtra</option><option>Tamil Nadu</option><option>Gujarat</option>
            <option>Rajasthan</option><option>Delhi</option><option>West Bengal</option>
            <option>Uttar Pradesh</option><option>Kerala</option><option>Punjab</option>
          </select>
        </div>
      </div>
      <div class="mf-group">
        <label class="mf-label">Pincode</label>
        <input class="mf-input" id="addr_pin" type="text" style="max-width:150px">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-modal-save" onclick="saveAddress()"><i class="fas fa-check"></i> Save</button>
      <button class="btn-modal-cancel" onclick="closeModal('addressModal')">Cancel</button>
    </div>
  </div>
</div>

<!-- JavaScript — inlined from po_script.js -->
<script>
window.itemIndex = <?= count($rows) ?>;

function fmt(n){return parseFloat(n).toFixed(2);}
function esc(s){if(!s)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function calcRow(el){
    const row=el.closest('.item-row');
    const qty=parseFloat(row.querySelector('[name*="[qty]"]').value)||0;
    const rate=parseFloat(row.querySelector('[name*="[rate]"]').value)||0;
    const disc=parseFloat(row.querySelector('[name*="[discount]"]').value)||0;
    const cgstP=parseFloat(row.querySelector('[name*="[cgst_pct]"]').value)||0;
    const sgstP=parseFloat(row.querySelector('[name*="[sgst_pct]"]').value)||0;
    const igstP=parseFloat(row.querySelector('[name*="[igst_pct]"]').value)||0;
    const taxable=(qty*rate)-disc;
    const cgst=taxable*cgstP/100, sgst=taxable*sgstP/100, igst=taxable*igstP/100;
    const amt=taxable+cgst+sgst+igst;
    row.querySelector('.taxable-cell').childNodes[0].textContent=fmt(taxable);
    row.querySelector('[name*="[taxable]"]').value=taxable.toFixed(2);
    row.querySelector('.cgst-cell').childNodes[0].textContent=fmt(cgst);
    row.querySelector('[name*="[cgst_amt]"]').value=cgst.toFixed(2);
    row.querySelector('.sgst-cell').childNodes[0].textContent=fmt(sgst);
    row.querySelector('[name*="[sgst_amt]"]').value=sgst.toFixed(2);
    row.querySelector('.igst-cell').childNodes[0].textContent=fmt(igst);
    row.querySelector('[name*="[igst_amt]"]').value=igst.toFixed(2);
    row.querySelector('.amt-cell').childNodes[0].textContent=fmt(amt);
    row.querySelector('[name*="[amount]"]').value=amt.toFixed(2);
    updateTotals();
}

function updateTotals(){
    let tax=0,cg=0,sg=0,ig=0,grand=0;
    document.querySelectorAll('.item-row').forEach(row=>{
        tax+=parseFloat(row.querySelector('[name*="[taxable]"]')?.value)||0;
        cg+=parseFloat(row.querySelector('[name*="[cgst_amt]"]')?.value)||0;
        sg+=parseFloat(row.querySelector('[name*="[sgst_amt]"]')?.value)||0;
        ig+=parseFloat(row.querySelector('[name*="[igst_amt]"]')?.value)||0;
        grand+=parseFloat(row.querySelector('[name*="[amount]"]')?.value)||0;
    });
    document.getElementById('totalTaxable').textContent=fmt(tax);
    document.getElementById('totalCgst').textContent=fmt(cg);
    document.getElementById('totalSgst').textContent=fmt(sg);
    document.getElementById('totalIgst').textContent=fmt(ig);
    const roundOffRow=document.getElementById('roundOffRow');
    if(roundOffRow && roundOffRow.style.display!=='none'){
        const paise=parseFloat((grand-Math.floor(grand)).toFixed(2));
        const roundOff=paise>0?-paise:0;
        document.getElementById('roundOffAmt').textContent=roundOff.toFixed(2);
        document.getElementById('grandTotal').textContent=fmt(grand+roundOff);
    } else {
        document.getElementById('grandTotal').textContent=fmt(grand);
    }
}
function toggleRoundOff(){
    const row=document.getElementById('roundOffRow');
    const btn=document.getElementById('addRoundOffBtn');
    const grandEl=document.getElementById('grandTotal');
    const isHidden=row.style.display==='none'||row.style.display==='';
    if(isHidden){
        const grandVal=parseFloat(grandEl.textContent.replace(/[,]/g,''))||0;
        const paise=parseFloat((grandVal-Math.floor(grandVal)).toFixed(2));
        if(paise===0){alert('No paise to round off. Grand Total is already a whole number.');return;}
        const roundOff=-paise;
        document.getElementById('roundOffAmt').textContent=roundOff.toFixed(2);
        grandEl.textContent=fmt(grandVal+roundOff);
        row.style.display='';
        btn.style.background='#fef2f2';btn.style.borderColor='#dc2626';btn.style.color='#dc2626';
        btn.innerHTML='<i class="fas fa-times"></i> Remove Round Off';
    } else {
        const grandVal=parseFloat(grandEl.textContent.replace(/[,]/g,''))||0;
        const roundVal=parseFloat(document.getElementById('roundOffAmt').textContent)||0;
        grandEl.textContent=fmt(grandVal-roundVal);
        row.style.display='none';
        btn.style.background='#f0fdf4';btn.style.borderColor='#16a34a';btn.style.color='#16a34a';
        btn.innerHTML='<i class="fas fa-plus"></i> Add Round Off';
    }
}

function buildRowHTML(i,name,desc,hsn,qty,unit,rate,disc,cgstP,sgstP,igstP,itemId){
    itemId=itemId||0;
    const taxable=(qty*rate)-disc;
    const cgst=taxable*cgstP/100,sgst=taxable*sgstP/100,igst=taxable*igstP/100,amt=taxable+cgst+sgst+igst;
    const rowNum=document.getElementById('itemBody').rows.length+1;
    return `<td class="num">${rowNum}</td>
        <td><input type="hidden" name="items[${i}][item_id]" value="${itemId}">
            <input type="text" class="item-name-input" name="items[${i}][item_name]" value="${esc(name)}" placeholder="Item name" required>
            <input type="text" class="item-desc-input" name="items[${i}][description]" value="${esc(desc)}" placeholder="Description (optional)"></td>
        <td><input type="text" name="items[${i}][hsn_sac]" value="${esc(hsn)}"></td>
        <td><input type="number" name="items[${i}][qty]" value="${qty}" min="0" step="0.001" oninput="calcRow(this)"></td>
        <td><input type="text" name="items[${i}][unit]" value="${esc(unit)}" placeholder="pcs"></td>
        <td><input type="number" name="items[${i}][rate]" value="${rate}" min="0" step="0.01" oninput="calcRow(this)"></td>
        <td><input type="number" name="items[${i}][discount]" value="${disc}" min="0" step="0.01" oninput="calcRow(this)"></td>
        <td class="num taxable-cell">${fmt(taxable)}<input type="hidden" name="items[${i}][taxable]" value="${taxable.toFixed(2)}"></td>
        <td><input type="number" name="items[${i}][cgst_pct]" value="${cgstP}" min="0" step="0.01" placeholder="0" oninput="calcRow(this)"></td>
        <td class="num cgst-cell">${fmt(cgst)}<input type="hidden" name="items[${i}][cgst_amt]" value="${cgst.toFixed(2)}"></td>
        <td><input type="number" name="items[${i}][sgst_pct]" value="${sgstP}" min="0" step="0.01" placeholder="0" oninput="calcRow(this)"></td>
        <td class="num sgst-cell">${fmt(sgst)}<input type="hidden" name="items[${i}][sgst_amt]" value="${sgst.toFixed(2)}"></td>
        <td><input type="number" name="items[${i}][igst_pct]" value="${igstP}" min="0" step="0.01" placeholder="0" oninput="calcRow(this)"></td>
        <td class="num igst-cell">${fmt(igst)}<input type="hidden" name="items[${i}][igst_amt]" value="${igst.toFixed(2)}"></td>
        <td class="num amt-cell">${fmt(amt)}<input type="hidden" name="items[${i}][amount]" value="${amt.toFixed(2)}"></td>
        <td><button type="button" class="btn-remove-item" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>`;
}

function addRow(){
    const i=window.itemIndex++;
    const tbody=document.getElementById('itemBody');
    const tr=document.createElement('tr');
    tr.className='item-row';tr.dataset.index=i;
    tr.innerHTML=buildRowHTML(i,'','','',1,'',0,0,0,0,0);
    tbody.appendChild(tr);renumberRows();
    tr.querySelector('[name*="[item_name]"]').focus();
}

function addItemRowWithData(name,desc,hsn,qty,unit,rate,disc,cgstP,sgstP,igstP,itemId){
    cgstP=cgstP||0;sgstP=sgstP||0;igstP=igstP||0;itemId=itemId||0;
    const i=window.itemIndex++;
    const tr=document.createElement('tr');
    tr.className='item-row';tr.dataset.index=i;
    tr.innerHTML=buildRowHTML(i,name,desc,hsn,qty,unit,rate,disc,cgstP,sgstP,igstP,itemId);
    document.getElementById('itemBody').appendChild(tr);
    renumberRows();updateTotals();
}

function removeRow(btn){
    if(document.querySelectorAll('.item-row').length<=1){alert('At least one item required.');return;}
    btn.closest('.item-row').remove();renumberRows();updateTotals();
}
function renumberRows(){document.querySelectorAll('#itemBody .item-row').forEach((r,i)=>r.querySelector('.num').textContent=i+1);}

function addTerm(t){
    const d=document.createElement('div');d.className='term-row';
    d.innerHTML=`<span>${esc(t.trim())}</span>
        <div class="term-actions">
            <button type="button" class="term-btn term-btn-edit" onclick="editTerm(this)"><i class="fas fa-pencil-alt"></i></button>
            <button type="button" class="term-btn term-btn-del" onclick="removeTerm(this)"><i class="fas fa-times"></i></button>
        </div>
        <input type="hidden" name="terms[]" value="${esc(t.trim())}">`;
    document.getElementById('termsList').appendChild(d);
}
function editTerm(btn){const row=btn.closest('.term-row');const v=prompt('Edit term:',row.querySelector('span').textContent);if(v&&v.trim()){row.querySelector('span').textContent=v.trim();row.querySelector('input[type=hidden]').value=v.trim();}}
function removeTerm(btn){btn.closest('.term-row').remove();}

const GSTIN_RE=/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/;
const PAN_RE=/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/;

function validateGSTIN(inp,hintId){
    inp.value=inp.value.toUpperCase().replace(/[^A-Z0-9]/g,'');
    const hint=document.getElementById(hintId);
    if(!inp.value){inp.classList.remove('error','valid');hint.className='field-hint';hint.textContent='';return true;}
    if(/^(NA|N\/A|na|n\/a)$/i.test(inp.value)){inp.classList.remove('error','valid');hint.className='field-hint';hint.textContent='';return true;}
    if(GSTIN_RE.test(inp.value)){inp.classList.remove('error');inp.classList.add('valid');hint.className='field-hint valid';hint.textContent='✓ Valid GSTIN';return true;}
    inp.classList.remove('valid');inp.classList.add('error');hint.className='field-hint error';
    hint.textContent=inp.value.length<15?`Format: 22AAAAA0000A1Z5 (${inp.value.length}/15 chars)`:'Invalid GSTIN format';return false;
}
function validatePAN(inp,hintId){
    inp.value=inp.value.toUpperCase().replace(/[^A-Z0-9]/g,'');
    const hint=document.getElementById(hintId);
    if(!inp.value){inp.classList.remove('error','valid');hint.className='field-hint';hint.textContent='';return true;}
    if(/^(NA|N\/A|na|n\/a)$/i.test(inp.value)){inp.classList.remove('error','valid');hint.className='field-hint';hint.textContent='';return true;}
    if(PAN_RE.test(inp.value)){inp.classList.remove('error');inp.classList.add('valid');hint.className='field-hint valid';hint.textContent='✓ Valid PAN';return true;}
    inp.classList.remove('valid');inp.classList.add('error');hint.className='field-hint error';
    hint.textContent=inp.value.length<10?`Format: ABCDE1234F (${inp.value.length}/10 chars)`:'Invalid PAN format';return false;
}

function showValToast(msg){const t=document.getElementById('valToast');t.textContent=msg;t.classList.add('show');clearTimeout(t._tid);t._tid=setTimeout(()=>t.classList.remove('show'),3500);}
function showMiniToast(msg){
    let t=document.getElementById('miniToast');
    if(!t){t=document.createElement('div');t.id='miniToast';t.style.cssText='position:fixed;bottom:24px;right:24px;background:#2e7d32;color:#fff;border-radius:8px;padding:10px 18px;font-size:13px;font-weight:700;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,.2);transition:opacity .3s';document.body.appendChild(t);}
    t.textContent=msg;t.style.opacity='1';clearTimeout(t._tid);t._tid=setTimeout(()=>t.style.opacity='0',2500);
}

function submitForm(a){document.getElementById('formAction').value=a;document.getElementById('poForm').submit();}

function validateAndSubmit(action){
    const errors=[];
    const supplierCtrl=document.getElementById('supplierInput');
    if(!supplierCtrl.value.trim()){supplierCtrl.classList.add('error');errors.push('Supplier is required');}else{supplierCtrl.classList.remove('error');}
    const poNum=document.querySelector('[name="po_number"]');
    if(poNum&&!poNum.value.trim()){poNum.classList.add('error');errors.push('PO Number is required');}else if(poNum){poNum.classList.remove('error');}
    const poDate=document.querySelector('[name="po_date"]'),dueDate=document.querySelector('[name="due_date"]');
    if(poDate&&!poDate.value){poDate.classList.add('error');errors.push('PO Date is required');}else if(poDate){poDate.classList.remove('error');}
    if(dueDate&&!dueDate.value){dueDate.classList.add('error');errors.push('Due Date is required');}else if(dueDate){dueDate.classList.remove('error');}
    if(poDate&&dueDate&&poDate.value&&dueDate.value&&dueDate.value<poDate.value){dueDate.classList.add('error');errors.push('Due Date must be on or after PO Date');}
    const itemNames=document.querySelectorAll('[name*="[item_name]"]');
    let hasItem=false;
    itemNames.forEach(inp=>{if(inp.value.trim())hasItem=true;inp.classList.remove('error');});
    if(!hasItem){itemNames.forEach(inp=>inp.classList.add('error'));errors.push('At least one item with a name is required');}
    document.querySelectorAll('.item-row').forEach((row,idx)=>{
        const nameInp=row.querySelector('[name*="[item_name]"]');if(!nameInp||!nameInp.value.trim())return;
        const qty=parseFloat(row.querySelector('[name*="[qty]"]')?.value)||0;
        const rate=parseFloat(row.querySelector('[name*="[rate]"]')?.value);
        const hsnInp=row.querySelector('[name*="[hsn_sac]"]');
        if(qty<=0){row.querySelector('[name*="[qty]"]').classList.add('error');errors.push(`Item ${idx+1}: Qty must be greater than 0`);}else{row.querySelector('[name*="[qty]"]').classList.remove('error');}
        if(isNaN(rate)||rate<0){row.querySelector('[name*="[rate]"]').classList.add('error');errors.push(`Item ${idx+1}: Rate must be 0 or more`);}else{row.querySelector('[name*="[rate]"]').classList.remove('error');}
        if(hsnInp&&!hsnInp.value.trim()){hsnInp.classList.add('error');errors.push(`Item ${idx+1}: HSN/SAC code is required`);}else if(hsnInp){hsnInp.classList.remove('error');}
    });
    const shippingEl=document.getElementById('shippingDetails');
    if(shippingEl) shippingEl.classList.remove('error');
    if(errors.length){
        showValToast('⚠ '+errors[0]+(errors.length>1?` (+${errors.length-1} more)`:''));
        const firstErr=document.querySelector('.form-control.error,.mf-input.error,input.error');
        if(firstErr)firstErr.scrollIntoView({behavior:'smooth',block:'center'});return;
    }
    document.getElementById('formAction').value=action;
    document.getElementById('poForm').submit();
}

function copySupplier(){const v=document.getElementById('supplierInput').value;if(v)navigator.clipboard.writeText(v);}

function openModal(id){document.getElementById(id).classList.add('open');if(id==='termModal')loadAndRenderTerms('');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.addEventListener('click',e=>{
    document.querySelectorAll('.modal-overlay.open').forEach(m=>{if(e.target===m)m.classList.remove('open');});
    if(e.target===document.getElementById('spOverlay'))document.getElementById('spOverlay').classList.remove('open');
});
document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'));document.getElementById('spOverlay').classList.remove('open');}
});
const __sigSel = document.getElementById('signature_select');
if(__sigSel){
    __sigSel.addEventListener('change', refreshSignaturePreview);
    refreshSignaturePreview();
}

let allSuppliers=[];
function openSupplierPopup(){
    document.getElementById('spOverlay').classList.add('open');
    document.getElementById('spSearch').value='';
    const list=document.getElementById('spList');
    list.innerHTML='<div class="sp-empty">Loading...</div>';
    fetch('createpurchase.php?get_suppliers=1').then(r=>r.json()).then(data=>{allSuppliers=data;renderSuppliers(data);document.getElementById('spSearch').focus();}).catch(()=>{list.innerHTML='<div class="sp-empty">Error loading suppliers.</div>';});
}
function renderSuppliers(list){
    const el=document.getElementById('spList');
    if(!list.length){el.innerHTML='<div class="sp-empty">No suppliers found. Add one below.</div>';return;}
    el.innerHTML=list.map((s)=>`<div class="sp-item" onclick="selectSupplierById(${s.id})"><div class="sp-item-name">${esc(s.supplier_name)}</div><div class="sp-item-sub">${s.contact_person?esc(s.contact_person):''}${s.email?' &nbsp;·&nbsp; '+esc(s.email):''}${s.phone?' &nbsp;·&nbsp; 📞'+esc(s.phone):''}</div></div>`).join('');
}
function filterSuppliers(q){
    q = q.trim().toLowerCase();
    if (!q) { renderSuppliers(allSuppliers); return; }
    renderSuppliers(allSuppliers.filter(function(s){
        return (s.supplier_name||'').toLowerCase().includes(q) ||
               (s.contact_person||'').toLowerCase().includes(q) ||
               (s.email||'').toLowerCase().includes(q) ||
               (s.phone||'').toLowerCase().includes(q);
    }));
}
function selectSupplierById(id){const s=allSuppliers.find(x=>x.id==id);if(!s)return;selectSupplier(s.supplier_name,s.contact_person||'',s.address||'');}
function selectSupplierIdx(idx){const s=allSuppliers[idx];if(!s)return;selectSupplier(s.supplier_name,s.contact_person||'',s.address||'');}
function selectSupplier(name,contact,address){
    document.getElementById('supplierInput').value=name;
    const contactEl=document.querySelector('[name="contact_person"]');if(contactEl)contactEl.value=contact||'';
    if(address){document.getElementById('sourceAddrHidden').value=address;if(document.getElementById('same_as_billing').checked)document.getElementById('shippingDetails').value=address;}
    closeSupplierPopup();
}
function closeSupplierPopup(e){if(!e||e.target===document.getElementById('spOverlay'))document.getElementById('spOverlay').classList.remove('open');}
function openNewSupplierForm(){document.getElementById('spOverlay').classList.remove('open');openModal('supplierModal');}

function saveNewSupplier(){
    const business=document.getElementById('ns_business').value.trim();
    const email=document.getElementById('ns_email').value.trim();
    const address=document.getElementById('ns_address').value.trim();
    const gstinInp=document.getElementById('ns_gstin'),panInp=document.getElementById('ns_pan');
    let hasError=false;
    if(!business){document.getElementById('ns_business').classList.add('error');hasError=true;}else{document.getElementById('ns_business').classList.remove('error');}
    if(!email){document.getElementById('ns_email').classList.add('error');hasError=true;}else{document.getElementById('ns_email').classList.remove('error');}
    if(!address){document.getElementById('ns_address').classList.add('error');hasError=true;}else{document.getElementById('ns_address').classList.remove('error');}
    if(gstinInp.value.trim() && !/^(NA|N\/A|na|n\/a)$/i.test(gstinInp.value.trim()) && !validateGSTIN(gstinInp,'ns_gstin_hint')){hasError=true;}
    if(panInp.value.trim() && !/^(NA|N\/A|na|n\/a)$/i.test(panInp.value.trim()) && !validatePAN(panInp,'ns_pan_hint')){hasError=true;}
    if(hasError){const f=document.querySelector('#supplierModal .mf-input.error,#supplierModal .mf-textarea.error');if(f)f.scrollIntoView({behavior:'smooth',block:'center'});showValToast('⚠ Please fill all required fields in supplier form');return;}
    const fd=new FormData();
    fd.append('save_supplier_ajax','1');fd.append('supplier_name',business);fd.append('contact_person',document.getElementById('ns_contact').value.trim());fd.append('phone',document.getElementById('ns_mobile').value.trim());fd.append('email',document.getElementById('ns_email').value.trim());fd.append('address',document.getElementById('ns_address').value.trim());fd.append('gstin',(document.getElementById('ns_gstin')||{value:''}).value.trim());fd.append('pan',(document.getElementById('ns_pan')||{value:''}).value.trim());fd.append('website',(document.getElementById('ns_website')||{value:''}).value.trim());
    fetch('createpurchase.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
        if(d.success){document.getElementById('supplierInput').value=business;const c=document.querySelector('[name="contact_person"]');if(c)c.value=document.getElementById('ns_contact').value.trim();['ns_business','ns_contact','ns_mobile','ns_email','ns_address','ns_gstin','ns_pan','ns_website'].forEach(id=>{const el=document.getElementById(id);if(el){el.value='';el.classList.remove('error','valid');}});['ns_gstin_hint','ns_pan_hint'].forEach(id=>{const el=document.getElementById(id);if(el){el.className='field-hint';el.textContent='';}});closeModal('supplierModal');showMiniToast('✓ Supplier saved');}else{alert('Failed to save: '+(d.message||'Unknown error'));}
    }).catch(()=>alert('Network error'));
}

let allItems=[];
function openSelectItemModal(){openModal('selectItemModal');document.getElementById('itemSearch').value='';loadItemList();}
function loadItemList(){
    document.getElementById('itemSelectList').innerHTML='<div style="padding:30px;text-align:center;color:#9ca3af;font-size:13px;">Loading...</div>';
    fetch('createpurchase.php?get_items=1').then(r=>r.json()).then(data=>{allItems=data;renderItemList(data);document.getElementById('itemSearch').focus();}).catch(()=>{document.getElementById('itemSelectList').innerHTML='<div style="padding:30px;text-align:center;color:#ef4444;">Error loading items.</div>';});
}
function renderItemList(items){
    const el=document.getElementById('itemSelectList');
    if(!items.length){el.innerHTML='<div style="padding:30px;text-align:center;color:#9ca3af;font-size:13px;">No items in library yet.<br><span style="font-size:11px;margin-top:6px;display:block;">Click <b>+ Create New Item</b> below to add one.</span></div>';return;}
    el.innerHTML=items.map((it,idx)=>{
        const name=it.item_name||it.name||it.product_name||'',desc=it.description||it.item_description||'',hsn=it.hsn_sac||it.hsn||it.sac||'',unit=it.unit||it.uom||'',rate=parseFloat(it.rate||it.price||it.selling_price||0),cgst=parseFloat(it.cgst_pct||it.cgst||0),sgst=parseFloat(it.sgst_pct||it.sgst||0),igst=parseFloat(it.igst_pct||it.igst||0),type=it.item_type||it.type||'',itemId=it.id||idx;
        return `<div class="item-select-row" onclick="addItemFromListById(${itemId})"><div style="flex:1;min-width:0;"><div class="item-select-name">${esc(name)}</div><div class="item-select-sub">${type?'<span style="background:#e8f5e9;color:#2e7d32;padding:1px 6px;border-radius:4px;font-size:10px;margin-right:5px;">'+esc(type)+'</span>':''}${unit?esc(unit):''}${hsn?' &nbsp;|&nbsp; HSN: '+esc(hsn):''}${rate?' &nbsp;|&nbsp; ₹ '+rate.toLocaleString('en-IN'):''}${cgst>0?' &nbsp;|&nbsp; C+S: '+(cgst+sgst)+'%':''}${igst>0?' &nbsp;|&nbsp; IGST: '+igst+'%':''}${desc?'<br><span style="color:#aaa;font-size:10px;">'+esc(desc.substring(0,60))+(desc.length>60?'…':'')+'</span>':''}</div></div></div>`;
    }).join('');
}
function addItemFromListById(id){const it=allItems.find(x=>x.id==id);if(!it)return;const name=it.item_name||it.name||it.product_name||'',desc=it.description||it.item_description||'',hsn=it.hsn_sac||it.hsn||it.sac||'',unit=it.unit||it.uom||'no.s',rate=parseFloat(it.rate||it.price||it.selling_price||0),cgst=parseFloat(it.cgst_pct||it.cgst||0),sgst=parseFloat(it.sgst_pct||it.sgst||0),igst=parseFloat(it.igst_pct||it.igst||0),itemId=parseInt(it.id||0);addItemRowWithData(name,desc,hsn,1,unit,rate,0,cgst,sgst,igst,itemId);showMiniToast('✓ '+name+' added to PO');}
function addItemFromList(idx){const it=allItems[idx];if(!it)return;addItemFromListById(it.id);}
function filterItems(q){
    q = q.trim().toLowerCase();
    if (!q) { renderItemList(allItems); return; }
    renderItemList(allItems.filter(function(it){
        return (it.item_name||'').toLowerCase().includes(q) ||
               (it.description||'').toLowerCase().includes(q) ||
               (it.hsn_sac||'').toLowerCase().includes(q) ||
               (it.unit||'').toLowerCase().includes(q);
    }));
}

let currentItemTab='product';
function switchItemTab(tab){currentItemTab=tab;document.getElementById('tabProduct').className='tab-btn'+(tab==='product'?' active':'');document.getElementById('tabService').className='tab-btn'+(tab==='service'?' active':'');document.getElementById('addItemTitle').textContent=tab==='product'?'Add Product':'Add Service';}

function saveAddItem(saveToMaster){
    const name=document.getElementById('ai_name').value.trim();if(!name){alert('Item name is required.');return;}
    const hsn=document.getElementById('ai_hsn').value.trim();if(!hsn){document.getElementById('ai_hsn').classList.add('error');showValToast('⚠ HSN/SAC code is required');return;}
    document.getElementById('ai_hsn').classList.remove('error');
    const rate=parseFloat(document.getElementById('ai_rate').value)||0,unit=document.getElementById('ai_unit').value,desc=document.getElementById('ai_desc').value.trim(),cgst=parseFloat(document.getElementById('ai_cgst').value)||0,sgst=parseFloat(document.getElementById('ai_sgst').value)||0,igst=parseFloat(document.getElementById('ai_igst').value)||0;
    addItemRowWithData(name,desc,hsn,1,unit,rate,0,cgst,sgst,igst);
    ['ai_name','ai_desc','ai_hsn'].forEach(id=>document.getElementById(id).value='');
    document.getElementById('ai_rate').value=0;document.getElementById('ai_cgst').value=0;document.getElementById('ai_sgst').value=0;document.getElementById('ai_igst').value=0;
    closeModal('addItemModal');
    if(saveToMaster){const fd=new FormData();fd.append('save_master_item','1');fd.append('item_type',currentItemTab==='product'?'Product':'Service');fd.append('item_name',name);fd.append('description',desc);fd.append('hsn_sac',hsn);fd.append('unit',unit);fd.append('rate',rate);fd.append('cgst_pct',cgst);fd.append('sgst_pct',sgst);fd.append('igst_pct',igst);fetch('createpurchase.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){showMiniToast('✓ '+name+' saved to item library');fetch('createpurchase.php?get_items=1').then(r=>r.json()).then(data=>{allItems=data;});}});}else{showMiniToast('✓ '+name+' added to PO');}
}

function saveExtraCharge(){const item=document.getElementById('ec_item').value.trim(),amount=parseFloat(document.getElementById('ec_amount').value)||0;if(!item){alert('Charge name is required.');return;}addItemRowWithData('Extra: '+item,'Extra Charge','',1,'no.s',amount,0,0,0,0);document.getElementById('ec_item').value='';document.getElementById('ec_amount').value=0;closeModal('extraChargeModal');}

function refreshSignaturePreview(){
    const sel = document.getElementById('signature_select');
    const preview = document.getElementById('signature_preview');
    if(!sel || !preview) return;
    const opt = sel.options[sel.selectedIndex];
    const path = opt && opt.dataset ? (opt.dataset.path || '') : '';
    if(path){
        preview.src = '/invoice/' + String(path).replace(/^\/+/, '');
        preview.style.display = 'inline-block';
    } else {
        preview.src = '';
        preview.style.display = 'none';
    }
}

async function saveNewSignature(){
    const name = document.getElementById('new_signature_name').value.trim();
    const fileInput = document.getElementById('new_signature_image');
    const errDiv = document.getElementById('add_sig_error');
    if(!name){ errDiv.textContent='Signature name is required.'; errDiv.style.display='block'; return; }
    if(!fileInput.files.length){ errDiv.textContent='Signature image is required.'; errDiv.style.display='block'; return; }
    errDiv.style.display='none';
    const fd = new FormData();
    fd.append('signature_name', name);
    fd.append('signature_image', fileInput.files[0]);
    try{
        const res = await fetch('/invoice/addsignature.php', { method:'POST', body:fd });
        const json = await res.json();
        if(json.status === 'success'){
            const sel = document.getElementById('signature_select');
            const opt = document.createElement('option');
            opt.value = json.id;
            opt.textContent = json.signature_name;
            opt.dataset.path = json.file_path;
            sel.appendChild(opt);
            sel.value = String(json.id);
            refreshSignaturePreview();
            document.getElementById('new_signature_name').value = '';
            fileInput.value = '';
            closeModal('addSignatureModal');
        } else {
            errDiv.textContent = json.message || 'Error uploading signature.';
            errDiv.style.display='block';
        }
    } catch(e){
        errDiv.textContent = 'Network error: ' + e.message;
        errDiv.style.display='block';
    }
}

// ── Terms System ──────────────────────────────────────────────────────────────
const defaultTerms = [
    'Delivery Time: Material to be delivered within 7 days from the date of PO release.',
    'Payment Terms: Payment will be released as per agreed terms.',
    'Warranty: Standard manufacturer warranty must be provided.',
    'Inspection: Material shall be inspected at the customer end upon receipt.',
    'Freight charges are included in the quotation.',
    'Freight: extra at actuals',
    'Installation & Commissioning included in the above Price.',
    'Dedicated manpower upto one month after receipt of the purchase order'
];
let masterTermsList = [...defaultTerms];

function openTermsPopup(){
    fetch('/invoice/purchaseorder/createpurchase.php?get_terms=1')
        .then(r=>r.json())
        .then(data=>{
            data.forEach(t=>{if(t&&!masterTermsList.includes(t))masterTermsList.push(t);});
            renderTermsPopup();
        })
        .catch(()=>{ renderTermsPopup(); });
    document.getElementById('termSearch').value='';
    document.getElementById('termModalOverlay').style.display='flex';
}

function closeTermsPopup(){
    document.getElementById('termModalOverlay').style.display='none';
}

function renderTermsPopup(){
    const el = document.getElementById('termSelectList');
    const currentTerms = new Set(
        [...document.querySelectorAll('#termsList input[type=hidden]')].map(i=>i.value)
    );
    el.innerHTML = masterTermsList.map((t,i)=>{
        const checked = currentTerms.has(t);
        return `<div style="display:flex;align-items:flex-start;gap:10px;padding:10px 20px;border-bottom:1px solid #f5f5f5;transition:background .1s;" onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background=''">
            <input type="checkbox" id="ptc_${i}" ${checked?'checked':''} value="${esc(t)}"
                style="margin-top:3px;width:16px;height:16px;cursor:pointer;accent-color:#16a34a;flex-shrink:0;">
            <label for="ptc_${i}" style="flex:1;cursor:pointer;font-size:13px;line-height:1.6;color:#374151;">${esc(t)}</label>
        </div>`;
    }).join('');
}

function applyTermsFromPopup(){
    const checks = document.querySelectorAll('#termSelectList input[type=checkbox]');
    document.querySelectorAll('#termsList .term-row').forEach(r=>r.remove());
    checks.forEach(cb=>{ if(cb.checked) addTerm(cb.value); });
    closeTermsPopup();
}

function addTerm(t){
    // Don't add duplicates
    const existing = [...document.querySelectorAll('#termsList input[type=hidden]')].map(i=>i.value);
    if(existing.includes(t)) return;
    const d=document.createElement('div');d.className='term-row';
    d.innerHTML=`<span>${esc(t)}</span>
        <div class="term-actions">
            <button type="button" class="term-btn term-btn-edit" onclick="editTerm(this)"><i class="fas fa-pencil-alt"></i></button>
            <button type="button" class="term-btn term-btn-del"  onclick="removeTerm(this)"><i class="fas fa-times"></i></button>
        </div>
        <input type="hidden" name="terms[]" value="${esc(t)}">`;
    document.getElementById('termsList').appendChild(d);
}
function editTerm(btn){const row=btn.closest('.term-row');const v=prompt('Edit term:',row.querySelector('span').textContent);if(v&&v.trim()){row.querySelector('span').textContent=v.trim();row.querySelector('input[type=hidden]').value=v.trim();}}
function removeTerm(btn){btn.closest('.term-row').remove();}
function openAddNewTerm(){
    const box = document.getElementById('newTermBox');
    box.style.display = box.style.display === 'none' ? 'flex' : 'none';
    if(box.style.display === 'flex') {
        document.getElementById('newTermInput').value = '';
        document.getElementById('newTermInput').focus();
    }
}
function saveNewTermInline(){
    const inp = document.getElementById('newTermInput');
    if(!inp) return;
    const t = inp.value.trim();
    if(!t) return;
    if(!masterTermsList.includes(t)) masterTermsList.push(t);
    renderTermsPopup();
    // auto-check the new term
    setTimeout(()=>{
        const checks = document.querySelectorAll('#termSelectList input[type=checkbox]');
        checks.forEach(cb=>{ if(cb.value === t) cb.checked = true; });
    }, 50);
    inp.value = '';
    document.getElementById('newTermBox').style.display = 'none';
}

// Pre-add first 4 default terms on page load (new PO only)
document.addEventListener('DOMContentLoaded', function(){
    const isEdit = <?= $edit_id ? 'true' : 'false' ?>;
    const hasTerms = document.querySelectorAll('#termsList .term-row').length > 0;
    if(!isEdit && !hasTerms){
        const first4 = masterTermsList.slice(0, 4);
        first4.forEach(t => addTerm(t));
    }
});
function loadAndRenderTerms(q){ renderTermsPopup(); }
function renderTermList(q){ renderTermsPopup(); }
function filterTerms(q){
    const val = q.toLowerCase();
    document.querySelectorAll('#termSelectList > div').forEach(row=>{
        const label = row.querySelector('label');
        if(label) row.style.display = label.textContent.toLowerCase().includes(val) ? '' : 'none';
    });
}

// ── Same as Billing: full sync of all shipping fields ─────────────────────
function getShippingFields() {
    return {
        address : document.getElementById('shippingDetails'),
        gstin   : document.getElementById('shipping_gstin'),
        phone   : document.getElementById('shipping_phone'),
        city    : document.getElementById('shipping_city'),
        state   : document.getElementById('shipping_state'),
        pincode : document.getElementById('shipping_pincode'),
    };
}
function getBillingValues() {
    return {
        address : document.getElementById('sourceAddrHidden').value,
        gstin   : document.getElementById('billing_gstin').value,
        phone   : document.getElementById('billing_phone').value,
        city    : document.getElementById('billing_city') ? document.getElementById('billing_city').value : '',
        state   : document.getElementById('billing_state') ? document.getElementById('billing_state').value : '',
        pincode : document.getElementById('billing_pincode') ? document.getElementById('billing_pincode').value : '',
    };
}
function syncShippingFromBilling() {
    const s = getShippingFields(), b = getBillingValues();
    s.address.value = b.address;
    s.gstin.value   = b.gstin;
    s.phone.value   = b.phone;
    s.city.value    = b.city;
    s.state.value   = b.state;
    s.pincode.value = b.pincode;
}
function lockShipping(lock) {
    ['shippingDetails','shipping_gstin','shipping_phone','shipping_city','shipping_state','shipping_pincode'].forEach(function(id){
        const el = document.getElementById(id);
        if(el){
            if(lock){
                el.setAttribute('readonly',true);
                el.style.background='#f0fdf4';
                el.style.borderColor='#bbf7d0';
                el.style.cursor='not-allowed';
            } else {
                el.removeAttribute('readonly');
                el.style.background='';
                el.style.borderColor='';
                el.style.cursor='';
            }
        }
    });
}
function bsSyncAddr(cb) {
    if (cb.checked) {
        syncShippingFromBilling();
        lockShipping(true);
    } else {
        lockShipping(false);
    }
}
// Live sync: when billing fields change and checkbox is checked, update shipping instantly
['billing_gstin','billing_phone','billing_city','billing_state','billing_pincode'].forEach(function(id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', function() {
        if (document.getElementById('same_as_billing').checked) syncShippingFromBilling();
    });
});
// Billing address textarea live sync
document.getElementById('sourceAddrHidden').addEventListener('input', function() {
    if (document.getElementById('same_as_billing').checked) syncShippingFromBilling();
});

// ── Inline format validation (non-mandatory) for billing/shipping GSTIN & Phone ──
const PO_GSTIN_RE = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/;
const PO_PHONE_RE = /^[6-9]\d{9}$/;
const PO_NA_RE    = /^(NA|N\/A)$/i;

function poShowHint(hintId, msg, isError) {
    const el = document.getElementById(hintId);
    if (!el) return;
    el.textContent = msg;
    el.style.display = 'block';
    el.style.color = isError ? '#dc2626' : '#16a34a';
}
function poClearHint(hintId, inputId) {
    const el = document.getElementById(hintId);
    if (el) { el.style.display = 'none'; el.textContent = ''; }
    const inp = document.getElementById(inputId);
    if (inp) inp.style.borderColor = '';
}
function poValidateGstin(inputId, hintId) {
    const inp = document.getElementById(inputId); if(!inp) return;
    const val = inp.value.trim().toUpperCase();
    inp.value = val;
    if (!val || PO_NA_RE.test(val)) { poClearHint(hintId, inputId); return; }
    if (PO_GSTIN_RE.test(val)) {
        poShowHint(hintId, '✓ Valid GSTIN', false);
        inp.style.borderColor = '#16a34a';
    } else {
        poShowHint(hintId, '✗ Invalid GSTIN (e.g. 22AAAAA0000A1Z5)', true);
        inp.style.borderColor = '#dc2626';
    }
}
function poValidatePhone(inputId, hintId) {
    const inp = document.getElementById(inputId); if(!inp) return;
    const val = inp.value.trim();
    if (!val || PO_NA_RE.test(val)) { poClearHint(hintId, inputId); return; }
    if (PO_PHONE_RE.test(val)) {
        poShowHint(hintId, '✓ Valid phone', false);
        inp.style.borderColor = '#16a34a';
    } else {
        poShowHint(hintId, '✗ Must be a valid 10-digit Indian mobile', true);
        inp.style.borderColor = '#dc2626';
    }
}
document.getElementById('billing_gstin')?.addEventListener('blur', function(){ poValidateGstin('billing_gstin','billing_gstin_hint'); });
document.getElementById('shipping_gstin')?.addEventListener('blur', function(){ poValidateGstin('shipping_gstin','shipping_gstin_hint'); });
document.getElementById('billing_phone')?.addEventListener('blur', function(){ poValidatePhone('billing_phone','billing_phone_hint'); });
document.getElementById('shipping_phone')?.addEventListener('blur', function(){ poValidatePhone('shipping_phone','shipping_phone_hint'); });

function saveAddress(){
    const l1=document.getElementById('addr_line1').value.trim(),l2=document.getElementById('addr_line2').value.trim(),city=document.getElementById('addr_city').value.trim(),state=document.getElementById('addr_state').value,pin=document.getElementById('addr_pin').value.trim();
    const full=[l1,l2,city,state,pin].filter(Boolean).join(', ');
    if(!full){alert('Please enter address details.');return;}
    document.getElementById('sourceAddrHidden').value=full;
    if(document.getElementById('billing_city')) document.getElementById('billing_city').value=city;
    if(document.getElementById('billing_state')) document.getElementById('billing_state').value=state;
    if(document.getElementById('billing_pincode')) document.getElementById('billing_pincode').value=pin;
    if(document.getElementById('same_as_billing').checked) syncShippingFromBilling();
    closeModal('addressModal');
}

document.getElementById('supplierInput').addEventListener('click',function(){this.classList.remove('error');});
document.querySelector('[name="po_number"]')?.addEventListener('input',function(){this.classList.remove('error');});
document.querySelector('[name="po_date"]')?.addEventListener('change',function(){this.classList.remove('error');});
document.querySelector('[name="due_date"]')?.addEventListener('change',function(){this.classList.remove('error');});
document.addEventListener('input',e=>{
    if(e.target.matches('[name*="[item_name]"],[name*="[qty]"],[name*="[rate]"],[name*="[hsn_sac]"]'))e.target.classList.remove('error');
    if(e.target.id==='shippingDetails')e.target.classList.remove('error');
    if(e.target.id==='ns_email'||e.target.id==='ns_address')e.target.classList.remove('error');
});

updateTotals();
</script>

<?php include dirname(__DIR__) . '/company_add_modal.php'; ?>

<script>
// Company dropdown: Select2 rich template (same idea as create_invoice.php)
$(document).ready(function() {
    var sel = document.getElementById('company_select');
    if (!sel) return;

    $('#company_select').select2({
        width: '260px',
        placeholder: '-- Select Company --',
        allowClear: false,
        templateResult: function(opt) {
            if (!opt.id) return opt.text;
            var el = opt.element;
            var line1 = $(el).data('line1') || '';
            var city  = $(el).data('city')  || '';
            var state = $(el).data('state') || '';
            var addrParts = [];
            if (line1) addrParts.push(line1);
            if (city) addrParts.push(city);
            if (state) addrParts.push(state);
            var addr = addrParts.join(', ');

            var $r = $('<div style="padding:2px 0;line-height:1.4;"></div>');
            $r.append('<div style="font-weight:700;font-size:13px;color:#1a1f2e;">' + $('<div>').text(opt.text).html() + '</div>');
            if (addr) $r.append('<div style="font-size:11px;color:#9ca3af;margin-top:1px;">' + $('<div>').text(addr).html() + '</div>');
            return $r;
        },
        templateSelection: function(opt) {
            if (!opt.id) return opt.text;
            var el = opt.element;
            var line1 = $(el).data('line1') || '';
            var city  = $(el).data('city')  || '';
            var state = $(el).data('state') || '';
            var addrParts = [];
            if (line1) addrParts.push(line1);
            if (city) addrParts.push(city);
            if (state) addrParts.push(state);
            var addr = addrParts.join(', ');
            if (!addr) return $('<span><strong>' + $('<div>').text(opt.text).html() + '</strong></span>');
            return $('<span><strong>' + $('<div>').text(opt.text).html() + '</strong>' +
                '<small style="color:#9ca3af;font-size:11px;">' + $('<div>').text(addr).html() + '</small></span>');
        }
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- ADD COMPANY MODAL -->
<div id="addCompanyModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:18px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <div style="padding:16px 24px;border-bottom:1.5px solid #f0f2f7;display:flex;align-items:center;gap:10px;background:#fafbfd;border-radius:18px 18px 0 0;">
            <div style="width:34px;height:34px;background:linear-gradient(135deg,#f97316,#fb923c);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;"><i class="fas fa-building"></i></div>
            <div>
                <div style="font-size:15px;font-weight:800;color:#1a1f2e;font-family:'Times New Roman',Times,serif;">Add New Company</div>
                <div style="font-size:11px;color:#9ca3af;">Saved to invoice_company table</div>
            </div>
            <button type="button" onclick="closeAddCompanyModal()" style="margin-left:auto;background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>
        <div style="padding:20px 24px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div style="grid-column:1/-1;">
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Company Name *</label>
                <input type="text" id="ac_company_name" placeholder="e.g. Eltrive Automations Pvt Ltd" style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Company Logo</label>
                <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                    <div style="width:56px;height:56px;border-radius:12px;border:1.5px solid #e4e8f0;background:#f4f6fb;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
                        <img id="ac_logo_preview" src="" alt="Logo" style="max-width:100%;max-height:100%;display:none;">
                        <i id="ac_logo_placeholder" class="fas fa-image" style="font-size:22px;color:#c0c8d8;"></i>
                    </div>
                    <div style="flex:1;min-width:220px;">
                        <input type="hidden" id="ac_company_logo_existing" value="">
                        <input type="file" id="ac_company_logo" accept="image/*" onchange="previewLogo(this)" style="width:100%;padding:7px 10px;border:1.5px dashed #e4e8f0;border-radius:8px;font-size:11px;color:#6b7280;background:#fafbfc;cursor:pointer;font-family:'Times New Roman',Times,serif;">
                        <div style="font-size:10px;color:#9ca3af;margin-top:2px">PNG, JPG up to 5MB</div>
                    </div>
                </div>
            </div>
            <div><label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Address Line 1</label><input type="text" id="ac_address_line1" placeholder="Street / Door No." style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;"></div>
            <div><label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Address Line 2</label><input type="text" id="ac_address_line2" placeholder="Area / Landmark" style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;"></div>
            <div><label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">City</label><input type="text" id="ac_city" placeholder="City" style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;"></div>
            <div><label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">State</label><input type="text" id="ac_state" placeholder="State" style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;"></div>
            <div><label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Pincode</label><input type="text" id="ac_pincode" placeholder="500001" style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;"></div>
            <div><label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Phone</label><input type="text" id="ac_phone" placeholder="+91 9999999999" style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;"></div>
            <div><label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Email</label><input type="email" id="ac_email" placeholder="info@company.com" style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;"></div>
            <div><label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">GST Number</label><input type="text" id="ac_gst_number" maxlength="15" placeholder="29XXXXX1234X1ZX" style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:monospace;text-transform:uppercase;color:#1a1f2e;outline:none;"></div>
            <div><label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">CIN Number</label><input type="text" id="ac_cin_number" placeholder="U12345TN2020PTC000000" style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:monospace;text-transform:uppercase;color:#1a1f2e;outline:none;"></div>
            <div><label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">PAN Number</label><input type="text" id="ac_pan" maxlength="10" placeholder="AAAAA9999A" style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:monospace;text-transform:uppercase;color:#1a1f2e;outline:none;"></div>
            <div><label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Website</label><input type="text" id="ac_website" placeholder="www.company.com" style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;"></div>
        </div>
        <div style="padding:14px 24px;border-top:1.5px solid #f0f2f7;display:flex;gap:10px;align-items:center;background:#fafbfd;border-radius:0 0 18px 18px;">
            <button type="button" onclick="saveNewCompany()" style="padding:9px 22px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 8px rgba(249,115,22,.3);"><i class="fas fa-save"></i> Save Company</button>
            <button type="button" onclick="closeAddCompanyModal()" style="padding:9px 18px;background:#fff;color:#6b7280;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Times New Roman',Times,serif;"><i class="fas fa-times"></i> Cancel</button>
            <div id="ac_status" style="margin-left:auto;font-size:12px;color:#16a34a;font-weight:600;display:none;"></div>
        </div>
    </div>
</div>

<script>
// ── Add Company Modal ──────────────────────────────────────────────
function openAddCompanyModal() {
    const map = {
        'ac_company_name':          'co_company_name',
        'ac_address_line1':         'co_address_line1',
        'ac_address_line2':         'co_address_line2',
        'ac_city':                  'co_city',
        'ac_state':                 'co_state',
        'ac_pincode':               'co_pincode',
        'ac_phone':                 'co_phone',
        'ac_email':                 'co_email',
        'ac_gst_number':            'co_gst_number',
        'ac_cin_number':            'co_cin_number',
        'ac_pan':                   'co_pan',
        'ac_website':               'co_website',
        'ac_company_logo_existing': 'co_company_logo',
    };
    Object.entries(map).forEach(([toId, fromId]) => {
        const toEl = document.getElementById(toId);
        if (!toEl) return;
        const fromEl = document.getElementById(fromId);
        toEl.value = fromEl ? (fromEl.value || '') : '';
    });
    const fileEl = document.getElementById('ac_company_logo');
    if (fileEl) fileEl.value = '';
    const logoPath = (document.getElementById('ac_company_logo_existing')?.value || '').trim();
    const img = document.getElementById('ac_logo_preview');
    const placeholder = document.getElementById('ac_logo_placeholder');
    if (img && placeholder) {
        if (logoPath) {
            img.src = logoPath.startsWith('/') || logoPath.startsWith('http') ? logoPath : '/invoice/' + logoPath;
            img.style.display = 'block'; placeholder.style.display = 'none';
        } else {
            img.src = ''; img.style.display = 'none'; placeholder.style.display = 'block';
        }
    }
    const st = document.getElementById('ac_status');
    if (st) st.style.display = 'none';
    document.getElementById('addCompanyModal').style.display = 'flex';
}
function closeAddCompanyModal() {
    document.getElementById('addCompanyModal').style.display = 'none';
}
document.getElementById('addCompanyModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddCompanyModal();
});
function previewLogo(input) {
    const img = document.getElementById('ac_logo_preview');
    const ph  = document.getElementById('ac_logo_placeholder');
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; img.style.display = 'block'; ph.style.display = 'none'; };
        reader.readAsDataURL(input.files[0]);
    }
}
async function saveNewCompany() {
    const name = document.getElementById('ac_company_name').value.trim();
    if (!name) { alert('Company name is required.'); return; }
    try {
        const fd = new FormData();
        fd.append('action',        'add_company');
        fd.append('company_name',  name);
        fd.append('address_line1', document.getElementById('ac_address_line1').value.trim());
        fd.append('address_line2', document.getElementById('ac_address_line2').value.trim());
        fd.append('city',          document.getElementById('ac_city').value.trim());
        fd.append('state',         document.getElementById('ac_state').value.trim());
        fd.append('pincode',       document.getElementById('ac_pincode').value.trim());
        fd.append('phone',         document.getElementById('ac_phone').value.trim());
        fd.append('email',         document.getElementById('ac_email').value.trim());
        fd.append('gst_number',    document.getElementById('ac_gst_number').value.trim().toUpperCase());
        fd.append('cin_number',    document.getElementById('ac_cin_number').value.trim().toUpperCase());
        fd.append('pan',           document.getElementById('ac_pan').value.trim().toUpperCase());
        fd.append('website',       document.getElementById('ac_website').value.trim());
        const existingLogo = (document.getElementById('ac_company_logo_existing')?.value || '').trim();
        const logoFile = document.getElementById('ac_company_logo')?.files?.[0];
        if (logoFile) fd.append('company_logo', logoFile);
        else if (existingLogo) fd.append('company_logo_existing', existingLogo);

        const res  = await fetch('createpurchase.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.success) {
            const sel = document.getElementById('company_select');
            const opt = document.createElement('option');
            opt.value           = json.id;
            opt.dataset.name    = name;
            opt.dataset.logo    = json.company_logo || '';
            opt.dataset.line1   = document.getElementById('ac_address_line1').value.trim();
            opt.dataset.line2   = document.getElementById('ac_address_line2').value.trim();
            opt.dataset.city    = document.getElementById('ac_city').value.trim();
            opt.dataset.state   = document.getElementById('ac_state').value.trim();
            opt.dataset.pincode = document.getElementById('ac_pincode').value.trim();
            opt.dataset.phone   = document.getElementById('ac_phone').value.trim();
            opt.dataset.email   = document.getElementById('ac_email').value.trim();
            opt.dataset.gst     = document.getElementById('ac_gst_number').value.trim().toUpperCase();
            opt.dataset.cin     = document.getElementById('ac_cin_number').value.trim().toUpperCase();
            opt.dataset.pan     = document.getElementById('ac_pan').value.trim().toUpperCase();
            opt.dataset.website = document.getElementById('ac_website').value.trim();
            opt.dataset.addr    = [opt.dataset.line1, opt.dataset.city].filter(Boolean).join(', ');
            opt.textContent     = name + (opt.dataset.addr ? ' — ' + opt.dataset.addr : '');
            sel.appendChild(opt);
            if (window.$) {
                $(sel).append(new Option(opt.textContent, json.id, true, true)).trigger('change');
                // Copy dataset to new Select2 option element
                const newOpt = sel.querySelector('option[value="' + json.id + '"]');
                if (newOpt) Object.assign(newOpt.dataset, opt.dataset);
            } else {
                sel.value = json.id;
            }
            onCompanyChange(sel);
            const status = document.getElementById('ac_status');
            status.textContent = '✓ Company saved & selected!';
            status.style.display = 'block';
            setTimeout(closeAddCompanyModal, 1200);
        } else {
            alert('Error: ' + (json.message || 'Save failed'));
        }
    } catch(e) {
        alert('Error: ' + e.message);
    }
}
function onCompanyChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val || ''; };
    setVal('co_company_name',  opt.dataset.name);
    // Normalize logo path to absolute for subdirectory files
    const rawLogo = opt.dataset.logo || '';
    const absLogo = rawLogo && !rawLogo.startsWith('/') && !rawLogo.startsWith('http') ? '/invoice/' + rawLogo : rawLogo;
    setVal('co_company_logo', absLogo);
    setVal('co_address_line1', opt.dataset.line1);
    setVal('co_address_line2', opt.dataset.line2);
    setVal('co_city',          opt.dataset.city);
    setVal('co_state',         opt.dataset.state);
    setVal('co_pincode',       opt.dataset.pincode);
    setVal('co_phone',         opt.dataset.phone);
    setVal('co_email',         opt.dataset.email);
    setVal('co_gst_number',    opt.dataset.gst);
    setVal('co_cin_number',    opt.dataset.cin);
    setVal('co_pan',           opt.dataset.pan);
    setVal('co_website',       opt.dataset.website);
    const chg = document.getElementById('co_company_changed') || document.getElementById('co_changed');
    if (chg) chg.value = '1';
}
</script>
</body>
</html>

<?php
require_once dirname(__DIR__) . '/db.php';

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

date_default_timezone_set('Asia/Kolkata');
error_reporting(E_ERROR); ini_set('display_errors',0);

// ── Ensure purchases table exists ──────────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(255) NOT NULL DEFAULT '',
    contact_person VARCHAR(255) DEFAULT '',
    supplier_address TEXT DEFAULT '',
    supplier_gstin VARCHAR(100) DEFAULT '',
    supplier_phone VARCHAR(50) DEFAULT '',
    invoice_number VARCHAR(100) NOT NULL DEFAULT '',
    reference VARCHAR(255) DEFAULT '',
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    voucher_number VARCHAR(100) DEFAULT '',
    voucher_date DATE DEFAULT NULL,
    supplier_ledger VARCHAR(255) DEFAULT '',
    purchase_ledger VARCHAR(255) DEFAULT '',
    credit_month VARCHAR(50) DEFAULT 'None',
    notes TEXT DEFAULT '',
    items_json LONGTEXT DEFAULT NULL,
    terms_json LONGTEXT DEFAULT NULL,
    total_taxable DECIMAL(15,2) DEFAULT 0.00,
    total_cgst DECIMAL(15,2) DEFAULT 0.00,
    total_sgst DECIMAL(15,2) DEFAULT 0.00,
    total_igst DECIMAL(15,2) DEFAULT 0.00,
    grand_total DECIMAL(15,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");
try { $pdo->exec("ALTER TABLE purchases ADD COLUMN terms_json LONGTEXT DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE purchases ADD COLUMN item_list JSON DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE purchases ADD COLUMN terms_list JSON DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE purchases ADD COLUMN company_override TEXT DEFAULT NULL"); } catch(Exception $e){}

// ── Ensure po_master_terms table exists ────────────────────────
$pdo->exec("CREATE TABLE IF NOT EXISTS po_master_terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
// Seed default terms if empty
$termCount = $pdo->query("SELECT COUNT(*) FROM po_master_terms")->fetchColumn();
if ($termCount == 0) {
    $defaultTerms = [
        'Specifications: PLC and extension modules must match the approved model numbers and technical specifications mentioned in PO.',
        'Delivery Responsibility: Vendor is responsible for safe packing and delivery of the PLC and modules.',
        'Acceptance: Material will be accepted only after inspection at our site.',
        'Delivery Time: Material to be delivered within 7 days from the date of PO release.',
        'Payment Terms: Payment will be released as per agreed terms.',
        'Warranty: Standard manufacturer warranty must be provided.',
        'Inspection: Material shall be inspected at the customer end upon receipt.',
        'Freight charges are included in the quotation.',
        'Freight: extra at actuals',
        'Installation & Commissioning included in the above Price.',
    ];
    $ins = $pdo->prepare("INSERT INTO po_master_terms (term_text) VALUES (?)");
    foreach ($defaultTerms as $dt) { $ins->execute([$dt]); }
}

// ── AJAX: get suppliers ─────────────────────────────────────────
if (isset($_GET['get_suppliers'])) {
    header('Content-Type: application/json');
    try {
        // Try po_suppliers first, fallback to customers or purchases history
        $suppliers = [];
        try {
            $s = $pdo->query("SELECT id, supplier_name, contact_person, phone, address, gstin FROM po_suppliers ORDER BY supplier_name ASC");
            $suppliers = $s->fetchAll(PDO::FETCH_ASSOC);
        } catch(Exception $e) {}
        // Also pull distinct supplier names from purchases table
        if (empty($suppliers)) {
            $s2 = $pdo->query("SELECT DISTINCT supplier_name, contact_person, supplier_phone as phone, supplier_address as address, supplier_gstin as gstin FROM purchases WHERE supplier_name != '' ORDER BY supplier_name ASC");
            $suppliers = $s2->fetchAll(PDO::FETCH_ASSOC);
        }
        echo json_encode(array_values($suppliers));
    } catch(Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// ── AJAX: save new supplier ─────────────────────────────────────
if (isset($_POST['save_supplier_ajax'])) {
    header('Content-Type: application/json');
    try {
        $name = trim($_POST['supplier_name'] ?? '');
        if (!$name) { echo json_encode(['success'=>false,'message'=>'Name required']); exit; }
        // ensure po_suppliers exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS po_suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_name VARCHAR(255) NOT NULL,
            contact_person VARCHAR(255) DEFAULT '',
            phone VARCHAR(50) DEFAULT '',
            email VARCHAR(255) DEFAULT '',
            address TEXT DEFAULT '',
            gstin VARCHAR(100) DEFAULT '',
            pan VARCHAR(20) DEFAULT '',
            website VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $ins = $pdo->prepare("INSERT INTO po_suppliers (supplier_name,contact_person,phone,email,address,gstin,pan,website) VALUES (?,?,?,?,?,?,?,?)");
        $ins->execute([
            $name,
            trim($_POST['contact_person']??''),
            trim($_POST['phone']??''),
            trim($_POST['email']??''),
            trim($_POST['address']??''),
            strtoupper(trim($_POST['gstin']??'')),
            strtoupper(trim($_POST['pan']??'')),
            trim($_POST['website']??''),
        ]);
        echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId(),'supplier_name'=>$name]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX: get items from po_master_items ────────────────────────
if (isset($_GET['get_items'])) {
    header('Content-Type: application/json');
    try {
        $items = $pdo->query("SELECT * FROM po_master_items ORDER BY item_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($items);
    } catch(Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// ── AJAX: save new item to po_master_items ───────────────────────
if (isset($_POST['save_new_item_ajax'])) {
    header('Content-Type: application/json');
    try {
        $item_name = trim($_POST['item_name'] ?? '');
        if (!$item_name) { echo json_encode(['success'=>false,'message'=>'Item name required']); exit; }
        // Ensure po_master_items table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS po_master_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_name VARCHAR(255) NOT NULL,
            description TEXT DEFAULT '',
            hsn_sac VARCHAR(50) DEFAULT '',
            unit VARCHAR(50) DEFAULT 'no.s',
            rate DECIMAL(15,2) DEFAULT 0.00,
            cgst_pct DECIMAL(5,2) DEFAULT 0.00,
            sgst_pct DECIMAL(5,2) DEFAULT 0.00,
            igst_pct DECIMAL(5,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $ins = $pdo->prepare("INSERT INTO po_master_items (item_name, description, hsn_sac, unit, rate, cgst_pct, sgst_pct, igst_pct) VALUES (?,?,?,?,?,?,?,?)");
        $ins->execute([
            $item_name,
            trim($_POST['description'] ?? ''),
            trim($_POST['hsn_sac']     ?? ''),
            trim($_POST['unit']        ?? 'no.s'),
            floatval($_POST['rate']    ?? 0),
            floatval($_POST['cgst_pct'] ?? 0),
            floatval($_POST['sgst_pct'] ?? 0),
            floatval($_POST['igst_pct'] ?? 0),
        ]);
        $newId = $pdo->lastInsertId();
        echo json_encode(['success'=>true, 'id'=>$newId, 'item_name'=>$item_name]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX: get terms from po_master_terms ────────────────────────
if (isset($_GET['get_terms'])) {
    header('Content-Type: application/json');
    try {
        $terms = $pdo->query("SELECT * FROM po_master_terms ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($terms);
    } catch(Exception $e) {
        echo json_encode([]);
    }
    exit;
}

// ── AJAX: save new term to po_master_terms ───────────────────────
if (isset($_POST['save_new_term_ajax'])) {
    header('Content-Type: application/json');
    try {
        $term_text = trim($_POST['term_text'] ?? '');
        if (!$term_text) { echo json_encode(['success'=>false,'message'=>'Term text required']); exit; }
        $ins = $pdo->prepare("INSERT INTO po_master_terms (term_text) VALUES (?)");
        $ins->execute([$term_text]);
        echo json_encode(['success'=>true, 'id'=>(int)$pdo->lastInsertId(), 'term_text'=>$term_text]);
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

$editId    = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$isEdit    = $editId > 0;
$editInv   = null;
$editItems = [];
$editTerms = [];
$error     = '';

if ($isEdit) {
    $es = $pdo->prepare("SELECT * FROM purchases WHERE id=?");
    $es->execute([$editId]);
    $editInv = $es->fetch(PDO::FETCH_ASSOC);
    if (!$editInv){ $isEdit=false; $editId=0; }
    else {
        $editItems = json_decode($editInv['items_json']??'[]',true)?:[];
        $editTerms = json_decode($editInv['terms_json']??'[]',true)?:[];
        // Load selected term IDs for edit
        $editTermIds = json_decode($editInv['terms_list']??'[]',true)?:[];
        $editItemIds = json_decode($editInv['item_list']??'[]',true)?:[];
    }
}

/* ── Company override (invoice_company) for Supplier Invoice ── */
$allCompanies = [];
try { $allCompanies = $pdo->query("SELECT * FROM invoice_company ORDER BY company_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch(Exception $e) { $allCompanies = []; }
$companyBase = $allCompanies[0] ?? [];

$existingCompanyOverride = [];
if ($isEdit && is_array($editInv) && !empty($editInv['company_override'])) {
    $existingCompanyOverride = json_decode($editInv['company_override'], true);
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

/* ── SAVE ── */
if (isset($_POST['save_supplier_invoice'])) {
    $editId = (int)($_POST['edit_id'] ?? 0);
    $isEdit = $editId > 0;
    try {
        $supplier_name    = trim($_POST['supplier_name']    ?? '');
        $contact_person   = trim($_POST['contact_person']   ?? '');
        $supplier_address = trim($_POST['supplier_address'] ?? '');
        $supplier_gstin   = strtoupper(trim($_POST['supplier_gstin'] ?? ''));
        $supplier_phone   = trim($_POST['supplier_phone']   ?? '');
        $invoice_number   = trim($_POST['invoice_number']   ?? '');
        $reference        = trim($_POST['reference']        ?? '');
        $invoice_date     = $_POST['invoice_date']  ?? date('Y-m-d');
        $due_date         = $_POST['due_date']       ?? date('Y-m-d');
        $voucher_number   = trim($_POST['voucher_number']   ?? '');
        $voucher_date     = $_POST['voucher_date']   ?? date('Y-m-d');
        $supplier_ledger  = trim($_POST['supplier_ledger']  ?? '');
        $purchase_ledger  = trim($_POST['purchase_ledger']  ?? '');
        $credit_month     = trim($_POST['credit_month']     ?? 'None');
        $notes            = trim($_POST['notes']            ?? '');

        // Company override snapshot (invoice_company fields)
        $co_data = [
            'company_name'  => trim($_POST['co_company_name']  ?? ''),
            'address_line1' => trim($_POST['co_address_line1'] ?? ''),
            'address_line2' => trim($_POST['co_address_line2'] ?? ''),
            'city'          => trim($_POST['co_city']          ?? ''),
            'state'         => trim($_POST['co_state']         ?? ''),
            'pincode'       => trim($_POST['co_pincode']       ?? ''),
            'phone'         => trim($_POST['co_phone']         ?? ''),
            'email'         => trim($_POST['co_email']         ?? ''),
            'gst_number'    => strtoupper(trim($_POST['co_gst_number'] ?? '')),
            'cin_number'    => strtoupper(trim($_POST['co_cin_number'] ?? '')),
            'pan'           => strtoupper(trim($_POST['co_pan']        ?? '')),
            'website'       => trim($_POST['co_website']       ?? ''),
            'company_logo'  => trim($_POST['co_company_logo']  ?? ''),
        ];
        $company_override = !empty($co_data['company_name']) ? json_encode($co_data) : null;

        $terms_raw  = $_POST['terms'] ?? [];
        $terms_arr  = array_values(array_filter(array_map('trim', (array)$terms_raw)));
        $terms_json = json_encode($terms_arr);

        // Store selected term IDs as list
        $terms_id_raw = $_POST['terms_ids'] ?? [];
        $terms_list   = json_encode(array_values(array_filter(array_map('intval', (array)$terms_id_raw))));

        // Store selected item IDs as list
        $items_id_raw = $_POST['item_ids'] ?? [];
        $item_list    = json_encode(array_values(array_filter(array_map('intval', (array)$items_id_raw))));

        $items = [];
        $total_taxable=$total_cgst=$total_sgst=$total_igst=$grand_total=0;
        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $it) {
                $desc     = trim($it['description'] ?? '');
                if ($desc === '') continue;
                $qty      = floatval($it['qty']      ?? 1);
                $rate     = floatval($it['rate']     ?? 0);
                $discount = floatval($it['discount'] ?? 0);
                $basic    = max(0, ($qty * $rate) - $discount);
                $cgst_pct = floatval($it['cgst_percent'] ?? 0);
                $sgst_pct = floatval($it['sgst_percent'] ?? 0);
                $igst_pct = floatval($it['igst_percent'] ?? 0);
                $cgst_amt = round($basic * $cgst_pct / 100, 2);
                $sgst_amt = round($basic * $sgst_pct / 100, 2);
                $igst_amt = round($basic * $igst_pct / 100, 2);
                $total    = $basic + $cgst_amt + $sgst_amt + $igst_amt;
                $items[]  = [
                    'description'  => $desc,
                    'hsn_sac'      => trim($it['hsn_sac'] ?? ''),
                    'qty'          => $qty,
                    'unit'         => trim($it['unit']    ?? ''),
                    'rate'         => $rate,
                    'discount'     => $discount,
                    'basic_amount' => $basic,
                    'cgst_percent' => $cgst_pct,
                    'sgst_percent' => $sgst_pct,
                    'igst_percent' => $igst_pct,
                    'cgst_amount'  => $cgst_amt,
                    'sgst_amount'  => $sgst_amt,
                    'igst_amount'  => $igst_amt,
                    'total'        => $total,
                ];
                $total_taxable += $basic;
                $total_cgst    += $cgst_amt;
                $total_sgst    += $sgst_amt;
                $total_igst    += $igst_amt;
                $grand_total   += $total;
            }
        }
        $items_json = json_encode($items);

        $fields = compact(
            'supplier_name','contact_person','supplier_address','supplier_gstin','supplier_phone',
            'invoice_number','reference','invoice_date','due_date',
            'voucher_number','voucher_date','supplier_ledger','purchase_ledger','credit_month',
            'notes','company_override','items_json','terms_json','item_list','terms_list',
            'total_taxable','total_cgst','total_sgst','total_igst','grand_total'
        );

        if ($isEdit) {
            $sets = implode(',', array_map(fn($k)=>"$k=:$k", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE purchases SET $sets WHERE id=:id");
            $fields['id'] = $editId;
        } else {
            $cols = implode(',', array_keys($fields));
            $vals = ':'.implode(',:', array_keys($fields));
            $stmt = $pdo->prepare("INSERT INTO purchases ($cols) VALUES ($vals)");
        }
        $stmt->execute($fields);
        header("Location: supplier_invoices.php?success=1");
        exit;
    } catch (Exception $e) {
        $error = "Error saving: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title><?= $isEdit ? 'Edit' : 'Create' ?> Supplier Invoice</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;background:#f0f2f8;color:#1a1f2e;font-size:13px}
.content{margin-left:220px;padding:14px 16px 16px;min-height:100vh;background:#f0f2f8}

/* PAGE HEADER */
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.page-header-left{display:flex;align-items:center;gap:10px}
.page-icon{width:34px;height:34px;background:linear-gradient(135deg,#f97316,#fb923c);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;box-shadow:0 2px 8px rgba(249,115,22,.3)}
.page-title{font-size:16px;font-weight:800;color:#1a1f2e;line-height:1.2}
.page-sub{font-size:11px;color:#9ca3af;margin-top:1px}

/* CARDS */
.form-card{background:#fff;border:1px solid #e8ecf4;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.04);margin-bottom:8px;overflow:hidden}
.form-card-header{display:flex;align-items:center;gap:8px;padding:7px 14px;border-bottom:1px solid #f0f2f7;background:#fafbfd}
.hdr-icon{width:22px;height:22px;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;flex-shrink:0}
.form-card-header h3{font-size:12px;font-weight:800;color:#1a1f2e;text-transform:uppercase;letter-spacing:.5px}
.form-card-body{padding:8px 14px 10px}

/* UNIFIED FIELD LABELS + INPUTS */
.field-section-label,label{display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#9ca3af;margin-bottom:3px}
.field-input-styled,
.field-select-styled,
.form-control,
.form-select{
  width:100%;
  padding:6px 10px;
  border:1.5px solid #e4e8f0;
  border-radius:7px;
  font-size:12px;
  font-family:'Segoe UI',system-ui,sans-serif;
  color:#374151;
  background:#fff;
  outline:none;
  transition:border-color .2s,box-shadow .2s;
  height:30px;
  line-height:1.4;
}
textarea.field-input-styled,
textarea.form-control{height:52px;resize:vertical;line-height:1.5}
.field-input-styled:focus,.field-select-styled:focus,.form-control:focus,.form-select:focus{border-color:#f97316;box-shadow:0 0 0 2px rgba(249,115,22,.1)}
.field-input-styled[readonly]{background:#f8fafc;cursor:pointer}
.supplier-field-wrap,.supplier-input-row{display:flex;gap:5px;align-items:center}
.supplier-field-wrap .field-input-styled,.supplier-input-row .form-control{flex:1}
.row>[class*=col]{margin-bottom:6px}

/* TWO-COL LAYOUT */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}

/* BUTTONS */
.btn-theme{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;box-shadow:0 2px 8px rgba(249,115,22,.25)}
.btn-theme:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(249,115,22,.35)}
.btn-outline-theme{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;background:#fff;border:1.5px solid #e4e8f0;border-radius:7px;font-size:12px;font-weight:600;color:#374151;text-decoration:none;cursor:pointer;transition:all .2s}
.btn-outline-theme:hover{border-color:#f97316;color:#f97316;background:#fff7f0}
.btn-add-item{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;margin-top:6px}
.btn-add-item:hover{transform:translateY(-1px)}
.btn-plus{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:7px;font-size:14px;cursor:pointer;transition:all .2s;flex-shrink:0}
.btn-plus:hover{transform:translateY(-1px)}
.btn-danger-sm{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;background:#fef2f2;border:1px solid #fca5a5;border-radius:5px;color:#dc2626;cursor:pointer;font-size:10px;transition:all .2s}
.btn-danger-sm:hover{background:#dc2626;color:#fff;border-color:#dc2626}
.bottom-actions{display:flex;gap:8px;align-items:center;padding:10px 14px;border-top:1px solid #f0f2f7;background:#fafbfd}

/* ITEM TABLE */
.table-wrap{overflow-x:auto;max-height:280px;overflow-y:auto}
#itemTable{width:100%;border-collapse:collapse;font-size:11.5px;background:#fff;min-width:900px}
#itemTable thead th{background:#fff7f0;color:#f97316;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:5px 5px;border-bottom:2px solid #fed7aa;white-space:nowrap;position:sticky;top:0;z-index:1}
#itemTable td{padding:3px 5px;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#374151}
#itemTable tbody tr:hover td{background:#fafbff}
#itemTable input.form-control{height:26px;padding:3px 5px;font-size:11.5px;border-radius:5px;border:1.5px solid #e4e8f0;background:#fff;font-family:'Segoe UI',system-ui,sans-serif}
#itemTable input.form-control:focus{border-color:#f97316;box-shadow:0 0 0 2px rgba(249,115,22,.1)}
#itemTable tfoot td{padding:5px;font-weight:700;font-size:12px;border-top:2px solid #e4e8f0;background:#f8fafc}

/* TOTALS BOX */
.total-box{display:flex;gap:20px;justify-content:flex-end;flex-wrap:wrap;margin-top:8px;padding:8px 14px;background:#fff7f0;border-radius:8px;border:1px solid #fed7aa;font-size:12px;color:#374151}
.total-box .grand{font-size:13px;font-weight:800;color:#1a1f2e;padding-left:16px;border-left:2px solid #f97316}

/* TERMS */
.term-row{display:flex;align-items:center;gap:6px;background:#fafafa;border:1px solid #f0f2f7;border-radius:7px;padding:6px 10px;margin-bottom:4px;font-size:12px}
.term-row span{flex:1;color:#374151}
.term-actions{display:flex;gap:3px;flex-shrink:0}
.term-btn{width:22px;height:22px;border-radius:5px;border:none;cursor:pointer;font-size:10px;display:inline-flex;align-items:center;justify-content:center}
.term-btn-edit{background:#fff8e1;color:#f57c00}.term-btn-del{background:#fef2f2;color:#dc2626}
.btn-add-term{background:none;border:1.5px dashed #f97316;color:#f97316;border-radius:7px;padding:5px 11px;cursor:pointer;font-size:11px;font-weight:700;margin-top:3px;display:inline-flex;align-items:center;gap:5px}
.btn-add-term:hover{background:#fff7f0}

/* SUPPLIER POPUP */
.sp-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:3000;align-items:center;justify-content:center}
.sp-overlay.open{display:flex}
.sp-box{background:#fff;border-radius:12px;width:380px;max-width:95vw;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:hidden;font-family:'Segoe UI',system-ui,sans-serif}
.sp-header{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f0f2f7;background:linear-gradient(135deg,#fff7f0,#fff)}
.sp-header-left{display:flex;align-items:center;gap:8px}
.sp-header-icon{width:28px;height:28px;background:linear-gradient(135deg,#f97316,#fb923c);border-radius:7px;display:flex;align-items:center;justify-content:center}
.sp-header h3{font-size:13px;font-weight:800;color:#1a1f2e}
.sp-close{background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer;width:26px;height:26px;display:flex;align-items:center;justify-content:center;border-radius:50%}
.sp-close:hover{background:#fee2e2;color:#dc2626}
.sp-search-wrap{padding:8px 14px;border-bottom:1px solid #f0f2f7}
.sp-search{width:100%;border:1.5px solid #d1d5db;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;outline:none}
.sp-search:focus{border-color:#f97316}
.sp-list{overflow-y:auto;flex:1;min-height:100px}
.sp-item{padding:8px 14px;cursor:pointer;border-bottom:1px solid #f9f9f9;transition:background .1s}
.sp-item:hover{background:#fff7f0}
.sp-item-name{font-size:12px;font-weight:700;color:#1a1f2e}
.sp-item-sub{font-size:11px;color:#9ca3af;margin-top:1px}
.sp-empty{padding:20px;text-align:center;color:#9ca3af;font-size:12px}
.sp-footer{padding:10px 14px;border-top:1px solid #f0f2f7}
.sp-add-btn{width:100%;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:7px;padding:7px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:5px}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:12px;width:440px;max-width:96vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.15);font-family:'Segoe UI',system-ui,sans-serif}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #e4e8f0;position:sticky;top:0;background:#fafbfc;z-index:1;border-radius:12px 12px 0 0}
.modal-header h3{font-size:13px;font-weight:800;color:#1a1f2e}
.modal-close{background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer}
.modal-body{padding:12px 14px}
.mf-group{margin-bottom:8px}
.mf-label{display:block;font-size:10px;font-weight:700;color:#9ca3af;margin-bottom:3px;text-transform:uppercase;letter-spacing:.7px}
.mf-input{width:100%;border:1.5px solid #e4e8f0;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;outline:none}
.mf-input:focus{border-color:#f97316;box-shadow:0 0 0 2px rgba(249,115,22,.08)}
.mf-textarea{width:100%;border:1.5px solid #e4e8f0;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;outline:none;resize:vertical;min-height:50px}
.mf-textarea:focus{border-color:#f97316;box-shadow:0 0 0 2px rgba(249,115,22,.08)}
.mf-row{display:flex;gap:8px;align-items:flex-end}
.mf-row .mf-group{flex:1;margin-bottom:0}
.prefix-box{display:flex;align-items:center;border:1.5px solid #e4e8f0;border-radius:7px;overflow:hidden}
.prefix-box span{background:#f9fafb;padding:5px 8px;font-size:12px;color:#6b7280;border-right:1px solid #e4e8f0;white-space:nowrap}
.prefix-box input{border:none;padding:5px 8px;font-size:12px;font-family:inherit;outline:none;flex:1;width:100%}
.modal-footer{padding:10px 14px;border-top:1px solid #e4e8f0;display:flex;gap:6px;background:#fafbfc;border-radius:0 0 12px 12px}
.btn-modal-save{background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:7px;padding:7px 18px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:5px}
.btn-modal-cancel{background:#f5f5f5;color:#374151;border:1.5px solid #e4e8f0;border-radius:7px;padding:7px 14px;font-size:12px;cursor:pointer;font-family:inherit}

/* ITEM SELECT MODAL */
.item-select-row{display:flex;align-items:center;gap:8px;padding:7px 12px;border-bottom:1px solid #f5f5f5;cursor:pointer;transition:background .1s}
.item-select-row:hover{background:#fff7f0}
.item-select-name{font-size:12px;font-weight:700;color:#1a1f2e;flex:1}
.item-select-sub{font-size:11px;color:#9ca3af;margin-top:1px}
.modal-search{width:100%;border:1.5px solid #e4e8f0;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;outline:none;margin-bottom:8px}
.modal-search:focus{border-color:#f97316;box-shadow:0 0 0 2px rgba(249,115,22,.08)}

/* TERMS POPUP */
.term-popup-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:3000;align-items:center;justify-content:center}
.term-popup-overlay.open{display:flex}
.term-popup-box{background:#fff;border-radius:12px;width:500px;max-width:95vw;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.2);overflow:hidden;font-family:'Segoe UI',system-ui,sans-serif}

/* SELECT2 height fix */
.select2-container .select2-selection--single{height:30px!important;line-height:28px!important;border:1.5px solid #e4e8f0!important;border-radius:7px!important;font-size:12px!important}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:28px!important}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:28px!important;padding-left:10px!important;font-size:12px}

.alert-danger{background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;padding:8px 12px;border-radius:8px;margin-bottom:8px;font-size:12px}
.val-toast{position:fixed;top:60px;left:50%;transform:translateX(-50%);background:#dc2626;color:#fff;padding:10px 20px;border-radius:8px;font-size:12px;font-weight:700;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,.25);display:none;min-width:220px;text-align:center}
.val-toast.show{display:block}
::-webkit-scrollbar{width:3px}::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:99px}
@media(max-width:900px){.two-col{grid-template-columns:1fr}.content{margin-left:0!important;padding:60px 10px 16px}}
</style>
</head>
<body>
<?php include dirname(__DIR__) . '/sidebar.php'; ?>
<?php include dirname(__DIR__) . '/header.php'; ?>

<div class="content">
<?php if($error): ?>
<div class="alert-danger"><i class="fas fa-exclamation-circle" style="margin-right:6px"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="post" id="siForm">
<?php if($isEdit): ?><input type="hidden" name="edit_id" value="<?= $editId ?>"><?php endif; ?>

<!-- Company override hidden fields (posted to save on submit) -->
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

<!-- PAGE HEADER -->
<div class="page-header">
    <div class="page-header-left">
        <div class="page-icon"><i class="fas fa-file-invoice-dollar"></i></div>
        <div>
            <div class="page-title"><?= $isEdit ? 'Edit Supplier Invoice' : 'Create Supplier Invoice' ?></div>
            <div class="page-sub"><?= $isEdit ? 'Update details of invoice '.htmlspecialchars($editInv['invoice_number']??'') : 'Fill in the details to record a supplier invoice' ?></div>
        </div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
        <a href="supplier_invoices.php" class="btn-outline-theme"><i class="fas fa-arrow-left"></i> Back</a>
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
        <button type="submit" name="save_supplier_invoice" class="btn-theme"><i class="fas fa-save"></i> <?= $isEdit?'Update':'Save' ?> Invoice</button>
    </div>
</div>

<!-- SUPPLIER INFO + DOCUMENT + ACCOUNTS – 3 columns in one card -->
<div class="form-card">
    <div class="form-card-header">
        <div class="hdr-icon" style="background:linear-gradient(135deg,#f97316,#fb923c)"><i class="fas fa-truck"></i></div>
        <h3>Supplier Information</h3>
    </div>
    <div class="form-card-body">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">

            <!-- COLUMN 1: Supplier Info -->
            <div style="border:1px solid #e8ecf4;border-radius:8px;padding:8px 10px;background:#fafbfd">
                <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#f97316;margin-bottom:6px;display:flex;align-items:center;gap:5px">
                    <i class="fas fa-truck" style="font-size:9px"></i> Supplier Info
                </div>
                <!-- Row 1: Supplier -->
                <div style="margin-bottom:5px">
                    <span class="field-section-label">Supplier</span>
                    <div class="supplier-field-wrap">
                        <input type="text" name="supplier_name" id="supplierInput" class="field-input-styled" required
                               value="<?= htmlspecialchars($editInv['supplier_name']??'') ?>"
                               placeholder="— Select Supplier —"
                               onclick="openSupplierPopup()" readonly>
                        <button type="button" class="btn-plus" onclick="openSupplierPopup()" title="Select / Add Supplier"><i class="fas fa-plus"></i></button>
                    </div>
                </div>
                <!-- Row 2: Contact Person + Phone -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:5px">
                    <div>
                        <span class="field-section-label">Contact Person</span>
                        <input type="text" name="contact_person" id="contactInput" class="field-input-styled" value="<?= htmlspecialchars($editInv['contact_person']??'') ?>">
                    </div>
                    <div>
                        <span class="field-section-label">Phone</span>
                        <input type="text" name="supplier_phone" id="phoneInput" class="field-input-styled" value="<?= htmlspecialchars($editInv['supplier_phone']??'') ?>">
                    </div>
                </div>
                <!-- Row 3: GSTIN + Address -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
                    <div>
                        <span class="field-section-label">GSTIN</span>
                        <input type="text" name="supplier_gstin" id="gstinInput" class="field-input-styled" value="<?= htmlspecialchars($editInv['supplier_gstin']??'') ?>" style="text-transform:uppercase" placeholder="27AABCS1234A1Z5">
                    </div>
                    <div>
                        <span class="field-section-label">Address</span>
                        <input type="text" name="supplier_address" id="addressInput" class="field-input-styled" value="<?= htmlspecialchars($editInv['supplier_address']??'') ?>" placeholder="Supplier address">
                    </div>
                </div>
            </div>

            <!-- COLUMN 2: Document Details -->
            <div style="border:1px solid #e8ecf4;border-radius:8px;padding:8px 10px;background:#fafbfd">
                <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#7c3aed;margin-bottom:6px;display:flex;align-items:center;gap:5px">
                    <i class="fas fa-file-alt" style="font-size:9px"></i> Document Details
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:5px">
                    <div>
                        <label>Invoice No.</label>
                        <input type="text" name="invoice_number" class="form-control" required value="<?= htmlspecialchars($editInv['invoice_number']??'') ?>">
                    </div>
                    <div>
                        <label>Reference</label>
                        <input type="text" name="reference" class="form-control" value="<?= htmlspecialchars($editInv['reference']??'') ?>">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
                    <div>
                        <label>Invoice Date</label>
                        <input type="date" name="invoice_date" class="form-control" required value="<?= htmlspecialchars($editInv['invoice_date']??date('Y-m-d')) ?>">
                    </div>
                    <div>
                        <label>Due Date</label>
                        <input type="date" name="due_date" class="form-control" required value="<?= htmlspecialchars($editInv['due_date']??date('Y-m-d')) ?>">
                    </div>
                </div>
            </div>

            <!-- COLUMN 3: Accounts Update -->
            <div style="border:1px solid #e8ecf4;border-radius:8px;padding:8px 10px;background:#fafbfd">
                <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#0284c7;margin-bottom:6px;display:flex;align-items:center;gap:5px">
                    <i class="fas fa-book" style="font-size:9px"></i> Accounts Update
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:5px">
                    <div>
                        <label>Supplier Ledger</label>
                        <input type="text" name="supplier_ledger" class="form-control" value="<?= htmlspecialchars($editInv['supplier_ledger']??'') ?>">
                    </div>
                    <div>
                        <label>Purchase Ledger</label>
                        <input type="text" name="purchase_ledger" class="form-control" value="<?= htmlspecialchars($editInv['purchase_ledger']??'') ?>">
                    </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px">
                    <div>
                        <label>Voucher No.</label>
                        <input type="text" name="voucher_number" class="form-control" value="<?= htmlspecialchars($editInv['voucher_number']??'') ?>">
                    </div>
                    <div>
                        <label>Voucher Date</label>
                        <input type="date" name="voucher_date" class="form-control" value="<?= htmlspecialchars($editInv['voucher_date']??date('Y-m-d')) ?>">
                    </div>
                    <div>
                        <label>Credit Month</label>
                        <select name="credit_month" class="form-select">
                            <?php foreach(['None','1','2','3','6','12'] as $cm): ?>
                            <option value="<?=$cm?>" <?=($editInv['credit_month']??'None')===$cm?'selected':''?>><?=$cm?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- ITEM LIST -->
<div class="form-card">
    <div class="form-card-header" style="justify-content:space-between">
        <div style="display:flex;align-items:center;gap:10px">
            <div class="hdr-icon" style="background:linear-gradient(135deg,#16a34a,#15803d)"><i class="fas fa-list"></i></div>
            <h3>Item List</h3>
        </div>
    </div>
    <div class="table-wrap">
        <table id="itemTable">
            <thead>
                <tr>
                    <th style="width:32px">No.</th>
                    <th>Item &amp; Description</th>
                    <th style="width:90px">HSN/SAC</th>
                    <th style="width:65px">Qty</th>
                    <th style="width:55px">Unit</th>
                    <th style="width:85px">Rate (&#8377;)</th>
                    <th style="width:75px">Discount</th>
                    <th style="width:90px">Taxable (&#8377;)</th>
                    <th style="width:60px">CGST%</th>
                    <th style="width:60px">SGST%</th>
                    <th style="width:60px">IGST%</th>
                    <th style="width:90px">Amt (&#8377;)</th>
                    <th style="width:30px"></th>
                </tr>
            </thead>
            <tbody id="itemBody">
            <?php if($isEdit && !empty($editItems)): ?>
            <?php foreach($editItems as $i=>$it): $idx=$i+1; ?>
            <tr>
                <td style="text-align:center;color:#9ca3af;font-size:12px"><?=$idx?></td>
                <td><input type="text" name="items[<?=$idx?>][description]" class="form-control" value="<?=htmlspecialchars($it['description']??'')?>" placeholder="Item description"></td>
                <td><input type="text" name="items[<?=$idx?>][hsn_sac]" class="form-control" value="<?=htmlspecialchars($it['hsn_sac']??'')?>"></td>
                <td><input type="number" name="items[<?=$idx?>][qty]" class="form-control qty" value="<?=$it['qty']??1?>" min="0" step="0.001" oninput="recalcRow(this)"></td>
                <td><input type="text" name="items[<?=$idx?>][unit]" class="form-control" value="<?=htmlspecialchars($it['unit']??'')?>"></td>
                <td><input type="number" name="items[<?=$idx?>][rate]" class="form-control rate" value="<?=$it['rate']??0?>" min="0" step="0.01" oninput="recalcRow(this)"></td>
                <td><input type="number" name="items[<?=$idx?>][discount]" class="form-control discount" value="<?=$it['discount']??0?>" min="0" step="0.01" oninput="recalcRow(this)"></td>
                <td class="taxable" style="text-align:right;font-weight:700"><?=number_format($it['basic_amount']??0,2)?></td>
                <td><input type="number" name="items[<?=$idx?>][cgst_percent]" class="form-control cgst-rate" value="<?=$it['cgst_percent']??0?>" min="0" step="0.01" oninput="recalcRow(this)"></td>
                <td><input type="number" name="items[<?=$idx?>][sgst_percent]" class="form-control sgst-rate" value="<?=$it['sgst_percent']??0?>" min="0" step="0.01" oninput="recalcRow(this)"></td>
                <td><input type="number" name="items[<?=$idx?>][igst_percent]" class="form-control igst-rate" value="<?=$it['igst_percent']??0?>" min="0" step="0.01" oninput="recalcRow(this)"></td>
                <td class="amount" style="text-align:right;font-weight:700;color:#f97316"><?=number_format($it['total']??0,2)?></td>
                <td><button type="button" class="btn-danger-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="7" style="text-align:right;font-size:13px;color:#6b7280;font-weight:600;padding:7px">Totals</td>
                    <td id="footTaxable" style="text-align:right;padding:7px;font-weight:700">0.00</td>
                    <td colspan="3"></td>
                    <td id="footTotal" style="text-align:right;padding:7px;font-weight:700;color:#f97316">0.00</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="bottom-actions">
        <button type="button" class="btn-add-item" onclick="openSelectItemModal()"><i class="fas fa-plus"></i> Add Item</button>
        <div style="flex:1;text-align:right;font-size:12px;color:#6b7280">Grand Total: <strong style="color:#f97316;font-size:14px;font-weight:800" id="grandTotalDisplay">&#8377; 0.00</strong></div>
    </div>
</div>

<!-- TERMS & CONDITIONS -->
<div class="form-card">
    <div class="form-card-header">
        <div class="hdr-icon" style="background:linear-gradient(135deg,#0ea5e9,#0284c7)"><i class="fas fa-file-contract"></i></div>
        <h3>Terms &amp; Conditions</h3>
    </div>
    <div class="form-card-body">
        <div id="termsList">
        <?php foreach($editTerms as $term): ?>
            <div class="term-row">
                <span><?= htmlspecialchars($term) ?></span>
                <div class="term-actions">
                    <button type="button" class="term-btn term-btn-edit" onclick="editTerm(this)"><i class="fas fa-pencil-alt"></i></button>
                    <button type="button" class="term-btn term-btn-del"  onclick="removeTerm(this)"><i class="fas fa-times"></i></button>
                </div>
                <input type="hidden" name="terms[]" value="<?= htmlspecialchars($term) ?>">
            </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="btn-add-term" onclick="openTermsPopup()">
            <i class="fas fa-plus"></i> Add Term / Condition
        </button>
    </div>
</div>

<!-- NOTES -->
<div class="form-card">
    <div class="form-card-header">
        <div class="hdr-icon" style="background:linear-gradient(135deg,#64748b,#475569)"><i class="fas fa-sticky-note"></i></div>
        <h3>Notes</h3>
    </div>
    <div class="form-card-body">
        <textarea name="notes" class="form-control" rows="2" placeholder="Additional notes..."><?= htmlspecialchars($editInv['notes']??'') ?></textarea>
    </div>
</div>

<!-- BOTTOM SAVE -->
<div style="display:flex;gap:8px;justify-content:flex-start;margin-top:4px;margin-bottom:12px">
    <a href="supplier_invoices.php" class="btn-outline-theme"><i class="fas fa-times"></i> Cancel</a>
    <button type="submit" name="save_supplier_invoice" class="btn-theme"><i class="fas fa-save"></i> <?= $isEdit?'Update':'Save' ?> Invoice</button>
</div>
</form>
</div>

<!-- ═══ SUPPLIER POPUP ═══ -->
<div class="sp-overlay" id="spOverlay">
    <div class="sp-box">
        <div class="sp-header">
            <div class="sp-header-left">
                <div class="sp-header-icon"><i class="fas fa-truck" style="color:#fff;font-size:15px"></i></div>
                <div>
                    <h3>Select Supplier</h3>
                    <div style="font-size:11px;color:#9ca3af">Choose from existing or add new</div>
                </div>
            </div>
            <button class="sp-close" onclick="closeSupplierPopup()">✕</button>
        </div>
        <div class="sp-search-wrap">
            <input class="sp-search" id="spSearch" type="text" placeholder="🔍 Search suppliers..." oninput="filterSuppliers(this.value)">
        </div>
        <div class="sp-list" id="spList">
            <div class="sp-empty">Loading...</div>
        </div>
        <div class="sp-footer">
            <button class="sp-add-btn" onclick="openNewSupplierModal()"><i class="fas fa-plus"></i> Add New Supplier</button>
        </div>
    </div>
</div>

<!-- ═══ MODAL: Add New Supplier ═══ -->
<div class="modal-overlay" id="supplierModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3><i class="fas fa-truck" style="color:#f97316;margin-right:6px"></i> Add New Supplier</h3>
      <button class="modal-close" onclick="closeModal('supplierModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="mf-group">
        <label class="mf-label">Business / Company Name <span style="color:#ef4444">*</span></label>
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
          <input class="mf-input" id="ns_gstin" type="text" placeholder="22AAAAA0000A1Z5" maxlength="15" style="text-transform:uppercase">
        </div>
        <div class="mf-group">
          <label class="mf-label">PAN</label>
          <input class="mf-input" id="ns_pan" type="text" placeholder="ABCDE1234F" maxlength="10" style="text-transform:uppercase">
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-modal-save" onclick="saveNewSupplier()"><i class="fas fa-check"></i> Save Supplier</button>
      <button class="btn-modal-cancel" onclick="closeModal('supplierModal');openSupplierPopup()">← Back</button>
    </div>
  </div>
</div>

<!-- ═══ MODAL: Select Item from po_master_items ═══ -->
<div class="modal-overlay" id="selectItemModal">
  <div class="modal-box" style="width:560px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;">

    <!-- ── VIEW 1: Item Library ── -->
    <div id="itemLibView" style="display:flex;flex-direction:column;flex:1;overflow:hidden;">
      <div class="modal-header">
        <h3>📦 Item Library</h3>
        <button class="modal-close" onclick="closeModal('selectItemModal')">✕</button>
      </div>
      <div class="modal-body" style="padding:12px 16px 6px;flex:1;overflow-y:auto;">
        <input class="modal-search" id="itemSearch" type="text" placeholder="🔍 Search by name, HSN, description..." oninput="filterItems(this.value)">
        <div id="itemSelectList" style="max-height:340px;overflow-y:auto;border:1px solid #f0f2f7;border-radius:8px">
          <div style="padding:30px;text-align:center;color:#9ca3af;font-size:13px">Loading...</div>
        </div>
        <p style="font-size:11px;color:#9ca3af;margin-top:6px;text-align:center">Click <b>+ Add</b> on any item to add it to the invoice.</p>
      </div>
      <div class="modal-footer" style="justify-content:space-between;">
        <button type="button" class="btn-modal-cancel" onclick="openAddItemView()"
          style="border-color:#f97316;color:#f97316;background:#fff;display:inline-flex;align-items:center;gap:6px;">
          <i class="fas fa-plus"></i> Add New Item
        </button>
        <button class="btn-modal-save" onclick="closeModal('selectItemModal')" style="background:linear-gradient(135deg,#16a34a,#15803d)">
          <i class="fas fa-check"></i> Done
        </button>
      </div>
    </div>

    <!-- ── VIEW 2: Add New Item Form ── -->
    <div id="addItemView" style="display:none;flex-direction:column;flex:1;overflow:hidden;">
      <div class="modal-header">
        <h3><i class="fas fa-plus-circle" style="color:#f97316;margin-right:6px"></i> Add Item</h3>
        <button class="modal-close" onclick="closeModal('selectItemModal')">✕</button>
      </div>
      <div class="modal-body" style="padding:16px 18px;overflow-y:auto;flex:1;">
        <div class="mf-group">
          <label class="mf-label">Item Name <span style="color:#ef4444">*</span></label>
          <input class="mf-input" id="ni_name" type="text" placeholder="Item name">
        </div>
        <div style="display:flex;gap:10px;">
          <div class="mf-group" style="flex:1;margin-bottom:14px;">
            <label class="mf-label">Rate (₹)</label>
            <div class="prefix-box">
              <span>₹</span>
              <input id="ni_rate" type="number" placeholder="0" min="0" step="0.01" style="border:none;padding:8px 10px;font-size:13px;font-family:inherit;outline:none;flex:1;width:100%;">
            </div>
          </div>
          <div class="mf-group" style="flex:1;margin-bottom:14px;">
            <label class="mf-label">Unit</label>
            <select id="ni_unit" class="mf-input" style="cursor:pointer;padding:8px 10px;">
              <option value="no.s">no.s</option>
              <option value="kg">kg</option>
              <option value="litre">litre</option>
              <option value="meter">meter</option>
              <option value="box">box</option>
              <option value="pcs">pcs</option>
              <option value="set">set</option>
            </select>
          </div>
        </div>
        <div class="mf-group">
          <label class="mf-label">HSN/SAC</label>
          <input class="mf-input" id="ni_hsn" type="text" placeholder="HSN/SAC code">
        </div>
        <div class="mf-group">
          <label class="mf-label">Description <span style="font-weight:400;color:#9ca3af">(optional)</span></label>
          <textarea class="mf-textarea" id="ni_desc" placeholder="Description (optional)" style="min-height:60px;"></textarea>
        </div>
        <div style="display:flex;gap:10px;">
          <div class="mf-group" style="flex:1;margin-bottom:0;">
            <label class="mf-label">CGST %</label>
            <div class="prefix-box">
              <input id="ni_cgst" type="number" placeholder="0" min="0" step="0.01" style="border:none;padding:8px 10px;font-size:13px;font-family:inherit;outline:none;flex:1;width:100%;">
              <span>%</span>
            </div>
          </div>
          <div class="mf-group" style="flex:1;margin-bottom:0;">
            <label class="mf-label">SGST %</label>
            <div class="prefix-box">
              <input id="ni_sgst" type="number" placeholder="0" min="0" step="0.01" style="border:none;padding:8px 10px;font-size:13px;font-family:inherit;outline:none;flex:1;width:100%;">
              <span>%</span>
            </div>
          </div>
          <div class="mf-group" style="flex:1;margin-bottom:0;">
            <label class="mf-label">IGST %</label>
            <div class="prefix-box">
              <input id="ni_igst" type="number" placeholder="0" min="0" step="0.01" style="border:none;padding:8px 10px;font-size:13px;font-family:inherit;outline:none;flex:1;width:100%;">
              <span>%</span>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="justify-content:space-between;">
        <button type="button" class="btn-modal-cancel" onclick="closeAddItemView()">
          <i class="fas fa-arrow-left"></i> Back
        </button>
        <button type="button" class="btn-modal-save" onclick="saveNewItem()">
          <i class="fas fa-bookmark"></i> Save &amp; Add
        </button>
      </div>
    </div>

  </div>
</div>

<!-- ═══ TERMS POPUP ═══ -->
<div id="termModalOverlay" class="term-popup-overlay">
  <div class="term-popup-box">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #f0f2f7;background:linear-gradient(135deg,#fff7f0,#fff);">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:36px;height:36px;background:linear-gradient(135deg,#f97316,#fb923c);border-radius:9px;display:flex;align-items:center;justify-content:center;">
          <i class="fas fa-file-contract" style="color:#fff;font-size:15px;"></i>
        </div>
        <div>
          <div style="font-weight:800;font-size:15px;color:#1a1f2e;">Terms &amp; Conditions</div>
          <div style="font-size:11px;color:#9ca3af;">Select terms to include in supplier invoice</div>
        </div>
      </div>
      <button onclick="closeTermsPopup()" style="width:32px;height:32px;border-radius:50%;border:1.5px solid #e4e8f0;background:#fff;cursor:pointer;font-size:16px;color:#6b7280;display:flex;align-items:center;justify-content:center;">✕</button>
    </div>
    <div style="padding:12px 20px;border-bottom:1px solid #f0f2f7;">
      <input id="termSearch" type="text" placeholder="🔍 Search terms..." oninput="filterTerms(this.value)"
        style="width:100%;padding:8px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;outline:none;font-family:inherit;box-sizing:border-box;">
    </div>
    <div id="termSelectList" style="flex:1;overflow-y:auto;padding:8px 0;min-height:150px;"></div>
    <div id="newTermBox" style="display:none;padding:10px 20px;border-top:1px solid #f0f2f7;background:#f8faff;">
        <div style="display:flex;gap:8px;align-items:center;">
            <input id="newTermInput" type="text" placeholder="Type new term here..."
                style="flex:1;padding:8px 12px;border:1.5px solid #f97316;border-radius:8px;font-size:13px;outline:none;font-family:inherit;"
                onkeydown="if(event.key==='Enter')saveNewTermInline();">
            <button type="button" onclick="saveNewTermInline()"
                style="padding:8px 14px;background:#f97316;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
                <i class="fas fa-check"></i> Add
            </button>
            <button type="button" onclick="document.getElementById('newTermBox').style.display='none';"
                style="padding:8px 10px;background:#f5f5f5;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-size:13px;cursor:pointer;">✕</button>
        </div>
    </div>
    <div style="padding:12px 20px;border-top:1px solid #f0f2f7;display:flex;justify-content:space-between;align-items:center;background:#fafbfd;">
      <button type="button" onclick="openAddNewTerm()"
        style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#fff;border:1.5px solid #f97316;color:#f97316;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">
        <i class="fas fa-plus"></i> New Term
      </button>
      <div style="display:flex;gap:8px;">
        <button type="button" onclick="closeTermsPopup()"
          style="padding:8px 16px;background:#f5f5f5;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-size:13px;cursor:pointer;font-family:inherit;">Cancel</button>
        <button type="button" onclick="applyTermsFromPopup()"
          style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">
          <i class="fas fa-check"></i> Apply Selected
        </button>
      </div>
    </div>
  </div>
</div>

<div class="val-toast" id="valToast"></div>

<script>
let rowCount = <?= $isEdit ? count($editItems) : 0 ?>;
let allSuppliers = [];
let allItems = [];

function esc(s){if(!s)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function showToast(msg){const t=document.getElementById('valToast');t.textContent=msg;t.classList.add('show');clearTimeout(t._tid);t._tid=setTimeout(()=>t.classList.remove('show'),3000);}
function showMiniToast(msg){let t=document.getElementById('miniToast');if(!t){t=document.createElement('div');t.id='miniToast';t.style.cssText='position:fixed;bottom:24px;right:24px;background:#f97316;color:#fff;border-radius:8px;padding:10px 18px;font-size:13px;font-weight:700;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,.2);transition:opacity .3s';document.body.appendChild(t);}t.textContent=msg;t.style.opacity='1';clearTimeout(t._tid);t._tid=setTimeout(()=>t.style.opacity='0',2500);}

/* ── Modals ── */
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.addEventListener('click',e=>{
    document.querySelectorAll('.modal-overlay.open').forEach(m=>{if(e.target===m)m.classList.remove('open');});
    if(e.target===document.getElementById('spOverlay'))closeSupplierPopup();
});
document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){document.querySelectorAll('.modal-overlay.open').forEach(m=>m.classList.remove('open'));closeSupplierPopup();closeTermsPopup();}
});

/* ── Item Table ── */
function addItemRow(name,hsn,qty,unit,rate,cgst,sgst,igst,itemId){
    rowCount++;
    const idx = rowCount;
    name=name||''; hsn=hsn||''; qty=qty||1; unit=unit||''; rate=rate||0; cgst=cgst||0; sgst=sgst||0; igst=igst||0; itemId=itemId||0;
    const basic = Math.max(0,(qty*rate));
    const cgstAmt=basic*cgst/100, sgstAmt=basic*sgst/100, igstAmt=basic*igst/100;
    const total=basic+cgstAmt+sgstAmt+igstAmt;
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td style="text-align:center;color:#9ca3af;font-size:12px">${idx}</td>
        <td><input type="text" name="items[${idx}][description]" class="form-control" value="${esc(name)}" placeholder="Item description">
            <input type="hidden" name="item_ids[]" value="${itemId}" class="item-id-hidden"></td>
        <td><input type="text" name="items[${idx}][hsn_sac]" class="form-control" value="${esc(hsn)}"></td>
        <td><input type="number" name="items[${idx}][qty]" class="form-control qty" value="${qty}" min="0" step="0.001" oninput="recalcRow(this)"></td>
        <td><input type="text" name="items[${idx}][unit]" class="form-control" value="${esc(unit)}"></td>
        <td><input type="number" name="items[${idx}][rate]" class="form-control rate" value="${rate}" min="0" step="0.01" oninput="recalcRow(this)"></td>
        <td><input type="number" name="items[${idx}][discount]" class="form-control discount" value="0" min="0" step="0.01" oninput="recalcRow(this)"></td>
        <td class="taxable" style="text-align:right;font-weight:700">${basic.toFixed(2)}</td>
        <td><input type="number" name="items[${idx}][cgst_percent]" class="form-control cgst-rate" value="${cgst}" min="0" step="0.01" oninput="recalcRow(this)"></td>
        <td><input type="number" name="items[${idx}][sgst_percent]" class="form-control sgst-rate" value="${sgst}" min="0" step="0.01" oninput="recalcRow(this)"></td>
        <td><input type="number" name="items[${idx}][igst_percent]" class="form-control igst-rate" value="${igst}" min="0" step="0.01" oninput="recalcRow(this)"></td>
        <td class="amount" style="text-align:right;font-weight:700;color:#f97316">${total.toFixed(2)}</td>
        <td><button type="button" class="btn-danger-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
    `;
    document.getElementById('itemBody').appendChild(tr);
    renumberRows(); recalcFooter();
    if(!name) tr.querySelector('input').focus();
}
function addEmptyRow(){ addItemRow(); }
function removeRow(btn){ btn.closest('tr').remove(); renumberRows(); recalcFooter(); }
function renumberRows(){ document.querySelectorAll('#itemBody tr').forEach((r,i)=>r.cells[0].textContent=i+1); }
function recalcRow(inp){
    const tr       = inp.closest('tr');
    const qty      = parseFloat(tr.querySelector('.qty')?.value      || 0);
    const rate     = parseFloat(tr.querySelector('.rate')?.value     || 0);
    const discount = parseFloat(tr.querySelector('.discount')?.value || 0);
    const basic    = Math.max(0,(qty*rate)-discount);
    const cgst_pct = parseFloat(tr.querySelector('.cgst-rate')?.value || 0);
    const sgst_pct = parseFloat(tr.querySelector('.sgst-rate')?.value || 0);
    const igst_pct = parseFloat(tr.querySelector('.igst-rate')?.value || 0);
    const total    = basic+(basic*cgst_pct/100)+(basic*sgst_pct/100)+(basic*igst_pct/100);
    tr.querySelector('.taxable').textContent = basic.toFixed(2);
    tr.querySelector('.amount').textContent  = total.toFixed(2);
    recalcFooter();
}
function recalcFooter(){
    let totTaxable=0,totTotal=0;
    document.querySelectorAll('#itemBody tr').forEach(tr=>{
        totTaxable += parseFloat(tr.querySelector('.taxable')?.textContent || 0);
        totTotal   += parseFloat(tr.querySelector('.amount')?.textContent  || 0);
    });
    document.getElementById('footTaxable').textContent       = totTaxable.toFixed(2);
    document.getElementById('footTotal').textContent         = totTotal.toFixed(2);
    document.getElementById('grandTotalDisplay').textContent = '₹ '+totTotal.toLocaleString('en-IN',{minimumFractionDigits:2});
}

/* ── Supplier Popup ── */
function openSupplierPopup(){
    document.getElementById('spOverlay').classList.add('open');
    document.getElementById('spSearch').value='';
    document.getElementById('spList').innerHTML='<div class="sp-empty">Loading...</div>';
    fetch('supplier_invoice_create.php?get_suppliers=1')
        .then(r=>r.json())
        .then(data=>{allSuppliers=data;renderSuppliers(data);document.getElementById('spSearch').focus();})
        .catch(()=>{document.getElementById('spList').innerHTML='<div class="sp-empty">Error loading suppliers.</div>';});
}
function renderSuppliers(list){
    const el=document.getElementById('spList');
    if(!list.length){el.innerHTML='<div class="sp-empty">No suppliers found.<br><small>Click "Add New Supplier" below to add one.</small></div>';return;}
    el.innerHTML=list.map((s,idx)=>`
        <div class="sp-item" onclick="selectSupplier(${idx})">
            <div class="sp-item-name">${esc(s.supplier_name)}</div>
            <div class="sp-item-sub">${s.contact_person?esc(s.contact_person):''}${s.phone?' &nbsp;·&nbsp; 📞 '+esc(s.phone):''}${s.gstin?' &nbsp;·&nbsp; GST: '+esc(s.gstin):''}</div>
        </div>`).join('');
    renderSuppliers._cur=list;
}
function filterSuppliers(q){
    renderSuppliers(allSuppliers.filter(s=>
        (s.supplier_name||'').toLowerCase().includes(q.toLowerCase())||
        (s.contact_person||'').toLowerCase().includes(q.toLowerCase())||
        (s.gstin||'').toLowerCase().includes(q.toLowerCase())
    ));
}
function selectSupplier(idx){
    const list=renderSuppliers._cur||allSuppliers;
    const s=list[idx]; if(!s)return;
    document.getElementById('supplierInput').value=s.supplier_name||'';
    document.getElementById('contactInput').value=s.contact_person||'';
    document.getElementById('phoneInput').value=s.phone||'';
    document.getElementById('gstinInput').value=s.gstin||'';
    document.getElementById('addressInput').value=s.address||'';
    closeSupplierPopup();
}
function closeSupplierPopup(){document.getElementById('spOverlay').classList.remove('open');}
function openNewSupplierModal(){closeSupplierPopup();openModal('supplierModal');}

function saveNewSupplier(){
    const business=document.getElementById('ns_business').value.trim();
    if(!business){document.getElementById('ns_business').style.borderColor='#ef4444';showToast('⚠ Company name is required');return;}
    document.getElementById('ns_business').style.borderColor='';
    const fd=new FormData();
    fd.append('save_supplier_ajax','1');
    fd.append('supplier_name',business);
    fd.append('contact_person',document.getElementById('ns_contact').value.trim());
    fd.append('phone',document.getElementById('ns_mobile').value.trim());
    fd.append('email',document.getElementById('ns_email').value.trim());
    fd.append('address',document.getElementById('ns_address').value.trim());
    fd.append('gstin',document.getElementById('ns_gstin').value.trim());
    fd.append('pan',document.getElementById('ns_pan').value.trim());
    fetch('supplier_invoice_create.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(d=>{
            if(d.success){
                // Fill form fields
                document.getElementById('supplierInput').value=business;
                document.getElementById('contactInput').value=document.getElementById('ns_contact').value.trim();
                document.getElementById('phoneInput').value=document.getElementById('ns_mobile').value.trim();
                document.getElementById('gstinInput').value=document.getElementById('ns_gstin').value.trim();
                document.getElementById('addressInput').value=document.getElementById('ns_address').value.trim();
                // Clear modal
                ['ns_business','ns_contact','ns_mobile','ns_email','ns_address','ns_gstin','ns_pan'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='';});
                closeModal('supplierModal');
                showMiniToast('✓ Supplier saved & selected');
            } else {
                alert('Failed to save: '+(d.message||'Unknown error'));
            }
        }).catch(()=>alert('Network error'));
}

/* ── Item Library ── */
function openSelectItemModal(){
    // Always start on library view
    document.getElementById('itemLibView').style.display='flex';
    document.getElementById('addItemView').style.display='none';
    openModal('selectItemModal');
    document.getElementById('itemSearch').value='';
    document.getElementById('itemSelectList').innerHTML='<div style="padding:30px;text-align:center;color:#9ca3af;font-size:13px">Loading...</div>';
    fetch('supplier_invoice_create.php?get_items=1')
        .then(r=>r.json())
        .then(data=>{allItems=data;renderItemList(data);document.getElementById('itemSearch').focus();})
        .catch(()=>{document.getElementById('itemSelectList').innerHTML='<div style="padding:30px;text-align:center;color:#ef4444">Error loading items.</div>';});
}
function renderItemList(items){
    const el=document.getElementById('itemSelectList');
    if(!items.length){
        el.innerHTML='<div style="padding:30px;text-align:center;color:#9ca3af;font-size:13px">No items in library.<br><small>Add items to po_master_items first.</small></div>';
        return;
    }
    el.innerHTML=items.map((it,idx)=>{
        const name=it.item_name||it.name||'';
        const desc=it.description||it.item_description||'';
        const hsn=it.hsn_sac||it.hsn||'';
        const unit=it.unit||it.uom||'';
        const rate=parseFloat(it.rate||it.price||0);
        const cgst=parseFloat(it.cgst_pct||it.cgst||0);
        const sgst=parseFloat(it.sgst_pct||it.sgst||0);
        const igst=parseFloat(it.igst_pct||it.igst||0);
        const itemId=it.id||idx;
        return `<div class="item-select-row" onclick="addItemFromLibraryById(${itemId})">
            <div style="flex:1;min-width:0">
                <div class="item-select-name">${esc(name)}</div>
                <div class="item-select-sub">${unit?esc(unit):''}${hsn?' &nbsp;|&nbsp; HSN: '+esc(hsn):''}${rate?' &nbsp;|&nbsp; ₹ '+rate.toLocaleString('en-IN'):''}${cgst>0?' &nbsp;|&nbsp; CGST+SGST: '+(cgst+sgst)+'%':''}${igst>0?' &nbsp;|&nbsp; IGST: '+igst+'%':''}${desc?'<br><span style="color:#aaa;font-size:10px">'+esc(desc.substring(0,70))+(desc.length>70?'…':'')+'</span>':''}</div>
            </div>
        </div>`;
    }).join('');
}
function addItemFromLibraryById(id){
    const it=allItems.find(x=>x.id==id); if(!it)return;
    const name=it.item_name||it.name||'';
    const hsn=it.hsn_sac||it.hsn||'';
    const unit=it.unit||it.uom||'no.s';
    const rate=parseFloat(it.rate||it.price||0);
    const cgst=parseFloat(it.cgst_pct||it.cgst||0);
    const sgst=parseFloat(it.sgst_pct||it.sgst||0);
    const igst=parseFloat(it.igst_pct||it.igst||0);
    const itemId=parseInt(it.id||0);
    addItemRow(name,hsn,1,unit,rate,cgst,sgst,igst,itemId);
    showMiniToast('✓ '+name+' added');
}
function addItemFromLibrary(idx){const it=allItems[idx];if(!it)return;addItemFromLibraryById(it.id);}
function filterItems(q){
    renderItemList(allItems.filter(it=>
        (it.item_name||'').toLowerCase().includes(q.toLowerCase())||
        (it.description||'').toLowerCase().includes(q.toLowerCase())||
        (it.hsn_sac||'').toLowerCase().includes(q.toLowerCase())
    ));
}

/* ── Add New Item (inside Item Library modal) ── */
function openAddItemView(){
    document.getElementById('itemLibView').style.display='none';
    document.getElementById('addItemView').style.display='flex';
    // Clear form
    ['ni_name','ni_rate','ni_hsn','ni_desc','ni_cgst','ni_sgst','ni_igst'].forEach(id=>{
        const el=document.getElementById(id); if(el) el.value='';
    });
    document.getElementById('ni_unit').value='no.s';
    setTimeout(()=>document.getElementById('ni_name').focus(),50);
}
function closeAddItemView(){
    document.getElementById('addItemView').style.display='none';
    document.getElementById('itemLibView').style.display='flex';
}
function saveNewItem(){
    const name=document.getElementById('ni_name').value.trim();
    if(!name){
        document.getElementById('ni_name').style.borderColor='#ef4444';
        showToast('⚠ Item name is required');
        return;
    }
    document.getElementById('ni_name').style.borderColor='';
    const fd=new FormData();
    fd.append('save_new_item_ajax','1');
    fd.append('item_name', name);
    fd.append('rate',        document.getElementById('ni_rate').value||0);
    fd.append('unit',        document.getElementById('ni_unit').value);
    fd.append('hsn_sac',     document.getElementById('ni_hsn').value.trim());
    fd.append('description', document.getElementById('ni_desc').value.trim());
    fd.append('cgst_pct',    document.getElementById('ni_cgst').value||0);
    fd.append('sgst_pct',    document.getElementById('ni_sgst').value||0);
    fd.append('igst_pct',    document.getElementById('ni_igst').value||0);
    fetch('supplier_invoice_create.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(d=>{
            if(d.success){
                // Add the new item directly to invoice
                const rate=parseFloat(document.getElementById('ni_rate').value||0);
                const cgst=parseFloat(document.getElementById('ni_cgst').value||0);
                const sgst=parseFloat(document.getElementById('ni_sgst').value||0);
                const igst=parseFloat(document.getElementById('ni_igst').value||0);
                const unit=document.getElementById('ni_unit').value;
                const hsn=document.getElementById('ni_hsn').value.trim();
                addItemRow(name,hsn,1,unit,rate,cgst,sgst,igst,d.id);
                // Also push into allItems so it appears in the library list
                allItems.push({
                    id:d.id, item_name:name,
                    description:document.getElementById('ni_desc').value.trim(),
                    hsn_sac:hsn, unit:unit, rate:rate,
                    cgst_pct:cgst, sgst_pct:sgst, igst_pct:igst
                });
                showMiniToast('✓ '+name+' saved & added');
                closeAddItemView();
            } else {
                alert('Failed to save: '+(d.message||'Unknown error'));
            }
        }).catch(()=>alert('Network error'));
}

/* ── Terms System (ID-based, from po_master_terms) ── */
let masterTermsList = []; // [{id, term_text}, ...]

function loadAndOpenTermsPopup(){
    document.getElementById('termSearch').value = '';
    document.getElementById('termModalOverlay').classList.add('open');
    document.getElementById('termSelectList').innerHTML = '<div style="padding:20px;text-align:center;color:#9ca3af;">Loading...</div>';
    fetch('supplier_invoice_create.php?get_terms=1')
        .then(r=>r.json())
        .then(data=>{ masterTermsList=data; renderTermsPopup(); })
        .catch(()=>{ document.getElementById('termSelectList').innerHTML='<div style="padding:20px;text-align:center;color:#ef4444;">Error loading terms.</div>'; });
}
function openTermsPopup(){ loadAndOpenTermsPopup(); }
function closeTermsPopup(){document.getElementById('termModalOverlay').classList.remove('open');}

function renderTermsPopup(){
    const el = document.getElementById('termSelectList');
    const currentIds = new Set([...document.querySelectorAll('#termsList input.term-id-hidden')].map(i=>i.value));
    el.innerHTML = masterTermsList.map((t)=>{
        const checked = currentIds.has(String(t.id));
        return `<div style="display:flex;align-items:flex-start;gap:10px;padding:10px 20px;border-bottom:1px solid #f5f5f5;" onmouseover="this.style.background='#fff7f0'" onmouseout="this.style.background=''">
            <input type="checkbox" id="stc_${t.id}" ${checked?'checked':''} data-id="${t.id}" data-text="${esc(t.term_text)}" style="margin-top:3px;width:16px;height:16px;cursor:pointer;accent-color:#f97316;flex-shrink:0;">
            <label for="stc_${t.id}" style="flex:1;cursor:pointer;font-size:13px;line-height:1.6;color:#374151;">${esc(t.term_text)}</label>
        </div>`;
    }).join('');
}

function applyTermsFromPopup(){
    const checks = document.querySelectorAll('#termSelectList input[type=checkbox]');
    document.querySelectorAll('#termsList .term-row').forEach(r=>r.remove());
    checks.forEach(cb=>{ if(cb.checked) addTermById(cb.dataset.id, cb.dataset.text); });
    closeTermsPopup();
}

function addTermById(id, text){
    const existing = [...document.querySelectorAll('#termsList input.term-id-hidden')].map(i=>i.value);
    if(existing.includes(String(id))) return;
    const d = document.createElement('div');
    d.className = 'term-row';
    d.innerHTML = `<span>${esc(text)}</span>
        <div class="term-actions">
            <button type="button" class="term-btn term-btn-del" onclick="removeTerm(this)"><i class="fas fa-times"></i></button>
        </div>
        <input type="hidden" name="terms[]" value="${esc(text)}">
        <input type="hidden" name="terms_ids[]" value="${esc(id)}" class="term-id-hidden">`;
    document.getElementById('termsList').appendChild(d);
}

function removeTerm(btn){btn.closest('.term-row').remove();}

function openAddNewTerm(){
    const box=document.getElementById('newTermBox');
    box.style.display=box.style.display==='none'?'flex':'none';
    if(box.style.display==='flex'){document.getElementById('newTermInput').value='';document.getElementById('newTermInput').focus();}
}

function saveNewTermInline(){
    const inp = document.getElementById('newTermInput');
    const t = inp.value.trim();
    if(!t) return;
    const fd = new FormData();
    fd.append('save_new_term_ajax','1');
    fd.append('term_text', t);
    fetch('supplier_invoice_create.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(d=>{
            if(d.success){
                masterTermsList.push({id:d.id, term_text:d.term_text});
                renderTermsPopup();
                setTimeout(()=>{
                    const cb = document.getElementById('stc_'+d.id);
                    if(cb) cb.checked = true;
                },50);
                inp.value='';
                document.getElementById('newTermBox').style.display='none';
                showMiniToast('✓ Term saved');
            } else { alert('Failed: '+(d.message||'Error')); }
        }).catch(()=>alert('Network error'));
}

function filterTerms(q){
    const val=q.toLowerCase();
    document.querySelectorAll('#termSelectList > div').forEach(row=>{
        const label=row.querySelector('label');
        if(label) row.style.display=label.textContent.toLowerCase().includes(val)?'':'none';
    });
}

document.addEventListener('DOMContentLoaded', function(){
    const isEdit = <?= $isEdit ? 'true' : 'false' ?>;
    const editTermIds = <?= isset($editTermIds) ? json_encode($editTermIds) : '[]' ?>;
    // For edit mode: load terms from DB and restore selected ones
    if(isEdit && editTermIds.length > 0){
        fetch('supplier_invoice_create.php?get_terms=1')
            .then(r=>r.json())
            .then(data=>{
                masterTermsList=data;
                editTermIds.forEach(id=>{
                    const found = data.find(t=>t.id==id);
                    if(found) addTermById(found.id, found.term_text);
                });
            });
    } else if(!isEdit){
        // Auto-add first 3 terms for new invoice
        fetch('supplier_invoice_create.php?get_terms=1')
            .then(r=>r.json())
            .then(data=>{
                masterTermsList=data;
                data.slice(0,3).forEach(t=>addTermById(t.id, t.term_text));
            });
    }
    recalcFooter();
});
document.getElementById('termModalOverlay').addEventListener('click',function(e){if(e.target===this)closeTermsPopup();});
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

        const res  = await fetch('supplier_invoice_create.php', { method: 'POST', body: fd });
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
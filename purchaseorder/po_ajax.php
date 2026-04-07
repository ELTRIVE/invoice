<?php
/* =============================================================
   po_ajax.php  —  All POST / AJAX handlers for Purchase Order
   Included at the top of createpurchase.php before any HTML.
   ============================================================= */

require_once dirname(__DIR__) . '/db.php';

/* ── Schema migrations ───────────────────────────────────────── */

$master_cols = [
    'cgst_pct DECIMAL(5,2) DEFAULT 0.00',
    'sgst_pct DECIMAL(5,2) DEFAULT 0.00',
    'igst_pct DECIMAL(5,2) DEFAULT 0.00',
    'is_active TINYINT(1) DEFAULT 1',
    'description TEXT',
    'hsn_sac VARCHAR(50)',
    'unit VARCHAR(50) DEFAULT \'no.s\''
];
foreach ($master_cols as $col) {
    try { $pdo->exec("ALTER TABLE po_master_items ADD COLUMN $col"); } catch(Exception $e){}
}

foreach ([
    'billing_gstin VARCHAR(100) DEFAULT \'\'',
    'billing_phone VARCHAR(50) DEFAULT \'\''
] as $col) {
    try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN $col"); } catch(Exception $e){}
}

$pdo->exec("CREATE TABLE IF NOT EXISTS po_master_terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term_text TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

foreach ([
    'email VARCHAR(255) DEFAULT \'\'',
    'address TEXT',
    'gstin VARCHAR(100) DEFAULT \'\'',
    'pan VARCHAR(50) DEFAULT \'\'',
    'website VARCHAR(255) DEFAULT \'\''
] as $col) {
    try { $pdo->exec("ALTER TABLE suppliers ADD COLUMN $col"); } catch(Exception $e){}
}

/* ── AJAX: Get suppliers ─────────────────────────────────────── */

if (isset($_GET['get_suppliers'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT supplier_name,contact_person,phone,email,address,gstin FROM suppliers ORDER BY supplier_name ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

/* ── AJAX: Get items ─────────────────────────────────────────── */

if (isset($_GET['get_items'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT * FROM po_master_items WHERE COALESCE(is_active,1)=1 ORDER BY item_name ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) {
        $stmt = $pdo->query("SELECT * FROM po_master_items ORDER BY item_name ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    exit;
}

/* ── AJAX: Get terms ─────────────────────────────────────────── */

if (isset($_GET['get_terms'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT term_text FROM po_master_terms WHERE is_active=1 ORDER BY id ASC");
        echo json_encode(array_column($stmt->fetchAll(PDO::FETCH_ASSOC),'term_text'));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

/* ── AJAX: Save master item ──────────────────────────────────── */

if (isset($_POST['save_master_item'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("INSERT INTO po_master_items
            (item_type, item_name, description, hsn_sac, unit, rate, cgst_pct, sgst_pct, igst_pct)
            VALUES (:type,:name,:desc,:hsn,:unit,:rate,:cgst,:sgst,:igst)");
        $stmt->execute([
            ':type' => $_POST['item_type']   ?? '',
            ':name' => $_POST['item_name']   ?? '',
            ':desc' => $_POST['description'] ?? '',
            ':hsn'  => $_POST['hsn_sac']     ?? '',
            ':unit' => $_POST['unit']        ?? '',
            ':rate' => $_POST['rate']        ?? 0,
            ':cgst' => $_POST['cgst_pct']    ?? 0,
            ':sgst' => $_POST['sgst_pct']    ?? 0,
            ':igst' => $_POST['igst_pct']    ?? 0,
        ]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ── POST: Save supplier (AJAX) ──────────────────────────────── */

if (isset($_POST['save_supplier_ajax'])) {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->prepare("INSERT INTO suppliers
            (supplier_name, contact_person, phone, email, address, gstin, pan, website)
            VALUES (:name,:person,:phone,:email,:address,:gstin,:pan,:website)");
        $stmt->execute([
            ':name'    => $_POST['supplier_name']  ?? '',
            ':person'  => $_POST['contact_person'] ?? '',
            ':phone'   => $_POST['phone']          ?? '',
            ':email'   => $_POST['email']          ?? '',
            ':address' => $_POST['address']        ?? '',
            ':gstin'   => $_POST['gstin']          ?? '',
            ':pan'     => $_POST['pan']            ?? '',
            ':website' => $_POST['website']        ?? ''
        ]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// NOTE: Full PO save (with items, totals, terms, billing/shipping) is handled by savepurchase.php
// Do NOT add a generic POST handler here — it would intercept the form submission before savepurchase.php runs.
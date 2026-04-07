<?php
require_once dirname(__DIR__) . '/invoice/db.php';

$action  = $_POST['action']  ?? 'save';
$edit_id = (int)($_POST['edit_id'] ?? 0);

$supplier_name    = trim($_POST['supplier_name']    ?? '');
$contact_person   = trim($_POST['contact_person']   ?? '');
$billing_address   = trim($_POST['billing_address']   ?? '');

$po_number        = trim($_POST['po_number']        ?? '');
$reference        = trim($_POST['reference']        ?? '');
$po_date          = $_POST['po_date']  ?? date('Y-m-d');
$due_date         = $_POST['due_date'] ?? date('Y-m-d');
$notes            = trim($_POST['notes'] ?? '');
$created_by       = 'Gayatri Geeta Gopisetty';

// Ensure po_master_terms table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS po_master_terms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    term_text TEXT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// Ensure items_json column exists in purchase_orders (DDL outside transaction)
try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN items_json LONGTEXT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN item_list LONGTEXT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE purchase_orders ADD COLUMN terms_list LONGTEXT NULL"); } catch(Exception $e){}

// ── Totals ────────────────────────────────────────────────────────────────────
$raw_items     = $_POST['items'] ?? [];
$grand_total   = 0;
$total_taxable = 0;
$total_cgst    = 0;
$total_sgst    = 0;
$total_igst    = 0;
foreach ($raw_items as $it) {
    $total_taxable += (float)($it['taxable']  ?? 0);
    $total_cgst    += (float)($it['cgst_amt'] ?? 0);
    $total_sgst    += (float)($it['sgst_amt'] ?? 0);
    $total_igst    += (float)($it['igst_amt'] ?? 0);
    $grand_total   += (float)($it['amount']   ?? 0);
}

$terms = array_values(array_filter(array_map('trim', $_POST['terms'] ?? [])));

// Save any new terms to po_master_terms and collect their IDs
$terms_ids = [];
foreach ($terms as $t) {
    if ($t) {
        $chk = $pdo->prepare("SELECT id FROM po_master_terms WHERE term_text=?");
        $chk->execute([$t]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $terms_ids[] = (int)$existing['id'];
        } else {
            $pdo->prepare("INSERT INTO po_master_terms (term_text) VALUES (?)")->execute([$t]);
            $terms_ids[] = (int)$pdo->lastInsertId();
        }
    }
}
$terms_list_json = json_encode(array_values(array_unique($terms_ids)));

// Build items JSON
$items_array = [];
foreach ($raw_items as $it) {
    $qty     = (float)($it['qty']      ?? 0);
    $rate    = (float)($it['rate']     ?? 0);
    $disc    = (float)($it['discount'] ?? 0);
    $taxable = (float)($it['taxable']  ?? ($qty * $rate) - $disc);
    $cgst_p  = (float)($it['cgst_pct'] ?? 0);
    $sgst_p  = (float)($it['sgst_pct'] ?? 0);
    $igst_p  = (float)($it['igst_pct'] ?? 0);
    $cgst_a  = (float)($it['cgst_amt'] ?? $taxable * $cgst_p / 100);
    $sgst_a  = (float)($it['sgst_amt'] ?? $taxable * $sgst_p / 100);
    $igst_a  = (float)($it['igst_amt'] ?? $taxable * $igst_p / 100);
    $amt     = (float)($it['amount']   ?? $taxable + $cgst_a + $sgst_a + $igst_a);
    $items_array[] = [
        'item_id'     => (int)($it['item_id']    ?? 0),
        'item_name'   => trim($it['item_name']   ?? ''),
        'description' => trim($it['description'] ?? ''),
        'hsn_sac'     => trim($it['hsn_sac']     ?? ''),
        'qty'         => $qty,
        'unit'        => trim($it['unit']        ?? ''),
        'rate'        => $rate,
        'discount'    => $disc,
        'taxable'     => $taxable,
        'cgst_pct'    => $cgst_p, 'cgst_amt' => $cgst_a,
        'sgst_pct'    => $sgst_p, 'sgst_amt' => $sgst_a,
        'igst_pct'    => $igst_p, 'igst_amt' => $igst_a,
        'amount'      => $amt,
    ];
}
$items_json = json_encode($items_array, JSON_UNESCAPED_UNICODE);

// Build item_list — array of master item IDs (non-zero only)
$item_list_ids = [];
foreach ($items_array as $itm) {
    $iid = (int)($itm['item_id'] ?? 0);
    if ($iid > 0) $item_list_ids[] = $iid;
}
$item_list_ids   = array_values($item_list_ids); // duplicates kept intentionally
$item_list_json  = json_encode($item_list_ids);

try {
    $pdo->beginTransaction();

    if ($edit_id) {
        $pdo->prepare("
            UPDATE purchase_orders SET
                supplier_name=:sn, contact_person=:cp, billing_address=:sa,
                reference=:ref, po_date=:pd, due_date=:dd,
                notes=:notes, total_taxable=:tt, total_cgst=:tc, total_sgst=:ts,
                total_igst=:ti, grand_total=:gt, items_json=:ij, item_list=:il, terms_list=:tl
            WHERE id=:id
        ")->execute([
            ':sn'=>$supplier_name,  ':cp'=>$contact_person,
            ':sa'=>$billing_address, 
            ':ref'=>$reference,     ':pd'=>$po_date, ':dd'=>$due_date,
            ':notes'=>$notes,       ':tt'=>$total_taxable,
            ':tc'=>$total_cgst,     ':ts'=>$total_sgst,
            ':ti'=>$total_igst,     ':gt'=>$grand_total,
            ':ij'=>$items_json, ':il'=>$item_list_json, ':tl'=>$terms_list_json, ':id'=>$edit_id,
        ]);
        $po_id = $edit_id;
        // po_terms no longer used — terms stored in purchase_orders.terms_list

    } else {
        $pdo->prepare("
            INSERT INTO purchase_orders
                (po_number,supplier_name,contact_person,billing_address,
                 reference,po_date,due_date,notes,
                 total_taxable,total_cgst,total_sgst,total_igst,grand_total,
                 created_by,items_json,item_list,terms_list)
            VALUES
                (:pn,:sn,:cp,:sa,:ref,:pd,:dd,:notes,:tt,:tc,:ts,:ti,:gt,:cb,:ij,:il,:tl)
            ON DUPLICATE KEY UPDATE id=id
        ")->execute([
            ':pn'=>$po_number,        ':sn'=>$supplier_name,
            ':cp'=>$contact_person,   ':sa'=>$billing_address,
           ':ref'=>$reference,
            ':pd'=>$po_date,          ':dd'=>$due_date,
            ':notes'=>$notes,         ':tt'=>$total_taxable,
            ':tc'=>$total_cgst,       ':ts'=>$total_sgst,
            ':ti'=>$total_igst,       ':gt'=>$grand_total,
            ':cb'=>$created_by,       ':ij'=>$items_json,  ':il'=>$item_list_json, ':tl'=>$terms_list_json,
        ]);
        $po_id = $pdo->lastInsertId();
        if (!$po_id) {
            $s = $pdo->prepare("SELECT id FROM purchase_orders WHERE po_number=?");
            $s->execute([$po_number]);
            $po_id = (int)$s->fetchColumn();
            // po_terms no longer used — terms stored in purchase_orders.terms_list
        }
    }

    // Terms stored in purchase_orders.terms_list — no insert into po_terms needed

    $pdo->commit();
    header($action === 'save_another' ? 'Location: createpurchase.php' : 'Location: pindex.php?saved=1');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die('Error: ' . htmlspecialchars($e->getMessage()));
}
<?php
ob_start();
require_once dirname(__DIR__) . '/db.php';

function ensureCustomerInvoiceAddressColumns(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $columns = [
        "billing_gstin VARCHAR(20) DEFAULT ''",
        "billing_pan VARCHAR(20) DEFAULT ''",
        "billing_phone VARCHAR(20) DEFAULT ''",
        "ship_address_line1 VARCHAR(255) DEFAULT ''",
        "ship_address_line2 VARCHAR(255) DEFAULT ''",
        "ship_city VARCHAR(100) DEFAULT ''",
        "ship_state VARCHAR(100) DEFAULT ''",
        "ship_pincode VARCHAR(20) DEFAULT ''",
        "ship_country VARCHAR(100) DEFAULT ''",
        "shipping_gstin VARCHAR(20) DEFAULT ''",
        "shipping_pan VARCHAR(20) DEFAULT ''",
        "shipping_phone VARCHAR(20) DEFAULT ''"
    ];

    foreach ($columns as $definition) {
        try {
            $pdo->exec("ALTER TABLE customers ADD COLUMN $definition");
        } catch (Exception $e) {
        }
    }

    $ensured = true;
}

function buildAddressText(array $parts): string {
    $clean = [];
    foreach ($parts as $part) {
        $value = trim((string)$part);
        if ($value !== '') {
            $clean[] = $value;
        }
    }
    return implode("\n", $clean);
}

ensureCustomerInvoiceAddressColumns($pdo);

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

function document_number_exists(PDO $pdo, string $table, string $column, string $value, ?int $excludeId = null): bool {
    $sql = "SELECT id FROM `$table` WHERE `$column` = ?";
    $params = [$value];
    if ($excludeId) {
        $sql .= " AND id <> ?";
        $params[] = $excludeId;
    }
    $sql .= " LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
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


// ── HANDLE POST (save) ───────────────────────────────────────────────────────
if (!isset($error)) $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['save_master_item'])) {
    $action       = $_POST['action']         ?? 'save';
    $post_edit_id = (int)($_POST['edit_id']  ?? 0);

    $customer_name    = trim($_POST['customer_name']    ?? '');
    $contact_person   = trim($_POST['contact_person']   ?? '');
    $billing_details  = trim($_POST['billing_details']  ?? '');
    $customer_gstin   = trim($_POST['customer_gstin']   ?? '');
    $customer_phone   = trim($_POST['customer_phone']   ?? '');
    $shipping_details = trim($_POST['shipping_details'] ?? '');
    $billing_gstin    = strtoupper(trim($_POST['billing_gstin']  ?? ''));
    $billing_pan      = strtoupper(trim($_POST['billing_pan']    ?? ''));
    $billing_phone    = trim($_POST['billing_phone']    ?? '');
    $shipping_gstin   = strtoupper(trim($_POST['shipping_gstin'] ?? ''));
    $shipping_pan     = strtoupper(trim($_POST['shipping_pan']   ?? ''));
    $shipping_phone   = trim($_POST['shipping_phone']   ?? '');
    $quot_number      = trim($_POST['quot_number']      ?? '');
    $reference        = trim($_POST['reference']        ?? '');
    $quot_date        = $_POST['quot_date']  ?? date('Y-m-d');
    $valid_till       = $_POST['valid_till'] ?? date('Y-m-d', strtotime('+30 days'));
    $notes            = trim($_POST['notes'] ?? '');
    $bank_id          = !empty($_POST['bank_id']) ? (int)$_POST['bank_id'] : null;
    $signature_id     = !empty($_POST['signature_id']) ? (int)$_POST['signature_id'] : null;
    $created_by       = 'Gayatri Geeta Gopisetty';

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

    $status = ($action === 'save') ? 'Sent' : 'Draft';

    $raw_items     = $_POST['items'] ?? [];
    $grand_total   = 0; $total_taxable = 0;
    $total_cgst    = 0; $total_sgst    = 0; $total_igst = 0;
    foreach ($raw_items as $it) {
        $total_taxable += (float)($it['taxable']  ?? 0);
        $total_cgst    += (float)($it['cgst_amt'] ?? 0);
        $total_sgst    += (float)($it['sgst_amt'] ?? 0);
        $total_igst    += (float)($it['igst_amt'] ?? 0);
        $grand_total   += (float)($it['amount']   ?? 0);
    }

    $terms = array_values(array_filter(array_map('trim', $_POST['terms'] ?? [])));
    $terms_ids = [];
    foreach ($terms as $t) {
        if ($t) {
            $chk = $pdo->prepare("SELECT id FROM po_master_terms WHERE term_text=?");
            $chk->execute([$t]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);
            if ($existing) { $terms_ids[] = (int)$existing['id']; }
            else { $pdo->prepare("INSERT INTO po_master_terms (term_text) VALUES (?)")->execute([$t]); $terms_ids[] = (int)$pdo->lastInsertId(); }
        }
    }
    $terms_list_json = json_encode(array_values(array_unique($terms_ids)));

    $items_array = [];
    foreach ($raw_items as $it) {
        $qty=$it['qty']??0; $rate=$it['rate']??0; $disc=$it['discount']??0;
        $taxable=(float)($it['taxable']??($qty*$rate)-$disc);
        $cgst_p=(float)($it['cgst_pct']??0); $sgst_p=(float)($it['sgst_pct']??0); $igst_p=(float)($it['igst_pct']??0);
        $cgst_a=(float)($it['cgst_amt']??$taxable*$cgst_p/100);
        $sgst_a=(float)($it['sgst_amt']??$taxable*$sgst_p/100);
        $igst_a=(float)($it['igst_amt']??$taxable*$igst_p/100);
        $amt=(float)($it['amount']??$taxable+$cgst_a+$sgst_a+$igst_a);
        $items_array[]=['item_id'=>(int)($it['item_id']??0),'item_name'=>trim($it['item_name']??''),'description'=>trim($it['description']??''),'hsn_sac'=>trim($it['hsn_sac']??''),'qty'=>(float)$qty,'unit'=>trim($it['unit']??''),'rate'=>(float)$rate,'discount'=>(float)$disc,'taxable'=>$taxable,'cgst_pct'=>$cgst_p,'cgst_amt'=>$cgst_a,'sgst_pct'=>$sgst_p,'sgst_amt'=>$sgst_a,'igst_pct'=>$igst_p,'igst_amt'=>$igst_a,'amount'=>$amt];
    }
    $items_json = json_encode($items_array, JSON_UNESCAPED_UNICODE);
    $item_list_ids = array_filter(array_map(fn($i)=>(int)($i['item_id']??0), $items_array));
    $item_list_json = json_encode(array_values($item_list_ids));

    try {
        if ($post_edit_id) {
            if (document_number_exists($pdo, 'quotations', 'quot_number', $quot_number, $post_edit_id)) {
                throw new Exception("Quotation number '{$quot_number}' already exists. Please use a different quotation number.");
            }
        } else {
            if (document_number_exists($pdo, 'quotations', 'quot_number', $quot_number)) {
                if (preg_match('/^ELT\-QT\-\d{7}$/', $quot_number)) {
                    [$quot_number] = get_next_prefixed_number($pdo, 'quotations', 'quot_number', 'ELT-QT-', 7, 2526001);
                } else {
                    throw new Exception("Quotation number '{$quot_number}' already exists. Please use a different quotation number.");
                }
            }
        }
        $pdo->beginTransaction();
        if ($post_edit_id) {
            $pdo->prepare("UPDATE quotations SET quot_number=:qn,customer_name=:cn,contact_person=:cp,billing_details=:ca,customer_gstin=:cg,customer_phone=:cph,shipping_details=:sd,billing_gstin=:bg,billing_pan=:bpan,billing_phone=:bp,shipping_gstin=:sg,shipping_pan=:span,shipping_phone=:sph,reference=:ref,quot_date=:qd,valid_till=:vt,notes=:notes,bank_id=:bid,signature_id=:sid,status=:st,total_taxable=:tt,total_cgst=:tc,total_sgst=:ts,total_igst=:ti,grand_total=:gt,items_json=:ij,item_list=:il,terms_list=:tl, company_override=:co WHERE id=:id")
                ->execute([':qn'=>$quot_number,':cn'=>$customer_name,':cp'=>$contact_person,':ca'=>$billing_details,':cg'=>$customer_gstin,':cph'=>$customer_phone,':sd'=>$shipping_details,':bg'=>$billing_gstin,':bpan'=>$billing_pan,':bp'=>$billing_phone,':sg'=>$shipping_gstin,':span'=>$shipping_pan,':sph'=>$shipping_phone,':ref'=>$reference,':qd'=>$quot_date,':vt'=>$valid_till,':notes'=>$notes,':bid'=>$bank_id,':sid'=>$signature_id,':st'=>$status,':tt'=>$total_taxable,':tc'=>$total_cgst,':ts'=>$total_sgst,':ti'=>$total_igst,':gt'=>$grand_total,':ij'=>$items_json,':il'=>$item_list_json,':tl'=>$terms_list_json,':co'=>$company_override,':id'=>$post_edit_id]);
        } else {
            $pdo->prepare("INSERT INTO quotations (quot_number,customer_name,contact_person,billing_details,customer_gstin,customer_phone,shipping_details,billing_gstin,billing_pan,billing_phone,shipping_gstin,shipping_pan,shipping_phone,reference,quot_date,valid_till,notes,bank_id,signature_id,status,total_taxable,total_cgst,total_sgst,total_igst,grand_total,items_json,item_list,terms_list,created_by, company_override) VALUES (:qn,:cn,:cp,:ca,:cg,:cph,:sd,:bg,:bpan,:bp,:sg,:span,:sph,:ref,:qd,:vt,:notes,:bid,:sid,:st,:tt,:tc,:ts,:ti,:gt,:ij,:il,:tl,:cb,:co) ON DUPLICATE KEY UPDATE id=id")
                ->execute([':qn'=>$quot_number,':cn'=>$customer_name,':cp'=>$contact_person,':ca'=>$billing_details,':cg'=>$customer_gstin,':cph'=>$customer_phone,':sd'=>$shipping_details,':bg'=>$billing_gstin,':bpan'=>$billing_pan,':bp'=>$billing_phone,':sg'=>$shipping_gstin,':span'=>$shipping_pan,':sph'=>$shipping_phone,':ref'=>$reference,':qd'=>$quot_date,':vt'=>$valid_till,':notes'=>$notes,':bid'=>$bank_id,':sid'=>$signature_id,':st'=>$status,':tt'=>$total_taxable,':tc'=>$total_cgst,':ts'=>$total_sgst,':ti'=>$total_igst,':gt'=>$grand_total,':ij'=>$items_json,':il'=>$item_list_json,':tl'=>$terms_list_json,':cb'=>$created_by,':co'=>$company_override]);
            $quot_id = $pdo->lastInsertId();
            if (!$quot_id) { $s=$pdo->prepare("SELECT id FROM quotations WHERE quot_number=?"); $s->execute([$quot_number]); $quot_id=(int)$s->fetchColumn(); }
        }
        $pdo->commit();
        header('Location: quote_index.php?saved=1');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $e->getMessage();
        if (strpos($msg, 'SQLSTATE[23000]') !== false || stripos($msg, 'Duplicate entry') !== false) {
            $msg = "Quotation number '{$quot_number}' already exists. Please use a different quotation number.";
        }
        $error = $msg;
    }
}

// Ensure tables exist
$pdo->exec("CREATE TABLE IF NOT EXISTS quotations (id INT AUTO_INCREMENT PRIMARY KEY,quot_number VARCHAR(50) NOT NULL UNIQUE,customer_name VARCHAR(255) NOT NULL DEFAULT '',contact_person VARCHAR(255) DEFAULT '',billing_details TEXT,customer_gstin VARCHAR(100) DEFAULT '',customer_phone VARCHAR(50) DEFAULT '',reference VARCHAR(255) DEFAULT '',quot_date DATE NOT NULL,valid_till DATE NOT NULL,notes TEXT,shipping_details TEXT,billing_gstin VARCHAR(20) DEFAULT '',billing_pan VARCHAR(20) DEFAULT '',billing_phone VARCHAR(50) DEFAULT '',shipping_gstin VARCHAR(20) DEFAULT '',shipping_pan VARCHAR(20) DEFAULT '',shipping_phone VARCHAR(50) DEFAULT '',signature_id INT DEFAULT NULL,status ENUM('Draft','Sent','Approved','Rejected') DEFAULT 'Draft',total_taxable DECIMAL(15,2) DEFAULT 0.00,total_cgst DECIMAL(15,2) DEFAULT 0.00,total_sgst DECIMAL(15,2) DEFAULT 0.00,total_igst DECIMAL(15,2) DEFAULT 0.00,grand_total DECIMAL(15,2) DEFAULT 0.00,items_json LONGTEXT NULL,item_list LONGTEXT NULL,terms_list LONGTEXT NULL,created_by VARCHAR(255) DEFAULT '',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
try { $pdo->exec("ALTER TABLE quotations ADD COLUMN billing_pan VARCHAR(20) DEFAULT ''"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE quotations ADD COLUMN shipping_pan VARCHAR(20) DEFAULT ''"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE quotations ADD COLUMN company_override TEXT DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE quotations ADD COLUMN signature_id INT DEFAULT NULL"); } catch(Exception $e){}
$pdo->exec("CREATE TABLE IF NOT EXISTS po_master_terms (id INT AUTO_INCREMENT PRIMARY KEY,term_text TEXT NOT NULL,is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

// AJAX endpoints
if (isset($_GET['get_items'])) {
    header('Content-Type: application/json');
    try {
        $rows = $pdo->query("SELECT id, service_code, COALESCE(NULLIF(item_name,''), material_description) AS item_name, material_description AS description, hsn_sac, uom AS unit, unit_price AS rate, 0 AS cgst_pct, 0 AS sgst_pct, 0 AS igst_pct FROM items ORDER BY service_code ASC")->fetchAll(PDO::FETCH_ASSOC);

        // Backfill missing master item HSN/rate from historical quotation items_json
        // so older items still show meaningful values in the Add Item popup.
        $normKey = static function(string $s): string {
            $s = strtolower(trim($s));
            return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
        };
        $histMap = [];
        $histById = [];
        $qRows = $pdo->query("SELECT items_json FROM quotations WHERE items_json IS NOT NULL AND items_json <> ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($qRows as $qRow) {
            $arr = json_decode($qRow['items_json'] ?? '[]', true);
            if (!is_array($arr)) continue;
            foreach ($arr as $it) {
                $name = trim((string)($it['item_name'] ?? ''));
                $desc = trim((string)($it['description'] ?? ''));
                $itemId = (int)($it['item_id'] ?? 0);
                $keys = array_values(array_filter([$normKey($name), $normKey($desc)]));

                $hsn = trim((string)($it['hsn_sac'] ?? ($it['hsn'] ?? ($it['hsn_code'] ?? ''))));
                $qty = (float)($it['qty'] ?? 0);
                $rate = (float)($it['rate'] ?? ($it['unit_price'] ?? 0));
                if ($rate <= 0) {
                    $amt = (float)($it['amount'] ?? ($it['taxable'] ?? 0));
                    if ($qty > 0 && $amt > 0) $rate = $amt / $qty;
                }

                if ($itemId > 0) {
                    if (!isset($histById[$itemId])) $histById[$itemId] = ['hsn_sac' => '', 'rate' => 0.0];
                    if ($histById[$itemId]['hsn_sac'] === '' && $hsn !== '' && $hsn !== '0') $histById[$itemId]['hsn_sac'] = $hsn;
                    if ($histById[$itemId]['rate'] <= 0 && $rate > 0) $histById[$itemId]['rate'] = $rate;
                }

                if (!$keys) continue;
                foreach ($keys as $k) {
                    if (!isset($histMap[$k])) $histMap[$k] = ['hsn_sac' => '', 'rate' => 0.0];
                    if ($histMap[$k]['hsn_sac'] === '' && $hsn !== '' && $hsn !== '0') $histMap[$k]['hsn_sac'] = $hsn;
                    if ($histMap[$k]['rate'] <= 0 && $rate > 0) $histMap[$k]['rate'] = $rate;
                }
            }
        }

        foreach ($rows as &$r) {
            $rowId = (int)($r['id'] ?? 0);
            $idHsn = '';
            $idRate = 0.0;
            if ($rowId > 0 && isset($histById[$rowId])) {
                $idHsn = (string)$histById[$rowId]['hsn_sac'];
                $idRate = (float)$histById[$rowId]['rate'];
            }
            $k1 = $normKey((string)($r['item_name'] ?? ''));
            $k2 = $normKey((string)($r['description'] ?? ''));
            $pick = '';
            if ($k1 !== '' && isset($histMap[$k1])) $pick = $k1;
            elseif ($k2 !== '' && isset($histMap[$k2])) $pick = $k2;

            $curHsn = trim((string)($r['hsn_sac'] ?? ''));
            $curRate = (float)($r['rate'] ?? 0);
            if ($curHsn === '' || $curHsn === '0') {
                if ($idHsn !== '') $r['hsn_sac'] = $idHsn;
                elseif ($pick !== '' && $histMap[$pick]['hsn_sac'] !== '') $r['hsn_sac'] = $histMap[$pick]['hsn_sac'];
            }
            if ($curRate <= 0) {
                if ($idRate > 0) $r['rate'] = $idRate;
                elseif ($pick !== '' && $histMap[$pick]['rate'] > 0) $r['rate'] = $histMap[$pick]['rate'];
            }
        }
        unset($r);

        // Include history-only items (present in quotation edit via items_json,
        // but missing in master `items` table) so popup search can still show them.
        $existingKeys = [];
        foreach ($rows as $r) {
            $k1 = $normKey((string)($r['item_name'] ?? ''));
            $k2 = $normKey((string)($r['description'] ?? ''));
            if ($k1 !== '') $existingKeys[$k1] = true;
            if ($k2 !== '') $existingKeys[$k2] = true;
        }

        $histId = -1;
        foreach ($qRows as $qRow) {
            $arr = json_decode($qRow['items_json'] ?? '[]', true);
            if (!is_array($arr)) continue;
            foreach ($arr as $it) {
                $name = trim((string)($it['item_name'] ?? ''));
                $desc = trim((string)($it['description'] ?? ''));
                $k1 = $normKey($name);
                $k2 = $normKey($desc);
                $matchKey = $k1 !== '' ? $k1 : $k2;
                if ($matchKey === '' || isset($existingKeys[$matchKey])) continue;

                $hsn  = trim((string)($it['hsn_sac'] ?? ($it['hsn'] ?? ($it['hsn_code'] ?? ''))));
                $unit = trim((string)($it['unit'] ?? 'no.s'));
                $qty  = (float)($it['qty'] ?? 0);
                $rate = (float)($it['rate'] ?? ($it['unit_price'] ?? 0));
                if ($rate <= 0) {
                    $amt = (float)($it['amount'] ?? ($it['taxable'] ?? 0));
                    if ($qty > 0 && $amt > 0) $rate = $amt / $qty;
                }

                $rows[] = [
                    'id' => $histId--,
                    'service_code' => '',
                    'item_name' => $name !== '' ? $name : $desc,
                    'description' => $desc,
                    'hsn_sac' => $hsn,
                    'unit' => $unit !== '' ? $unit : 'no.s',
                    'rate' => $rate > 0 ? $rate : 0,
                    'cgst_pct' => 0,
                    'sgst_pct' => 0,
                    'igst_pct' => 0,
                ];

                $existingKeys[$matchKey] = true;
                if ($k2 !== '') $existingKeys[$k2] = true;
            }
        }

        ob_clean(); echo json_encode($rows);
    } catch (Exception $e) { ob_clean(); echo json_encode([]); }
    exit;
}
if (isset($_GET['get_customers'])) {
    header('Content-Type: application/json');
    try {
        $per_page = 10;
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $search   = trim($_GET['search'] ?? '');
        $offset   = ($page - 1) * $per_page;
        $where    = '';
        $params   = [];
        if ($search !== '') {
            $where  = "WHERE business_name LIKE ? OR mobile LIKE ?";
            $params = ["%$search%", "%$search%"];
        }
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM customers $where");
        $countStmt->execute($params);
        $total       = (int)$countStmt->fetchColumn();
        $total_pages = max(1, (int)ceil($total / $per_page));
        $dataStmt = $pdo->prepare("SELECT id, business_name, title, first_name, last_name, mobile, gstin, pan_no,
            address_line1, address_line2, address_city, address_state, pincode, address_country,
            billing_gstin, billing_pan, billing_phone,
            ship_address_line1, ship_address_line2, ship_city, ship_state, ship_pincode, ship_country,
            shipping_gstin, shipping_pan, shipping_phone
            FROM customers $where ORDER BY business_name ASC LIMIT $per_page OFFSET $offset");
        $dataStmt->execute($params);
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['contact_person'] = trim(implode(' ', array_filter([
                $row['title'] ?? '',
                $row['first_name'] ?? '',
                $row['last_name'] ?? ''
            ])));
            $row['billing_address'] = buildAddressText([
                $row['address_line1'] ?? '',
                $row['address_line2'] ?? '',
                $row['address_city'] ?? '',
                $row['address_state'] ?? '',
                $row['pincode'] ?? '',
                $row['address_country'] ?? ''
            ]);
            $row['shipping_address'] = buildAddressText([
                $row['ship_address_line1'] ?? '',
                $row['ship_address_line2'] ?? '',
                $row['ship_city'] ?? '',
                $row['ship_state'] ?? '',
                $row['ship_pincode'] ?? '',
                $row['ship_country'] ?? ''
            ]);
        }
        unset($row);
        ob_clean(); echo json_encode([
            'data'        => $rows,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => $total_pages,
        ]);
    } catch (Exception $e) { ob_clean(); echo json_encode(['data'=>[],'total'=>0,'page'=>1,'total_pages'=>1,'error'=>$e->getMessage()]); }
    exit;
}
if (isset($_GET['get_terms'])) {
    header('Content-Type: application/json');
    try { ob_clean(); echo json_encode(array_column($pdo->query("SELECT term_text FROM po_master_terms WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC),'term_text')); }
    catch (Exception $e) { ob_clean(); echo json_encode([]); }
    exit;
}
if (isset($_POST['save_master_item'])) {
    header('Content-Type: application/json');
    try {
        $name=trim($_POST['item_name']??'');
        if(!$name){echo json_encode(['success'=>false,'message'=>'Name required']);exit;}
        $chk=$pdo->prepare("SELECT id FROM items WHERE LOWER(item_name)=LOWER(?)");$chk->execute([$name]);
        if($chk->fetch()){echo json_encode(['success'=>true,'exists'=>true]);exit;}
        $pdo->prepare("INSERT INTO items (item_name,material_description,hsn_sac,uom,unit_price) VALUES (:name,:desc,:hsn,:unit,:rate)")
            ->execute([':name'=>$name,':desc'=>trim($_POST['description']??''),':hsn'=>trim($_POST['hsn_sac']??''),':unit'=>trim($_POST['unit']??'no.s'),':rate'=>(float)($_POST['rate']??0)]);
        echo json_encode(['success'=>true]);
    } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

// Edit mode
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$quot = null; $items = []; $terms = [];
$default_terms = ['This quotation is valid for 30 days from the date of issue.','Prices are subject to change without prior notice after validity period.','Delivery timeline will be confirmed upon order placement.','Payment Terms: 100% advance or as per agreed terms.'];

if ($edit_id) {
    $s=$pdo->prepare("SELECT * FROM quotations WHERE id=?");$s->execute([$edit_id]);$quot=$s->fetch(PDO::FETCH_ASSOC);
    if(!$quot){header('Location: quote_index.php');exit;}
    $items=!empty($quot['items_json'])?json_decode($quot['items_json'],true):[];
    if(!empty($quot['terms_list'])){
        $term_ids=json_decode($quot['terms_list'],true);
        if(!empty($term_ids)&&is_array($term_ids)){
            $pl=implode(',',array_fill(0,count($term_ids),'?'));
            $ts=$pdo->prepare("SELECT term_text FROM po_master_terms WHERE id IN ($pl) ORDER BY FIELD(id,".implode(',',array_fill(0,count($term_ids),'?')).")");
            $ts->execute(array_merge($term_ids,$term_ids));
            $terms=array_column($ts->fetchAll(PDO::FETCH_ASSOC),'term_text');
        }
    }
}
if(empty($terms)) $terms=$default_terms;

// Fetch banks
$banks = [];
try { $banks = $pdo->query("SELECT * FROM bank_details ORDER BY bank_name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){ $banks=[]; }
// Fetch signatures
$signatures = [];
try { $signatures = $pdo->query("SELECT * FROM signatures ORDER BY signature_name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e){ $signatures=[]; }

[$next_num, $last] = get_next_prefixed_number($pdo, 'quotations', 'quot_number', 'ELT-QT-', 7, 2526001);
$rows=$items?:[];

/* ── Company override (invoice_company) for Quotation ─────────── */
$allCompanies = [];
try { $allCompanies = $pdo->query("SELECT * FROM invoice_company ORDER BY company_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Exception $e) { $allCompanies = []; }
$companyBase = $allCompanies[0] ?? [];
$existingCompanyOverride = [];
if (is_array($quot) && !empty($quot['company_override'])) {
    $existingCompanyOverride = json_decode($quot['company_override'], true);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title><?= $edit_id ? 'Edit' : 'Create' ?> Quotation – Eltrive</title>
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

/* FIELD LABELS + INPUTS – unified across all sections */
.field-section-label,label{display:block;font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.6px;color:#1f2937;margin-bottom:3px}
.field-input-styled,
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
.field-input-styled:focus,.form-control:focus,.form-select:focus{border-color:#f97316;box-shadow:0 0 0 2px rgba(249,115,22,.1)}
.field-input-styled[readonly]{background:#f8fafc;cursor:pointer}
.supplier-field-wrap{display:flex;gap:5px;align-items:center}
.supplier-field-wrap .field-input-styled{flex:1}
.row>[class*=col]{margin-bottom:6px}

/* SECTION DIVIDERS */
.section-divider{margin:6px 0 5px;border-top:1px dashed #e4e8f0;position:relative}
.section-divider span{position:absolute;top:-8px;left:0;background:#fff;padding:0 8px;font-size:9px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px}

/* TWO-COL LAYOUT */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px}

/* BUTTONS */
.btn-theme{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;box-shadow:0 2px 8px rgba(249,115,22,.25)}
.btn-theme:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(249,115,22,.35)}
.btn-theme-blue{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;background:linear-gradient(135deg,#1565c0,#0d47a1);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;box-shadow:0 2px 8px rgba(21,101,192,.25)}
.btn-theme-blue:hover{transform:translateY(-1px)}
.btn-outline-theme{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;background:#fff;border:1.5px solid #e4e8f0;border-radius:7px;font-size:12px;font-weight:600;color:#374151;text-decoration:none;cursor:pointer;transition:all .2s}
.btn-outline-theme:hover{border-color:#f97316;color:#f97316;background:#fff7f0}
.btn-add-item{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;transition:all .2s;margin-top:6px}
.btn-add-item:hover{transform:translateY(-1px)}
.btn-plus{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:7px;font-size:14px;cursor:pointer;transition:all .2s;flex-shrink:0}
.btn-plus:hover{transform:translateY(-1px)}
.btn-danger-sm{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;background:#fef2f2;border:1px solid #fca5a5;border-radius:5px;color:#dc2626;cursor:pointer;font-size:10px;transition:all .2s}
.btn-danger-sm:hover{background:#dc2626;color:#fff}
.bottom-actions{display:flex;gap:8px;align-items:center;padding:10px 14px;border-top:1px solid #f0f2f7;background:#fafbfd}

/* ITEM TABLE */
.table-wrap{overflow-x:auto}
#itemTable{width:100%;border-collapse:collapse;font-size:11.5px;background:#fff}
#itemTable thead th{background:#fff7f0;color:#f97316;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;padding:5px 5px;border-bottom:2px solid #fed7aa;white-space:nowrap}
#itemTable td{padding:3px 5px;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#374151}
#itemTable tbody tr:hover td{background:#fff7f0}
#itemTable input{width:100%;border:1.5px solid #e4e8f0;border-radius:5px;padding:3px 5px;font-size:11.5px;font-family:'Segoe UI',system-ui,sans-serif;background:#fff;outline:none}
#itemTable input:focus{border-color:#f97316;box-shadow:0 0 0 2px rgba(249,115,22,.1)}
#itemTable td.num{text-align:right;font-weight:600;white-space:nowrap;padding:3px 6px}

/* TOTALS BOX */
.total-box{text-align:right;margin-top:8px;font-size:12px;padding:8px 12px;background:#f8fafc;border-radius:8px;border:1px solid #e4e8f0;min-width:220px}
.total-box .grand{font-size:14px;font-weight:800;color:#15803d;margin-top:4px;background:#f0fdf4;padding:4px 8px;border-radius:7px;display:inline-block}

/* TERMS */
.term-row{display:flex;align-items:center;gap:6px;background:#fafafa;border:1px solid #f0f2f7;border-radius:7px;padding:6px 10px;margin-bottom:4px;font-size:12px}
.term-row span{flex:1;color:#374151}
.term-actions{display:flex;gap:3px;flex-shrink:0}
.term-btn{width:22px;height:22px;border-radius:5px;border:none;cursor:pointer;font-size:10px;display:flex;align-items:center;justify-content:center}
.term-btn-edit{background:#fff8e1;color:#f57c00}
.term-btn-del{background:#fde8e8;color:#c62828}
.btn-add-term{display:inline-flex;align-items:center;gap:5px;background:none;border:1.5px dashed #f97316;color:#f97316;border-radius:7px;padding:5px 11px;cursor:pointer;font-size:11px;font-weight:700;margin-top:3px;transition:all .2s}
.btn-add-term:hover{background:#fff7f0}

/* OVERLAY MODALS */
.sp-overlay,.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center}
.sp-overlay.open,.modal-overlay.open{display:flex!important}
.sp-box,.modal-box{background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.18);font-family:'Segoe UI',system-ui,sans-serif}
.sp-box{width:360px;max-width:95vw;max-height:85vh;display:flex;flex-direction:column;overflow:hidden}
.modal-box{width:500px;max-width:96vw;max-height:90vh;overflow-y:auto}
.sp-header,.modal-header-box{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f0f2f7;background:#fafbfd}
.sp-header h3,.modal-header-box h3{font-size:13px;font-weight:800;color:#1a1f2e}
.sp-close,.modal-close-btn{background:none;border:none;font-size:18px;color:#9ca3af;cursor:pointer}
.sp-search-wrap,.modal-search-wrap{padding:8px 14px;border-bottom:1px solid #f0f2f7}
.sp-search,.modal-search-inp{width:100%;border:1.5px solid #e4e8f0;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;outline:none}
.sp-search:focus,.modal-search-inp:focus{border-color:#f97316}
.sp-list{overflow-y:auto;flex:1}
.sp-item{padding:8px 14px;cursor:pointer;border-bottom:1px solid #f9f9f9;transition:background .1s}
.sp-item:hover{background:#fff7f0}
.sp-item-name{font-size:13px;font-weight:700;color:#1a1f2e}
.sp-item-sub{font-size:11px;color:#9ca3af;margin-top:1px}
.sp-empty{padding:20px;text-align:center;color:#9ca3af;font-size:13px}
.sp-footer,.modal-footer-box{padding:10px 14px;border-top:1px solid #f0f2f7;background:#fafbfd;display:flex;gap:6px}
.item-select-row{display:flex;align-items:center;gap:8px;padding:7px 12px;border-bottom:1px solid #f5f5f5;cursor:pointer;transition:background .1s}
.item-select-row:hover{background:#fff7f0}
.item-select-name{font-size:13px;font-weight:700;color:#1a1f2e}
.item-select-sub{font-size:11px;color:#9ca3af;margin-top:1px}
.mf-group{margin-bottom:8px}
.mf-label{display:block;font-size:10px;font-weight:900;color:#1f2937;text-transform:uppercase;letter-spacing:.6px;margin-bottom:3px}
.mf-input,.mf-select,.mf-textarea{width:100%;border:1.5px solid #e4e8f0;border-radius:7px;padding:6px 10px;font-size:12px;font-family:inherit;outline:none;background:#fff}
.mf-input:focus,.mf-select:focus,.mf-textarea:focus{border-color:#f97316}
.mf-textarea{resize:vertical;min-height:50px}
.mf-row{display:flex;gap:8px}.mf-row .mf-group{flex:1;margin-bottom:0}
.term-select-item{padding:8px 12px;cursor:pointer;border-bottom:1px solid #f5f5f5;font-size:13px;transition:background .1s}
.term-select-item:hover{background:#fff7f0;color:#f97316}
.term-select-list{max-height:200px;overflow-y:auto}
.add-term-box{padding:10px 14px;border-top:1px solid #f0f2f7}

/* SELECT2 height fix */
.select2-container .select2-selection--single{height:30px!important;line-height:28px!important;border:1.5px solid #e4e8f0!important;border-radius:7px!important;font-size:12px!important}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:28px!important}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:28px!important;padding-left:10px!important;font-size:12px}

/* TOAST */
.val-toast{position:fixed;top:60px;left:50%;transform:translateX(-50%);background:#c62828;color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:700;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,.25);display:none;min-width:220px;text-align:center}
.val-toast.show{display:block}

::-webkit-scrollbar{width:4px}::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:99px}
@media(max-width:900px){.two-col{grid-template-columns:1fr}.content{margin-left:0!important;padding:60px 10px 16px}}
</style>
</head>
<body>
<?php include dirname(__DIR__) . '/sidebar.php'; ?>
<?php include dirname(__DIR__) . '/header.php'; ?>

<div class="content">
<?php if (!empty($error)): ?>
<div style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;padding:8px 12px;border-radius:8px;margin-bottom:8px;font-size:12px;">
    <i class="fas fa-exclamation-circle" style="margin-right:6px"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>
<form id="qtForm" method="POST" action="quote_create.php">
    <input type="hidden" name="action" id="formAction" value="save">
    <?php if($edit_id): ?><input type="hidden" name="edit_id" value="<?= $edit_id ?>"><?php endif; ?>

    <!-- Company override hidden fields (posted to quote_create.php) -->
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
            <div class="page-icon"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="page-title"><?= $edit_id ? 'Edit' : 'Create' ?> Quotation</div>
                <div class="page-sub">Fill in the details to generate a new quotation</div>
            </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
            <a href="quote_index.php" class="btn-outline-theme"><i class="fas fa-arrow-left"></i> Back</a>
            <div style="display:flex;gap:8px;align-items:center">
                <select id="company_select" name="selected_company_id" class="mf-select" style="min-width:260px;cursor:pointer">
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

    <!-- CUSTOMER -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="hdr-icon" style="background:linear-gradient(135deg,#f97316,#fb923c)"><i class="fas fa-user"></i></div>
            <h3>Customer</h3>
        </div>
        <div class="form-card-body">

            <!-- 3-column layout: Customer | Billing | Shipping -->
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">

                <!-- COLUMN 1: Customer Info -->
                <div style="border:1px solid #e8ecf4;border-radius:8px;padding:8px 10px;background:#fafbfd">
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#f97316;margin-bottom:6px;display:flex;align-items:center;gap:5px">
                        <i class="fas fa-user" style="font-size:9px"></i> Customer Info
                    </div>
                    <div style="margin-bottom:5px">
                        <span class="field-section-label">Customer</span>
                        <div class="supplier-field-wrap">
                            <input class="field-input-styled" type="text" name="customer_name" id="customerInput"
                                   value="<?= htmlspecialchars($quot['customer_name'] ?? '') ?>"
                                   placeholder="— Select Customer —" onclick="openCustomerPopup()" readonly>
                            <a href="/invoice/add_customer.php" class="btn-plus" title="Add New Customer" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center;"><i class="fas fa-plus"></i></a>
                        </div>
                    </div>
                    <div style="margin-bottom:5px">
                        <span class="field-section-label">Contact Person</span>
                        <input class="field-input-styled" type="text" name="contact_person" id="contactInput" value="<?= htmlspecialchars($quot['contact_person'] ?? '') ?>" placeholder="Contact name">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
                        <div>
                            <span class="field-section-label">Phone</span>
                            <input class="field-input-styled" type="text" name="customer_phone" id="phoneInput" value="<?= htmlspecialchars($quot['customer_phone'] ?? '') ?>" placeholder="Mobile number">
                        </div>
                        <div>
                            <span class="field-section-label">GSTIN</span>
                            <input class="field-input-styled" type="text" name="customer_gstin" id="gstinInput" value="<?= htmlspecialchars($quot['customer_gstin'] ?? '') ?>" placeholder="22AAAAA0000A1Z5" style="text-transform:uppercase">
                        </div>
                    </div>
                </div>

                <!-- COLUMN 2: Billing Details -->
                <div style="border:1px solid #e8ecf4;border-radius:8px;padding:8px 10px;background:#fafbfd">
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#7c3aed;margin-bottom:6px;display:flex;align-items:center;gap:5px">
                        <i class="fas fa-file-invoice" style="font-size:9px"></i> Billing Details
                    </div>
                    <div style="margin-bottom:5px">
                        <span class="field-section-label">Address</span>
                        <textarea class="field-input-styled" name="billing_details" id="addrInput" rows="3" placeholder="Customer billing address"><?= htmlspecialchars($quot['billing_details'] ?? '') ?></textarea>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
                        <div>
                            <span class="field-section-label">GSTIN</span>
                            <input class="field-input-styled" type="text" name="billing_gstin" id="billingGstinInput" value="<?= htmlspecialchars($quot['billing_gstin'] ?? '') ?>" placeholder="Billing GSTIN" style="text-transform:uppercase" maxlength="15">
                            <span id="billingGstin_hint" style="font-size:10px;margin-top:2px;display:none;font-weight:600"></span>
                        </div>
                        <div>
                            <span class="field-section-label">PAN</span>
                            <input class="field-input-styled" type="text" name="billing_pan" id="billingPanInput" value="<?= htmlspecialchars($quot['billing_pan'] ?? '') ?>" placeholder="Billing PAN" style="text-transform:uppercase" maxlength="10">
                            <span id="billingPan_hint" style="font-size:10px;margin-top:2px;display:none;font-weight:600"></span>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px">
                        <div>
                            <span class="field-section-label">Phone</span>
                            <input class="field-input-styled" type="text" name="billing_phone" id="billingPhoneInput" value="<?= htmlspecialchars($quot['billing_phone'] ?? '') ?>" placeholder="Billing phone" maxlength="10">
                            <span id="billingPhone_hint" style="font-size:10px;margin-top:2px;display:none;font-weight:600"></span>
                        </div>
                    </div>
                </div>

                <!-- COLUMN 3: Shipping Details -->
                <div style="border:1px solid #e8ecf4;border-radius:8px;padding:8px 10px;background:#fafbfd">
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#0891b2;margin-bottom:6px;display:flex;align-items:center;justify-content:space-between">
                        <span style="display:flex;align-items:center;gap:5px"><i class="fas fa-truck" style="font-size:9px"></i> Shipping Details</span>
                        <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;text-transform:none;letter-spacing:0;font-size:10px;font-weight:600;color:#374151;margin-bottom:0">
                            <input type="checkbox" id="sameAsBillingChk" style="width:12px;height:12px;accent-color:#f97316;cursor:pointer"
                                <?= !empty($quot['shipping_details']) && $quot['shipping_details'] == ($quot['billing_details'] ?? $quot['customer_address'] ?? '') ? 'checked' : '' ?>>
                            Same as Billing
                        </label>
                    </div>
                    <div style="margin-bottom:5px">
                        <span class="field-section-label">Address</span>
                        <textarea class="field-input-styled" name="shipping_details" id="shippingInput" rows="3" placeholder="Delivery / shipping address"><?= htmlspecialchars($quot['shipping_details'] ?? '') ?></textarea>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
                        <div>
                            <span class="field-section-label">GSTIN</span>
                            <input class="field-input-styled" type="text" name="shipping_gstin" id="shippingGstinInput" value="<?= htmlspecialchars($quot['shipping_gstin'] ?? '') ?>" placeholder="Shipping GSTIN" style="text-transform:uppercase" maxlength="15">
                            <span id="shippingGstin_hint" style="font-size:10px;margin-top:2px;display:none;font-weight:600"></span>
                        </div>
                        <div>
                            <span class="field-section-label">PAN</span>
                            <input class="field-input-styled" type="text" name="shipping_pan" id="shippingPanInput" value="<?= htmlspecialchars($quot['shipping_pan'] ?? '') ?>" placeholder="Shipping PAN" style="text-transform:uppercase" maxlength="10">
                            <span id="shippingPan_hint" style="font-size:10px;margin-top:2px;display:none;font-weight:600"></span>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px">
                        <div>
                            <span class="field-section-label">Phone</span>
                            <input class="field-input-styled" type="text" name="shipping_phone" id="shippingPhoneInput" value="<?= htmlspecialchars($quot['shipping_phone'] ?? '') ?>" placeholder="Shipping phone" maxlength="10">
                            <span id="shippingPhone_hint" style="font-size:10px;margin-top:2px;display:none;font-weight:600"></span>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>

    <!-- QUOTATION DETAILS + DOCUMENT in two columns -->
    <div class="two-col">
        <!-- QUOTATION DETAILS -->
        <div class="form-card">
            <div class="form-card-header">
                <div class="hdr-icon" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed)"><i class="fas fa-file-alt"></i></div>
                <h3>Quotation Details</h3>
            </div>
            <div class="form-card-body">
                <div class="row">
                    <div class="col-md-6">
                        <label>Quotation No.</label>
                        <input class="form-control" type="text" name="quot_number"
                               value="<?= htmlspecialchars($quot['quot_number'] ?? $next_num) ?>">
                        <?php if(!$edit_id): ?><small style="color:#9ca3af;font-size:12px;margin-top:3px;display:block">Prev: <?= htmlspecialchars($last ?: 'None') ?></small><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label>Reference</label>
                        <input class="form-control" type="text" name="reference" value="<?= htmlspecialchars($quot['reference'] ?? '') ?>" placeholder="VERBAL / EMAIL">
                    </div>
                    <div class="col-md-6">
                        <label>Quotation Date</label>
                        <input class="form-control" type="date" name="quot_date" value="<?= htmlspecialchars($quot['quot_date'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label>Valid Till</label>
                        <input class="form-control" type="date" name="valid_till" value="<?= htmlspecialchars($quot['valid_till'] ?? date('Y-m-d', strtotime('+30 days')))?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- NOTES + BANK -->
        <div class="form-card">
            <div class="form-card-header">
                <div class="hdr-icon" style="background:linear-gradient(135deg,#06b6d4,#0891b2)"><i class="fas fa-sticky-note"></i></div>
                <h3>Notes</h3>
            </div>
            <div class="form-card-body">
                <label>Additional Notes</label>
                <textarea class="form-control" name="notes" rows="2" placeholder="Additional notes or remarks..."><?= htmlspecialchars($quot['notes'] ?? '') ?></textarea>

                <div style="margin-top:8px;border-top:1px dashed #e4e8f0;padding-top:8px">
                    <label>Bank Details</label>
                    <div style="display:flex;gap:8px">
                        <select name="bank_id" id="bank_select" class="form-control">
                            <option value="">-- Select Bank --</option>
                            <?php foreach ($banks as $bank): ?>
                                <option value="<?= $bank['id']; ?>" <?= (!empty($quot['bank_id']) && (string)$quot['bank_id'] === (string)$bank['id']) ? 'selected' : '' ?>><?= htmlspecialchars($bank['bank_name'] . (!empty($bank['branch']) ? ' - ' . $bank['branch'] : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn-plus" onclick="openModal('addBankModal')" title="Add Bank"><i class="fas fa-plus"></i></button>
                    </div>
                </div>

                <div style="margin-top:8px;border-top:1px dashed #e4e8f0;padding-top:8px">
                    <label>Authorised Signature</label>
                    <div style="display:flex;gap:8px;align-items:center">
                        <select name="signature_id" id="signature_select" class="form-control">
                            <option value="">-- Select Signature --</option>
                            <?php foreach ($signatures as $sig): ?>
                                <option value="<?= $sig['id']; ?>" data-path="<?= htmlspecialchars($sig['file_path']); ?>" <?= (!empty($quot['signature_id']) && (string)$quot['signature_id'] === (string)$sig['id']) ? 'selected' : '' ?>><?= htmlspecialchars($sig['signature_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn-plus" onclick="openModal('addSignatureModal')" title="Add Signature"><i class="fas fa-plus"></i></button>
                        <img id="signature_preview" src="" style="max-height:32px;max-width:80px;object-fit:contain;display:none;border:1px dashed #ccc;border-radius:4px;padding:2px">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ITEMS -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="hdr-icon" style="background:linear-gradient(135deg,#16a34a,#15803d)"><i class="fas fa-boxes"></i></div>
            <h3>Item List</h3>
        </div>
        <div class="form-card-body">
            <div class="table-wrap">
            <table id="itemTable">
                <thead>
                    <tr>
                        <th style="width:35px;">#</th>
                        <th style="min-width:180px;">Item / Description</th>
                        <th style="width:80px;">HSN/SAC</th>
                        <th style="width:65px;">Qty</th>
                        <th style="width:55px;">Unit</th>
                        <th style="width:90px;">Rate (₹)</th>
                        <th style="width:85px;">Disc (₹)</th>
                        <th style="width:90px;">Taxable (₹)</th>
                        <th style="width:60px;">CGST%</th>
                        <th style="width:80px;">CGST (₹)</th>
                        <th style="width:60px;">SGST%</th>
                        <th style="width:80px;">SGST (₹)</th>
                        <th style="width:60px;">IGST%</th>
                        <th style="width:80px;">IGST (₹)</th>
                        <th style="width:95px;">Amount (₹)</th>
                        <th style="width:34px;"></th>
                    </tr>
                </thead>
                <tbody id="itemBody">
                <?php foreach ($rows as $i => $it): ?>
                <tr class="item-row" data-index="<?= $i ?>">
                    <td class="num"><?= $i+1 ?></td>
                    <td>
                        <input type="hidden" name="items[<?= $i ?>][item_id]" value="<?= (int)($it['item_id']??0) ?>">
                        <input type="text" style="font-weight:700;color:#1a1f2e;margin-bottom:3px" name="items[<?= $i ?>][item_name]" value="<?= htmlspecialchars($it['item_name']??'') ?>" placeholder="Item name" required>
                        <input type="text" style="font-size:12px;color:#6b7280;border-top:1px dashed #e4e8f0!important" name="items[<?= $i ?>][description]" value="<?= htmlspecialchars($it['description']??'') ?>" placeholder="Description">
                    </td>
                    <td><input type="text" name="items[<?= $i ?>][hsn_sac]" value="<?= htmlspecialchars($it['hsn_sac']??'') ?>"></td>
                    <td><input type="text" name="items[<?= $i ?>][qty]" value="<?= $it['qty']??1 ?>" oninput="calcRow(this)" style="color:#1a1f2e;text-align:center;width:60px"></td>
                    <td><input type="text" name="items[<?= $i ?>][unit]" value="<?= htmlspecialchars($it['unit']??'') ?>" placeholder="pcs"></td>
                    <td><input type="number" name="items[<?= $i ?>][rate]" value="<?= $it['rate']??0 ?>" min="0" step="0.01" oninput="calcRow(this)"></td>
                    <td><input type="number" name="items[<?= $i ?>][discount]" value="<?= $it['discount']??0 ?>" min="0" step="0.01" oninput="calcRow(this)"></td>
                    <td class="num taxable-cell"><?= number_format($it['taxable']??0,2) ?><input type="hidden" name="items[<?= $i ?>][taxable]" value="<?= $it['taxable']??0 ?>"></td>
                    <td><input type="number" name="items[<?= $i ?>][cgst_pct]" value="<?= $it['cgst_pct']??0 ?>" min="0" step="0.01" oninput="calcRow(this)"></td>
                    <td class="num cgst-cell"><?= number_format($it['cgst_amt']??0,2) ?><input type="hidden" name="items[<?= $i ?>][cgst_amt]" value="<?= $it['cgst_amt']??0 ?>"></td>
                    <td><input type="number" name="items[<?= $i ?>][sgst_pct]" value="<?= $it['sgst_pct']??0 ?>" min="0" step="0.01" oninput="calcRow(this)"></td>
                    <td class="num sgst-cell"><?= number_format($it['sgst_amt']??0,2) ?><input type="hidden" name="items[<?= $i ?>][sgst_amt]" value="<?= $it['sgst_amt']??0 ?>"></td>
                    <td><input type="number" name="items[<?= $i ?>][igst_pct]" value="<?= $it['igst_pct']??0 ?>" min="0" step="0.01" oninput="calcRow(this)"></td>
                    <td class="num igst-cell"><?= number_format($it['igst_amt']??0,2) ?><input type="hidden" name="items[<?= $i ?>][igst_amt]" value="<?= $it['igst_amt']??0 ?>"></td>
                    <td class="num amt-cell"><?= number_format($it['amount']??0,2) ?><input type="hidden" name="items[<?= $i ?>][amount]" value="<?= $it['amount']??0 ?>"></td>
                    <td><button type="button" class="btn-danger-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <button type="button" class="btn-add-item" onclick="openSelectItemModal()"><i class="fas fa-plus"></i> Add Item</button>
            <div class="total-box">
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

    <!-- TERMS & CONDITIONS -->
    <div class="form-card">
        <div class="form-card-header">
            <div class="hdr-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)"><i class="fas fa-list-ul"></i></div>
            <h3>Terms &amp; Conditions</h3>
        </div>
        <div class="form-card-body">
            <div id="termsList">
            <?php foreach ($terms as $term): ?>
                <div class="term-row">
                    <span><?= htmlspecialchars($term) ?></span>
                    <div class="term-actions">
                        <button type="button" class="term-btn term-btn-edit" onclick="editTerm(this)"><i class="fas fa-pencil-alt"></i></button>
                        <button type="button" class="term-btn term-btn-del" onclick="removeTerm(this)"><i class="fas fa-times"></i></button>
                    </div>
                    <input type="hidden" name="terms[]" value="<?= htmlspecialchars($term) ?>">
                </div>
            <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add-term" onclick="openTermsPopup()"><i class="fas fa-plus"></i> Add Term</button>
        </div>
    </div>

    <!-- BOTTOM ACTIONS -->
    <div class="bottom-actions">

        <button type="button" class="btn-theme-blue" onclick="submitForm('save_draft')"><i class="fas fa-save"></i> Save Draft</button>
        <button type="button" class="btn-theme" onclick="submitForm('save')"><i class="fas fa-check"></i> Save &amp; Send</button>
    </div>
</form>
</div>

<div class="val-toast" id="valToast"></div>

<!-- Customer Popup -->
<div class="sp-overlay" id="customerOverlay">
  <div class="sp-box">
    <div class="sp-header"><h3><i class="fas fa-user" style="color:#f97316;margin-right:6px"></i>Select Customer</h3><button class="sp-close" onclick="closeCustomerPopup()">✕</button></div>
    <div class="sp-search-wrap"><input class="sp-search" id="custSearch" type="text" placeholder="Search by name or mobile..." oninput="onCustSearch(this.value)"></div>
    <div class="sp-list" id="custList"><div class="sp-empty">Loading...</div></div>
    <!-- Pagination -->
    <div id="custPagination" style="display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px 14px;border-top:1px solid #f0f2f7;background:#fafbfd;">
        <span id="custPageInfo" style="font-size:11px;color:#9ca3af;font-weight:600;"></span>
        <div style="display:flex;align-items:center;gap:5px;flex-wrap:wrap;justify-content:center;">
            <button type="button" id="custPrevBtn" onclick="custChangePage(-1)"
                style="display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border-radius:7px;font-size:13px;font-weight:600;border:1.5px solid #e4e8f0;color:#374151;background:#fff;cursor:pointer;">‹</button>
            <div id="custPageNumbers" style="display:flex;gap:5px;flex-wrap:wrap;"></div>
            <button type="button" id="custNextBtn" onclick="custChangePage(1)"
                style="display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border-radius:7px;font-size:13px;font-weight:600;border:1.5px solid #e4e8f0;color:#374151;background:#fff;cursor:pointer;">›</button>
        </div>
    </div>
    <div class="sp-footer">
        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit" onclick="useManualCustomer()"><i class="fas fa-keyboard"></i> Type Manually</button>
    </div>
  </div>
</div>

<!-- Bank Modal -->
<div class="modal-overlay" id="bankModal">
  <div class="modal-box" style="width:420px">
    <div class="modal-header-box"><h3><i class="fas fa-university" style="color:#0891b2;margin-right:6px"></i>Select Bank</h3><button class="modal-close-btn" onclick="closeModal('bankModal')">✕</button></div>
    <div style="padding:14px 18px" id="bankList">
        <?php if(empty($banks)): ?>
        <div style="text-align:center;padding:24px 0;color:#9ca3af;font-size:14px">No bank details found. Please add a bank first.</div>
        <?php else: ?>
        <?php foreach($banks as $b): ?>
        <div onclick="selectBank(<?= $b['id'] ?>, '<?= htmlspecialchars($b['bank_name'],ENT_QUOTES) ?>', '<?= htmlspecialchars($b['account_no']??'',ENT_QUOTES) ?>')"
             style="display:flex;align-items:center;gap:12px;padding:12px 14px;border:1.5px solid #e4e8f0;border-radius:10px;margin-bottom:8px;cursor:pointer;transition:all .15s"
             onmouseenter="this.style.borderColor='#0891b2';this.style.background='#f0f9ff'"
             onmouseleave="this.style.borderColor='#e4e8f0';this.style.background='#fff'">
            <div style="width:40px;height:40px;background:linear-gradient(135deg,#0891b2,#0e7490);border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="fas fa-university" style="color:#fff;font-size:16px"></i>
            </div>
            <div>
                <div style="font-weight:700;font-size:14px;color:#1a1f2e"><?= htmlspecialchars($b['bank_name']) ?></div>
                <div style="font-size:12px;color:#6b7280;margin-top:2px">
                    A/C: <?= htmlspecialchars($b['account_no']??'—') ?>
                    <?= !empty($b['ifsc_code'])?' | IFSC: '.htmlspecialchars($b['ifsc_code']):'' ?>
                    <?= !empty($b['branch'])?' | '.htmlspecialchars($b['branch']):'' ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <div class="modal-footer-box" style="justify-content:space-between">
        <button type="button" onclick="closeModal('bankModal');openModal('addBankModal')" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit"><i class="fas fa-plus"></i> Add New Bank</button>
        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#f5f5f5;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-size:13px;cursor:pointer;font-family:inherit" onclick="closeModal('bankModal')"><i class="fas fa-times"></i> Cancel</button>
    </div>
  </div>
</div>

<!-- Add Bank Modal -->
<div class="modal-overlay" id="addBankModal">
  <div class="modal-box" style="width:460px">
    <div class="modal-header-box">
        <h3><i class="fas fa-university" style="color:#16a34a;margin-right:8px"></i>Add Bank</h3>
        <button class="modal-close-btn" onclick="closeModal('addBankModal')">✕</button>
    </div>
    <div style="padding:20px 20px 10px">
        <div class="mf-group"><label class="mf-label">Bank Name</label><input class="mf-input" id="nb_name" placeholder="e.g. State Bank of India"></div>
        <div class="mf-group"><label class="mf-label">Branch</label><input class="mf-input" id="nb_branch" placeholder="Branch name"></div>
        <div class="mf-group"><label class="mf-label">Account No</label><input class="mf-input" id="nb_account" placeholder="Account number"></div>
        <div class="mf-group"><label class="mf-label">IFSC Code</label><input class="mf-input" id="nb_ifsc" placeholder="e.g. SBIN0001234"></div>
        <div id="nb_error" style="color:#dc2626;font-size:12px;margin-bottom:8px;display:none"></div>
    </div>
    <div class="modal-footer-box" style="justify-content:flex-end;gap:8px">
        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:9px 22px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 2px 8px rgba(22,163,74,.3)" onclick="saveNewBank()"><i class="fas fa-save"></i> Save Bank</button>
        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:9px 16px;background:#f5f5f5;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-size:13px;cursor:pointer;font-family:inherit" onclick="closeModal('addBankModal')">Cancel</button>
    </div>
  </div>
</div>

<!-- Add Signature Modal -->
<div class="modal-overlay" id="addSignatureModal">
  <div class="modal-box" style="width:460px">
    <div class="modal-header-box">
        <h3><i class="fas fa-signature" style="color:#10b981;margin-right:8px"></i>Add Signature</h3>
        <button class="modal-close-btn" onclick="closeModal('addSignatureModal')">✕</button>
    </div>
    <div style="padding:20px 20px 10px">
        <div class="mf-group"><label class="mf-label">Signature Name</label><input class="mf-input" id="new_signature_name" placeholder="e.g. CEO Signature"></div>
        <div class="mf-group"><label class="mf-label">Signature Image</label><input class="mf-input" id="new_signature_image" type="file" accept="image/png, image/jpeg, image/webp"></div>
        <small style="color:#6b7280;font-size:11px;display:block;margin-bottom:8px;">Upload clear PNG/JPG/WEBP signature.</small>
        <div id="add_sig_error" style="color:#dc2626;font-size:12px;margin-bottom:8px;display:none"></div>
    </div>
    <div class="modal-footer-box" style="justify-content:flex-end;gap:8px">
        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:9px 22px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 2px 8px rgba(16,185,129,.3)" onclick="saveNewSignature()"><i class="fas fa-save"></i> Save Signature</button>
        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:9px 16px;background:#f5f5f5;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-size:13px;cursor:pointer;font-family:inherit" onclick="closeModal('addSignatureModal')">Cancel</button>
    </div>
  </div>
</div>

<!-- Select Item Modal -->
<div class="modal-overlay" id="selectItemModal">
  <div class="modal-box">
    <div class="modal-header-box"><h3><i class="fas fa-boxes" style="color:#f97316;margin-right:6px"></i>Item Library</h3><button class="modal-close-btn" onclick="closeModal('selectItemModal')">✕</button></div>
    <div class="modal-search-wrap"><input class="modal-search-inp" id="itemSearch" type="text" placeholder="Search by name or HSN..." oninput="filterItems(this.value)"></div>
    <div id="itemSelectList" style="max-height:380px;overflow-y:auto;border-top:1px solid #f0f2f7">
        <div class="sp-empty">Loading...</div>
    </div>
    <div class="modal-footer-box" style="justify-content:space-between">
        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#1a2940;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit" onclick="closeModal('selectItemModal');openModal('addItemModal')"><i class="fas fa-plus"></i> Create New Item</button>
        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit" onclick="closeModal('selectItemModal')"><i class="fas fa-check"></i> Done</button>
    </div>
  </div>
</div>

<!-- Add Item Modal -->
<div class="modal-overlay" id="addItemModal">
  <div class="modal-box" style="width:460px">
    <div class="modal-header-box"><h3>Create New Item</h3><button class="modal-close-btn" onclick="closeModal('addItemModal')">✕</button></div>
    <div style="padding:18px">
        <div class="mf-group"><label class="mf-label">Item Name</label><input class="mf-input" id="ai_name" placeholder="e.g. Automation Panel"></div>
        <div class="mf-group"><label class="mf-label">Description</label><textarea class="mf-textarea" id="ai_desc" placeholder="Optional description"></textarea></div>
        <div class="mf-row">
            <div class="mf-group"><label class="mf-label">HSN/SAC</label><input class="mf-input" id="ai_hsn" placeholder="8537"></div>
            <div class="mf-group"><label class="mf-label">Unit</label><select class="mf-select" id="ai_unit"><option>no.s</option><option>pcs</option><option>set</option><option>lot</option><option>kg</option><option>m</option><option>sqm</option><option>hrs</option></select></div>
            <div class="mf-group"><label class="mf-label">Rate (₹)</label><input class="mf-input" id="ai_rate" type="number" value="0" min="0" step="0.01"></div>
        </div>
        <div class="mf-row">
            <div class="mf-group"><label class="mf-label">CGST %</label><input class="mf-input" id="ai_cgst" type="number" value="0" min="0" step="0.01"></div>
            <div class="mf-group"><label class="mf-label">SGST %</label><input class="mf-input" id="ai_sgst" type="number" value="0" min="0" step="0.01"></div>
            <div class="mf-group"><label class="mf-label">IGST %</label><input class="mf-input" id="ai_igst" type="number" value="0" min="0" step="0.01"></div>
        </div>
    </div>
    <div class="modal-footer-box" style="gap:8px">
        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit" onclick="saveAddItem(true)"><i class="fas fa-save"></i> Save & Add to Library</button>
        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#f5f5f5;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-size:13px;cursor:pointer;font-family:inherit" onclick="saveAddItem(false)"><i class="fas fa-plus"></i> Add to Quotation Only</button>
        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#f5f5f5;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-size:13px;cursor:pointer;font-family:inherit" onclick="closeModal('addItemModal')">Cancel</button>
    </div>
  </div>
</div>

<!-- Term Modal -->
<div id="termModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:3000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;width:520px;max-width:95vw;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.2);overflow:hidden;">
    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid #f0f2f7;background:linear-gradient(135deg,#fff7f0,#fff);">
      <div style="display:flex;align-items:center;gap:10px;">
        <div style="width:36px;height:36px;background:linear-gradient(135deg,#f97316,#fb923c);border-radius:9px;display:flex;align-items:center;justify-content:center;">
          <i class="fas fa-file-contract" style="color:#fff;font-size:15px;"></i>
        </div>
        <div>
          <div style="font-weight:800;font-size:15px;color:#1a1f2e;">Terms &amp; Conditions</div>
          <div style="font-size:11px;color:#9ca3af;">Check terms to include in quotation</div>
        </div>
      </div>
      <button onclick="closeTermsPopup()" style="width:32px;height:32px;border-radius:50%;border:1.5px solid #e4e8f0;background:#fff;cursor:pointer;font-size:16px;color:#6b7280;display:flex;align-items:center;justify-content:center;">✕</button>
    </div>
    <!-- Search -->
    <div style="padding:12px 20px;border-bottom:1px solid #f0f2f7;">
      <input id="termSearch" type="text" placeholder="🔍 Search terms..." oninput="filterTerms(this.value)"
        style="width:100%;padding:8px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;outline:none;font-family:inherit;box-sizing:border-box;">
    </div>
    <!-- Checkbox List -->
    <div id="termSelectList" style="flex:1;overflow-y:auto;padding:8px 0;"></div>
    <!-- New Term Inline Input -->
    <div id="newTermBox" style="display:none;padding:10px 20px;border-top:1px solid #f0f2f7;background:#f8faff;">
        <div style="display:flex;gap:8px;align-items:center;">
            <input id="newTermInput" type="text" placeholder="Type new term here..."
                style="flex:1;padding:8px 12px;border:1.5px solid #f97316;border-radius:8px;font-size:13px;outline:none;font-family:inherit;"
                onkeydown="if(event.key==='Enter')saveNewTermInline();">
            <button type="button" onclick="saveNewTermInline()"
                style="padding:8px 14px;background:#f97316;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;white-space:nowrap;">
                <i class="fas fa-check"></i> Add
            </button>
            <button type="button" onclick="document.getElementById('newTermBox').style.display='none';"
                style="padding:8px 10px;background:#f5f5f5;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-size:13px;cursor:pointer;">✕</button>
        </div>
    </div>
    <!-- Footer -->
    <div style="padding:12px 20px;border-top:1px solid #f0f2f7;display:flex;justify-content:space-between;align-items:center;background:#fafbfd;">
      <button type="button" onclick="openAddNewTerm()"
        style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#fff;border:1.5px solid #f97316;color:#f97316;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;">
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

<script>
let itemIndex = <?= count($rows) ?>;
let allItems  = [];
let allCustomers = [];
function calcRow(el) {
    const row=el.closest('.item-row');
    const qty=parseFloat(row.querySelector('[name*="[qty]"]').value)||0;
    const rate=parseFloat(row.querySelector('[name*="[rate]"]').value)||0;
    const disc=parseFloat(row.querySelector('[name*="[discount]"]').value)||0;
    const cgstP=parseFloat(row.querySelector('[name*="[cgst_pct]"]').value)||0;
    const sgstP=parseFloat(row.querySelector('[name*="[sgst_pct]"]').value)||0;
    const igstP=parseFloat(row.querySelector('[name*="[igst_pct]"]').value)||0;
    const taxable=(qty*rate)-disc;
    const cgst=taxable*cgstP/100,sgst=taxable*sgstP/100,igst=taxable*igstP/100;
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
    let t=0,cg=0,sg=0,ig=0,g=0;
    document.querySelectorAll('[name*="[taxable]"]').forEach(e=>t+=parseFloat(e.value)||0);
    document.querySelectorAll('[name*="[cgst_amt]"]').forEach(e=>cg+=parseFloat(e.value)||0);
    document.querySelectorAll('[name*="[sgst_amt]"]').forEach(e=>sg+=parseFloat(e.value)||0);
    document.querySelectorAll('[name*="[igst_amt]"]').forEach(e=>ig+=parseFloat(e.value)||0);
    document.querySelectorAll('[name*="[amount]"]').forEach(e=>g+=parseFloat(e.value)||0);
    document.getElementById('totalTaxable').textContent=fmt(t);
    document.getElementById('totalCgst').textContent=fmt(cg);
    document.getElementById('totalSgst').textContent=fmt(sg);
    document.getElementById('totalIgst').textContent=fmt(ig);
    const roundOffRow=document.getElementById('roundOffRow');
    if(roundOffRow && roundOffRow.style.display!=='none'){
        const paise=parseFloat((g-Math.floor(g)).toFixed(2));
        const roundOff=paise>0?-paise:0;
        document.getElementById('roundOffAmt').textContent=roundOff.toFixed(2);
        document.getElementById('grandTotal').textContent=fmt(g+roundOff);
    } else {
        document.getElementById('grandTotal').textContent=fmt(g);
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
function fmt(n){return parseFloat(n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}

function buildRowHTML(i,name,desc,hsn,qty,unit,rate,disc,cgstP,sgstP,igstP,itemId){
    itemId=itemId||0;
    const taxable=(qty*rate)-disc;
    const cgst=taxable*cgstP/100,sgst=taxable*sgstP/100,igst=taxable*igstP/100;
    const amt=taxable+cgst+sgst+igst;
    const rowNum=document.getElementById('itemBody').rows.length+1;
    return `<td class="num">${rowNum}</td>
        <td>
            <input type="hidden" name="items[${i}][item_id]" value="${itemId}">
            <input type="text" style="font-weight:700;color:#1a1f2e;margin-bottom:3px" name="items[${i}][item_name]" value="${esc(name)}" placeholder="Item name" required>
            <input type="text" style="font-size:12px;color:#6b7280" name="items[${i}][description]" value="${esc(desc)}" placeholder="Description">
        </td>
        <td><input type="text" name="items[${i}][hsn_sac]" value="${esc(hsn)}"></td>
        <td><input type="text" name="items[${i}][qty]" value="${qty}" oninput="calcRow(this)" style="color:#1a1f2e;text-align:center;width:60px"></td>
        <td><input type="text" name="items[${i}][unit]" value="${esc(unit)}" placeholder="pcs"></td>
        <td><input type="number" name="items[${i}][rate]" value="${rate}" min="0" step="0.01" oninput="calcRow(this)"></td>
        <td><input type="number" name="items[${i}][discount]" value="${disc}" min="0" step="0.01" oninput="calcRow(this)"></td>
        <td class="num taxable-cell">${fmt(taxable)}<input type="hidden" name="items[${i}][taxable]" value="${taxable.toFixed(2)}"></td>
        <td><input type="number" name="items[${i}][cgst_pct]" value="${cgstP}" min="0" step="0.01" oninput="calcRow(this)"></td>
        <td class="num cgst-cell">${fmt(cgst)}<input type="hidden" name="items[${i}][cgst_amt]" value="${cgst.toFixed(2)}"></td>
        <td><input type="number" name="items[${i}][sgst_pct]" value="${sgstP}" min="0" step="0.01" oninput="calcRow(this)"></td>
        <td class="num sgst-cell">${fmt(sgst)}<input type="hidden" name="items[${i}][sgst_amt]" value="${sgst.toFixed(2)}"></td>
        <td><input type="number" name="items[${i}][igst_pct]" value="${igstP}" min="0" step="0.01" oninput="calcRow(this)"></td>
        <td class="num igst-cell">${fmt(igst)}<input type="hidden" name="items[${i}][igst_amt]" value="${igst.toFixed(2)}"></td>
        <td class="num amt-cell">${fmt(amt)}<input type="hidden" name="items[${i}][amount]" value="${amt.toFixed(2)}"></td>
        <td><button type="button" class="btn-danger-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>`;
}
function addItemRowWithData(name,desc,hsn,qty,unit,rate,disc,cgstP,sgstP,igstP,itemId){
    const i=itemIndex++;
    const tbody=document.getElementById('itemBody');
    const tr=document.createElement('tr');tr.className='item-row';tr.dataset.index=i;
    tr.innerHTML=buildRowHTML(i,name,desc,hsn,qty,unit,rate,disc,cgstP||0,sgstP||0,igstP||0,itemId||0);
    tbody.appendChild(tr);renumberRows();updateTotals();
}
function removeRow(btn){
    if(document.querySelectorAll('.item-row').length<=1){alert('At least one item is required.');return;}
    btn.closest('.item-row').remove();renumberRows();updateTotals();
}
function renumberRows(){document.querySelectorAll('#itemBody .item-row').forEach((r,i)=>r.querySelector('.num').textContent=i+1);}

let custCurrentPage = 1;
let custTotalPages  = 1;
let custSearchQuery = '';
let custSearchTimer = null;

function openCustomerPopup(){
    document.getElementById('customerOverlay').classList.add('open');
    document.getElementById('custSearch').value='';
    custCurrentPage=1; custSearchQuery='';
    loadCustomers();
}
function closeCustomerPopup(){document.getElementById('customerOverlay').classList.remove('open');}

function onCustSearch(q){
    custSearchQuery=q; custCurrentPage=1;
    clearTimeout(custSearchTimer);
    custSearchTimer=setTimeout(()=>loadCustomers(), 300);
}

function custChangePage(dir){
    const newPage = custCurrentPage + dir;
    if(newPage<1||newPage>custTotalPages) return;
    custCurrentPage=newPage;
    loadCustomers();
}

function loadCustomers(){
    document.getElementById('custList').innerHTML='<div class="sp-empty">Loading...</div>';
    const url='/invoice/quotations/quote_create.php?get_customers=1&page='+custCurrentPage+'&search='+encodeURIComponent(custSearchQuery);
    fetch(url).then(r=>r.json()).then(res=>{
        if(res.error){document.getElementById('custList').innerHTML='<div class="sp-empty" style="color:red">Error: '+res.error+'</div>';return;}
        custTotalPages = res.total_pages||1;
        renderCustomers(res.data||[]);
        // Update pagination controls
        const info = document.getElementById('custPageInfo');
        info.textContent = 'Page '+custCurrentPage+' of '+custTotalPages+' ('+res.total+' customers)';
        const prev = document.getElementById('custPrevBtn');
        const next = document.getElementById('custNextBtn');
        prev.disabled = custCurrentPage<=1;
        prev.style.opacity = custCurrentPage<=1 ? '0.4' : '1';
        next.disabled = custCurrentPage>=custTotalPages;
        next.style.opacity = custCurrentPage>=custTotalPages ? '0.4' : '1';
        // Render page number buttons
        const pnDiv = document.getElementById('custPageNumbers');
        pnDiv.innerHTML = '';
        for(let p=1; p<=custTotalPages; p++){
            if(p===1||p===custTotalPages||Math.abs(p-custCurrentPage)<=1){
                const btn=document.createElement('button');
                btn.type='button';
                btn.textContent=p;
                btn.onclick=(function(pg){return function(){custCurrentPage=pg;loadCustomers();};})(p);
                btn.style.cssText='display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border-radius:7px;font-size:13px;font-weight:600;border:1.5px solid '+(p===custCurrentPage?'#16a34a':'#e4e8f0')+';color:'+(p===custCurrentPage?'#fff':'#374151')+';background:'+(p===custCurrentPage?'#16a34a':'#fff')+';cursor:pointer;';
                pnDiv.appendChild(btn);
            } else if(Math.abs(p-custCurrentPage)===2){
                const dot=document.createElement('span');
                dot.textContent='…';
                dot.style.cssText='display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;font-size:13px;color:#9ca3af;';
                pnDiv.appendChild(dot);
            }
        }
        // Show/hide pagination bar
        document.getElementById('custPagination').style.display = 'flex';
        if(custCurrentPage===1) document.getElementById('custSearch').focus();
    })
    .catch(()=>{document.getElementById('custList').innerHTML='<div class="sp-empty">Error loading.</div>';});
}
function renderCustomers(list){
    const el=document.getElementById('custList');
    if(!list.length){el.innerHTML='<div class="sp-empty">No customers found.</div>';return;}
    el.innerHTML=list.map(c=>`<div class="sp-item" onclick="selectCustomer(${JSON.stringify(c).replace(/"/g,'&quot;')})">
        <div class="sp-item-name">${esc(c.business_name||'')}</div>
        <div class="sp-item-sub">${c.mobile||''}${c.gstin?' | GSTIN: '+c.gstin:''}</div>
    </div>`).join('');
}
function filterCustomers(q){onCustSearch(q);}
function selectCustomer(c){
    const inp=document.getElementById('customerInput');
    inp.value=c.business_name||'';inp.removeAttribute('readonly');inp.style.cursor='';inp.onclick=null;
    document.getElementById('contactInput').value=c.contact_person||'';
    document.getElementById('phoneInput').value=c.mobile||'';
    document.getElementById('gstinInput').value=c.gstin||'';
    document.getElementById('billingGstinInput').value=c.billing_gstin||c.gstin||'';
    document.getElementById('billingPanInput').value=(c.billing_pan||c.pan_no||'').toUpperCase();
    document.getElementById('billingPhoneInput').value=c.billing_phone||c.mobile||'';

    const billingAddress = (c.billing_address||'').trim();
    const shippingAddress = (c.shipping_address||'').trim();
    const shippingGstin = (c.shipping_gstin||'').trim();
    const shippingPan = (c.shipping_pan||'').trim();
    const shippingPhone = (c.shipping_phone||'').trim();
    const hasSeparateShipping = shippingAddress !== '' || shippingGstin !== '' || shippingPan !== '' || shippingPhone !== '';

    document.getElementById('addrInput').value=billingAddress;

    if (hasSeparateShipping) {
        document.getElementById('sameAsBillingChk').checked = false;
        document.getElementById('shippingInput').value = shippingAddress;
        document.getElementById('shippingGstinInput').value = shippingGstin || document.getElementById('billingGstinInput').value;
        document.getElementById('shippingPanInput').value = (shippingPan || document.getElementById('billingPanInput').value).toUpperCase();
        document.getElementById('shippingPhoneInput').value = shippingPhone || document.getElementById('billingPhoneInput').value;
    } else {
        document.getElementById('sameAsBillingChk').checked = false;
        document.getElementById('shippingInput').value = '';
        document.getElementById('shippingGstinInput').value = '';
        document.getElementById('shippingPanInput').value = '';
        document.getElementById('shippingPhoneInput').value = '';
    }
    closeCustomerPopup();
}
function useManualCustomer(){
    const inp=document.getElementById('customerInput');
    inp.removeAttribute('readonly');inp.style.background='';inp.style.cursor='';inp.onclick=null;
    closeCustomerPopup();inp.focus();
}

function openSelectItemModal(){openModal('selectItemModal');document.getElementById('itemSearch').value='';loadItemList();}
function loadItemList(){
    document.getElementById('itemSelectList').innerHTML='<div class="sp-empty">Loading...</div>';
    fetch('/invoice/quotations/quote_create.php?get_items=1').then(r=>r.json()).then(data=>{allItems=data;renderItemList(data);document.getElementById('itemSearch').focus();})
    .catch(()=>{document.getElementById('itemSelectList').innerHTML='<div class="sp-empty" style="color:#ef4444">Error loading items.</div>';});
}
function renderItemList(items){
    const el=document.getElementById('itemSelectList');
    if(!items.length){el.innerHTML='<div class="sp-empty">No items yet.</div>';return;}
    el.innerHTML=items.map((it,idx)=>{const itemId=it.id||idx;
        const parts=[];
        const unit=(it.unit||'').trim();
        const hsn=(it.hsn_sac||'').trim();
        const rateNum=parseFloat(it.rate||0);
        if(unit) parts.push(esc(unit));
        if(hsn) parts.push('HSN: '+esc(hsn));
        if(rateNum>0) parts.push('₹ '+rateNum.toLocaleString('en-IN'));
        if(parseFloat(it.cgst_pct||0)>0) parts.push('C+S: '+(parseFloat(it.cgst_pct||0)+parseFloat(it.sgst_pct||0))+'%');
        if(parseFloat(it.igst_pct||0)>0) parts.push('IGST: '+it.igst_pct+'%');
        const sub=parts.join(' | ');
        return `<div class="item-select-row" onclick="addItemFromListById(${itemId})">
        <div style="flex:1;min-width:0">
            <div class="item-select-name">${esc(it.item_name||'')}</div>
            <div class="item-select-sub">${sub}</div>
        </div>
    </div>`;}).join('');
}
function addItemFromListById(id){
    const it=allItems.find(x=>x.id==id);if(!it)return;
    addItemRowWithData(it.item_name||'',it.description||'',it.hsn_sac||'',1,it.unit||'no.s',parseFloat(it.rate||0),0,parseFloat(it.cgst_pct||0),parseFloat(it.sgst_pct||0),parseFloat(it.igst_pct||0),parseInt(it.id||0));
    showMiniToast('✓ '+it.item_name+' added');
}
function addItemFromList(idx){const it=allItems[idx];if(!it)return;addItemFromListById(it.id);}
function filterItems(q){renderItemList(allItems.filter(it=>(it.item_name||'').toLowerCase().includes(q.toLowerCase())||(it.hsn_sac||'').toLowerCase().includes(q.toLowerCase())));}
function saveAddItem(saveToMaster){
    const name=document.getElementById('ai_name').value.trim();
    if(!name){alert('Item name is required.');return;}
    const desc=document.getElementById('ai_desc').value.trim();
    const hsn=document.getElementById('ai_hsn').value.trim();
    const unit=document.getElementById('ai_unit').value;
    const rate=document.getElementById('ai_rate').value;
    const cgst=document.getElementById('ai_cgst').value;
    const sgst=document.getElementById('ai_sgst').value;
    const igst=document.getElementById('ai_igst').value;
    addItemRowWithData(name,desc,hsn,1,unit,parseFloat(rate)||0,0,parseFloat(cgst)||0,parseFloat(sgst)||0,parseFloat(igst)||0,0);
    if(saveToMaster){
        const fd=new FormData();fd.append('save_master_item','1');fd.append('item_type','Product');fd.append('item_name',name);
        fd.append('description',desc);fd.append('hsn_sac',hsn);
        fd.append('unit',unit);fd.append('rate',rate);
        fd.append('cgst_pct',cgst);fd.append('sgst_pct',sgst);fd.append('igst_pct',igst);
        fetch('/invoice/quotations/quote_create.php',{method:'POST',body:fd}).then(r=>r.json()).then(d=>{if(d.success){showMiniToast('✓ '+name+' saved to library');fetch('/invoice/quotations/quote_create.php?get_items=1').then(r=>r.json()).then(data=>{allItems=data;renderItemList(data);});}});
    } else {showMiniToast('✓ '+name+' added to quotation');}
    ['ai_name','ai_desc','ai_hsn'].forEach(id=>document.getElementById(id).value='');
    ['ai_rate','ai_cgst','ai_sgst','ai_igst'].forEach(id=>document.getElementById(id).value=0);
    closeModal('addItemModal');
}

// ── Terms System ──────────────────────────────────────────────────────────────
let masterTermsList = [
    'This quotation is valid for 30 days.',
    'Delivery timeline to be confirmed on order.',
    'Payment Terms: 100% advance.',
    'Prices subject to change after validity.',
    'Taxes as applicable.',
    'Installation & Commissioning included in the above Price.',
    'Warranty: Standard manufacturer warranty must be provided.',
    'Freight charges are included in the quotation.'
];

function openTermsPopup(){
    fetch('/invoice/quotations/quote_create.php?get_terms=1')
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
        return `<div style="display:flex;align-items:flex-start;gap:10px;padding:10px 20px;border-bottom:1px solid #f5f5f5;transition:background .1s;" onmouseover="this.style.background='#fff7f0'" onmouseout="this.style.background=''">
            <input type="checkbox" id="ptc_${i}" ${checked?'checked':''} value="${esc(t)}"
                style="margin-top:3px;width:16px;height:16px;cursor:pointer;accent-color:#f97316;flex-shrink:0;">
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
    const existing=[...document.querySelectorAll('#termsList input[type=hidden]')].map(i=>i.value);
    if(existing.includes(t))return;
    const d=document.createElement('div');d.className='term-row';
    d.innerHTML=`<span>${esc(t)}</span>
        <div class="term-actions">
            <button type="button" class="term-btn term-btn-edit" onclick="editTerm(this)"><i class="fas fa-pencil-alt"></i></button>
            <button type="button" class="term-btn term-btn-del" onclick="removeTerm(this)"><i class="fas fa-times"></i></button>
        </div>
        <input type="hidden" name="terms[]" value="${esc(t)}">`;
    document.getElementById('termsList').appendChild(d);
}
function editTerm(btn){const row=btn.closest('.term-row');const v=prompt('Edit term:',row.querySelector('span').textContent);if(v&&v.trim()){row.querySelector('span').textContent=v.trim();row.querySelector('input[type=hidden]').value=v.trim();}}
function removeTerm(btn){btn.closest('.term-row').remove();}
function loadAndRenderTerms(q){ renderTermsPopup(); }
function renderTermList(q){ renderTermsPopup(); }
function filterTerms(q){
    const val=q.toLowerCase();
    document.querySelectorAll('#termSelectList > div').forEach(row=>{
        const label=row.querySelector('label');
        if(label) row.style.display=label.textContent.toLowerCase().includes(val)?'flex':'none';
    });
}
function openAddNewTerm(){
    const box = document.getElementById('newTermBox');
    box.style.display = box.style.display === 'none' ? 'flex' : 'none';
    if(box.style.display === 'flex'){
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
    setTimeout(()=>{
        document.querySelectorAll('#termSelectList input[type=checkbox]').forEach(cb=>{
            if(cb.value === t) cb.checked = true;
        });
    }, 50);
    inp.value = '';
    document.getElementById('newTermBox').style.display = 'none';
}
function toggleAddTermBox(){}
function selectTermById(){}
function saveNewTermInlineLEGACY(){
    const inp=document.getElementById('newTermInput');if(!inp)return;const t=inp.value.trim();
    if(!t){inp.style.borderColor='#ef4444';return;}
    inp.style.borderColor='#e4e8f0';addTerm(t);if(!masterTermsList.includes(t))masterTermsList.push(t);
    inp.value='';closeTermsPopup();
}

function openModal(id){if(id==='termModal'){openTermsPopup();return;}document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
function openBankModal(){openModal('bankModal');}
function selectBank(id, name, acct){
    const sel=document.getElementById('bank_select');
    if(sel) sel.value=id;
    closeModal('bankModal');
}
function saveNewBank(){
    const name=document.getElementById('nb_name').value.trim();
    const branch=document.getElementById('nb_branch').value.trim();
    const account=document.getElementById('nb_account').value.trim();
    const ifsc=document.getElementById('nb_ifsc').value.trim();
    const errEl=document.getElementById('nb_error');
    if(!name||!branch||!account||!ifsc){errEl.textContent='All fields are required.';errEl.style.display='block';return;}
    errEl.style.display='none';
    const fd=new FormData();
    fd.append('bank_name',name);fd.append('branch',branch);
    fd.append('account_no',account);fd.append('ifsc_code',ifsc);
    fetch('/invoice/addbank.php',{method:'POST',body:fd})
    .then(r=>r.json()).then(data=>{
        if(data.status==='success'||data.id){
            const select=document.getElementById('bank_select');
            const option=new Option(name + (branch ? ' - ' + branch : ''), data.id, true, true);
            select.appendChild(option);
            select.value=data.id;
            ['nb_name','nb_branch','nb_account','nb_ifsc'].forEach(id=>document.getElementById(id).value='');
            closeModal('addBankModal');
        } else {
            errEl.textContent=data.message||'Error saving bank.';errEl.style.display='block';
        }
    }).catch(()=>{errEl.textContent='Network error. Try again.';errEl.style.display='block';});
}

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

            document.getElementById('new_signature_name').value='';
            fileInput.value='';
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

const __sigSel = document.getElementById('signature_select');
if(__sigSel){
    __sigSel.addEventListener('change', refreshSignaturePreview);
    refreshSignaturePreview();
}

function submitForm(action){
    let valid = true;
    const GSTIN_RE = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/;
    const PAN_RE = /^[A-Z]{5}[0-9]{4}[A-Z]{1}$/;
    const PHONE_RE = /^[6-9]\d{9}$/;

    function markErr(el, msg){
        if(!el) return;
        el.style.borderColor='#ef4444'; el.style.boxShadow='0 0 0 3px rgba(239,68,68,.15)';
        let tip=el.parentElement.querySelector('.val-tip');
        if(!tip){tip=document.createElement('div');tip.className='val-tip';tip.style.cssText='font-size:11px;color:#ef4444;margin-top:3px;font-weight:600';el.parentElement.appendChild(tip);}
        tip.textContent='⚠ '+msg;
        el.addEventListener('input',()=>{el.style.borderColor='';el.style.boxShadow='';if(tip)tip.textContent='';},{once:true});
    }
    function reqField(id, msg){ const el=document.getElementById(id); if(!el) return; if(!el.value.trim()){ markErr(el,msg); valid=false; } }
    function fmtField(id, re, msg){ const el=document.getElementById(id); if(!el||!el.value.trim()) return; if(/^(NA|N\/A|na|n\/a)$/i.test(el.value.trim())) return; if(!re.test(el.value.trim().toUpperCase())){ markErr(el,msg); valid=false; } }
    function reqQuery(sel, msg){ const el=document.querySelector(sel); if(!el) return; if(!el.value.trim()){ markErr(el,msg); valid=false; } }

    // ── Customer (Row 1) ──
    fmtField('phoneInput',       PHONE_RE, 'Enter a valid 10-digit mobile number');
    fmtField('gstinInput',       GSTIN_RE, 'Invalid GSTIN format (e.g. 22AAAAA0000A1Z5)');

    // ── Billing (Row 2) ──
    fmtField('billingGstinInput',    GSTIN_RE, 'Invalid Billing GSTIN format (e.g. 22AAAAA0000A1Z5)');
    fmtField('billingPanInput',      PAN_RE,   'Invalid Billing PAN format (e.g. ABCDE1234F)');
    fmtField('billingPhoneInput',    PHONE_RE, 'Enter a valid 10-digit billing phone');

    // ── Shipping (Row 3) ──
    fmtField('shippingGstinInput',   GSTIN_RE, 'Invalid Shipping GSTIN format (e.g. 22AAAAA0000A1Z5)');
    fmtField('shippingPanInput',     PAN_RE,   'Invalid Shipping PAN format (e.g. ABCDE1234F)');
    fmtField('shippingPhoneInput',   PHONE_RE, 'Enter a valid 10-digit shipping phone');

    // ── Quotation Details ──
    const quotDate  = document.querySelector('[name="quot_date"]')?.value;
    const validTill = document.querySelector('[name="valid_till"]')?.value;
    if(quotDate && validTill && validTill < quotDate){
        markErr(document.querySelector('[name="valid_till"]'), 'Valid till must be on or after quotation date'); valid=false;
    }

    // ── Items ──
    const itemRows = document.querySelectorAll('#itemBody tr');
    if(itemRows.length === 0){ showValToast('⚠ Please add at least one item'); valid=false; }
    else {
        itemRows.forEach(row=>{
            const nm=row.querySelector('[name*="[item_name]"]');
            const qt=row.querySelector('[name*="[qty]"]');
            const rt=row.querySelector('[name*="[rate]"]');
            if(nm && !nm.value.trim()){ nm.style.borderColor='#ef4444'; valid=false; } else if(nm) nm.style.borderColor='';
            if(qt && (parseFloat(qt.value)||0)<=0){ qt.style.borderColor='#ef4444'; valid=false; } else if(qt) qt.style.borderColor='';
            if(rt && (parseFloat(rt.value)||0)<=0){ rt.style.borderColor='#ef4444'; valid=false; } else if(rt) rt.style.borderColor='';
        });
    }

    if(!valid){
        const firstErr = document.querySelector('.val-tip:not(:empty)') || document.querySelector('[style*="ef4444"]');
        if(firstErr) firstErr.scrollIntoView({behavior:'smooth',block:'center'});
        const tips = [...document.querySelectorAll('.val-tip:not(:empty)')];
        showValToast('⚠ ' + (tips.length ? tips[0].textContent.replace('⚠ ','') : 'Please fix the errors highlighted in red'));
        return;
    }
    document.getElementById('formAction').value = action;
    document.getElementById('qtForm').submit();
}
function esc(s){if(!s)return '';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function showValToast(msg){const t=document.getElementById('valToast');t.textContent=msg;t.classList.add('show');clearTimeout(t._tid);t._tid=setTimeout(()=>t.classList.remove('show'),3500);}
function showMiniToast(msg){let t=document.getElementById('miniToast');if(!t){t=document.createElement('div');t.id='miniToast';t.style.cssText='position:fixed;bottom:24px;right:24px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border-radius:8px;padding:10px 18px;font-size:14px;font-weight:700;z-index:99999;box-shadow:0 4px 20px rgba(0,0,0,.2);transition:opacity .3s';document.body.appendChild(t);}t.textContent=msg;t.style.opacity='1';clearTimeout(t._tid);t._tid=setTimeout(()=>t.style.opacity='0',2500);}

document.getElementById('customerOverlay').addEventListener('click',function(e){if(e.target===this)closeCustomerPopup();});
document.querySelectorAll('.modal-overlay').forEach(o=>o.addEventListener('click',function(e){if(e.target===this)closeModal(this.id);}));

// ── Same as Billing ──
function applySameAsBilling(checked){
    const shipAddr  = document.getElementById('shippingInput');
    const shipGstin = document.getElementById('shippingGstinInput');
    const shipPan   = document.getElementById('shippingPanInput');
    const shipPhone = document.getElementById('shippingPhoneInput');
    if(checked){
        shipAddr.value  = document.getElementById('addrInput').value;
        shipGstin.value = document.getElementById('billingGstinInput').value;
        shipPan.value   = document.getElementById('billingPanInput').value;
        shipPhone.value = document.getElementById('billingPhoneInput').value;
        // Lock with green style
        [shipAddr, shipGstin, shipPan, shipPhone].forEach(function(el){
            el.readOnly = true;
            el.style.background   = '#f0fdf4';
            el.style.borderColor  = '#bbf7d0';
            el.style.color        = '#374151';
            el.style.cursor       = 'not-allowed';
        });
    } else {
        // Unlock — restore full editability
        [shipAddr, shipGstin, shipPan, shipPhone].forEach(function(el){
            el.readOnly = false;
            el.style.background  = '';
            el.style.borderColor = '';
            el.style.color       = '';
            el.style.cursor      = '';
        });
    }
}
const sameChk = document.getElementById('sameAsBillingChk');
sameChk.addEventListener('change', function(){ applySameAsBilling(this.checked); });

// Keep shipping in sync when billing fields change while checkbox is ticked
document.getElementById('addrInput').addEventListener('input', function(){
    if(sameChk.checked) document.getElementById('shippingInput').value = this.value;
});
document.getElementById('billingGstinInput').addEventListener('input', function(){
    if(sameChk.checked) document.getElementById('shippingGstinInput').value = this.value.toUpperCase();
});
document.getElementById('billingPanInput').addEventListener('input', function(){
    if(sameChk.checked) document.getElementById('shippingPanInput').value = this.value.toUpperCase();
});
document.getElementById('billingPhoneInput').addEventListener('input', function(){
    if(sameChk.checked) document.getElementById('shippingPhoneInput').value = this.value;
});

// Apply on page load if checkbox is pre-checked
if(sameChk.checked) applySameAsBilling(true);

// ── Inline format validation (non-mandatory) for billing/shipping GSTIN & Phone ──
const QT_GSTIN_RE = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/;
const QT_PHONE_RE = /^[6-9]\d{9}$/;
const QT_NA_RE    = /^(NA|N\/A)$/i;

function qtShowHint(hintId, msg, isError) {
    const el = document.getElementById(hintId);
    if (!el) return;
    el.textContent = msg;
    el.style.display = 'block';
    el.style.color = isError ? '#dc2626' : '#16a34a';
    el.style.fontWeight = '600';
    el.style.fontSize = '11px';
    el.style.marginTop = '3px';
}
function qtClearHint(hintId, inputId) {
    const el = document.getElementById(hintId);
    if (el) { el.style.display = 'none'; el.textContent = ''; }
    const inp = document.getElementById(inputId);
    if (inp) inp.style.borderColor = '';
}
function qtValidateGstin(inputId, hintId) {
    const inp = document.getElementById(inputId); if(!inp) return;
    const val = inp.value.trim().toUpperCase();
    inp.value = val;
    if (!val || QT_NA_RE.test(val)) { qtClearHint(hintId, inputId); return; }
    if (QT_GSTIN_RE.test(val)) {
        qtShowHint(hintId, '✓ Valid GSTIN', false);
        inp.style.borderColor = '#16a34a';
    } else {
        qtShowHint(hintId, '✗ Invalid GSTIN (e.g. 22AAAAA0000A1Z5)', true);
        inp.style.borderColor = '#dc2626';
    }
}
function qtValidatePhone(inputId, hintId) {
    const inp = document.getElementById(inputId); if(!inp) return;
    const val = inp.value.trim();
    if (!val || QT_NA_RE.test(val)) { qtClearHint(hintId, inputId); return; }
    if (QT_PHONE_RE.test(val)) {
        qtShowHint(hintId, '✓ Valid phone', false);
        inp.style.borderColor = '#16a34a';
    } else {
        qtShowHint(hintId, '✗ Must be a valid 10-digit Indian mobile', true);
        inp.style.borderColor = '#dc2626';
    }
}
document.getElementById('billingGstinInput')?.addEventListener('blur', function(){ qtValidateGstin('billingGstinInput','billingGstin_hint'); });
document.getElementById('shippingGstinInput')?.addEventListener('blur', function(){ qtValidateGstin('shippingGstinInput','shippingGstin_hint'); });
document.getElementById('billingPhoneInput')?.addEventListener('blur', function(){ qtValidatePhone('billingPhoneInput','billingPhone_hint'); });
document.getElementById('shippingPhoneInput')?.addEventListener('blur', function(){ qtValidatePhone('shippingPhoneInput','shippingPhone_hint'); });
document.getElementById('billingPanInput')?.addEventListener('input', function(){ this.value = this.value.toUpperCase(); });
document.getElementById('shippingPanInput')?.addEventListener('input', function(){ this.value = this.value.toUpperCase(); });

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

        const res  = await fetch('quote_create.php', { method: 'POST', body: fd });
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

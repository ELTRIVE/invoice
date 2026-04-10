<?php
error_reporting(E_ERROR);
ini_set('display_errors', 0);
// create_invoice.php
require_once 'db.php';
try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN pan_no VARCHAR(20) DEFAULT ''");
} catch (Exception $e) {
}
try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN billing_pan VARCHAR(20) DEFAULT ''");
} catch (Exception $e) {
}
try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN ship_pan VARCHAR(20) DEFAULT ''");
} catch (Exception $e) {
}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS executives (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
} catch (Exception $e) {
}
try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN executive_id INT DEFAULT NULL");
} catch (Exception $e) {
}
try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN company_override TEXT DEFAULT NULL");
} catch (Exception $e) {
}
try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN po_number VARCHAR(100) DEFAULT ''");
} catch (Exception $e) {
}
try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN signature_id INT DEFAULT NULL");
} catch (Exception $e) {
}
date_default_timezone_set('Asia/Kolkata');

function get_next_prefixed_number(PDO $pdo, string $table, string $column, string $prefix, int $padLength, int $startNumber): array
{
    $stmt = $pdo->query("SELECT `$column` AS doc_no FROM `$table`");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $maxNum = $startNumber - 1;
    $lastDoc = '';
    $pattern = '/^' . preg_quote($prefix, '/') . '(\d{' . $padLength . '})$/';
    foreach ($rows as $row) {
        $value = trim((string)($row['doc_no'] ?? ''));
        if ($value === '' || !preg_match($pattern, $value, $m)) {
            continue;
        }
        $num = (int)$m[1];
        if ($num > $maxNum) {
            $maxNum = $num;
            $lastDoc = $value;
        }
    }
    $nextNum = max($startNumber, $maxNum + 1);
    return [$prefix . str_pad((string)$nextNum, $padLength, '0', STR_PAD_LEFT), $lastDoc];
}

function mergeAddressWithMeta(string $address, string $gstin = '', string $pan = '', string $phone = ''): string
{
    $lines = preg_split("/\r\n|\r|\n/", trim($address)) ?: [];
    $clean = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^(GSTIN|PAN|Phone)\s*:/i', $line)) {
            continue;
        }
        $clean[] = $line;
    }

    if (trim($gstin) !== '') {
        $clean[] = 'GSTIN: ' . strtoupper(trim($gstin));
    }
    if (trim($pan) !== '') {
        $clean[] = 'PAN: ' . strtoupper(trim($pan));
    }
    if (trim($phone) !== '') {
        $clean[] = 'Phone: ' . trim($phone);
    }

    return implode("\n", $clean);
}

function document_number_exists(PDO $pdo, string $table, string $column, string $value, ?int $excludeId = null): bool
{
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

// Handle Add Company AJAX (supports JSON or multipart/form-data)
$rawInput = file_get_contents('php://input');
$jsonBody = $rawInput ? json_decode($rawInput, true) : null;
$action = is_array($jsonBody) ? ($jsonBody['action'] ?? '') : '';
$action = $action ?: ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_company') {
    header('Content-Type: application/json');
    $src = is_array($jsonBody) ? $jsonBody : $_POST;

    $coName = trim($src['company_name'] ?? '');
    if (!$coName) {
        echo json_encode(['success' => false, 'message' => 'Company name required']);
        exit;
    }

    try {
        // Ensure table has all columns
        try {
            $pdo->exec("ALTER TABLE invoice_company ADD COLUMN IF NOT EXISTS cin_number VARCHAR(50) DEFAULT ''");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE invoice_company ADD COLUMN IF NOT EXISTS website VARCHAR(255) DEFAULT ''");
        } catch (Exception $e) {
        }
        try {
            $pdo->exec("ALTER TABLE invoice_company ADD COLUMN IF NOT EXISTS company_logo TEXT DEFAULT NULL");
        } catch (Exception $e) {
        }

        // Logo upload (optional): use uploaded file if provided, otherwise reuse existing path if passed.
        $logoPath = trim($src['company_logo_existing'] ?? '');
        $uploadDir = 'uploads/company/';
        if (!is_dir($uploadDir))
            mkdir($uploadDir, 0777, true);
        if (!empty($_FILES['company_logo']['name'])) {
            $logoName = time() . '_' . basename($_FILES['company_logo']['name']);
            $destPath = $uploadDir . $logoName;
            move_uploaded_file($_FILES['company_logo']['tmp_name'], $destPath);
            $logoPath = $destPath;
        }

        $pdo->prepare("INSERT INTO invoice_company
            (company_name, address_line1, address_line2, city, state, pincode, phone, email, gst_number, cin_number, pan, website, company_logo)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([
                $coName,
                trim($src['address_line1'] ?? ''),
                trim($src['address_line2'] ?? ''),
                trim($src['city'] ?? ''),
                trim($src['state'] ?? ''),
                trim($src['pincode'] ?? ''),
                trim($src['phone'] ?? ''),
                trim($src['email'] ?? ''),
                strtoupper(trim($src['gst_number'] ?? '')),
                strtoupper(trim($src['cin_number'] ?? '')),
                strtoupper(trim($src['pan'] ?? '')),
                trim($src['website'] ?? ''),
                !empty($logoPath) ? $logoPath : null,
            ]);

        echo json_encode([
            'success' => true,
            'id' => $pdo->lastInsertId(),
            'name' => $coName,
            'company_logo' => $logoPath,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Handle Add Executive AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_executive'])) {
    header('Content-Type: application/json');
    $name = trim($_POST['exec_name'] ?? '');
    if (!$name) {
        echo json_encode(['success' => false, 'message' => 'Name required']);
        exit;
    }
    try {
        $pdo->prepare("INSERT INTO executives (name) VALUES (?)")->execute([$name]);
        $newId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'id' => $newId, 'name' => $name]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Fetch all executives
try {
    $allExecs = $pdo->query("SELECT id, name FROM executives ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $allExecs = [];
}

/* ================= EDIT MODE: FETCH EXISTING INVOICE ================= */
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$isEdit = $editId > 0;
$editInvoice = null;
$editItems = [];

if ($isEdit) {
    $es = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
    $es->execute([$editId]);
    $editInvoice = $es->fetch(PDO::FETCH_ASSOC);
    if (!$editInvoice) {
        $isEdit = false;
        $editId = 0;
    }

    if ($isEdit) {
        $ei = $pdo->prepare("
            SELECT ia.*,
                   COALESCE(NULLIF(i.item_name,''), '') AS item_name
            FROM invoice_amounts ia
            LEFT JOIN items i ON i.id = ia.item_id
            WHERE ia.invoice_id = ?
              AND (ia.service_code IS NULL OR ia.service_code != 'PAYMENT')
            ORDER BY ia.id ASC
        ");
        $ei->execute([$editId]);
        $editItems = $ei->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ================= FETCH PREVIOUS INVOICE & VOUCHER ================= */
$error = '';
$prevInvoiceNo = '';
$prefix = 'ELT2526';
$startNum = 1;

[$nextInvoiceNo, $prevInvoiceNo] = get_next_prefixed_number($pdo, 'invoices', 'invoice_number', $prefix, 4, $startNum);

$prevVoucherStmt = $pdo->query("SELECT voucher_number FROM invoices ORDER BY id DESC LIMIT 1");
$prevVoucher = $prevVoucherStmt->fetch(PDO::FETCH_ASSOC);
$prevVoucherNo = '';
$nextVoucherNo = 1;
if ($prevVoucher && !empty($prevVoucher['voucher_number'])) {
    $prevVoucherNo = $prevVoucher['voucher_number'];
    $nextVoucherNo = is_numeric(trim($prevVoucherNo)) ? (int) trim($prevVoucherNo) + 1 : 1;
}


/* ================= SAVE INVOICE ================= */
if (isset($_POST['save_invoice'])) {
    $editId = isset($_POST['edit_id']) ? (int) $_POST['edit_id'] : 0;
    $isEdit = $editId > 0;
    try {
        $pdo->beginTransaction();

        // Collect all form data
        $customer = trim($_POST['customer'] ?? '');
        $contact_person = $_POST['contact_person'] ?? '';
        $mobile = $_POST['mobile'] ?? '';
        $sales_credit = $_POST['sales_credit'] ?? '';
        $executive_id = (int) ($_POST['executive_id'] ?? 0) ?: null;
        $billing_address = trim($_POST['billing_address'] ?? '');
        $shipping_address = trim($_POST['shipping_address'] ?? '');
        $gstin = $_POST['gstin'] ?? '';
        $pan_no = strtoupper(trim($_POST['pan'] ?? ''));
        $billing_gstin = trim($_POST['billing_gstin'] ?? '');
        $billing_pan = strtoupper(trim($_POST['billing_pan'] ?? ''));
        $billing_phone = trim($_POST['billing_phone'] ?? '');
        $ship_gstin = $_POST['ship_gstin'] ?? '';
        $ship_pan = strtoupper(trim($_POST['ship_pan'] ?? ''));
        $ship_phone_num = $_POST['ship_phone_num'] ?? '';
        $billing_address = mergeAddressWithMeta($billing_address, $billing_gstin, $billing_pan, $billing_phone);
        $shipping_address = mergeAddressWithMeta($shipping_address, $ship_gstin, $ship_pan, $ship_phone_num);
        $invoice_number = trim($_POST['invoice_number']);
        $reference = $_POST['reference'] ?? '';
        $po_number = trim($_POST['po_number'] ?? '');
        $invoice_date = $_POST['invoice_date'] ?? date('Y-m-d');
        $due_date = $_POST['due_date'] ?? date('Y-m-d');
        $party_ledger = $_POST['party_ledger'] ?? '';
        $income_ledger = $_POST['income_ledger'] ?? '';
        $voucher_number = trim($_POST['voucher_number']);
        $voucher_date = $_POST['voucher_date'] ?? date('Y-m-d');

        // Collect selected item IDs for item_list
        $item_ids = [];
        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (!empty($item['id'])) {
                    $item_ids[] = (int) $item['id'];
                }
            }
        }
        $item_list = !empty($item_ids)
            ? json_encode($item_ids)
            : json_encode([]);


        $bank_id = $_POST['bank_id'] ?? null;
        $signature_id = !empty($_POST['signature_id']) ? (int) $_POST['signature_id'] : null;

        if ($isEdit) {
            if (document_number_exists($pdo, 'invoices', 'invoice_number', $invoice_number, $editId)) {
                throw new Exception("Invoice number '{$invoice_number}' already exists. Please use a different invoice number.");
            }
        } else {
            if (document_number_exists($pdo, 'invoices', 'invoice_number', $invoice_number)) {
                if (preg_match('/^' . preg_quote($prefix, '/') . '\d{4}$/', $invoice_number)) {
                    [$invoice_number] = get_next_prefixed_number($pdo, 'invoices', 'invoice_number', $prefix, 4, $startNum);
                } else {
                    throw new Exception("Invoice number '{$invoice_number}' already exists. Please use a different invoice number.");
                }
            }
        }

        // Collect company override from hidden form fields
        $co_data = [
            'company_name' => trim($_POST['co_company_name'] ?? ''),
            'address_line1' => trim($_POST['co_address_line1'] ?? ''),
            'address_line2' => trim($_POST['co_address_line2'] ?? ''),
            'city' => trim($_POST['co_city'] ?? ''),
            'state' => trim($_POST['co_state'] ?? ''),
            'pincode' => trim($_POST['co_pincode'] ?? ''),
            'phone' => trim($_POST['co_phone'] ?? ''),
            'email' => trim($_POST['co_email'] ?? ''),
            'gst_number' => strtoupper(trim($_POST['co_gst_number'] ?? '')),
            'cin_number' => strtoupper(trim($_POST['co_cin_number'] ?? '')),
            'pan' => strtoupper(trim($_POST['co_pan'] ?? '')),
            'website' => trim($_POST['co_website'] ?? ''),
            'company_logo' => trim($_POST['co_company_logo'] ?? ''),
        ];
        // Only save override if user actually opened and confirmed the popup (flag hidden field)
        $company_override = (!empty($_POST['co_changed']) && $_POST['co_changed'] === '1')
            ? json_encode($co_data)
            : null;

        // INSERT with EXACT 18 columns (item_list is 18th, created_at is auto)
        if ($isEdit) {
            // UPDATE existing invoice
            $stmt = $pdo->prepare("
                UPDATE invoices SET
                    customer=?, contact_person=?, mobile=?, sales_credit=?,
                    billing_address=?, shipping_address=?, gstin=?, pan_no=?, billing_gstin=?, billing_pan=?, billing_phone=?, ship_gstin=?, ship_pan=?, ship_phone_num=?,
                    invoice_number=?, reference=?, po_number=?, invoice_date=?, due_date=?,
                    party_ledger=?, income_ledger=?, voucher_number=?, voucher_date=?, bank_id=?,
                    item_list=?, executive_id=?, company_override=?, signature_id=?
                WHERE id=?
            ");
            $stmt->execute([
                $customer,
                $contact_person,
                $mobile,
                $sales_credit,
                $billing_address,
                $shipping_address,
                $gstin,
                $pan_no,
                $billing_gstin,
                $billing_pan,
                $billing_phone,
                $ship_gstin,
                $ship_pan,
                $ship_phone_num,
                $invoice_number,
                $reference,
                $po_number,
                $invoice_date,
                $due_date,
                $party_ledger,
                $income_ledger,
                $voucher_number,
                $voucher_date,
                $bank_id,
                $item_list,
                $executive_id,
                $company_override,
                $signature_id,
                $editId
            ]);
            $invoiceId = $editId;
            // Delete old invoice_amounts and re-insert
            $pdo->prepare("DELETE FROM invoice_amounts WHERE invoice_id = ? AND (service_code IS NULL OR service_code != 'PAYMENT')")->execute([$invoiceId]);
        } else {
            // INSERT new invoice
            $stmt = $pdo->prepare("
                INSERT INTO invoices (
                    customer, contact_person, mobile, sales_credit,
                    billing_address, shipping_address, gstin, pan_no, billing_gstin, billing_pan, billing_phone, ship_gstin, ship_pan, ship_phone_num,
                    invoice_number, reference, po_number, invoice_date, due_date,
                    party_ledger, income_ledger, voucher_number, voucher_date, bank_id,
                    item_list, executive_id, company_override, signature_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $customer,
                $contact_person,
                $mobile,
                $sales_credit,
                $billing_address,
                $shipping_address,
                $gstin,
                $pan_no,
                $billing_gstin,
                $billing_pan,
                $billing_phone,
                $ship_gstin,
                $ship_pan,
                $ship_phone_num,
                $invoice_number,
                $reference,
                $po_number,
                $invoice_date,
                $due_date,
                $party_ledger,
                $income_ledger,
                $voucher_number,
                $voucher_date,
                $bank_id,
                $item_list,
                $executive_id,
                $company_override,
                $signature_id
            ]);
            $invoiceId = $pdo->lastInsertId();
        }

        // Save each item into invoice_amounts (permanent snapshot per invoice)
        if (!empty($_POST['items']) && is_array($_POST['items'])) {
            $insAmt = $pdo->prepare("
                INSERT INTO invoice_amounts
                    (invoice_id, invoice_no, invoice_date, item_id,
                     service_code, hsn_sac, description, uom,
                     qty, unit_price, discount, basic_amount,
                     cgst_percent, sgst_percent, igst_percent,
                     cgst_amount, sgst_amount, igst_amount,
                     tcs_percent, total)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            foreach ($_POST['items'] as $item) {
                if (empty($item['id']))
                    continue;
                $qty = floatval($item['qty'] ?? 1);
                $rate = floatval($item['rate'] ?? 0);
                $discount = floatval($item['discount'] ?? 0);
                $cgst_pct = floatval($item['cgst_percent'] ?? 0);
                $sgst_pct = floatval($item['sgst_percent'] ?? 0);
                $igst_pct = floatval($item['igst_percent'] ?? 0);
                // Use JS-computed hidden values if available, else recalculate
                $basic = isset($item['basic_amount']) && floatval($item['basic_amount']) > 0
                    ? floatval($item['basic_amount'])
                    : ($qty * $rate) - $discount;
                $cgst_amt = isset($item['cgst_amount']) && floatval($item['cgst_amount']) > 0
                    ? floatval($item['cgst_amount'])
                    : $basic * ($cgst_pct / 100);
                $sgst_amt = isset($item['sgst_amount']) && floatval($item['sgst_amount']) > 0
                    ? floatval($item['sgst_amount'])
                    : $basic * ($sgst_pct / 100);
                $igst_amt = isset($item['igst_amount']) && floatval($item['igst_amount']) > 0
                    ? floatval($item['igst_amount'])
                    : $basic * ($igst_pct / 100);
                $total = isset($item['total_amount']) && floatval($item['total_amount']) > 0
                    ? floatval($item['total_amount'])
                    : $basic + $cgst_amt + $sgst_amt + $igst_amt;
                $insAmt->execute([
                    $invoiceId,
                    $invoice_number,
                    $invoice_date,
                    intval($item['id']),
                    $item['service_code'] ?? '',
                    $item['hsn_sac'] ?? '',
                    $item['description'] ?? '',
                    $item['uom'] ?? '',
                    $qty,
                    $rate,
                    $discount,
                    $basic,
                    $cgst_pct,
                    $sgst_pct,
                    $igst_pct,
                    $cgst_amt,
                    $sgst_amt,
                    $igst_amt,
                    0,
                    $total
                ]);
            }
        }

        // After inserting all items, recalculate grand_total from invoice_amounts
        $gtStmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM invoice_amounts WHERE invoice_id=? AND (service_code!='PAYMENT' OR service_code IS NULL)");
        $gtStmt->execute([$invoiceId]);
        $invoiceGrandTotal = floatval($gtStmt->fetchColumn());

        // Get already received amount (preserved from before edit)
        $recvStmt = $pdo->prepare("SELECT COALESCE(amount_received,0) FROM invoices WHERE id=?");
        $recvStmt->execute([$invoiceId]);
        $alreadyReceived = floatval($recvStmt->fetchColumn());

        // Recalculate pending correctly
        $newPending = max(0, $invoiceGrandTotal - $alreadyReceived);

        // Determine correct status
        if ($alreadyReceived <= 0) {
            $newStatus = 'Unpaid';
        } elseif ($newPending <= 0.01) {
            $newStatus = 'Paid';
            $newPending = 0;
        } else {
            $newStatus = 'Partial';
        }

        $pdo->prepare("UPDATE invoices SET amount_pending=?, payment_status=? WHERE id=?")
            ->execute([$newPending, $newStatus, $invoiceId]);

        $pdo->commit();
        $msg = $isEdit ? 'Invoice updated successfully!' : 'Invoice saved successfully!';
        header("Location: index.php?view=invoices&success=" . urlencode($msg));
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $msg = $e->getMessage();
        if (strpos($msg, 'SQLSTATE[23000]') !== false || stripos($msg, 'Duplicate entry') !== false) {
            $msg = "Invoice number '{$invoice_number}' already exists. Please use a different invoice number.";
        }
        $error = "Error saving invoice: " . $msg;
    }
}

/* ================= FETCH CUSTOMERS & ITEMS ================= */
function ensureCustomerInvoiceAddressColumns(PDO $pdo): void
{
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

function buildAddressText(array $parts): string
{
    $clean = [];
    foreach ($parts as $part) {
        $value = trim((string) $part);
        if ($value !== '') {
            $clean[] = $value;
        }
    }
    return implode("\n", $clean);
}

ensureCustomerInvoiceAddressColumns($pdo);

try {
    $customers = $pdo->query("
        SELECT id, business_name AS customer, mobile, gstin, pan_no,
               title, first_name, last_name,
               address_line1, address_line2, address_city, address_state, pincode, address_country,
               billing_gstin, billing_pan, billing_phone,
               ship_address_line1, ship_address_line2, ship_city, ship_state, ship_pincode, ship_country,
               shipping_gstin, shipping_pan, shipping_phone
        FROM customers
        ORDER BY business_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $customers = []; }

try {
    $masterItems = $pdo->query("
        SELECT id, service_code,
               COALESCE(NULLIF(item_name,''), material_description, '') AS item_name,
               COALESCE(NULLIF(material_description,''), '') AS material_description,
               hsn_sac, uom, unit_price
        FROM items
        ORDER BY service_code ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $masterItems = []; }

try {
    $banks = $pdo->query("SELECT * FROM bank_details ORDER BY bank_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $banks = []; }

try {
    $signatures = $pdo->query("SELECT * FROM signatures ORDER BY signature_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $signatures = []; }

/* ── COMPANY DATA ── */
// Fetch ALL companies for dropdown
$allCompanies = $pdo->query("SELECT * FROM invoice_company ORDER BY company_name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$companyData = !empty($allCompanies) ? $allCompanies[0] : [];

// If editing and override exists, determine selected company
$existingOverride = [];
$selectedCompanyId = 0;
if ($isEdit && !empty($editInvoice['company_override'])) {
    $existingOverride = json_decode($editInvoice['company_override'], true) ?? [];
    // Try to match override to a company by name
    foreach ($allCompanies as $co) {
        if (($co['company_name'] ?? '') === ($existingOverride['company_name'] ?? '')) {
            $selectedCompanyId = (int) $co['id'];
            break;
        }
    }
} elseif (!empty($allCompanies)) {
    $selectedCompanyId = (int) $allCompanies[0]['id'];
    $existingOverride = $allCompanies[0];
}

// Use the selected company's row as base (important for company_logo when override is missing it)
if ($selectedCompanyId) {
    foreach ($allCompanies as $co) {
        if ((int) ($co['id'] ?? 0) === (int) $selectedCompanyId) {
            $companyData = $co;
            break;
        }
    }
}

$popupCompany = !empty($existingOverride) ? array_merge($companyData, $existingOverride) : $companyData;
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?= $isEdit ? 'Edit Invoice' : 'Create Invoice' ?></title>
    <link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0
        }

        body {
            font-family: 'Times New Roman', Times, serif;
            background: #f0f2f8;
            color: #1a1f2e;
            font-size: 15px
        }

        .content {
            margin-left: 220px;
            padding: 60px 16px 10px;
            min-height: 100vh;
            background: #f0f2f8
        }

        /* PAGE HEADER */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px
        }

        .page-header-left {
            display: flex;
            align-items: center;
            gap: 12px
        }

        .page-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #16a34a, #15803d);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 18px;
            box-shadow: 0 3px 10px rgba(22, 163, 74, .3)
        }

        .page-title {
            font-size: 19px;
            font-weight: 800;
            color: #1a1f2e
        }

        .page-sub {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 1px
        }

        /* CARDS */
        .form-card {
            background: #fff;
            border: 1px solid #e8ecf4;
            border-radius: 14px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .04);
            margin-bottom: 6px;
            overflow: hidden
        }

        .form-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 14px;
            border-bottom: 1px solid #f0f2f7;
            background: #fafbfd
        }

        .hdr-icon {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 14px;
            flex-shrink: 0
        }

        .form-card-header h3 {
            font-size: 15px;
            font-weight: 800;
            color: #1a1f2e;
            font-family: 'Times New Roman', Times, serif
        }

        .form-card-body {
            padding: 8px 14px
        }

        /* LABELS & INPUTS */
        label {
            display: block;
            font-size: 12px;
            font-weight: 900;
            color: #1f2937;
            text-transform: uppercase;
            letter-spacing: .6px;
            margin-bottom: 5px
        }

        .form-control,
        .form-select {
            width: 100%;
            padding: 9px 12px;
            border: 1.5px solid #e4e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Times New Roman', Times, serif;
            color: #1a1f2e;
            background: #fff;
            outline: none;
            transition: border-color .2s, box-shadow .2s;
            height: auto
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #16a34a;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(22, 163, 74, .08)
        }

        textarea.form-control {
            height: 48px;
            resize: vertical
        }

        .form-control-sm {
            padding: 5px 8px;
            font-size: 13px
        }

        .row>[class*=col] {
            margin-bottom: 6px
        }

        /* TWO-COL LAYOUT */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin-bottom: 6px
        }

        /* CUSTOMER ROW */
        .customer-row {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            margin-bottom: 12px
        }

        .customer-row .select-wrap {
            flex: 1
        }

        .select2-container--default .select2-selection--single {
            height: 38px;
            border: 1.5px solid #e4e8f0;
            border-radius: 8px;
            background: #fff;
            display: flex;
            align-items: center
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
            font-family: 'Times New Roman', Times, serif;
            font-size: 14px;
            color: #1a1f2e;
            padding-left: 10px
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px
        }

        /* Use orange theme for Select2 focus (prevents default blue focus ring) */
        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, .12);
        }

        .select2-search__field:focus {
            border-color: #f97316 !important;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, .12) !important;
            outline: none !important;
        }

        .select2-container:focus-within .select2-selection {
            border-color: #f97316 !important;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, .12) !important;
        }

        /* Select2 dropdown highlighted and selected options - orange theme */
        .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
            background-color: #f97316 !important;
            color: #fff !important;
        }

        .select2-container--default .select2-results__option--selected {
            background-color: #fff7ed !important;
            color: #f97316 !important;
            font-weight: 600;
        }

        /* BUTTONS */
        .btn-theme {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: #fff;
            border: none;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 700;
            font-family: 'Times New Roman', Times, serif;
            cursor: pointer;
            transition: all .2s;
            box-shadow: 0 3px 10px rgba(22, 163, 74, .25)
        }

        .btn-theme:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(22, 163, 74, .35)
        }

        .btn-outline-theme {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: #fff;
            border: 1.5px solid #e4e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Times New Roman', Times, serif;
            color: #374151;
            text-decoration: none;
            cursor: pointer;
            transition: all .2s
        }

        .btn-outline-theme:hover {
            border-color: #16a34a;
            color: #16a34a;
            background: #f0fdf4
        }

        .btn-add-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Times New Roman', Times, serif;
            transition: all .2s
        }

        .btn-add-item:hover {
            transform: translateY(-1px)
        }

        /* QUOTATION-STYLE POPUP THEME */
        .modal-box {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .18);
            font-family: 'Segoe UI', system-ui, sans-serif;
            width: 500px;
            max-width: 96vw;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 14px;
            border-bottom: 1px solid #f0f2f7;
            background: #fafbfd;
        }

        .modal-header-box h3 {
            font-size: 13px;
            font-weight: 800;
            color: #1a1f2e;
            margin: 0;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        .modal-close-btn {
            background: none;
            border: none;
            font-size: 18px;
            color: #9ca3af;
            cursor: pointer;
            line-height: 1;
        }

        .modal-search-wrap {
            padding: 8px 14px;
            border-bottom: 1px solid #f0f2f7;
        }

        .modal-search-inp {
            width: 100%;
            border: 1.5px solid #e4e8f0;
            border-radius: 7px;
            padding: 6px 10px;
            font-size: 12px;
            font-family: 'Segoe UI', system-ui, sans-serif;
            outline: none;
            background: #fff;
            color: #374151;
        }

        .modal-search-inp:focus {
            border-color: #f97316;
            box-shadow: none;
        }

        .sp-item {
            padding: 8px 14px;
            cursor: pointer;
            border-bottom: 1px solid #f9f9f9;
            transition: background .1s;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        .sp-item:hover {
            background: #fff7f0;
        }

        .sp-item-name {
            font-size: 13px;
            font-weight: 700;
            color: #1a1f2e;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        .sp-item-sub {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 1px;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        .sp-empty {
            padding: 20px;
            text-align: center;
            color: #9ca3af;
            font-size: 13px;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        .modal-footer-box {
            padding: 10px 14px;
            border-top: 1px solid #f0f2f7;
            background: #fafbfd;
            display: flex;
            gap: 6px;
        }

        .btn-plus {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 19px;
            cursor: pointer;
            transition: all .2s;
            flex-shrink: 0
        }

        .btn-plus:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(22, 163, 74, .3)
        }

        .btn-danger-sm {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px;
            height: 26px;
            background: #fef2f2;
            border: 1px solid #fca5a5;
            border-radius: 6px;
            color: #dc2626;
            cursor: pointer;
            font-size: 12px;
            transition: all .2s
        }

        .btn-danger-sm:hover {
            background: #dc2626;
            color: #fff
        }

        .bottom-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            padding: 14px 18px;
            border-top: 1px solid #f0f2f7;
            background: #fafbfd
        }

        .total-box {
            text-align: right;
            margin-top: 8px;
            font-size: 12px;
            padding: 8px 12px;
            background: #f8fafc;
            border-radius: 8px;
            border: 1px solid #e4e8f0;
            min-width: 240px;
        }

        .total-box .grand {
            font-size: 14px;
            font-weight: 800;
            color: #15803d;
            margin-top: 4px;
            background: #f0fdf4;
            padding: 4px 8px;
            border-radius: 7px;
            display: inline-block;
        }

        /* ITEM TABLE */
        .table-wrap {
            overflow-x: auto;
            max-height: 260px;
            overflow-y: auto
        }

        #itemTable {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            background: #fff
        }

        #itemTable col.sno-col {
            width: 5%
        }

        #itemTable col.item-col {
            width: 28%
        }

        #itemTable col.hsn-col {
            width: 8%
        }

        #itemTable col.qty-col {
            width: 7%
        }

        #itemTable col.unit-col {
            width: 7%
        }

        #itemTable col.rate-col {
            width: 8%
        }

        #itemTable col.discount-col {
            width: 8%
        }

        #itemTable col.taxable-col {
            width: 9%
        }

        #itemTable col.gst-col {
            width: 5%
        }

        #itemTable col.amount-col {
            width: 10%
        }

        #itemTable col.action-col {
            width: 3%
        }

        #itemTable thead tr:first-child th {
            background: #fff7f0;
            color: #f97316;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            padding: 5px 5px;
            border-bottom: 2px solid #fed7aa;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 1
        }

        #itemTable thead tr:last-child th {
            background: #fff7f0;
            color: #6b7280;
            font-size: 10.5px;
            padding: 5px 5px;
            border-bottom: 1px solid #e4e8f0;
            position: sticky;
            top: 28px;
            z-index: 1
        }

        #itemTable td {
            padding: 3px 5px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            color: #374151
        }

        #itemTable td.item-desc-cell {
            white-space: normal;
            word-break: break-word
        }

        #itemTable tbody tr:hover td {
            background: #fff7f0
        }

        #itemTable input.form-control {
            height: 26px;
            padding: 3px 5px;
            font-size: 11.5px;
            border-radius: 5px;
            border: 1.5px solid #e4e8f0;
            background: #fff;
            font-family: 'Segoe UI', system-ui, sans-serif
        }

        #itemTable input.form-control:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 2px rgba(249, 115, 22, .1)
        }

        #itemTable tfoot td {
            padding: 7px;
            font-weight: 700;
            font-size: 14px;
            border-top: 2px solid #e4e8f0;
            background: #f8fafc
        }

        /* MODAL */
        .modal-content {
            border: none;
            border-radius: 14px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, .15)
        }

        .modal-header {
            background: #fafbfc;
            border-bottom: 1px solid #e4e8f0;
            border-radius: 14px 14px 0 0;
            padding: 13px 18px
        }

        .modal-title {
            font-family: 'Times New Roman', Times, serif;
            font-size: 16px;
            font-weight: 700;
            color: #1a1f2e
        }

        .modal-body {
            padding: 16px 18px
        }

        .modal-footer {
            padding: 12px 18px;
            border-top: 1px solid #e4e8f0;
            background: #fafbfc;
            border-radius: 0 0 14px 14px
        }

        /* Prevent browser default blue focus ring in Add Company modal */
        #addCompanyModal input:focus {
            border-color: #f97316 !important;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, .12) !important;
            outline: none !important;
        }

        #addCompanyModal input {
            outline: none !important
        }

        #addCompanyModal button:focus {
            outline: none !important;
            box-shadow: none !important
        }

        /* ALERT */
        .alert-danger {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #dc2626;
            padding: 10px 14px;
            border-radius: 10px;
            margin-bottom: 12px;
            font-size: 14px
        }

        .form-check-input:checked {
            background-color: #16a34a;
            border-color: #16a34a
        }

        ::-webkit-scrollbar {
            width: 3px
        }

        ::-webkit-scrollbar-thumb {
            background: #e2e8f0;
            border-radius: 99px
        }

        @media(max-width:900px) {
            .two-col {
                grid-template-columns: 1fr
            }

            .content {
                margin-left: 0 !important;
                padding: 70px 12px 20px
            }
        }
    </style>
</head>

<body>
    <?php include 'sidebar.php'; ?>
    <?php include 'header.php'; ?>

    <div class="content">

        <?php if ($error): ?>
            <div class="alert-danger"><i class="fas fa-exclamation-circle"
                    style="margin-right:6px"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post">
            <!-- COMPANY OVERRIDE HIDDEN FIELDS (populated by popup) -->
            <input type="hidden" name="co_changed" id="co_changed" value="<?= !empty($existingOverride) ? '1' : '0' ?>">
            <input type="hidden" name="co_company_name" id="co_company_name"
                value="<?= htmlspecialchars($popupCompany['company_name'] ?? '') ?>">
            <input type="hidden" name="co_company_logo" id="co_company_logo"
                value="<?= htmlspecialchars($popupCompany['company_logo'] ?? '') ?>">
            <input type="hidden" name="co_address_line1" id="co_address_line1"
                value="<?= htmlspecialchars($popupCompany['address_line1'] ?? '') ?>">
            <input type="hidden" name="co_address_line2" id="co_address_line2"
                value="<?= htmlspecialchars($popupCompany['address_line2'] ?? '') ?>">
            <input type="hidden" name="co_city" id="co_city"
                value="<?= htmlspecialchars($popupCompany['city'] ?? '') ?>">
            <input type="hidden" name="co_state" id="co_state"
                value="<?= htmlspecialchars($popupCompany['state'] ?? '') ?>">
            <input type="hidden" name="co_pincode" id="co_pincode"
                value="<?= htmlspecialchars($popupCompany['pincode'] ?? '') ?>">
            <input type="hidden" name="co_phone" id="co_phone"
                value="<?= htmlspecialchars($popupCompany['phone'] ?? '') ?>">
            <input type="hidden" name="co_email" id="co_email"
                value="<?= htmlspecialchars($popupCompany['email'] ?? '') ?>">
            <input type="hidden" name="co_gst_number" id="co_gst_number"
                value="<?= htmlspecialchars($popupCompany['gst_number'] ?? '') ?>">
            <input type="hidden" name="co_cin_number" id="co_cin_number"
                value="<?= htmlspecialchars($popupCompany['cin_number'] ?? '') ?>">
            <input type="hidden" name="co_pan" id="co_pan" value="<?= htmlspecialchars($popupCompany['pan'] ?? '') ?>">
            <input type="hidden" name="co_website" id="co_website"
                value="<?= htmlspecialchars($popupCompany['website'] ?? '') ?>">

            <!-- PAGE HEADER -->
            <div class="page-header">
                <div class="page-header-left">
                    <div class="page-icon"><i class="fas fa-file-invoice"></i></div>
                    <div>
                        <div class="page-title"><?= $isEdit ? 'Edit Invoice' : 'Create Invoice' ?></div>
                        <div class="page-sub">
                            <?= $isEdit ? 'Update the details of invoice ' . htmlspecialchars($editInvoice['invoice_number'] ?? '') : 'Fill in the details to generate a new invoice' ?>
                        </div>
                    </div>
                </div>
                <div style="display:flex;gap:10px;align-items:center">
                    <a href="index.php?view=invoices" class="btn-outline-theme"><i class="fas fa-arrow-left"></i>
                        Back</a>
                    <?php if ($isEdit): ?><input type="hidden" name="edit_id" value="<?= $editId ?>"><?php endif; ?>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <div style="display:flex;flex-direction:column;gap:3px;">
                            <div style="display:flex;gap:6px;align-items:center;">
                                <select id="company_select" name="selected_company_id" style="min-width:260px;">
                                    <option value="">-- Select Company --</option>
                                    <?php foreach ($allCompanies as $co):
                                        $addr = trim(($co['address_line1'] ?? '') . ', ' . ($co['city'] ?? '') . ', ' . ($co['state'] ?? ''));
                                        $addr = trim($addr, ', ');
                                        ?>
                                        <option value="<?= $co['id'] ?>" <?= ($selectedCompanyId === (int) $co['id']) ? 'selected' : '' ?>
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
                                            data-addr="<?= htmlspecialchars($addr) ?>">
                                            <?= htmlspecialchars($co['company_name'] ?? 'Company #' . $co['id']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" onclick="openAddCompanyModal()" title="Add Company"
                                    style="width:34px;height:34px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:16px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="save_invoice" class="btn-theme"><i class="fas fa-save"></i>
                        <?= $isEdit ? 'Update Invoice' : 'Save Invoice' ?></button>
                </div>
            </div>

            <!-- MAIN 3-COLUMN GRID -->
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-bottom:6px">

                <!-- COL 1: CUSTOMER -->
                <div class="form-card" style="margin-bottom:0">
                    <div class="form-card-header">
                        <div class="hdr-icon" style="background:linear-gradient(135deg,#f97316,#fb923c)"><i class="fas fa-user"></i></div>
                        <h3>Customer</h3>
                    </div>
                    <div class="form-card-body">
                        <input type="hidden" name="customer" id="customer_hidden">
                        <input type="hidden" name="gstin" id="gstin" value="<?= $isEdit ? htmlspecialchars($editInvoice['gstin'] ?? '') : '' ?>">
                        <input type="hidden" name="pan" id="pan" value="<?= $isEdit ? htmlspecialchars($editInvoice['pan_no'] ?? '') : '' ?>">
                        <div style="margin-bottom:6px">
                            <label>Customer</label>
                            <div style="display:flex;gap:5px;align-items:center">
                                <select class="form-select" id="customer_select" style="font-size:13px;height:34px;padding:4px 8px;flex:1">
                                    <option value="">-- Select Customer --</option>
                                    <?php foreach ($customers as $c): ?>
                                        <?php
                                        $contactName = trim(
                                            implode(' ', array_filter([
                                                $c['title'] ?? '',
                                                $c['first_name'] ?? '',
                                                $c['last_name'] ?? ''
                                            ]))
                                        );
                                        $billingAddress = buildAddressText([
                                            $c['address_line1'] ?? '',
                                            $c['address_line2'] ?? '',
                                            $c['address_city'] ?? '',
                                            $c['address_state'] ?? '',
                                            $c['pincode'] ?? '',
                                            $c['address_country'] ?? ''
                                        ]);
                                        $shippingAddress = buildAddressText([
                                            $c['ship_address_line1'] ?? '',
                                            $c['ship_address_line2'] ?? '',
                                            $c['ship_city'] ?? '',
                                            $c['ship_state'] ?? '',
                                            $c['ship_pincode'] ?? '',
                                            $c['ship_country'] ?? ''
                                        ]);
                                        ?>
                                        <option value="<?= htmlspecialchars($c['customer']) ?>" <?= ($isEdit && $editInvoice['customer'] === $c['customer']) ? 'selected' : '' ?>
                                            data-contact="<?= htmlspecialchars($contactName) ?>"
                                            data-mobile="<?= htmlspecialchars($c['mobile'] ?? '') ?>"
                                            data-gstin="<?= htmlspecialchars($c['gstin'] ?? '') ?>"
                                            data-pan="<?= htmlspecialchars($c['pan_no'] ?? '') ?>"
                                            data-billing-address="<?= htmlspecialchars($billingAddress) ?>"
                                            data-shipping-address="<?= htmlspecialchars($shippingAddress) ?>"
                                            data-billing-gstin="<?= htmlspecialchars($c['billing_gstin'] ?? '') ?>"
                                            data-billing-pan="<?= htmlspecialchars($c['billing_pan'] ?? '') ?>"
                                            data-billing-phone="<?= htmlspecialchars($c['billing_phone'] ?? '') ?>"
                                            data-shipping-gstin="<?= htmlspecialchars($c['shipping_gstin'] ?? '') ?>"
                                            data-shipping-pan="<?= htmlspecialchars($c['shipping_pan'] ?? '') ?>"
                                            data-shipping-phone="<?= htmlspecialchars($c['shipping_phone'] ?? '') ?>">
                                            <?= htmlspecialchars($c['customer']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" onclick="goAddCustomer()" title="Add Customer" style="width:32px;height:34px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:7px;font-size:14px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-bottom:6px">
                            <div>
                                <label>Contact Person</label>
                                <input type="text" class="form-control" name="contact_person" id="contact_person" style="font-size:13px;padding:5px 8px" value="<?= $isEdit ? htmlspecialchars($editInvoice['contact_person'] ?? '') : '' ?>">
                            </div>
                            <div>
                                <label>Mobile</label>
                                <input type="text" class="form-control" name="mobile" id="mobile" style="font-size:13px;padding:5px 8px" value="<?= $isEdit ? htmlspecialchars($editInvoice['mobile'] ?? '') : '' ?>">
                            </div>
                        </div>
                        <div style="margin-bottom:6px">
                            <label>Executive</label>
                            <div style="display:flex;gap:5px;align-items:center">
                                <select class="form-control" name="executive_id" id="executive_id" style="flex:1;font-size:13px;padding:5px 8px">
                                    <option value="">-- Select Executive --</option>
                                    <?php foreach ($allExecs as $ex): ?>
                                        <option value="<?= $ex['id'] ?>" <?= ($isEdit && ($editInvoice['executive_id'] ?? '') == $ex['id']) ? 'selected' : '' ?>><?= htmlspecialchars($ex['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" onclick="openAddExecModal()" title="Add Executive" style="width:32px;height:34px;background:#f97316;color:#fff;border:none;border-radius:7px;font-size:14px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px">
                            <div>
                                <label>Billing Address</label>
                                <textarea class="form-control" name="billing_address" id="billing_address" style="height:52px;font-size:12px;padding:5px 8px"><?= $isEdit ? htmlspecialchars($editInvoice['billing_address'] ?? '') : '' ?></textarea>
                                <div class="form-check" style="margin-top:3px">
                                    <input class="form-check-input" type="checkbox" id="same_as_billing">
                                    <label class="form-check-label" for="same_as_billing" style="text-transform:none;letter-spacing:0;font-size:10px;color:#6b7280;font-weight:400">Same as Billing</label>
                                </div>
                            </div>
                            <div>
                                <label>Shipping Address</label>
                                <textarea class="form-control" name="shipping_address" id="shipping_address" style="height:52px;font-size:12px;padding:5px 8px;background:#f0fdf4;border-color:#bbf7d0;color:#374151"><?= $isEdit ? htmlspecialchars($editInvoice['shipping_address'] ?? '') : '' ?></textarea>
                            </div>
                        </div>
                        <input type="hidden" name="billing_gstin" id="billing_gstin" value="<?= $isEdit ? htmlspecialchars($editInvoice['billing_gstin'] ?? '') : '' ?>">
                        <input type="hidden" name="billing_pan" id="billing_pan" value="<?= $isEdit ? htmlspecialchars($editInvoice['billing_pan'] ?? '') : '' ?>">
                        <input type="hidden" name="ship_gstin" id="ship_gstin" value="<?= $isEdit ? htmlspecialchars($editInvoice['ship_gstin'] ?? '') : '' ?>">
                        <input type="hidden" name="ship_pan" id="ship_pan" value="<?= $isEdit ? htmlspecialchars($editInvoice['ship_pan'] ?? '') : '' ?>">
                        <input type="hidden" name="billing_phone" id="billing_phone" value="<?= $isEdit ? htmlspecialchars($editInvoice['billing_phone'] ?? '') : '' ?>">
                        <input type="hidden" name="ship_phone_num" id="ship_phone_num" value="<?= $isEdit ? htmlspecialchars($editInvoice['ship_phone_num'] ?? '') : '' ?>">
                    </div>
                </div>

                <!-- COL 2: DOCUMENT DETAILS -->
                <div class="form-card" style="margin-bottom:0">
                    <div class="form-card-header">
                        <div class="hdr-icon" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed)"><i class="fas fa-file-alt"></i></div>
                        <h3>Document Details</h3>
                    </div>
                    <div class="form-card-body">
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:5px;margin-bottom:6px">
                            <div>
                                <label>Invoice No.</label>
                                <input type="text" name="invoice_number" class="form-control" style="font-size:13px;padding:5px 8px"
                                    value="<?= $isEdit ? htmlspecialchars($editInvoice['invoice_number'] ?? '') : htmlspecialchars($nextInvoiceNo ?? '') ?>" required>
                                <?php if (!$isEdit && $prevInvoiceNo): ?><small style="font-size:10px;color:#9ca3af;font-style:italic">Last: <?= htmlspecialchars($prevInvoiceNo) ?></small><?php endif; ?>
                            </div>
                            <div>
                                <label>PO Number</label>
                                <input type="text" name="po_number" class="form-control" style="font-size:13px;padding:5px 8px"
                                    value="<?= $isEdit ? htmlspecialchars($editInvoice['po_number'] ?? '') : '' ?>" required>
                            </div>
                            <div>
                                <label>Reference</label>
                                <input type="text" name="reference" class="form-control" style="font-size:13px;padding:5px 8px"
                                    value="<?= $isEdit ? htmlspecialchars($editInvoice['reference'] ?? '') : '' ?>">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px;margin-bottom:6px">
                            <div>
                                <label>Invoice Date</label>
                                <input type="date" name="invoice_date" class="form-control" style="font-size:13px;padding:5px 8px"
                                    value="<?= $isEdit ? htmlspecialchars($editInvoice['invoice_date'] ?? date('Y-m-d')) : date('Y-m-d') ?>" required>
                            </div>
                            <div>
                                <label>Due Date</label>
                                <input type="date" name="due_date" class="form-control" style="font-size:13px;padding:5px 8px"
                                    value="<?= $isEdit ? htmlspecialchars($editInvoice['due_date'] ?? date('Y-m-d')) : date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div style="margin-bottom:6px">
                            <label>Bank Details</label>
                            <div style="display:flex;gap:5px">
                                <select name="bank_id" id="bank_select" class="form-select" style="font-size:13px;padding:5px 8px">
                                    <option value="">-- Select Bank --</option>
                                    <?php foreach ($banks as $bank): ?>
                                        <option value="<?= $bank['id']; ?>" <?= ($isEdit && (string)($editInvoice['bank_id'] ?? '') === (string)$bank['id']) ? 'selected' : '' ?>><?= htmlspecialchars($bank['bank_name'] . (!empty($bank['branch']) ? ' - ' . $bank['branch'] : '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" style="width:32px;height:34px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:7px;font-size:14px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center" data-bs-toggle="modal" data-bs-target="#addBankModal" title="Add Bank"><i class="fas fa-plus"></i></button>
                            </div>
                        </div>
                        <div>
                            <label>Authorised Signature</label>
                            <div style="display:flex;gap:5px;align-items:center">
                                <select name="signature_id" id="signature_select" class="form-select" style="font-size:13px;padding:5px 8px">
                                    <option value="">-- Select Signature --</option>
                                    <?php foreach ($signatures as $sig): ?>
                                        <option value="<?= $sig['id']; ?>" data-path="<?= htmlspecialchars($sig['file_path']); ?>" <?= ($isEdit && (string)($editInvoice['signature_id'] ?? '') === (string)$sig['id']) ? 'selected' : '' ?>><?= htmlspecialchars($sig['signature_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" style="width:32px;height:34px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;border:none;border-radius:7px;font-size:14px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center" data-bs-toggle="modal" data-bs-target="#addSignatureModal" title="Add Signature"><i class="fas fa-plus"></i></button>
                                <img id="signature_preview" src="" style="max-height:32px;max-width:80px;object-fit:contain;display:none;border:1px dashed #ccc;border-radius:4px;padding:2px">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- COL 3: ACCOUNTS UPDATE -->
                <div class="form-card" style="margin-bottom:0">
                    <div class="form-card-header">
                        <div class="hdr-icon" style="background:linear-gradient(135deg,#0ea5e9,#0284c7)"><i class="fas fa-book"></i></div>
                        <h3>Accounts Update</h3>
                    </div>
                    <div class="form-card-body">
                        <div style="margin-bottom:6px">
                            <label>Customer Ledger</label>
                            <input type="text" name="party_ledger" id="party_ledger" class="form-control" style="font-size:13px;padding:5px 8px" placeholder="e.g. CGST-Out" value="<?= $isEdit ? htmlspecialchars($editInvoice['party_ledger'] ?? '') : '' ?>">
                        </div>
                        <div style="margin-bottom:6px">
                            <label>Income Ledger</label>
                            <input type="text" name="income_ledger" id="income_ledger" class="form-control" style="font-size:13px;padding:5px 8px" placeholder="e.g. ELTRIVE" value="<?= $isEdit ? htmlspecialchars($editInvoice['income_ledger'] ?? '') : '' ?>">
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px">
                            <div>
                                <label>Voucher No.</label>
                                <input type="text" name="voucher_number" id="voucher_number" class="form-control" style="font-size:13px;padding:5px 8px"
                                    value="<?= $isEdit ? htmlspecialchars($editInvoice['voucher_number'] ?? '') : htmlspecialchars((string)$nextVoucherNo) ?>" placeholder="e.g. 58">
                            </div>
                            <div>
                                <label>Voucher Date</label>
                                <input type="date" name="voucher_date" id="voucher_date" class="form-control" style="font-size:13px;padding:5px 8px"
                                    value="<?= $isEdit ? htmlspecialchars($editInvoice['voucher_date'] ?? date('Y-m-d')) : date('Y-m-d') ?>">
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /3-col grid -->

            <!-- ITEM LIST -->
            <div class="form-card">
                <div class="form-card-header" style="justify-content:space-between">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div class="hdr-icon" style="background:linear-gradient(135deg,#16a34a,#15803d)"><i
                                class="fas fa-list"></i></div>
                        <h3>Item List</h3>
                    </div>
                    <div style="display:flex;gap:8px">
                        <button type="button" class="btn-add-item" data-bs-toggle="modal"
                            data-bs-target="#selectItemModal">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                    </div>
                </div>
                <div class="table-wrap">
                    <table id="itemTable">
                        <colgroup>
                            <col class="sno-col">
                            <col class="item-col">
                            <col class="hsn-col">
                            <col class="qty-col">
                            <col class="unit-col">
                            <col class="rate-col">
                            <col class="discount-col">
                            <col class="taxable-col">
                            <col class="gst-col">
                            <col class="gst-col">
                            <col class="gst-col">
                            <col class="amount-col">
                            <col class="action-col">
                        </colgroup>
                        <thead>
                            <tr>
                                <th>S.No</th>
                                <th>Item &amp; Description</th>
                                <th>HSN/SAC</th>
                                <th>Qty</th>
                                <th>Unit</th>
                                <th>Rate (₹)</th>
                                <th>Discount (₹)</th>
                                <th>Taxable (₹)</th>
                                <th colspan="3" style="text-align:center">GST (%)</th>
                                <th>Amt (₹)</th>
                                <th></th>
                            </tr>
                            <tr>
                                <th colspan="8"></th>
                                <th>CGST</th>
                                <th>SGST</th>
                                <th>IGST</th>
                                <th colspan="2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($isEdit && !empty($editItems)): ?>
                                <?php foreach ($editItems as $i => $item):
                                    $idx = $i + 1;
                                    $basic = floatval($item['basic_amount']);
                                    $cgstAmt = floatval($item['cgst_amount']);
                                    $sgstAmt = floatval($item['sgst_amount']);
                                    $igstAmt = floatval($item['igst_amount']);
                                    $total = floatval($item['total']);
                                    $itemName = trim($item['item_name'] ?? '');
                                    $descText = trim($item['description'] ?? '');
                                    ?>
                                    <tr>
                                        <td class="text-center"><?= $idx ?></td>
                                        <td class="item-desc-cell">
                                            <?php if ($itemName !== ''): ?>
                                                <strong><?= htmlspecialchars($itemName) ?></strong>
                                                <?php if ($descText !== '' && $descText !== $itemName): ?><br><?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($descText !== ''): ?>
                                                <small class="text-muted"><?= nl2br(htmlspecialchars($descText)) ?></small>
                                            <?php endif; ?>
                                            <input type="hidden" name="items[<?= $idx ?>][id]"
                                                value="<?= (int) $item['item_id'] ?>">
                                            <input type="hidden" name="items[<?= $idx ?>][service_code]"
                                                value="<?= htmlspecialchars($item['service_code'] ?? '') ?>">
                                            <input type="hidden" name="items[<?= $idx ?>][item_name]"
                                                value="<?= htmlspecialchars($itemName) ?>">
                                            <input type="hidden" name="items[<?= $idx ?>][description]"
                                                value="<?= htmlspecialchars($item['description'] ?? '') ?>">
                                            <input type="hidden" name="items[<?= $idx ?>][hsn_sac]"
                                                value="<?= htmlspecialchars($item['hsn_sac'] ?? '') ?>">
                                            <input type="hidden" name="items[<?= $idx ?>][uom]"
                                                value="<?= htmlspecialchars($item['uom'] ?? '') ?>">
                                            <input type="hidden" class="hidden-basic" name="items[<?= $idx ?>][basic_amount]"
                                                value="<?= $basic ?>">
                                            <input type="hidden" class="hidden-cgst-amt" name="items[<?= $idx ?>][cgst_amount]"
                                                value="<?= $cgstAmt ?>">
                                            <input type="hidden" class="hidden-sgst-amt" name="items[<?= $idx ?>][sgst_amount]"
                                                value="<?= $sgstAmt ?>">
                                            <input type="hidden" class="hidden-igst-amt" name="items[<?= $idx ?>][igst_amount]"
                                                value="<?= $igstAmt ?>">
                                            <input type="hidden" class="hidden-total" name="items[<?= $idx ?>][total_amount]"
                                                value="<?= $total ?>">
                                        </td>
                                        <td class="text-center"><?= htmlspecialchars($item['hsn_sac'] ?? '-') ?></td>
                                        <td><input type="number" class="form-control form-control-sm qty text-end"
                                                name="items[<?= $idx ?>][qty]" value="<?= $item['qty'] ?>" min="1" step="0.001">
                                        </td>
                                        <td class="text-center"><?= htmlspecialchars($item['uom'] ?? '-') ?></td>
                                        <td><input type="number" class="form-control form-control-sm rate text-end"
                                                name="items[<?= $idx ?>][rate]" value="<?= $item['unit_price'] ?>" step="0.01">
                                        </td>
                                        <td><input type="number" class="form-control form-control-sm discount text-end"
                                                name="items[<?= $idx ?>][discount]" value="<?= $item['discount'] ?>"
                                                step="0.01"></td>
                                        <td class="taxable text-end fw-bold"><?= number_format($basic, 2) ?></td>
                                        <td><input type="number"
                                                class="form-control form-control-sm gst-rate text-end cgst-rate"
                                                name="items[<?= $idx ?>][cgst_percent]" value="<?= $item['cgst_percent'] ?>"
                                                step="0.01" min="0"></td>
                                        <td><input type="number"
                                                class="form-control form-control-sm gst-rate text-end sgst-rate"
                                                name="items[<?= $idx ?>][sgst_percent]" value="<?= $item['sgst_percent'] ?>"
                                                step="0.01" min="0"></td>
                                        <td><input type="number"
                                                class="form-control form-control-sm gst-rate text-end igst-rate"
                                                name="items[<?= $idx ?>][igst_percent]" value="<?= $item['igst_percent'] ?>"
                                                step="0.01" min="0"></td>
                                        <td class="amount text-end fw-bold"><?= number_format($total, 2) ?></td>
                                        <td class="text-center">
                                            <button type="button" class="btn-danger-sm" onclick="removeRow(this)"><i
                                                    class="fas fa-times"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot></tfoot>
                    </table>
                </div>
            </div>
            <div class="bottom-actions" style="justify-content:flex-end;flex-wrap:wrap">
                <div class="total-box">
                    <div style="color:#6b7280">Taxable : <span id="totalTaxable">₹ 0.00</span></div>
                    <div style="color:#6b7280">CGST : <span id="totalCgst">₹ 0.00</span></div>
                    <div style="color:#6b7280">SGST : <span id="totalSgst">₹ 0.00</span></div>
                    <div style="color:#6b7280">IGST : <span id="totalIgst">₹ 0.00</span></div>
                    <div style="color:#6b7280">Tax Amount : <span id="totalTax">₹ 0.00</span></div>
                    <div id="roundOffRow" style="display:none;color:#6b7280">
                        <span style="margin-right:4px;">🗑</span> Round off : <span id="roundOffAmt">₹ 0.00</span>
                    </div>
                    <div class="grand">Grand Total : <span id="grandTotal">₹ 0.00</span></div>
                    <div style="margin-top:8px;">
                        <button type="button" id="addRoundOffBtn" onclick="toggleRoundOff()"
                            style="padding:5px 13px;border-radius:7px;border:1.5px solid #16a34a;background:#f0fdf4;color:#16a34a;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;">
                            <i class="fas fa-plus"></i> Add Round Off
                        </button>
                    </div>
                </div>
            </div>
<div style="display:flex; justify-content:flex-start; gap:10px; margin-top:20px; flex-wrap:wrap; align-items:center;">

    <button type="submit" name="save_invoice" class="btn-theme" style="padding:10px 18px;">
        <i class="fas fa-save"></i>
        <?= $isEdit ? 'Update Invoice' : 'Save Invoice' ?>
    </button>

    <a href="index.php?view=invoices" class="btn-outline-theme" style="padding:10px 18px; text-decoration:none;">
        <i class="fas fa-times"></i>
        Cancel
    </a>

</div>

<script>
function toggleRoundOff() {
    const row = document.getElementById('roundOffRow');
    const btn = document.getElementById('addRoundOffBtn');
    const grandEl = document.getElementById('grandTotal');
    const isHidden = row.style.display === 'none' || row.style.display === '';
    if (isHidden) {
        const grandVal = parseFloat(grandEl.textContent.replace(/[₹,\s]/g, '')) || 0;
        const paise    = parseFloat((grandVal - Math.floor(grandVal)).toFixed(2));
        if (paise === 0) { alert('No paise to round off. Grand Total is already a whole number.'); return; }
        const roundOff = -paise;
        document.getElementById('roundOffAmt').textContent = '₹ ' + roundOff.toFixed(2);
        grandEl.textContent = '₹ ' + (grandVal + roundOff).toFixed(2);
        row.style.display = '';
        btn.style.background = '#fef2f2'; btn.style.borderColor = '#dc2626'; btn.style.color = '#dc2626';
        btn.innerHTML = '<i class="fas fa-times"></i> Remove Round Off';
    } else {
        const grandVal = parseFloat(grandEl.textContent.replace(/[₹,\s]/g, '')) || 0;
        const roundVal = parseFloat(document.getElementById('roundOffAmt').textContent.replace(/[₹,\s]/g, '')) || 0;
        grandEl.textContent = '₹ ' + (grandVal - roundVal).toFixed(2);
        row.style.display = 'none';
        btn.style.background = '#f0fdf4'; btn.style.borderColor = '#16a34a'; btn.style.color = '#16a34a';
        btn.innerHTML = '<i class="fas fa-plus"></i> Add Round Off';
    }
}
</script>
        </form>
        <!-- ADD BANK MODAL -->
        <div class="modal fade" id="addBankModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form id="addBankForm">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-university"
                                    style="color:#16a34a;margin-right:8px"></i>Add Bank</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3"><label>Bank Name</label><input type="text" name="bank_name"
                                    class="form-control" placeholder="e.g. State Bank of India"></div>
                            <div class="mb-3"><label>Branch</label><input type="text" name="branch" class="form-control"
                                    placeholder="Branch name"></div>
                            <div class="mb-3"><label>Account No</label><input type="text" name="account_no"
                                    class="form-control" placeholder="Account number"></div>
                            <div class="mb-3"><label>IFSC Code</label><input type="text" name="ifsc_code"
                                    class="form-control" placeholder="e.g. SBIN0001234"></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" id="saveBankBtn" class="btn-theme"><i class="fas fa-save"></i> Save
                                Bank</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>


        <!-- SELECT ITEM MODAL -->
        <div class="modal fade" id="selectItemModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content"
                    style="border:none;border-radius:18px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.18);">

                    <div class="modal-header-box" style="padding:18px 22px;">
                        <h3><i class="fas fa-boxes" style="color:#f97316;margin-right:6px"></i>Item Library</h3>
                        <button class="modal-close-btn" type="button" data-bs-dismiss="modal">✕</button>
                    </div>
                    <div class="modal-search-wrap" style="padding:14px 18px;border-top:1px solid #f0f2f7;border-bottom:1px solid #f0f2f7;background:#fafbfd;">
                        <input class="modal-search-inp" id="itemSearch" type="text" placeholder="Search by name or HSN...">
                    </div>
                    <div id="itemSelectList" style="max-height:380px;overflow-y:auto;border-top:1px solid #f0f2f7">
                        <?php foreach ($masterItems as $item): ?>
                            <?php
                            $parts = [];
                            if (!empty($item['uom'])) $parts[] = $item['uom'];
                            if (!empty($item['hsn_sac'])) $parts[] = 'HSN: ' . $item['hsn_sac'];
                            if ((float) ($item['unit_price'] ?? 0) > 0) $parts[] = 'Rs ' . number_format((float) $item['unit_price'], 2);
                            $subline = implode(' | ', $parts);
                            ?>
                            <div class="sp-item item-popup-row"
                                data-search="<?= htmlspecialchars(strtolower(trim(($item['service_code'] ?? '') . ' ' . ($item['item_name'] ?? '') . ' ' . ($item['material_description'] ?? '') . ' ' . ($item['hsn_sac'] ?? '')))) ?>"
                                onclick='selectItem(<?= (int) $item['id'] ?>, <?= htmlspecialchars(json_encode($item['service_code'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($item['item_name'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($item['hsn_sac'] ?? ''), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($item['uom'] ?? ''), ENT_QUOTES) ?>, <?= (float) ($item['unit_price'] ?? 0) ?>, <?= htmlspecialchars(json_encode($item['material_description'] ?? ''), ENT_QUOTES) ?>)'
                                style="cursor:pointer;">
                                <div class="sp-item-name"><?= htmlspecialchars($item['item_name'] ?? '') ?></div>
                                <div class="sp-item-sub">
                                    <?= htmlspecialchars($subline) ?>
                                    <?= !empty($item['service_code']) ? ' | Code: ' . htmlspecialchars($item['service_code']) : '' ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div id="itemNoResult" class="sp-empty" style="display:none;">No items found.</div>
                    </div>
                    <div class="modal-footer-box" style="justify-content:space-between;padding:14px 18px;background:#fafbfd;border-top:1px solid #f0f2f7;">
                        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:#1a2940;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit" onclick="bootstrap.Modal.getOrCreateInstance(document.getElementById('selectItemModal')).hide(); goAddStock();"><i class="fas fa-plus"></i> Create New Item</button>
                        <button type="button" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit" data-bs-dismiss="modal"><i class="fas fa-check"></i> Done</button>
                    </div>
                </div>
            </div>
        </div>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

        <script>
            // Form validation
            document.querySelector('form').addEventListener('submit', function (e) {
                // Validate customer selected
                if (!$('#customer_select').val()) {
                    e.preventDefault();
                    alert('Please select a Customer.');
                    $('#customer_select').next('.select2-container').find('.select2-selection').css('border-color', '#dc2626');
                    return;
                }
                // Validate at least one item
                if (document.querySelectorAll('#itemTable tbody tr').length === 0) {
                    e.preventDefault();
                    alert('Please add at least one Item.');
                    return;
                }
            });

            // Customer Select2
            $(document).ready(function () {
                $('#customer_select').select2({
                    placeholder: "-- Select Customer --",
                    allowClear: true
                });

                <?php if ($isEdit && $editInvoice): ?>
                    // Edit mode: pre-set customer_hidden and trigger field sync
                    $('#customer_hidden').val(<?= json_encode($editInvoice['customer'] ?? '') ?>);

                    // Uncheck "Same as Billing" if addresses differ
                    const billingAddr = <?= json_encode($editInvoice['billing_address'] ?? '') ?>;
                    const shippingAddr = <?= json_encode($editInvoice['shipping_address'] ?? '') ?>;
                    if (billingAddr.trim() !== shippingAddr.trim()) {
                        $('#same_as_billing').prop('checked', false);
                        lockShippingFields(false);
                        $('#shipping_address').css({ 'background': '', 'border-color': '', 'color': '' });
                    }
                <?php endif; ?>
            });

            $('#customer_select').on('change', function () {
                const selected = $(this).find('option:selected');
                $('#customer_hidden').val(selected.val() || '');

                if (!selected.val()) {
                    $('#contact_person, #mobile, #gstin, #pan, #billing_gstin, #billing_pan, #billing_phone, #billing_address, #shipping_address, #ship_gstin, #ship_pan, #ship_phone_num').val('');
                    $('#same_as_billing').prop('checked', false);
                    lockShippingFields(false);
                    return;
                }

                $('#contact_person').val(selected.data('contact'));
                $('#mobile').val(selected.data('mobile'));
                $('#gstin').val(selected.data('gstin'));
                $('#billing_gstin').val(selected.data('billing-gstin') || selected.data('gstin'));
                $('#billing_pan').val((selected.data('billing-pan') || selected.data('pan') || '').toUpperCase());
                $('#billing_phone').val(selected.data('billing-phone') || selected.data('mobile'));
                $('#pan').val((selected.data('pan') || '').toUpperCase());

                const billingAddress = (selected.data('billing-address') || '').trim();
                const shippingAddress = (selected.data('shipping-address') || '').trim();
                const shippingGstin = (selected.data('shipping-gstin') || '').trim();
                const shippingPan = (selected.data('shipping-pan') || '').trim();
                const shippingPhone = (selected.data('shipping-phone') || '').trim();
                const hasSeparateShipping = shippingAddress !== '' || shippingGstin !== '' || shippingPan !== '' || shippingPhone !== '';

                $('#billing_address').val(composeAddressBlock(billingAddress, $('#billing_gstin').val(), $('#billing_pan').val(), $('#billing_phone').val()));

                if (hasSeparateShipping) {
                    $('#same_as_billing').prop('checked', false);
                    lockShippingFields(false);
                    $('#ship_gstin').val(shippingGstin || $('#billing_gstin').val());
                    $('#ship_pan').val((shippingPan || $('#billing_pan').val()).toUpperCase());
                    $('#ship_phone_num').val(shippingPhone || $('#billing_phone').val());
                    $('#shipping_address').val(composeAddressBlock(shippingAddress, $('#ship_gstin').val(), $('#ship_pan').val(), $('#ship_phone_num').val()));
                } else {
                    $('#same_as_billing').prop('checked', false);
                    $('#shipping_address').val('');
                    $('#ship_gstin').val('');
                    $('#ship_pan').val('');
                    $('#ship_phone_num').val('');
                    lockShippingFields(false);
                }
            });

            // ── Same As Billing: full sync (address + gstin + phone) ──────────────────
            function composeAddressBlock(address, gstin, pan, phone) {
                const lines = String(address || '')
                    .split(/\r?\n/)
                    .map(function (line) { return line.trim(); })
                    .filter(Boolean);

                if (gstin) lines.push('GSTIN: ' + String(gstin).trim().toUpperCase());
                if (pan) lines.push('PAN: ' + String(pan).trim().toUpperCase());
                if (phone) lines.push('Phone: ' + String(phone).trim());

                return lines.join('\n');
            }

            function lockShippingFields(lock) {
                const shippingFields = ['#shipping_address', '#ship_gstin', '#ship_pan', '#ship_phone_num'];
                shippingFields.forEach(function (sel) {
                    const el = $(sel);
                    if (lock) {
                        el.prop('readonly', true)
                            .css({ 'background': '#f0fdf4', 'border-color': '#bbf7d0', 'color': '#374151', 'cursor': 'not-allowed' });
                    } else {
                        el.prop('readonly', false)
                            .css({ 'background': '', 'border-color': '', 'color': '', 'cursor': '' });
                    }
                });
            }

            function syncShippingFromBilling() {
                $('#shipping_address').val(composeAddressBlock($('#billing_address').val(), $('#billing_gstin').val(), $('#billing_pan').val(), $('#billing_phone').val()));
                $('#ship_gstin').val($('#billing_gstin').val());
                $('#ship_pan').val($('#billing_pan').val());
                $('#ship_phone_num').val($('#billing_phone').val());
            }

            $('#same_as_billing').on('change', function () {
                if (this.checked) {
                    syncShippingFromBilling();
                    lockShippingFields(true);
                } else {
                    lockShippingFields(false);
                    $('#shipping_address').css({ 'background': '', 'border-color': '', 'color': '' });
                    $('#ship_gstin').css({ 'background': '', 'border-color': '', 'color': '' });
                    $('#ship_pan').css({ 'background': '', 'border-color': '', 'color': '' });
                    $('#ship_phone_num').css({ 'background': '', 'border-color': '', 'color': '' });
                }
            });

            // Live sync when billing fields change and checkbox is checked
            $('#billing_address').on('input', function () {
                if ($('#same_as_billing').is(':checked')) {
                    $('#shipping_address').val(composeAddressBlock(this.value, $('#billing_gstin').val(), $('#billing_pan').val(), $('#billing_phone').val()));
                }
            });
            $('#billing_gstin').on('input', function () {
                if ($('#same_as_billing').is(':checked')) {
                    $('#ship_gstin').val(this.value.toUpperCase());
                    $('#shipping_address').val(composeAddressBlock($('#billing_address').val(), this.value, $('#billing_pan').val(), $('#billing_phone').val()));
                }
            });
            $('#billing_pan').on('input', function () {
                if ($('#same_as_billing').is(':checked')) {
                    $('#ship_pan').val(this.value.toUpperCase());
                    $('#shipping_address').val(composeAddressBlock($('#billing_address').val(), $('#billing_gstin').val(), this.value, $('#billing_phone').val()));
                }
            });
            $('#billing_phone').on('input', function () {
                if ($('#same_as_billing').is(':checked')) {
                    $('#ship_phone_num').val(this.value);
                    $('#shipping_address').val(composeAddressBlock($('#billing_address').val(), $('#billing_gstin').val(), $('#billing_pan').val(), this.value));
                }
            });

            // Run lock on page load only if user has enabled same-as-billing
            $(document).ready(function () {
                if ($('#same_as_billing').is(':checked')) lockShippingFields(true);
            });

            // ── Inline format validation (non-mandatory) ────────────────────────────────
            const GSTIN_RE_INV = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/;
            const PHONE_RE_INV = /^[6-9]\d{9}$/;
            const NA_RE_INV = /^(NA|N\/A)$/i;

            function showHint(hintId, msg, isError) {
                const el = document.getElementById(hintId);
                if (!el) return;
                el.textContent = msg;
                el.style.display = 'block';
                el.style.color = isError ? '#dc2626' : '#16a34a';
                el.style.fontWeight = '600';
            }
            function clearHint(hintId) {
                const el = document.getElementById(hintId);
                if (el) { el.style.display = 'none'; el.textContent = ''; }
            }

            function validateGstinField(inputId, hintId) {
                const val = document.getElementById(inputId).value.trim().toUpperCase();
                document.getElementById(inputId).value = val;
                if (!val || NA_RE_INV.test(val)) { clearHint(hintId); document.getElementById(inputId).style.borderColor = ''; return; }
                if (GSTIN_RE_INV.test(val)) {
                    showHint(hintId, '✓ Valid GSTIN', false);
                    document.getElementById(inputId).style.borderColor = '#16a34a';
                } else {
                    showHint(hintId, '✗ Invalid GSTIN format (e.g. 22AAAAA0000A1Z5)', true);
                    document.getElementById(inputId).style.borderColor = '#dc2626';
                }
            }
            function validatePhoneField(inputId, hintId) {
                const val = document.getElementById(inputId).value.trim();
                if (!val || NA_RE_INV.test(val)) { clearHint(hintId); document.getElementById(inputId).style.borderColor = ''; return; }
                if (PHONE_RE_INV.test(val)) {
                    showHint(hintId, '✓ Valid phone', false);
                    document.getElementById(inputId).style.borderColor = '#16a34a';
                } else {
                    showHint(hintId, '✗ Must be a valid 10-digit Indian mobile', true);
                    document.getElementById(inputId).style.borderColor = '#dc2626';
                }
            }

            document.getElementById('billing_gstin')?.addEventListener('blur', function () { validateGstinField('billing_gstin', 'billing_gstin_hint'); });
            document.getElementById('ship_gstin')?.addEventListener('blur', function () { validateGstinField('ship_gstin', 'ship_gstin_hint'); });
            document.getElementById('billing_phone')?.addEventListener('blur', function () { validatePhoneField('billing_phone', 'billing_phone_hint'); });
            document.getElementById('ship_phone_num')?.addEventListener('blur', function () { validatePhoneField('ship_phone_num', 'ship_phone_hint'); });

            // Item handling — use a unique timestamp-based key so gaps never occur
            // even after rows are removed and new ones added.
            let itemCounter = Date.now();

            // Debounce guard to prevent double-add on fast clicks
            let _lastAddedId = null, _lastAddedTime = 0;

            function selectItem(id, code, itemName, hsn, uom, rate, materialDesc) {
                // Debounce: ignore duplicate fires within 400ms
                const now = Date.now();
                if (_lastAddedId === id && (now - _lastAddedTime) < 400) return;
                _lastAddedId = id; _lastAddedTime = now;

                // description saved to DB = materialDesc if available, else itemName
                const savedDesc = (materialDesc && materialDesc.trim()) ? materialDesc : itemName;

                const tbody = document.querySelector('#itemTable tbody');
                const row = document.createElement('tr');
                const idx = itemCounter++;
                const displayNo = tbody.querySelectorAll('tr').length + 1;

                // Build display: bold item name + smaller description below
                const displayHtml = (itemName ? `<strong>${itemName}</strong>` : '') +
                    (itemName && savedDesc && savedDesc !== itemName ? `<br><small class="text-muted" style="font-size:11px;">${savedDesc.replace(/\\n/g, '<br>')}</small>` : '');

                row.innerHTML = `
        <td class="text-center">${displayNo}</td>
        <td class="item-desc-cell">
            ${displayHtml}
            <input type="hidden" name="items[${idx}][id]"           value="${id}">
            <input type="hidden" name="items[${idx}][service_code]" value="${code}">
            <input type="hidden" name="items[${idx}][item_name]"    value="${itemName}">
            <input type="hidden" name="items[${idx}][description]"  value="${savedDesc}">
            <input type="hidden" name="items[${idx}][hsn_sac]"      value="${hsn}">
            <input type="hidden" name="items[${idx}][uom]"          value="${uom}">
            <input type="hidden" name="items[${idx}][basic_amount]"  class="hidden-basic"    value="0">
            <input type="hidden" name="items[${idx}][cgst_amount]"   class="hidden-cgst-amt" value="0">
            <input type="hidden" name="items[${idx}][sgst_amount]"   class="hidden-sgst-amt" value="0">
            <input type="hidden" name="items[${idx}][igst_amount]"   class="hidden-igst-amt" value="0">
            <input type="hidden" name="items[${idx}][total_amount]"  class="hidden-total"    value="0">
        </td>
        <td class="text-center">${hsn || '-'}</td>
        <td><input type="number" class="form-control form-control-sm qty text-end"      name="items[${idx}][qty]"          value="1"     min="1" step="0.001"></td>
        <td class="text-center">${uom || '-'}</td>
        <td><input type="number" class="form-control form-control-sm rate text-end"     name="items[${idx}][rate]"         value="${rate}"        step="0.01"></td>
        <td><input type="number" class="form-control form-control-sm discount text-end" name="items[${idx}][discount]"     value="0"             step="0.01"></td>
        <td class="taxable text-end fw-bold">0.00</td>
        <td><input type="number" class="form-control form-control-sm gst-rate text-end cgst-rate" name="items[${idx}][cgst_percent]" value="0" step="0.01" min="0"></td>
        <td><input type="number" class="form-control form-control-sm gst-rate text-end sgst-rate" name="items[${idx}][sgst_percent]" value="0" step="0.01" min="0"></td>
        <td><input type="number" class="form-control form-control-sm gst-rate text-end igst-rate" name="items[${idx}][igst_percent]" value="0" step="0.01" min="0"></td>
        <td class="amount text-end fw-bold">0.00</td>
        <td class="text-center">
            <button type="button" class="btn-danger-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button>
        </td>
    `;

                tbody.appendChild(row);
                updateRow(row);
                updateTotals();
            }

            function removeRow(btn) {
                btn.closest('tr').remove();
                updateTotals();
                document.querySelectorAll('#itemTable tbody tr').forEach((row, i) => {
                    row.querySelector('td:first-child').textContent = i + 1;
                });
                itemCounter = document.querySelectorAll('#itemTable tbody tr').length + 1;
            }

            document.getElementById('itemSearch')?.addEventListener('keyup', function () {
                const query = this.value.toLowerCase();
                let visible = 0;
                document.querySelectorAll('#itemSelectList .item-popup-row').forEach(row => {
                    const haystack = (row.getAttribute('data-search') || row.textContent || '').toLowerCase();
                    const show = haystack.includes(query);
                    row.style.display = show ? '' : 'none';
                    if (show) visible++;
                });
                const noResult = document.getElementById('itemNoResult');
                if (noResult) noResult.style.display = visible === 0 ? 'block' : 'none';
            });

            function handleGstInput(input) {
                const row = input.closest('tr');
                const cgst = row.querySelector('.cgst-rate');
                const sgst = row.querySelector('.sgst-rate');
                const igst = row.querySelector('.igst-rate');

                const cgstVal = parseFloat(cgst.value) || 0;
                const sgstVal = parseFloat(sgst.value) || 0;
                const igstVal = parseFloat(igst.value) || 0;

                if (igstVal > 0) {
                    cgst.readOnly = true; cgst.style.background = "#f0f0f0";
                    sgst.readOnly = true; sgst.style.background = "#f0f0f0";
                    cgst.value = 0;
                    sgst.value = 0;
                } else {
                    cgst.readOnly = false; cgst.style.background = "";
                    sgst.readOnly = false; sgst.style.background = "";
                }

                if (cgstVal > 0 || sgstVal > 0) {
                    igst.readOnly = true; igst.style.background = "#f0f0f0";
                    igst.value = 0;
                } else {
                    igst.readOnly = false; igst.style.background = "";
                }
            }

            function updateRow(row) {
                const qty = parseFloat(row.querySelector('.qty').value) || 0;
                const rate = parseFloat(row.querySelector('.rate').value) || 0;
                const discount = parseFloat(row.querySelector('.discount').value) || 0;

                const taxable = (qty * rate) - discount;

                const cgstVal = parseFloat(row.querySelector('.cgst-rate').value) || 0;
                const sgstVal = parseFloat(row.querySelector('.sgst-rate').value) || 0;
                const igstVal = parseFloat(row.querySelector('.igst-rate').value) || 0;

                let taxAmount = 0;
                if (igstVal > 0) {
                    taxAmount = taxable * (igstVal / 100);
                } else if (cgstVal > 0 || sgstVal > 0) {
                    taxAmount = taxable * ((cgstVal + sgstVal) / 100);
                }

                const totalAmt = taxable + taxAmount;

                row.querySelector('.taxable').textContent = taxable.toFixed(2);
                row.querySelector('.amount').textContent = totalAmt.toFixed(2);

                // Sync hidden inputs so computed values POST correctly
                const hBasic = row.querySelector('.hidden-basic'); if (hBasic) hBasic.value = taxable.toFixed(2);
                const hCgst = row.querySelector('.hidden-cgst-amt'); if (hCgst) hCgst.value = (taxable * (cgstVal / 100)).toFixed(2);
                const hSgst = row.querySelector('.hidden-sgst-amt'); if (hSgst) hSgst.value = (taxable * (sgstVal / 100)).toFixed(2);
                const hIgst = row.querySelector('.hidden-igst-amt'); if (hIgst) hIgst.value = (taxable * (igstVal / 100)).toFixed(2);
                const hTotal = row.querySelector('.hidden-total'); if (hTotal) hTotal.value = totalAmt.toFixed(2);
            }

            function updateTotals() {
                let totalTaxable = 0, totalTax = 0, totalCgst = 0, totalSgst = 0, totalIgst = 0, grandTotal = 0;

                document.querySelectorAll('#itemTable tbody tr').forEach(row => {
                    const taxable = parseFloat(row.querySelector('.taxable').textContent.replace(/,/g, '')) || 0;
                    const amount = parseFloat(row.querySelector('.amount').textContent.replace(/,/g, '')) || 0;
                    const cgstAmt = parseFloat(row.querySelector('.hidden-cgst-amt')?.value || 0);
                    const sgstAmt = parseFloat(row.querySelector('.hidden-sgst-amt')?.value || 0);
                    const igstAmt = parseFloat(row.querySelector('.hidden-igst-amt')?.value || 0);
                    totalTaxable += taxable;
                    totalCgst += cgstAmt;
                    totalSgst += sgstAmt;
                    totalIgst += igstAmt;
                    totalTax += (cgstAmt + sgstAmt + igstAmt);
                    grandTotal += amount;
                });

                document.getElementById('totalTaxable').textContent = '₹ ' + totalTaxable.toFixed(2);
                document.getElementById('totalCgst').textContent    = '₹ ' + totalCgst.toFixed(2);
                document.getElementById('totalSgst').textContent    = '₹ ' + totalSgst.toFixed(2);
                document.getElementById('totalIgst').textContent    = '₹ ' + totalIgst.toFixed(2);
                document.getElementById('totalTax').textContent     = '₹ ' + totalTax.toFixed(2);

                // Recalculate round off only if user has already enabled it
                const roundOffRow = document.getElementById('roundOffRow');
                if (roundOffRow && roundOffRow.style.display !== 'none') {
                    const floored  = Math.floor(grandTotal);
                    const paise    = parseFloat((grandTotal - floored).toFixed(2));
                    const roundOff = paise > 0 ? -paise : 0;
                    document.getElementById('roundOffAmt').textContent = '₹ ' + roundOff.toFixed(2);
                    document.getElementById('grandTotal').textContent  = '₹ ' + (grandTotal + roundOff).toFixed(2);
                } else {
                    document.getElementById('grandTotal').textContent = '₹ ' + grandTotal.toFixed(2);
                }
            }

            document.getElementById('itemTable').addEventListener('input', function (e) {
                const target = e.target;
                if (target.matches('.qty, .rate, .discount, .cgst-rate, .sgst-rate, .igst-rate')) {
                    const row = target.closest('tr');
                    if (target.matches('.cgst-rate, .sgst-rate, .igst-rate')) {
                        handleGstInput(target);
                    }
                    updateRow(row);
                    updateTotals();
                }
            });

            updateTotals();
        </script>
        <script>
            document.addEventListener("DOMContentLoaded", function () {

                const saveBtn = document.getElementById("saveBankBtn");
                const form = document.getElementById("addBankForm");
                const select = document.getElementById("bank_select");
                const modalElement = document.getElementById("addBankModal");

                saveBtn.addEventListener("click", function (e) {

                    e.preventDefault();

                    fetch("addbank.php", {
                        method: "POST",
                        body: new FormData(form)
                    })
                        .then(res => res.json())
                        .then(data => {

                            if (data.status === "success") {

                                // Add new bank to dropdown
                                const branch = (form.querySelector('[name=\"branch\"]')?.value || '').trim();
                                let option = new Option(data.bank_name + (branch ? ' - ' + branch : ''), data.id, true, true);
                                select.add(option);

                                // Properly close modal
                                const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
                                modal.hide();

                            } else {
                                alert(data.message);
                            }

                        })
                        .catch(err => {
                            console.error(err);
                            alert("Error saving bank");
                        });

                });

                // Reset form when modal closes
                modalElement.addEventListener("hidden.bs.modal", function () {
                    form.reset();
                });

            });
        </script>

        <!-- Add Executive Modal -->
        <div id="addExecModal"
            style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:9999;justify-content:center;align-items:center;backdrop-filter:blur(4px);">
            <div
                style="background:#fff;border-radius:14px;padding:28px 24px;min-width:320px;box-shadow:0 24px 60px rgba(0,0,0,0.15);border:1px solid #e4e8f0;">
                <h3 style="font-size:16px;font-weight:800;color:#1a1f2e;margin-bottom:16px;"><i class="fas fa-user-tie"
                        style="color:#f97316;margin-right:8px;"></i>Add Executive</h3>
                <input type="text" id="execNameInput" placeholder="Executive name"
                    style="width:100%;padding:10px 13px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;outline:none;margin-bottom:14px;font-family:inherit;">
                <div style="display:flex;gap:10px;">
                    <button type="button" onclick="saveExecutive()"
                        style="flex:1;padding:10px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
                        <i class="fas fa-check"></i> Save
                    </button>
                    <button type="button" onclick="closeAddExecModal()"
                        style="flex:1;padding:10px;background:#fff;color:#6b7280;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;">
                        Cancel
                    </button>
                </div>
            </div>
        </div>

        <script>
            function openAddExecModal() {
                document.getElementById('addExecModal').style.display = 'flex';
                setTimeout(function () { document.getElementById('execNameInput').focus(); }, 100);
            }
            function closeAddExecModal() {
                document.getElementById('addExecModal').style.display = 'none';
                document.getElementById('execNameInput').value = '';
            }
            function saveExecutive() {
                var name = document.getElementById('execNameInput').value.trim();
                if (!name) { alert('Please enter a name.'); return; }
                var fd = new FormData();
                fd.append('add_executive', '1');
                fd.append('exec_name', name);
                fetch('create_invoice.php', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.success) {
                            var sel = document.getElementById('executive_id');
                            var opt = document.createElement('option');
                            opt.value = d.id;
                            opt.textContent = d.name;
                            opt.selected = true;
                            sel.appendChild(opt);
                            closeAddExecModal();
                        } else {
                            alert('Error: ' + (d.message || 'Could not save'));
                        }
                    })
                    .catch(function () { alert('Network error. Try again.'); });
            }
            document.getElementById('addExecModal').addEventListener('click', function (e) {
                if (e.target === this) closeAddExecModal();
            });
        </script>

        <script>
            // ═══════════════════════════════════════════════════════
            // DRAFT SAVE / RESTORE — keeps form when navigating away
            // ═══════════════════════════════════════════════════════
            var DRAFT_KEY = 'invoice_draft_<?= $isEdit ? 'edit_' . $editId : 'new' ?>';

            function _esc(s) { return String(s ?? '').replace(/"/g, '&quot;'); }

            function saveFormDraft() {
                var draft = {};
                // All named fields except item hidden inputs
                document.querySelectorAll('form [name]').forEach(function (el) {
                    if (el.name && !el.name.startsWith('items[')) draft[el.name] = el.value;
                });
                // Select2 customer
                var cs = document.getElementById('customer_select');
                if (cs) draft['__cs__'] = cs.value;
                // Item rows
                var rows = [];
                document.querySelectorAll('#itemTable tbody tr').forEach(function (tr) {
                    var r = {};
                    tr.querySelectorAll('input').forEach(function (inp) { r[inp.name] = inp.value; });
                    var strong = tr.querySelector('td:nth-child(2) strong');
                    var small = tr.querySelector('td:nth-child(2) small');
                    r['__iname__'] = strong ? strong.textContent : '';
                    r['__idesc__'] = small ? small.textContent : '';
                    var tx = tr.querySelector('.taxable'); r['__tx__'] = tx ? tx.textContent : '0.00';
                    var am = tr.querySelector('.amount'); r['__am__'] = am ? am.textContent : '0.00';
                    rows.push(r);
                });
                draft['__rows__'] = rows;
                try { sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft)); } catch (e) { }
            }

            function restoreFormDraft() {
                var raw; try { raw = sessionStorage.getItem(DRAFT_KEY); } catch (e) { }
                if (!raw) return;
                var d; try { d = JSON.parse(raw); } catch (e) { return; }

                // Restore plain fields
                Object.keys(d).forEach(function (k) {
                    if (k.startsWith('__')) return;
                    var el = document.querySelector('form [name="' + k + '"]');
                    if (el && el.type !== 'submit') el.value = d[k];
                });
                // Restore Select2
                if (d['__cs__']) {
                    var cs = document.getElementById('customer_select');
                    if (cs) { cs.value = d['__cs__']; if (window.$) $('#customer_select').trigger('change.select2'); }
                }
                // Restore item rows
                var rows = d['__rows__'] || [];
                if (rows.length) {
                    var tbody = document.querySelector('#itemTable tbody');
                    tbody.innerHTML = '';
                    rows.forEach(function (r, i) {
                        var idx = itemCounter++;
                        var id = '', code = '', iname = r['__iname__'] || '', desc = r['__idesc__'] || '';
                        var hsn = '', uom = '', qty = 1, rate = 0, disc = 0;
                        var cgst = 0, sgst = 0, igst = 0;
                        var bAmt = 0, cAmt = 0, sAmt = 0, igAmt = 0, tAmt = 0;
                        Object.keys(r).forEach(function (k) {
                            var m = k.match(/\[([^\]]+)\]$/); if (!m) return; var f = m[1];
                            if (f === 'id') id = r[k];
                            if (f === 'service_code') code = r[k];
                            if (f === 'item_name') iname = r[k] || iname;
                            if (f === 'description') desc = r[k] || desc;
                            if (f === 'hsn_sac') hsn = r[k];
                            if (f === 'uom') uom = r[k];
                            if (f === 'qty') qty = r[k];
                            if (f === 'rate') rate = r[k];
                            if (f === 'discount') disc = r[k];
                            if (f === 'cgst_percent') cgst = r[k];
                            if (f === 'sgst_percent') sgst = r[k];
                            if (f === 'igst_percent') igst = r[k];
                            if (f === 'basic_amount') bAmt = r[k];
                            if (f === 'cgst_amount') cAmt = r[k];
                            if (f === 'sgst_amount') sAmt = r[k];
                            if (f === 'igst_amount') igAmt = r[k];
                            if (f === 'total_amount') tAmt = r[k];
                        });
                        var dispHtml = (iname ? '<strong>' + iname + '</strong>' : '') +
                            (iname && desc && desc !== iname ? '<br><small class="text-muted" style="font-size:11px;">' + desc + '</small>' : '');
                        var tr = document.createElement('tr');
                        tr.innerHTML =
                            '<td class="text-center">' + (i + 1) + '</td>' +
                            '<td class="item-desc-cell">' + dispHtml +
                            '<input type="hidden" name="items[' + idx + '][id]"           value="' + _esc(id) + '">' +
                            '<input type="hidden" name="items[' + idx + '][service_code]" value="' + _esc(code) + '">' +
                            '<input type="hidden" name="items[' + idx + '][item_name]"    value="' + _esc(iname) + '">' +
                            '<input type="hidden" name="items[' + idx + '][description]"  value="' + _esc(desc) + '">' +
                            '<input type="hidden" name="items[' + idx + '][hsn_sac]"      value="' + _esc(hsn) + '">' +
                            '<input type="hidden" name="items[' + idx + '][uom]"          value="' + _esc(uom) + '">' +
                            '<input type="hidden" name="items[' + idx + '][basic_amount]"  class="hidden-basic"    value="' + _esc(bAmt) + '">' +
                            '<input type="hidden" name="items[' + idx + '][cgst_amount]"   class="hidden-cgst-amt" value="' + _esc(cAmt) + '">' +
                            '<input type="hidden" name="items[' + idx + '][sgst_amount]"   class="hidden-sgst-amt" value="' + _esc(sAmt) + '">' +
                            '<input type="hidden" name="items[' + idx + '][igst_amount]"   class="hidden-igst-amt" value="' + _esc(igAmt) + '">' +
                            '<input type="hidden" name="items[' + idx + '][total_amount]"  class="hidden-total"    value="' + _esc(tAmt) + '">' +
                            '</td>' +
                            '<td class="text-center">' + (hsn || '-') + '</td>' +
                            '<td><input type="number" class="form-control form-control-sm qty text-end"      name="items[' + idx + '][qty]"          value="' + _esc(qty) + '"  min="1" step="0.001"></td>' +
                            '<td class="text-center">' + (uom || '-') + '</td>' +
                            '<td><input type="number" class="form-control form-control-sm rate text-end"     name="items[' + idx + '][rate]"         value="' + _esc(rate) + '" step="0.01"></td>' +
                            '<td><input type="number" class="form-control form-control-sm discount text-end" name="items[' + idx + '][discount]"     value="' + _esc(disc) + '" step="0.01"></td>' +
                            '<td class="taxable text-end fw-bold">' + (r['__tx__'] || '0.00') + '</td>' +
                            '<td><input type="number" class="form-control form-control-sm gst-rate text-end cgst-rate" name="items[' + idx + '][cgst_percent]" value="' + _esc(cgst) + '" step="0.01" min="0"></td>' +
                            '<td><input type="number" class="form-control form-control-sm gst-rate text-end sgst-rate" name="items[' + idx + '][sgst_percent]" value="' + _esc(sgst) + '" step="0.01" min="0"></td>' +
                            '<td><input type="number" class="form-control form-control-sm gst-rate text-end igst-rate" name="items[' + idx + '][igst_percent]" value="' + _esc(igst) + '" step="0.01" min="0"></td>' +
                            '<td class="amount text-end fw-bold">' + (r['__am__'] || '0.00') + '</td>' +
                            '<td class="text-center"><button type="button" class="btn-danger-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>';
                        tbody.appendChild(tr);
                    });
                    updateTotals();
                }
                try { sessionStorage.removeItem(DRAFT_KEY); } catch (e) { }
            }

            function addReturnedStockFromQuery() {
                var params = new URLSearchParams(window.location.search);
                var itemId = params.get('_new_item_id');
                if (!itemId) return;

                selectItem(
                    parseInt(itemId, 10) || 0,
                    params.get('_new_item_code') || '',
                    params.get('_new_item_name') || '',
                    params.get('_new_item_hsn') || '',
                    params.get('_new_item_uom') || '',
                    parseFloat(params.get('_new_item_rate') || '0') || 0,
                    params.get('_new_item_desc') || ''
                );
            }

            function goAddStock() {
                saveFormDraft();
                window.location.href = 'add_stock.php?_from=create_invoice<?= $isEdit ? "&edit_id=$editId" : "" ?>';
            }
            function goAddCustomer() {
                saveFormDraft();
                window.location.href = 'add_customer.php?_from=create_invoice<?= $isEdit ? "&edit_id=$editId" : "" ?>';
            }

            // Restore draft on return from add_stock / add_customer
            (function () {
                if (new URLSearchParams(window.location.search).get('_restored') !== '1') return;
                function tryRestore() {
                    var tries = 0, iv = setInterval(function () {
                        tries++;
                        if ((window.$ && $('#customer_select').data('select2')) || tries > 20) {
                            clearInterval(iv);
                            restoreFormDraft();
                            addReturnedStockFromQuery();
                        }
                    }, 100);
                }
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', tryRestore);
                else tryRestore();
            })();
        </script>

        <!-- ADD COMPANY MODAL -->
        <div id="addCompanyModal"
            style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
            <div
                style="background:#fff;border-radius:18px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);">
                <!-- Header -->
                <div
                    style="padding:16px 24px;border-bottom:1.5px solid #f0f2f7;display:flex;align-items:center;gap:10px;background:#fafbfd;border-radius:18px 18px 0 0;">
                    <div
                        style="width:34px;height:34px;background:linear-gradient(135deg,#f97316,#fb923c);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <div
                            style="font-size:15px;font-weight:800;color:#1a1f2e;font-family:'Times New Roman',Times,serif;">
                            Add New Company</div>
                        <div style="font-size:11px;color:#9ca3af;">Saved to invoice_company table</div>
                    </div>
                    <button type="button" onclick="closeAddCompanyModal()"
                        style="margin-left:auto;background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;">&times;</button>
                </div>
                <!-- Body -->
                <div style="padding:20px 24px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                    <div style="grid-column:1/-1;">
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Company
                            Name *</label>
                        <input type="text" id="ac_company_name" placeholder="e.g. Eltrive Automations Pvt Ltd"
                            style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
                    </div>
                    <div style="grid-column:1/-1;">
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Company
                            Logo</label>
                        <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                            <div
                                style="width:56px;height:56px;border-radius:12px;border:1.5px solid #e4e8f0;background:#f4f6fb;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
                                <img id="ac_logo_preview" src="" alt="Company logo"
                                    style="max-width:100%;max-height:100%;display:none;">
                                <i id="ac_logo_placeholder" class="fas fa-image"
                                    style="font-size:22px;color:#c0c8d8;"></i>
                            </div>
                            <div style="flex:1;min-width:220px;">
                                <input type="hidden" id="ac_company_logo_existing" value="">
                                <input type="file" id="ac_company_logo" accept="image/*"
                                    style="width:100%;padding:7px 10px;border:1.5px dashed #e4e8f0;border-radius:8px;font-size:11px;color:#6b7280;background:#fafbfc;cursor:pointer;font-family:'Times New Roman',Times,serif;transition:border-color .2s;">
                                <div style="font-size:10px;color:#9ca3af;margin-top:2px">PNG, JPG up to 5MB</div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Address
                            Line 1</label>
                        <input type="text" id="ac_address_line1" placeholder="Street / Door No."
                            style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
                    </div>
                    <div>
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Address
                            Line 2</label>
                        <input type="text" id="ac_address_line2" placeholder="Area / Landmark"
                            style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
                    </div>
                    <div>
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">City</label>
                        <input type="text" id="ac_city" placeholder="City"
                            style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
                    </div>
                    <div>
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">State</label>
                        <input type="text" id="ac_state" placeholder="State"
                            style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
                    </div>
                    <div>
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Pincode</label>
                        <input type="text" id="ac_pincode" placeholder="500001"
                            style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
                    </div>
                    <div>
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Phone</label>
                        <input type="text" id="ac_phone" placeholder="+91 9999999999"
                            style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
                    </div>
                    <div>
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Email</label>
                        <input type="email" id="ac_email" placeholder="info@company.com"
                            style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
                    </div>
                    <div>
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">GST
                            Number</label>
                        <input type="text" id="ac_gst_number" maxlength="15" placeholder="29XXXXX1234X1ZX"
                            style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:monospace;text-transform:uppercase;color:#1a1f2e;outline:none;">
                    </div>
                    <div>
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">CIN
                            Number</label>
                        <input type="text" id="ac_cin_number" placeholder="U12345TN2020PTC000000"
                            style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:monospace;text-transform:uppercase;color:#1a1f2e;outline:none;">
                    </div>
                    <div>
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">PAN
                            Number</label>
                        <input type="text" id="ac_pan" maxlength="10" placeholder="AAAAA9999A"
                            style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:monospace;text-transform:uppercase;color:#1a1f2e;outline:none;">
                    </div>
                    <div>
                        <label
                            style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Website</label>
                        <input type="text" id="ac_website" placeholder="www.company.com"
                            style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
                    </div>
                </div>
                <!-- Footer -->
                <div
                    style="padding:14px 24px;border-top:1.5px solid #f0f2f7;display:flex;gap:10px;align-items:center;background:#fafbfd;border-radius:0 0 18px 18px;">
                    <button type="button" onclick="saveNewCompany()"
                        style="padding:9px 22px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 8px rgba(249,115,22,.3);">
                        <i class="fas fa-save"></i> Save Company
                    </button>
                    <button type="button" onclick="closeAddCompanyModal()"
                        style="padding:9px 18px;background:#fff;color:#6b7280;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Times New Roman',Times,serif;">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <div id="ac_status"
                        style="margin-left:auto;font-size:12px;color:#16a34a;font-weight:600;display:none;"></div>
                </div>
            </div>
        </div>

        <script>
            // ── Initialize company_select with Select2 rich template ─────────────
            $(document).ready(function () {
                $('#company_select').select2({
                    width: '260px',
                    placeholder: '-- Select Company --',
                    allowClear: false,
                    templateResult: function (opt) {
                        if (!opt.id) return opt.text;
                        var el = opt.element;
                        var addr = $(el).data('addr') || '';
                        var $r = $('<div style="padding:2px 0;line-height:1.4;"></div>');
                        $r.append('<div style="font-weight:700;font-size:13px;color:#1a1f2e;">' + $('<div>').text(opt.text).html() + '</div>');
                        if (addr) $r.append('<div style="font-size:11px;color:#9ca3af;margin-top:1px;">' + $('<div>').text(addr).html() + '</div>');
                        return $r;
                    },
                    templateSelection: function (opt) {
                        if (!opt.id) return opt.text;
                        var el = opt.element;
                        var addr = $(el).data('addr') || '';
                        if (!addr) return opt.text;
                        return $('<span><strong>' + $('<div>').text(opt.text).html() + '</strong>'
                            + ' <small style="color:#9ca3af;font-size:11px;">' + $('<div>').text(addr).html() + '</small></span>');
                    }
                }).on('change', function () {
                    onCompanyChange(this);
                });
                // Apply on load
                var sel = document.getElementById('company_select');
                var coChangedEl = document.getElementById('co_changed');
                var coChanged = coChangedEl ? coChangedEl.value : '0';
                // If editing and user already has an override snapshot, don't overwrite hidden fields.
                if (sel && sel.value && coChanged !== '1') onCompanyChange(sel);
            });

            // ── Company dropdown: when user selects a company, push into hidden fields ──
            function onCompanyChange(sel) {
                const opt = sel.options[sel.selectedIndex];
                if (!opt || !opt.value) return;
                document.getElementById('co_company_name').value = opt.dataset.name || '';
                document.getElementById('co_company_logo').value = opt.dataset.logo || '';
                document.getElementById('co_address_line1').value = opt.dataset.line1 || '';
                document.getElementById('co_address_line2').value = opt.dataset.line2 || '';
                document.getElementById('co_city').value = opt.dataset.city || '';
                document.getElementById('co_state').value = opt.dataset.state || '';
                document.getElementById('co_pincode').value = opt.dataset.pincode || '';
                document.getElementById('co_phone').value = opt.dataset.phone || '';
                document.getElementById('co_email').value = opt.dataset.email || '';
                document.getElementById('co_gst_number').value = opt.dataset.gst || '';
                document.getElementById('co_cin_number').value = opt.dataset.cin || '';
                document.getElementById('co_pan').value = opt.dataset.pan || '';
                document.getElementById('co_website').value = opt.dataset.website || '';
                document.getElementById('co_changed').value = '1';
            }

            // ── Add Company Modal ──────────────────────────────────────────────────
            function openAddCompanyModal() {
                // Pre-fill modal from existing hidden override fields (so "Add" shows existing details).
                const map = {
                    'ac_company_name': 'co_company_name',
                    'ac_address_line1': 'co_address_line1',
                    'ac_address_line2': 'co_address_line2',
                    'ac_city': 'co_city',
                    'ac_state': 'co_state',
                    'ac_pincode': 'co_pincode',
                    'ac_phone': 'co_phone',
                    'ac_email': 'co_email',
                    'ac_gst_number': 'co_gst_number',
                    'ac_cin_number': 'co_cin_number',
                    'ac_pan': 'co_pan',
                    'ac_website': 'co_website',
                    'ac_company_logo_existing': 'co_company_logo',
                };
                Object.entries(map).forEach(([toId, fromId]) => {
                    const toEl = document.getElementById(toId);
                    if (!toEl) return;
                    const fromEl = document.getElementById(fromId);
                    toEl.value = fromEl ? (fromEl.value || '') : '';
                });

                // File input must be cleared (can't be prefilled for security).
                const fileEl = document.getElementById('ac_company_logo');
                if (fileEl) fileEl.value = '';

                // Logo preview
                const logoPath = (document.getElementById('ac_company_logo_existing')?.value || '').trim();
                const img = document.getElementById('ac_logo_preview');
                const placeholder = document.getElementById('ac_logo_placeholder');
                if (img && placeholder) {
                    if (logoPath) {
                        img.src = logoPath;
                        img.style.display = 'block';
                        placeholder.style.display = 'none';
                    } else {
                        img.src = '';
                        img.style.display = 'none';
                        placeholder.style.display = 'block';
                    }
                }

                document.getElementById('ac_status').style.display = 'none';
                document.getElementById('addCompanyModal').style.display = 'flex';
            }

            function closeAddCompanyModal() {
                document.getElementById('addCompanyModal').style.display = 'none';
            }

            document.getElementById('addCompanyModal').addEventListener('click', function (e) {
                if (e.target === this) closeAddCompanyModal();
            });

            async function saveNewCompany() {
                const name = document.getElementById('ac_company_name').value.trim();
                if (!name) { alert('Company name is required.'); return; }
                try {
                    const fd = new FormData();
                    fd.append('action', 'add_company');
                    fd.append('company_name', name);
                    fd.append('address_line1', document.getElementById('ac_address_line1').value.trim());
                    fd.append('address_line2', document.getElementById('ac_address_line2').value.trim());
                    fd.append('city', document.getElementById('ac_city').value.trim());
                    fd.append('state', document.getElementById('ac_state').value.trim());
                    fd.append('pincode', document.getElementById('ac_pincode').value.trim());
                    fd.append('phone', document.getElementById('ac_phone').value.trim());
                    fd.append('email', document.getElementById('ac_email').value.trim());
                    fd.append('gst_number', document.getElementById('ac_gst_number').value.trim().toUpperCase());
                    fd.append('cin_number', document.getElementById('ac_cin_number').value.trim().toUpperCase());
                    fd.append('pan', document.getElementById('ac_pan').value.trim().toUpperCase());
                    fd.append('website', document.getElementById('ac_website').value.trim());

                    const existingLogo = (document.getElementById('ac_company_logo_existing')?.value || '').trim();
                    const logoFile = document.getElementById('ac_company_logo')?.files?.[0];
                    if (logoFile) fd.append('company_logo', logoFile);
                    else if (existingLogo) fd.append('company_logo_existing', existingLogo);

                    const res = await fetch('create_invoice.php', { method: 'POST', body: fd });
                    const json = await res.json();
                    if (json.success) {
                        // Add new option to dropdown with full address detail
                        const sel = document.getElementById('company_select');
                        const opt = document.createElement('option');
                        opt.value = json.id;
                        opt.dataset.name = name;
                        opt.dataset.line1 = document.getElementById('ac_address_line1').value.trim();
                        opt.dataset.line2 = document.getElementById('ac_address_line2').value.trim();
                        opt.dataset.city = document.getElementById('ac_city').value.trim();
                        opt.dataset.state = document.getElementById('ac_state').value.trim();
                        opt.dataset.pincode = document.getElementById('ac_pincode').value.trim();
                        opt.dataset.phone = document.getElementById('ac_phone').value.trim();
                        opt.dataset.email = document.getElementById('ac_email').value.trim();
                        opt.dataset.gst = document.getElementById('ac_gst_number').value.trim().toUpperCase();
                        opt.dataset.cin = document.getElementById('ac_cin_number').value.trim().toUpperCase();
                        opt.dataset.pan = document.getElementById('ac_pan').value.trim().toUpperCase();
                        opt.dataset.website = document.getElementById('ac_website').value.trim();
                        opt.dataset.logo = json.company_logo || '';

                        const addr = [opt.dataset.line1, opt.dataset.city].filter(Boolean).join(', ');
                        opt.textContent = opt.dataset.name + (addr ? ' — ' + addr : '');
                        sel.appendChild(opt);
                        sel.value = json.id;
                        onCompanyChange(sel);   // auto-apply to hidden fields
                        const status = document.getElementById('ac_status');
                        status.textContent = '✓ Company saved & selected!';
                        status.style.display = 'block';
                        setTimeout(closeAddCompanyModal, 1200);
                    } else {
                        alert('Error: ' + (json.message || 'Save failed'));
                    }
                } catch (e) {
                    alert('Error: ' + e.message);
                }
            }
        </script>

        <!-- Add Signature Modal -->
        <div id="addSignatureModal" class="modal fade" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content" style="border:none;border-radius:18px;box-shadow:0 10px 40px rgba(0,0,0,.1)">
                    <div class="modal-header"
                        style="background:#fafbfd;padding:16px 24px;border-bottom:1px solid #f0f2f7;border-radius:18px 18px 0 0;">
                        <h5 class="modal-title"
                            style="font-size:16px;font-weight:800;color:#1a1f2e;font-family:'Times New Roman',Times,serif;">
                            Add New Signature</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"
                            style="font-size:12px;"></button>
                    </div>
                    <div class="modal-body" style="padding:24px;">
                        <input type="text" id="new_signature_name" class="form-control"
                            placeholder="Signature Name (e.g. CEO Signature)" style="margin-bottom: 12px;">
                        <input type="file" id="new_signature_image" class="form-control"
                            accept="image/png, image/jpeg, image/webp" style="margin-bottom: 12px;">
                        <small style="color: #6b7280; font-size: 11px;">Upload a clear signature image (PNG/JPG).
                            Recommended max 50px height.</small>
                        <div id="add_sig_error" class="text-danger mt-2" style="font-size: 12px; display: none;"></div>
                    </div>
                    <div class="modal-footer"
                        style="background:#fafbfd;border-top:1px solid #f0f2f7;border-radius:0 0 18px 18px;">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"
                            style="border-radius: 8px; font-size: 13px;">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="saveNewSignature()"
                            style="background:linear-gradient(135deg,#3b82f6,#2563eb);border:none;border-radius:8px;font-size:13px;font-weight:700;"><i
                                class="fas fa-save"></i> Save Signature</button>
                    </div>
                </div>
            </div>
        </div>
        <script>
            // Signature Select Preview logic
            document.getElementById('signature_select').addEventListener('change', function () {
                var opt = this.options[this.selectedIndex];
                var preview = document.getElementById('signature_preview');
                if (opt && opt.dataset.path) {
                    preview.src = opt.dataset.path;
                    preview.style.display = 'inline-block';
                } else {
                    preview.src = '';
                    preview.style.display = 'none';
                }
            });

            // Trigger initial preview if editing
            window.addEventListener('DOMContentLoaded', function () {
                var sel = document.getElementById('signature_select');
                if (sel && sel.value) {
                    var ev = new Event('change');
                    sel.dispatchEvent(ev);
                }
            });

            async function saveNewSignature() {
                var name = document.getElementById('new_signature_name').value.trim();
                var fileInput = document.getElementById('new_signature_image');
                var errDiv = document.getElementById('add_sig_error');

                if (!name) { errDiv.textContent = 'Name is required.'; errDiv.style.display = 'block'; return; }
                if (!fileInput.files.length) { errDiv.textContent = 'Image is required.'; errDiv.style.display = 'block'; return; }

                var fd = new FormData();
                fd.append('signature_name', name);
                fd.append('signature_image', fileInput.files[0]);

                try {
                    const res = await fetch('addsignature.php', { method: 'POST', body: fd });
                    const json = await res.json();

                    if (json.status === 'success') {
                        var sel = document.getElementById('signature_select');
                        var opt = document.createElement('option');
                        opt.value = json.id;
                        opt.textContent = json.signature_name;
                        opt.dataset.path = json.file_path;
                        sel.appendChild(opt);
                        sel.value = json.id;

                        // trigger preview
                        sel.dispatchEvent(new Event('change'));

                        // clear modal
                        document.getElementById('new_signature_name').value = '';
                        fileInput.value = '';
                        errDiv.style.display = 'none';

                        // hide modal
                        bootstrap.Modal.getInstance(document.getElementById('addSignatureModal')).hide();
                    } else {
                        errDiv.textContent = json.message || 'Error uploading signature.'; errDiv.style.display = 'block';
                    }
                } catch (e) {
                    errDiv.textContent = 'Network error: ' + e.message; errDiv.style.display = 'block';
                }
            }
        </script>
</body>

</html>

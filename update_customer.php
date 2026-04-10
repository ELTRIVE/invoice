<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;

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

try {
    ensureCustomerInvoiceAddressColumns($pdo);

    $id        = intval($_POST['id'] ?? 0);
    $form_type = trim($_POST['form_type'] ?? 'main');

    if ($form_type === 'financials') {
        $stmt = $pdo->prepare("UPDATE customers SET
            receivables=:r, business_prospect=:bp, order_target=:ot,
            receivable_notes=:rn, msme_no=:mn WHERE id=:id");
        $stmt->execute([
            ':r'  => $_POST['receivables']       ?? null,
            ':bp' => $_POST['business_prospect'] ?? null,
            ':ot' => $_POST['order_target']      ?? null,
            ':rn' => $_POST['receivable_notes']  ?? '',
            ':mn' => $_POST['msme_no']           ?? '',
            ':id' => $id,
        ]);
    } else {
        $show_title = isset($_POST['show_title_in_shipping']) ? 1 : 0;
        $billingCity    = $_POST['address_city'] ?? '';
        $billingState   = $_POST['address_state'] ?? '';
        $billingCountry = $_POST['address_country'] ?? '';

        $stmt = $pdo->prepare("UPDATE customers SET
            title=:ti, first_name=:fn, last_name=:ln, business_name=:bn,
            email=:em, mobile=:mo,
            gstin=:gs, pan_no=:pn,
            address_line1=:al1, address_line2=:al2,
            address_city=:ac, city=:ci,
            address_state=:as2, state=:st,
            address_country=:acn, country=:cn,
            pincode=:pc,
            billing_gstin=:bg, billing_pan=:bpan, billing_phone=:bp,
            ship_address_line1=:sal1, ship_address_line2=:sal2,
            ship_city=:sci, ship_state=:sst, ship_pincode=:spc, ship_country=:scn,
            shipping_gstin=:sg, shipping_pan=:span, shipping_phone=:sph,
            show_title_in_shipping=:sht
            WHERE id=:id");
        $stmt->execute([
            ':ti'  => $_POST['title'] ?? '',
            ':fn'  => $_POST['first_name']    ?? '',
            ':ln'  => $_POST['last_name']     ?? '',
            ':bn'  => $_POST['business_name'] ?? '',
            ':em'  => $_POST['email']         ?? '',
            ':mo'  => $_POST['mobile']        ?? '',
            ':gs'  => strtoupper($_POST['gstin']  ?? ''),
            ':pn'  => strtoupper($_POST['pan_no'] ?? ''),
            ':al1' => $_POST['address_line1'] ?? '',
            ':al2' => $_POST['address_line2'] ?? '',
            ':ac'  => $billingCity,
            ':ci'  => $billingCity,
            ':as2' => $billingState,
            ':st'  => $billingState,
            ':acn' => $billingCountry,
            ':cn'  => $billingCountry,
            ':pc'  => $_POST['pincode'] ?? '',
            ':bg'  => strtoupper($_POST['billing_gstin'] ?? ''),
            ':bpan' => strtoupper($_POST['billing_pan'] ?? ''),
            ':bp'  => $_POST['billing_phone'] ?? '',
            ':sal1' => $_POST['ship_address_line1'] ?? '',
            ':sal2' => $_POST['ship_address_line2'] ?? '',
            ':sci' => $_POST['ship_city'] ?? '',
            ':sst' => $_POST['ship_state'] ?? '',
            ':spc' => $_POST['ship_pincode'] ?? '',
            ':scn' => $_POST['ship_country'] ?? '',
            ':sg'  => strtoupper($_POST['shipping_gstin'] ?? ''),
            ':span' => strtoupper($_POST['shipping_pan'] ?? ''),
            ':sph' => $_POST['shipping_phone'] ?? '',
            ':sht' => $show_title,
            ':id'  => $id,
        ]);
    }

    echo json_encode(['status' => 'success']);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

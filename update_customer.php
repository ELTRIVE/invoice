<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;

try {
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
        $city    = $_POST['address_city']    ?? '';
        $state   = $_POST['address_state']   ?? '';
        $country = $_POST['address_country'] ?? '';

        $stmt = $pdo->prepare("UPDATE customers SET
            first_name=:fn, last_name=:ln, business_name=:bn,
            email=:em, mobile=:mo,
            gstin=:gs, pan_no=:pn,
            address_line1=:al1, address_line2=:al2,
            address_city=:ac, city=:ci,
            address_state=:as2, state=:st,
            address_country=:acn, country=:cn,
            pincode=:pc, show_title_in_shipping=:sht
            WHERE id=:id");
        $stmt->execute([
            ':fn'  => $_POST['first_name']    ?? '',
            ':ln'  => $_POST['last_name']     ?? '',
            ':bn'  => $_POST['business_name'] ?? '',
            ':em'  => $_POST['email']         ?? '',
            ':mo'  => $_POST['mobile']        ?? '',
            ':gs'  => strtoupper($_POST['gstin']  ?? ''),
            ':pn'  => strtoupper($_POST['pan_no'] ?? ''),
            ':al1' => $_POST['address_line1'] ?? '',
            ':al2' => $_POST['address_line2'] ?? '',
            ':ac'  => $city,
            ':ci'  => $city,
            ':as2' => $state,
            ':st'  => $state,
            ':acn' => $country,
            ':cn'  => $country,
            ':pc'  => $_POST['pincode'] ?? '',
            ':sht' => $show_title,
            ':id'  => $id,
        ]);
    }

    echo json_encode(['status' => 'success']);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
<?php
require_once 'db.php';
header('Content-Type: application/json');

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if ($id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid customer ID']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Get customer business name to match invoices
    $stmt = $pdo->prepare("SELECT business_name FROM customers WHERE id = ?");
    $stmt->execute([$id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Customer not found']);
        exit;
    }

    // Get all invoice IDs for this customer
    $invStmt = $pdo->prepare("SELECT id FROM invoices WHERE customer = ?");
    $invStmt->execute([$customer['business_name']]);
    $invoiceIds = $invStmt->fetchAll(PDO::FETCH_COLUMN);

    // Delete invoice_amounts and invoices
    if (!empty($invoiceIds)) {
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $pdo->prepare("DELETE FROM invoice_amounts WHERE invoice_id IN ($placeholders)")->execute($invoiceIds);
        $pdo->prepare("DELETE FROM invoices WHERE id IN ($placeholders)")->execute($invoiceIds);
    }

    // Delete the customer
    $pdo->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);

    $pdo->commit();
    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
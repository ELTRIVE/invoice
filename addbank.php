<?php
require_once 'db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $bank_name = $_POST['bank_name'] ?? '';
    $branch = $_POST['branch'] ?? '';
    $account_no = $_POST['account_no'] ?? '';
    $ifsc_code = $_POST['ifsc_code'] ?? '';

    // Check required fields
    if (empty($bank_name) || empty($account_no)) {
        echo json_encode([
            "status" => "error",
            "message" => "Required fields missing"
        ]);
        exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO bank_details (bank_name, branch, account_no, ifsc_code)
            VALUES (?, ?, ?, ?)
        ");

        $stmt->execute([$bank_name, $branch, $account_no, $ifsc_code]);

        echo json_encode([
            "status" => "success",
            "id" => $pdo->lastInsertId(),
            "bank_name" => $bank_name
        ]);

    } catch (PDOException $e) {
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
    }

}
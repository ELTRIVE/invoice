<?php
require_once 'db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['signature_name'] ?? '';

    if (empty($name)) {
        echo json_encode(["status" => "error", "message" => "Signature name is required"]);
        exit;
    }

    if (!isset($_FILES['signature_image']) || $_FILES['signature_image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["status" => "error", "message" => "Signature image is required"]);
        exit;
    }

    $file = $_FILES['signature_image'];
    
    // Check file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($file['type'], $allowedTypes)) {
        echo json_encode(["status" => "error", "message" => "Invalid file format. Only JPG, PNG, WEBP allowed"]);
        exit;
    }

    // Check file size (e.g., max 2MB)
    if ($file['size'] > 2 * 1024 * 1024) {
        echo json_encode(["status" => "error", "message" => "File size exceeds 2MB limit"]);
        exit;
    }

    // Prepare upload directory
    $uploadDir = 'uploads/signatures/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid('sign_') . '.' . $extension;
    $targetPath = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO signatures (signature_name, file_path) VALUES (?, ?)");
            $stmt->execute([$name, $targetPath]);
            $newId = $pdo->lastInsertId();

            echo json_encode([
                "status" => "success",
                "id" => $newId,
                "signature_name" => $name,
                "file_path" => $targetPath
            ]);
        } catch (PDOException $e) {
            // Remove uploaded file on DB error
            unlink($targetPath);
            echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to move uploaded file"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method"]);
}
?>

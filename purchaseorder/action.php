<?php
require_once dirname(__DIR__) . '/db.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

switch ($action) {

    case 'delete':
        try {
            // po_terms deleted automatically via ON DELETE CASCADE on purchase_orders
            $pdo->prepare("DELETE FROM purchase_orders WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    case 'approve':
        try {
            $pdo->prepare("UPDATE purchase_orders SET status='Approved' WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    case 'complete':
        try {
            $pdo->prepare("UPDATE purchase_orders SET status='Completed' WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'Unknown action']);
}
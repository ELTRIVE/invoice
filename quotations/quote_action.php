<?php
require_once dirname(__DIR__) . '/db.php';
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

switch ($action) {
    case 'delete':
        try {
            $pdo->prepare("DELETE FROM quotations WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    case 'approve':
        try {
            $pdo->prepare("UPDATE quotations SET status='Approved' WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    case 'reject':
        try {
            $pdo->prepare("UPDATE quotations SET status='Rejected' WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    case 'send':
        try {
            $pdo->prepare("UPDATE quotations SET status='Sent' WHERE id=?")->execute([$id]);
            echo json_encode(['success'=>true]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    case 'convert_to_invoice':
        try {
            $q = $pdo->prepare("SELECT * FROM quotations WHERE id=?");
            $q->execute([$id]);
            $quot = $q->fetch(PDO::FETCH_ASSOC);
            if (!$quot) { echo json_encode(['success'=>false,'message'=>'Quotation not found']); exit; }

            // Get next invoice number
            $last = $pdo->query("SELECT invoice_number FROM invoices ORDER BY id DESC LIMIT 1")->fetchColumn();
            $nextNum = 101;
            if ($last) {
                preg_match('/(\d+)$/', $last, $m);
                $nextNum = (int)($m[1] ?? 100) + 1;
            }
            $inv_number = 'ELT2526' . $nextNum;

            // Insert into invoices
            $pdo->prepare("
                INSERT INTO invoices (customer, contact_person, billing_address, gstin,
                    invoice_number, reference, invoice_date, due_date, item_list, items_json)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $quot['customer_name'],
                $quot['contact_person'],
                $quot['customer_address'],
                $quot['customer_gstin'],
                $inv_number,
                'QT-' . $quot['quot_number'],
                date('Y-m-d'),
                date('Y-m-d', strtotime('+30 days')),
                $quot['item_list'],
                $quot['items_json'],
            ]);
            $inv_id = $pdo->lastInsertId();

            // Insert items into invoice_amounts
            $items = json_decode($quot['items_json'] ?? '[]', true) ?: [];
            $insAmt = $pdo->prepare("
                INSERT INTO invoice_amounts
                    (invoice_id, invoice_no, invoice_date, item_id,
                     service_code, hsn_sac, description, uom,
                     qty, unit_price, discount, basic_amount,
                     cgst_percent, sgst_percent, igst_percent,
                     cgst_amount, sgst_amount, igst_amount, tcs_percent, total)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            foreach ($items as $item) {
                $insAmt->execute([
                    $inv_id, $inv_number, date('Y-m-d'),
                    (int)($item['item_id'] ?? 0),
                    '', $item['hsn_sac'] ?? '', $item['description'] ?? '',
                    $item['unit'] ?? '',
                    $item['qty'] ?? 1, $item['rate'] ?? 0,
                    $item['discount'] ?? 0, $item['taxable'] ?? 0,
                    $item['cgst_pct'] ?? 0, $item['sgst_pct'] ?? 0, $item['igst_pct'] ?? 0,
                    $item['cgst_amt'] ?? 0, $item['sgst_amt'] ?? 0, $item['igst_amt'] ?? 0,
                    0, $item['amount'] ?? 0,
                ]);
            }

            // Update quotation status to Approved
            $pdo->prepare("UPDATE quotations SET status='Approved' WHERE id=?")->execute([$id]);

            echo json_encode(['success'=>true,'invoice_id'=>$inv_id,'invoice_number'=>$inv_number]);
        } catch (Exception $e) {
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success'=>false,'message'=>'Unknown action']);
}
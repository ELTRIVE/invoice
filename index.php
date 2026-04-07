<?php if(!isset($_GET["view"]) && !isset($_GET["ajax"])) { header("Location: dashboard.php"); exit; }

function indianFormat($n, $decimals=2){
    $n=round($n,$decimals); $neg=$n<0?'-':''; $n=abs($n);
    $dec=($decimals>0)?('.'.str_pad((string)round(($n-floor($n))*pow(10,$decimals)),$decimals,'0',STR_PAD_LEFT)):'';
    $int=(string)(int)floor($n);
    if(strlen($int)<=3) return $neg.$int.$dec;
    $last3=substr($int,-3); $rest=substr($int,0,-3);
    $rest=preg_replace('/\B(?=(\d{2})+(?!\d))/',',',$rest);
    return $neg.$rest.','.$last3.$dec;
}
require_once 'db.php';
date_default_timezone_set('Asia/Kolkata');

// Auto-format invoice number: if purely numeric, prefix with ELT2526
function fmtInvNo($n) {
    $n = trim((string)$n);
    if (is_numeric($n)) return 'ELT2526' . str_pad((int)$n, 4, '0', STR_PAD_LEFT);
    return $n;
}




// ══════════════════════════════════════════════════════
// AJAX: GET customer invoices  →  ?ajax=get_invoices
// ══════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_invoices') {
    header('Content-Type: application/json');
    $customer = trim($_GET['customer'] ?? '');
    if (empty($customer)) { echo json_encode(['invoices'=>[],'total_pending'=>0]); exit; }

    $stmt = $pdo->prepare("
        SELECT i.id, i.invoice_number,
               DATE_FORMAT(i.invoice_date,'%d-%b-%y') AS date,
               i.payment_status AS status,
               i.amount_received,
               COALESCE(ia.grand_total,0) AS grand_total,
               GREATEST(0, COALESCE(ia.grand_total,0) - COALESCE(i.amount_received,0)) AS amount_pending
        FROM invoices i
        LEFT JOIN (
            SELECT invoice_id, SUM(total) AS grand_total
            FROM invoice_amounts
            WHERE COALESCE(service_code,'') != 'PAYMENT'
            GROUP BY invoice_id
        ) ia ON ia.invoice_id = i.id
        WHERE i.customer = ? AND i.payment_status IN ('Unpaid','Partial')
        ORDER BY i.id ASC
    ");
    $stmt->execute([$customer]);
    $invoices     = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalPending = array_reduce($invoices, function($carry, $inv) {
        $gt = floatval($inv['grand_total'] ?? 0);
        if ($gt <= 0) return $carry;
        return $carry + floatval($inv['amount_pending'] ?? 0);
    }, 0);

    if (empty($invoices)) {
        $stmt2 = $pdo->prepare("
            SELECT i.id, i.invoice_number,
                   DATE_FORMAT(i.invoice_date,'%d-%b-%y') AS date,
                   i.payment_status AS status,
                   i.amount_received,
                   COALESCE(ia.grand_total,0) AS grand_total,
                   GREATEST(0, COALESCE(ia.grand_total,0) - COALESCE(i.amount_received,0)) AS amount_pending
            FROM invoices i
            LEFT JOIN (
                SELECT invoice_id, SUM(total) AS grand_total
                FROM invoice_amounts
                WHERE COALESCE(service_code,'') != 'PAYMENT'
                GROUP BY invoice_id
            ) ia ON ia.invoice_id = i.id
            WHERE i.customer = ?
            ORDER BY i.id DESC LIMIT 5
        ");
        $stmt2->execute([$customer]);
        $invoices     = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $totalPending = 0;
    }
    // Format invoice numbers before returning
    foreach ($invoices as &$inv) {
        $n = trim((string)($inv['invoice_number'] ?? ''));
        $inv['invoice_number'] = is_numeric($n) ? 'ELT2526'.str_pad((int)$n,4,'0',STR_PAD_LEFT) : $n;
    }
    unset($inv);
    echo json_encode(['invoices'=>$invoices,'total_pending'=>$totalPending]);
    exit;
}

// ══════════════════════════════════════════════════════
// AJAX: Save payment  →  POST ?ajax=save_payment
// ══════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_GET['ajax']) && $_GET['ajax']==='save_payment') {
    header('Content-Type: application/json');
    $data            = json_decode(file_get_contents('php://input'),true) ?: $_POST;
    $invoicePayments = $data['invoice_payments'] ?? [];
    $note            = trim($data['note'] ?? '');
    $today           = date('Y-m-d');
    if (empty($invoicePayments)) { echo json_encode(['success'=>false,'message'=>'No payment data']); exit; }
    try {
        $pdo->beginTransaction();
        foreach ($invoicePayments as $item) {
            $invoiceId    = (int)($item['invoice_id'] ?? 0);
            $received     = floatval($item['received'] ?? 0);
            $manualStatus = trim($item['manual_status'] ?? '');

            // Skip rows with no invoice id or nothing to process
            if ($invoiceId <= 0) continue;
            if ($received <= 0 && !in_array($manualStatus, ['Unpaid','Partial','Paid'])) continue;

            // Get grand total from invoice_amounts (source of truth for invoice value)
            $gs = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM invoice_amounts WHERE invoice_id=? AND COALESCE(service_code,'') != 'PAYMENT'");
            $gs->execute([$invoiceId]);
            $grandTotal = floatval($gs->fetchColumn());

            // Get already received amount from invoices table
            $s2 = $pdo->prepare("SELECT COALESCE(amount_received,0) FROM invoices WHERE id=?");
            $s2->execute([$invoiceId]);
            $alreadyReceived = floatval($s2->fetchColumn());

            // Calculate new received and pending based on actual amounts only
            $newReceived = $alreadyReceived + $received;
            $pending     = max(0, $grandTotal - $newReceived);

            // Determine status automatically from actual amounts
            if ($newReceived <= 0)    { $status = 'Unpaid';  $pending = $grandTotal; $newReceived = 0; }
            elseif ($pending <= 0.01) { $status = 'Paid';    $pending = 0; }
            else                      { $status = 'Partial'; }

            // Only allow manual Unpaid override (to reset a payment)
            if ($manualStatus === 'Unpaid') {
                $status = 'Unpaid'; $pending = $grandTotal; $newReceived = 0;
            }

            // Update invoices table — drives dashboard + invoice list display
            $pdo->prepare("UPDATE invoices SET amount_received=?, amount_pending=?, payment_status=? WHERE id=?")
                ->execute([$newReceived, $pending, $status, $invoiceId]);

            // Update payment info on all invoice_amounts rows for this invoice
            $updAmt = $pdo->prepare("UPDATE invoice_amounts SET payment_received=?, payment_date=?, payment_note=? WHERE invoice_id=? AND (service_code IS NULL OR service_code != 'PAYMENT')");
            $updAmt->execute([$newReceived, $today, $note, $invoiceId]);
            $rowsUpdated = $updAmt->rowCount();

            // Clean up any stray PAYMENT rows in invoice_amounts
            $pdo->prepare("DELETE FROM invoice_amounts WHERE invoice_id=? AND service_code='PAYMENT'")->execute([$invoiceId]);
        }
        $pdo->commit();
        echo json_encode(['success'=>true,'rows_updated'=>$rowsUpdated??0]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ══════════════════════════════════════════════════════
// AJAX: Global invoice search  →  ?ajax=search_invoices
// ══════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_invoices') {
    header('Content-Type: application/json');
    $q       = trim($_GET['search'] ?? '');
    $statusF = $_GET['status'] ?? 'all';
    $execFilter = $_GET['executive_id'] ?? '';

    // Always search all dates when a query is present
    $whereClauses = ["i.invoice_date BETWEEN '2000-01-01' AND '2099-12-31'"];
    $bindParams   = [];

    if ($statusF === 'paid')    $whereClauses[] = "i.payment_status='Paid'";
    elseif ($statusF === 'unpaid')  $whereClauses[] = "i.payment_status='Unpaid'";
    elseif ($statusF === 'partial') $whereClauses[] = "i.payment_status='Partial'";

    if ($execFilter === 'unassigned') {
        $whereClauses[] = "(i.executive_id IS NULL OR i.executive_id = 0)";
    } elseif ((int)$execFilter > 0) {
        $whereClauses[] = "i.executive_id = ?";
        $bindParams[] = (int)$execFilter;
    }

    if ($q !== '') {
        $whereClauses[] = "(i.customer LIKE ? OR i.invoice_number LIKE ?)";
        $bindParams[] = "%$q%";
        $bindParams[] = "%$q%";
    }

    $whereSQL = implode(' AND ', $whereClauses);

    $rs = $pdo->prepare("
        SELECT i.*,
               COALESCE(ia.grand_total,0) AS grand_total,
               COALESCE(ia.basic_total,0) AS basic_total,
               GREATEST(0, COALESCE(ia.grand_total,0) - COALESCE(i.amount_received,0)) AS amount_pending
        FROM invoices i
        LEFT JOIN (
            SELECT invoice_id,
                   SUM(total)        AS grand_total,
                   SUM(basic_amount) AS basic_total
            FROM invoice_amounts
            WHERE (service_code!='PAYMENT' OR service_code IS NULL)
            GROUP BY invoice_id
        ) ia ON ia.invoice_id = i.id
        WHERE $whereSQL ORDER BY i.id DESC LIMIT 200
    ");
    $rs->execute($bindParams);
    $rows = $rs->fetchAll(PDO::FETCH_ASSOC);

    $cs = $pdo->prepare("SELECT COUNT(*) FROM invoices i WHERE $whereSQL");
    $cs->execute($bindParams);
    $cnt = (int)$cs->fetchColumn();

    ob_start();
    if (empty($rows)) {
        echo '<tr id="noResultsRow"><td colspan="8" style="text-align:center;padding:30px;color:#9ca3af;"><i class="fas fa-search" style="margin-right:8px;color:#f97316"></i>No invoices found for "<strong>'.htmlspecialchars($q).'</strong>"</td></tr>';
    } else {
        foreach ($rows as $inv) {
            $gt    = floatval($inv['grand_total']);
            $pnd   = floatval($inv['amount_pending']);
            $ps    = ($gt <= 0) ? 'Paid' : ($inv['payment_status'] ?? 'Unpaid');
            $psCls = strtolower($ps);
            $invNo = fmtInvNo($inv['invoice_number']);
            $obj   = htmlspecialchars(json_encode($inv));
            echo '<tr class="invoice-row"
                data-id="'.htmlspecialchars($inv['id']).'"
                data-customer="'.htmlspecialchars($inv['customer']).'"
                data-invno="'.htmlspecialchars($invNo).'"
                data-date="'.date('d M Y',strtotime($inv['invoice_date'])).'"
                data-total="'.$gt.'"
                data-pending="'.$pnd.'"
                data-status="'.htmlspecialchars($ps).'"
                onclick="openDownloadModal(this)">';
            echo '<td>'.htmlspecialchars($inv['customer']).'</td>';
            echo '<td>'.htmlspecialchars($invNo).'</td>';
            echo '<td>'.date('d-M-y',strtotime($inv['invoice_date'])).'</td>';
            echo '<td>'.indianFormat(floatval($inv['basic_total']),2).'</td>';
            echo '<td>'.indianFormat($gt,2).'</td>';
            $icon = $psCls==='paid' ? 'fa-check-circle' : ($psCls==='partial' ? 'fa-adjust' : 'fa-clock');
            echo '<td><span class="status-pill '.$psCls.'" onclick="openPayModal(event,this.closest(\'tr\'))"><i class="fas '.$icon.'"></i> '.$ps.'</span></td>';
            echo '<td class="'.($pnd>0?'pnd-amt':'pnd-zero').'">'.($pnd>0?indianFormat($pnd,2):'0.00').'</td>';
            echo '<td onclick="event.stopPropagation()"><a href="create_invoice?edit='.htmlspecialchars($inv['id']).'" class="edit-btn" title="Edit Invoice"><i class="fas fa-pen"></i></a></td>';
            echo '</tr>';
        }
    }
    $html = ob_get_clean();
    echo json_encode(['html' => $html, 'count' => $cnt]);
    exit;
}

// ══════════════════════════════════════════════════════
// AJAX: Global customer search  →  ?ajax=search_customers
// ══════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_customers') {
    header('Content-Type: application/json');
    $q = trim($_GET['search'] ?? '');
    $where = ['1=1']; $params = [];
    if ($q !== '') {
        $where[] = "(first_name LIKE ? OR last_name LIKE ? OR business_name LIKE ? OR email LIKE ?)";
        $params  = ["%$q%", "%$q%", "%$q%", "%$q%"];
    }
    $wsql = implode(' AND ', $where);
    $cs = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE $wsql");
    $cs->execute($params);
    $cnt = (int)$cs->fetchColumn();
    $rs = $pdo->prepare("SELECT * FROM customers WHERE $wsql ORDER BY id DESC LIMIT 200");
    $rs->execute($params);
    $rows = $rs->fetchAll(PDO::FETCH_ASSOC);
    ob_start();
    if (empty($rows)) {
        echo '<tr><td colspan="3" style="text-align:center;padding:30px;color:#9ca3af;">No customers found.</td></tr>';
    } else {
        foreach ($rows as $cust) {
            echo '<tr>';
            echo '<td>'.htmlspecialchars($cust['first_name'].' '.($cust['last_name']??'')).'</td>';
            echo '<td>'.htmlspecialchars($cust['business_name']).'</td>';
            echo '<td><a href="editcustomer.php?id='.htmlspecialchars($cust['id']).'" class="edit-btn"><i class="fas fa-pen"></i></a></td>';
            echo '</tr>';
        }
    }
    $html = ob_get_clean();
    echo json_encode(['html' => $html, 'count' => $cnt]);
    exit;
}

// ══════════════════════════════════════════════════════
// NORMAL PAGE
// ══════════════════════════════════════════════════════
$view     = $_GET['view']    ?? 'invoices';
$perPage  = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;
$invPage  = max(1, (int)($_GET['inv_page']  ?? 1));
$custPage = max(1, (int)($_GET['cust_page'] ?? 1));
$period   = $_GET['period']  ?? 'all';
$statusF  = $_GET['status']  ?? 'all';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to']   ?? '';

// ── SORT ──
$sortCol = $_GET['sort_col'] ?? '';
$sortDir = $_GET['sort_dir'] ?? 'asc';
$allowedSorts = [
    'customer'       => 'i.customer',
    'invoice_number' => 'i.invoice_number',
    'invoice_date'   => 'i.invoice_date',
    'basic_total'    => 'COALESCE(ia.basic_total,0)',
    'grand_total'    => 'COALESCE(ia.grand_total,0)',
    'payment_status' => 'i.payment_status',
    'amount_pending' => 'GREATEST(0, COALESCE(ia.grand_total,0) - COALESCE(i.amount_received,0))',
];
$orderSQL = 'i.id DESC';
if ($sortCol && isset($allowedSorts[$sortCol])) {
    $dir = ($sortDir === 'desc') ? 'DESC' : 'ASC';
    $orderSQL = $allowedSorts[$sortCol] . ' ' . $dir . ', i.id DESC';
}

$today2 = date('Y-m-d'); $y=date('Y'); $m=date('n');
$finYearStart = ($m>=4)?"$y-04-01":(($y-1)."-04-01");
$finYearEnd   = ($m>=4)?(($y+1)."-03-31"):("$y-03-31");

switch($period){
    case 'today':       $from=$to=$today2; break;
    case 'this_week':   $from=date('Y-m-d',strtotime('monday this week')); $to=date('Y-m-d',strtotime('sunday this week')); break;
    case 'this_month':  $from=date('Y-m-01'); $to=date('Y-m-t'); break;
    case 'last_month':  $from=date('Y-m-01',strtotime('first day of last month')); $to=date('Y-m-t',strtotime('last day of last month')); break;
    case 'this_quarter':
        $qS=[1=>1,2=>1,3=>1,4=>4,5=>4,6=>4,7=>7,8=>7,9=>7,10=>10,11=>10,12=>10][$m];
        $from=date("Y-$qS-01"); $to=date('Y-m-t',strtotime(date("Y-".($qS+2)."-01"))); break;
    case 'this_year':   $from=$finYearStart; $to=$finYearEnd; break;
    case 'last_year':   $from=($m>=4)?(($y-1).'-04-01'):(($y-2).'-04-01'); $to=($m>=4)?("$y-03-31"):(($y-1).'-03-31'); break;
    case 'all':         $from='2000-01-01'; $to='2099-12-31'; break;
    case 'custom':      $from=$dateFrom?:$finYearStart; $to=$dateTo?:$today2; break;
    default:            $from=$finYearStart; $to=$finYearEnd;
}

$whereClauses=["i.invoice_date BETWEEN ? AND ?"]; $bindParams=[$from,$to];
if($statusF==='paid')    $whereClauses[]="i.payment_status='Paid'";
elseif($statusF==='unpaid')  $whereClauses[]="i.payment_status='Unpaid'";
elseif($statusF==='partial') $whereClauses[]="i.payment_status='Partial'";

// Executive filter
$execFilter = $_GET['executive_id'] ?? '';
if ($execFilter === 'unassigned') {
    $whereClauses[] = "(i.executive_id IS NULL OR i.executive_id = 0)";
} elseif ((int)$execFilter > 0) {
    $whereClauses[] = "i.executive_id = ?";
    $bindParams[] = (int)$execFilter;
}

// Fetch all executives for dropdown
try { $allExecs = $pdo->query("SELECT id, name FROM executives ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC); } catch(Exception $e) { $allExecs = []; }

$whereSQL=implode(' AND ',$whereClauses);

// Count totals for stats (all records, no pagination)
$countStmt=$pdo->prepare("
    SELECT COUNT(*) AS cnt,
           COALESCE(SUM(ia.grand_total),0) AS grand_total,
           COALESCE(SUM(ia.basic_total),0) AS basic_total
    FROM invoices i
    LEFT JOIN (
        SELECT invoice_id,
               SUM(total)        AS grand_total,
               SUM(basic_amount) AS basic_total
        FROM invoice_amounts
        WHERE (service_code!='PAYMENT' OR service_code IS NULL)
        GROUP BY invoice_id
    ) ia ON ia.invoice_id=i.id
    WHERE $whereSQL
");
$countStmt->execute($bindParams);
$countRow     = $countStmt->fetch(PDO::FETCH_ASSOC);
$invoiceCount = (int)$countRow['cnt'];
$totalAmount  = floatval($countRow['grand_total']);
$invTotalPages = max(1, ceil($invoiceCount / $perPage));
$invPage = min($invPage, $invTotalPages);
$invOffset = ($invPage - 1) * $perPage;

// Pending (need all records for accurate total)
$pendStmt=$pdo->prepare("
    SELECT COALESCE(ia.grand_total,0) AS grand_total, COALESCE(i.amount_received,0) AS amount_received
    FROM invoices i
    LEFT JOIN (
        SELECT invoice_id, SUM(total) AS grand_total
        FROM invoice_amounts WHERE (service_code!='PAYMENT' OR service_code IS NULL) GROUP BY invoice_id
    ) ia ON ia.invoice_id=i.id
    WHERE $whereSQL
");
$pendStmt->execute($bindParams);
$allForPend = $pendStmt->fetchAll(PDO::FETCH_ASSOC);
$totalPending = array_reduce($allForPend, function($carry, $inv) {
    $gt = floatval($inv['grand_total']);
    if ($gt <= 0) return $carry;
    return $carry + max(0, $gt - floatval($inv['amount_received']));
}, 0);

// Paginated invoices
$stmt=$pdo->prepare("
    SELECT i.*,
           COALESCE(ia.grand_total,0) AS grand_total,
           COALESCE(ia.basic_total,0) AS basic_total,
           GREATEST(0, COALESCE(ia.grand_total,0) - COALESCE(i.amount_received,0)) AS amount_pending
    FROM invoices i
    LEFT JOIN (
        SELECT invoice_id,
               SUM(total)        AS grand_total,
               SUM(basic_amount) AS basic_total
        FROM invoice_amounts
        WHERE (service_code!='PAYMENT' OR service_code IS NULL)
        GROUP BY invoice_id
    ) ia ON ia.invoice_id=i.id
    WHERE $whereSQL ORDER BY $orderSQL
    LIMIT $perPage OFFSET $invOffset
");
$stmt->execute($bindParams);
$invoices=$stmt->fetchAll(PDO::FETCH_ASSOC);


// Customers with pagination + search + sort
$custSearch  = trim($_GET['cust_search'] ?? '');
$custSortCol = $_GET['cust_sort_col'] ?? '';
$custSortDir = $_GET['cust_sort_dir'] ?? 'asc';
$custAllowedSorts = ['first_name'=>'first_name','last_name'=>'last_name','business_name'=>'business_name','email'=>'email'];
$custOrderSql = 'id DESC';
if ($custSortCol && isset($custAllowedSorts[$custSortCol])) {
    $custSdir = ($custSortDir === 'desc') ? 'DESC' : 'ASC';
    $custOrderSql = $custAllowedSorts[$custSortCol] . ' ' . $custSdir;
}
$custWhere  = ['1=1'];
$custParams = [];
if ($custSearch !== '') {
    $custWhere[]       = '(first_name LIKE :cs OR last_name LIKE :cs OR business_name LIKE :cs OR email LIKE :cs)';
    $custParams[':cs'] = "%$custSearch%";
}
$custWhereSql = implode(' AND ', $custWhere);
$custCountStmt = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE $custWhereSql");
$custCountStmt->execute($custParams);
$custTotal = (int)$custCountStmt->fetchColumn();
$custTotalPages = max(1, ceil($custTotal / $perPage));
$custPage = min($custPage, $custTotalPages);
$custOffset = ($custPage - 1) * $perPage;
$custStmt = $pdo->prepare("SELECT * FROM customers WHERE $custWhereSql ORDER BY $custOrderSql LIMIT $perPage OFFSET $custOffset");
$custStmt->execute($custParams);
$customers=$custStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Invoices</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
.pagination{display:flex;justify-content:center;align-items:center;gap:5px;padding:16px 0 8px}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;
  min-width:32px;height:32px;padding:0 8px;border-radius:7px;font-size:13px;font-weight:600;
  text-decoration:none;border:1.5px solid #e4e8f0;color:#374151;background:#fff;transition:all .15s}
.pagination a:hover{border-color:#16a34a;color:#16a34a;background:#f0fdf4}
.pagination span.active{background:#16a34a;color:#fff;border-color:#16a34a}
.pagination span.dots{border:none;background:none;color:#9ca3af}
body{font-family:'Times New Roman',Times,serif;background:#f4f6fb}
.content{margin-left:220px;padding:32px 28px 28px}
h2{font-weight:700;margin-bottom:20px;color:#1a1f2e;font-size:22px}

/* Filter */
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.filter-bar select,.filter-bar input[type=date]{
    padding:7px 12px;border:1.5px solid #e2e8f0;border-radius:8px;
    font-size:13px;font-family:'Times New Roman',Times,serif;
    color:#374151;background:#fff;cursor:pointer;outline:none}
.filter-bar select:focus,.filter-bar input[type=date]:focus{border-color:#f97316}
.custom-dates{display:none;gap:8px;align-items:center}
.custom-dates.show{display:flex}

/* Stat badges */
.stat-badges{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.stat-badge{
    display:inline-flex;align-items:center;gap:6px;
    padding:6px 16px;border-radius:8px;border:1.5px solid #f97316;
    background:#fff;font-size:13px;color:#374151;white-space:nowrap}
.stat-badge .label{color:#6b7280}
.stat-badge .value{font-weight:700;color:#f97316}
.stat-badge.green{border-color:#16a34a}.stat-badge.green .value{color:#16a34a}
.stat-badge.blue {border-color:#2563eb}.stat-badge.blue  .value{color:#2563eb}
.stat-badge.red  {border-color:#f97316}.stat-badge.red   .value{color:#f97316}

/* Card & table */
.card{background:#fff;border-radius:14px;padding:20px;border:1px solid #e4e8f0}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;padding:0 12px 12px 0}
td{padding:13px 12px 13px 0;border-top:1px solid #f1f5f9;font-size:14px;color:#1a1f2e}
.invoice-row{cursor:pointer;transition:background .15s}
.invoice-row:hover{background:#fff7f0}

/* Status pills — matching orange theme */
.status-pill{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;
    cursor:pointer;transition:transform .15s,box-shadow .15s;white-space:nowrap}
.status-pill:hover{transform:scale(1.06);box-shadow:0 3px 10px rgba(0,0,0,.1)}
.status-pill.paid   {background:#dcfce7;color:#16a34a}
.status-pill.partial{background:#fff7ed;color:#f97316;border:1px solid #fed7aa}
.status-pill.unpaid {background:#fff7ed;color:#f97316;border:1px solid #fed7aa}

/* Pending amount */
.pnd-amt {font-weight:700;color:#f97316}
.pnd-zero{color:#9ca3af}

/* Buttons */
.header-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.btn{
    display:inline-flex;align-items:center;gap:6px;padding:10px 18px;
    border-radius:8px;background:#f97316;color:#fff;text-decoration:none;
    font-size:14px;font-weight:600;border:none;cursor:pointer;
    font-family:'Times New Roman',Times,serif;transition:background .2s}
.btn:hover{background:#fb923c}
.btn-green{background:#16a34a}.btn-green:hover{background:#15803d}
.edit-btn{
    display:inline-flex;align-items:center;justify-content:center;
    width:32px;height:32px;border-radius:8px;background:#f4f6fb;
    color:#6b7280;border:1px solid #e2e8f0;text-decoration:none;font-size:13px;transition:all .2s}
.edit-btn:hover{background:#f97316;color:#fff}

/* ── DOWNLOAD MODAL ── */
.dl-overlay{
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);
    backdrop-filter:blur(3px);z-index:1050;justify-content:center;align-items:center}
.dl-overlay.open{display:flex}
.dl-modal{
    background:#fff;width:min(92vw,420px);
    border-radius:16px;overflow:hidden;
    box-shadow:0 16px 48px rgba(0,0,0,.14);animation:slideUp .25s ease}
.dl-header{
    background:#fafbfc;padding:20px 22px 16px;
    border-bottom:1px solid #e4e8f0;position:relative}
.dl-close{
    position:absolute;top:14px;right:14px;font-size:16px;cursor:pointer;
    color:#9ca3af;width:28px;height:28px;display:flex;align-items:center;
    justify-content:center;border-radius:50%;border:none;background:none;transition:all .2s}
.dl-close:hover{background:#f1f5f9;color:#374151}
.dl-invno{
    display:inline-flex;align-items:center;gap:7px;
    font-size:12px;font-weight:600;color:#6b7280;margin-bottom:5px}
.dl-invno i{color:#16a34a;font-size:12px}
.dl-date-text{color:#16a34a;font-weight:700}
.dl-customer{font-size:19px;font-weight:800;color:#1a1f2e}
.dl-body{padding:16px 22px 20px}
.dl-sec{font-size:10px;font-weight:700;color:#9ca3af;
    text-transform:uppercase;letter-spacing:.1em;margin:0 0 10px}
.dl-fin-row{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:12px;align-items:center}
.dl-fin-pill{
    padding:6px 12px;border-radius:8px;font-size:12px;font-weight:600;
    border:1.5px solid;white-space:nowrap}
.dl-fin-pill.amount {background:#f8fafc;color:#374151;border-color:#e2e8f0}
.dl-fin-pill.pending{
    background:#f0fdf4;color:#16a34a;border-color:#bbf7d0;
    display:flex;align-items:center;gap:6px}
.dl-icon-btn{
    width:24px;height:24px;border-radius:6px;background:#dcfce7;
    display:inline-flex;align-items:center;justify-content:center;
    font-size:11px;color:#16a34a;cursor:pointer;border:none;transition:background .15s}
.dl-icon-btn:hover{background:#bbf7d0}
.dl-status-row{margin-bottom:16px}
.dl-status-pill{
    display:inline-flex;align-items:center;gap:6px;
    padding:5px 12px;border-radius:8px;font-size:12px;font-weight:600;border:1.5px solid}
.dl-status-pill.paid   {background:#f0fdf4;color:#16a34a;border-color:#bbf7d0}
.dl-status-pill.partial{background:#fff7ed;color:#f97316;border-color:#fed7aa}
.dl-status-pill.unpaid {background:#fff7ed;color:#f97316;border-color:#fed7aa}
.dl-action-row{display:flex;gap:10px;margin-top:4px}
.dl-action-btn{
    flex:1;display:flex;align-items:center;justify-content:center;gap:8px;
    padding:12px;border-radius:10px;font-size:13px;font-weight:700;
    font-family:'Times New Roman',Times,serif;text-decoration:none;
    cursor:pointer;border:none;transition:all .2s}
.dl-action-btn.download{background:#f97316;color:#fff}
.dl-action-btn.download:hover{background:#fb923c;transform:translateY(-1px)}
.dl-action-btn.cancel{background:#f1f5f9;color:#374151;border:1.5px solid #e2e8f0}
.dl-action-btn.cancel:hover{background:#e4e8f0}

/* ── PAYMENT MODAL ── */
.pay-overlay{
    display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);
    backdrop-filter:blur(3px);z-index:1100;justify-content:center;align-items:center}
.pay-overlay.open{display:flex}
.pay-modal{
    background:#fff;width:min(92vw,560px);max-height:90vh;overflow-y:auto;
    border-radius:16px;padding:28px 30px 24px;position:relative;
    box-shadow:0 24px 64px rgba(0,0,0,.18);animation:slideUp .25s ease}
@keyframes slideUp{from{transform:translateY(20px);opacity:0}to{transform:translateY(0);opacity:1}}
.pay-close{
    position:absolute;top:14px;right:16px;font-size:20px;cursor:pointer;
    color:#9ca3af;width:30px;height:30px;display:flex;align-items:center;
    justify-content:center;border-radius:50%;border:none;background:none;transition:all .2s}
.pay-close:hover{background:#f4f6fb;color:#374151}
.pay-subtitle{font-size:12px;color:#9ca3af;margin-bottom:2px}
.pay-customer{font-size:22px;font-weight:800;color:#1a1f2e;margin-bottom:14px}
.pay-receivable{
    display:inline-flex;align-items:center;gap:6px;padding:5px 14px;
    border:1.5px solid #16a34a;border-radius:8px;background:#f0fdf4;
    font-size:13px;font-weight:700;color:#16a34a;margin-bottom:18px}
.pay-total-row{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.pay-total-row label{font-size:13px;font-weight:600;color:#374151;min-width:140px}
.inp-box{
    display:inline-flex;align-items:center;gap:5px;border:1.5px solid #e2e8f0;
    border-radius:8px;padding:7px 12px;background:#f8fafc;font-size:14px;color:#374151}
.inp-box span{color:#9ca3af}
.pay-check-row{display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;margin-bottom:8px}
.pay-check-row input[type=checkbox]{accent-color:#f97316;width:15px;height:15px}
.pay-note{
    width:100%;border:1.5px solid #e2e8f0;border-radius:8px;padding:9px 12px;
    font-size:13px;resize:vertical;font-family:'Times New Roman',Times,serif;
    color:#374151;outline:none;min-height:60px;margin-bottom:16px}
.pay-note:focus{border-color:#f97316}
.pay-section-title{font-size:16px;font-weight:800;color:#1a1f2e;margin-bottom:12px}
.pay-loading{text-align:center;padding:30px 0;color:#9ca3af;font-size:14px}
.pay-loading i{font-size:22px;color:#f97316;margin-bottom:8px;display:block;animation:spin 1s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.pay-inv-table{width:100%;border-collapse:collapse;margin-bottom:16px}
.pay-inv-table th{
    font-size:11px;text-transform:uppercase;letter-spacing:.06em;
    color:#9ca3af;padding-bottom:8px;text-align:left;padding-right:8px}
.pay-inv-table td{
    padding:9px 8px 9px 0;border-top:1px solid #f1f5f9;
    font-size:13px;color:#1a1f2e;vertical-align:middle}
.pay-inv-table select{
    padding:5px 8px;border:1.5px solid #e2e8f0;border-radius:7px;
    font-size:12px;font-family:'Times New Roman',Times,serif;
    color:#374151;background:#fff;cursor:pointer;outline:none}
.pay-inv-table select:focus{border-color:#f97316}
.recv-inp{
    width:110px;padding:6px 10px;border:1.5px solid #e2e8f0;border-radius:7px;
    font-size:13px;font-family:'Times New Roman',Times,serif;color:#374151;outline:none}
.recv-inp:focus{border-color:#f97316}
.copy-btn{background:none;border:none;cursor:pointer;color:#bbb;font-size:12px;padding:2px 4px;transition:color .15s}
.copy-btn:hover{color:#f97316}
.pay-acct-row{display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;margin-bottom:18px}
.pay-acct-row input[type=checkbox]{accent-color:#f97316;width:15px;height:15px}
.pay-save-btn{
    display:inline-flex;align-items:center;gap:8px;padding:11px 28px;
    background:#1a6b3c;color:#fff;border:none;border-radius:10px;
    font-size:15px;font-weight:700;cursor:pointer;
    font-family:'Times New Roman',Times,serif;transition:background .2s}
.pay-save-btn:hover{background:#15803d}
.pay-save-btn:disabled{background:#9ca3af;cursor:not-allowed}

/* Toast */
.toast{
    position:fixed;bottom:28px;right:28px;background:#1a1f2e;color:#fff;
    padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;
    z-index:2000;display:none;gap:8px;align-items:center;
    box-shadow:0 8px 24px rgba(0,0,0,.2)}
.toast.show{display:flex}
.toast.success{border-left:4px solid #16a34a}
.toast.error  {border-left:4px solid #f97316}

::-webkit-scrollbar{width:3px}
::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:99px}

/* ── SEARCH BAR ── */
.search-wrap{position:relative;margin-bottom:0;width:230px}
.search-wrap i.search-icon{
    position:absolute;left:12px;top:50%;transform:translateY(-50%);
    font-size:13px;pointer-events:none;
    font-style:normal;line-height:1}
#invoiceSearch{
    width:100%;padding:7px 28px 7px 34px;
    border:1.5px solid #d1d5db;border-radius:50px;
    font-size:12.5px;font-family:'Times New Roman',Times,serif;
    color:#374151;background:#fff;outline:none;
    box-shadow:0 1px 3px rgba(0,0,0,.06);transition:border-color .2s,box-shadow .2s}
#invoiceSearch:focus{border-color:#93c5fd;box-shadow:0 0 0 3px rgba(147,197,253,.2)}
#invoiceSearch::placeholder{color:#9ca3af;font-size:12px}
.search-clear{
    position:absolute;right:10px;top:50%;transform:translateY(-50%);
    background:none;border:none;cursor:pointer;color:#9ca3af;
    font-size:12px;display:none;padding:2px 3px;line-height:1}
.search-clear:hover{color:#374151}
/* ── SORT HEADERS ── */
.sort-th { white-space:nowrap; cursor:pointer; user-select:none; }
.sort-th:hover { color:#f97316; }
.sort-th .si { font-size:10px; color:#d1d5db; margin-left:4px; }
.sort-th.asc .si, .sort-th.desc .si { color:#f97316; }
/* Show entries */
.show-entries { display:flex; align-items:center; gap:8px; font-size:13px; color:#374151; margin-bottom:10px; }
.show-entries select { padding:5px 10px; border:1.5px solid #e2e8f0; border-radius:7px; font-size:13px;
    font-family:'Times New Roman',Times,serif; color:#374151; background:#fff; outline:none; cursor:pointer; }
.show-entries select:focus { border-color:#f97316; }
#noResultsRow td{text-align:center;color:#9ca3af;padding:20px 0;font-size:13px}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<?php include 'header.php'; ?>

<div class="content">
<div class="header-bar">
<?php if($view=='invoices'): ?>
    <h2>Invoices</h2>
    <div style="display:flex;align-items:center;gap:10px;">
        <div class="search-wrap" style="margin-bottom:0">
            <i class="search-icon">🔍</i>
            <input type="text" id="invoiceSearch"
                   placeholder="Search invoices, customers…"
                   oninput="ajaxSearchInvoices(this.value)">
            <button class="search-clear" id="searchClear" onclick="clearInvoiceSearch()" title="Clear">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <a href="create_invoice" class="btn"><i class="fas fa-plus"></i> Create Invoice</a>
    </div>
<?php else: ?>
<?php endif; ?>
</div>

<?php if($view=='invoices'): ?>

<form method="GET" id="filterForm">
<input type="hidden" name="view" value="invoices">
<input type="hidden" name="sort_col" value="<?=htmlspecialchars($sortCol)?>">
<input type="hidden" name="sort_dir" value="<?=htmlspecialchars($sortDir)?>">
<div class="filter-bar">
    <select name="period" onchange="this.form.submit();toggleCustom(this.value);">
        <?php $periods=['today'=>'Today','this_week'=>'This Week','this_month'=>'This Month',
            'last_month'=>'Last Month','this_quarter'=>'This Quarter',
            'this_year'=>'This Financial Year','last_year'=>'Last Financial Year',
            'all'=>'All Invoices','custom'=>'Custom Range']; ?>
        <?php foreach($periods as $val=>$label): ?>
        <option value="<?=$val?>" <?=$period==$val?'selected':''?>><?=$label?></option>
        <?php endforeach; ?>
    </select>
    <div class="custom-dates <?=$period=='custom'?'show':''?>" id="customDates">
        <input type="date" name="date_from" value="<?=htmlspecialchars($dateFrom)?>" onchange="document.getElementById('filterForm').submit()">
        <span style="color:#9ca3af">to</span>
        <input type="date" name="date_to"   value="<?=htmlspecialchars($dateTo)?>"   onchange="document.getElementById('filterForm').submit()">
    </div>
    <select name="status" onchange="this.form.submit();">
        <option value="all"     <?=$statusF=='all'    ?'selected':''?>>All Invoices</option>
        <option value="paid"    <?=$statusF=='paid'   ?'selected':''?>>Paid</option>
        <option value="partial" <?=$statusF=='partial'?'selected':''?>>Partial</option>
        <option value="unpaid"  <?=$statusF=='unpaid' ?'selected':''?>>Unpaid</option>
    </select>
    <select name="executive_id" onchange="this.form.submit();" style="padding:7px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;outline:none;">
        <option value="">All Executives</option>
        <option value="unassigned" <?=$execFilter==='unassigned'?'selected':''?>>Unassigned</option>
        <?php foreach($allExecs as $ex): ?>
        <option value="<?=$ex['id']?>" <?=$execFilter==(string)$ex['id']?'selected':''?>><?=htmlspecialchars($ex['name'])?></option>
        <?php endforeach; ?>
    </select>
</div>
<div class="stat-badges">
    <div class="stat-badge"><span class="label">Count</span><span class="value"><?=$invoiceCount?></span></div>
    <div class="stat-badge blue"><span class="label">Total</span><span class="value">&#8377; <?=indianFormat($totalAmount,2)?></span></div>
    <div class="stat-badge red"><span class="label">Pending</span><span class="value">&#8377; <?=indianFormat($totalPending,2)?></span></div>
</div>
</form>
<script>function toggleCustom(v){document.getElementById('customDates').classList.toggle('show',v==='custom');}</script>

<!-- Invoice Table -->
<?php
function thSort($col, $label, $sortCol, $sortDir, $get) {
    $active  = $sortCol === $col;
    $nextDir = ($active && $sortDir === 'asc') ? 'desc' : 'asc';
    $qs = $get; $qs['sort_col'] = $col; $qs['sort_dir'] = $nextDir; unset($qs['inv_page']);
    $url  = '?' . http_build_query($qs);
    $cls  = $active ? $sortDir : '';
    $icon = $active ? ($sortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
    return '<th class="sort-th '.$cls.'" onclick="location.href=\''.htmlspecialchars($url,ENT_QUOTES).'\'">'
         . $label . '<i class="fas '.$icon.' si"></i></th>';
}
?>
<div class="show-entries">
    Show
    <select name="per_page" form="filterForm" onchange="document.getElementById('filterForm').submit();">
        <?php foreach([10,25,50,100] as $n): ?>
        <option value="<?=$n?>" <?=$perPage==$n?'selected':''?>><?=$n?></option>
        <?php endforeach; ?>
    </select>
    entries
</div>
<div class="card">
<table>
<thead>
<tr>
    <?=thSort('customer',       'Customer',          $sortCol,$sortDir,$_GET)?>
    <?=thSort('invoice_number', 'Invoice No.',        $sortCol,$sortDir,$_GET)?>
    <?=thSort('invoice_date',   'Invoice Date',       $sortCol,$sortDir,$_GET)?>
    <?=thSort('basic_total',    'Taxable (&#8377;)',  $sortCol,$sortDir,$_GET)?>
    <?=thSort('grand_total',    'Amount (&#8377;)',   $sortCol,$sortDir,$_GET)?>
    <?=thSort('payment_status', 'Status',             $sortCol,$sortDir,$_GET)?>
    <?=thSort('amount_pending', 'Pndg. (&#8377;)',    $sortCol,$sortDir,$_GET)?>
    <th></th>
</tr>
</thead>
<tbody>
<?php foreach($invoices as $inv):
    $gt    = floatval($inv['grand_total']);
    $pnd   = floatval($inv['amount_pending']);
    // If grand_total is 0, invoice has no items yet — show as Paid
    $ps    = ($gt <= 0) ? 'Paid' : ($inv['payment_status'] ?? 'Unpaid');
    $psCls = strtolower($ps);
?>
<tr class="invoice-row"
    data-id="<?=$inv['id']?>"
    data-customer="<?=htmlspecialchars($inv['customer'])?>"
    data-invno="<?=htmlspecialchars(fmtInvNo($inv['invoice_number']))?>"
    data-date="<?=date('d M Y',strtotime($inv['invoice_date']))?>"
    data-total="<?=$gt?>"
    data-pending="<?=$pnd?>"
    data-status="<?=$ps?>"
    onclick="openDownloadModal(this)">
    <td><?=htmlspecialchars($inv['customer'])?></td>
    <td><?=htmlspecialchars(fmtInvNo($inv['invoice_number']))?></td>
    <td><?=date('d-M-y',strtotime($inv['invoice_date']))?></td>
    <td><?=indianFormat(floatval($inv['basic_total']),2)?></td>
    <td><?=indianFormat($gt,2)?></td>
    <td>
        <span class="status-pill <?=$psCls?>" onclick="openPayModal(event,this.closest('tr'))">
            <?php if($psCls==='paid'): ?><i class="fas fa-check-circle"></i>
            <?php elseif($psCls==='partial'): ?><i class="fas fa-adjust"></i>
            <?php else: ?><i class="fas fa-clock"></i><?php endif; ?>
            <?=$ps?>
        </span>
    </td>
    <td class="<?=$pnd>0?'pnd-amt':'pnd-zero'?>">
        <?=$pnd>0?indianFormat($pnd,2):'0.00'?>
    </td>
    <td onclick="event.stopPropagation()">
        <a href="create_invoice?edit=<?=$inv['id']?>" class="edit-btn" title="Edit Invoice"><i class="fas fa-pen"></i></a>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pagination">
<?php
$qs = array_merge($_GET, ['view'=>'invoices']);
$pages = [];
for ($i = 1; $i <= $invTotalPages; $i++) {
    if ($i <= 3 || $i == $invTotalPages || abs($i - $invPage) <= 1) $pages[] = $i;
}
$pages = array_unique($pages); sort($pages);
$qs['inv_page'] = $invPage - 1;
echo $invPage <= 1 ? '<span class="disabled">&laquo;</span>' : "<a href='".htmlspecialchars('?'.http_build_query($qs))."'>&laquo;</a>";
$prev = null;
foreach ($pages as $p) {
    if ($prev !== null && $p - $prev > 1) echo '<span class="dots">…</span>';
    $qs['inv_page'] = $p;
    if ($p == $invPage) echo '<span class="active">'.$p.'</span>';
    else echo "<a href='".htmlspecialchars('?'.http_build_query($qs))."'>$p</a>";
    $prev = $p;
}
$qs['inv_page'] = $invPage + 1;
echo $invPage >= $invTotalPages ? '<span class="disabled">&raquo;</span>' : "<a href='".htmlspecialchars('?'.http_build_query($qs))."'>&raquo;</a>";
?>
</div>

<?php else: ?>
<div class="header-bar">
    <h2><i class="fas fa-users" style="color:#f97316;margin-right:8px"></i>Customers</h2>
        <div class="search-wrap" style="margin-bottom:0;width:230px">
            <i class="search-icon" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none">🔍</i>
            <input type="text" id="custSearch"
                   placeholder="Search customers…"
                   value="<?= htmlspecialchars($custSearch) ?>"
                   oninput="ajaxSearchCustomers(this.value)"
                   style="width:100%;padding:7px 28px 7px 34px;border:1.5px solid #d1d5db;border-radius:50px;font-size:12.5px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;outline:none;box-shadow:0 1px 3px rgba(0,0,0,.06);">
        </div>
</div>
<?php
function custThSort($col, $label, $custSortCol, $custSortDir, $get) {
    $active  = $custSortCol === $col;
    $nextDir = ($active && $custSortDir === 'asc') ? 'desc' : 'asc';
    $qs = $get; $qs['cust_sort_col'] = $col; $qs['cust_sort_dir'] = $nextDir; $qs['view'] = 'customers'; unset($qs['cust_page']);
    $url  = '?' . http_build_query($qs);
    $cls  = trim('sort-th ' . ($active ? $custSortDir : ''));
    $icon = $active ? ($custSortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
    return '<th class="'.$cls.'" onclick="location.href=\''.htmlspecialchars($url,ENT_QUOTES).'\'">'
         . $label . '<i class="fas '.$icon.' si"></i></th>';
}
?>
<div class="show-entries">
    Show
    <form method="GET" id="custPpForm" style="display:inline">
        <input type="hidden" name="view" value="customers">
        <input type="hidden" name="cust_search" value="<?= htmlspecialchars($custSearch) ?>">
        <input type="hidden" name="cust_sort_col" value="<?= htmlspecialchars($custSortCol) ?>">
        <input type="hidden" name="cust_sort_dir" value="<?= htmlspecialchars($custSortDir) ?>">
        <select name="per_page" onchange="this.form.submit();">
            <?php foreach([10,25,50,100] as $n): ?>
            <option value="<?=$n?>" <?=$perPage==$n?'selected':''?>><?=$n?></option>
            <?php endforeach; ?>
        </select>
    </form>
    entries
</div>
<div class="card">
<table>
<thead><tr>
    <?=custThSort('first_name',   'Name',     $custSortCol,$custSortDir,$_GET)?>
    <?=custThSort('business_name','Business', $custSortCol,$custSortDir,$_GET)?>
    <th>Action</th>
</tr></thead>
<tbody id="custTbody">
<?php foreach($customers as $cust): ?>
<tr>
    <td><?=htmlspecialchars($cust['first_name'] . ' ' . ($cust['last_name'] ?? ''))?></td>
    <td><?=htmlspecialchars($cust['business_name'])?></td>
    <td><a href="editcustomer.php?id=<?=$cust['id']?>" class="edit-btn"><i class="fas fa-pen"></i></a></td>
</tr>
<?php endforeach; ?>
<?php if(empty($customers)): ?>
<tr><td colspan="3" style="text-align:center;padding:30px;color:#9ca3af;">No customers found.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<div class="pagination">
<?php
$qs2 = array_merge($_GET, ['view'=>'customers', 'cust_search'=>$custSearch, 'cust_sort_col'=>$custSortCol, 'cust_sort_dir'=>$custSortDir]);
$pages2 = [];
for ($i = 1; $i <= $custTotalPages; $i++) {
    if ($i <= 3 || $i == $custTotalPages || abs($i - $custPage) <= 1) $pages2[] = $i;
}
$pages2 = array_unique($pages2); sort($pages2);
$qs2['cust_page'] = $custPage - 1;
echo $custPage <= 1 ? '<span class="disabled">&laquo;</span>' : "<a href='".htmlspecialchars('?'.http_build_query($qs2))."'>&laquo;</a>";
$prev2 = null;
foreach ($pages2 as $p) {
    if ($prev2 !== null && $p - $prev2 > 1) echo '<span class="dots">…</span>';
    $qs2['cust_page'] = $p;
    if ($p == $custPage) echo '<span class="active">'.$p.'</span>';
    else echo "<a href='".htmlspecialchars('?'.http_build_query($qs2))."'>$p</a>";
    $prev2 = $p;
}
$qs2['cust_page'] = $custPage + 1;
echo $custPage >= $custTotalPages ? '<span class="disabled">&raquo;</span>' : "<a href='".htmlspecialchars('?'.http_build_query($qs2))."'>&raquo;</a>";
?>
</div>
<?php endif; ?>
</div>

<!-- ══ DOWNLOAD MODAL ══ -->
<div class="dl-overlay" id="dlOverlay">
<div class="dl-modal">
    <div class="dl-header">
        <button class="dl-close" onclick="closeDlModal()"><i class="fas fa-times"></i></button>
        <div class="dl-invno">
            <span id="dlInvNo"></span>
            <span style="color:#e2e8f0">|</span>
            <i class="fas fa-calendar-alt"></i>
            <span class="dl-date-text" id="dlDate"></span>
        </div>
        <div class="dl-customer" id="dlCustomer"></div>
    </div>
    <div class="dl-body">
        <div class="dl-sec">Financials</div>
        <div class="dl-fin-row">
            <div class="dl-fin-pill amount">Amount &#8377;&nbsp;<span id="dlAmount"></span></div>
            <div class="dl-fin-pill pending">
                Receivable &#8377;&nbsp;<span id="dlPending"></span>
                <button class="dl-icon-btn" title="Receive Payment"
                    onclick="closeDlModal();setTimeout(()=>{const r=document.querySelector('[data-id=\''+currentDlId+'\']');if(r)r.querySelector('.status-pill').click();},120)">
                    <i class="fas fa-rupee-sign"></i>
                </button>
            </div>
        </div>
        <div class="dl-status-row">
            <span class="dl-status-pill" id="dlStatusPill">
                <i id="dlStatusIcon" class="fas fa-clock"></i>
                <span id="dlStatusText">Unpaid</span>
            </span>
        </div>
        <div class="dl-sec">Download</div>
        <div class="dl-action-row">
            <a id="dlEditBtn" href="#" class="dl-action-btn" style="background:#f4f6fb;color:#374151;border:1.5px solid #e2e8f0;flex:0 0 auto;padding:12px 16px;">
                <i class="fas fa-pen"></i> Edit
            </a>
            <a id="dlWordBtn" href="#" class="dl-action-btn download">
                <i class="fas fa-download"></i> Download Invoice
            </a>
            <button onclick="closeDlModal()" class="dl-action-btn cancel">
                <i class="fas fa-times"></i> Cancel
            </button>
        </div>
    </div>
</div>
</div>

<!-- ══ PAYMENT MODAL ══ -->
<div class="pay-overlay" id="payOverlay">
<div class="pay-modal">
    <button class="pay-close" onclick="closePayModal()"><i class="fas fa-times"></i></button>
    <div class="pay-subtitle">Receive payment from</div>
    <div class="pay-customer" id="payCustomer">–</div>
    <div class="pay-receivable">Current Receivable : &#8377; <span id="payReceivable">0.00</span></div>
    <div class="pay-total-row">
        <label>Payment Received :</label>
        <div class="inp-box"><span>&#8377;</span> <strong id="payTotalRecv">0.00</strong></div>
        <div class="inp-box">(Total : <span>&#8377;</span> <strong id="payGrandTotal">0.00</strong> )</div>
    </div>
    <div class="pay-check-row">
        <input type="checkbox" id="chkThankYou" checked>
        <label for="chkThankYou">Send Thank you Note</label>
    </div>
    <textarea class="pay-note" id="payNote" placeholder="Note"></textarea>
    <div class="pay-section-title">Update Invoice Status</div>
    <div id="payContent">
        <div class="pay-loading"><i class="fas fa-spinner"></i>Loading invoices…</div>
    </div>
    <div class="pay-acct-row">
        <input type="checkbox" id="chkAccounts">
        <label for="chkAccounts">Update Accounts</label>
    </div>
    <button class="pay-save-btn" id="paySaveBtn" onclick="savePayment()">
        <i class="fas fa-check"></i> Save
    </button>
</div>
</div>

<div class="toast" id="toast"></div>

<script>
// ── Download modal ──
let currentDlId = null;
function openDownloadModal(row) {
    const id      = row.dataset.id;
    const amount  = parseFloat(row.dataset.total  ||0).toLocaleString('en-IN',{minimumFractionDigits:2});
    const pending = parseFloat(row.dataset.pending||0).toLocaleString('en-IN',{minimumFractionDigits:2});
    const status  = row.dataset.status || 'Unpaid';
    currentDlId   = id;

    document.getElementById('dlInvNo').textContent    = '#' + row.dataset.invno;
    document.getElementById('dlCustomer').textContent = row.dataset.customer;
    document.getElementById('dlDate').textContent     = row.dataset.date;
    document.getElementById('dlAmount').textContent   = amount;
    document.getElementById('dlPending').textContent  = pending;

    const pill = document.getElementById('dlStatusPill');
    document.getElementById('dlStatusIcon').className =
        status==='Paid'    ? 'fas fa-check-circle' :
        status==='Partial' ? 'fas fa-adjust'        : 'fas fa-clock';
    document.getElementById('dlStatusText').textContent = status;
    pill.className = 'dl-status-pill ' + status.toLowerCase();

    document.getElementById('dlWordBtn').href = 'download.php?id=' + id;
    document.getElementById('dlEditBtn').href = 'create_invoice?edit=' + id;
    document.getElementById('dlOverlay').classList.add('open');
}
document.getElementById('dlOverlay').addEventListener('click', function(e){
    if(e.target===this) closeDlModal();
});
function closeDlModal(){
    document.getElementById('dlOverlay').classList.remove('open');
}

// ── Payment modal ──
function openPayModal(e, row) {
    e.stopPropagation(); // prevent download modal from opening
    const customer   = row.dataset.customer;
    const grandTotal = parseFloat(row.dataset.total   ||0);
    const pending    = parseFloat(row.dataset.pending ||0);

    document.getElementById('payCustomer').textContent   = customer;
    document.getElementById('payReceivable').textContent = pending.toFixed(2);
    document.getElementById('payGrandTotal').textContent = grandTotal.toFixed(2);
    document.getElementById('payTotalRecv').textContent  = '0.00';
    document.getElementById('payNote').value             = '';
    document.getElementById('payContent').innerHTML      =
        '<div class="pay-loading"><i class="fas fa-spinner"></i>Loading invoices…</div>';
    document.getElementById('payOverlay').classList.add('open');

    fetch('index.php?ajax=get_invoices&customer='+encodeURIComponent(customer))
        .then(r=>r.json())
        .then(data=>{
            const invoices = data.invoices||[];
            document.getElementById('payReceivable').textContent =
                parseFloat(data.total_pending||0).toFixed(2);

            let html=`<table class="pay-inv-table">
                <thead><tr>
                    <th>Invoice No.</th><th>Date</th><th>Status</th>
                    <th>Pending Amt (&#8377;)</th><th>Received Amt (&#8377;)</th>
                </tr></thead><tbody>`;
            invoices.forEach(inv=>{
                const pnd=parseFloat(inv.amount_pending||0).toFixed(2);
                const recd=parseFloat(inv.amount_received||0).toFixed(2);
                const gt=parseFloat(inv.grand_total||0).toFixed(2);
                const st=inv.status||'Unpaid';
                html+=`<tr>
                    <td><strong>${inv.invoice_number}</strong><br>
                        <small style="color:#888;">Total: ₹${gt}</small><br>
                        <small style="color:#28a745;">Recd: ₹${recd}</small>
                    </td>
                    <td>${inv.date}</td>
                    <td>
                        <select onchange="onStatusChange(this)">
                            <option value="Unpaid"  ${st==='Unpaid' ?'selected':''}>Unpaid</option>
                            <option value="Partial" ${st==='Partial'?'selected':''}>Partly Paid</option>
                            <option value="Paid"    ${st==='Paid'   ?'selected':''}>Paid</option>
                        </select>
                    </td>
                    <td>
                        <span class="pnd-text" style="color:#f97316;font-weight:bold;">₹${pnd}</span>
                        <button type="button" class="copy-btn" onclick="copyPending(this)" title="Fill pending">
                            <i class="fas fa-copy"></i>
                        </button>
                    </td>
                    <td>
                        <input class="recv-inp" type="number" min="0" step="0.01"
                               placeholder="New payment"
                               data-inv-id="${parseInt(inv.id)}"
                               data-pending="${pnd}"
                               oninput="recalcTotal()">
                    </td>
                </tr>`;
            });
            html+='</tbody></table>';
            document.getElementById('payContent').innerHTML=html;
        })
        .catch(()=>{
            document.getElementById('payContent').innerHTML=
                '<p style="color:#f97316;font-size:13px;padding:10px 0">Failed to load. Please try again.</p>';
        });
}
document.getElementById('payOverlay').addEventListener('click',function(e){
    if(e.target===this) closePayModal();
});
function closePayModal(){
    document.getElementById('payOverlay').classList.remove('open');
}
function copyPending(btn){
    const row=btn.closest('tr');
    const inp=row.querySelector('.recv-inp');
    inp.value=parseFloat(row.querySelector('.pnd-text').textContent).toFixed(2);
    recalcTotal();
}
function onStatusChange(sel){
    if(sel.value==='Paid'){
        const inp=sel.closest('tr').querySelector('.recv-inp');
        if(!inp.value) inp.value=parseFloat(inp.dataset.pending||0).toFixed(2);
        recalcTotal();
    }
}
function recalcTotal(){
    let t=0;
    document.querySelectorAll('.recv-inp').forEach(i=>t+=parseFloat(i.value||0));
    document.getElementById('payTotalRecv').textContent=t.toFixed(2);
}
function savePayment(){
    const payments=[];
    document.querySelectorAll('.recv-inp').forEach(inp=>{
        const r=parseFloat(inp.value||0);
        const row=inp.closest('tr');
        const sel=row?row.querySelector('select'):null;
        const manualStatus=sel?sel.value:'';
        const rawId=inp.getAttribute('data-inv-id')||inp.dataset.invId||'0';
        const invId=parseInt(rawId,10);
        if(invId>0 && (r>0 || manualStatus==='Unpaid'))
            payments.push({invoice_id:invId,received:r,manual_status:manualStatus});
    });
    if(!payments.length){showToast('Enter a received amount.','error');return;}
    const btn=document.getElementById('paySaveBtn');
    btn.disabled=true;
    btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
    fetch('index.php?ajax=save_payment',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({invoice_payments:payments,note:document.getElementById('payNote').value})
    })
    .then(r=>r.json())
    .then(res=>{
        btn.disabled=false;
        btn.innerHTML='<i class="fas fa-check"></i> Save';
        if(res.success){
            closePayModal();
            showToast('Payment saved!','success');
            setTimeout(()=>location.reload(),1200);
        } else {
            showToast('Error: '+(res.message||'Unknown'),'error');
        }
    })
    .catch(()=>{
        btn.disabled=false;
        btn.innerHTML='<i class="fas fa-check"></i> Save';
        showToast('Network error. Try again.','error');
    });
}
// ── Invoice AJAX Global Search ──
var _invTimer;
function ajaxSearchInvoices(q) {
    clearTimeout(_invTimer);
    var clearBtn = document.getElementById('searchClear');
    if (clearBtn) clearBtn.style.display = q.trim() ? 'block' : 'none';
    _invTimer = setTimeout(function() { doAjaxSearchInvoices(q); }, 300);
}
function doAjaxSearchInvoices(q) {
    var status = document.querySelector('[name="status"]') ? document.querySelector('[name="status"]').value : 'all';
    var execId = document.querySelector('[name="executive_id"]') ? document.querySelector('[name="executive_id"]').value : '';
    var url = 'index.php?ajax=search_invoices'
            + '&search=' + encodeURIComponent(q)
            + '&status=' + encodeURIComponent(status)
            + '&executive_id=' + encodeURIComponent(execId);
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var tbody = document.querySelector('table tbody');
            if (!tbody) return;
            tbody.innerHTML = data.html;
            // hide pagination when searching
            var pg = document.querySelector('.pagination');
            if (pg) pg.style.display = q.trim() ? 'none' : '';
            // update count badge
            var countEl = document.querySelector('.stat-badge .value');
            if (countEl) countEl.textContent = data.count;
        }).catch(function() {});
}
function clearInvoiceSearch() {
    var inp = document.getElementById('invoiceSearch');
    inp.value = '';
    ajaxSearchInvoices('');
    inp.focus();
}

// ── Customer AJAX Global Search ──
var _custTimer;
function ajaxSearchCustomers(q) {
    clearTimeout(_custTimer);
    _custTimer = setTimeout(function() { doAjaxSearchCustomers(q); }, 300);
}
function doAjaxSearchCustomers(q) {
    var url = 'index.php?ajax=search_customers&search=' + encodeURIComponent(q);
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var tbody = document.getElementById('custTbody');
            if (!tbody) return;
            tbody.innerHTML = data.html;
            var pg = document.querySelector('.pagination');
            if (pg) pg.style.display = q.trim() ? 'none' : '';
        }).catch(function() {});
}

</script>
</body>
</html>
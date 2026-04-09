<?php
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/env.php';
loadEnv(dirname(__DIR__) . '/.env');

// ── Handle AJAX live search ────────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    require_once dirname(__DIR__) . '/db.php';
    $status_f = $_GET['status'] ?? 'All';
    $period_f = $_GET['period'] ?? 'all';
    $q        = trim($_GET['search'] ?? '');
    switch ($period_f) {
        case 'last_month': $df=date('Y-m-01',strtotime('first day of last month')); $dt=date('Y-m-t',strtotime('last day of last month')); break;
        case 'this_year':  $m2=(int)date('n');$y2=(int)date('Y'); $df=($m2>=4)?($y2.'-04-01'):(($y2-1).'-04-01'); $dt=($m2>=4)?(($y2+1).'-03-31'):($y2.'-03-31'); break;
        case 'last_year':  $m2=(int)date('n');$y2=(int)date('Y'); $df=($m2>=4)?(($y2-1).'-04-01'):(($y2-2).'-04-01'); $dt=($m2>=4)?($y2.'-03-31'):(($y2-1).'-03-31'); break;
        case 'all':        $df='2000-01-01'; $dt='2099-12-31'; break;
        default:           $df=date('Y-m-01'); $dt=date('Y-m-t');
    }
    $where  = ['po_date BETWEEN :df AND :dt'];
    $params = [':df'=>$df,':dt'=>$dt];
    if ($status_f && $status_f !== 'All') { $where[]='status=:st'; $params[':st']=$status_f; }
    if ($q !== '') { $where[]='(supplier_name LIKE :s OR po_number LIKE :s OR contact_person LIKE :s)'; $params[':s']="%$q%"; }
    $wsql = implode(' AND ', $where);
    $cnt = (int)$pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE $wsql")->execute($params) ? 0 : 0;
    $cstmt = $pdo->prepare("SELECT COUNT(*) FROM purchase_orders WHERE $wsql");
    $cstmt->execute($params); $cnt = (int)$cstmt->fetchColumn();
    $rows = $pdo->prepare("SELECT * FROM purchase_orders WHERE $wsql ORDER BY created_at DESC LIMIT 200");
    $rows->execute($params); $orders = $rows->fetchAll(PDO::FETCH_ASSOC);
    // build supplier map
    $smRows = $pdo->query("SELECT supplier_name, email, phone FROM po_suppliers")->fetchAll(PDO::FETCH_ASSOC);
    $supplierMap = [];
    foreach ($smRows as $sm) { $supplierMap[strtolower(trim($sm['supplier_name']))] = ['email'=>$sm['email']??'','phone'=>$sm['phone']??'']; }
    function _smartDate($ds){$d=new DateTime($ds);$t=new DateTime('today');$y=new DateTime('yesterday');$tm=new DateTime('tomorrow');if($d==$t)return'Today';if($d==$y)return'Yesterday';if($d==$tm)return'Tomorrow';return $d->format('d-M');}
    function _indFmt($n){$n=number_format((float)$n,2,'.','');$p=explode('.',$n);$i=$p[0];$d=$p[1];$neg='';if(isset($i[0])&&$i[0]==='-'){$neg='-';$i=substr($i,1);}if(strlen($i)<=3)return $neg.$i.'.'.$d;$l3=substr($i,-3);$r=substr($i,0,-3);$r=preg_replace('/\B(?=(\d{2})+(?!\d))/',',',$r);return $neg.$r.','.$l3.'.'.$d;}
    ob_start();
    if (empty($orders)) {
        echo '<tr><td colspan="10" style="text-align:center;padding:40px;color:#9ca3af;">No purchase orders found.</td></tr>';
    } else {
        foreach ($orders as $o) {
            $key=$o['_email']=($supplierMap[strtolower(trim($o['supplier_name']))]['email']??'');
            $o['_phone']=!empty($o['supplier_phone'])?$o['supplier_phone']:($supplierMap[strtolower(trim($o['supplier_name']))]['phone']??'');
            $o['_email']=$supplierMap[strtolower(trim($o['supplier_name']))]['email']??'';
            $sc2=strtolower($o['status']);
            $obj=htmlspecialchars(json_encode($o));
            echo '<tr data-id="'.$o['id'].'" data-obj=\''.$obj.'\' style="cursor:pointer;transition:background .15s" onmouseover="this.style.background=\'#fff7f0\'" onmouseout="this.style.background=\'\'">';
            echo '<td>'.htmlspecialchars($o['supplier_name']).'</td>';
            echo '<td>'.htmlspecialchars($o['contact_person']).'</td>';
            echo '<td style="color:#1565c0;font-weight:400;">'.htmlspecialchars($o['po_number']).'</td>';
            echo '<td><span class="badge-status '.$sc2.'">'.htmlspecialchars($o['status']).'</span></td>';
            echo '<td>'._smartDate($o['po_date']).'</td>';
            echo '<td>'._smartDate($o['due_date']).'</td>';
            echo '<td class="num">'._indFmt($o['total_taxable']).'</td>';
            echo '<td class="num">'._indFmt($o['grand_total']).'</td>';
            echo '<td style="font-size:12px;">'.htmlspecialchars($o['created_by']).'</td>';
            echo '<td onclick="event.stopPropagation()"><div class="action-btns" id="actions-'.$o['id'].'">';
            if($sc2==='pending') echo '<button class="action-btn btn-approve" onclick="openAprModal('.$o['id'].',\''.htmlspecialchars($o['po_number']).'\')" title="Approve"><i class="fas fa-check"></i></button>';
            if($sc2==='approved') echo '<button class="action-btn btn-complete" onclick="openCmpModal('.$o['id'].',\''.htmlspecialchars($o['po_number']).'\')" title="Complete"><i class="fas fa-flag-checkered"></i></button>';
            echo '<button class="action-btn btn-bell" onclick="openReminderModal('.json_encode($o).')" title="Reminder"><i class="fas fa-bell"></i></button>';
            echo '<a href="createpurchase.php?edit='.$o['id'].'" class="action-btn btn-edit" title="Edit"><i class="fas fa-pencil-alt"></i></a>';
            echo '</div></td></tr>';
        }
    }
    $html = ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode(['html'=>$html,'count'=>$cnt]);
    exit;
}

// ── Handle SMTP email send ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reminder_email'])) {
    header('Content-Type: application/json');
    $to      = trim($_POST['to']      ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Invalid or missing email address.']);
        exit;
    }

    $smtp_server = getenv('SMTP_SERVER') ?: 'smtp.gmail.com';
    $smtp_port   = (int)(getenv('SMTP_PORT') ?: 587);
    $smtp_user   = getenv('SMTP_USER') ?: '';
    $smtp_pass   = str_replace(' ', '', getenv('SMTP_PASS') ?: '');
    $from_addr   = getenv('FROM_ADDR') ?: $smtp_user;

    // Send via SMTP using raw socket
    try {
        $socket = fsockopen('tls://' . $smtp_server, 465, $errno, $errstr, 10);
        if (!$socket) {
            // Try STARTTLS on port 587
            $socket = fsockopen($smtp_server, $smtp_port, $errno, $errstr, 10);
            if (!$socket) throw new Exception("Cannot connect to SMTP: $errstr ($errno)");
            $useStartTLS = true;
        } else {
            $useStartTLS = false;
        }

        function smtpRead($socket) {
            $data = '';
            while ($line = fgets($socket, 515)) {
                $data .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            return $data;
        }
        function smtpCmd($socket, $cmd) {
            fwrite($socket, $cmd . "\r\n");
            return smtpRead($socket);
        }

        smtpRead($socket); // greeting
        smtpCmd($socket, 'EHLO localhost');

        if ($useStartTLS) {
            smtpCmd($socket, 'STARTTLS');
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            smtpCmd($socket, 'EHLO localhost');
        }

        smtpCmd($socket, 'AUTH LOGIN');
        smtpCmd($socket, base64_encode($smtp_user));
        $authResp = smtpCmd($socket, base64_encode($smtp_pass));
        if (strpos($authResp, '235') === false) throw new Exception('SMTP Auth failed: ' . $authResp);

        smtpCmd($socket, 'MAIL FROM:<' . $from_addr . '>');
        smtpCmd($socket, 'RCPT TO:<' . $to . '>');
        smtpCmd($socket, 'DATA');

        $headers  = "From: ELTRIVE AUTOMATIONS PVT LTD <{$from_addr}>\r\n";
        $headers .= "To: {$to}\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Date: " . date('r') . "\r\n";

        fwrite($socket, $headers . "\r\n" . $message . "\r\n.\r\n");
        $sendResp = smtpRead($socket);
        smtpCmd($socket, 'QUIT');
        fclose($socket);

        if (strpos($sendResp, '250') === false) throw new Exception('Send failed: ' . $sendResp);

        echo json_encode(['success' => true, 'message' => 'Email sent to ' . $to]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Smart date display like biziverse
function smartDate($dateStr) {
    $date    = new DateTime($dateStr);
    $today   = new DateTime('today');
    $yesterday = new DateTime('yesterday');
    $tomorrow  = new DateTime('tomorrow');
    if ($date == $today)     return 'Today';
    if ($date == $yesterday) return 'Yesterday';
    if ($date == $tomorrow)  return 'Tomorrow';
    return $date->format('d-M');
}

// Indian number format
function indFmt($num) {
    $num = number_format((float)$num, 2, '.', '');
    $parts = explode('.', $num);
    $int = $parts[0];
    $dec = $parts[1];
    $negative = '';
    if ($int[0] === '-') { $negative = '-'; $int = substr($int, 1); }
    if (strlen($int) <= 3) return $negative . $int . '.' . $dec;
    $last3 = substr($int, -3);
    $rest   = substr($int, 0, -3);
    $rest   = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
    return $negative . $rest . ',' . $last3 . '.' . $dec;
}


// Build supplier email+phone map from po_suppliers
$supplierMap = [];
try {
    $smRows = $pdo->query("SELECT supplier_name, email, phone FROM po_suppliers")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($smRows as $sm) {
        $supplierMap[strtolower(trim($sm['supplier_name']))] = [
            'email' => $sm['email'] ?? '',
            'phone' => $sm['phone'] ?? '',
        ];
    }
} catch(Exception $e) {}

$status_filter = $_GET['status'] ?? 'All';
$period_filter = $_GET['period'] ?? 'all';
$fin_year      = $_GET['fin_year'] ?? '';
$search        = trim($_GET['search'] ?? '');
$per_page      = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;
$current_page  = max(1, (int)($_GET['page'] ?? 1));

// ── SORT ──
$sort_col = $_GET['sort_col'] ?? '';
$sort_dir = $_GET['sort_dir'] ?? 'asc';
$allowed_sorts = [
    'supplier_name'  => 'supplier_name',
    'contact_person' => 'contact_person',
    'po_number'      => 'po_number',
    'status'         => 'status',
    'po_date'        => 'po_date',
    'due_date'       => 'due_date',
    'total_taxable'  => 'total_taxable',
    'grand_total'    => 'grand_total',
    'created_by'     => 'created_by',
];
$order_sql = 'created_at DESC';
if ($sort_col && isset($allowed_sorts[$sort_col])) {
    $sdir = ($sort_dir === 'desc') ? 'DESC' : 'ASC';
    $order_sql = $allowed_sorts[$sort_col] . ' ' . $sdir . ', created_at DESC';
}

switch ($period_filter) {
    case 'last_month': $date_from=date('Y-m-01',strtotime('first day of last month')); $date_to=date('Y-m-t',strtotime('last day of last month')); break;
    case 'this_year':  $fm=date('n');$fy=date('Y'); $date_from=($fm>=4)?"$fy-04-01":(($fy-1)."-04-01"); $date_to=($fm>=4)?(($fy+1)."-03-31"):("$fy-03-31"); break;
    case 'last_year':  $fm=date('n');$fy=date('Y'); $date_from=($fm>=4)?(($fy-1)."-04-01"):(($fy-2)."-04-01"); $date_to=($fm>=4)?("$fy-03-31"):(($fy-1)."-03-31"); break;
    case 'all':        $date_from='2000-01-01';    $date_to='2099-12-31';    break;
    default:           $date_from=date('Y-m-01');  $date_to=date('Y-m-t');
}

// Financial Year dropdown overrides period date range if selected
if ($fin_year !== '') {
    $fyMap = [
        'fy_2023_24' => ['2023-04-01','2024-03-31'],
        'fy_2024_25' => ['2024-04-01','2025-03-31'],
        'fy_2025_26' => ['2025-04-01','2026-03-31'],
        'fy_2026_27' => ['2026-04-01','2027-03-31'],
    ];
    if (isset($fyMap[$fin_year])) { $date_from=$fyMap[$fin_year][0]; $date_to=$fyMap[$fin_year][1]; }
}

$where  = ['po_date BETWEEN :df AND :dt'];
$params = [':df'=>$date_from, ':dt'=>$date_to];

if ($status_filter && $status_filter !== 'All') {
    $where[]      = 'status=:st';
    $params[':st']= $status_filter;
}
if ($search !== '') {
    $where[]      = '(supplier_name LIKE :s OR po_number LIKE :s OR contact_person LIKE :s)';
    $params[':s'] = "%$search%";
}

$where_sql = implode(' AND ', $where);

// Count + summary totals across ALL matching rows
$all_stmt = $pdo->prepare('SELECT COUNT(*), SUM(total_taxable), SUM(grand_total) FROM purchase_orders WHERE '.$where_sql);
$all_stmt->execute($params);
$all_row      = $all_stmt->fetch(PDO::FETCH_NUM);
$count        = (int)$all_row[0];
$total_taxable= (float)$all_row[1];
$total_amount = (float)$all_row[2];
$total_pages  = max(1, (int)ceil($count / $per_page));
if ($current_page > $total_pages) $current_page = $total_pages;
$offset = ($current_page - 1) * $per_page;

// Pending amount across all matching rows
$pend_stmt = $pdo->prepare('SELECT SUM(grand_total) FROM purchase_orders WHERE '.$where_sql." AND status='Pending'");
$pend_stmt->execute($params);
$pending_amount = (float)$pend_stmt->fetchColumn();

// Fetch only current page rows (cast offset/limit to int for safe inline use)
$limit_sql = (int)$per_page;
$offset_sql = (int)$offset;
$stmt = $pdo->prepare('SELECT * FROM purchase_orders WHERE '.$where_sql.' ORDER BY '.$order_sql.' LIMIT '.$limit_sql.' OFFSET '.$offset_sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build pagination URL preserving current filters
function pageUrl($pg, $st, $pf, $sr, $fy='') {
    $q = ['status'=>$st,'period'=>$pf,'search'=>$sr,'page'=>$pg];
    if ($fy !== '') $q['fin_year'] = $fy;
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Purchase Orders – Eltrive</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;background:#f4f6fb;color:#222;height:100vh;overflow:hidden}
.content{margin-left:220px;padding:10px 18px 6px;height:100vh;display:flex;flex-direction:column;overflow:hidden}

/* ── Top bar ── */
.header-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
h2{font-weight:700;color:#1a1f2e;font-size:18px}
.btn-create{
    display:inline-flex;align-items:center;gap:6px;padding:6px 14px;
    border-radius:8px;background:#f97316;color:#fff;text-decoration:none;
    font-size:12px;font-weight:600;border:none;cursor:pointer;
    font-family:'Times New Roman',Times,serif;transition:background .2s}
.btn-create:hover{background:#fb923c}

/* ── Search box ── */
.search-wrap{position:relative;width:230px}
.search-wrap .search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none;font-style:normal;line-height:1}
.search-wrap input[type=text]{width:100%;padding:7px 28px 7px 34px;border:1.5px solid #d1d5db;border-radius:50px;font-size:12.5px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;outline:none;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:border-color .2s,box-shadow .2s}
.search-wrap input[type=text]:focus{border-color:#93c5fd;box-shadow:0 0 0 3px rgba(147,197,253,.2)}
.search-wrap input[type=text]::placeholder{color:#9ca3af;font-size:12px}
.topbar-right{display:flex;align-items:center;gap:8px}

/* ── Filter bar ── */
.filter-bar{display:flex;align-items:center;gap:8px;margin-bottom:3px;flex-wrap:nowrap}
.filter-select{padding:4px 8px;border:1.5px solid #e2e8f0;border-radius:8px;
    font-size:12px;font-family:'Times New Roman',Times,serif;
    color:#374151;background:#fff;cursor:pointer;outline:none}
.filter-select:focus{border-color:#f97316}

/* ── Stat badges ── */
.summary-row{display:none}
.sum-pill{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 12px;border-radius:8px;border:1.5px solid;
    background:#fff;font-size:12px;color:#374151;white-space:nowrap}
.sum-pill .label{color:#6b7280}
.sum-pill .val{font-weight:700}
.sum-pill.orange{border-color:#f97316}.sum-pill.orange .val{color:#f97316}
.sum-pill.blue  {border-color:#2563eb}.sum-pill.blue   .val{color:#2563eb}
.sum-pill.red   {border-color:#f97316}.sum-pill.red    .val{color:#f97316}
.sum-pill[onclick]:hover{background:#fff7ed;box-shadow:0 2px 8px rgba(249,115,22,.15);transform:translateY(-1px);transition:all .15s}

/* ── Status badge ── */
.badge-status         {background:#fff7ed;color:#f97316;border:1px solid #fed7aa}
.badge-status.approved{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0}
.badge-status.completed{background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd}
.badge-status.rejected{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}

/* ── Action button colors ── */
.btn-approve{background:#dcfce7;color:#16a34a}.btn-approve:hover{background:#bbf7d0}
.btn-complete{background:#e0f2fe;color:#0369a1}.btn-complete:hover{background:#bae6fd}
.btn-bell   {background:#fff7ed;color:#f97316}.btn-bell:hover{background:#fed7aa}
.btn-edit   {background:#f4f6fb;color:#6b7280;border:1px solid #e2e8f0}.btn-edit:hover{background:#f97316;color:#fff}

/* ── Card & table ── */
.po-card{background:#fff;border-radius:12px;padding:8px 12px;border:1px solid #e4e8f0;flex:1;overflow-y:auto;}
.po-table{width:100%;border-collapse:collapse;table-layout:fixed}
.po-table thead tr{background:#fff}
.po-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;
    color:#6b7280;padding:0 8px 6px 0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.po-table tbody tr{cursor:pointer;transition:background .15s}
.po-table tbody tr:hover{background:#fff7f0}
.po-table td{padding:5px 6px 5px 0;border-top:1px solid #f1f5f9;font-size:12px;color:#1a1f2e;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.po-table td.num{text-align:left}
.po-table th.num{text-align:left}

/* ── Status badge ── */
.badge-status{
    display:inline-flex;align-items:center;gap:5px;
    padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}

/* ── Action buttons ── */
.action-btns{display:flex;gap:5px}
.action-btn{width:26px;height:26px;border-radius:7px;display:inline-flex;align-items:center;
    justify-content:center;font-size:12px;border:none;cursor:pointer;text-decoration:none;
    transition:all .2s}
.btn-approve{background:#dcfce7;color:#16a34a}.btn-approve:hover{background:#bbf7d0}
.btn-bell   {background:#fff7ed;color:#f97316}.btn-bell:hover{background:#fed7aa}
.btn-edit   {background:#f4f6fb;color:#6b7280;border:1px solid #e2e8f0}.btn-edit:hover{background:#f97316;color:#fff}

/* ── Pagination ── */
.pagination{display:flex;justify-content:center;align-items:center;gap:4px;padding:4px 0 2px}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;
  min-width:32px;height:32px;padding:0 8px;border-radius:7px;font-size:13px;font-weight:600;
  text-decoration:none;border:1.5px solid #e4e8f0;color:#374151;background:#fff;transition:all .15s}
.pagination a:hover{border-color:#16a34a;color:#16a34a;background:#f0fdf4}
.pagination span.active{background:#16a34a;color:#fff;border-color:#16a34a}
.pagination span.dots{border:none;background:none;color:#9ca3af}

/* show entries */
.show-entries{display:flex;align-items:center;gap:6px;font-size:12px;color:#374151;margin-bottom:4px}
.show-entries select{padding:3px 6px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;
    font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;outline:none;cursor:pointer}
.show-entries select:focus{border-color:#f97316}

::-webkit-scrollbar{width:3px}
::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:99px}
@media(max-width:900px){.content{margin-left:0!important;padding:70px 12px 20px}}
.popup-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);
    backdrop-filter:blur(3px);z-index:2000;align-items:center;justify-content:center}
.popup-overlay.open{display:flex}
.popup-box{background:#fff;border-radius:16px;width:320px;max-width:95vw;
    box-shadow:0 16px 48px rgba(0,0,0,.14);font-family:'Times New Roman',Times,serif;overflow:hidden}
.popup-header{display:flex;justify-content:space-between;align-items:flex-start;
    padding:20px 22px 16px;border-bottom:1px solid #e4e8f0;background:#fafbfc}
.popup-po-num{font-size:11px;color:#9ca3af;font-weight:600;margin-bottom:4px}
.popup-supplier{font-size:19px;font-weight:800;color:#1a1f2e}
.popup-close{background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;
    width:28px;height:28px;display:flex;align-items:center;justify-content:center;
    border-radius:50%;transition:all .2s}
.popup-close:hover{background:#f1f5f9;color:#374151}
.popup-body{padding:16px 22px}
.popup-section-title{font-size:10px;font-weight:700;color:#9ca3af;
    margin-bottom:8px;text-transform:uppercase;letter-spacing:1px}
.pill-tag{display:inline-block;border-radius:8px;padding:5px 14px;font-size:12px;
    font-weight:600;border:1.5px solid #e2e8f0;color:#374151;background:#fff}
.pill-green{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0}
.popup-footer{display:flex;gap:10px;padding:14px 22px;border-top:1px solid #e4e8f0}
.btn-popup-pdf{flex:1;background:#f97316;color:#fff;border:none;border-radius:8px;padding:11px;
    font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;
    display:flex;align-items:center;justify-content:center;gap:6px;text-decoration:none}
.btn-popup-pdf:hover{background:#fb923c}
.btn-popup-cancel{flex:1;background:#f1f5f9;color:#374151;border:1.5px solid #e2e8f0;
    border-radius:8px;padding:11px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;
    display:flex;align-items:center;justify-content:center;gap:6px}
.btn-popup-cancel:hover{border-color:#f97316;color:#f97316}

/* ── Approve Modal ── */
.apr-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);
    backdrop-filter:blur(3px);z-index:3000;align-items:center;justify-content:center}
.apr-overlay.open{display:flex}
.apr-box{background:#fff;border-radius:16px;width:340px;max-width:95vw;
    box-shadow:0 16px 48px rgba(0,0,0,.15);overflow:hidden;font-family:'Times New Roman',Times,serif}
.apr-header{padding:22px 24px 16px;border-bottom:1px solid #f1f5f9}
.apr-icon{width:44px;height:44px;border-radius:12px;background:#f0fdf4;
    display:flex;align-items:center;justify-content:center;margin-bottom:12px}
.apr-icon i{color:#16a34a;font-size:20px}
.apr-title{font-size:17px;font-weight:800;color:#1a1f2e;margin-bottom:4px}
.apr-sub{font-size:13px;color:#6b7280}
.apr-body{padding:16px 24px 20px;display:flex;gap:10px}
.apr-btn-confirm{flex:1;background:#16a34a;color:#fff;border:none;border-radius:10px;
    padding:11px;font-size:14px;font-weight:700;cursor:pointer;
    font-family:'Times New Roman',Times,serif;transition:background .2s}
.apr-btn-confirm:hover{background:#15803d}
.apr-btn-cancel{flex:1;background:#f1f5f9;color:#374151;border:1.5px solid #e2e8f0;
    border-radius:10px;padding:11px;font-size:14px;font-weight:700;cursor:pointer;
    font-family:'Times New Roman',Times,serif;transition:all .2s}
.apr-btn-cancel:hover{border-color:#f97316;color:#f97316}

/* ── Reminder Modal ── */
.rem-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);
    backdrop-filter:blur(3px);z-index:3000;align-items:center;justify-content:center}
.rem-overlay.open{display:flex}
.rem-box{background:#fff;border-radius:16px;width:420px;max-width:95vw;
    box-shadow:0 16px 48px rgba(0,0,0,.15);overflow:hidden;font-family:'Times New Roman',Times,serif}
.rem-header{display:flex;justify-content:space-between;align-items:center;
    padding:18px 22px 14px;border-bottom:1px solid #e4e8f0;background:#fafbfc}
.rem-title{font-size:17px;font-weight:800;color:#1a1f2e}
.rem-supplier{font-size:13px;font-weight:700;color:#374151;margin-top:4px}
.rem-close{background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;
    width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:50%}
.rem-close:hover{background:#f1f5f9;color:#374151}
.rem-body{padding:16px 22px}
.rem-pills{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}
.rem-pill{border:1.5px solid #e2e8f0;border-radius:8px;padding:5px 14px;font-size:12px;
    font-weight:600;color:#374151;background:#fff}
.rem-textarea{width:100%;border:1.5px solid #e2e8f0;border-radius:10px;padding:10px 13px;
    font-size:13px;font-family:'Times New Roman',Times,serif;color:#374151;
    resize:vertical;min-height:90px;outline:none}
.rem-textarea:focus{border-color:#16a34a}
.rem-footer{display:flex;gap:10px;padding:14px 22px;border-top:1px solid #e4e8f0}
.btn-rem-email{flex:1;background:#16a34a;color:#fff;border:none;border-radius:10px;
    padding:11px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;
    display:flex;align-items:center;justify-content:center;gap:7px;transition:background .2s}
.btn-rem-email:hover{background:#15803d}
.btn-rem-wa{flex:1;background:#25d366;color:#fff;border:none;border-radius:10px;
    padding:11px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;
    display:flex;align-items:center;justify-content:center;gap:7px;transition:background .2s}
/* ── Sort headers ── */
.sort-th { white-space:nowrap; cursor:pointer; user-select:none; }
.sort-th:hover { color:#f97316; }
.sort-th .si { font-size:10px; color:#d1d5db; margin-left:4px; }
.sort-th.asc .si, .sort-th.desc .si { color:#f97316; }
</style>
</head>
<body>

<?php include dirname(__DIR__) . '/sidebar.php'; ?>
<?php include dirname(__DIR__) . '/header.php'; ?>

<div class="content">

    <!-- TOP BAR -->
    <div class="header-bar">
        <h2><i class="fas fa-file-invoice" style="color:#f97316;margin-right:8px"></i>Purchase Orders</h2>
        <div class="topbar-right">
            <div class="search-wrap">
                <span class="search-icon">🔍</span>
                <input type="text" id="liveSearch" placeholder="Search suppliers, PO no..." value="<?= htmlspecialchars($search) ?>" oninput="ajaxSearch(this.value)" autocomplete="off">
            </div>
            <a href="createpurchase.php" class="btn-create"><i class="fas fa-plus"></i> Create Purchase Order</a>
        </div>
    </div>

    <!-- FILTERS -->
    <form method="GET" id="filterForm">
    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="page" value="1">
    <input type="hidden" name="per_page" value="<?= $per_page ?>">
    <input type="hidden" name="sort_col" value="<?= htmlspecialchars($sort_col) ?>">
    <input type="hidden" name="sort_dir" value="<?= htmlspecialchars($sort_dir) ?>">
    <div class="filter-bar">
        <select class="filter-select" name="period" oninput="debounceSubmit(this.form)">
            <option value="this_month" <?= $period_filter==='this_month'?'selected':'' ?>>This Month</option>
            <option value="last_month" <?= $period_filter==='last_month'?'selected':'' ?>>Last Month</option>
            <option value="this_year"  <?= $period_filter==='this_year' ?'selected':'' ?>>This Financial Year</option>
            <option value="last_year"  <?= $period_filter==='last_year' ?'selected':'' ?>>Last Financial Year</option>
            <option value="all"        <?= $period_filter==='all'       ?'selected':'' ?>>All Invoices</option>
        </select>
        <select class="filter-select" name="fin_year" oninput="debounceSubmit(this.form)">
            <option value="">Fin Year</option>
            <option value="fy_2023_24" <?= $fin_year==='fy_2023_24'?'selected':'' ?>>FY 2023-24</option>
            <option value="fy_2024_25" <?= $fin_year==='fy_2024_25'?'selected':'' ?>>FY 2024-25</option>
            <option value="fy_2025_26" <?= $fin_year==='fy_2025_26'?'selected':'' ?>>FY 2025-26</option>
            <option value="fy_2026_27" <?= $fin_year==='fy_2026_27'?'selected':'' ?>>FY 2026-27</option>
        </select>
        <select class="filter-select" name="status" oninput="debounceSubmit(this.form)">
            <option value="All"       <?= $status_filter==='All'      ?'selected':'' ?>>All Status</option>
            <option value="Pending"   <?= $status_filter==='Pending'  ?'selected':'' ?>>Pending</option>
            <option value="Approved"  <?= $status_filter==='Approved' ?'selected':'' ?>>Approved</option>
            <option value="Completed" <?= $status_filter==='Completed'?'selected':'' ?>>Completed</option>
        </select>
        <span style="width:1px;height:22px;background:#e2e8f0;display:inline-block;margin:0 2px;"></span>
        <div class="sum-pill orange" style="cursor:pointer;" title="Show all" onclick="filterByStatus('All')">
            <span class="label">Count</span><span class="val"><?= $count ?></span>
        </div>
        <div class="sum-pill blue" style="cursor:pointer;" title="Show all" onclick="filterByStatus('All')">
            <span class="label">Pre-Tax</span><span class="val">&#8377; <?= indFmt($total_taxable) ?></span>
        </div>
        <div class="sum-pill blue" style="cursor:pointer;" title="Show all" onclick="filterByStatus('All')">
            <span class="label">Total</span><span class="val">&#8377; <?= indFmt($total_amount) ?></span>
        </div>
        <div class="sum-pill red" id="pendingPill" style="cursor:pointer;" title="Click to show all pending orders" onclick="filterByStatus('Pending')">
            <span class="label">Pending</span><span class="val">&#8377; <?= indFmt($pending_amount) ?></span>
        </div>
    </div>
    </form>

    <!-- SUMMARY PILLS now merged into filter-bar above -->
    <div class="summary-row"></div>

    <!-- Show entries + sort helper -->
    <?php
    function poThSort($col, $label, $sort_col, $sort_dir, $get, $extraClass='') {
        $active  = $sort_col === $col;
        $nextDir = ($active && $sort_dir === 'asc') ? 'desc' : 'asc';
        $qs = $get; $qs['sort_col'] = $col; $qs['sort_dir'] = $nextDir; unset($qs['page']);
        $url  = '?' . http_build_query($qs);
        $cls  = trim('sort-th ' . ($active ? $sort_dir : '') . ' ' . $extraClass);
        $icon = $active ? ($sort_dir === 'asc' ? 'fa-sort-up' : 'fa-sort-down') : 'fa-sort';
        return '<th class="'.$cls.'" onclick="location.href=\''.htmlspecialchars($url,ENT_QUOTES).'\'">'
             . $label . '<i class="fas '.$icon.' si"></i></th>';
    }
    ?>
    <div class="show-entries">
        Show
        <select name="per_page" form="filterForm" onchange="document.getElementById('filterForm').submit();">
            <?php foreach([10,25,50,100] as $n): ?>
                
            <option value="<?=$n?>" <?=$per_page==$n?'selected':''?>><?=$n?></option>
            <?php endforeach; ?>
        </select>
        entries
    </div>
    <!-- TABLE -->
    <div class="po-card">
        <table class="po-table">
            <colgroup>
                <col style="width:16%"><!-- Supplier -->
                <col style="width:11%"><!-- Contact -->
                <col style="width:10%"><!-- Order No. -->
                <col style="width:9%"> <!-- Status -->
                <col style="width:9%"> <!-- Order Date -->
                <col style="width:8%"> <!-- Due On -->
                <col style="width:9%"> <!-- Taxable -->
                <col style="width:9%"> <!-- Amount -->
                <col style="width:10%"><!-- Created By -->
                <col style="width:9%"> <!-- Actions -->
            </colgroup>
            <thead>
                <tr>
                    <?=poThSort('supplier_name',  'Supplier',       $sort_col,$sort_dir,$_GET)?>
                    <?=poThSort('contact_person',  'Contact',        $sort_col,$sort_dir,$_GET)?>
                    <?=poThSort('po_number',       'Order No.',      $sort_col,$sort_dir,$_GET)?>
                    <?=poThSort('status',          'Status',         $sort_col,$sort_dir,$_GET)?>
                    <?=poThSort('po_date',         'Order Date',     $sort_col,$sort_dir,$_GET)?>
                    <?=poThSort('due_date',        'Due on',         $sort_col,$sort_dir,$_GET)?>
                    <?=poThSort('total_taxable',   'Taxable (₹)',    $sort_col,$sort_dir,$_GET,'num')?>
                    <?=poThSort('grand_total',     'Amount (₹)',     $sort_col,$sort_dir,$_GET,'num')?>
                    <?=poThSort('created_by',      'Created by',     $sort_col,$sort_dir,$_GET)?>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($orders)): ?>
                <tr><td colspan="10" style="text-align:center;padding:40px;color:#9ca3af;">No purchase orders found.</td></tr>
            <?php else: ?>
                <?php foreach ($orders as $o):
                    $key = strtolower(trim($o['supplier_name']));
                    $o['_email'] = $supplierMap[$key]['email'] ?? '';
                    $o['_phone'] = !empty($o['supplier_phone']) ? $o['supplier_phone'] : ($supplierMap[$key]['phone'] ?? '');
                ?>
                <tr data-id="<?= $o['id'] ?>" data-obj='<?= htmlspecialchars(json_encode($o)) ?>' onclick="openPopup(<?= htmlspecialchars(json_encode($o)) ?>)">
                    <td><?= htmlspecialchars($o['supplier_name']) ?></td>
                    <td><?= htmlspecialchars($o['contact_person']) ?></td>
                    <td style="color:#1565c0;font-weight:400;"><?= htmlspecialchars($o['po_number']) ?></td>
                    <td>
                        <?php $sc=strtolower($o['status']); ?>
                        <span class="badge-status <?= $sc ?>">
                            <?= htmlspecialchars($o['status']) ?>
                        </span>
                    </td>
                    <td><?= smartDate($o['po_date']) ?></td>
                    <td><?= smartDate($o['due_date']) ?></td>
                    <td class="num"><?= indFmt($o['total_taxable']) ?></td>
                    <td class="num"><?= indFmt($o['grand_total']) ?></td>
                    <td style="font-size:12px;"><?= htmlspecialchars($o['created_by']) ?></td>
                    <td onclick="event.stopPropagation()">
                        <div class="action-btns" id="actions-<?= $o['id'] ?>">
                            <?php $sc = strtolower($o['status']); ?>
                            <?php if($sc === 'pending'): ?>
                            <button class="action-btn btn-approve" onclick="openAprModal(<?= $o['id'] ?>, '<?= htmlspecialchars($o['po_number']) ?>')" title="Approve">
                                <i class="fas fa-check"></i>
                            </button>
                            <?php endif; ?>
                            <?php if($sc === 'approved'): ?>
                            <button class="action-btn btn-complete" onclick="openCmpModal(<?= $o['id'] ?>, '<?= htmlspecialchars($o['po_number']) ?>')" title="Mark Complete">
                                <i class="fas fa-flag-checkered"></i>
                            </button>
                            <?php endif; ?>
                            <?php if($sc !== 'completed'): ?>
                            <button class="action-btn btn-bell" onclick="openReminderModal(<?= htmlspecialchars(json_encode($o)) ?>)" title="Send Reminder">
                                <i class="fas fa-bell"></i>
                            </button>
                            <?php endif; ?>
                            <a href="createpurchase.php?edit=<?= $o['id'] ?>" class="action-btn btn-edit" title="Edit">
                                <i class="fas fa-pencil-alt"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- PAGINATION -->
    <div class="pagination">
    <?php
    $qs = ['status'=>$status_filter,'period'=>$period_filter,'search'=>$search,'per_page'=>$per_page,'sort_col'=>$sort_col,'sort_dir'=>$sort_dir];
    if ($fin_year !== '') $qs['fin_year'] = $fin_year;
    $pg = $current_page; $tp = $total_pages;
    $qs['page'] = $pg - 1;
    echo $pg <= 1 ? '<span class="disabled">&laquo;</span>' : "<a href='".htmlspecialchars('?'.http_build_query($qs))."'>&laquo;</a>";
    $pages = [];
    for ($i = 1; $i <= $tp; $i++) {
        if ($i <= 3 || $i == $tp || abs($i - $pg) <= 1) $pages[] = $i;
    }
    $pages = array_unique($pages); sort($pages);
    $prev = null;
    foreach ($pages as $p) {
        if ($prev !== null && $p - $prev > 1) echo "<span class='dots'>…</span>";
        if ($p == $pg) echo "<span class='active'>$p</span>";
        else { $qs['page']=$p; echo "<a href='".htmlspecialchars('?'.http_build_query($qs))."'>$p</a>"; }
        $prev = $p;
    }
    $qs['page'] = $pg + 1;
    echo $pg >= $tp ? '<span class="disabled">&raquo;</span>' : "<a href='".htmlspecialchars('?'.http_build_query($qs))."'>&raquo;</a>";
    ?>
    </div>

</div>

<!-- POPUP -->
<div class="popup-overlay" id="popupOverlay" onclick="closePopup(event)">
    <div class="popup-box" onclick="event.stopPropagation()">
        <div class="popup-header">
            <div>
                <div class="popup-po-num" id="popMeta"></div>
                <div class="popup-supplier" id="popSupplier"></div>
            </div>
            <button class="popup-close" onclick="closePopup()">✕</button>
        </div>
        <div class="popup-body">
            <div class="popup-section-title">Order Details</div>
            <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                <span class="pill-tag" id="popAmount"></span>
                <span class="pill-tag pill-green" id="popPreTax"></span>
                <span class="pill-tag pill-green" id="popStatus"></span>
            </div>
            <div id="popContactRow" style="font-size:12px;color:#374151;margin-bottom:4px;display:none;">
                <span style="color:#9ca3af;font-weight:600;">Contact:</span> <span id="popContact"></span>
            </div>
            <div id="popEmailRow" style="font-size:12px;color:#374151;margin-bottom:4px;display:none;">
                <span style="color:#9ca3af;font-weight:600;">Email:</span> <span id="popEmail"></span>
            </div>
            <div id="popRefRow" style="font-size:12px;color:#374151;display:none;">
                <span style="color:#9ca3af;font-weight:600;">Ref:</span> <span id="popRef"></span>
            </div>
        </div>
        <div class="popup-footer">
            <a id="popPdfBtn" href="#" class="btn-popup-pdf" onclick="downloadPO(event)">
                <i class="fas fa-download"></i> Download PO
            </a>
            <button class="btn-popup-cancel" onclick="closePopup()">
                ✕ Cancel
            </button>
        </div>
    </div>
</div>

<script>
let currentOrder = null;
function fmt(n){ return parseFloat(n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }

function openPopup(o) {
    currentOrder = o;
    document.getElementById('popSupplier').textContent = o.supplier_name || '—';
    document.getElementById('popMeta').textContent = '#'+o.po_number+'  |  📅 '+o.po_date;
    document.getElementById('popAmount').textContent = 'Total ₹ '+fmt(o.grand_total);
    document.getElementById('popPreTax').textContent = 'Pre-Tax ₹ '+fmt(o.total_taxable);
    document.getElementById('popStatus').textContent = o.status;
    document.getElementById('popStatus').className   = 'pill-tag pill-green';
    document.getElementById('popPdfBtn').dataset.url = 'downloadpurchase.php?id='+o.id;
    // Contact
    const cRow = document.getElementById('popContactRow');
    if (o.contact_person) { document.getElementById('popContact').textContent = o.contact_person; cRow.style.display='block'; }
    else cRow.style.display='none';
    // Ref
    const rRow = document.getElementById('popRefRow');
    if (o.reference) { document.getElementById('popRef').textContent = o.reference; rRow.style.display='block'; }
    else rRow.style.display='none';
    // Email — fetch from suppliers
    const eRow = document.getElementById('popEmailRow');
    eRow.style.display='none';
    fetch('createpurchase.php?get_suppliers=1')
        .then(r=>r.json())
        .then(list => {
            const s = list.find(x=>x.supplier_name===o.supplier_name);
            if (s && s.email) { document.getElementById('popEmail').textContent=s.email; eRow.style.display='block'; }
        }).catch(()=>{});
    document.getElementById('popupOverlay').classList.add('open');
}
function closePopup(e){
    if(!e||e.target===document.getElementById('popupOverlay'))
        document.getElementById('popupOverlay').classList.remove('open');
}
function goEdit(){ if(currentOrder) window.location='createpurchase.php?edit='+currentOrder.id; }

function deleteOrder(){
    if(!currentOrder) return;
    if(confirm('Delete PO '+currentOrder.po_number+'? This will also delete all its items and terms.')){
        fetch('action.php',{
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:'action=delete&id='+currentOrder.id
        }).then(r=>r.json()).then(d=>{ if(d.success) location.reload(); else alert('Error: '+d.message); });
    }
}
let _aprId = null;
function openAprModal(id, poNum) {
    _aprId = id;
    document.getElementById('aprSub').textContent = 'Approve PO ' + poNum + '?';
    document.getElementById('aprOverlay').classList.add('open');
}
function closeAprModal() {
    document.getElementById('aprOverlay').classList.remove('open');
    _aprId = null;
}
function updateRowToApproved(id) {
    // Update status badge
    const allRows = document.querySelectorAll('.po-table tbody tr');
    allRows.forEach(function(tr) {
        const actDiv = tr.querySelector('#actions-' + id);
        if (actDiv) {
            // Remove tick + bell buttons
            const ab = actDiv.querySelector('.btn-approve');
            const bb = actDiv.querySelector('.btn-bell');
            if (ab) ab.remove();
            if (bb) bb.remove();
            // Update badge
            const badge = tr.querySelector('.badge-status');
            if (badge) {
                badge.className = 'badge-status approved';
                badge.textContent = 'Approved';
            }
        }
    });
}

function doApprove() {
    if (!_aprId) return;
    const id = _aprId;
    const btn = document.getElementById('aprConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Approving…';

    fetch('action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=approve&id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        document.getElementById('aprOverlay').classList.remove('open');
        _aprId = null;
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Approve';
        // Update UI regardless — server updated DB, show it
        updateRowToApproved(id);
    })
    .catch(function() {
        // Even on network error, still update UI optimistically
        document.getElementById('aprOverlay').classList.remove('open');
        _aprId = null;
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Approve';
        updateRowToApproved(id);
    });
}
document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
        document.getElementById('popupOverlay').classList.remove('open');
        closeAprModal();
        closeReminderModal();
    }
});
document.getElementById('aprOverlay').addEventListener('click', function(e){
    if(e.target===this) closeAprModal();
});

// ── Complete modal ──
var _cmpId = null;
function openCmpModal(id, poNum) {
    _cmpId = id;
    document.getElementById('cmpSub').textContent = 'Mark PO ' + poNum + ' as Completed?';
    document.getElementById('cmpOverlay').classList.add('open');
}
function closeCmpModal() {
    document.getElementById('cmpOverlay').classList.remove('open');
    _cmpId = null;
}
function updateRowToCompleted(id) {
    document.querySelectorAll('.po-table tbody tr').forEach(function(tr) {
        var actDiv = document.getElementById('actions-' + id);
        if (actDiv && tr.contains(actDiv)) {
            var cmpBtn = actDiv.querySelector('.btn-complete');
            if (cmpBtn) cmpBtn.remove();
            var badge = tr.querySelector('.badge-status');
            if (badge) {
                badge.className = 'badge-status completed';
                badge.textContent = 'Completed';
            }
        }
    });
}
function doComplete() {
    if (!_cmpId) return;
    var id = _cmpId;
    var btn = document.getElementById('cmpConfirmBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    fetch('action.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=complete&id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        closeCmpModal();
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-flag-checkered"></i> Complete';
        updateRowToCompleted(id);
    })
    .catch(function() {
        closeCmpModal();
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-flag-checkered"></i> Complete';
        updateRowToCompleted(id);
    });
}
// attach overlay click after DOM ready
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('cmpOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeCmpModal();
    });
});

// ── Reminder Modal ──
var _remOrder = null;

function openReminderModal(o) {
    _remOrder = o;
    document.getElementById('remSupplier').textContent = o.supplier_name || '';
    document.getElementById('remPONum').textContent = 'PO # ' + o.po_number;
    document.getElementById('remAmount').textContent = 'Order Amount ₹ ' + parseFloat(o.grand_total).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
    // Build default message
    var dueDate = o.due_date ? new Date(o.due_date).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'2-digit'}).replace(/ /g,'-') : '';
    var poDate  = o.po_date  ? new Date(o.po_date ).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'2-digit'}).replace(/ /g,'-') : '';
    document.getElementById('remMessage').value =
        'Gentle reminder to complete order #' + o.po_number +
        ' dated ' + poDate +
        ' for ' + (o.supplier_name || '') +
        ' due on ' + dueDate;
    document.getElementById('remOverlay').classList.add('open');
}

function closeReminderModal() {
    document.getElementById('remOverlay').classList.remove('open');
    _remOrder = null;
}

function sendReminderEmail() {
    if (!_remOrder) return;
    var msg     = document.getElementById('remMessage').value.trim();
    var to      = (_remOrder._email || '').trim();
    var subject = 'Order Reminder: ' + _remOrder.po_number;

    if (!to) {
        showMiniToast('No email found for this supplier.', 'error');
        return;
    }
    if (!msg) {
        showMiniToast('Please enter a message.', 'error');
        return;
    }

    var btn = document.querySelector('.btn-rem-email');
    if (btn) { btn.disabled = true; btn.textContent = 'Sending...'; }

    var fd = new FormData();
    fd.append('send_reminder_email', '1');
    fd.append('to', to);
    fd.append('subject', subject);
    fd.append('message', msg);

    fetch('pindex.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-envelope"></i> Send Email'; }
            if (d.success) {
                showMiniToast('✓ Email sent to ' + to, 'success');
                closeReminderModal();
            } else {
                showMiniToast('Failed: ' + (d.message || 'Unknown error'), 'error');
            }
        })
        .catch(function(){
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-envelope"></i> Send Email'; }
            showMiniToast('Network error. Try again.', 'error');
        });
}

function sendReminderWhatsApp() {
    if (!_remOrder) return;
    var msg   = document.getElementById('remMessage').value.trim();
    var phone = (_remOrder._phone || '').replace(/[^0-9]/g, '');
    if (phone.length === 10) phone = '91' + phone;
    var url = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(msg);
    window.open(url, '_blank');
}

function showMiniToast(msg, type) {
    var t = document.getElementById('miniToast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'miniToast';
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;color:#fff;z-index:99999;opacity:0;transition:opacity 0.3s;pointer-events:none;';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.background = (type === 'error') ? '#dc2626' : '#16a34a';
    t.style.opacity = '1';
    clearTimeout(t._timer);
    t._timer = setTimeout(function(){ t.style.opacity = '0'; }, 3000);
}

document.getElementById('remOverlay').addEventListener('click', function(e){
    if (e.target === this) closeReminderModal();
});

function downloadPO(e) {
    e.preventDefault();
    const url = document.getElementById('popPdfBtn').dataset.url;
    if (!url) return;
    // Open in new tab — page auto-triggers print/save dialog
    const win = window.open(url, '_blank');
    if (!win) {
        // Popup blocked fallback — navigate directly
        window.location.href = url;
    }
}
</script>
<!-- Hidden iframe for PDF generation -->
<iframe id="pdfFrame" style="display:none;width:0;height:0;border:none;"></iframe>
<!-- ── Complete Modal ── -->
<div class="apr-overlay" id="cmpOverlay">
    <div class="apr-box">
        <div class="apr-header">
            <div class="apr-icon" style="background:#e0f2fe;"><i class="fas fa-flag-checkered" style="color:#0369a1;"></i></div>
            <div class="apr-title">Mark as Completed</div>
            <div class="apr-sub" id="cmpSub">Mark this PO as completed?</div>
        </div>
        <div class="apr-body">
            <button class="apr-btn-confirm" id="cmpConfirmBtn" style="background:#0369a1;" onclick="doComplete()">
                <i class="fas fa-flag-checkered"></i> Complete
            </button>
            <button class="apr-btn-cancel" onclick="document.getElementById('cmpOverlay').classList.remove('open')">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Approve Modal ── -->
<div class="apr-overlay" id="aprOverlay">
    <div class="apr-box">
        <div class="apr-header">
            <div class="apr-icon"><i class="fas fa-check"></i></div>
            <div class="apr-title">Approve Purchase Order</div>
            <div class="apr-sub" id="aprSub">Are you sure you want to approve this PO?</div>
        </div>
        <div class="apr-body">
            <button class="apr-btn-confirm" id="aprConfirmBtn" onclick="doApprove()"><i class="fas fa-check"></i> Approve</button>
            <button class="apr-btn-cancel" onclick="closeAprModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- ── Reminder Modal ── -->
<div class="rem-overlay" id="remOverlay">
    <div class="rem-box">
        <div class="rem-header">
            <div>
                <div class="rem-title">Send Order Reminder</div>
                <div class="rem-supplier" id="remSupplier"></div>
            </div>
            <button class="rem-close" onclick="closeReminderModal()">✕</button>
        </div>
        <div class="rem-body">
            <div class="rem-pills">
                <span class="rem-pill" id="remPONum"></span>
                <span class="rem-pill" id="remAmount"></span>
            </div>
            <textarea class="rem-textarea" id="remMessage"></textarea>
        </div>
        <div class="rem-footer">
            <button class="btn-rem-email" onclick="sendReminderEmail()">
                <i class="fas fa-check"></i> Send by Email
            </button>
            <button class="btn-rem-wa" onclick="sendReminderWhatsApp()">
                <i class="fab fa-whatsapp"></i> Send by WhatsApp
            </button>
        </div>
    </div>
</div>

<script>
var _debTimer;
function debounceSubmit(form) {
    clearTimeout(_debTimer);
    _debTimer = setTimeout(function(){ form.submit(); }, 300);
}

function filterByStatus(status) {
    var form = document.getElementById('filterForm');
    var statusSel = form.querySelector('[name="status"]');
    if (statusSel) statusSel.value = status;
    var pgInput = form.querySelector('[name="page"]');
    if (pgInput) pgInput.value = 1;
    if (status === 'Pending') {
        var periodSel = form.querySelector('[name="period"]');
        if (periodSel) periodSel.value = 'all';
        var fySel = form.querySelector('[name="fin_year"]');
        if (fySel) fySel.value = '';
        var ppSel = form.querySelector('[name="per_page"]');
        if (ppSel) ppSel.value = 100;
    }
    form.submit();
}
(function(){
    if ('<?= addslashes($status_filter) ?>' === 'Pending') {
        var pill = document.getElementById('pendingPill');
        if (pill) { pill.style.background='#fff7ed'; pill.style.boxShadow='0 0 0 2px #f97316'; }
    }
})();
var _ajaxTimer;
function ajaxSearch(q) {
    clearTimeout(_ajaxTimer);
    _ajaxTimer = setTimeout(function() { doAjaxSearch(q); }, 300);
}

function doAjaxSearch(q) {
    var status  = document.querySelector('[name="status"]') ? document.querySelector('[name="status"]').value : 'All';
    // When searching, always use 'all' period so results are truly global across all pages/dates
    var period  = q.trim() ? 'all' : (document.querySelector('[name="period"]') ? document.querySelector('[name="period"]').value : 'this_month');
    var url = 'pindex.php?status=' + encodeURIComponent(status) +
              '&period=' + encodeURIComponent(period) +
              '&search=' + encodeURIComponent(q) +
              '&page=1&ajax=1';
    fetch(url)
        .then(function(r){ return r.json(); })
        .then(function(data) {
            var tbody = document.querySelector('.po-table tbody');
            if (!tbody) return;
            tbody.innerHTML = data.html;
            // re-bind popup clicks
            tbody.querySelectorAll('tr[data-id]').forEach(function(row) {
                row.addEventListener('click', function() {
                    var d = JSON.parse(this.getAttribute('data-obj'));
                    openPopup(d);
                });
            });
            // hide pagination when searching
            var pg = document.querySelector('.pagination');
            if (pg) pg.style.display = q.trim() ? 'none' : '';
            // update count pill
            var pill = document.querySelector('.sum-pill.orange .val');
            if (pill) pill.textContent = data.count;
        })
        .catch(function(){});
}

document.addEventListener('DOMContentLoaded', function() {
    var s = document.getElementById('liveSearch');
    if (s && s.value.trim()) doAjaxSearch(s.value);
});
</script>
</body>
</html>
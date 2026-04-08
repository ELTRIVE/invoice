<?php
require_once dirname(__DIR__) . '/db.php';

// ── AJAX live search handler ───────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $status_f = $_GET['status'] ?? 'All';
    $period_f = $_GET['period'] ?? 'all';
    $q        = trim($_GET['search'] ?? '');
    switch($period_f){
        case 'last_month': $df=date('Y-m-01',strtotime('first day of last month')); $dt=date('Y-m-t',strtotime('last day of last month')); break;
        case 'this_year':  $df=date('Y-01-01'); $dt=date('Y-12-31'); break;
        case 'all':        $df='2000-01-01'; $dt='2099-12-31'; break;
        default:           $df=date('Y-m-01'); $dt=date('Y-m-t');
    }
    $where=['quot_date BETWEEN :df AND :dt']; $params=[':df'=>$df,':dt'=>$dt];
    if($status_f && $status_f!=='All'){$where[]='status=:st';$params[':st']=$status_f;}
    if($q!==''){$where[]='(customer_name LIKE :s OR quot_number LIKE :s OR contact_person LIKE :s)';$params[':s']="%$q%";}
    $wsql=implode(' AND ',$where);
    $cs=$pdo->prepare("SELECT COUNT(*) FROM quotations WHERE $wsql"); $cs->execute($params); $cnt=(int)$cs->fetchColumn();
    $rs=$pdo->prepare("SELECT * FROM quotations WHERE $wsql ORDER BY created_at DESC LIMIT 200"); $rs->execute($params); $quotes=$rs->fetchAll(PDO::FETCH_ASSOC);
    function _qi_indFmt($n){$n=number_format((float)$n,2,'.','');$p=explode('.',$n);$i=$p[0];$d=$p[1];$neg='';if(isset($i[0])&&$i[0]==='-'){$neg='-';$i=substr($i,1);}if(strlen($i)<=3)return $neg.$i.'.'.$d;$l3=substr($i,-3);$r=substr($i,0,-3);$r=preg_replace('/\B(?=(\d{2})+(?!\d))/',',',$r);return $neg.$r.','.$l3.'.'.$d;}
    ob_start();
    if(empty($quotes)){
        echo '<tr><td colspan="6" style="text-align:center;padding:40px;color:#9ca3af;">No quotations found.</td></tr>';
    } else {
        foreach($quotes as $q2){
            $obj=htmlspecialchars(json_encode($q2));
            echo '<tr class="qt-row" onclick="openPopup('.$obj.')">';
            echo '<td><strong style="color:#f97316">'.htmlspecialchars($q2['quot_number']).'</strong></td>';
            echo '<td><div style="font-weight:700">'.htmlspecialchars($q2['customer_name']).'</div>';
            if($q2['contact_person']) echo '<div style="font-size:12px;color:#9ca3af;margin-top:2px">'.htmlspecialchars($q2['contact_person']).'</div>';
            echo '</td>';
            echo '<td>'.date('d-M-y',strtotime($q2['quot_date'])).'</td>';
            echo '<td>'.date('d-M-y',strtotime($q2['valid_till'])).'</td>';
            echo '<td class="col-amount" style="font-weight:700;">&#8377; '._qi_indFmt($q2['grand_total']).'</td>';
            echo '<td onclick="event.stopPropagation()"><div class="action-btns"><a href="quote_create.php?edit='.$q2['id'].'" class="action-btn btn-edit" title="Edit"><i class="fas fa-pencil-alt"></i></a></div></td>';
            echo '</tr>';
        }
    }
    $html=ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode(['html'=>$html,'count'=>$cnt]);
    exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS quotations (id INT AUTO_INCREMENT PRIMARY KEY,quot_number VARCHAR(50) NOT NULL UNIQUE,customer_name VARCHAR(255) NOT NULL,contact_person VARCHAR(255) DEFAULT '',customer_address TEXT,customer_gstin VARCHAR(100) DEFAULT '',customer_phone VARCHAR(50) DEFAULT '',reference VARCHAR(255) DEFAULT '',quot_date DATE NOT NULL,valid_till DATE NOT NULL,notes TEXT,shipping_details TEXT,status ENUM('Draft','Sent','Approved','Rejected') DEFAULT 'Draft',total_taxable DECIMAL(15,2) DEFAULT 0.00,total_cgst DECIMAL(15,2) DEFAULT 0.00,total_sgst DECIMAL(15,2) DEFAULT 0.00,total_igst DECIMAL(15,2) DEFAULT 0.00,grand_total DECIMAL(15,2) DEFAULT 0.00,items_json LONGTEXT NULL,item_list LONGTEXT NULL,terms_list LONGTEXT NULL,created_by VARCHAR(255) DEFAULT '',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");

function indFmt($n){
    $n=number_format((float)$n,2,'.','');$p=explode('.',$n);$i=$p[0];$d=$p[1];
    $neg='';if(isset($i[0])&&$i[0]==='-'){$neg='-';$i=substr($i,1);}
    if(strlen($i)<=3)return $neg.$i.'.'.$d;
    $l3=substr($i,-3);$r=substr($i,0,-3);$r=preg_replace('/\B(?=(\d{2})+(?!\d))/',',',$r);
    return $neg.$r.','.$l3.'.'.$d;
}

$status_filter = $_GET['status'] ?? 'All';
$period_filter = $_GET['period'] ?? 'all';
$fin_year      = $_GET['fin_year'] ?? '';
$search        = trim($_GET['search'] ?? '');
$per_page      = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;
$current_page  = max(1,(int)($_GET['page'] ?? 1));

// ── SORT ──
$sort_col = $_GET['sort_col'] ?? '';
$sort_dir = $_GET['sort_dir'] ?? 'asc';
$allowed_sorts = [
    'quot_number'   => 'quot_number',
    'customer_name' => 'customer_name',
    'quot_date'     => 'quot_date',
    'valid_till'    => 'valid_till',
    'grand_total'   => 'grand_total',
];
$order_sql = 'created_at DESC';
if ($sort_col && isset($allowed_sorts[$sort_col])) {
    $sdir = ($sort_dir === 'desc') ? 'DESC' : 'ASC';
    $order_sql = $allowed_sorts[$sort_col] . ' ' . $sdir . ', created_at DESC';
}

switch($period_filter){
    case 'last_month': $df=date('Y-m-01',strtotime('first day of last month')); $dt=date('Y-m-t',strtotime('last day of last month')); break;
    case 'this_year':  $fm=(int)date('n');$fy=(int)date('Y'); $df=($fm>=4)?($fy.'-04-01'):(($fy-1).'-04-01'); $dt=($fm>=4)?(($fy+1).'-03-31'):($fy.'-03-31'); break;
    case 'last_year':  $fm=(int)date('n');$fy=(int)date('Y'); $df=($fm>=4)?(($fy-1).'-04-01'):(($fy-2).'-04-01'); $dt=($fm>=4)?($fy.'-03-31'):(($fy-1).'-03-31'); break;
    case 'all':        $df='2000-01-01'; $dt='2099-12-31'; break;
    default:           $df=date('Y-m-01'); $dt=date('Y-m-t');
}

// Financial Year dropdown overrides period date range if selected
if ($fin_year !== '') {
    $fyMap = [
        'fy_2023_24' => ['2023-04-01','2024-03-31'],
        'fy_2024_25' => ['2024-04-01','2025-03-31'],
        'fy_2025_26' => ['2025-04-01','2026-03-31'],
        'fy_2026_27' => ['2026-04-01','2027-03-31'],
    ];
    if (isset($fyMap[$fin_year])) { $df=$fyMap[$fin_year][0]; $dt=$fyMap[$fin_year][1]; }
}

$where=['quot_date BETWEEN :df AND :dt'];
$params=[':df'=>$df,':dt'=>$dt];
if($status_filter==='pending'){ $where[]="status IN ('Draft','Sent')"; }
elseif($status_filter && $status_filter!=='All'&&$status_filter!=='pending'){$where[]='status=:st';$params[':st']=$status_filter;}
if($search!==''){$where[]='(customer_name LIKE :s OR quot_number LIKE :s OR contact_person LIKE :s)';$params[':s']="%$search%";}
$wsql=implode(' AND ',$where);

$all=$pdo->prepare("SELECT COUNT(*),SUM(grand_total) FROM quotations WHERE $wsql");
$all->execute($params);$ar=$all->fetch(PDO::FETCH_NUM);
$count=(int)$ar[0];$total_amount=(float)$ar[1];
$total_pages=max(1,(int)ceil($count/$per_page));
if($current_page>$total_pages)$current_page=$total_pages;
$offset=($current_page-1)*$per_page;

$stmt=$pdo->prepare("SELECT * FROM quotations WHERE $wsql ORDER BY $order_sql LIMIT ".(int)$per_page." OFFSET ".(int)$offset);
$stmt->execute($params);
$quotes=$stmt->fetchAll(PDO::FETCH_ASSOC);

$scounts=[];
foreach(['Draft','Sent','Approved','Rejected'] as $s){
    $sc=$pdo->prepare("SELECT COUNT(*) FROM quotations WHERE $wsql AND status=:_st");
    $sc->execute(array_merge($params,[':_st'=>$s]));
    $scounts[$s]=(int)$sc->fetchColumn();
}

function pageUrl($pg,$st,$pf,$sr,$pp=10,$sc='',$sd='',$fy=''){return '?'.http_build_query(array_filter(['status'=>$st,'period'=>$pf,'search'=>$sr,'page'=>$pg,'per_page'=>$pp,'sort_col'=>$sc,'sort_dir'=>$sd,'fin_year'=>$fy],fn($v)=>$v!==''));}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Quotations</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;background:#f4f6fb;color:#1a1f2e;font-size:15px;height:100vh;overflow:hidden}
.content{margin-left:220px;padding:10px 18px 6px;height:100vh;display:flex;flex-direction:column;overflow:hidden}
.header-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
h2{font-weight:700;color:#1a1f2e;font-size:18px}
.filter-bar{display:flex;align-items:center;gap:8px;margin-bottom:5px;flex-wrap:nowrap}
.filter-bar select{padding:4px 8px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:12px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;cursor:pointer;outline:none}
.filter-bar select:focus{border-color:#f97316}
/* ── SEARCH BAR ── */
.search-wrap{position:relative;width:230px}
.search-wrap .search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none;font-style:normal;line-height:1}
.search-wrap input[type=text]{width:100%;padding:7px 28px 7px 34px;border:1.5px solid #d1d5db;border-radius:50px;font-size:12.5px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;outline:none;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:border-color .2s,box-shadow .2s}
.search-wrap input[type=text]:focus{border-color:#93c5fd;box-shadow:0 0 0 3px rgba(147,197,253,.2)}
.search-wrap input[type=text]::placeholder{color:#9ca3af;font-size:12px}
.status-tabs{display:flex;gap:0;border-bottom:2px solid #f0f2f7;margin-bottom:5px}
.status-tab{padding:5px 12px;font-size:12px;font-weight:600;color:#6b7280;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;text-decoration:none;transition:all .2s}
.status-tab:hover{color:#f97316}
.status-tab.active{color:#f97316;border-bottom-color:#f97316;font-weight:800}
.stat-badges{display:flex;gap:10px;margin-bottom:5px;flex-wrap:wrap}
.stat-badge{display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:8px;border:1.5px solid #f97316;background:#fff;font-size:11px;color:#374151;white-space:nowrap}
.stat-badge .label{color:#6b7280}.stat-badge .value{font-weight:700;color:#f97316}
.stat-badge.blue{border-color:#2563eb}.stat-badge.blue .value{color:#2563eb}
.stat-badge.green{border-color:#16a34a}.stat-badge.green .value{color:#16a34a}
.stat-badge.red{border-color:#dc2626}.stat-badge.red .value{color:#dc2626}
.card{background:#fff;border-radius:12px;padding:8px 12px;border:1px solid #e4e8f0;flex:1;overflow-y:auto}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;padding:0 8px 5px 8px;font-weight:700}
td{padding:5px 6px 5px 6px;border-top:1px solid #f1f5f9;font-size:12px;color:#1a1f2e}
.col-amount{text-align:right;width:140px}
.col-actions{text-align:left;width:80px}
.qt-row{cursor:pointer;transition:background .15s}
.qt-row:hover{background:#fff7f0}
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;white-space:nowrap}
.pill-draft{background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1}
.pill-sent{background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe}
.pill-approved{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0}
.pill-rejected{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}
.action-btns{display:flex;gap:5px;align-items:center}
.action-btn{width:30px;height:30px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;border:none;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-edit{background:#f4f6fb;color:#6b7280;border:1px solid #e4e8f0}.btn-edit:hover{background:#f97316;color:#fff;border-color:#f97316}
.btn-pdf{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}.btn-pdf:hover{background:#dc2626;color:#fff}
.btn-convert{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0}.btn-convert:hover{background:#16a34a;color:#fff}
.btn-del{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}.btn-del:hover{background:#dc2626;color:#fff}
.btn{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;background:#f97316;color:#fff;text-decoration:none;font-size:12px;font-weight:600;border:none;cursor:pointer;font-family:'Times New Roman',Times,serif;transition:background .2s}
.btn:hover{background:#fb923c}
.pagination{display:flex;justify-content:center;align-items:center;gap:5px;padding:4px 0 2px}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;border:1.5px solid #e4e8f0;color:#374151;background:#fff;transition:all .15s}
.pagination a:hover{border-color:#f97316;color:#f97316;background:#fff7f0}
.pagination span.active{background:#f97316!important;color:#fff!important;border-color:#f97316!important}
.pagination span.dots{border:none;background:none;color:#9ca3af}
.popup-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);backdrop-filter:blur(3px);z-index:2000;align-items:center;justify-content:center}
.popup-overlay.open{display:flex}
.popup-box{background:#fff;border-radius:16px;width:420px;max-width:95vw;box-shadow:0 16px 48px rgba(0,0,0,.14);font-family:'Times New Roman',Times,serif;overflow:hidden}
.popup-header{display:flex;justify-content:space-between;align-items:flex-start;padding:20px 22px 16px;border-bottom:1px solid #e4e8f0;background:#fafbfc}
.popup-qt-num{font-size:12px;color:#9ca3af;font-weight:600;margin-bottom:4px;text-transform:uppercase;letter-spacing:.7px}
.popup-customer{font-size:22px;font-weight:800;color:#1a1f2e}
.popup-close{background:none;border:none;font-size:22px;color:#9ca3af;cursor:pointer;width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:50%;transition:all .2s}
.popup-close:hover{background:#fee2e2;color:#dc2626}
.popup-body{padding:18px 24px}
.popup-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:15px}
.popup-row:last-child{border-bottom:none}
.popup-label{color:#6b7280;font-weight:600}
.popup-value{color:#1a1f2e;font-weight:700;text-align:right}
.popup-footer{display:flex;gap:10px;padding:16px 24px;border-top:1px solid #e4e8f0;background:#fafbfc;flex-wrap:wrap}
.pop-btn{flex:1;padding:9px 0;border-radius:8px;font-size:13px;font-weight:700;border:none;cursor:pointer;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;justify-content:center;gap:5px;text-decoration:none;transition:all .2s}
.pop-btn-edit{background:#f4f6fb;color:#374151;border:1px solid #e2e8f0}.pop-btn-edit:hover{background:#f97316;color:#fff}
.pop-btn-pdf{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}.pop-btn-pdf:hover{background:#dc2626;color:#fff}
.pop-btn-convert{background:#dcfce7;color:#16a34a;border:1px solid #bbf7d0}.pop-btn-convert:hover{background:#16a34a;color:#fff}
.pop-btn-del{background:#fee2e2;color:#dc2626;border:1px solid #fecaca}.pop-btn-del:hover{background:#dc2626;color:#fff}
.apr-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:3000;align-items:center;justify-content:center}
.apr-overlay.open{display:flex}
.apr-box{background:#fff;border-radius:16px;width:320px;max-width:92vw;box-shadow:0 16px 48px rgba(0,0,0,.18);overflow:hidden}
.apr-header{padding:24px 24px 16px;text-align:center;background:#fafbfc;border-bottom:1px solid #e4e8f0}
.apr-icon{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:18px}
.apr-title{font-size:16px;font-weight:800;color:#1a1f2e;margin-bottom:6px}
.apr-sub{font-size:13px;color:#6b7280}
.apr-body{padding:20px 24px;display:flex;gap:10px}
.apr-btn-confirm{flex:1;padding:10px;border-radius:8px;color:#fff;border:none;font-size:14px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif}
.apr-btn-cancel{flex:1;padding:10px;border-radius:8px;background:#f4f6fb;color:#374151;border:1px solid #e4e8f0;font-size:14px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif}
.toast{position:fixed;bottom:28px;right:28px;background:#1a1f2e;color:#fff;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;z-index:2000;display:none;gap:8px;align-items:center;box-shadow:0 8px 24px rgba(0,0,0,.2)}
.toast.show{display:flex}
.toast.success{border-left:4px solid #16a34a}
.toast.error{border-left:4px solid #f97316}
/* ── Sort headers ── */
.sort-th { white-space:nowrap; cursor:pointer; user-select:none; }
.sort-th:hover { color:#f97316; }
.sort-th .si { font-size:10px; color:#d1d5db; margin-left:4px; }
.sort-th.asc .si, .sort-th.desc .si { color:#f97316; }
/* Show entries */
.show-entries { display:flex; align-items:center; gap:8px; font-size:13px; color:#374151; margin-bottom:10px; }
.show-entries select { padding:5px 10px; border:1.5px solid #e2e8f0; border-radius:7px; font-size:13px;
    font-family:'Times New Roman',Times,serif; color:#374151; background:#fff; outline:none; cursor:pointer; }
.show-entries select:focus { border-color:#f97316; }
::-webkit-scrollbar{width:3px}::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:99px}
@media(max-width:900px){.content{margin-left:0!important;padding:70px 12px 20px}}
</style>
</head>
<body>
<?php include dirname(__DIR__) . '/sidebar.php'; ?>
<?php include dirname(__DIR__) . '/header.php'; ?>

<div class="content">
    <div class="header-bar">
        <h2>Quotations</h2>
        <div style="display:flex;align-items:center;gap:10px;">
            <form method="GET" id="searchForm" style="margin:0">
                <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                <input type="hidden" name="period" value="<?= htmlspecialchars($period_filter) ?>">
                <div class="search-wrap">
                    <span class="search-icon">🔍</span>
                    <input type="text" name="search" id="liveSearch"
                           placeholder="Search customer, quot no..."
                           value="<?= htmlspecialchars($search) ?>"
                           oninput="ajaxSearch(this.value)"
                           autocomplete="off">
                    <button type="submit" style="display:none"></button>
                </div>
            </form>
            <a href="quote_create.php" class="btn"><i class="fas fa-plus"></i> New Quotation</a>
        </div>
    </div>

    <form method="GET" id="filterForm">
        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <input type="hidden" name="per_page" value="<?= $per_page ?>">
        <input type="hidden" name="sort_col" value="<?= htmlspecialchars($sort_col) ?>">
        <input type="hidden" name="sort_dir" value="<?= htmlspecialchars($sort_dir) ?>">
        <div class="filter-bar" style="display:flex;align-items:center;gap:8px;margin-bottom:5px;flex-wrap:nowrap;">
            <select name="period" onchange="document.getElementById('filterForm').submit()">
                <option value="this_month" <?= $period_filter==='this_month'?'selected':'' ?>>This Month</option>
                <option value="last_month" <?= $period_filter==='last_month'?'selected':'' ?>>Last Month</option>
                <option value="this_year"  <?= $period_filter==='this_year'?'selected':'' ?>>This Financial Year</option>
                <option value="last_year"  <?= $period_filter==='last_year'?'selected':'' ?>>Last Financial Year</option>
                <option value="all"        <?= $period_filter==='all'?'selected':'' ?>>All Time</option>
            </select>
            <select name="fin_year" onchange="document.getElementById('filterForm').submit()">
                <option value="">Fin Year</option>
                <option value="fy_2023_24" <?= $fin_year==='fy_2023_24'?'selected':'' ?>>FY 2023-24</option>
                <option value="fy_2024_25" <?= $fin_year==='fy_2024_25'?'selected':'' ?>>FY 2024-25</option>
                <option value="fy_2025_26" <?= $fin_year==='fy_2025_26'?'selected':'' ?>>FY 2025-26</option>
                <option value="fy_2026_27" <?= $fin_year==='fy_2026_27'?'selected':'' ?>>FY 2026-27</option>
            </select>
            <button type="submit" style="display:none"></button>
        </div>
    </form>
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;flex-wrap:nowrap;">
        <div class="stat-badge" style="cursor:pointer;" title="Show all" onclick="filterByStatus('')">
            <span class="label">Count</span><span class="value"><?= $count ?></span>
        </div>
        <?php
        $pretax_stmt = $pdo->prepare("SELECT COALESCE(SUM(total_taxable),0) FROM quotations WHERE $wsql");
        $pretax_stmt->execute($params);
        $total_pretax = (float)$pretax_stmt->fetchColumn();
        ?>
        <div class="stat-badge" style="cursor:pointer;" title="Show all" onclick="filterByStatus('')">
            <span class="label">Pre-Tax</span><span class="value">&#8377; <?= indFmt($total_pretax) ?></span>
        </div>
        <div class="stat-badge blue" style="cursor:pointer;" title="Show all" onclick="filterByStatus('')">
            <span class="label">Total</span><span class="value">&#8377; <?= indFmt($total_amount) ?></span>
        </div>
        <?php
        $pend_params = array_merge($params, [':_pst1'=>'Draft',':_pst2'=>'Sent']);
        $pend_stmt = $pdo->prepare("SELECT COALESCE(SUM(grand_total),0) FROM quotations WHERE $wsql AND status IN (:_pst1,:_pst2)");
        $pend_stmt->execute($pend_params);
        $pending_amount = (float)$pend_stmt->fetchColumn();
        ?>
       
    </div>
    <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:#374151;margin-bottom:4px;">Show
        <select name="per_page" form="filterForm" onchange="document.getElementById('filterForm').submit();" style="padding:3px 6px;border:1.5px solid #e2e8f0;border-radius:7px;font-size:12px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;outline:none;">
            <?php foreach([10,25,50,100] as $n): ?>
            <option value="<?=$n?>" <?=$per_page==$n?'selected':''?>><?=$n?></option>
            <?php endforeach; ?>
        </select> entries
    </div>

    <div class="card">
        <?php if(empty($quotes)): ?>
        <div style="text-align:center;padding:48px 0;color:#9ca3af">
            <i class="fas fa-file-alt" style="font-size:40px;margin-bottom:12px;display:block;opacity:.3"></i>
            <div style="font-size:15px;font-weight:600;margin-bottom:8px">No quotations found</div>
            <a href="quote_create.php" style="color:#f97316;font-weight:700;text-decoration:none">+ Create your first quotation</a>
        </div>
        <?php else: ?>
        <?php
        function qtThSort($col, $label, $sort_col, $sort_dir, $get, $extraClass='') {
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
        <table>
            <thead>
                <tr>
                    <?=qtThSort('quot_number',  'Quot No.',       $sort_col,$sort_dir,$_GET)?>
                    <?=qtThSort('customer_name','Customer',       $sort_col,$sort_dir,$_GET)?>
                    <?=qtThSort('quot_date',    'Date',           $sort_col,$sort_dir,$_GET)?>
                    <?=qtThSort('valid_till',   'Valid Till',     $sort_col,$sort_dir,$_GET)?>
                    <?=qtThSort('grand_total',  'Amount (&#8377;)',$sort_col,$sort_dir,$_GET,'col-amount')?>
                    <th class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($quotes as $q): ?>
            <tr class="qt-row" onclick="openPopup(<?= htmlspecialchars(json_encode($q)) ?>)">
                <td><strong style="color:#f97316"><?= htmlspecialchars($q['quot_number']) ?></strong></td>
                <td>
                    <div style="font-weight:700"><?= htmlspecialchars($q['customer_name']) ?></div>
                    <?php if($q['contact_person']): ?><div style="font-size:12px;color:#9ca3af;margin-top:2px"><?= htmlspecialchars($q['contact_person']) ?></div><?php endif; ?>
                </td>
                <td><?= date('d-M-y',strtotime($q['quot_date'])) ?></td>
                <td><?= date('d-M-y',strtotime($q['valid_till'])) ?></td>
                <td class="col-amount" style="font-weight:700;">&#8377; <?= indFmt($q['grand_total']) ?></td>
                <td onclick="event.stopPropagation()">
                    <div class="action-btns">
                        <a href="quote_create.php?edit=<?= $q['id'] ?>" class="action-btn btn-edit" title="Edit"><i class="fas fa-pencil-alt"></i></a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <div class="pagination">
    <?php
    $pages = [];
    for ($i = 1; $i <= $total_pages; $i++) {
        if ($i <= 3 || $i == $total_pages || abs($i - $current_page) <= 1) $pages[] = $i;
    }
    $pages = array_unique($pages); sort($pages);
    echo $current_page <= 1 ? '<span class="disabled">&laquo;</span>' : '<a href="'.pageUrl($current_page-1,$status_filter,$period_filter,$search,$per_page,$sort_col,$sort_dir,$fin_year).'">&laquo;</a>';
    $prev = null;
    foreach ($pages as $p) {
        if ($prev !== null && $p - $prev > 1) echo '<span class="dots">…</span>';
        if ($p == $current_page) echo '<span class="active">'.$p.'</span>';
        else echo '<a href="'.pageUrl($p,$status_filter,$period_filter,$search,$per_page,$sort_col,$sort_dir,$fin_year).'">'.$p.'</a>';
        $prev = $p;
    }
    echo $current_page >= $total_pages ? '<span class="disabled">&raquo;</span>' : '<a href="'.pageUrl($current_page+1,$status_filter,$period_filter,$search,$per_page,$sort_col,$sort_dir,$fin_year).'">&raquo;</a>';
    ?>
    </div>

<div class="popup-overlay" id="popupOverlay">
    <div class="popup-box">
        <div class="popup-header">
            <div><div class="popup-qt-num" id="popQtNum"></div><div class="popup-customer" id="popCustomer"></div></div>
            <button class="popup-close" onclick="closePopup()">✕</button>
        </div>
        <div class="popup-body">
            <div class="popup-row"><span class="popup-label">Date</span><span class="popup-value" id="popDate"></span></div>
            <div class="popup-row"><span class="popup-label">Valid Till</span><span class="popup-value" id="popValid"></span></div>
            <div class="popup-row"><span class="popup-label">Status</span><span class="popup-value" id="popStatus"></span></div>
            <div class="popup-row"><span class="popup-label">Grand Total</span><span class="popup-value" id="popAmount"></span></div>
        </div>
        <div class="popup-footer">
            <a id="popDownloadBtn" href="#" target="_blank" class="pop-btn pop-btn-convert" style="background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none"><i class="fas fa-download"></i> Download</a>
            <button class="pop-btn" style="background:#f4f6fb;color:#374151;border:1px solid #e2e8f0;" onclick="closePopup()"><i class="fas fa-times"></i> Cancel</button>
        </div>
    </div>
</div>

<div class="apr-overlay" id="delOverlay">
    <div class="apr-box">
        <div class="apr-header"><div class="apr-icon" style="background:#fee2e2;color:#dc2626"><i class="fas fa-trash"></i></div><div class="apr-title">Delete Quotation</div><div class="apr-sub" id="delSub">Are you sure?</div></div>
        <div class="apr-body">
            <button class="apr-btn-confirm" style="background:#dc2626" onclick="doDelete()"><i class="fas fa-trash"></i> Delete</button>
            <button class="apr-btn-cancel" onclick="document.getElementById('delOverlay').classList.remove('open')">Cancel</button>
        </div>
    </div>
</div>

<div class="apr-overlay" id="convOverlay">
    <div class="apr-box">
        <div class="apr-header"><div class="apr-icon" style="background:#dcfce7;color:#16a34a"><i class="fas fa-exchange-alt"></i></div><div class="apr-title">Convert to Invoice</div><div class="apr-sub" id="convSub">Convert this quotation?</div></div>
        <div class="apr-body">
            <button class="apr-btn-confirm" style="background:#16a34a" id="convConfirmBtn" onclick="doConvert()"><i class="fas fa-check"></i> Convert</button>
            <button class="apr-btn-cancel" onclick="document.getElementById('convOverlay').classList.remove('open')">Cancel</button>
        </div>
    </div>
</div>

<div class="toast" id="toast"></div>

<script>
let currentQuot=null,_delId=null,_convId=null;
function openPopup(q){currentQuot=q;document.getElementById('popQtNum').textContent=q.quot_number;document.getElementById('popCustomer').textContent=q.customer_name;document.getElementById('popDate').textContent=q.quot_date;document.getElementById('popValid').textContent=q.valid_till;document.getElementById('popStatus').textContent=q.status;document.getElementById('popAmount').textContent='₹ '+parseFloat(q.grand_total||0).toLocaleString('en-IN',{minimumFractionDigits:2});document.getElementById('popDownloadBtn').href='quote_download.php?id='+q.id;document.getElementById('popupOverlay').classList.add('open');}
function closePopup(){document.getElementById('popupOverlay').classList.remove('open');}
function confirmDelete(id,num){_delId=id;document.getElementById('delSub').textContent='Delete '+num+'? This cannot be undone.';document.getElementById('delOverlay').classList.add('open');}
function deleteFromPopup(){if(currentQuot)confirmDelete(currentQuot.id,currentQuot.quot_number);closePopup();}
function doDelete(){if(!_delId)return;fetch('quote_action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=delete&id='+_delId}).then(r=>r.json()).then(d=>{document.getElementById('delOverlay').classList.remove('open');if(d.success)location.reload();else showToast('Error: '+d.message,'error');});}
function convertToInvoice(id,num){_convId=id;document.getElementById('convSub').textContent='Convert '+num+' to an invoice?';document.getElementById('convOverlay').classList.add('open');}
function convertFromPopup(){if(currentQuot)convertToInvoice(currentQuot.id,currentQuot.quot_number);closePopup();}
function doConvert(){if(!_convId)return;const btn=document.getElementById('convConfirmBtn');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Converting…';fetch('quote_action.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=convert_to_invoice&id='+_convId}).then(r=>r.json()).then(d=>{document.getElementById('convOverlay').classList.remove('open');btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i> Convert';if(d.success){showToast('✓ Invoice '+d.invoice_number+' created!','success');setTimeout(()=>location.reload(),1500);}else{showToast('Error: '+d.message,'error');}});}
function showToast(msg,type){const t=document.getElementById('toast');t.innerHTML=`<i class="fas fa-${type==='success'?'check-circle':'exclamation-circle'}"></i> ${msg}`;t.className='toast show '+type;setTimeout(()=>t.classList.remove('show'),3000);}
document.addEventListener('keydown',e=>{if(e.key==='Escape')closePopup();});
document.getElementById('popupOverlay').addEventListener('click',function(e){if(e.target===this)closePopup();});
</script>
<script>
function filterByStatus(status) {
    var form = document.getElementById('filterForm');
    var statusInput = form.querySelector('[name="status"]');
    if (statusInput) statusInput.value = status;
    // Reset page to 1
    var pg = form.querySelector('[name="page"]');
    if (pg) pg.value = 1; else { var h=document.createElement('input');h.type='hidden';h.name='page';h.value='1';form.appendChild(h); }
    if (status === 'pending') {
        // Show all pending across all dates
        var periodSel = form.querySelector('select[name="period"]');
        if (periodSel) periodSel.value = 'all';
        var fySel = form.querySelector('select[name="fin_year"]');
        if (fySel) fySel.value = '';
        var ppSel = form.querySelector('[name="per_page"]');
        if (ppSel) ppSel.value = 100;
    }
    form.submit();
}
// Highlight active pending pill
(function(){
    var s = '<?= addslashes($status_filter) ?>';
    if (s === 'pending') {
        var pill = document.getElementById('pendingPill');
        if (pill) { pill.style.background='#fee2e2'; pill.style.boxShadow='0 0 0 2px #dc2626'; }
    }
})();
</script>
<script>
var _ajaxTimer;
function ajaxSearch(q) {
    clearTimeout(_ajaxTimer);
    _ajaxTimer = setTimeout(function() { doAjaxSearch(q); }, 300);
}
function doAjaxSearch(q) {
    var status = document.querySelector('[name="status"]') ? document.querySelector('[name="status"]').value : 'All';
    // When searching, always use 'all' period so results are truly global across all pages/dates
    var period = q.trim() ? 'all' : (document.querySelector('select[name="period"]') ? document.querySelector('select[name="period"]').value : 'this_month');
    var url = 'quote_index.php?status=' + encodeURIComponent(status) +
              '&period=' + encodeURIComponent(period) +
              '&search=' + encodeURIComponent(q) + '&ajax=1';
    fetch(url)
        .then(function(r){ return r.json(); })
        .then(function(data) {
            var tbody = document.querySelector('table tbody');
            if (!tbody) return;
            tbody.innerHTML = data.html;
            var pg = document.querySelector('.pagination');
            if (pg) pg.style.display = q.trim() ? 'none' : '';
            var countEl = document.querySelector('.stat-badge .value');
            if (countEl) countEl.textContent = data.count;
        }).catch(function(){});
}
document.addEventListener('DOMContentLoaded', function() {
    var s = document.getElementById('liveSearch');
    if (s && s.value.trim()) doAjaxSearch(s.value);
});
</script>
</body>
</html>
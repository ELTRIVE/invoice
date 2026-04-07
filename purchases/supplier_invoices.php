<?php
require_once dirname(__DIR__) . '/db.php';
date_default_timezone_set('Asia/Kolkata');

// ── AJAX live search handler ───────────────────────────────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    $period = $_GET['period'] ?? 'all';
    $q      = trim($_GET['search'] ?? '');
    switch($period){
        case 'last_month': $df=date('Y-m-01',strtotime('first day of last month')); $dt=date('Y-m-t',strtotime('last day of last month')); break;
        case 'this_year':  $df=date('Y-01-01'); $dt=date('Y-12-31'); break;
        case 'all':        $df='2000-01-01'; $dt='2099-12-31'; break;
        default:           $df=date('Y-m-01'); $dt=date('Y-m-t');
    }
    $where=['invoice_date BETWEEN :df AND :dt']; $params=[':df'=>$df,':dt'=>$dt];
    if($q!==''){$where[]='(supplier_name LIKE :s OR invoice_number LIKE :s OR contact_person LIKE :s OR supplier_gstin LIKE :s)';$params[':s']="%$q%";}
    $wsql=implode(' AND ',$where);
    $cs=$pdo->prepare("SELECT COUNT(*) FROM purchases WHERE $wsql"); $cs->execute($params); $cnt=(int)$cs->fetchColumn();
    $rs=$pdo->prepare("SELECT * FROM purchases WHERE $wsql ORDER BY created_at DESC LIMIT 200"); $rs->execute($params); $invs=$rs->fetchAll(PDO::FETCH_ASSOC);
    function _si_indFmt($n){$n=number_format((float)$n,2,'.','');$p=explode('.',$n);$i=$p[0];$d=$p[1];$neg='';if(isset($i[0])&&$i[0]==='-'){$neg='-';$i=substr($i,1);}if(strlen($i)<=3)return $neg.$i.'.'.$d;$l3=substr($i,-3);$r=substr($i,0,-3);$r=preg_replace('/\B(?=(\d{2})+(?!\d))/',',',$r);return $neg.$r.','.$l3.'.'.$d;}
    ob_start();
    if(empty($invs)){
        echo '<tr><td colspan="8" style="text-align:center;padding:40px;color:#9ca3af;">No invoices found.</td></tr>';
    } else {
        foreach($invs as $inv){
            $obj=htmlspecialchars(json_encode($inv));
            echo '<tr class="si-row" onclick="openPopup('.$obj.')">';
            echo '<td><strong>'.htmlspecialchars($inv['supplier_name']).'</strong></td>';
            echo '<td style="color:#6b7280;font-size:13px">'.htmlspecialchars($inv['contact_person']).'</td>';
            echo '<td><strong style="color:#f97316">'.htmlspecialchars($inv['invoice_number']).'</strong></td>';
            echo '<td>'.date('d-M-y',strtotime($inv['invoice_date'])).'</td>';
            echo '<td class="col-amount" style="font-weight:600;">&#8377; '._si_indFmt($inv['total_taxable']).'</td>';
            echo '<td class="col-amount" style="font-weight:700;">&#8377; '._si_indFmt($inv['grand_total']).'</td>';
            echo '<td style="font-size:12px;color:#6b7280">'.htmlspecialchars(substr($inv['supplier_gstin'],0,15)).'</td>';
            echo '<td onclick="event.stopPropagation()"><div class="action-btns">';
            echo '<a href="supplier_invoice_create.php?edit='.$inv['id'].'" class="action-btn btn-edit" title="Edit"><i class="fas fa-pencil-alt"></i></a>';
            echo '<a href="supplier_invoice_download.php?id='.$inv['id'].'" class="action-btn btn-pdf" title="Download PDF" target="_blank"><i class="fas fa-file-pdf"></i></a>';
            echo '</div></td></tr>';
        }
    }
    $html=ob_get_clean();
    header('Content-Type: application/json');
    echo json_encode(['html'=>$html,'count'=>$cnt]);
    exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_name VARCHAR(255) NOT NULL DEFAULT '',
    contact_person VARCHAR(255) DEFAULT '',
    supplier_address TEXT DEFAULT '',
    supplier_gstin VARCHAR(100) DEFAULT '',
    supplier_phone VARCHAR(50) DEFAULT '',
    invoice_number VARCHAR(100) NOT NULL DEFAULT '',
    reference VARCHAR(255) DEFAULT '',
    invoice_date DATE NOT NULL,
    due_date DATE NOT NULL,
    voucher_number VARCHAR(100) DEFAULT '',
    voucher_date DATE DEFAULT NULL,
    supplier_ledger VARCHAR(255) DEFAULT '',
    purchase_ledger VARCHAR(255) DEFAULT '',
    credit_month VARCHAR(50) DEFAULT 'None',
    notes TEXT DEFAULT '',
    items_json LONGTEXT DEFAULT NULL,
    terms_json LONGTEXT DEFAULT NULL,
    total_taxable DECIMAL(15,2) DEFAULT 0.00,
    total_cgst DECIMAL(15,2) DEFAULT 0.00,
    total_sgst DECIMAL(15,2) DEFAULT 0.00,
    total_igst DECIMAL(15,2) DEFAULT 0.00,
    grand_total DECIMAL(15,2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

function indFmt($n){
    $n=number_format((float)$n,2,'.','');$p=explode('.',$n);$i=$p[0];$d=$p[1];
    $neg='';if(isset($i[0])&&$i[0]==='-'){$neg='-';$i=substr($i,1);}
    if(strlen($i)<=3)return $neg.$i.'.'.$d;
    $l3=substr($i,-3);$r=substr($i,0,-3);$r=preg_replace('/\B(?=(\d{2})+(?!\d))/',',',$r);
    return $neg.$r.','.$l3.'.'.$d;
}

$period   = $_GET['period']   ?? 'all';
$search   = trim($_GET['search'] ?? '');
$per_page = in_array((int)($_GET['per_page'] ?? 10), [10,25,50,100]) ? (int)($_GET['per_page'] ?? 10) : 10;
$cur_page = max(1,(int)($_GET['page'] ?? 1));

// ── SORT ──
$sort_col = $_GET['sort_col'] ?? '';
$sort_dir = $_GET['sort_dir'] ?? 'asc';
$allowed_sorts = [
    'supplier_name'  => 'supplier_name',
    'contact_person' => 'contact_person',
    'invoice_number' => 'invoice_number',
    'invoice_date'   => 'invoice_date',
    'total_taxable'  => 'total_taxable',
    'grand_total'    => 'grand_total',
];
$order_sql = 'created_at DESC';
if ($sort_col && isset($allowed_sorts[$sort_col])) {
    $sdir = ($sort_dir === 'desc') ? 'DESC' : 'ASC';
    $order_sql = $allowed_sorts[$sort_col] . ' ' . $sdir . ', created_at DESC';
}

switch($period){
    case 'last_month': $df=date('Y-m-01',strtotime('first day of last month')); $dt=date('Y-m-t',strtotime('last day of last month')); break;
    case 'this_year':  $fm=(int)date('n');$fy=(int)date('Y'); $df=($fm>=4)?($fy.'-04-01'):(($fy-1).'-04-01'); $dt=($fm>=4)?(($fy+1).'-03-31'):($fy.'-03-31'); break;
    case 'last_year':  $fm=(int)date('n');$fy=(int)date('Y'); $df=($fm>=4)?(($fy-1).'-04-01'):(($fy-2).'-04-01'); $dt=($fm>=4)?($fy.'-03-31'):(($fy-1).'-03-31'); break;
    case 'all':        $df='2000-01-01'; $dt='2099-12-31'; break;
    default:           $df=date('Y-m-01'); $dt=date('Y-m-t');
}

$where  = ['invoice_date BETWEEN :df AND :dt'];
$params = [':df'=>$df,':dt'=>$dt];
if($search!==''){
    $where[]='(supplier_name LIKE :s OR invoice_number LIKE :s OR contact_person LIKE :s OR supplier_gstin LIKE :s)';
    $params[':s']="%$search%";
}
$wsql = implode(' AND ',$where);

$all = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(grand_total),0), COALESCE(SUM(total_taxable),0) FROM purchases WHERE $wsql");
$all->execute($params); $ar=$all->fetch(PDO::FETCH_NUM);
$count=(int)$ar[0]; $total_amount=(float)$ar[1]; $total_taxable=(float)$ar[2];
$total_pages=max(1,(int)ceil($count/$per_page));
if($cur_page>$total_pages) $cur_page=$total_pages;
$offset=($cur_page-1)*$per_page;

$stmt=$pdo->prepare("SELECT * FROM purchases WHERE $wsql ORDER BY $order_sql LIMIT ".(int)$per_page." OFFSET ".(int)$offset);
$stmt->execute($params);
$invoices=$stmt->fetchAll(PDO::FETCH_ASSOC);

function pageUrl($pg,$pf,$sr,$pp=10,$sc='',$sd='asc'){return '?'.http_build_query(['period'=>$pf,'search'=>$sr,'page'=>$pg,'per_page'=>$pp,'sort_col'=>$sc,'sort_dir'=>$sd]);}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Supplier Invoices</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;background:#f4f6fb;color:#1a1f2e;font-size:15px}
.content{margin-left:220px;padding:32px 28px 28px;padding-top:68px!important}
.header-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
h2{font-weight:700;color:#1a1f2e;font-size:22px}
.filter-bar{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.filter-bar select{padding:7px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;cursor:pointer;outline:none}
.filter-bar select:focus{border-color:#f97316}
/* ── SEARCH BAR ── */
.search-wrap{position:relative;width:230px}
.search-wrap .search-icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);font-size:13px;pointer-events:none;font-style:normal;line-height:1}
.search-wrap input[type=text]{width:100%;padding:7px 28px 7px 34px;border:1.5px solid #d1d5db;border-radius:50px;font-size:12.5px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;outline:none;box-shadow:0 1px 3px rgba(0,0,0,.06);transition:border-color .2s,box-shadow .2s}
.search-wrap input[type=text]:focus{border-color:#93c5fd;box-shadow:0 0 0 3px rgba(147,197,253,.2)}
.search-wrap input[type=text]::placeholder{color:#9ca3af;font-size:12px}
.stat-badges{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.stat-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 16px;border-radius:8px;border:1.5px solid #f97316;background:#fff;font-size:13px;color:#374151;white-space:nowrap}
.stat-badge .label{color:#6b7280}.stat-badge .value{font-weight:700;color:#f97316}
.stat-badge.blue{border-color:#2563eb}.stat-badge.blue .value{color:#2563eb}
.stat-badge.green{border-color:#16a34a}.stat-badge.green .value{color:#16a34a}
.card{background:#fff;border-radius:14px;padding:20px;border:1px solid #e4e8f0}
table{width:100%;border-collapse:collapse}
th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;padding:0 12px 12px 12px;font-weight:700}
td{padding:13px 12px 13px 12px;border-top:1px solid #f1f5f9;font-size:14px;color:#1a1f2e}
.col-amount{text-align:right;width:130px}
.col-actions{text-align:left;width:90px}
.si-row{cursor:pointer;transition:background .15s}
.si-row:hover{background:#fff7f0}
.action-btns{display:flex;gap:5px;align-items:center}
.action-btn{width:30px;height:30px;border-radius:7px;display:inline-flex;align-items:center;justify-content:center;font-size:13px;border:none;cursor:pointer;text-decoration:none;transition:all .2s}
.btn-edit{background:#f4f6fb;color:#6b7280;border:1px solid #e4e8f0}.btn-edit:hover{background:#f97316;color:#fff;border-color:#f97316}
.btn-pdf{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}.btn-pdf:hover{background:#dc2626;color:#fff}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;border-radius:8px;background:#f97316;color:#fff;text-decoration:none;font-size:14px;font-weight:600;border:none;cursor:pointer;font-family:'Times New Roman',Times,serif;transition:background .2s}
.btn:hover{background:#fb923c}
.pagination{display:flex;justify-content:center;align-items:center;gap:5px;padding:16px 0 8px}
.pagination a,.pagination span{display:inline-flex;align-items:center;justify-content:center;min-width:32px;height:32px;padding:0 8px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;border:1.5px solid #e4e8f0;color:#374151;background:#fff;transition:all .15s}
.pagination a:hover{border-color:#f97316;color:#f97316;background:#fff7f0}
.pagination span.active{background:#f97316;color:#fff;border-color:#f97316}
.pagination span.dots{border:none;background:none;color:#9ca3af}
.pagination span.disabled{color:#d1d5db;border-color:#e4e8f0;cursor:default}
.popup-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);backdrop-filter:blur(3px);z-index:2000;align-items:center;justify-content:center}
.popup-overlay.open{display:flex}
.popup-box{background:#fff;border-radius:16px;width:430px;max-width:95vw;box-shadow:0 16px 48px rgba(0,0,0,.14);font-family:'Times New Roman',Times,serif;overflow:hidden}
.popup-header{display:flex;justify-content:space-between;align-items:flex-start;padding:20px 22px 16px;border-bottom:1px solid #e4e8f0;background:#fafbfc}
.popup-invno{font-size:12px;color:#9ca3af;font-weight:600;margin-bottom:4px;text-transform:uppercase;letter-spacing:.7px}
.popup-supplier{font-size:20px;font-weight:800;color:#1a1f2e}
.popup-close{background:none;border:none;font-size:22px;color:#9ca3af;cursor:pointer;width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:50%;transition:all .2s}
.popup-close:hover{background:#fee2e2;color:#dc2626}
.popup-body{padding:18px 24px}
.popup-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:14px}
.popup-row:last-child{border-bottom:none}
.popup-label{color:#6b7280;font-weight:600}
.popup-value{color:#1a1f2e;font-weight:700}
.popup-footer{display:flex;gap:10px;padding:16px 24px;border-top:1px solid #e4e8f0;background:#fafbfc}
.pop-btn{flex:1;padding:9px 0;border-radius:8px;font-size:13px;font-weight:700;border:none;cursor:pointer;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;justify-content:center;gap:5px;text-decoration:none;transition:all .2s}
.toast{position:fixed;bottom:28px;right:28px;background:#1a1f2e;color:#fff;padding:12px 22px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;display:none;gap:8px;align-items:center;box-shadow:0 8px 24px rgba(0,0,0,.2)}
.toast.show{display:flex}.toast.success{border-left:4px solid #16a34a}
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
@media(max-width:900px){.content{margin-left:0!important;padding:70px 12px 20px!important}}
</style>
</head>
<body>
<?php include dirname(__DIR__) . '/sidebar.php'; ?>
<?php include dirname(__DIR__) . '/header.php'; ?>

<div class="content">
    <?php if(isset($_GET['success'])): ?>
    <div class="toast success show" id="successToast" style="display:flex"><i class="fas fa-check-circle"></i> Invoice saved successfully!</div>
    <script>setTimeout(()=>document.getElementById('successToast').classList.remove('show'),3500);</script>
    <?php endif; ?>

    <div class="header-bar">
        <h2>Supplier Invoices</h2>
        <div style="display:flex;align-items:center;gap:10px;">
            <form method="GET" id="searchForm" style="margin:0">
                <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                <div class="search-wrap">
                    <span class="search-icon">🔍</span>
                    <input type="text" name="search" id="liveSearch"
                           placeholder="Search supplier, invoice no..."
                           value="<?= htmlspecialchars($search) ?>"
                           oninput="ajaxSearch(this.value)"
                           autocomplete="off">
                    <button type="submit" style="display:none"></button>
                </div>
            </form>
            <a href="supplier_invoice_create.php" class="btn"><i class="fas fa-plus"></i> Enter Supplier Invoice</a>
        </div>
    </div>

    <form method="GET" id="filterForm">
        <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <input type="hidden" name="per_page" value="<?= $per_page ?>">
        <input type="hidden" name="sort_col" value="<?= htmlspecialchars($sort_col) ?>">
        <input type="hidden" name="sort_dir" value="<?= htmlspecialchars($sort_dir) ?>">
        <div class="filter-bar">
            <select name="period" onchange="document.getElementById('filterForm').submit()">
                <option value="this_month" <?= $period==='this_month'?'selected':'' ?>>This Month</option>
                <option value="last_month" <?= $period==='last_month'?'selected':'' ?>>Last Month</option>
                <option value="this_year"  <?= $period==='this_year'?'selected':'' ?>>This Financial Year</option>
                <option value="last_year"  <?= $period==='last_year'?'selected':'' ?>>Last Financial Year</option>
                <option value="all"        <?= $period==='all'?'selected':'' ?>>All Invoices</option>
            </select>
        </div>
    </form>

    <div class="stat-badges">
        <div class="stat-badge"><span class="label">Count</span><span class="value"><?= $count ?></span></div>
        <div class="stat-badge blue"><span class="label">Pre-Tax</span><span class="value">&#8377; <?= indFmt($total_taxable) ?></span></div>
        <div class="stat-badge green"><span class="label">Total</span><span class="value">&#8377; <?= indFmt($total_amount) ?></span></div>
    </div>

    <div class="show-entries">
        Show
        <select name="per_page" form="filterForm" onchange="document.getElementById('filterForm').submit();">
            <?php foreach([10,25,50,100] as $n): ?>
            <option value="<?=$n?>" <?=$per_page==$n?'selected':''?>><?=$n?></option>
            <?php endforeach; ?>
        </select>
        entries
    </div>

    <div class="card">
        <?php if(empty($invoices)): ?>
        <div style="text-align:center;padding:48px 0;color:#9ca3af">
            <i class="fas fa-file-invoice-dollar" style="font-size:40px;margin-bottom:12px;display:block;opacity:.3"></i>
            <div style="font-size:15px;font-weight:600;margin-bottom:8px">No supplier invoices found</div>
            <a href="supplier_invoice_create.php" style="color:#f97316;font-weight:700;text-decoration:none">+ Enter your first supplier invoice</a>
        </div>
        <?php else: ?>
        <?php
        function siThSort($col, $label, $sort_col, $sort_dir, $get, $extraClass='') {
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
            <thead><tr>
                <?=siThSort('supplier_name', 'Supplier', $sort_col,$sort_dir,$_GET)?>
                <?=siThSort('contact_person','Contact', $sort_col,$sort_dir,$_GET)?>
                <?=siThSort('invoice_number','Invoice No.', $sort_col,$sort_dir,$_GET)?>
                <?=siThSort('invoice_date',  'Invoice Date', $sort_col,$sort_dir,$_GET)?>
                <?=siThSort('total_taxable','Taxable (&#8377;)',$sort_col,$sort_dir,$_GET,'col-amount')?>
                <?=siThSort('grand_total','Amount (&#8377;)',$sort_col,$sort_dir,$_GET,'col-amount')?>
                <th>GSTIN</th>
                <th class="col-actions">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach($invoices as $inv): ?>
            <tr class="si-row" onclick="openPopup(<?= htmlspecialchars(json_encode($inv)) ?>)">
                <td><strong><?= htmlspecialchars($inv['supplier_name']) ?></strong></td>
                <td style="color:#6b7280;font-size:13px"><?= htmlspecialchars($inv['contact_person']) ?></td>
                <td><strong style="color:#f97316"><?= htmlspecialchars($inv['invoice_number']) ?></strong></td>
                <td><?= date('d-M-y',strtotime($inv['invoice_date'])) ?></td>
                <td class="col-amount" style="font-weight:600;">&#8377; <?= indFmt($inv['total_taxable']) ?></td>
                <td class="col-amount" style="font-weight:700;">&#8377; <?= indFmt($inv['grand_total']) ?></td>
                <td style="font-size:12px;color:#6b7280"><?= htmlspecialchars(substr($inv['supplier_gstin'],0,15)) ?></td>
                <td onclick="event.stopPropagation()">
                    <div class="action-btns">
                        <a href="supplier_invoice_create.php?edit=<?= $inv['id'] ?>" class="action-btn btn-edit" title="Edit"><i class="fas fa-pencil-alt"></i></a>
                        <a href="supplier_invoice_download.php?id=<?= $inv['id'] ?>" class="action-btn btn-pdf" title="Download PDF" target="_blank"><i class="fas fa-file-pdf"></i></a>
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
        $prevDisabled = $cur_page <= 1;
        $nextDisabled = $cur_page >= $total_pages;
        echo $prevDisabled ? '<span class="disabled">&laquo;</span>' : '<a href="'.pageUrl($cur_page-1,$period,$search,$per_page,$sort_col,$sort_dir).'">&laquo;</a>';
        $pages = [];
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i <= 3 || $i == $total_pages || abs($i - $cur_page) <= 1) $pages[] = $i;
        }
        $pages = array_unique($pages); sort($pages);
        $prev = null;
        foreach ($pages as $p) {
            if ($prev !== null && $p - $prev > 1) echo '<span class="dots">…</span>';
            if ($p == $cur_page) echo '<span class="active">'.$p.'</span>';
            else echo '<a href="'.pageUrl($p,$period,$search,$per_page,$sort_col,$sort_dir).'">'.$p.'</a>';
            $prev = $p;
        }
        echo $nextDisabled ? '<span class="disabled">&raquo;</span>' : '<a href="'.pageUrl($cur_page+1,$period,$search,$per_page,$sort_col,$sort_dir).'">&raquo;</a>';
        ?>
    </div>
</div>

<div class="popup-overlay" id="popupOverlay">
    <div class="popup-box">
        <div class="popup-header">
            <div>
                <div class="popup-invno" id="popInvNo"></div>
                <div class="popup-supplier" id="popSupplier"></div>
            </div>
            <button class="popup-close" onclick="closePopup()">✕</button>
        </div>
        <div class="popup-body">
            <div class="popup-row"><span class="popup-label">Invoice Date</span><span class="popup-value" id="popDate"></span></div>
            <div class="popup-row"><span class="popup-label">Taxable</span><span class="popup-value" id="popTaxable"></span></div>
            <div class="popup-row"><span class="popup-label">Grand Total</span><span class="popup-value" id="popTotal"></span></div>
            <div class="popup-row"><span class="popup-label">GSTIN</span><span class="popup-value" id="popGstin"></span></div>
        </div>
        <div class="popup-footer">
            <a id="popDownloadBtn" href="#" target="_blank" class="pop-btn" style="background:linear-gradient(135deg,#f97316,#fb923c);color:#fff"><i class="fas fa-file-pdf"></i> Download PDF</a>
            <a id="popEditBtn" href="#" class="pop-btn" style="background:#f4f6fb;color:#374151;border:1px solid #e2e8f0"><i class="fas fa-pencil-alt"></i> Edit</a>
            <button class="pop-btn" style="background:#f1f5f9;color:#374151;border:1px solid #e2e8f0" onclick="closePopup()"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>
<div class="toast" id="toast"></div>
<script>
function openPopup(inv){
    document.getElementById('popInvNo').textContent   = inv.invoice_number;
    document.getElementById('popSupplier').textContent= inv.supplier_name;
    document.getElementById('popDate').textContent    = inv.invoice_date;
    document.getElementById('popTaxable').textContent = '₹ '+parseFloat(inv.total_taxable||0).toLocaleString('en-IN',{minimumFractionDigits:2});
    document.getElementById('popTotal').textContent   = '₹ '+parseFloat(inv.grand_total||0).toLocaleString('en-IN',{minimumFractionDigits:2});
    document.getElementById('popGstin').textContent   = inv.supplier_gstin||'—';
    document.getElementById('popDownloadBtn').href    = 'supplier_invoice_download.php?id='+inv.id;
    document.getElementById('popEditBtn').href        = 'supplier_invoice_create.php?edit='+inv.id;
    document.getElementById('popupOverlay').classList.add('open');
}
function closePopup(){ document.getElementById('popupOverlay').classList.remove('open'); }
document.getElementById('popupOverlay').addEventListener('click',function(e){if(e.target===this)closePopup();});
document.addEventListener('keydown',e=>{if(e.key==='Escape')closePopup();});
</script>
<script>
var _ajaxTimer;
function ajaxSearch(q) {
    clearTimeout(_ajaxTimer);
    _ajaxTimer = setTimeout(function() { doAjaxSearch(q); }, 300);
}
function doAjaxSearch(q) {
    // When searching, always use 'all' period so results are truly global across all pages/dates
    var period = q.trim() ? 'all' : (document.querySelector('[name="period"]') ? document.querySelector('[name="period"]').value : 'this_month');
    var url = 'supplier_invoices.php?period=' + encodeURIComponent(period) +
              '&search=' + encodeURIComponent(q) +
              '&page=1&ajax=1';
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
<?php
require_once 'db.php';

/* ── PERIOD FILTER ── */
$period    = $_GET['period'] ?? 'this_month';
$today     = date('Y-m-d');
$y = date('Y'); $m = date('n');
$finYearStart = ($m>=4) ? "$y-04-01" : (($y-1)."-04-01");
$finYearEnd   = ($m>=4) ? (($y+1)."-03-31") : ("$y-03-31");

switch($period) {
    case 'today':       $from = $to = $today; $periodLabel = 'Today'; break;
    case 'this_week':   $from = date('Y-m-d', strtotime('monday this week')); $to = date('Y-m-d', strtotime('sunday this week')); $periodLabel = 'This Week'; break;
    case 'this_month':  $from = date('Y-m-01'); $to = date('Y-m-t'); $periodLabel = date('F Y'); break;
    case 'last_month':  $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('last day of last month')); $periodLabel = date('F Y', strtotime('last month')); break;
    case 'this_quarter':
        $qS = [1=>1,2=>1,3=>1,4=>4,5=>4,6=>4,7=>7,8=>7,9=>7,10=>10,11=>10,12=>10][$m];
        $from = date("Y-$qS-01"); $to = date('Y-m-t', strtotime(date("Y-".($qS+2)."-01")));
        $periodLabel = 'This Quarter'; break;
    case 'this_year':   $from = $finYearStart; $to = $finYearEnd; $periodLabel = 'This Financial Year'; break;
    case 'last_year':   $from = ($m>=4)?(($y-1).'-04-01'):(($y-2).'-04-01'); $to = ($m>=4)?("$y-03-31"):(($y-1).'-03-31'); $periodLabel = 'Last Financial Year'; break;
    default:            $from = date('Y-m-01'); $to = date('Y-m-t'); $periodLabel = date('F Y'); $period = 'this_month';
}

/* ── COUNTS ── */
$totalInvoices  = $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
$totalCustomers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$thisMonth      = date('Y-m');
$lastMonth      = date('Y-m', strtotime('last month'));
$finYearStartOld = (date('m') >= 4) ? date('Y').'-04-01' : (date('Y')-1).'-04-01';
$invoicesThisMonth = $pdo->query("SELECT COUNT(*) FROM invoices WHERE DATE_FORMAT(invoice_date,'%Y-%m')='$thisMonth'")->fetchColumn();
$invoicesLastMonth = $pdo->query("SELECT COUNT(*) FROM invoices WHERE DATE_FORMAT(invoice_date,'%Y-%m')='$lastMonth'")->fetchColumn();

/* ── FILTERED PAYMENT STATUS ── */
$paidCount    = $pdo->query("SELECT COUNT(*) FROM invoices WHERE payment_status='Paid' AND invoice_date BETWEEN '$from' AND '$to'")->fetchColumn();
$partialCount = $pdo->query("SELECT COUNT(*) FROM invoices WHERE payment_status='Partial' AND invoice_date BETWEEN '$from' AND '$to'")->fetchColumn();
$unpaidCount  = $pdo->query("SELECT COUNT(*) FROM invoices WHERE payment_status='Unpaid' AND invoice_date BETWEEN '$from' AND '$to'")->fetchColumn();
$totalPending  = floatval($pdo->query("SELECT COALESCE(SUM(amount_pending),0) FROM invoices WHERE invoice_date BETWEEN '$from' AND '$to'")->fetchColumn());
$totalReceived = floatval($pdo->query("SELECT COALESCE(SUM(amount_received),0) FROM invoices WHERE invoice_date BETWEEN '$from' AND '$to'")->fetchColumn());

$paidAmt     = floatval($pdo->query("SELECT COALESCE(SUM(amount_received),0) FROM invoices WHERE payment_status='Paid' AND invoice_date BETWEEN '$from' AND '$to'")->fetchColumn());
$partialRecv = floatval($pdo->query("SELECT COALESCE(SUM(amount_received),0) FROM invoices WHERE payment_status='Partial' AND invoice_date BETWEEN '$from' AND '$to'")->fetchColumn());
$unpaidAmt   = floatval($pdo->query("SELECT COALESCE(SUM(amount_pending),0)  FROM invoices WHERE payment_status='Unpaid' AND invoice_date BETWEEN '$from' AND '$to'")->fetchColumn());

/* ── INVOICE DETAIL DATA FOR PIE MODAL ── */
$modalInvoices = ['Paid'=>[], 'Partial'=>[], 'Unpaid'=>[]];
$modalRows = $pdo->query("
    SELECT id, invoice_number, invoice_date, payment_status,
           amount_received, amount_pending
    FROM invoices
    WHERE invoice_date BETWEEN '$from' AND '$to'
    ORDER BY invoice_date DESC
")->fetchAll(PDO::FETCH_ASSOC);
foreach($modalRows as $r){
    $st = $r['payment_status'];
    if(!isset($modalInvoices[$st])) continue;
    $modalInvoices[$st][] = [
        'inv_no'   => $r['invoice_number'] ?? ('#'.$r['id']),
        'date'     => $r['invoice_date'],
        'received' => floatval($r['amount_received']),
        'pending'  => floatval($r['amount_pending']),
    ];
}

/* ── AMOUNTS ── */
$yesterday = date('Y-m-d', strtotime('yesterday'));
$annualAmount=$thisMonthAmount=$lastMonthAmount=$yesterdayAmount=$todayAmount=$filteredAmount=0;

$iaCount=0;
try { $iaCount=$pdo->query("SELECT COUNT(*) FROM invoice_amounts")->fetchColumn(); } catch(Exception $e){}

if($iaCount>0){
    $amtRows=$pdo->query("
        SELECT i.invoice_date, SUM(ia.total) AS total
        FROM invoice_amounts ia
        JOIN invoices i ON i.id = ia.invoice_id
        WHERE (ia.service_code != 'PAYMENT' OR ia.service_code IS NULL)
        GROUP BY ia.invoice_id, i.invoice_date
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach($amtRows as $row){
        $date=($row['invoice_date']??''); $amount=floatval($row['total']);
        if($date>=$finYearStartOld)        $annualAmount    +=$amount;
        if(substr($date,0,7)===$thisMonth) $thisMonthAmount +=$amount;
        if(substr($date,0,7)===$lastMonth) $lastMonthAmount +=$amount;
        if($date===$yesterday)             $yesterdayAmount +=$amount;
        if($date===$today)                 $todayAmount     +=$amount;
        if($date>=$from && $date<=$to)     $filteredAmount  +=$amount;
    }
} else {
    $allInvoices=$pdo->query("SELECT id,invoice_date,item_list FROM invoices")->fetchAll(PDO::FETCH_ASSOC);
    $allItems   =$pdo->query("SELECT id,total FROM items")->fetchAll(PDO::FETCH_ASSOC);
    $itemTotals =[];
    foreach($allItems as $it) $itemTotals[(int)$it['id']]=floatval($it['total']);
    foreach($allInvoices as $inv){
        $date=($inv['invoice_date']??'');
        $ids=json_decode($inv['item_list']??'[]',true);
        $amount=0; foreach((array)$ids as $id) $amount+=$itemTotals[(int)$id]??0;
        if($date>=$finYearStartOld)        $annualAmount    +=$amount;
        if(substr($date,0,7)===$thisMonth) $thisMonthAmount +=$amount;
        if(substr($date,0,7)===$lastMonth) $lastMonthAmount +=$amount;
        if($date===$yesterday)             $yesterdayAmount +=$amount;
        if($date===$today)                 $todayAmount     +=$amount;
        if($date>=$from && $date<=$to)     $filteredAmount  +=$amount;
    }
}

/* ── MONTHLY TREND DATA (last 6 months) ── */
$monthlyData = [];
for($i=5; $i>=0; $i--) {
    $mStart = date('Y-m-01', strtotime("-$i months"));
    $mEnd   = date('Y-m-t',  strtotime("-$i months"));
    $mLabel = date('M Y',    strtotime("-$i months"));
    $mPaid    = floatval($pdo->query("SELECT COALESCE(SUM(amount_received),0) FROM invoices WHERE payment_status='Paid' AND invoice_date BETWEEN '$mStart' AND '$mEnd'")->fetchColumn());
    $mPartial = floatval($pdo->query("SELECT COALESCE(SUM(amount_received),0) FROM invoices WHERE payment_status='Partial' AND invoice_date BETWEEN '$mStart' AND '$mEnd'")->fetchColumn());
    $mUnpaid  = floatval($pdo->query("SELECT COALESCE(SUM(amount_pending),0) FROM invoices WHERE payment_status='Unpaid' AND invoice_date BETWEEN '$mStart' AND '$mEnd'")->fetchColumn());
    $mCount   = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_date BETWEEN '$mStart' AND '$mEnd'")->fetchColumn();
    $monthlyData[] = ['label'=>$mLabel, 'paid'=>$mPaid, 'partial'=>$mPartial, 'unpaid'=>$mUnpaid, 'count'=>$mCount];
}

function indianFormat($n,$decimals=2){
    $n=round($n,$decimals);$neg=$n<0?'-':'';$n=abs($n);
    $dec=($decimals>0)?('.'.str_pad(round(($n-floor($n))*pow(10,$decimals)),$decimals,'0',STR_PAD_LEFT)):'';
    $int=(string)floor($n);
    if(strlen($int)<=3) return $neg.$int.$dec;
    $last3=substr($int,-3);$rest=substr($int,0,-3);
    $rest=preg_replace('/\B(?=(\d{2})+(?!\d))/',',',$rest);
    return $neg.$rest.','.$last3.$dec;
}
function formatINR($n){return '&#8377;'.indianFormat($n);}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<title>Dashboard</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;overflow:hidden}
body{font-family:'Times New Roman',Times,serif;background:#f0f2f8;color:#1a1f2e}

.content{margin-left:220px;padding:56px 16px 6px !important;background:#f0f2f8;height:calc(100vh - 52px);overflow:hidden;display:flex;flex-direction:column;}

/* ── FILTER BAR ── */
.filter-wrap{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:6px}
.filter-bar{display:flex;align-items:center;gap:3px;background:#fff;padding:3px 6px;border-radius:30px;box-shadow:0 1px 6px rgba(0,0,0,.07);border:1px solid #e4e8f0}
.filter-bar a{
    padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;
    text-decoration:none;color:#6b7280;transition:all .2s;white-space:nowrap;letter-spacing:.3px;
}
.filter-bar a:hover{color:#f97316;background:#fff7f0}
.filter-bar a.active{background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;box-shadow:0 3px 10px rgba(249,115,22,.35)}
.period-badge{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:1.2px}

/* ── STAT CARDS ── */
.top-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:6px}
.ts{
    background:#fff;border:1px solid #e8ecf4;border-radius:12px;
    padding:10px 12px;position:relative;overflow:hidden;
    box-shadow:0 2px 8px rgba(0,0,0,.05);transition:transform .2s,box-shadow .2s;
    cursor:pointer;text-decoration:none;color:inherit;display:block;}
.ts:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,.1)}
.ts::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:12px 12px 0 0}
.ts.orange::before{background:linear-gradient(90deg,#f97316,#fb923c)}
.ts.green::before {background:linear-gradient(90deg,#16a34a,#4ade80)}
.ts.blue::before  {background:linear-gradient(90deg,#2563eb,#60a5fa)}
.ts.purple::before{background:linear-gradient(90deg,#7c3aed,#a78bfa)}
.ts-icon-row{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px}
.ts-icon{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff}
.ts-icon.orange{background:linear-gradient(135deg,#f97316,#fb923c)}
.ts-icon.green {background:linear-gradient(135deg,#16a34a,#22c55e)}
.ts-icon.blue  {background:linear-gradient(135deg,#2563eb,#3b82f6)}
.ts-icon.purple{background:linear-gradient(135deg,#7c3aed,#8b5cf6)}
.ts-label{font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:3px}
.ts-value{font-size:14px;font-weight:800;color:#1a1f2e;line-height:1;margin-bottom:3px}
.ts-sub{font-size:10px;color:#9ca3af;display:flex;align-items:center;gap:4px;flex-wrap:wrap}
.badge-trend{display:inline-flex;align-items:center;gap:3px;font-size:9px;font-weight:700;padding:1px 5px;border-radius:20px}
.badge-trend.up  {background:rgba(22,163,74,.12);color:#16a34a}
.badge-trend.down{background:rgba(220,38,38,.12);color:#dc2626}

/* ── CHARTS GRID ── */
.sec-label{
    font-size:9px;font-weight:800;color:#9ca3af;text-transform:uppercase;
    letter-spacing:2px;margin-bottom:6px;display:flex;align-items:center;gap:8px;}
.sec-label::after{content:'';flex:1;height:1px;background:#e4e8f0}

.rev-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.rev-card{
    background:#fff;border:1px solid #e8ecf4;border-radius:12px;
    padding:12px 14px;box-shadow:0 2px 8px rgba(0,0,0,.05);}
.card-title{font-size:12px;font-weight:800;color:#1a1f2e;margin-bottom:2px;display:flex;align-items:center;gap:6px}
.card-title i{color:#f97316}
.card-subtitle{font-size:10px;color:#9ca3af;margin-bottom:8px}

/* Pie legend */
.pie-legend-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid #f4f6fb}
.pie-legend-row:last-of-type{border-bottom:none}
.pie-leg-left{display:flex;align-items:center;gap:9px}
.pie-leg-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0}
.pie-leg-label{font-size:11px;font-weight:700;color:#1a1f2e}
.pie-leg-badge{font-size:11px;font-weight:600;padding:2px 8px;border-radius:10px;margin-left:4px}
.pie-leg-amt{font-size:11px;font-weight:800;color:#1a1f2e}
.pie-total-row{margin-top:10px;padding-top:10px;border-top:2px solid #f0f2f8;display:flex;justify-content:space-between;align-items:center}
.pie-total-label{font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:1px;font-weight:700}
.pie-total-val{font-size:12px;font-weight:800;color:#1a1f2e}

@media(max-width:900px){.top-stats{grid-template-columns:repeat(2,1fr)}.rev-grid{grid-template-columns:1fr}}
@media(max-width:600px){.top-stats{grid-template-columns:1fr}.content{margin-left:0;padding:56px 12px 6px !important;height:calc(100vh - 52px)}}
@media (max-height:820px){
    .filter-wrap{margin-bottom:4px;gap:4px}
    .filter-bar{padding:2px 5px}
    .filter-bar a{padding:3px 8px;font-size:10px}
    .period-badge{font-size:9px}
    .sec-label{font-size:8px;letter-spacing:1.4px;margin-bottom:4px}
    .top-stats{gap:6px;margin-bottom:4px}
    .ts{padding:8px 10px}
    .ts-label{font-size:9px}
    .ts-value{font-size:13px}
    .ts-sub{font-size:9px}
    .rev-grid{gap:8px}
    .rev-card{padding:10px 12px}
}
::-webkit-scrollbar{width:4px}::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:99px}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<?php include 'header.php'; ?>

<div class="content">

    <!-- FILTER BAR -->
    <div class="filter-wrap">
        <div class="filter-bar">
            <?php
            $periods = ['today'=>'Today','this_week'=>'This Week','this_month'=>'This Month','last_month'=>'Last Month','this_quarter'=>'This Quarter','this_year'=>'This Year','last_year'=>'Last Fin. Year'];
            foreach($periods as $val=>$lbl): ?>
            <a href="?period=<?=$val?>" class="<?=$period===$val?'active':''?>"><?=$lbl?></a>
            <?php endforeach; ?>
        </div>
        <span class="period-badge"><i class="fas fa-calendar-alt" style="color:#f97316;margin-right:5px"></i><?=$periodLabel?></span>
    </div>

    <!-- STAT CARDS -->
    <div class="sec-label" style="margin-bottom:4px">Overview</div>
    <?php
    // Period-aware stat card values — all driven by $from / $to
    $cardRevenue  = $filteredAmount;
    $cardPaid     = floatval($pdo->query("SELECT COALESCE(SUM(amount_received),0) FROM invoices WHERE payment_status='Paid' AND invoice_date BETWEEN '$from' AND '$to'")->fetchColumn());
    $cardPending  = floatval($pdo->query("SELECT COALESCE(SUM(amount_pending),0)  FROM invoices WHERE invoice_date BETWEEN '$from' AND '$to'")->fetchColumn());
    $cardCount    = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_date BETWEEN '$from' AND '$to'")->fetchColumn();
    ?>
    <div class="top-stats">

        <a href="index.php?view=invoices&period=<?=$period?>" class="ts orange">
            <div class="ts-label">Revenue</div>
            <div class="ts-value"><?=formatINR($cardRevenue)?></div>
            <div class="ts-sub"><?=htmlspecialchars($periodLabel)?></div>
        </a>

        <a href="index.php?view=invoices&period=<?=$period?>&status=paid" class="ts green">
            <div class="ts-label">Collected</div>
            <div class="ts-value"><?=formatINR($cardPaid)?></div>
            <div class="ts-sub">Paid invoices &nbsp;·&nbsp; <?=$paidCount?> inv</div>
        </a>

        <a href="index.php?view=invoices&period=<?=$period?>&status=unpaid" class="ts blue">
            <div class="ts-label">Pending</div>
            <div class="ts-value"><?=formatINR($cardPending)?></div>
            <div class="ts-sub">Unpaid + Partial &nbsp;·&nbsp; <?=$unpaidCount+$partialCount?> inv</div>
        </a>

        <a href="index.php?view=invoices&period=<?=$period?>" class="ts purple">
            <div class="ts-label">Total Invoices</div>
            <div class="ts-value"><?=$cardCount?></div>
            <div class="ts-sub"><?=htmlspecialchars($periodLabel)?> &nbsp;·&nbsp; <?=$totalCustomers?> customers</div>
        </a>

    </div>

    <!-- CHARTS -->
    <div class="sec-label" style="margin-bottom:4px">Revenue &amp; Payment Status — <span style="color:#f97316;font-style:italic;text-transform:none;letter-spacing:0"><?=$periodLabel?></span></div>
    <div class="rev-grid">

        <!-- BAR CHART: highlights selected period bar -->
        <div class="rev-card">
            <div class="card-title"><i class="fas fa-chart-bar"></i> Business Revenue</div>
            <div class="card-subtitle">Selected period bar is highlighted — others are dimmed</div>
            <?php
            $finLabel = 'FY ('.date('Y').'-'.date('y',strtotime('+1 year')).')';
            $barLabels = [$finLabel,     'Last Month',                         'This Month', 'Yesterday',                        'Today'      ];
            $barSubs   = ['Apr–Mar',     date('M',strtotime('last month')),    date('M Y'),  date('d M',strtotime('yesterday')), date('d M')  ];
            $barData   = [(float)$annualAmount,(float)$lastMonthAmount,(float)$thisMonthAmount,(float)$yesterdayAmount,(float)$todayAmount];
            $barKeys   = ['this_year','last_month','this_month','today','today'];
            // active index: which bar matches current period
            $activeIdx = 0;
            foreach($barKeys as $bi=>$bk){ if($bk===$period){ $activeIdx=$bi; break; } }
            // for today, highlight index 4
            if($period==='today') $activeIdx=4;
            ?>
            <div style="position:relative;height:108px;width:100%;">
                <canvas id="revBarChart"></canvas>
            </div>
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
            <script>
            (function(){
                const labels   = <?=json_encode($barLabels)?>;
                const subs     = <?=json_encode($barSubs)?>;
                const data     = <?=json_encode($barData)?>;
                const activeIdx= <?=$activeIdx?>;
                const fullColors  = ['#f97316','#3b82f6','#16a34a','#94a3b8','#f97316'];
                const dimColors   = ['rgba(249,115,22,.18)','rgba(59,130,246,.18)','rgba(22,163,74,.18)','rgba(148,163,184,.18)','rgba(249,115,22,.18)'];
                const hoverColors = ['#ea6a00','#2563eb','#15803d','#64748b','#ea6a00'];

                const bgColors    = data.map((_,i) => i===activeIdx ? fullColors[i]  : dimColors[i]);
                const hovColors   = data.map((_,i) => i===activeIdx ? hoverColors[i] : dimColors[i]);

                const ctx = document.getElementById('revBarChart').getContext('2d');
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels.map((l,i)=>[l,subs[i]]),
                        datasets:[{
                            data: data,
                            backgroundColor: bgColors,
                            hoverBackgroundColor: hovColors,
                            borderRadius: 8,
                            borderSkipped: false,
                            barPercentage: 0.58,
                        }]
                    },
                    options:{
                        responsive:true, maintainAspectRatio:false,
                        plugins:{
                            legend:{display:false},
                            tooltip:{
                                backgroundColor:'#1a1f2e',
                                titleFont:{family:'Times New Roman',size:13},
                                bodyFont:{family:'Times New Roman',size:12},
                                padding:10,
                                callbacks:{
                                    title: items => labels[items[0].dataIndex]+' ('+subs[items[0].dataIndex]+')',
                                    label: c => '  ₹ '+c.raw.toLocaleString('en-IN',{maximumFractionDigits:0})
                                }
                            }
                        },
                        scales:{
                            x:{grid:{display:false}, ticks:{font:{family:'Times New Roman',size:10},color:'#374151'}},
                            y:{
                                grid:{color:'#f1f5f9'}, border:{dash:[4,4]},
                                ticks:{font:{family:'Times New Roman',size:10},color:'#9ca3af',
                                    callback:v=>v>=10000000?'₹'+(v/10000000).toFixed(1)+'Cr':v>=100000?'₹'+(v/100000).toFixed(1)+'L':v>=1000?'₹'+(v/1000).toFixed(0)+'K':'₹'+v
                                }
                            }
                        }
                    }
                });
            })();
            </script>
        </div>

        <!-- PIE CHART: filtered by selected period -->
        <div class="rev-card">
            <div class="card-title"><i class="fas fa-chart-pie"></i> Payment Status</div>
            <div class="card-subtitle">Showing: <?=htmlspecialchars($periodLabel)?></div>
            <?php $grandInvTotal = max($paidAmt + $partialRecv + $unpaidAmt, 1); ?>
            <div style="display:flex;align-items:center;gap:20px;padding:4px 0 10px;">
                <div style="position:relative;width:110px;height:110px;flex-shrink:0;">
                    <canvas id="payPieChart" width="110" height="110"></canvas>
                </div>
                <div style="flex:1;">
                    <div class="pie-legend-row" onclick="openPieModal('Paid')" style="cursor:pointer">
                        <div class="pie-leg-left">
                            <div class="pie-leg-dot" style="background:#16a34a"></div>
                            <span class="pie-leg-label">Paid</span>
                            <span class="pie-leg-badge" style="background:#dcfce7;color:#16a34a"><?=$paidCount?> inv</span>
                        </div>
                        <span class="pie-leg-amt">&#8377;&nbsp;<?=indianFormat($paidAmt,0)?></span>
                    </div>
                    <div class="pie-legend-row" onclick="openPieModal('Partial')" style="cursor:pointer">
                        <div class="pie-leg-left">
                            <div class="pie-leg-dot" style="background:#f97316"></div>
                            <span class="pie-leg-label">Partial</span>
                            <span class="pie-leg-badge" style="background:#fff7ed;color:#f97316"><?=$partialCount?> inv</span>
                        </div>
                        <span class="pie-leg-amt">&#8377;&nbsp;<?=indianFormat($partialRecv,0)?></span>
                    </div>
                    <div class="pie-legend-row" onclick="openPieModal('Unpaid')" style="cursor:pointer">
                        <div class="pie-leg-left">
                            <div class="pie-leg-dot" style="background:#dc2626"></div>
                            <span class="pie-leg-label">Unpaid</span>
                            <span class="pie-leg-badge" style="background:#fef2f2;color:#dc2626"><?=$unpaidCount?> inv</span>
                        </div>
                        <span class="pie-leg-amt">&#8377;&nbsp;<?=indianFormat($unpaidAmt,0)?></span>
                    </div>
                    <div class="pie-total-row">
                        <span class="pie-total-label">Total</span>
                        <span class="pie-total-val">&#8377;&nbsp;<?=indianFormat($grandInvTotal,0)?></span>
                    </div>
                </div>
            </div>
            <script>
            (function(){
                const paid=<?=(float)$paidAmt?>, partial=<?=(float)$partialRecv?>, unpaid=<?=(float)$unpaidAmt?>;
                const counts=[<?=(int)$paidCount?>,<?=(int)$partialCount?>,<?=(int)$unpaidCount?>];
                const total=paid+partial+unpaid||1;
                const data=[paid,partial,unpaid];
                const colors=['#16a34a','#f97316','#dc2626'];
                const darker=['#15803d','#ea6a00','#b91c1c'];
                const labels=['Paid','Partial','Unpaid'];
                const cx=55,cy=55,R=50,ir=28;
                let startAngle=-Math.PI/2;
                const slices=data.map((v,i)=>{
                    const a=(v/total)*2*Math.PI;
                    const s={sa:startAngle,ea:startAngle+a,color:colors[i],dark:darker[i],val:v};
                    startAngle+=a; return s;
                });
                const canvas=document.getElementById('payPieChart');
                const ctx=canvas.getContext('2d');

                /* ── tooltip element ── */
                const tip=document.createElement('div');
                tip.style.cssText='position:fixed;display:none;background:#1a1f2e;color:#fff;border-radius:10px;padding:10px 14px;font-family:"Times New Roman",serif;pointer-events:none;z-index:9999;box-shadow:0 8px 28px rgba(0,0,0,.35);min-width:160px';
                document.body.appendChild(tip);

                function inr(n){
                    n=Math.round(n);if(!n)return '0';
                    let s=String(n),l=s.slice(-3),r=s.slice(0,-3);
                    if(r)r=r.replace(/\B(?=(\d{2})+(?!\d))/g,',');
                    return (r?r+',':'')+l;
                }

                function showTip(i, clientX, clientY){
                    const pct=Math.round((slices[i].val/total)*100);
                    tip.innerHTML=
                        '<div style="font-size:13px;font-weight:800;margin-bottom:6px">'+labels[i]+' ('+pct+'%)</div>'+
                        '<div style="display:flex;align-items:center;gap:8px;font-size:13px">'+
                        '<span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:'+colors[i]+';flex-shrink:0"></span>'+
                        '&#8377; '+inr(slices[i].val)+'</div>'+
                        '<div style="font-size:11px;color:#9ca3af;margin-top:5px">'+counts[i]+' invoice'+(counts[i]!==1?'s':'')+'</div>';
                    /* position near click, keep inside viewport */
                    const tw=180, th=80;
                    let lx=clientX+14, ly=clientY-20;
                    if(lx+tw>window.innerWidth)  lx=clientX-tw-14;
                    if(ly+th>window.innerHeight) ly=clientY-th-10;
                    tip.style.left=lx+'px';
                    tip.style.top=ly+'px';
                    tip.style.display='block';
                }
                function hideTip(){ tip.style.display='none'; }

                function getSliceAt(e){
                    const rect=canvas.getBoundingClientRect();
                    const mx=e.clientX-rect.left-cx, my=e.clientY-rect.top-cy;
                    const dist=Math.sqrt(mx*mx+my*my);
                    if(dist<ir||dist>R+8) return -1;
                    let a=Math.atan2(my,mx), hov=-1;
                    slices.forEach((s,i)=>{
                        let sa=s.sa,ea=s.ea,aa=a;
                        if(aa<sa) aa+=2*Math.PI;
                        if(aa>=sa&&aa<ea) hov=i;
                    });
                    return hov;
                }
                function draw(hov){
                    ctx.clearRect(0,0,150,150);
                    slices.forEach((s,i)=>{
                        const off=i===hov?6:0;
                        const mid=(s.sa+s.ea)/2;
                        const ox=Math.cos(mid)*off,oy=Math.sin(mid)*off;
                        ctx.beginPath();ctx.moveTo(cx+ox,cy+oy);
                        ctx.arc(cx+ox,cy+oy,i===hov?R+5:R,s.sa,s.ea);
                        ctx.closePath();
                        ctx.fillStyle=i===hov?s.dark:s.color;ctx.fill();
                    });
                    ctx.beginPath();ctx.arc(cx,cy,ir,0,2*Math.PI);
                    ctx.fillStyle='#fff';ctx.fill();
                    const pct=Math.round((slices[0].val/total)*100);
                    ctx.fillStyle='#1a1f2e';ctx.font='bold 16px Times New Roman';
                    ctx.textAlign='center';ctx.textBaseline='middle';
                    ctx.fillText(pct+'%',cx,cy-7);
                    ctx.font='10px Times New Roman';ctx.fillStyle='#9ca3af';
                    ctx.fillText('Paid',cx,cy+9);
                }
                draw(-1);

                let activeTip=-1;
                canvas.addEventListener('mousemove',function(e){
                    const h=getSliceAt(e);
                    canvas.style.cursor=h>=0?'pointer':'default';
                    draw(h);
                    if(h>=0) showTip(h,e.clientX,e.clientY);
                    else hideTip();
                });
                canvas.addEventListener('mouseleave',()=>{
                    canvas.style.cursor='default';
                    draw(-1);
                    hideTip();
                });
            })();
            </script>
        </div>

    </div><!-- end rev-grid -->



    <!-- ── CASH FLOW + GOALS GRID ── -->
    <div class="sec-label" style="margin-top:6px">Cash Flow — Last 6 Months</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px;align-items:stretch">
    <div class="rev-card">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:6px;flex-wrap:wrap;gap:6px">
            <div>
                <div class="card-title"><i class="fas fa-water"></i> Revenue Cash Flow</div>
                <div class="card-subtitle">Invoiced → Collected → Outstanding flow per month</div>
            </div>
            <div style="display:flex;gap:18px;align-items:center">
                <span style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#6b7280"><span style="width:28px;height:3px;background:linear-gradient(90deg,#f97316,#fb923c);border-radius:2px;display:inline-block"></span>Invoiced</span>
                <span style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#6b7280"><span style="width:28px;height:3px;background:linear-gradient(90deg,#16a34a,#4ade80);border-radius:2px;display:inline-block"></span>Collected</span>
                <span style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;color:#6b7280"><span style="width:28px;height:3px;background:linear-gradient(90deg,#dc2626,#f87171);border-radius:2px;display:inline-block;opacity:.7"></span>Outstanding</span>
            </div>
        </div>
        <div style="position:relative;height:130px;width:100%;">
            <canvas id="cashFlowChart"></canvas>
        </div>
        <?php
        $cfLabels   = array_column($monthlyData,'label');
        $cfInvoiced = array_map(fn($r)=>round($r['paid']+$r['partial']+$r['unpaid']), $monthlyData);
        $cfCollect  = array_map(fn($r)=>round($r['paid']+$r['partial']),              $monthlyData);
        $cfPending  = array_map(fn($r)=>round($r['unpaid']),                          $monthlyData);
        $cfCounts   = array_column($monthlyData,'count');
        ?>
        <script>
        (function(){
            const labels   = <?=json_encode($cfLabels)?>;
            const invoiced = <?=json_encode($cfInvoiced)?>;
            const collected= <?=json_encode($cfCollect)?>;
            const pending  = <?=json_encode($cfPending)?>;
            const counts   = <?=json_encode($cfCounts)?>;
            const ctx = document.getElementById('cashFlowChart').getContext('2d');

            // Gradient fills
            const gOrange = ctx.createLinearGradient(0,0,0,240);
            gOrange.addColorStop(0,'rgba(249,115,22,.22)'); gOrange.addColorStop(1,'rgba(249,115,22,0)');
            const gGreen = ctx.createLinearGradient(0,0,0,240);
            gGreen.addColorStop(0,'rgba(22,163,74,.28)');  gGreen.addColorStop(1,'rgba(22,163,74,0)');
            const gRed = ctx.createLinearGradient(0,0,0,240);
            gRed.addColorStop(0,'rgba(220,38,38,.18)');    gRed.addColorStop(1,'rgba(220,38,38,0)');

            function inr(v){ v=Math.round(v); if(!v) return '0';
                let s=String(v),l=s.slice(-3),r=s.slice(0,-3);
                if(r) r=r.replace(/\B(?=(\d{2})+(?!\d))/g,',');
                return '₹'+(r?r+',':'')+l; }

            new Chart(ctx,{
                type:'line',
                data:{labels, datasets:[
                    {label:'Invoiced',  data:invoiced,  borderColor:'#f97316', backgroundColor:gOrange, fill:true, tension:.45, pointRadius:6, pointBackgroundColor:'#fff', pointBorderColor:'#f97316', pointBorderWidth:2.5, pointHoverRadius:8, borderWidth:2.5, order:3},
                    {label:'Collected', data:collected, borderColor:'#16a34a', backgroundColor:gGreen,  fill:true, tension:.45, pointRadius:6, pointBackgroundColor:'#fff', pointBorderColor:'#16a34a', pointBorderWidth:2.5, pointHoverRadius:8, borderWidth:2.5, order:2},
                    {label:'Outstanding',data:pending,  borderColor:'#dc2626', backgroundColor:gRed,    fill:true, tension:.45, pointRadius:6, pointBackgroundColor:'#fff', pointBorderColor:'#dc2626', pointBorderWidth:2.5, pointHoverRadius:8, borderWidth:2.5, order:1, borderDash:[6,3]}
                ]},
                options:{
                    responsive:true, maintainAspectRatio:false,
                    interaction:{mode:'index', intersect:false},
                    plugins:{
                        legend:{display:false},
                        tooltip:{
                            backgroundColor:'#1a1f2e',
                            titleFont:{family:'Times New Roman',size:13,weight:'bold'},
                            bodyFont:{family:'Times New Roman',size:12},
                            padding:14, cornerRadius:12,
                            callbacks:{
                                title: i => i[0].label,
                                beforeBody: i => '  ' + counts[i[0].dataIndex] + ' invoices this month',
                                label: c => {
                                    const icons = ['  ▶ Invoiced ','  ✓ Collected','  ⧗ Outstanding'];
                                    return icons[c.datasetIndex] + ' : ' + inr(c.raw);
                                },
                                afterBody: i => {
                                    const inv = invoiced[i[0].dataIndex];
                                    const col = collected[i[0].dataIndex];
                                    const pct = inv > 0 ? Math.round(col/inv*100) : 0;
                                    return ['', '  Collection Rate: ' + pct + '%'];
                                }
                            }
                        }
                    },
                    scales:{
                        x:{
                            grid:{display:false},
                            ticks:{font:{family:'Times New Roman',size:11}, color:'#374151'}
                        },
                        y:{
                            grid:{color:'#f1f5f9'}, border:{dash:[5,5]},
                            ticks:{font:{family:'Times New Roman',size:10}, color:'#9ca3af',
                                callback: v => v>=10000000?'₹'+(v/10000000).toFixed(1)+'Cr':v>=100000?'₹'+(v/100000).toFixed(1)+'L':v>=1000?'₹'+(v/1000).toFixed(0)+'K':'₹'+v
                            }
                        }
                    },
                    animation:{duration:900, easing:'easeInOutQuart'}
                }
            });
        })();
        </script>

        <!-- Bottom stat strip -->
        <?php
        $totalInv6 = array_sum($cfInvoiced);
        $totalCol6 = array_sum($cfCollect);
        $totalPen6 = array_sum($cfPending);
        $rate6     = $totalInv6 > 0 ? round($totalCol6/$totalInv6*100) : 0;
        $bestMonth = $cfLabels[array_search(max($cfCollect),$cfCollect)];
        ?>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:0;margin-top:8px;border-top:1px solid #f0f2f8;padding-top:8px">
            <div style="text-align:center;padding:0 12px;border-right:1px solid #f0f2f8">
                <div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:2px">6-Month Invoiced</div>
                <div style="font-size:13px;font-weight:800;color:#f97316">&#8377;&nbsp;<?=indianFormat($totalInv6,0)?></div>
            </div>
            <div style="text-align:center;padding:0 12px;border-right:1px solid #f0f2f8">
                <div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:2px">6-Month Collected</div>
                <div style="font-size:13px;font-weight:800;color:#16a34a">&#8377;&nbsp;<?=indianFormat($totalCol6,0)?></div>
            </div>
            <div style="text-align:center;padding:0 12px;border-right:1px solid #f0f2f8">
                <div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:2px">Overall Rate</div>
                <div style="font-size:13px;font-weight:800;color:<?=$rate6>=70?'#16a34a':($rate6>=40?'#f97316':'#dc2626')?>"><?=$rate6?>%</div>
            </div>
            <div style="text-align:center;padding:0 12px">
                <div style="font-size:9px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;margin-bottom:2px">Best Month</div>
                <div style="font-size:13px;font-weight:800;color:#2563eb"><?=date('M Y',strtotime($bestMonth))?></div>
            </div>
        </div>
    </div>

    <!-- ── TOP CUSTOMERS LEADERBOARD ── -->
    <div class="rev-card" style="padding:0;overflow:hidden;">

        <!-- Header -->
        <div style="padding:8px 14px 6px;border-bottom:1px solid #f0f2f8;">
            <div class="card-title"><i class="fas fa-trophy"></i> Customer Leaderboard</div>
            <div class="card-subtitle">Top customers by revenue — <?=htmlspecialchars($periodLabel)?></div>
        </div>

        <?php
        $leaders = $pdo->query("
            SELECT customer,
                   COUNT(*) as inv_count,
                   COALESCE(SUM(amount_received),0) as collected,
                   COALESCE(SUM(amount_pending),0)  as pending
            FROM invoices
            WHERE invoice_date BETWEEN '$from' AND '$to'
            GROUP BY customer
            ORDER BY collected DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
        $medals  = ['🥇','🥈','🥉','4','5'];
        $lColors = ['#f59e0b','#94a3b8','#c2773a','#6b7280','#6b7280'];
        $bgCols  = ['rgba(245,158,11,.12)','rgba(148,163,184,.1)','rgba(194,119,58,.1)','#f8fafc','#f8fafc'];
        $maxC    = !empty($leaders) ? max(array_column($leaders,'collected')) : 1;
        ?>

        <?php if(empty($leaders)): ?>
        <div style="text-align:center;padding:40px;color:#9ca3af;font-size:12px">No data for this period</div>
        <?php else: ?>
        <div style="padding:4px 0;">
        <?php foreach($leaders as $li => $ldr):
            $pct   = $maxC > 0 ? round($ldr['collected']/$maxC*100) : 0;
            $lc    = $lColors[$li];
            $bg    = $bgCols[$li];
            $name  = htmlspecialchars($ldr['customer'] ?? '—');
            $init  = strtoupper(substr($ldr['customer'],0,1));
            $isTop = $li === 0;
        ?>
        <div style="display:flex;align-items:center;gap:10px;padding:6px 14px;background:<?=$bg?>;<?=$isTop?'border-left:3px solid #f59e0b;':''?>transition:background .15s" onmouseenter="this.style.background='#f0f2f8'" onmouseleave="this.style.background='<?=$bg?>'">

            <!-- Rank -->
            <div style="font-size:<?=$li<3?'20px':'13px'?>;width:24px;text-align:center;flex-shrink:0;font-weight:800;color:<?=$lc?>"><?=$medals[$li]?></div>

            <!-- Avatar -->
            <div style="width:34px;height:34px;border-radius:10px;background:<?=$lc?>22;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:<?=$lc?>;flex-shrink:0;border:1.5px solid <?=$lc?>44"><?=$init?></div>

            <!-- Name + bar -->
            <div style="flex:1;min-width:0;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
                    <div style="font-size:12px;font-weight:700;color:#1a1f2e;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px"><?=$name?></div>
                    <div style="text-align:right;flex-shrink:0;margin-left:8px">
                        <div style="font-size:12px;font-weight:800;color:#16a34a">&#8377;&nbsp;<?=indianFormat($ldr['collected'],0)?></div>
                        <?php if($ldr['pending']>0): ?>
                        <div style="font-size:9px;color:#dc2626">+&#8377;&nbsp;<?=indianFormat($ldr['pending'],0)?> due</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="background:#e8ecf4;border-radius:20px;height:5px;overflow:hidden">
                    <div style="width:<?=$pct?>%;height:100%;background:<?=$lc?>;border-radius:20px;"></div>
                </div>
                <div style="font-size:9px;color:#9ca3af;margin-top:3px"><?=$ldr['inv_count']?> invoice<?=$ldr['inv_count']!=1?'s':''?></div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <!-- Total footer -->
        <?php $grandTotal = array_sum(array_column($leaders,'collected')); ?>
        <div style="padding:6px 14px;border-top:1px solid #f0f2f8;display:flex;justify-content:space-between;align-items:center;background:#fafbfd">
            <span style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px">Top <?=count($leaders)?> Total</span>
            <span style="font-size:14px;font-weight:800;color:#1a1f2e">&#8377;&nbsp;<?=indianFormat($grandTotal,0)?></span>
        </div>
        <?php endif; ?>
    </div>

    </div><!-- end cash flow + goals grid -->

    </div>
</div>
<!-- ── PIE CLICK MODAL ── -->
<div id="pieModal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,20,35,.6);backdrop-filter:blur(3px);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:18px;width:94%;max-width:640px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.28);overflow:hidden;font-family:'Times New Roman',serif;">

    <!-- Header -->
    <div id="pmHeader" style="padding:18px 22px 14px;border-bottom:1px solid #f0f2f8;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="display:flex;align-items:center;gap:9px;font-size:15px;font-weight:800;color:#1a1f2e">
          <span id="pmDot" style="width:14px;height:14px;border-radius:4px;display:inline-block;flex-shrink:0"></span>
          <span id="pmTitle"></span>
        </div>
        <div id="pmSub" style="font-size:11px;color:#9ca3af;margin-top:4px;padding-left:23px"></div>
      </div>
      <div style="display:flex;align-items:center;gap:16px">
        <div style="text-align:right">
          <div style="font-size:10px;color:#9ca3af;font-weight:700;text-transform:uppercase;letter-spacing:.8px">Amount</div>
          <div id="pmTotal" style="font-size:18px;font-weight:800;color:#1a1f2e"></div>
        </div>
        <button onclick="closePieModal()" style="background:#f4f6fb;border:none;border-radius:50%;width:34px;height:34px;font-size:18px;cursor:pointer;color:#6b7280;line-height:1;display:flex;align-items:center;justify-content:center">×</button>
      </div>
    </div>

    <!-- Table -->
    <div style="overflow-y:auto;flex:1;padding:4px 22px 18px">
      <table style="width:100%;border-collapse:collapse;font-size:13px;font-family:'Times New Roman',serif">
        <thead>
          <tr style="color:#9ca3af;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;position:sticky;top:0;background:#fff">
            <th style="padding:12px 8px 8px;text-align:left;border-bottom:2px solid #f0f2f8">#</th>
            <th style="padding:12px 8px 8px;text-align:left;border-bottom:2px solid #f0f2f8">Invoice No</th>
            <th style="padding:12px 8px 8px;text-align:left;border-bottom:2px solid #f0f2f8">Date</th>
            <th style="padding:12px 8px 8px;text-align:right;border-bottom:2px solid #f0f2f8">Received (₹)</th>
            <th style="padding:12px 8px 8px;text-align:right;border-bottom:2px solid #f0f2f8">Pending (₹)</th>
          </tr>
        </thead>
        <tbody id="pmBody"></tbody>
      </table>
      <div id="pmEmpty" style="display:none;text-align:center;padding:36px;color:#9ca3af;font-size:13px">No invoices for this period.</div>
    </div>
  </div>
</div>

<script>
const _pieData  = <?=json_encode($modalInvoices,JSON_HEX_TAG|JSON_HEX_AMP)?>;
const _pieColor = {Paid:'#16a34a',Partial:'#f97316',Unpaid:'#dc2626'};
const _pieBg    = {Paid:'#dcfce7',Partial:'#fff7ed',Unpaid:'#fef2f2'};
const _periodLabel = '<?=addslashes($periodLabel)?>';

function _inr(n){
    n=Math.round(n); if(!n) return '0';
    let s=String(n),l=s.slice(-3),r=s.slice(0,-3);
    if(r) r=r.replace(/\B(?=(\d{2})+(?!\d))/g,',');
    return (r?r+',':'')+l;
}

function openPieModal(status){
    const rows   = _pieData[status]||[];
    const color  = _pieColor[status];
    const bg     = _pieBg[status];
    const isUnpaid = status==='Unpaid';

    document.getElementById('pmDot').style.background   = color;
    document.getElementById('pmTitle').textContent       = status+' Invoices';
    document.getElementById('pmSub').textContent         = rows.length+' invoice'+(rows.length!==1?'s':'')+' · '+_periodLabel;
    document.getElementById('pmHeader').style.borderTop  = '4px solid '+color;

    let total=0;
    rows.forEach(r=>total+=(isUnpaid?r.pending:r.received));
    document.getElementById('pmTotal').textContent = '₹'+_inr(total);

    const tbody=document.getElementById('pmBody');
    tbody.innerHTML='';
    if(!rows.length){
        document.getElementById('pmEmpty').style.display='block';
    } else {
        document.getElementById('pmEmpty').style.display='none';
        rows.forEach((r,i)=>{
            const tr=document.createElement('tr');
            tr.style.cssText='border-bottom:1px solid #f9fafb;transition:background .12s';
            tr.onmouseenter=()=>tr.style.background='#f9fafb';
            tr.onmouseleave=()=>tr.style.background='';
            tr.innerHTML=
                '<td style="padding:10px 8px;color:#9ca3af;font-size:11px">'+(i+1)+'</td>'+
                '<td style="padding:10px 8px"><span style="background:'+bg+';color:'+color+';padding:3px 10px;border-radius:8px;font-size:11px;font-weight:700">'+r.inv_no+'</span></td>'+
                '<td style="padding:10px 8px;color:#6b7280;font-size:12px">'+r.date+'</td>'+
                '<td style="padding:10px 8px;text-align:right;font-weight:700;color:#16a34a">'+(r.received>0?'₹'+_inr(r.received):'—')+'</td>'+
                '<td style="padding:10px 8px;text-align:right;font-weight:700;color:#dc2626">'+(r.pending>0?'₹'+_inr(r.pending):'—')+'</td>';
            tbody.appendChild(tr);
        });
    }
    const m=document.getElementById('pieModal');
    m.style.display='flex';
    document.body.style.overflow='hidden';
}
function closePieModal(){
    document.getElementById('pieModal').style.display='none';
    document.body.style.overflow='';
}
document.getElementById('pieModal').addEventListener('click',function(e){if(e.target===this)closePieModal();});
document.addEventListener('keydown',function(e){if(e.key==='Escape')closePieModal();});
</script>
</body>
</html>

<?php
require_once dirname(__DIR__, 2) . '/db.php';

$today  = date('j-M-y');
$tcPage = $_GET['tc_page'] ?? 'mainproject';
$tcSub  = $_GET['tc_sub']  ?? 'create';

$company = [];
try { $company = $pdo->query("SELECT * FROM invoice_company ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: []; } catch(Exception $e) {}

$doc_key = 'ELT-QT-' . date('y') . rand(10,99) . 'V1';

// ── INLINE GENERATION (POST) ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $d = $_POST;
    $authors = [];
    foreach (($d['auth_role'] ?? []) as $i => $r) {
        if (trim($r)==='' && trim($d['auth_name'][$i]??'')==='') continue;
        $authors[] = ['role'=>trim($r),'name'=>trim($d['auth_name'][$i]??''),'dept'=>trim($d['auth_dept'][$i]??''),'email'=>trim($d['auth_email'][$i]??''),'date'=>trim($d['auth_date'][$i]??'')];
    }
    $revisions = [];
    foreach (($d['rev_ver'] ?? []) as $i => $v) {
        if (trim($v)==='') continue;
        $revisions[] = ['ver'=>trim($v),'prev'=>trim($d['rev_prev'][$i]??''),'date'=>trim($d['rev_date'][$i]??''),'change'=>trim($d['rev_change'][$i]??'')];
    }
    $hw_rows  = [];
    foreach (($d['hw_sno'] ?? []) as $i => $s) { $hw_rows[]  = ['sno'=>trim($s),'desc'=>trim($d['hw_desc'][$i]??''),'qty'=>trim($d['hw_qty'][$i]??'1'),'unit'=>trim($d['hw_unit'][$i]??'Lot'),'price'=>(float)($d['hw_price'][$i]??0),'amount'=>(float)($d['hw_qty'][$i]??1)*(float)($d['hw_price'][$i]??0)]; }
    $svc_rows = [];
    foreach (($d['svc_sno'] ?? []) as $i => $s) { $svc_rows[] = ['sno'=>trim($s),'desc'=>trim($d['svc_desc'][$i]??''),'make'=>trim($d['svc_make'][$i]??''),'qty'=>trim($d['svc_qty'][$i]??'1'),'amount'=>(float)($d['svc_amount'][$i]??0)]; }
    $comm_rows = [];
    foreach (($d['comm_item'] ?? []) as $i => $it) { $comm_rows[] = ['item'=>trim($it),'desc'=>trim($d['comm_desc'][$i]??''),'hsn'=>trim($d['comm_hsn'][$i]??''),'qty'=>trim($d['comm_qty'][$i]??'1'),'unit'=>trim($d['comm_unit'][$i]??'Lot'),'amount'=>(float)($d['comm_amount'][$i]??0)]; }

    $data = [
        'project_title'  => trim($d['project_title']  ?? ''),
        'customer_name'  => trim($d['customer_name']  ?? ''),
        'doc_key'        => trim($d['doc_key']         ?? $doc_key),
        'version'        => trim($d['version']         ?? 'V1'),
        'version_desc'   => trim($d['version_desc']    ?? 'Initial Release'),
        'revision'       => trim($d['revision']        ?? '1.0'),
        'doc_date'       => trim($d['doc_date']        ?? $today),
        'company_name'   => trim($d['company_name']    ?? 'ELTRIVE AUTOMATIONS PVT LTD'),
        'company_email'  => trim($d['company_email']   ?? 'automations@eltrive.com'),
        'designed_by'    => trim($d['designed_by']     ?? ''),
        'designed_title' => trim($d['designed_title']  ?? 'Assistant Manager'),
        'released_by'    => trim($d['released_by']     ?? ''),
        'released_title' => trim($d['released_title']  ?? 'Automation Lead'),
        'amc_yearly'     => (float)($d['amc_yearly']   ?? 0),
        'total_cost_desc'=> trim($d['total_cost_desc'] ?? 'Total Cost: Rs.'),
        'total_cost'     => (float)($d['total_cost']   ?? 0),
        'payment_terms'  => trim($d['payment_terms']   ?? ''),
        'notes'          => trim($d['notes']           ?? ''),
        'authors'        => $authors, 'revisions' => $revisions,
        'hw_rows'        => $hw_rows, 'svc_rows'  => $svc_rows, 'comm_rows' => $comm_rows,
    ];

    $sid        = substr(md5(uniqid()), 0, 8);
    $json_path  = sys_get_temp_dir() . '/tc_data_' . $sid . '.json';
    $out_path   = sys_get_temp_dir() . '/tc_out_'  . $sid . '.docx';
    $js_script  = __DIR__ . '/tc_generate.js';

    file_put_contents($json_path, json_encode($data, JSON_UNESCAPED_UNICODE));
    if (file_exists($out_path)) unlink($out_path);

    $cmd    = 'TC_JSON=' . escapeshellarg($json_path) . ' TC_OUT=' . escapeshellarg($out_path) . ' node ' . escapeshellarg($js_script) . ' 2>&1';
    $result = shell_exec($cmd);

    if (file_exists($out_path)) {
        $fname = preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['doc_key'] ?: 'TC_Document') . '.docx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . filesize($out_path));
        header('Cache-Control: no-cache');
        readfile($out_path);
        @unlink($json_path); @unlink($out_path);
        exit;
    }
    $genError = 'DOCX generation failed: ' . htmlspecialchars(substr($result ?? '', 0, 300));
}

// ── Sections map ──────────────────────────────────────────────────────────────
$sections = [
    'mainproject'  => ['icon'=>'fa-project-diagram',   'label'=>'Main Project',        'color'=>'#f97316'],
    'overview'     => ['icon'=>'fa-binoculars',         'label'=>'Overview',            'color'=>'#3b82f6'],
    'benefits'     => ['icon'=>'fa-chart-line',         'label'=>'Benefits & ROI',      'color'=>'#16a34a'],
    'scope'        => ['icon'=>'fa-list-check',         'label'=>'Scope of Work',       'color'=>'#8b5cf6'],
    'current'      => ['icon'=>'fa-exclamation-circle', 'label'=>'Current Scenario',    'color'=>'#f59e0b'],
    'proposed'     => ['icon'=>'fa-lightbulb',          'label'=>'Proposed Solution',   'color'=>'#0ea5e9'],
    'utilities'    => ['icon'=>'fa-plug',               'label'=>'Utilities Covered',   'color'=>'#6366f1'],
    'architecture' => ['icon'=>'fa-sitemap',            'label'=>'Architecture',         'color'=>'#d946ef'],
    'kpis'         => ['icon'=>'fa-gauge-high',         'label'=>'KPIs',                'color'=>'#10b981'],
    'dashboardfeat'=> ['icon'=>'fa-desktop',            'label'=>'Dashboard Features',  'color'=>'#f97316'],
    'testing'      => ['icon'=>'fa-vials',              'label'=>'Testing & Comm.',     'color'=>'#3b82f6'],
    'deliverables' => ['icon'=>'fa-boxes-stacked',      'label'=>'Deliverables (BOQ)',  'color'=>'#f59e0b'],
    'customer'     => ['icon'=>'fa-handshake',          'label'=>'Customer Scope',      'color'=>'#16a34a'],
    'outscope'     => ['icon'=>'fa-ban',                'label'=>'Out of Scope',        'color'=>'#dc2626'],
    'commercials'  => ['icon'=>'fa-rupee-sign',         'label'=>'Commercials',         'color'=>'#16a34a'],
    'comsummary'   => ['icon'=>'fa-file-invoice',       'label'=>'Commercial Summary',  'color'=>'#8b5cf6'],
];
$cur = $sections[$tcPage] ?? $sections['mainproject'];
$keys = array_keys($sections);
$idx  = array_search($tcPage, $keys);
$prev = $idx > 0                  ? $keys[$idx-1] : null;
$next = $idx < count($keys)-1     ? $keys[$idx+1] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>TC – <?= htmlspecialchars($cur['label']) ?> – Eltrive</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;background:#f4f6fb;color:#222;min-height:100vh}
.content{margin-left:190px;padding:58px 20px 40px;background:#f4f6fb;min-height:100vh}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:11px;color:#9ca3af;margin-bottom:10px;flex-wrap:wrap}
.breadcrumb a{color:#9ca3af;text-decoration:none}.breadcrumb a:hover{color:#f97316}
.breadcrumb span{color:#374151;font-weight:600}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px}
.page-header-left{display:flex;align-items:center;gap:10px}
.page-icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff;flex-shrink:0}
.page-title{font-size:16px;font-weight:700;color:#1a1f2e}
.page-sub{font-size:11px;color:#9ca3af;margin-top:1px}
.section-tabs{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #e4e8f0}
.section-tab{padding:4px 11px;border-radius:20px;font-size:11px;font-weight:600;text-decoration:none;color:#6b7280;border:1.5px solid #e4e8f0;background:#fff;transition:all .15s;font-family:'Times New Roman',Times,serif}
.section-tab:hover{border-color:#f97316;color:#f97316}.section-tab.active{background:#f97316;color:#fff;border-color:#f97316}
.form-card{background:#fff;border-radius:12px;border:1px solid #e4e8f0;box-shadow:0 1px 4px rgba(0,0,0,.04);margin-bottom:12px;overflow:hidden}
.form-card-head{padding:8px 14px;border-bottom:1px solid #f0f2f7;background:#fafbfd;display:flex;align-items:center;gap:8px}
.form-card-head .icon{width:22px;height:22px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#fff;flex-shrink:0}
.form-card-head h3{font-size:12px;font-weight:700;color:#1a1f2e;text-transform:uppercase;letter-spacing:.5px}
.form-card-body{padding:14px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.full{grid-column:1/-1}
.fg{margin-bottom:8px}
.fg label{display:block;font-size:10px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;margin-bottom:3px}
.fg input,.fg textarea,.fg select{width:100%;padding:6px 9px;border:1.5px solid #e4e8f0;border-radius:7px;font-size:12px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;outline:none;transition:border-color .2s}
.fg input:focus,.fg textarea:focus,.fg select:focus{border-color:#f97316}
.fg textarea{resize:vertical;min-height:65px}
.fg input[readonly]{background:#f9fafb;color:#9ca3af}
.items-textarea{width:100%;min-height:120px;padding:8px 10px;border:1.5px solid #e4e8f0;border-radius:7px;font-size:12px;font-family:'Times New Roman',Times,serif;color:#374151;background:#fff;outline:none;resize:vertical;line-height:1.7;transition:border-color .2s}
.items-textarea:focus{border-color:#f97316}
.items-hint{font-size:10px;color:#9ca3af;margin-top:3px}
.boq-table,.rev-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px}
.boq-table th,.rev-table th{background:#f0f2f7;padding:5px 7px;text-align:left;font-size:10px;font-weight:700;color:#6b7280;border:1px solid #e4e8f0;text-transform:uppercase}
.boq-table td,.rev-table td{padding:3px 5px;border:1px solid #e4e8f0;vertical-align:middle}
.boq-table td input,.rev-table td input{border:none;background:transparent;width:100%;font-size:12px;font-family:'Times New Roman',Times,serif;outline:none;padding:2px 4px}
.boq-table td input:focus,.rev-table td input:focus{background:#fff7f0;border-radius:3px}
.btn-add-row{margin-top:7px;padding:4px 11px;background:#f97316;color:#fff;border:none;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;font-family:'Times New Roman',Times,serif}
.btn-del-row{width:22px;height:22px;border-radius:4px;background:#fee2e2;color:#dc2626;border:none;cursor:pointer;font-size:11px;display:inline-flex;align-items:center;justify-content:center}
.actions{display:flex;gap:8px;margin-top:14px;flex-wrap:wrap;align-items:center}
.btn-generate{padding:8px 20px;background:#f97316;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;gap:7px;transition:background .2s}
.btn-generate:hover{background:#ea6a0a}
.btn-nav{padding:8px 14px;background:#fff;color:#374151;border:1.5px solid #e4e8f0;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;gap:5px;text-decoration:none;transition:all .2s}
.btn-nav:hover{border-color:#f97316;color:#f97316}
.alert{padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:12px;display:flex;align-items:center;gap:8px}
.alert-error{background:#fee2e2;border:1px solid #fecaca;color:#dc2626}
@media(max-width:900px){.content{margin-left:0;padding:70px 12px 20px}.grid2,.grid3{grid-template-columns:1fr}.section-tabs{display:none}}
</style>
</head>
<body>
<?php include dirname(__DIR__, 2) . '/sidebar.php'; ?>
<?php include dirname(__DIR__, 2) . '/header.php'; ?>

<div class="content">

<?php if (isset($genError)): ?>
<div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $genError ?></div>
<?php endif; ?>

<div class="breadcrumb">
    <a href="/invoice/dashboard.php">Dashboard</a>
    <i class="fas fa-chevron-right" style="font-size:9px"></i>
    <a href="tc_index.php?tc_sub=create&tc_page=mainproject">Techno Commercial</a>
    <i class="fas fa-chevron-right" style="font-size:9px"></i>
    <span><?= htmlspecialchars($cur['label']) ?></span>
</div>

<div class="page-header">
    <div class="page-header-left">
        <div class="page-icon" style="background:<?= $cur['color'] ?>"><i class="fas <?= $cur['icon'] ?>"></i></div>
        <div>
            <div class="page-title"><?= htmlspecialchars($cur['label']) ?></div>
            <div class="page-sub">Techno Commercial Document Builder</div>
        </div>
    </div>
    <button type="submit" form="tcForm" name="generate" value="1" class="btn-generate">
        <i class="fas fa-file-word"></i> Generate DOCX
    </button>
</div>

<div class="section-tabs">
<?php foreach ($sections as $key => $sec): ?>
    <a href="tc_index.php?tc_sub=create&tc_page=<?= $key ?>" class="section-tab <?= ($tcPage===$key)?'active':'' ?>"><?= htmlspecialchars($sec['label']) ?></a>
<?php endforeach; ?>
</div>

<form method="POST" action="tc_index.php?tc_sub=create&tc_page=<?= htmlspecialchars($tcPage) ?>" id="tcForm">

<?php
$isMain        = ($tcPage === 'mainproject');
$isBenefits    = in_array($tcPage, ['benefits','overview']);
$isScope       = in_array($tcPage, ['scope','customer','outscope']);
$isSolution    = in_array($tcPage, ['current','proposed']);
$isUtilities   = in_array($tcPage, ['utilities','architecture']);
$isKPI         = in_array($tcPage, ['kpis','dashboardfeat']);
$isTesting     = ($tcPage === 'testing');
$isDeliverable = in_array($tcPage, ['deliverables','commercials','comsummary']);
$isCommercial  = in_array($tcPage, ['commercials','comsummary']);
?>

<?php if (!$isMain): ?>
<input type="hidden" name="project_title" value="">
<input type="hidden" name="customer_name" value="">
<input type="hidden" name="doc_key" value="<?= htmlspecialchars($doc_key) ?>">
<input type="hidden" name="version" value="V1">
<input type="hidden" name="version_desc" value="Initial Release">
<input type="hidden" name="revision" value="1.0">
<input type="hidden" name="doc_date" value="<?= $today ?>">
<input type="hidden" name="company_name" value="<?= htmlspecialchars($company['company_name'] ?? 'ELTRIVE AUTOMATIONS PVT LTD') ?>">
<input type="hidden" name="company_email" value="<?= htmlspecialchars($company['email'] ?? 'automations@eltrive.com') ?>">
<input type="hidden" name="auth_role[]" value=""><input type="hidden" name="auth_name[]" value=""><input type="hidden" name="auth_dept[]" value=""><input type="hidden" name="auth_email[]" value=""><input type="hidden" name="auth_date[]" value="">
<input type="hidden" name="rev_ver[]" value="1.0"><input type="hidden" name="rev_prev[]" value="AA"><input type="hidden" name="rev_date[]" value="<?= $today ?>"><input type="hidden" name="rev_change[]" value="Initial Release">
<input type="hidden" name="designed_by" value=""><input type="hidden" name="designed_title" value="Assistant Manager">
<input type="hidden" name="released_by" value=""><input type="hidden" name="released_title" value="Automation Lead">
<?php endif; ?>

<?php if (!$isDeliverable): ?>
<input type="hidden" name="hw_sno[]" value=""><input type="hidden" name="hw_desc[]" value=""><input type="hidden" name="hw_qty[]" value="1"><input type="hidden" name="hw_unit[]" value="Lot"><input type="hidden" name="hw_price[]" value="0"><input type="hidden" name="hw_amount[]" value="0">
<input type="hidden" name="svc_sno[]" value=""><input type="hidden" name="svc_desc[]" value=""><input type="hidden" name="svc_make[]" value="Eltrive"><input type="hidden" name="svc_qty[]" value="1"><input type="hidden" name="svc_amount[]" value="0">
<?php endif; ?>
<?php if (!$isCommercial): ?>
<input type="hidden" name="comm_item[]" value=""><input type="hidden" name="comm_desc[]" value=""><input type="hidden" name="comm_hsn[]" value=""><input type="hidden" name="comm_qty[]" value="1"><input type="hidden" name="comm_unit[]" value="Lot"><input type="hidden" name="comm_amount[]" value="0">
<input type="hidden" name="amc_yearly" value="0">
<input type="hidden" name="total_cost_desc" value="Total Cost: Rs.">
<input type="hidden" name="total_cost" value="0">
<input type="hidden" name="payment_terms" value="">
<input type="hidden" name="notes" value="">
<?php endif; ?>

<!-- ── MAIN PROJECT ── -->
<?php if ($isMain): ?>
<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:#f97316"><i class="fas fa-id-card"></i></div><h3>Cover Page / Document Identity</h3></div>
    <div class="form-card-body">
        <div class="grid2">
            <div class="fg full"><label>Project Title / System Name *</label><input type="text" name="project_title" required placeholder="e.g. Safe Hydra- Fire Hydrant Pump House Monitoring System"></div>
            <div class="fg"><label>Customer Name *</label><input type="text" name="customer_name" required placeholder="e.g. M/S Aragen Pharma, Nacharam"></div>
            <div class="fg"><label>Document Key</label><input type="text" name="doc_key" value="<?= htmlspecialchars($doc_key) ?>"></div>
            <div class="fg"><label>Version</label><input type="text" name="version" value="V1"></div>
            <div class="fg"><label>Version Description</label><input type="text" name="version_desc" value="Initial Release"></div>
            <div class="fg"><label>Revision</label><input type="text" name="revision" value="1.0"></div>
            <div class="fg"><label>Document Date</label><input type="text" name="doc_date" value="<?= $today ?>"></div>
            <div class="fg"><label>Company Name *</label><input type="text" name="company_name" value="<?= htmlspecialchars($company['company_name'] ?? 'ELTRIVE AUTOMATIONS PVT LTD') ?>" required></div>
            <div class="fg"><label>Company Email</label><input type="text" name="company_email" value="<?= htmlspecialchars($company['email'] ?? 'automations@eltrive.com') ?>"></div>
        </div>
    </div>
</div>

<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:#3b82f6"><i class="fas fa-users"></i></div><h3>Document Authors / Approvers</h3></div>
    <div class="form-card-body">
        <table class="boq-table" id="authorsTable">
            <thead><tr><th style="width:95px">Role</th><th>Name</th><th style="width:110px">Department</th><th>Email</th><th style="width:85px">Date</th><th style="width:28px"></th></tr></thead>
            <tbody>
                <tr><td><input type="text" name="auth_role[]" value="Author:"></td><td><input type="text" name="auth_name[]" placeholder="Name"></td><td><input type="text" name="auth_dept[]" value="Automation"></td><td><input type="text" name="auth_email[]" placeholder="email@eltrive.com"></td><td><input type="text" name="auth_date[]" value="<?= $today ?>"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'authorsTable')"><i class="fas fa-times"></i></button></td></tr>
                <tr><td><input type="text" name="auth_role[]" value="1st. Check:"></td><td><input type="text" name="auth_name[]" placeholder="Name"></td><td><input type="text" name="auth_dept[]" value="Automation"></td><td><input type="text" name="auth_email[]" placeholder="email@eltrive.com"></td><td><input type="text" name="auth_date[]" value="<?= $today ?>"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'authorsTable')"><i class="fas fa-times"></i></button></td></tr>
                <tr><td><input type="text" name="auth_role[]" value="Approved"></td><td><input type="text" name="auth_name[]" placeholder="Name"></td><td><input type="text" name="auth_dept[]" value="Automation"></td><td><input type="text" name="auth_email[]" placeholder="email@eltrive.com"></td><td><input type="text" name="auth_date[]" value="<?= $today ?>"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'authorsTable')"><i class="fas fa-times"></i></button></td></tr>
            </tbody>
        </table>
        <button type="button" class="btn-add-row" onclick="addAuthRow()"><i class="fas fa-plus"></i> Add Row</button>
    </div>
</div>

<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:#8b5cf6"><i class="fas fa-history"></i></div><h3>Revision History</h3></div>
    <div class="form-card-body">
        <table class="rev-table" id="revTable">
            <thead><tr><th style="width:75px">Version</th><th style="width:95px">Previous Ver</th><th style="width:95px">Date</th><th>Change Content</th><th style="width:28px"></th></tr></thead>
            <tbody>
                <tr><td><input type="text" name="rev_ver[]" value="1.0"></td><td><input type="text" name="rev_prev[]" value="AA"></td><td><input type="text" name="rev_date[]" value="<?= $today ?>"></td><td><input type="text" name="rev_change[]" value="Initial Release"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'revTable')"><i class="fas fa-times"></i></button></td></tr>
            </tbody>
        </table>
        <button type="button" class="btn-add-row" onclick="addRevRow()"><i class="fas fa-plus"></i> Add Row</button>
    </div>
</div>

<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:#16a34a"><i class="fas fa-signature"></i></div><h3>Page Footer Details</h3></div>
    <div class="form-card-body">
        <div class="grid2">
            <div class="fg"><label>Designed by – Name</label><input type="text" name="designed_by" placeholder="G.Chakravarthy"></div>
            <div class="fg"><label>Designed by – Title</label><input type="text" name="designed_title" value="Assistant Manager"></div>
            <div class="fg"><label>Released by – Name</label><input type="text" name="released_by" placeholder="V.Bhaskar Bhargavi"></div>
            <div class="fg"><label>Released by – Title</label><input type="text" name="released_title" value="Automation Lead"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── BENEFITS / OVERVIEW ── -->
<?php if ($isBenefits): ?>
<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:<?= $cur['color'] ?>"><i class="fas <?= $cur['icon'] ?>"></i></div><h3><?= $tcPage==='overview' ? 'Project Overview' : 'Benefits & ROI' ?></h3></div>
    <div class="form-card-body">
        <div class="fg"><label><?= $tcPage==='overview' ? 'Project Overview Text' : 'Benefits (one per line)' ?></label>
            <textarea class="items-textarea" name="benefits_text" placeholder="Enter each benefit on a new line...&#10;e.g. 24×7 real-time monitoring&#10;Instant alerts for pump faults and pressure drops"></textarea>
            <div class="items-hint">Each line becomes a numbered point in the document</div>
        </div>
        <div class="fg" style="margin-top:10px"><label>Key Features (one per line)</label>
            <textarea class="items-textarea" name="features_text" placeholder="e.g. Jockey pump health monitoring&#10;Main hydrant pump health monitoring"></textarea>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── SCOPE ── -->
<?php if ($isScope): ?>
<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:<?= $cur['color'] ?>"><i class="fas <?= $cur['icon'] ?>"></i></div>
    <h3><?= ['scope'=>'Eltrive Scope of Work','customer'=>'Customer Scope / Support Required','outscope'=>'Out of Scope'][$tcPage] ?></h3></div>
    <div class="form-card-body">
        <div class="fg"><label>Items (one per line)</label>
            <textarea class="items-textarea" name="scope_text" style="min-height:160px" placeholder="Enter each item on a new line...&#10;e.g. Site visit to the fire pump house&#10;System requirements capture and finalization"></textarea>
            <div class="items-hint">Each line becomes a numbered sub-point in the document</div>
        </div>
        <?php if ($tcPage==='scope'): ?>
        <div class="fg" style="margin-top:10px"><label>Assumptions (one per line)</label>
            <textarea class="items-textarea" name="assumptions_text" placeholder="e.g. Accessible pump room&#10;Clean 230V AC power supply"></textarea>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── CURRENT / PROPOSED ── -->
<?php if ($isSolution): ?>
<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:<?= $cur['color'] ?>"><i class="fas <?= $cur['icon'] ?>"></i></div>
    <h3><?= $tcPage==='current' ? 'Current Scenario / Problem Statement' : 'Proposed Solution' ?></h3></div>
    <div class="form-card-body">
        <div class="fg"><label>Description</label>
            <textarea class="items-textarea" name="solution_text" style="min-height:150px" placeholder="<?= $tcPage==='current' ? 'Describe the current challenges and pain points...' : 'Describe the proposed solution and how it addresses the challenges...' ?>"></textarea>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── UTILITIES / ARCHITECTURE ── -->
<?php if ($isUtilities): ?>
<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:<?= $cur['color'] ?>"><i class="fas <?= $cur['icon'] ?>"></i></div>
    <h3><?= $tcPage==='utilities' ? 'Utilities Covered' : 'System Architecture' ?></h3></div>
    <div class="form-card-body">
        <div class="fg"><label><?= $tcPage==='utilities' ? 'Utilities / Systems Covered (one per line)' : 'Architecture Description' ?></label>
            <textarea class="items-textarea" name="utilities_text" style="min-height:130px" placeholder="<?= $tcPage==='utilities' ? "e.g. Fire pump house\nJockey pump monitoring\nDiesel engine monitoring" : 'Describe the system architecture and components...' ?>"></textarea>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── KPIs / DASHBOARD ── -->
<?php if ($isKPI): ?>
<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:<?= $cur['color'] ?>"><i class="fas <?= $cur['icon'] ?>"></i></div>
    <h3><?= $tcPage==='kpis' ? 'KPIs & Metrics' : 'Dashboard Features' ?></h3></div>
    <div class="form-card-body">
        <div class="fg"><label><?= $tcPage==='kpis' ? 'KPIs (one per line)' : 'Dashboard Features (one per line)' ?></label>
            <textarea class="items-textarea" name="kpi_text" style="min-height:130px" placeholder="<?= $tcPage==='kpis' ? "e.g. Pump uptime %\nPressure range compliance\nAlert response time" : "e.g. Real-time pressure gauges\nPump on/off status\nAlert history log" ?>"></textarea>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── TESTING ── -->
<?php if ($isTesting): ?>
<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:#3b82f6"><i class="fas fa-vials"></i></div><h3>Testing & Commissioning</h3></div>
    <div class="form-card-body">
        <div class="fg"><label>Testing Steps (one per line)</label>
            <textarea class="items-textarea" name="testing_text" style="min-height:130px" placeholder="e.g. End-to-end system testing and commissioning&#10;Conduct training sessions for customer personnel&#10;Provide system manuals and technical documentation"></textarea>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── DELIVERABLES / BOQ ── -->
<?php if ($isDeliverable): ?>
<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:#f97316"><i class="fas fa-microchip"></i></div><h3>3.1 Hardware Deliverables (BOQ)</h3></div>
    <div class="form-card-body">
        <table class="boq-table" id="hwTable">
            <thead><tr><th style="width:36px">S.No</th><th>Details / Description</th><th style="width:44px">Qty</th><th style="width:50px">Unit</th><th style="width:80px">Price (Rs)</th><th style="width:85px">Amount (Rs)</th><th style="width:28px"></th></tr></thead>
            <tbody>
                <tr><td><input type="text" name="hw_sno[]" value="1"></td><td><input type="text" name="hw_desc[]" placeholder="Full Hardware Kit..."></td><td><input type="number" name="hw_qty[]" value="1" min="0" step="any"></td><td><input type="text" name="hw_unit[]" value="Lot"></td><td><input type="number" name="hw_price[]" value="0" min="0" step="any" class="hw-price" oninput="calcHwAmt(this)"></td><td><input type="text" name="hw_amount[]" readonly class="hw-amt" value="0"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'hwTable')"><i class="fas fa-times"></i></button></td></tr>
            </tbody>
        </table>
        <button type="button" class="btn-add-row" onclick="addHwRow()"><i class="fas fa-plus"></i> Add Item</button>
    </div>
</div>

<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:#0ea5e9"><i class="fas fa-tools"></i></div><h3>3.2 Services Supply</h3></div>
    <div class="form-card-body">
        <table class="boq-table" id="svcTable">
            <thead><tr><th style="width:36px">S.No</th><th>Details</th><th style="width:65px">Make</th><th style="width:44px">Qty</th><th style="width:90px">Amount (Rs)</th><th style="width:28px"></th></tr></thead>
            <tbody>
                <tr><td><input type="text" name="svc_sno[]" value="16"></td><td><input type="text" name="svc_desc[]" value="PLC Configuration & Programming"></td><td><input type="text" name="svc_make[]" value="Eltrive"></td><td><input type="number" name="svc_qty[]" value="1"></td><td><input type="number" name="svc_amount[]" value="20000" min="0" step="any"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'svcTable')"><i class="fas fa-times"></i></button></td></tr>
                <tr><td><input type="text" name="svc_sno[]" value="17"></td><td><input type="text" name="svc_desc[]" value="Web Application Development and Customization charges"></td><td><input type="text" name="svc_make[]" value="Eltrive"></td><td><input type="number" name="svc_qty[]" value="1"></td><td><input type="number" name="svc_amount[]" value="80000" min="0" step="any"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'svcTable')"><i class="fas fa-times"></i></button></td></tr>
                <tr><td><input type="text" name="svc_sno[]" value="18"></td><td><input type="text" name="svc_desc[]" value="Erection and Commissioning"></td><td><input type="text" name="svc_make[]" value="Eltrive"></td><td><input type="number" name="svc_qty[]" value="1"></td><td><input type="number" name="svc_amount[]" value="40000" min="0" step="any"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'svcTable')"><i class="fas fa-times"></i></button></td></tr>
                <tr><td><input type="text" name="svc_sno[]" value="19"></td><td><input type="text" name="svc_desc[]" value="Subscription- Yearly (Server and Data)"></td><td><input type="text" name="svc_make[]" value="Eltrive"></td><td><input type="number" name="svc_qty[]" value="1"></td><td><input type="number" name="svc_amount[]" value="125000" min="0" step="any"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'svcTable')"><i class="fas fa-times"></i></button></td></tr>
            </tbody>
        </table>
        <button type="button" class="btn-add-row" onclick="addSvcRow()"><i class="fas fa-plus"></i> Add Service</button>
    </div>
</div>
<?php endif; ?>

<!-- ── COMMERCIALS ── -->
<?php if ($isCommercial): ?>
<div class="form-card">
    <div class="form-card-head"><div class="icon" style="background:#16a34a"><i class="fas fa-rupee-sign"></i></div><h3>7. Commercials – Price Breakup</h3></div>
    <div class="form-card-body">
        <table class="boq-table" id="commTable">
            <thead><tr><th style="width:38px">Item</th><th>Details</th><th style="width:65px">HSN</th><th style="width:44px">Qty</th><th style="width:55px">Unit</th><th style="width:90px">Amount (Rs)</th><th style="width:28px"></th></tr></thead>
            <tbody>
                <tr><td><input type="text" name="comm_item[]" value="1"></td><td><input type="text" name="comm_desc[]" value="Hardware Supply"></td><td><input type="text" name="comm_hsn[]" value="85371"></td><td><input type="number" name="comm_qty[]" value="1"></td><td><input type="text" name="comm_unit[]" value="Lot"></td><td><input type="number" name="comm_amount[]" value="529811" min="0" step="any"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'commTable')"><i class="fas fa-times"></i></button></td></tr>
                <tr><td><input type="text" name="comm_item[]" value="2"></td><td><input type="text" name="comm_desc[]" value="Service & with AMC"></td><td><input type="text" name="comm_hsn[]" value="998314"></td><td><input type="number" name="comm_qty[]" value="1"></td><td><input type="text" name="comm_unit[]" value="Lot"></td><td><input type="number" name="comm_amount[]" value="265000" min="0" step="any"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'commTable')"><i class="fas fa-times"></i></button></td></tr>
                <tr><td><input type="text" name="comm_item[]" value="3"></td><td><input type="text" name="comm_desc[]" value="Software Applications"></td><td><input type="text" name="comm_hsn[]" value="998314"></td><td><input type="number" name="comm_qty[]" value="1"></td><td><input type="text" name="comm_unit[]" value="Lot"></td><td><input type="number" name="comm_amount[]" value="145000" min="0" step="any"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'commTable')"><i class="fas fa-times"></i></button></td></tr>
            </tbody>
        </table>
        <button type="button" class="btn-add-row" onclick="addCommRow()"><i class="fas fa-plus"></i> Add Item</button>

        <div class="grid3" style="margin-top:14px">
            <div class="fg"><label>Yearly AMC Charges (Rs)</label><input type="number" name="amc_yearly" value="250000" min="0" step="any"></div>
            <div class="fg"><label>Total Cost Label</label><input type="text" name="total_cost_desc" value="Total Cost for the first year including subscription Rs."></div>
            <div class="fg"><label>Total Cost (Rs)</label><input type="number" name="total_cost" value="939811" min="0" step="any"></div>
        </div>
        <div class="grid2" style="margin-top:8px">
            <div class="fg"><label>Payment Terms (one per line)</label>
                <textarea name="payment_terms">Validity: This quotation is valid for 30 days from the issue date.
Taxes: GST Extra 18%
Installation & Commissioning: Included.
Material Delivery Cost: Included.
Warranty 1 year from the delivery date
Delivery: 2 weeks from the date of the PO.
Payment Terms: 50% advance, 50% against delivery</textarea>
            </div>
            <div class="fg"><label>Notes / Additional Info</label><textarea name="notes" placeholder="Any additional notes or remarks..."></textarea></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="actions">
    <?php if ($prev): ?>
    <a href="tc_index.php?tc_sub=create&tc_page=<?= $prev ?>" class="btn-nav"><i class="fas fa-arrow-left"></i> <?= htmlspecialchars($sections[$prev]['label']) ?></a>
    <?php endif; ?>
    <button type="submit" name="generate" value="1" class="btn-generate"><i class="fas fa-file-word"></i> Generate DOCX</button>
    <?php if ($next): ?>
    <a href="tc_index.php?tc_sub=create&tc_page=<?= $next ?>" class="btn-nav"><?= htmlspecialchars($sections[$next]['label']) ?> <i class="fas fa-arrow-right"></i></a>
    <?php endif; ?>
</div>

</form>
</div>

<script>
function delRow(btn, tid) { const tb=document.getElementById(tid).querySelector('tbody'); if(tb.rows.length>1) btn.closest('tr').remove(); }
function addAuthRow() {
    const tb=document.getElementById('authorsTable').querySelector('tbody'),tr=document.createElement('tr');
    tr.innerHTML=`<td><input type="text" name="auth_role[]" placeholder="Role"></td><td><input type="text" name="auth_name[]"></td><td><input type="text" name="auth_dept[]" value="Automation"></td><td><input type="text" name="auth_email[]" placeholder="email@eltrive.com"></td><td><input type="text" name="auth_date[]" value="<?= $today ?>"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'authorsTable')"><i class="fas fa-times"></i></button></td>`;
    tb.appendChild(tr);
}
function addRevRow() {
    const tb=document.getElementById('revTable').querySelector('tbody'),tr=document.createElement('tr');
    tr.innerHTML=`<td><input type="text" name="rev_ver[]" placeholder="2.0"></td><td><input type="text" name="rev_prev[]" placeholder="1.0"></td><td><input type="text" name="rev_date[]" value="<?= $today ?>"></td><td><input type="text" name="rev_change[]" placeholder="Change description"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'revTable')"><i class="fas fa-times"></i></button></td>`;
    tb.appendChild(tr);
}
function calcHwAmt(input) { const row=input.closest('tr'),qty=parseFloat(row.querySelector('[name="hw_qty[]"]').value)||0,price=parseFloat(input.value)||0; row.querySelector('.hw-amt').value=(qty*price).toLocaleString('en-IN'); }
function addHwRow() {
    const tb=document.getElementById('hwTable').querySelector('tbody'),sno=tb.rows.length+1,tr=document.createElement('tr');
    tr.innerHTML=`<td><input type="text" name="hw_sno[]" value="${sno}"></td><td><input type="text" name="hw_desc[]" placeholder="Item description"></td><td><input type="number" name="hw_qty[]" value="1" min="0" step="any"></td><td><input type="text" name="hw_unit[]" value="Lot"></td><td><input type="number" name="hw_price[]" value="0" min="0" step="any" class="hw-price" oninput="calcHwAmt(this)"></td><td><input type="text" name="hw_amount[]" readonly class="hw-amt" value="0"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'hwTable')"><i class="fas fa-times"></i></button></td>`;
    tb.appendChild(tr);
}
function addSvcRow() {
    const tb=document.getElementById('svcTable').querySelector('tbody'),sno=15+tb.rows.length+1,tr=document.createElement('tr');
    tr.innerHTML=`<td><input type="text" name="svc_sno[]" value="${sno}"></td><td><input type="text" name="svc_desc[]" placeholder="Service description"></td><td><input type="text" name="svc_make[]" value="Eltrive"></td><td><input type="number" name="svc_qty[]" value="1"></td><td><input type="number" name="svc_amount[]" value="0" min="0" step="any"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'svcTable')"><i class="fas fa-times"></i></button></td>`;
    tb.appendChild(tr);
}
function addCommRow() {
    const tb=document.getElementById('commTable').querySelector('tbody'),sno=tb.rows.length+1,tr=document.createElement('tr');
    tr.innerHTML=`<td><input type="text" name="comm_item[]" value="${sno}"></td><td><input type="text" name="comm_desc[]" placeholder="Item"></td><td><input type="text" name="comm_hsn[]" placeholder="HSN"></td><td><input type="number" name="comm_qty[]" value="1"></td><td><input type="text" name="comm_unit[]" value="Lot"></td><td><input type="number" name="comm_amount[]" value="0" min="0" step="any"></td><td><button type="button" class="btn-del-row" onclick="delRow(this,'commTable')"><i class="fas fa-times"></i></button></td>`;
    tb.appendChild(tr);
}
</script>
</body>
</html>
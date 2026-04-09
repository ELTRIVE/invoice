<?php
require_once dirname(__DIR__, 2) . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// ── Collect POST data ──────────────────────────────────────────────────────────
$d = $_POST;

// Authors
$authors = [];
$roles  = $d['auth_role']  ?? [];
$names  = $d['auth_name']  ?? [];
$depts  = $d['auth_dept']  ?? [];
$emails = $d['auth_email'] ?? [];
$dates  = $d['auth_date']  ?? [];
foreach ($roles as $i => $role) {
    if (trim($role) === '' && trim($names[$i] ?? '') === '') continue;
    $authors[] = [
        'role'  => trim($role),
        'name'  => trim($names[$i]  ?? ''),
        'dept'  => trim($depts[$i]  ?? ''),
        'email' => trim($emails[$i] ?? ''),
        'date'  => trim($dates[$i]  ?? ''),
    ];
}

// Revisions
$revisions = [];
foreach (($d['rev_ver'] ?? []) as $i => $ver) {
    if (trim($ver) === '') continue;
    $revisions[] = [
        'ver'    => trim($ver),
        'prev'   => trim($d['rev_prev'][$i]   ?? ''),
        'date'   => trim($d['rev_date'][$i]   ?? ''),
        'change' => trim($d['rev_change'][$i] ?? ''),
    ];
}

// Hardware rows
$hw_rows = [];
foreach (($d['hw_sno'] ?? []) as $i => $sno) {
    $hw_rows[] = [
        'sno'    => trim($sno),
        'desc'   => trim($d['hw_desc'][$i]   ?? ''),
        'qty'    => trim($d['hw_qty'][$i]    ?? '1'),
        'unit'   => trim($d['hw_unit'][$i]   ?? 'Lot'),
        'price'  => (float)($d['hw_price'][$i] ?? 0),
        'amount' => (float)($d['hw_qty'][$i] ?? 1) * (float)($d['hw_price'][$i] ?? 0),
    ];
}

// Service rows
$svc_rows = [];
foreach (($d['svc_sno'] ?? []) as $i => $sno) {
    $svc_rows[] = [
        'sno'    => trim($sno),
        'desc'   => trim($d['svc_desc'][$i]   ?? ''),
        'make'   => trim($d['svc_make'][$i]   ?? ''),
        'qty'    => trim($d['svc_qty'][$i]    ?? '1'),
        'amount' => (float)($d['svc_amount'][$i] ?? 0),
    ];
}

// Commercials rows
$comm_rows = [];
foreach (($d['comm_item'] ?? []) as $i => $item) {
    $comm_rows[] = [
        'item'   => trim($item),
        'desc'   => trim($d['comm_desc'][$i]   ?? ''),
        'hsn'    => trim($d['comm_hsn'][$i]    ?? ''),
        'qty'    => trim($d['comm_qty'][$i]    ?? '1'),
        'unit'   => trim($d['comm_unit'][$i]   ?? 'Lot'),
        'amount' => (float)($d['comm_amount'][$i] ?? 0),
    ];
}

$data = [
    'project_title'   => trim($d['project_title']   ?? ''),
    'customer_name'   => trim($d['customer_name']   ?? ''),
    'doc_key'         => trim($d['doc_key']          ?? 'ELT-QT-0000V1'),
    'version'         => trim($d['version']          ?? 'V1'),
    'version_desc'    => trim($d['version_desc']     ?? 'Initial Release'),
    'revision'        => trim($d['revision']         ?? '1.0'),
    'doc_date'        => trim($d['doc_date']         ?? date('j-M-y')),
    'company_name'    => trim($d['company_name']     ?? 'ELTRIVE AUTOMATIONS PVT LTD'),
    'company_email'   => trim($d['company_email']    ?? 'automations@eltrive.com'),
    'designed_by'     => trim($d['designed_by']      ?? ''),
    'designed_title'  => trim($d['designed_title']   ?? 'Assistant Manager'),
    'released_by'     => trim($d['released_by']      ?? ''),
    'released_title'  => trim($d['released_title']   ?? 'Automation Lead'),
    'amc_yearly'      => (float)($d['amc_yearly']    ?? 0),
    'total_cost_desc' => trim($d['total_cost_desc']  ?? 'Total Cost: Rs.'),
    'total_cost'      => (float)($d['total_cost']    ?? 0),
    'payment_terms'   => trim($d['payment_terms']    ?? ''),
    'notes'           => trim($d['notes']            ?? ''),
    'authors'         => $authors,
    'revisions'       => $revisions,
    'hw_rows'         => $hw_rows,
    'svc_rows'        => $svc_rows,
    'comm_rows'       => $comm_rows,
];

// ── Write JSON, run Node ───────────────────────────────────────────────────────
$json_path  = '/tmp/tc_data.json';
$output_path = '/tmp/tc_output.docx';
$script_path = __DIR__ . '/tc_generate.js';

file_put_contents($json_path, json_encode($data, JSON_UNESCAPED_UNICODE));

// Remove stale output
if (file_exists($output_path)) unlink($output_path);

$cmd    = "node " . escapeshellarg($script_path) . " 2>&1";
$result = shell_exec($cmd);

if (!file_exists($output_path)) {
    $err = urlencode('DOCX generation failed: ' . substr($result, 0, 200));
    header("Location: index.php?error=$err");
    exit;
}

// ── Serve the file ─────────────────────────────────────────────────────────────
$safe_title = preg_replace('/[^A-Za-z0-9_\-]/', '_', $data['doc_key'] ?: 'TC_Document');
$filename   = $safe_title . '.docx';

// Optionally save to techno_commercial folder
$save_dir = __DIR__ . '/generated/';
if (!is_dir($save_dir)) mkdir($save_dir, 0755, true);
$save_path = $save_dir . $filename;
copy($output_path, $save_path);

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($output_path));
header('Cache-Control: no-cache');
readfile($output_path);
exit;
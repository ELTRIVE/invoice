<?php
// /invoice/techno_commercial/sections.php
error_reporting(0);
ini_set('display_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e['message']]);
        exit;
    }
});

function jsonSuccess($data = [], $message = 'OK'): void {
    echo json_encode(['success' => true, 'data' => $data, 'message' => $message]);
    exit;
}
function jsonError($message = 'Error', $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$dbPath = dirname(__DIR__) . '/db.php';
if (!file_exists($dbPath)) {
    jsonError('db.php not found at: ' . $dbPath, 500);
}
require_once $dbPath;

// Map section_key -> column name in techno_projects
function sectionColumn(string $key): string {
    $map = [
        'overview'      => 'project_overview',
        'benefits'      => 'expected_benefits',
        'scope'         => 'scope_of_work',
        'current'       => 'current_system',
        'proposed'      => 'proposed_solution',
        'utilities'     => 'utilities_covered',
        'architecture'  => 'system_architecture',
        'kpis'          => 'kpis',
        'dashboardfeat' => 'dashboard_features',
        'testing'       => 'testing_commissioning',
        'deliverables'  => 'deliverables',
        'customer'      => 'customer_scope',
        'outscope'      => 'out_of_scope',
        'commercials'   => 'commercials',
        'comsummary'    => 'commercial_summary',
    ];
    return $map[$key] ?? '';
}

$action = $_GET['action'] ?? null;
if (!$action) {
    $body   = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? null;
}

switch ($action) {
    case 'save':   saveSection();   break;
    case 'load':   loadSection();   break;
    case 'delete': deleteSection(); break;
    default:       jsonError('Unknown action: ' . $action);
}

function saveSection(): void {
    global $pdo;
    $rawInput = file_get_contents('php://input');
    $data     = json_decode($rawInput, true);
    if (!$data) jsonError('Invalid JSON payload');

    $projectId  = (int)($data['project_id']  ?? 0);
    $sectionKey = trim($data['section_key']  ?? '');
    $headings   = $data['headings'] ?? [];

    if (!$projectId || !$sectionKey) jsonError('Missing project_id or section_key');

    // Check project exists
    try {
        $chk = $pdo->prepare("SELECT id FROM techno_projects WHERE id=:id");
        $chk->execute([':id' => $projectId]);
        if (!$chk->fetch()) jsonError('Project not found', 404);
    } catch (Exception $e) {
        jsonError('DB error: ' . $e->getMessage(), 500);
    }

    $column = sectionColumn($sectionKey);
    if (!$column) jsonError('Unknown section key: ' . $sectionKey);

    // Store headings as JSON in the matching column
    $content = json_encode($headings, JSON_UNESCAPED_UNICODE);

    try {
        $pdo->prepare("UPDATE techno_projects SET `$column`=:content WHERE id=:id")
            ->execute([':content' => $content, ':id' => $projectId]);
        jsonSuccess(['section_key' => $sectionKey], 'Section saved.');
    } catch (Exception $e) {
        jsonError('Save failed: ' . $e->getMessage(), 500);
    }
}

function loadSection(): void {
    global $pdo;
    $projectId  = (int)($_GET['project_id'] ?? 0);
    $sectionKey = trim($_GET['section_key'] ?? '');

    if (!$projectId || !$sectionKey) jsonError('Missing project_id or section_key');

    $column = sectionColumn($sectionKey);
    if (!$column) jsonError('Unknown section key: ' . $sectionKey);

    try {
        $stmt = $pdo->prepare("SELECT `$column` FROM techno_projects WHERE id=:id");
        $stmt->execute([':id' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) jsonError('Project not found', 404);

        $headings = json_decode($row[$column] ?? '[]', true) ?: [];

        jsonSuccess([
            'section'  => ['project_id' => $projectId, 'section_key' => $sectionKey],
            'headings' => $headings
        ]);
    } catch (Exception $e) {
        jsonError('Load failed: ' . $e->getMessage(), 500);
    }
}

function deleteSection(): void {
    global $pdo;
    $data       = json_decode(file_get_contents('php://input'), true);
    $projectId  = (int)($data['project_id']  ?? 0);
    $sectionKey = trim($data['section_key']  ?? '');

    if (!$projectId || !$sectionKey) jsonError('Missing params');

    $column = sectionColumn($sectionKey);
    if (!$column) jsonError('Unknown section key: ' . $sectionKey);

    try {
        $pdo->prepare("UPDATE techno_projects SET `$column`=NULL WHERE id=:id")
            ->execute([':id' => $projectId]);
        jsonSuccess([], 'Section cleared.');
    } catch (Exception $e) {
        jsonError('Delete failed: ' . $e->getMessage(), 500);
    }
}
<?php
// /invoice/techno_commercial/main_project.php
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

$action = $_GET['action'] ?? null;
if (!$action) {
    $body   = json_decode(file_get_contents('php://input'), true);
    $action = $body['action'] ?? null;
}

switch ($action) {
    case 'save':     saveProject();   break;
    case 'load':     loadProject();   break;
    case 'list':     listProjects();  break;
    case 'delete':   deleteProject(); break;
    case 'next_key': nextDocKey();    break;
    default:         jsonError('Unknown action: ' . $action);
}

// ── Generate next document key: ELT-TC-YYMMNNN (NNN = 3-digit sequence for this YYMM) ──
function nextDocKey(): void {
    global $pdo;
    $prefix = 'ELT-TC-' . date('ym');   // e.g. ELT-TC-2602
    try {
        $stmt = $pdo->prepare(
            "SELECT document_key FROM techno_projects
             WHERE document_key LIKE :pfx
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':pfx' => $prefix . '%']);
        $last = $stmt->fetchColumn();
        $seq  = 1;
        if ($last) {
            // Extract the numeric suffix after the prefix
            $num = intval(substr($last, strlen($prefix)));
            if ($num > 0) $seq = $num + 1;
        }
        $key = $prefix . str_pad($seq, 3, '0', STR_PAD_LEFT);
        jsonSuccess(['document_key' => $key]);
    } catch (Exception $e) {
        jsonError('Key generation failed: ' . $e->getMessage(), 500);
    }
}

function saveProject(): void {
    global $pdo;
    // ── Ensure all required columns exist ──────────────────────────────────
    static $migrated = false;
    if (!$migrated) {
        $migrated = true;
        $cols = [
            'designed_by'         => "VARCHAR(255) DEFAULT ''",
            'designed_by_role'    => "VARCHAR(255) DEFAULT ''",
            'released_by'         => "VARCHAR(255) DEFAULT ''",
            'released_by_role'    => "VARCHAR(255) DEFAULT ''",
            'template_version'    => "VARCHAR(100) DEFAULT ''",
            'footer_document_key' => "VARCHAR(100) DEFAULT ''",
            'company_name'        => "VARCHAR(255) DEFAULT ''",
            'document_title'      => "VARCHAR(255) DEFAULT ''",
            'contact_email'       => "VARCHAR(255) DEFAULT ''",
            'footer_version'      => "VARCHAR(100) DEFAULT ''",
            'page_no'             => "VARCHAR(50)  DEFAULT ''",
            'author_name'         => "VARCHAR(255) DEFAULT ''",
            'author_department'   => "VARCHAR(255) DEFAULT ''",
            'author_email'        => "VARCHAR(255) DEFAULT ''",
            'author_date'         => "DATE DEFAULT NULL",
            'checker_name'        => "VARCHAR(255) DEFAULT ''",
            'checker_department'  => "VARCHAR(255) DEFAULT ''",
            'checker_email'       => "VARCHAR(255) DEFAULT ''",
            'checker_date'        => "DATE DEFAULT NULL",
            'approver_name'       => "VARCHAR(255) DEFAULT ''",
            'approver_department' => "VARCHAR(255) DEFAULT ''",
            'approver_email'      => "VARCHAR(255) DEFAULT ''",
            'approver_date'       => "DATE DEFAULT NULL",
            'revision_history'    => "LONGTEXT DEFAULT NULL",
        ];
        try {
            $existing = array_map('strtolower', array_column(
                $pdo->query("SHOW COLUMNS FROM techno_projects")->fetchAll(PDO::FETCH_ASSOC), 'Field'
            ));
            foreach ($cols as $col => $def) {
                if (!in_array(strtolower($col), $existing))
                    $pdo->exec("ALTER TABLE techno_projects ADD COLUMN `$col` $def");
            }
        } catch (Exception $e) { /* non-fatal */ }
    }

    $rawInput = file_get_contents('php://input');
    $data     = json_decode($rawInput, true);
    if (!$data) jsonError('Invalid JSON payload');

    $h    = $data['header']        ?? [];
    $foot = $data['footer']        ?? [];
    $fd   = $data['footerDetails'] ?? [];
    $id   = (int)($data['id']      ?? 0);

    // Pull approvals from rows
    $approvals  = $data['approvals']  ?? [];
    $author   = $approvals[0] ?? [];
    $checker  = $approvals[1] ?? [];
    $approver = $approvals[2] ?? [];

    // Revisions as JSON
    $revisionHistory = json_encode($data['revisions'] ?? []);

    try {
        if ($id > 0) {
            $pdo->prepare("UPDATE techno_projects SET
                project_name=:pn,
                document_key=:dk,
                version=:ver,
                revision=:rev,
                customer_name=:cust,
                author_name=:aname,
                author_department=:adept,
                author_email=:aemail,
                author_date=:adate,
                checker_name=:cname,
                checker_department=:cdept,
                checker_email=:cemail,
                checker_date=:cdate,
                approver_name=:apname,
                approver_department=:apdept,
                approver_email=:apemail,
                approver_date=:apdate,
                revision_history=:revhist,
                company_name=:co,
                document_title=:title,
                contact_email=:email,
                page_no=:pno,
                footer_version=:fver,
                footer_document_key=:fdk,
                template_version=:tv,
                designed_by=:db,
                designed_by_role=:dbr,
                released_by=:rb,
                released_by_role=:rbr
                WHERE id=:id")
              ->execute([
                ':pn'      => $h['project_name']    ?? '',
                ':dk'      => $h['document_key']    ?? '',
                ':ver'     => $h['version']         ?? '',
                ':rev'     => $h['revision']        ?? '',
                ':cust'    => $h['customer']        ?? '',
                ':aname'   => $author['name']       ?? '',
                ':adept'   => $author['dept']       ?? '',
                ':aemail'  => $author['email']      ?? '',
                ':adate'   => ($author['date']      ?? '') ?: null,
                ':cname'   => $checker['name']      ?? '',
                ':cdept'   => $checker['dept']      ?? '',
                ':cemail'  => $checker['email']     ?? '',
                ':cdate'   => ($checker['date']     ?? '') ?: null,
                ':apname'  => $approver['name']     ?? '',
                ':apdept'  => $approver['dept']     ?? '',
                ':apemail' => $approver['email']    ?? '',
                ':apdate'  => ($approver['date']    ?? '') ?: null,
                ':revhist' => $revisionHistory,
                ':co'      => $foot['company_name'] ?? '',
                ':title'   => $foot['title']        ?? '',
                ':email'   => $foot['contact_email']?? '',
                ':pno'     => $foot['page_no']      ?? '',
                ':fver'    => $foot['version']      ?? '',
                ':fdk'     => $fd['doc_key']        ?? '',
                ':tv'      => $fd['template_ver']   ?? '',
                ':db'      => $fd['designed_by']    ?? '',
                ':dbr'     => $fd['assistant_manager'] ?? '',
                ':rb'      => $fd['released_by']    ?? '',
                ':rbr'     => $fd['automation_lead']?? '',
                ':id'      => $id
            ]);
        } else {
            $pdo->prepare("INSERT INTO techno_projects
                (project_name, document_key, version, revision, customer_name,
                 author_name, author_department, author_email, author_date,
                 checker_name, checker_department, checker_email, checker_date,
                 approver_name, approver_department, approver_email, approver_date,
                 revision_history, company_name, document_title, contact_email,
                 page_no, footer_version, footer_document_key, template_version,
                 designed_by, designed_by_role, released_by, released_by_role)
                VALUES
                (:pn,:dk,:ver,:rev,:cust,
                 :aname,:adept,:aemail,:adate,
                 :cname,:cdept,:cemail,:cdate,
                 :apname,:apdept,:apemail,:apdate,
                 :revhist,:co,:title,:email,
                 :pno,:fver,:fdk,:tv,
                 :db,:dbr,:rb,:rbr)")
              ->execute([
                ':pn'      => $h['project_name']    ?? '',
                ':dk'      => $h['document_key']    ?? '',
                ':ver'     => $h['version']         ?? '',
                ':rev'     => $h['revision']        ?? '',
                ':cust'    => $h['customer']        ?? '',
                ':aname'   => $author['name']       ?? '',
                ':adept'   => $author['dept']       ?? '',
                ':aemail'  => $author['email']      ?? '',
                ':adate'   => ($author['date']      ?? '') ?: null,
                ':cname'   => $checker['name']      ?? '',
                ':cdept'   => $checker['dept']      ?? '',
                ':cemail'  => $checker['email']     ?? '',
                ':cdate'   => ($checker['date']     ?? '') ?: null,
                ':apname'  => $approver['name']     ?? '',
                ':apdept'  => $approver['dept']     ?? '',
                ':apemail' => $approver['email']    ?? '',
                ':apdate'  => ($approver['date']    ?? '') ?: null,
                ':revhist' => $revisionHistory,
                ':co'      => $foot['company_name'] ?? '',
                ':title'   => $foot['title']        ?? '',
                ':email'   => $foot['contact_email']?? '',
                ':pno'     => $foot['page_no']      ?? '',
                ':fver'    => $foot['version']      ?? '',
                ':fdk'     => $fd['doc_key']        ?? '',
                ':tv'      => $fd['template_ver']   ?? '',
                ':db'      => $fd['designed_by']    ?? '',
                ':dbr'     => $fd['assistant_manager'] ?? '',
                ':rb'      => $fd['released_by']    ?? '',
                ':rbr'     => $fd['automation_lead']?? '',
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        jsonSuccess(['id' => $id], 'Project saved successfully.');

    } catch (Exception $e) {
        jsonError('Save failed: ' . $e->getMessage(), 500);
    }
}

function loadProject(): void {
    global $pdo;
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Missing id');
    try {
        $stmt = $pdo->prepare("SELECT * FROM techno_projects WHERE id=:id");
        $stmt->execute([':id' => $id]);
        $project = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$project) jsonError('Project not found', 404);

        // Rebuild approvals array from flat columns
        $approvals = [
            ['role'=>'Prepared By', 'name'=>$project['author_name'],   'dept'=>$project['author_department'],   'email'=>$project['author_email'],   'date'=>$project['author_date']],
            ['role'=>'Checked By',  'name'=>$project['checker_name'],  'dept'=>$project['checker_department'],  'email'=>$project['checker_email'],   'date'=>$project['checker_date']],
            ['role'=>'Approved By', 'name'=>$project['approver_name'], 'dept'=>$project['approver_department'], 'email'=>$project['approver_email'],  'date'=>$project['approver_date']],
        ];

        // Decode revision history
        $revisions = json_decode($project['revision_history'] ?? '[]', true) ?: [];

        jsonSuccess([
            'project'    => $project,
            'approvals'  => $approvals,
            'revisions'  => $revisions,
            'additional' => [],
            'footer'     => [
                'designed_by'       => $project['designed_by']          ?? '',
                'assistant_manager' => $project['designed_by_role']     ?? '',
                'released_by'       => $project['released_by']          ?? '',
                'automation_lead'   => $project['released_by_role']     ?? '',
                'template_ver'      => $project['template_version']     ?? '',
                'doc_key'           => $project['footer_document_key']  ?? '',
            ],
        ]);
    } catch (Exception $e) {
        jsonError('Load failed: ' . $e->getMessage(), 500);
    }
}

function listProjects(): void {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT id, project_name, document_key, customer_name AS customer, version, created_at
            FROM techno_projects ORDER BY created_at DESC");
        jsonSuccess(['projects' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        jsonError('List failed: ' . $e->getMessage(), 500);
    }
}

function deleteProject(): void {
    global $pdo;
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = (int)($data['id'] ?? 0);
    if (!$id) jsonError('Missing id');
    try {
        $pdo->prepare("DELETE FROM techno_projects WHERE id=:id")->execute([':id' => $id]);
        jsonSuccess([], 'Project deleted.');
    } catch (Exception $e) {
        jsonError('Delete failed: ' . $e->getMessage(), 500);
    }
}
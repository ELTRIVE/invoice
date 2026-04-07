<?php
// /invoice/techno_commercial/generate_pdf.php
// Pure PHP .docx generator — ZERO external dependencies.
// Builds OOXML ZIP manually using only core PHP.

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ── DB ────────────────────────────────────────────────────────────────────
$dbPath = dirname(__DIR__) . '/db.php';
if (!file_exists($dbPath)) die('db.php not found');
require_once $dbPath;

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Missing project id');

// ── Ensure all required columns exist (safe migration) ────────────────────
$requiredColumns = [
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
    $existingCols = array_map('strtolower', array_column(
        $pdo->query("SHOW COLUMNS FROM techno_projects")->fetchAll(PDO::FETCH_ASSOC), 'Field'
    ));
    foreach ($requiredColumns as $colName => $colDef) {
        if (!in_array(strtolower($colName), $existingCols)) {
            $pdo->exec("ALTER TABLE techno_projects ADD COLUMN `$colName` $colDef");
        }
    }
} catch (Exception $e) { /* non-fatal */ }

$stmt = $pdo->prepare("SELECT * FROM techno_projects WHERE id=:id");
$stmt->execute([':id' => $id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) die('Project not found');

$revisions = json_decode($p['revision_history'] ?? '[]', true) ?: [];
// ── PDF VIEW MODE (HTML print view) ───────────────────────────────────────
$format = strtolower(trim($_GET['format'] ?? 'word'));

function fmtDateV($d) {
    if (!$d) return '';
    try { return (new DateTime($d))->format('d-M-y'); } catch(Exception $e) { return $d; }
}
function xeV($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

if ($format === 'pdf') {
    // ── Dompdf ────────────────────────────────────────────────────────────
    $dompdfPath = dirname(__DIR__) . '/dompdf/autoload.inc.php';
    if (!file_exists($dompdfPath)) die('Dompdf not found at: ' . $dompdfPath);
    require_once $dompdfPath;

    function hE($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }

    $sectionCols = [
        'project_overview'      => 'Project Overview',
        'expected_benefits'     => 'Expected Benefits & Tentative ROI',
        'scope_of_work'         => 'Project Scope of Work',
        'current_system'        => 'Current System Scenario',
        'proposed_solution'     => 'Proposed Solution',
        'utilities_covered'     => 'Utilities Covered Under Project',
        'system_architecture'   => 'System Architecture',
        'kpis'                  => 'Standard Utility KPIs',
        'dashboard_features'    => 'Dashboard Features',
        'testing_commissioning' => 'Testing & Commissioning',
        'deliverables'          => 'Deliverables',
        'customer_scope'        => 'Customer Scope',
        'out_of_scope'          => 'Out of Scope',
        'commercials'           => 'Commercials',
        'commercial_summary'    => 'Commercial Summary - UMS',
    ];

    // ── Footer data ───────────────────────────────────────────────────────
    $f_designedBy   = $p['designed_by']         ?? '';
    $f_designedRole = $p['designed_by_role']    ?: 'Assistant Manager';
    $f_releasedBy   = $p['released_by']         ?? '';
    $f_releasedRole = $p['released_by_role']    ?: 'Automation Lead';
    $f_templateVer  = $p['template_version']    ?? '';
    $f_docKey       = $p['footer_document_key'] ?: ($p['document_key']  ?? '');
    $f_company      = $p['company_name']         ?: 'ELTRIVE AUTOMATIONS PVT LTD';
    $f_email        = $p['contact_email']        ?: 'automations@eltrive.com';
    $f_title        = $p['document_title']       ?: ($p['project_name'] ?? '');
    $f_version      = $p['footer_version']       ?: ($p['version']      ?? '');

    // ── Build HTML ────────────────────────────────────────────────────────
    // NOTE: No header/footer HTML in body — we use page_script() callback instead.
    // Large top/bottom margins leave space for the drawn header/footer.
    $html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { margin: 75px 40px 110px 40px; }
body  { font-family: "Times New Roman", serif; font-size: 10pt; color: #222; margin:0; padding:0; }

.info-table { width:100%; border-collapse:collapse; margin-bottom:12px; }
.info-table td { padding:4px 8px; border:1px solid #ccc; font-size:9pt; }
.info-table td:first-child { background:#f0f0f0; font-weight:bold; width:130px; }

.data-table { width:100%; border-collapse:collapse; margin-bottom:12px; font-size:9pt; }
.data-table th { background:#444444; color:#fff; padding:5px 8px; text-align:left; -webkit-print-color-adjust:exact; }
.data-table td { padding:4px 8px; border:1px solid #ccc; }
.data-table tr:nth-child(even) td { background:#f9f9f9; }

/* NO page-break-before on sections — content flows naturally, fills each page */
h2.sec  { font-size:11.5pt; font-weight:bold; color:#000000; margin:18px 0 8px; padding-bottom:3px; }
h3.h3   { font-size:10pt; font-weight:bold; color:#000000; margin:10px 0 4px; }
h4.h4   { font-size:9.5pt; font-weight:bold; color:#222; margin:7px 0 3px 14px; }
h5.h5   { font-size:9pt; font-weight:bold; color:#555; margin:5px 0 2px 28px; }
p.d     { font-size:9.5pt; color:#333; line-height:1.55; margin-bottom:5px; }
p.i1    { margin-left:14px; }
p.i2    { margin-left:28px; }
p.i3    { margin-left:42px; }
</style>
</head>
<body>';

    // Cover tables
    $html .= '<table class="info-table">
  <tr><td>Project</td><td>'      . hE($p['project_name'])  . '</td></tr>
  <tr><td>Document Key</td><td>' . hE($p['document_key'])  . '</td></tr>
  <tr><td>Version</td><td>'      . hE($p['version'])        . '</td></tr>
  <tr><td>Revision</td><td>'     . hE($p['revision'])       . '</td></tr>
  <tr><td>Customer</td><td>'     . hE($p['customer_name'])  . '</td></tr>
</table>';

    $html .= '<table class="data-table">
  <thead><tr><th>Role</th><th>Name</th><th>Department</th><th>Email</th><th>Date</th></tr></thead>
  <tbody>
    <tr><td>Prepared By</td><td>' . hE($p['author_name'])   . '</td><td>' . hE($p['author_department'])   . '</td><td>' . hE($p['author_email'])   . '</td><td>' . hE(fmtDateV($p['author_date']))   . '</td></tr>
    <tr><td>Checked By</td><td>'  . hE($p['checker_name'])  . '</td><td>' . hE($p['checker_department'])  . '</td><td>' . hE($p['checker_email'])  . '</td><td>' . hE(fmtDateV($p['checker_date']))  . '</td></tr>
    <tr><td>Approved By</td><td>' . hE($p['approver_name']) . '</td><td>' . hE($p['approver_department']) . '</td><td>' . hE($p['approver_email']) . '</td><td>' . hE(fmtDateV($p['approver_date'])) . '</td></tr>
  </tbody>
</table>';

    if ($revisions) {
        $html .= '<table class="data-table">
  <thead><tr><th>Version</th><th>Previous Ver.</th><th>Date</th><th>Change Content</th></tr></thead>
  <tbody>';
        foreach ($revisions as $r) {
            $html .= '<tr><td>' . hE($r['version']??'') . '</td><td>' . hE($r['previous']??'') . '</td><td>' . hE(fmtDateV($r['date']??'')) . '</td><td>' . hE($r['change']??'') . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }

    // Content sections — NO forced page breaks, content flows naturally
    $globalNum  = 0;
    $sectionNum = 0;
    foreach ($sectionCols as $col => $sectionTitle) {
        $sc = $p[$col] ?? '';
        if (!$sc || $sc === '[]') continue;
        $headings = json_decode($sc, true);
        if (!$headings) continue;
        $sectionNum++;
        $html .= '<h2 class="sec">' . $sectionNum . '. ' . hE($sectionTitle) . '</h2>';
        foreach ($headings as $hd) {
            $globalNum++; $subNum = 0;
            $html .= '<h3 class="h3">' . $globalNum . '. ' . hE($hd['title'] ?? '') . '</h3>';
            if (!empty($hd['description']))
                $html .= '<p class="d i1">' . nl2br(hE($hd['description'])) . '</p>';
            foreach ($hd['subheadings'] ?? [] as $sh) {
                $subNum++; $subsubNum = 0;
                $html .= '<h4 class="h4">' . $globalNum . '.' . $subNum . '. ' . hE($sh['title'] ?? '') . '</h4>';
                if (!empty($sh['description']))
                    $html .= '<p class="d i2">' . nl2br(hE($sh['description'])) . '</p>';
                foreach ($sh['subsubheadings'] ?? [] as $ss) {
                    $subsubNum++;
                    $html .= '<h5 class="h5">' . $globalNum . '.' . $subNum . '.' . $subsubNum . '. ' . hE($ss['title'] ?? '') . '</h5>';
                    if (!empty($ss['description']))
                        $html .= '<p class="d i3">' . nl2br(hE($ss['description'])) . '</p>';
                }
            }
        }
    }

    $html .= '</body></html>';

    // ── Render with Dompdf ────────────────────────────────────────────────
    $options = new Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Times New Roman');

    $dompdf = new Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // ── page_script: draw header + footer on EVERY page via canvas ────────
    // A4 in Dompdf points: 595.28 wide x 841.89 tall
    $canvas = $dompdf->getCanvas();
    $W      = $canvas->get_width();   // ~595
    $H      = $canvas->get_height();  // ~841
    $lm     = 40;  // left margin  (matches @page margin-left: 40px)
    $rm     = 40;  // right margin
    $cW     = $W - $lm - $rm;        // content width

    // Fonts
    $fontNormal = $dompdf->getFontMetrics()->getFont('Times New Roman', 'normal');
    $fontBold   = $dompdf->getFontMetrics()->getFont('Times New Roman', 'bold');

    // Pre-escape strings for canvas text (no HTML entities)
    $t_company      = html_entity_decode(strip_tags($f_company),      ENT_QUOTES, 'UTF-8');
    $t_email        = html_entity_decode(strip_tags($f_email),        ENT_QUOTES, 'UTF-8');
    $t_title        = html_entity_decode(strip_tags($f_title),        ENT_QUOTES, 'UTF-8');
    $t_version      = html_entity_decode(strip_tags($f_version),      ENT_QUOTES, 'UTF-8');
    $t_docKey       = html_entity_decode(strip_tags($f_docKey),       ENT_QUOTES, 'UTF-8');
    $t_templateVer  = html_entity_decode(strip_tags($f_templateVer),  ENT_QUOTES, 'UTF-8');
    $t_designedBy   = html_entity_decode(strip_tags($f_designedBy),   ENT_QUOTES, 'UTF-8');
    $t_designedRole = html_entity_decode(strip_tags($f_designedRole), ENT_QUOTES, 'UTF-8');
    $t_releasedBy   = html_entity_decode(strip_tags($f_releasedBy),   ENT_QUOTES, 'UTF-8');
    $t_releasedRole = html_entity_decode(strip_tags($f_releasedRole), ENT_QUOTES, 'UTF-8');

    // ── Resolve logo path for PDF canvas ─────────────────────────────────
    $pdfLogoPath = '';
    foreach ([
        'C:/xampp/htdocs/invoice/assets/eltrive-logo.png',
        dirname(__DIR__) . '/assets/eltrive-logo.png',
        __DIR__ . '/../assets/eltrive-logo.png',
        $_SERVER['DOCUMENT_ROOT'] . '/invoice/assets/eltrive-logo.png',
    ] as $tryPath) {
        if (file_exists($tryPath)) { $pdfLogoPath = $tryPath; break; }
    }

    $canvas->page_script(function($pageNum, $pageCount, $canvas, $fontMetrics)
        use ($W, $H, $lm, $rm, $cW,
             $fontNormal, $fontBold, $pdfLogoPath,
             $t_company, $t_email, $t_title, $t_version, $t_docKey,
             $t_templateVer, $t_designedBy, $t_designedRole,
             $t_releasedBy,  $t_releasedRole) {

        $green  = [0.239, 0.729, 0.435]; // #3dba6f
        $black  = [0, 0, 0];
        $white  = [1, 1, 1];
        $gray   = [0.94, 0.94, 0.94];
        $dgray  = [0.33, 0.33, 0.33];

        // ══════════════════════════════════════════════════════════════════
        // HEADER  (top of page)
        // ══════════════════════════════════════════════════════════════════
        $hTop = 8;   // header starts 8pt from top
        $hH   = 46;  // header block height

        // White background
        $canvas->filled_rectangle($lm, $hTop, $cW, $hH, $white);

        // Logo image — rendered in original colors (green)
        if ($pdfLogoPath) {
            // logo: ~90pt wide x 30pt tall to match Word proportions
            $canvas->image($pdfLogoPath, $lm, $hTop + 2, 90, 30, 'png');
        }

        // "Automations" italic below logo
        $fontItalic = $fontMetrics->getFont('Times New Roman', 'italic') ?: $fontNormal;
        $canvas->text($lm, $hTop + 34, 'Automations', $fontItalic, 8, [0.27, 0.27, 0.27]);

        // Document title — centered across full content width, normal weight
        $titleW = $fontMetrics->getTextWidth($t_title, $fontNormal, 11);
        $titleX = $lm + ($cW / 2) - ($titleW / 2);
        $canvas->text($titleX, $hTop + 16, $t_title, $fontNormal, 11, $black);

        // Thin black bottom border line of header
        $canvas->line($lm, $hTop + $hH, $lm + $cW, $hTop + $hH, $black, 0.5);

        // ══════════════════════════════════════════════════════════════════
        // FOOTER  (bottom of page) — 4-row table matching reference image
        // ══════════════════════════════════════════════════════════════════
        $rowH   = 16;   // height of each row
        $fRows  = 4;
        $fTotalH = $rowH * $fRows;
        $fTop   = $H - $fTotalH - 10;  // footer starts this far from bottom

        // Thin black top border line of footer
        $canvas->line($lm, $fTop - 2, $lm + $cW, $fTop - 2, $black, 0.5);

        // Column widths (proportional, total = $cW)
        $c0 = $cW * 0.12;  // "Designed by" label
        $c1 = $cW * 0.23;  // name value
        $c2 = $cW * 0.22;  // role value
        $c3 = $cW * 0.22;  // label (Template Ver / Doc Key / Title / Version)
        $c4 = $cW * 0.21;  // value

        // Draw outer border
        $canvas->rectangle($lm, $fTop, $cW, $fTotalH, $black, 0.5);

        // Helper: draw cell border (right + bottom)
        $drawCell = function($x, $y, $w, $h) use ($canvas, $black) {
            $canvas->line($x + $w, $y,      $x + $w, $y + $h, $black, 0.3); // right
            $canvas->line($x,      $y + $h, $x + $w, $y + $h, $black, 0.3); // bottom
        };

        // Helper: text in cell with left padding
        $cellText = function($x, $y, $h, $text, $bold = false, $sz = 7) use ($canvas, $fontNormal, $fontBold, $black) {
            $font = $bold ? $fontBold : $fontNormal;
            $canvas->text($x + 3, $y + ($h - $sz) / 2 + 1, $text, $font, $sz, $black);
        };

        // ── ROW 0: Designed by | name | role | Template Ver: | value ──────
        $y0 = $fTop;
        $x  = $lm;
        $drawCell($x, $y0, $c0, $rowH); $cellText($x, $y0, $rowH, 'Designed by', false);          $x += $c0;
        $drawCell($x, $y0, $c1, $rowH); $cellText($x, $y0, $rowH, $t_designedBy);                 $x += $c1;
        $drawCell($x, $y0, $c2, $rowH); $cellText($x, $y0, $rowH, $t_designedRole);               $x += $c2;
        $drawCell($x, $y0, $c3, $rowH); $cellText($x, $y0, $rowH, 'Template Ver:',  false);        $x += $c3;
        $drawCell($x, $y0, $c4, $rowH); $cellText($x, $y0, $rowH, $t_templateVer);

        // ── ROW 1: Released by | name | role | Document Key: | value ──────
        $y1 = $fTop + $rowH;
        $x  = $lm;
        $drawCell($x, $y1, $c0, $rowH); $cellText($x, $y1, $rowH, 'Released by', false);          $x += $c0;
        $drawCell($x, $y1, $c1, $rowH); $cellText($x, $y1, $rowH, $t_releasedBy);                 $x += $c1;
        $drawCell($x, $y1, $c2, $rowH); $cellText($x, $y1, $rowH, $t_releasedRole);               $x += $c2;
        $drawCell($x, $y1, $c3, $rowH); $cellText($x, $y1, $rowH, 'Document Key:', false);         $x += $c3;
        $drawCell($x, $y1, $c4, $rowH); $cellText($x, $y1, $rowH, $t_docKey);

        // ── ROW 2: Company+email (span c0+c1) | Title (span c2+c3) | empty
        $y2 = $fTop + $rowH * 2;
        $x  = $lm;
        $spanA = $c0 + $c1;
        $spanB = $c2 + $c3;
        $drawCell($x,           $y2, $spanA, $rowH); $cellText($x,           $y2, $rowH, $t_company, true, 7.5); $x += $spanA;
        $drawCell($x,           $y2, $spanB, $rowH); $cellText($x,           $y2, $rowH, 'Title: ' . $t_title, true, 7); $x += $spanB;
        $drawCell($x,           $y2, $c4,    $rowH); // empty cell

        // ── ROW 3: Company+email row2 (span, email) | Version | Page no ───
        $y3 = $fTop + $rowH * 3;
        $x  = $lm;
        $drawCell($x,    $y3, $spanA, $rowH); $cellText($x, $y3, $rowH, $t_email, false, 6.5);    $x += $spanA;
        $drawCell($x,    $y3, $spanB, $rowH); $cellText($x, $y3, $rowH, 'Version: ' . $t_version, false, 7); $x += $spanB;
        $drawCell($x,    $y3, $c4,    $rowH); $cellText($x, $y3, $rowH, 'Page no: ' . $pageNum, false, 7);
    });

    // ── Stream as direct download ─────────────────────────────────────────
    $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $p['project_name'] ?? 'project');
    $dompdf->stream('TC_' . $safeName . '_' . date('Ymd') . '.pdf', ['Attachment' => true]);
    exit;
}



// ── THEME ─────────────────────────────────────────────────────────────────
define('C_GREEN',  '3DBA6F');
define('C_GREEND', '2A9555');
define('C_LGRAY',  'F0F0F0');
define('C_WHITE',  'FFFFFF');
define('C_TEXT',   '222222');
define('C_MUTED',  '6B7280');

// ── HELPERS ───────────────────────────────────────────────────────────────
function xe(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function fmtDate($d): string {
    if (!$d) return '';
    if (preg_match('/[a-zA-Z]/', $d)) return $d;
    try { return (new DateTime($d))->format('d-M-y'); }
    catch (Exception $e) { return $d; }
}

// ══════════════════════════════════════════════════════════════════════════
// PURE PHP ZIP WRITER
// ══════════════════════════════════════════════════════════════════════════
class PureZip {
    private string $data   = '';
    private array  $index  = [];
    private int    $offset = 0;

    public function addFile(string $name, string $content): void {
        $crc        = crc32($content);
        $size       = strlen($content);
        $compressed = gzdeflate($content, 6);
        $csize      = strlen($compressed);
        $method     = 8;

        $local  = pack('V', 0x04034b50);
        $local .= pack('v', 20);
        $local .= pack('v', 0);
        $local .= pack('v', $method);
        $local .= pack('v', 0);
        $local .= pack('v', 0);
        $local .= pack('V', $crc);
        $local .= pack('V', $csize);
        $local .= pack('V', $size);
        $local .= pack('v', strlen($name));
        $local .= pack('v', 0);
        $local .= $name;
        $local .= $compressed;

        $this->index[] = [
            'name'   => $name,
            'crc'    => $crc,
            'size'   => $size,
            'csize'  => $csize,
            'method' => $method,
            'offset' => $this->offset,
        ];

        $this->data   .= $local;
        $this->offset += strlen($local);
    }

    public function build(): string {
        $cdOffset = $this->offset;
        $cd = '';
        foreach ($this->index as $e) {
            $cd .= pack('V', 0x02014b50);
            $cd .= pack('v', 20);
            $cd .= pack('v', 20);
            $cd .= pack('v', 0);
            $cd .= pack('v', $e['method']);
            $cd .= pack('v', 0);
            $cd .= pack('v', 0);
            $cd .= pack('V', $e['crc']);
            $cd .= pack('V', $e['csize']);
            $cd .= pack('V', $e['size']);
            $cd .= pack('v', strlen($e['name']));
            $cd .= pack('v', 0);
            $cd .= pack('v', 0);
            $cd .= pack('v', 0);
            $cd .= pack('v', 0);
            $cd .= pack('V', 0);
            $cd .= pack('V', $e['offset']);
            $cd .= $e['name'];
        }
        $cdSize = strlen($cd);
        $count  = count($this->index);

        $eocd  = pack('V', 0x06054b50);
        $eocd .= pack('v', 0);
        $eocd .= pack('v', 0);
        $eocd .= pack('v', $count);
        $eocd .= pack('v', $count);
        $eocd .= pack('V', $cdSize);
        $eocd .= pack('V', $cdOffset);
        $eocd .= pack('v', 0);

        return $this->data . $cd . $eocd;
    }
}

// ══════════════════════════════════════════════════════════════════════════
// DOCX XML PARTS
// ══════════════════════════════════════════════════════════════════════════

function contentTypes(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Default Extension="png"  ContentType="image/png"/>
  <Override PartName="/word/document.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
  <Override PartName="/word/styles.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>
  <Override PartName="/word/settings.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>
  <Override PartName="/word/header1.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>
  <Override PartName="/word/footer1.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>
</Types>';
}

function rootRels(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1"
    Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="word/document.xml"/>
</Relationships>';
}

function wordRels(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"   Target="styles.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings"  Target="settings.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header"    Target="header1.xml"/>
  <Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer"    Target="footer1.xml"/>
</Relationships>';
}

// header1.xml relationships: rId1=logo, rId2=watermark
function headerRels(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/eltrive-logo.png"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/watermark.png"/>
</Relationships>';
}

// footer1.xml relationships (empty but required by Word)
function footerRels(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
</Relationships>';
}

function settings(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:defaultTabStop w:val="720"/>
</w:settings>';
}

function styles(): string {
    $g  = C_GREEN;
    $gd = C_GREEND;
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
  <w:docDefaults>
    <w:rPrDefault><w:rPr>
      <w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/>
      <w:sz w:val="18"/><w:szCs w:val="18"/>
      <w:color w:val="222222"/>
    </w:rPr></w:rPrDefault>
    <w:pPrDefault><w:pPr>
      <w:spacing w:after="0" w:line="240" w:lineRule="auto"/>
    </w:pPr></w:pPrDefault>
  </w:docDefaults>
  <w:style w:type="paragraph" w:styleId="Normal"><w:name w:val="Normal"/></w:style>
  <w:style w:type="table" w:styleId="TableGrid">
    <w:name w:val="Table Grid"/>
    <w:tblPr><w:tblBorders>
      <w:top    w:val="single" w:sz="4" w:color="000000"/>
      <w:left   w:val="single" w:sz="4" w:color="000000"/>
      <w:bottom w:val="single" w:sz="4" w:color="000000"/>
      <w:right  w:val="single" w:sz="4" w:color="000000"/>
      <w:insideH w:val="single" w:sz="4" w:color="000000"/>
      <w:insideV w:val="single" w:sz="4" w:color="000000"/>
    </w:tblBorders></w:tblPr>
  </w:style>
</w:styles>';
}

// ── XML PRIMITIVES ────────────────────────────────────────────────────────────

function tblPrXml(int $w, bool $center = false): string {
    $jc = $center ? '<w:jc w:val="center"/>' : '';
    return "<w:tblPr>
      <w:tblStyle w:val=\"TableGrid\"/>
      <w:tblW w:w=\"{$w}\" w:type=\"dxa\"/>
      {$jc}
      <w:tblBorders>
        <w:top    w:val=\"single\" w:sz=\"4\" w:color=\"000000\"/>
        <w:left   w:val=\"single\" w:sz=\"4\" w:color=\"000000\"/>
        <w:bottom w:val=\"single\" w:sz=\"4\" w:color=\"000000\"/>
        <w:right  w:val=\"single\" w:sz=\"4\" w:color=\"000000\"/>
        <w:insideH w:val=\"single\" w:sz=\"4\" w:color=\"000000\"/>
        <w:insideV w:val=\"single\" w:sz=\"4\" w:color=\"000000\"/>
      </w:tblBorders>
      <w:tblCellMar>
        <w:top    w:w=\"80\"  w:type=\"dxa\"/>
        <w:left   w:w=\"120\" w:type=\"dxa\"/>
        <w:bottom w:w=\"80\"  w:type=\"dxa\"/>
        <w:right  w:w=\"120\" w:type=\"dxa\"/>
      </w:tblCellMar>
    </w:tblPr>";
}

function tcPrXml(int $w, string $fill = ''): string {
    $shd = $fill ? "<w:shd w:val=\"clear\" w:color=\"auto\" w:fill=\"{$fill}\"/>" : '';
    return "<w:tcPr>
      <w:tcW w:w=\"{$w}\" w:type=\"dxa\"/>
      <w:tcBorders>
        <w:top    w:val=\"single\" w:sz=\"4\" w:color=\"000000\"/>
        <w:left   w:val=\"single\" w:sz=\"4\" w:color=\"000000\"/>
        <w:bottom w:val=\"single\" w:sz=\"4\" w:color=\"000000\"/>
        <w:right  w:val=\"single\" w:sz=\"4\" w:color=\"000000\"/>
      </w:tcBorders>
      {$shd}
      <w:tcMar>
        <w:top    w:w=\"80\"  w:type=\"dxa\"/>
        <w:left   w:w=\"120\" w:type=\"dxa\"/>
        <w:bottom w:w=\"80\"  w:type=\"dxa\"/>
        <w:right  w:w=\"120\" w:type=\"dxa\"/>
      </w:tcMar>
    </w:tcPr>";
}

function cellP(string $text, bool $bold = false, string $color = '', int $sz = 18): string {
    $rPr = ($bold ? '<w:b/>' : '') . ($color ? "<w:color w:val=\"{$color}\"/>" : '')
         . "<w:sz w:val=\"{$sz}\"/><w:szCs w:val=\"{$sz}\"/>";
    return "<w:p><w:pPr><w:spacing w:after=\"0\"/></w:pPr>"
         . "<w:r><w:rPr>{$rPr}</w:rPr>"
         . "<w:t xml:space=\"preserve\">" . xe($text) . "</w:t></w:r></w:p>";
}

function cellPLines(string $text, bool $bold = false, int $sz = 14): string {
    $lines = explode("\n", $text);
    $rPr   = ($bold ? '<w:b/>' : '') . "<w:sz w:val=\"{$sz}\"/><w:szCs w:val=\"{$sz}\"/>";
    $runs  = '';
    foreach ($lines as $i => $line) {
        if ($i > 0) $runs .= "<w:r><w:rPr>{$rPr}</w:rPr><w:br/></w:r>";
        $runs .= "<w:r><w:rPr>{$rPr}</w:rPr><w:t xml:space=\"preserve\">" . xe($line) . "</w:t></w:r>";
    }
    return "<w:p><w:pPr><w:spacing w:after=\"0\"/></w:pPr>{$runs}</w:p>";
}

function bodyPara(string $text, array $o = []): string {
    $bold  = $o['bold']  ?? false;
    $color = $o['color'] ?? '';
    $sz    = $o['sz']    ?? 18;
    $fill  = $o['fill']  ?? '';
    $spB   = $o['spB']   ?? '';
    $spA   = $o['spA']   ?? '';
    $indL  = $o['indL']  ?? '';
    $align = $o['align'] ?? '';

    $pPrInner = '';
    if ($align) $pPrInner .= "<w:jc w:val=\"{$align}\"/>";
    if ($fill)  $pPrInner .= "<w:shd w:val=\"clear\" w:color=\"auto\" w:fill=\"{$fill}\"/>";
    $sp = '';
    if ($spB !== '' || $spA !== '') {
        $sp .= '<w:spacing';
        if ($spB !== '') $sp .= " w:before=\"{$spB}\"";
        if ($spA !== '') $sp .= " w:after=\"{$spA}\"";
        $sp .= '/>';
    }
    if ($indL) $sp .= "<w:ind w:left=\"{$indL}\"/>";
    $pPr = "<w:pPr>{$pPrInner}{$sp}</w:pPr>";

    $rPr = ($bold ? '<w:b/>' : '') . ($color ? "<w:color w:val=\"{$color}\"/>" : '')
         . "<w:sz w:val=\"{$sz}\"/><w:szCs w:val=\"{$sz}\"/>";

    return "<w:p>{$pPr}<w:r><w:rPr>{$rPr}</w:rPr>"
         . "<w:t xml:space=\"preserve\">" . xe($text) . "</w:t></w:r></w:p>";
}

function emptyP(int $after = 80): string {
    return "<w:p><w:pPr><w:spacing w:after=\"{$after}\"/></w:pPr></w:p>";
}

function pageBreakXml(): string {
    return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
}

function tcNoBorder(int $w, string $vAlign = ''): string {
    $va = $vAlign ? "<w:vAlign w:val=\"{$vAlign}\"/>" : '';
    return "<w:tcPr><w:tcW w:w=\"{$w}\" w:type=\"dxa\"/>
      <w:tcBorders>
        <w:top    w:val=\"nil\"/>
        <w:left   w:val=\"nil\"/>
        <w:bottom w:val=\"nil\"/>
        <w:right  w:val=\"nil\"/>
      </w:tcBorders>{$va}</w:tcPr>";
}

// ── HEADER ────────────────────────────────────────────────────────────────
function buildHeader(array $p): string {
    $g     = C_GREEN;
    $title = xe($p['document_title'] ?: $p['project_name'] ?? '');

    // Logo inline: ~1.8 inch wide x 0.6 inch tall  (1EMU=1/914400 inch)
    // 1.8" = 1638720 EMU wide, 0.6" = 548640 EMU tall
    $logoCx = 1638720;
    $logoCy = 548640;

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
       xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
       xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
       xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
       xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"
       xmlns:v="urn:schemas-microsoft-com:vml"
       xmlns:o="urn:schemas-microsoft-com:office:office"
       xmlns:w10="urn:schemas-microsoft-com:office:word">

  <!-- WATERMARK: absolute positioned, centered on page, behind text -->
  <w:p>
    <w:pPr><w:spacing w:after="0"/></w:pPr>
    <w:r>
      <w:rPr><w:noProof/></w:rPr>
      <w:pict>
        <v:shapetype id="_x0000_t75" coordsize="21600,21600" o:spt="75" o:preferrelative="t"
          path="m@4@5l@4@11@9@11@9@5xe" filled="f" stroked="f">
          <v:stroke joinstyle="miter"/>
          <v:formulas>
            <v:f eqn="if lineDrawn pixelLineWidth 0"/>
            <v:f eqn="sum @0 1 0"/>
            <v:f eqn="sum 0 0 @1"/>
            <v:f eqn="prod @2 1 2"/>
            <v:f eqn="prod @3 21600 pixelWidth"/>
            <v:f eqn="prod @3 21600 pixelHeight"/>
            <v:f eqn="sum @0 0 1"/>
            <v:f eqn="prod @6 1 2"/>
            <v:f eqn="prod @7 21600 pixelWidth"/>
            <v:f eqn="sum @8 21600 0"/>
            <v:f eqn="prod @7 21600 pixelHeight"/>
            <v:f eqn="sum @10 21600 0"/>
          </v:formulas>
          <v:path o:extrusionok="f" gradientshapeok="t" o:connecttype="rect"/>
          <o:lock v:ext="edit" aspectratio="t"/>
        </v:shapetype>
        <v:shape id="WatermarkShape" o:spid="_x0000_s1026" type="#_x0000_t75"
          style="position:absolute;margin-left:0;margin-top:0;width:204pt;height:225pt;
                 z-index:-251657216;
                 mso-position-horizontal:center;mso-position-horizontal-relative:margin;
                 mso-position-vertical:center;mso-position-vertical-relative:margin"
          o:allowincell="f">
          <v:imagedata r:id="rId2" o:title="watermark" gain="19661f" blacklevel="22938f"/>
          <w10:wrap anchorx="margin" anchory="margin"/>
        </v:shape>
      </w:pict>
    </w:r>
  </w:p>

  <!-- HEADER TABLE: logo left | title truly centered across full width -->
  <w:tbl>
    <w:tblPr>
      <w:tblW w:w="10488" w:type="dxa"/>
      <w:tblLook w:val="0000"/>
      <w:tblBorders>
        <w:top    w:val="nil"/>
        <w:left   w:val="nil"/>
        <w:bottom w:val="nil"/>
        <w:right  w:val="nil"/>
        <w:insideH w:val="nil"/>
        <w:insideV w:val="nil"/>
      </w:tblBorders>
      <w:tblCellMar>
        <w:top    w:w="0" w:type="dxa"/>
        <w:left   w:w="0" w:type="dxa"/>
        <w:bottom w:w="0" w:type="dxa"/>
        <w:right  w:w="0" w:type="dxa"/>
      </w:tblCellMar>
    </w:tblPr>
    <w:tblGrid>
      <w:gridCol w:w="3200"/>
      <w:gridCol w:w="4088"/>
      <w:gridCol w:w="3200"/>
    </w:tblGrid>
    <w:tr>
      <!-- LEFT: Logo image + "Automations" italic subtitle below -->
      <w:tc>' . tcNoBorder(3200) . '
        <w:p><w:pPr><w:spacing w:after="0" w:before="0"/></w:pPr>
          <w:r><w:rPr><w:noProof/></w:rPr>
            <w:drawing>
              <wp:inline distT="0" distB="0" distL="0" distR="0">
                <wp:extent cx="' . $logoCx . '" cy="' . $logoCy . '"/>
                <wp:effectExtent l="0" t="0" r="0" b="0"/>
                <wp:docPr id="1" name="EltriveLogoHeader"/>
                <wp:cNvGraphicFramePr>
                  <a:graphicFrameLocks noChangeAspect="1"/>
                </wp:cNvGraphicFramePr>
                <a:graphic>
                  <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
                    <pic:pic>
                      <pic:nvPicPr>
                        <pic:cNvPr id="1" name="EltriveLogoHeader"/>
                        <pic:cNvPicPr><a:picLocks noChangeAspect="1" noChangeArrowheads="1"/></pic:cNvPicPr>
                      </pic:nvPicPr>
                      <pic:blipFill>
                        <a:blip r:embed="rId1"/>
                        <a:stretch><a:fillRect/></a:stretch>
                      </pic:blipFill>
                      <pic:spPr>
                        <a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $logoCx . '" cy="' . $logoCy . '"/></a:xfrm>
                        <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>
                        <a:noFill/>
                      </pic:spPr>
                    </pic:pic>
                  </a:graphicData>
                </a:graphic>
              </wp:inline>
            </w:drawing>
          </w:r>
        </w:p>
        <!-- "Automations" italic subtitle -->
        <w:p><w:pPr><w:spacing w:after="0" w:before="20"/></w:pPr>
          <w:r><w:rPr>
            <w:i/><w:iCs/>
            <w:sz w:val="18"/><w:szCs w:val="18"/>
            <w:color w:val="444444"/>
            <w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/>
          </w:rPr>
            <w:t>Automations</w:t>
          </w:r>
        </w:p>
      </w:tc>
      <!-- CENTER: Document title — centered across remaining width, text-align center -->
      <w:tc>' . tcNoBorder(4088, 'center') . '
        <w:p><w:pPr><w:jc w:val="center"/><w:spacing w:after="0"/><w:ind w:left="-3200" w:right="-3200"/></w:pPr>
          <w:r><w:rPr>
            <w:sz w:val="22"/><w:szCs w:val="22"/>
            <w:color w:val="000000"/>
            <w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/>
          </w:rPr>
            <w:t>' . $title . '</w:t>
          </w:r>
        </w:p>
      </w:tc>
      <!-- RIGHT: empty spacer -->
      <w:tc>' . tcNoBorder(3200) . '
        <w:p><w:pPr><w:spacing w:after="0"/></w:pPr></w:p>
      </w:tc>
    </w:tr>
  </w:tbl>
  <!-- Thin line below header -->
  <w:p>
    <w:pPr><w:spacing w:after="0" w:before="20"/>
      <w:pBdr><w:bottom w:val="single" w:sz="4" w:color="000000" w:space="1"/></w:pBdr>
    </w:pPr>
  </w:p>
</w:hdr>';
}

// ── FOOTER ────────────────────────────────────────────────────────────────
// Reference: 5 cols (1285+2538+2442+2377+2172=10814), 4 rows
// Row1: Designed by | name       | Assistant Manager | Template Ver: | Rev:x.x
// Row2: Released by | name       | Automation Lead   | Document Key: | ELT-xx
// Row3: COMPANY+email (span2)    | Title (span2)                     | [empty merged]
// Row4: [merge]                  | Version (span2)                   | Page no: N
function buildFooter(array $p): string {
    $g = C_GREEN;

    $c = [1260, 2480, 2388, 2320, 2040]; // total=10488, matches header/page usable width

    $designedBy   = xe($p['designed_by']        ?? '');
    $designedRole = xe($p['designed_by_role']    ?? 'Assistant Manager');
    $releasedBy   = xe($p['released_by']        ?? '');
    $releasedRole = xe($p['released_by_role']   ?? 'Automation Lead');
    $templateVer  = xe($p['template_version']   ?? '');
    $docKey       = xe($p['footer_document_key'] ?? '');
    $company      = xe($p['company_name']        ?? 'ELTRIVE AUTOMATIONS PVT LTD');
    $email        = xe($p['contact_email']       ?? 'automations@eltrive.com');
    $title        = xe($p['document_title']      ?: ($p['project_name'] ?? ''));
    $version      = xe($p['footer_version']      ?: ($p['version']      ?? ''));

    $gridCols = implode('', array_map(fn($w) => "<w:gridCol w:w=\"{$w}\"/>", $c));
    $TW = array_sum($c); // 10814

    // Helper: simple cell WITH vertical center
    $tc = fn(int $w, string $xml) =>
        "<w:tc><w:tcPr><w:tcW w:w=\"{$w}\" w:type=\"dxa\"/><w:vAlign w:val=\"center\"/></w:tcPr>{$xml}</w:tc>";

    // Helper: span cell WITH vertical center
    $tcSpan = fn(int $w, int $span, string $xml) =>
        "<w:tc><w:tcPr><w:tcW w:w=\"{$w}\" w:type=\"dxa\"/><w:gridSpan w:val=\"{$span}\"/><w:vAlign w:val=\"center\"/></w:tcPr>{$xml}</w:tc>";

    // Helper: vMerge restart cell WITH vertical center
    $tcMergeStart = fn(int $w, int $span, string $xml) =>
        "<w:tc><w:tcPr><w:tcW w:w=\"{$w}\" w:type=\"dxa\"/><w:gridSpan w:val=\"{$span}\"/><w:vMerge w:val=\"restart\"/><w:vAlign w:val=\"center\"/></w:tcPr>{$xml}</w:tc>";

    // Helper: vMerge continue cell (empty)
    $tcMergeCont = fn(int $w, int $span) =>
        "<w:tc><w:tcPr><w:tcW w:w=\"{$w}\" w:type=\"dxa\"/><w:gridSpan w:val=\"{$span}\"/><w:vMerge/><w:vAlign w:val=\"center\"/></w:tcPr><w:p><w:pPr><w:spacing w:after=\"0\"/></w:pPr></w:p></w:tc>";

    // Cell paragraph helper — vertically centered, consistent left padding
    $p9 = fn(string $txt, bool $bold = false, int $sz = 18, string $extra = '') =>
        "<w:p><w:pPr><w:spacing w:before=\"0\" w:after=\"0\"/><w:ind w:left=\"60\"/>{$extra}</w:pPr>"
        . "<w:r><w:rPr>" . ($bold ? '<w:b/>' : '') . "<w:rFonts w:ascii=\"Times New Roman\" w:hAnsi=\"Times New Roman\" w:cs=\"Times New Roman\"/>"
        . "<w:sz w:val=\"{$sz}\"/><w:szCs w:val=\"{$sz}\"/></w:rPr>"
        . "<w:t xml:space=\"preserve\">{$txt}</w:t></w:r></w:p>";

    // Row 1: Designed by | name | Assistant Manager | Template Ver: | Rev value
    $row1 = '<w:tr><w:trPr><w:trHeight w:hRule="exact" w:val="280"/></w:trPr>'
        . $tc($c[0], $p9('Designed by'))
        . $tc($c[1], $p9($designedBy))
        . $tc($c[2], $p9($designedRole ?: 'Assistant Manager'))
        . $tc($c[3], $p9('Template Ver:'))
        . $tc($c[4], $p9($templateVer))
        . '</w:tr>';

    // Row 2: Released by | name | Automation Lead | Document Key: | doc key value
    $row2 = '<w:tr><w:trPr><w:trHeight w:hRule="exact" w:val="280"/></w:trPr>'
        . $tc($c[0], $p9('Released by'))
        . $tc($c[1], $p9($releasedBy))
        . $tc($c[2], $p9($releasedRole ?: 'Automation Lead'))
        . $tc($c[3], $p9('Document Key:'))
        . $tc($c[4], $p9($docKey))
        . '</w:tr>';

    // Row 3: COMPANY (span2, vMerge restart) | Title (span2) | Page no (with PAGE field)
    $companyXml =
        "<w:p><w:pPr><w:spacing w:before=\"0\" w:after=\"0\"/><w:ind w:left=\"60\"/></w:pPr>"
        . "<w:r><w:rPr><w:b/><w:rFonts w:ascii=\"Times New Roman\" w:hAnsi=\"Times New Roman\" w:cs=\"Times New Roman\"/><w:sz w:val=\"20\"/><w:szCs w:val=\"20\"/></w:rPr>"
        . "<w:t>{$company}</w:t></w:r></w:p>"
        . "<w:p><w:pPr><w:spacing w:before=\"0\" w:after=\"0\"/><w:ind w:left=\"60\"/></w:pPr>"
        . "<w:r><w:rPr><w:rFonts w:ascii=\"Times New Roman\" w:hAnsi=\"Times New Roman\" w:cs=\"Times New Roman\"/><w:sz w:val=\"16\"/><w:szCs w:val=\"16\"/></w:rPr>"
        . "<w:t>{$email}</w:t></w:r></w:p>";

    $titleXml =
        "<w:p><w:pPr><w:spacing w:before=\"0\" w:after=\"0\"/><w:ind w:left=\"60\"/></w:pPr>"
        . "<w:r><w:rPr><w:b/><w:rFonts w:ascii=\"Times New Roman\" w:hAnsi=\"Times New Roman\" w:cs=\"Times New Roman\"/><w:sz w:val=\"18\"/><w:szCs w:val=\"18\"/></w:rPr>"
        . "<w:t xml:space=\"preserve\">Title: {$title}</w:t></w:r></w:p>";

    $pageXml =
        "<w:p><w:pPr><w:spacing w:before=\"0\" w:after=\"0\"/><w:ind w:left=\"60\"/></w:pPr>"
        . "<w:r><w:rPr><w:rFonts w:ascii=\"Times New Roman\" w:hAnsi=\"Times New Roman\" w:cs=\"Times New Roman\"/><w:sz w:val=\"18\"/><w:szCs w:val=\"18\"/></w:rPr>"
        . "<w:t xml:space=\"preserve\">Page no:  </w:t></w:r>"
        . "<w:r><w:rPr><w:rFonts w:ascii=\"Times New Roman\" w:hAnsi=\"Times New Roman\" w:cs=\"Times New Roman\"/><w:sz w:val=\"18\"/><w:szCs w:val=\"18\"/></w:rPr><w:fldChar w:fldCharType=\"begin\"/></w:r>"
        . "<w:r><w:rPr><w:rFonts w:ascii=\"Times New Roman\" w:hAnsi=\"Times New Roman\" w:cs=\"Times New Roman\"/><w:sz w:val=\"18\"/><w:szCs w:val=\"18\"/></w:rPr><w:instrText xml:space=\"preserve\"> PAGE \\* MERGEFORMAT </w:instrText></w:r>"
        . "<w:r><w:rPr><w:rFonts w:ascii=\"Times New Roman\" w:hAnsi=\"Times New Roman\" w:cs=\"Times New Roman\"/><w:sz w:val=\"18\"/><w:szCs w:val=\"18\"/></w:rPr><w:fldChar w:fldCharType=\"separate\"/></w:r>"
        . "<w:r><w:rPr><w:noProof/><w:rFonts w:ascii=\"Times New Roman\" w:hAnsi=\"Times New Roman\" w:cs=\"Times New Roman\"/><w:sz w:val=\"18\"/><w:szCs w:val=\"18\"/></w:rPr><w:t>1</w:t></w:r>"
        . "<w:r><w:rPr><w:rFonts w:ascii=\"Times New Roman\" w:hAnsi=\"Times New Roman\" w:cs=\"Times New Roman\"/><w:sz w:val=\"18\"/><w:szCs w:val=\"18\"/></w:rPr><w:fldChar w:fldCharType=\"end\"/></w:r>"
        . "</w:p>";

    $spanC1C2 = $c[0] + $c[1]; // 3823
    $spanC3C4 = $c[2] + $c[3]; // 4819

    $row3 = '<w:tr><w:trPr><w:trHeight w:val="360"/></w:trPr>'
        . $tcMergeStart($spanC1C2, 2, $companyXml)
        . $tcSpan($spanC3C4, 2, $titleXml)
        . $tc($c[4], "<w:p><w:pPr><w:spacing w:after=\"0\"/></w:pPr></w:p>")
        . '</w:tr>';

    // Row 4: vMerge continue (span2) | Version (span2) | Page no
    $versionXml =
        "<w:p><w:pPr><w:spacing w:before=\"0\" w:after=\"0\"/><w:ind w:left=\"60\"/></w:pPr>"
        . "<w:r><w:rPr><w:rFonts w:ascii=\"Times New Roman\" w:hAnsi=\"Times New Roman\" w:cs=\"Times New Roman\"/><w:sz w:val=\"18\"/><w:szCs w:val=\"18\"/></w:rPr>"
        . "<w:t xml:space=\"preserve\">Version: {$version}</w:t></w:r></w:p>";

    $row4 = '<w:tr><w:trPr><w:trHeight w:val="280"/></w:trPr>'
        . $tcMergeCont($spanC1C2, 2)
        . $tcSpan($spanC3C4, 2, $versionXml)
        . $tc($c[4], $pageXml)
        . '</w:tr>';

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
       xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <w:tbl>
    <w:tblPr>
      <w:tblW w:w="' . $TW . '" w:type="dxa"/>
      <w:tblBorders>
        <w:top    w:val="none" w:sz="0" w:space="0" w:color="auto"/>
        <w:left   w:val="single" w:sz="4" w:space="0" w:color="000000"/>
        <w:bottom w:val="single" w:sz="4" w:space="0" w:color="000000"/>
        <w:right  w:val="single" w:sz="4" w:space="0" w:color="000000"/>
        <w:insideH w:val="single" w:sz="4" w:space="0" w:color="000000"/>
        <w:insideV w:val="single" w:sz="4" w:space="0" w:color="000000"/>
      </w:tblBorders>
      <w:tblLayout w:type="fixed"/>
      <w:tblCellMar>
        <w:left  w:w="60" w:type="dxa"/>
        <w:right w:w="60" w:type="dxa"/>
      </w:tblCellMar>
    </w:tblPr>
    <w:tblGrid>' . $gridCols . '</w:tblGrid>
    ' . $row1 . $row2 . $row3 . $row4 . '
  </w:tbl>
  <w:p><w:pPr><w:pStyle w:val="Footer"/></w:pPr></w:p>
</w:ftr>';
}

// ── DOCUMENT BODY ─────────────────────────────────────────────────────────
function buildBody(array $p, array $revisions): string {
    $g    = C_GREEN;
    $gd   = C_GREEND;
    $gray = C_LGRAY;
    $b    = '';

    // ─────────────────────────────────────────────────────────────────────
    // Page layout (A4):
    //   page width = 11906, margins left+right = 900+900 = 1800
    //   usable width = 10106 twips
    //
    // From the screenshot the tables are narrower (~70% of usable width)
    // and centered on the page:
    //   Table width ≈ 7200 twips, centered
    //
    // Project info:  Label 2000 | Value 5200  = 7200
    // Approval:      Role 1100 | Name 1500 | Dept 1300 | Email 2300 | Date 1000  = 7200
    // Revision:      4 equal cols × 1800 = 7200
    // ─────────────────────────────────────────────────────────────────────
    $TW = 7200; // total table width, centered

    // ── Spacer before first table ─────────────────────────────────────────
    $b .= emptyP(300);

    // ══════════════════════════════════════════════════════════════════════
    // 1. PROJECT INFO TABLE  (Label 2000 | Value 5200)
    // ══════════════════════════════════════════════════════════════════════
    $lw = 2000;
    $vw = 5200;
    $b .= '<w:tbl>' . tblPrXml($TW, true)
        . "<w:tblGrid><w:gridCol w:w=\"{$lw}\"/><w:gridCol w:w=\"{$vw}\"/></w:tblGrid>";
    foreach ([
        ['Project:',      $p['project_name']  ?? ''],
        ['Document Key:', $p['document_key']  ?? ''],
        ['Version:',      $p['version']       ?? ''],
        ['Revision:',     $p['revision']      ?? ''],
        ['Customer:',     $p['customer_name'] ?? ''],
    ] as [$lbl, $val]) {
        $b .= '<w:tr>'
            . '<w:tc>' . tcPrXml($lw, $gray) . cellP($lbl, true,  '', 18) . '</w:tc>'
            . '<w:tc>' . tcPrXml($vw)         . cellP($val, false, '', 18) . '</w:tc>'
            . '</w:tr>';
    }
    $b .= '</w:tbl>';

    // ══════════════════════════════════════════════════════════════════════
    // 2. APPROVAL TABLE
    //    Role 1100 | Name 1500 | Dept 1300 | Email 2300 | Date 1000 = 7200
    // ══════════════════════════════════════════════════════════════════════
    $b .= emptyP(240);
    $aw = [1100, 1500, 1300, 2300, 1000];
    $b .= '<w:tbl>' . tblPrXml(array_sum($aw), true)
         . '<w:tblGrid>'
         . implode('', array_map(fn($w) => "<w:gridCol w:w=\"{$w}\"/>", $aw))
         . '</w:tblGrid>';
    // Header row
    $b .= '<w:tr>';
    foreach (['Role', 'Name:', 'Department:', 'Email', 'Date:'] as $i => $h) {
        $b .= '<w:tc>' . tcPrXml($aw[$i], $gray) . cellP($h, true, '', 18) . '</w:tc>';
    }
    $b .= '</w:tr>';
    // Data rows
    foreach ([
        ['Author:',     $p['author_name']??'',   $p['author_department']??'',   $p['author_email']??'',   fmtDate($p['author_date']??'')],
        ['1st. Check:', $p['checker_name']??'',  $p['checker_department']??'',  $p['checker_email']??'',  fmtDate($p['checker_date']??'')],
        ['Approved',    $p['approver_name']??'', $p['approver_department']??'', $p['approver_email']??'', fmtDate($p['approver_date']??'')],
    ] as $row) {
        $b .= '<w:tr>';
        foreach ($row as $i => $v) {
            $b .= '<w:tc>' . tcPrXml($aw[$i]) . cellP($v, false, '', 18) . '</w:tc>';
        }
        $b .= '</w:tr>';
    }
    $b .= '</w:tbl>';

    // ══════════════════════════════════════════════════════════════════════
    // 3. REVISION HISTORY TABLE
    //    Version | Previous Ver | Date | Change Content
    //    1800    | 1800         | 1800 | 1800  = 7200
    // ══════════════════════════════════════════════════════════════════════
    $b .= emptyP(240);
    $b .= bodyPara('Revision History', ['bold' => true, 'sz' => 22, 'spA' => '80']);
    $rw = [1800, 1800, 1800, 1800];
    $b .= '<w:tbl>' . tblPrXml(array_sum($rw), true)
         . '<w:tblGrid>'
         . implode('', array_map(fn($w) => "<w:gridCol w:w=\"{$w}\"/>", $rw))
         . '</w:tblGrid>';
    $b .= '<w:tr>';
    foreach (['Version', 'Previous Ver.', 'Date', 'Change Content'] as $i => $h) {
        $b .= '<w:tc>' . tcPrXml($rw[$i], $gray) . cellP($h, true, '', 18) . '</w:tc>';
    }
    $b .= '</w:tr>';
    if ($revisions) {
        foreach ($revisions as $r) {
            $b .= '<w:tr>';
            foreach ([$r['version']??'', $r['previous']??'', fmtDate($r['date']??''), $r['change']??''] as $i => $v) {
                $b .= '<w:tc>' . tcPrXml($rw[$i]) . cellP($v, false, '', 18) . '</w:tc>';
            }
            $b .= '</w:tr>';
        }
    } else {
        $b .= '<w:tr>';
        foreach ($rw as $i => $w) {
            $b .= '<w:tc>' . tcPrXml($w) . cellP($i === 3 ? 'No revision history.' : '', false, C_MUTED, 18) . '</w:tc>';
        }
        $b .= '</w:tr>';
    }
    $b .= '</w:tbl>';

    // ── Page break before content sections ───────────────────────────────
    $b .= pageBreakXml();

    // ══════════════════════════════════════════════════════════════════════
    // 5. CONTENT SECTIONS (all sidebar pages)
    // ══════════════════════════════════════════════════════════════════════
    $globalNum = 0; // continuous counter across all sections

    foreach ([
        ['project_overview',      'Project Overview'],
        ['expected_benefits',     'Expected Benefits & Tentative ROI'],
        ['scope_of_work',         'Project Scope of Work'],
        ['current_system',        'Current System Scenario'],
        ['proposed_solution',     'Proposed Solution'],
        ['utilities_covered',     'Utilities Covered Under Project'],
        ['system_architecture',   'System Architecture'],
        ['kpis',                  'Standard Utility KPIs'],
        ['dashboard_features',    'Dashboard Features'],
        ['testing_commissioning', 'Testing & Commissioning'],
        ['deliverables',          'Deliverables'],
        ['customer_scope',        'Customer Scope'],
        ['out_of_scope',          'Out of Scope'],
        ['commercials',           'Commercials'],
        ['commercial_summary',    'Commercial Summary – UMS'],
    ] as [$col, $title]) {
        $content = $p[$col] ?? '';
        if (!$content || $content === '[]') continue;
        $headings = json_decode($content, true);
        if (!$headings) continue;

        // Section title — bold black, no green bar
        $b .= bodyPara($title, ['bold'=>true,'sz'=>22,'spB'=>'120','spA'=>'80']);

        foreach ($headings as $h) {
            $globalNum++;     // continuous across ALL sections: 1, 2, 3 …
            $subNum = 0;      // resets per heading

            $b .= bodyPara($globalNum . '. ' . ($h['title'] ?? ''),
                ['bold'=>true,'sz'=>20,'spB'=>'100','spA'=>'60','indL'=>'0']);
            if (!empty($h['description']))
                $b .= bodyPara($h['description'], ['sz'=>18,'spA'=>'80','indL'=>'200']);

            foreach ($h['subheadings'] ?? [] as $sh) {
                $subNum++;
                $subsubNum = 0;   // resets per subheading

                $b .= bodyPara($globalNum . '.' . $subNum . '. ' . ($sh['title'] ?? ''),
                    ['bold'=>true,'sz'=>18,'spB'=>'80','spA'=>'40','indL'=>'200']);
                if (!empty($sh['description']))
                    $b .= bodyPara($sh['description'], ['sz'=>18,'spA'=>'80','indL'=>'400']);

                foreach ($sh['subsubheadings'] ?? [] as $ss) {
                    $subsubNum++;
                    $b .= bodyPara($globalNum . '.' . $subNum . '.' . $subsubNum . '. ' . ($ss['title'] ?? ''),
                        ['bold'=>true,'sz'=>18,'spB'=>'60','spA'=>'30','indL'=>'400']);
                    if (!empty($ss['description']))
                        $b .= bodyPara($ss['description'], ['sz'=>18,'spA'=>'80','indL'=>'600']);
                }
            }
        }
    }

    return $b;
}

// ── DOCUMENT.XML ──────────────────────────────────────────────────────────
function buildDocument(array $p, array $revisions): string {
    $body = buildBody($p, $revisions);
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
            xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <w:body>
    ' . $body . '
    <w:sectPr>
      <w:headerReference w:type="default" r:id="rId3"/>
      <w:footerReference w:type="default" r:id="rId4"/>
      <w:pgSz w:w="11906" w:h="16838"/>
      <w:pgMar w:top="1134" w:right="709" w:bottom="1134" w:left="709"
               w:header="709" w:footer="709" w:gutter="0"/>

    </w:sectPr>
  </w:body>
</w:document>';
}

// ══════════════════════════════════════════════════════════════════════════
// BUILD & STREAM
// ══════════════════════════════════════════════════════════════════════════

// Load images from disk
// Try multiple possible paths to find the assets folder
$possiblePaths = [
    'C:/xampp/htdocs/invoice/assets/',                  // Windows XAMPP absolute
    dirname(__DIR__) . '/assets/',                       // one level up from techno_commercial
    __DIR__ . '/../assets/',                             // relative from this file
    $_SERVER['DOCUMENT_ROOT'] . '/invoice/assets/',      // from web root
];
$assetsPath = '';
foreach ($possiblePaths as $path) {
    if (file_exists($path . 'eltrive-logo.png')) {
        $assetsPath = $path;
        break;
    }
}
// Uncomment below to debug which path is found:
// debug removed
$logoData      = $assetsPath ? file_get_contents($assetsPath . 'eltrive-logo.png') : '';
$watermarkData = ($assetsPath && file_exists($assetsPath . 'watermark.png')) ? file_get_contents($assetsPath . 'watermark.png') : '';

$zip = new PureZip();
$zip->addFile('[Content_Types].xml',                contentTypes());
$zip->addFile('_rels/.rels',                        rootRels());
$zip->addFile('word/_rels/document.xml.rels',       wordRels());
$zip->addFile('word/_rels/header1.xml.rels',        headerRels());
$zip->addFile('word/_rels/footer1.xml.rels',        footerRels());
$zip->addFile('word/settings.xml',                  settings());
$zip->addFile('word/styles.xml',                    styles());
$zip->addFile('word/header1.xml',                   buildHeader($p));
$zip->addFile('word/footer1.xml',                   buildFooter($p));
$zip->addFile('word/document.xml',                  buildDocument($p, $revisions));
if ($logoData)      $zip->addFile('word/media/eltrive-logo.png', $logoData);
if ($watermarkData) $zip->addFile('word/media/watermark.png',    $watermarkData);

$docx = $zip->build();

$safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $p['document_key'] ?: ($p['project_name'] ?? 'project'));

// Word format download
$filename = $safeName . '.docx';
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($docx));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
echo $docx;
exit;
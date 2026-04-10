<?php
// safehydra/download_sh.php
require_once dirname(__DIR__) . '/db.php';
@require_once __DIR__ . '/vendor/autoload.php';

function shIndianFormat($n): string {
    $n = preg_replace('/[^0-9]/', '', (string)$n);
    if ($n === '') {
        return '0';
    }
    if (strlen($n) <= 3) {
        return $n;
    }
    $last3 = substr($n, -3);
    $rest = substr($n, 0, -3);
    $groups = [];
    while (strlen($rest) > 2) {
        $groups[] = substr($rest, -2);
        $rest = substr($rest, 0, -2);
    }
    if ($rest !== '') {
        $groups[] = $rest;
    }
    $groups = array_reverse($groups);
    return implode(',', $groups) . ',' . $last3;
}

function shDrawOverlayText($pdf, float $x, float $top, float $w, float $h, string $text, string $style = '', float $fontSize = 12.0): void {
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($x - 1.2, $top - 1.2, $w + 2.4, $h + 2.4, 'F');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Times', $style, $fontSize);
    // FPDF Text() expects baseline from top, so position near box bottom.
    $baselineY = $top + $h - 1.6;
    $pdf->Text($x, $baselineY, $text);
}

function shFitFontSize($pdf, string $text, float $maxWidth, string $style = '', float $base = 12.0, float $min = 9.6): float {
    $size = $base;
    $pdf->SetFont('Times', $style, $size);
    while ($size > $min && $pdf->GetStringWidth($text) > $maxWidth) {
        $size -= 0.1;
        $pdf->SetFont('Times', $style, $size);
    }
    return $size;
}

function shDrawOverlayParagraph($pdf, float $x, float $top, float $w, float $h, string $text, float $fontSize = 12.0, float $lineHeight = 13.2): void {
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($x - 1.0, $top - 1.0, $w + 2.0, $h + 2.0, 'F');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('Times', '', $fontSize);
    $pdf->SetXY($x, $top);
    $pdf->MultiCell($w, $lineHeight, $text, 0, 'L');
}

function shGenerateWithFpdi(string $templatePath, string $outputPath, string $customerName, string $amount): array {
    if (!class_exists('\\setasign\\Fpdi\\Fpdi')) {
        return [false, 'FPDI library not installed.'];
    }

    try {
        $pdf = new \setasign\Fpdi\Fpdi('P', 'pt');
        $pageCount = $pdf->setSourceFile($templatePath);
        $amountFormatted = shIndianFormat($amount);

        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $tpl = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($tpl);
            $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height'], true);

            if ($pageNo === 1) {
                // Page 1: customer name block
                if (trim($customerName) !== '') {
                    shDrawOverlayText($pdf, 175.8, 247.1, 226.0, 12.0, $customerName, '', 12.0);
                }
            } elseif ($pageNo === 3) {
                // Page 3: redraw full first paragraph so long names wrap naturally.
                if (trim($customerName) !== '') {
                    $firstPara = 'The fire pump house at ' . $customerName . ' plays a mission-critical role in plant safety, '
                        . 'comprising a jockey pump, main hydrant pump, and diesel pump that must remain fully operational '
                        . 'and ready at all times. Any unnoticed failure, pressure drop, or delayed response can significantly '
                        . 'compromise fire preparedness. Conventional fire pump systems rely heavily on local panels, pressure '
                        . 'switches, and manual checks, offering limited visibility into real-time pump health, operational status, '
                        . 'or fault conditions especially during non-working hours.';
                    shDrawOverlayParagraph($pdf, 86.0, 101.0, 500.0, 88.0, $firstPara, 11.2, 12.0);
                }
            } elseif ($pageNo === 4) {
                // Page 4: BOQ amount columns
                if (intval($amount) > 0) {
                    shDrawOverlayText($pdf, 444.9, 652.0, 44.0, 14.0, $amountFormatted, '', 12.0);
                    shDrawOverlayText($pdf, 498.1, 652.0, 44.0, 14.0, $amountFormatted, '', 12.0);
                }
            }
        }

        $pdf->Output('F', $outputPath);
        if (!file_exists($outputPath) || filesize($outputPath) <= 0) {
            return [false, 'FPDI wrote empty output file.'];
        }
        return [true, ''];
    } catch (\Throwable $e) {
        return [false, $e->getMessage()];
    }
}

$id = intval($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit('Invalid ID.'); }

$stmt = $pdo->prepare("SELECT * FROM safe_hydra_documents WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) { http_response_code(404); exit('Document not found.'); }

$templatePath = __DIR__ . '/safe_hydra_template.pdf';
if (!file_exists($templatePath)) {
    http_response_code(500);
    exit('Template PDF not found. Please place it at: safehydra/safe_hydra_template.pdf');
}

// Output path — temp file
$outputPath = sys_get_temp_dir() . '/sh_' . $doc['id'] . '_' . time() . '.pdf';

[$fpdiOk, $fpdiErr] = shGenerateWithFpdi(
    $templatePath,
    $outputPath,
    (string)$doc['customer_name'],
    (string)intval($doc['amount'])
);

if (!$fpdiOk || !file_exists($outputPath) || filesize($outputPath) <= 0) {
    http_response_code(500);
    if ($fpdiErr === '') {
        $fpdiErr = 'Unknown FPDI generation failure.';
    }
    exit("PDF generation failed: {$fpdiErr}");
}

$filename = 'SafeHydra_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $doc['document_number']) . '_' . time() . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($outputPath));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

readfile($outputPath);
@unlink($outputPath);
exit;

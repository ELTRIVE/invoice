<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();
date_default_timezone_set('Asia/Kolkata');

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$watermarkPath = dirname(__DIR__) . '/assets/watermark.png';

function formatAddr($a) {
    return htmlspecialchars(preg_replace("/\r\n|\r|\n/", "\n", trim($a)));
}

function numberToWords($number) {
    $decimal = round($number - ($no = floor($number)), 2) * 100;
    $digits_length = strlen($no);
    $i = 0;
    $str = [];
    $words = [
        0=>'',1=>'One',2=>'Two',3=>'Three',4=>'Four',5=>'Five',6=>'Six',
        7=>'Seven',8=>'Eight',9=>'Nine',10=>'Ten',11=>'Eleven',12=>'Twelve',
        13=>'Thirteen',14=>'Fourteen',15=>'Fifteen',16=>'Sixteen',17=>'Seventeen',
        18=>'Eighteen',19=>'Nineteen',20=>'Twenty',30=>'Thirty',40=>'Forty',
        50=>'Fifty',60=>'Sixty',70=>'Seventy',80=>'Eighty',90=>'Ninety'
    ];
    $digits = ['','Hundred','Thousand','Lakh','Crore'];
    while ($i < $digits_length) {
        $divider = ($i == 2) ? 10 : 100;
        $number  = floor($no % $divider);
        $no      = floor($no / $divider);
        $i      += ($divider == 10) ? 1 : 2;
        if ($number) {
            $counter = count($str);
            $plural  = ($counter && $number > 9) ? 's' : '';
            $hundred = ($counter == 1 && $str[0]) ? ' and ' : '';
            $str[]   = ($number < 21)
                ? $words[$number] . ' ' . $digits[$counter] . $plural . ' ' . $hundred
                : $words[floor($number / 10) * 10] . ' ' . $words[$number % 10] . ' ' . $digits[$counter] . $plural . ' ' . $hundred;
        } else {
            $str[] = null;
        }
    }
    $Rupees = implode('', array_reverse($str));
    $paise  = ($decimal)
        ? ' and ' . $words[floor($decimal / 10)] . ' ' . $words[floor($decimal % 10)] . ' Paise'
        : '';
    return ($Rupees ? trim($Rupees) . ' Rupees ' : '') . $paise . ' Only';
}

// ── Validate ID ──────────────────────────────────────────────────────────────
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die("Invalid ID");

// ── Fetch purchase record ────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM purchases WHERE id = ?");
$stmt->execute([$id]);
$inv = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$inv) die("Invoice not found");

// ── Fetch company info ───────────────────────────────────────────────────────
$company = $pdo->query("SELECT * FROM invoice_company LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Apply per-invoice company override snapshot (if present)
if (!empty($inv['company_override'])) {
    $override = json_decode($inv['company_override'], true);
    if (is_array($override)) {
        foreach ($override as $key => $val) {
            if ($val !== '' && $val !== null) {
                $company[$key] = $val;
            }
        }
    }
}

// ── Logo → base64 ────────────────────────────────────────────────────────────
$logoBase64 = '';
if (!empty($company['company_logo'])) {
    $logoFile = dirname(__DIR__) . '/' . ltrim($company['company_logo'], '/');
    if (file_exists($logoFile)) {
        $ext        = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
        $mime       = ($ext === 'png') ? 'image/png' : (($ext === 'gif') ? 'image/gif' : 'image/jpeg');
        $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoFile));
    }
}

$signatureBase64 = '';
if (!empty($inv['signature_id'])) {
    $sigStmt = $pdo->prepare("SELECT file_path FROM signatures WHERE id = ?");
    $sigStmt->execute([$inv['signature_id']]);
    $sigData = $sigStmt->fetch(PDO::FETCH_ASSOC);
    if ($sigData && !empty($sigData['file_path'])) {
        $sigFile = dirname(__DIR__) . '/' . ltrim($sigData['file_path'], '/');
        if (file_exists($sigFile)) {
            $ext = strtolower(pathinfo($sigFile, PATHINFO_EXTENSION));
            $mime = ($ext === 'png') ? 'image/png' : (($ext === 'gif') ? 'image/gif' : 'image/jpeg');
            $signatureBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($sigFile));
        }
    }
}

// ── Parse items / terms ──────────────────────────────────────────────────────
$items = json_decode($inv['items_json'] ?? '[]', true) ?: [];
$terms = json_decode($inv['terms_json'] ?? '[]', true) ?: [];

// ── Invoice date ─────────────────────────────────────────────────────────────
$invoiceDate = !empty($inv['invoice_date'])
    ? date('d-M-Y', strtotime($inv['invoice_date']))
    : date('d-M-Y');

// ── Totals ───────────────────────────────────────────────────────────────────
$subtotalBasic = $subtotalCGST = $subtotalSGST = $subtotalIGST = $grandTotal = 0;
foreach ($items as $item) {
    $subtotalBasic += floatval($item['basic_amount'] ?? 0);
    $subtotalCGST  += floatval($item['cgst_amount']  ?? 0);
    $subtotalSGST  += floatval($item['sgst_amount']  ?? 0);
    $subtotalIGST  += floatval($item['igst_amount']  ?? 0);
    $grandTotal    += floatval($item['total']         ?? 0);
}

// ── Tax flags ────────────────────────────────────────────────────────────────
$hasIGST = $subtotalIGST > 0;
$hasCGST = $subtotalCGST > 0;
$hasSGST = $subtotalSGST > 0;
// Default to CGST+SGST columns if no tax found
if (!$hasIGST && !$hasCGST && !$hasSGST) {
    $hasCGST = true;
    $hasSGST = true;
}
$taxCols = ($hasCGST ? 1 : 0) + ($hasSGST ? 1 : 0) + ($hasIGST ? 1 : 0);

$amountInWords = numberToWords(round($grandTotal, 2));

// ── Supplier address lines ────────────────────────────────────────────────────
$supplierAddrLines = array_filter(
    array_map('trim', explode("\n", str_replace(["\r\n", "\r"], "\n", $inv['supplier_address'] ?? '')))
);

// ── HSN/SAC summary groups ────────────────────────────────────────────────────
$hsnGroups = [];
foreach ($items as $item) {
    $hsn = $item['hsn_sac'] ?? '';
    if (!isset($hsnGroups[$hsn])) {
        $hsnGroups[$hsn] = ['taxable'=>0,'cgst'=>0,'sgst'=>0,'igst'=>0,'cgst_pct'=>0,'sgst_pct'=>0,'igst_pct'=>0];
    }
    $hsnGroups[$hsn]['taxable'] += floatval($item['basic_amount'] ?? 0);
    $hsnGroups[$hsn]['cgst']    += floatval($item['cgst_amount']  ?? 0);
    $hsnGroups[$hsn]['sgst']    += floatval($item['sgst_amount']  ?? 0);
    $hsnGroups[$hsn]['igst']    += floatval($item['igst_amount']  ?? 0);
    if (floatval($item['basic_amount'] ?? 0) > 0) {
        $hsnGroups[$hsn]['cgst_pct'] = floatval($item['cgst_percent'] ?? 0);
        $hsnGroups[$hsn]['sgst_pct'] = floatval($item['sgst_percent'] ?? 0);
        $hsnGroups[$hsn]['igst_pct'] = floatval($item['igst_percent'] ?? 0);
    }
}

// ── Document/filename slugs ───────────────────────────────────────────────────
$coSlug  = preg_replace('/[^a-zA-Z0-9]+/', '', strtoupper($company['company_name'] ?? ''));
$invNum  = preg_replace('/[^a-zA-Z0-9\/]+/', '_', trim($inv['invoice_number'] ?? (string)$id));
$supSlug = preg_replace('/[^a-zA-Z0-9]+/', '_', trim($inv['supplier_name'] ?? 'Supplier'));
$invSlug = preg_replace('/[^a-zA-Z0-9]+/', '_', trim($inv['invoice_number'] ?? (string)$id));

// ─── Build HTML ───────────────────────────────────────────────────────────────
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
/* ── Reset & base ─────────────────────────────── */
@page { margin: 18px 16px; }
* { box-sizing: border-box; }
body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 9.5px;
    color: #111;
    margin: 0; padding: 0;
}
table { border-collapse: collapse; width: 100%; }
td, th { vertical-align: middle; }

/* ── Print meta row ───────────────────────────── */
.meta-row td {
    border: none;
    font-size: 8px;
    color: #888;
    padding-bottom: 5px;
}

/* ── Company header ───────────────────────────── */
.co-name { font-size: 16px; font-weight: bold; margin-bottom: 3px; }
.co-detail { font-size: 8.5px; line-height: 1.6; }

/* ── Invoice title bar ────────────────────────── */
.inv-title {
    text-align: center;
    font-size: 15px;
    font-weight: bold;
    padding: 5px 0;
    margin: 7px 0 6px;
    letter-spacing: 1px;
}

/* ── From / meta block ────────────────────────── */
.from-label { font-size: 8.5px; color: #555; margin-bottom: 2px; }
.from-name  { font-weight: bold; font-size: 10.5px; margin-bottom: 1px; }
.from-addr  { font-size: 8.5px; line-height: 1.65; }
.inv-meta   { text-align: right; font-size: 9px; line-height: 1.9; }
.inv-meta .inv-no { font-size: 12px; font-weight: bold; }

/* ── Items table ──────────────────────────────── */
.itm { font-size: 8.5px; margin-top: 6px; }
.itm th {
    background: #f2f2f2;
    border: 0.5px solid #444;
    padding: 4px 5px;
    text-align: center;
    font-weight: bold;
    white-space: nowrap;
    line-height: 1.4;
}
.itm td { border: 0.5px solid #444; padding: 4px 5px; line-height: 1.4; }
.itm td.r { text-align: right; }
.itm td.c { text-align: center; }
.itm td.l { text-align: left;  }
.itm tfoot td {
    background: #f5f5f5;
    font-weight: bold;
    border: 0.5px solid #444;
    padding: 4px 6px;
}

/* ── Words / summary section ──────────────────── */
.words-cell {
    border: 0.5px solid #444;
    padding: 5px 8px;
    font-size: 8.5px;
    line-height: 1.6;
}
.summary-left {
    border: 0.5px solid #444;
    padding: 7px 9px;
    vertical-align: top;
    font-size: 8.5px;
}
.summary-right {
    border: 0.5px solid #444;
    padding: 0;
    vertical-align: top;
}
.summary-right table td {
    border: none;
    border-bottom: 0.5px solid #ccc;
    padding: 4px 10px;
    font-size: 9px;
}
.summary-right table tr:last-child td {
    border-bottom: none;
    font-weight: bold;
    font-size: 10.5px;
    padding: 5px 10px;
}

/* ── Signature row ────────────────────────────── */
.sig-left {
    border: 0.5px solid #444;
    padding: 28px 9px 7px;
    vertical-align: bottom;
    font-size: 8.5px;
    width: 55%;
}
.sig-right {
    border: 0.5px solid #444;
    padding: 8px 10px;
    vertical-align: top;
    text-align: right;
    font-size: 9px;
    width: 45%;
}

/* ── HSN/SAC summary table ────────────────────── */
.hsn-tbl { width: auto; font-size: 9px; margin-top: 9px; border-collapse: collapse; }
.hsn-tbl th {
    background: #f2f2f2;
    border: 0.75px solid #555;
    padding: 4px 9px;
    text-align: center;
    font-weight: bold;
    white-space: nowrap;
}
.hsn-tbl td          { border: 0.75px solid #555; padding: 4px 9px; }
.hsn-tbl td.r        { text-align: right;  }
.hsn-tbl td.c        { text-align: center; }
</style>
</head>
<body>

<!-- ── Print meta header ────────────────────────────────────────────────── -->
<table class="meta-row" style="margin-bottom:4px;">
<tr>
  <td style="text-align:left;"><?= date("d/m/Y, h:i A") ?></td>
  <td style="text-align:right;">
    Document_<?= $coSlug ?>_<?= $invNum ?>_<?= date("Y-m-d") ?> - Biziverse
  </td>
</tr>
</table>

<!-- ── Company header ───────────────────────────────────────────────────── -->
<table style="border:none;margin-bottom:7px;">
<tr>
<?php if ($logoBase64): ?>
  <td style="border:none;width:35%;vertical-align:middle;">
    <img src="<?= $logoBase64 ?>" style="max-height:80px;max-width:155px;">
  </td>
  <td style="border:none;width:65%;vertical-align:top;text-align:right;">
<?php else: ?>
  <td style="border:none;width:100%;vertical-align:top;text-align:right;" colspan="2">
<?php endif; ?>
    <div class="co-name"><?= htmlspecialchars($company['company_name'] ?? '') ?></div>
    <div class="co-detail">
      <?= htmlspecialchars($company['address_line1'] ?? '') ?>
      <?php if (!empty($company['address_line2'])): ?>, <?= htmlspecialchars($company['address_line2']) ?><?php endif; ?><br>
      <?= htmlspecialchars($company['city'] ?? '') ?>, <?= htmlspecialchars($company['state'] ?? '') ?> - <?= htmlspecialchars($company['pincode'] ?? '') ?><br>
      <strong>GSTIN :</strong> <?= htmlspecialchars($company['gst_number'] ?? '') ?>
      <?php if (!empty($company['pan'])): ?>&nbsp;&nbsp;<strong>PAN :</strong> <?= htmlspecialchars($company['pan']) ?><?php endif; ?>
      <?php if (!empty($company['phone'])): ?><br><strong>Phone :</strong> <?= htmlspecialchars($company['phone']) ?><?php endif; ?>
    </div>
  </td>
</tr>
</table>

<!-- ── Title ────────────────────────────────────────────────────────────── -->
<div class="inv-title">PURCHASE INVOICE</div>

<!-- ── From + Invoice meta ──────────────────────────────────────────────── -->
<table style="border:none;margin-bottom:5px;">
<tr>
  <!-- Supplier info -->
  <td style="border:none;width:58%;vertical-align:top;">
    <div class="from-label">From :</div>
    <div class="from-name"><?= htmlspecialchars($inv['supplier_name'] ?? '') ?></div>
    <div class="from-addr">
      <?php foreach ($supplierAddrLines as $line): ?>
        <?= htmlspecialchars($line) ?><br>
      <?php endforeach; ?>
      <?php if (!empty($inv['supplier_gstin'])): ?>
        <strong>GSTIN :</strong> <?= htmlspecialchars($inv['supplier_gstin']) ?>
      <?php endif; ?>
      <?php if (!empty($inv['supplier_phone'])): ?>
        <br><strong>Phone :</strong> <?= htmlspecialchars($inv['supplier_phone']) ?>
      <?php endif; ?>
    </div>
  </td>

  <!-- Invoice meta -->
  <td style="border:none;width:42%;vertical-align:top;">
    <div class="inv-meta">
      Invoice No. : <span class="inv-no"><?= htmlspecialchars($inv['invoice_number'] ?? '') ?></span><br>
      <strong>Date :</strong> <?= $invoiceDate ?><br>
      <?php if (!empty($inv['reference'])): ?>
        <strong>Ref :</strong> <?= htmlspecialchars($inv['reference']) ?><br>
      <?php endif; ?>
      <?php if (!empty($inv['supplier_gstin'])): ?>
        <strong>GSTIN :</strong> <?= htmlspecialchars($inv['supplier_gstin']) ?>
      <?php endif; ?>
    </div>
  </td>
</tr>
</table>

<!-- ── Items table ──────────────────────────────────────────────────────── -->
<?php
// Compute column widths dynamically based on tax columns
$descW = $hasIGST ? '30%' : ($hasCGST && $hasSGST ? '28%' : '32%');
?>
<table class="itm">
<thead>
<tr>
  <th style="width:4%">No.</th>
  <th style="width:<?= $descW ?>">Item &amp; Description</th>
  <th style="width:9%">HSN / SAC</th>
  <th style="width:5%">Qty</th>
  <th style="width:5%">Unit</th>
  <th style="width:9%">Rate (&#8377;)</th>
  <th style="width:9%">Taxable (&#8377;)</th>
  <?php if ($hasCGST): ?><th style="width:7%">CGST</th><?php endif; ?>
  <?php if ($hasSGST): ?><th style="width:7%">SGST</th><?php endif; ?>
  <?php if ($hasIGST): ?><th style="width:8%">IGST</th><?php endif; ?>
  <th style="width:9%">Amount (&#8377;)</th>
</tr>
</thead>
<tbody>
<?php foreach ($items as $idx => $item):
    $basic    = floatval($item['basic_amount'] ?? 0);
    $cgst_amt = floatval($item['cgst_amount']  ?? 0);
    $sgst_amt = floatval($item['sgst_amount']  ?? 0);
    $igst_amt = floatval($item['igst_amount']  ?? 0);
    $cgst_pct = floatval($item['cgst_percent'] ?? 0);
    $sgst_pct = floatval($item['sgst_percent'] ?? 0);
    $igst_pct = floatval($item['igst_percent'] ?? 0);
    $total    = floatval($item['total']        ?? 0);
    $qty      = floatval($item['qty']          ?? 1);
    // Strip trailing zeros from qty (e.g. 2.000 → 2)
    $qtyFmt   = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
?>
<tr>
  <td class="c"><?= $idx + 1 ?></td>
  <td class="l"><?= htmlspecialchars($item['description'] ?? '') ?></td>
  <td class="c"><?= htmlspecialchars($item['hsn_sac'] ?? '') ?></td>
  <td class="r"><?= $qtyFmt ?></td>
  <td class="c"><?= htmlspecialchars($item['unit'] ?? '') ?></td>
  <td class="r"><?= number_format(floatval($item['rate'] ?? 0), 2) ?></td>
  <td class="r"><?= number_format($basic, 2) ?></td>
  <?php if ($hasCGST): ?>
    <td class="r"><?= number_format($cgst_amt, 2) ?><br><small style="color:#666;"><?= number_format($cgst_pct, 2) ?>%</small></td>
  <?php endif; ?>
  <?php if ($hasSGST): ?>
    <td class="r"><?= number_format($sgst_amt, 2) ?><br><small style="color:#666;"><?= number_format($sgst_pct, 2) ?>%</small></td>
  <?php endif; ?>
  <?php if ($hasIGST): ?>
    <td class="r"><?= number_format($igst_amt, 2) ?><br><small style="color:#666;"><?= number_format($igst_pct, 2) ?>%</small></td>
  <?php endif; ?>
  <td class="r"><strong><?= number_format($total, 2) ?></strong></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr>
  <td colspan="6" style="text-align:right;padding:4px 7px;">Subtotal</td>
  <td class="r"><?= number_format($subtotalBasic, 2) ?></td>
  <?php if ($hasCGST): ?><td class="r"><?= number_format($subtotalCGST, 2) ?></td><?php endif; ?>
  <?php if ($hasSGST): ?><td class="r"><?= number_format($subtotalSGST, 2) ?></td><?php endif; ?>
  <?php if ($hasIGST): ?><td class="r"><?= number_format($subtotalIGST, 2) ?></td><?php endif; ?>
  <td class="r"><?= number_format($grandTotal, 2) ?></td>
</tr>
</tfoot>
</table>

<!-- ── ROW 1: Amount in Words (left) | Tax Summary (right) ──────────────── -->
<table style="margin-top:0;width:100%;border-collapse:collapse;">
<tr>
  <td style="width:55%;border:0.5px solid #444;padding:6px 8px;vertical-align:top;font-size:8.5px;">
    <strong>Total Invoice Amount in Words :</strong><br>
    <strong><?= htmlspecialchars($amountInWords) ?></strong>
  </td>
  <td style="width:45%;border:0.5px solid #444;padding:0;vertical-align:top;">
    <table style="width:100%;border-collapse:collapse;">
      <tr>
        <td style="border-bottom:0.5px solid #ccc;padding:4px 10px;font-size:9px;">Total Amount before Tax (&#8377;)</td>
        <td style="border-bottom:0.5px solid #ccc;padding:4px 10px;font-size:9px;text-align:right;"><?= number_format($subtotalBasic, 2) ?></td>
      </tr>
      <?php if ($hasCGST): ?>
      <tr>
        <td style="border-bottom:0.5px solid #ccc;padding:4px 10px;font-size:9px;">Add CGST (&#8377;)</td>
        <td style="border-bottom:0.5px solid #ccc;padding:4px 10px;font-size:9px;text-align:right;"><?= number_format($subtotalCGST, 2) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($hasSGST): ?>
      <tr>
        <td style="border-bottom:0.5px solid #ccc;padding:4px 10px;font-size:9px;">Add SGST (&#8377;)</td>
        <td style="border-bottom:0.5px solid #ccc;padding:4px 10px;font-size:9px;text-align:right;"><?= number_format($subtotalSGST, 2) ?></td>
      </tr>
      <?php endif; ?>
      <?php if ($hasIGST): ?>
      <tr>
        <td style="border-bottom:0.5px solid #ccc;padding:4px 10px;font-size:9px;">Add IGST (&#8377;)</td>
        <td style="border-bottom:0.5px solid #ccc;padding:4px 10px;font-size:9px;text-align:right;"><?= number_format($subtotalIGST, 2) ?></td>
      </tr>
      <?php endif; ?>
      <tr>
        <td style="padding:5px 10px;font-size:10px;font-weight:bold;">Grand Total (&#8377;)</td>
        <td style="padding:5px 10px;font-size:10px;font-weight:bold;text-align:right;"><?= number_format($grandTotal, 2) ?></td>
      </tr>
    </table>
  </td>
</tr>
</table>

<!-- ── ROW 2: Terms & Conditions full width ──────────────────────────────── -->
<?php if (!empty($terms) || !empty($inv['notes'])): ?>
<table style="margin-top:0;width:100%;border-collapse:collapse;">
<tr>
  <td style="border:0.5px solid #444;padding:7px 9px;vertical-align:top;font-size:8.5px;">
    <?php if (!empty($terms)): ?>
      <strong>Terms &amp; Conditions :</strong>
      <ul style="margin:4px 0 0 14px;padding:0;line-height:1.75;">
        <?php foreach ($terms as $t): ?>
          <li><?= htmlspecialchars($t) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if (!empty($inv['notes'])): ?>
      <?php if (!empty($terms)): ?><br><?php endif; ?>
      <strong>Notes :</strong><br>
      <?= nl2br(htmlspecialchars($inv['notes'])) ?>
    <?php endif; ?>
  </td>
</tr>
</table>
<?php endif; ?>

<!-- ── Signature row ────────────────────────────────────────────────────── -->
<table style="width:100%;margin-top:0;border-collapse:collapse;">
<tr>
  <td class="sig-left">
    This is a computer-generated supplier invoice. E. &amp; O. E.
  </td>
  <td class="sig-right">
    For, <strong><?= htmlspecialchars(strtoupper($company['company_name'] ?? '')) ?></strong>
    <br><br>
    <?= $signatureBase64 ? '<img src="' . $signatureBase64 . '" style="max-height:75px; max-width:175px; object-fit:contain; display:inline-block;" /><br>' : '<br><br><br>' ?>
    <strong>Authorised Signatory</strong>
  </td>
</tr>
</table>

<!-- ── HSN/SAC summary table ────────────────────────────────────────────── -->
<table class="hsn-tbl">
<thead>
<tr>
  <th>HSN/SAC Code</th>
  <th>Taxable (&#8377;)</th>
  <?php if ($hasCGST): ?><th>CGST %</th><th>CGST (&#8377;)</th><?php endif; ?>
  <?php if ($hasSGST): ?><th>SGST %</th><th>SGST (&#8377;)</th><?php endif; ?>
  <?php if ($hasIGST): ?><th>IGST %</th><th>IGST (&#8377;)</th><?php endif; ?>
</tr>
</thead>
<tbody>
<?php foreach ($hsnGroups as $hsn => $row): ?>
<tr>
  <td class="c"><?= htmlspecialchars($hsn) ?></td>
  <td class="r"><?= number_format($row['taxable'], 2) ?></td>
  <?php if ($hasCGST): ?>
    <td class="c"><?= number_format($row['cgst_pct'], 2) ?>%</td>
    <td class="r"><?= number_format($row['cgst'], 2) ?></td>
  <?php endif; ?>
  <?php if ($hasSGST): ?>
    <td class="c"><?= number_format($row['sgst_pct'], 2) ?>%</td>
    <td class="r"><?= number_format($row['sgst'], 2) ?></td>
  <?php endif; ?>
  <?php if ($hasIGST): ?>
    <td class="c"><?= number_format($row['igst_pct'], 2) ?>%</td>
    <td class="r"><?= number_format($row['igst'], 2) ?></td>
  <?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>

</body>
</html>
<?php
$html = ob_get_clean();

// ─── Render PDF via Dompdf ────────────────────────────────────────────────────
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// ── Optional watermark ────────────────────────────────────────────────────────
if (file_exists($watermarkPath)) {
    $canvas = $dompdf->getCanvas();
    $cw = $canvas->get_width();
    $ch = $canvas->get_height();
    $wmW = 300; $wmH = 300;
    $canvas->page_script(function ($pn, $pc, $canvas, $fm) use ($watermarkPath, $cw, $ch, $wmW, $wmH) {
        $canvas->set_opacity(0.08);
        $canvas->image($watermarkPath, ($cw - $wmW) / 2, ($ch - $wmH) / 2, $wmW, $wmH);
        $canvas->set_opacity(1);
    });
}

// ── Stream PDF to browser ─────────────────────────────────────────────────────
$filename = "PurchaseInvoice_{$supSlug}_{$invSlug}_" . date('d-m-Y') . ".pdf";
ob_end_clean();
$dompdf->stream($filename, ["Attachment" => true]);
exit;
?>

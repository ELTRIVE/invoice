<?php
// quote_download.php - Generate PDF Quotation

error_reporting(E_ALL);
ini_set('display_errors', 1);

ob_start();
date_default_timezone_set('Asia/Kolkata');

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$watermarkPath = dirname(__DIR__) . '/assets/watermark.png';

// Format address
function formatAddress($address) {
    return htmlspecialchars(preg_replace("/\r\n|\r|\n/", ', ', trim($address)));
}

// Number to Words (Indian format)
function numberToWords($number) {
    $decimal = round($number - ($no = floor($number)), 2) * 100;
    $digits_length = strlen($no);
    $i = 0;
    $str = [];
    $words = [
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
        6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
        11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen',
        15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen',
        19 => 'Nineteen', 20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty',
        60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety'
    ];
    $digits = ['', 'Hundred', 'Thousand', 'Lakh', 'Crore'];

    while ($i < $digits_length) {
        $divider = ($i == 2) ? 10 : 100;
        $number = floor($no % $divider);
        $no = floor($no / $divider);
        $i += ($divider == 10) ? 1 : 2;
        if ($number) {
            $counter = count($str);
            $plural = ($counter && $number > 9) ? 's' : '';
            $hundred = ($counter == 1 && $str[0]) ? ' and ' : '';
            $str[] = ($number < 21) ? $words[$number] . ' ' . $digits[$counter] . $plural . ' ' . $hundred :
                $words[floor($number / 10) * 10] . ' ' . $words[$number % 10] . ' ' . $digits[$counter] . $plural . ' ' . $hundred;
        } else {
            $str[] = null;
        }
    }

    $Rupees = implode('', array_reverse($str));
    $paise = ($decimal) ? " and " . $words[floor($decimal / 10)] . " " . $words[floor($decimal % 10)] . ' Paise' : '';
    return ($Rupees ? $Rupees . 'Rupees ' : '') . $paise . ' Only';
}

// Quotation ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('Location: quote_index.php'); exit; }

// Fetch quotation
$stmt = $pdo->prepare("SELECT * FROM quotations WHERE id = ?");
$stmt->execute([$id]);
$quot = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$quot) { header('Location: quote_index.php'); exit; }

// Company Info
$company = $pdo->query("
    SELECT company_name, company_logo, address_line1, address_line2,
           city, state, pincode, gst_number, cin_number,
           phone, email, pan, website
    FROM invoice_company LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Apply per-quotation company override snapshot (if present)
if (!empty($quot['company_override'])) {
    $override = json_decode($quot['company_override'], true);
    if (is_array($override)) {
        foreach ($override as $key => $val) {
            if ($val !== '' && $val !== null) {
                $company[$key] = $val;
            }
        }
    }
}

// Bank details
$bank = null;
if (!empty($quot['bank_id'])) {
    $bs = $pdo->prepare("SELECT * FROM bank_details WHERE id = ?");
    $bs->execute([$quot['bank_id']]);
    $bank = $bs->fetch(PDO::FETCH_ASSOC);
}
if (!$bank) {
    $bank = $pdo->query("SELECT * FROM bank_details ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}

// Company logo as base64
$logoBase64 = '';
if (!empty($company['company_logo'])) {
    $logoFile = dirname(__DIR__) . '/' . ltrim($company['company_logo'], '/');
    if (file_exists($logoFile)) {
        $ext  = pathinfo($logoFile, PATHINFO_EXTENSION);
        $data = file_get_contents($logoFile);
        $logoBase64 = 'data:image/' . $ext . ';base64,' . base64_encode($data);
    }
}

// Signature image as base64 (same pattern as invoice download.php)
$signatureBase64 = '';
if (!empty($quot['signature_id'])) {
    $sigStmt = $pdo->prepare("SELECT file_path FROM signatures WHERE id = ?");
    $sigStmt->execute([$quot['signature_id']]);
    $sigData = $sigStmt->fetch(PDO::FETCH_ASSOC);
    if ($sigData && !empty($sigData['file_path'])) {
        $sigFile = dirname(__DIR__) . '/' . ltrim($sigData['file_path'], '/');
        if (file_exists($sigFile)) {
            $ext = pathinfo($sigFile, PATHINFO_EXTENSION);
            $data = file_get_contents($sigFile);
            $signatureBase64 = 'data:image/' . $ext . ';base64,' . base64_encode($data);
        }
    }
}

// Dates
$quotDate = !empty($quot['quot_date']) ? date('d-M-Y', strtotime($quot['quot_date'])) : date('d-M-Y');

// Items from items_json
$items = [];
if (!empty($quot['items_json'])) {
    $decoded = json_decode($quot['items_json'], true);
    if (is_array($decoded)) $items = $decoded;
}

// Terms
$terms = [];
if (!empty($quot['terms_list'])) {
    $term_ids = json_decode($quot['terms_list'], true);
    if (!empty($term_ids) && is_array($term_ids)) {
        $placeholders = implode(',', array_fill(0, count($term_ids), '?'));
        $ts = $pdo->prepare("SELECT term_text FROM po_master_terms WHERE id IN ($placeholders) ORDER BY FIELD(id," . implode(',', array_fill(0, count($term_ids), '?')) . ")");
        $ts->execute(array_merge($term_ids, $term_ids));
        $terms = array_column($ts->fetchAll(PDO::FETCH_ASSOC), 'term_text');
    }
}

// Calculate totals
$subtotalBasic = $subtotalSGST = $subtotalCGST = $subtotalIGST = $grandTotal = 0;
$hasCGST = $hasSGST = $hasIGST = true;
$calc = [];
foreach ($items as $item) {
    $qty     = (float)($item['qty']      ?? 0);
    $rate    = (float)($item['rate']     ?? 0);
    $disc    = (float)($item['discount'] ?? 0);
    $taxable = (float)($item['taxable']  ?? (($qty * $rate) - $disc));
    $cgst_p  = (float)($item['cgst_pct'] ?? 0);
    $sgst_p  = (float)($item['sgst_pct'] ?? 0);
    $igst_p  = (float)($item['igst_pct'] ?? 0);
    $cgst_a  = (float)($item['cgst_amt'] ?? round($taxable * $cgst_p / 100, 2));
    $sgst_a  = (float)($item['sgst_amt'] ?? round($taxable * $sgst_p / 100, 2));
    $igst_a  = (float)($item['igst_amt'] ?? round($taxable * $igst_p / 100, 2));
    $amt     = (float)($item['amount']   ?? $taxable + $cgst_a + $sgst_a + $igst_a);
    $subtotalBasic += $taxable;
    $subtotalCGST  += $cgst_a;
    $subtotalSGST  += $sgst_a;
    $subtotalIGST  += $igst_a;
    $grandTotal    += $amt;
    $calc[] = compact('item','qty','rate','disc','taxable','cgst_p','sgst_p','igst_p','cgst_a','sgst_a','igst_a','amt');
}

$amountInWords = numberToWords($grandTotal);
$totalInWords  = numberToWords($grandTotal);

// Billing address block
$to_html = '';
if (!empty($quot['contact_person'])) $to_html .= htmlspecialchars($quot['contact_person']) . '<br>';
$to_html .= '<strong>' . htmlspecialchars($quot['customer_name']) . '</strong><br>';
$addr_text = !empty($quot['billing_details']) ? $quot['billing_details'] : ($quot['customer_address'] ?? '');
if (!empty($addr_text))
    foreach (array_filter(array_map('trim', explode("\n", $addr_text))) as $ln)
        $to_html .= htmlspecialchars($ln) . '<br>';
if (!empty($quot['billing_phone']))      $to_html .= '<strong>Phone :</strong> ' . htmlspecialchars($quot['billing_phone']) . '<br>';
elseif (!empty($quot['customer_phone'])) $to_html .= '<strong>Phone :</strong> ' . htmlspecialchars($quot['customer_phone']) . '<br>';
if (!empty($quot['billing_gstin']))      $to_html .= '<strong>GSTIN :</strong> ' . htmlspecialchars($quot['billing_gstin']) . '<br>';
elseif (!empty($quot['customer_gstin'])) $to_html .= '<strong>GSTIN :</strong> ' . htmlspecialchars($quot['customer_gstin']) . '<br>';

// Shipping address block
$ship_html = '';
if (!empty($quot['shipping_details'])) {
    foreach (array_filter(array_map('trim', explode("\n", $quot['shipping_details']))) as $ln)
        $ship_html .= htmlspecialchars($ln) . '<br>';
    if (!empty($quot['shipping_phone'])) $ship_html .= '<strong>Phone :</strong> ' . htmlspecialchars($quot['shipping_phone']) . '<br>';
    if (!empty($quot['shipping_gstin'])) $ship_html .= '<strong>GSTIN :</strong> ' . htmlspecialchars($quot['shipping_gstin']) . '<br>';
} else {
    $ship_html = $to_html;
}

// Item rows
$item_rows_html = '';
foreach ($calc as $c) {
    $item = $c['item'];
    $nm   = htmlspecialchars($item['item_name'] ?? '');
    if (!empty($item['description'])) $nm .= '<br>' . htmlspecialchars($item['description']);
    $item_rows_html .= '<tr>
<td></td>
<td>' . htmlspecialchars($item['hsn_sac'] ?? '') . '</td>
<td class="desc">' . $nm . '</td>
<td>' . htmlspecialchars($item['unit'] ?? '') . '</td>
<td class="right">' . number_format($c['qty'], 3) . '</td>
<td class="right">' . number_format($c['rate'], 2) . '</td>
<td class="right">' . number_format($c['taxable'], 2) . '</td>
' . ($hasSGST ? '<td class="right">' . number_format($c['sgst_a'], 2) . '</td>' : '') . '
' . ($hasCGST ? '<td class="right">' . number_format($c['cgst_a'], 2) . '</td>' : '') . '
' . ($hasIGST ? '<td class="right">' . number_format($c['igst_a'], 2) . '</td>' : '') . '
<td class="right">0.00</td>
<td class="right">' . number_format($c['amt'], 2) . '</td>
</tr>';
}

// Terms HTML
$terms_html = '';
if (!empty($terms)) {
    $all_lines = [];
    foreach ($terms as $t) {
        $parts = preg_split('/\|\||\n/', $t);
        foreach ($parts as $p) { $p = trim($p); if ($p !== '') $all_lines[] = $p; }
    }
    $all_lines = array_unique($all_lines);
    foreach ($all_lines as $idx => $line)
        $terms_html .= '<div style="margin-bottom:3px;line-height:1.5;font-size:9px;padding-left:10px;text-indent:-10px;">' . ($idx + 1) . '. ' . htmlspecialchars($line) . '</div>';
}

// ================= HTML =================
$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<style>
@page {margin: 20px 15px;}
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
.header-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.logo-cell { width: 40%; vertical-align: top; }
.company-cell { width: 60%; text-align: right; vertical-align: top; }
.logo-cell img { height: 120px; }
.company-name { font-size: 18px; font-weight: bold; }
.company-details { line-height: 1.4; font-size: 10px; }
.invoice-title { text-align: center; font-size: 18px; font-weight: bold; margin: 8px 0; }
.invoice-meta { width: 100%; margin-bottom: 8px; }
.invoice-meta .right { text-align: right; }
.address-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
.address-table th { border: 0.5px solid #000; padding: 8px; background: #f0f0f0; font-weight: bold; }
.address-table td { border: 0.5px solid #000; padding: 8px; vertical-align: top; }
.item-table { width: 100%; border-collapse: collapse; font-size: 8.5px; table-layout: fixed; }
.item-table th { border: 0.5px solid #000; padding: 3px 2px; background: #f0f0f0; text-align: center; font-weight: bold; word-wrap: break-word; }
.item-table td { border: 0.5px solid #000; padding: 3px 2px; vertical-align: middle; word-wrap: break-word; }
.item-table .desc { text-align: left; white-space: pre-line; }
.item-table .right { text-align: right; }
.item-table .footer-row td { background: #f5f5f5; font-weight: bold; }
</style>
</head>
<body>
<table style="width:100%;font-size:9px;font-family:DejaVu Sans,sans-serif;margin-bottom:8px;border-collapse:collapse;">
<tr>
<td style="text-align:left;color:#666;">' . date("d/m/Y, h:i A") . '</td>
<td style="text-align:right;color:#666;">QT_' . preg_replace('/[^a-zA-Z0-9]+/', '_', trim($quot['customer_name'] ?? '')) . '_' . preg_replace('/[^a-zA-Z0-9]+/', '_', trim($quot['quot_number'] ?? '')) . '_' . date("d-m-Y") . '_ELTRIVE</td>
</tr>
</table>
<table class="header-table" style="width:100%; border:none; border-collapse:collapse;">
<tr>
    <td class="logo-cell" style="border:none; vertical-align:top; width:40%;">
        ' . ($logoBase64 ? '<img src="' . $logoBase64 . '" style="height:120px;">' : '') . '
    </td>
    <td class="company-cell" style="border:none; vertical-align:top; width:60%;">
        <div class="company-name" style="font-size:18px; font-weight:bold;">
            ' . htmlspecialchars($company['company_name']) . '
        </div>
        <div class="company-details" style="line-height:1.4; font-size:9px; margin-top:2px;">
            ' . htmlspecialchars($company['address_line1']) . '<br>' .
            (!empty($company['address_line2']) ? htmlspecialchars($company['address_line2']) . '<br>' : '') .
            htmlspecialchars($company['city']) . ', ' . htmlspecialchars($company['state']) . ' - ' . htmlspecialchars($company['pincode']) . '<br>
            <strong>PAN :</strong> ' . htmlspecialchars($company['pan']) . '<br>
            <strong>GSTIN :</strong> ' . htmlspecialchars($company['gst_number']) . '<br>
            <strong>Phone :</strong> ' . htmlspecialchars($company['phone']) . '<br>
            <strong>Email :</strong> ' . htmlspecialchars($company['email']) . '
        </div>
    </td>
</tr>
</table>
<div class="invoice-title">QUOTATION</div>

<div class="invoice-meta">
<div class="right">
<strong>Quot No. :</strong> ' . htmlspecialchars($quot['quot_number']) . '<br>
<strong>Date :</strong> ' . $quotDate . '<br>
<strong>Ref :</strong> ' . htmlspecialchars($quot['reference'] ?? '') . '
</div>
</div>

<table class="address-table">
<tr>
<th width="50%">Billing Address</th>
<th width="50%">Shipping Address</th>
</tr>
<tr>
<td>' . $to_html . '</td>
<td>' . $ship_html . '</td>
</tr>
</table>

<table class="item-table" style="margin-top:8px;">
<thead>
<tr>
<th>Service Code</th>
<th>HSN/SAC</th>
<th>Material Description</th>
<th>UOM</th>
<th>Qty</th>
<th>Unit Price</th>
<th>Basic Amount</th>
' . ($hasSGST ? "<th>SGST</th>" : "") . '' . ($hasCGST ? "<th>CGST</th>" : "") . '' . ($hasIGST ? "<th>IGST</th>" : "") . '
<th>TCS Value %</th>
<th>Total</th>
</tr>
</thead>
<tbody>' . $item_rows_html . '
<tr class="footer-row">
<td colspan="6" class="right"><strong>Subtotal</strong></td>
<td class="right">' . number_format($subtotalBasic, 2) . '</td>
' . ($hasSGST ? '<td class="right">' . number_format($subtotalSGST, 2) . '</td>' : '') . '
' . ($hasCGST ? '<td class="right">' . number_format($subtotalCGST, 2) . '</td>' : '') . '
' . ($hasIGST ? '<td class="right">' . number_format($subtotalIGST, 2) . '</td>' : '') . '
<td></td>
<td class="right">' . number_format($grandTotal, 2) . '</td>
</tr>
<tr class="footer-row">
<td colspan="' . (8 + ($hasSGST ? 1 : 0) + ($hasCGST ? 1 : 0) + ($hasIGST ? 1 : 0)) . '" style="text-align:center; vertical-align:middle; border:0.5px solid #000; padding:4px;"><strong>Grand Total (in words): ' . htmlspecialchars($amountInWords) . '</strong></td>
<td class="right">' . number_format($grandTotal, 2) . '</td>
</tr>
</tbody>
</table>

<table width="100%" style="border-collapse:collapse;font-size:9px;margin-top:8px;">
<tr>
<td style="border:0.5px solid #000; padding:6px 8px; width:45%; vertical-align:top; font-size:9px; line-height:1.3;">
<b style="font-size:10.5px;">Bank Details</b><br>
<strong>Bank Name:</strong> ' . htmlspecialchars($bank['bank_name'] ?? '') . '<br>
<strong>Account Number:</strong> ' . htmlspecialchars($bank['account_no'] ?? $bank['account_number'] ?? '') . '<br>
<strong>IFSC Code:</strong> ' . htmlspecialchars($bank['ifsc_code'] ?? '') . '<br>
<strong>Branch:</strong> ' . htmlspecialchars($bank['branch'] ?? '') . '
</td>
<td style="border:0.5px solid #000; padding:6px 8px; width:30%; vertical-align:top; font-size:9px; line-height:1.3;">
<b style="font-size:10.5px;">Total Amount (In Words)</b><br>
Rupees ' . htmlspecialchars($totalInWords) . ' Only
</td>
<td style="border:0.5px solid #000; padding:0; width:25%; vertical-align:top;">
<table width="100%" style="border-collapse:collapse;font-size:9px;margin-top:0;">
<tr>
<td style="border-bottom:0.5px solid #000; padding:4px 8px;">Total Before Tax</td>
<td style="border-bottom:0.5px solid #000; padding:4px 8px; text-align:right;">' . number_format($subtotalBasic, 2) . '</td>
</tr>
<tr>
<td style="border-bottom:0.5px solid #000; padding:4px 8px;">CGST</td>
<td style="border-bottom:0.5px solid #000; padding:4px 8px; text-align:right;">' . number_format($subtotalCGST, 2) . '</td>
</tr>
<tr>
<td style="border-bottom:0.5px solid #000; padding:4px 8px;">SGST</td>
<td style="border-bottom:0.5px solid #000; padding:4px 8px; text-align:right;">' . number_format($subtotalSGST, 2) . '</td>
</tr>
<tr>
<td style="padding:4px 8px; font-weight:bold;">Grand Total</td>
<td style="padding:4px 8px; text-align:right; font-weight:bold;">' . number_format($grandTotal, 0) . '</td>
</tr>
</table>
</td>
</tr>
</table>';

// Terms & Notes
$terms_block = '';
if ($terms_html) $terms_block .= '<strong>Terms &amp; Conditions:</strong><br>' . $terms_html;
if (!empty($quot['notes'])) $terms_block .= '<div style="margin-top:4px;"><strong>Notes:</strong> ' . nl2br(htmlspecialchars($quot['notes'])) . '</div>';
if ($terms_block) $html .= '<div style="margin-top:8px;font-size:9px;border:0.5px solid #000;padding:5px 8px;">' . $terms_block . '</div>';

$html .= '
<table style="width:100%;border-collapse:collapse;border:0.5px solid #000;font-size:9px;font-family:Arial,sans-serif;margin-top:8px;">
    <tr>
        <td style="border:0.5px solid #000; padding:20px 8px 6px 8px; width:50%; vertical-align:bottom; font-size:9px;">
            This is a computer-generated quotation. E. &amp; O. E.
        </td>
        <td style="border:0.5px solid #000; padding:8px; width:50%; text-align:right; vertical-align:top; font-size:9px;">
            For, ' . htmlspecialchars($company['company_name']) . '<br><br>
            ' . ($signatureBase64 ? '<img src="' . $signatureBase64 . '" style="max-height:75px; max-width:175px; object-fit:contain; display:inline-block;" /><br>' : '<br><br><br>') . '
            <strong>Authorised Signatory</strong>
        </td>
    </tr>
</table>';

// HSN Summary table
$html .= (function() use ($calc) {
    $hsnGroups = [];
    foreach ($calc as $c) {
        $hsn = trim($c['item']['hsn_sac'] ?? ''); $hsn = $hsn !== '' ? $hsn : '—';
        $key = $hsn . '|' . $c['cgst_p'] . '|' . $c['sgst_p'] . '|' . $c['igst_p'];
        if (!isset($hsnGroups[$key])) {
            $hsnGroups[$key] = ['hsn'=>$hsn,'taxable'=>0,'cgst_amt'=>0,'sgst_amt'=>0,'igst_amt'=>0,
                                'cgst_pcts'=>[],'sgst_pcts'=>[],'igst_pcts'=>[]];
        }
        $hsnGroups[$key]['taxable']  += $c['taxable'];
        $hsnGroups[$key]['cgst_amt'] += $c['cgst_a'];
        $hsnGroups[$key]['sgst_amt'] += $c['sgst_a'];
        $hsnGroups[$key]['igst_amt'] += $c['igst_a'];
        if ($c['taxable'] > 0) {
            $hsnGroups[$key]['cgst_pcts'][] = $c['cgst_p'];
            $hsnGroups[$key]['sgst_pcts'][] = $c['sgst_p'];
            $hsnGroups[$key]['igst_pcts'][] = $c['igst_p'];
        }
    }
    $b  = "border:1px solid #555;padding:6px 10px;font-size:11px;";
    $ts = "width:auto;border-collapse:collapse;border:1px solid #555;font-size:11px;font-family:Arial,sans-serif;margin-top:6px;";
    $out  = "<style>.hsn-table thead { display: table-row-group !important; }</style>";
    $out .= "<table class=\"hsn-table\" style=\"$ts\">";
    $out .= "<thead><tr style=\"background:#f2f2f2;font-weight:bold;font-size:10px;\">";
    $out .= "<th style=\"{$b}text-align:center;\">HSN/SAC</th>";
    $out .= "<th style=\"{$b}text-align:right;\">Taxable (&#8377;)</th>";
    $out .= "<th style=\"{$b}text-align:center;\">CGST%</th><th style=\"{$b}text-align:right;\">CGST (&#8377;)</th>";
    $out .= "<th style=\"{$b}text-align:center;\">SGST%</th><th style=\"{$b}text-align:right;\">SGST (&#8377;)</th>";
    $out .= "<th style=\"{$b}text-align:center;\">IGST%</th><th style=\"{$b}text-align:right;\">IGST (&#8377;)</th>";
    $out .= "<th style=\"{$b}text-align:right;\">Total Tax (&#8377;)</th>";
    $out .= "</tr></thead><tbody>";
    foreach ($hsnGroups as $row) {
        $taxAmt = $row['cgst_amt'] + $row['sgst_amt'] + $row['igst_amt'];
        $cgst_total_pct = array_sum(array_unique($row['cgst_pcts']));
        $sgst_total_pct = array_sum(array_unique($row['sgst_pcts']));
        $igst_total_pct = array_sum(array_unique($row['igst_pcts']));
        $out .= "<tr>";
        $out .= "<td style=\"{$b}text-align:center;\">" . htmlspecialchars($row['hsn']) . "</td>";
        $out .= "<td style=\"{$b}text-align:right;\">" . number_format($row['taxable'], 2) . "</td>";
        $out .= "<td style=\"{$b}text-align:center;\">" . ($cgst_total_pct > 0 ? $cgst_total_pct . '%' : '0%') . "</td><td style=\"{$b}text-align:right;\">" . number_format($row['cgst_amt'], 2) . "</td>";
        $out .= "<td style=\"{$b}text-align:center;\">" . ($sgst_total_pct > 0 ? $sgst_total_pct . '%' : '0%') . "</td><td style=\"{$b}text-align:right;\">" . number_format($row['sgst_amt'], 2) . "</td>";
        $out .= "<td style=\"{$b}text-align:center;\">" . ($igst_total_pct > 0 ? $igst_total_pct . '%' : '0%') . "</td><td style=\"{$b}text-align:right;\">" . number_format($row['igst_amt'], 2) . "</td>";
        $out .= "<td style=\"{$b}text-align:right;\">" . number_format($taxAmt, 2) . "</td>";
        $out .= "</tr>";
    }
    $out .= "</tbody></table>";
    return $out;
})();

$html .= '</body></html>';

// Single copy — no Original/Duplicate/Triplicate labels

// ================= PDF Render =================
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Watermark on all pages
$canvas       = $dompdf->getCanvas();
$canvasWidth  = $canvas->get_width();
$canvasHeight = $canvas->get_height();

if (file_exists($watermarkPath)) {
    $wmWidth  = 300;
    $wmHeight = 300;
    $x = ($canvasWidth  - $wmWidth)  / 2;
    $y = ($canvasHeight - $wmHeight) / 2;

    $canvas->page_script(function($pageNumber, $pageCount, $canvas, $fontMetrics) use ($watermarkPath, $x, $y, $wmWidth, $wmHeight) {
        $canvas->set_opacity(0.08);
        $canvas->image($watermarkPath, $x, $y, $wmWidth, $wmHeight);
        $canvas->set_opacity(1);
    });
}

$customerSlug = preg_replace('/[^a-zA-Z0-9]+/', '_', trim($quot['customer_name'] ?? 'Customer'));
$quotSlug     = preg_replace('/[^a-zA-Z0-9]+/', '_', trim($quot['quot_number']   ?? $id));
$dateSlug     = date('d-m-Y');
$filename     = "QT_{$customerSlug}_{$quotSlug}_{$dateSlug}_ELTRIVE.pdf";

$dompdf->stream($filename, ["Attachment" => true]);
exit;
?>

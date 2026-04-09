<?php
// download.php - Generate PDF Invoice

error_reporting(E_ALL);
ini_set('display_errors', 1);

ob_start();
date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$watermarkPath = 'assets/watermark.png';


// Format address
function formatAddress($address)
{
    return htmlspecialchars(preg_replace("/\r\n|\r|\n/", ', ', trim($address)));
}

// Number to Words (Indian format)
function numberToWords($number)
{
    $decimal = round($number - ($no = floor($number)), 2) * 100;
    $digits_length = strlen($no);
    $i = 0;
    $str = [];
    $words = [
        0 => '',
        1 => 'One',
        2 => 'Two',
        3 => 'Three',
        4 => 'Four',
        5 => 'Five',
        6 => 'Six',
        7 => 'Seven',
        8 => 'Eight',
        9 => 'Nine',
        10 => 'Ten',
        11 => 'Eleven',
        12 => 'Twelve',
        13 => 'Thirteen',
        14 => 'Fourteen',
        15 => 'Fifteen',
        16 => 'Sixteen',
        17 => 'Seventeen',
        18 => 'Eighteen',
        19 => 'Nineteen',
        20 => 'Twenty',
        30 => 'Thirty',
        40 => 'Forty',
        50 => 'Fifty',
        60 => 'Sixty',
        70 => 'Seventy',
        80 => 'Eighty',
        90 => 'Ninety'
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

    $Rupees = trim(implode('', array_reverse($str)));
    return $Rupees !== '' ? $Rupees . ' Rupees' : 'Zero Rupees';
}

// Invoice ID
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0)
    die("Invalid Invoice ID");

// Fetch invoice
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = :id");
$stmt->execute(['id' => $id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice)
    die("Invalid Invoice");
// Company Info — start with global settings
$company = $pdo->query("
    SELECT company_name, company_logo, address_line1, address_line2,
           city, state, pincode, gst_number, cin_number,
           phone, email, pan, website
    FROM invoice_company LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Apply per-invoice company override (if set)
if (!empty($invoice['company_override'])) {
    $override = json_decode($invoice['company_override'], true);
    if (is_array($override)) {
        foreach ($override as $key => $val) {
            if ($val !== '' && $val !== null) {
                $company[$key] = $val;
            }
        }
    }
}


/* ================= FETCH BANK DETAILS ================= */
$bankData = [];
if (!empty($invoice['bank_id'])) {
    $stmtBank = $pdo->prepare("SELECT * FROM bank_details WHERE id = ?");
    $stmtBank->execute([$invoice['bank_id']]);
    $bankData = $stmtBank->fetch(PDO::FETCH_ASSOC);
}
// Company logo
$logoBase64 = '';
if (!empty($company['company_logo'])) {
    $logoFile = __DIR__ . '/' . ltrim($company['company_logo'], '/');
    if (file_exists($logoFile)) {
        $ext = pathinfo($logoFile, PATHINFO_EXTENSION);
        $data = file_get_contents($logoFile);
        $logoBase64 = 'data:image/' . $ext . ';base64,' . base64_encode($data);
    }
}


// Fetch signature
$signatureBase64 = '';
if (!empty($invoice['signature_id'])) {
    $sigStmt = $pdo->prepare("SELECT file_path FROM signatures WHERE id = ?");
    $sigStmt->execute([$invoice['signature_id']]);
    $sigData = $sigStmt->fetch(PDO::FETCH_ASSOC);
    if ($sigData && !empty($sigData['file_path'])) {
        $sigFile = __DIR__ . '/' . ltrim($sigData['file_path'], '/');
        if (file_exists($sigFile)) {
            $ext = pathinfo($sigFile, PATHINFO_EXTENSION);
            $data = file_get_contents($sigFile);
            $signatureBase64 = 'data:image/' . $ext . ';base64,' . base64_encode($data);
        }
    }
}

// Invoice Date
$invoiceDate = !empty($invoice['invoice_date']) ? date('d-M-Y', strtotime($invoice['invoice_date'])) : date('d-M-Y');

// Fetch items — from invoice_amounts (permanent per-invoice snapshot)
// JOIN items table to get item_name as well as description
$items = [];
$iaStmt = $pdo->prepare("
    SELECT ia.*,
           COALESCE(NULLIF(i.item_name,''), '') AS item_name,
           COALESCE(NULLIF(ia.description,''), i.material_description, '') AS resolved_description
    FROM invoice_amounts ia
    LEFT JOIN items i ON i.id = ia.item_id
    WHERE ia.invoice_id = ?
    ORDER BY ia.id ASC
");
$iaStmt->execute([$invoice['id']]);
$iaRows = $iaStmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($iaRows)) {
    foreach ($iaRows as $row) {
        $basic = floatval($row['basic_amount'] ?? 0);
        $cgst_amt = floatval($row['cgst_amount'] ?? 0);
        $sgst_amt = floatval($row['sgst_amount'] ?? 0);
        $igst_amt = floatval($row['igst_amount'] ?? 0);
        $cgst_pct = floatval($row['cgst_percent'] ?? 0);
        $sgst_pct = floatval($row['sgst_percent'] ?? 0);
        $igst_pct = floatval($row['igst_percent'] ?? 0);
        $items[] = [
            'service_code' => $row['service_code'] ?? '',
            'hsn_sac' => $row['hsn_sac'] ?? '',
            'item_name' => $row['item_name'] ?? '',
            'material_description' => $row['resolved_description'] ?? '',
            'uom' => $row['uom'] ?? '',
            'qty' => floatval($row['qty'] ?? 1),
            'delivery_date' => '',
            'unit_price' => floatval($row['unit_price'] ?? 0),
            'basic_amount' => $basic,
            'sgst' => $sgst_amt,
            'cgst' => $cgst_amt,
            'igst' => $igst_amt,
            'cgst_pct' => $cgst_pct,
            'sgst_pct' => $sgst_pct,
            'igst_pct' => $igst_pct,
            'tcs_percent' => floatval($row['tcs_percent'] ?? 0),
            'total' => floatval($row['total'] ?? 0),
        ];
    }
} else {
    // Fallback for old invoices — read from items master table
    if (!empty($invoice['item_list'])) {
        preg_match_all('/\d+/', $invoice['item_list'], $matches);
        $item_ids = array_map('intval', $matches[0]);
        if ($item_ids) {
            $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
            $itemStmt = $pdo->prepare("SELECT id, service_code,
                    COALESCE(NULLIF(item_name,''), '') AS item_name,
                    material_description, hsn_sac, uom, qty, unit_price,
                    basic_amount, sgst, cgst, igst, tcs_percent, total
                    FROM items WHERE id IN ($placeholders)
                    ORDER BY FIELD(id," . implode(',', $item_ids) . ")");
            $itemStmt->execute($item_ids);
            foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $master) {
                $basic = floatval($master['basic_amount'] ?? 0);
                $cgst_amt = floatval($master['cgst'] ?? 0);
                $sgst_amt = floatval($master['sgst'] ?? 0);
                $igst_amt = floatval($master['igst'] ?? 0);
                $items[] = [
                    'service_code' => $master['service_code'],
                    'hsn_sac' => $master['hsn_sac'] ?? '',
                    'item_name' => $master['item_name'] ?? '',
                    'material_description' => $master['material_description'] ?? '',
                    'uom' => $master['uom'] ?? '',
                    'qty' => floatval($master['qty'] ?? 1),
                    'delivery_date' => '',
                    'unit_price' => floatval($master['unit_price'] ?? 0),
                    'basic_amount' => $basic,
                    'sgst' => $sgst_amt,
                    'cgst' => $cgst_amt,
                    'igst' => $igst_amt,
                    'cgst_pct' => ($basic > 0) ? round(($cgst_amt / $basic) * 100, 2) : 0,
                    'sgst_pct' => ($basic > 0) ? round(($sgst_amt / $basic) * 100, 2) : 0,
                    'igst_pct' => ($basic > 0) ? round(($igst_amt / $basic) * 100, 2) : 0,
                    'tcs_percent' => floatval($master['tcs_percent'] ?? 0),
                    'total' => floatval($master['total'] ?? 0),
                ];
            }
        }
    }
}

// Calculate totals
$subtotalBasic = $subtotalSGST = $subtotalCGST = $subtotalIGST = $grandTotal = 0;
$hasCGST = $hasSGST = $hasIGST = true; // always show all columns; shows 0 if not applicable
foreach ($items as $item) {
    $subtotalBasic += $item['basic_amount'];
    $subtotalSGST += $item['sgst'];
    $subtotalCGST += $item['cgst'];
    $subtotalIGST += $item['igst'];
    $grandTotal += $item['total'];
}

$displayGrandTotal = round($grandTotal);
$amountInWords = numberToWords($displayGrandTotal);
$totalInWords = $amountInWords;

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
.item-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9px;
    table-layout: auto; /* مهم: allows auto fit */
}

.item-table th {
    border: 0.5px solid #000;
    padding: 4px 3px;  /* reduced padding */
    background: #f0f0f0;
    text-align: center;
    font-weight: bold;
    white-space: nowrap;
}

.item-table td {
    border: 0.5px solid #000;
    padding: 3px 3px;
    vertical-align: middle;
    white-space: normal;   /* ✅ allow wrapping */
    word-break: break-word; /* ✅ prevent cut */
}

.item-table .desc {
    text-align: left;
    white-space: normal;   /* allow wrapping */
    line-height: 1.3;
}
.item-table .right { text-align: right; }
.item-table .footer-row td { background: #f5f5f5; font-weight: bold; }
.item-table td.right {
    text-align: right;
    white-space: nowrap; /* keep numbers in one line */
}
</style>
</html>
<body>
<table style="width:100%;font-size:9px;font-family:DejaVu Sans,sans-serif;margin-bottom:8px;border-collapse:collapse;">
<tr>
<td style="text-align:left;color:#666;">' . date("d/m/Y, h:i A") . '</td>
<td style="text-align:right;color:#666;">Invoice_' . preg_replace('/[^a-zA-Z0-9]+/', '_', trim($invoice['customer'] ?? '')) . '_' . preg_replace('/[^a-zA-Z0-9]+/', '_', trim($invoice['invoice_number'] ?? '')) . '_' . date("d-m-Y") . '_ELTRIVE</td>
</tr>
</table>
<table class="header-table" style="width:100%; border:none; border-collapse:collapse;">
<tr>
    <!-- Logo on the left -->
    <td class="logo-cell" style="border:none; vertical-align:top; width:40%;">
        ' . ($logoBase64 ? '<img src="' . $logoBase64 . '" style="height:120px;">' : '') . '
    </td>

    <!-- Company details on the right -->
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

        <!-- COPY_LABEL below company details, left-aligned -->
        
    </td>
</tr>
</table>
<div class="invoice-title">TAX INVOICE</div>

<div class="invoice-meta">
<div class="right">
<strong>Invoice No :</strong> ' . htmlspecialchars($invoice['invoice_number']) . '<br>
<strong>Date :</strong> ' . $invoiceDate . '<br>
<strong>PO No :</strong> ' . htmlspecialchars($invoice['po_number'] ?? '') . '<br>
<strong>Ref :</strong> ' . htmlspecialchars($invoice['reference'] ?? '') . '
</div>
</div>

<table class="address-table">
<tr>
<th width="50%">Billing Address</th>
<th width="50%">Shipping Address</th>
</tr>
<tr>
<td>
<strong>' . htmlspecialchars($invoice['customer']) . '</strong><br>
' . formatAddress($invoice['billing_address']) . '
</td>
<td>

' . formatAddress($invoice['shipping_address']) . '
</td>
</tr>
</table>

<table class="item-table" style="margin-top:8px;">
<colgroup>
<col style="width:4%;">   <!-- S.No -->
<col style="width:7%;">   <!-- HSN -->
<col style="width:42%;">  <!-- Description (MORE SPACE) -->
<col style="width:5%;">   <!-- UOM -->
<col style="width:5%;">   <!-- Qty -->
<col style="width:9%;">   <!-- Unit Price -->
<col style="width:8%;">   <!-- Basic (REDUCED) -->
<col style="width:5%;">   <!-- SGST -->
<col style="width:5%;">   <!-- CGST -->
<col style="width:5%;">   <!-- IGST (REDUCED) -->
<col style="width:10%;">  <!-- Total -->
</colgroup>
<thead>
<tr>
<th>S.No</th>
<th>HSN/SAC</th>
<th>Item Name &amp; Description</th>
<th>UOM</th>
<th>Qty</th>
<th>Unit Price</th>
<th>Basic Amount</th>
' . ($hasSGST ? "<th>SGST</th>" : "") . '' . ($hasCGST ? "<th>CGST</th>" : "") . '' . ($hasIGST ? "<th>IGST</th>" : "") . '
<th>Total</th>
</tr>
</thead>
<tbody>';

foreach ($items as $index => $item) {
    $html .= '<tr>
<td class="right">' . ($index + 1) . '</td>
<td>' . htmlspecialchars($item['hsn_sac']) . '</td>
<td class="desc">
    <div style="font-weight:600; font-size:9px;">
        ' . htmlspecialchars($item['item_name']) . '
    </div>
    ' . (!empty(trim($item['material_description'])) && trim($item['material_description']) !== trim($item['item_name']) ? '<div style="font-size:8px; color:#444; line-height:1.3;">' . htmlspecialchars($item['material_description']) . '</div>' : '') . '
</td>
<td>' . htmlspecialchars($item['uom']) . '</td>
<td class="right">' . number_format($item['qty'], 3) . '</td>
<td class="right">' . number_format($item['unit_price'], 2) . '</td>
<td class="right">' . number_format($item['basic_amount'], 2) . '</td>
' . ($hasSGST ? '<td class="right">' . number_format($item['sgst'], 2) . '</td>' : '') . '' . ($hasCGST ? '<td class="right">' . number_format($item['cgst'], 2) . '</td>' : '') . '' . ($hasIGST ? '<td class="right">' . number_format($item['igst'], 2) . '</td>' : '') . '
<td class="right">' . number_format($item['total'], 2) . '</td>
</tr>';
}

$html .= '
<tr class="footer-row">
<td colspan="6" class="right"><strong>Subtotal</strong></td>
<td class="right">' . number_format($subtotalBasic, 2) . '</td>
' . ($hasSGST ? '<td class="right">' . number_format($subtotalSGST, 2) . '</td>' : '') . '' . ($hasCGST ? '<td class="right">' . number_format($subtotalCGST, 2) . '</td>' : '') . '' . ($hasIGST ? '<td class="right">' . number_format($subtotalIGST, 2) . '</td>' : '') . '
<td class="right">' . number_format($displayGrandTotal, 0) . '</td>
</tr>
<tr class="footer-row">
<td colspan=' . (7 + ($hasSGST ? 1 : 0) + ($hasCGST ? 1 : 0) + ($hasIGST ? 1 : 0)) . '" style="text-align:center; vertical-align:middle; border:0.5px solid #000; padding:4px;"><strong>Grand Total (in words): ' . htmlspecialchars($amountInWords) . ' Only</strong></td>
<td class="right">' . number_format($displayGrandTotal, 0) . '</td>
</tr>
</tbody>
</table>
<table width="100%" style="border-collapse:collapse;font-size:9px;margin-top:8px;">
<tr>

<td style="border:0.5px solid #000; padding:6px 8px; width:45%; vertical-align:top; font-size:9px; line-height:1.3;">
<b style="font-size:10.5px;">Bank Details</b><br>
<strong>Bank Name:</strong> ' . htmlspecialchars($bankData['bank_name'] ?? '') . '<br>
<strong>Account Number:</strong> ' . htmlspecialchars($bankData['account_no'] ?? '') . '<br>
<strong>IFSC Code:</strong> ' . htmlspecialchars($bankData['ifsc_code'] ?? '') . '<br>
<strong>Branch:</strong> ' . htmlspecialchars($bankData['branch'] ?? '') . '
</td>

<td style="border:0.5px solid #000; padding:6px 8px; width:30%; vertical-align:top; font-size:9px; line-height:1.3;">
<b style="font-size:10.5px;">Total Amount (In Words)</b>
' . htmlspecialchars($totalInWords) . ' Only
</td>

<td style="border:0.5px solid #000; padding:0; width:25%; vertical-align:top;">
<table width="100%" style="border-collapse:collapse;font-size:9px;margin-top:0;">

<tr>
<td style="border-bottom:0.5px solid #000; padding:4px 8px;">Total Before Tax</td>
<td style="border-bottom:0.5px solid #000; padding:4px 8px; text-align:right;">
' . number_format($subtotalBasic ?? 0, 2) . '
</td>
</tr>

<tr>
<td style="border-bottom:0.5px solid #000; padding:4px 8px;">CGST</td>
<td style="border-bottom:0.5px solid #000; padding:4px 8px; text-align:right;">
' . number_format($subtotalCGST ?? 0, 2) . '
</td>
</tr>

<tr>
<td style="border-bottom:0.5px solid #000; padding:4px 8px;">SGST</td>
<td style="border-bottom:0.5px solid #000; padding:4px 8px; text-align:right;">
' . number_format($subtotalSGST ?? 0, 2) . '
</td>
</tr>

<tr>
<td style="padding:4px 8px; font-weight:bold;">Grand Total</td>
<td style="padding:4px 8px; text-align:right; font-weight:bold;">
' . number_format($grandTotal ?? 0, 0) . '
</td>
</tr>
</table>
</td>
</tr>
</table>
<table style="width:100%;border-collapse:collapse;border:0.5px solid #000;font-size:9px;font-family:Arial,sans-serif;margin-top:8px;">
    <tr>
        <td style="border:0.5px solid #000; padding:20px 8px 6px 8px; width:50%; vertical-align:bottom; font-size:9px;">
            This is a computer-generated invoice. E. &amp; O. E.
        </td>
        <td style="border:0.5px solid #000; padding:8px; width:50%; text-align:center; vertical-align:middle; font-size:9px;">
            For, ' . htmlspecialchars($company['company_name'] ?? 'ELTRIVE AUTOMATIONS PVT LTD') . '<br><br>
            ' . ($signatureBase64 ? '<img src="' . $signatureBase64 . '" style="max-height:75px; max-width:175px; object-fit:contain; display:inline-block;" /><br>' : '<br><br><br>') . '
            <strong>Authorised Signatory</strong>
        </td>
    </tr>
</table>
' . (function () use ($items, $hasCGST, $hasSGST, $hasIGST, $company, $signatureBase64) {
    $hsnGroups = [];
    foreach ($items as $item) {
        $hsn = $item["hsn_sac"] ?? "";
        if (!isset($hsnGroups[$hsn])) {
            $hsnGroups[$hsn] = [
                "taxable" => 0,
                "cgst_amt" => 0,
                "sgst_amt" => 0,
                "igst_amt" => 0,
                "cgst_pcts" => [],
                "sgst_pcts" => [],
                "igst_pcts" => []
            ];
        }
        $hsnGroups[$hsn]["taxable"] += $item["basic_amount"];
        $hsnGroups[$hsn]["cgst_amt"] += $item["cgst"];
        $hsnGroups[$hsn]["sgst_amt"] += $item["sgst"];
        $hsnGroups[$hsn]["igst_amt"] += $item["igst"];
        if ($item["basic_amount"] > 0) {
            // Collect each item's rate; derive from amount if percent not stored
            $cp = $item["cgst_pct"] ?? round(($item["cgst"] / $item["basic_amount"]) * 100, 2);
            $sp = $item["sgst_pct"] ?? round(($item["sgst"] / $item["basic_amount"]) * 100, 2);
            $ip = $item["igst_pct"] ?? round(($item["igst"] / $item["basic_amount"]) * 100, 2);
            $hsnGroups[$hsn]["cgst_pcts"][] = $cp;
            $hsnGroups[$hsn]["sgst_pcts"][] = $sp;
            $hsnGroups[$hsn]["igst_pcts"][] = $ip;
        }
    }
    $b = "border:1px solid #555;padding:6px 10px;font-size:11px;";
    $ts = "width:100%;border-collapse:collapse;border:1px solid #555;font-size:11px;font-family:Arial,sans-serif;margin-top:8px;";
    $out = "<style>.hsn-table thead { display: table-row-group !important; }</style>";

    $out .= "<table class=\"hsn-table\" style=\"$ts; margin-top:0;\">";
    $out .= "<thead><tr style=\"background:#f2f2f2;font-weight:bold;font-size:10px;\">";
    $out .= "<th style=\"{$b}text-align:center;\">HSN/SAC</th>";
    $out .= "<th style=\"{$b}text-align:right;\">Taxable (&#8377;)</th>";
    $out .= "<th style=\"{$b}text-align:center;\">CGST%</th><th style=\"{$b}text-align:right;\">CGST (&#8377;)</th>";
    $out .= "<th style=\"{$b}text-align:center;\">SGST%</th><th style=\"{$b}text-align:right;\">SGST (&#8377;)</th>";
    $out .= "<th style=\"{$b}text-align:center;\">IGST%</th><th style=\"{$b}text-align:right;\">IGST (&#8377;)</th>";
    $out .= "<th style=\"{$b}text-align:right;\">Total Tax (&#8377;)</th>";
    $out .= "</tr></thead><tbody>";
    $tCgst = $tSgst = $tIgst = $tTaxable = 0;
    foreach ($hsnGroups as $hsn => $row) {
        $taxAmt = $row["cgst_amt"] + $row["sgst_amt"] + $row["igst_amt"];
        $tTaxable += $row["taxable"];
        $tCgst += $row["cgst_amt"];
        $tSgst += $row["sgst_amt"];
        $tIgst += $row["igst_amt"];
        $out .= "<tr>";
        $out .= "<td style=\"{$b}text-align:center;\">" . htmlspecialchars($hsn) . "</td>";
        $out .= "<td style=\"{$b}text-align:right;\">" . number_format($row["taxable"], 2) . "</td>";
        // Sum all unique rates collected for this HSN
        $cgst_total_pct = array_sum(array_unique($row["cgst_pcts"]));
        $sgst_total_pct = array_sum(array_unique($row["sgst_pcts"]));
        $igst_total_pct = array_sum(array_unique($row["igst_pcts"]));
        $out .= "<td style=\"{$b}text-align:center;\">" . ($cgst_total_pct > 0 ? $cgst_total_pct . '%' : '0%') . "</td><td style=\"{$b}text-align:right;\">" . number_format($row["cgst_amt"], 2) . "</td>";
        $out .= "<td style=\"{$b}text-align:center;\">" . ($sgst_total_pct > 0 ? $sgst_total_pct . '%' : '0%') . "</td><td style=\"{$b}text-align:right;\">" . number_format($row["sgst_amt"], 2) . "</td>";
        $out .= "<td style=\"{$b}text-align:center;\">" . ($igst_total_pct > 0 ? $igst_total_pct . '%' : '0%') . "</td><td style=\"{$b}text-align:right;\">" . number_format($row["igst_amt"], 2) . "</td>";
        $out .= "<td style=\"{$b}text-align:right;\">" . number_format($taxAmt, 2) . "</td>";
        $out .= "</tr>";
    }
    $out .= "</tbody></table>";

    return $out;
})() . '
</body>
</html>';
// Save your original HTML
$originalHtml = $html;

// Wrap in 3 copies
$html = '<html><head><meta charset="UTF-8"><style>
body{font-family: DejaVu Sans,sans-serif;}
.header-table, .address-table, .item-table { width:100%; border-collapse: collapse; }
.header-table td, .address-table td, .address-table th, .item-table td, .item-table th { border:1px solid #000; padding:5px; }
.item-table th { background:#f0f0f0; font-weight:bold; text-align:center; }
.item-table .right {
    text-align: right;
}
.item-table .desc { text-align:left; white-space:pre-line; }
.footer-row td { background:#f5f5f5; font-weight:bold; }
</style></head><body>';
$html .= generateInvoiceCopy('Original', $originalHtml);
$html .= generateInvoiceCopy('Duplicate', $originalHtml);
$html .= generateInvoiceCopy('Triplicate', $originalHtml);
$html .= '</body></html>';

// ================= PDF Render =================
// Function to generate one copy with label
function generateInvoiceCopy($label, $html)
{
    // Insert label below invoice title instead of company details
    $htmlWithLabel = preg_replace(
        '/(<div class="invoice-title".*?<\/div>)/s',
        '$1
        <div style="font-size:13px; font-weight:bold; margin-top:5px; text-align:right;">
            ' . $label . '
        </div>',
        $html,
        1
    );

    // Wrap in a div for page break
    $copyHtml = '<div style="page-break-after: always;">';
    $copyHtml .= $htmlWithLabel;
    $copyHtml .= '</div>';

    return $copyHtml;
}
$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Get canvas
$canvas = $dompdf->getCanvas();
$canvasWidth = $canvas->get_width();
$canvasHeight = $canvas->get_height();

// Watermark on all pages
if (file_exists($watermarkPath)) {
    $wmWidth = 300;
    $wmHeight = 300;
    $x = ($canvasWidth - $wmWidth) / 2;
    $y = ($canvasHeight - $wmHeight) / 2;

    $canvas->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) use ($watermarkPath, $x, $y, $wmWidth, $wmHeight) {
        $canvas->set_opacity(0.08);
        $canvas->image($watermarkPath, $x, $y, $wmWidth, $wmHeight);
        $canvas->set_opacity(1);
    });
}

$customerSlug = preg_replace('/[^a-zA-Z0-9]+/', '_', trim($invoice['customer'] ?? 'Customer'));
$invoiceSlug = preg_replace('/[^a-zA-Z0-9]+/', '_', trim($invoice['invoice_number'] ?? $id));
$dateSlug = date('d-m-Y');
$filename = "Invoice_{$customerSlug}_{$invoiceSlug}_{$dateSlug}_ELTRIVE.pdf";
$dompdf->stream($filename, ["Attachment" => true]);
exit;
?>

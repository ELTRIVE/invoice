<?php
// download_word.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';

// ── Number to Words ──
function numberToWords($number) {
    $decimal = round($number - ($no = floor($number)), 2) * 100;
    $digits_length = strlen($no);
    $i = 0; $str = [];
    $words = [0=>'',1=>'One',2=>'Two',3=>'Three',4=>'Four',5=>'Five',6=>'Six',7=>'Seven',
              8=>'Eight',9=>'Nine',10=>'Ten',11=>'Eleven',12=>'Twelve',13=>'Thirteen',
              14=>'Fourteen',15=>'Fifteen',16=>'Sixteen',17=>'Seventeen',18=>'Eighteen',
              19=>'Nineteen',20=>'Twenty',30=>'Thirty',40=>'Forty',50=>'Fifty',
              60=>'Sixty',70=>'Seventy',80=>'Eighty',90=>'Ninety'];
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
                ? $words[$number].' '.$digits[$counter].$plural.' '.$hundred
                : $words[floor($number/10)*10].' '.$words[$number%10].' '.$digits[$counter].$plural.' '.$hundred;
        } else { $str[] = null; }
    }
    $Rupees = trim(implode('', array_reverse($str)));
    return $Rupees !== '' ? $Rupees.' Rupees Only' : 'Zero Rupees Only';
}

// ── Fetch invoice ──
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) die("Invalid Invoice ID");

$stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = :id");
$stmt->execute(['id' => $id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$invoice) die("Invoice not found");

// ── Company info ──
$company = $pdo->query("SELECT company_name, address_line1, address_line2, city, state, pincode, gst_number, phone, email
                         FROM invoice_company LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// ── Fetch real items from invoice_amounts ──
$items = [];
$iaStmt = $pdo->prepare("SELECT * FROM invoice_amounts WHERE invoice_id = ? AND (service_code != 'PAYMENT' OR service_code IS NULL) ORDER BY id ASC");
$iaStmt->execute([$invoice['id']]);
$iaRows = $iaStmt->fetchAll(PDO::FETCH_ASSOC);

if (!empty($iaRows)) {
    foreach ($iaRows as $row) {
        $items[] = [
            'service_code'         => $row['service_code']        ?? '',
            'hsn_sac'              => $row['hsn_sac']              ?? '',
            'material_description' => $row['description']          ?? '',
            'uom'                  => $row['uom']                  ?? '',
            'qty'                  => floatval($row['qty']         ?? 1),
            'unit_price'           => floatval($row['unit_price']  ?? 0),
            'total'                => floatval($row['total']       ?? 0),
        ];
    }
} else if (!empty($invoice['item_list'])) {
    // Fallback to items master table
    preg_match_all('/\d+/', $invoice['item_list'], $matches);
    $item_ids = array_map('intval', $matches[0]);
    if ($item_ids) {
        $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
        $itemStmt = $pdo->prepare("SELECT * FROM items WHERE id IN ($placeholders)");
        $itemStmt->execute($item_ids);
        foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'service_code'         => $row['service_code']          ?? '',
                'hsn_sac'              => $row['hsn_sac']               ?? '',
                'material_description' => $row['material_description']  ?? '',
                'uom'                  => $row['uom']                   ?? '',
                'qty'                  => floatval($row['qty']          ?? 1),
                'unit_price'           => floatval($row['unit_price']   ?? 0),
                'total'                => floatval($row['total']        ?? 0),
            ];
        }
    }
}

// ── Grand Total ──
$grandTotal = array_sum(array_column($items, 'total'));
$displayGrandTotal = round($grandTotal);
$invoiceDate = !empty($invoice['invoice_date']) ? date('d-M-Y', strtotime($invoice['invoice_date'])) : date('d-M-Y');
$h = fn($s) => htmlspecialchars((string)($s ?? ''), ENT_QUOTES);

// ── Build HTML (same sections as original PhpWord code) ──
ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<style>
  body  { font-family: Arial, sans-serif; font-size: 10pt; margin: 40px; color: #000; }
  /* ── Header: Company (right aligned) ── */
  .company-block { text-align: right; margin-bottom: 20px; font-size: 10pt; line-height: 1.7; }
  .company-block .name { font-size: 16pt; font-weight: bold; }
  /* ── Title (center) ── */
  .invoice-title { text-align: center; font-size: 18pt; font-weight: bold;
                   margin: 16px 0 10px; letter-spacing: 2px; }
  /* ── Invoice meta (right) ── */
  .invoice-meta { text-align: right; font-size: 10pt; line-height: 1.8; margin-bottom: 20px; }
  /* ── Bill To ── */
  .bill-to { font-size: 10pt; line-height: 1.7; margin-bottom: 20px; }
  .bill-to .label { font-size: 12pt; font-weight: bold; }
  /* ── Items Table ── */
  table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
  table th { border: 1px solid #000; padding: 6px 5px; background: #f0f0f0;
             font-weight: bold; text-align: center; font-size: 9.5pt; }
  table td { border: 1px solid #000; padding: 6px 5px; font-size: 9.5pt;
             vertical-align: top; }
  .center { text-align: center; }
  .right  { text-align: right; }
  .footer-row td { background: #f5f5f5; font-weight: bold; }
  /* ── Amount in words ── */
  .words { font-size: 10pt; font-weight: bold; margin-top: 10px; }
</style>
</head>
<body>

<!-- ── Company Name & Details (right aligned) — same as PhpWord companyHeader ── -->
<div class="company-block">
    <div class="name"><?= $h($company['company_name'] ?? 'YOUR COMPANY NAME') ?></div>
    <?= $h(($company['address_line1'] ?? '').' '.($company['address_line2'] ?? '')) ?><br>
    <?= $h(($company['city'] ?? '').', '.($company['state'] ?? '').' - '.($company['pincode'] ?? '')) ?><br>
    <b>GSTIN:</b> <?= $h($company['gst_number'] ?? '') ?><br>
    <b>Phone:</b> <?= $h($company['phone'] ?? '') ?><br>
    <b>Email:</b> <?= $h($company['email'] ?? '') ?>
</div>

<!-- ── Title (center) — same as PhpWord title ── -->
<div class="invoice-title">TAX INVOICE</div>

<!-- ── Invoice Details (right aligned) — same as PhpWord meta ── -->
<div class="invoice-meta">
    <b>Invoice No: <?= $h($invoice['invoice_number'] ?? '') ?></b><br>
    Date: <?= $h($invoiceDate) ?>
</div>

<!-- ── Customer Address — same as PhpWord Bill To section ── -->
<div class="bill-to">
    <div class="label">Bill To:</div>
    <?= $h($invoice['customer'] ?? '') ?><br>
    <?= nl2br($h($invoice['billing_address'] ?? '')) ?><br>
    GSTIN: <?= $h($invoice['gstin'] ?? '') ?><br>
    Phone: <?= $h($invoice['mobile'] ?? '') ?>
</div>

<!-- ── Items Table — same columns as PhpWord table ── -->
<table>
<colgroup>
    <col style="width:8%;">
    <col style="width:12%;">
    <col style="width:40%;">
    <col style="width:10%;">
    <col style="width:10%;">
    <col style="width:10%;">
    <col style="width:10%;">
</colgroup>
<thead>
<tr>
    <th>S.No</th>
    <th>HSN/SAC</th>
    <th>Description</th>
    <th>UOM</th>
    <th>Qty</th>
    <th>Rate</th>
    <th>Total</th>
</tr>
</thead>
<tbody>
<?php foreach ($items as $index => $item): ?>
<tr>
    <td class="center"><?= $index + 1 ?></td>
    <td class="center"><?= $h($item['hsn_sac']) ?></td>
    <td style="white-space:pre-line;"><?= $h($item['material_description']) ?></td>
    <td class="center"><?= $h($item['uom']) ?></td>
    <td class="right"><?= number_format($item['qty'], 3) ?></td>
    <td class="right"><?= number_format($item['unit_price'], 2) ?></td>
    <td class="right"><?= number_format($item['total'], 2) ?></td>
</tr>
<?php endforeach; ?>

<!-- ── Footer row — Grand Total — same as PhpWord footer ── -->
<tr class="footer-row">
    <td colspan="5" class="right">Grand Total:</td>
    <td class="right" colspan="2"><?= number_format($displayGrandTotal, 0) ?></td>
</tr>
</tbody>
</table>

<!-- ── Amount in Words — same as PhpWord addText at end ── -->
<div class="words">Amount in Words: <?= $h(numberToWords($displayGrandTotal)) ?></div>

</body>
</html>
<?php
$html = ob_get_clean();

// ── Output — replaces IOFactory::createWriter + $writer->save ──
header('Content-Type: application/msword');
header('Content-Disposition: attachment; filename="invoice_' . ($invoice['invoice_number'] ?? $id) . '.doc"');
header('Cache-Control: max-age=0');

echo $html;
exit;

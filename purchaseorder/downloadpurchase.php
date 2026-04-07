<?php
require_once dirname(__DIR__) . '/db.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: pindex.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id=?");
$stmt->execute([$id]);
$po = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$po) { header('Location: pindex.php'); exit; }

// Read items — try items_json first, then reconstruct from item_list + po_master_items
$items = [];
if (!empty($po['items_json'])) {
    // items_json still present — use it directly
    $decoded = json_decode($po['items_json'], true);
    if (is_array($decoded)) $items = $decoded;
} elseif (!empty($po['item_list'])) {
    // items_json dropped — reconstruct from item_list IDs + po_master_items
    $id_list = json_decode($po['item_list'], true);
    if (is_array($id_list) && count($id_list) > 0) {
        // Fetch master item details for each ID (preserving duplicates)
        foreach ($id_list as $mid) {
            $ms = $pdo->prepare("SELECT * FROM po_master_items WHERE id=? LIMIT 1");
            $ms->execute([$mid]);
            $mrow = $ms->fetch(PDO::FETCH_ASSOC);
            if ($mrow) {
                $rate    = (float)($mrow['rate']     ?? 0);
                $cgst_p  = (float)($mrow['cgst_pct'] ?? 0);
                $sgst_p  = (float)($mrow['sgst_pct'] ?? 0);
                $igst_p  = (float)($mrow['igst_pct'] ?? 0);
                $taxable = $rate; // qty=1, no discount
                $cgst_a  = round($taxable * $cgst_p / 100, 2);
                $sgst_a  = round($taxable * $sgst_p / 100, 2);
                $igst_a  = round($taxable * $igst_p / 100, 2);
                $items[] = [
                    'item_id'     => (int)$mrow['id'],
                    'item_name'   => $mrow['item_name']   ?? '',
                    'description' => $mrow['description'] ?? '',
                    'hsn_sac'     => $mrow['hsn_sac']     ?? '',
                    'qty'         => 1,
                    'unit'        => $mrow['unit']        ?? '',
                    'rate'        => $rate,
                    'discount'    => 0,
                    'taxable'     => $taxable,
                    'cgst_pct'    => $cgst_p, 'cgst_amt' => $cgst_a,
                    'sgst_pct'    => $sgst_p, 'sgst_amt' => $sgst_a,
                    'igst_pct'    => $igst_p, 'igst_amt' => $igst_a,
                    'amount'      => $taxable + $cgst_a + $sgst_a + $igst_a,
                ];
            }
        }
    }
}

// Read terms from purchase_orders.terms_list -> po_master_terms
$terms = [];

if (!empty($po['terms_list'])) {

    $term_ids = json_decode($po['terms_list'], true);

    if (!empty($term_ids) && is_array($term_ids)) {

        $placeholders = implode(',', array_fill(0, count($term_ids), '?'));

        $stmt = $pdo->prepare("SELECT term_text FROM po_master_terms WHERE id IN ($placeholders)");
        $stmt->execute($term_ids);

        $terms = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Fallback: if terms_list is empty, read from po_terms

if (empty($terms)) {
    $terms = ['No terms available'];
}

$company = $pdo->query("SELECT * FROM invoice_company ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// Apply per-PO company override snapshot (if present)
if (!empty($po['company_override'])) {
    $override = json_decode($po['company_override'], true);
    if (is_array($override)) {
        foreach ($override as $key => $val) {
            if ($val !== '' && $val !== null) {
                $company[$key] = $val;
            }
        }
    }
}

$supplier_row = null;
try {
    $ss = $pdo->prepare("SELECT * FROM suppliers WHERE supplier_name=? LIMIT 1");
    $ss->execute([$po['supplier_name']]);
    $supplier_row = $ss->fetch(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

function numberToWords(float $num): string {
    $ones=['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
           'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    $tens=['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $n=(int)round($num); if($n===0) return 'Zero'; $str='';
    if($n>=10000000){$str.=numberToWords($n/10000000).' Crore ';$n%=10000000;}
    if($n>=100000)  {$str.=numberToWords($n/100000)  .' Lakh '; $n%=100000;}
    if($n>=1000)    {$str.=numberToWords($n/1000)    .' Thousand ';$n%=1000;}
    if($n>=100)     {$str.=$ones[(int)($n/100)].' Hundred ';$n%=100;}
    if($n>=20)      {$str.=$tens[(int)($n/10)];if($n%10)$str.=' '.$ones[$n%10];}
    elseif($n>0)    {$str.=$ones[$n];}
    return trim($str);
}
$amount_words = 'Rupees '.numberToWords((float)$po['grand_total']).' only';

$subtotal = $tot_cgst = $tot_sgst = $tot_igst = 0;
$calc = [];
foreach ($items as $item) {
    $qty     = (float)$item['qty'];
    $rate    = (float)$item['rate'];
    $disc    = (float)($item['discount'] ?? 0);
    $taxable = (float)($item['taxable'] ?? (($qty * $rate) - $disc));
    $cgst_p  = (float)($item['cgst_pct'] ?? 0);
    $sgst_p  = (float)($item['sgst_pct'] ?? 0);
    $igst_p  = (float)($item['igst_pct'] ?? 0);
    $cgst_a  = (float)($item['cgst_amt'] ?? round($taxable * $cgst_p / 100, 2));
    $sgst_a  = (float)($item['sgst_amt'] ?? round($taxable * $sgst_p / 100, 2));
    $igst_a  = (float)($item['igst_amt'] ?? round($taxable * $igst_p / 100, 2));
    $amt     = (float)($item['amount']   ?? $taxable + $cgst_a + $sgst_a + $igst_a);
    $subtotal += $taxable; $tot_cgst += $cgst_a; $tot_sgst += $sgst_a; $tot_igst += $igst_a;
    $calc[] = compact('item','qty','rate','disc','taxable','cgst_p','sgst_p','igst_p','cgst_a','sgst_a','igst_a','amt');
}
$grand_total = (float)$po['grand_total'] ?: ($subtotal + $tot_cgst + $tot_sgst + $tot_igst);
$has_tax = ($tot_cgst + $tot_sgst + $tot_igst) > 0;

function h($s){ return htmlspecialchars((string)($s ?? ''), ENT_QUOTES); }

$co_name    = $company['company_name']  ?? 'ELTRIVE AUTOMATIONS PVT LTD';
$co_address = $company['address_line1'] ?? '';
$co_address .= !empty($company['address_line2']) ? "\n" . $company['address_line2'] : '';
$co_address .= (!empty($company['city']) || !empty($company['state']) || !empty($company['pincode']))
    ? "\n" . trim(($company['city'] ?? '') . (empty($company['state']) ? '' : ', ' . $company['state']) . (empty($company['pincode']) ? '' : ' - ' . $company['pincode']))
    : '';
if (trim($co_address) === '') {
    $co_address = "1st floor, plot NO 33, 34 P Aditya Nagar\nColony, madinaguda village,\nHyderabad";
}
$co_pan     = $company['pan']           ?? '';
$co_gstin   = $company['gst_number']    ?? '';
$co_cin     = $company['cin_number']    ?? '';
$co_phone   = $company['phone']         ?? '';
$co_email   = $company['email']         ?? '';
$co_website = $company['website']       ?? '';
$co_logo    = $company['company_logo']  ?? '';

// ── Build logo HTML ──────────────────────────────────────────────────────────
$logo_html = '';
if ($co_logo && file_exists(dirname(__DIR__) . '/' . $co_logo)) {
    $logo_path = realpath(dirname(__DIR__) . '/' . $co_logo);
    $logo_data = base64_encode(file_get_contents($logo_path));
    $logo_mime = mime_content_type($logo_path);
    $logo_html = '<img src="data:' . $logo_mime . ';base64,' . $logo_data . '" style="width:80px;height:80px;object-fit:contain;" />';
} else {
    $logo_html = '<div style="width:80px;height:80px;background:#111;border-radius:6px;display:flex;align-items:center;justify-content:center;"><span style="color:#7fff00;font-weight:900;font-size:28px;">E</span></div>';
}


// ── Watermark path (same as download.php) ────────────────────────────────────
$watermarkPath = dirname(__DIR__) . '/assets/watermark.png';

// ── Company address ───────────────────────────────────────────────────────────
$co_addr_html = '';
foreach (array_filter(array_map('trim', explode("\n", $co_address))) as $line)
    $co_addr_html .= h($line) . '<br>';
if ($co_pan)     $co_addr_html .= '<strong>PAN:</strong> '     . h($co_pan)     . '<br>';
if ($co_gstin)   $co_addr_html .= '<strong>GSTIN:</strong> '   . h($co_gstin)   . '<br>';
if ($co_cin)     $co_addr_html .= '<strong>CIN:</strong> '     . h($co_cin)     . '<br>';
if ($co_phone)   $co_addr_html .= '<strong>Phone:</strong> '   . h($co_phone)   . '<br>';
if ($co_email)   $co_addr_html .= '<strong>Email:</strong> '   . h($co_email)   . '<br>';
if ($co_website) $co_addr_html .= '<strong>Website:</strong> ' . h($co_website) . '<br>';

// ── To/Supplier block ─────────────────────────────────────────────────────────
// ── Billing block ─────────────────────────────────────────────────────────────
$billing_html = '';
if (!empty($po['contact_person'])) $billing_html .= h($po['contact_person']) . '<br>';
$billing_html .= '<strong>' . h($po['supplier_name']) . '</strong><br>';
if (!empty($po['billing_address'])) {
    $addr_lines = array_filter(array_map('trim', explode("\n", $po['billing_address'])));
    $billing_html .= implode(', ', array_map('h', $addr_lines)) . '<br>';
}
if (!empty($po['billing_phone']))        $billing_html .= '<strong>Phone :</strong> ' . h($po['billing_phone']) . '<br>';
elseif (!empty($supplier_row['phone'])) $billing_html .= '<strong>Phone :</strong> ' . h($supplier_row['phone']) . '<br>';
if (!empty($po['billing_gstin']))        $billing_html .= '<strong>GSTIN :</strong> ' . h($po['billing_gstin']) . '<br>';
elseif (!empty($supplier_row['gstin'])) $billing_html .= '<strong>GSTIN :</strong> ' . h($supplier_row['gstin']) . '<br>';

// ── Shipping block ────────────────────────────────────────────────────────────
$shipping_html = '';
if (!empty($po['shipping_address'])) {
    $ship_lines = array_filter(array_map('trim', explode("\n", $po['shipping_address'])));
    $shipping_html .= implode(', ', array_map('h', $ship_lines)) . '<br>';
    if (!empty($po['shipping_phone'])) $shipping_html .= '<strong>Phone :</strong> ' . h($po['shipping_phone']) . '<br>';
    if (!empty($po['shipping_gstin'])) $shipping_html .= '<strong>GSTIN :</strong> ' . h($po['shipping_gstin']) . '<br>';
} else {
    $shipping_html = $billing_html;
}

// ── PO meta (plain, right-aligned — no box) ────────────────────────────────────
$po_meta_html  = '<strong>PO No. :</strong> ' . h($po['po_number']) . '<br>';
$po_meta_html .= '<strong>Date :</strong> '   . date('d-M-Y', strtotime($po['po_date'])) . '<br>';
$po_meta_html .= '<strong>Valid Till :</strong> ' . date('d-M-Y', strtotime($po['due_date'])) . '<br>';
if (!empty($po['reference'])) $po_meta_html .= '<strong>Ref. :</strong> ' . h($po['reference']) . '<br>';

// ── Tax column headers (matching quotation format) ───────────────────────────
$tax_th = '';
if ($has_tax) {
    if ($tot_cgst > 0) $tax_th .= '<th>SGST</th>';
    if ($tot_sgst > 0) $tax_th .= '<th>CGST</th>';
    if ($tot_igst > 0) $tax_th .= '<th>IGST</th>';
}


// ── Shipping info for footer ──────────────────────────────────────────────────
$shipping_info_html = '';
if (!empty($po['shipping_address']) || !empty($po['shipping_city'])) {
    $ship_parts = [];
    if (!empty($po['shipping_address'])) {
        foreach (array_filter(array_map('trim', explode("\n", $po['shipping_address']))) as $l) $ship_parts[] = h($l);
    }
    foreach (['shipping_city','shipping_state','shipping_pincode'] as $k) {
        if (!empty($po[$k])) $ship_parts[] = h($po[$k]);
    }
    $shipping_info_html = implode(', ', $ship_parts);
} else {
    if (!empty($po['billing_address'])) {
        $ship_parts = array_filter(array_map('trim', explode("\n", $po['billing_address'])));
        $shipping_info_html = implode(', ', array_map('h', $ship_parts));
    }
}
if (!empty($po['shipping_phone'])) $shipping_info_html .= ' | Phone: ' . h($po['shipping_phone']);

// ── Item rows: No. | Item & Description | Qty | Unit | Rate | Taxable | [GST] | Amount ──
$item_rows_html = '';
foreach ($calc as $i => $c) {
    $item = $c['item'];
    $nm = '<strong>' . h($item['item_name'] ?? '') . '</strong>';
    if (!empty($item['description'])) $nm .= '<br><span style="font-size:7.5px;">' . nl2br(h($item['description'])) . '</span>';
    $item_rows_html .= '<tr>';
    $item_rows_html .= '<td style="text-align:center;">' . ($i + 1) . '</td>';
    $item_rows_html .= '<td class="desc">' . $nm . '</td>';
    $item_rows_html .= '<td style="text-align:center;">' . number_format($c['qty'], 0) . '</td>';
    $item_rows_html .= '<td style="text-align:center;">' . h($item['unit'] ?? '') . '</td>';
    $item_rows_html .= '<td class="right">' . number_format($c['rate'], 2) . '</td>';
    $item_rows_html .= '<td class="right">' . number_format($c['taxable'], 2) . '</td>';
    if ($has_tax) {
        if ($tot_cgst > 0) $item_rows_html .= '<td class="right">' . number_format($c['cgst_a'], 2) . '</td>';
        if ($tot_sgst > 0) $item_rows_html .= '<td class="right">' . number_format($c['sgst_a'], 2) . '</td>';
        if ($tot_igst > 0) $item_rows_html .= '<td class="right">' . number_format($c['igst_a'], 2) . '</td>';
    }
    $item_rows_html .= '<td class="right">' . number_format($c['amt'], 2) . '</td>';
    $item_rows_html .= '</tr>';
}

// ── Terms ─────────────────────────────────────────────────────────────────────
$terms_html = '';
if (!empty($terms)) {
    $all_lines = [];
    foreach ($terms as $t) {
        $parts = preg_split('/\|\||\n/', $t);
        foreach ($parts as $p) { $p = trim($p); if ($p !== '') $all_lines[] = $p; }
    }
    $all_lines = array_unique($all_lines);
    foreach ($all_lines as $idx => $line)
        $terms_html .= '<div style="margin-bottom:3px;padding-left:10px;text-indent:-10px;">• ' . h($line) . '</div>';
}

// ── HSN Summary ───────────────────────────────────────────────────────────────
$hsnGroups = [];
foreach ($calc as $c) {
    $hsn  = trim($c['item']['hsn_sac'] ?? ''); $hsn = ($hsn !== '') ? $hsn : '—';
    $key  = $hsn . '|' . $c['cgst_p'] . '|' . $c['sgst_p'] . '|' . $c['igst_p'];
    if (!isset($hsnGroups[$key]))
        $hsnGroups[$key] = ['hsn'=>$hsn,'taxable'=>0,'cgst_amt'=>0,'sgst_amt'=>0,'igst_amt'=>0,
                            'cgst_p'=>$c['cgst_p'],'sgst_p'=>$c['sgst_p'],'igst_p'=>$c['igst_p']];
    $hsnGroups[$key]['taxable']  += $c['taxable'];
    $hsnGroups[$key]['cgst_amt'] += $c['cgst_a'];
    $hsnGroups[$key]['sgst_amt'] += $c['sgst_a'];
    $hsnGroups[$key]['igst_amt'] += $c['igst_a'];
}
$bH = 'border:0.5px solid #000;padding:3px 6px;font-size:8.5px;';
$hsn_rows = '';
foreach ($hsnGroups as $row) {
    $tax = $row['cgst_amt'] + $row['sgst_amt'] + $row['igst_amt'];
    $hsn_rows .= '<tr>';
    $hsn_rows .= '<td style="' . $bH . 'text-align:center;">' . h($row['hsn']) . '</td>';
    $hsn_rows .= '<td style="' . $bH . 'text-align:right;">' . number_format($row['taxable'],2) . '</td>';
    if ($tot_cgst>0) $hsn_rows .= '<td style="' . $bH . 'text-align:center;">' . $row['cgst_p'] . '%</td><td style="' . $bH . 'text-align:right;">' . number_format($row['cgst_amt'],2) . '</td>';
    if ($tot_sgst>0) $hsn_rows .= '<td style="' . $bH . 'text-align:center;">' . $row['sgst_p'] . '%</td><td style="' . $bH . 'text-align:right;">' . number_format($row['sgst_amt'],2) . '</td>';
    if ($tot_igst>0) $hsn_rows .= '<td style="' . $bH . 'text-align:center;">' . $row['igst_p'] . '%</td><td style="' . $bH . 'text-align:right;">' . number_format($row['igst_amt'],2) . '</td>';
    $hsn_rows .= '<td style="' . $bH . 'text-align:right;font-weight:bold;">' . number_format($tax,2) . '</td></tr>';
}

// ── Tax column headers ────────────────────────────────────────────────────────
$tax_th_new = '';
if ($has_tax) {
    if ($tot_cgst > 0) $tax_th_new .= '<th style="width:38px;">CGST (&#8377;)</th>';
    if ($tot_sgst > 0) $tax_th_new .= '<th style="width:38px;">SGST (&#8377;)</th>';
    if ($tot_igst > 0) $tax_th_new .= '<th style="width:38px;">IGST (&#8377;)</th>';
}

// ════════════════════════════════════════════════════════════════════════════
// BUILD HTML — layout matching reference PO image
// ════════════════════════════════════════════════════════════════════════════
date_default_timezone_set('Asia/Kolkata');
$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { margin: 12mm 12mm; }
body { font-family: DejaVu Sans, sans-serif; font-size: 9px; line-height: 1.4; color: #111; }
.hdr-table { width:100%; border-collapse:collapse; margin-bottom:6px; }
.hdr-logo  { width:38%; vertical-align:top; border:none; }
.hdr-info  { width:62%; text-align:right; vertical-align:top; border:none; font-size:8.5px; line-height:1.5; }
.hdr-info .co-name { font-size:16px; font-weight:bold; }
.po-title { text-align:center; font-size:15px; font-weight:bold; letter-spacing:1px; margin:6px 0 5px; padding:4px 0; }
.to-meta { width:100%; border-collapse:collapse; margin-bottom:5px; }
.to-cell  { vertical-align:top; border:none; width:55%; font-size:9px; line-height:1.6; }
.meta-cell{ vertical-align:top; border:none; width:45%; text-align:right; font-size:9px; line-height:1.7; }
.meta-cell .po-num { font-size:13px; font-weight:bold; }
.item-table { width:100%; border-collapse:collapse; font-size:8.5px; margin-top:4px; table-layout:fixed; }
.item-table th { border:0.5px solid #000; padding:4px 3px; background:#f0f0f0; text-align:center; font-weight:bold; overflow:hidden; }
.item-table td { border:0.5px solid #000; padding:4px 3px; vertical-align:top; word-wrap:break-word; overflow-wrap:break-word; }
.item-table .desc { text-align:left; }
.item-table .right { text-align:right; }
.summary-table { width:100%; border-collapse:collapse; font-size:9px; margin-top:0; }
.summary-table td { border:0.5px solid #000; padding:4px 8px; vertical-align:top; }
.totals-inner { width:100%; border-collapse:collapse; }
.totals-inner tr td { border:none; border-bottom:0.5px solid #000; padding:3px 6px; }
.totals-inner .grand-row td { border-top:0.5px solid #000; font-weight:bold; background:#f5f5f5; }
.terms-box { margin-top:4px; border:0.5px solid #000; padding:5px 8px; font-size:8.5px; line-height:1.6; }
.sig-table { width:100%; border-collapse:collapse; border:0.5px solid #000; font-size:9px; margin-top:4px; }
.sig-table td { border:0.5px solid #000; padding:8px; }
</style>
</head>
<body>

<!-- COMPANY HEADER -->
<table class="hdr-table">
<tr>
  <td class="hdr-logo">' . $logo_html . '</td>
  <td class="hdr-info">
    <span class="co-name">' . h($co_name) . '</span><br>
    ' . $co_addr_html . '
  </td>
</tr>
</table>

<!-- PO TITLE -->
<div class="po-title">PURCHASE ORDER</div>

<!-- TO block (left) + PO Meta (right) -->
<table class="to-meta">
<tr>
  <td class="to-cell">
    <strong>To :</strong><br>
    ' . $billing_html . '
  </td>
  <td class="meta-cell">
    <span class="po-num">PO No. :&nbsp;' . h($po['po_number']) . '</span><br>
    <strong>Date :</strong>&nbsp;' . date('d-M-Y', strtotime($po['po_date'])) . '<br>
    <strong>Valid till :</strong>&nbsp;' . date('d-M-Y', strtotime($po['due_date'])) . '
    ' . (!empty($po['reference']) ? '<br><strong>Ref. :</strong>&nbsp;' . h($po['reference']) : '') . '
  </td>
</tr>
</table>

<!-- ITEMS TABLE -->
<table class="item-table">
<thead>
<tr>
  <th style="width:22px;">No.</th>
  <th style="min-width:80px;">Item &amp; Description</th>
  <th style="width:28px;">Qty</th>
  <th style="width:26px;">Unit</th>
  <th style="width:48px;">Rate (&#8377;)</th>
  <th style="width:52px;">Taxable (&#8377;)</th>
  ' . $tax_th_new . '
  <th style="width:52px;">Amount (&#8377;)</th>
</tr>
</thead>
<tbody>' . $item_rows_html . '
</tbody>
</table>

<!-- SUMMARY: words left, totals right -->
<table class="summary-table">
<tr>
  <td style="width:52%;">
    <strong>Total Purchase Order Amount in Words :</strong><br>
    <em>' . h($amount_words) . '</em>
  </td>
  <td style="padding:0; width:48%;">
    <table class="totals-inner">
      <tr><td>Total Amount before Tax (&#8377;)</td><td style="text-align:right;">' . number_format($subtotal,2) . '</td></tr>
      ' . ($tot_cgst>0?'<tr><td>CGST (&#8377;)</td><td style="text-align:right;">' . number_format($tot_cgst,2) . '</td></tr>':'') . '
      ' . ($tot_sgst>0?'<tr><td>SGST (&#8377;)</td><td style="text-align:right;">' . number_format($tot_sgst,2) . '</td></tr>':'') . '
      ' . ($tot_igst>0?'<tr><td>IGST (&#8377;)</td><td style="text-align:right;">' . number_format($tot_igst,2) . '</td></tr>':'') . '
      <tr class="grand-row"><td><strong>Grand Total (&#8377;)</strong></td><td style="text-align:right;"><strong>' . number_format($grand_total,2) . '</strong></td></tr>
    </table>
  </td>
</tr>
</table>';

// Terms + Shipping Info block
$html .= '<div class="terms-box">';
if (!empty($terms_html)) {
    $html .= '<strong>Terms &amp; Conditions :</strong><br>' . $terms_html;
}
if (!empty($po['notes'])) {
    $html .= '<div style="margin-top:4px;"><strong>Notes:</strong> ' . nl2br(h($po['notes'])) . '</div>';
}
if (!empty($shipping_info_html)) {
    $html .= '<div style="margin-top:5px;"><strong>Shipping Info :</strong><br>' . $shipping_info_html . '</div>';
}
$html .= '</div>';

// Signature row
$html .= '
<table class="sig-table">
<tr>
  <td style="width:50%; vertical-align:bottom; padding:12px 8px 6px; font-size:9px;">
    This is a computer-generated purchase order. E. &amp; O. E.
  </td>
  <td style="width:50%; text-align:right; vertical-align:top; padding:8px; font-size:9px;">
    For, ' . h($co_name) . '<br><br><br><br>
    <strong>Authorised Signatory</strong>
  </td>
</tr>
</table>';

// HSN summary (only if tax)
if ($has_tax) {
$html .= '
<table style="width:auto;border-collapse:collapse;font-size:8.5px;margin-top:6px;">
<thead><tr style="background:#f2f2f2;font-weight:bold;">
<th style="' . $bH . '">HSN/SAC</th>
<th style="' . $bH . 'text-align:right;">Taxable (&#8377;)</th>
' . ($tot_cgst>0?'<th style="' . $bH . '">CGST%</th><th style="' . $bH . 'text-align:right;">CGST (&#8377;)</th>':'') . '
' . ($tot_sgst>0?'<th style="' . $bH . '">SGST%</th><th style="' . $bH . 'text-align:right;">SGST (&#8377;)</th>':'') . '
' . ($tot_igst>0?'<th style="' . $bH . '">IGST%</th><th style="' . $bH . 'text-align:right;">IGST (&#8377;)</th>':'') . '
<th style="' . $bH . 'text-align:right;">Total Tax (&#8377;)</th>
</tr></thead>
<tbody>' . $hsn_rows . '</tbody>
</table>';
}

$html .= '</body></html>';


// ════════════════════════════════════════════════════════════════════════════
// RENDER PDF via dompdf — same as download.php
// ════════════════════════════════════════════════════════════════════════════
$dompdf_path = dirname(__DIR__) . '/dompdf/autoload.inc.php';
$dompdf_loaded = false;
if (file_exists($dompdf_path)) {
    require_once $dompdf_path;
    $dompdf_loaded = true;
}

if ($dompdf_loaded && class_exists('Dompdf\Dompdf')) {
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Watermark on every page — same as download.php
    if (file_exists($watermarkPath)) {
        $canvas      = $dompdf->getCanvas();
        $canvasWidth  = $canvas->get_width();
        $canvasHeight = $canvas->get_height();
        $wmWidth = 300; $wmHeight = 300;
        $x = ($canvasWidth  - $wmWidth)  / 2;
        $y = ($canvasHeight - $wmHeight) / 2;
        $canvas->page_script(function($pageNumber, $pageCount, $canvas, $fontMetrics)
            use ($watermarkPath, $x, $y, $wmWidth, $wmHeight) {
            $canvas->set_opacity(0.08);
            $canvas->image($watermarkPath, $x, $y, $wmWidth, $wmHeight);
            $canvas->set_opacity(1);
        });
    }

    $filename = 'PO_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $po['po_number']) . '.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// FALLBACK: mPDF (if dompdf not found)
// ════════════════════════════════════════════════════════════════════════════
$autoload_paths = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];
$mpdf_loaded = false;
foreach ($autoload_paths as $path) {
    if (file_exists($path)) { require_once $path; $mpdf_loaded = true; break; }
}
if ($mpdf_loaded && class_exists('\Mpdf\Mpdf')) {
    $mpdf = new \Mpdf\Mpdf(['mode'=>'utf-8','format'=>'A4','margin_top'=>15,'margin_bottom'=>15,'margin_left'=>15,'margin_right'=>15]);
    $mpdf->SetTitle('PO ' . $po['po_number']);
    if (file_exists($watermarkPath)) {
        $mpdf->SetWatermarkImage($watermarkPath, 0.08, [150,150], [30,73]);
        $mpdf->showWatermarkImage = true;
    }
    $mpdf->WriteHTML($html);
    $filename = 'PO_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $po['po_number']) . '.pdf';
    $mpdf->Output($filename, 'D');
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// FALLBACK: HTML preview (browser print)
// ════════════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>PO <?= h($po['po_number']) ?> – Eltrive</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:Arial,sans-serif;background:#f0f2f8;color:#222;}
.form-topbar{display:flex;align-items:center;justify-content:space-between;padding:12px 24px;
    background:#fff;border-bottom:1px solid #e4e8f0;position:sticky;top:0;z-index:200;flex-wrap:wrap;gap:10px;}
.form-topbar-title{font-size:17px;font-weight:800;color:#1a1f2e;}
.topbar-btns{display:flex;gap:10px;align-items:center;}
.btn{border:none;border-radius:8px;padding:8px 18px;font-size:13px;font-family:inherit;cursor:pointer;
    font-weight:600;display:inline-flex;align-items:center;gap:6px;text-decoration:none;}
.btn-back{background:#f5f5f5;color:#374151;border:1px solid #d1d5db;}
.btn-print{background:#f97316;color:#fff;}
.btn-print:hover{background:#ea6a0a;}
.content{padding:24px;background:#f0f2f8;min-height:calc(100vh - 54px);}
.page-wrap{display:flex;justify-content:center;}
.page{width:210mm;min-height:297mm;background:#fff;padding:15mm;
    box-shadow:0 4px 24px rgba(0,0,0,.12);border-radius:4px;overflow:visible;}
#sidebar,.sidebar,nav.sidebar,[class*="sidebar"]{display:none !important;}
@media print{
    html,body{background:#fff;margin:0;padding:0;}
    #sidebar,.sidebar,nav.sidebar,[class*="sidebar"],.form-topbar{display:none !important;}
    .content{margin:0;padding:0;background:#fff;}
    .page-wrap{display:block;}
    .page{width:auto;min-height:0;margin:0;padding:0;box-shadow:none;border-radius:0;}
    /* thead repeats on every printed page, tfoot pins to bottom of last page */
    .item-table thead{display:table-header-group;}
    .item-table tfoot{display:table-footer-group;}
    .item-table tbody tr{page-break-inside:avoid;}
}
@page{size:A4 portrait;margin:15mm;}
</style>
</head>
<body>
<?php include dirname(__DIR__) . '/sidebar.php'; ?>
<div class="form-topbar">
    <div class="form-topbar-title">
        <i class="fas fa-file-invoice" style="color:#f97316;margin-right:8px;"></i>
        Purchase Order — <?= h($po['po_number']) ?>
    </div>
    <div class="topbar-btns">
        <a href="pindex.php" class="btn btn-back"><i class="fas fa-arrow-left"></i> Back</a>
        <button onclick="window.print()" class="btn btn-print"><i class="fas fa-print"></i> Print / Save as PDF</button>
    </div>
</div>
<div class="content">
    <div class="page-wrap">
        <div class="page">
            <?= $html ?>
        </div>
    </div>
</div>
</body>
</html>
<?php
<?php
require_once 'db.php';

$success = $error = '';
$ref         = $_SERVER['HTTP_REFERER'] ?? 'items_list.php';
$fromInvoice = isset($_GET['_from']) && $_GET['_from'] === 'create_invoice';
$editId      = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;

if ($editId <= 0) {
    die("Invalid Item ID.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['_ref'])) $ref = $_POST['_ref'];
    $fromInvoicePost = !empty($_POST['_from_invoice']);
    $editIdPost      = (int)($_POST['_edit_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("
            UPDATE items SET 
                item_name = :item_name, service_code = :service_code, hsn_sac = :hsn_sac,
                material_description = :material_description, uom = :uom, qty = :qty,
                delivery_date = :delivery_date, unit_price = :unit_price, basic_amount = :basic_amount,
                discount_percent = :discount_percent, pf_percent = :pf_percent,
                sgst = :sgst, cgst = :cgst, igst = :igst, tcs_percent = :tcs_percent, total = :total
            WHERE id = :id
        ");
        $stmt->execute([
            'item_name'            => $_POST['item_name'] ?? '',
            'service_code'         => $_POST['service_code'],
            'hsn_sac'              => $_POST['hsn_sac'],
            'material_description' => $_POST['material_description'],
            'uom'                  => $_POST['uom'],
            'qty'                  => $_POST['qty'],
            'delivery_date'        => $_POST['delivery_date'],
            'unit_price'           => $_POST['unit_price'],
            'basic_amount'         => $_POST['basic_amount'],
            'discount_percent'     => $_POST['discount_percent'],
            'pf_percent'           => $_POST['pf_percent'],
            'sgst'                 => $_POST['sgst'],
            'cgst'                 => $_POST['cgst'],
            'igst'                 => $_POST['igst'],
            'tcs_percent'          => $_POST['tcs_percent'],
            'total'                => $_POST['total'],
            'id'                   => $editIdPost
        ]);
        if ($fromInvoicePost) {
            $back = 'create_invoice.php?_restored=1&edit=' . $editIdPost;
            header('Location: ' . $back);
        } else {
            header('Location: ' . $ref . '?stock_saved=1');
        }
        exit;
    } catch (PDOException $e) {
        $error = "Error updating stock: " . $e->getMessage();
    }
}

// Fetch existing data
$stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
$stmt->execute([$editId]);
$itemData = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$itemData) die("Item not found.");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Edit Stock Item</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;background:#f0f2f8;color:#1a1f2e;font-size:13px}

.content{margin-left:220px;padding:68px 24px 28px;min-height:100vh;background:#f0f2f8}

/* PAGE HEADER */
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.page-header-left{display:flex;align-items:center;gap:12px}
.page-icon{width:40px;height:40px;background:linear-gradient(135deg,#16a34a,#15803d);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;box-shadow:0 3px 10px rgba(22,163,74,.3)}
.page-title{font-size:17px;font-weight:800;color:#1a1f2e}
.page-sub{font-size:11px;color:#9ca3af;margin-top:1px}

/* TWO-COL LAYOUT */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}

/* CARDS */
.fc{background:#fff;border:1px solid #e8ecf4;border-radius:14px;box-shadow:0 2px 6px rgba(0,0,0,.04);overflow:hidden}
.fc-head{display:flex;align-items:center;gap:10px;padding:12px 18px;border-bottom:1px solid #f0f2f7;background:#fafbfd}
.fc-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px;flex-shrink:0}
.fc-head h3{font-size:13px;font-weight:800;color:#1a1f2e}
.fc-body{padding:14px 18px}

/* FIELD GRIDS */
.fg{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.fg .full{grid-column:1/-1}
.fg3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
.fg3 .span2{grid-column:span 2}

/* LABELS & INPUTS */
.field label{display:block;font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px}
.field input,.field select,.field textarea{
    width:100%;padding:9px 12px;
    border:1.5px solid #e4e8f0;border-radius:8px;
    font-size:12px;font-family:'Times New Roman',Times,serif;
    color:#1a1f2e;background:#fff;outline:none;
    transition:border-color .2s,box-shadow .2s;
}
.field input:focus,.field select:focus,.field textarea:focus{border-color:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.08)}
.field textarea{resize:vertical;min-height:80px}
input[readonly]{background:#f0fdf4;border-color:#bbf7d0;color:#15803d;font-weight:700}

/* ALERTS */
.alert{padding:10px 14px;border-radius:10px;font-size:12px;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#15803d}
.alert-danger{background:#fef2f2;border:1px solid #fca5a5;color:#dc2626}

/* BOTTOM ACTIONS */
.bottom-actions{display:flex;gap:10px;align-items:center;padding:14px 18px;border-top:1px solid #f0f2f7;background:#fafbfd}
.btn-save{display:inline-flex;align-items:center;gap:6px;padding:9px 22px;background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif;transition:all .2s;box-shadow:0 2px 8px rgba(22,163,74,.3)}
.btn-save:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(22,163,74,.4)}
.btn-view{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;background:#fff;color:#374151;border:1.5px solid #e4e8f0;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;font-family:'Times New Roman',Times,serif;transition:all .2s}
.btn-view:hover{border-color:#16a34a;color:#16a34a;background:#f0fdf4}

::-webkit-scrollbar{width:3px}::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:99px}
@media(max-width:900px){.two-col,.fg,.fg3{grid-template-columns:1fr}.content{margin-left:0!important;padding:70px 12px 20px}}
</style>
</head>
<body>

<?php include 'sidebar.php'; ?>
<?php include 'header.php'; ?>

<div class="content">

    <!-- PAGE HEADER — title only, no Back or Save buttons -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-icon"><i class="fas fa-edit"></i></div>
            <div>
                <div class="page-title">Edit Stock Item</div>
                <div class="page-sub">Update the details of this stock item</div>
            </div>
        </div>
        <?php
        $backHref = $fromInvoice
            ? ('create_invoice.php?_restored=1' . ($editId > 0 ? '&edit='.$editId : ''))
            : 'javascript:history.back()';
        ?>
        <a href="<?= htmlspecialchars($backHref) ?>" title="Close" style="
            margin-left:auto;width:36px;height:36px;border-radius:50%;
            background:#fff;border:1.5px solid #e4e8f0;
            display:flex;align-items:center;justify-content:center;
            font-size:20px;color:#6b7280;text-decoration:none;
            box-shadow:0 2px 8px rgba(0,0,0,.08);transition:all .2s"
            onmouseover="this.style.background='#fef2f2';this.style.color='#dc2626';this.style.borderColor='#fca5a5'"
            onmouseout="this.style.background='#fff';this.style.color='#6b7280';this.style.borderColor='#e4e8f0'">
            &times;
        </a>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="_ref" value="<?= htmlspecialchars($ref) ?>">
        <input type="hidden" name="_from_invoice" value="<?= $fromInvoice ? '1' : '0' ?>">
        <input type="hidden" name="_edit_id" value="<?= $editId ?>">

        <!-- ROW 1: Item Identification  |  Quantity & Delivery -->
        <div class="two-col">

            <!-- ITEM IDENTIFICATION -->
            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#f97316,#fb923c)"><i class="fas fa-tag"></i></div>
                    <h3>Item Identification</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">
                        <div class="field full">
                            <label>Item Name</label>
                            <input type="text" name="item_name" id="item_name" placeholder="e.g. Fire Alarm Panel" value="<?= htmlspecialchars($itemData['item_name'] ?? '') ?>">
                            <span class="field-error" id="item_name_error" style="font-size:12px;color:#dc2626;margin-top:3px;display:none"></span>
                        </div>
                        <div class="field">
                            <label>Service Code</label>
                            <input type="text" name="service_code" id="service_code" placeholder="e.g. SRV-001" value="<?= htmlspecialchars($itemData['service_code'] ?? '') ?>">
                            <span class="field-error" id="service_code_error" style="font-size:12px;color:#dc2626;margin-top:3px;display:none"></span>
                        </div>
                        <div class="field">
                            <label>HSN / SAC</label>
                            <input type="text" name="hsn_sac" id="hsn_sac" placeholder="e.g. 998314" value="<?= htmlspecialchars($itemData['hsn_sac'] ?? '') ?>">
                            <span class="field-error" id="hsn_sac_error" style="font-size:12px;color:#dc2626;margin-top:3px;display:none"></span>
                        </div>
                        <div class="field full">
                            <label>Material Description</label>
                            <textarea name="material_description" id="material_description" placeholder="Enter detailed description..."><?= htmlspecialchars($itemData['material_description'] ?? '') ?></textarea>
                            <span class="field-error" id="desc_error" style="font-size:12px;color:#dc2626;margin-top:3px;display:none"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- QUANTITY & DELIVERY -->
            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#3b82f6,#2563eb)"><i class="fas fa-cubes"></i></div>
                    <h3>Quantity &amp; Delivery</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">
                        <div class="field">
                            <label>UOM</label>
                            <input type="text" name="uom" placeholder="Nos / Kg / Mtr" value="<?= htmlspecialchars($itemData['uom'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>Qty</label>
                            <input type="number" name="qty" id="qty" placeholder="0" min="0" step="any" value="<?= htmlspecialchars($itemData['qty'] ?? '') ?>">
                        </div>
                        <div class="field full">
                            <label>Delivery Date</label>
                            <input type="date" name="delivery_date" value="<?= htmlspecialchars($itemData['delivery_date'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /ROW 1 -->

        <!-- ROW 2: Pricing  |  Tax & Total -->
        <div class="two-col">

            <!-- PRICING -->
            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed)"><i class="fas fa-rupee-sign"></i></div>
                    <h3>Pricing</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">
                        <div class="field">
                            <label>Unit Price (&#8377;)</label>
                            <input type="number" step="0.01" name="unit_price" id="unit_price" placeholder="0.00" min="0" value="<?= htmlspecialchars($itemData['unit_price'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>Basic Amount (&#8377;)</label>
                            <input type="number" step="0.01" name="basic_amount" id="basic_amount" placeholder="auto-calculated" readonly value="<?= htmlspecialchars($itemData['basic_amount'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>Discount %</label>
                            <input type="number" step="0.01" name="discount_percent" id="discount_percent" placeholder="0.00" min="0" max="100" value="<?= htmlspecialchars($itemData['discount_percent'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>P &amp; F %</label>
                            <input type="number" step="0.01" name="pf_percent" id="pf_percent" placeholder="0.00" min="0" value="<?= htmlspecialchars($itemData['pf_percent'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAX & TOTAL -->
            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#16a34a,#15803d)"><i class="fas fa-file-invoice-dollar"></i></div>
                    <h3>Tax &amp; Total</h3>
                </div>
                <div class="fc-body">
                    <div class="fg3">
                        <div class="field">
                            <label>SGST %</label>
                            <input type="number" step="0.01" name="sgst" id="sgst" placeholder="0.00" min="0" value="<?= htmlspecialchars($itemData['sgst'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>CGST %</label>
                            <input type="number" step="0.01" name="cgst" id="cgst" placeholder="0.00" min="0" value="<?= htmlspecialchars($itemData['cgst'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>IGST %</label>
                            <input type="number" step="0.01" name="igst" id="igst" placeholder="0.00" min="0" value="<?= htmlspecialchars($itemData['igst'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>TCS %</label>
                            <input type="number" step="0.01" name="tcs_percent" id="tcs_percent" placeholder="0.00" min="0" value="<?= htmlspecialchars($itemData['tcs_percent'] ?? '') ?>">
                        </div>
                        <div class="field span2">
                            <label>Total Amount (&#8377;)</label>
                            <input type="number" step="0.01" name="total" id="total" placeholder="auto-calculated" readonly value="<?= htmlspecialchars($itemData['total'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /ROW 2 -->

        <!-- SINGLE SAVE BAR at the bottom -->
        <div class="fc" style="margin-bottom:0">
            <div class="bottom-actions">
                <button type="submit" class="btn-save"><i class="fas fa-save"></i> Save Changes</button>
                <a href="<?= htmlspecialchars($backHref) ?>" class="btn-view"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

    </form>
</div>

<script>
function calc() {
    const qty   = parseFloat(document.getElementById('qty').value)             || 0;
    const price = parseFloat(document.getElementById('unit_price').value)       || 0;
    const disc  = parseFloat(document.getElementById('discount_percent').value) || 0;
    const pf    = parseFloat(document.getElementById('pf_percent').value)       || 0;
    const sgst  = parseFloat(document.getElementById('sgst').value)             || 0;
    const cgst  = parseFloat(document.getElementById('cgst').value)             || 0;
    const igst  = parseFloat(document.getElementById('igst').value)             || 0;
    const tcs   = parseFloat(document.getElementById('tcs_percent').value)      || 0;

    const basic     = qty * price;
    const afterDisc = basic - (basic * disc / 100);
    const afterPF   = afterDisc + (afterDisc * pf / 100);
    const total     = afterPF
                    + (afterPF * sgst / 100)
                    + (afterPF * cgst / 100)
                    + (afterPF * igst / 100)
                    + (afterPF * tcs  / 100);

    document.getElementById('basic_amount').value = basic.toFixed(2);
    document.getElementById('total').value         = total.toFixed(2);
}

['qty','unit_price','discount_percent','pf_percent','sgst','cgst','igst','tcs_percent']
    .forEach(id => document.getElementById(id).addEventListener('input', calc));

function showFieldErr(id, msg){
    const el=document.getElementById(id);
    const errEl=document.getElementById(id+'_error');
    if(el){ el.style.borderColor='#dc2626'; el.style.boxShadow='0 0 0 3px rgba(220,38,38,.1)'; }
    if(errEl){ errEl.textContent=msg; errEl.style.display='block'; }
}
function clearFieldErr(id){
    const el=document.getElementById(id);
    const errEl=document.getElementById(id+'_error');
    if(el){ el.style.borderColor=''; el.style.boxShadow=''; }
    if(errEl){ errEl.style.display='none'; }
}

document.querySelector('form').addEventListener('submit', function(e){
    let valid = true;

    // HSN/SAC format: if filled, must be 4–8 digits
    const hsn = document.getElementById('hsn_sac').value.trim();
    if(hsn && !/^\d{4,8}$/.test(hsn)){
        showFieldErr('hsn_sac','HSN/SAC must be 4–8 digits');
        valid=false;
    } else {
        clearFieldErr('hsn_sac');
    }

    // SGST + CGST: if one is set, both should be set
    const sgst = parseFloat(document.getElementById('sgst').value)||0;
    const cgst = parseFloat(document.getElementById('cgst').value)||0;
    if((sgst > 0 && cgst === 0) || (cgst > 0 && sgst === 0)){
        document.getElementById('sgst').style.borderColor='#dc2626';
        document.getElementById('cgst').style.borderColor='#dc2626';
        valid=false;
    } else {
        document.getElementById('sgst').style.borderColor='';
        document.getElementById('cgst').style.borderColor='';
    }

    if(!valid){
        e.preventDefault();
        const firstInvalid = document.querySelector('[style*="border-color: rgb(220"]') || document.querySelector('.field-error[style*="display: block"]');
        if(firstInvalid) firstInvalid.scrollIntoView({behavior:'smooth', block:'center'});
    }
});

// Clear errors on input
['item_name','service_code','hsn_sac'].forEach(id=>{
    document.getElementById(id)?.addEventListener('input',()=>clearFieldErr(id));
});
</script>
</body>
</html>
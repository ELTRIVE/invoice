<?php
// add_customer.php
require_once 'db.php';
date_default_timezone_set('Asia/Kolkata');
$error = '';
$ref = $_SERVER['HTTP_REFERER'] ?? 'create_invoice';

function ensureCustomerInvoiceAddressColumns(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $columns = [
        "billing_gstin VARCHAR(20) DEFAULT ''",
        "billing_pan VARCHAR(20) DEFAULT ''",
        "billing_phone VARCHAR(20) DEFAULT ''",
        "ship_address_line1 VARCHAR(255) DEFAULT ''",
        "ship_address_line2 VARCHAR(255) DEFAULT ''",
        "ship_city VARCHAR(100) DEFAULT ''",
        "ship_state VARCHAR(100) DEFAULT ''",
        "ship_pincode VARCHAR(20) DEFAULT ''",
        "ship_country VARCHAR(100) DEFAULT ''",
        "shipping_gstin VARCHAR(20) DEFAULT ''",
        "shipping_pan VARCHAR(20) DEFAULT ''",
        "shipping_phone VARCHAR(20) DEFAULT ''"
    ];

    foreach ($columns as $definition) {
        try {
            $pdo->exec("ALTER TABLE customers ADD COLUMN $definition");
        } catch (Exception $e) {
        }
    }

    $ensured = true;
}

ensureCustomerInvoiceAddressColumns($pdo);

if (isset($_POST['save_customer'])) {
    if(!empty($_POST['_ref'])) $ref = $_POST['_ref'];
    $stmt = $pdo->prepare("
        INSERT INTO customers
        (business_name, title, first_name, last_name, mobile, email, website,
         industry_segment, country, state, city,
         address_title, address_line1, address_line2, address_city,
         address_state, address_country, pincode, gstin,
         show_title_in_shipping, extra_key, extra_value,
         receivables, receivable_notes, business_prospect, order_target,
         msme_no, pan_no, billing_gstin, billing_pan, billing_phone,
         ship_address_line1, ship_address_line2, ship_city, ship_state, ship_pincode, ship_country,
         shipping_gstin, shipping_pan, shipping_phone, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $_POST['business_name'] ?? null, $_POST['title'] ?? null,
        $_POST['first_name'] ?? null,    $_POST['last_name'] ?? null,
        $_POST['mobile'] ?? null,        $_POST['email'] ?? null,
        $_POST['website'] ?? null,       $_POST['industry_segment'] ?? null,
        $_POST['country'] ?? 'India',    $_POST['state'] ?? null,
        $_POST['city'] ?? null,          $_POST['address_title'] ?? null,
        $_POST['address_line1'] ?? null, $_POST['address_line2'] ?? null,
        $_POST['address_city'] ?? null,  $_POST['address_state'] ?? null,
        $_POST['address_country'] ?? 'India', $_POST['pincode'] ?? null,
        $_POST['gstin'] ?? null,
        isset($_POST['show_title_in_shipping']) ? 1 : 0,
        $_POST['extra_key'] ?? null,     $_POST['extra_value'] ?? null,
        $_POST['receivables'] ?? 0,      $_POST['receivable_notes'] ?? null,
        $_POST['business_prospect'] ?? 0, $_POST['order_target'] ?? null,
        $_POST['msme_no'] ?? null,       $_POST['pan_no'] ?? null,
        strtoupper(trim($_POST['billing_gstin'] ?? '')),
        strtoupper(trim($_POST['billing_pan'] ?? '')),
        trim($_POST['billing_phone'] ?? ''),
        $_POST['ship_address_line1'] ?? null,
        $_POST['ship_address_line2'] ?? null,
        $_POST['ship_city'] ?? null,
        $_POST['ship_state'] ?? null,
        $_POST['ship_pincode'] ?? null,
        $_POST['ship_country'] ?? 'India',
        strtoupper(trim($_POST['shipping_gstin'] ?? '')),
        strtoupper(trim($_POST['shipping_pan'] ?? '')),
        trim($_POST['shipping_phone'] ?? ''),
        date('Y-m-d H:i:s')
    ]);
    header("Location: " . $ref . "?customer_saved=1");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Add Customer</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;background:#f0f2f8;color:#1a1f2e}
.content{margin-left:220px;padding:68px 24px 28px;min-height:100vh;background:#f0f2f8}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.page-header-left{display:flex;align-items:center;gap:12px}
.page-header-icon{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#f97316,#fb923c);display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;box-shadow:0 3px 10px rgba(249,115,22,.3)}
.page-title{font-size:19px;font-weight:800;color:#1a1f2e}
.page-sub{font-size:13px;color:#9ca3af;margin-top:1px}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.one-col{margin-bottom:12px}
.fc{background:#fff;border:1px solid #e8ecf4;border-radius:14px;box-shadow:0 2px 6px rgba(0,0,0,.04);overflow:hidden}
.fc-head{padding:12px 18px;border-bottom:1px solid #f0f2f7;background:#fafbfd;display:flex;align-items:center;gap:10px}
.fc-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:14px;color:#fff;flex-shrink:0}
.fc-head h3{font-size:15px;font-weight:800;color:#1a1f2e}
.fc-body{padding:14px 18px}
.fg{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.fg .full{grid-column:1/-1}
.field label{display:block;font-size:12px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px}
.field label .req{color:#dc2626}
.field input[type=text],.field input[type=email],.field input[type=number],.field select,.field textarea{width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:14px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;background:#fff;outline:none;transition:border-color .2s,box-shadow .2s}
.field input:focus,.field select:focus,.field textarea:focus{border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.1)}
.field-error{font-size:12px;color:#dc2626;margin-top:3px;display:none}
.field-error.show{display:block}
input.invalid{border-color:#dc2626!important;box-shadow:0 0 0 3px rgba(220,38,38,.1)!important}
.check-row{display:flex;align-items:center;gap:8px;font-size:14px;color:#374151;margin-bottom:10px}
.check-row input[type=checkbox]{width:15px;height:15px;accent-color:#f97316}
.btn-save{padding:9px 22px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;gap:6px;transition:all .2s;box-shadow:0 2px 8px rgba(249,115,22,.3)}
.btn-save:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(249,115,22,.4)}
.btn-cancel{padding:9px 18px;background:#fff;color:#6b7280;border:1.5px solid #e4e8f0;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;gap:6px;text-decoration:none;transition:all .2s}
.btn-cancel:hover{background:#f4f6fb;border-color:#d1d5db}
.btn-back{padding:8px 16px;background:#fff;color:#6b7280;border:1.5px solid #e4e8f0;border-radius:8px;font-size:14px;font-weight:600;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;gap:6px;text-decoration:none;transition:all .2s}
.btn-back:hover{background:#f4f6fb}
.bottom-actions{display:flex;gap:10px;align-items:center;padding:14px 18px;border-top:1px solid #f0f2f7;background:#fafbfd}
::-webkit-scrollbar{width:3px}::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:99px}
@media(max-width:900px){.two-col{grid-template-columns:1fr}.fg{grid-template-columns:1fr}.content{margin-left:0!important;padding:70px 12px 20px}}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<?php include 'header.php'; ?>

<div class="content">

    <div class="page-header">
        <div class="page-header-left">
            <div class="page-header-icon"><i class="fas fa-user-plus"></i></div>
            <div>
                <div class="page-title">Add New Customer</div>
                <div class="page-sub">Fill in the details to create a new customer</div>
            </div>
        </div>
        <a href="<?= htmlspecialchars($ref) ?>" class="btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    </div>

    <form method="post">
        <input type="hidden" name="_ref" value="<?= htmlspecialchars($ref) ?>">

        <!-- ROW 1: Basic Info + Contact -->
        <div class="two-col">

            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#f97316,#fb923c)"><i class="fas fa-building"></i></div>
                    <h3>Basic Information</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">
                        <div class="field full">
                            <label>Business Name</label>
                            <input type="text" name="business_name" placeholder="Company / Individual name">
                        </div>
                        <div class="field full" style="display:grid;grid-template-columns:90px 1fr;gap:10px;align-items:end">
                            <div class="field" style="margin:0">
                                <label>Title</label>
                                <select name="title">
                                    <option>Mr</option><option>Ms</option><option>Mrs</option><option>Dr</option>
                                </select>
                            </div>
                            <div class="field" style="margin:0">
                                <label>First Name</label>
                                <input type="text" name="first_name">
                            </div>
                        </div>
                        <div class="field full">
                            <label>Last Name</label>
                            <input type="text" name="last_name">
                        </div>
                        <div class="field full">
                            <label>Website</label>
                            <input type="text" name="website" placeholder="https://example.com">
                        </div>
                        <div class="field full">
                            <label>Industry &amp; Segment</label>
                            <input type="text" name="industry_segment">
                        </div>
                    </div>
                </div>
            </div>

            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#7c3aed,#a78bfa)"><i class="fas fa-user"></i></div>
                    <h3>Contact Details</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">
                        <div class="field full">
                            <label>Mobile</label>
                            <input type="text" name="mobile" id="mobile" placeholder="9XXXXXXXXX" maxlength="10">
                            <span class="field-error" id="mobile_error"></span>
                        </div>
                        <div class="field full">
                            <label>Email</label>
                            <input type="email" name="email" id="email_field">
                            <span class="field-error" id="email_error"></span>
                        </div>
                        <div class="field full">
                            <label>Country</label>
                            <select name="country"><option>India</option></select>
                        </div>
                        <div class="field">
                            <label>State</label>
                            <input type="text" name="state">
                        </div>
                        <div class="field">
                            <label>City</label>
                            <input type="text" name="city">
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ROW 2: Billing Address + Shipping Address -->
        <div class="two-col">

            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#2563eb,#60a5fa)"><i class="fas fa-map-marker-alt"></i></div>
                    <h3>Billing Address</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">
                        <div class="field full">
                            <label>Address Line 1</label>
                            <input type="text" name="address_line1">
                        </div>
                        <div class="field full">
                            <label>Address Line 2</label>
                            <input type="text" name="address_line2">
                        </div>
                        <div class="field">
                            <label>City</label>
                            <input type="text" name="address_city">
                        </div>
                        <div class="field">
                            <label>State</label>
                            <input type="text" name="address_state">
                        </div>
                        <div class="field">
                            <label>Pincode</label>
                            <input type="text" name="pincode" id="pincode" maxlength="6" placeholder="6-digit pincode">
                            <span class="field-error" id="pincode_error"></span>
                        </div>
                        <div class="field">
                            <label>Country</label>
                            <select name="address_country"><option>India</option></select>
                        </div>
                        <div class="field full">
                            <label>Billing GSTIN</label>
                            <input type="text" name="billing_gstin" id="billing_gstin" placeholder="22AAAAA0000A1Z5" maxlength="15" style="text-transform:uppercase">
                            <span class="field-error" id="billing_gstin_error"></span>
                        </div>
                        <div class="field full">
                            <label>Billing PAN</label>
                            <input type="text" name="billing_pan" id="billing_pan" placeholder="ABCDE1234F" maxlength="10" style="text-transform:uppercase">
                            <span class="field-error" id="billing_pan_error"></span>
                        </div>
                        <div class="field full">
                            <label>Billing Phone</label>
                            <input type="text" name="billing_phone" id="billing_phone" placeholder="9XXXXXXXXX" maxlength="10">
                            <span class="field-error" id="billing_phone_error"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#0891b2,#0e7490)"><i class="fas fa-truck"></i></div>
                    <h3>Shipping Address</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">
                        <div class="field full">
                            <div class="check-row">
                                <input type="checkbox" id="sameAsBilling" onchange="copySameAsBilling(this)">
                                <label for="sameAsBilling" style="font-size:14px;font-weight:500;color:#374151;text-transform:none;letter-spacing:0;margin:0">Same as Billing Address</label>
                            </div>
                        </div>
                        <div class="field full">
                            <label>Address Line 1</label>
                            <input type="text" name="ship_address_line1" id="ship_addr1">
                        </div>
                        <div class="field full">
                            <label>Address Line 2</label>
                            <input type="text" name="ship_address_line2" id="ship_addr2">
                        </div>
                        <div class="field">
                            <label>City</label>
                            <input type="text" name="ship_city" id="ship_city">
                        </div>
                        <div class="field">
                            <label>State</label>
                            <input type="text" name="ship_state" id="ship_state">
                        </div>
                        <div class="field">
                            <label>Pincode</label>
                            <input type="text" name="ship_pincode" id="ship_pincode" maxlength="6">
                        </div>
                        <div class="field">
                            <label>Country</label>
                            <select name="ship_country"><option>India</option></select>
                        </div>
                        <div class="field full">
                            <label>Shipping GSTIN</label>
                            <input type="text" name="shipping_gstin" id="shipping_gstin" placeholder="22AAAAA0000A1Z5" maxlength="15" style="text-transform:uppercase">
                            <span class="field-error" id="shipping_gstin_error"></span>
                        </div>
                        <div class="field full">
                            <label>Shipping PAN</label>
                            <input type="text" name="shipping_pan" id="shipping_pan" placeholder="ABCDE1234F" maxlength="10" style="text-transform:uppercase">
                            <span class="field-error" id="shipping_pan_error"></span>
                        </div>
                        <div class="field full">
                            <label>Shipping Phone</label>
                            <input type="text" name="shipping_phone" id="shipping_phone" placeholder="9XXXXXXXXX" maxlength="10">
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ROW 3: Tax & Business + Bank Details -->
        <div class="two-col">

            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#16a34a,#4ade80)"><i class="fas fa-file-contract"></i></div>
                    <h3>Tax &amp; Business</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">
                        <div class="field full">
                            <label>GSTIN</label>
                            <input type="text" name="gstin" id="gstin" placeholder="22AAAAA0000A1Z5" maxlength="15" style="text-transform:uppercase">
                            <span class="field-error" id="gstin_error"></span>
                        </div>
                        <div class="field full">
                            <label>PAN No.</label>
                            <input type="text" name="pan_no" id="pan_no" placeholder="ABCDE1234F" maxlength="10" style="text-transform:uppercase">
                            <span class="field-error" id="pan_error"></span>
                        </div>
                        <div class="field full">
                            <label>MSME No.</label>
                            <input type="text" name="msme_no" id="msme_no">
                        </div>
                        <div class="field">
                            <label>Receivables (&#8377;)</label>
                            <input type="number" name="receivables" value="0">
                        </div>
                        <div class="field">
                            <label>Business Prospect (&#8377;)</label>
                            <input type="number" name="business_prospect" value="0">
                        </div>
                        <div class="field">
                            <label>Order Target (&#8377;)</label>
                            <input type="number" name="order_target">
                        </div>
                        <div class="field">
                            <label>Receivable Notes</label>
                            <input type="text" name="receivable_notes">
                        </div>
                    </div>
                </div>
            </div>

            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#0891b2,#0e7490)"><i class="fas fa-university"></i></div>
                    <h3>Bank Details</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">
                        <div class="field full">
                            <label>Bank Name</label>
                            <input type="text" name="bank_name" placeholder="e.g. State Bank of India">
                        </div>
                        <div class="field full">
                            <label>Account Number</label>
                            <input type="text" name="bank_account_no" placeholder="Account number">
                        </div>
                        <div class="field full">
                            <label>IFSC Code</label>
                            <input type="text" name="bank_ifsc" id="bank_ifsc" placeholder="e.g. SBIN0001234" maxlength="11" style="text-transform:uppercase">
                            <span class="field-error" id="ifsc_error"></span>
                        </div>
                        <div class="field full">
                            <label>Branch</label>
                            <input type="text" name="bank_branch" placeholder="Branch name">
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <?php if (!empty($error)): ?>
        <div style="background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;padding:10px 14px;border-radius:8px;margin-bottom:12px;font-size:14px;">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <div class="fc one-col">
            <div class="bottom-actions">
                <button type="submit" name="save_customer" class="btn-save"><i class="fas fa-check"></i> Save Customer</button>
                <a href="<?= htmlspecialchars($ref) ?>" class="btn-cancel"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </div>

    </form>
</div>

<script>
// Auto uppercase
['gstin','pan_no','billing_gstin','billing_pan','shipping_gstin','shipping_pan','bank_ifsc'].forEach(id=>{
    const el=document.getElementById(id); if(el) el.addEventListener('input',function(){this.value=this.value.toUpperCase();});
});

const rules = {
    gstin:        { pattern:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/, msg:'Invalid GSTIN. Format: 22AAAAA0000A1Z5' },
    pan_no:       { pattern:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/, msg:'Invalid PAN. Format: ABCDE1234F' },
    billing_gstin:{ pattern:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/, msg:'Invalid Billing GSTIN format' },
    billing_pan:  { pattern:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/, msg:'Invalid Billing PAN format' },
    shipping_gstin:{ pattern:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/, msg:'Invalid Shipping GSTIN format' },
    shipping_pan: { pattern:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/, msg:'Invalid Shipping PAN format' },
    mobile:       { pattern:/^[6-9]\d{9}$/, msg:'Mobile must be a valid 10-digit Indian number' },
    billing_phone:{ pattern:/^[6-9]\d{9}$/, msg:'Billing phone must be a valid 10-digit number' },
    pincode:      { pattern:/^\d{6}$/, msg:'Pincode must be exactly 6 digits' },
    bank_ifsc:    { pattern:/^[A-Z]{4}0[A-Z0-9]{6}$/, msg:'Invalid IFSC format (e.g. SBIN0001234)' },
};

function validateField(id, required=false){
    const el = document.getElementById(id); if(!el) return true;
    const errEl = document.getElementById(id+'_error'); if(!errEl) return true;
    const val = el.value.trim();
    if(required && !val){
        showErr(el, errEl, 'This field is required');return false;
    }
    if(val && /^(NA|N/A|na|n/a|N/a)$/.test(val)){ clearErr(el,errEl); return true; }
    if(val && rules[id] && !rules[id].pattern.test(val)){
        showErr(el, errEl, rules[id].msg);return false;
    }
    clearErr(el, errEl); return true;
}
function showErr(el,errEl,msg){
    errEl.textContent=msg; errEl.classList.add('show');
    el.style.borderColor='#dc2626'; el.style.boxShadow='0 0 0 3px rgba(220,38,38,.1)';
}
function clearErr(el,errEl){
    errEl.classList.remove('show');
    el.style.borderColor=''; el.style.boxShadow='';
}

// Attach blur validators
Object.keys(rules).forEach(id=>{ const el=document.getElementById(id); if(el) el.addEventListener('blur',()=>validateField(id)); });

function copySameAsBilling(chk){
    if(!chk.checked) return;
    document.getElementById('ship_addr1').value  = document.querySelector('[name="address_line1"]').value;
    document.getElementById('ship_addr2').value  = document.querySelector('[name="address_line2"]').value;
    document.getElementById('ship_city').value   = document.querySelector('[name="address_city"]').value;
    document.getElementById('ship_state').value  = document.querySelector('[name="address_state"]').value;
    document.getElementById('ship_pincode').value= document.querySelector('[name="pincode"]').value;
    document.getElementById('shipping_gstin').value = document.getElementById('billing_gstin').value || document.getElementById('gstin').value || '';
    document.getElementById('shipping_pan').value = document.getElementById('billing_pan').value || document.getElementById('pan_no').value || '';
    document.getElementById('shipping_phone').value = document.getElementById('billing_phone').value || '';
}

document.querySelector('form').addEventListener('submit', function(e){
    let valid = true;
    // Required field format checks
    if(!validateField('gstin')) valid=false;
    if(!validateField('pan_no')) valid=false;
    if(!validateField('mobile')) valid=false;
    // Optional format checks
    ['billing_gstin','billing_pan','shipping_gstin','shipping_pan','billing_phone','pincode','bank_ifsc'].forEach(id=>{
        if(!validateField(id)) valid=false;
    });
    // Email check
    const emailEl=document.getElementById('email_field');
    const emailErr=document.getElementById('email_error');
    if(emailEl && emailEl.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailEl.value)){
        showErr(emailEl, emailErr, 'Invalid email address');valid=false;
    }
    if(!valid){ e.preventDefault(); document.querySelector('.invalid, .field-error.show')?.scrollIntoView({behavior:'smooth',block:'center'}); }
});
</script>
</body>
</html>

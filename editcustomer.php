<?php
require_once 'db.php';
/* ── TOPBAR LOGO ── */
$_topbarCompany = $pdo->query("SELECT company_logo, company_name FROM invoice_company ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$_topbarLogo = $_topbarCompany['company_logo'] ?? '';
$_topbarName = $_topbarCompany['company_name'] ?? 'ELTRIVE';


$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$customer = $pdo->query("SELECT * FROM customers WHERE id=$id")->fetch(PDO::FETCH_ASSOC);

if(!$customer){
    header("Location: index.php?view=customers");
    exit;
}

$hideTopRightInvoice = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Edit Customer</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>

        * { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Times New Roman', Times, serif;
    background: #f4f6fb;
}



/* ===== CONTENT ===== */
.content { margin-left: 220px; padding: 68px 24px 28px; min-height: 100vh; background: #f4f6fb; transition: margin-left 0.25s ease; }
.sidebar:hover ~ .content {  }

.page-wrap {
    max-width: 100%;
    margin: 0;
}

/* ===== CARD ===== */
.form-card {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e4e8f0;
    box-shadow: 0 1px 6px rgba(0,0,0,0.05);
    overflow: hidden;
}

/* ===== CARD HEADER ===== */
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 18px 24px;
    border-bottom: 1px solid #f0f2f7;
    background: #fafbfc;
}
.card-header-left { display: flex; align-items: center; gap: 12px; }
.customer-avatar {
    width: 42px; height: 42px;
    border-radius: 50%;
    background: linear-gradient(135deg, #f97316, #fb923c);
    display: flex; align-items: center; justify-content: center;
    font-family: 'Times New Roman', Times, serif; font-weight: 800;
    font-size: 18px; color: #fff;
}
.card-title { font-family: 'Times New Roman', Times, serif; font-size: 16px; font-weight: 800; color: #1a1f2e; }
.card-sub { font-size: 12px; color: #9ca3af; margin-top: 2px; }

/* ===== FORM BODY ===== */
.form-body { padding: 20px 24px; }

/* ===== FIELD ===== */
.field { display: flex; flex-direction: column; gap: 5px; }
.field label {
    display: flex; align-items: center; gap: 3px;
    font-size: 11px; font-weight: 600;
    color: #6b7280;
    text-transform: uppercase; letter-spacing: 0.5px;
}
.req { color: #f97316; }

.field input,
.field select,
.field textarea {
    width: 100%;
    padding: 8px 11px;
    border: 1.5px solid #e4e8f0;
    border-radius: 8px;
    font-size: 13px;
    font-family: 'Times New Roman', Times, serif;
    color: #1a1f2e;
    background: #fff;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.field input:focus,
.field select:focus,
.field textarea:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249,115,22,0.1);
}
.field textarea { resize: vertical; min-height: 64px; }

/* 4-column compact grid */
.form-grid { display: grid; gap: 12px 14px; margin-bottom: 12px; }
.g-4 { grid-template-columns: repeat(4, 1fr); }
.g-3 { grid-template-columns: repeat(3, 1fr); }
.g-2 { grid-template-columns: repeat(2, 1fr); }
.span-2 { grid-column: span 2; }
.span-3 { grid-column: span 3; }
.span-4 { grid-column: span 4; }

/* ===== NAME ROW ===== */
.name-row { display: flex; gap: 8px; }
.name-row select { width: 90px; flex-shrink: 0; }
.name-row input  { flex: 1; }

/* ===== MOBILE ROW ===== */
.mobile-wrap { display: flex; }
.mobile-prefix {
    padding: 8px 11px;
    background: #f8f9fc;
    border: 1.5px solid #e4e8f0;
    border-right: none;
    border-radius: 8px 0 0 8px;
    font-size: 13px; font-weight: 600;
    color: #6b7280;
    display: flex; align-items: center;
    white-space: nowrap;
}
.mobile-wrap input { border-radius: 0 8px 8px 0 !important; }

@media (max-width: 700px) {
    .g-4,.g-3 { grid-template-columns: 1fr 1fr; }
    .span-4,.span-3 { grid-column: span 2; }
    .name-row { flex-direction: column; }
    .name-row select { width: 100%; }
}
.section-label {
    font-size: 11px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px;
    color: #9ca3af;
    padding: 16px 0 10px;
    border-top: 1px solid #f0f2f7;
    margin-top: 8px;
    display: flex; align-items: center; gap: 8px;
}
.section-label::after { content: ''; flex: 1; height: 1px; background: #f0f2f7; }

/* ===== MORE TOGGLE ===== */
.more-toggle {
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 600; color: #374151;
    cursor: pointer;
    padding: 13px 0;
    border-top: 1px solid #f0f2f7;
    margin-top: 6px;
    user-select: none;
    transition: color 0.15s;
}
.more-toggle:hover { color: #f97316; }
.more-arrow {
    width: 24px; height: 24px;
    border-radius: 6px;
    background: #f4f6fb;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; color: #6b7280;
    transition: all 0.25s;
}
.more-arrow.open { background: #fff7f0; color: #f97316; transform: rotate(90deg); }

.more-section { display: block; padding-top: 8px; }

/* ===== BUTTONS ===== */
.btn-save {
    padding: 10px 22px;
    background: linear-gradient(135deg, #f97316, #fb923c);
    color: #fff; border: none; border-radius: 8px;
    font-size: 13px; font-weight: 700;
    cursor: pointer;
    font-family: 'Times New Roman', Times, serif;
    display: inline-flex; align-items: center; gap: 7px;
    transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(249,115,22,0.3);
}
.btn-save:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(249,115,22,0.4); }

.btn-delete {
    padding: 10px 20px;
    background: #fff; color: #dc2626;
    border: 1.5px solid #fecaca; border-radius: 8px;
    font-size: 13px; font-weight: 700;
    cursor: pointer;
    font-family: 'Times New Roman', Times, serif;
    display: inline-flex; align-items: center; gap: 7px;
    transition: all 0.2s;
}
.btn-delete:hover { background: #fef2f2; border-color: #dc2626; }

.btn-cancel {
    padding: 10px 20px;
    background: #fff; color: #6b7280;
    border: 1.5px solid #e4e8f0; border-radius: 8px;
    font-size: 13px; font-weight: 700;
    cursor: pointer;
    font-family: 'Times New Roman', Times, serif;
    display: inline-flex; align-items: center; gap: 7px;
    transition: all 0.2s;
    text-decoration: none;
}
.btn-cancel:hover { background: #f4f6fb; border-color: #9ca3af; color: #374151; }

.btn-financials {
    padding: 9px 16px;
    background: #f4f6fb; color: #374151;
    border: 1.5px solid #e4e8f0; border-radius: 8px;
    font-size: 13px; font-weight: 600;
    cursor: pointer;
    font-family: 'Times New Roman', Times, serif;
    display: inline-flex; align-items: center; gap: 7px;
    transition: all 0.2s;
}
.btn-financials:hover { background: #fff7f0; color: #f97316; border-color: #f97316; }

/* ===== BOTTOM ACTIONS ===== */
.bottom-actions {
    display: flex; gap: 10px; align-items: center;
    padding: 18px 24px;
    border-top: 1px solid #f0f2f7;
    background: #fafbfc;
    border-radius: 0 0 14px 14px;
}
.bottom-actions-right { margin-left: auto; }

/* ===== MODAL ===== */
.modal {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.4);
    justify-content: center; align-items: center;
    z-index: 9999; backdrop-filter: blur(4px);
}
.modal.open { display: flex; }
.modal-card {
    background: #fff; width: min(90vw, 440px);
    padding: 28px; border-radius: 14px; position: relative;
    box-shadow: 0 24px 60px rgba(0,0,0,0.14);
    border: 1px solid #e4e8f0;
    animation: popIn 0.2s ease;
}
@keyframes popIn { from{transform:scale(0.95);opacity:0} to{transform:scale(1);opacity:1} }
.modal-card h3 {
    font-family: 'Times New Roman', Times, serif;
    font-size: 17px; font-weight: 800;
    color: #1a1f2e; margin-bottom: 18px;
    padding-bottom: 14px;
    border-bottom: 1px solid #f0f2f7;
}
.close-btn {
    position: absolute; top: 14px; right: 14px;
    font-size: 16px; cursor: pointer; color: #9ca3af;
    width: 30px; height: 30px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; border: 1px solid #e4e8f0; background: none;
    transition: all 0.2s;
}
.close-btn:hover { color: #f97316; border-color: #f97316; background: #fff7f0; }

.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border-radius: 8px;
    background: linear-gradient(135deg, #f97316, #fb923c);
    color: #fff; font-size: 13px; font-weight: 600;
    border: none; cursor: pointer;
    font-family: 'Times New Roman', Times, serif;
    transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(249,115,22,0.25);
}
.btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(249,115,22,0.35); }

/* ===== TOAST ===== */
#toast {
    position: fixed; bottom: 24px; right: 24px;
    padding: 12px 20px; border-radius: 10px;
    font-size: 13px; font-weight: 600;
    display: flex; align-items: center; gap: 8px;
    opacity: 0; transform: translateY(10px);
    transition: all 0.3s; pointer-events: none; z-index: 9999;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
}
#toast.show { opacity: 1; transform: translateY(0); }
#toast.success { background: #16a34a; color: #fff; }
#toast.error   { background: #dc2626; color: #fff; }

/* ===== SUCCESS POPUP ===== */
#successPopup {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.45);
    justify-content: center; align-items: center;
    z-index: 9999; backdrop-filter: blur(4px);
    pointer-events: none;
}
#successPopup[style*="flex"] {
    pointer-events: all;
}
.success-card {
    background: #fff; padding: 32px 28px;
    border-radius: 16px; text-align: center;
    min-width: 300px;
    box-shadow: 0 24px 60px rgba(0,0,0,0.14);
    border: 1px solid #e4e8f0;
    animation: popIn 0.25s ease;
}
.success-icon {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, #16a34a, #22c55e);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px;
    font-size: 24px; color: #fff;
}
.success-card h3 {
    font-family: 'Times New Roman', Times, serif;
    font-size: 18px; font-weight: 800;
    color: #1a1f2e; margin-bottom: 6px;
}
.success-card p { font-size: 13px; color: #6b7280; margin-bottom: 20px; }
#okBtn {
    padding: 10px 28px;
    background: linear-gradient(135deg, #f97316, #fb923c);
    color: #fff; border: none; border-radius: 8px;
    font-size: 14px; font-weight: 700;
    cursor: pointer; font-family: 'Times New Roman', Times, serif;
    box-shadow: 0 2px 8px rgba(249,115,22,0.3);
    transition: all 0.2s;
}
#okBtn:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(249,115,22,0.4); }

.field-error {
    font-size: 11px;
    color: #dc2626;
    margin-top: 3px;
    display: none;
}
.field-error.show { display: block; }
input.invalid { border-color: #dc2626 !important; box-shadow: 0 0 0 3px rgba(220,38,38,0.1) !important; }

/* ===== RESPONSIVE ===== */
@media (max-width: 640px) {
    .content { margin-left: 80px !important; padding: 8px 16px 16px !important; }
    .topbar { left: 80px !important; }
    .two-grid { grid-template-columns: 1fr; }
    .two-grid .full { grid-column: 1; }
    .name-row { flex-direction: column; }
    .name-row select { width: 100%; }
    .two-col { flex-direction: column; gap: 0; }
}


body { font-family: 'Times New Roman', Times, serif !important; }
h1,h2,h3,h4,h5,h6,.page-title,.card-title,.tc-title,.rev-card-title,
.ts-value,.ts-label,.stat-value,.stat-label,.inv-num,.section-title,
.sec-label,.form-card-header h3,.modal-title,.logo-company-name,
.topbar-badge span,.company-meta strong { 
    font-family: 'Times New Roman', Times, serif !important; 
}

                ::-webkit-scrollbar { width: 2px; height: 2px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #ccc; border-radius: 99px; }
        html { scrollbar-width: thin; scrollbar-color: #ccc transparent; }
        </style>
</head>
<body>

<?php include 'sidebar.php'; ?>
<?php include 'header.php'; ?>


<div class="content">
<div class="page-wrap">

<form id="editForm">
<input type="hidden" name="id" value="<?= $customer['id'] ?>">
<input type="hidden" name="form_type" value="main">

<div class="form-card">

    <!-- Card Header -->
    <div class="card-header">
        <div class="card-header-left">
            <div class="customer-avatar">
                <?= strtoupper(substr($customer['first_name'], 0, 1)) ?>
            </div>
            <div>
                <div class="card-title"><?= htmlspecialchars($customer['first_name'] . ' ' . ($customer['last_name'] ?? '')) ?></div>
                <div class="card-sub"><?= htmlspecialchars($customer['business_name'] ?? 'No business name') ?></div>
            </div>
        </div>

    </div>

    <!-- Form Body -->
    <div class="form-body">

        <!-- Row 1: Business + Name (title + first + last) -->
        <div class="form-grid g-4">
            <div class="field span-2">
                <label>Business <span class="req">*</span></label>
                <input type="text" name="business_name"
                       value="<?= htmlspecialchars($customer['business_name']) ?>"
                       placeholder="Business name" required>
            </div>
            <div class="field span-2">
                <label>Name <span class="req">*</span></label>
                <div class="name-row">
                    <select name="title">
                        <?php foreach (['Mr.','Mrs.','Ms.','Dr.','Prof.'] as $t): ?>
                            <option value="<?= $t ?>" <?= ($customer['title'] ?? '') === $t ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="first_name"
                           value="<?= htmlspecialchars($customer['first_name']) ?>"
                           placeholder="First name" required>
                    <input type="text" name="last_name"
                           value="<?= htmlspecialchars($customer['last_name'] ?? '') ?>"
                           placeholder="Last name">
                </div>
            </div>
        </div>

        <!-- Row 2: Mobile + Email -->
        <div class="form-grid g-4">
            <div class="field span-2">
                <label>Mobile</label>
                <div class="mobile-wrap">
                    <span class="mobile-prefix">+91</span>
                    <input type="text" name="mobile"
                           value="<?= htmlspecialchars($customer['mobile'] ?? '') ?>"
                           placeholder="10-digit number" maxlength="10">
                </div>
            </div>
            <div class="field span-2">
                <label>Email</label>
                <input type="email" name="email"
                       value="<?= htmlspecialchars($customer['email']) ?>"
                       placeholder="email@example.com">
            </div>
        </div>

        <div id="moreSection">

            <!-- Address -->
            <div class="section-label">Address</div>
            <!-- Row 1: Address Line 1 + Address Line 2 -->
            <div class="form-grid g-2" style="margin-bottom:12px;">
                <div class="field">
                    <label>Address Line 1</label>
                    <input type="text" name="address_line1"
                           value="<?= htmlspecialchars($customer['address_line1'] ?? '') ?>"
                           placeholder="Street, area">
                </div>
                <div class="field">
                    <label>Address Line 2</label>
                    <input type="text" name="address_line2"
                           value="<?= htmlspecialchars($customer['address_line2'] ?? '') ?>"
                           placeholder="Landmark">
                </div>
            </div>
            <!-- Row 2: City + State + Country + Pincode -->
            <div class="form-grid g-4">
                <div class="field">
                    <label>City</label>
                    <input type="text" name="address_city"
                           value="<?= htmlspecialchars($customer['address_city'] ?? '') ?>"
                           placeholder="City">
                </div>
                <div class="field">
                    <label>State</label>
                    <input type="text" name="address_state"
                           value="<?= htmlspecialchars($customer['address_state'] ?? '') ?>"
                           placeholder="State">
                </div>
                <div class="field">
                    <label>Country</label>
                    <input type="text" name="address_country"
                           value="<?= htmlspecialchars($customer['address_country'] ?? '') ?>"
                           placeholder="Country">
                </div>
                <div class="field">
                    <label>Pincode</label>
                    <input type="text" name="pincode"
                           value="<?= htmlspecialchars($customer['pincode'] ?? '') ?>"
                           placeholder="Pincode">
                </div>
            </div>

            <!-- Tax & Legal -->
            <div class="section-label">Tax & Legal</div>
            <div class="form-grid g-4">
                <div class="field span-2">
                    <label>GSTIN</label>
                    <input type="text" name="gstin" id="gstin"
                           value="<?= htmlspecialchars($customer['gstin'] ?? '') ?>"
                           placeholder="22AAAAA0000A1Z5" maxlength="15"
                           style="font-family:monospace;text-transform:uppercase;">
                    <span class="field-error" id="gstin_error"></span>
                </div>
                <div class="field span-2">
                    <label>PAN No</label>
                    <input type="text" name="pan_no" id="pan_no"
                           value="<?= htmlspecialchars($customer['pan_no'] ?? '') ?>"
                           placeholder="ABCDE1234F" maxlength="10"
                           style="font-family:monospace;text-transform:uppercase;">
                    <span class="field-error" id="pan_error"></span>
                </div>
            </div>

            <!-- Financials -->
            <div class="section-label">Financials</div>
            <div style="padding-bottom:12px;">
                <button type="button" class="btn-financials"
                        onclick="document.getElementById('financialModal').classList.add('open')">
                    <i class="fas fa-calculator"></i> View / Edit Financials
                </button>
            </div>

        </div><!-- /.more-section -->

    </div><!-- /.form-body -->

    <!-- Bottom Actions -->
    <div class="bottom-actions">
        <button type="button" class="btn-save" onclick="submitEditForm()">
            <i class="fas fa-check"></i> Save Changes
        </button>
        <button type="button" class="btn-delete" onclick="deleteCustomer(<?= $id ?>)">
            <i class="fas fa-trash"></i> Delete Customer
        </button>
        <a href="index.php?view=customers" class="btn-cancel">
            <i class="fas fa-times"></i> Cancel
        </a>
    </div>

</div><!-- /.form-card -->
</form>

</div><!-- /.page-wrap -->
</div><!-- /.content -->

<!-- FINANCIAL MODAL (functionality unchanged) -->
<div class="modal" id="financialModal">
    <div class="modal-card">
        <button class="close-btn" onclick="document.getElementById('financialModal').classList.remove('open')">
            <i class="fas fa-times"></i>
        </button>
        <h3><i class="fas fa-calculator" style="color:#f97316;margin-right:8px"></i>Financials</h3>
        <form id="financialForm">
            <input type="hidden" name="id" value="<?= $customer['id'] ?>">
            <input type="hidden" name="form_type" value="financials">
            <div class="field">
                <label>Receivables</label>
                <input type="number" step="0.01" name="receivables"
                       value="<?= htmlspecialchars($customer['receivables'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Business Prospect</label>
                <input type="number" step="0.01" name="business_prospect"
                       value="<?= htmlspecialchars($customer['business_prospect'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Order Target</label>
                <input type="number" step="0.01" name="order_target"
                       value="<?= htmlspecialchars($customer['order_target'] ?? '') ?>">
            </div>
            <div class="field">
                <label>Receivable Notes</label>
                <textarea name="receivable_notes"><?= htmlspecialchars($customer['receivable_notes'] ?? '') ?></textarea>
            </div>
            <div class="field">
                <label>MSME No</label>
                <input type="text" name="msme_no"
                       value="<?= htmlspecialchars($customer['msme_no'] ?? '') ?>">
            </div>
            <br>
            <button type="submit" class="btn"><i class="fas fa-check"></i> Save Financials</button>
        </form>
    </div>
</div>

<!-- SUCCESS POPUP -->
<div id="successPopup">
    <div class="success-card">
        <div class="success-icon"><i class="fas fa-check"></i></div>
        <h3 id="popupTitle">Updated Successfully!</h3>
        <p id="popupMsg">Customer details have been saved.</p>
        <button id="okBtn">OK</button>
    </div>
</div>

<div id="toast"></div>

<script>
/* ── SUBMIT EDIT FORM (called by button directly) ── */
function submitEditForm() {
    if (!validateGstinPan()) return;
    var editForm = document.getElementById('editForm');
    var successPopup = document.getElementById('successPopup');
    fetch('update_customer.php', { method: 'POST', body: new FormData(editForm) })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.status === 'success') {
                document.getElementById('popupTitle').textContent = 'Updated Successfully!';
                document.getElementById('popupMsg').textContent = 'Customer details have been saved.';
                successPopup.style.display = 'flex';
                successPopup.setAttribute('data-type', 'main');
            } else {
                alert('Update failed: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(function(err) { alert('Network error: ' + err); });
}

/* ── MORE TOGGLE ── */
function toggleMore() {
    var sec   = document.getElementById('moreSection');
    var arrow = document.getElementById('moreArrow');
    var open  = sec.classList.toggle('open');
    arrow.classList.toggle('open', open);
}

/* ── TOAST ── */
function showToast(msg, type) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'show ' + (type || 'success');
    setTimeout(function() { t.className = ''; }, 3000);
}

/* ── DELETE ── */
function deleteCustomer(id) {
    if (!confirm("Delete this customer and ALL their invoices? This cannot be undone.")) return;
    fetch('deletecustomer.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id=' + id
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            showToast('Customer deleted!', 'success');
            setTimeout(function() { window.location.href = 'index.php?view=customers'; }, 1200);
        } else {
            showToast('Delete failed: ' + (data.message || 'Unknown error'), 'error');
        }
    })
    .catch(function() { showToast('Network error. Try again.', 'error'); });
}

/* ── GSTIN / PAN VALIDATION ── */
function validateGstinPan() {
    var valid = true;

    var gstinEl  = document.getElementById('gstin');
    var gstinErr = document.getElementById('gstin_error');
    if (gstinEl && gstinEl.value.trim() !== '') {
        var gstinRegex = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/;
        if (!gstinRegex.test(gstinEl.value.trim())) {
            if (gstinErr) { gstinErr.textContent = 'Invalid GSTIN. Format: 22AAAAA0000A1Z5'; gstinErr.classList.add('show'); }
            gstinEl.classList.add('invalid');
            valid = false;
        } else {
            if (gstinErr) gstinErr.classList.remove('show');
            gstinEl.classList.remove('invalid');
        }
    }

    var panEl  = document.getElementById('pan_no');
    var panErr = document.getElementById('pan_error');
    if (panEl && panEl.value.trim() !== '') {
        var panRegex = /^[A-Z]{5}[0-9]{4}[A-Z]{1}$/;
        if (!panRegex.test(panEl.value.trim())) {
            if (panErr) { panErr.textContent = 'Invalid PAN. Format: ABCDE1234F'; panErr.classList.add('show'); }
            panEl.classList.add('invalid');
            valid = false;
        } else {
            if (panErr) panErr.classList.remove('show');
            panEl.classList.remove('invalid');
        }
    }

    return valid;
}

/* ── ALL INIT ON DOM READY ── */
document.addEventListener('DOMContentLoaded', function() {

    /* auto uppercase */
    document.querySelectorAll('[name="gstin"],[name="pan_no"]').forEach(function(el) {
        el.addEventListener('input', function() { this.value = this.value.toUpperCase(); });
    });

    /* validate on blur */
    var gEl = document.getElementById('gstin');
    var pEl = document.getElementById('pan_no');
    if (gEl) gEl.addEventListener('blur', validateGstinPan);
    if (pEl) pEl.addEventListener('blur', validateGstinPan);

    /* close financial modal on overlay click */
    var finModal = document.getElementById('financialModal');
    if (finModal) {
        finModal.addEventListener('click', function(e) {
            if (e.target === this) this.classList.remove('open');
        });
    }

    var saveType   = 'main';
    var successPopup = document.getElementById('successPopup');
    var okBtn      = document.getElementById('okBtn');

    /* show popup helper */
    function showPopup(title, msg, type) {
        document.getElementById('popupTitle').textContent = title;
        document.getElementById('popupMsg').textContent   = msg;
        saveType = type;
        successPopup.style.display = 'flex';
    }

    /* OK button */
    if (okBtn) {
        okBtn.addEventListener('click', function() {
            var type = successPopup.getAttribute('data-type');
            successPopup.style.display = 'none';
            if (type === 'financials') {
                window.location.reload();
            } else {
                window.location.href = 'index.php?view=customers';
            }
        });
    }

    /* MAIN FORM SUBMIT */
    var editForm = document.getElementById('editForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!validateGstinPan()) return;
            fetch('update_customer.php', { method: 'POST', body: new FormData(editForm) })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.status === 'success') {
                        showPopup('Updated Successfully!', 'Customer details have been saved.', 'main');
                    } else {
                        alert('Update failed: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(function(err) { alert('Network error: ' + err); });
        });
    }

    /* FINANCIALS FORM SUBMIT */
    var financialForm = document.getElementById('financialForm');
    if (financialForm) {
        financialForm.addEventListener('submit', function(e) {
            e.preventDefault();
            fetch('update_customer.php', { method: 'POST', body: new FormData(financialForm) })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.status === 'success') {
                        document.getElementById('financialModal').classList.remove('open');
                        document.getElementById('popupTitle').textContent = 'Saved!';
                        document.getElementById('popupMsg').textContent = 'Financials have been saved successfully.';
                        successPopup.setAttribute('data-type', 'financials');
                        successPopup.style.display = 'flex';
                    } else {
                        alert('Save failed: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(function(err) { alert('Network error: ' + err); });
        });
    }

});
</script>
</body>
</html>
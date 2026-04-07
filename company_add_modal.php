<?php
/**
 * Reusable "Add Company" modal + JS for invoice_company.
 * Expected elements on the parent page:
 * - <select id="company_select">...</select> with option data-* attributes:
 *   data-name,data-logo,data-line1,data-line2,data-city,data-state,data-pincode,
 *   data-phone,data-email,data-gst,data-cin,data-pan,data-website
 * - Hidden inputs:
 *   co_company_name, co_company_logo, co_address_line1, co_address_line2,
 *   co_city, co_state, co_pincode, co_phone, co_email, co_gst_number, co_cin_number,
 *   co_pan, co_website, co_company_changed (optional, but recommended)
 * - Include create_invoice.php endpoint: it handles action=add_company and inserts into invoice_company.
 */
?>

<style>
/* Remove browser default blue focus ring inside modal (keep orange theme) */
#addCompanyModal input:focus{
    border-color:#f97316 !important;
    box-shadow:0 0 0 3px rgba(249,115,22,.12) !important;
    outline:none !important;
}
#addCompanyModal input{outline:none !important}
#addCompanyModal button:focus{outline:none !important; box-shadow:none !important}

/* Select2 rich dropdown theme (matches create_invoice.php) */
.select2-container--default .select2-selection--single{
    height:38px;border:1.5px solid #e4e8f0;border-radius:8px;background:#fff;
    display:flex;align-items:center;
}
.select2-container--default .select2-selection--single .select2-selection__rendered{
    line-height:38px;font-family:'Times New Roman',Times,serif;font-size:14px;color:#1a1f2e;
    padding-left:10px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:36px}
.select2-container--default.select2-container--focus .select2-selection--single{
    border-color:#f97316;
    box-shadow:0 0 0 3px rgba(249,115,22,.12);
}
.select2-search__field:focus{
    border-color:#f97316 !important;
    box-shadow:0 0 0 3px rgba(249,115,22,.12) !important;
    outline:none !important;
}
.select2-container:focus-within .select2-selection{
    border-color:#f97316 !important;
    box-shadow:0 0 0 3px rgba(249,115,22,.12) !important;
}
</style>

<!-- ADD COMPANY MODAL -->
<div id="addCompanyModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:18px;width:100%;max-width:600px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);">
        <!-- Header -->
        <div style="padding:16px 24px;border-bottom:1.5px solid #f0f2f7;display:flex;align-items:center;gap:10px;background:#fafbfd;border-radius:18px 18px 0 0;">
            <div style="width:34px;height:34px;background:linear-gradient(135deg,#f97316,#fb923c);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;">
                <i class="fas fa-building"></i>
            </div>
            <div>
                <div style="font-size:15px;font-weight:800;color:#1a1f2e;font-family:'Times New Roman',Times,serif;">Add New Company</div>
                <div style="font-size:11px;color:#9ca3af;">Saved to invoice_company table</div>
            </div>
            <button type="button" onclick="closeAddCompanyModal()" style="margin-left:auto;background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;">&times;</button>
        </div>

        <!-- Body -->
        <div style="padding:20px 24px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div style="grid-column:1/-1;">
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Company Name *</label>
                <input type="text" id="ac_company_name" placeholder="e.g. Eltrive Automations Pvt Ltd"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
            </div>

            <div style="grid-column:1/-1;">
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Company Logo</label>
                <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                    <div style="width:56px;height:56px;border-radius:12px;border:1.5px solid #e4e8f0;background:#f4f6fb;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
                        <img id="ac_logo_preview" src="" alt="Company logo" style="max-width:100%;max-height:100%;display:none;">
                        <i id="ac_logo_placeholder" class="fas fa-image" style="font-size:22px;color:#c0c8d8;"></i>
                    </div>
                    <div style="flex:1;min-width:220px;">
                        <input type="hidden" id="ac_company_logo_existing" value="">
                        <input type="file" id="ac_company_logo" accept="image/*"
                            style="width:100%;padding:7px 10px;border:1.5px dashed #e4e8f0;border-radius:8px;font-size:11px;color:#6b7280;background:#fafbfc;cursor:pointer;font-family:'Times New Roman',Times,serif;transition:border-color .2s;">
                        <div style="font-size:10px;color:#9ca3af;margin-top:2px">PNG, JPG up to 5MB</div>
                    </div>
                </div>
            </div>

            <div>
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Address Line 1</label>
                <input type="text" id="ac_address_line1" placeholder="Street / Door No."
                    style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Address Line 2</label>
                <input type="text" id="ac_address_line2" placeholder="Area / Landmark"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">City</label>
                <input type="text" id="ac_city" placeholder="City"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">State</label>
                <input type="text" id="ac_state" placeholder="State"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Pincode</label>
                <input type="text" id="ac_pincode" placeholder="500001"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Phone</label>
                <input type="text" id="ac_phone" placeholder="+91 9999999999"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Email</label>
                <input type="email" id="ac_email" placeholder="info@company.com"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">GST Number</label>
                <input type="text" id="ac_gst_number" maxlength="15" placeholder="29XXXXX1234X1ZX"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:monospace;text-transform:uppercase;color:#1a1f2e;outline:none;">
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">CIN Number</label>
                <input type="text" id="ac_cin_number" placeholder="U12345TN2020PTC000000"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:monospace;text-transform:uppercase;color:#1a1f2e;outline:none;">
            </div>
            <div>
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">PAN Number</label>
                <input type="text" id="ac_pan" maxlength="10" placeholder="AAAAA9999A"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:monospace;text-transform:uppercase;color:#1a1f2e;outline:none;">
            </div>
            <div style="grid-column:1/-1;">
                <label style="font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;display:block;margin-bottom:5px;">Website</label>
                <input type="text" id="ac_website" placeholder="www.company.com"
                    style="width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-family:'Times New Roman',Times,serif;color:#1a1f2e;outline:none;">
            </div>
        </div>

        <!-- Footer -->
        <div style="padding:14px 24px;border-top:1.5px solid #f0f2f7;display:flex;gap:10px;align-items:center;background:#fafbfd;border-radius:0 0 18px 18px;">
            <button type="button" onclick="saveNewCompany()"
                style="padding:9px 22px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;gap:6px;box-shadow:0 2px 8px rgba(249,115,22,.3);">
                <i class="fas fa-save"></i> Save Company
            </button>
            <button type="button" onclick="closeAddCompanyModal()"
                style="padding:9px 18px;background:#fff;color:#6b7280;border:1.5px solid #e4e8f0;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;font-family:'Times New Roman',Times,serif;">
                <i class="fas fa-times"></i> Cancel
            </button>
            <div id="ac_status" style="margin-left:auto;font-size:12px;color:#16a34a;font-weight:600;display:none;"></div>
        </div>
    </div>
</div>

<script>
function _acSetPreview(logoPath) {
    const img = document.getElementById('ac_logo_preview');
    const placeholder = document.getElementById('ac_logo_placeholder');
    if (!img || !placeholder) return;
    if (logoPath && String(logoPath).trim() !== '') {
        img.src = logoPath;
        img.style.display = 'block';
        placeholder.style.display = 'none';
    } else {
        img.src = '';
        img.style.display = 'none';
        placeholder.style.display = 'block';
    }
}

function openAddCompanyModal() {
    const map = {
        'ac_company_name': 'co_company_name',
        'ac_address_line1': 'co_address_line1',
        'ac_address_line2': 'co_address_line2',
        'ac_city': 'co_city',
        'ac_state': 'co_state',
        'ac_pincode': 'co_pincode',
        'ac_phone': 'co_phone',
        'ac_email': 'co_email',
        'ac_gst_number': 'co_gst_number',
        'ac_cin_number': 'co_cin_number',
        'ac_pan': 'co_pan',
        'ac_website': 'co_website',
        'ac_company_logo_existing': 'co_company_logo'
    };
    Object.entries(map).forEach(([toId, fromId]) => {
        const toEl = document.getElementById(toId);
        const fromEl = document.getElementById(fromId);
        if (!toEl) return;
        toEl.value = fromEl ? (fromEl.value || '') : '';
    });

    // Clear file input (security) but keep existing-logo value for backend.
    const fileEl = document.getElementById('ac_company_logo');
    if (fileEl) fileEl.value = '';

    const logoPath = (document.getElementById('co_company_logo')?.value || '').trim();
    _acSetPreview(logoPath);

    const status = document.getElementById('ac_status');
    if (status) { status.style.display = 'none'; status.textContent = ''; }

    const modal = document.getElementById('addCompanyModal');
    if (modal) modal.style.display = 'flex';
}

function closeAddCompanyModal() {
    const modal = document.getElementById('addCompanyModal');
    if (modal) modal.style.display = 'none';
}

function onCompanyChange(sel) {
    if (!sel) return;
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;

    const setVal = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
    setVal('co_company_name', opt.dataset.name);
    setVal('co_company_logo', opt.dataset.logo);
    setVal('co_address_line1', opt.dataset.line1);
    setVal('co_address_line2', opt.dataset.line2);
    setVal('co_city', opt.dataset.city);
    setVal('co_state', opt.dataset.state);
    setVal('co_pincode', opt.dataset.pincode);
    setVal('co_phone', opt.dataset.phone);
    setVal('co_email', opt.dataset.email);
    setVal('co_gst_number', opt.dataset.gst);
    setVal('co_cin_number', opt.dataset.cin);
    setVal('co_pan', opt.dataset.pan);
    setVal('co_website', opt.dataset.website);

    const changed = document.getElementById('co_company_changed');
    if (changed) changed.value = '1';
}

async function saveNewCompany() {
    const nameEl = document.getElementById('ac_company_name');
    const name = nameEl ? nameEl.value.trim() : '';
    if (!name) { alert('Company name is required.'); return; }

    const fd = new FormData();
    fd.append('action', 'add_company');
    fd.append('company_name', name);
    fd.append('address_line1', document.getElementById('ac_address_line1').value.trim());
    fd.append('address_line2', document.getElementById('ac_address_line2').value.trim());
    fd.append('city', document.getElementById('ac_city').value.trim());
    fd.append('state', document.getElementById('ac_state').value.trim());
    fd.append('pincode', document.getElementById('ac_pincode').value.trim());
    fd.append('phone', document.getElementById('ac_phone').value.trim());
    fd.append('email', document.getElementById('ac_email').value.trim());
    fd.append('gst_number', document.getElementById('ac_gst_number').value.trim().toUpperCase());
    fd.append('cin_number', document.getElementById('ac_cin_number').value.trim().toUpperCase());
    fd.append('pan', document.getElementById('ac_pan').value.trim().toUpperCase());
    fd.append('website', document.getElementById('ac_website').value.trim());

    const existingLogo = (document.getElementById('ac_company_logo_existing')?.value || '').trim();
    const logoFile = document.getElementById('ac_company_logo')?.files?.[0];
    if (logoFile) fd.append('company_logo', logoFile);
    else if (existingLogo) fd.append('company_logo_existing', existingLogo);

    try {
        const res = await fetch('create_invoice.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (!json || !json.success) {
            alert('Error: ' + ((json && json.message) ? json.message : 'Save failed'));
            return;
        }

        const sel = document.getElementById('company_select');
        if (sel) {
            const opt = document.createElement('option');
            opt.value = json.id;
            opt.dataset.name = name;
            opt.dataset.logo = json.company_logo || '';
            opt.dataset.line1 = document.getElementById('ac_address_line1').value.trim();
            opt.dataset.line2 = document.getElementById('ac_address_line2').value.trim();
            opt.dataset.city = document.getElementById('ac_city').value.trim();
            opt.dataset.state = document.getElementById('ac_state').value.trim();
            opt.dataset.pincode = document.getElementById('ac_pincode').value.trim();
            opt.dataset.phone = document.getElementById('ac_phone').value.trim();
            opt.dataset.email = document.getElementById('ac_email').value.trim();
            opt.dataset.gst = document.getElementById('ac_gst_number').value.trim().toUpperCase();
            opt.dataset.cin = document.getElementById('ac_cin_number').value.trim().toUpperCase();
            opt.dataset.pan = document.getElementById('ac_pan').value.trim().toUpperCase();
            opt.dataset.website = document.getElementById('ac_website').value.trim();

            const addr = [opt.dataset.line1, opt.dataset.city].filter(Boolean).join(', ');
            opt.textContent = name + (addr ? ' — ' + addr : '');

            sel.appendChild(opt);
            sel.value = json.id;
            onCompanyChange(sel);
        }

        const status = document.getElementById('ac_status');
        if (status) {
            status.textContent = '✓ Company saved & selected!';
            status.style.display = 'block';
        }
        setTimeout(closeAddCompanyModal, 1200);
    } catch(e) {
        alert('Error: ' + e.message);
    }
}

document.addEventListener('DOMContentLoaded', function(){
    const modal = document.getElementById('addCompanyModal');
    if (modal) {
        modal.addEventListener('click', function(e){
            if (e.target === this) closeAddCompanyModal();
        });
    }

    const sel = document.getElementById('company_select');
    if (sel) {
        sel.addEventListener('change', function(){ onCompanyChange(this); });
    }
    // Initial logo preview (optional)
    const logoPath = (document.getElementById('co_company_logo')?.value || '').trim();
    _acSetPreview(logoPath);
});
</script>


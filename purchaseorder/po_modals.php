
<!-- =============================================================
     po_modals.php  —  All modal dialogs for Purchase Order
     Included just before closing </body> in createpurchase.php
     ============================================================= -->

<!-- ═══════════════════════════════ SUPPLIER POPUP ════════════ -->
<div class="sp-overlay" id="spOverlay" onclick="closeSupplierPopup(event)">
    <div class="sp-box" onclick="event.stopPropagation()">
        <div class="sp-header">
            <h3>Select Supplier</h3>
            <button class="sp-close" onclick="closeSupplierPopup()">✕</button>
        </div>
        <div class="sp-search-wrap">
            <input class="sp-search" id="spSearch" type="text" placeholder="Search suppliers..." oninput="filterSuppliers(this.value)">
        </div>
        <div class="sp-list" id="spList">
            <div class="sp-empty">Loading...</div>
        </div>
        <div class="sp-footer">
            <button class="sp-add-btn" onclick="openNewSupplierForm()"><i class="fas fa-plus"></i> Add New Supplier</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════ MODAL: Add New Supplier ═══ -->
<div class="modal-overlay" id="supplierModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Add New Supplier</h3>
      <div class="modal-header-btns">
        <button class="btn-modal-save" onclick="saveNewSupplier()"><i class="fas fa-check"></i> Save</button>
        <button class="modal-close" onclick="closeModal('supplierModal')">✕</button>
      </div>
    </div>
    <div class="modal-body">
      <div class="mf-group">
        <label class="mf-label">Business / Company Name <span class="req">*</span></label>
        <input class="mf-input" id="ns_business" type="text" placeholder="Company name">
      </div>
      <div class="mf-row">
        <div class="mf-group">
          <label class="mf-label">Contact Person</label>
          <input class="mf-input" id="ns_contact" type="text" placeholder="Full name">
        </div>
        <div class="mf-group">
          <label class="mf-label">Mobile</label>
          <div class="prefix-box"><span>+91</span><input id="ns_mobile" type="text" placeholder="Mobile"></div>
        </div>
      </div>
      <div class="mf-group">
        <label class="mf-label">Email <span class="req">*</span></label>
        <input class="mf-input" id="ns_email" type="email" placeholder="supplier@email.com">
      </div>
      <div class="mf-group">
        <label class="mf-label">Address <span class="req">*</span></label>
        <textarea class="mf-textarea" id="ns_address" placeholder="Full address" style="min-height:60px"></textarea>
      </div>
      <div class="mf-row">
        <div class="mf-group">
          <label class="mf-label">GSTIN <span class="req">*</span></label>
          <input class="mf-input" id="ns_gstin" type="text" placeholder="22AAAAA0000A1Z5" maxlength="15"
                 oninput="validateGSTIN(this,'ns_gstin_hint')" style="text-transform:uppercase;">
          <div class="field-hint" id="ns_gstin_hint"></div>
        </div>
        <div class="mf-group">
          <label class="mf-label">PAN <span class="req">*</span></label>
          <input class="mf-input" id="ns_pan" type="text" placeholder="ABCDE1234F" maxlength="10"
                 oninput="validatePAN(this,'ns_pan_hint')" style="text-transform:uppercase;">
          <div class="field-hint" id="ns_pan_hint"></div>
        </div>
      </div>
      <div class="mf-group">
        <label class="mf-label">Website</label>
        <input class="mf-input" id="ns_website" type="text" placeholder="https://example.com">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-modal-save" onclick="saveNewSupplier()"><i class="fas fa-check"></i> Save</button>
      <button class="btn-modal-cancel" onclick="closeModal('supplierModal')">Cancel</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════ MODAL: Select Item ════════ -->
<div class="modal-overlay" id="selectItemModal">
  <div class="modal-box" style="width:540px;">
    <div class="modal-header">
      <h3>📦 Item Library</h3>
      <button class="modal-close" onclick="closeModal('selectItemModal')">✕</button>
    </div>
    <div class="modal-body" style="padding:12px 16px 6px;">
      <input class="modal-search" id="itemSearch" type="text" placeholder="🔍 Search by name, HSN, description..." oninput="filterItems(this.value)">
      <div id="itemSelectList" style="max-height:400px;overflow-y:auto;border:1px solid #f0f2f7;border-radius:8px;">
        <div style="padding:30px;text-align:center;color:#9ca3af;font-size:13px;">Loading...</div>
      </div>
      <p style="font-size:11px;color:#9ca3af;margin-top:6px;text-align:center;">Click <b>+ Add</b> on any item to add it to the PO. You can add multiple items.</p>
    </div>
    <div class="modal-footer" style="justify-content:space-between;">
      <button class="btn-modal-save" style="background:#1a2940;" onclick="closeModal('selectItemModal');openModal('addItemModal');">
        <i class="fas fa-plus"></i> Create New Item
      </button>
      <button class="btn-modal-save" style="background:#2e7d32;" onclick="closeModal('selectItemModal')">
        <i class="fas fa-check"></i> Done
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════ MODAL: Add Item ══════════ -->
<div class="modal-overlay" id="addItemModal">
  <div class="modal-box sm" style="width:360px;">
    <div class="modal-header" style="padding:12px 16px;">
      <h3>Add Item</h3>
      <button class="modal-close" onclick="closeModal('addItemModal')">✕</button>
    </div>
    <div class="modal-body" style="padding:12px 16px;">
      <div class="mf-row" style="margin-bottom:10px;">
        <div class="mf-group" style="flex:1;margin-bottom:0;">
          <label class="mf-label">Item Name <span class="req">*</span></label>
          <input class="mf-input" id="ai_name" type="text" placeholder="Item name">
        </div>
      </div>
      <div class="mf-row" style="margin-bottom:10px;">
        <div class="mf-group" style="flex:1;margin-bottom:0;">
          <label class="mf-label">Rate <span class="req">*</span></label>
          <div class="prefix-box"><span>₹</span><input id="ai_rate" type="number" value="0" min="0" step="0.01"></div>
        </div>
        <div class="mf-group" style="flex:0 0 100px;margin-bottom:0;">
          <label class="mf-label">Unit</label>
          <select class="mf-select" id="ai_unit">
            <option>no.s</option><option>pcs</option><option>kg</option><option>m</option><option>ltr</option><option>set</option><option>hr</option>
          </select>
        </div>
      </div>
      <div class="mf-group" style="margin-bottom:10px;">
        <label class="mf-label">HSN/SAC <span class="req">*</span></label>
        <input class="mf-input" id="ai_hsn" type="text" placeholder="HSN/SAC code">
      </div>
      <div class="mf-group" style="margin-bottom:10px;">
        <label class="mf-label">Description</label>
        <textarea class="mf-textarea" id="ai_desc" placeholder="Description (optional)" style="min-height:50px;"></textarea>
      </div>
      <div class="mf-row">
        <div class="mf-group" style="margin-bottom:0;">
          <label class="mf-label">CGST %</label>
          <div class="prefix-box"><input id="ai_cgst" type="number" value="0" min="0" step="0.01"><span>%</span></div>
        </div>
        <div class="mf-group" style="margin-bottom:0;">
          <label class="mf-label">SGST %</label>
          <div class="prefix-box"><input id="ai_sgst" type="number" value="0" min="0" step="0.01"><span>%</span></div>
        </div>
        <div class="mf-group" style="margin-bottom:0;">
          <label class="mf-label">IGST %</label>
          <div class="prefix-box"><input id="ai_igst" type="number" value="0" min="0" step="0.01"><span>%</span></div>
        </div>
      </div>
    </div>
    <div class="modal-footer" style="padding:10px 16px;">
      <button class="btn-modal-save" onclick="saveAddItem(true)"><i class="fas fa-bookmark"></i> Save &amp; Add</button>
      <button class="btn-modal-cancel" onclick="closeModal('addItemModal');openModal('selectItemModal');">← Back</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════ MODAL: Extra Charge ══════ -->
<div class="modal-overlay" id="extraChargeModal">
  <div class="modal-box sm">
    <div class="modal-header">
      <h3>Add Extra Charge</h3>
      <button class="modal-close" onclick="closeModal('extraChargeModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="mf-group">
        <label class="mf-label">Charge Name</label>
        <input class="mf-input" id="ec_item" type="text" placeholder="e.g. Freight, Packing">
      </div>
      <div class="mf-group">
        <label class="mf-label">Amount ₹</label>
        <div class="prefix-box"><span>₹</span><input id="ec_amount" type="number" value="0" min="0" step="0.01"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-modal-save" onclick="saveExtraCharge()"><i class="fas fa-check"></i> Add</button>
      <button class="btn-modal-cancel" onclick="closeModal('extraChargeModal')">Cancel</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════ MODAL: Select Term ═══════ -->
<div class="modal-overlay" id="termModal">
  <div class="modal-box" onclick="event.stopPropagation()" style="display:flex;flex-direction:column;max-height:85vh;overflow:hidden;">
    <div class="modal-header" style="position:static;flex-shrink:0;">
      <h3>Select Term / Condition</h3>
      <button class="modal-close" onclick="closeModal('termModal')">✕</button>
    </div>
    <div class="modal-body" style="flex:1;overflow:hidden;display:flex;flex-direction:column;padding:16px 20px;">
      <input class="modal-search" id="termSearch" type="text" placeholder="🔍 Search terms..." oninput="filterTerms(this.value)" style="flex-shrink:0;">
      <div class="term-select-list" id="termSelectList" style="flex:1;overflow-y:auto;max-height:none;border:1px solid #f0f2f7;border-radius:8px;">
        <div style="padding:20px;text-align:center;color:#9ca3af;">Loading...</div>
      </div>
    </div>
    <div class="modal-footer" style="flex-shrink:0;">
      <button class="btn-modal-save" style="background:#1565c0;" onclick="openAddNewTerm()"><i class="fas fa-plus"></i> Add New Term</button>
      <button class="btn-modal-cancel" onclick="closeModal('termModal')">Close</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════ MODAL: Billing Address ═══ -->
<div class="modal-overlay" id="addressModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>Add Address</h3>
      <button class="modal-close" onclick="closeModal('addressModal')">✕</button>
    </div>
    <div class="modal-body">
      <div class="mf-group">
        <label class="mf-label">Address Line 1</label>
        <input class="mf-input" id="addr_line1" type="text" placeholder="Street / Building">
      </div>
      <div class="mf-group">
        <label class="mf-label">Address Line 2</label>
        <input class="mf-input" id="addr_line2" type="text" placeholder="Area / Landmark">
      </div>
      <div class="mf-row">
        <div class="mf-group">
          <label class="mf-label">City</label>
          <input class="mf-input" id="addr_city" type="text">
        </div>
        <div class="mf-group">
          <label class="mf-label">State</label>
          <select class="mf-select" id="addr_state">
            <option value="">Select State</option>
            <option>Andhra Pradesh</option><option selected>Telangana</option><option>Karnataka</option>
            <option>Maharashtra</option><option>Tamil Nadu</option><option>Gujarat</option>
            <option>Rajasthan</option><option>Delhi</option><option>West Bengal</option>
            <option>Uttar Pradesh</option><option>Kerala</option><option>Punjab</option>
          </select>
        </div>
      </div>
      <div class="mf-group">
        <label class="mf-label">Pincode</label>
        <input class="mf-input" id="addr_pin" type="text" style="max-width:150px">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn-modal-save" onclick="saveAddress()"><i class="fas fa-check"></i> Save</button>
      <button class="btn-modal-cancel" onclick="closeModal('addressModal')">Cancel</button>
    </div>
  </div>
</div>

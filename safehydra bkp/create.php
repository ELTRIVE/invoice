<?php
// safehydra/create.php
require_once dirname(__DIR__) . '/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Safe Hydra – Create</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:#f0f4f8;font-family:'Times New Roman',Times,serif;}
.page-wrap{margin-left:190px;min-height:100vh;}

.topbar{background:#fff;border-bottom:1px solid #e4e8f0;padding:0 24px;height:52px;display:flex;align-items:center;position:sticky;top:0;z-index:50;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.topbar-title{font-size:15px;font-weight:800;color:#1a1f2e;display:flex;align-items:center;gap:8px;}
.topbar-title i{color:#22c55e;}

.content{padding:36px 24px;}

.create-hero{text-align:center;margin-bottom:32px;}
.create-hero h1{font-size:24px;font-weight:800;color:#1a1f2e;margin-bottom:6px;}
.create-hero p{font-size:13px;color:#9ca3af;}

.options-grid{display:grid;grid-template-columns:1fr 1fr;gap:22px;max-width:700px;margin:0 auto;}
.option-card{background:#fff;border-radius:13px;border:2px solid #e4e8f0;padding:34px 26px;text-align:center;cursor:pointer;transition:all .2s;position:relative;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.04);}
.option-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;}
.option-card.preview-card::before{background:linear-gradient(90deg,#1d4ed8,#3b82f6);}
.option-card.generate-card::before{background:linear-gradient(90deg,#16a34a,#22c55e);}
.option-card:hover{transform:translateY(-3px);box-shadow:0 10px 30px rgba(0,0,0,.1);border-color:transparent;}
.option-icon{width:68px;height:68px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:26px;margin:0 auto 16px;}
.preview-card .option-icon{background:#eff6ff;color:#1d4ed8;}
.generate-card .option-icon{background:#f0fdf4;color:#16a34a;}
.option-card h2{font-size:17px;font-weight:800;margin-bottom:7px;color:#1a1f2e;}
.option-card p{font-size:12.5px;color:#9ca3af;line-height:1.6;}
.card-btn{display:inline-flex;align-items:center;gap:6px;margin-top:18px;padding:8px 18px;border-radius:7px;font-size:12.5px;font-weight:800;border:none;cursor:pointer;transition:all .15s;font-family:'Times New Roman',Times,serif;}
.preview-card .card-btn{background:#1d4ed8;color:#fff;}
.preview-card .card-btn:hover{background:#1e40af;}
.generate-card .card-btn{background:#16a34a;color:#fff;}
.generate-card .card-btn:hover{background:#15803d;}

/* Preview modal */
.preview-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:9999;align-items:center;justify-content:center;}
.preview-overlay.show{display:flex;}
.preview-modal{background:#fff;border-radius:11px;width:88vw;max-width:940px;height:87vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.3);}
.preview-modal-header{padding:13px 18px;border-bottom:1px solid #e4e8f0;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;}
.preview-modal-header h3{font-size:14px;font-weight:800;color:#1a1f2e;display:flex;align-items:center;gap:7px;}
.preview-close{background:none;border:none;font-size:19px;cursor:pointer;color:#9ca3af;}
.preview-close:hover{color:#ef4444;}
.preview-modal iframe{flex:1;border:none;width:100%;}

/* Generate modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9999;align-items:center;justify-content:center;}
.modal-overlay.show{display:flex;}
.modal{background:#fff;border-radius:13px;padding:30px;width:440px;max-width:95vw;box-shadow:0 20px 60px rgba(0,0,0,.15);position:relative;}
.modal-close{position:absolute;top:13px;right:15px;background:none;border:none;font-size:17px;cursor:pointer;color:#9ca3af;}
.modal-close:hover{color:#ef4444;}
.modal h2{font-size:17px;font-weight:800;color:#1a1f2e;margin-bottom:5px;display:flex;align-items:center;gap:8px;}
.modal-sub{font-size:11.5px;color:#9ca3af;margin-bottom:22px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:11px;font-weight:800;color:#374151;margin-bottom:4px;text-transform:uppercase;letter-spacing:.6px;}
.form-group input{width:100%;padding:9px 12px;border:1.5px solid #e4e8f0;border-radius:7px;font-size:13.5px;outline:none;transition:border .15s;font-family:'Times New Roman',Times,serif;}
.form-group input:focus{border-color:#f97316;}
.form-group input.error{border-color:#ef4444;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 15px;border-radius:7px;font-size:12.5px;font-weight:700;cursor:pointer;border:none;transition:all .15s;font-family:'Times New Roman',Times,serif;}
.btn-green{background:#16a34a;color:#fff;}
.btn-green:hover{background:#15803d;}
.btn-outline{background:#fff;color:#f97316;border:1.5px solid #f97316;}
.btn-outline:hover{background:#fff7f0;}
.modal-actions{display:flex;gap:9px;margin-top:22px;}
.modal-actions .btn{flex:1;justify-content:center;padding:10px;}
.toast{position:fixed;bottom:22px;right:22px;background:#16a34a;color:#fff;padding:11px 18px;border-radius:8px;font-size:12.5px;font-weight:700;z-index:99999;display:none;align-items:center;gap:7px;box-shadow:0 4px 18px rgba(0,0,0,.18);}
.toast.show{display:flex;}
.toast.error{background:#ef4444;}
</style>
</head>
<body>

<?php include dirname(__DIR__) . '/sidebar.php'; ?>

<div class="page-wrap">
  <div class="topbar">
    <div class="topbar-title"><i class="fas fa-fire-extinguisher"></i> Safe Hydra – Create</div>
  </div>
  <div class="content">
    <div class="create-hero">
      <h1>What would you like to do?</h1>
      <p>Preview the Safe Hydra template or generate a new customised proposal document.</p>
    </div>
    <div class="options-grid">
      <div class="option-card preview-card">
        <div class="option-icon"><i class="fas fa-eye"></i></div>
        <h2>Preview Document</h2>
        <p>View the Safe Hydra Fire Hydrant Pump House Monitoring System template document exactly as it will appear.</p>
        <button class="card-btn" onclick="openPreview()"><i class="fas fa-eye"></i> Preview</button>
      </div>
      <div class="option-card generate-card">
        <div class="option-icon"><i class="fas fa-bolt"></i></div>
        <h2>Generate New Document</h2>
        <p>Create a personalised proposal by filling in customer details. Saved and ready to download as PDF.</p>
        <button class="card-btn" onclick="openGenerate()"><i class="fas fa-plus"></i> Generate New</button>
      </div>
    </div>
  </div>
</div>

<!-- Preview Modal -->
<div class="preview-overlay" id="previewOverlay">
  <div class="preview-modal">
    <div class="preview-modal-header">
      <h3><i class="fas fa-file-pdf" style="color:#ef4444;"></i> Safe Hydra – Template Preview</h3>
      <button class="preview-close" onclick="closePreview()"><i class="fas fa-times"></i></button>
    </div>
    <iframe src="/invoice/safehydra/preview.php" id="previewFrame"></iframe>
  </div>
</div>

<!-- Generate Modal -->
<div class="modal-overlay" id="genModal">
  <div class="modal">
    <button class="modal-close" onclick="closeGenerate()"><i class="fas fa-times"></i></button>
    <h2><i class="fas fa-fire-extinguisher" style="color:#22c55e;"></i> Generate New Document</h2>
    <p class="modal-sub">Fill in the customer details to create a new Safe Hydra proposal.</p>
    <div class="form-group"><label>Customer Name</label><input type="text" id="f_customer" placeholder="e.g. M/S Aragen Pharma, Nacharam"></div>
    <div class="form-group"><label>Company Name</label><input type="text" id="f_company" placeholder="e.g. Eltrive Automations Pvt Ltd"></div>
    <div class="form-group"><label>Total Amount (₹)</label><input type="number" id="f_amount" placeholder="e.g. 939811" min="1"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeGenerate()">Cancel</button>
      <button class="btn btn-green" onclick="generateDoc()"><i class="fas fa-bolt"></i> Generate</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"><i class="fas fa-check-circle"></i><span id="toast-msg"></span></div>

<script>
function openPreview(){document.getElementById('previewOverlay').classList.add('show');}
function closePreview(){document.getElementById('previewOverlay').classList.remove('show');}
function openGenerate(){document.getElementById('genModal').classList.add('show');}
function closeGenerate(){document.getElementById('genModal').classList.remove('show');clearFields();}
function clearFields(){['f_customer','f_company','f_amount'].forEach(id=>{const el=document.getElementById(id);el.value='';el.classList.remove('error');});}
function showToast(msg,isErr=false){const t=document.getElementById('toast');document.getElementById('toast-msg').textContent=msg;t.className='toast show'+(isErr?' error':'');setTimeout(()=>t.classList.remove('show'),3500);}
function generateDoc(){
  const customer=document.getElementById('f_customer').value.trim();
  const company=document.getElementById('f_company').value.trim();
  const amount=document.getElementById('f_amount').value.trim();
  let valid=true;
  [['f_customer',customer],['f_company',company],['f_amount',amount]].forEach(([id,val])=>{
    const el=document.getElementById(id);
    if(!val||val==='0'){el.classList.add('error');valid=false;}else el.classList.remove('error');
  });
  if(!valid){showToast('Please fill all fields.',true);return;}
  const fd=new FormData();
  fd.append('action','generate');fd.append('customer_name',customer);fd.append('company_name',company);fd.append('amount',amount);
  fetch('/invoice/safehydra/index.php',{method:'POST',body:fd})
    .then(r=>r.json()).then(data=>{
      if(data.success){
        closeGenerate();
        showToast('Document '+data.doc_number+' generated! Redirecting...');
        setTimeout(()=>{window.location.href='/invoice/safehydra/index.php';},1800);
      }else{showToast(data.message,true);}
    }).catch(()=>showToast('Server error.',true));
}
document.getElementById('previewOverlay').addEventListener('click',function(e){if(e.target===this)closePreview();});
document.getElementById('genModal').addEventListener('click',function(e){if(e.target===this)closeGenerate();});
</script>
</body>
</html>
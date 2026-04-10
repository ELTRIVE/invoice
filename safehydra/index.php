<?php
// safehydra/index.php — All Documents listing
require_once dirname(__DIR__) . '/db.php';

// Handle AJAX generate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    header('Content-Type: application/json');
    $customer = trim($_POST['customer_name'] ?? '');
    $project  = trim($_POST['project_name']  ?? ($_POST['company_name'] ?? ''));
    $amountRaw = trim((string)($_POST['amount'] ?? '0'));
    $amount   = is_numeric($amountRaw) ? floatval($amountRaw) : 0;
    $count = $pdo->query("SELECT COUNT(*) FROM safe_hydra_documents")->fetchColumn();
    $doc_number = 'SH-' . date('Ymd') . '-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare("INSERT INTO safe_hydra_documents (customer_name, company_name, amount, document_number) VALUES (?, ?, ?, ?)");
    $stmt->execute([$customer, $project, $amount, $doc_number]);
    $new_id = $pdo->lastInsertId();

    echo json_encode([
        'success'       => true,
        'action'        => 'generate',
        'id'            => $new_id,
        'doc_number'    => $doc_number,
        'customer_name' => $customer,
        'project_name'  => $project,
        'company_name'  => $project,
        'amount'        => number_format($amount, 2),
        'created_at'    => date('d-M-Y H:i'),
    ]);
    exit;
}

// Fetch all documents
$docs = $pdo->query("SELECT * FROM safe_hydra_documents ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Safe Hydra – All Documents</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:#f0f4f8;font-family:'Times New Roman',Times,serif;}
.page-wrap{margin-left:190px;min-height:100vh;}

.topbar{background:#fff;border-bottom:1px solid #e4e8f0;padding:0 24px;height:52px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;box-shadow:0 2px 8px rgba(0,0,0,.04);}
.topbar-title{font-size:15px;font-weight:800;color:#1a1f2e;display:flex;align-items:center;gap:8px;}
.topbar-title i{color:#22c55e;}

.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 15px;border-radius:7px;font-size:12.5px;font-weight:700;cursor:pointer;border:none;transition:all .15s;font-family:'Times New Roman',Times,serif;}
.btn-green{background:#22c55e;color:#fff;}
.btn-green:hover{background:#16a34a;}
.btn-outline{background:#fff;color:#f97316;border:1.5px solid #f97316;}
.btn-outline:hover{background:#fff7f0;}

.sh-content{padding:20px 22px;}

.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px;}
.stat-card{background:#fff;border-radius:10px;padding:16px 18px;border:1px solid #e4e8f0;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,.03);}
.stat-icon{width:40px;height:40px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.stat-icon.blue{background:#eff6ff;color:#1d4ed8;}
.stat-icon.green{background:#f0fdf4;color:#16a34a;}
.stat-icon.orange{background:#fff7ed;color:#ea580c;}
.stat-info p{font-size:10.5px;color:#9ca3af;font-weight:600;margin-bottom:2px;}
.stat-info h3{font-size:20px;font-weight:800;color:#1a1f2e;}

.table-card{background:#fff;border-radius:10px;border:1px solid #e4e8f0;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.03);}
.table-header{padding:14px 18px;border-bottom:1px solid #e4e8f0;display:flex;align-items:center;justify-content:space-between;}
.table-header h2{font-size:14px;font-weight:800;color:#1a1f2e;}
.table-search{display:flex;align-items:center;gap:7px;background:#f8fafc;border:1px solid #e4e8f0;border-radius:7px;padding:5px 11px;width:210px;}
.table-search input{border:none;background:transparent;outline:none;font-size:12.5px;width:100%;font-family:'Times New Roman',Times,serif;}
table{width:100%;border-collapse:collapse;}
thead tr{background:#f8fafc;}
th{padding:10px 15px;font-size:10.5px;font-weight:800;color:#9ca3af;text-transform:uppercase;letter-spacing:.8px;text-align:left;border-bottom:1px solid #e4e8f0;}
td{padding:12px 15px;font-size:12.5px;border-bottom:1px solid #f0f2f7;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafafa;}
.doc-num{font-weight:800;color:#f97316;font-size:11.5px;}
.customer-name{font-weight:700;color:#1a1f2e;}
.amount-cell{font-weight:700;color:#16a34a;}
.date-cell{color:#9ca3af;font-size:11.5px;}
.doc-row{cursor:pointer;}
.empty-state{text-align:center;padding:50px 20px;color:#9ca3af;}
.empty-state i{font-size:36px;margin-bottom:10px;opacity:.3;display:block;}

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
.modal-actions{display:flex;gap:9px;margin-top:22px;}
.modal-actions .btn{flex:1;justify-content:center;padding:10px;}
.toast{position:fixed;bottom:22px;right:22px;background:#16a34a;color:#fff;padding:11px 18px;border-radius:8px;font-size:12.5px;font-weight:700;z-index:99999;display:none;align-items:center;gap:7px;box-shadow:0 4px 18px rgba(0,0,0,.18);}
.toast.show{display:flex;}
.toast.error{background:#ef4444;}
.download-popup .modal{width:360px;border:1px solid #e4e8f0;}
.download-actions{display:flex;gap:10px;margin-top:14px;justify-content:center;}
.download-actions .btn{justify-content:center;padding:10px;min-width:170px;}
</style>
</head>
<body>

<?php include dirname(__DIR__) . '/sidebar.php'; ?>

<div class="page-wrap">
  <div class="topbar">
    <div class="topbar-title"><i class="fas fa-fire-extinguisher"></i> Safe Hydra – All Documents</div>
    <button class="btn btn-green" onclick="openModal()"><i class="fas fa-plus"></i> Generate New Document</button>
  </div>

  <div class="sh-content">
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-file-alt"></i></div>
        <div class="stat-info"><p>Total Documents</p><h3><?= count($docs) ?></h3></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-rupee-sign"></i></div>
        <div class="stat-info"><p>Total Value</p><h3>₹<?= number_format(array_sum(array_column($docs,'amount')),0) ?></h3></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon orange"><i class="fas fa-calendar-alt"></i></div>
        <div class="stat-info"><p>This Month</p><h3><?= count(array_filter($docs, fn($d) => date('Y-m', strtotime($d['created_at'])) === date('Y-m'))) ?></h3></div>
      </div>
    </div>

    <div class="table-card">
      <div class="table-header">
        <h2><i class="fas fa-list" style="color:#f97316;margin-right:5px;"></i> Documents</h2>
        <div class="table-search"><i class="fas fa-search" style="color:#9ca3af;font-size:12px;"></i><input type="text" id="searchInput" placeholder="Search..." onkeyup="filterTable()"></div>
      </div>
      <table id="docsTable">
        <thead>
          <tr><th>#</th><th>Doc Number</th><th>Customer Name</th><th>Project Name</th><th>Amount (₹)</th><th>Created</th></tr>
        </thead>
        <tbody id="tableBody">
          <?php if(empty($docs)): ?>
          <tr><td colspan="6"><div class="empty-state"><i class="fas fa-folder-open"></i><p>No documents yet.</p></div></td></tr>
          <?php else: foreach($docs as $i=>$d): ?>
          <tr class="doc-row" data-id="<?= (int)$d['id'] ?>" data-customer="<?= htmlspecialchars($d['customer_name'], ENT_QUOTES) ?>">
            <td><?= $i+1 ?></td>
            <td><span class="doc-num"><?= htmlspecialchars($d['document_number']) ?></span></td>
            <td><span class="customer-name"><?= htmlspecialchars($d['customer_name']) ?></span></td>
            <td><?= htmlspecialchars($d['company_name']) ?></td>
            <td><span class="amount-cell">₹<?= number_format($d['amount'],2) ?></span></td>
            <td><span class="date-cell"><?= date('d-M-Y', strtotime($d['created_at'])) ?></span></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Generate Modal -->
<div class="modal-overlay" id="genModal">
  <div class="modal">
    <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
    <h2><i class="fas fa-fire-extinguisher" style="color:#22c55e;"></i> <span id="modalTitle">Generate New Document</span></h2>
    <p class="modal-sub">Fill in the details to create a new Safe Hydra proposal.</p>
    <div class="form-group"><label>Customer Name</label><input type="text" id="f_customer" placeholder="e.g. M/S Aragen Pharma, Nacharam"></div>
    <div class="form-group"><label>Project Name</label><input type="text" id="f_project" placeholder="e.g. Safe Hydra Plant 2"></div>
    <div class="form-group"><label>Total Amount (₹)</label><input type="number" id="f_amount" placeholder="e.g. 939811" min="1"></div>
    <div class="modal-actions">
      <button class="btn btn-outline" onclick="closeModal()">Cancel</button>
      <button class="btn btn-green" onclick="generateDoc()"><i class="fas fa-bolt"></i> Generate</button>
    </div>
  </div>
</div>

<!-- Download options popup -->
<div class="modal-overlay download-popup" id="downloadPopup">
  <div class="modal">
    <button class="modal-close" onclick="closeDownloadPopup()"><i class="fas fa-times"></i></button>
    <h2><i class="fas fa-file-export" style="color:#f97316;"></i> Download Options</h2>
    <p class="modal-sub">Customer: <strong id="popupCustomerName">-</strong></p>
    <div class="download-actions">
      <a class="btn btn-green" id="popupPdfBtn" href="#" target="_blank"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
  </div>
</div>

<div class="toast" id="toast"><i class="fas fa-check-circle"></i><span id="toast-msg"></span></div>

<script>
function openModal(){document.getElementById('genModal').classList.add('show');}
function closeModal(){document.getElementById('genModal').classList.remove('show');clearFields();document.getElementById('modalTitle').textContent='Generate New Document';}
function clearFields(){['f_customer','f_project','f_amount'].forEach(id=>{const el=document.getElementById(id);if(el){el.value='';el.classList.remove('error');}});}
function showToast(msg,isErr=false){const t=document.getElementById('toast');document.getElementById('toast-msg').textContent=msg;t.className='toast show'+(isErr?' error':'');setTimeout(()=>t.classList.remove('show'),3500);}
function closeDownloadPopup(){document.getElementById('downloadPopup').classList.remove('show');}
function openDownloadPopup(id, customer){
  document.getElementById('popupCustomerName').textContent = customer || '-';
  document.getElementById('popupPdfBtn').href = '/invoice/safehydra/download_sh.php?id=' + id + '&t=' + Date.now();
  document.getElementById('downloadPopup').classList.add('show');
}
function generateDoc(){
  const customer=document.getElementById('f_customer').value.trim();
  const project=document.getElementById('f_project').value.trim();
  const amount=document.getElementById('f_amount').value.trim();
  const fd=new FormData();
  fd.append('action', 'generate');
  fd.append('customer_name',customer);
  fd.append('project_name',project);
  fd.append('amount',amount);
  fetch('/invoice/safehydra/index.php',{method:'POST',body:fd})
    .then(r=>r.json()).then(data=>{
      if(data.success){
        showToast('Document '+data.doc_number+' generated!');
        setTimeout(()=>window.location.reload(), 600);
        closeModal();
      }else{showToast(data.message,true);}
    }).catch(()=>showToast('Server error.',true));
}
function filterTable(){
  const q=document.getElementById('searchInput').value.toLowerCase();
  document.querySelectorAll('#tableBody tr').forEach(row=>{row.style.display=row.textContent.toLowerCase().includes(q)?'':'none';});
}
document.getElementById('genModal').addEventListener('click',function(e){if(e.target===this)closeModal();});
document.getElementById('downloadPopup').addEventListener('click',function(e){if(e.target===this)closeDownloadPopup();});
document.querySelectorAll('#tableBody .doc-row').forEach(row=>{
  row.addEventListener('click', function(){
    openDownloadPopup(this.dataset.id, this.dataset.customer);
  });
});
</script>
</body>
</html>

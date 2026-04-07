<?php
// /invoice/techno_commercial/tc_index.php
if (session_status() === PHP_SESSION_NONE) session_start();

$tcSub  = $_GET['tc_sub']  ?? 'create';
$tcPage = $_GET['tc_page'] ?? 'mainproject';

$pageTitles = [
    'mainproject'   => 'Main Project Details',
    'overview'      => 'Project Overview',
    'benefits'      => 'Expected Benefits & Tentative ROI',
    'scope'         => 'Project Scope of Work',
    'current'       => 'Current System Scenario',
    'proposed'      => 'Proposed Solution',
    'utilities'     => 'Utilities Covered Under Project',
    'architecture'  => 'System Architecture',
    'kpis'          => 'Standard Utility KPIs',
    'dashboardfeat' => 'Dashboard Features',
    'testing'       => 'Testing & Commissioning',
    'deliverables'  => 'Deliverables',
    'customer'      => 'Customer Scope',
    'outscope'      => 'Out of Scope',
    'commercials'   => 'Commercials',
    'comsummary'    => 'Commercial Summary – UMS',
];

$pageTitle  = $tcSub === 'documents' ? 'Documents' : ($pageTitles[$tcPage] ?? 'Main Project Details');
$breadcrumb = $tcSub === 'documents'
    ? 'Techno Commercial › Documents'
    : 'Techno Commercial › Create › ' . $pageTitle;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Techno Commercial – <?= htmlspecialchars($pageTitle) ?> | Eltrive</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
/* ── RESET & BASE ── */
*{margin:0;padding:0;box-sizing:border-box;}
:root{
  /* GREEN ELTRIVE THEME – soft, matching the document header */
  --primary:#3dba6f;
  --primary-dark:#2a9555;
  --primary-light:#e8f7ef;
  --primary-border:#b6e8cc;
  --navy:#1a1a2e; --sidebar-hover:#16213e; --sidebar-act:#0f3460;
  --text:#222; --muted:#6b7280; --border:#e4e8f0;
  --bg:#f4f6f9; --white:#fff;
  --header-h:56px;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);height:100vh;overflow:hidden;display:flex;flex-direction:column;}

/* ── TOP HEADER ── */
.top-header{
  height:var(--header-h);background:var(--white);
  border-bottom:2px solid var(--primary);
  display:flex;align-items:center;padding:0 20px;gap:16px;
  flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,.07);z-index:200;
  position:fixed;top:0;left:0;right:0;
}
.header-logo-img{height:38px;width:auto;object-fit:contain;display:block;}
.header-logo-sub{font-size:11px;color:#888;font-weight:400;margin-top:-2px;}
.header-title{font-family:'Rajdhani',sans-serif;font-size:17px;font-weight:600;color:#444;margin-left:8px;border-left:2px solid #ddd;padding-left:16px;}
.header-spacer{flex:1;}
.header-badge{
  display:flex;align-items:center;gap:6px;padding:5px 14px;
  background:var(--primary-light);border:1px solid var(--primary-border);
  border-radius:20px;font-size:11px;color:var(--primary-dark);font-weight:600;
}
.header-badge i{font-size:12px;}

/* ── LAYOUT ── */
.app-layout{display:flex;flex:1;overflow:hidden;margin-top:var(--header-h);}
.sidebar-placeholder{flex-shrink:0;width:190px;}
.tc-main{flex:1;overflow:hidden;display:flex;flex-direction:column;}

/* ── PAGE TOP BAR ── */
.page-topbar{
  background:var(--white);border-bottom:1px solid var(--border);
  padding:12px 24px;display:flex;align-items:center;gap:12px;flex-shrink:0;
}
.page-topbar h1{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:var(--text);}
.breadcrumb{font-size:11px;color:#aaa;display:flex;align-items:center;gap:5px;margin-bottom:2px;}
.breadcrumb .bc-active{color:var(--primary);font-weight:600;}
.ml-auto{margin-left:auto;}

/* ── BUTTONS ── */
.btn{
  padding:8px 18px;border:none;border-radius:6px;
  font-family:'Inter',sans-serif;font-size:13px;font-weight:600;
  cursor:pointer;transition:all .15s;display:inline-flex;align-items:center;gap:6px;
}
.btn-primary{background:var(--primary);color:#fff;}
.btn-primary:hover{background:var(--primary-dark);}
.btn-outline{background:transparent;border:1.5px solid var(--primary);color:var(--primary-dark);}
.btn-outline:hover{background:var(--primary-light);}
.btn-danger{background:#e53935;color:#fff;}
.btn-danger:hover{background:#c62828;}
.btn-word{background:#2b579a;color:#fff;}
.btn-word:hover{background:#1e4080;}
.btn-sm{padding:5px 13px;font-size:12px;}

/* ── CONTENT AREA ── */
.content-area{flex:1;padding:20px 24px;overflow-y:auto;}

/* ── PAGE SECTION HEADER ── */
.page-section-header{
  display:flex;align-items:center;gap:12px;
  padding:14px 0 12px;margin-bottom:18px;
  border-bottom:2px solid #f0f2f7;
}
.page-section-header .psh-icon{
  width:38px;height:38px;border-radius:10px;
  background:linear-gradient(135deg,var(--primary),var(--primary-dark));
  display:flex;align-items:center;justify-content:center;
  color:#fff;font-size:16px;flex-shrink:0;
  box-shadow:0 3px 10px rgba(61,186,111,.25);
}
.page-section-header h2{font-family:'Rajdhani',sans-serif;font-size:20px;font-weight:700;color:var(--text);}
.page-section-header .psh-sub{font-size:11px;color:#aaa;margin-top:1px;}

/* ── PROJECT SELECTOR BAR ── */
.project-bar{
  display:flex;align-items:center;gap:10px;
  padding:10px 24px;background:var(--white);
  border-bottom:1px solid #eee;flex-shrink:0;
}
.project-bar label{font-size:12px;color:#888;font-weight:600;white-space:nowrap;}
.project-bar select{
  padding:6px 10px;border:1.5px solid var(--border);border-radius:6px;
  font-size:13px;outline:none;font-family:'Inter',sans-serif;min-width:240px;
}
.project-bar select:focus{border-color:var(--primary);}

/* ── AUTOSAVE INDICATOR ── */
.autosave-indicator{
  display:flex;align-items:center;gap:5px;
  font-size:11px;color:#aaa;font-style:italic;
  transition:color .3s;
}
.autosave-indicator.saving{color:var(--primary);}
.autosave-indicator.saved{color:var(--primary-dark);}
.autosave-indicator i{font-size:10px;}

/* ── SECTION CARD ── */
.section-card{
  background:var(--white);border:1px solid var(--border);
  border-radius:8px;margin-bottom:18px;overflow:hidden;
  box-shadow:0 1px 5px rgba(0,0,0,.05);
}
.section-card-header{
  padding:11px 16px;font-family:'Rajdhani',sans-serif;
  font-size:14px;font-weight:700;letter-spacing:.5px;
  display:flex;align-items:center;gap:8px;color:#fff;
}
/* All card headers use green theme */
.sh-green  {background:linear-gradient(135deg,var(--primary),var(--primary-dark));}
.sh-navy   {background:linear-gradient(135deg,#1a1a2e,#0f3460);}
.sh-teal   {background:linear-gradient(135deg,#0d7377,#14a085);}
.sh-indigo {background:linear-gradient(135deg,#5c6bc0,#3949ab);}
.sh-dark   {background:linear-gradient(135deg,#374151,#111827);}
/* Replace orange with green */
.sh-orange {background:linear-gradient(135deg,var(--primary),var(--primary-dark));}

/* ── FORM GRID ── */
.form-grid{display:grid;gap:0;}
.cols-2{grid-template-columns:1fr 1fr;}
.cols-3{grid-template-columns:1fr 1fr 1fr;}
.field-cell{padding:0;border-right:1px solid #e8e8e8;border-bottom:1px solid #e8e8e8;}
.field-cell:last-child{border-right:none;}
.field-label{
  background:#f8fafc;padding:6px 12px;
  font-size:11px;font-weight:700;color:#64748b;
  border-bottom:1px solid #e8e8e8;text-transform:uppercase;letter-spacing:.4px;
}
.field-input{padding:8px 12px;}
.field-input input,.field-input textarea,.field-input select{
  width:100%;border:none;outline:none;
  font-family:'Inter',sans-serif;font-size:13px;color:var(--text);
  background:transparent;resize:none;
}
.field-input input:focus,.field-input textarea:focus{background:#f0fbf4;border-radius:4px;}
.field-input input::placeholder,.field-input textarea::placeholder{color:#d1d5db;}

/* ── DATA TABLE ── */
.tbl-wrap{overflow-x:auto;}
.data-table{width:100%;border-collapse:collapse;}
.data-table th{
  background:#f8fafc;padding:9px 12px;font-size:11px;font-weight:700;
  color:#64748b;text-align:left;border-bottom:2px solid var(--primary-light);
  text-transform:uppercase;letter-spacing:.4px;
}
.data-table td{padding:4px 8px;border-bottom:1px solid #f1f5f9;}
.data-table td input,.data-table td select{
  width:100%;border:none;outline:none;padding:5px 6px;font-size:13px;
  font-family:'Inter',sans-serif;color:var(--text);background:transparent;border-radius:4px;
}
.data-table td input:focus{background:var(--primary-light);}
.data-table tr:hover td{background:#f9fffe;}

/* ── SAVE BAR ── */
.save-bar{
  background:var(--white);border-top:1px solid var(--border);
  padding:12px 24px;display:flex;gap:10px;align-items:center;flex-shrink:0;
}

/* ── RICH EDITOR ── */
.editor-card{
  background:var(--white);border:1px solid var(--border);
  border-radius:8px;margin-bottom:18px;overflow:hidden;
  box-shadow:0 1px 5px rgba(0,0,0,.05);
}
.editor-toolbar{
  padding:10px 14px;background:#f9fafb;
  border-bottom:1px solid #eee;display:flex;gap:8px;flex-wrap:wrap;align-items:center;
}
.add-btn{
  display:inline-flex;align-items:center;gap:5px;
  padding:5px 13px;border:1.5px dashed var(--primary);
  border-radius:5px;background:transparent;color:var(--primary-dark);
  font-size:12px;font-weight:600;cursor:pointer;
  transition:all .15s;font-family:'Inter',sans-serif;
}
.add-btn:hover{background:var(--primary-light);}
.del-btn{
  background:transparent;border:none;color:#d1d5db;
  cursor:pointer;font-size:13px;padding:3px 7px;border-radius:4px;
}
.del-btn:hover{color:#e53935;background:#fff0f0;}

.section-entry{border-bottom:1px solid #f1f1f1;padding:14px 16px;}
.section-entry:last-child{border-bottom:none;}
.section-num{
  font-family:'Rajdhani',sans-serif;font-size:13px;font-weight:700;
  color:var(--primary-dark);margin-bottom:4px;
  display:flex;justify-content:space-between;align-items:center;
}
.section-title-input{
  width:100%;border:none;border-bottom:1.5px dashed #e2e8f0;
  outline:none;font-family:'Rajdhani',sans-serif;font-size:17px;font-weight:700;
  color:var(--text);padding:4px 0;margin-bottom:8px;background:transparent;
}
.section-title-input:focus{border-bottom-color:var(--primary);}
.section-desc-input{
  width:100%;border:1px solid #e8e8e8;border-radius:5px;
  outline:none;font-family:'Inter',sans-serif;font-size:13px;
  color:#444;padding:8px 10px;resize:vertical;min-height:65px;background:#fafafa;
}
.section-desc-input:focus{border-color:var(--primary);background:#fff;}
.sub-entry{border-left:2px solid var(--primary-border);padding:8px 0 8px 12px;margin:8px 0 0 20px;}
.subsub-entry{border-left:2px solid #b6e8cc;padding:6px 0 6px 12px;margin:6px 0 0 20px;}
.sub-num{font-size:12px;color:var(--primary-dark);font-weight:600;margin-bottom:3px;display:flex;justify-content:space-between;align-items:center;}
.sub-title-input{width:100%;border:none;border-bottom:1px dashed #e2e8f0;outline:none;font-family:'Inter',sans-serif;font-size:14px;font-weight:600;color:#333;padding:3px 0;margin-bottom:5px;background:transparent;}
.sub-title-input:focus{border-bottom-color:var(--primary);}
.sub-desc-input{width:100%;border:1px solid #eee;border-radius:4px;outline:none;font-family:'Inter',sans-serif;font-size:13px;color:#555;padding:6px 9px;resize:vertical;min-height:50px;background:#fafafa;}
.sub-desc-input:focus{border-color:var(--primary);background:#fff;}
.subsub-title-input{width:100%;border:none;border-bottom:1px dashed #eee;outline:none;font-family:'Inter',sans-serif;font-size:13px;font-weight:600;color:#444;padding:2px 0;margin-bottom:4px;background:transparent;}
.subsub-desc-input{width:100%;border:1px solid #f0f0f0;border-radius:4px;outline:none;font-family:'Inter',sans-serif;font-size:12.5px;color:#666;padding:5px 8px;resize:vertical;min-height:45px;background:#fafafa;}

/* ── WELCOME ── */
.welcome-screen{display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;color:#aaa;gap:12px;}
.welcome-screen .big-icon{font-size:52px;opacity:.25;color:var(--primary);}
.welcome-screen h2{font-family:'Rajdhani',sans-serif;font-size:24px;color:#bbb;font-weight:600;}
.welcome-screen p{font-size:13px;color:#ccc;}

/* ── DOC LIST TABLE ── */
.doc-list-table{width:100%;border-collapse:collapse;background:var(--white);border-radius:8px;overflow:hidden;box-shadow:0 1px 5px rgba(0,0,0,.05);}
.doc-list-table th{background:var(--navy);color:#e0e0e0;padding:11px 14px;font-size:11.5px;font-weight:600;text-align:left;text-transform:uppercase;letter-spacing:.5px;}
.doc-list-table td{padding:10px 14px;border-bottom:1px solid #eee;font-size:13px;}
.doc-list-table tr:hover td{background:var(--primary-light);}
.badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;background:var(--primary-light);color:var(--primary-dark);border:1px solid var(--primary-border);}

/* ── TOAST ── */
.toast{
  position:fixed;bottom:24px;right:24px;
  background:#333;color:#fff;padding:12px 22px;
  border-radius:8px;font-size:13px;font-weight:500;z-index:9999;
  opacity:0;transform:translateY(12px);
  transition:all .3s;pointer-events:none;font-family:'Inter',sans-serif;
}
.toast.show{opacity:1;transform:translateY(0);}
.toast.success{background:var(--primary-dark);}
.toast.error{background:#e53935;}

/* ── UTILITIES ── */
.hidden{display:none!important;}
.ml-auto{margin-left:auto;}
.flex{display:flex;}.items-center{align-items:center;}
.gap-8{gap:8px;}.gap-10{gap:10px;}
.doc-form{width:100%;}.rich-editor-page{width:100%;}
::-webkit-scrollbar{display:none;}
html{scrollbar-width:none;}

/* ── IMAGE PICKER MODAL ── */
.img-overlay,.tbl-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9100;
  display:flex;align-items:center;justify-content:center;
  opacity:0;pointer-events:none;transition:opacity .2s;
}
.img-overlay.open,.tbl-overlay.open{opacity:1;pointer-events:all;}
.img-modal,.tbl-modal{
  background:#fff;border-radius:14px;padding:28px 30px;
  width:90%;max-width:460px;
  box-shadow:0 20px 60px rgba(0,0,0,.25);
  transform:translateY(16px) scale(.97);transition:transform .2s;
}
.img-overlay.open .img-modal,.tbl-overlay.open .tbl-modal{transform:translateY(0) scale(1);}
.modal-title{font-family:'Rajdhani',sans-serif;font-size:18px;font-weight:700;color:#222;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
.modal-tabs{display:flex;gap:0;border:1.5px solid var(--border);border-radius:7px;overflow:hidden;margin-bottom:18px;}
.modal-tab{flex:1;padding:8px;text-align:center;font-size:13px;font-weight:600;cursor:pointer;border:none;background:#f8fafc;color:#888;transition:all .15s;font-family:'Inter',sans-serif;}
.modal-tab.active{background:var(--primary);color:#fff;}
.modal-field{margin-bottom:14px;}
.modal-field label{display:block;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px;}
.modal-field input[type=text],.modal-field input[type=url],.modal-field input[type=number]{
  width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:6px;
  font-size:13px;font-family:'Inter',sans-serif;outline:none;
}
.modal-field input:focus{border-color:var(--primary);}
.modal-field input[type=file]{font-size:13px;font-family:'Inter',sans-serif;}
.modal-field .hint{font-size:11px;color:#aaa;margin-top:4px;}
.modal-preview{width:100%;max-height:140px;object-fit:contain;border-radius:6px;border:1px solid var(--border);margin-bottom:14px;display:none;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:6px;}
.tbl-grid-picker{display:grid;gap:3px;margin:10px 0;cursor:pointer;}
.tbl-grid-cell{width:22px;height:22px;border:1.5px solid var(--border);border-radius:3px;background:#f8fafc;transition:all .12s;}
.tbl-grid-cell.hover{background:var(--primary-light);border-color:var(--primary);}
.tbl-grid-wrap{display:flex;align-items:flex-start;gap:18px;}
.tbl-grid-label{font-size:12px;color:var(--primary-dark);font-weight:600;margin-top:6px;white-space:nowrap;}

/* ── INSERTED CONTENT BLOCKS ── */
.inserted-block{
  border:1px solid #d4edda;border-radius:8px;
  background:#f6fcf8;padding:12px 14px;margin:12px 0;position:relative;
  box-shadow:0 1px 4px rgba(61,186,111,.08);
}
.inserted-block .block-remove{
  position:absolute;top:7px;right:8px;background:none;border:none;
  color:#c8d6ce;cursor:pointer;font-size:14px;transition:color .15s;
}
.inserted-block .block-remove:hover{color:#e53935;background:#fff0f0;border-radius:50%;}
.inserted-img{max-width:100%;display:block;border-radius:6px 6px 0 0;border:1px solid #d4edda;border-bottom:none;box-shadow:0 2px 10px rgba(0,0,0,.08);}
.inserted-img-wrap{text-align:center;border:1px solid #d4edda;border-radius:6px;overflow:hidden;display:inline-block;width:100%;}
.inserted-block-image{text-align:center;}
.inserted-img-caption{font-size:11.5px;color:#6b7280;text-align:center;margin:0;padding:6px 10px;background:#f0faf4;border-top:1px solid #d4edda;font-style:italic;}
.inserted-table-wrap{overflow-x:auto;}
.inserted-table{width:100%;border-collapse:collapse;font-size:13px;font-family:'Inter',sans-serif;border-radius:6px;overflow:hidden;}
.inserted-table th,.inserted-table td{
  border:1.5px solid #c8e6c9;padding:7px 10px;
  text-align:left;vertical-align:middle;
}
.inserted-table th{background:linear-gradient(135deg,var(--primary),var(--primary-dark));font-weight:700;color:#fff;font-size:12px;letter-spacing:.3px;}
.inserted-table tr:nth-child(even) td{background:#f8fbf9;}
.inserted-table td input{
  width:100%;border:none;outline:none;background:transparent;
  font-size:13px;font-family:'Inter',sans-serif;color:#333;padding:2px 0;
}
.inserted-table td input:focus{background:#e8f7ef;border-radius:3px;padding:2px 4px;}
.block-label{font-size:10px;font-weight:700;color:var(--primary-dark);text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;display:flex;align-items:center;gap:5px;}
.dl-overlay{
  position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;
  display:flex;align-items:center;justify-content:center;
  opacity:0;pointer-events:none;transition:opacity .2s;
}
.dl-overlay.open{opacity:1;pointer-events:all;}
.dl-modal{
  background:#fff;border-radius:14px;padding:32px 36px;
  min-width:340px;max-width:420px;width:90%;
  box-shadow:0 20px 60px rgba(0,0,0,.25);
  transform:translateY(18px) scale(.97);transition:transform .2s;
  text-align:center;
}
.dl-overlay.open .dl-modal{transform:translateY(0) scale(1);}
.dl-modal-title{font-family:'Rajdhani',sans-serif;font-size:19px;font-weight:700;color:#222;margin-bottom:4px;}
.dl-modal-sub{font-size:12px;color:#aaa;margin-bottom:26px;}
.dl-modal-btns{display:flex;gap:14px;justify-content:center;margin-bottom:18px;}
.dl-btn{
  flex:1;padding:18px 10px;border:none;border-radius:10px;
  cursor:pointer;font-family:'Inter',sans-serif;font-weight:700;
  font-size:14px;display:flex;flex-direction:column;align-items:center;
  gap:8px;transition:all .15s;
}
.dl-btn i{font-size:28px;}
.dl-btn-word{background:#e8f0fb;color:#2b579a;}
.dl-btn-word:hover{background:#2b579a;color:#fff;}
.dl-btn-pdf{background:#fdecea;color:#c0392b;}
.dl-btn-pdf:hover{background:#c0392b;color:#fff;}
.dl-cancel{font-size:12px;color:#aaa;cursor:pointer;background:none;border:none;font-family:'Inter',sans-serif;}
.dl-cancel:hover{color:#555;}
</style>
</head>
<body>

<!-- TOP HEADER -->
<?php
$headerModule = 'Techno Commercial';
$headerIcon   = 'fa-industry';
$headerPath   = dirname(__DIR__) . '/header.php';
if (file_exists($headerPath)) {
    require_once $headerPath;
} else {
?>
<div class="top-header">
    <div style="display:flex;align-items:center;gap:10px;">
        <img src="/invoice/assets/favicon.png" alt="Logo" class="header-logo-img">
    </div>
    <div class="header-title">Techno Commercial Module</div>
    <div class="header-spacer"></div>
    <div class="header-badge"><i class="fas fa-industry"></i> Techno Commercial</div>
</div>
<?php } ?>

<!-- LAYOUT -->
<div class="app-layout">

    <?php require_once dirname(__DIR__) . '/sidebar.php'; ?>
    <div class="sidebar-placeholder"></div>

    <div class="tc-main">

        <?php if ($tcSub === 'documents'): ?>
        <!-- ═══════════════ DOCUMENTS LIST ═══════════════ -->
        <div class="page-topbar">
            <h1>All Documents</h1>
            <div class="ml-auto">
                <a class="btn btn-primary btn-sm" href="tc_index.php?tc_sub=create&tc_page=mainproject">
                    <i class="fas fa-plus"></i> New Project
                </a>
            </div>
        </div>
        <div class="content-area">
            <table class="doc-list-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Project Name</th>
                        <th>Document Key</th>
                        <th>Customer</th>
                        <th>Version</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="doc-list-tbody">
                    <tr><td colspan="7" style="text-align:center;color:#aaa;padding:28px;">Loading…</td></tr>
                </tbody>
            </table>
        </div>

        <?php elseif ($tcSub === 'create' && $tcPage === 'mainproject'): ?>
        <!-- ═══════════════ MAIN PROJECT FORM ═══════════════ -->
        <div class="page-topbar">
            <h1>Main Project Details</h1>
            <div style="display:flex;align-items:center;gap:8px;margin-left:24px;">
                <label style="font-size:12px;color:#888;font-weight:600;white-space:nowrap;"><i class="fas fa-folder"></i> Load Project:</label>
                <select id="mp-project-select" style="padding:6px 10px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;outline:none;font-family:'Inter',sans-serif;min-width:220px;">
                    <option value="">Loading projects…</option>
                </select>
            </div>
            <div class="ml-auto flex gap-10">
                <button class="btn btn-primary btn-sm" onclick="saveMainProject()">
                    <i class="fas fa-save"></i> Save
                </button>
            </div>
        </div>

        <div class="content-area">
        <div class="doc-form">

            <!-- HEADER SECTION -->
            <div class="section-card">
                <div class="section-card-header sh-green">
                    <i class="fas fa-clipboard-list"></i> Header Section
                </div>
                <div class="form-grid cols-2">
                    <div class="field-cell">
                        <div class="field-label">Project Name</div>
                        <div class="field-input"><input id="mp-project-name" type="text" placeholder="Enter project name"></div>
                    </div>
                    <div class="field-cell">
                        <div class="field-label">Document Key <span style="font-size:10px;color:var(--primary);font-weight:400;text-transform:none;margin-left:4px;">(auto-generated)</span></div>
                        <div class="field-input" style="display:flex;align-items:center;gap:6px;">
                            <input id="mp-document-key" type="text" placeholder="Auto-generating…"  style="background:#f0fbf4;color:var(--primary-dark);font-weight:600;cursor:default;flex:1">
                            <button type="button" onclick="refreshDocKey()" title="Regenerate key" style="background:none;border:none;color:var(--primary);cursor:pointer;font-size:14px;padding:4px 6px;" id="doc-key-refresh-btn"><i class="fas fa-sync-alt"></i></button>
                        </div>
                    </div>
                    <div class="field-cell">
                        <div class="field-label">Version</div>
                        <div class="field-input"><input id="mp-version" type="text" placeholder="e.g. Initial Release"></div>
                    </div>
                    <div class="field-cell">
                        <div class="field-label">Revision</div>
                        <div class="field-input"><input id="mp-revision" type="text" placeholder="e.g. 1.0"></div>
                    </div>
                    <div class="field-cell" style="grid-column:span 2">
                        <div class="field-label">Customer</div>
                        <div class="field-input"><input id="mp-customer" type="text" placeholder="Enter customer name"></div>
                    </div>
                </div>
            </div>

            <!-- APPROVAL TABLE -->
            <div class="section-card">
                <div class="section-card-header sh-navy" style="justify-content:space-between;">
                    <span><i class="fas fa-user-check"></i> Approval Details</span>
                    <button class="btn btn-outline btn-sm" onclick="addApprovalRow()"
                            style="color:#fff;border-color:rgba(255,255,255,.5);font-size:12px;">
                        <i class="fas fa-plus"></i> Add Row
                    </button>
                </div>
                <div class="tbl-wrap">
                    <table class="data-table">
                        <thead><tr>
                            <th>Role</th><th>Name</th><th>Department</th><th>Email</th><th>Date</th>
                            <th style="width:42px"></th>
                        </tr></thead>
                        <tbody id="approval-tbody">
                            <tr>
                                <td><input type="text" placeholder="Prepared By"></td>
                                <td><input type="text" placeholder="Full name"></td>
                                <td><input type="text" placeholder="Dept."></td>
                                <td><input type="email" placeholder="email@company.com"></td>
                                <td><input type="date"></td>
                                <td><button class="del-btn" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i></button></td>
                            </tr>
                            <tr>
                                <td><input type="text" placeholder="Reviewed By"></td>
                                <td><input type="text"></td><td><input type="text"></td>
                                <td><input type="email"></td><td><input type="date"></td>
                                <td><button class="del-btn" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i></button></td>
                            </tr>
                            <tr>
                                <td><input type="text" placeholder="Approved By"></td>
                                <td><input type="text"></td><td><input type="text"></td>
                                <td><input type="email"></td><td><input type="date"></td>
                                <td><button class="del-btn" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- REVISION TABLE -->
            <div class="section-card">
                <div class="section-card-header sh-teal" style="justify-content:space-between;">
                    <span><i class="fas fa-history"></i> Revision Details</span>
                    <button class="btn btn-outline btn-sm" onclick="addRevisionRow()"
                            style="color:#fff;border-color:rgba(255,255,255,.5);font-size:12px;">
                        <i class="fas fa-plus"></i> Add Row
                    </button>
                </div>
                <div class="tbl-wrap">
                    <table class="data-table">
                        <thead><tr>
                            <th>Version</th><th>Previous Ver.</th><th>Date</th><th>Change Content</th>
                            <th style="width:42px"></th>
                        </tr></thead>
                        <tbody id="revision-tbody">
                            <tr>
                                <td><input type="text" placeholder="1.0"></td>
                                <td><input type="text" placeholder="-"></td>
                                <td><input type="date"></td>
                                <td><input type="text" placeholder="Initial Release"></td>
                                <td><button class="del-btn" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>


            <!-- FOOTER SECTION -->
            <div class="section-card">
                <div class="section-card-header sh-dark">
                    <i class="fas fa-thumbtack"></i> Footer Section
                </div>
                <div class="form-grid cols-3">
                    <div class="field-cell">
                        <div class="field-label">Designed By</div>
                        <div class="field-input"><input id="mp-designed-by" type="text" placeholder="Name"></div>
                    </div>
                    <div class="field-cell">
                        <div class="field-label">Assistant Manager</div>
                        <div class="field-input"><input id="mp-asst-manager" type="text" placeholder="Name"></div>
                    </div>
                    <div class="field-cell">
                        <div class="field-label">Template Ver.</div>
                        <div class="field-input"><input id="mp-template-ver" type="text" placeholder="Rev:1.0"></div>
                    </div>
                    <div class="field-cell">
                        <div class="field-label">Released By</div>
                        <div class="field-input"><input id="mp-released-by" type="text" placeholder="Name"></div>
                    </div>
                    <div class="field-cell">
                        <div class="field-label">Automation Lead</div>
                        <div class="field-input"><input id="mp-automation-lead" type="text" placeholder="Name"></div>
                    </div>
                    <div class="field-cell">
                        <div class="field-label">Document Key</div>
                        <div class="field-input"><input id="mp-footer-doc-key" type="text" placeholder="ELT-QT-XXXX"></div>
                    </div>
                    <div class="field-cell" style="grid-column:span 2">
                        <div class="field-label">Company Name</div>
                        <div class="field-input"><input id="mp-company-name" type="text" placeholder="ELTRIVE AUTOMATIONS PVT LTD"></div>
                    </div>
                    <div class="field-cell">
                        <div class="field-label">Page No.</div>
                        <div class="field-input"><input id="mp-page-no" type="text" placeholder="1"></div>
                    </div>
                    <div class="field-cell" style="grid-column:span 2">
                        <div class="field-label">Title</div>
                        <div class="field-input"><input id="mp-footer-title" type="text" placeholder="Utility Monitoring System"></div>
                    </div>
                    <div class="field-cell">
                        <div class="field-label">Version</div>
                        <div class="field-input"><input id="mp-footer-version" type="text" placeholder="Initial Release"></div>
                    </div>
                    <div class="field-cell" style="grid-column:span 3">
                        <div class="field-label">Contact / Email</div>
                        <div class="field-input"><input id="mp-contact-email" type="email" placeholder="automations@eltrive.com"></div>
                    </div>
                </div>
            </div>

        </div><!-- /doc-form -->
        </div><!-- /content-area -->

        <div class="save-bar">
            <button class="btn btn-primary" onclick="saveMainProject()">
                <i class="fas fa-save"></i> Save Document
            </button>
           
        </div>

        <?php else: ?>
        <!-- ═══════════════ RICH EDITOR (all other sections) ═══════════════ -->
        <?php $richLabel = htmlspecialchars($pageTitles[$tcPage] ?? 'Section'); ?>
        <div class="page-topbar">
            <h1><?= $richLabel ?></h1>
            <div id="rich-project-badge" style="display:none;align-items:center;gap:6px;margin-left:16px;padding:5px 14px;background:var(--primary-light);border:1px solid var(--primary-border);border-radius:20px;font-size:12px;color:var(--primary-dark);font-weight:600;">
                <i class="fas fa-folder-open"></i>
                <span id="rich-project-name">No project selected</span>
            </div>
            <div class="ml-auto flex items-center gap-10">
                <button class="btn btn-primary btn-sm" onclick="saveRichSection()">
                    <i class="fas fa-save"></i> Save
                </button>
            </div>
        </div>

        <div class="content-area">
        <div class="rich-editor-page">
            <div class="editor-card">
                <div class="editor-toolbar">
                    <button class="add-btn" onclick="addHeading()">
                        <i class="fas fa-plus"></i> New Heading
                    </button>
                    <button class="add-btn" onclick="addSubHeading()">
                        <i class="fas fa-indent"></i> New Subheading
                    </button>
                    <button class="add-btn" onclick="addSubSubHeading()">
                        <i class="fas fa-indent" style="font-size:10px;"></i> Sub-sub Heading
                    </button>
                    <button class="add-btn" onclick="openImagePicker()"
                            style="border-color:#0d7377;color:#0d7377;">
                        <i class="fas fa-image"></i> Insert Image
                    </button>
                    <button class="add-btn" onclick="openTableDialog()"
                            style="border-color:#5c6bc0;color:#5c6bc0;">
                        <i class="fas fa-table"></i> Insert Table
                    </button>
                    <button class="add-btn" onclick="document.getElementById('rich-sections-container').innerHTML='';headingCount=0;lastHeadingEl=null;lastSubEl=null;const b=document.getElementById('offset-badge');if(b)b.textContent='Headings start at '+(globalHeadingOffset+1);"
                            style="border-color:#e53935;color:#e53935;">
                        <i class="fas fa-trash-alt"></i> Clear All
                    </button>
                    <span id="offset-badge" style="margin-left:auto;font-size:11px;color:#9ca3af;font-style:italic;padding:4px 10px;background:#f8fafc;border-radius:12px;border:1px solid #e8e8e8;">
                        Loading numbering…
                    </span>
                </div>
                <div id="rich-sections-container" style="padding:8px 0;min-height:120px;"></div>
            </div>
        </div>
        </div><!-- /content-area -->

        <div class="save-bar">
            <button class="btn btn-primary" onclick="saveRichSection()">
                <i class="fas fa-save"></i> Save Section
            </button>
        </div>
        <?php endif; ?>

    </div><!-- /tc-main -->
</div><!-- /app-layout -->


<!-- Download Modal -->
<div class="dl-overlay" id="dl-overlay" onclick="if(event.target===this)closeDlModal()">
  <div class="dl-modal">
    <div class="dl-modal-title" id="dl-modal-project-name">Project Name</div>
    <div class="dl-modal-sub">Choose export format</div>
    <div class="dl-modal-btns">
      <button class="dl-btn dl-btn-word" onclick="triggerDownload('word')">
        <i class="fas fa-file-word"></i>
        Word
      </button>
      <button class="dl-btn dl-btn-pdf" onclick="triggerDownload('pdf')">
        <i class="fas fa-file-pdf"></i>
        PDF
      </button>
    </div>
    <button class="dl-cancel" onclick="closeDlModal()">Cancel</button>
  </div>
</div>

<!-- ═══ IMAGE PICKER MODAL ═══ -->
<div class="img-overlay" id="img-overlay" onclick="if(event.target===this)closeImagePicker()">
  <div class="img-modal">
    <div class="modal-title"><i class="fas fa-image" style="color:var(--primary);"></i> Insert Image</div>
    <div class="modal-tabs">
      <button class="modal-tab active" id="img-tab-upload" onclick="switchImgTab('upload')">
        <i class="fas fa-upload"></i> Upload from PC
      </button>
      <button class="modal-tab" id="img-tab-url" onclick="switchImgTab('url')">
        <i class="fas fa-link"></i> Paste URL / Google
      </button>
    </div>

    <!-- Upload tab -->
    <div id="img-panel-upload">
      <div class="modal-field">
        <label>Choose Image File</label>
        <input type="file" id="img-file-input" accept="image/*" onchange="previewUpload(this)">
        <div class="hint">Supports JPG, PNG, GIF, WEBP, SVG</div>
      </div>
    </div>

    <!-- URL tab -->
    <div id="img-panel-url" style="display:none;">
      <div class="modal-field">
        <label>Image URL</label>
        <input type="url" id="img-url-input" placeholder="https://…  or paste a Google image link" oninput="previewUrl(this.value)">
        <div class="hint">Right-click any image → "Copy image address" → paste here</div>
      </div>
    </div>

    <img id="img-preview" class="modal-preview" alt="Preview">

    <div class="modal-field">
      <label>Caption (optional)</label>
      <input type="text" id="img-caption-input" placeholder="Figure 1: …">
    </div>
    <div class="modal-field">
      <label>Width %</label>
      <input type="number" id="img-width-input" value="100" min="10" max="100" style="width:90px;">
    </div>
    <div class="modal-actions">
      <button class="btn btn-outline btn-sm" onclick="closeImagePicker()">Cancel</button>
      <button class="btn btn-primary btn-sm" onclick="insertImageBlock()"><i class="fas fa-check"></i> Insert</button>
    </div>
  </div>
</div>

<!-- ═══ TABLE DIALOG MODAL ═══ -->
<div class="tbl-overlay" id="tbl-overlay" onclick="if(event.target===this)closeTableDialog()">
  <div class="tbl-modal">
    <div class="modal-title"><i class="fas fa-table" style="color:#5c6bc0;"></i> Insert Table</div>

    <!-- Visual grid picker -->
    <div class="modal-field">
      <label>Hover to pick size (max 8×8) — or type below</label>
      <div class="tbl-grid-wrap">
        <div id="tbl-grid" style="display:inline-grid;gap:3px;grid-template-columns:repeat(8,24px);"></div>
        <div>
          <div class="tbl-grid-label" id="tbl-grid-label">0 × 0</div>
        </div>
      </div>
    </div>

    <div style="display:flex;gap:14px;">
      <div class="modal-field" style="flex:1;">
        <label>Rows</label>
        <input type="number" id="tbl-rows-input" value="3" min="1" max="50" oninput="syncGridFromInputs()">
      </div>
      <div class="modal-field" style="flex:1;">
        <label>Columns</label>
        <input type="number" id="tbl-cols-input" value="3" min="1" max="20" oninput="syncGridFromInputs()">
      </div>
    </div>

    <div class="modal-field">
      <label>Border Style</label>
      <select id="tbl-border-style" style="width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;">
        <option value="solid">Solid (default)</option>
        <option value="double">Double</option>
        <option value="dashed">Dashed</option>
        <option value="dotted">Dotted</option>
        <option value="none">No border</option>
      </select>
    </div>
    <div class="modal-field">
      <label>Header Row</label>
      <select id="tbl-has-header" style="width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;">
        <option value="1">Yes – first row is header</option>
        <option value="0">No – all rows are data</option>
      </select>
    </div>
    <div style="display:flex;gap:14px;">
      <div class="modal-field" style="flex:2;">
        <label>Table Width %</label>
        <input type="number" id="tbl-width-input" value="70" min="20" max="100" style="width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:6px;font-size:13px;font-family:'Inter',sans-serif;outline:none;">
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn btn-outline btn-sm" onclick="closeTableDialog()">Cancel</button>
      <button class="btn btn-primary btn-sm" style="background:#5c6bc0;" onclick="insertTableBlock()"><i class="fas fa-check"></i> Insert Table</button>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="toast" class="toast"></div>

<script>
// ── Base URL (PHP-injected, always correct) ────────────────────────────
const BASE_URL = '<?= implode("/", array_slice(explode("/", str_replace("\\", "/", $_SERVER["PHP_SELF"])), 0, -1)) . "/" ?>';

// ── Persist project selection across pages ─────────────────────────────
function persistProject(id, name) {
  if (id) {
    sessionStorage.setItem('tc_project_id',   String(id));
    sessionStorage.setItem('tc_project_name', name || '');
  }
}
function getPersistedProjectId() {
  return parseInt(sessionStorage.getItem('tc_project_id') || '0') || null;
}
function getPersistedProjectName() {
  return sessionStorage.getItem('tc_project_name') || '';
}

// ── State ──────────────────────────────────────────────────────────────
const TC_PAGE = '<?= $tcPage ?>';
const TC_SUB  = '<?= $tcSub ?>';
let currentProjectId = null;
let headingCount     = 0;
let lastHeadingEl    = null;
let lastSubEl        = null;
const currentSectionKey = TC_PAGE;

// Auto-save debounce timer
let autoSaveTimer = null;
const AUTO_SAVE_DELAY = 1500; // 1.5 seconds after last keystroke

const RICH_LABELS = {
  overview:'Project Overview',benefits:'Expected Benefits & Tentative ROI',
  scope:'Project Scope of Work',current:'Current System Scenario',
  proposed:'Proposed Solution',utilities:'Utilities Covered Under Project',
  architecture:'System Architecture',kpis:'Standard Utility KPIs',
  dashboardfeat:'Dashboard Features',testing:'Testing & Commissioning',
  deliverables:'Deliverables',customer:'Customer Scope',
  outscope:'Out of Scope',commercials:'Commercials',
  comsummary:'Commercial Summary – Utility Monitoring System (UMS)'
};

// ── Toast ──────────────────────────────────────────────────────────────
function toast(msg,type='success'){
  const t=document.getElementById('toast');
  t.textContent=msg; t.className='toast show '+type;
  setTimeout(()=>t.className='toast',3200);
}

// ── Helpers ────────────────────────────────────────────────────────────
function setVal(id,val){const e=document.getElementById(id);if(e)e.value=val??'';}
function getVal(id){return document.getElementById(id)?.value??'';}
function esc(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

// ── Auto-save indicator ────────────────────────────────────────────────
function setAutoSaveStatus(state){
  const el=document.getElementById('autosave-status');
  if(!el) return;
  el.className='autosave-indicator '+state;
  if(state==='saving') el.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
  else if(state==='saved') el.innerHTML='<i class="fas fa-check-circle"></i> Saved';
  else el.innerHTML='<i class="fas fa-circle"></i> Auto-save on';
}

// ── Schedule auto-save (rich editor) ──────────────────────────────────
function scheduleAutoSave(){
  if(!currentProjectId) return;
  clearTimeout(autoSaveTimer);
  setAutoSaveStatus('');
  autoSaveTimer=setTimeout(()=>saveRichSection(true), AUTO_SAVE_DELAY);
}

// ── Project Options ────────────────────────────────────────────────────

// ── Auto-generate document key ────────────────────────────────────────
async function refreshDocKey(){
  const btn = document.getElementById('doc-key-refresh-btn');
  const inp = document.getElementById('mp-document-key');
  if(!inp) return;
  if(btn) btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
  try {
    const res  = await fetch(BASE_URL + 'main_project.php?action=next_key');
    const json = await res.json();
    if(json.success && json.data?.document_key){
      inp.value = json.data.document_key;
    }
  } catch(e){ console.warn('Key gen failed:', e); }
  if(btn) btn.innerHTML = '<i class="fas fa-sync-alt"></i>';
}

// ── New Project (clear form, reset state) ─────────────────────────────
function newProject(){
  currentProjectId = null;
  const sel = document.getElementById('mp-project-select');
  if(sel) sel.value = '';
  document.querySelectorAll('.doc-form input, .doc-form textarea').forEach(el => el.value = '');
  const aBody = document.getElementById('approval-tbody');
  if(aBody){
    aBody.innerHTML = '';
    addApprovalRow('Prepared By','','','','');
    addApprovalRow('Reviewed By','','','','');
    addApprovalRow('Approved By','','','','');
  }
  const rBody = document.getElementById('revision-tbody');
  if(rBody){ rBody.innerHTML = ''; addRevisionRow('','','',''); }
  refreshDocKey();
  toast('Ready for new project. Fill in details and Save.','success');
}

async function loadProjectOptions(selectId){
  const sel = document.getElementById(selectId);
  if(!sel) return;
  sel.innerHTML = '<option value="">Loading projects…</option>';
  try{
    const url = BASE_URL + 'main_project.php?action=list';
    console.log('[projects] fetching:', url);
    const res  = await fetch(url);
    const text = await res.text();
    console.log('[projects] response:', text.substring(0, 300));
    let json;
    try { json = JSON.parse(text); }
    catch(e){
      console.error('[projects] non-JSON:', text.substring(0,300));
      sel.innerHTML = '<option value="">Server error – check console</option>';
      return;
    }
    if(!json.success){
      sel.innerHTML = '<option value="">'+(json.message||'Failed to load')+'</option>';
      return;
    }
    const projects = json.data?.projects || [];
    sel.innerHTML = '<option value="">— Select Project —</option>';
    if(!projects.length){
      const o = document.createElement('option');
      o.disabled = true; o.textContent = 'No projects saved yet';
      sel.appendChild(o);
    }
    projects.forEach(p => {
      const opt = document.createElement('option');
      opt.value = p.id;
      opt.textContent = (p.project_name || '').trim() || ('Project #' + p.id);
      if(String(p.id) === String(currentProjectId)) opt.selected = true;
      sel.appendChild(opt);
    });
    console.log('[projects] loaded', projects.length, 'project(s)');
  } catch(e){
    console.error('[projects] fetch error:', e);
    sel.innerHTML = '<option value="">Network error – check console</option>';
  }
}


// ── Row builders ───────────────────────────────────────────────────────
function addApprovalRow(role='',name='',dept='',email='',date=''){
  const tbody=document.getElementById('approval-tbody');
  if(!tbody) return;
  const tr=document.createElement('tr');
  tr.innerHTML=`
    <td><input type="text" value="${esc(role)}" placeholder="Role"></td>
    <td><input type="text" value="${esc(name)}" placeholder="Full name"></td>
    <td><input type="text" value="${esc(dept)}" placeholder="Department"></td>
    <td><input type="email" value="${esc(email)}" placeholder="email"></td>
    <td><input type="date" value="${esc(date)}"></td>
    <td><button class="del-btn" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i></button></td>`;
  tbody.appendChild(tr);
}
function addRevisionRow(ver='',prev='',date='',change=''){
  const tbody=document.getElementById('revision-tbody');
  if(!tbody) return;
  const tr=document.createElement('tr');
  tr.innerHTML=`
    <td><input type="text" value="${esc(ver)}" placeholder="1.0"></td>
    <td><input type="text" value="${esc(prev)}" placeholder="-"></td>
    <td><input type="date" value="${esc(date)}"></td>
    <td><input type="text" value="${esc(change)}" placeholder="Change description"></td>
    <td><button class="del-btn" onclick="this.closest('tr').remove()"><i class="fas fa-times"></i></button></td>`;
  tbody.appendChild(tr);
}


// ── Collect table rows ────────────────────────────────────────────────
function collectTableRows(tbodyId,fields){
  const rows=[];
  document.getElementById(tbodyId)?.querySelectorAll('tr').forEach(tr=>{
    const inputs=[...tr.querySelectorAll('input,select,textarea')];
    const row={};
    fields.forEach((f,i)=>row[f]=inputs[i]?.value??'');
    rows.push(row);
  });
  return rows;
}

// ── MAIN PROJECT SAVE ─────────────────────────────────────────────────
async function saveMainProject(){
  const payload={
    id:currentProjectId||0,
    header:{
      project_name:getVal('mp-project-name'),
      document_key:getVal('mp-document-key'),
      version:     getVal('mp-version'),
      revision:    getVal('mp-revision'),
      customer:    getVal('mp-customer'),
    },
    approvals:  collectTableRows('approval-tbody', ['role','name','dept','email','date']),
    revisions:  collectTableRows('revision-tbody', ['version','previous','date','change']),
    footer:{
      company_name: getVal('mp-company-name'),
      title:        getVal('mp-footer-title'),
      contact_email:getVal('mp-contact-email'),
      page_no:      getVal('mp-page-no'),
      version:      getVal('mp-footer-version'),
    },
    footerDetails:{
      designed_by:       getVal('mp-designed-by'),
      assistant_manager: getVal('mp-asst-manager'),
      template_ver:      getVal('mp-template-ver'),
      released_by:       getVal('mp-released-by'),
      automation_lead:   getVal('mp-automation-lead'),
      doc_key:           getVal('mp-footer-doc-key'),
    }
  };
  try{
    const res=await fetch(BASE_URL+'main_project.php?action=save',{
      method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)
    });
    const ct=res.headers.get('content-type')||'';
    if(!ct.includes('application/json')){
      const text=await res.text();
      console.error('Non-JSON response:',text.substring(0,300));
      toast('Save failed: unexpected server response','error');return;
    }
    const json=await res.json();
    if(json.success){
      currentProjectId=json.data.id;
      await loadProjectOptions('mp-project-select');
      document.getElementById('mp-project-select').value=currentProjectId;
      const pname = document.getElementById('mp-project-select')?.selectedOptions[0]?.textContent || getVal('mp-project-name');
      persistProject(currentProjectId, pname);
      toast('Project saved successfully!','success');
    } else toast(json.message||'Save failed','error');
  } catch(e){toast('Save failed: '+e.message,'error');}
}

async function loadMainProject(id){
  currentProjectId=id;
  try{
    const res=await fetch(BASE_URL+`main_project.php?action=load&id=${id}`);
    const ct=res.headers.get('content-type')||'';
    if(!ct.includes('application/json')){toast('Load failed: unexpected response','error');return;}
    const json=await res.json();
    if(!json.success){toast(json.message||'Load failed','error');return;}
    const {project:p,approvals,revisions,additional,footer:f}=json.data;
    setVal('mp-project-name',p.project_name); setVal('mp-document-key',p.document_key);
    setVal('mp-version',p.version);           setVal('mp-revision',p.revision);
    setVal('mp-customer',p.customer_name);
    const aBody=document.getElementById('approval-tbody');
    if(aBody){aBody.innerHTML='';(approvals||[]).forEach(r=>addApprovalRow(r.role,r.name,r.dept,r.email,r.date));}
    const rBody=document.getElementById('revision-tbody');
    if(rBody){rBody.innerHTML='';(revisions||[]).forEach(r=>addRevisionRow(r.version,r.previous,r.date,r.change));}
    setVal('mp-designed-by',    f?.designed_by);
    setVal('mp-asst-manager',   f?.assistant_manager);
    setVal('mp-template-ver',   f?.template_ver);
    setVal('mp-released-by',    f?.released_by);
    setVal('mp-automation-lead',f?.automation_lead);
    setVal('mp-footer-doc-key', f?.doc_key);
    setVal('mp-company-name',   p.company_name);
    setVal('mp-footer-title',   p.document_title);
    setVal('mp-contact-email',  p.contact_email);
    setVal('mp-page-no',        p.page_no);
    setVal('mp-footer-version', p.footer_version);
    persistProject(id, p.project_name);
    toast('Project loaded.','success');
  } catch(e){toast('Load failed: '+e.message,'error');}
}

function clearMainProject(){
  currentProjectId=null;
  sessionStorage.removeItem('tc_project_id');
  sessionStorage.removeItem('tc_project_name');
  const sel=document.getElementById('mp-project-select');
  if(sel) sel.value='';
  document.querySelectorAll('.doc-form input,.doc-form textarea').forEach(el=>el.value='');
}

// ── RICH EDITOR ───────────────────────────────────────────────────────
// Ordered section keys — same order as PDF generator
const SECTION_ORDER = [
  'overview','benefits','scope','current','proposed','utilities',
  'architecture','kpis','dashboardfeat','testing',
  'deliverables','customer','outscope','commercials','comsummary'
];

let globalHeadingOffset = 0;

async function calcGlobalOffset(projectId, currentKey) {
  const priorKeys = SECTION_ORDER.slice(0, SECTION_ORDER.indexOf(currentKey));
  let offset = 0;
  for (const key of priorKeys) {
    try {
      const res  = await fetch(BASE_URL + `sections.php?action=load&project_id=${projectId}&section_key=${key}`);
      const json = await res.json();
      if (json.success) offset += (json.data?.headings || []).length;
    } catch(e) { /* skip */ }
  }
  return offset;
}

async function openRichEditor(){
  const pid = currentProjectId;
  const container = document.getElementById('rich-sections-container');
  if(container) container.innerHTML = '<div style="padding:20px;text-align:center;color:#aaa;font-size:13px;"><i class="fas fa-spinner fa-spin"></i> Loading…</div>';
  headingCount = 0; lastHeadingEl = null; lastSubEl = null;

  globalHeadingOffset = pid ? await calcGlobalOffset(pid, TC_PAGE) : 0;

  const badge = document.getElementById('offset-badge');
  if(badge){
    const nextNum = globalHeadingOffset + 1;
    badge.textContent = globalHeadingOffset === 0
      ? 'Headings start at 1'
      : 'Headings start at ' + nextNum + ' (' + globalHeadingOffset + ' in prior sections)';
  }

  if(container) container.innerHTML = '';

  if(pid && TC_PAGE){
    try{
      const res = await fetch(BASE_URL+`sections.php?action=load&project_id=${pid}&section_key=${TC_PAGE}`);
      const json = await res.json();
      if(json.success){
        (json.data?.headings||[]).forEach(h=>addHeadingFromData(h.title,h.description,h.heading_num??h.num,h.subheadings||[]));
      }
    } catch(e){console.error('openRichEditor error:',e);}
  }
}

async function saveRichSection(isAuto=false){
  if(!currentProjectId){
    if(!isAuto) toast('Please select a project first.','error');
    return;
  }
  if(isAuto) setAutoSaveStatus('saving');
  const headings=collectHeadings();
  const payload={
    project_id:currentProjectId,
    section_key:currentSectionKey,
    section_title:RICH_LABELS[currentSectionKey]||currentSectionKey,
    headings
  };
  try{
    const res=await fetch(BASE_URL+'sections.php?action=save',{
      method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)
    });
    const ct=res.headers.get('content-type')||'';
    if(!ct.includes('application/json')){
      if(!isAuto) toast('Save failed: unexpected server response','error');
      if(isAuto) setAutoSaveStatus('');
      return;
    }
    const json=await res.json();
    if(json.success){
      if(isAuto) setAutoSaveStatus('saved');
      else toast('Section saved!','success');
      setTimeout(()=>setAutoSaveStatus(''),2500);
    } else {
      if(!isAuto) toast(json.message||'Save failed','error');
      if(isAuto) setAutoSaveStatus('');
    }
  } catch(e){
    if(!isAuto) toast('Save failed: '+e.message,'error');
    if(isAuto) setAutoSaveStatus('');
  }
}

// ── Restore a saved block (table/image) into a parent element ─────────
function restoreBlock(b, parentEl) {
  if (!b || !b.type) return;
  if (b.type === 'table') {
    const rows = b.rows || [];
    const headers = b.headers || [];
    const hasHeader = headers.length > 0;
    const widthPct = b.widthPct || '70%';
    const borderStyle = '1.5px solid #c8e6c9';
    let thead = '';
    if (hasHeader) {
      const hcells = headers.map((h,i) =>
        `<th style="border:${borderStyle};padding:7px 10px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;font-weight:700;font-size:12px;">
          <input type="text" value="${esc(h)}" style="width:100%;border:none;outline:none;background:transparent;font-weight:700;font-size:12px;font-family:'Inter',sans-serif;color:#fff;">
         </th>`).join('');
      thead = `<thead><tr>${hcells}</tr></thead>`;
    }
    const dataRows = rows.map((cells, ri) => {
      const tds = cells.map(v =>
        `<td style="border:${borderStyle};padding:5px 10px;background:${ri%2===0?'#fff':'#f8fbf9'};">
          <input type="text" value="${esc(v)}" style="width:100%;border:none;outline:none;background:transparent;font-size:13px;font-family:'Inter',sans-serif;color:#333;">
         </td>`).join('');
      return `<tr>${tds}</tr>`;
    }).join('');
    const block = document.createElement('div');
    block.className = 'inserted-block';
    block.dataset.blockType = 'table';
    block.innerHTML = `
      <button class="block-remove" title="Remove table" onclick="this.parentElement.remove();scheduleAutoSave()">
        <i class="fas fa-times-circle"></i>
      </button>
      <div class="block-label"><i class="fas fa-table"></i> Table</div>
      <div class="inserted-table-wrap" style="max-width:${widthPct};">
        <table class="inserted-table" style="border-collapse:collapse;width:100%;box-shadow:0 1px 6px rgba(61,186,111,.12);">
          ${thead}<tbody>${dataRows}</tbody>
        </table>
      </div>`;
    block.querySelectorAll('input').forEach(attachAutoSave);
    parentEl.appendChild(block);
  } else if (b.type === 'image') {
    const src = b.src || '';
    const caption = b.caption || '';
    const widthPct = b.widthPct || '70%';
    if (!src) return;
    const block = document.createElement('div');
    block.className = 'inserted-block inserted-block-image';
    block.dataset.blockType = 'image';
    block.dataset.src = src;
    block.innerHTML =
      '<button class="block-remove" title="Remove figure" onclick="this.parentElement.remove();scheduleAutoSave()">' +
      '<i class="fas fa-times-circle"></i></button>' +
      '<div class="block-label"><i class="fas fa-image"></i> Figure</div>' +
      '<div class="inserted-img-wrap" style="max-width:' + widthPct + ';margin:0 auto;">' +
      '<img class="inserted-img" src="' + esc(src) + '" style="width:100%;" alt="' + esc(caption) + '">' +
      (caption ? '<div class="inserted-img-caption"><i class="fas fa-camera" style="font-size:9px;margin-right:4px;"></i>' + esc(caption) + '</div>' : '') +
      '</div>';
    parentEl.appendChild(block);
  }
}

function collectHeadings(){
  const results=[];
  document.getElementById('rich-sections-container')?.querySelectorAll(':scope > .section-entry').forEach(entry=>{
    const subs=[];
    // collect inserted blocks (tables/images) directly inside this heading (not inside subs)
    const headingBlocks = [];
    entry.querySelectorAll(':scope > .inserted-block').forEach(block => {
      headingBlocks.push(collectBlock(block));
    });
    entry.querySelectorAll(':scope > .sub-entries > .sub-entry').forEach(sub=>{
      const subsubs=[];
      const subBlocks=[];
      sub.querySelectorAll(':scope > .inserted-block').forEach(block => {
        subBlocks.push(collectBlock(block));
      });
      sub.querySelectorAll(':scope > .subsub-container > .subsub-entry').forEach(ss=>{
        subsubs.push({num:+ss.dataset.ssnum,title:ss.querySelector('.subsub-title-input')?.value||'',description:ss.querySelector('.subsub-desc-input')?.value||''});
      });
      subs.push({num:+sub.dataset.snum,title:sub.querySelector('.sub-title-input')?.value||'',description:sub.querySelector('.sub-desc-input')?.value||'',subsubheadings:subsubs,blocks:subBlocks});
    });
    results.push({num:+entry.dataset.hnum,title:entry.querySelector('.section-title-input')?.value||'',description:entry.querySelector('.section-desc-input')?.value||'',subheadings:subs,blocks:headingBlocks});
  });
  return results;
}

function collectBlock(block) {
  const type = block.dataset.blockType;
  if (type === 'table') {
    const headers = [];
    block.querySelectorAll('thead th input').forEach(inp => headers.push(inp.value));
    const rows = [];
    block.querySelectorAll('tbody tr').forEach(tr => {
      const cells = [];
      tr.querySelectorAll('td input').forEach(inp => cells.push(inp.value));
      rows.push(cells);
    });
    const maxW = block.querySelector('.inserted-table-wrap')?.style.maxWidth || '70%';
    return { type: 'table', headers, rows, widthPct: maxW };
  } else if (type === 'image') {
    const img = block.querySelector('.inserted-img');
    const wrap = block.querySelector('.inserted-img-wrap');
    const caption = block.querySelector('.inserted-img-caption')?.textContent?.trim().replace(/^./, '').trim() || '';
    const widthPct = wrap?.style.maxWidth || '70%';
    return { type: 'image', src: img?.src || block.dataset.src || '', widthPct, caption };
  }
  return { type: 'unknown' };
}

// Attach auto-save to any input/textarea change inside rich container
function attachAutoSave(el){
  el.addEventListener('input', scheduleAutoSave);
  el.addEventListener('change', scheduleAutoSave);
}

function addHeading(){headingCount++;buildHeading(headingCount,'','',[]);}
function addHeadingFromData(title,desc,num,subs){headingCount=num;return buildHeading(num,title,desc,subs);}
function buildHeading(num,title,desc,subs){
  const container=document.getElementById('rich-sections-container');
  if(!container) return;
  const gnum = globalHeadingOffset + num;
  const entry=document.createElement('div');
  entry.className='section-entry'; entry.dataset.hnum=num; entry.dataset.gnum=gnum; entry.dataset.subCount=0;
  entry.innerHTML=`
    <div class="section-num"><span>${gnum}.</span>
      <button class="del-btn" onclick="this.closest('.section-entry').remove();scheduleAutoSave()"><i class="fas fa-times"></i> Remove</button></div>
    <input class="section-title-input" type="text" placeholder="Heading ${gnum} title…" value="${esc(title)}">
    <textarea class="section-desc-input" rows="3" placeholder="Description…">${esc(desc)}</textarea>
    <div class="sub-entries"></div>`;
  container.appendChild(entry);
  lastHeadingEl=entry; lastSubEl=null;
  entry.querySelectorAll('input,textarea').forEach(attachAutoSave);
  (subs||[]).forEach(sh=>addSubFromData(entry,sh.sub_num??sh.num,sh.title,sh.description,sh.subsubheadings||[],sh.blocks||[]));
  // restore heading-level blocks
  (subs?.length === 0 ? [] : []).concat([]); // placeholder – blocks restored below
  if(!title) entry.querySelector('.section-title-input').focus();
  return entry;
}

function addSubHeading(){
  if(!lastHeadingEl){toast('Add a Heading first.','error');return;}
  lastHeadingEl.dataset.subCount=+lastHeadingEl.dataset.subCount+1;
  buildSubHeading(lastHeadingEl,+lastHeadingEl.dataset.gnum,lastHeadingEl.dataset.subCount,'','',[]);
}
function addSubFromData(hEl,snum,title,desc,subsubs,blocks){
  hEl.dataset.subCount=snum; return buildSubHeading(hEl,+hEl.dataset.gnum,snum,title,desc,subsubs,blocks);
}
function buildSubHeading(hEl,gnum,snum,title,desc,subsubs,blocks){
  const sub=document.createElement('div'); sub.className='sub-entry';
  sub.dataset.gnum=gnum; sub.dataset.snum=snum; sub.dataset.ssCount=0;
  sub.innerHTML=`
    <div class="sub-num"><span>${gnum}.${snum}</span>
      <button class="del-btn" onclick="this.closest('.sub-entry').remove();scheduleAutoSave()"><i class="fas fa-times"></i></button></div>
    <input class="sub-title-input" type="text" placeholder="Subheading ${gnum}.${snum} title…" value="${esc(title)}">
    <textarea class="sub-desc-input" rows="2" placeholder="Description…">${esc(desc)}</textarea>
    <div class="subsub-container"></div>`;
  hEl.querySelector('.sub-entries').appendChild(sub);
  lastSubEl=sub;
  sub.querySelectorAll('input,textarea').forEach(attachAutoSave);
  (subsubs||[]).forEach(ss=>buildSubSubHeading(sub,gnum,snum,ss.subsub_num??ss.num,ss.title,ss.description));
  // restore saved table/image blocks
  (blocks||[]).forEach(b => restoreBlock(b, sub));
  if(!title) sub.querySelector('.sub-title-input').focus();
  return sub;
}

function addSubSubHeading(){
  if(!lastSubEl){toast('Add a Subheading first.','error');return;}
  lastSubEl.dataset.ssCount=+lastSubEl.dataset.ssCount+1;
  buildSubSubHeading(lastSubEl,+lastSubEl.dataset.gnum,lastSubEl.dataset.snum,lastSubEl.dataset.ssCount,'','');
}
function buildSubSubHeading(subEl,gnum,snum,ssnum,title,desc){
  subEl.dataset.ssCount=ssnum;
  const ss=document.createElement('div'); ss.className='subsub-entry'; ss.dataset.ssnum=ssnum;
  ss.innerHTML=`
    <div class="sub-num" style="color:#9ca3af;"><span>${gnum}.${snum}.${ssnum}</span>
      <button class="del-btn" onclick="this.closest('.subsub-entry').remove();scheduleAutoSave()"><i class="fas fa-times"></i></button></div>
    <input class="subsub-title-input" type="text" placeholder="${gnum}.${snum}.${ssnum} title…" value="${esc(title)}">
    <textarea class="subsub-desc-input" rows="2" placeholder="Description…">${esc(desc)}</textarea>`;
  subEl.querySelector('.subsub-container').appendChild(ss);
  ss.querySelectorAll('input,textarea').forEach(attachAutoSave);
  if(!title) ss.querySelector('.subsub-title-input').focus();
}

// ── DOCUMENT LIST ─────────────────────────────────────────────────────
async function loadDocumentList(){
  const res=await fetch(BASE_URL+'main_project.php?action=list');
  const json=await res.json();
  const tbody=document.getElementById('doc-list-tbody');
  if(!tbody) return;
  tbody.innerHTML='';
  const projects=json.data?.projects||[];
  if(!projects.length){
    tbody.innerHTML='<tr><td colspan="7" style="text-align:center;color:#aaa;padding:28px;">No projects found.</td></tr>';
    return;
  }
  projects.forEach(p=>{
    const tr=document.createElement('tr');
    tr.style.cursor='pointer';
    tr.addEventListener('click', e => {
      if(e.target.closest('button, a')) return;
      openDlModal(p.id, p.project_name);
    });
    tr.innerHTML=`
      <td>${esc(p.id)}</td>
      <td><strong>${esc(p.project_name)}</strong></td>
      <td><span class="badge">${esc(p.document_key)}</span></td>
      <td>${esc(p.customer||p.customer_name||'')}</td>
      <td>${esc(p.version)}</td>
      <td>${esc(p.created_at)}</td>
      <td style="display:flex;gap:6px;">
        <a class="btn btn-outline btn-sm" href="tc_index.php?tc_sub=create&tc_page=mainproject&load_id=${p.id}">
          <i class="fas fa-edit"></i> Edit
        </a>

        <button class="btn btn-danger btn-sm" onclick="deleteProject(${p.id})">
          <i class="fas fa-trash"></i>
        </button>
      </td>`;
    tbody.appendChild(tr);
  });
}

// ── DOWNLOAD MODAL ──────────────────────────────────────────────────
let dlProjectId = null;

function openDlModal(id, name){
  dlProjectId = id;
  document.getElementById('dl-modal-project-name').textContent = name || ('Project #' + id);
  document.getElementById('dl-overlay').classList.add('open');
}
function closeDlModal(){
  document.getElementById('dl-overlay').classList.remove('open');
  dlProjectId = null;
}
function triggerDownload(format){
  if(!dlProjectId) return;
  // Both PDF and Word download directly — no new tab, no print dialog
  window.location.href = BASE_URL + 'generate_pdf.php?id=' + dlProjectId + (format === 'pdf' ? '&format=pdf' : '');
  closeDlModal();
}

async function deleteProject(id){
  if(!confirm('Delete this project and all its data? This cannot be undone.')) return;
  const res=await fetch(BASE_URL+'main_project.php?action=delete',{
    method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})
  });
  const json=await res.json();
  if(json.success){toast('Project deleted.','success');loadDocumentList();}
  else toast(json.message,'error');
}

// ── INIT ──────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async function(){

  // ── Main Project page ──────────────────────────────────────────────
  const mpSel=document.getElementById('mp-project-select');
  if(mpSel){
    await loadProjectOptions('mp-project-select');
    mpSel.addEventListener('change',()=>{
      const id=parseInt(mpSel.value);
      if(id) loadMainProject(id); else { clearMainProject(); refreshDocKey(); }
    });
    // Check URL load_id first, then sessionStorage
    const loadId=parseInt(new URLSearchParams(location.search).get('load_id')||'0') || getPersistedProjectId();
    if(loadId){ mpSel.value=loadId; loadMainProject(loadId); }
    else { refreshDocKey(); } // new project — auto-fill doc key
  }

  // ── Sidebar (rich editor) pages ────────────────────────────────────
  const richBadge = document.getElementById('rich-project-badge');
  const richName  = document.getElementById('rich-project-name');
  if(richBadge){
    const urlLoadId   = new URLSearchParams(location.search).get('load_id');
    const persistedId = urlLoadId ? parseInt(urlLoadId) : getPersistedProjectId();
    const persistedName = getPersistedProjectName();

    if(persistedId){
      currentProjectId = persistedId;
      richBadge.style.display = 'flex';
      if(richName) richName.textContent = persistedName || ('Project #' + persistedId);
      await openRichEditor();
    } else {
      richBadge.style.display = 'flex';
      richBadge.style.background = '#fff3cd';
      richBadge.style.borderColor = '#ffc107';
      richBadge.style.color = '#856404';
      if(richName) richName.textContent = 'No project selected';
      const badge = document.getElementById('offset-badge');
      if(badge) badge.textContent = 'Select a project first';
    }
  }

  if(TC_SUB==='documents') loadDocumentList();
});

// ═══════════════════════════════════════════════════════════════════════
// IMAGE PICKER
// ═══════════════════════════════════════════════════════════════════════
let _imgTab = 'upload';
let _imgInsertTarget = null; // insert after this element, or null = append

function openImagePicker() {
  document.getElementById('img-overlay').classList.add('open');
  document.getElementById('img-file-input').value = '';
  document.getElementById('img-url-input').value = '';
  document.getElementById('img-caption-input').value = '';
  document.getElementById('img-width-input').value = '70';
  const prev = document.getElementById('img-preview');
  prev.style.display = 'none'; prev.src = '';
  switchImgTab('upload');
}
function closeImagePicker() {
  document.getElementById('img-overlay').classList.remove('open');
}
function switchImgTab(tab) {
  _imgTab = tab;
  document.getElementById('img-panel-upload').style.display = tab === 'upload' ? '' : 'none';
  document.getElementById('img-panel-url').style.display   = tab === 'url'    ? '' : 'none';
  document.getElementById('img-tab-upload').classList.toggle('active', tab === 'upload');
  document.getElementById('img-tab-url').classList.toggle('active',    tab === 'url');
}
function previewUpload(input) {
  const file = input.files[0];
  if (!file) return;
  const reader = new FileReader();
  reader.onload = e => {
    const prev = document.getElementById('img-preview');
    prev.src = e.target.result;
    prev.style.display = 'block';
  };
  reader.readAsDataURL(file);
}
function previewUrl(url) {
  const prev = document.getElementById('img-preview');
  if (!url) { prev.style.display = 'none'; return; }
  prev.src = url;
  prev.style.display = 'block';
  prev.onerror = () => { prev.style.display = 'none'; };
}
function insertImageBlock() {
  let src = '';
  if (_imgTab === 'upload') {
    const file = document.getElementById('img-file-input').files[0];
    if (!file) { toast('Please choose an image file.', 'error'); return; }
    // Convert to base64 data URL and insert
    const reader = new FileReader();
    reader.onload = e => _doInsertImage(e.target.result);
    reader.readAsDataURL(file);
    closeImagePicker();
    return;
  } else {
    src = document.getElementById('img-url-input').value.trim();
    if (!src) { toast('Please enter an image URL.', 'error'); return; }
  }
  _doInsertImage(src);
  closeImagePicker();
}
function _doInsertImage(src) {
  const caption  = document.getElementById('img-caption-input').value.trim();
  const widthPct = parseInt(document.getElementById('img-width-input').value) || 70;
  const container = document.getElementById('rich-sections-container');
  if (!container) return;

  const block = document.createElement('div');
  block.className = 'inserted-block inserted-block-image';
  block.dataset.blockType = 'image';
  block.dataset.src = src;
  block.innerHTML = `
    <button class="block-remove" title="Remove figure" onclick="this.parentElement.remove();scheduleAutoSave()">
      <i class="fas fa-times-circle"></i>
    </button>
    <div class="block-label"><i class="fas fa-image"></i> Figure</div>
    <div class="inserted-img-wrap" style="max-width:${widthPct}%;margin:0 auto;">
      <img class="inserted-img" src="${esc(src)}" style="width:100%;" alt="${esc(caption)}">
      ${caption ? '<div class="inserted-img-caption"><i class="fas fa-camera" style="font-size:9px;margin-right:4px;"></i>' + esc(caption) + '</div>' : ''}
    </div>`;

  if (lastSubEl) {
    lastSubEl.parentNode.insertBefore(block, lastSubEl.nextSibling);
  } else if (lastHeadingEl) {
    lastHeadingEl.appendChild(block);
  } else {
    container.appendChild(block);
  }

  scheduleAutoSave();
  toast('Figure inserted!', 'success');
}

// ═══════════════════════════════════════════════════════════════════════
// TABLE DIALOG
// ═══════════════════════════════════════════════════════════════════════
let _tblHoverR = 0, _tblHoverC = 0;

function openTableDialog() {
  document.getElementById('tbl-overlay').classList.add('open');
  buildTblGrid();
  syncGridHighlight(0, 0);
}
function closeTableDialog() {
  document.getElementById('tbl-overlay').classList.remove('open');
}
function buildTblGrid() {
  const grid = document.getElementById('tbl-grid');
  grid.innerHTML = '';
  for (let r = 1; r <= 8; r++) {
    for (let c = 1; c <= 8; c++) {
      const cell = document.createElement('div');
      cell.className = 'tbl-grid-cell';
      cell.dataset.r = r; cell.dataset.c = c;
      cell.addEventListener('mouseenter', () => syncGridHighlight(r, c));
      cell.addEventListener('click', () => {
        document.getElementById('tbl-rows-input').value = r;
        document.getElementById('tbl-cols-input').value = c;
      });
      grid.appendChild(cell);
    }
  }
  grid.addEventListener('mouseleave', () => syncGridHighlight(
    parseInt(document.getElementById('tbl-rows-input').value) || 0,
    parseInt(document.getElementById('tbl-cols-input').value) || 0
  ));
}
function syncGridHighlight(r, c) {
  document.getElementById('tbl-grid-label').textContent = r + ' × ' + c + ' table';
  document.querySelectorAll('.tbl-grid-cell').forEach(cell => {
    cell.classList.toggle('hover', +cell.dataset.r <= r && +cell.dataset.c <= c);
  });
}
function syncGridFromInputs() {
  const r = parseInt(document.getElementById('tbl-rows-input').value) || 0;
  const c = parseInt(document.getElementById('tbl-cols-input').value) || 0;
  syncGridHighlight(r, c);
}
function insertTableBlock() {
  const rows      = Math.max(1, parseInt(document.getElementById('tbl-rows-input').value) || 3);
  const cols      = Math.max(1, parseInt(document.getElementById('tbl-cols-input').value) || 3);
  const border    = document.getElementById('tbl-border-style').value;
  const hasHeader = document.getElementById('tbl-has-header').value === '1';
  const widthPct  = parseInt(document.getElementById('tbl-width-input')?.value) || 70;
  const container = document.getElementById('rich-sections-container');
  if (!container) return;

  const borderStyle = border === 'none' ? 'none' : `1.5px ${border} #c8e6c9`;

  let thead = '';
  let startRow = 0;
  if (hasHeader) {
    startRow = 1;
    const hcells = Array.from({length: cols}, (_, i) =>
      `<th style="border:${borderStyle};padding:7px 10px;background:linear-gradient(135deg,var(--primary),var(--primary-dark));color:#fff;font-weight:700;font-size:12px;letter-spacing:.3px;">
        <input type="text" placeholder="Header ${i+1}" style="width:100%;border:none;outline:none;background:transparent;font-weight:700;font-size:12px;font-family:'Inter',sans-serif;color:#fff;::placeholder-color:rgba(255,255,255,.6);">
       </th>`
    ).join('');
    thead = `<thead><tr>${hcells}</tr></thead>`;
  }

  const dataRows = Array.from({length: rows - startRow}, (_, ri) => {
    const cells = Array.from({length: cols}, (_, ci) =>
      `<td style="border:${borderStyle};padding:5px 10px;background:${ri%2===0?'#fff':'#f8fbf9'};">
        <input type="text" placeholder="" style="width:100%;border:none;outline:none;background:transparent;font-size:13px;font-family:'Inter',sans-serif;color:#333;">
       </td>`
    ).join('');
    return `<tr>${cells}</tr>`;
  }).join('');

  const block = document.createElement('div');
  block.className = 'inserted-block';
  block.dataset.blockType = 'table';
  block.dataset.rows = rows;
  block.dataset.cols = cols;
  block.innerHTML = `
    <button class="block-remove" title="Remove table" onclick="this.parentElement.remove();scheduleAutoSave()">
      <i class="fas fa-times-circle"></i>
    </button>
    <div class="block-label"><i class="fas fa-table"></i> Table (${rows}×${cols})</div>
    <div class="inserted-table-wrap" style="max-width:${widthPct}%;">
      <table class="inserted-table" style="border-collapse:collapse;width:100%;box-shadow:0 1px 6px rgba(61,186,111,.12);border-radius:6px;overflow:hidden;">
        ${thead}
        <tbody>${dataRows}</tbody>
      </table>
    </div>`;

  // Attach auto-save to all inputs inside the table
  block.querySelectorAll('input').forEach(attachAutoSave);

  // ── Insert at the correct position (after active heading/sub/subsub) ──
  // Priority: lastSubEl textarea → lastHeadingEl textarea → end of container
  let insertAfter = null;
  if (lastSubEl) {
    // Find the subsub-container or the desc textarea inside lastSubEl
    insertAfter = lastSubEl.querySelector('.subsub-container') || lastSubEl.querySelector('.sub-desc-input') || lastSubEl;
    // We want to insert the block as a sibling AFTER lastSubEl inside its parent
    insertAfter = lastSubEl;
    lastSubEl.parentNode.insertBefore(block, insertAfter.nextSibling);
  } else if (lastHeadingEl) {
    // Insert after the sub-entries div of lastHeadingEl
    const subEntries = lastHeadingEl.querySelector('.sub-entries');
    if (subEntries && subEntries.children.length > 0) {
      lastHeadingEl.appendChild(block); // append inside heading, after sub-entries
    } else {
      lastHeadingEl.appendChild(block);
    }
  } else {
    container.appendChild(block);
  }

  closeTableDialog();
  scheduleAutoSave();
  toast(`Table ${rows}×${cols} inserted!`, 'success');
}


</script>
</body>
</html>
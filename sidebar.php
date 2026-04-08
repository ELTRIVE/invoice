<?php
require_once __DIR__ . '/db.php';
$customerCount = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$company = $pdo->query("
    SELECT company_logo FROM invoice_company ORDER BY id DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$logo = $company['company_logo'] ?? '';

// Detect Techno Commercial context
$isTechno = strpos(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/techno_commercial/') !== false;
$tcPage   = $_GET['tc_page'] ?? '';
$tcSub    = $_GET['tc_sub']  ?? '';
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
/* ================= SIDEBAR ================= */
:root { --sb-w: 190px; }

.sidebar {
    position: fixed;
    top: 0; left: 0;
    height: 100vh;
    width: var(--sb-w);
    background: #ffffff;
    display: flex;
    flex-direction: column;
    z-index: 1000;
    box-shadow: 4px 0 16px rgba(0,0,0,0.07);
    border-right: 1px solid #e4e8f0;
    overflow: hidden;
}

/* LOGO */
.logo-box {
    width: 100%;
    height: 52px;
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 0 10px;
    border-bottom: 1px solid #f0f2f7;
    flex-shrink: 0;
}
.default-logo { font-size: 18px; color: #f97316; flex-shrink: 0; }
.logo-img {
    width: 26px; height: 26px;
    border-radius: 6px; object-fit: contain;
    background: #fff7f0; padding: 2px;
    border: 1px solid #ffe0cc; flex-shrink: 0;
}
.logo-company-name {
    font-family: 'Times New Roman', Times, serif;
    font-size: 10.5px; font-weight: 800;
    color: #1a1f2e; display: block; line-height: 1.2;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.logo-company-sub { font-size: 8.5px; color: #9ca3af; display: block; }

/* NAV */
.nav-section {
    width: 100%;
    padding: 8px 6px;
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
}
.nav-label {
    padding: 0 8px 4px;
    font-size: 8.5px; font-weight: 700;
    letter-spacing: 2px; text-transform: uppercase;
    color: #c0c8d8; margin-top: 4px;
    white-space: nowrap;
}

/* NAV LINKS */
.sidebar a,
.sidebar .nav-link-btn {
    width: 100%;
    display: flex; align-items: center; gap: 7px;
    padding: 7px 8px;
    color: #6b7280;
    text-decoration: none;
    font-size: 12.5px; font-weight: 600;
    font-family: 'Times New Roman', Times, serif;
    border-radius: 8px;
    position: relative;
    transition: background 0.15s ease, color 0.15s ease;
    margin-bottom: 1px;
    background: none; border: none; cursor: pointer; text-align: left;
    box-sizing: border-box;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sidebar a::before, .sidebar .nav-link-btn::before {
    content: '';
    position: absolute; top: 0; left: 0;
    width: 3px; height: 100%;
    background: #f97316; opacity: 0;
    border-radius: 0 3px 3px 0;
    transition: opacity 0.15s ease;
}
.sidebar a:hover::before, .sidebar a.active::before,
.sidebar .nav-link-btn:hover::before, .sidebar .nav-link-btn.active::before { opacity: 1; }
.sidebar a:hover, .sidebar .nav-link-btn:hover { background: #fff7f0; color: #f97316; }
.sidebar a.active, .sidebar .nav-link-btn.active {
    background: linear-gradient(135deg, rgba(249,115,22,0.12), rgba(249,115,22,0.04));
    color: #f97316;
    border: 1px solid rgba(249,115,22,0.18);
    border-left: none;
}
.sidebar .icon { font-size: 13px; min-width: 16px; text-align: center; flex-shrink: 0; }

/* TC CHEVRON */
.tc-chev { margin-left: auto; font-size: 9px; transition: transform 0.25s; flex-shrink: 0; }

/* TC SUB PANEL */
.tc-sub-panel {
    overflow: hidden;
    max-height: 0;
    transition: max-height 0.35s ease;
    width: 100%;
}
.tc-sub-panel.open { max-height: 900px; }

/* TC SECONDARY BLOCK */
.tc-block {
    margin: 2px 2px 3px 22px;
    border-radius: 7px;
    overflow: hidden;
    border: 1px solid #ffe8d6;
    background: #fffbf7;
}
.tc-block-header {
    display: flex; align-items: center; gap: 5px;
    padding: 5px 8px;
    font-size: 9px; font-weight: 800;
    font-family: 'Times New Roman', Times, serif;
    color: #c2410c;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    background: #fff4ed;
    border-bottom: 1px solid #ffe8d6;
    white-space: nowrap;
}
.tc-block-header i { font-size: 9px; }

/* TC secondary item */
.tc-sec-item {
    display: flex; align-items: center; gap: 6px;
    padding: 6px 8px;
    font-size: 12px; font-weight: 600;
    font-family: 'Times New Roman', Times, serif;
    color: #6b7280;
    cursor: pointer;
    text-decoration: none;
    border-radius: 5px;
    margin: 2px 3px;
    transition: all 0.15s;
    border-left: 2px solid transparent;
    background: none; border: none; width: calc(100% - 6px);
    box-sizing: border-box; white-space: nowrap;
}
.tc-sec-item:hover { background: #fff0e6; color: #ea580c; }
.tc-sec-item.active { background: #fff0e6; color: #ea580c; border-left: 2px solid #f97316; }
.tc-sec-icon { font-size: 11px; min-width: 14px; text-align: center; flex-shrink: 0; }

/* TC tertiary sub-pages */
.tc-tertiary {
    overflow: hidden;
    max-height: 0;
    transition: max-height 0.3s ease;
    background: #fffcfa;
    border-top: 1px solid #ffe8d6;
}
.tc-tertiary.open { max-height: 700px; }
.tc-ter-item {
    display: flex; align-items: center; gap: 5px;
    padding: 5px 8px 5px 14px;
    font-size: 11px; font-weight: 500;
    font-family: 'Times New Roman', Times, serif;
    color: #9ca3af;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.12s;
    border-left: 2px solid transparent;
    margin: 1px 0;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    display: block;
}
.tc-ter-item:hover { background: #fff7f0; color: #f97316; border-left-color: #fed7aa; }
.tc-ter-item.active { background: #fff0e6; color: #ea580c; border-left-color: #f97316; font-weight: 600; }

/* FOOTER */
.sidebar-footer {
    width: 100%; padding: 10px 12px;
    border-top: 1px solid #f0f2f7;
    display: flex; align-items: center; gap: 8px;
    flex-shrink: 0;
}
.footer-dot { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; flex-shrink: 0; box-shadow: 0 0 6px rgba(34,197,94,0.5); }
.footer-text { font-size: 10px; color: #9ca3af; font-family: 'Times New Roman', Times, serif; }

/* CONTENT OFFSET — pages that use .content should set this */
.content { margin-left: var(--sb-w) !important; }

::-webkit-scrollbar { display: none; }
html { scrollbar-width: none; }
body { font-family: 'Times New Roman', Times, serif !important; }
</style>

<div class="sidebar" id="appSidebar">

    <!-- Logo -->
    <div class="logo-box">
        <?php if ($logo && file_exists(__DIR__ . '/' . $logo)): ?>
            <img class="logo-img" src="/invoice/<?= htmlspecialchars(ltrim($logo, '/')) ?>" alt="Logo"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='block'">
            <i class="default-logo fas fa-bolt" style="display:none"></i>
        <?php else: ?>
            <i class="default-logo fas fa-bolt"></i>
        <?php endif; ?>
        <div style="min-width:0">
            <span class="logo-company-name">Eltrive</span>
            <span class="logo-company-sub">Automations Pvt Ltd</span>
        </div>
    </div>

    <!-- Nav Links -->
    <div class="nav-section">
        <div class="nav-label">Menu</div>

        <a href="/invoice/dashboard.php"
           class="<?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">
            <i class="icon fas fa-th-large"></i>Dashboard
        </a>

        <a href="/invoice/index.php?view=invoices"
           class="<?= (basename($_SERVER['PHP_SELF']) === 'index.php' && ($_GET['view'] ?? 'invoices') === 'invoices') || basename($_SERVER['PHP_SELF']) === 'create_invoice.php' ? 'active' : '' ?>">
            <i class="icon fas fa-file-invoice"></i>Invoices
        </a>

        <a href="/invoice/purchaseorder/pindex.php"
           class="<?= (strpos(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/purchaseorder/') !== false) ? 'active' : '' ?>">
            <i class="icon fas fa-shopping-cart"></i>Purchase Orders
        </a>

        <a href="/invoice/quotations/quote_index.php"
           class="<?= (strpos(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/quotations/') !== false) ? 'active' : '' ?>">
            <i class="icon fas fa-file-alt"></i>Quotations
        </a>

        <a href="/invoice/purchases/supplier_invoices.php"
           class="<?= (strpos(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/purchases/') !== false && basename($_SERVER['PHP_SELF']) !== 'suppliers.php') ? 'active' : '' ?>">
            <i class="icon fas fa-file-invoice-dollar"></i>Purchases
        </a>

        <a href="/invoice/purchases/suppliers.php"
           class="<?= basename($_SERVER['PHP_SELF']) === 'suppliers.php' ? 'active' : '' ?>">
            <i class="icon fas fa-truck"></i>Suppliers
        </a>

        <a href="/invoice/index.php?view=customers"
           class="<?= (($_GET['view'] ?? '') === 'customers') ? 'active' : '' ?>">
            <i class="icon fas fa-users"></i>Customers
        </a>

        <a href="/invoice/company.php"
           class="<?= basename($_SERVER['PHP_SELF']) === 'company.php' ? 'active' : '' ?>">
            <i class="icon fas fa-building"></i>Company
        </a>

        <a href="/invoice/items_list.php"
           class="<?= basename($_SERVER['PHP_SELF']) === 'items_list.php' ? 'active' : '' ?>">
            <i class="icon fas fa-box"></i>Items
        </a>

        <a href="/invoice/stock/stock_index.php"
           class="<?= (strpos(str_replace('\\', '/', $_SERVER['PHP_SELF']), '/stock/') !== false) ? 'active' : '' ?>">
            <i class="icon fas fa-boxes-stacked"></i>Stock
        </a>

        <!-- TECHNO COMMERCIAL -->
        <button class="nav-link-btn <?= $isTechno ? 'active' : '' ?>"
                id="tc-parent-btn"
                onclick="tcToggle()">
            <i class="icon fas fa-industry"></i>
            Techno Comm.
            <i class="fas fa-chevron-right tc-chev" id="tc-main-chev"></i>
        </button>

        <!-- TC Dropdown Panel -->
        <div class="tc-sub-panel <?= $isTechno ? 'open' : '' ?>" id="tc-sub-panel">

            <!-- CREATE -->
            <div class="tc-block">
                <button class="tc-sec-item <?= ($isTechno && $tcSub === 'create') ? 'active' : '' ?>"
                        id="tc-create-btn"
                        onclick="tcCreateToggle()">
                    <i class="tc-sec-icon fas fa-file-signature"></i>
                    Create Doc
                    <i class="fas fa-chevron-right tc-chev" id="tc-create-chev" style="margin-left:auto;"></i>
                </button>

                <!-- Tertiary sub-pages -->
                <div class="tc-tertiary <?= ($isTechno && $tcSub === 'create') ? 'open' : '' ?>"
                     id="tc-create-tertiary">

                    <?php
                    $terPages = [
                        'mainproject'  => 'Main Project',
                        'overview'     => 'Overview',
                        'benefits'     => 'Benefits & ROI',
                        'scope'        => 'Scope of Work',
                        'current'      => 'Current Scenario',
                        'proposed'     => 'Proposed Solution',
                        'utilities'    => 'Utilities Covered',
                        'architecture' => 'Architecture',
                        'kpis'         => 'KPIs',
                        'dashboardfeat'=> 'Dashboard Features',
                        'testing'      => 'Testing & Comm.',
                        'deliverables' => 'Deliverables',
                        'customer'     => 'Customer Scope',
                        'outscope'     => 'Out of Scope',
                        'commercials'  => 'Commercials',
                        'comsummary'   => 'Commercial Summary',
                    ];
                    foreach ($terPages as $key => $label):
                        $isActive = ($tcPage === $key) ? 'active' : '';
                    ?>
                    <a class="tc-ter-item <?= $isActive ?>"
                       href="/invoice/techno_commercial/tc_index.php?tc_sub=create&tc_page=<?= $key ?>"
                       title="<?= htmlspecialchars($label) ?>">
                        <?= $label ?>
                    </a>
                    <?php endforeach; ?>

                </div><!-- /tc-create-tertiary -->
            </div><!-- /tc-block Create -->

            <!-- DOCUMENTS -->
            <div class="tc-block" style="margin-top:3px;">
               
                <a class="tc-sec-item <?= ($isTechno && $tcSub === 'documents') ? 'active' : '' ?>"
                   href="/invoice/techno_commercial/tc_index.php?tc_sub=documents"
                   style="display:flex;">
                    <i class="tc-sec-icon fas fa-file-contract"></i>
                    All Documents
                </a>
            </div><!-- /tc-block Documents -->

        </div><!-- /tc-sub-panel -->

    </div><!-- /nav-section -->

    <!-- Footer -->
    <div class="sidebar-footer">
        <div class="footer-dot"></div>
        <span class="footer-text">System Active</span>
    </div>
</div><!-- /sidebar -->

<script>
(function () {
    var panel      = document.getElementById('tc-sub-panel');
    var mainChev   = document.getElementById('tc-main-chev');
    var createBtn  = document.getElementById('tc-create-btn');
    var createTer  = document.getElementById('tc-create-tertiary');
    var createChev = document.getElementById('tc-create-chev');

    function syncChevrons() {
        if (mainChev)   mainChev.style.transform   = panel    && panel.classList.contains('open')     ? 'rotate(90deg)' : '';
        if (createChev) createChev.style.transform = createTer && createTer.classList.contains('open') ? 'rotate(90deg)' : '';
    }
    syncChevrons();

    window.tcToggle = function () {
        if (!panel) return;
        panel.classList.toggle('open');
        syncChevrons();
    };

    window.tcCreateToggle = function () {
        if (!createTer) return;
        createTer.classList.toggle('open');
        if (createBtn) createBtn.classList.toggle('active', createTer.classList.contains('open'));
        syncChevrons();
    };
})();
</script>
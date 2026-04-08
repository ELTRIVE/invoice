<?php
// header.php — Include this on every page: include 'header.php'
$_hdr_logo = '';
try {
    if (!isset($pdo)) require_once __DIR__ . '/db.php';
    $_hdr_row = $pdo->query("SELECT company_logo FROM invoice_company ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $_hdr_logo = $_hdr_row['company_logo'] ?? '';
} catch (Exception $e) {
    $_hdr_logo = '';
}
?>
<style>
.topbar {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    height: 52px;
    background: #ffffff;
    border-bottom: 1.5px solid #e4e8f0;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
    display: flex;
    align-items: center;
    padding: 0 28px;
    z-index: 999;
    gap: 10px;
    transition: none;
}
.topbar-company {
    font-family: 'Times New Roman', Times, serif;
    font-size: 18px;
    font-weight: 800;
    color: #16a34a;
    letter-spacing: 0.5px;
}
.topbar-sub {
    font-size: 11px;
    color: #9ca3af;
    font-family: 'Times New Roman', Times, serif;
}
.topbar-right {
    margin-left: auto;
    position: relative;
}
.logout-icon-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: #f4f6fb;
    border: 1.5px solid #e4e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #6b7280;
    font-size: 16px;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.logout-icon-btn:hover {
    background: #fef2f2;
    border-color: #fca5a5;
    color: #dc2626;
}
.logout-dropdown {
    display: none;
    position: absolute;
    top: 44px;
    right: 0;
    background: #fff;
    border: 1.5px solid #e4e8f0;
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.10);
    min-width: 140px;
    z-index: 9999;
    overflow: hidden;
}
.logout-dropdown.open {
    display: block;
}
.logout-dropdown a {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    font-size: 13px;
    font-family: 'Times New Roman', Times, serif;
    color: #dc2626;
    text-decoration: none;
    font-weight: 600;
    transition: background 0.15s;
}
.logout-dropdown a:hover {
    background: #fef2f2;
}
/* Push all page content below topbar */
.content {
    padding-top: 60px !important;
}
@media (max-width: 900px) {
    .topbar { left: 0; }
}
</style>

<div class="topbar">
    <span class="topbar-company">ELTRIVE PVT LTD</span>
   

    <div class="topbar-right">
        <div class="logout-icon-btn" onclick="toggleLogout()" title="Account">
            <?php if (!empty($_hdr_logo)): ?>
                <img src="/invoice/<?= htmlspecialchars(ltrim($_hdr_logo, '/')) ?>" alt="Logo"
                     style="width:28px;height:28px;object-fit:contain;border-radius:50%;"
                     onerror="this.outerHTML='<i class=\'fas fa-bolt\' style=\'color:#f97316\'></i>'">
            <?php else: ?>
                <i class="fas fa-bolt" style="color:#f97316"></i>
            <?php endif; ?>
        </div>
        <div class="logout-dropdown" id="logoutDropdown">
            <a href="../home.php">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</div>

<script>
function toggleLogout() {
    document.getElementById('logoutDropdown').classList.toggle('open');
}
// Close dropdown if clicked outside
document.addEventListener('click', function(e) {
    const btn = document.querySelector('.logout-icon-btn');
    const dd  = document.getElementById('logoutDropdown');
    if (dd && btn && !btn.contains(e.target) && !dd.contains(e.target)) {
        dd.classList.remove('open');
    }
});

// Sync topbar left and content margin-left to actual sidebar width
function syncSidebarWidth() {
    var sidebar = document.getElementById('appSidebar');
    var topbar  = document.querySelector('.topbar');
    var content = document.querySelector('.content');
    if (sidebar && topbar) {
        var w = sidebar.offsetWidth + 'px';
        topbar.style.left = w;
        if (content) content.style.marginLeft = w;
    }
}
document.addEventListener('DOMContentLoaded', syncSidebarWidth);
window.addEventListener('resize', syncSidebarWidth);
</script>
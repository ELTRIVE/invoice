<?php
require_once 'db.php';
/* ── TOPBAR LOGO ── */


/* ── FETCH COMPANY ── */
$companyData = $pdo->query("
    SELECT * FROM invoice_company ORDER BY id ASC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

/* ── UPDATE COMPANY ── */
if (isset($_POST['update_company']) && $companyData) {
    $company_name = trim($_POST['company_name'] ?? '');
    $address1     = trim($_POST['address_line1'] ?? '');
    $address2     = trim($_POST['address_line2'] ?? '');
    $city         = trim($_POST['city'] ?? '');
    $state        = trim($_POST['state'] ?? '');
    $pincode      = trim($_POST['pincode'] ?? '');
    $gst          = strtoupper(trim($_POST['gst_number'] ?? ''));
    $cin          = strtoupper(trim($_POST['cin_number'] ?? ''));
    $phone        = trim($_POST['phone'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $pan          = strtoupper(trim($_POST['pan'] ?? ''));
    $website      = trim($_POST['website'] ?? '');

    $uploadDir = 'uploads/company/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $logoPath = $companyData['company_logo'] ?? null;
    if (!empty($_FILES['company_logo']['name'])) {
        $logoName = time() . '_' . basename($_FILES['company_logo']['name']);
        $logoPath = $uploadDir . $logoName;
        move_uploaded_file($_FILES['company_logo']['tmp_name'], $logoPath);
    }

    $stmt = $pdo->prepare("
        UPDATE invoice_company SET
            company_name=?, address_line1=?, address_line2=?,
            city=?, state=?, pincode=?, gst_number=?, cin_number=?,
            phone=?, email=?, pan=?, website=?, company_logo=?
        WHERE id=?
    ");
    $stmt->execute([
        $company_name, $address1, $address2,
        $city, $state, $pincode, $gst, $cin,
        $phone, $email, $pan, $website, $logoPath,
        $companyData['id']
    ]);
    header("Location: company.php");
    exit;
}

$editMode = isset($_GET['edit']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="/invoice/assets/favicon.png">
<title>Company Settings</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;background:#f0f2f8;color:#1a1f2e}

.content{margin-left:220px;padding:68px 24px 28px;min-height:100vh;background:#f0f2f8}

/* PAGE HEADER */
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.page-header-left{display:flex;align-items:center;gap:12px}
.page-header-icon{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#f97316,#fb923c);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;box-shadow:0 3px 10px rgba(249,115,22,.3)}
.page-title{font-size:17px;font-weight:800;color:#1a1f2e}
.page-sub{font-size:11px;color:#9ca3af;margin-top:1px}

/* HERO CARD */
.hero-card{
    background:#fff;border:1px solid #e8ecf4;border-radius:16px;
    padding:16px 20px;margin-bottom:12px;
    box-shadow:0 2px 8px rgba(0,0,0,.05);
    display:flex;align-items:center;gap:16px;
    position:relative;overflow:hidden;
}
.hero-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#f97316,#fb923c,#7c3aed)}
.hero-logo{width:60px;height:60px;border-radius:12px;background:#fff7f0;border:2px solid #ffe0cc;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
.hero-logo img{width:100%;height:100%;object-fit:contain}
.hero-logo i{font-size:24px;color:#f97316}
.hero-info strong{font-size:18px;font-weight:800;color:#1a1f2e;display:block;line-height:1.2}
.hero-info .hero-sub{font-size:12px;color:#6b7280;margin-top:3px}
.hero-tags{display:flex;gap:6px;margin-top:7px;flex-wrap:wrap}
.htag{background:rgba(249,115,22,.08);color:#f97316;padding:2px 10px;border-radius:20px;font-size:10px;font-weight:700;letter-spacing:.3px}

/* TWO COLUMN LAYOUT */
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.one-col{margin-bottom:12px}

/* FORM CARD */
.fc{background:#fff;border:1px solid #e8ecf4;border-radius:14px;box-shadow:0 2px 6px rgba(0,0,0,.04);overflow:hidden}
.fc-head{padding:12px 18px;border-bottom:1px solid #f0f2f7;background:#fafbfd;display:flex;align-items:center;gap:10px}
.fc-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff;flex-shrink:0}
.fc-head h3{font-size:13px;font-weight:800;color:#1a1f2e}
.fc-body{padding:14px 18px}

/* FIELDS */
.fg{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.fg .full{grid-column:1/-1}
.field label{display:block;font-size:10px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px}
.field input,.field select{
    width:100%;padding:9px 12px;
    border:1.5px solid #e4e8f0;border-radius:8px;
    font-size:12px;font-family:'Times New Roman',Times,serif;
    color:#1a1f2e;background:#fff;outline:none;
    transition:border-color .2s,box-shadow .2s;
}
.field input:focus,.field select:focus{border-color:#f97316;box-shadow:0 0 0 3px rgba(249,115,22,.1)}
.field-value{
    padding:9px 12px;background:#f8f9fc;border:1.5px solid #f0f2f7;
    border-radius:8px;font-size:12px;color:#374151;min-height:38px;
    display:flex;align-items:center;
}
.field-value.empty{color:#c0c8d8;font-style:italic}
.field-error{font-size:10px;color:#dc2626;margin-top:3px;display:none}
.field-error.show{display:block}
input.invalid{border-color:#dc2626!important;box-shadow:0 0 0 3px rgba(220,38,38,.1)!important}

/* LOGO UPLOAD */
.logo-upload-wrap{display:flex;align-items:center;gap:12px}
.logo-preview{width:48px;height:48px;border-radius:8px;background:#f4f6fb;border:1.5px solid #e4e8f0;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
.logo-preview img{width:100%;height:100%;object-fit:cover}
.logo-preview i{font-size:18px;color:#c0c8d8}
.file-input{width:100%;padding:7px 10px;border:1.5px dashed #e4e8f0;border-radius:8px;font-size:11px;color:#6b7280;background:#fafbfc;cursor:pointer;font-family:'Times New Roman',Times,serif;transition:border-color .2s}
.file-input:hover{border-color:#f97316}

/* BUTTONS */
.btn-save{padding:9px 22px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;gap:6px;transition:all .2s;box-shadow:0 2px 8px rgba(249,115,22,.3)}
.btn-save:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(249,115,22,.4)}
.btn-edit{padding:8px 16px;background:linear-gradient(135deg,#f97316,#fb923c);color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;gap:6px;text-decoration:none;transition:all .2s;box-shadow:0 2px 8px rgba(249,115,22,.25)}
.btn-edit:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(249,115,22,.4)}
.btn-cancel{padding:9px 18px;background:#fff;color:#6b7280;border:1.5px solid #e4e8f0;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;font-family:'Times New Roman',Times,serif;display:inline-flex;align-items:center;gap:6px;text-decoration:none;transition:all .2s}
.btn-cancel:hover{background:#f4f6fb;border-color:#d1d5db}
.bottom-actions{display:flex;gap:10px;align-items:center;padding:14px 18px;border-top:1px solid #f0f2f7;background:#fafbfd}

::-webkit-scrollbar{width:3px}::-webkit-scrollbar-thumb{background:#e2e8f0;border-radius:99px}
@media(max-width:900px){.two-col{grid-template-columns:1fr}.fg{grid-template-columns:1fr}.fg .full{grid-column:1}.content{margin-left:0!important;padding:70px 12px 20px}}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<?php include 'header.php'; ?>

<div class="content">

    <!-- PAGE HEADER -->
    <div class="page-header">
        <div class="page-header-left">
            <div class="page-header-icon"><i class="fas fa-building"></i></div>
            <div>
                <div class="page-title">Company Settings</div>
                <div class="page-sub">Manage your business information</div>
            </div>
        </div>
        <?php if (!$editMode): ?>
        <a href="?edit=1" class="btn-edit"><i class="fas fa-pen"></i> Edit Company</a>
        <?php endif; ?>
    </div>

    <!-- HERO CARD -->
    <div class="hero-card">
        <div class="hero-logo">
            <?php if (!empty($companyData['company_logo']) && file_exists($companyData['company_logo'])): ?>
                <img src="<?= htmlspecialchars($companyData['company_logo']) ?>" alt="Logo">
            <?php else: ?>
                <i class="fas fa-bolt"></i>
            <?php endif; ?>
        </div>
        <div class="hero-info">
            <strong><?= htmlspecialchars($companyData['company_name'] ?? 'Your Company Name') ?></strong>
            <div class="hero-sub"><?= htmlspecialchars($companyData['email'] ?? '') ?><?= !empty($companyData['phone']) ? ' &nbsp;·&nbsp; '.htmlspecialchars($companyData['phone']) : '' ?></div>
            <div class="hero-tags">
                <?php if (!empty($companyData['gst_number'])): ?><span class="htag">GST: <?= htmlspecialchars($companyData['gst_number']) ?></span><?php endif; ?>
                <?php if (!empty($companyData['pan'])): ?><span class="htag">PAN: <?= htmlspecialchars($companyData['pan']) ?></span><?php endif; ?>
                <?php if (!empty($companyData['city'])): ?><span class="htag"><i class="fas fa-map-marker-alt" style="margin-right:3px"></i><?= htmlspecialchars($companyData['city']) ?></span><?php endif; ?>
            </div>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data">

        <!-- ROW 1: Basic Info + Contact side by side -->
        <div class="two-col">

            <!-- BASIC INFO -->
            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#f97316,#fb923c)"><i class="fas fa-building"></i></div>
                    <h3>Basic Information</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">

                        <!-- Company Name (full width) -->
                        <div class="field full">
                            <label>Company Name <span style="color:#dc2626">*</span></label>
                            <?php if ($editMode): ?>
                                <input type="text" name="company_name" value="<?= htmlspecialchars($companyData['company_name'] ?? '') ?>" required placeholder="Your Company Pvt Ltd">
                            <?php else: ?>
                                <div class="field-value <?= empty($companyData['company_name']) ? 'empty':'' ?>"><?= !empty($companyData['company_name']) ? htmlspecialchars($companyData['company_name']) : 'Not set' ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Logo (full width) -->
                        <div class="field full">
                            <label>Company Logo</label>
                            <div class="logo-upload-wrap">
                                <div class="logo-preview">
                                    <?php if (!empty($companyData['company_logo']) && file_exists($companyData['company_logo'])): ?>
                                        <img src="<?= htmlspecialchars($companyData['company_logo']) ?>" alt="Logo">
                                    <?php else: ?>
                                        <i class="fas fa-image"></i>
                                    <?php endif; ?>
                                </div>
                                <?php if ($editMode): ?>
                                    <div style="flex:1">
                                        <input type="file" name="company_logo" class="file-input" accept="image/*">
                                        <div style="font-size:10px;color:#9ca3af;margin-top:2px">PNG, JPG up to 5MB</div>
                                    </div>
                                <?php else: ?>
                                    <div class="field-value" style="flex:1"><?= !empty($companyData['company_logo']) ? 'Logo uploaded' : 'No logo uploaded' ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Website (full width) -->
                        <div class="field full">
                            <label>Website</label>
                            <?php if ($editMode): ?>
                                <input type="url" name="website" value="<?= htmlspecialchars($companyData['website'] ?? '') ?>" placeholder="https://yourwebsite.com">
                            <?php else: ?>
                                <div class="field-value <?= empty($companyData['website']) ? 'empty':'' ?>"><?= !empty($companyData['website']) ? htmlspecialchars($companyData['website']) : 'Not set' ?></div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
            </div>

            <!-- CONTACT -->
            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#7c3aed,#a78bfa)"><i class="fas fa-user"></i></div>
                    <h3>Contact Details</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">

                        <div class="field full">
                            <label>Phone <span style="color:#dc2626">*</span></label>
                            <?php if ($editMode): ?>
                                <input type="text" name="phone" value="<?= htmlspecialchars($companyData['phone'] ?? '') ?>" placeholder="+91 XXXXX XXXXX" required>
                            <?php else: ?>
                                <div class="field-value <?= empty($companyData['phone']) ? 'empty':'' ?>"><?= !empty($companyData['phone']) ? htmlspecialchars($companyData['phone']) : 'Not set' ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="field full">
                            <label>Email <span style="color:#dc2626">*</span></label>
                            <?php if ($editMode): ?>
                                <input type="email" name="email" value="<?= htmlspecialchars($companyData['email'] ?? '') ?>" placeholder="company@email.com" required>
                            <?php else: ?>
                                <div class="field-value <?= empty($companyData['email']) ? 'empty':'' ?>"><?= !empty($companyData['email']) ? htmlspecialchars($companyData['email']) : 'Not set' ?></div>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>
            </div>

        </div><!-- /ROW 1 -->

        <!-- ROW 2: Address + Tax & Legal side by side -->
        <div class="two-col">

            <!-- ADDRESS -->
            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#2563eb,#60a5fa)"><i class="fas fa-map-marker-alt"></i></div>
                    <h3>Address</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">
                        <?php
                        $addrFields = [
                            'address_line1' => ['Address Line 1 <span style="color:#dc2626">*</span>', 'full', 'Street, area...', true],
                            'address_line2' => ['Address Line 2', 'full', 'Landmark, area...', false],
                            'city'          => ['City <span style="color:#dc2626">*</span>',    '', 'City', true],
                            'state'         => ['State <span style="color:#dc2626">*</span>',   '', 'State', true],
                            'pincode'       => ['Pincode <span style="color:#dc2626">*</span>', '', 'PIN Code', true],
                        ];
                        foreach ($addrFields as $key => [$label, $span, $placeholder, $req]):
                            $val = $companyData[$key] ?? '';
                        ?>
                        <div class="field <?= $span ?>">
                            <label><?= $label ?></label>
                            <?php if ($editMode): ?>
                                <input type="text" name="<?= $key ?>" value="<?= htmlspecialchars($val) ?>" placeholder="<?= $placeholder ?>" <?= $req ? 'required' : '' ?>>
                            <?php else: ?>
                                <div class="field-value <?= empty($val) ? 'empty':'' ?>"><?= !empty($val) ? htmlspecialchars($val) : 'Not set' ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- TAX & LEGAL -->
            <div class="fc">
                <div class="fc-head">
                    <div class="fc-icon" style="background:linear-gradient(135deg,#16a34a,#4ade80)"><i class="fas fa-file-contract"></i></div>
                    <h3>Tax &amp; Legal</h3>
                </div>
                <div class="fc-body">
                    <div class="fg">
                        <?php
                        $taxFields = [
                            'gst_number' => ['GST Number <span style="color:#dc2626">*</span>', '22AAAAA0000A1Z5', 'id="gst_number" required style="text-transform:uppercase" maxlength="15"'],
                            'cin_number' => ['CIN Number <span style="color:#dc2626">*</span>', 'U12345AB2020PTC123456', 'required style="text-transform:uppercase"'],
                            'pan'        => ['PAN Number <span style="color:#dc2626">*</span>', 'ABCDE1234F', 'id="pan" required style="text-transform:uppercase" maxlength="10"'],
                        ];
                        foreach ($taxFields as $key => [$label, $placeholder, $extra]):
                            $val = $companyData[$key] ?? '';
                        ?>
                        <div class="field full">
                            <label><?= $label ?></label>
                            <?php if ($editMode): ?>
                                <input type="text" name="<?= $key ?>" value="<?= htmlspecialchars($val) ?>" placeholder="<?= $placeholder ?>" <?= $extra ?>>
                                <?php if ($key === 'gst_number'): ?><span class="field-error" id="gstin_error"></span>
                                <?php elseif ($key === 'pan'): ?><span class="field-error" id="pan_error"></span><?php endif; ?>
                            <?php else: ?>
                                <div class="field-value <?= empty($val) ? 'empty':'' ?>" style="font-family:<?= !empty($val) ? 'monospace':'inherit' ?>"><?= !empty($val) ? htmlspecialchars($val) : 'Not set' ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div><!-- /ROW 2 -->

        <!-- SAVE ACTIONS -->
        <?php if ($editMode): ?>
        <div class="fc one-col">
            <div class="bottom-actions">
                <button type="submit" name="update_company" class="btn-save"><i class="fas fa-check"></i> Save Changes</button>
                <a href="company.php" class="btn-cancel"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </div>
        <?php endif; ?>

    </form>

</div><!-- /content -->

<script>
const gstInput = document.getElementById('gst_number');
const panInput = document.getElementById('pan');
if (gstInput) gstInput.addEventListener('input', function(){ this.value = this.value.toUpperCase(); });
if (panInput) panInput.addEventListener('input', function(){ this.value = this.value.toUpperCase(); });

function validateGstinPan() {
    let valid = true;
    if (gstInput && gstInput.value.trim()) {
        const r = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/;
        const e = document.getElementById('gstin_error');
        if (!r.test(gstInput.value.trim())) { e.textContent='Invalid GSTIN. Format: 22AAAAA0000A1Z5'; e.classList.add('show'); gstInput.classList.add('invalid'); valid=false; }
        else { e.classList.remove('show'); gstInput.classList.remove('invalid'); }
    }
    if (panInput && panInput.value.trim()) {
        const r = /^[A-Z]{5}[0-9]{4}[A-Z]{1}$/;
        const e = document.getElementById('pan_error');
        if (!r.test(panInput.value.trim())) { e.textContent='Invalid PAN. Format: ABCDE1234F'; e.classList.add('show'); panInput.classList.add('invalid'); valid=false; }
        else { e.classList.remove('show'); panInput.classList.remove('invalid'); }
    }
    return valid;
}
if (gstInput) gstInput.addEventListener('blur', validateGstinPan);
if (panInput) panInput.addEventListener('blur', validateGstinPan);
document.querySelector('form')?.addEventListener('submit', function(e){ if (!validateGstinPan()) e.preventDefault(); });
</script>
</body>
</html>
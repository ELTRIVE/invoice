<?php
// safehydra/preview.php — Serves the template PDF inline for preview in iframe
// Place your template PDF at: safehydra/template/safe_hydra_template.pdf

$pdfPath = __DIR__ . '/template/safe_hydra_template.pdf';

if (!file_exists($pdfPath)) {
    echo '<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<style>
body{font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f5f5f5;}
.box{text-align:center;background:#fff;padding:40px;border-radius:10px;box-shadow:0 4px 20px rgba(0,0,0,.1);}
.box i{font-size:48px;color:#f97316;margin-bottom:16px;display:block;}
.box h2{font-size:18px;color:#1a1f2e;margin-bottom:8px;}
.box code{background:#f0f4f8;padding:4px 10px;border-radius:4px;font-size:13px;color:#e74c3c;}
</style>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head><body>
<div class="box">
  <i class="fas fa-file-pdf"></i>
  <h2>Template PDF Not Found</h2>
  <p style="color:#6b7280;margin-bottom:12px;">Please place your PDF template at:</p>
  <code>safehydra/template/safe_hydra_template.pdf</code>
</div>
</body></html>';
    exit;
}

// Serve PDF inline — no download prompt
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="safe_hydra_template.pdf"');
header('Content-Length: ' . filesize($pdfPath));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($pdfPath);
exit;
?>
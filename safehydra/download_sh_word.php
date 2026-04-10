<?php
require_once dirname(__DIR__) . '/db.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    exit('Invalid ID.');
}

$stmt = $pdo->prepare("SELECT * FROM safe_hydra_documents WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

$mode = trim((string)($_GET['mode'] ?? 'download'));

$filename = 'SafeHydra_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$doc['document_number']) . '.doc';
$customer = htmlspecialchars((string)$doc['customer_name'], ENT_QUOTES, 'UTF-8');
$project = htmlspecialchars((string)$doc['company_name'], ENT_QUOTES, 'UTF-8');
$amount = number_format((float)$doc['amount'], 0);
$docNo = htmlspecialchars((string)$doc['document_number'], ENT_QUOTES, 'UTF-8');
$created = date('d-M-Y', strtotime((string)$doc['created_at']));

header('Content-Type: application/msword; charset=UTF-8');
if ($mode === 'edit') {
    header('Content-Disposition: inline; filename="' . $filename . '"');
} else {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
}
header('Cache-Control: no-cache');

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Safe Hydra</title>
<style>
body{font-family:"Times New Roman",serif;font-size:12pt;line-height:1.35;margin:26px;color:#111;}
h1,h2,h3{margin:0 0 8px 0;}
.muted{color:#555;font-size:10pt;margin-bottom:12px;}
table{border-collapse:collapse;width:100%;margin:10px 0;}
th,td{border:1px solid #333;padding:6px;vertical-align:top;}
th{background:#f2f2f2;}
.small{font-size:10pt;}
.page-break{page-break-after:always;}
.toc td,.toc th{font-size:11pt;}
.no-border{border:none !important;}
.section-title{margin-top:12px;}
</style></head><body>';

echo '<h2>Safe Hydra- Fire Hydrant Pump House Monitoring System</h2>';
echo '<div class="muted">Document Key: ELT-QT-2551V1 | Version: V1 | Generated: ' . $created . ' | Editable Word Copy</div>';
echo '<table>
<tr><th style="width:220px;">Document Number</th><td>' . $docNo . '</td></tr>
<tr><th>Customer</th><td>' . $customer . '</td></tr>
<tr><th>Project Name</th><td>' . $project . '</td></tr>
</table>';

echo '<h3 class="section-title">Revision History</h3>';
echo '<table>
<tr><th>Role</th><th>Name</th><th>Department</th><th>Date</th></tr>
<tr><td>Author</td><td>G.Chakravarthy</td><td>Automation</td><td>02-Apr-26</td></tr>
<tr><td>1st Check</td><td>V.Bhaskar Bhargavi</td><td>Automation</td><td>02-Apr-26</td></tr>
<tr><td>Approved</td><td>G.Rajesh</td><td>Automation</td><td>06-Apr-26</td></tr>
</table>';

echo '<div class="page-break"></div>';

echo '<h3>Contents</h3>';
echo '<table class="toc">
<tr><td>1. PROJECT OVERVIEW</td><td>3</td></tr>
<tr><td>1.1 BENEFITS OF SAFE HYDRA</td><td>3</td></tr>
<tr><td>1.2 KEY FEATURES</td><td>3</td></tr>
<tr><td>2. SCOPE OF WORK - SAFE HYDRA</td><td>3</td></tr>
<tr><td>2.1 ELTRIVE SCOPE</td><td>3</td></tr>
<tr><td>2.2 CUSTOMER SCOPE / SUPPORT REQUIRED</td><td>3</td></tr>
<tr><td>2.3 ASSUMPTIONS</td><td>4</td></tr>
<tr><td>2.4 OUT OF SCOPE</td><td>4</td></tr>
<tr><td>3. DELIVERABLES</td><td>4</td></tr>
<tr><td>3.1 HARDWARE DELIVERABLES (BOQ)</td><td>4</td></tr>
<tr><td>3.2 SERVICES SUPPLY</td><td>5</td></tr>
<tr><td>3.3 SOFTWARE CHARGES</td><td>5</td></tr>
<tr><td>3.4 OPTIONAL SERVICES</td><td>5</td></tr>
<tr><td>4. USER ACCESS & APPLICATION AVAILABILITY</td><td>5</td></tr>
<tr><td>5. APPLICATION REFERENCE IMAGES</td><td>6</td></tr>
<tr><td>6. SITE WISE PROPOSALS</td><td>9</td></tr>
<tr><td>7. COMMERCIALS</td><td>9</td></tr>
</table>';

echo '<div class="page-break"></div>';

echo '<h3>1. Project Overview</h3>';
echo '<p>The fire pump house at ' . $customer . ' plays a mission-critical role in plant safety, comprising a jockey pump, main hydrant pump, and diesel pump that must remain fully operational and ready at all times. Any unnoticed failure, pressure drop, or delayed response can significantly compromise fire preparedness.</p>';
echo '<p>To overcome these limitations, Eltrive Automations introduces SAFE HYDRA NextGen Hydrant Monitoring & Alert System, an advanced IoT-enabled solution designed to provide 24x7 real-time monitoring, instant alerts, and centralized visibility of fire pump operations.</p>';
echo '<h3>1.1 Benefits of SAFE HYDRA</h3>';
echo '<ol>
<li>24x7 real-time monitoring of jockey, main, and diesel fire pumps</li>
<li>Instant alerts for pump faults, pressure drops, and power failures</li>
<li>Proactive maintenance through early fault detection and trend analysis</li>
<li>Centralized and remote access to fire pump status from anywhere</li>
<li>Improved safety, compliance readiness, and system reliability</li>
</ol>';
echo '<h3>1.2 Key Features</h3>';
echo '<ol>
<li>Jockey pump health monitoring</li>
<li>Main hydrant pump health monitoring</li>
<li>Diesel pump health monitoring</li>
<li>Pressure, level, voltage, kW, kWh, and current sensing</li>
<li>Web and mobile dashboards with SMS / Email alerts</li>
<li>Customizable, compliance-ready reports</li>
</ol>';

echo '<h3>2. Scope of Work - SAFE HYDRA</h3>';
echo '<ul>
<li>Site visit, requirement capture, design and engineering</li>
<li>Supply of sensors, IoT controller, gateway, and accessories</li>
<li>Installation, integration, testing, and commissioning</li>
<li>Dashboard setup and alert logic implementation</li>
</ul>';
echo '<h3>2.2 Customer Scope / Support Required</h3>';
echo '<ol>
<li>Electrical panel wiring, terminations, and modifications</li>
<li>Execution support for fabrication and mounting</li>
<li>Provision of safety permits and statutory approvals</li>
<li>Support for shutdowns during installation and commissioning</li>
<li>Civil supports and cable routing support where required</li>
</ol>';
echo '<h3>2.3 Assumptions</h3>';
echo '<ol><li>Accessible pump room</li><li>Clean 230V AC power supply</li><li>Adequate equipment space</li><li>Internet connectivity available</li></ol>';
echo '<h3>2.4 Out of Scope</h3>';
echo '<ol><li>Remote start/stop of pumps</li><li>Diesel pump auto-start</li><li>Major panel modifications</li><li>NFPA/IS compliance testing</li></ol>';

echo '<div class="page-break"></div>';

echo '<h3>3. Deliverables</h3>';
echo '<table>
<tr><th style="width:50px;">S.No</th><th>Details</th><th style="width:120px;">Qty</th><th style="width:160px;">Amount (Rs.)</th></tr>
<tr><td>1</td><td>Full Hardware Kit including sensors, PLC panel and accessories</td><td>1 Lot</td><td>' . $amount . '</td></tr>
</table>';
echo '<h3>3.2 Services Supply</h3>';
echo '<table>
<tr><th>S.No</th><th>Details</th><th>Make</th><th>Qty</th><th>Amount</th></tr>
<tr><td>16</td><td>PLC Configuration & Programming</td><td>Eltrive</td><td>1</td><td>20,000</td></tr>
<tr><td>17</td><td>Web Application Development and Customization charges</td><td>Eltrive</td><td>1</td><td>80,000</td></tr>
<tr><td>18</td><td>Erection and Commissioning</td><td>Eltrive</td><td>1</td><td>40,000</td></tr>
<tr><td>19</td><td>Subscription - Yearly (Server and Data)</td><td>Eltrive</td><td>1</td><td>1,25,000</td></tr>
</table>';
echo '<h3>3.3 Software Charges</h3>';
echo '<p>Software Application Development charges with customization for company themes, test sequences, logbooks/reports, and SMS/Email alerts: <strong>1,45,000</strong>.</p>';
echo '<h3>3.4 Optional Services</h3>';
echo '<p>Additional user subscriptions: Android (20,000/user/year), iOS (35,000/user/year).</p>';

echo '<div class="page-break"></div>';

echo '<h3>4. User Access & Application Availability</h3>';
echo '<h3>4.1 Mobile Application Access</h3>';
echo '<p>Dedicated mobile application with pre-configured automatic access and alert notifications.</p>';
echo '<h3>4.2 Web Application Access</h3>';
echo '<p>Secure login credentials with complimentary access for two admin users and three standard users.</p>';
echo '<h3>4.3 Additional Users</h3>';
echo '<p>Additional mobile/web users can be provisioned at applicable commercial cost.</p>';
echo '<h3>4.4 Access Control & Security</h3>';
echo '<p>Role-based and password-protected access to ensure secure and traceable data handling.</p>';
echo '<h3>5. Application Reference Images</h3>';
echo '<p class="small">[Insert application screenshots here while editing this Word document.]</p>';
echo '<table><tr><td style="height:180px;text-align:center;">Reference Image Placeholder</td></tr></table>';
echo '<table><tr><td style="height:180px;text-align:center;">Reference Image Placeholder</td></tr></table>';

echo '<div class="page-break"></div>';

echo '<h3>4. Commercials</h3>';
echo '<table>
<tr><th>Item</th><th>Details</th><th>Amount (Rs.)</th></tr>
<tr><td>1</td><td>Hardware Supply</td><td>' . $amount . '</td></tr>
<tr><td>2</td><td>Service & with AMC</td><td>2,65,000</td></tr>
<tr><td>3</td><td>Software Applications</td><td>1,45,000</td></tr>
<tr><th colspan="2">Total Amount</th><th>' . number_format((float)$doc['amount'] + 265000 + 145000, 0) . '</th></tr>
</table>';

echo '<h3>5. Tension Free AMC</h3>';
echo '<ol>
<li>Yearly AMC Charges - 2,50,000</li>
<li>Subscription Cost yearly 1,25,000 (First year already covered)</li>
<li>Maintenance & Service of HW 1,25,000</li>
<li>Including 4 visits; additional visits 15,000/day</li>
</ol>';
echo '<h3>6. Total Cost</h3>';
echo '<p>Total Cost for the first year including subscription: <strong>Rs.' . number_format((float)$doc['amount'] + 265000 + 145000, 0) . '</strong></p>';
echo '<h3>7. Payment Terms</h3>';
echo '<ol>
<li>Validity: Quotation valid for 30 days.</li>
<li>Taxes: GST extra as applicable.</li>
<li>Delivery: 2 weeks from PO date.</li>
<li>Payment: 50% advance, 50% against delivery.</li>
</ol>';

echo '<p class="small"><strong>Note:</strong> This is the full editable Word version of the SafeHydra document. You can modify any text/content before saving.</p>';
echo '</body></html>';
exit;

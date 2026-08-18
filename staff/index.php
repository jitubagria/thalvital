<?php
require_once __DIR__.'/../includes/layout.php';
$s = require_staff_center();
$org = (int)$s['org_id'];
$like = '%'.($_GET['q'] ?? '').'%';

$q = db()->prepare('SELECT patient_id,full_name,blood_group,next_due FROM patients p LEFT JOIN (SELECT patient_id,MAX(next_due) next_due FROM visits GROUP BY patient_id) v USING(patient_id) WHERE p.patient_id LIKE ? OR p.full_name LIKE ? OR p.mobile LIKE ? LIMIT 12');
$q->execute([$like,$like,$like]);

$due = db()->prepare('SELECT patient_id,full_name,blood_group,next_due FROM patients p JOIN (SELECT v.patient_id,MAX(v.next_due) next_due FROM visits v JOIN blood_centers c ON c.id=v.center_id WHERE c.org_id=? GROUP BY v.patient_id) x USING(patient_id) WHERE next_due<=DATE_ADD(CURDATE(),INTERVAL 7 DAY) ORDER BY next_due');
$due->execute([$org]);
$dueRows = $due->fetchAll();

$stockQ = db()->prepare('SELECT c.name,COUNT(b.id) units FROM blood_centers c LEFT JOIN bags b ON b.center_id=c.id AND b.status="available" AND b.expiry_date>=CURDATE() WHERE c.org_id=? AND c.active=1 GROUP BY c.id ORDER BY c.id');
$stockQ->execute([$org]);
$stock = $stockQ->fetchAll();
$centerCount = count($stock);

$patientCountQ = db()->prepare('SELECT COUNT(*) FROM patients WHERE org_id=?');
$patientCountQ->execute([$org]);
$patientCount = $patientCountQ->fetchColumn();
$visitCountQ = db()->prepare('SELECT COUNT(*) FROM visits v JOIN blood_centers c ON c.id=v.center_id WHERE c.org_id=? AND MONTH(v.visit_date)=MONTH(CURDATE()) AND YEAR(v.visit_date)=YEAR(CURDATE())');
$visitCountQ->execute([$org]);
$visitCount = $visitCountQ->fetchColumn();

staff_start('Dashboard','index.php');
?>
<div class="page-head"><h1>Dashboard</h1><a class="btn" href="/staff/register.php">⊕ New Patient</a></div>
<form><input class="wide-search" name="q" value="<?=h($_GET['q']??'')?>" placeholder="Search by patient ID, name, mobile or Aadhaar last 4…"></form>
<section class="stats">
  <div class="stat"><b><?=$patientCount?></b>Total Patients</div>
  <div class="stat"><b><?=$visitCount?></b>Transfusions this month</div>
  <div class="stat"><b><?=count($dueRows)?></b>Due within 7 days</div>
  <div class="stat"><b><?=$centerCount?></b>Centers</div>
</section>
<section class="grid">
  <article class="card"><h2>Patient search</h2><div class="table-wrap"><table><tr><th>ID</th><th>Patient</th><th>Group</th><th>Next due</th></tr><?php foreach($q as $p):?><tr><td><a href="/staff/patient.php?id=<?=h($p['patient_id'])?>"><?=h($p['patient_id'])?></a></td><td><?=h($p['full_name'])?></td><td><?=h($p['blood_group'])?></td><td><?=h($p['next_due'])?></td></tr><?php endforeach?></table></div></article>
  <article class="card"><h2><?=$centerCount?>-center stock overview</h2><?php foreach($stock as $x):?><div class="result-row"><span><?=h($x['name'])?></span><b><?=h($x['units'])?> available</b></div><?php endforeach?></article>
</section>
<?php staff_end();

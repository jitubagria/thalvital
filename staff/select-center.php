<?php
require_once __DIR__ . '/../includes/layout.php';
$s = require_staff();

if ((int)($s['center_id'] ?? 0) > 0) {
    header('Location: /staff/index.php');
    exit;
}
if (!in_array($s['role'], ['super_admin', 'dept_admin'], true)) {
    http_response_code(403);
    exit('No working center is assigned to this account.');
}

$q = db()->prepare('SELECT id,name FROM blood_centers WHERE active=1 AND (? = 1 OR org_id=?) ORDER BY id');
$q->execute([$s['role'] === 'super_admin' ? 1 : 0, (int)$s['org_id']]);
$availableCenters = $q->fetchAll();
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $selected = (int)($_POST['center_id'] ?? 0);
    $allowed = null;
    foreach ($availableCenters as $center) {
        if ((int)$center['id'] === $selected) { $allowed = $center; break; }
    }
    if (!$allowed) {
        $err = 'Select an authorised working center.';
    } else {
        $_SESSION['staff']['center_id'] = $selected;
        audit('working_center_selected', 'staff', $s['id'], $selected, 'staff', $s['id'], 'center_id', null, $selected);
        header('Location: /staff/index.php');
        exit;
    }
}

head('Select Working Center');
?>
<div class="login card">
  <a class="brand" href="/index.php">Thal<span>Vital</span></a>
  <h2>Select working center</h2>
  <p class="muted">Clinical records created in this login session will be attributed to this center.</p>
  <?php if ($err): ?><p class="alert"><?=h($err)?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?=csrf()?>">
    <div class="field"><label>Working center</label><select name="center_id" required>
      <option value="">Select center</option>
      <?php foreach ($availableCenters as $center): ?><option value="<?=(int)$center['id']?>"><?=h($center['name'])?></option><?php endforeach; ?>
    </select></div>
    <button>Continue</button>
  </form>
</div>
<?php footer();

<?php
require_once __DIR__ . '/../includes/layout.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $last4 = trim($_POST['last4'] ?? '');
    $pin = $_POST['pin'] ?? '';
    $q = db()->prepare('SELECT * FROM patients WHERE aadhaar_last4=? AND active=1');
    $q->execute([$last4]);
    $patients = $q->fetchAll();

    // Last-four identifiers are intentionally low entropy: an ambiguous match
    // must never be resolved by choosing the first patient record.
    if (!portal_login_is_throttled($last4) && count($patients) === 1 && password_verify($pin, $patients[0]['pin_hash'])) {
        portal_login_succeeded($last4);
        $p = $patients[0];
        session_regenerate_id(true);
        $_SESSION['patient'] = $p['patient_id'];
        audit('portal_login', 'patients', $p['patient_id'], null, 'patient', $p['id']);
        header('Location: /portal/index.php');
        exit;
    }
    portal_login_failed($last4);
    $err = 'Invalid Aadhaar last 4 or PIN.';
}
head('Patient Portal');
?><div class="login card"><a class="brand" href="/index.php">Thal<span>Vital</span></a><h2>Patient Portal</h2><?php if($err):?><p class="alert"><?=$err?></p><?php endif?><form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><div class="field"><label>Aadhaar last 4</label><input name="last4" maxlength="4" required></div><div class="field"><label>PIN</label><input type="password" name="pin" required></div><button>Sign in</button></form><p><a href="?lang=en">EN</a> / <a href="?lang=hi">हि</a></p></div><?php footer();

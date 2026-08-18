<?php
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/matching.php';
$s = require_staff_center();

if (isset($_GET['check_aadhaar'])) {
    header('Content-Type: application/json');
    $q = db()->prepare('SELECT id FROM patients WHERE aadhaar_hash=?');
    $q->execute([aadhaar_hash($_GET['check_aadhaar'])]);
    echo json_encode(['exists'=>(bool)$q->fetch()]);
    exit;
}

$err = '';
$selectedCenter = (int)$s['center_id'];
$hid = trim((string)($_POST['hid'] ?? ''));
$selectedRh = trim((string)($_POST['rh_phenotype'] ?? ''));
$rhValues = pheno_from_rh_string($selectedRh);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $aad = preg_replace('/\D/','',$_POST['aadhaar']);
    if (!$selectedCenter) {
        $err = 'Sign in with a working center before registering a patient.';
    } elseif (strlen($hid) > 30) {
        $err = 'HID must be 30 characters or fewer.';
    } elseif ($selectedRh !== '' && !$rhValues) {
        $err = 'Select a valid Rh phenotype.';
    } elseif (strlen($aad) !== 12) {
        $err = 'Enter a valid 12-digit Aadhaar.';
    } else {
        $hash = aadhaar_hash($aad);
        $q = db()->prepare('SELECT id FROM patients WHERE aadhaar_hash=?');
        $q->execute([$hash]);
        if ($q->fetch()) {
            $err = 'Duplicate Aadhaar blocked.';
        } else {
            if ($hid !== '') {
                $q = db()->prepare('SELECT id FROM patients WHERE hid=?');
                $q->execute([$hid]);
                if ($q->fetch()) $err = 'HID already belongs to another patient.';
            }
        }
        if (!$err) {
            db()->beginTransaction();
            try {
                $id = next_patient_id();
                $pin = substr(preg_replace('/\D/','',$_POST['mobile']),-4);
                $q = db()->prepare('INSERT INTO patients(patient_id,hid,aadhaar_hash,aadhaar_last4,full_name,guardian_name,sex,dob,mobile,address,blood_group,diagnosis,pin_hash,org_id,registered_by,registered_center_id,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([$id,$hid?:null,$hash,substr($aad,-4),$_POST['full_name'],$_POST['guardian_name'],$_POST['sex'],$_POST['dob']?:null,$_POST['mobile'],$_POST['address'],$_POST['blood_group'],$_POST['diagnosis'],password_hash($pin,PASSWORD_BCRYPT),$s['org_id'],$s['id'],$selectedCenter,$_POST['notes']]);
                $vals = [
                    $rhValues['antigen_C'] ?? null,
                    $rhValues['antigen_c_lower'] ?? null,
                    $rhValues['antigen_E'] ?? null,
                    $rhValues['antigen_e_lower'] ?? null,
                ];
                $ph = build_phenotype_string(['C'=>$vals[0],'c'=>$vals[1],'E'=>$vals[2],'e'=>$vals[3]], ['C','c','E','e']);
                $q = db()->prepare('INSERT INTO phenotypes(patient_id,antigen_C,antigen_c_lower,antigen_E,antigen_e_lower,phenotype_string,typed_by,typed_at) VALUES(?,?,?,?,?,?,?,NOW())');
                $q->execute(array_merge([$id],$vals,[$ph,$s['id']]));
                audit('create','patients',$id,$selectedCenter);
                audit('create','phenotypes',$id,$selectedCenter);
                db()->commit();
                header('Location: /staff/patient.php?id='.$id);
                exit;
            } catch (Throwable $e) {
                db()->rollBack();
                $err = 'Could not save patient.';
            }
        }
    }
}

staff_start('Register Patient','register.php');
?>
<div class="page-head"><h1>Register patient</h1></div>
<?php if($err):?><p class="alert"><?=h($err)?></p><?php endif?>
<form id="patient-form" method="post" class="card">
  <input type="hidden" name="csrf" value="<?=csrf()?>">
  <h2>Identity & demographics</h2>
  <div class="form-grid">
    <div class="field"><label>Registration center</label><strong><?=h($s['_center_name'])?></strong><p class="muted" style="margin:4px 0 0;font-size:12px">Fixed from your login session</p></div>
    <div class="field"><label>Aadhaar number</label><input id="aadhaar" name="aadhaar" inputmode="numeric" maxlength="12" required><small id="duplicate-msg" class="alert"></small></div>
    <div class="field"><label>Full name</label><input name="full_name" required></div>
    <div class="field"><label>HID (optional)</label><input name="hid" maxlength="30" value="<?=h($_POST['hid']??'')?>"></div>
    <div class="field"><label>Guardian name</label><input name="guardian_name"></div>
    <div class="field"><label>Sex</label><select name="sex"><option>Male</option><option>Female</option><option>Other</option></select></div>
    <div class="field"><label>DOB</label><input type="date" name="dob"></div>
    <div class="field"><label>Mobile</label><input name="mobile" required></div>
    <div class="field"><label>Blood group</label><select name="blood_group"><?php foreach(['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $g):?><option><?=$g?></option><?php endforeach?></select></div>
    <div class="field"><label>Diagnosis</label><select name="diagnosis"><option>Thal Major</option><option>Thal Intermedia</option><option>HbE-Beta</option><option>Sickle</option><option>Other</option></select></div>
  </div>
  <div class="field"><label>Address</label><textarea name="address"></textarea></div>
  <h2>Rh phenotype</h2>
  <p class="muted">Select the complete C/c/E/e profile reported by the laboratory. Kell/Kidd/Duffy antibodies are recorded via serology, not here.</p>
  <div class="field" style="max-width:280px"><label>Rh phenotype</label><select name="rh_phenotype">
    <option value="">Not tested</option>
    <?php foreach(RH_PHENOTYPES as $rh):?><option value="<?=$rh?>"<?=$selectedRh===$rh?' selected':''?>><?=$rh?></option><?php endforeach?>
  </select></div>
  <div class="field"><label>Notes</label><textarea name="notes"></textarea></div>
  <button type="submit">Register patient</button>
</form>
<?php staff_end();

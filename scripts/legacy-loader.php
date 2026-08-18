<?php
// ThalVital legacy patient loader — VALIDATE first, report rejects, then (optionally) insert.
//
//   php scripts/legacy-loader.php <patients.csv> [--commit] [--org=1]
//
// DEFAULT IS DRY RUN: validates every row and prints a rejects report, writing nothing.
// Add --commit to insert valid rows. NEVER coerces: a blank phenotype cell is stored
// NULL ("not tested"), never 0 — the same rule the app enforces.
//
// Exact CSV header (case-sensitive), extra columns ignored:
//   aadhaar,full_name,guardian_name,sex,dob,mobile,blood_group,diagnosis,address,
//   antigen_C,antigen_c_lower,antigen_E,antigen_e_lower,notes
// Phenotype cells: 1 = present, 0 = tested-negative, blank = not tested.

require __DIR__ . '/../includes/functions.php';

$csv = $argv[1] ?? ''; $commit = in_array('--commit', $argv, true); $org = 1;
foreach ($argv as $a) if (preg_match('/^--org=(\d+)$/', $a, $m)) $org = (int)$m[1];
if ($csv === '' || !is_file($csv)) { fwrite(STDERR, "usage: php legacy-loader.php <patients.csv> [--commit] [--org=N]\n"); exit(1); }

$GROUPS = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
$DIAG = ['Thal Major','Thal Intermedia','HbE-Beta','Sickle','Other'];
$SEX = ['Male','Female','Other'];

$fh = fopen($csv, 'r'); $header = fgetcsv($fh);
if (!$header) { fwrite(STDERR, "empty CSV\n"); exit(1); }
$col = array_flip(array_map('trim', $header));
foreach (['aadhaar','full_name','blood_group','diagnosis','mobile'] as $n) if (!isset($col[$n])) { fwrite(STDERR, "missing required column: $n\n"); exit(1); }

$G = fn($row,$name) => isset($col[$name]) ? trim((string)($row[$col[$name]] ?? '')) : '';
$TRI = function($v) { if ($v === '') return [true, null]; if ($v === '0' || $v === '1') return [true, (int)$v]; return [false, null]; };

$rowNo = 0; $valid = []; $rejects = []; $seen = [];
while (($row = fgetcsv($fh)) !== false) {
    if (count(array_filter($row, fn($c)=>trim((string)$c)!=='')) === 0) continue;
    $rowNo++; $errs = [];
    $aadRaw = $G($row,'aadhaar'); $aad = preg_replace('/\D/','',$aadRaw);
    $name = $G($row,'full_name'); $group = $G($row,'blood_group'); $diag = $G($row,'diagnosis');
    $mobile = preg_replace('/\D/','',$G($row,'mobile')); $sex = $G($row,'sex'); $dob = $G($row,'dob');
    if (strlen($aad) !== 12) $errs[] = "aadhaar not 12 digits ('$aadRaw')";
    if ($name === '') $errs[] = "full_name empty";
    if (!in_array($group,$GROUPS,true)) $errs[] = "blood_group '$group' invalid";
    if (!in_array($diag,$DIAG,true)) $errs[] = "diagnosis '$diag' invalid";
    if (strlen($mobile) < 10) $errs[] = "mobile '$mobile' too short";
    if ($sex !== '' && !in_array($sex,$SEX,true)) $errs[] = "sex '$sex' invalid";
    if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dob)) $errs[] = "dob '$dob' not YYYY-MM-DD";
    $ph = [];
    foreach (['antigen_C','antigen_c_lower','antigen_E','antigen_e_lower'] as $pcol) {
        [$okp,$val] = $TRI($G($row,$pcol)); if (!$okp) $errs[] = "$pcol must be 1/0/blank ('".$G($row,$pcol)."')"; $ph[$pcol] = $val;
    }
    $hash = strlen($aad)===12 ? aadhaar_hash($aad) : null;
    if ($hash) { if (isset($seen[$hash])) $errs[] = "duplicate aadhaar within file (row {$seen[$hash]})"; else $seen[$hash] = $rowNo; }
    if ($errs) { $rejects[] = ['row'=>$rowNo,'name'=>$name?:'(no name)','errors'=>$errs]; continue; }
    $valid[] = compact('aad','hash','name','group','diag','mobile','sex','dob') + ['guardian'=>$G($row,'guardian_name'),'address'=>$G($row,'address'),'notes'=>$G($row,'notes'),'ph'=>$ph];
}
fclose($fh);

echo "== ThalVital legacy import ==  file=$csv  mode=".($commit?'COMMIT':'DRY-RUN')."  org=$org\n";
echo "rows read: $rowNo   valid: ".count($valid)."   rejected: ".count($rejects)."\n\n";
if ($rejects) { echo "-- REJECTS (not imported) --\n"; foreach ($rejects as $r) echo "  row {$r['row']} [{$r['name']}]: ".implode('; ',$r['errors'])."\n"; echo "\n"; }
if (!$commit) { echo "DRY RUN — nothing written. ".count($valid)." row(s) would import. Re-run with --commit to write.\n"; exit($rejects ? 3 : 0); }

$pdo = db(); $ins = 0; $skip = 0;
foreach ($valid as $v) {
    $chk = $pdo->prepare('SELECT 1 FROM patients WHERE aadhaar_hash=?'); $chk->execute([$v['hash']]);
    if ($chk->fetchColumn()) { $skip++; echo "  skip (exists): {$v['name']}\n"; continue; }
    $pdo->beginTransaction();
    try {
        $id = next_patient_id(); $pin = substr($v['mobile'], -4);
        $pdo->prepare('INSERT INTO patients(patient_id,aadhaar_hash,aadhaar_last4,full_name,guardian_name,sex,dob,mobile,address,blood_group,diagnosis,pin_hash,org_id,registered_by,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id,$v['hash'],substr($v['aad'],-4),$v['name'],$v['guardian']?:null,$v['sex']?:null,$v['dob']?:null,$v['mobile'],$v['address']?:null,$v['group'],$v['diag'],password_hash($pin,PASSWORD_BCRYPT),$org,null,$v['notes']?:null]);
        $str = build_phenotype_string(['C'=>$v['ph']['antigen_C'],'c'=>$v['ph']['antigen_c_lower'],'E'=>$v['ph']['antigen_E'],'e'=>$v['ph']['antigen_e_lower']],['C','c','E','e']);
        $pdo->prepare('INSERT INTO phenotypes(patient_id,antigen_C,antigen_c_lower,antigen_E,antigen_e_lower,phenotype_string,typed_at) VALUES(?,?,?,?,?,?,NOW())')
            ->execute([$id,$v['ph']['antigen_C'],$v['ph']['antigen_c_lower'],$v['ph']['antigen_E'],$v['ph']['antigen_e_lower'],$str]);
        $pdo->prepare('INSERT INTO audit_log(actor_type,actor_id,action,target_table,target_id,center_id,ip) VALUES(?,?,?,?,?,?,?)')->execute(['staff',null,'legacy_import','patients',$id,null,'cli']);
        $pdo->commit(); $ins++; echo "  imported {$id}: {$v['name']} ({$v['group']})\n";
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $skip++; echo "  FAILED {$v['name']}: {$e->getMessage()}\n"; }
}
echo "\ncommitted: $ins inserted, $skip skipped.\n";

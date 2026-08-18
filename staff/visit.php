<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/matching.php';
$s = require_staff_center();
$p = passport($_REQUEST['patient'] ?? '');
$err = '';
// Gate-display state — set on a POST that fails a gate so the form re-renders the right sections.
$needC2 = false; $needUnverif = false; $needProph = false;
$blockList = []; $prophList = []; $unverifNames = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $p = passport($_POST['patient'] ?? '');
    $result = $_POST['crossmatch_result'] ?? 'Compatible';
    $center = (int)$s['center_id'];
    if (!$p || !patient_access($s, $p)) $err = 'Patient unavailable.';
    elseif (!$center) $err = 'Sign in with a working center before issuing stock.';
    else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $postedHid = trim((string)($_POST['hid'] ?? ''));
            if (strlen($postedHid) > 30) throw new RuntimeException('HID must be 30 characters or fewer.');
            $hid = $postedHid !== '' ? $postedHid : (trim((string)($p['hid'] ?? '')) ?: null);
            $beforeHid = trim((string)($p['hid'] ?? '')) ?: null;
            if ($hid !== null && $hid !== $beforeHid) {
                $hq = $pdo->prepare('SELECT patient_id FROM patients WHERE hid=? AND patient_id<>? LIMIT 1 FOR UPDATE');
                $hq->execute([$hid, $p['patient_id']]);
                if ($hq->fetch()) throw new RuntimeException('HID already belongs to another patient.');
                $pdo->prepare('UPDATE patients SET hid=? WHERE patient_id=?')->execute([$hid, $p['patient_id']]);
                audit('update', 'patients', $p['patient_id'], $center, 'staff', null, 'hid', $beforeHid, $hid);
            }
            $q = $pdo->prepare('INSERT INTO visits(hid,patient_id,center_id,visit_date,weight_kg,target_hb,pre_hb,post_hb,units_calculated,units_given,product,next_due,reaction,reaction_notes,recorded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $q->execute([$hid,$p['patient_id'],$center,$_POST['visit_date'],$_POST['weight'],$_POST['target_hb'],$_POST['pre_hb'],$_POST['post_hb'],$_POST['units_calculated'],$_POST['units_given'],'PRC',$_POST['next_due'],$_POST['reaction'],$_POST['reaction_notes'],$s['id']]);
            $visitId = (int)$pdo->lastInsertId(); // §4: captured inline off held $pdo, no query between the INSERT and here
            $bags = array_filter(array_map('trim', explode(',', $_POST['bag_numbers'] ?? '')));
            // ---- PHASE 1: lock each bag, evaluate the matcher ----
            $rows = []; $anyHardBlock = false;
            foreach ($bags as $number) {
                $bq = $pdo->prepare('SELECT * FROM bags WHERE bag_number=? AND center_id=? AND year=? AND status="available" FOR UPDATE');
                $bq->execute([$number, $center, date('Y')]);
                $bag = $bq->fetch();
                if (!$bag) throw new RuntimeException('Bag ' . $number . ' is not available in this center.');
                $m = match_bag_for_patient($p['antibodies'], $p['phenotype'], $bag);
                $rows[] = ['bag' => $bag, 'm' => $m];
                if ($m['hard_block']) { $anyHardBlock = true; foreach ($m['blocking'] as $bl) $blockList[] = $bag['bag_number'] . ' → ' . $bl['reason']; }
                foreach ($m['unverifiable'] as $u) $unverifNames[$u] = true;
                if (!$m['hard_block']) foreach (array_merge($m['proph_mismatch'], $m['proph_warn']) as $pr) $prophList[] = $bag['bag_number'] . ' → ' . $pr['reason']; // hard-blocked units go via C2; prophylactic is moot for them
            }
            // ---- PHASE 2: tiered gates (evaluate all three so every needed field shows at once) ----
            $needC2      = ($result !== 'Compatible') || $anyHardBlock;         // red: antibody hard block or serologic-incompatible
            $needUnverif = !empty($unverifNames);                               // grey: unverifiable antibody -> doctor acknowledgement
            $needProph   = !empty($prophList);                                  // amber: prophylactic mismatch -> acknowledge
            $doctor      = trim($_POST['authorized_by'] ?? '');
            $c2ok        = !$needC2 || ($doctor !== '' && trim($_POST['clinical_justification'] ?? '') !== '' && !empty($_POST['consent_taken']));
            $unverifOk   = !$needUnverif || ($doctor !== '' && !empty($_POST['ack_unverifiable']));
            $prophOk     = !$needProph || !empty($_POST['ack_prophylactic']);
            if (!($c2ok && $unverifOk && $prophOk)) {
                $msgs = [];
                if (!$c2ok)      $msgs[] = ($anyHardBlock ? 'Antigen-incompatible unit(s) — ' . implode('; ', $blockList) . '. ' : '') . 'Issue requires the authorizing doctor, clinical justification, and documented consent.';
                if (!$unverifOk) $msgs[] = 'Patient carries ' . implode(', ', array_keys($unverifNames)) . ', which cannot be verified against stock; bench crossmatch is the safeguard. A named doctor must acknowledge before issue.';
                if (!$prophOk)   $msgs[] = 'Prophylactic Rh mismatch — ' . implode('; ', $prophList) . '. Acknowledge to proceed.';
                $err = implode(' ', $msgs);
                $pdo->rollBack();
            } else {
                // ---- PHASE 3: commit ----
                $cj      = $needC2 ? ($_POST['clinical_justification'] ?: null) : null;
                $consent = $needC2 && !empty($_POST['consent_taken']) ? 1 : 0;
                $authBy  = ($needC2 || $needUnverif) ? ($doctor ?: null) : null;
                $unverifNote = $needUnverif ? ('unverifiable ' . implode(', ', array_keys($unverifNames)) . ' — units not typed (bench crossmatch safeguard)') : '';
                foreach ($rows as $r) {
                    $bag = $r['bag']; $m = $r['m'];
                    // antigen_override is SYSTEM-GENERATED from matcher output + recorded antibody names — never free-typed.
                    $parts = [];
                    if ($m['hard_block']) $parts[] = implode('; ', array_column($m['blocking'], 'reason'));
                    if ($unverifNote !== '') $parts[] = $unverifNote;
                    $override = $parts ? implode(' · ', $parts) : null;
                    $iq = $pdo->prepare('INSERT INTO crossmatches(visit_id,bag_id,technique,result,consent_taken,authorized_by,clinical_justification,antigen_override,performed_by) VALUES(?,?,?,?,?,?,?,?,?)');
                    $iq->execute([$visitId, $bag['id'], $_POST['technique'], $result, $consent, $authBy, $cj, $override, $s['id']]);
                    $pdo->prepare('UPDATE bags SET status="issued",issued_to_visit=?,issued_date=CURDATE() WHERE id=?')->execute([$visitId, $bag['id']]);
                    audit('issue_bag', 'bags', $bag['id'], $center);
                    if (!$m['hard_block'] && (!empty($m['proph_mismatch']) || !empty($m['proph_warn']))) audit('prophylactic_mismatch', 'bags', $bag['id'], $center);
                }
                audit(($anyHardBlock || $result !== 'Compatible') ? 'override_issue' : 'create', 'visits', $visitId, $center);
                if ($needUnverif) audit('unverifiable_ack', 'visits', $visitId, $center);
                $pdo->commit();
                header('Location: /staff/patient.php?id=' . $p['patient_id']); exit;
            }
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $err = $e->getMessage(); }
    }
}
$V = fn($k, $d = '') => h($_POST[$k] ?? $d); // preserve entered values across a gate re-render
staff_start('Log Transfusion', 'visit.php');
?><div class="page-head"><h1>Log transfusion</h1></div><?php if($err):?><p class="alert"><?=h($err)?></p><?php endif; if($p) passport_card($p,true); ?><form method="post" class="card"><input type="hidden" name="csrf" value="<?=csrf()?>"><div class="form-grid"><div class="field"><label>Patient ID</label><input name="patient" value="<?=h($p['patient_id']??'')?>" required></div><div class="field"><label>Center</label><div class="fixed-value"><?=h($s['_center_name'])?></div><small>Fixed from your login session</small></div><div class="field"><label>HID</label><input name="hid" maxlength="30" value="<?=$V('hid',$p['hid']??'')?>"><small>Saved on the patient record; enter or update it here.</small></div><div class="field"><label>Visit date</label><input type="date" name="visit_date" value="<?=$V('visit_date',date('Y-m-d'))?>"></div><div class="field"><label>Weight kg</label><input id="weight" name="weight" type="number" step=".1" value="<?=$V('weight')?>"></div><div class="field"><label>Target Hb</label><input id="target_hb" name="target_hb" value="<?=$V('target_hb','10')?>" type="number" step=".1"></div><div class="field"><label>Pre Hb</label><input id="pre_hb" name="pre_hb" type="number" step=".1" value="<?=$V('pre_hb')?>"></div><div class="field"><label>Suggested units</label><input id="suggested-units" name="units_calculated" value="<?=$V('units_calculated')?>" readonly></div><div class="field"><label>Units given</label><input name="units_given" type="number" value="<?=$V('units_given','1')?>"></div><div class="field"><label>Post Hb</label><input name="post_hb" type="number" step=".1" value="<?=$V('post_hb')?>"></div><div class="field"><label>Next due</label><input name="next_due" type="date" value="<?=$V('next_due')?>"></div><div class="field"><label>Bag number(s), comma-separated</label><input name="bag_numbers" value="<?=$V('bag_numbers')?>" required></div></div><h2>Crossmatch</h2><div class="form-grid"><div class="field"><label>Technique</label><select name="technique"><?php foreach(['IS','AHG','Enzyme','Electronic'] as $o):?><option <?=(($_POST['technique']??'')===$o)?'selected':''?>><?=$o?></option><?php endforeach?></select></div><div class="field"><label>Result (serologic)</label><select id="crossmatch-result" name="crossmatch_result"><?php foreach(['Compatible','Incompatible','Least Incompatible'] as $o):?><option <?=(($_POST['crossmatch_result']??'')===$o)?'selected':''?>><?=$o?></option><?php endforeach?></select></div><div class="field"><label>Reaction</label><select name="reaction"><?php foreach(['None','Mild','Moderate','Severe'] as $o):?><option <?=(($_POST['reaction']??'')===$o)?'selected':''?>><?=$o?></option><?php endforeach?></select></div></div><div id="auth-doctor" class="<?=($needC2||$needUnverif)?'':'hidden'?>"><div class="field"><label>Authorizing doctor</label><input name="authorized_by" value="<?=$V('authorized_by')?>"></div></div><div id="consent-fields" class="<?=$needC2?'':'hidden'?>"><div class="field"><label>Clinical justification</label><textarea name="clinical_justification"><?=$V('clinical_justification')?></textarea></div><label><input type="checkbox" name="consent_taken" value="1" <?=!empty($_POST['consent_taken'])?'checked':''?>> Hard-copy consent filed at center</label></div><?php if($needUnverif):?><div class="notice">⛔ Patient carries <b><?=h(implode(', ',array_keys($unverifNames)))?></b> — stock is <b>not typed</b> for this system; the system cannot clear these units. Bench crossmatch is the safeguard.<br><label><input type="checkbox" name="ack_unverifiable" value="1" <?=!empty($_POST['ack_unverifiable'])?'checked':''?>> The authorizing doctor named above acknowledges this and confirms bench crossmatch.</label></div><?php endif; if($needProph):?><div class="notice">⚠ Prophylactic Rh mismatch — <?=h(implode('; ',$prophList))?>. Not a hard incompatibility; prioritise a better-matched unit if available.<br><label><input type="checkbox" name="ack_prophylactic" value="1" <?=!empty($_POST['ack_prophylactic'])?'checked':''?>> Acknowledge and proceed.</label></div><?php endif;?><div class="field"><label>Reaction notes</label><textarea name="reaction_notes"><?=$V('reaction_notes')?></textarea></div><button>Save visit</button></form><?php staff_end();

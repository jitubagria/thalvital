<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/matching.php';
$s = require_staff_center();
$p = passport($_REQUEST['patient'] ?? '');
if (!$p || !patient_access($s, $p)) { http_response_code(404); exit('Patient not found'); }
$pid = $p['patient_id'];

// ---- small typed-value helpers (NULL discipline: blank => NULL "not done", never coerced to 0) ----
$GRADES = ['', '0', '1+', '2+', '3+', '4+'];              // '' = not done; '0' is a real (negative) reaction grade
$ABO    = ['', 'A', 'B', 'AB', 'O'];
$GROUPS = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
$gv = fn($k) => (($v = $_POST[$k] ?? '') === '') ? null : $v;                 // grade/text: keep string, blank => NULL
$tv = fn($k) => (trim((string)($_POST[$k] ?? '')) === '') ? null : trim($_POST[$k]);
$yn = fn($k) => (($v = $_POST[$k] ?? '') === '') ? null : (int)$v;           // yes/no/blank => 1/0/NULL
$V  = fn($k, $d = '') => h($_POST[$k] ?? $d);

$err = ''; $ok = $_GET['ok'] ?? ''; $anchorPrompt = null;
$PHMAP = ['C'=>'antigen_C','c'=>'antigen_c_lower','E'=>'antigen_E','e'=>'antigen_e_lower'];
$sign  = fn($v) => $v === null ? 'not tested' : ($v ? '+' : '-');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    $center = (int)$s['center_id'];
    if (!$center) {
        $err = 'Sign in with a working center before recording clinical data.';
    } else try {
        $pdo = db();

        // ---------- PRIMARY: phenotype anchor (per-antigen: fill blank / confirm same / conflict on differ) ----------
        if ($action === 'phenotype') {
            $anchor = $p['phenotype'] ?: [];
            $changes = []; $conflicts = [];
            $selectedRh = trim((string)($_POST['rh_phenotype'] ?? ''));
            $enteredRh = pheno_from_rh_string($selectedRh);
            if (!$enteredRh) {
                $err = 'Select a valid complete Rh phenotype.';
            }
            foreach ($PHMAP as $lbl => $col) {
                if (!$enteredRh) break;
                $entered = $enteredRh[$col];
                $current = array_key_exists($col, $anchor) ? ag_state($anchor[$col]) : null;
                if ($current === null) { $changes[$col] = [$lbl, null, $entered]; continue; }   // fill blank anchor
                if ($entered === $current) continue;             // same -> confirm / no-op
                $conflicts[$col] = [$lbl, $current, $entered];   // differing non-null -> conflict
            }
            if ($err !== '') {
                // Validation error already set; do not write or replace the phenotype anchor.
            } elseif ($conflicts && empty($_POST['ack_pheno'])) {
                $parts = array_map(fn($x) => $x[0] . ': recorded ' . $sign($x[1]) . ', you entered ' . $sign($x[2]), $conflicts);
                $err = 'Phenotype conflict — ' . implode('; ', $parts) . '. The existing typing is preserved. Tick "acknowledge" to record the new value; both are kept in the audit trail.';
                $GLOBALS['_ph_conflict'] = $conflicts;           // re-render marker
            } elseif (!$changes && !$conflicts) {
                $err = 'No phenotype changes to record.';
            } else {
                $apply = $changes + ($conflicts ?: []);          // acknowledged conflicts included; each value = [label, before, after]
                $final = [];
                foreach ($PHMAP as $lbl => $col) {
                    $final[$col] = isset($apply[$col]) ? $apply[$col][2] : (array_key_exists($col, $anchor) ? ag_state($anchor[$col]) : null);
                }
                $str = build_phenotype_string(['C'=>$final['antigen_C'],'c'=>$final['antigen_c_lower'],'E'=>$final['antigen_E'],'e'=>$final['antigen_e_lower']], ['C','c','E','e']);
                $pdo->beginTransaction();
                if ($p['phenotype']) {
                    $pdo->prepare('UPDATE phenotypes SET antigen_C=?,antigen_c_lower=?,antigen_E=?,antigen_e_lower=?,phenotype_string=?,typed_by=?,typed_at=NOW() WHERE patient_id=?')
                        ->execute([$final['antigen_C'],$final['antigen_c_lower'],$final['antigen_E'],$final['antigen_e_lower'],$str,$s['id'],$pid]);
                } else {
                    $pdo->prepare('INSERT INTO phenotypes(patient_id,antigen_C,antigen_c_lower,antigen_E,antigen_e_lower,phenotype_string,typed_by,typed_at) VALUES(?,?,?,?,?,?,?,NOW())')
                        ->execute([$pid,$final['antigen_C'],$final['antigen_c_lower'],$final['antigen_E'],$final['antigen_e_lower'],$str,$s['id']]);
                }
                foreach ($apply as $col => [$lbl, $before, $after]) {
                    $act = isset($conflicts[$col]) ? 'phenotype_conflict' : 'phenotype_change';
                    audit($act, 'phenotypes', $pid, $center, 'staff', null, $col, $sign($before), $sign($after));
                }
                $pdo->commit();
                header('Location: /staff/clinical-entry.php?patient=' . urlencode($pid) . '&ok=phenotype'); exit;
            }
        }

        // ---------- ADVANCED ①: tube/card grouping (append-only dated event) ----------
        elseif ($action === 'grouping') {
            $method = in_array($_POST['method'] ?? '', ['tube','gel','automated'], true) ? $_POST['method'] : 'tube';
            $final_abo_rh = $tv('final_abo_rh');
            $concordant   = $yn('concordant');
            $pdate = $tv('performed_date') ?: date('Y-m-d');
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO blood_groupings(patient_id,center_id,method,anti_A,anti_B,anti_AB,anti_D_IgM,anti_D_IgG,anti_A1,anti_H,rh_control,cell_A1,cell_A2,cell_B,cell_O,forward_group,reverse_group,concordant,discordance_note,weak_D_tested,weak_D_result,final_abo_rh,recent_transfusion_3mo,performed_by,performed_date) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$pid,$center,$method,$gv('anti_A'),$gv('anti_B'),$gv('anti_AB'),$gv('anti_D_IgM'),$gv('anti_D_IgG'),$gv('anti_A1'),$gv('anti_H'),$gv('rh_control'),$gv('cell_A1'),$gv('cell_A2'),$gv('cell_B'),$gv('cell_O'),$tv('forward_group'),$tv('reverse_group'),$concordant,$tv('discordance_note'),$yn('weak_D_tested'),$tv('weak_D_result'),$final_abo_rh,$yn('recent_transfusion_3mo'),$s['id'],$pdate]);
            $gid = (int)$pdo->lastInsertId();
            audit('create', 'blood_groupings', $gid, $center);
            $pdo->commit();
            // Concordant re-test whose final group differs from the current anchor -> prompt to update (never auto).
            $q = ($concordant === 1 && $final_abo_rh && $final_abo_rh !== $p['blood_group']) ? '&anchor=' . $gid : '';
            header('Location: /staff/clinical-entry.php?patient=' . urlencode($pid) . '&ok=grouping' . $q); exit;
        }

        // ---------- ADVANCED: anchor update (only via a concordant tube grouping, audited before->after) ----------
        elseif ($action === 'anchor_update') {
            $new = $_POST['new_group'] ?? ''; $src = (int)($_POST['grouping_id'] ?? 0);
            if (!in_array($new, $GROUPS, true)) { $err = 'Invalid blood group.'; }
            else {
                // Re-verify the source grouping is a concordant row for this patient with this final group (server-authoritative).
                $g = $pdo->prepare('SELECT concordant,final_abo_rh FROM blood_groupings WHERE id=? AND patient_id=?');
                $g->execute([$src, $pid]); $row = $g->fetch();
                if (!$row || (int)$row['concordant'] !== 1 || $row['final_abo_rh'] !== $new) {
                    $err = 'Blood group change must be backed by a concordant tube grouping with a matching final group.';
                } else {
                    $before = $p['blood_group'];
                    if ($new !== $before) {
                        $pdo->beginTransaction();
                        $pdo->prepare('UPDATE patients SET blood_group=? WHERE patient_id=?')->execute([$new, $pid]);
                        audit('blood_group_change', 'patients', $pid, $center, 'staff', null, 'blood_group', $before, $new);
                        $pdo->commit();
                    }
                    header('Location: /staff/clinical-entry.php?patient=' . urlencode($pid) . '&ok=group_changed'); exit;
                }
            }
        }

        // ---------- ADVANCED ②: serology (append-only dated event) ----------
        elseif ($action === 'serology') {
            $pdate = $tv('performed_date') ?: date('Y-m-d');
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO serology_workups(patient_id,center_id,dct_done,dct_result,dct_grade,ict_done,ict_result,ict_grade,three_cell_done,three_cell_result,eleven_cell_done,eleven_cell_interpretation,performed_by,performed_date) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$pid,$center,$yn('dct_done'),$tv('dct_result'),$gv('dct_grade'),$yn('ict_done'),$tv('ict_result'),$gv('ict_grade'),$yn('three_cell_done'),$tv('three_cell_result'),$yn('eleven_cell_done'),$tv('eleven_cell_interpretation'),$s['id'],$pdate]);
            $sid = (int)$pdo->lastInsertId();
            audit('create', 'serology_workups', $sid, $center);
            $pdo->commit();
            header('Location: /staff/clinical-entry.php?patient=' . urlencode($pid) . '&ok=serology'); exit;
        }

        // ---------- ADVANCED ③: antibody (ADD-ONLY — no edit/delete path is ever built) ----------
        elseif ($action === 'antibody') {
            $name = trim($_POST['antibody'] ?? '');
            if ($name === '') { $err = 'Antibody name is required.'; }
            else {
                $dup = false;
                foreach ($p['antibodies'] as $ab) { if (strcasecmp($ab['antibody'] ?? '', $name) === 0) { $dup = true; break; } }
                if ($dup && empty($_POST['ack_dup'])) {
                    $err = h($name) . ' is already on record for this patient. Antibodies are never removed; tick "acknowledge" to add another dated record of it.';
                    $GLOBALS['_ab_dup'] = true;
                } else {
                    // default system/significance from the known-antibody catalogue when not supplied
                    $k = $pdo->prepare('SELECT `system`,clinical_significance FROM known_antibodies WHERE name=?'); $k->execute([$name]); $known = $k->fetch() ?: [];
                    $system = $tv('system') ?? ($known['system'] ?? null);
                    $sig = in_array($_POST['clinical_significance'] ?? '', ['High','Moderate','Low','Unknown'], true) ? $_POST['clinical_significance'] : ($known['clinical_significance'] ?? 'High');
                    $pdate = $tv('detected_date') ?: date('Y-m-d');
                    $pdo->beginTransaction();
                    $pdo->prepare('INSERT INTO alloantibodies(patient_id,antibody,`system`,clinical_significance,titer,detected_at_center,detected_date,how_found,recorded_by) VALUES(?,?,?,?,?,?,?,?,?)')
                        ->execute([$pid,$name,$system,$sig,$tv('titer'),$center,$pdate,$tv('how_found'),$s['id']]);
                    $abId = (int)$pdo->lastInsertId();
                    audit('antibody_add', 'alloantibodies', $abId, $center, 'staff', null, 'antibody', null, $name);
                    $pdo->commit();
                    header('Location: /staff/clinical-entry.php?patient=' . urlencode($pid) . '&ok=antibody'); exit;
                }
            }
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $err = 'Could not save: ' . $e->getMessage();
    }
    // reload passport so the re-render reflects any partial state
    $p = passport($pid);
}

// ---- data for display ----
$anchorPromptGid = (int)($_GET['anchor'] ?? 0);
if ($anchorPromptGid) {
    $g = db()->prepare('SELECT id,final_abo_rh,concordant FROM blood_groupings WHERE id=? AND patient_id=?');
    $g->execute([$anchorPromptGid, $pid]); $row = $g->fetch();
    if ($row && (int)$row['concordant'] === 1 && $row['final_abo_rh'] && $row['final_abo_rh'] !== $p['blood_group']) {
        $anchorPrompt = ['from' => $p['blood_group'], 'to' => $row['final_abo_rh'], 'gid' => (int)$row['id']];
    }
}
$q = db()->prepare('SELECT g.*,c.name center FROM blood_groupings g JOIN blood_centers c ON c.id=g.center_id WHERE g.patient_id=? ORDER BY g.performed_date DESC, g.id DESC');
$q->execute([$pid]); $groupings = $q->fetchAll();
$q = db()->prepare('SELECT w.*,c.name center FROM serology_workups w JOIN blood_centers c ON c.id=w.center_id WHERE w.patient_id=? ORDER BY w.performed_date DESC, w.id DESC');
$q->execute([$pid]); $serologies = $q->fetchAll();
$q = db()->prepare('SELECT a.*,c.name center FROM alloantibodies a LEFT JOIN blood_centers c ON c.id=a.detected_at_center WHERE a.patient_id=? ORDER BY a.detected_date DESC, a.id DESC');
$q->execute([$pid]); $antibodies = $q->fetchAll();
$knownList = db()->query('SELECT name FROM known_antibodies ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$phConflict = $GLOBALS['_ph_conflict'] ?? [];
$abDup = !empty($GLOBALS['_ab_dup']);
$hasAntibodies = !empty($antibodies);
$anchor = $p['phenotype'] ?: [];
$selectedRh = (($_POST['action'] ?? '') === 'phenotype')
    ? trim((string)($_POST['rh_phenotype'] ?? ''))
    : rh_string_from_pheno($anchor);
$centerLabel = '<div class="field" style="max-width:280px"><label>' . t('center') . '</label><strong>' . h($s['_center_name']) . '</strong><p class="muted" style="margin:4px 0 0;font-size:12px">Fixed from your login session</p></div>';
$gradeSel = function($name, $val = '') use ($GRADES) { $o = ''; foreach ($GRADES as $g) { $lbl = $g === '' ? t('not_done') : $g; $o .= '<option value="' . h($g) . '"' . ((string)$val === (string)$g ? ' selected' : '') . '>' . h($lbl) . '</option>'; } return '<select name="' . h($name) . '">' . $o . '</select>'; };
$ynSel = function($name) { $v = $_POST[$name] ?? ''; $opt = ['' => t('not_done'), '1' => t('yes'), '0' => t('no')]; $o = ''; foreach ($opt as $k => $l) { $o .= '<option value="' . $k . '"' . ((string)$v === (string)$k ? ' selected' : '') . '>' . h($l) . '</option>'; } return '<select name="' . h($name) . '">' . $o . '</select>'; };

staff_start('Clinical Entry', 'clinical-entry.php');
?><div class="page-head"><h1><?=t('clinical_entry')?></h1><a class="btn outline" href="/staff/patient.php?id=<?=h($pid)?>">← <?=t('back_to_passport')?></a></div>
<?php if ($err): ?><p class="alert"><?=$err?></p><?php endif;
if ($ok === 'phenotype') echo '<p class="notice">'.t('saved_phenotype').'</p>';
if ($ok === 'grouping') echo '<p class="notice">'.t('saved_grouping').'</p>';
if ($ok === 'serology') echo '<p class="notice">'.t('saved_serology').'</p>';
if ($ok === 'antibody') echo '<p class="notice">'.t('saved_antibody').'</p>';
if ($ok === 'group_changed') echo '<p class="notice">'.t('saved_group_changed').'</p>';
passport_card($p);

if ($anchorPrompt): ?>
<section class="card" style="border-left:4px solid var(--accent)"><h2><?=t('bg_change_prompt')?></h2>
  <p><?=t('bg_change_body')?> <b><?=h($anchorPrompt['from'])?></b> → <b><?=h($anchorPrompt['to'])?></b>.</p>
  <form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="anchor_update">
    <input type="hidden" name="new_group" value="<?=h($anchorPrompt['to'])?>"><input type="hidden" name="grouping_id" value="<?=$anchorPrompt['gid']?>">
    <button><?=t('bg_change_confirm')?> <?=h($anchorPrompt['to'])?></button>
    <a class="btn outline" href="/staff/clinical-entry.php?patient=<?=h($pid)?>" style="margin-left:8px"><?=t('cancel')?></a>
  </form></section>
<?php endif; ?>

<!-- ============ PRIMARY: current-state anchors ============ -->
<section class="card"><h2><?=t('primary_anchors')?></h2>
  <div class="grid">
    <div>
      <label><?=t('blood_group')?> (<?=t('slide_current')?>)</label>
      <p style="font:900 34px Georgia,serif;margin:2px 0"><?=h($p['blood_group'])?: '—'?></p>
      <p class="muted"><?=t('bg_readonly_hint')?></p>
    </div>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="phenotype">
      <label><?=t('phenotype')?> — Rh C/c/E/e</label>
      <p class="muted">Select the complete profile reported by the laboratory—the same nine choices used by public availability search.</p>
      <div class="field" style="max-width:280px"><select name="rh_phenotype"<?=$phConflict?' style="border-color:var(--accent)"':''?>>
        <option value="">Select Rh phenotype</option>
        <?php foreach (RH_PHENOTYPES as $rh): ?><option value="<?=$rh?>"<?=$selectedRh===$rh?' selected':''?>><?=$rh?></option><?php endforeach; ?>
      </select></div>
      <?=$centerLabel?>
      <?php if ($phConflict): ?><label class="notice" style="display:block"><input type="checkbox" name="ack_pheno" value="1"> <?=t('ack_pheno')?></label><?php endif; ?>
      <button><?=t('save_phenotype')?></button>
      <p class="muted" style="font-size:12px"><?=t('pheno_rule_hint')?></p>
    </form>
  </div>
</section>

<!-- ============ ADVANCED: tube/card workup ============ -->
<details class="card"<?=$hasAntibodies||$phConflict||$abDup?' open':''?>>
  <summary style="cursor:pointer;font-weight:700;color:var(--navy)"><?=t('advanced_workup')?>
    <?php if ($hasAntibodies): ?><span class="badge b-block">⚠ <?php foreach ($antibodies as $i=>$a) echo ($i?' · ':'').h($a['antibody']); ?> <?=t('on_record')?></span><?php endif; ?>
  </summary>
  <div style="margin-top:16px">

  <!-- ① TUBE/CARD GROUPING -->
  <section class="card"><h2>① <?=t('tube_grouping')?></h2>
    <form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="grouping">
      <div class="form-grid">
        <?=$centerLabel?>
        <div class="field"><label><?=t('method')?></label><select name="method"><?php foreach (['tube'=>'Tube','gel'=>'Gel / Card (CAT)','automated'=>'Automated'] as $k=>$l): ?><option value="<?=$k?>"<?=(($_POST['method']??'')===$k?' selected':'')?>><?=$l?></option><?php endforeach?></select></div>
        <div class="field"><label><?=t('date')?></label><input type="date" name="performed_date" value="<?=$V('performed_date',date('Y-m-d'))?>"></div>
      </div>
      <p class="muted"><b><?=t('forward')?></b> — <?=t('grade_hint')?></p>
      <div class="antigen-grid" style="grid-template-columns:repeat(8,1fr)">
        <?php foreach (['anti_A'=>'Anti-A','anti_B'=>'Anti-B','anti_AB'=>'Anti-A,B','anti_D_IgM'=>'Anti-D IgM','anti_D_IgG'=>'Anti-D IgG','anti_A1'=>'Anti-A1','anti_H'=>'Anti-H','rh_control'=>'Rh control'] as $k=>$l): ?>
          <div><label><?=$l?></label><?=$gradeSel($k, $_POST[$k] ?? '')?></div>
        <?php endforeach; ?>
      </div>
      <p class="muted"><b><?=t('reverse')?></b> — <?=t('grade_hint')?></p>
      <div class="antigen-grid" style="grid-template-columns:repeat(4,1fr)">
        <?php foreach (['cell_A1'=>'A1 cell','cell_A2'=>'A2 cell','cell_B'=>'B cell','cell_O'=>'O cell'] as $k=>$l): ?>
          <div><label><?=$l?></label><?=$gradeSel($k, $_POST[$k] ?? '')?></div>
        <?php endforeach; ?>
      </div>
      <div class="form-grid">
        <div class="field"><label><?=t('forward_group')?></label><select name="forward_group"><?php foreach ($ABO as $g): ?><option<?=(($_POST['forward_group']??'')===$g?' selected':'')?>><?=$g?></option><?php endforeach?></select></div>
        <div class="field"><label><?=t('reverse_group')?></label><select name="reverse_group"><?php foreach ($ABO as $g): ?><option<?=(($_POST['reverse_group']??'')===$g?' selected':'')?>><?=$g?></option><?php endforeach?></select></div>
        <div class="field"><label><?=t('concordant')?></label><?=$ynSel('concordant')?></div>
        <div class="field"><label><?=t('weak_d_tested')?></label><?=$ynSel('weak_D_tested')?></div>
        <div class="field"><label><?=t('weak_d_result')?></label><input name="weak_D_result" value="<?=$V('weak_D_result')?>"></div>
        <div class="field"><label><?=t('recent_tx')?></label><?=$ynSel('recent_transfusion_3mo')?></div>
        <div class="field"><label><?=t('final_group')?></label><select name="final_abo_rh"><option value=""><?=t('not_tested')?></option><?php foreach ($GROUPS as $g): ?><option<?=(($_POST['final_abo_rh']??'')===$g?' selected':'')?>><?=$g?></option><?php endforeach?></select></div>
      </div>
      <div class="field"><label><?=t('discordance_note')?></label><textarea name="discordance_note"><?=$V('discordance_note')?></textarea></div>
      <button><?=t('save_grouping')?></button>
      <p class="muted" style="font-size:12px"><?=t('grouping_rule_hint')?></p>
    </form>
    <?php if ($groupings): ?><div class="table-wrap"><table><tr><th><?=t('date')?></th><th><?=t('center')?></th><th><?=t('method')?></th><th><?=t('final_group')?></th><th><?=t('concordant')?></th></tr>
      <?php foreach ($groupings as $g): ?><tr><td><?=h($g['performed_date'])?></td><td><?=h($g['center'])?></td><td><?=h($g['method'])?></td><td><?=h($g['final_abo_rh']?:'—')?></td><td><?=$g['concordant']===null?'—':($g['concordant']?'✓':'<span class="badge b-block">discordant</span>')?></td></tr><?php endforeach?></table></div><?php endif; ?>
  </section>

  <!-- ② SEROLOGY -->
  <section class="card"><h2>② <?=t('serology')?></h2>
    <form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="serology">
      <div class="form-grid">
        <?=$centerLabel?>
        <div class="field"><label><?=t('date')?></label><input type="date" name="performed_date" value="<?=$V('performed_date',date('Y-m-d'))?>"></div>
        <div></div>
        <div class="field"><label>DCT <?=t('done')?></label><?=$ynSel('dct_done')?></div>
        <div class="field"><label>DCT <?=t('result')?></label><input name="dct_result" value="<?=$V('dct_result')?>"></div>
        <div class="field"><label>DCT <?=t('grade')?></label><?=$gradeSel('dct_grade', $_POST['dct_grade'] ?? '')?></div>
        <div class="field"><label>ICT <?=t('done')?></label><?=$ynSel('ict_done')?></div>
        <div class="field"><label>ICT <?=t('result')?></label><input name="ict_result" value="<?=$V('ict_result')?>"></div>
        <div class="field"><label>ICT <?=t('grade')?></label><?=$gradeSel('ict_grade', $_POST['ict_grade'] ?? '')?></div>
        <div class="field"><label><?=t('three_cell')?> <?=t('done')?></label><?=$ynSel('three_cell_done')?></div>
        <div class="field"><label><?=t('three_cell')?> <?=t('result')?></label><input name="three_cell_result" value="<?=$V('three_cell_result')?>"></div>
        <div></div>
        <div class="field"><label><?=t('eleven_cell')?> <?=t('done')?></label><?=$ynSel('eleven_cell_done')?></div>
      </div>
      <div class="field"><label><?=t('eleven_cell')?> <?=t('interpretation')?></label><textarea name="eleven_cell_interpretation" placeholder="e.g. anti-E identified"><?=$V('eleven_cell_interpretation')?></textarea></div>
      <button><?=t('save_serology')?></button>
      <p class="muted" style="font-size:12px"><?=t('append_hint')?></p>
    </form>
    <?php if ($serologies): ?><div class="table-wrap"><table><tr><th><?=t('date')?></th><th><?=t('center')?></th><th>DCT</th><th>ICT</th><th><?=t('three_cell')?></th><th><?=t('eleven_cell')?></th></tr>
      <?php foreach ($serologies as $w): ?><tr><td><?=h($w['performed_date'])?></td><td><?=h($w['center'])?></td><td><?=h($w['dct_result']?:($w['dct_done']===null?'—':''))?> <?=h($w['dct_grade'])?></td><td><?=h($w['ict_result']?:($w['ict_done']===null?'—':''))?> <?=h($w['ict_grade'])?></td><td><?=h($w['three_cell_result']?:'—')?></td><td><?=h($w['eleven_cell_interpretation']?:'—')?></td></tr><?php endforeach?></table></div><?php endif; ?>
  </section>

  <!-- ③ ANTIBODY (add-only) -->
  <section class="card"><h2>③ <?=t('antibody')?> <span class="muted" style="font-weight:400">— <?=t('add_only')?></span></h2>
    <?php if ($antibodies): ?><p><b><?=t('on_record')?>:</b> <?php foreach ($antibodies as $i=>$a): ?><?=$i?' · ':''?><i><?=h($a['antibody'])?></i> <span class="muted">(<?=h($a['how_found']?:'—')?>, <?=h($a['detected_date'])?><?=$a['center']?', '.h($a['center']):''?>)</span><?php endforeach; ?></p>
      <p class="muted" style="font-size:12px"><?=t('antibody_permanent')?></p><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="antibody">
      <div class="form-grid">
        <div class="field"><label><?=t('antibody')?></label><input name="antibody" list="known-abs" value="<?=$V('antibody')?>" placeholder="anti-E" required>
          <datalist id="known-abs"><?php foreach ($knownList as $n) echo '<option value="'.h($n).'">'; ?></datalist></div>
        <div class="field"><label><?=t('system')?></label><input name="system" value="<?=$V('system')?>" placeholder="Rh / Kell / Kidd…"></div>
        <div class="field"><label><?=t('significance')?></label><select name="clinical_significance"><?php foreach (['High','Moderate','Low','Unknown'] as $x): ?><option<?=(($_POST['clinical_significance']??'')===$x?' selected':'')?>><?=$x?></option><?php endforeach?></select></div>
        <div class="field"><label><?=t('titer')?></label><input name="titer" value="<?=$V('titer')?>" placeholder="1:32"></div>
        <div class="field"><label><?=t('how_found')?></label><input name="how_found" value="<?=$V('how_found')?>" placeholder="11-cell panel"></div>
        <div class="field"><label><?=t('date')?></label><input type="date" name="detected_date" value="<?=$V('detected_date',date('Y-m-d'))?>"></div>
        <?=$centerLabel?>
      </div>
      <?php if ($abDup): ?><label class="notice" style="display:block"><input type="checkbox" name="ack_dup" value="1"> <?=t('ack_dup')?></label><?php endif; ?>
      <button><?=t('add_antibody')?></button>
    </form>
  </section>

  </div>
</details>
<?php staff_end();

<?php
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/matching.php';
const PRC_SHELF_LIFE_DAYS = 42;
const STOCK_BLOOD_GROUPS = ['A+','A-','B+','B-','AB+','AB-','O+','O-'];
$s=require_staff_center();
$err='';
$selectedRh=trim((string)($_POST['rh_phenotype']??''));
$selectedK=(string)($_POST['antigen_K']??'');
$selectedGroup=trim((string)($_POST['blood_group']??''));
$selectedExpiry=trim((string)($_POST['expiry_date']??date('Y-m-d',strtotime('+'.PRC_SHELF_LIFE_DAYS.' days'))));
if($_SERVER['REQUEST_METHOD']==='POST'){
  check_csrf();$cid=(int)$s['center_id'];
  if(($_POST['action']??'')==='remove_bag'){
    $bagId=(int)($_POST['bag_id']??0);
    $pdo=db();$pdo->beginTransaction();
    try{
      $bagQ=$pdo->prepare('SELECT id,bag_number,status FROM bags WHERE id=? AND center_id=? FOR UPDATE');
      $bagQ->execute([$bagId,$cid]);$bag=$bagQ->fetch();
      if(!$bag) throw new RuntimeException('Bag not found at your logged-in center.');
      if($bag['status']==='issued') throw new RuntimeException('Issued bags are linked to transfusion history and cannot be deleted.');
      if($bag['status']==='discarded') throw new RuntimeException('This bag has already been removed.');
      $pdo->prepare("UPDATE bags SET status='discarded' WHERE id=? AND center_id=?")->execute([$bagId,$cid]);
      audit('remove_from_inventory','bags',$bagId,$cid,'staff',null,'status',$bag['status'],'discarded');
      $pdo->commit();header('Location: /staff/stock.php?removed=1');exit;
    }catch(Throwable $ex){$pdo->rollBack();$err='Could not remove bag: '.$ex->getMessage();}
  }else{
  // phenotyping_capable is a UI DEFAULT, not a wall: a submitted K result persists
  // (blank => NULL "not tested", never coerced to 0). A center that
  // doesn't routinely run the K screen can still record a K result obtained externally.
  // $capable is retained only for the "confirm no Rh typing" nag below; it no longer gates K.
  $capQ=db()->prepare('SELECT phenotyping_capable FROM blood_centers WHERE id=?');$capQ->execute([$cid]);$capable=(int)$capQ->fetchColumn();
  $rhValues=pheno_from_rh_string($selectedRh);
  $eff=[
    'C'=>$rhValues['antigen_C']??'',
    'c'=>$rhValues['antigen_c_lower']??'',
    'E'=>$rhValues['antigen_E']??'',
    'e'=>$rhValues['antigen_e_lower']??'',
    'K'=>$selectedK,
  ];
  $ph=build_phenotype_string($eff,['C','c','E','e','K']);
  $exp=$selectedExpiry;
  $expiryDate=DateTimeImmutable::createFromFormat('!Y-m-d',$exp);
  $validExpiry=$expiryDate!==false&&$expiryDate->format('Y-m-d')===$exp;
  $coll=$validExpiry?$expiryDate->modify('-'.PRC_SHELF_LIFE_DAYS.' days')->format('Y-m-d'):'';
  $rhBlank=($eff['C']===''&&$eff['c']===''&&$eff['E']===''&&$eff['e']==='');
  if(!in_array($selectedGroup,STOCK_BLOOD_GROUPS,true)) $err='Select a valid blood group.';
  elseif($selectedRh!==''&&!in_array($selectedRh,RH_PHENOTYPES,true)) $err='Select a valid complete Rh phenotype.';
  elseif(!in_array($selectedK,['','0','1'],true)) $err='Select a valid K antigen result.';
  elseif(!$validExpiry) $err='Enter a valid expiry date.';
  elseif($exp<date('Y-m-d')) $err='This bag is already past expiry; it cannot be added as available stock.';
  elseif($coll>date('Y-m-d')) $err='This expiry date implies a collection date in the future.';
  elseif($capable && $rhBlank && empty($_POST['confirm_no_rh'])) $err='No Rh typing (C/c/E/e) was recorded at this phenotyping-capable center. Select an Rh phenotype, or tick "No Rh typing for this unit" to confirm this is intentional.';
  else{
    $year=(int)date('Y');$num=trim($_POST['bag_number']??'');
    $pdo=db();$pdo->beginTransaction();
    try{
      if($num===''){ // per-center-per-year sequence: atomic lock-and-increment, no MAX() race
        $pdo->prepare('INSERT INTO bag_sequences(center_id,year,last_seq) VALUES(?,?,1) ON DUPLICATE KEY UPDATE last_seq=LAST_INSERT_ID(last_seq+1)')->execute([$cid,$year]);
        $seq=(int)$pdo->lastInsertId();
        if(!$seq){$sq=$pdo->prepare('SELECT last_seq FROM bag_sequences WHERE center_id=? AND year=?');$sq->execute([$cid,$year]);$seq=(int)$sq->fetchColumn();}
        $num=$year.str_pad((string)$seq,4,'0',STR_PAD_LEFT);
      }
      $st=$pdo->prepare('INSERT INTO bags(bag_number,center_id,year,blood_group,antigen_C,antigen_c_lower,antigen_E,antigen_e_lower,antigen_K,phenotype_string,product,volume_ml,collection_date,expiry_date,received_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      // product is fixed to PRC for v1 — thalassemia stock is packed red cells; add a selector if non-PRC stock is ever needed.
      $st->execute([$num,$cid,$year,$selectedGroup,antigen_col($eff['C']),antigen_col($eff['c']),antigen_col($eff['E']),antigen_col($eff['e']),antigen_col($eff['K']),$ph,'PRC',null,$coll,$exp,$s['id']]);
      $bagId=(int)$pdo->lastInsertId(); // §4: capture inline off held $pdo, no query between INSERT and id
      audit('create','bags',$bagId,$cid);
      $pdo->commit();header('Location: /staff/stock.php');exit;
    }catch(Throwable $ex){$pdo->rollBack();$err='Could not save bag: '.$ex->getMessage();}
  }
  }
}
db()->exec("UPDATE bags SET status='expired' WHERE status='available' AND expiry_date<CURDATE()");
$q=db()->prepare('SELECT b.*,c.name center FROM bags b JOIN blood_centers c ON c.id=b.center_id WHERE b.center_id=? AND b.status<>"discarded" AND b.blood_group LIKE ? AND b.status LIKE ? ORDER BY b.expiry_date');
$q->execute([(int)$s['center_id'],'%'.($_GET['group']??'').'%','%'.($_GET['status']??'').'%']);
staff_start('Stock','stock.php');
?><div class="page-head"><h1>Stock / bags</h1></div>
<?php if($err):?><p class="alert"><?=h($err)?></p><?php elseif(isset($_GET['removed'])):?><p class="notice">Bag removed from usable inventory. The action remains in the audit log.</p><?php endif?>
<form method="post" class="card"><input type="hidden" name="csrf" value="<?=csrf()?>"><h2>Add bag</h2><div class="form-grid"><div class="field"><label>Center</label><select name="center_id" id="bag-center"><?php foreach(centers() as $c):?><option value="<?=$c['id']?>" data-cap="<?=(int)$c['phenotyping_capable']?>"><?=h($c['name'])?><?=$c['phenotyping_capable']?'':' (Rh only)'?></option><?php endforeach?></select></div><div class="field"><label>Bag number (blank = sequential)</label><input name="bag_number"></div><div class="field"><label>Group</label><select name="blood_group" required><option value="">Select group</option><?php foreach(STOCK_BLOOD_GROUPS as $g):?><option value="<?=$g?>"<?=$selectedGroup===$g?' selected':''?>><?=$g?></option><?php endforeach?></select></div><div class="field"><label>Expiry</label><input type="date" name="expiry_date" value="<?=h($selectedExpiry)?>" required><small>Collection date is calculated automatically as <?=PRC_SHELF_LIFE_DAYS?> days before expiry.</small></div></div><h2><?=t('phenotype')?> — Rh C/c/E/e + K</h2><p class="muted"><?=t('antigen_hint')?></p><div class="antigen-grid"><div><label>Rh phenotype</label><select name="rh_phenotype"><option value=""><?=t('not_tested')?></option><?php foreach(RH_PHENOTYPES as $rh):?><option value="<?=$rh?>"<?=$selectedRh===$rh?' selected':''?>><?=$rh?></option><?php endforeach?></select></div><div><label>K</label><select name="antigen_K" data-kell="1"><option value=""<?=$selectedK===''?' selected':''?>><?=t('not_tested')?></option><option value="1"<?=$selectedK==='1'?' selected':''?>><?=t('ag_pos')?></option><option value="0"<?=$selectedK==='0'?' selected':''?>><?=t('ag_neg')?></option></select></div></div><label class="muted"><input type="checkbox" name="confirm_no_rh" value="1"<?=!empty($_POST['confirm_no_rh'])?' checked':''?>> <?=t('confirm_no_rh')?></label><br><button>Add bag</button></form>
<section class="card"><h2>Inventory</h2><div class="table-wrap"><table><tr><th>Bag</th><th>Center</th><th>Group</th><th>Phenotype</th><th>Expiry</th><th>Status</th><th>Action</th></tr><?php foreach($q as $b):?><tr><td><code><?=h($b['bag_number'])?></code></td><td><?=h($b['center'])?></td><td><?=h($b['blood_group'])?></td><td><?=h($b['phenotype_string']?:'— not typed')?></td><td><?=h($b['expiry_date'])?></td><td><?=h($b['status'])?></td><td><?php if($b['status']!=='issued'):?><form method="post" style="display:inline" onsubmit="return confirm('Delete this bag from usable inventory? The audit record will be retained.')"><input type="hidden" name="csrf" value="<?=csrf()?>"><input type="hidden" name="action" value="remove_bag"><input type="hidden" name="bag_id" value="<?=(int)$b['id']?>"><button type="submit" style="background:var(--red);padding:7px 11px">Delete</button></form><?php else:?><span class="muted">Protected</span><?php endif?></td></tr><?php endforeach?></table></div></section>
<script>(function(){var c=document.getElementById('bag-center'),k=document.querySelector('select[data-kell]');if(!c||!k)return;function sync(){var cap=c.options[c.selectedIndex].dataset.cap==='1';k.title=cap?'':'This center does not routinely run the K screen — enter a K result only if one was obtained externally.';}c.addEventListener('change',sync);sync();})();</script><?php staff_end();

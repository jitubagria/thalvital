<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/i18n.php';

function head(string $title='ThalVital', bool $staffArea=false): void { $base=defined('BASE_URL')?rtrim(BASE_URL,'/').'/' : '/'; $cssVersion = (string)filemtime(__DIR__.'/../assets/main.css'); ?>
<!doctype html><html lang="<?=h($_SESSION['lang']??'en')?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($title)?></title><link rel="icon" type="image/png" sizes="32x32" href="<?=h($base)?>assets/blood-drop.png"><link rel="stylesheet" href="<?=h($base)?>assets/main.css?v=<?=h($cssVersion)?>"><script defer src="<?=h($base)?>assets/app.js"></script><script src="<?=h($base)?>assets/vendor/chart.umd.min.js"></script></head><body>
<?php }
function footer(): void { echo '</body></html>'; }
function public_nav(): void { ?>
<nav class="public-nav"><a class="brand" href="/index.php">Thal<span>Vital</span></a><div><a href="/staff/login.php">Staff Login</a><a class="btn outline" href="/portal/login.php">Patient Portal</a><a href="?lang=en">EN</a> / <a href="?lang=hi">हि</a></div></nav>
<?php }
function sidebar(string $active): void { $s=require_staff(); $links=['index.php'=>'⊞ Dashboard','register.php'=>'⊕ New Patient','visit.php'=>'↗ Log Transfusion','stock.php'=>'▣ Stock / Bags'];
$context='Working center not selected';
if(!empty($s['org_id'])&&!empty($s['center_id'])){$q=db()->prepare('SELECT o.short_name,c.name center,c.city FROM organizations o JOIN blood_centers c ON c.org_id=o.id WHERE o.id=? AND c.id=?');$q->execute([(int)$s['org_id'],(int)$s['center_id']]);if($row=$q->fetch()){$context=$row['short_name'].' · '.$row['center'].($row['city']?', '.$row['city']:'');}}
?>
<aside class="sidebar"><a class="brand white" href="/staff/index.php">Thal<span>Vital</span></a><small><?=h($context)?></small><?php foreach($links as $url=>$label): ?><a class="<?=str_contains($active,$url)?'active':''?>" href="/staff/<?=$url?>"><?=$label?></a><?php endforeach; ?><div class="side-bottom"><?=h($s['full_name'])?><br><a href="/staff/logout.php">Sign out</a></div></aside>
<?php }
function staff_start(string $title,string $active): void { head($title,true); sidebar($active); echo '<main class="staff-main">'; }
function staff_end(): void { echo '</main>'; footer(); }
function passport_card(array $p,bool $compact=false): void { $ph=$p['phenotype']['phenotype_string']??'Not typed'; ?>
<section class="passport <?=$compact?'compact':''?>"><div><label>Blood Group</label><strong><?=h($p['blood_group'])?></strong><code><?=h($p['patient_id'])?></code><h3><?=h($p['full_name'])?></h3><small><?=h($p['diagnosis'])?></small></div><div class="passport-right"><label>Phenotype</label><b><?=h($ph)?></b><?php if(!empty($p['antibodies'])): ?><div class="antibodies">⚠ Antibodies: <?php foreach($p['antibodies'] as $a): ?><i><?=h($a['antibody']??$a)?></i><?php endforeach; ?></div><?php endif; ?></div></section>
<?php }

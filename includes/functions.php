<?php
require_once __DIR__.'/db.php';
function h($s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function aadhaar_hash(string $n):string{return hash('sha256',AADHAAR_SALT.preg_replace('/\D/','',$n));}
// Tri-state phenotype display string. $vals keyed by antigen label: '1'=present, '0'=tested-negative,
// ''/null=not tested. Not-tested antigens are OMITTED (absent letter is unambiguous). DISPLAY ONLY —
// matching always uses the structured antigen_* columns, never this string.
function build_phenotype_string(array $vals, array $antigens): ?string { $out=[]; foreach($antigens as $a){ $v=$vals[$a]??''; if($v===''||$v===null) continue; $out[]=$a.((string)$v==='1'?'+':'-'); } return $out?implode(' ',$out):null; }
// Tri-state form value -> nullable DB column ('' / null => NULL "not tested", never coerced to 0).
function antigen_col($v): ?int { return ($v===''||$v===null)?null:(int)$v; }
function calc_units(float $kg,float $target,float $pre):int{return max(1,(int)ceil(($kg*($target-$pre)*3)/250));}
function patient_by_id(string $id):?array{$q=db()->prepare('SELECT p.*,o.name org_name FROM patients p JOIN organizations o ON o.id=p.org_id WHERE p.patient_id=? AND p.active=1');$q->execute([$id]);return $q->fetch()?:null;}
// The clinical passport is network-portable across participating organizations.
// Operational writes and inventory remain locked to the authenticated staff center.
function patient_access(array $s,array $p,bool $full=true):bool{return !empty($s['id'])&&!empty($p['active']);}
function passport(string $id):array{$p=patient_by_id($id);if(!$p)return []; $q=db()->prepare('SELECT * FROM phenotypes WHERE patient_id=?');$q->execute([$id]);$p['phenotype']=$q->fetch()?:[];$q=db()->prepare('SELECT antibody,clinical_significance FROM alloantibodies WHERE patient_id=?');$q->execute([$id]);$p['antibodies']=$q->fetchAll();return $p;}
function next_patient_id():string{$n=(int)db()->query('SELECT COALESCE(MAX(id),0)+1 FROM patients')->fetchColumn();return 'PAT-'.str_pad((string)$n,6,'0',STR_PAD_LEFT);}
function centers():array{
    $s=staff();
    if($s&&!empty($s['center_id'])){$q=db()->prepare('SELECT * FROM blood_centers WHERE active=1 AND id=?');$q->execute([(int)$s['center_id']]);return $q->fetchAll();}
    return db()->query('SELECT * FROM blood_centers WHERE active=1 ORDER BY id')->fetchAll();
}

<?php
require_once __DIR__.'/includes/layout.php';
require_once __DIR__.'/includes/matching.php';

$group = $_GET['group'] ?? '';

// Public phenotype search: a COMPLETE Rh phenotype from a fixed dropdown (the 9).
// Explicit k=neg/pos remains supported for legacy/direct URLs, but the homepage sends no
// Kell value. An absent Kell parameter therefore adds no K constraint at all.
$selRh = trim((string)($_GET['pheno'] ?? ''));
$selK  = trim((string)($_GET['k'] ?? ''));           // absent/'' = no K filter
$pheno = pheno_from_rh_string($selRh);
if     ($selK === 'neg') $pheno['antigen_K'] = 0;    // patient K− -> require unit K−
elseif ($selK === 'pos') $pheno['antigen_K'] = 1;    // patient K+ -> no K constraint
// A constraint exists only where the patient LACKS an antigen (=0); an all-positive
// phenotype (e.g. CcEe, K+) constrains nothing and returns plain ABO/RhD availability.
$hasConstraint = false;
foreach ($pheno as $v) { if ($v === 0) { $hasConstraint = true; break; } }

// Location cascade — Country → State → City, tied to real center records (not free text).
// Center-level location supports one department spanning cities; organization values remain
// a backward-compatible fallback for rows created before the center-location migration.
$hasLocationParams = array_key_exists('country', $_GET)
    || array_key_exists('state', $_GET)
    || array_key_exists('city', $_GET);
$selCountry = $hasLocationParams ? trim((string)($_GET['country'] ?? '')) : 'India';
$selState   = $hasLocationParams ? trim((string)($_GET['state']   ?? '')) : 'Rajasthan';
$selCity    = $hasLocationParams ? trim((string)($_GET['city']    ?? '')) : 'Jaipur';
$locTree = [];
$lq = db()->query("SELECT DISTINCT COALESCE(NULLIF(c.country,''),o.country) country,COALESCE(NULLIF(c.state,''),o.state) state,COALESCE(NULLIF(c.city,''),o.city) city FROM organizations o JOIN blood_centers c ON c.org_id=o.id WHERE o.active=1 AND c.active=1 AND COALESCE(NULLIF(c.country,''),o.country) IS NOT NULL AND COALESCE(NULLIF(c.country,''),o.country)<>'' ORDER BY country,state,city");
foreach ($lq as $r) { $locTree[$r['country']][$r['state'] ?? ''][] = $r['city'] ?? ''; }
$publicCenterCount = (int)db()->query('SELECT COUNT(*) FROM blood_centers c JOIN organizations o ON o.id=c.org_id WHERE c.active=1 AND o.active=1')->fetchColumn();

// Render the selected cascade on the server so the first paint is complete without JS.
$countries = array_keys($locTree);
$states = ($selCountry !== '' && isset($locTree[$selCountry]))
    ? array_values(array_filter(array_keys($locTree[$selCountry]), static fn($v) => $v !== ''))
    : [];
$cities = ($selCountry !== '' && $selState !== '' && isset($locTree[$selCountry][$selState]))
    ? array_values(array_filter(array_unique($locTree[$selCountry][$selState]), static fn($v) => $v !== ''))
    : [];

$locLabel = $selCity !== '' ? $selCity.($selState !== '' ? ', '.$selState : '')
          : ($selState !== '' ? $selState
          : ($selCountry !== '' ? $selCountry : 'all locations'));

head(); public_nav();
?>
<section class="hero">
  <h1><?=t('tagline')?></h1>
  <p>A clinical passport and phenotype-matched blood availability platform for India.</p>
  <form class="search-box" method="get">
    <div class="field"><label>Country</label><select name="country" id="loc-country"><option value="">All countries</option><?php foreach ($countries as $country): ?><option value="<?=h($country)?>" <?=$country===$selCountry?'selected':''?>><?=h($country)?></option><?php endforeach; ?></select></div>
    <div class="field"><label>State</label><select name="state" id="loc-state"><option value="">All states</option><?php foreach ($states as $state): ?><option value="<?=h($state)?>" <?=$state===$selState?'selected':''?>><?=h($state)?></option><?php endforeach; ?></select></div>
    <div class="field"><label>City</label><select name="city" id="loc-city"><option value="">All cities</option><?php foreach ($cities as $city): ?><option value="<?=h($city)?>" <?=$city===$selCity?'selected':''?>><?=h($city)?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?=t('blood_group')?></label>
      <select name="group"><option value="">Select group</option>
        <?php foreach (['A+','A-','B+','B-','AB+','AB-','O+','O-'] as $g): ?>
          <option <?=$g===$group?'selected':''?>><?=$g?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Rh phenotype</label>
      <select name="pheno">
        <option value="">Any / don't know</option>
        <?php foreach (RH_PHENOTYPES as $ph): ?>
          <option <?=$ph===$selRh?'selected':''?>><?=$ph?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button><?=t('check_availability')?></button>
  </form>
</section>
<main class="content">
<?php if ($group):
    // Build the location scope once (org join), reused for both the center list and the bags.
    $scope = 'c.active=1 AND o.active=1'; $locArgs = [];
    if ($selCountry !== '') { $scope .= " AND COALESCE(NULLIF(c.country,''),o.country)=?"; $locArgs[] = $selCountry; }
    if ($selState   !== '') { $scope .= " AND COALESCE(NULLIF(c.state,''),o.state)=?";     $locArgs[] = $selState; }
    if ($selCity    !== '') { $scope .= " AND COALESCE(NULLIF(c.city,''),o.city)=?";       $locArgs[] = $selCity; }

    // Centers in scope, zero-initialised so empty centers still show (0 units), as before.
    $cq = db()->prepare("SELECT c.id,c.name,c.code FROM blood_centers c JOIN organizations o ON o.id=c.org_id WHERE $scope ORDER BY c.name");
    $cq->execute($locArgs);
    $centers = [];
    foreach ($cq as $c) { $centers[$c['id']] = ['name'=>$c['name'],'code'=>$c['code'],'avail'=>0,'match'=>0,'typed'=>0,'profiles'=>[]]; }

    // Candidate units: available, in-date, matching ABO/RhD, in scope. The phenotype decision
    // is NOT in SQL — every candidate is judged by the shared matcher (bag_matches_phenotype),
    // the same module the clinical screens use, so NULL antigens can never pass as negative.
    $bq = db()->prepare("SELECT b.center_id,b.phenotype_string,b.antigen_C,b.antigen_c_lower,b.antigen_E,b.antigen_e_lower,b.antigen_K "
        . "FROM bags b JOIN blood_centers c ON c.id=b.center_id JOIN organizations o ON o.id=c.org_id "
        . "WHERE $scope AND b.status='available' AND b.expiry_date>=CURDATE() AND b.blood_group=?");
    $bq->execute(array_merge($locArgs, [$group]));
    $matchTotal = 0;
    foreach ($bq as $b) {
        $cid = $b['center_id']; if (!isset($centers[$cid])) continue;
        $centers[$cid]['avail']++;
        if ($b['antigen_C']!==null||$b['antigen_c_lower']!==null||$b['antigen_E']!==null||$b['antigen_e_lower']!==null||$b['antigen_K']!==null) $centers[$cid]['typed']++;
        $matches = bag_matches_phenotype($pheno, $b);
        if ($matches) { $centers[$cid]['match']++; $matchTotal++; }
        // Expose only aggregate canonical Rh phenotype counts—never public bag identifiers.
        // Use the same nine-value notation as the search dropdown (for example CCee),
        // not the stock-entry display string (C+ c- E- e+ K-). Kell is intentionally
        // absent from the homepage search, so it is not used to split public Rh totals.
        // Any Rh shows every available profile; a selected Rh shows matched profiles only.
        if (!$hasConstraint || $matches) {
            $profile = rh_string_from_pheno($b) ?: t('not_tested');
            $centers[$cid]['profiles'][$profile] = ($centers[$cid]['profiles'][$profile] ?? 0) + 1;
        }
    }
    foreach ($centers as &$center) ksort($center['profiles'], SORT_NATURAL | SORT_FLAG_CASE);
    unset($center);
    $kLabel = $selK==='neg' ? 'K&minus;' : ($selK==='pos' ? 'K+' : '');
?>
  <section class="card results">
    <h2><?=$hasConstraint ? 'Units matching this profile' : t('available').' '.h($group)?> — <?=h($locLabel)?></h2>
    <p class="profile-line">Searched: <span class="mono"><?=h($group)?> · <?=$selRh!=='' ? h($selRh) : 'Any Rh'?><?=$kLabel!=='' ? ' · '.$kLabel : ''?></span></p>
    <?php foreach ($centers as $c): ?>
      <div class="result-row">
        <div><b><?=h($c['name'])?></b><br><small class="muted"><?=h($c['code'])?><?php if ($hasConstraint): ?> · <?=(int)$c['avail']?> <?=h($group)?> in stock<?php else: ?> · <?=(int)$c['typed']?> phenotyped<?php endif; ?></small><?php if ($c['profiles']): ?><div class="muted" style="display:flex;flex-wrap:wrap;align-items:center;gap:5px;margin-top:7px;font-size:12px"><span><?=h(t('phenotype'))?>:</span><?php foreach ($c['profiles'] as $profile=>$count): ?><i><span class="mono"><?=h($profile)?></span> × <?=(int)$count?></i><?php endforeach; ?></div><?php endif; ?></div>
        <b><?=$hasConstraint ? (int)$c['match'].' matching' : (int)$c['avail'].' units'?></b>
      </div>
    <?php endforeach; ?>
    <?php if ($hasConstraint): ?><p class="profile-line"><b><?=$matchTotal?></b> phenotype-matched unit<?=$matchTotal===1?'':'s'?> across <?=h($locLabel)?>.</p><?php endif; ?>
    <p class="disclaimer">This is an availability check based on the details you entered. Final compatibility must be confirmed by crossmatch at the blood center.</p>
  </section>
<?php endif; ?>
  <section class="stats">
    <div class="stat"><b><?=$publicCenterCount?></b>Blood Centers</div>
    <div class="stat"><b>One</b>Clinical passport</div>
    <div class="stat"><b>ABO +</b>Phenotype matching</div>
    <div class="stat no-login"><b><?=t('live_stock')?></b>Availability check</div>
  </section>
  <section class="grid">
    <article class="card"><h2>For Blood Centers</h2><p class="audience-copy"><?=t('blood_centers_intro')?></p></article>
    <article class="card"><h2>For Patients</h2><p class="audience-copy">A lifelong compatibility card, transfusion history and a fast way to check matching blood before travel.</p></article>
  </section>
</main>
<script>
(function () {
  var TREE = <?=json_encode($locTree, JSON_UNESCAPED_UNICODE)?>;
  var sel = {country: <?=json_encode($selCountry)?>, state: <?=json_encode($selState)?>, city: <?=json_encode($selCity)?>};
  var cEl = document.getElementById('loc-country'),
      sEl = document.getElementById('loc-state'),
      tEl = document.getElementById('loc-city');
  function opt(v, label, chosen) { var o = document.createElement('option'); o.value = v; o.textContent = label; if (v === chosen) o.selected = true; return o; }
  function fill(el, items, chosen, allLabel) { el.innerHTML = ''; el.appendChild(opt('', allLabel, chosen)); items.forEach(function (it) { el.appendChild(opt(it, it, chosen)); }); }
  function uniq(a) { return Object.keys(a.reduce(function (m, k) { m[k] = 1; return m; }, {})); }
  function states(country) { var out = []; Object.keys(TREE).forEach(function (c) { if (country && c !== country) return; out = out.concat(Object.keys(TREE[c])); }); return uniq(out); }
  function cities(country, state) { var out = []; Object.keys(TREE).forEach(function (c) { if (country && c !== country) return; Object.keys(TREE[c]).forEach(function (st) { if (state && st !== state) return; out = out.concat(TREE[c][st]); }); }); return uniq(out); }
  function renderCountry() { fill(cEl, Object.keys(TREE), sel.country, 'All countries'); }
  function renderState()   { fill(sEl, states(cEl.value), sel.state, 'All states'); }
  function renderCity()    { fill(tEl, cities(cEl.value, sEl.value), sel.city, 'All cities'); }
  renderCountry(); renderState(); renderCity();
  cEl.addEventListener('change', function () { sel.country = cEl.value; sel.state = ''; sel.city = ''; renderState(); renderCity(); });
  sEl.addEventListener('change', function () { sel.state = sEl.value; sel.city = ''; renderCity(); });
})();
</script>
<?php footer();

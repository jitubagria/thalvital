<?php
// Phenotype matching — the single source of truth for the two-layer model.
// Never duplicate this logic in a screen; include this file and call match_bag_for_patient().
//
// Layer 1 (antibody) = HARD block: a compatible unit must be antigen_X = 0 (tested-negative).
//   antigen_X = 1 blocks (antigen present); antigen_X IS NULL blocks (not tested — cannot confirm
//   negative). NULL NEVER passes as negative. Blocked units require the C2 interlock to issue.
// Layer 2 (prophylactic Rh) = prioritise, not block: for each of C/c/E/e the TYPED patient is
//   tested-negative for (=0), prefer a unit that also lacks it; unit=1 is a mismatch (warn+log),
//   unit=NULL is a soft warn (untested). Never blocks, never excludes a unit from the issuable set.
// Antibodies to systems not typed on bags (Kidd/Duffy/MNS) are UNVERIFIABLE in v1 — surfaced
//   separately so the caller can gate on them; they are NOT silently passed and NOT hard-blocked.

const ANTIBODY_ANTIGEN = ['anti-C'=>'antigen_C','anti-c'=>'antigen_c_lower','anti-E'=>'antigen_E','anti-e'=>'antigen_e_lower','anti-K'=>'antigen_K'];
const PROPHYLACTIC_RH  = ['C'=>'antigen_C','c'=>'antigen_c_lower','E'=>'antigen_E','e'=>'antigen_e_lower'];

// Normalize a nullable TINYINT (int, "0"/"1", '' or null) to exactly 0, 1, or null. '' and null => null.
function ag_state($v): ?int { return ($v === null || $v === '') ? null : (int)$v; }

// Evaluate one bag against one patient.
//   $antibodies: list of ['antibody'=>'anti-E', ...] (or bare strings)
//   $pheno:      patient phenotypes row (assoc) or [] if never typed
//   $bag:        bags row (assoc) carrying antigen_* columns
// Returns a structured verdict; see keys below.
function match_bag_for_patient(array $antibodies, array $pheno, array $bag): array {
    // ---- Layer 1: antibody hard block ----
    $blocking = []; $unverifiable = [];
    foreach ($antibodies as $ab) {
        $name = is_array($ab) ? ($ab['antibody'] ?? '') : $ab;
        if ($name === '') continue;
        if (!array_key_exists($name, ANTIBODY_ANTIGEN)) { $unverifiable[] = $name; continue; }
        $col = ANTIBODY_ANTIGEN[$name];
        $u = ag_state($bag[$col] ?? null);
        if ($u === 0) continue;                       // verified antigen-negative -> compatible for this antibody
        $blocking[] = [
            'antibody'   => $name,
            'antigen'    => $col,
            'unit_state' => $u === 1 ? 'positive' : 'untested',
            'reason'     => $name . ' — unit ' . ($u === 1 ? 'antigen present' : 'not tested (cannot confirm negative)'),
        ];
    }
    // ---- Layer 2: prophylactic Rh (typed patient only) ----
    $typedAny = false;
    foreach (PROPHYLACTIC_RH as $col) { if (ag_state($pheno[$col] ?? null) !== null) { $typedAny = true; break; } }
    $mismatch = []; $warn = []; $score = 0; $applicable = 0;
    if ($typedAny) {
        foreach (PROPHYLACTIC_RH as $lbl => $col) {
            $p = ag_state($pheno[$col] ?? null);
            if ($p === null) continue;                // patient not typed for this antigen -> N/A (NULL never counts)
            if ($p === 0) {                           // patient lacks the antigen -> prefer a unit that also lacks it
                $applicable++;
                $u = ag_state($bag[$col] ?? null);
                if ($u === 0)      { $score++; }
                elseif ($u === 1)  { $mismatch[] = ['antigen'=>$lbl, 'reason'=>$lbl.': patient negative, unit positive']; }
                else               { $warn[]     = ['antigen'=>$lbl, 'reason'=>$lbl.': patient negative, unit not tested']; }
            }
        }
    }
    $hard_block = !empty($blocking);
    return [
        'hard_block'       => $hard_block,           // Layer 1: red, consent-gated (C2)
        'blocking'         => $blocking,             // per-antibody reasons (for the legible override message)
        'unverifiable'     => $unverifiable,         // recorded antibodies v1 cannot check -> acknowledgement gate
        'proph_typed'      => $typedAny,             // false => "not phenotyped — prophylactic match unavailable"
        'proph_applicable' => $applicable,           // count of C/c/E/e the patient lacks (prophylactic constraints)
        'proph_mismatch'   => $mismatch,             // Layer 2: amber, acknowledge (unit positive where patient negative)
        'proph_warn'       => $warn,                 // Layer 2: amber, soft (unit untested where patient negative)
        'score'            => $score,                // satisfied prophylactic constraints -> best-first ranking
        // clean pass: no block, no unverifiable antibody, and (if prophylactic applies) fully satisfied
        'issuable_clean'   => (!$hard_block && empty($unverifiable) && empty($mismatch) && empty($warn)),
    ];
}

// ---------------------------------------------------------------------------
// Public availability adapter (unauthenticated search page).
// The public page supplies a COMPLETE, enumerable Rh phenotype (one of the 9). Optional
// Kell input remains available to legacy/direct callers. There are no antibodies and no
// crossmatch here — this is a prophylactic "no foreign antigen" FILTER, not the staff
// ranking. It reuses the same
// ag_state() NULL-normalisation and the same per-antigen rule as Layer 2 above, so the
// public page and the clinical screens cannot drift on the Rh logic.
//
// K is intentionally NOT in PROPHYLACTIC_RH (staff Layer 2 stays Rh-only, unchanged). It
// is supported HERE only when a caller explicitly passes it. With no antigen_K patient
// value, K−, K+, and untyped/NULL units all pass. Columns are derived from
// PROPHYLACTIC_RH so a schema rename can't desync them.
const PROPHYLACTIC_PUBLIC_EXTRA = ['K'=>'antigen_K'];
function prophylactic_public_cols(): array { return PROPHYLACTIC_RH + PROPHYLACTIC_PUBLIC_EXTRA; }

// The 9 complete Rh phenotypes (C/c and E/e are allelic -> 3x3 = 9). Anything else
// (including 'any' / '' / 'don't know') means NO Rh constraint -> returns [].
const RH_PHENOTYPES = ['CCEE','CCEe','CCee','CcEE','CcEe','Ccee','ccEE','ccEe','ccee'];
function pheno_from_rh_string(string $rh): array {
    $rh = trim($rh);
    if (!in_array($rh, RH_PHENOTYPES, true)) return [];   // 'any' / unknown -> ABO/RhD only
    $cc = substr($rh, 0, 2); $ee = substr($rh, 2, 2);     // 'Cc' + 'Ee'
    return [
        'antigen_C'       => str_contains($cc, 'C') ? 1 : 0,
        'antigen_c_lower' => str_contains($cc, 'c') ? 1 : 0,
        'antigen_E'       => str_contains($ee, 'E') ? 1 : 0,
        'antigen_e_lower' => str_contains($ee, 'e') ? 1 : 0,
    ];
}

// Return the canonical dropdown value for a complete stored Rh phenotype.
// Partial/invalid antigen combinations deliberately return blank so the UI does not
// pretend that an incomplete historical anchor is one of the nine complete profiles.
function rh_string_from_pheno(array $pheno): string {
    $normalized = [];
    foreach (PROPHYLACTIC_RH as $col) {
        $normalized[$col] = ag_state($pheno[$col] ?? null);
        if ($normalized[$col] === null) return '';
    }
    foreach (RH_PHENOTYPES as $rh) {
        if (pheno_from_rh_string($rh) === $normalized) return $rh;
    }
    return '';
}

// True iff the unit carries no antigen the (fully-typed) patient lacks. For each antigen
// the patient is tested-negative for (=0), the unit must be explicitly tested-negative (=0)
// too; a unit that is antigen-positive (1) OR not tested (NULL) does NOT satisfy it and is
// excluded. NULL never counts as negative. Antigens the patient HAS impose no constraint.
// A patient phenotype with no negatives (e.g. CcEe, K+) constrains nothing -> matches all.
function bag_matches_phenotype(array $pheno, array $bag): bool {
    foreach (prophylactic_public_cols() as $col) {
        if (ag_state($pheno[$col] ?? null) === 0 && ag_state($bag[$col] ?? null) !== 0) return false;
    }
    return true;
}

// Rank available bags for a patient, best match first. Nothing is excluded — hard-blocked /
// unverifiable units sort last (still issuable via the interlock/acknowledgement), so a patient
// never ends up with zero options. Each returned bag carries '_match' => verdict.
function rank_bags_for_patient(array $antibodies, array $pheno, array $bags): array {
    $eval = [];
    foreach ($bags as $b) { $b['_match'] = match_bag_for_patient($antibodies, $pheno, $b); $eval[] = $b; }
    usort($eval, function ($x, $y) {
        $xg = ($x['_match']['hard_block'] || $x['_match']['unverifiable']) ? 1 : 0;
        $yg = ($y['_match']['hard_block'] || $y['_match']['unverifiable']) ? 1 : 0;
        if ($xg !== $yg) return $xg - $yg;                                   // clearable units first
        if ($x['_match']['score'] !== $y['_match']['score']) return $y['_match']['score'] - $x['_match']['score']; // better prophylactic first
        $xi = count($x['_match']['proph_mismatch']) + count($x['_match']['proph_warn']);
        $yi = count($y['_match']['proph_mismatch']) + count($y['_match']['proph_warn']);
        if ($xi !== $yi) return $xi - $yi;                                   // fewer soft issues first
        return strcmp($x['expiry_date'] ?? '', $y['expiry_date'] ?? '');     // then earliest expiry
    });
    return $eval;
}

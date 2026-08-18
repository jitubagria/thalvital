# ThalVital

A network-portable blood compatibility passport for thalassemia patients. ThalVital keeps a patient's extended red cell phenotype and full alloantibody history in one record that moves with the patient across blood centres, and it supports safe, antigen-aware blood unit selection.

It is built for low-bandwidth hospital networks: server-rendered PHP 8.1+ and MySQL/MariaDB, plain HTML/CSS/JavaScript, no framework, no build step, and no external CDN.

## ⚠️ Medical disclaimer

ThalVital provides transfusion **decision support** only. It does **not** replace serological crossmatch, antibody identification, or clinical judgment. The final compatibility decision rests with the treating physician and the blood bank. The software is provided "AS IS", without warranty of any kind (see `LICENSE`). Validate it in your own environment before any clinical use.

## What it does

- Extended red cell phenotype record (Rh C/c/E/e, Kell K; Kidd, Duffy, MNS where typed).
- Longitudinal serology and alloantibody history (DCT, ICT, 3-cell screen, 11-cell panel).
- Two-layer matching engine:
  - **Layer 1 (hard block):** blocks any unit carrying, or not tested for, an antigen against the patient's historical alloantibody.
  - **Layer 2 (prophylactic warning):** prefers Rh/Kell matched units and warns on mismatch.
  - **Three-state antigen logic** (positive / negative / not-tested). Not-tested is never treated as negative.
- Cross-centre patient passport.
- Longitudinal vitals and iron-overload tracking (pre/post-transfusion Hb, serum ferritin, organ markers).
- Privacy by design: Aadhaar is stored only as a salted SHA-256 hash plus the last four digits.

## For thalassemia-care organisations

ThalVital is offered free of charge. Each organisation runs **its own instance** and remains the data fiduciary for its own patients. See `DEPLOYMENT.md`.

## Setup (summary)

1. Copy `config.sample.php` to `config.php` **outside the web root**, and fill in your database credentials and a permanent Aadhaar salt.
2. Load `schema.sql`, then `seed.sql` for synthetic demo data.
3. Serve the app on a PHP 8.1+ and MySQL 8+ (or MariaDB) stack over HTTPS.

## Data and privacy

- Never commit `config.php`, the Aadhaar salt, real patient data, or backups.
- The Aadhaar salt is set once and must stay secret permanently.
- Designed to align with India's DPDP Act 2023 and ABDM (FHIR-ready).

## Licence

Apache License 2.0. Copyright 2026 Jitendra Kumar Bagria. See `LICENSE`.

## Citation

If you use ThalVital, please cite:

> Bagria JK, Meena BS, Bhakar A. ThalVital: a network-portable blood compatibility passport for extended phenotype-matched transfusion in thalassemia. Zenodo; 2026. DOI: [to be added]. ORCID: 0009-0004-9958-8795.

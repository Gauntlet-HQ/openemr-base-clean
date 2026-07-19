# QA sample documents for upload testing

Freely-redistributable, **PHI-free** sample documents for exercising the
Clinical Co-Pilot ingestion flow (VLM extraction → typed, per-field-cited
facts). Every file here was opened and verified to render and to contain **no
real patient data** — all patients are fictitious ("Test User", "Sally
Walker", synthetic Indian-name cohort). Each file is well under the ~5 MB cap.

The module ingests three `doc_type` values (see `src/Ingest/DocType.php`):
`intake_form`, `lab_pdf`, `medication_list`. Upload each file below under the
`doc_type` named in its row.

## Files

| File | doc_type | Source URL | Publisher | License / terms | Why chosen |
|------|----------|-----------|-----------|-----------------|------------|
| `health-intake-form.pdf` | `intake_form` | `https://storage.googleapis.com/cloud-training/gsp924/health-intake-form.pdf` (also mirrored at `gs://cloud-samples-data/documentai/codelabs/form-parser/intake-form.pdf`) | Google Cloud (Document AI "Form Parser" codelab sample) | Google-published public sample document used across Google Cloud training labs (GSP924/GSP925); a synthetic, handwritten-style form with a "FakeDoc M.D." letterhead. Freely downloadable, no login. | A completed new-patient **health intake** form carrying exactly the fields the extractor targets: demographics (name/DOB/address/email/phone/gender/marital status/occupation), emergency contact, **chief medical concern** ("runny nose, mucus in throat, weakness, aches, chills, tired"), and a **current-medication** entry ("Vyvanse 25 mg daily"). Handwritten cursive stresses the VLM path. |
| `sample-lab-basic-metabolic.pdf` | `lab_pdf` | `github.com/SiddhantGupta3112/blood-test-tracker` → `backend/tests/fixtures/sample_blood_report.pdf` | Fictitious "ABC Diagnostic Laboratory" (test fixture) | MIT license (repo). Patient "Test User". | A compact, single-page, **text-layer** report combining glucose, HbA1c, full lipids, creatinine, vitamin D, and CBC. Useful as a fast, clean-text control case (contrast with the richer rasterized reports above). |

### doc_type coverage note

`intake_form` and `lab_pdf` are both covered. **`medication_list` has no
dedicated sample here** — no freely-redistributable, clearly-synthetic
standalone medication-list PDF was found through the available egress proxy.
For QA of that path, either upload `health-intake-form.pdf`'s medication line
as an `intake_form`, or hand-author a short med list (see "Fetch manually"
below).

## Does OpenEMR ship a canonical intake form?

**No.** OpenEMR ships **no canonical patient-intake PDF**. Its patient-facing
intake is captured through HTML / Layout-Based-Forms (LBF) and the patient
portal, and its encounter forms under `interface/forms/` (e.g. `newpatient`,
which is an *encounter visit* form, not an intake questionnaire) are HTML/PHP,
not fillable PDFs. The old `contrib/forms/` tree has been moved to the
unmaintained `openemr/contrib-encounter-forms` repo and contains no intake PDF.
A repo-wide search for `*.pdf` under `contrib/` and `interface/forms/` returns
nothing. The samples above therefore stand in for a canonical OpenEMR intake
document.

## Fetch manually (blocked by the session egress proxy)

These are genuinely endocrinology-specific blank intake forms found via search
but **not downloadable through this environment's proxy** (all returned a
policy `403` at the CONNECT stage). They are listed here so a human on an
unrestricted network can fetch them if an endo-specific blank form is wanted in
addition to the completed generic one above. Verify licensing/terms with each
clinic before redistribution.

- Northside Endocrinology Patient Intake Form (Northside Hospital) —
  `https://www.northside.com/docs/librariesprovider63/default-document-library/pp0146-58729-endocrinology-patient-intake-form.pdf`
  (has medical history + family history of diabetes/thyroid disease).
- Endocrinology New Patient Form —
  `https://assets.ctfassets.net/pxcfulgsd9e2/2CZjQYXFXPYrVjTyRkf0pc/8d9e7818463ebd04d6944a154a622213/endocrinology-new-patient-form.pdf`
  (PCP, pharmacy, review-of-systems checklist).
- Joslin Diabetes Center New Patient Referral Form —
  `https://www.upstate.edu/endo/pdf/joslin-new-patient-referral-form.pdf`.

## Generating richer synthetic lab PDFs (optional, run at your own discretion)

Only `sample-lab-basic-metabolic.pdf` (a static MIT-licensed test fixture) is
committed here. If you want richer rasterized endocrine panels (HbA1c/glycemic,
thyroid profile, lipid profile) for upload QA, the MIT-licensed `lab-gen-pdf`
generator produces watermarked synthetic reports deterministically per seed —
it is deliberately NOT vendored or executed by this repo; review it yourself
before running:

```bash
git clone https://github.com/RKInnovate/lab-gen-pdf && cd lab-gen-pdf
npm install
node src/index.js --count 30 --seed 42 --now 2026-05-29T12:00:00Z --out-dir ./out
# then pick the hba1c / thyroid / lipid outputs
```

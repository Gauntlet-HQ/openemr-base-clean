# Open-access source documents for the guideline corpus

Primary open-access endocrinology literature used to derive the `lit-*` chunks
in `src/Rag/corpus/endocrinology.json`. Every document here is **CC BY 4.0**
(free to redistribute with attribution) and was retrieved from the **PubMed
Central Open Access Subset** via the AWS Open Data mirror
(`s3://pmc-oa-opendata/`), since the ncbi.nlm.nih.gov / MDPI / Frontiers web
hosts are blocked by this environment's egress policy. The `license_code`
field in each article's PMC OA metadata was checked to be `CC BY` before
download.

Each PDF's companion machine-readable text (`.txt`/`.xml`) at the same S3 key
was used to extract faithful summaries; the corpus chunks quote/paraphrase
specific points and cite the PMC article URL.

## Files

| File | Citation | URL | License | Relevance |
|------|----------|-----|---------|-----------|
| `drug-therapies-for-diabetes_ijms-2023_CCBY.pdf` | Weinberg Sibony R, Segev O, Dor S, Raz I. *Drug Therapies for Diabetes.* Int J Mol Sci. 2023;24(24):17147. doi:10.3390/ijms242417147 | https://pmc.ncbi.nlm.nih.gov/articles/PMC10742594/ | CC BY 4.0 (MDPI) | Outpatient T2DM pharmacotherapy: metformin as first-line, GLP-1 RA and SGLT2i benefits, DPP-4i, sulfonylureas, incretin combos. Source of `lit-metformin-first-line`, `lit-glp1-cardio-weight`. |
| `t2dm-mechanisms-treatment-complications_ijms-2025_CCBY.pdf` | Młynarska E, et al. *Type 2 Diabetes Mellitus: New Pathogenetic Mechanisms, Treatment and the Most Important Complications.* Int J Mol Sci. 2025;26(3):1094. doi:10.3390/ijms26031094 | https://pmc.ncbi.nlm.nih.gov/articles/PMC11817707/ | CC BY 4.0 (MDPI) | T2DM diagnosis (ADA criteria), SGLT2i cardiorenal use + eGFR thresholds, and screening/management of diabetic kidney disease, retinopathy, neuropathy. Source of `lit-sglt2-cardiorenal`, `lit-dkd-screening`, `lit-neuropathy-screening`, `lit-retinopathy-duration`, `lit-t2dm-diagnostic-criteria`. |
| `subclinical-hypothyroidism-evidence_medicina-2020_CCBY.pdf` | Calissendorff J, Falhammar H. *To Treat or Not to Treat Subclinical Hypothyroidism, What Is the Evidence?* Medicina (Kaunas). 2020;56(1):40. doi:10.3390/medicina56010040 | https://pmc.ncbi.nlm.nih.gov/articles/PMC7022757/ | CC BY 4.0 (MDPI) | Thyroid: when to start levothyroxine (TSH > 10 mIU/L), repeat-TSH confirmation, pregnancy threshold (TSH > 4.0), overtreatment risk in the elderly. Source of the four `lit-*hypothyroid*` / `lit-subclinical-*` chunks. |
| `nodular-thyroid-disease-precision-medicine_frontendocrinol-2020_CCBY.pdf` | Tumino D, et al. *Nodular Thyroid Disease in the Era of Precision Medicine.* Front Endocrinol (Lausanne). 2020;10:907. doi:10.3389/fendo.2019.00907 | https://pmc.ncbi.nlm.nih.gov/articles/PMC6989479/ | CC BY 4.0 (Frontiers) | Thyroid-nodule ultrasound risk stratification (ATA / ACR-/EU-/K-TIRADS / AACE) guiding FNA. Source of `lit-thyroid-nodule-ultrasound`. |

## Re-download

```bash
# Each PDF is at s3://pmc-oa-opendata/<PMCID>.<ver>/<PMCID>.<ver>.pdf
curl -o drug-therapies-for-diabetes_ijms-2023_CCBY.pdf \
  https://pmc-oa-opendata.s3.amazonaws.com/PMC10742594.1/PMC10742594.1.pdf
# metadata + license: .../<PMCID>.<ver>.json  (field: license_code)
# machine-readable text: .../<PMCID>.<ver>.txt
```

## Attribution requirement

All four are CC BY 4.0: reuse (including the derived corpus chunks) is
permitted provided the original authors and publication are credited. The
`source` and `url` fields on each `lit-*` chunk carry that attribution.

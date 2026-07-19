### In-process hot-path results (module compute only — no web stack / DB / LLM)

_Captured 2026-07-13T22:03:58+00:00 · commit `899c583` · host x86_64 · PHP 8.4.19 · 4 cores · 16075 MB RAM · 8s/cell · warmup 300/worker._

| Workload | Conc | Throughput (ops/s) | p50 (ms) | p95 (ms) | p99 (ms) | CPU (% all cores) | RSS/worker (MB) | Aggregate RSS (MB) |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `guideline_retrieval_sparse` | 1 | 4,193.6 | 0.226 | 0.284 | 0.351 | 25% | 14.980 | 14.980 |
| `guideline_retrieval_sparse` | 10 | 16,716.0 | 0.221 | 4.258 | 8.279 | 99% | 14.760 | 147.290 |
| `guideline_retrieval_sparse` | 50 | 15,406.5 | 0.221 | 31.351 | 52.283 | 98% | 14.860 | 742.490 |
| `guideline_retrieval_hybrid` | 1 | 4,292.7 | 0.224 | 0.263 | 0.295 | 25% | 15.250 | 15.250 |
| `guideline_retrieval_hybrid` | 10 | 16,741.4 | 0.223 | 4.257 | 8.279 | 99% | 15.090 | 150.840 |
| `guideline_retrieval_hybrid` | 50 | 14,831.1 | 0.225 | 36.294 | 52.301 | 97% | 14.880 | 743.550 |
| `extraction_validate_parse` | 1 | 95,221.8 | 0.014 | 0.018 | 0.029 | 24% | 29.430 | 29.430 |
| `extraction_validate_parse` | 10 | 368,870.1 | 0.014 | 0.019 | 0.031 | 96% | 21.510 | 213.270 |
| `extraction_validate_parse` | 50 | 358,073.0 | 0.014 | 0.020 | 0.032 | 95% | 14.750 | 679.170 |
| `extraction_client_full` | 1 | 42,965.0 | 0.021 | 0.032 | 0.047 | 25% | 21.330 | 21.330 |
| `extraction_client_full` | 10 | 166,711.6 | 0.021 | 0.033 | 0.050 | 98% | 15.840 | 156.090 |
| `extraction_client_full` | 50 | 159,070.6 | 0.021 | 0.033 | 0.055 | 97% | 13.000 | 645.280 |
| `verify_chat` | 1 | 58,872.7 | 0.015 | 0.023 | 0.036 | 25% | 23.510 | 23.510 |
| `verify_chat` | 10 | 225,814.4 | 0.016 | 0.024 | 0.039 | 97% | 19.060 | 186.580 |
| `verify_chat` | 50 | 227,453.0 | 0.015 | 0.023 | 0.037 | 97% | 15.410 | 765.610 |
| `verify_synthesis` | 1 | 56,097.9 | 0.016 | 0.024 | 0.038 | 25% | 23.480 | 23.480 |
| `verify_synthesis` | 10 | 218,094.3 | 0.016 | 0.025 | 0.039 | 96% | 18.930 | 184.210 |
| `verify_synthesis` | 50 | 209,816.5 | 0.016 | 0.025 | 0.041 | 95% | 15.460 | 765.780 |
| `canonical_serialize_digest` | 1 | 10,543.6 | 0.087 | 0.134 | 0.160 | 25% | 15.490 | 15.490 |
| `canonical_serialize_digest` | 10 | 41,667.7 | 0.087 | 0.129 | 8.124 | 97% | 13.690 | 136.250 |
| `canonical_serialize_digest` | 50 | 39,995.1 | 0.086 | 0.121 | 48.137 | 96% | 13.360 | 666.710 |
| `prompt_assemble_reduce` | 1 | 38,643.0 | 0.024 | 0.037 | 0.052 | 25% | 21.950 | 21.950 |
| `prompt_assemble_reduce` | 10 | 155,343.3 | 0.023 | 0.035 | 0.052 | 98% | 16.340 | 161.770 |
| `prompt_assemble_reduce` | 50 | 152,254.9 | 0.023 | 0.035 | 0.051 | 97% | 13.710 | 682.120 |

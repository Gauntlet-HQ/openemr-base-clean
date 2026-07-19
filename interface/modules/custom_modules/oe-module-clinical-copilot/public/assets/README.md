# Vendored third-party assets

## pdf.js (pdfjs-dist 4.10.38)

- `pdf.min.mjs` — the pdf.js library (minified ES-module build)
- `pdf.worker.min.mjs` — its web worker (parsing/rendering runs off-thread)

Vendored verbatim from the npm package `pdfjs-dist@4.10.38`
(`package/build/pdf.min.mjs` and `package/build/pdf.worker.min.mjs` from
https://registry.npmjs.org/pdfjs-dist/-/pdfjs-dist-4.10.38.tgz), Apache-2.0
licensed (c) Mozilla Foundation. Do not edit; to upgrade, replace both files
from the same pdfjs-dist version and update this note.

Used by `templates/oe-module-clinical-copilot/extraction_review.html.twig` to
render the source PDF to canvases and draw each extracted field's citation
bounding box on the real page (see `docs/bbox-overlay.md`). Served as static
files under the module's `public/assets/` web path; loaded via
`import()` from an inline `<script type="module">`, with the plain iframe
viewer as the fallback whenever they fail to load.

Lint/CI note: repo codespell skips `**/public/assets/*`, and `npm run lint:js`
only globs `**/*.js`, so these `.mjs` bundles are outside both.

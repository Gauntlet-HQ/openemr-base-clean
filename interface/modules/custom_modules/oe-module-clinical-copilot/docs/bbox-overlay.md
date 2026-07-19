# Bounding-box overlay on the extraction review screen

How the lab verify screen (`public/extraction_review.php` →
`templates/oe-module-clinical-copilot/extraction_review.html.twig`) draws each
extracted field's citation box on the real rendered source PDF, and how
click-to-source navigation works. (An earlier SVG "source map" drew boxes on a
blank proportional rectangle and was removed in `0eade04`; this replaces it
with boxes on the actual page.)

## Data flow

- The extractor stores one citation per field: page number plus a bounding box
  in **normalized 0-1000 coordinates** (x0, y0, x1, y1 over the page, origin
  top-left). `IngestController::reviewViewModel()` passes it to the template
  as `f.page` / `f.bbox`.
- The template emits the geometry as `data-page` / `data-bbox` attributes on
  each field `<tr>` — the overlay script's only data source, so the overlay is
  inherently conditional on bbox presence (rows without boxes, e.g. manual
  entries or intake drafts, simply contribute nothing, and with zero boxes the
  script exits before touching the DOM).

## Rendering (pdf.js canvas)

The right pane is dual-mode:

1. **Default / fallback:** the existing `<iframe>` on `source_view_url` (the
   core `controller.php?document&retrieve...&as_file=false` route — no new
   endpoint), i.e. the browser's native PDF viewer. This is what no-JS
   browsers, non-PDF sources, and any pdf.js failure get.
2. **Citation view (progressive upgrade):** an inline
   `<script type="module">` dynamically `import()`s the vendored pdf.js
   (`public/assets/pdf.min.mjs`, worker `pdf.worker.min.mjs`, pdfjs-dist
   **4.10.38** — see `public/assets/README.md`), fetches the same
   `source_view_url` (same-origin, session cookie auth), and renders each page
   to a `<canvas>` sized to the pane (devicePixelRatio-scaled for crispness,
   capped at 25 pages). Only after page 1 renders successfully does it swap
   the iframe for the canvas view, so a failure at any earlier step leaves the
   iframe visible and untouched.

Each box is an absolutely positioned `<div>` over its page's canvas, placed
with **percentages** (`coord / 10`%), so normalized 0-1000 coordinates map to
the rendered page at any width with no pixel math and survive resizes. Boxes
are numbered to match the field rows' `#` column.

## Click-to-source interaction

- **Row → page:** the "Source" cell's `p.N` citation is a link. In citation
  view, clicking it scrolls the right pane to the cited page and flashes that
  field's box (amber highlight, ~1.2 s).
- **Page → row:** clicking a box scrolls the left pane to its field row and
  flashes it. Hovering a box highlights its row and vice versa.
- **View toggle:** a header link switches between "Open in PDF viewer"
  (native iframe, for zoom/search/print) and "Show citation boxes".

## Deep-link fallback (works without pdf.js)

The citation link's `href` is the raw document URL with a `#page=N` fragment,
targeted (`target="ccpSourceFrame"`) at the named iframe: without JS, without
pdf.js, or for a page beyond the render cap (the script then reveals the
iframe and lets the click through), the native PDF viewer navigates to the
cited page. Page-level navigation therefore always works; the box overlay is
additive precision on top.

## Asset serving / CSP considerations

- The two pdf.js files are plain static files under the module's
  `public/assets/`, served by Apache at
  `<webroot>/interface/modules/custom_modules/oe-module-clinical-copilot/public/assets/`
  — the same web path scheme the module's PHP endpoints already use; no new
  PHP endpoint, so `ops/api/openapi.yaml` and `OpenApiContractTest` are
  unaffected (that test only globs `public/*.php`).
- Everything is same-origin: library, module worker, and the PDF fetch — no
  CDN, so no `script-src`/`connect-src`/`worker-src` exceptions would be
  needed if a CSP is introduced. The overlay script itself is inline, which
  matches the module's existing inline-script convention; a future
  strict-CSP pass would need to nonce it along with every other inline script.
- `.mjs` must be served as a JavaScript MIME type for module loading; current
  Apache `mime.types` maps `mjs` to `text/javascript`. If a stripped-down
  server ever serves it as `text/plain`, the `import()` rejects and the page
  degrades to the iframe viewer — never a broken pane.
- Repo lint/CI: codespell skips `**/public/assets/*` and `npm run lint:js`
  globs only `**/*.js`, so the vendored `.mjs` bundles are outside both.

(Folded into the root `W2_ARCHITECTURE.md` — see its §11 bullet on the
shipped bbox overlay, which points back here for design and CSP detail.)

<?php

/**
 * Loads, validates against, and parses through the strict extraction schemas.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ingest;

/**
 * The single gatekeeper between raw VLM output and anything persisted. Callers
 * MUST run {@see self::validate()} and only build a {@see ParsedExtraction} via
 * {@see self::parse()} — there is no path from model JSON to a stored fact that
 * skips this class. A lightweight structural validator (no external JSON-Schema
 * dependency) is deliberate: it enforces exactly the contract fields the review
 * UI and ChartWriter rely on, and every check maps to a documented failure mode
 * ("VLM returned a value with no page citation", "field_key outside the intake
 * enum", etc.). {@see self::responseSchema()} returns the same schema for the
 * provider's constrained decoding, so the model is pushed toward valid output
 * and this validator is the backstop, never the only line of defense.
 */
final class ExtractionSchema
{
    /**
     * The provider-facing JSON Schema for constrained decoding
     * (generationConfig.responseSchema). On the Gemini API-key path this is run
     * through GeminiApiSchemaTranslator; on Vertex it is sent verbatim.
     *
     * @return array<string, mixed>
     */
    public static function responseSchema(DocType $docType): array
    {
        $path = __DIR__ . '/schema/' . $docType->schemaFile();
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Extraction schema not found for doc type: ' . $docType->value);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Extraction schema is not valid JSON for doc type: ' . $docType->value);
        }

        return $decoded;
    }

    /**
     * Structural validation. Returns a list of human-readable error strings;
     * an empty list means the payload satisfies the contract. This drives the
     * `schema_valid` eval rubric category.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    public static function validate(DocType $docType, array $payload): array
    {
        $errors = [];

        if (!isset($payload['fields']) || !is_array($payload['fields'])) {
            return ['payload.fields is missing or not an array'];
        }

        $allowedKeys = self::allowedFieldKeys($docType);

        foreach (array_values($payload['fields']) as $i => $field) {
            $at = "fields[{$i}]";
            if (!is_array($field)) {
                $errors[] = "{$at} is not an object";
                continue;
            }

            $key = $field['field_key'] ?? null;
            if (!is_string($key) || $key === '') {
                $errors[] = "{$at}.field_key is missing or empty";
            } elseif ($allowedKeys !== null && !in_array($key, $allowedKeys, true)) {
                $errors[] = "{$at}.field_key '{$key}' is not in the {$docType->value} field enum";
            }

            // value must be PRESENT but may be null (the "blank/illegible" case).
            if (!array_key_exists('value', $field)) {
                $errors[] = "{$at}.value is missing (must be present, may be null)";
            } elseif ($field['value'] !== null && !is_string($field['value'])) {
                $errors[] = "{$at}.value must be a string or null";
            }

            // A citation (page + quote) is required only for a LAB field that has
            // a value: labs drive the click-to-source bbox overlay, so every real
            // result must cite its page. INTAKE and MEDICATION-LIST citations are
            // OPTIONAL — welcome when the model can supply them (the review
            // screen shows a page link and quote beside the field), but requiring
            // them rejects the whole extraction and blanks the review whenever
            // the model returns a value without a clean page/quote — the original
            // intake breakage. And a field with no value has nothing to cite.
            $hasValue = array_key_exists('value', $field) && is_string($field['value']) && $field['value'] !== '';
            if ($hasValue && $docType === DocType::LabPdf) {
                if (!isset($field['page']) || !is_int($field['page']) || $field['page'] < 1) {
                    $errors[] = "{$at}.page must be a positive integer (citation is required for a value)";
                }

                if (!isset($field['quote']) || !is_string($field['quote']) || $field['quote'] === '') {
                    $errors[] = "{$at}.quote must be a non-empty string (citation is required for a value)";
                }
            } elseif ($hasValue && isset($field['page']) && (!is_int($field['page']) || $field['page'] < 1)) {
                // Even where a citation is optional (intake, medication list),
                // a page supplied alongside a real value must be a positive 1-based
                // integer. A page like "one", a quoted "3", 2.0, true, or a
                // zero/negative index can never address a real page in the
                // source document, so the extraction is refused rather than
                // persisting an uncheckable citation. Blank fields keep their
                // page:0 tolerance — with no value there is nothing to cite.
                $errors[] = "{$at}.page must be a positive integer when present";
            }

            if (isset($field['confidence'])) {
                $conf = $field['confidence'];
                if ((!is_int($conf) && !is_float($conf)) || $conf < 0 || $conf > 1) {
                    $errors[] = "{$at}.confidence must be a number in [0,1]";
                }
            }

            if (isset($field['bbox'])) {
                $bbox = $field['bbox'];
                if (!is_array($bbox) || count($bbox) !== 4) {
                    $errors[] = "{$at}.bbox must be a 4-element array";
                }
            }
        }

        return $errors;
    }

    /**
     * Builds the typed {@see ParsedExtraction} from an already-validated
     * payload. Every lab field gets a {@see SourceCitation} pointing back at
     * the source document; intake fields get one only when the model included
     * the optional page/quote (a missing intake citation is normal, never an
     * error). On the human path, `value` starts equal to the model's value
     * (unedited); the review UI applies {@see ExtractedField::withHumanValue()}.
     *
     * @param array<string, mixed> $payload
     */
    public static function parse(DocType $docType, array $payload, string $sourceId): ParsedExtraction
    {
        $fields = [];
        $rawFields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];

        foreach (array_values($rawFields) as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $key = is_string($raw['field_key'] ?? null) ? $raw['field_key'] : null;
            if ($key === null || $key === '') {
                continue;
            }

            $vlmValue = array_key_exists('value', $raw) && is_string($raw['value']) ? $raw['value'] : null;
            $unit = is_string($raw['unit'] ?? null) ? $raw['unit'] : null;
            $refRange = is_string($raw['reference_range'] ?? null) ? $raw['reference_range'] : null;
            $abnormal = is_string($raw['abnormal_flag'] ?? null) ? $raw['abnormal_flag'] : null;
            $confidence = (is_int($raw['confidence'] ?? null) || is_float($raw['confidence'] ?? null))
                ? (float)$raw['confidence']
                : null;

            $page = is_int($raw['page'] ?? null) ? $raw['page'] : null;
            $quote = is_string($raw['quote'] ?? null) ? $raw['quote'] : '';
            $bbox = null;
            if (isset($raw['bbox']) && is_array($raw['bbox']) && count($raw['bbox']) === 4) {
                $c = array_values($raw['bbox']);
                $bbox = new BoundingBox((int)$c[0], (int)$c[1], (int)$c[2], (int)$c[3]);
            }

            // Citation building is best-effort by design: labs always have one
            // (validate() enforces page+quote per valued result); intake gets
            // one only when the model volunteered it — its schema lists
            // page/quote as OPTIONAL, and their absence must never fail the
            // extraction. When a (validated) page arrives without a quote, the
            // field's own value stands in as quote_or_value — the spec's
            // citation shape explicitly allows either — so a usable page is
            // never dropped. No page and no quote simply means no citation.
            $citationText = $quote !== '' ? $quote : ($vlmValue ?? '');
            $citation = ($quote !== '' || ($page !== null && $citationText !== ''))
                ? new SourceCitation(SourceType::Document, $sourceId, $page, $key, $citationText, $bbox)
                : null;

            $fields[] = new ExtractedField(
                fieldKey: $key,
                vlmValue: $vlmValue,
                value: $vlmValue,
                unit: $unit,
                refRange: $refRange,
                abnormalFlag: $abnormal,
                citation: $citation,
                confidence: $confidence,
            );
        }

        // Top-level document-header identity (labs carry patient_name/patient_dob
        // so the report can be matched to the chart it is uploaded onto). Intake
        // schemas have no such keys, so these stay null there.
        $patientName = is_string($payload['patient_name'] ?? null) ? $payload['patient_name'] : null;
        $patientDob = is_string($payload['patient_dob'] ?? null) ? $payload['patient_dob'] : null;

        return new ParsedExtraction(
            $docType,
            $fields,
            $patientName,
            $patientDob,
            self::normalizeCollectionDate($payload['collection_date'] ?? null),
        );
    }

    /**
     * The printed specimen collection date, or null. Like its header siblings
     * (`patient_name`/`patient_dob`), `collection_date` is deliberately NOT
     * checked in {@see self::validate()}: it is advisory metadata the reviewer
     * can always override on the review screen, so a garbage date must degrade
     * to null (the screen then defaults to today) rather than reject the whole
     * extraction and blank every lab result the model DID read correctly. The
     * `page` citation check (W1b) is the deliberate contrast — a bad page makes
     * a citation uncheckable, which is a per-field integrity failure, not
     * prefill metadata. Only a strict, real calendar date in Y-m-d (the format
     * the schema and prompt request) survives; everything else is null.
     */
    private static function normalizeCollectionDate(mixed $raw): ?string
    {
        if (!is_string($raw) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) !== 1) {
            return null;
        }

        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $raw : null;
    }

    /**
     * A blank extraction for the manual-entry / degraded path: no model ran (or
     * its output was rejected), so there is nothing to verify against. For an
     * intake form we pre-seed one empty field per enum key so the review page
     * renders the full form to hand-fill; a medication list gets the same
     * treatment — one blank attribute row per enum key, i.e. a single blank
     * medication skeleton to hand-fill; for a lab report there is no fixed
     * field set, so the physician adds result rows themselves. Every value is
     * null and `vlmValue` is null, so these fields never count toward extraction
     * accuracy (there was no model claim to be right or wrong about).
     */
    public static function blankExtraction(DocType $docType): ParsedExtraction
    {
        $keys = self::allowedFieldKeys($docType) ?? [];
        $fields = [];
        foreach ($keys as $key) {
            $fields[] = new ExtractedField(fieldKey: $key, vlmValue: null, value: null);
        }

        return new ParsedExtraction($docType, $fields);
    }

    /**
     * The closed field_key enum for a doc type, or null when field_key is open
     * (lab test names are free text). Intake AND medication lists are closed:
     * intake keys name form fields, medication keys name the per-medication
     * attributes (medication_name/dose/route/frequency/prn/prescriber) —
     * emitted as document-order runs, each starting at a `medication_name`.
     *
     * @return list<string>|null
     */
    private static function allowedFieldKeys(DocType $docType): ?array
    {
        if ($docType === DocType::LabPdf) {
            return null;
        }

        $schema = self::responseSchema($docType);
        $enum = $schema['properties']['fields']['items']['properties']['field_key']['enum'] ?? null;
        if (!is_array($enum)) {
            return null;
        }

        return array_values(array_filter($enum, static fn ($v): bool => is_string($v)));
    }
}

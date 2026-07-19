<?php

/**
 * Groups guideline retrieval by clinical topic — the summarizer/chat augmentation surface.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Rag;

/**
 * The evidence half of "what should I pay attention to, and what evidence
 * supports the recommendation." It maps a curated, CLOSED set of clinical
 * topics onto retriever queries + analyte tags, so the physician (or a caller
 * that derived the patient's notable analytes) gets cited guideline evidence
 * grouped by topic. Deliberately closed-vocabulary — no free-text query surface
 * (consistent with the chat catalog's design), and PHI-free (the corpus has no
 * patient scoping). This is kept OFF the Week 1 fact/verifier pipeline on
 * purpose: guideline evidence carries `SourceType::Guideline` corpus citations
 * and renders as its own section, never as a verified patient-fact claim.
 */
final class PatientEvidenceService
{
    /**
     * Curated topics → {label, query, tags}. The tags line up with the corpus
     * chunk tags so retrieval is analyte-aware.
     *
     * @var array<string, array{label: string, query: string, tags: list<string>}>
     */
    private const TOPICS = [
        'a1c' => ['label' => 'Glycemic control (A1c)', 'query' => 'A1c glycemic target and monitoring', 'tags' => ['a1c', 'glycemic', 'monitoring']],
        'lipids' => ['label' => 'Lipids & statin therapy', 'query' => 'lipid management statin LDL triglycerides', 'tags' => ['lipids', 'ldl', 'statin', 'triglycerides']],
        'kidney' => ['label' => 'Kidney (UACR / eGFR)', 'query' => 'kidney screening albumin creatinine ratio', 'tags' => ['acr', 'uacr', 'kidney', 'egfr']],
        'blood_pressure' => ['label' => 'Blood pressure', 'query' => 'blood pressure target in diabetes', 'tags' => ['blood_pressure', 'hypertension', 'bp']],
        'medications' => ['label' => 'Pharmacologic therapy', 'query' => 'first line pharmacologic therapy metformin SGLT2 GLP-1', 'tags' => ['metformin', 'medications', 'sglt2', 'glp1']],
        'hypoglycemia' => ['label' => 'Hypoglycemia', 'query' => 'hypoglycemia assessment insulin sulfonylurea', 'tags' => ['hypoglycemia', 'insulin', 'sulfonylurea']],
    ];

    public function __construct(private readonly RetrieverInterface $retriever)
    {
    }

    public static function createDefault(): self
    {
        // The factory returns the external knowledge-store retriever when the
        // Postgres is configured, else the offline corpus pipeline — so the
        // evidence page and the summarizer draw from the same source.
        return new self(GuidelineRetrieverFactory::createDefault());
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function availableTopics(): array
    {
        $out = [];
        foreach (self::TOPICS as $key => $topic) {
            $out[] = ['key' => $key, 'label' => $topic['label']];
        }

        return $out;
    }

    public static function isTopic(string $key): bool
    {
        return isset(self::TOPICS[$key]);
    }

    /**
     * Maps the summarizer's fine-grained analyte keys
     * ({@see \OpenEMR\Modules\ClinicalCopilot\Capability\Config\AnalyteCodeSets::analyteKeyForLoinc()}:
     * a1c, glucose, cholesterol, ldl, hdl, triglycerides, acr) onto this
     * service's closed topic vocabulary — the SAME "topics from patient data"
     * derivation, whichever surface asks. Deliberately still closed on both
     * sides: an unrecognized analyte key maps to nothing rather than becoming
     * a free-text query.
     *
     * @param list<string> $analyteKeys
     *
     * @return list<string> topic keys, deduplicated, in {@see self::TOPICS} order
     */
    public static function topicsForAnalyteKeys(array $analyteKeys): array
    {
        $topicByAnalyte = [
            'a1c' => 'a1c',
            'glucose' => 'a1c',
            'cholesterol' => 'lipids',
            'ldl' => 'lipids',
            'hdl' => 'lipids',
            'triglycerides' => 'lipids',
            'acr' => 'kidney',
        ];

        /** @var array<string, true> $selected */
        $selected = [];
        foreach ($analyteKeys as $key) {
            $topic = $topicByAnalyte[$key] ?? null;
            if ($topic !== null) {
                $selected[$topic] = true;
            }
        }

        // TOPICS order, so rendered groups follow the same clinical order as
        // the evidence tab regardless of the order analytes arrived in.
        $ordered = [];
        foreach (array_keys(self::TOPICS) as $topicKey) {
            if (isset($selected[$topicKey])) {
                $ordered[] = $topicKey;
            }
        }

        return $ordered;
    }

    /**
     * Retrieves cited guideline evidence for each requested topic.
     *
     * @param list<string> $topicKeys
     *
     * @return list<array{key: string, label: string, snippets: list<EvidenceSnippet>}>
     */
    public function forTopics(array $topicKeys, int $perTopic = 2): array
    {
        $groups = [];
        foreach ($topicKeys as $key) {
            if (!isset(self::TOPICS[$key])) {
                continue;
            }
            $topic = self::TOPICS[$key];
            $groups[] = [
                'key' => $key,
                'label' => $topic['label'],
                'snippets' => $this->retriever->retrieve($topic['query'], $topic['tags'], $perTopic),
            ];
        }

        return $groups;
    }
}

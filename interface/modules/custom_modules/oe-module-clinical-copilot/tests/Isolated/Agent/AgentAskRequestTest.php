<?php

/**
 * The public/agent.php boundary parser: raw POST input becomes one typed request or a user-safe rejection.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Agent;

use OpenEMR\Modules\ClinicalCopilot\Agent\AgentAskRequest;
use OpenEMR\Modules\ClinicalCopilot\Agent\InvalidAgentAskException;
use OpenEMR\Modules\ClinicalCopilot\Ingest\DocType;
use OpenEMR\Modules\ClinicalCopilot\Ingest\UploadedDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Failure modes guarded: a request with no (or a forged, non-numeric) pid
 * reaching the supervisor; an empty or unbounded question reaching the LLM;
 * an attacker-chosen evidence tag escaping the closed topic vocabulary into
 * the retrieval query; a document accepted without a declared (or with an
 * unrecognized) doc_type, which would pick the wrong extraction schema; and
 * the parsed request mapping fields onto the wrong AgentRequest slots
 * (question/tags/document transposition), which would silently change the
 * supervisor's routing decision.
 */
final class AgentAskRequestTest extends TestCase
{
    private static function upload(): UploadedDocument
    {
        return new UploadedDocument('%PDF-1.4 stub bytes', 'lab.pdf', 'application/pdf');
    }

    public function testParsesAFullRequestAndMapsItOntoTheAgentRequest(): void
    {
        $ask = AgentAskRequest::fromPost('42', '  What was the last A1c?  ', 'a1c, lipids ,bogus_topic', 'lab_pdf', self::upload());

        self::assertSame(42, $ask->pid);
        self::assertSame('What was the last A1c?', $ask->question);
        self::assertSame(['a1c', 'lipids'], $ask->tags, 'unknown topics must be dropped, valid ones kept in order');
        self::assertSame(DocType::LabPdf, $ask->docType);

        $request = $ask->toAgentRequest('corr-test-1');
        self::assertSame(42, $request->pid);
        self::assertSame('corr-test-1', $request->correlationId);
        self::assertSame('What was the last A1c?', $request->question);
        self::assertSame(['a1c', 'lipids'], $request->tags);
        self::assertTrue($request->hasDocument(), 'a parsed upload must route to the intake-extractor worker');
        self::assertSame('application/pdf', $request->mimeType);
        self::assertTrue($request->needsEvidence());
    }

    public function testQuestionOnlyRequestHasNoDocumentAndNoTags(): void
    {
        $ask = AgentAskRequest::fromPost(7, 'Anything overdue?', null, null, null);

        self::assertSame([], $ask->tags);
        self::assertNull($ask->docType);
        self::assertNull($ask->document);

        $request = $ask->toAgentRequest('corr-test-2');
        self::assertFalse($request->hasDocument());
        self::assertTrue($request->needsEvidence());
    }

    /**
     * @param array{mixed, mixed, mixed, mixed, ?UploadedDocument} $args
     */
    #[DataProvider('rejectedInputProvider')]
    public function testRejectsInvalidInputWithAUserSafeReason(array $args, string $expectedReasonFragment): void
    {
        try {
            AgentAskRequest::fromPost(...$args);
            self::fail('expected InvalidAgentAskException');
        } catch (InvalidAgentAskException $e) {
            self::assertStringContainsString($expectedReasonFragment, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{array{mixed, mixed, mixed, mixed, ?UploadedDocument}, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function rejectedInputProvider(): array
    {
        return [
            'missing pid' => [[null, 'q', null, null, null], 'pid'],
            'non-numeric pid' => [['abc', 'q', null, null, null], 'pid'],
            'zero pid' => [['0', 'q', null, null, null], 'pid'],
            'negative pid' => [['-3', 'q', null, null, null], 'pid'],
            'missing question' => [['1', null, null, null, null], 'question'],
            'blank question' => [['1', "  \t ", null, null, null], 'question'],
            'non-string question' => [['1', ['array'], null, null, null], 'question'],
            'oversized question' => [['1', str_repeat('a', 4001), null, null, null], 'too long'],
            'document without doc_type' => [['1', 'q', null, null, self::upload()], 'doc_type'],
            'document with unknown doc_type' => [['1', 'q', null, 'radiology_pdf', self::upload()], 'doc_type'],
        ];
    }

    public function testDocTypeWithoutDocumentIsIgnored(): void
    {
        // doc_type is only meaningful alongside an upload; a stray value must
        // not fabricate a document route.
        $ask = AgentAskRequest::fromPost('1', 'q', null, 'lab_pdf', null);

        self::assertNull($ask->docType);
        self::assertFalse($ask->toAgentRequest('corr-test-3')->hasDocument());
    }
}

<?php

/**
 * KnowledgeBaseConfig — URL and discrete env forms parse to the same settings.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Tests\Isolated\Knowledge;

use OpenEMR\Modules\ClinicalCopilot\Knowledge\KnowledgeBaseConfig;
use PHPUnit\Framework\TestCase;

/**
 * Guards the two failure modes that would silently misconfigure the store: a
 * blank/garbled config reading as "configured" (and then throwing on a page
 * load), and the password leaking into the loggable DSN string.
 */
final class KnowledgeBaseConfigTest extends TestCase
{
    public function testBlankConfigIsNotConfigured(): void
    {
        $config = new KnowledgeBaseConfig('', 5432, '', '', '', 'prefer', 'guideline_chunks');

        self::assertFalse($config->isConfigured());
    }

    public function testHostAndDbNameAreTheMinimumForConfigured(): void
    {
        self::assertTrue(
            (new KnowledgeBaseConfig('db.host', 5432, 'kb', 'u', '', 'require', 'guideline_chunks'))->isConfigured(),
            'a missing password is legal (trust/peer auth) — host + db name suffice',
        );
        self::assertFalse(
            (new KnowledgeBaseConfig('db.host', 5432, '', 'u', 'p', 'require', 'guideline_chunks'))->isConfigured(),
        );
    }

    public function testWriteRoleFallsBackToTheReadUserWhenUnset(): void
    {
        $noWriteRole = new KnowledgeBaseConfig('h', 5432, 'kb', 'reader', 'rpw', 'require', 'guideline_chunks');
        self::assertSame('reader', $noWriteRole->effectiveWriteUser());
        self::assertSame('rpw', $noWriteRole->effectiveWritePassword());

        $withWriteRole = new KnowledgeBaseConfig('h', 5432, 'kb', 'reader', 'rpw', 'require', 'guideline_chunks', 'writer', 'wpw');
        self::assertSame('writer', $withWriteRole->effectiveWriteUser());
        self::assertSame('wpw', $withWriteRole->effectiveWritePassword());
    }

    public function testDsnCarriesTargetButNeverThePassword(): void
    {
        $dsn = (new KnowledgeBaseConfig('db.host', 5433, 'kb', 'u', 'sup3r-secret', 'require', 'guideline_chunks'))->dsn();

        self::assertStringContainsString('host=db.host', $dsn);
        self::assertStringContainsString('port=5433', $dsn);
        self::assertStringContainsString('dbname=kb', $dsn);
        self::assertStringContainsString('sslmode=require', $dsn);
        self::assertStringNotContainsString('sup3r-secret', $dsn);
    }
}

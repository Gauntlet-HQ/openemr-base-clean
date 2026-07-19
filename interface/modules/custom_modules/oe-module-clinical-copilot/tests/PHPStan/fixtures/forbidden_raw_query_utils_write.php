<?php

// Fixture for ForbiddenWriteOutsideRepositoriesRuleTest. Declares a minimal
// stand-in for QueryUtils (never the real class -- PHPStan only parses this
// file's AST, it never `require`s it, so there is no runtime redeclaration
// risk against the real project classes) so the fixture is fully
// self-contained and needs no project autoloading at all.

namespace OpenEMR\Common\Database {
    final class QueryUtils
    {
        /**
         * @param array<int, mixed> $binds
         */
        public static function sqlInsert(string $sql, array $binds = []): int
        {
            return 0;
        }
    }
}

namespace OpenEMR\Modules\ClinicalCopilot\Fixtures {

    use OpenEMR\Common\Database\QueryUtils;

    /**
     * NOT one of ForbiddenWriteOutsideRepositoriesRule::WHITELISTED_REPOSITORIES
     * -- calling QueryUtils::sqlInsert() directly from here must be flagged.
     */
    final class NotARepository
    {
        public function doSomething(): void
        {
            QueryUtils::sqlInsert('INSERT INTO `mod_copilot_doc` (`pid`) VALUES (?)', [1]);
        }
    }
}

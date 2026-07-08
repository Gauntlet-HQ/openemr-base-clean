<?php

// Fixture for ForbiddenWriteOutsideRepositoriesRuleTest -- the "passes"
// counterpart to forbidden_raw_query_utils_write.php. Same fake QueryUtils
// stand-in, but the caller is named exactly one of
// ForbiddenWriteOutsideRepositoriesRule::WHITELISTED_REPOSITORIES
// (OpenEMR\Modules\ClinicalCopilot\DocStore), so calling QueryUtils::sqlInsert()
// from inside it must NOT be flagged.

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

namespace OpenEMR\Modules\ClinicalCopilot {

    use OpenEMR\Common\Database\QueryUtils;

    final class DocStore
    {
        public function insert(mixed $newDoc): int
        {
            return QueryUtils::sqlInsert('INSERT INTO `mod_copilot_doc` (`pid`) VALUES (?)', [1]);
        }
    }
}

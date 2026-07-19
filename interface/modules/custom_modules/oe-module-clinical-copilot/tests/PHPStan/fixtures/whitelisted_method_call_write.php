<?php

// Fixture for ForbiddenWriteOutsideRepositoriesRuleTest -- the "passes"
// counterpart to forbidden_method_call_write.php. `$docStore`'s type IS
// OpenEMR\Modules\ClinicalCopilot\DocStore (one of
// ForbiddenWriteOutsideRepositoriesRule::WHITELISTED_REPOSITORIES), so
// calling its own insert() method from a DIFFERENT, non-repository class --
// exactly the sanctioned "write through the repository" pattern used
// throughout src/ (e.g. SynthesisReadPath calling $this->docStore->insert())
// -- must NOT be flagged.

namespace OpenEMR\Modules\ClinicalCopilot {
    final class DocStore
    {
        public function insert(mixed $newDoc): int
        {
            return 0;
        }
    }
}

namespace OpenEMR\Modules\ClinicalCopilot\Fixtures {

    use OpenEMR\Modules\ClinicalCopilot\DocStore;

    final class SomeOrchestrator
    {
        public function __construct(private DocStore $docStore)
        {
        }

        public function doSomething(): void
        {
            $this->docStore->insert('a doc');
        }
    }
}

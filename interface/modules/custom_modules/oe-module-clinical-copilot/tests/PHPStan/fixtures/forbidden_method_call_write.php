<?php

// Fixture for ForbiddenWriteOutsideRepositoriesRuleTest -- check 2 (a host
// service's own insert()/update()/save()/delete() method, called from
// outside the whitelisted repositories). A fake stand-in for "some host
// service exposing a write API", never the real project code.

namespace OpenEMR\Services\Fake {
    final class SomeHostService
    {
        public function insert(mixed $row): int
        {
            return 0;
        }
    }
}

namespace OpenEMR\Modules\ClinicalCopilot\Fixtures {

    use OpenEMR\Services\Fake\SomeHostService;

    /**
     * NOT one of ForbiddenWriteOutsideRepositoriesRule::WHITELISTED_REPOSITORIES,
     * and `$service`'s type is not whitelisted either -- calling
     * `$service->insert(...)` must be flagged.
     */
    final class NotARepositoryCallingAHostService
    {
        public function __construct(private SomeHostService $service)
        {
        }

        public function doSomething(): void
        {
            $this->service->insert('a row');
        }
    }
}

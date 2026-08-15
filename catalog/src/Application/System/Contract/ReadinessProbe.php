<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\System\Contract;

/**
 * One independently replaceable production-readiness dependency.
 *
 * Implementations live in Infrastructure. Application orchestration only needs
 * a stable name and a success/failure signal, so database/filesystem details do
 * not leak inward.
 */
interface ReadinessProbe
{
    public function name(): string;

    /**
     * Return true when the dependency is ready to serve the current application
     * role. Implementations may throw; the readiness service converts failures
     * into an unhealthy result so one failed dependency never hides the others.
     */
    public function ready(): bool;
}

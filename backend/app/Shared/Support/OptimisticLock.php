<?php

namespace App\Shared\Support;

use App\Shared\Exceptions\OptimisticLockException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

final class OptimisticLock
{
    public const INITIAL_VERSION = 0;

    public static function assertVersion(?int $requestVersion, int $loadedVersion): void
    {
        if ($requestVersion !== $loadedVersion) {
            throw new OptimisticLockException;
        }
    }

    /**
     * Persist dirty database columns for one existing primary key.
     * The caller supplies lifecycle values and owns the business transaction.
     */
    public static function update(
        string $table,
        string $primaryKey,
        int $id,
        int $expectedVersion,
        array $changes,
        ?ConnectionInterface $connection = null,
    ): int {
        unset($changes['version_number']);
        if ($changes === []) {
            return $expectedVersion;
        }

        // QUIRK-NEW: Hibernate's Long version wraps at Long.MAX_VALUE instead of rejecting the update.
        $nextVersion = $expectedVersion === PHP_INT_MAX ? PHP_INT_MIN : $expectedVersion + 1;
        $changes['version_number'] = $nextVersion;

        $affected = ($connection ?? DB::connection())->table($table)
            ->where($primaryKey, $id)
            ->where('version_number', $expectedVersion)
            ->update($changes);

        if ($affected !== 1) {
            throw new OptimisticLockException;
        }

        return $nextVersion;
    }
}

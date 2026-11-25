<?php

namespace App\Models\Concerns;

/**
 * Oracle stores '' as NULL in VARCHAR2 and CLOB columns. Only the values sent to the
 * database change: like a Hibernate entity, the model keeps '' until it is reloaded.
 */
trait StoresEmptyStringAsNull
{
    /**
     * Also read by the repositories that save through OptimisticLock.
     *
     * @return array<string, mixed>
     */
    public function getDirty()
    {
        return $this->emptyStringsToNull(parent::getDirty());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert()
    {
        return $this->emptyStringsToNull(parent::getAttributesForInsert());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function emptyStringsToNull(array $attributes): array
    {
        return array_map(fn (mixed $value) => $value === '' ? null : $value, $attributes);
    }
}

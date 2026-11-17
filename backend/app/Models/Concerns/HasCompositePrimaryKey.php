<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent only supports a single-column primary key. Models using this trait keep
 * $primaryKey set to the first key column (so delete() does not refuse to run) and
 * filter every save, delete, fresh and refresh query by all columns of the key.
 * Use findByCompositeKey() instead of find(), which only looks at $primaryKey.
 */
trait HasCompositePrimaryKey
{
    /**
     * @return list<string>
     */
    abstract public function getCompositeKeyNames(): array;

    public function getIncrementing(): bool
    {
        return false;
    }

    /**
     * @param  array<string, mixed>  $keyValues  column => value for every key column
     */
    public static function findByCompositeKey(array $keyValues): ?static
    {
        $query = static::query();

        foreach ($query->getModel()->getCompositeKeyNames() as $column) {
            $query->where($column, '=', $keyValues[$column]);
        }

        return $query->first();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery($query)
    {
        return $this->whereCompositeKey($query);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery($query)
    {
        return $this->whereCompositeKey($query);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    private function whereCompositeKey($query)
    {
        foreach ($this->getCompositeKeyNames() as $column) {
            $query->where($column, '=', $this->original[$column] ?? $this->getAttribute($column));
        }

        return $query;
    }
}

<?php

namespace App\Modules\Appointment\Repositories;

use App\Shared\Support\OptimisticLock;
use Illuminate\Database\Eloquent\Model;

abstract class VersionedRepository
{
    /**
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    protected function saveVersioned(Model $model): Model
    {
        if (! $model->exists) {
            $model->save();
        } elseif ($model->isDirty()) {
            $dispatcher = $model::getEventDispatcher();
            $dispatcher?->until('eloquent.saving: '.$model::class, $model);
            $dispatcher?->until('eloquent.updating: '.$model::class, $model);
            $version = OptimisticLock::update($model->getTable(), $model->getKeyName(), $model->getKey(),
                (int) $model->getRawOriginal('version_number'), $model->getDirty(), $model->getConnection());
            $model->setAttribute('version_number', $version);
            $model->syncChanges();
            $model->syncOriginal();
            $dispatcher?->dispatch('eloquent.updated: '.$model::class, $model);
            $dispatcher?->dispatch('eloquent.saved: '.$model::class, $model);
        }

        return $model;
    }
}

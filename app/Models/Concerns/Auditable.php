<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes an audit_logs row on create/update/delete. Updates record the
 * old and new value of every changed attribute; hidden attributes are
 * masked. Add attribute names to $auditExcluded on the model to skip them.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => static::writeAudit($model, 'created'));

        static::updated(function (Model $model) {
            $changes = $model->auditableChanges();
            if ($changes !== []) {
                static::writeAudit($model, 'updated', $changes);
            }
        });

        static::deleted(fn (Model $model) => static::writeAudit($model, 'deleted'));
    }

    protected function auditableChanges(): array
    {
        $excluded = array_merge(
            ['created_at', 'updated_at', 'remember_token'],
            property_exists($this, 'auditExcluded') ? $this->auditExcluded : [],
        );

        $changes = [];
        foreach ($this->getChanges() as $attribute => $new) {
            if (in_array($attribute, $excluded, true)) {
                continue;
            }
            $changes[$attribute] = in_array($attribute, $this->getHidden(), true)
                ? ['old' => '[hidden]', 'new' => '[hidden]']
                : ['old' => $this->getOriginal($attribute), 'new' => $new];
        }

        return $changes;
    }

    protected static function writeAudit(Model $model, string $action, ?array $changes = null): void
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'company_id' => $model instanceof Company
                ? ($model->exists ? $model->getKey() : null)
                : ($model->company_id ?? null),
            'action' => $action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'changes' => $changes,
        ]);
    }
}

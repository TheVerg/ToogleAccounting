<?php

namespace App\Services\Compliance;

use InvalidArgumentException;

/**
 * The single source of truth for which return types can be opened as a return
 * workspace. Anything not registered here is calendar-only — see
 * ComplianceDeadline::TYPES and docs/COMPLIANCE.md.
 *
 * Registering a type is a statement that it has real working papers, its own
 * blocking exceptions, its own evidence CSV and a considered answer on whether
 * approval locks the period. Do not register a type to make a dropdown longer.
 */
class ComplianceReturnTypeRegistry
{
    /** @var array<string, ReturnWorkspace> */
    protected array $types = [];

    public function __construct(Vat7Workspace $vat7, PayeP2Workspace $payeP2, QpdWorkspace $qpd)
    {
        foreach ([$vat7, $payeP2, $qpd] as $workspace) {
            $this->types[$workspace->key()] = $workspace;
        }
    }

    public function has(?string $type): bool
    {
        return $type !== null && isset($this->types[$type]);
    }

    public function for(string $type): ReturnWorkspace
    {
        return $this->types[$type] ?? throw new InvalidArgumentException("No compliance workspace for return type [{$type}].");
    }

    /** The type backing the "live working papers" view when no return is selected. */
    public function default(): ReturnWorkspace
    {
        return $this->types['VAT7'];
    }

    /** @return array<string> */
    public function keys(): array
    {
        return array_keys($this->types);
    }
}

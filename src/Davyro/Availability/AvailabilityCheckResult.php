<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\Availability;

final readonly class AvailabilityCheckResult
{
    /** @param array<int, array<string, mixed>> $warnings */
    public function __construct(public array $warnings)
    {
    }

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }

    /** @return array{available:bool,requires_confirmation:bool,warnings:array<int, array<string, mixed>>,automatically_rejected:false} */
    public function toArray(): array
    {
        return [
            'available' => !$this->hasWarnings(),
            'requires_confirmation' => $this->hasWarnings(),
            'warnings' => $this->warnings,
            'automatically_rejected' => false,
        ];
    }
}

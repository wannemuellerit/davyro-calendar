<?php

declare(strict_types=1);

namespace AgenDAV\Davyro\WebCal;

interface WebCalReferenceResolver
{
    public function resolve(string $reference): string;
}

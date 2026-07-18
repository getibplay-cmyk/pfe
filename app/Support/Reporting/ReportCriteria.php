<?php

namespace App\Support\Reporting;

use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReportCriteria
{
    /**
     * @param  array<int, int>  $agencyIds
     */
    public function __construct(
        public int $tenantId,
        public array $agencyIds,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $timezone,
        public ?string $currency = null,
    ) {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Le tenant du rapport est obligatoire.');
        }

        if ($agencyIds === [] || collect($agencyIds)->contains(fn (mixed $id): bool => ! is_int($id) || $id <= 0)) {
            throw new InvalidArgumentException('Le rapport requiert au moins une agence autorisée.');
        }

        if (! $startsAt->lessThan($endsAt)) {
            throw new InvalidArgumentException('La fin exclusive doit être postérieure au début du rapport.');
        }

        new DateTimeZone($timezone);

        if ($currency !== null && preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('La devise du rapport doit respecter ISO 4217.');
        }
    }

    /**
     * Convertit les dates inclusives de l'interface en intervalle métier [début, fin).
     *
     * @param  array<int, int>  $agencyIds
     */
    public static function fromInclusiveDates(
        int $tenantId,
        array $agencyIds,
        string $dateFrom,
        string $dateTo,
        string $timezone,
        ?string $currency = null,
    ): self {
        $startsAt = CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom, $timezone)->startOfDay();
        $endsAt = CarbonImmutable::createFromFormat('!Y-m-d', $dateTo, $timezone)->addDay()->startOfDay();

        return new self(
            $tenantId,
            array_values(array_unique(array_map('intval', $agencyIds))),
            $startsAt,
            $endsAt,
            $timezone,
            $currency === null ? null : strtoupper($currency),
        );
    }

    public function dateFrom(): string
    {
        return $this->startsAt->setTimezone($this->timezone)->toDateString();
    }

    public function dateTo(): string
    {
        return $this->endsAt->setTimezone($this->timezone)->subDay()->toDateString();
    }

    public function durationSeconds(): int
    {
        return $this->startsAt->diffInSeconds($this->endsAt);
    }
}

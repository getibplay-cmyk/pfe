<?php

namespace App\Support\Reservations;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ReservationPeriodValidator
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function future(mixed $startsAt, mixed $endsAt): array
    {
        $timezone = config('app.timezone');
        $start = CarbonImmutable::parse($startsAt, $timezone)->setTimezone($timezone);
        $end = CarbonImmutable::parse($endsAt, $timezone)->setTimezone($timezone);

        if ($end->lte($start)) {
            throw ValidationException::withMessages(['ends_at' => 'La fin doit être strictement postérieure au début.']);
        }

        if ($start->lt(CarbonImmutable::now($timezone))) {
            throw ValidationException::withMessages(['starts_at' => 'Le début de la réservation ne peut pas être dans le passé.']);
        }

        if ($start->diffInDays($end) > config('reservations.maximum_duration_days')) {
            throw ValidationException::withMessages(['ends_at' => 'La durée dépasse la limite configurable.']);
        }

        return [$start, $end];
    }
}

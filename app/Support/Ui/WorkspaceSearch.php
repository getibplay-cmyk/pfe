<?php

namespace App\Support\Ui;

use App\Models\Customer;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

final class WorkspaceSearch
{
    public const RESOURCES = [
        'vehicles' => [Vehicle::class, ['registration_number', 'brand', 'model'], 'Véhicules'],
        'customers' => [Customer::class, ['first_name', 'last_name', 'company_name'], 'Clients'],
        'reservations' => [Reservation::class, ['reservation_number'], 'Réservations'],
        'contracts' => [RentalContract::class, ['contract_number'], 'Contrats'],
    ];

    public function search(User $user, string $term): array
    {
        if (mb_strlen($term) < 2) {
            return [];
        }
        $results = [];
        $like = '%'.addcslashes($term, '\\%_').'%';
        foreach (self::RESOURCES as $kind => [$model, $columns]) {
            if (! Gate::forUser($user)->allows('viewAny', $model)) {
                continue;
            }
            $records = $model::query()->when($user->agency_id !== null, fn ($q) => $q->where('agency_id', $user->agency_id))
                ->where(function ($q) use ($columns, $like) {
                    foreach ($columns as $column) {
                        $q->orWhere($column, 'ilike', $like);
                    }
                })->orderByDesc('id')->limit(5)->get();
            foreach ($records as $record) {
                if (Gate::forUser($user)->allows('view', $record)) {
                    $results[] = $this->present($kind, $record);
                }
            }
        }

        return $results;
    }

    public function resolve(User $user, string $kind, int $id): ?array
    {
        $model = self::RESOURCES[$kind][0] ?? null;
        if (! $model || ! Gate::forUser($user)->allows('viewAny', $model)) {
            return null;
        }
        $record = $model::query()->whereKey($id)
            ->when($user->agency_id !== null, fn ($q) => $q->where('agency_id', $user->agency_id))->first();

        return $record && Gate::forUser($user)->allows('view', $record) ? $this->present($kind, $record) : null;
    }

    private function present(string $kind, Model $record): array
    {
        return [
            'kind' => $kind, 'id' => $record->id, 'group' => __(self::RESOURCES[$kind][2]),
            'label' => match ($kind) {
                'vehicles' => $record->registration_number.' · '.$record->brand.' '.$record->model,
                'customers' => $record->displayName(),
                'reservations' => $record->reservation_number,
                'contracts' => $record->contract_number,
            },
            'url' => route($kind.'.show', $record),
        ];
    }
}

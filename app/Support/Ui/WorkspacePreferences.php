<?php

namespace App\Support\Ui;

use App\Models\User;
use App\Support\Tenancy\AgencyAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkspacePreferences
{
    public const SCREENS = [
        'vehicles.index' => ['vehicle.view', ['agency_id', 'category_id', 'status'], 'Véhicules'],
        'customers.index' => ['customer.view', ['q', 'status'], 'Clients'],
        'reservations.index' => ['reservation.view', ['agency_id', 'status', 'q'], 'Réservations'],
        'contracts.index' => ['contract.view', ['status', 'q'], 'Contrats'],
        'fleet.planning.index' => ['vehicle.view', ['agency_id', 'category_id', 'status', 'q', 'date', 'days'], 'Planning de flotte'],
    ];

    public function __construct(private WorkspaceSearch $search) {}

    public function read(User $user): array
    {
        $preferences = $user->workspace_preferences ?? [];

        return ($preferences['tenant_id'] ?? null) === $user->tenant_id ? $preferences : ['tenant_id' => $user->tenant_id, 'favorites' => [], 'filters' => []];
    }

    public function visible(User $user): array
    {
        $preferences = $this->read($user);
        $favorites = [];
        foreach ($preferences['favorites'] ?? [] as $saved) {
            $result = $this->search->resolve($user, $saved['kind'], $saved['id']);
            if ($result) {
                $favorites[] = $result;
            }
        }
        $filters = [];
        foreach ($preferences['filters'] ?? [] as $saved) {
            $screen = self::SCREENS[$saved['screen']] ?? null;
            if ($screen && $user->hasPermission($screen[0])
                && ($user->agency_id === null || ! isset($saved['filters']['agency_id']) || (int) $saved['filters']['agency_id'] === $user->agency_id)) {
                $filters[] = [...$saved, 'url' => route($saved['screen'], $saved['filters']), 'group' => __($screen[2])];
            }
        }

        return compact('favorites', 'filters');
    }

    public function favorite(User $user, string $kind, int $id, bool $remove): void
    {
        if (! $remove) {
            abort_unless($this->search->resolve($user, $kind, $id), 404);
        }
        $this->update($user, function (array $preferences) use ($kind, $id, $remove) {
            $favorites = array_values(array_filter($preferences['favorites'] ?? [], fn ($item) => $item['kind'] !== $kind || $item['id'] !== $id));
            if (! $remove) {
                if (count($favorites) >= 20) {
                    throw ValidationException::withMessages(['favorite' => __('Vous pouvez conserver jusqu’à 20 favoris.')]);
                }
                $favorites[] = compact('kind', 'id');
            }

            return [...$preferences, 'favorites' => $favorites];
        });
    }

    public function saveFilter(User $user, string $screen, string $label, array $filters): void
    {
        $definition = self::SCREENS[$screen] ?? null;
        abort_unless($definition && $user->hasPermission($definition[0]), 403);
        $rules = [];
        foreach ($definition[1] as $key) {
            $rules[$key] = match ($key) {
                'agency_id', 'category_id' => ['nullable', 'integer', 'min:1'],
                'days' => ['nullable', Rule::in([7, 14, 28])],
                'date' => ['nullable', 'date_format:Y-m-d'],
                'archived' => ['nullable', Rule::in(['0', '1'])],
                default => ['nullable', 'string', 'max:80'],
            };
        }
        $rules['tenant_id'] = ['prohibited'];
        $clean = Validator::make($filters, $rules)->validated();
        $clean = array_filter($clean, fn ($value) => $value !== null && $value !== '');
        if (isset($clean['agency_id'])) {
            $clean['agency_id'] = app(AgencyAccess::class)->required($clean['agency_id']);
        }
        $this->update($user, function (array $preferences) use ($screen, $label, $clean) {
            $filters = $preferences['filters'] ?? [];
            if (count($filters) >= 15) {
                throw ValidationException::withMessages(['label' => __('Vous pouvez conserver jusqu’à 15 filtres.')]);
            }
            $filters[] = ['id' => (string) Str::uuid(), 'screen' => $screen, 'label' => $label, 'filters' => $clean];

            return [...$preferences, 'filters' => $filters];
        });
    }

    public function removeFilter(User $user, string $id): void
    {
        $this->update($user, fn ($preferences) => [...$preferences, 'filters' => array_values(array_filter($preferences['filters'] ?? [], fn ($item) => $item['id'] !== $id))]);
    }

    private function update(User $user, \Closure $callback): void
    {
        DB::transaction(function () use ($user, $callback) {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($locked->tenant_id === $user->tenant_id, 403);
            $locked->forceFill(['workspace_preferences' => $callback($this->read($locked))])->save();
        });
    }
}

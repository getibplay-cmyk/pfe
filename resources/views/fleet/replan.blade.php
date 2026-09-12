<x-app-layout>
    @php($timezone = auth()->user()->tenant->settings['timezone'] ?? config('app.timezone'))
    <div class="rf-page max-w-4xl">
        <x-page-header :title="__('Déplacer une réservation')" :description="$reservation->reservation_number" />
        <x-input-error :messages="$errors->all()" />
        <x-section-card :title="__('Nouvelle organisation')">
            <form method="POST" action="{{ route('fleet.planning.preview', $reservation) }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <div class="sm:col-span-2"><x-input-label for="replan-vehicle" :value="__('Véhicule')" /><select id="replan-vehicle" name="vehicle_id" required class="w-full rounded-lg border-slate-300">@foreach($vehicles as $vehicle)<option value="{{ $vehicle->id }}" @selected(($preview['vehicle']->id ?? $reservation->vehicle_id) === $vehicle->id)>{{ $vehicle->registration_number }} · {{ $vehicle->brand }} {{ $vehicle->model }}</option>@endforeach</select></div>
                <div><x-input-label for="replan-start" :value="__('Nouveau départ')" /><x-text-input id="replan-start" name="starts_at" type="datetime-local" required :value="($preview['start'] ?? $reservation->starts_at)->setTimezone($timezone)->format('Y-m-d\TH:i')" /></div>
                <div><x-input-label for="replan-end" :value="__('Nouveau retour')" /><x-text-input id="replan-end" name="ends_at" type="datetime-local" required :value="($preview['end'] ?? $reservation->ends_at)->setTimezone($timezone)->format('Y-m-d\TH:i')" /></div>
                <p class="text-xs text-slate-600 sm:col-span-2">{{ __('Fuseau horaire') }} : {{ $timezone }}</p>
                <x-primary-button>{{ __('Vérifier la disponibilité et le tarif') }}</x-primary-button>
            </form>
        </x-section-card>
        @if($preview)
            <x-section-card :title="__('Conséquences du déplacement')">
                <div class="rf-table-scroll"><table><thead><tr><th>{{ __('Élément') }}</th><th>{{ __('Actuellement') }}</th><th>{{ __('Après confirmation') }}</th></tr></thead><tbody>
                    <tr><th>{{ __('Véhicule') }}</th><td>{{ $reservation->vehicle->registration_number }}</td><td>{{ $preview['vehicle']->registration_number }}</td></tr>
                    <tr><th>{{ __('Départ') }}</th><td>{{ App\Support\Ui\UiLabel::dateTime($reservation->starts_at) }}</td><td>{{ App\Support\Ui\UiLabel::dateTime($preview['start']) }}</td></tr>
                    <tr><th>{{ __('Retour') }}</th><td>{{ App\Support\Ui\UiLabel::dateTime($reservation->ends_at) }}</td><td>{{ App\Support\Ui\UiLabel::dateTime($preview['end']) }}</td></tr>
                    <tr><th>{{ __('Montant') }}</th><td>{{ $reservation->total_amount }} {{ $reservation->currency }}</td><td>{{ $preview['quote']['total_amount'] }} {{ $preview['quote']['currency'] }}</td></tr>
                    <tr><th>{{ __('Caution requise') }}</th><td>{{ $reservation->deposit_amount }} {{ $reservation->currency }}</td><td>{{ $preview['quote']['deposit_amount'] }} {{ $preview['quote']['currency'] }}</td></tr>
                </tbody></table></div>
                @if($preview['conflicts']->isNotEmpty())
                    <p class="mt-4 font-semibold text-red-800">{{ __('Ce déplacement rencontre un conflit de disponibilité.') }}</p><ul class="mt-2 space-y-2 text-sm">@foreach($preview['conflicts'] as $block)<li>{{ App\Support\Ui\UiLabel::blockType($block->block_type) }} · {{ App\Support\Ui\UiLabel::dateTime($block->starts_at) }} → {{ App\Support\Ui\UiLabel::dateTime($block->ends_at) }}</li>@endforeach</ul>
                @else
                    <p class="my-4 text-sm">{{ __('Après confirmation, une nouvelle réservation remplacera celle-ci. Son tarif sera figé et l’ancien dossier restera consultable. La disponibilité et le prix seront vérifiés une dernière fois.') }}</p>
                    <form method="POST" action="{{ route('fleet.planning.confirm', $reservation) }}" class="space-y-4">
                        @csrf<input type="hidden" name="proposal" value="{{ $preview['token'] }}">
                        <div><x-input-label for="replan-reason" :value="__('Motif du déplacement')" /><x-text-input id="replan-reason" name="reason" minlength="5" maxlength="500" required /></div>
                        <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="confirmed" value="1" required class="mt-1 rounded border-slate-300"><span>{{ __('Je confirme les nouvelles dates, le véhicule et le montant affiché.') }}</span></label>
                        <x-primary-button>{{ __('Confirmer le déplacement') }}</x-primary-button>
                    </form>
                @endif
            </x-section-card>
        @endif
        <a class="rf-button-link" href="{{ route('fleet.planning.index') }}">{{ __('Revenir au planning') }}</a>
    </div>
</x-app-layout>

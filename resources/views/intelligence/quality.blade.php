<x-app-layout>
    <div class="rf-page">
        <x-page-header :title="__('Qualité des modèles')" :description="__('Suivez les abstentions et les corrections vérifiées dans votre périmètre. Les analyses non revues ne sont pas considérées comme correctes.')" />
        <form method="GET" class="rf-panel rf-panel-body flex flex-wrap items-end gap-4">
            <div><x-input-label for="quality-days" :value="__('Période')" /><select id="quality-days" name="days" class="rounded-lg border-slate-300">@foreach([7,30,90] as $value)<option value="{{ $value }}" @selected($days === $value)>{{ $value }} {{ __('jours') }}</option>@endforeach</select></div>
            <div><x-input-label for="quality-agency" :value="__('Agence')" /><select id="quality-agency" name="agency_id" class="rounded-lg border-slate-300">@if(auth()->user()->agency_id === null)<option value="">{{ __('Toutes les agences autorisées') }}</option>@endif @foreach($agencies as $item)<option value="{{ $item->id }}" @selected($agency === $item->id)>{{ $item->name }}</option>@endforeach</select></div>
            <x-primary-button>{{ __('Actualiser') }}</x-primary-button><a href="{{ route('annotations.index') }}" class="rf-button-link">{{ __('Revoir les annotations') }}</a>
        </form>
        <p class="text-sm text-slate-600">{{ App\Support\Ui\UiLabel::dateTime($start) }} — {{ App\Support\Ui\UiLabel::dateTime($end) }}</p>
        <div class="rf-panel rf-table-scroll"><table><thead><tr><th>{{ __('Agence et modèle') }}</th><th>{{ __('Analyses') }}</th><th>{{ __('Revue humaine') }}</th><th>{{ __('Abstentions') }}</th><th>{{ __('Corrections') }}</th><th>{{ __('Évolution') }}</th></tr></thead><tbody>
            @forelse($rows as $row)<tr>
                <td><span class="font-semibold">{{ $row['agency'] }}</span><span class="block">{{ $row['label'] }}</span><code class="text-xs">{{ $row['version'] }}</code></td>
                <td>{{ $row['runs'] }}<span class="block text-xs">{{ $row['completed'] }} {{ __('terminées') }} · {{ $row['failed'] }} {{ __('échecs') }}</span></td>
                <td>{{ $row['reviewed'] }} / {{ $row['completed'] }}<span class="block text-xs">{{ $row['review_rate'] === null ? '—' : number_format($row['review_rate'] * 100, 1, ',', ' ').' %' }}</span></td>
                <td>{{ $row['abstention_rate'] === null ? '—' : number_format($row['abstention_rate'] * 100, 1, ',', ' ').' %' }}<span class="block text-xs">{{ $row['abstained'] }} / {{ $row['completed'] }}</span></td>
                <td>{{ $row['correction_rate'] === null ? '—' : number_format($row['correction_rate'] * 100, 1, ',', ' ').' %' }}<span class="block text-xs">{{ $row['corrected'] }} / {{ $row['comparable'] }} {{ __('comparables') }}</span>@if($row['family'] === 'damage')<span class="block text-xs">{{ __('F1 (IoU 0,50) :') }} {{ $row['box_f1'] === null ? '—' : number_format($row['box_f1'], 3, ',', ' ') }}</span>@endif</td>
                <td>@if($row['change'] !== null){{ ($row['change'] > 0 ? '+' : '').number_format($row['change'] * 100, 1, ',', ' ') }} {{ __('points') }}@else<span class="text-xs">{{ __('Échantillon insuffisant pour comparer') }}</span>@endif @if($row['review_needed'])<span class="mt-1 block font-semibold text-amber-800">{{ __('Vérification recommandée') }}</span>@endif</td>
            </tr>@empty<tr><td colspan="6">{{ __('Aucune analyse sur cette période.') }}</td></tr>@endforelse
        </tbody></table></div>
        <x-section-card :title="__('Lire ces indicateurs')"><p class="text-sm leading-6">{{ __('Les corrections sont calculées uniquement sur les suggestions comparables ayant une annotation validée. Pour les plaques, une lecture incomplète ou ambiguë est comptée parmi les abstentions. Pour les dommages, le F1 compare les cadres vérifiés et proposés avec un recouvrement minimal de 50 %. La comparaison temporelle exige 20 annotations comparables dans chaque période.') }}</p><p class="mt-3 text-sm leading-6">{{ __('Une alerte de revue apparaît au-delà de 20 % de corrections, ou d’une hausse de 10 points. Ces observations dépendent des photos revues et ne remplacent pas un test indépendant sur de nouvelles données. Elles ne déclenchent aucune activation de modèle.') }}</p></x-section-card>
    </div>
</x-app-layout>

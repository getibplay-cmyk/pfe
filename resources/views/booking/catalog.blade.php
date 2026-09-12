<x-booking-layout :profile="$profile" :title="__('Choisissez votre véhicule')">
    @if($profile->description)<p class="max-w-3xl text-lg leading-7 text-slate-600">{{ $profile->description }}</p>@endif
    <p class="text-sm text-slate-600">{{ __('Consultez un véhicule pour vérifier vos dates et obtenir un devis. Chaque demande sera examinée par l’agence.') }}</p>
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">@forelse($listings as $listing)
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm"><div class="mb-5 flex h-20 items-center justify-center rounded-xl bg-blue-50 text-blue-700" aria-hidden="true"><x-icon name="vehicle" /></div><p class="text-sm font-semibold text-blue-700">{{ $listing->vehicle->category->name }}</p><h2 class="mt-2 text-xl font-bold">{{ $listing->vehicle->brand }} {{ $listing->vehicle->model }}</h2><p class="mt-3 text-sm text-slate-600">{{ App\Support\Ui\UiLabel::get($listing->vehicle->fuel_type) }} · {{ App\Support\Ui\UiLabel::get($listing->vehicle->transmission) }}</p><a class="rf-button-primary mt-6 inline-flex" href="{{ route('booking.vehicle', [$profile->slug, $listing->id]) }}">{{ __('Voir les disponibilités et le prix') }}</a></article>
    @empty<x-empty-state :title="__('Aucun véhicule publié pour le moment')" :description="__('Contactez l’agence pour connaître les prochaines disponibilités.')" />@endforelse</div>
    {{ $listings->links() }}
</x-booking-layout>

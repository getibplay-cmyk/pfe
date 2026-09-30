@props([
    'id',
    'name',
    'label' => __('Fichier'),
    'accept' => null,
    'multiple' => false,
    'required' => false,
    'disabled' => false,
    'formats' => null,
    'maxSize' => null,
    'hint' => null,
    'preview' => 'none',
    'fit' => 'contain',
    'errors' => [],
])
@php
    $validationErrors = $errors instanceof \Illuminate\Support\ViewErrorBag ? $errors->get($name) : $errors;
    $errorMessages = collect(is_array($validationErrors) ? $validationErrors : [$validationErrors])->filter()->values();
    $helpId = $id.'-help';
    $errorId = $id.'-error';
    $descriptionIds = trim(($formats || $maxSize || $hint ? $helpId : '').($errorMessages->isNotEmpty() ? ' '.$errorId : ''));
    $config = [
        'previewImages' => $preview === 'image',
        'multiple' => (bool) $multiple,
        'disabled' => (bool) $disabled,
    ];
    $previewFit = $fit === 'cover' ? 'object-cover' : 'object-contain';
@endphp
<div
    x-data="sanadPilotFileInput(@js($config))"
    {{ $attributes->class('min-w-0') }}
>
    <label for="{{ $id }}" class="rf-field-label">{{ $label }} @if($required)<span class="text-sanad-pilot-danger" aria-hidden="true">*</span>@endif</label>
    <div
        class="relative mt-2 rounded-2xl border-2 border-dashed bg-sanad-pilot-canvas transition duration-150"
        x-bind:class="dragging ? 'border-sanad-pilot-blue bg-brand-50' : 'border-sanad-pilot-border hover:border-slate-400'"
        x-on:dragenter.prevent="dragging = true"
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="dragging = false"
        x-on:drop.prevent="acceptDrop($event.dataTransfer.files)"
    >
        <input
            x-ref="input"
            id="{{ $id }}"
            name="{{ $name }}"
            type="file"
            @if($accept) accept="{{ $accept }}" @endif
            @if($multiple) multiple @endif
            @if($required) required @endif
            @disabled($disabled)
            @if($descriptionIds !== '') aria-describedby="{{ $descriptionIds }}" @endif
            @if($errorMessages->isNotEmpty()) aria-invalid="true" @endif
            class="peer sr-only"
            x-on:change="changed($event)"
        >
        <label for="{{ $id }}" class="flex min-h-32 cursor-pointer flex-col items-center justify-center rounded-2xl px-5 py-6 text-center peer-focus-visible:ring-2 peer-focus-visible:ring-sanad-pilot-blue peer-focus-visible:ring-offset-2 peer-disabled:cursor-not-allowed peer-disabled:opacity-60">
            <span aria-hidden="true" class="flex h-11 w-11 items-center justify-center rounded-xl bg-sanad-pilot-orange-soft text-sanad-pilot-orange">
                <x-icon :name="$preview === 'image' ? 'image' : 'upload'" size="lg" />
            </span>
            <span class="mt-3 text-sm font-semibold text-sanad-pilot-blue">{{ __('Choisir un fichier') }}</span>
            <span class="mt-1 text-xs text-sanad-pilot-muted">{{ __('ou déposez-le dans cette zone') }}</span>
        </label>
    </div>

    @if ($formats || $maxSize || $hint)
        <p id="{{ $helpId }}" class="rf-field-help">
            @if($formats)<span>{{ __('Formats :') }} {{ $formats }}.</span>@endif
            @if($maxSize)<span> {{ __('Taille maximale :') }} {{ $maxSize }}.</span>@endif
            @if($hint)<span> {{ $hint }}</span>@endif
        </p>
    @endif

    <div x-cloak x-show="hasFile" x-transition.opacity.duration.150ms class="mt-3 flex items-center gap-3 rounded-xl border border-sanad-pilot-border bg-white p-3">
        <span aria-hidden="true" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-sanad-pilot-blue"><x-icon name="file" /></span>
        <div class="min-w-0 flex-1">
            <p class="truncate text-sm font-semibold text-sanad-pilot-text" x-text="fileName"></p>
            <p class="text-xs text-sanad-pilot-muted" x-text="fileSize"></p>
        </div>
        <x-icon-button icon="close" :label="__('Retirer le fichier sélectionné')" variant="quiet" x-on:click="clear()" />
    </div>

    @if ($preview === 'image')
        <div class="mt-3 aspect-[4/3] overflow-hidden rounded-2xl border border-sanad-pilot-border bg-slate-100">
            <template x-if="previewUrl">
                <img :src="previewUrl" alt="{{ __('Aperçu local du fichier sélectionné') }}" class="h-full w-full {{ $previewFit }}">
            </template>
            <div x-show="! previewUrl" class="flex h-full flex-col items-center justify-center gap-2 px-4 text-center text-sm text-sanad-pilot-muted">
                <x-icon name="image" size="lg" />
                <span>{{ __('Aucun aperçu sélectionné') }}</span>
            </div>
        </div>
    @endif

    @if ($errorMessages->isNotEmpty())
        <div id="{{ $errorId }}" class="mt-2 text-sm text-sanad-pilot-danger" role="alert">
            @foreach ($errorMessages as $message)<p>{{ $message }}</p>@endforeach
        </div>
    @endif
</div>

@props(['id', 'name', 'label', 'messages' => [], 'autocomplete' => 'current-password', 'required' => true])
<div x-data="{ visible: false }">
    <x-input-label :for="$id" :value="$label" :required="$required" />
    <div class="relative mt-1">
        <input :type="visible ? 'text' : 'password'" id="{{ $id }}" name="{{ $name }}" @required($required) autocomplete="{{ $autocomplete }}" @if($messages) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif class="block w-full pe-24 rounded-lg border-slate-300 focus:border-brand-600 focus:ring-brand-600">
        <button type="button" @click="visible = ! visible" :aria-pressed="visible" class="absolute inset-y-1 end-1 rounded-md px-3 text-xs font-semibold text-slate-600 hover:bg-slate-100" :aria-label="visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe'" x-text="visible ? 'Masquer' : 'Afficher'"></button>
    </div>
    <x-field-error :id="$id.'-error'" :messages="$messages" class="mt-2" />
</div>

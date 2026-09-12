<?php

namespace App\Http\Controllers;

use App\Models\DocumentAccessLog;
use App\Models\InspectionDraft;
use App\Models\RentalContract;
use App\Support\Rentals\GuidedInspection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

final class GuidedInspectionController extends Controller
{
    public function show(Request $request, RentalContract $contract, string $kind, GuidedInspection $guide): View
    {
        $guide->authorize($request->user(), $contract, $kind);
        $contract->load('vehicle');
        $inspection = $contract->inspections()->where('inspection_type', $kind)->where('status', 'completed')->with('items')->first();
        abort_unless($inspection || $contract->status->value === ($kind === 'departure' ? 'accepted' : 'active'), 409, __('Cet état des lieux n’est pas disponible à cette étape du contrat.'));
        $draft = InspectionDraft::where('rental_contract_id', $contract->id)->where('kind', $kind)->with('photos')->first();
        $departure = $kind === 'return' ? InspectionDraft::where('rental_contract_id', $contract->id)->where('kind', 'departure')->whereNotNull('completed_at')->with('photos')->first() : null;
        $photos = $draft?->photos->sortByDesc('id')->unique('angle')->keyBy('angle') ?? collect();
        $before = $departure?->photos->whereIn('id', $departure->selected_photo_ids)->keyBy('angle') ?? collect();

        return view('inspections.guided', compact('contract', 'kind', 'draft', 'photos', 'before', 'inspection'));
    }

    public function save(Request $request, RentalContract $contract, string $kind, GuidedInspection $guide): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0']]);
        $draft = $guide->save($request->user(), $contract, $kind, $data['revision'], $request->all());

        return $this->saved($request, $contract, $kind, $draft);
    }

    public function photo(Request $request, RentalContract $contract, string $kind, GuidedInspection $guide): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['tenant_id' => ['prohibited'], 'revision' => ['required', 'integer', 'min:0'], 'angle' => ['required', 'string'], 'file' => ['required', 'file', 'max:8192']]);
        $draft = $guide->addPhoto($request->user(), $contract, $kind, $data['revision'], $data['angle'], $request->file('file'));

        return $this->saved($request, $contract, $kind, $draft);
    }

    public function complete(Request $request, RentalContract $contract, string $kind, GuidedInspection $guide): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:0'], 'confirmed' => ['accepted']]);
        $guide->complete($request->user(), $contract, $kind, $data['revision'], $request->all());
        $url = route('contracts.show', $contract);

        return $request->expectsJson() ? response()->json(['redirect' => $url]) : redirect($url)->with('status', __('État des lieux terminé.'));
    }

    public function image(Request $request, RentalContract $contract, string $kind, int $photo, GuidedInspection $guide): Response
    {
        $guide->authorize($request->user(), $contract, $kind);
        abort_unless($request->user()->hasPermission('document.view'), 403);
        $draft = InspectionDraft::where('rental_contract_id', $contract->id)->where('kind', $kind)->firstOrFail();
        $record = $draft->photos()->with('version')->findOrFail($photo);
        $version = $record->version;
        abort_unless($version && in_array($version->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true), 404);
        $bytes = Storage::disk(config('documents.disk'))->get($version->stored_path);
        abort_unless(is_string($bytes) && hash_equals($record->sha256, hash('sha256', $bytes)), 409);
        DocumentAccessLog::create(['document_id' => $record->document_id, 'document_version_id' => $record->document_version_id, 'user_id' => $request->user()->id, 'action' => 'preview', 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);

        return response($bytes, 200, ['Content-Type' => $version->mime_type, 'Content-Disposition' => 'inline; filename="inspection-photo"', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function saved(Request $request, RentalContract $contract, string $kind, InspectionDraft $draft): JsonResponse|RedirectResponse
    {
        $photo = $request->hasFile('file') ? $draft->photos()->latest('id')->first() : null;

        return $request->expectsJson() ? response()->json(['revision' => $draft->revision, 'saved_at' => $draft->updated_at->toIso8601String(), 'photo_url' => $photo ? route('inspections.guided.image', ['contract' => $contract, 'kind' => $kind, 'photo' => $photo->id]) : null]) : to_route('inspections.guided.show', compact('contract', 'kind'))->with('status', __('Brouillon enregistré.'));
    }
}

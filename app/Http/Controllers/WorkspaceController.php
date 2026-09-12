<?php

namespace App\Http\Controllers;

use App\Support\Ui\WorkspacePreferences;
use App\Support\Ui\WorkspaceSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class WorkspaceController extends Controller
{
    public function index(Request $request, WorkspaceSearch $search, WorkspacePreferences $preferences): View|JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['prohibited'], 'q' => ['nullable', 'string', 'max:80']]);
        $term = trim($data['q'] ?? '');
        $results = $search->search($request->user(), $term);
        if ($request->expectsJson()) {
            return response()->json(['results' => $results]);
        }

        return view('workspace.index', [...compact('results', 'term'), ...$preferences->visible($request->user())]);
    }

    public function favorite(Request $request, WorkspacePreferences $preferences): RedirectResponse
    {
        $data = $request->validate(['tenant_id' => ['prohibited'], 'kind' => ['required', Rule::in(array_keys(WorkspaceSearch::RESOURCES))], 'id' => ['required', 'integer', 'min:1']]);
        $preferences->favorite($request->user(), $data['kind'], (int) $data['id'], $request->isMethod('DELETE'));

        return back()->with('status', __('Favoris mis à jour.'));
    }

    public function saveFilter(Request $request, WorkspacePreferences $preferences): RedirectResponse
    {
        $data = $request->validate(['tenant_id' => ['prohibited'], 'screen' => ['required', Rule::in(array_keys(WorkspacePreferences::SCREENS))], 'label' => ['required', 'string', 'max:60'], 'filters' => ['present', 'array', 'max:10']]);
        $preferences->saveFilter($request->user(), $data['screen'], $data['label'], $data['filters']);

        return to_route('workspace.index')->with('status', __('Filtre personnel enregistré.'));
    }

    public function removeFilter(Request $request, WorkspacePreferences $preferences): RedirectResponse
    {
        $data = $request->validate(['id' => ['required', 'uuid']]);
        $preferences->removeFilter($request->user(), $data['id']);

        return to_route('workspace.index')->with('status', __('Filtre supprimé.'));
    }
}

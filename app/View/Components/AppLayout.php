<?php

namespace App\View\Components;

use App\Support\Ui\NavigationBuilder;
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    public function __construct(private readonly NavigationBuilder $navigation) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        $sections = $this->navigation->for(request()->user());
        $pageTitle = collect($sections)
            ->flatMap(fn (array $section) => $section['items'])
            ->first(fn (array $item) => request()->routeIs($item['pattern']))['label'] ?? match (true) {
                request()->routeIs('profile.*') => 'Mon profil',
                request()->routeIs('password.change-required*') => 'Sécurité du compte',
                default => 'Espace de travail',
            };

        return view('layouts.app', [
            'navigationSections' => $sections,
            'pageTitle' => $pageTitle,
        ]);
    }
}

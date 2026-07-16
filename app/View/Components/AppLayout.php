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
        return view('layouts.app', [
            'navigationSections' => $this->navigation->for(request()->user()),
        ]);
    }
}

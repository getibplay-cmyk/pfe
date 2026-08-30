<?php

namespace App\Http\Controllers;

use App\Http\Requests\Platform\PlatformStatisticsRequest;
use App\Support\Platform\BuildPlatformStatistics;
use Illuminate\View\View;

final class PlatformStatisticsController extends Controller
{
    public function __invoke(
        PlatformStatisticsRequest $request,
        BuildPlatformStatistics $statistics,
    ): View {
        return view('platform.statistics', [
            'statistics' => $statistics->handle($request->startsAt(), $request->endsAt()),
        ]);
    }
}

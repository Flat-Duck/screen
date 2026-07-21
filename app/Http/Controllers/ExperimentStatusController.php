<?php

namespace App\Http\Controllers;

use App\Models\Experiment;
use App\Models\FeatureFlag;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class ExperimentStatusController extends Controller
{
    public function __invoke(): View
    {
        $experiments = Experiment::query()->withCount('assignments')->orderBy('key')->get();
        $variantCounts = DB::table('experiment_assignments')
            ->selectRaw('experiment_id, variant, COUNT(*) AS aggregate')
            ->groupBy('experiment_id', 'variant')->get()->groupBy('experiment_id');

        return view('experiments.index', [
            'flags' => FeatureFlag::query()->orderBy('key')->get(),
            'experiments' => $experiments,
            'variantCounts' => $variantCounts,
        ]);
    }
}

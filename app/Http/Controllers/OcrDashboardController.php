<?php

namespace App\Http\Controllers;

use App\Services\Ocr\OcrInsightsService;
use Illuminate\Contracts\View\View;

class OcrDashboardController extends Controller
{
    public function index(OcrInsightsService $insights): View
    {
        return view('ocr.index', [
            'pipeline' => $insights->pipeline(),
            'accuracy' => $insights->accuracy(),
            'curve' => $insights->curve(),
            'labelled' => $insights->labelled(),
        ]);
    }
}

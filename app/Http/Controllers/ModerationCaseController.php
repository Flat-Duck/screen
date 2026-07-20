<?php

namespace App\Http\Controllers;

use App\Models\ModerationCase;
use Illuminate\Contracts\View\View;

class ModerationCaseController extends Controller
{
    public function index(): View
    {
        return view('moderation.cases.index');
    }

    public function show(ModerationCase $case): View
    {
        return view('moderation.cases.show', ['case' => $case]);
    }
}

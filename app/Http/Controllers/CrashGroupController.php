<?php

namespace App\Http\Controllers;

use App\Models\CrashGroup;
use Illuminate\Contracts\View\View;

class CrashGroupController extends Controller
{
    public function index(): View
    {
        return view('crash-groups.index');
    }

    public function show(CrashGroup $group): View
    {
        return view('crash-groups.show', compact('group'));
    }
}

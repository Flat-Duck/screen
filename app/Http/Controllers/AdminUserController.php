<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;

class AdminUserController extends Controller
{
    public function show(int $user): View
    {
        return view('users.show', ['user' => User::withTrashed()->findOrFail($user)]);
    }
}

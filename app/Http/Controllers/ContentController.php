<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Contracts\View\View;

class ContentController extends Controller
{
    public function index(): View
    {
        return view('moderation.content.index');
    }

    public function show(int $post): View
    {
        $post = Post::withTrashed()->with(['user', 'media'])->withCount(['likes', 'comments'])->findOrFail($post);

        return view('moderation.content.show', ['post' => $post]);
    }
}

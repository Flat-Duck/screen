<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\UpdatePostMediaRequest;
use App\Http\Resources\PostMediaResource;
use App\Models\Post;
use App\Models\PostMedia;

class PostMediaController extends Controller
{
    public function update(UpdatePostMediaRequest $request, Post $post, PostMedia $media): PostMediaResource
    {
        abort_unless($media->post_id === $post->id, 404);
        $this->authorize('update', $post);

        $media->update(['alt_text' => $request->validated('alt_text')]);

        return new PostMediaResource($media);
    }
}

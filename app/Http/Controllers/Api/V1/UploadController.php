<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Uploads\CommitUpload;
use App\Actions\Uploads\PrepareUpload;
use App\Http\Requests\CommitUploadRequest;
use App\Http\Requests\PrepareUploadRequest;
use App\Http\Resources\UploadResource;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    public function prepare(PrepareUploadRequest $request, PrepareUpload $prepare): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $prepare($user, $request->string('content_type')->toString(), (int) $request->integer('byte_size'));

        return (new UploadResource($result['upload']))
            ->additional([
                'upload_url' => $result['upload_url'],
                'headers' => $result['headers'],
            ])
            ->response()
            ->setStatusCode(201);
    }

    public function commit(CommitUploadRequest $request, string $uploadId, CommitUpload $commit): JsonResponse
    {
        $upload = $commit(
            $this->resolve($request, $uploadId),
            $request->string('image_sha256')->toString(),
            $request->string('ocr_text')->toString() ?: null,
        );

        return (new UploadResource($upload))->response();
    }

    private function resolve(Request $request, string $uploadId): Upload
    {
        return Upload::query()
            ->where('upload_id', $uploadId)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->firstOrFail();
    }
}

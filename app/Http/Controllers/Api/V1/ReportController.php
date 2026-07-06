<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\StoreReportRequest;
use App\Http\Resources\ReportResource;
use App\Models\User;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ModerationService $moderation) {}

    public function store(StoreReportRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();

        $report = $this->moderation->report(
            $user,
            $data['reportable_type'],
            (int) $data['reportable_id'],
            $data['reason'],
            $data['details'] ?? null,
        );

        return (new ReportResource($report))->response()->setStatusCode(201);
    }
}

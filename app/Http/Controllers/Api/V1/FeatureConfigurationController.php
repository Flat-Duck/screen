<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\User;
use App\Services\FeatureEvaluationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeatureConfigurationController extends Controller
{
    public function __invoke(Request $request, FeatureEvaluationService $features): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => [
            'flags' => $features->flagsFor($user),
            'experiment_assignments' => $features->assignmentsFor($user),
        ]]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\ScreenshotCategory;
use Illuminate\Http\JsonResponse;

class ScreenshotCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = ScreenshotCategory::query()->active()->orderBy('sort_order')->get(['id', 'slug', 'name']);

        return response()->json(['data' => $categories]);
    }
}

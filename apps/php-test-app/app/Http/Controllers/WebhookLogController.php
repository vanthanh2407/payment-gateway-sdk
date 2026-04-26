<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\WebhookStore;
use Illuminate\Http\JsonResponse;

class WebhookLogController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(WebhookStore::all());
    }

    public function clear(): JsonResponse
    {
        WebhookStore::clear();
        return response()->json(['cleared' => true]);
    }
}

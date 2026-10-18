<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\WebsiteSettings;
use Illuminate\Http\JsonResponse;

/** What the public website reads when it is built. Public on purpose: it only holds what the website prints anyway. */
class PublicWebsiteController extends Controller
{
    public function __invoke(WebsiteSettings $website): JsonResponse
    {
        return response()->json(['data' => $website->publicValues()])->header('Cache-Control', 'public, max-age=60');
    }
}

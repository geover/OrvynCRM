<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** The company logo. Served by a route (not a public storage link) because each company's files are private to it. */
class BrandingController extends Controller
{
    public function logo(): Response
    {
        $path = Setting::get('app.logo_path');
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    public function favicon(): Response
    {
        $path = Setting::get('app.favicon_path');
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}

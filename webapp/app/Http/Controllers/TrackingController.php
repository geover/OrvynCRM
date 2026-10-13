<?php

namespace App\Http\Controllers;

use App\Models\EmailMessage;
use Illuminate\Http\Response;

class TrackingController extends Controller
{
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(string $tracker): Response
    {
        // Single atomic UPDATE: no read-modify-write, safe under heavy traffic.
        EmailMessage::where('tracker_id', $tracker)->increment('open_count');

        return response(base64_decode(self::PIXEL), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store']);
    }
}

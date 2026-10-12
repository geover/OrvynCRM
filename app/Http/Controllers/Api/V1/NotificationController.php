<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = DB::table('notifications_feed')->where('user_id', $request->user()->id);

        return response()->json([
            'unread' => (clone $q)->whereNull('read_at')->count(),
            // raw rows carry naive "Y-m-d H:i:s" strings; send ISO-8601 so the browser does not read them as local time
            'data' => $q->orderByDesc('id')->limit(30)->get(['id', 'title', 'body', 'url', 'read_at', 'created_at'])
                ->map(fn ($n) => array_merge((array) $n, [
                    'created_at' => $n->created_at ? Carbon::parse($n->created_at, config('app.timezone'))->toIso8601String() : null,
                    'read_at' => $n->read_at ? Carbon::parse($n->read_at, config('app.timezone'))->toIso8601String() : null,
                ]))->values(),
        ]);
    }

    public function read(Request $request, ?int $id = null): JsonResponse
    {
        DB::table('notifications_feed')->where('user_id', $request->user()->id)->whereNull('read_at')
            ->when($id, fn ($q) => $q->where('id', $id))->update(['read_at' => now()]);

        return response()->json(null, 204);
    }

    public function clear(Request $request): JsonResponse
    {
        DB::table('notifications_feed')->where('user_id', $request->user()->id)->delete();

        return response()->json(null, 204);
    }
}

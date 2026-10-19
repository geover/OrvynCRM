<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Blast;
use App\Models\EmailMessage;
use App\Models\SmsMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Every "Email all" and "Text all" as a whole: how many people it found, how many messages it made, and how they are doing (waiting, sent,
 * failed, opened, unsubscribed). Administrators see everybody's; everyone else sees their own.
 */
class BlastController extends Controller
{
    private const UNSUBSCRIBED = 'The contact has unsubscribed.';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $blasts = Blast::with('user:id,name')->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->when($user->isAdmin() && $request->integer('user_id'), fn ($q, $id) => $q->where('user_id', $id))
            ->latest('id')->limit(30)->get();

        $progress = $this->progress($blasts);

        return response()->json(['data' => $blasts->map(fn (Blast $b) => $this->present($b, $progress[$b->id] ?? []))->values()]);
    }

    public function show(Request $request, Blast $blast): JsonResponse
    {
        abort_unless($request->user()->isAdmin() || $blast->user_id === $request->user()->id, 403);
        $blast->load('user:id,name');
        $failed = $blast->messages()->where('status', 'failed')->with('contact:id,first_name,last_name')->latest('id')->limit(50)
            ->get(['id', 'contact_id', 'error'])->map(fn ($m) => ['id' => $m->id, 'contact_id' => $m->contact_id, 'contact' => trim(($m->contact?->first_name ?? '').' '.($m->contact?->last_name ?? '')), 'error' => $m->error]);

        return response()->json(['data' => $this->present($blast, $this->progress(collect([$blast]))[$blast->id] ?? []) + ['failures' => $failed]]);
    }

    /**
     * Message counts per blast and status, in two queries however many blasts there are.
     *
     * @param  Collection<int, Blast>  $blasts
     * @return array<int, array<string, int>>
     */
    private function progress(Collection $blasts): array
    {
        $out = [];
        foreach (['email' => EmailMessage::class, 'sms' => SmsMessage::class] as $channel => $model) {
            $ids = $blasts->where('channel', $channel)->pluck('id');
            if ($ids->isEmpty()) {
                continue;
            }
            $email = $channel === 'email';
            $rows = $model::query()->whereIn('blast_id', $ids)->selectRaw(
                'blast_id, status, count(*) as n, '
                .($email ? 'sum(case when open_count > 0 then 1 else 0 end) as opened, sum(case when click_count > 0 then 1 else 0 end) as clicked, ' : '0 as opened, 0 as clicked, ')
                .'sum(case when error = ? then 1 else 0 end) as unsubscribed', [self::UNSUBSCRIBED],
            )->groupBy('blast_id', 'status')->get();
            foreach ($rows as $r) {
                $b = &$out[$r->blast_id];
                $b[$r->status] = (int) $r->n;
                $b['opened'] = ($b['opened'] ?? 0) + (int) $r->opened;
                $b['clicked'] = ($b['clicked'] ?? 0) + (int) $r->clicked;
                $b['unsubscribed'] = ($b['unsubscribed'] ?? 0) + (int) $r->unsubscribed;
                unset($b);
            }
        }

        return $out;
    }

    /** @param  array<string, int>  $p */
    private function present(Blast $b, array $p): array
    {
        $count = fn (string $s) => (int) ($p[$s] ?? 0);
        $waiting = $count('queued') + $count('sending');
        $unsubscribed = $count('unsubscribed');

        return [
            'id' => $b->id, 'title' => $b->title, 'channel' => $b->channel, 'source' => $b->source, 'by' => $b->user?->name, 'created_at' => $b->created_at?->toIso8601String(),
            'matched' => $b->matched, 'queued' => $b->queued, 'skipped' => max(0, $b->matched - $b->queued),
            'status' => $b->finished_queuing_at === null ? 'preparing' : ($waiting > 0 ? 'sending' : 'done'),
            'waiting' => $waiting, 'sent' => $count('sent'), 'failed' => max(0, $count('failed') - $unsubscribed), 'unsubscribed' => $unsubscribed,
            'opened' => (int) ($p['opened'] ?? 0), 'clicked' => (int) ($p['clicked'] ?? 0),
        ];
    }
}

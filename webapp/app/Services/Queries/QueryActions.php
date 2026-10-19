<?php

namespace App\Services\Queries;

use App\Jobs\QueueBatchMessages;
use App\Models\Blast;
use App\Models\SavedQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What can be done with the people a query finds: download them as a CSV, e-mail them all, text them all. The same for a dashboard widget, a
 * saved query and a search that was just built and not saved, so every place that shows results offers the same actions.
 */
class QueryActions
{
    public function __construct(private readonly QueryRunner $runner) {}

    /**
     * Which query the request means: a saved one (`saved_query_id`), or a search described in the request (`scope` and `definition`).
     *
     * @return array{scope: string, definition: array<string, mixed>, saved: ?int, name: string}
     */
    public function resolve(Request $request): array
    {
        $d = $request->validate([
            'saved_query_id' => ['nullable', 'integer', 'exists:saved_queries,id'],
            'scope' => ['required_without:saved_query_id', Rule::in(['contact', 'data_object'])],
            'definition' => ['required_without:saved_query_id', 'array'],
        ]);
        if (! empty($d['saved_query_id'])) {
            $q = SavedQuery::findOrFail($d['saved_query_id']);

            return ['scope' => $q->scope, 'definition' => (array) $q->definition, 'saved' => $q->id, 'name' => $q->name];
        }
        try {
            $this->runner->run($d['scope'], $d['definition'], 1, 0);       // a definition that cannot run is refused here, not inside a queued job
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        return ['scope' => $d['scope'], 'definition' => $d['definition'], 'saved' => null, 'name' => 'search'];
    }

    /** The result as a CSV download, paged through so memory stays flat however many rows there are. */
    public function csv(Request $request, string $scope, array $definition, string $name): StreamedResponse
    {
        abort_unless($request->user()->hasPermission('contacts.view'), 403);

        return response()->streamDownload(function () use ($scope, $definition) {
            $out = fopen('php://output', 'w');
            $first = $this->runner->run($scope, $definition, 1, 0);
            fputcsv($out, array_column($first['columns'], 'label'));
            for ($offset = 0; $offset < $first['total']; $offset += 500) {
                foreach ($this->runner->run($scope, $definition, 500, $offset)['rows'] as $row) {
                    fputcsv($out, array_map(fn ($v) => $this->csvSafe(is_array($v) ? implode('; ', $v) : $v), array_values($row['values'])));
                }
            }
            fclose($out);
        }, Str::slug($name).'-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** E-mail or text everyone the query finds (queued in chunks; opt-outs and suppression are respected). */
    public function batch(Request $request, string $channel, string $scope, array $definition, ?int $savedId, string $source = 'a search'): JsonResponse
    {
        abort_unless(in_array($channel, ['email', 'sms'], true), 404);
        abort_unless($request->user()->hasPermission('contacts.view'), 403);
        $spec = $request->validate($channel === 'email'
            ? [
                'subject' => ['required', 'string', 'max:255'], 'html' => ['required', 'string', 'max:500000'],
                'attachments' => ['nullable', 'array', 'max:10'], 'attachments.*.path' => ['required_with:attachments', 'string', 'max:500'], 'attachments.*.name' => ['nullable', 'string', 'max:255'],
            ]
            : ['body' => ['required', 'string', 'max:1600']]);

        // Only files THIS user uploaded through /uploads may be attached (blocks path traversal and other people's files).
        foreach ($spec['attachments'] ?? [] as $attachment) {
            abort_unless(
                Str::startsWith($attachment['path'], "tmp/{$request->user()->id}/") && ! Str::contains($attachment['path'], '..') && Storage::disk('local')->exists($attachment['path']),
                422, 'Invalid attachment.',
            );
        }

        $blast = Blast::create([
            'user_id' => $request->user()->id, 'channel' => $channel, 'source' => $source,
            'title' => $channel === 'email' ? $spec['subject'] : Str::limit($spec['body'], 80),
        ]);
        QueueBatchMessages::dispatch($savedId, $channel, $spec, $request->user()->id, $savedId ? null : ['scope' => $scope, 'definition' => $definition], $blast->id);

        return response()->json(['queued' => true, 'blast_id' => $blast->id], 202);
    }

    /** Neutralise spreadsheet formula injection in exported cells. */
    private function csvSafe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }
}

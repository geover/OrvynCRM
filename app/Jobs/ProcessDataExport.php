<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\DataObject;
use App\Models\DataObjectRecord;
use App\Models\DataTransfer;
use App\Models\Note;
use App\Models\SavedQuery;
use App\Models\Task;
use App\Services\Queries\QueryRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/** Writes a CSV to storage/app/exports in constant memory (chunked), formula-injection safe. */
class ProcessDataExport implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $transferId)
    {
        $this->onConnection(config('queue.long'))->onQueue('imports');   // long job: its own visibility timeout, see config/queue.php
    }

    public function handle(QueryRunner $runner): void
    {
        $t = DataTransfer::findOrFail($this->transferId);
        $t->update(['status' => 'running']);
        Storage::disk('local')->makeDirectory('exports');
        $rel = "exports/{$t->id}.csv";
        $out = fopen(Storage::disk('local')->path($rel), 'w');
        $n = 0;
        $o = $t->options ?? [];

        try {
            match ($t->resource) {
                'contacts' => $n = $this->contacts($out, $o, $runner),
                'notes_tasks' => $n = $this->notesTasks($out, $o),
                'data_objects' => $n = $this->records($out, $o),
            };
            fclose($out);
            $t->update(['status' => 'done', 'file_path' => $rel, 'processed' => $n, 'total' => $n]);
        } catch (\Throwable $e) {
            is_resource($out) && fclose($out);
            $t->update(['status' => 'failed', 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function contacts($out, array $o, QueryRunner $runner): int
    {
        $cols = ['id', 'first_name', 'middle_name', 'last_name', 'title', 'email', 'phone', 'company_name', 'job_title', 'website', 'contact_type', 'birthday', 'created_at'];
        fputcsv($out, array_merge($cols, ['tags']));

        $q = ! empty($o['contact_id']) ? Contact::query()->whereKey($o['contact_id'])
            : (! empty($o['query_id']) && ($sq = SavedQuery::find($o['query_id'])) ? $runner->contactBuilder($sq->scope, $sq->definition) : Contact::query());
        $n = 0;
        $q->with('tags:id,name')->select('contacts.*')->chunkById(1000, function ($chunk) use ($out, $cols, &$n) {
            foreach ($chunk as $c) {
                fputcsv($out, array_merge(array_map(fn ($k) => $this->safe($c->{$k} instanceof \DateTimeInterface ? $c->{$k}->format('Y-m-d H:i:s') : $c->{$k}), $cols), [$this->safe($c->tags->pluck('name')->implode(', '))]));
                $n++;
            }
        }, 'contacts.id', 'id');

        return $n;
    }

    private function notesTasks($out, array $o): int
    {
        fputcsv($out, ['type', 'id', 'contact_id', 'title', 'body', 'due_at', 'completed_at', 'created_at']);
        $n = 0;
        $contact = $o['contact_id'] ?? null;
        Note::when($contact, fn ($q) => $q->where('contact_id', $contact))->chunkById(1000, function ($chunk) use ($out, &$n) {
            foreach ($chunk as $x) {
                fputcsv($out, ['note', $x->id, $x->contact_id, $this->safe($x->title), $this->safe($x->body), '', '', $x->created_at]);
                $n++;
            }
        });
        Task::when($contact, fn ($q) => $q->where('contact_id', $contact))->chunkById(1000, function ($chunk) use ($out, &$n) {
            foreach ($chunk as $x) {
                fputcsv($out, ['task', $x->id, $x->contact_id, $this->safe($x->title), $this->safe($x->description), $x->due_at, $x->completed_at, $x->created_at]);
                $n++;
            }
        });

        return $n;
    }

    private function records($out, array $o): int
    {
        $object = DataObject::with('fields')->findOrFail($o['data_object_id']);
        $keys = $object->fields->pluck('key')->all();
        fputcsv($out, array_merge(['id'], $keys, ['contact_emails']));
        $n = 0;

        DataObjectRecord::where('data_object_id', $object->id)
            ->when(($o['status'] ?? 'all') !== 'all', fn ($q) => $q->where('status', $o['status'] === 'inactive' ? 'archived' : 'active'))
            ->with('contacts:id,email')->chunkById(1000, function ($chunk) use ($out, $keys, &$n) {
                foreach ($chunk as $r) {
                    fputcsv($out, array_merge([$r->id], array_map(fn ($k) => $this->safe(is_array($v = $r->data[$k] ?? '') ? implode('; ', $v) : $v), $keys), [$this->safe($r->contacts->pluck('email')->filter()->implode('; '))]));
                    $n++;
                }
            });

        return $n;
    }

    private function safe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }
}

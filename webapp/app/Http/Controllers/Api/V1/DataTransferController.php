<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDataExport;
use App\Jobs\ProcessDataImport;
use App\Models\DataObject;
use App\Models\DataTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Admin "Data Import & Export". Heavy work is queued; the UI polls GET /transfers for progress. */
class DataTransferController extends Controller
{
    public const CONTACT_TARGETS = ['first_name', 'middle_name', 'last_name', 'title', 'email', 'phone', 'company_name', 'job_title', 'website', 'birthday', 'contact_type', 'tags', 'notes'];

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);

        return response()->json(['data' => DataTransfer::latest('id')->limit(30)->get()]);
    }

    /** Reads the uploaded CSV header + first rows and proposes a column mapping. */
    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate(['path' => ['required', 'string'], 'resource' => ['required', Rule::in(['contacts', 'data_objects'])], 'data_object_id' => ['nullable', 'exists:data_objects,id']]);
        $path = $this->ownTempFile($request, $d['path']);

        $fh = fopen(Storage::disk('local')->path($path), 'r');
        $headers = array_map(fn ($h) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', $h)), fgetcsv($fh) ?: []);
        $rows = [];
        for ($i = 0; $i < 5 && ($r = fgetcsv($fh)) !== false; $i++) {
            $rows[] = $r;
        }
        $total = 0;
        while (fgetcsv($fh) !== false) {
            $total++;
        }
        fclose($fh);

        $targets = $d['resource'] === 'contacts'
            ? array_map(fn ($t) => ['key' => $t, 'label' => Str::headline($t)], self::CONTACT_TARGETS)
            : DataObject::findOrFail($d['data_object_id'])->fields->map(fn ($f) => ['key' => $f->key, 'label' => $f->label])->all();
        $targets[] = ['key' => '_contact_email', 'label' => 'Link to contact by e-mail'];

        // common spreadsheet spellings of the contact columns
        $aliases = ['company' => 'company_name', 'organization' => 'company_name', 'organisation' => 'company_name', 'e_mail' => 'email', 'email_address' => 'email',
            'mobile' => 'phone', 'phone_number' => 'phone', 'telephone' => 'phone', 'firstname' => 'first_name', 'given_name' => 'first_name',
            'lastname' => 'last_name', 'surname' => 'last_name', 'family_name' => 'last_name', 'job' => 'job_title', 'position' => 'job_title', 'tag' => 'tags', 'url' => 'website'];
        $guess = [];
        foreach ($headers as $i => $h) {
            $n = Str::snake(Str::ascii($h));
            $n = $d['resource'] === 'contacts' ? ($aliases[$n] ?? $n) : $n;
            $hit = collect($targets)->first(fn ($t) => $t['key'] === $n || Str::snake($t['label']) === $n);
            $guess[$h] = $hit['key'] ?? '';
        }

        return response()->json(['headers' => $headers, 'sample' => $rows, 'total_rows' => $total + count($rows), 'targets' => $targets, 'mapping' => $guess]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $d = $request->validate([
            'direction' => ['required', Rule::in(['import', 'export'])],
            'resource' => ['required', Rule::in(['contacts', 'data_objects', 'notes_tasks'])],
            'path' => ['required_if:direction,import', 'nullable', 'string'],
            'options' => ['nullable', 'array'],
            'options.data_object_id' => ['nullable', 'exists:data_objects,id'],
            'options.identifying_field' => ['nullable', 'string'],
            'options.relationship_id' => ['nullable', 'exists:data_object_relationships,id'],
            'options.mapping' => ['nullable', 'array'],
            'options.status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'options.query_id' => ['nullable', 'exists:saved_queries,id'],
            'options.contact_id' => ['nullable', 'exists:contacts,id'],
        ]);
        $opts = $d['options'] ?? [];

        if ($d['direction'] === 'import') {
            abort_if($d['resource'] === 'notes_tasks', 422, 'Notes & tasks cannot be imported.');
            $file = $this->ownTempFile($request, $d['path']);
            abort_if(! array_filter($opts['mapping'] ?? []), 422, 'Map at least one column.');
            if ($d['resource'] === 'contacts') {
                abort_unless(in_array('email', $opts['mapping'], true), 422, 'Map a column to Email — it is how existing contacts are matched.');
                abort_if(array_diff(array_filter($opts['mapping']), array_merge(self::CONTACT_TARGETS)), 422, 'Unknown contact field in mapping.');
            } else {
                abort_unless(! empty($opts['data_object_id']), 422, 'Choose a data object.');
                $keys = DataObject::findOrFail($opts['data_object_id'])->fields->pluck('key')->all();
                abort_if(array_diff(array_filter($opts['mapping']), array_merge($keys, ['_contact_email'])), 422, 'Unknown field in mapping.');
                abort_if(! empty($opts['identifying_field']) && ! in_array($opts['identifying_field'], $keys, true), 422, 'Unknown identifying field.');
                abort_if(in_array('_contact_email', $opts['mapping'], true) && empty($opts['relationship_id']), 422, 'Choose the relationship to use when linking contacts.');
            }
            // move out of tmp so the pruner cannot delete it mid-import
            $dest = 'imports/'.Str::uuid().'.csv';
            Storage::disk('local')->move($file, $dest);
            $d['path'] = $dest;
        } elseif ($d['resource'] === 'data_objects') {
            abort_unless(! empty($opts['data_object_id']), 422, 'Choose a data object to export.');
        }

        $t = DataTransfer::create(['direction' => $d['direction'], 'resource' => $d['resource'], 'file_path' => $d['path'] ?? null, 'options' => $opts, 'user_id' => $request->user()->id]);
        $d['direction'] === 'import' ? ProcessDataImport::dispatch($t->id) : ProcessDataExport::dispatch($t->id);

        return response()->json(['data' => $t], 202);
    }

    public function download(Request $request, DataTransfer $transfer): StreamedResponse
    {
        abort_unless($request->user()->isAdmin() && $transfer->direction === 'export' && $transfer->status === 'done' && $transfer->file_path, 404);

        return Storage::disk('local')->download($transfer->file_path, "{$transfer->resource}-{$transfer->id}.csv", ['Content-Type' => 'text/csv']);
    }

    private function ownTempFile(Request $request, string $path): string
    {
        abort_unless(Str::startsWith($path, "tmp/{$request->user()->id}/") && ! Str::contains($path, '..') && Storage::disk('local')->exists($path), 422, 'Upload the CSV file first.');

        return $path;
    }
}

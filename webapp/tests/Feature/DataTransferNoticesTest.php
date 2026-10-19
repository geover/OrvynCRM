<?php

namespace Tests\Feature;

use App\Jobs\ProcessDataExport;
use App\Jobs\ProcessDataImport;
use App\Models\Contact;
use App\Models\DataTransfer;
use App\Models\User;
use App\Services\DataObjects\RecordService;
use App\Services\Queries\QueryRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The person who starts an import or an export is told by e-mail: waiting, started, and done (or failed). */
class DataTransferNoticesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin);
        Storage::fake('local');
    }

    /** @return list<string> subjects of every e-mail sent so far */
    private function subjects(): array
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage()->getSubject())->all();
    }

    public function test_an_export_sends_waiting_started_and_done(): void
    {
        Contact::create(['first_name' => 'Ann', 'email' => 'ann@x.io']);
        Queue::fake();
        $id = $this->postJson('/api/v1/transfers', ['direction' => 'export', 'resource' => 'contacts'])->assertStatus(202)->json('data.id');
        $this->assertSame(['Your export is waiting'], $this->subjects());

        (new ProcessDataExport($id))->handle(app(QueryRunner::class));
        $this->assertSame(['Your export is waiting', 'Your export has started', 'Your export is done'], $this->subjects());
        $this->assertSame($this->admin->email, app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getTo()[0]->getAddress());
    }

    public function test_an_import_sends_waiting_started_and_done_with_the_counts(): void
    {
        Queue::fake();
        $csv = UploadedFile::fake()->createWithContent('people.csv', "Email,First Name\nann@x.io,Ann\nnot-an-email,Bad\n");
        $path = $this->post('/api/v1/uploads', ['file' => $csv], ['Accept' => 'application/json'])->assertSuccessful()->json('path');
        $id = $this->postJson('/api/v1/transfers', ['direction' => 'import', 'resource' => 'contacts', 'path' => $path, 'options' => ['mapping' => ['Email' => 'email', 'First Name' => 'first_name']]])->assertStatus(202)->json('data.id');

        (new ProcessDataImport($id))->handle(app(RecordService::class));
        $this->assertSame(['Your import is waiting', 'Your import has started', 'Your import is done'], $this->subjects());
        $this->assertSame(1, DataTransfer::find($id)->processed);
        $this->assertSame(1, DataTransfer::find($id)->failed);
    }

    public function test_a_crashed_import_says_so(): void
    {
        $t = DataTransfer::create(['direction' => 'import', 'resource' => 'contacts', 'status' => 'running', 'options' => [], 'user_id' => $this->admin->id]);
        (new ProcessDataImport($t->id))->failed(new \RuntimeException('disk full'));

        $this->assertSame('failed', $t->fresh()->status);
        $this->assertSame(['Your import did not finish'], $this->subjects());
    }

    public function test_the_rows_of_an_uploaded_csv_come_a_screenful_at_a_time(): void
    {
        $lines = ['Email,Name'];
        foreach (range(1, 120) as $i) {
            $lines[] = "person{$i}@x.io,\"Person {$i}\"";
        }
        $path = $this->post('/api/v1/uploads', ['file' => UploadedFile::fake()->createWithContent('big.csv', implode('
', $lines).'
')], ['Accept' => 'application/json'])->assertSuccessful()->json('path');

        $first = $this->postJson('/api/v1/transfers/rows', ['path' => $path, 'limit' => 50])->assertOk();
        $this->assertCount(50, $first->json('rows'));
        $this->assertSame(['person1@x.io', 'Person 1'], $first->json('rows.0'));            // the header is not a row
        $this->assertNotNull($first->json('cursor'));

        $second = $this->postJson('/api/v1/transfers/rows', ['path' => $path, 'cursor' => $first->json('cursor'), 'limit' => 50])->assertOk();
        $this->assertSame('person51@x.io', $second->json('rows.0.0'));                       // continues where the first stopped
        $third = $this->postJson('/api/v1/transfers/rows', ['path' => $path, 'cursor' => $second->json('cursor'), 'limit' => 50])->assertOk();
        $this->assertCount(20, $third->json('rows'));
        $this->assertNull($third->json('cursor'));                                           // the end of the file

        $this->postJson('/api/v1/transfers/rows', ['path' => 'tmp/999/other.csv'])->assertUnprocessable();   // only your own uploads
    }
}

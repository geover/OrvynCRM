<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RunsInCompany;
use App\Models\DataObject;
use App\Models\DataObjectField;
use App\Models\DataObjectRelationship;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Fills the database with realistic bulk data so scaling can be measured (see bench:run).
 *
 * Deterministic (no Faker: it is far too slow for millions of rows), bulk-inserted in chunks, and tagged
 *
 * (contacts.source = 'bench', the "Bench Deals" object, bench*@example.test users) so --purge removes it again.
 * Many contacts share a created_at second on purpose: that is what breaks naive cursor pagination.
 */
class BenchSeed extends Command
{
    use RunsInCompany;

    protected $signature = 'bench:seed
        {--contacts=100000 : contacts to create}
        {--records=300000 : "Bench Deals" records to create}
        {--users=20 : owner / assignee users}
        {--chunk=2000 : rows per INSERT}
        {--purge : remove everything this command created, then stop}
        {--force : allow running with APP_ENV=production}
        {--company= : company slug or id (needed when companies exist)}';

    protected $description = 'Seed bulk contacts, tags, notes, tasks, activity and data-object records for load testing';

    private const FIRST = ['Ada', 'Alan', 'Grace', 'Linus', 'Margaret', 'Tim', 'Katherine', 'Dennis', 'Barbara', 'Donald', 'Edsger', 'Frances', 'Guido', 'Hedy', 'Ivan', 'Joan', 'Ken', 'Leslie', 'Mary', 'Niklaus', 'Olga', 'Peter', 'Radia', 'Sophie', 'Tony', 'Ursula', 'Vint', 'Whitfield', 'Xavier', 'Yukihiro', 'Zelda', 'Anita', 'Brian', 'Carol', 'David', 'Elena', 'Frank', 'Gina', 'Hugo', 'Irene', 'James', 'Kara', 'Liam', 'Mona', 'Nate', 'Opal', 'Paul', 'Quinn', 'Rita', 'Sam', 'Tara', 'Uma', 'Victor', 'Wendy', 'Xena', 'Yara', 'Zane', 'Amir', 'Bella', 'Chen'];

    private const LAST = ['Lovelace', 'Turing', 'Hopper', 'Torvalds', 'Hamilton', 'Berners-Lee', 'Johnson', 'Ritchie', 'Liskov', 'Knuth', 'Dijkstra', 'Allen', 'van Rossum', 'Lamarr', 'Sutherland', 'Clarke', 'Thompson', 'Lamport', 'Shaw', 'Wirth', 'Smith', 'Jones', 'Brown', 'Taylor', 'Wilson', 'Davies', 'Evans', 'Thomas', 'Roberts', 'Walker', 'Wright', 'Robinson', 'Thompson', 'White', 'Hughes', 'Edwards', 'Green', 'Hall', 'Wood', 'Harris', 'Lewis', 'Martin', 'Jackson', 'Clarke', 'Clark', 'Turner', 'Hill', 'Scott', 'Cooper', 'Morris', 'Ward', 'Moore', 'King', 'Watson', 'Baker', 'Harrison', 'Morgan', 'Patel', 'Young', 'Allen', 'Mitchell', 'James', 'Anderson', 'Phillips', 'Lee', 'Bell', 'Parker', 'Davis', 'Garcia', 'Nguyen', 'Kim', 'Singh', 'Khan', 'Silva', 'Costa', 'Rossi', 'Muller', 'Novak', 'Kowalski', 'Ivanov', 'Tanaka', 'Suzuki'];

    private const WORDS = ['Apex', 'Blue', 'Cobalt', 'Delta', 'Echo', 'Falcon', 'Granite', 'Harbor', 'Iron', 'Juniper', 'Kite', 'Lumen', 'Maple', 'Nova', 'Orbit', 'Pioneer', 'Quartz', 'Ridge', 'Summit', 'Titan', 'Union', 'Vertex', 'Willow', 'Zenith', 'Atlas'];

    private const SUFFIX = ['Systems', 'Labs', 'Group', 'Partners', 'Holdings', 'Works', 'Logistics', 'Media', 'Health', 'Energy', 'Capital', 'Studios', 'Foods', 'Robotics', 'Consulting', 'Networks'];

    private const JOBS = ['CEO', 'CTO', 'CFO', 'Engineer', 'Designer', 'Sales Manager', 'Account Executive', 'Marketing Lead', 'Operations Manager', 'Founder', 'Director', 'Analyst'];

    private const TYPES = ['Customer', 'Lead', 'Partner', 'Vendor', 'Prospect'];

    private const STAGES = ['New', 'Qualified', 'Proposal', 'Negotiation', 'Won', 'Lost'];

    private const REGIONS = ['North', 'South', 'East', 'West'];

    public function handle(): int
    {
        if (! $this->openCompany()) {
            return self::FAILURE;
        }

        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to seed bulk data with APP_ENV=production (use --force if you really mean it).');

            return self::FAILURE;
        }

        if ($this->option('purge')) {
            return $this->purge();
        }

        $contacts = max(0, (int) $this->option('contacts'));
        $records = max(0, (int) $this->option('records'));
        $chunk = max(100, (int) $this->option('chunk'));
        $started = microtime(true);
        $this->info(sprintf('Seeding into [%s] (%s): %s contacts, %s records', DB::connection()->getDatabaseName(), DB::getDriverName(), number_format($contacts), number_format($records)));

        $userIds = $this->users(max(1, (int) $this->option('users')));
        $tagIds = $this->tags(200);
        $firstContactId = null;
        if ($contacts > 0) {
            $firstContactId = $this->contacts($contacts, $chunk, $userIds, $tagIds);
        }
        if ($records > 0) {
            $this->records($records, $chunk, $contacts, $firstContactId);
        }
        $this->analyze();

        $secs = max(0.001, microtime(true) - $started);
        $this->info(sprintf('Done in %.1fs (%s rows/s).', $secs, number_format(($contacts + $records) / $secs)));

        return self::SUCCESS;
    }

    /** @return int[] */
    private function users(int $n): array
    {
        $hash = Hash::make('Bench-Passw0rd!');
        for ($i = 1; $i <= $n; $i++) {
            User::firstOrCreate(['email' => "bench{$i}@example.test"], ['name' => "Bench User {$i}", 'password' => $hash, 'role' => 'user']);
        }

        return User::where('email', 'like', 'bench%@example.test')->orderBy('id')->pluck('id')->all();
    }

    /** @return int[] */
    private function tags(int $n): array
    {
        $now = now();
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ['name' => "bench-tag-{$i}", 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('tags')->insertOrIgnore($rows);

        return DB::table('tags')->where('name', 'like', 'bench-tag-%')->orderBy('id')->pluck('id')->all();
    }

    /** @return int id of the first contact created */
    private function contacts(int $total, int $chunk, array $userIds, array $tagIds): int
    {
        $base = (int) DB::table('contacts')->max('id');
        $noteBase = (int) DB::table('notes')->max('id');
        $nu = count($userIds);
        $nt = count($tagIds);
        $start = now()->subYears(3)->getTimestamp();
        $companies = $this->companies(5000);
        $this->line('Contacts, tags, notes, tasks, activity');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        for ($offset = 0; $offset < $total; $offset += $chunk) {
            $n = min($chunk, $total - $offset);
            $contacts = $tagLinks = $notes = $tasks = $activities = [];
            for ($j = 0; $j < $n; $j++) {
                $i = $offset + $j;
                $id = $base + $i + 1;
                $first = self::FIRST[$i % 60];
                $last = self::LAST[intdiv($i, 60) % 80];
                $at = date('Y-m-d H:i:s', $start + intdiv($i * 94608000, max(1, $total * 3)) + intdiv($i, 3) % 3);   // three contacts per second
                $owner = $userIds[$i % $nu];
                $contacts[] = [
                    'id' => $id, 'first_name' => $first, 'last_name' => $last, 'email' => strtolower("{$first}.{$last}.{$i}@bench.example.test"),
                    'phone' => '+1555'.str_pad((string) ($i % 10000000), 7, '0', STR_PAD_LEFT), 'company_name' => $companies[($i * 7) % 5000],
                    'job_title' => self::JOBS[$i % 12], 'contact_type' => self::TYPES[$i % 5], 'owner_id' => $i % 10 === 0 ? null : $owner,
                    'addresses' => $i % 3 === 0 ? json_encode([['type' => 'BILLING', 'street1' => ($i % 900).' Main St', 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701', 'country' => 'US']]) : null,
                    'email_opt_out' => $i % 33 === 0 ? 1 : 0, 'source' => 'bench', 'created_at' => $at, 'updated_at' => $at,
                ];
                foreach (array_unique([$tagIds[$i % $nt], $tagIds[($i * 13 + 5) % $nt]]) as $tagId) {
                    $tagLinks[] = ['contact_id' => $id, 'tag_id' => $tagId, 'created_at' => $at];
                }
                $activities[] = ['contact_id' => $id, 'causer_id' => $owner, 'type' => 'contact.created', 'data' => null, 'created_at' => $at];
                if ($i % 4 === 0) {
                    $notes[] = ['id' => ++$noteBase, 'contact_id' => $id, 'user_id' => $owner, 'title' => "Call {$i}", 'body' => 'Discussed requirements, pricing and next steps. Follow up after the quarterly review. '.str_repeat('x', $i % 60), 'created_at' => $at, 'updated_at' => $at];
                    $activities[] = ['contact_id' => $id, 'causer_id' => $owner, 'type' => 'note.created', 'data' => json_encode(['title' => "Call {$i}"]), 'created_at' => $at];
                }
                if ($i % 5 === 0) {
                    $done = $i % 10 === 0;
                    $due = date('Y-m-d H:i:s', time() + (($i % 61) - 30) * 86400);
                    $tasks[] = ['contact_id' => $id, 'assigned_to' => $owner, 'created_by' => $owner, 'type' => 'task', 'title' => "Follow up {$first}", 'priority' => 'normal', 'due_at' => $due, 'completed_at' => $done ? $at : null, 'created_at' => $at, 'updated_at' => $at];
                }
            }
            DB::transaction(function () use ($contacts, $tagLinks, $notes, $tasks, $activities) {
                $this->insert('contacts', $contacts);
                $this->insert('contact_tag', $tagLinks);
                $notes && $this->insert('notes', $notes);
                $tasks && $this->insert('tasks', $tasks);
                $this->insert('activities', $activities);
            });
            $bar->advance($n);
        }
        $bar->finish();
        $this->newLine();

        return $base + 1;
    }

    private function records(int $total, int $chunk, int $contactCount, ?int $firstContactId): void
    {
        [$object, $fields] = $this->object();
        $owner = DataObjectRelationship::where('label', 'Owner')->first();
        $recordBase = (int) DB::table('data_object_records')->max('id');
        $start = now()->subYears(2)->getTimestamp();
        $companies = $this->companies(5000);
        $this->line('Bench Deals records, typed index rows, contact links');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        for ($offset = 0; $offset < $total; $offset += $chunk) {
            $n = min($chunk, $total - $offset);
            $recs = $index = $links = [];
            for ($j = 0; $j < $n; $j++) {
                $i = $offset + $j;
                $id = $recordBase + $i + 1;
                $stage = self::STAGES[$i % 6];
                $amount = (($i * 37) % 100000) + 100;
                $close = date('Y-m-d', $start + ($i * 41) % (2 * 365 * 86400));
                $title = 'Deal '.$companies[($i * 11) % 5000]." #{$i}";
                $region = self::REGIONS[$i % 4];
                $notes = 'Renewal discussion with procurement; budget confirmed for the next quarter.';
                $data = ['title' => $title, 'stage' => $stage, 'amount' => $amount, 'close_date' => $close, 'region' => $region, 'notes' => $notes];
                $at = date('Y-m-d H:i:s', $start + intdiv($i * 63072000, max(1, $total)));
                $recs[] = ['id' => $id, 'data_object_id' => $object->id, 'data' => json_encode($data), 'search_text' => "{$title} {$stage} {$amount} {$region} {$notes}", 'status' => 'active', 'created_at' => $at, 'updated_at' => $at];
                $index[] = ['record_id' => $id, 'field_id' => $fields['stage'], 'contact_id' => null, 'value_string' => $stage, 'value_number' => null, 'value_date' => null];
                $index[] = ['record_id' => $id, 'field_id' => $fields['amount'], 'contact_id' => null, 'value_string' => null, 'value_number' => $amount, 'value_date' => null];
                $index[] = ['record_id' => $id, 'field_id' => $fields['close_date'], 'contact_id' => null, 'value_string' => null, 'value_number' => null, 'value_date' => $close.' 00:00:00'];
                if ($owner && $contactCount > 0 && $firstContactId) {
                    $links[] = ['record_id' => $id, 'contact_id' => $firstContactId + (($i * 31) % $contactCount), 'relationship_id' => $owner->id, 'created_at' => $at, 'updated_at' => $at];
                }
            }
            DB::transaction(function () use ($recs, $index, $links) {
                $this->insert('data_object_records', $recs);
                $this->insert('data_object_field_index', $index);
                $links && $this->insert('data_object_record_contact', $links);
            });
            $bar->advance($n);
        }
        $bar->finish();
        $this->newLine();
    }

    /** @return array{0: DataObject, 1: array<string,int>} the object and key => field id */
    private function object(): array
    {
        $object = DataObject::firstOrCreate(['name' => 'Bench Deals']);
        $spec = [
            ['title', 'Title', 'text', true, false, null], ['stage', 'Stage', 'select', true, true, ['choices' => self::STAGES]],
            ['amount', 'Amount', 'number', true, true, null], ['close_date', 'Close date', 'date', false, true, null],
            ['region', 'Region', 'select', false, false, ['choices' => self::REGIONS]], ['notes', 'Notes', 'textarea', false, false, null],
        ];
        $ids = [];
        foreach ($spec as $pos => [$key, $label, $type, $inList, $indexed, $options]) {
            $f = DataObjectField::firstOrCreate(['data_object_id' => $object->id, 'key' => $key], [
                'label' => $label, 'type' => $type, 'show_in_list' => $inList, 'is_indexed' => $indexed, 'options' => $options, 'position' => $pos,
            ]);
            $ids[$key] = $f->id;
        }
        $rel = DataObjectRelationship::firstOrCreate(['label' => 'Owner']);
        $object->relationships()->syncWithoutDetaching([$rel->id]);

        return [$object, $ids];
    }

    /** @return string[] */
    private function companies(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::WORDS[$i % 25].' '.self::SUFFIX[intdiv($i, 25) % 16].($i >= 400 ? ' '.intdiv($i, 400) : '');
        }

        return $out;
    }

    private function purge(): int
    {
        $this->warn('Removing bench data (contacts, their notes/tasks/tags/activity, the Bench Deals object, bench users)…');
        $n = 0;
        do {
            $ids = DB::table('contacts')->where('source', 'bench')->limit(5000)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            DB::table('data_object_record_contact')->whereIn('contact_id', $ids)->delete();
            DB::table('activities')->whereIn('contact_id', $ids)->delete();
            DB::table('contacts')->whereIn('id', $ids)->delete();
            $n += $ids->count();
            $this->output->write('.');
        } while (true);
        $this->newLine();

        if ($object = DataObject::where('name', 'Bench Deals')->first()) {
            do {
                $ids = DB::table('data_object_records')->where('data_object_id', $object->id)->limit(5000)->pluck('id');
                if ($ids->isEmpty()) {
                    break;
                }
                DB::table('data_object_field_index')->whereIn('record_id', $ids)->delete();
                DB::table('data_object_record_contact')->whereIn('record_id', $ids)->delete();
                DB::table('data_object_records')->whereIn('id', $ids)->delete();
                $this->output->write('.');
            } while (true);
            $this->newLine();
            $object->delete();
        }
        DB::table('tags')->where('name', 'like', 'bench-tag-%')->delete();
        User::where('email', 'like', 'bench%@example.test')->delete();
        $this->info("Removed {$n} contacts and everything attached to them.");

        return self::SUCCESS;
    }

    /** Bulk insert in slices that stay under the driver's bound-parameter limit (SQLite 32766, MySQL 65535). */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, max(1, intdiv(30000, max(1, count(reset($rows)))))) as $slice) {
            DB::table($table)->insert($slice);
        }
    }

    /** Fresh statistics so the optimiser picks the plans it would pick in production. */
    private function analyze(): void
    {
        $tables = ['contacts', 'contact_tag', 'notes', 'tasks', 'activities', 'data_object_records', 'data_object_field_index', 'data_object_record_contact'];
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ANALYZE TABLE '.implode(', ', $tables));
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement('ANALYZE');
        }
    }
}

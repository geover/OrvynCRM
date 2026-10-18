<?php

namespace App\Services\Platform;

use App\Models\Tenant;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A READ-ONLY look into the central database or into one company's database, for platform administrators who need to see what is really
 * stored: which tables there are, how they are built and what is in them. Nothing here writes: it reads the schema and runs plain selects
 * built with the query builder, and every table and column name that reaches a query was first checked against the real schema, so
 * nothing a person types is ever part of a query. Columns that hold secrets are masked, long values are cut short.
 */
class DatabaseBrowser
{
    /** Columns whose values are never shown, by name: passwords, tokens, keys, secrets, codes, cookies. */
    private const SENSITIVE = '/(password|passwd|secret|token|api_key|private_key|remember|recovery|two_factor|otp|credential|authorization|cookie|signature|client_secret|webhook_secret)/i';

    /** A cell longer than this is cut (the full value is one click away in the app: it is sent whole up to the second limit). */
    private const CELL_LIMIT = 400;

    /** @var list<string> column types that hold text, searched with LIKE */
    private const TEXT_TYPES = ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'string', 'json', 'enum'];

    /**
     * Runs the callback with the connection of the central database ($tenant null) or of the company.
     *
     * @template T
     *
     * @param  Closure(Connection): T  $callback
     * @return T
     */
    public function within(?Tenant $tenant, Closure $callback): mixed
    {
        if ($tenant === null) {
            return $callback(DB::connection(config('tenancy.database.central_connection')));
        }

        return $tenant->run(fn () => $callback(DB::connection()));
    }

    /**
     * The tables of THIS database only. The schema builder lists the tables of every database on the server (and one per schema), so the list is
     * cut down to the open database and each name appears once.
     *
     * @return list<array<string, mixed>>
     */
    private function listed(Connection $db): array
    {
        $own = (string) $db->getDatabaseName();
        $seen = [];
        foreach ($db->getSchemaBuilder()->getTables() as $t) {
            if (isset($t['schema']) && $t['schema'] !== null && $db->getDriverName() !== 'sqlite' && $t['schema'] !== $own) {
                continue;
            }
            $seen[(string) $t['name']] ??= $t;
        }

        return array_values($seen);
    }

    /** @return array{name: string, driver: string, tables: int, size: ?int} */
    public function info(Connection $db): array
    {
        $tables = $this->listed($db);
        $size = null;
        $name = (string) $db->getDatabaseName();
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $size = (int) $db->table('information_schema.tables')->where('table_schema', $name)->sum(DB::raw('data_length + index_length'));
        } elseif ($db->getDriverName() === 'sqlite' && is_file($name)) {
            $size = (int) filesize($name);
        }

        return ['name' => $db->getDriverName() === 'sqlite' ? basename($name) : $name, 'driver' => $db->getDriverName(), 'tables' => count($tables), 'size' => $size];
    }

    /** @return list<array{name: string, rows: int, approx: bool, size: ?int}> */
    public function tables(Connection $db): array
    {
        $listed = $this->listed($db);
        $approx = [];
        if (in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            // the engine's own estimate: instant even for a table of millions
            $approx = $db->table('information_schema.tables')->where('table_schema', $db->getDatabaseName())->pluck('TABLE_ROWS', 'TABLE_NAME')->map(fn ($n) => (int) $n)->all();
        }
        $out = [];
        foreach ($listed as $t) {
            $name = (string) $t['name'];
            $estimated = array_key_exists($name, $approx);
            try {
                $estimated = $estimated && $approx[$name] > 5000;                  // the estimate only for big tables; small ones are counted for real
                $rows = $estimated ? $approx[$name] : (int) $db->table($name)->count();
            } catch (Throwable) {
                continue;                                    // listed by the schema but not readable (a view, another schema, a leftover): not shown
            }
            $out[] = ['name' => $name, 'rows' => $rows, 'approx' => $estimated, 'size' => isset($t['size']) ? (int) $t['size'] : null];
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** Is this the name of a table that exists? (the only way a table name gets into a query) */
    public function hasTable(Connection $db, string $table): bool
    {
        foreach ($this->listed($db) as $t) {
            if ($t['name'] === $table) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{columns: list<array<string, mixed>>, indexes: list<array<string, mixed>>}
     */
    public function structure(Connection $db, string $table): array
    {
        $schema = $db->getSchemaBuilder();
        $columns = array_map(fn (array $c) => [
            'name' => $c['name'], 'type' => $c['type'] ?? $c['type_name'], 'nullable' => (bool) $c['nullable'], 'default' => $c['default'],
            'auto_increment' => (bool) ($c['auto_increment'] ?? false), 'sensitive' => $this->sensitive($c['name']),
        ], $schema->getColumns($table));
        $indexes = array_map(fn (array $i) => ['name' => $i['name'], 'columns' => $i['columns'], 'primary' => (bool) $i['primary'], 'unique' => (bool) $i['unique']], $schema->getIndexes($table));

        return ['columns' => $columns, 'indexes' => $indexes];
    }

    /**
     * One page of rows. `$sort` and the columns searched are taken from the table's real columns; anything else is ignored.
     *
     * @param  list<array<string, mixed>>  $columns  from structure()
     * @return array{rows: list<array<string, mixed>>, has_more: bool, total: ?int, page: int, per_page: int, sort: ?string, dir: string, q: string}
     */
    public function rows(Connection $db, string $table, array $columns, int $page, int $perPage, ?string $sort, string $dir, string $q): array
    {
        $names = array_column($columns, 'name');
        $perPage = max(1, min($perPage, 100));
        $page = max(1, $page);
        $dir = $dir === 'desc' ? 'desc' : 'asc';
        $sort = in_array($sort, $names, true) ? $sort : null;
        $q = trim($q);

        $query = $db->table($table);
        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $searchable = array_values(array_filter($columns, fn ($c) => ! $c['sensitive'] && in_array(strtolower(preg_replace('/\(.*/', '', (string) $c['type'])), self::TEXT_TYPES, true)));
            $query->where(function ($w) use ($searchable, $like, $q, $columns) {
                foreach (array_slice($searchable, 0, 8) as $c) {
                    $w->orWhere($c['name'], 'like', $like);
                }
                if (ctype_digit($q)) {
                    foreach (array_slice(array_filter($columns, fn ($c) => ! empty($c['auto_increment'])), 0, 1) as $c) {
                        $w->orWhere($c['name'], (int) $q);
                    }
                }
                $w->orWhereRaw('1 = 0');                       // keeps the group valid when nothing is searchable
            });
        }

        // the total is only counted for a small result: counting millions of rows is exactly what must not happen here
        $total = (int) $db->query()->fromSub((clone $query)->selectRaw('1')->limit(10001), 'capped')->count();
        $total = $total > 10000 ? null : $total;

        if ($sort) {
            $query->orderBy($sort, $dir);
        } else {
            $primary = collect($db->getSchemaBuilder()->getIndexes($table))->firstWhere('primary', true);
            if ($primary && count($primary['columns']) === 1) {
                $query->orderBy($primary['columns'][0], 'desc');
            }
        }
        $fetched = $query->offset(($page - 1) * $perPage)->limit($perPage + 1)->get();
        $more = $fetched->count() > $perPage;
        $sensitive = array_flip(array_column(array_filter($columns, fn ($c) => $c['sensitive']), 'name'));

        $rows = [];
        foreach ($fetched->take($perPage) as $row) {
            $out = [];
            foreach ((array) $row as $name => $value) {
                $out[$name] = isset($sensitive[$name]) ? ($value === null ? null : '••••••••') : $this->cell($value);
            }
            $rows[] = $out;
        }

        return ['rows' => $rows, 'has_more' => $more, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'sort' => $sort, 'dir' => $dir, 'q' => $q];
    }

    public function sensitive(string $column): bool
    {
        return (bool) preg_match(self::SENSITIVE, $column);
    }

    /** One value as the grid shows it: text cut to a readable length, binary described, numbers and nulls as they are. */
    private function cell(mixed $value): mixed
    {
        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8')) {
                return '[binary, '.strlen($value).' bytes]';
            }

            return mb_strlen($value) > self::CELL_LIMIT ? mb_substr($value, 0, self::CELL_LIMIT).'…' : $value;
        }

        return is_scalar($value) || $value === null ? $value : (string) json_encode($value);
    }

    /** A database that cannot be opened (a company whose database was never created) is reported, not thrown. */
    public function failure(Throwable $e): string
    {
        report($e);

        return 'The database could not be opened: '.class_basename($e).'.';
    }
}

<?php

namespace App\Services\Queries;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Filter-tree -> SQL for contacts (same tree shape as RecordQuery).
 * Only whitelisted columns are filterable; "tag" is a virtual field resolved through contact_tag.
 */
class ContactQuery
{
    public const FIELDS = [
        ['key' => 'first_name', 'label' => 'First name', 'type' => 'text'],
        ['key' => 'last_name', 'label' => 'Last name', 'type' => 'text'],
        ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
        ['key' => 'phone', 'label' => 'Phone', 'type' => 'text'],
        ['key' => 'company_name', 'label' => 'Company', 'type' => 'text'],
        ['key' => 'job_title', 'label' => 'Job title', 'type' => 'text'],
        ['key' => 'contact_type', 'label' => 'Contact type', 'type' => 'text'],
        ['key' => 'owner_id', 'label' => 'Owner (user id)', 'type' => 'number'],
        ['key' => 'tag', 'label' => 'Has tag', 'type' => 'text'],
        ['key' => 'email_opt_out', 'label' => 'Email opt-out (0/1)', 'type' => 'number'],
        ['key' => 'birthday', 'label' => 'Birthday', 'type' => 'date'],
        ['key' => 'created_at', 'label' => 'Date added', 'type' => 'date'],
        ['key' => 'updated_at', 'label' => 'Last updated', 'type' => 'date'],
    ];

    private const OPS = ['=', '!=', '>', '>=', '<', '<=', 'contains', 'starts_with', 'in', 'empty', 'not_empty'];

    public function build(array $filter = [], ?Builder $base = null): Builder
    {
        $query = $base ?? Contact::query();
        if (! empty($filter['rules'])) {
            $this->group($query, $filter);
        }

        return $query;
    }

    private function group(Builder $query, array $group): void
    {
        $query->where(function (Builder $q) use ($group) {
            $any = ($group['match'] ?? 'all') === 'any';
            foreach ($group['rules'] as $rule) {
                $m = $any ? 'orWhere' : 'where';
                $q->{$m}(fn (Builder $b) => isset($rule['rules']) ? $this->group($b, $rule) : $this->rule($b, $rule));
            }
        });
    }

    private function rule(Builder $q, array $r): void
    {
        $key = $r['field'] ?? '';
        $op = $r['op'] ?? '=';
        $value = $r['value'] ?? null;
        if (! in_array($op, self::OPS, true) || ! in_array($key, array_column(self::FIELDS, 'key'), true)) {
            throw new InvalidArgumentException("Unsupported contact filter [$key $op]");
        }

        if ($key === 'tag') {
            $has = fn ($w) => $w->whereIn('contacts.id', fn ($s) => $s->select('contact_id')->from('contact_tag')
                ->whereIn('tag_id', fn ($t) => $t->select('id')->from('tags')->whereIn('name', (array) $value)));
            match ($op) {
                '!=' => $q->whereNot($has),
                'empty' => $q->whereNotIn('contacts.id', fn ($s) => $s->select('contact_id')->from('contact_tag')),
                'not_empty' => $q->whereIn('contacts.id', fn ($s) => $s->select('contact_id')->from('contact_tag')),
                default => $has($q),
            };

            return;
        }

        $col = 'contacts.'.$key;
        match ($op) {
            'empty' => $q->where(fn ($w) => $w->whereNull($col)->orWhere($col, '')),
            'not_empty' => $q->whereNotNull($col)->where($col, '!=', ''),
            'contains' => $q->where($col, 'like', '%'.addcslashes((string) $value, '%_\\').'%'),
            'starts_with' => $q->where($col, 'like', addcslashes((string) $value, '%_\\').'%'),
            'in' => $q->whereIn($col, (array) $value),
            default => $q->where($col, $op, $value),
        };
    }
}

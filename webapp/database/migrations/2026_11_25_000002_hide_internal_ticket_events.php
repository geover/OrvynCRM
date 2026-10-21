<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Owner, priority and category changes recorded before they became platform-only. */
    public function up(): void
    {
        DB::table('ticket_replies')->where('kind', 'event')->where('side', 'system')->where(function ($q) {
            $q->where('body', 'like', '% assigned the ticket to %')
                ->orWhere('body', 'like', '% changed the priority from %')
                ->orWhere('body', 'like', '% changed the category from %');
        })->update(['side' => 'internal']);
    }
};

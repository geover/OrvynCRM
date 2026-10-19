<?php

use App\Models\Setting;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * The platform's Companies list shows a company's favicon as its icon, and knows whether there is one from a note on the company's central record
     * (so the list never has to open every company). Companies that set a favicon before the note existed get it here, once.
     */
    public function up(): void
    {
        Tenant::all()->each(function (Tenant $tenant) {
            try {
                $tenant->run(function () use ($tenant) {
                    $path = Setting::get('app.favicon_path');
                    if ($path && Storage::disk('public')->exists($path)) {
                        $tenant->update(['favicon_version' => time()]);
                    }
                });
            } catch (Throwable $e) {
                report($e);                                           // a company that cannot be opened now simply has no icon until it saves its favicon again
            }
        });
    }

    public function down(): void {}
};

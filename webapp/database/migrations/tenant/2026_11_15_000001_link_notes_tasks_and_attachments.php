<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notes and tasks that know about each other, and files or links on either:
     *  - tasks.note_id: the note a task came from, or is about
     *  - notes.task_id: the task a note is the record of (the note logged when a task is completed)
     *  - contact_attachments.note_id / task_id: an attachment that belongs to a note or a task (it is still the contact's attachment too)
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->unsignedBigInteger('note_id')->nullable()->index();
        });
        Schema::table('notes', function (Blueprint $t) {
            $t->unsignedBigInteger('task_id')->nullable()->index();
        });
        Schema::table('contact_attachments', function (Blueprint $t) {
            $t->unsignedBigInteger('note_id')->nullable()->index();
            $t->unsignedBigInteger('task_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('contact_attachments', function (Blueprint $t) {
            $t->dropColumn(['note_id', 'task_id']);
        });
        Schema::table('notes', function (Blueprint $t) {
            $t->dropColumn('task_id');
        });
        Schema::table('tasks', function (Blueprint $t) {
            $t->dropColumn('note_id');
        });
    }
};

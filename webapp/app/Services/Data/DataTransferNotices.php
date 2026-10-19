<?php

namespace App\Services\Data;

use App\Models\DataObject;
use App\Models\DataTransfer;
use App\Models\User;
use App\Support\NoticeMail;

/**
 * Tells the person who started an import or an export how it is going, by e-mail: it is waiting in the queue, it has started, and it is done (or
 * failed). Large files take a while, so nobody has to keep the page open to know.
 */
class DataTransferNotices
{
    public static function queued(DataTransfer $t): void
    {
        self::send($t, "Your {$t->direction} is waiting", ["Your {$t->direction} of ".self::what($t).' is in the queue (status: pending). It will start as soon as it is its turn, and you will get another e-mail when it begins and when it is done.']);
    }

    public static function started(DataTransfer $t): void
    {
        self::send($t, "Your {$t->direction} has started", ["Your {$t->direction} of ".self::what($t).' is running now (status: in progress). You can close the page; you will get an e-mail when it is done.']);
    }

    public static function finished(DataTransfer $t): void
    {
        $page = [self::pageLabel($t), url('/admin/data-tools')];
        if ($t->status === 'failed') {
            self::send($t, "Your {$t->direction} did not finish", ["Your {$t->direction} of ".self::what($t).' stopped with an error (status: failed).', 'Reason: '.($t->error ?: 'unknown'), 'Nothing more was done; you can try again.'], $page);

            return;
        }
        $lines = $t->direction === 'import'
            ? ['Your import of '.self::what($t).' is done (status: done).', number_format($t->processed).' rows were imported'.($t->failed ? ' and '.number_format($t->failed).' could not be (the reasons are on the Data import & export page).' : '.')]
            : ['Your export of '.self::what($t).' is done (status: done).', number_format($t->processed).' rows were written. The file is ready to download.'];
        self::send($t, "Your {$t->direction} is done", $lines, $t->direction === 'export' ? ['Download the CSV', url("/files/transfers/{$t->id}")] : $page);
    }

    /** "contacts", "notes and tasks", or "the items of Deals". */
    private static function what(DataTransfer $t): string
    {
        return match ($t->resource) {
            'contacts' => 'contacts',
            'notes_tasks' => 'notes and tasks',
            default => 'the items of '.(DataObject::find($t->options['data_object_id'] ?? null)?->name ?? 'a data object'),
        };
    }

    private static function pageLabel(DataTransfer $t): string
    {
        return 'Open Data import & export';
    }

    /** @param  list<string>  $lines */
    private static function send(DataTransfer $t, string $subject, array $lines, ?array $button = null): void
    {
        $user = $t->user_id ? User::find($t->user_id) : null;
        $user?->email && NoticeMail::send($user->email, $subject, ["Hi {$user->name},", ...$lines], $button);
    }
}

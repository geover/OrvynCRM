<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;

/** Replaces rest/Timeline.php: ONE indexed query on `activities`, not six per-request history imports. */
class TimelineController extends Controller
{
    public function __invoke(Contact $contact)
    {
        return $contact->activities()->with('causer:id,name')->orderByDesc('id')->cursorPaginate(30);
    }
}

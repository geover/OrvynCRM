<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Inertia\Inertia;
use Inertia\Response;

/** "What's new": the platform's announcements, for people inside a company to read. */
class UpdatesController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Updates/Index', [
            'announcements' => Announcement::live()->where('show_in_app', true)->newestFirst()->limit(100)->get()->map->toReader()->values(),
            'categories' => Announcement::CATEGORIES,
        ]);
    }
}

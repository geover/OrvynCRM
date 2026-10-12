<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Write and manage the updates, news and improvements shown on the sign-in page and inside every company. */
class PlatformAnnouncementController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Platform/Announcements', [
            'announcements' => Announcement::orderByDesc('pinned')->orderByDesc('published_at')->orderByDesc('id')->get()->map(fn (Announcement $a) => [
                'id' => $a->id, 'title' => $a->title, 'body' => $a->body, 'category' => $a->category, 'status' => $a->status,
                'pinned' => $a->pinned, 'show_on_login' => $a->show_on_login, 'show_in_app' => $a->show_in_app,
                'published_at' => $a->published_at?->toIso8601String(), 'expires_at' => $a->expires_at?->toIso8601String(),
                'updated_at' => $a->updated_at?->toIso8601String(), 'archived_at' => $a->archived_at?->toIso8601String(),
            ])->values(),
            'categories' => Announcement::CATEGORIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $a = Announcement::create($this->attributes($request) + ['created_by' => $request->attributes->get('platform_identity')?->id]);
        $this->forget();

        return back()->with('success', $a->status === 'draft' ? 'Saved as a draft.' : ($a->status === 'scheduled' ? 'Scheduled.' : 'Published.'));
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        abort_if($announcement->isArchived(), 422, 'Restore it from the archive before editing.');
        $announcement->update($this->attributes($request, $announcement));
        $this->forget();

        return back()->with('success', 'Saved.');
    }

    /** Publish now or take it back to a draft without opening the editor. */
    public function publish(Request $request, Announcement $announcement): RedirectResponse
    {
        abort_if($announcement->isArchived(), 422, 'Restore it from the archive first.');
        $on = $request->boolean('published');
        $announcement->update(['published_at' => $on ? ($announcement->published_at && $announcement->published_at->isPast() ? $announcement->published_at : now()) : null]);
        $this->forget();

        return back()->with('success', $on ? 'Published.' : 'Moved back to drafts.');
    }

    /** Tuck away a draft or an ended update, keeping it so it can be restored. */
    public function archive(Announcement $announcement): RedirectResponse
    {
        // Something people can still see (live) or that is about to appear (scheduled) must first be unpublished or
        // allowed to end; only drafts and ended updates can be tucked away.
        abort_if(in_array($announcement->status, ['live', 'scheduled'], true), 422, 'Unpublish it first (or wait until it has ended): a published update cannot be archived.');
        $announcement->update(['archived_at' => now()]);
        $this->forget();

        return back()->with('success', 'Archived.');
    }

    public function unarchive(Announcement $announcement): RedirectResponse
    {
        $announcement->update(['archived_at' => null]);                      // it comes back as it was: live, scheduled or a draft
        $this->forget();

        return back()->with('success', 'Restored.');
    }

    /** Permanent. Only archived updates can be deleted, so nothing live disappears by accident. */
    public function destroy(Announcement $announcement): RedirectResponse
    {
        abort_unless($announcement->isArchived(), 422, 'Archive it first; only archived updates can be deleted permanently.');
        $announcement->delete();
        $this->forget();

        return back()->with('success', 'Deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Request $request, ?Announcement $current = null): array
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:8000'],
            'category' => ['required', Rule::in(Announcement::CATEGORIES)],
            'pinned' => ['boolean'],
            'show_on_login' => ['boolean'],
            'show_in_app' => ['boolean'],
            'publish' => ['required', Rule::in(['draft', 'now', 'scheduled'])],
            'published_at' => ['nullable', 'required_if:publish,scheduled', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $publishedAt = match ($d['publish']) {
            'draft' => null,
            'scheduled' => Carbon::parse($d['published_at']),
            default => $current?->published_at && $current->published_at->isPast() ? $current->published_at : now(),     // keep the original date when editing a live one
        };
        $expiresAt = ! empty($d['expires_at']) ? Carbon::parse($d['expires_at']) : null;
        if ($expiresAt && $publishedAt && $expiresAt->lte($publishedAt)) {
            throw ValidationException::withMessages(['expires_at' => 'The end date must be after the publish date.']);
        }

        return [
            'title' => trim($d['title']), 'body' => trim($d['body']), 'category' => $d['category'],
            'pinned' => (bool) ($d['pinned'] ?? false),
            'show_on_login' => (bool) ($d['show_on_login'] ?? true), 'show_in_app' => (bool) ($d['show_in_app'] ?? true),
            'published_at' => $publishedAt, 'expires_at' => $expiresAt,
        ];
    }

    private function forget(): void
    {
        Cache::forget('announcements:login');
    }
}

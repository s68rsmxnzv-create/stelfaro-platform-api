<?php

namespace App\Services;

use App\Models\FollowUpNote;
use App\Models\InternalNotification;

class FollowUpNoteReminderGenerator
{
    public function generate(): int
    {
        $created = 0;
        FollowUpNote::query()
            ->where('status', 'pending')
            ->whereNotNull('remind_at')
            ->whereNull('reminder_notified_at')
            ->where('remind_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($notes) use (&$created): void {
                foreach ($notes as $note) {
                    if (! $note->created_by) {
                        $note->forceFill(['reminder_notified_at' => now()])->save();

                        continue;
                    }
                    $notification = InternalNotification::query()->firstOrCreate(
                        ['dedupe_key' => 'follow-up-note:'.$note->id.':'.$note->remind_at?->timestamp],
                        [
                            'user_id' => $note->created_by,
                            'tenant_id' => $note->tenant_id,
                            'category' => 'follow_up_note',
                            'title' => 'Pendiente por revisar',
                            'message' => $note->person_name.' · '.$note->title,
                            'action_url' => '/pendientes?note='.$note->id,
                            'due_date' => $note->remind_at?->toDateString(),
                            'source_type' => 'follow_up_note',
                            'source_id' => (string) $note->id,
                            'metadata' => ['category' => $note->category],
                        ],
                    );
                    $note->forceFill(['reminder_notified_at' => now()])->save();
                    if ($notification->wasRecentlyCreated) {
                        $created++;
                    }
                }
            });

        return $created;
    }
}

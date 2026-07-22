<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\FollowUpNote;
use App\Models\InternalNotification;
use App\Models\Tenant;
use App\Services\PlatformAccessPolicy;
use App\Services\PlatformAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FollowUpNoteController extends Controller
{
    public function index(Request $request, Tenant $tenant, PlatformAccessPolicy $policy): JsonResponse
    {
        abort_unless($policy->canViewTenantCatalog($request->user(), $tenant), 403);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', Rule::in(['pending', 'resolved', 'discarded'])],
            'category' => ['nullable', Rule::in(['collection', 'loan', 'commitment', 'other'])],
            'due' => ['nullable', Rule::in(['overdue', 'today', 'upcoming'])],
            'note_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $today = now('America/El_Salvador')->toDateString();
        $query = FollowUpNote::query()->where('tenant_id', $tenant->id)->with(['creator:id,name', 'resolver:id,name']);
        $query->when($data['note_id'] ?? null, fn ($query, $noteId) => $query->whereKey($noteId));
        $query->when($data['q'] ?? null, function ($query, string $search): void {
            $term = '%'.mb_strtolower(trim($search)).'%';
            $query->where(fn ($nested) => $nested
                ->whereRaw('LOWER(person_name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(title) LIKE ?', [$term])
                ->orWhereRaw("LOWER(COALESCE(description, '')) LIKE ?", [$term]));
        });
        $query->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status));
        $query->when($data['category'] ?? null, fn ($query, $category) => $query->where('category', $category));
        $query->when(($data['due'] ?? null) === 'overdue', fn ($query) => $query->where('status', 'pending')->whereDate('remind_at', '<', $today));
        $query->when(($data['due'] ?? null) === 'today', fn ($query) => $query->where('status', 'pending')->whereDate('remind_at', $today));
        $query->when(($data['due'] ?? null) === 'upcoming', fn ($query) => $query->where('status', 'pending')->whereDate('remind_at', '>', $today));
        $rows = $query->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByRaw('CASE WHEN remind_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('remind_at')->latest('id')->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            'data' => collect($rows->items())->map(fn (FollowUpNote $note) => $this->payload($note))->values(),
            'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total()],
            'stats' => [
                'pending' => FollowUpNote::query()->where('tenant_id', $tenant->id)->where('status', 'pending')->count(),
                'overdue' => FollowUpNote::query()->where('tenant_id', $tenant->id)->where('status', 'pending')->whereDate('remind_at', '<', $today)->count(),
                'today' => FollowUpNote::query()->where('tenant_id', $tenant->id)->where('status', 'pending')->whereDate('remind_at', $today)->count(),
            ],
        ]);
    }

    public function store(Request $request, Tenant $tenant, PlatformAccessPolicy $policy, PlatformAuditLogger $audit): JsonResponse
    {
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
        $data = $request->validate($this->rules(true));
        $note = DB::transaction(function () use ($tenant, $request, $data): FollowUpNote {
            $existing = FollowUpNote::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                $existing->wasRecentlyCreated = false;

                return $existing;
            }
            $note = FollowUpNote::query()->create([
                ...$this->attributes($data),
                'tenant_id' => $tenant->id,
                'idempotency_key' => $data['idempotency_key'],
                'created_by' => $request->user()->id,
            ]);
            $note->wasRecentlyCreated = true;

            return $note;
        });
        $audit->record($request, 'follow_up_note.created', ['follow_up_note_id' => $note->id]);

        $created = $note->wasRecentlyCreated;

        return response()->json(['data' => $this->payload($note->refresh()->load('creator:id,name'))], $created ? 201 : 200);
    }

    public function update(Request $request, Tenant $tenant, FollowUpNote $followUpNote, PlatformAccessPolicy $policy, PlatformAuditLogger $audit): JsonResponse
    {
        $this->authorizeNote($request, $tenant, $followUpNote, $policy);
        abort_unless($followUpNote->status === 'pending', 422, 'Solo puedes editar una nota pendiente.');
        $data = $request->validate($this->rules(false));
        $attributes = $this->attributes($data);
        if (($attributes['remind_at'] ?? null) !== optional($followUpNote->remind_at)->toDateTimeString()) {
            $attributes['reminder_notified_at'] = null;
        }
        $followUpNote->forceFill($attributes)->save();
        $audit->record($request, 'follow_up_note.updated', ['follow_up_note_id' => $followUpNote->id]);

        return response()->json(['data' => $this->payload($followUpNote->refresh()->load('creator:id,name'))]);
    }

    public function resolve(Request $request, Tenant $tenant, FollowUpNote $followUpNote, PlatformAccessPolicy $policy, PlatformAuditLogger $audit): JsonResponse
    {
        $this->authorizeNote($request, $tenant, $followUpNote, $policy);
        $data = $request->validate([
            'resolution_type' => ['required', Rule::in(['invoiced', 'returned', 'completed', 'other'])],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
            'resolution_reference' => ['nullable', 'string', 'max:160'],
        ]);
        if ($followUpNote->status === 'pending') {
            $followUpNote->forceFill([...$data, 'status' => 'resolved', 'resolved_by' => $request->user()->id, 'resolved_at' => now()])->save();
            $this->closeNotification($followUpNote);
            $audit->record($request, 'follow_up_note.resolved', ['follow_up_note_id' => $followUpNote->id, 'resolution_type' => $data['resolution_type']]);
        }

        return response()->json(['data' => $this->payload($followUpNote->refresh()->load(['creator:id,name', 'resolver:id,name']))]);
    }

    public function discard(Request $request, Tenant $tenant, FollowUpNote $followUpNote, PlatformAccessPolicy $policy, PlatformAuditLogger $audit): JsonResponse
    {
        $this->authorizeNote($request, $tenant, $followUpNote, $policy);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        if ($followUpNote->status === 'pending') {
            $followUpNote->forceFill(['status' => 'discarded', 'resolution_type' => 'discarded', 'resolution_note' => $data['reason'], 'resolved_by' => $request->user()->id, 'resolved_at' => now()])->save();
            $this->closeNotification($followUpNote);
            $audit->record($request, 'follow_up_note.discarded', ['follow_up_note_id' => $followUpNote->id]);
        }

        return response()->json(['data' => $this->payload($followUpNote->refresh()->load(['creator:id,name', 'resolver:id,name']))]);
    }

    private function rules(bool $creating): array
    {
        return [
            'idempotency_key' => $creating ? ['required', 'uuid'] : ['prohibited'],
            'core_customer_id' => ['nullable', 'integer', 'min:1'],
            'person_name' => ['required', 'string', 'max:160'],
            'person_phone' => ['nullable', 'string', 'max:40'],
            'person_email' => ['nullable', 'email', 'max:160'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:3000'],
            'category' => ['required', Rule::in(['collection', 'loan', 'commitment', 'other'])],
            'occurred_on' => ['required', 'date'],
            'remind_at' => ['nullable', 'date'],
        ];
    }

    private function attributes(array $data): array
    {
        return [
            'core_customer_id' => $data['core_customer_id'] ?? null,
            'person_name' => trim($data['person_name']),
            'person_phone' => filled($data['person_phone'] ?? null) ? trim($data['person_phone']) : null,
            'person_email' => filled($data['person_email'] ?? null) ? trim($data['person_email']) : null,
            'title' => trim($data['title']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'category' => $data['category'],
            'occurred_on' => $data['occurred_on'],
            'remind_at' => $data['remind_at'] ?? null,
        ];
    }

    private function authorizeNote(Request $request, Tenant $tenant, FollowUpNote $note, PlatformAccessPolicy $policy): void
    {
        abort_unless($note->tenant_id === $tenant->id, 404);
        abort_unless($policy->canOperateTenant($request->user(), $tenant), 403);
    }

    private function closeNotification(FollowUpNote $note): void
    {
        InternalNotification::query()->where('source_type', 'follow_up_note')->where('source_id', (string) $note->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
    }

    private function payload(FollowUpNote $note): array
    {
        return [
            'id' => $note->id,
            'person' => ['customer_id' => $note->core_customer_id, 'name' => $note->person_name, 'phone' => $note->person_phone, 'email' => $note->person_email],
            'title' => $note->title, 'description' => $note->description, 'category' => $note->category,
            'occurred_on' => $note->occurred_on?->format('Y-m-d'), 'remind_at' => $note->remind_at?->toISOString(),
            'status' => $note->status,
            'resolution' => ['type' => $note->resolution_type, 'note' => $note->resolution_note, 'reference' => $note->resolution_reference, 'resolved_at' => $note->resolved_at?->toISOString(), 'resolved_by' => $note->resolver?->name],
            'created_by' => $note->creator?->name, 'created_at' => $note->created_at?->toISOString(), 'updated_at' => $note->updated_at?->toISOString(),
        ];
    }
}

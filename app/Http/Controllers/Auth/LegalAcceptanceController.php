<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LegalAcceptance;
use App\Services\Cash\CashOnboardingNotification;
use App\Services\Legal\LegalAcceptanceRequirement;
use App\Services\PlatformAuditLogger;
use App\Services\PlatformSessionResolver;
use App\Support\Platform\PortalUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class LegalAcceptanceController extends Controller
{
    public function __construct(
        private readonly LegalAcceptanceRequirement $requirement,
        private readonly PlatformSessionResolver $sessionResolver,
        private readonly CashOnboardingNotification $cashOnboarding,
        private readonly PlatformAuditLogger $audit,
    ) {}

    public function edit(Request $request): Response|SymfonyResponse
    {
        if ($request->user()->must_change_password) {
            return redirect()->route('password.temporary.edit');
        }

        $context = $this->requirement->pending($request->user());

        if (! $context) {
            return redirect()->intended($this->destination($request));
        }

        return Inertia::render('Auth/AcceptLegalDocuments', [
            'tenant' => [
                'name' => $context['tenant']->name,
                'environment' => '01',
            ],
            'acceptanceVersion' => config('legal.acceptance_version'),
            'acceptanceText' => config('legal.acceptance_text'),
            'documents' => $context['documents']->map(fn ($document): array => [
                'type' => $document->type,
                'title' => $document->title,
                'version' => $document->version,
                'effective_at' => $document->effective_at->translatedFormat('d \d\e F \d\e Y'),
                'url' => $document->public_path,
            ])->values(),
        ]);
    }

    public function store(Request $request): SymfonyResponse
    {
        if ($request->user()->must_change_password) {
            return redirect()->route('password.temporary.edit');
        }

        $context = $this->requirement->pending($request->user());

        if (! $context) {
            return redirect()->intended($this->destination($request));
        }

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'accept_terms' => ['accepted'],
            'accept_privacy' => ['accepted'],
            'authority_confirmed' => ['accepted'],
            'document_versions' => ['required', 'array'],
            'document_versions.terms' => ['required', 'string'],
            'document_versions.privacy' => ['required', 'string'],
        ]);

        foreach ($context['documents'] as $document) {
            if (($validated['document_versions'][$document->type] ?? null) !== $document->version) {
                throw ValidationException::withMessages([
                    'document_versions' => 'Los documentos legales fueron actualizados. Revísalos nuevamente antes de aceptar.',
                ]);
            }
        }

        $user = $request->user()->fresh();
        $tenant = $context['tenant'];
        $membership = $context['membership'];
        $eventUuid = (string) Str::uuid();
        $requestId = Str::limit((string) ($request->header('X-Request-Id') ?: $request->header('X-Correlation-Id') ?: Str::uuid()), 64, '');
        $sessionId = $request->session()->getId();
        $acceptedAt = now();

        DB::transaction(function () use ($context, $user, $tenant, $membership, $eventUuid, $requestId, $sessionId, $acceptedAt, $request): void {
            foreach ($context['documents'] as $document) {
                LegalAcceptance::query()->firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'tenant_id' => $tenant->id,
                        'legal_document_id' => $document->id,
                    ],
                    [
                        'event_uuid' => $eventUuid,
                        'membership_id' => $membership->id,
                        'document_type' => $document->type,
                        'document_version' => $document->version,
                        'document_hash' => $document->content_hash,
                        'acceptance_version' => config('legal.acceptance_version'),
                        'acceptance_text' => config('legal.acceptance_text'),
                        'environment' => '01',
                        'role_at_acceptance' => $membership->role,
                        'user_email_hash' => hash('sha256', strtolower((string) $user->email)),
                        'tenant_slug' => $tenant->slug,
                        'tenant_name' => $tenant->name,
                        'authentication_method' => 'password_reentry',
                        'password_changed_at' => $user->password_changed_at,
                        'accepted_at' => $acceptedAt,
                        'ip_address' => $request->ip(),
                        'user_agent' => Str::limit((string) $request->userAgent(), 2000, ''),
                        'session_id_hash' => $sessionId !== '' ? hash('sha256', $sessionId) : null,
                        'request_id' => $requestId,
                        'metadata' => [
                            'password_reauthenticated' => true,
                            'checkboxes' => ['terms', 'privacy', 'authority'],
                        ],
                    ],
                );
            }
        });

        $this->audit->record($request, 'legal.documents_accepted', [
            'tenant_id' => $tenant->id,
            'event_uuid' => $eventUuid,
            'document_versions' => $context['documents']->pluck('version', 'type')->all(),
            'authentication_method' => 'password_reentry',
        ]);

        $session = $this->sessionResolver->resolve($user);
        $this->cashOnboarding->ensure($user, $session);
        $target = $this->destination($request, $session);

        if ($request->header('X-Inertia')) {
            return Inertia::location($target);
        }

        return redirect()->intended($target);
    }

    /**
     * @param  array<string, mixed>|null  $session
     */
    private function destination(Request $request, ?array $session = null): string
    {
        $session ??= $this->sessionResolver->resolve($request->user());

        return $session['default_app']['local_path'] ?? PortalUrl::base();
    }
}

<?php

namespace Tests\Feature\V3\Access;

use App\Models\User;
use App\Models\V3\V3AccessEvent;
use App\Services\V3\Access\AccessAuditRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actor = User::create([
        'name' => 'Actor User',
        'email' => 'actor_'.Str::random(8).'@example.com',
        'password' => Hash::make('password'),
        'role' => 'admin',
    ]);

    $this->target = User::create([
        'name' => 'Target User',
        'email' => 'target_'.Str::random(8).'@example.com',
        'password' => Hash::make('password'),
        'role' => 'teacher',
    ]);
});

test('recorder creates account_changed event with allowlisted state snapshot and valid reason', function () {
    $recorder = app(AccessAuditRecorder::class);
    $requestId = (string) Str::uuid();

    $event = $recorder->recordAccountChanged(
        requestId: $requestId,
        actorUserId: $this->actor->id,
        targetUserId: $this->target->id,
        reasonCode: 'grant_access',
        beforeState: ['role' => 'student', 'is_active' => false, 'version' => 0],
        afterState: ['role' => 'teacher', 'is_active' => true, 'version' => 1],
        routeName: 'v3.admin.accounts.access.update'
    );

    expect($event)->toBeInstanceOf(V3AccessEvent::class)
        ->and($event->event_type)->toBe('account_changed')
        ->and($event->reason_code)->toBe('grant_access')
        ->and($event->before_state)->toEqual(['role' => 'student', 'is_active' => false, 'version' => 0])
        ->and($event->after_state)->toEqual(['role' => 'teacher', 'is_active' => true, 'version' => 1]);

    $stored = DB::table('v3_access_events')->where('id', $event->id)->first();
    expect($stored)->not->toBeNull();
});

test('recorder rejects invalid reason codes or non-allowlisted state snapshot keys', function () {
    $recorder = app(AccessAuditRecorder::class);
    $requestId = (string) Str::uuid();

    expect(fn () => $recorder->recordAccountChanged(
        requestId: $requestId,
        actorUserId: $this->actor->id,
        targetUserId: $this->target->id,
        reasonCode: 'invalid_reason_code',
        beforeState: null,
        afterState: ['role' => 'teacher', 'is_active' => true, 'version' => 1]
    ))->toThrow(\InvalidArgumentException::class);

    expect(fn () => $recorder->recordAccountChanged(
        requestId: $requestId,
        actorUserId: $this->actor->id,
        targetUserId: $this->target->id,
        reasonCode: 'grant_access',
        beforeState: null,
        afterState: ['role' => 'teacher', 'is_active' => true, 'version' => 1, 'forbidden_key' => 'secret_value']
    ))->toThrow(\InvalidArgumentException::class);
});

test('recorder recordAccessDenied accepts valid denial reason codes and deduplicates identical request IDs', function () {
    $recorder = app(AccessAuditRecorder::class);
    $requestId = (string) Str::uuid();

    $event1 = $recorder->recordAccessDenied(
        requestId: $requestId,
        actorUserId: null,
        reasonCode: 'unauthenticated',
        routeName: 'v3.entry'
    );

    expect($event1)->not->toBeNull()
        ->and($event1->event_type)->toBe('access_denied')
        ->and($event1->reason_code)->toBe('unauthenticated');

    // Duplicate call with same requestId must not create duplicate row or throw
    $event2 = $recorder->recordAccessDenied(
        requestId: $requestId,
        actorUserId: null,
        reasonCode: 'unauthenticated',
        routeName: 'v3.entry'
    );

    $count = DB::table('v3_access_events')->where('request_id', $requestId)->count();
    expect($count)->toBe(1);
});

test('L16: denial audit storage failure retains denial and logs fixed fallback message without sensitive data', function () {
    Log::spy();

    $recorder = app(AccessAuditRecorder::class);
    $requestId = (string) Str::uuid();

    // Temporarily rename table to force failure
    Schema::rename('v3_access_events', 'v3_access_events_temp');

    try {
        $result = $recorder->recordAccessDenied(
            requestId: $requestId,
            actorUserId: null,
            reasonCode: 'access_denied',
            routeName: 'v3.teacher.workspace'
        );

        expect($result)->toBeNull();

        Log::shouldHaveReceived('emergency')
            ->once()
            ->withArgs(function ($message) use ($requestId) {
                return str_contains($message, 'V3 access denial audit failed') &&
                    str_contains($message, $requestId) &&
                    ! str_contains($message, 'password') &&
                    ! str_contains($message, 'token');
            });
    } finally {
        Schema::rename('v3_access_events_temp', 'v3_access_events');
    }
});

test('L14: audit insert failure during account change propagates exception for transaction rollback', function () {
    $recorder = app(AccessAuditRecorder::class);
    $requestId = (string) Str::uuid();

    Schema::rename('v3_access_events', 'v3_access_events_temp');

    try {
        expect(fn () => $recorder->recordAccountChanged(
            requestId: $requestId,
            actorUserId: $this->actor->id,
            targetUserId: $this->target->id,
            reasonCode: 'grant_access',
            beforeState: null,
            afterState: ['role' => 'teacher', 'is_active' => true, 'version' => 1]
        ))->toThrow(QueryException::class);
    } finally {
        Schema::rename('v3_access_events_temp', 'v3_access_events');
    }
});

test('L15: denied V3 request contains no secret or raw payload values in audit event', function () {
    $recorder = app(AccessAuditRecorder::class);
    $requestId = (string) Str::uuid();

    $event = $recorder->recordAccessDenied(
        requestId: $requestId,
        actorUserId: $this->actor->id,
        reasonCode: 'wrong_role',
        routeName: 'v3.admin.workspace'
    );

    expect($event)->not->toBeNull();

    $row = DB::table('v3_access_events')->where('id', $event->id)->first();
    expect($row->before_state)->toBeNull()
        ->and($row->after_state)->toBeNull()
        ->and($row->route_name)->toBe('v3.admin.workspace');
});

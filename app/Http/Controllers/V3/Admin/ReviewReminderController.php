<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\V3\ProcessingCase;
use App\Services\V3\Admin\PostReviewReminder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReviewReminderController extends Controller
{
    public function __construct(
        protected PostReviewReminder $postReviewReminder
    ) {}

    public function store(Request $request, string $case, string $review): JsonResponse|RedirectResponse
    {
        if (! Str::isUuid($case) || strtolower($case) !== $case || ! Str::isUuid($review) || strtolower($review) !== $review) {
            return $this->errorNotFound($request);
        }

        $caseModel = ProcessingCase::find($case);
        if (! $caseModel) {
            return $this->errorNotFound($request);
        }

        $actor = $request->attributes->get('v3_actor') ?? $request->user();

        try {
            $result = $this->postReviewReminder->post($case, $review, $actor->id, $request->all());
        } catch (ModelNotFoundException $e) {
            return $this->errorNotFound($request);
        } catch (\InvalidArgumentException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'contract_invalid',
                        'message' => 'Invalid operation request.',
                    ],
                ], 422)->header('Cache-Control', 'no-store, private');
            }
            return back()->withErrors(['message' => 'Invalid operation request.']);
        } catch (\DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'operation_conflict',
                        'message' => 'Operation changed. Reload and retry.',
                    ],
                ], 409)->header('Cache-Control', 'no-store, private');
            }
            return back()->withErrors(['message' => 'Operation changed. Reload and retry.']);
        } catch (\Throwable $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => [
                        'code' => 'operation_unavailable',
                        'message' => 'Operation is temporarily unavailable.',
                    ],
                ], 503)->header('Cache-Control', 'no-store, private');
            }
            abort(503, 'Operation is temporarily unavailable.');
        }

        $statusCode = $result['replay'] ? 200 : 201;

        if ($request->expectsJson()) {
            return response()->json([
                'data' => [
                    'reminder_ref' => $result['reminder_ref'],
                    'state' => $result['state'],
                ],
            ], $statusCode)->header('Cache-Control', 'no-store, private');
        }

        return redirect()->route('v3.admin.operations.show', $case, 303)
            ->header('Cache-Control', 'no-store, private');
    }

    protected function errorNotFound(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code' => 'resource_unavailable',
                    'message' => 'V3 resource is unavailable.',
                ],
            ], 404)->header('Cache-Control', 'no-store, private');
        }
        abort(404, 'V3 resource is unavailable.');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\Submission;
use App\Models\SubmissionStatusEvent;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederCycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_submissions_follow_expected_role_and_status_cycle(): void
    {
        $this->seed(DatabaseSeeder::class);

        $submissions = Submission::query()
            ->with(['reporter:id,municipality_id,role', 'validator:id,municipality_id,role'])
            ->get();

        $this->assertNotEmpty($submissions);

        foreach ($submissions as $submission) {
            $this->assertNotNull($submission->reporter_id, "Submission {$submission->id} is missing reporter.");
            $this->assertSame(
                (int) $submission->municipality_id,
                (int) $submission->reporter?->municipality_id,
                "Submission {$submission->id} reporter scope mismatch.",
            );

            $status = SubmissionStatus::from($submission->status);
            $events = SubmissionStatusEvent::query()
                ->with('actor:id,role,municipality_id')
                ->where('submission_id', $submission->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->values();

            $expectedTransitions = $this->expectedTransitionsForStatus($status);
            $this->assertCount(
                count($expectedTransitions),
                $events,
                "Submission {$submission->id} has unexpected timeline length.",
            );

            foreach ($expectedTransitions as $index => [$from, $to]) {
                $this->assertSame($from, $events[$index]->from_status, "Submission {$submission->id} transition {$index} from_status mismatch.");
                $this->assertSame($to, $events[$index]->to_status, "Submission {$submission->id} transition {$index} to_status mismatch.");
            }

            $this->assertSame((int) $submission->reporter_id, (int) $events[0]->actor_id, "Submission {$submission->id} first actor must be reporter.");

            if ($events->count() > 1) {
                $reviewActor = $events[1]->actor;
                $this->assertNotNull($reviewActor, "Submission {$submission->id} review actor missing.");
                $this->assertTrue(
                    in_array($reviewActor->role, [UserRole::MUNICIPAL_FOCAL_POINT->value, UserRole::UNDP_ADMIN->value], true),
                    "Submission {$submission->id} review actor role is not validator/admin.",
                );

                if ($reviewActor->role === UserRole::MUNICIPAL_FOCAL_POINT->value) {
                    $this->assertSame(
                        (int) $submission->municipality_id,
                        (int) $reviewActor->municipality_id,
                        "Submission {$submission->id} focal actor municipality mismatch.",
                    );
                }
            }

            if (in_array($status, [SubmissionStatus::APPROVED, SubmissionStatus::REWORK_REQUESTED, SubmissionStatus::REJECTED], true)) {
                $this->assertNotNull($submission->validated_by, "Submission {$submission->id} terminal decision missing validator.");
                $this->assertSame(
                    (int) $submission->validated_by,
                    (int) $events->last()->actor_id,
                    "Submission {$submission->id} final actor must match validated_by.",
                );
            }
        }
    }

    /**
     * @return array<int, array{0:?string,1:string}>
     */
    private function expectedTransitionsForStatus(SubmissionStatus $status): array
    {
        return match ($status) {
            SubmissionStatus::DRAFT => [
                [null, SubmissionStatus::DRAFT->value],
            ],
            SubmissionStatus::QUEUED => [
                [null, SubmissionStatus::QUEUED->value],
            ],
            SubmissionStatus::SUBMITTED => [
                [null, SubmissionStatus::SUBMITTED->value],
            ],
            SubmissionStatus::UNDER_REVIEW => [
                [null, SubmissionStatus::SUBMITTED->value],
                [SubmissionStatus::SUBMITTED->value, SubmissionStatus::UNDER_REVIEW->value],
            ],
            SubmissionStatus::APPROVED => [
                [null, SubmissionStatus::SUBMITTED->value],
                [SubmissionStatus::SUBMITTED->value, SubmissionStatus::UNDER_REVIEW->value],
                [SubmissionStatus::UNDER_REVIEW->value, SubmissionStatus::APPROVED->value],
            ],
            SubmissionStatus::REWORK_REQUESTED => [
                [null, SubmissionStatus::SUBMITTED->value],
                [SubmissionStatus::SUBMITTED->value, SubmissionStatus::UNDER_REVIEW->value],
                [SubmissionStatus::UNDER_REVIEW->value, SubmissionStatus::REWORK_REQUESTED->value],
            ],
            SubmissionStatus::REJECTED => [
                [null, SubmissionStatus::SUBMITTED->value],
                [SubmissionStatus::SUBMITTED->value, SubmissionStatus::UNDER_REVIEW->value],
                [SubmissionStatus::UNDER_REVIEW->value, SubmissionStatus::REJECTED->value],
            ],
        };
    }
}

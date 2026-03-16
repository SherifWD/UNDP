<?php

namespace Database\Seeders;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\SubmissionStatusChangedNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RandomNotificationsSeeder extends Seeder
{
    private const MIN_NOTIFICATIONS_PER_USER = 3;

    private const MAX_NOTIFICATIONS_PER_USER = 8;

    public function run(): void
    {
        $totalUsers = User::query()->count();

        if ($totalUsers === 0) {
            $this->command?->warn('No users found. Nothing to seed.');

            return;
        }

        $submissions = Submission::query()
            ->with('project:id,name_en,name_ar')
            ->get();

        $submissionsByMunicipality = $submissions->groupBy(
            static fn (Submission $submission): string => (string) $submission->municipality_id,
        );

        $seededNotifications = 0;

        User::query()
            ->select(['id', 'municipality_id'])
            ->orderBy('id')
            ->chunkById(200, function (Collection $users) use ($submissions, $submissionsByMunicipality, &$seededNotifications): void {
                $rows = [];

                foreach ($users as $user) {
                    array_push($rows, ...$this->rowsForUser($user, $submissions, $submissionsByMunicipality));
                }

                if ($rows === []) {
                    return;
                }

                DB::table('notifications')->insert($rows);
                $seededNotifications += count($rows);
            });

        $this->command?->info("Seeded {$seededNotifications} notifications for {$totalUsers} users.");
    }

    private function rowsForUser(User $user, Collection $allSubmissions, Collection $submissionsByMunicipality): array
    {
        $count = random_int(self::MIN_NOTIFICATIONS_PER_USER, self::MAX_NOTIFICATIONS_PER_USER);
        $pool = $this->submissionPoolFor($user, $allSubmissions, $submissionsByMunicipality);
        $rows = [];

        for ($index = 0; $index < $count; $index++) {
            $createdAt = $this->createdAtForIndex($index);
            $readAt = $index === 0 ? null : $this->readAtFor($createdAt);
            $payload = $this->payloadForUser($user, $pool, $createdAt, $index);

            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => SubmissionStatusChangedNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
                'read_at' => $readAt,
                'created_at' => $createdAt,
                'updated_at' => $readAt ?? $createdAt,
            ];
        }

        return $rows;
    }

    private function submissionPoolFor(User $user, Collection $allSubmissions, Collection $submissionsByMunicipality): Collection
    {
        if ($user->municipality_id !== null) {
            $municipalityPool = $submissionsByMunicipality->get((string) $user->municipality_id, collect());

            if ($municipalityPool->isNotEmpty()) {
                return $municipalityPool;
            }
        }

        return $allSubmissions;
    }

    private function payloadForUser(User $user, Collection $submissionPool, Carbon $createdAt, int $index): array
    {
        if ($submissionPool->isNotEmpty()) {
            /** @var Submission $submission */
            $submission = $submissionPool->random();

            return (new SubmissionStatusChangedNotification($submission))->toArray($user);
        }

        $statuses = [
            SubmissionStatus::SUBMITTED->value,
            SubmissionStatus::UNDER_REVIEW->value,
            SubmissionStatus::APPROVED->value,
            SubmissionStatus::REWORK_REQUESTED->value,
            SubmissionStatus::REJECTED->value,
        ];
        $status = $statuses[array_rand($statuses)];

        $titles = [
            'Field update',
            'Progress report',
            'Site inspection',
            'Community feedback',
            'Verification note',
        ];

        $projects = [
            'Urban Water Network',
            'School Rehabilitation',
            'Primary Healthcare Clinics',
        ];

        return [
            'submission_id' => null,
            'title' => $titles[array_rand($titles)].' '.($index + 1),
            'status' => $status,
            'status_label' => SubmissionStatus::from($status)->label(),
            'project_name' => $projects[array_rand($projects)],
            'updated_at' => $createdAt->toIso8601String(),
        ];
    }

    private function createdAtForIndex(int $index): Carbon
    {
        if ($index === 0) {
            return now()
                ->subHours(random_int(1, 72))
                ->subMinutes(random_int(0, 59));
        }

        return now()
            ->subDays(random_int(2, 30))
            ->subHours(random_int(0, 23))
            ->subMinutes(random_int(0, 59));
    }

    private function readAtFor(Carbon $createdAt): ?Carbon
    {
        if (random_int(1, 100) > 70) {
            return null;
        }

        $readAt = $createdAt->copy()->addMinutes(random_int(5, 2880));

        return $readAt->greaterThan(now()) ? now() : $readAt;
    }
}

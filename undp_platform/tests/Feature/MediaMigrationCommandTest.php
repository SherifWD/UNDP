<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Models\MediaAsset;
use App\Models\Municipality;
use App\Models\Project;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_migrate_to_s3_copies_legacy_public_assets_and_updates_rows(): void
    {
        Storage::fake('public');
        Storage::fake('s3');

        config()->set('filesystems.disks.s3.key', 'testing');
        config()->set('filesystems.disks.s3.secret', 'testing');
        config()->set('filesystems.disks.s3.region', 'us-east-1');
        config()->set('filesystems.disks.s3.bucket', 'undp-media-test');

        $municipality = Municipality::query()->create([
            'name_en' => 'Tripoli',
            'name_ar' => 'Tripoli',
            'code' => 'TRI',
        ]);

        $project = Project::query()->create([
            'municipality_id' => $municipality->id,
            'name_en' => 'Water Network',
            'name_ar' => 'Water Network',
            'status' => 'active',
        ]);

        $reporter = User::factory()->create([
            'role' => UserRole::REPORTER->value,
            'municipality_id' => $municipality->id,
        ]);

        $submission = Submission::query()->create([
            'reporter_id' => $reporter->id,
            'project_id' => $project->id,
            'municipality_id' => $municipality->id,
            'status' => SubmissionStatus::SUBMITTED->value,
            'title' => 'Legacy media submission',
        ]);

        $mediaAsset = MediaAsset::query()->create([
            'uuid' => (string) Str::uuid(),
            'submission_id' => $submission->id,
            'uploaded_by' => $reporter->id,
            'disk' => 'public',
            'bucket' => null,
            'object_key' => 'mobile/assets/'.$submission->id.'/legacy-evidence.jpg',
            'media_type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'legacy-evidence.jpg',
            'size_bytes' => 512,
            'status' => 'uploaded',
            'uploaded_at' => now(),
        ]);

        Storage::disk('public')->put($mediaAsset->object_key, 'legacy-image');

        $exitCode = Artisan::call('media:migrate-to-s3', [
            '--from' => 'public',
            '--to' => 's3',
            '--delete-source' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $mediaAsset->refresh();

        $this->assertSame('s3', $mediaAsset->disk);
        $this->assertSame('undp-media-test', $mediaAsset->bucket);
        Storage::disk('s3')->assertExists($mediaAsset->object_key);
        Storage::disk('public')->assertMissing($mediaAsset->object_key);
    }
}

<?php

use App\Models\MediaAsset;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'media:migrate-to-s3
        {--from=public : Source disk that currently holds the media files}
        {--to=s3 : Target disk that should own the media files after migration}
        {--submission-id=* : Restrict the migration to one or more submission IDs}
        {--media-asset-id=* : Restrict the migration to one or more media asset IDs}
        {--delete-source : Delete the source object after a successful copy}
        {--dry-run : Preview the migration without copying files or updating the database}',
    function (): int {
        $fromDisk = (string) $this->option('from');
        $toDisk = (string) $this->option('to');
        $dryRun = (bool) $this->option('dry-run');
        $deleteSource = (bool) $this->option('delete-source');
        $targetDiskConfig = config("filesystems.disks.{$toDisk}");
        $sourceDiskConfig = config("filesystems.disks.{$fromDisk}");

        if (! is_array($sourceDiskConfig)) {
            $this->error("Source disk [{$fromDisk}] is not configured.");

            return Command::FAILURE;
        }

        if (! is_array($targetDiskConfig)) {
            $this->error("Target disk [{$toDisk}] is not configured.");

            return Command::FAILURE;
        }

        if (($targetDiskConfig['driver'] ?? null) !== 's3') {
            $this->error("Target disk [{$toDisk}] must use the s3 driver.");

            return Command::FAILURE;
        }

        $submissionIds = collect((array) $this->option('submission-id'))
            ->filter(fn ($value): bool => is_scalar($value) && (string) $value !== '')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        $mediaAssetIds = collect((array) $this->option('media-asset-id'))
            ->filter(fn ($value): bool => is_scalar($value) && (string) $value !== '')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->values();

        $query = MediaAsset::query()
            ->where('disk', $fromDisk)
            ->orderBy('id');

        if ($submissionIds->isNotEmpty()) {
            $query->whereIn('submission_id', $submissionIds->all());
        }

        if ($mediaAssetIds->isNotEmpty()) {
            $query->whereKey($mediaAssetIds->all());
        }

        $assets = $query->get();

        if ($assets->isEmpty()) {
            $this->warn("No media assets found on disk [{$fromDisk}] for the selected filters.");

            return Command::SUCCESS;
        }

        $bucket = $targetDiskConfig['bucket'] ?? null;
        $stats = [
            'copied' => 0,
            'adopted_existing_target' => 0,
            'updated' => 0,
            'deleted_source' => 0,
            'missing_source' => 0,
            'failed' => 0,
        ];

        $this->info(sprintf(
            'Processing %d media asset(s) from [%s] to [%s]%s.',
            $assets->count(),
            $fromDisk,
            $toDisk,
            $dryRun ? ' in dry-run mode' : '',
        ));

        foreach ($assets as $asset) {
            $path = trim((string) $asset->object_key, '/');

            if ($path === '') {
                $stats['failed']++;
                $this->error("Media asset #{$asset->id} has an empty object key.");

                continue;
            }

            $sourceExists = Storage::disk($fromDisk)->exists($path);
            $targetExists = Storage::disk($toDisk)->exists($path);

            if ($dryRun) {
                $this->line(sprintf(
                    '#%d submission=%d path=%s source=%s target=%s',
                    $asset->id,
                    $asset->submission_id,
                    $path,
                    $sourceExists ? 'yes' : 'no',
                    $targetExists ? 'yes' : 'no',
                ));

                continue;
            }

            if (! $sourceExists && ! $targetExists) {
                $stats['missing_source']++;
                $stats['failed']++;
                $this->error("Media asset #{$asset->id} is missing on both [{$fromDisk}] and [{$toDisk}] for [{$path}].");

                continue;
            }

            if ($sourceExists && ! $targetExists) {
                $stream = Storage::disk($fromDisk)->readStream($path);

                if ($stream === false) {
                    $stats['failed']++;
                    $this->error("Media asset #{$asset->id} could not be read from [{$fromDisk}] for [{$path}].");

                    continue;
                }

                try {
                    $written = Storage::disk($toDisk)->writeStream($path, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                if ($written === false) {
                    $stats['failed']++;
                    $this->error("Media asset #{$asset->id} could not be copied to [{$toDisk}] for [{$path}].");

                    continue;
                }

                $stats['copied']++;
            } elseif ($targetExists) {
                $stats['adopted_existing_target']++;
            }

            $asset->forceFill([
                'disk' => $toDisk,
                'bucket' => $bucket,
            ])->save();

            $stats['updated']++;

            if ($deleteSource && $sourceExists) {
                $deleted = Storage::disk($fromDisk)->delete($path);

                if ($deleted) {
                    $stats['deleted_source']++;
                } else {
                    $this->warn("Media asset #{$asset->id} was updated but the source file could not be deleted from [{$fromDisk}] for [{$path}].");
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            collect($stats)
                ->map(fn (int $count, string $metric): array => [$metric, $count])
                ->values()
                ->all(),
        );

        if ($stats['failed'] > 0) {
            $this->warn('Some media assets could not be migrated. Review the errors above.');
        }

        return Command::SUCCESS;
    }
)->purpose('Copy legacy submission attachments to S3 and update media_assets rows.');

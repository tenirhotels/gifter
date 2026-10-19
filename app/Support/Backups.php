<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/** Резервные копии базы на диске «backups» (папка gifter/, архивы spatie/laravel-backup). */
final class Backups
{
    public const DISK = 'backups';

    /** @return array{path: string, size: int, created_at: CarbonImmutable}|null */
    public static function latest(): ?array
    {
        $disk = Storage::disk(self::DISK);
        $path = collect($disk->files(config('backup.backup.name')))
            ->filter(fn (string $file) => str_ends_with($file, '.zip'))
            ->sortByDesc(fn (string $file) => $disk->lastModified($file))
            ->first();

        return $path ? [
            'path' => $path,
            'size' => $disk->size($path),
            'created_at' => CarbonImmutable::createFromTimestamp($disk->lastModified($path), config('app.timezone')),
        ] : null;
    }

    public static function absolutePath(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }
}

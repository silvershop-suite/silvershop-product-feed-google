<?php

declare(strict_types=1);

namespace SilverShop\ProductFeed\Google\Service;

use SilverShop\ProductFeed\Google\Model\GoogleShoppingCategory;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;

/**
 * Seeds {@link GoogleShoppingCategory} records from Google's official product-taxonomy file (bundled in
 * {@code resources/}, format {@code "<id> - A > B > C"}). Parent links are rebuilt from each line's own path, so the
 * resulting records form Google's category tree. Seeding is idempotent (keyed by {@code GoogleID}) and wrapped in a
 * single transaction for speed on the full ~5,600-node file.
 */
class GoogleTaxonomy
{
    use Injectable;
    use Configurable;

    private const TAXONOMY_FILE = 'google-product-taxonomy.txt';

    /**
     * Absolute path of the bundled taxonomy file. Replace it (same format) to use a newer or localised taxonomy.
     */
    public static function bundledPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/' . self::TAXONOMY_FILE;
    }

    /**
     * Seed from the bundled taxonomy file (or a newer one dropped in its place). Returns the number of nodes written.
     */
    public function seed(): int
    {
        $path = static::bundledPath();
        $text = is_file($path) ? (string) file_get_contents($path) : '';

        return $this->seedFrom($text);
    }

    /**
     * Seed from taxonomy text (one {@code "<id> - A > B > C"} line per node; blank and {@code #} lines ignored).
     * Idempotent: an existing node with the same {@code GoogleID} is updated in place.
     */
    public function seedFrom(string $text): int
    {
        $parsed = $this->parse($text);
        if ($parsed['nodes'] === []) {
            return 0;
        }

        /** @var array<int, array{title: string, path: string, parentPath: string, depth: int}> $nodes */
        $nodes = $parsed['nodes'];
        /** @var array<string, int> $pathToId */
        $pathToId = $parsed['pathToId'];

        // Seed shallow nodes first so a parent record always exists before its children.
        uasort($nodes, static fn (array $a, array $b): int => $a['depth'] <=> $b['depth']);

        /** @var array<int, GoogleShoppingCategory> $records keyed by GoogleID */
        $records = [];
        foreach (GoogleShoppingCategory::get() as $existing) {
            $records[(int) $existing->GoogleID] = $existing;
        }

        $count = 0;
        DB::get_conn()->withTransaction(function () use ($nodes, $pathToId, &$records, &$count): void {
            foreach ($nodes as $googleId => $info) {
                $record = $records[$googleId] ?? GoogleShoppingCategory::create();

                $parentGoogleId = $info['parentPath'] !== '' ? ($pathToId[$info['parentPath']] ?? 0) : 0;
                $parentRecord = $parentGoogleId !== 0 ? ($records[$parentGoogleId] ?? null) : null;

                $record->Title = $info['title'];
                $record->GoogleID = $googleId;
                $record->FullPath = $info['path'];
                $record->ParentID = $parentRecord !== null ? (int) $parentRecord->ID : 0;
                $record->write();

                $records[$googleId] = $record;
                $count++;
            }
        });

        return $count;
    }

    /**
     * @return array{nodes: array<int, array{title: string, path: string, parentPath: string, depth: int}>, pathToId: array<string, int>}
     */
    private function parse(string $text): array
    {
        $nodes = [];
        $pathToId = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $sep = strpos($line, ' - ');
            if ($sep === false) {
                continue;
            }

            $googleId = (int) trim(substr($line, 0, $sep));
            $path = trim(substr($line, $sep + 3));
            if ($googleId === 0 || $path === '') {
                continue;
            }

            $segments = array_map('trim', explode(' > ', $path));
            $title = (string) end($segments);
            $parentPath = count($segments) > 1
                ? implode(' > ', array_slice($segments, 0, -1))
                : '';

            $nodes[$googleId] = [
                'title' => $title,
                'path' => $path,
                'parentPath' => $parentPath,
                'depth' => count($segments),
            ];
            $pathToId[$path] = $googleId;
        }

        return ['nodes' => $nodes, 'pathToId' => $pathToId];
    }
}

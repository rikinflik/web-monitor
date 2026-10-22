<?php

namespace Database\Seeders;

use App\Models\AlertSource;
use Illuminate\Database\Seeder;

/**
 * Seeds the six fixed ecosystems tracked by the Tech Update Alerts feature.
 *
 * Idempotent by design (updateOrCreate keyed on `key`): re-running this
 * seeder never duplicates rows or touches already-recorded AlertFinding
 * history, it only refreshes name/kind/config if they changed.
 */
class AlertSourceSeeder extends Seeder
{
    /**
     * Seed the alert_sources table.
     */
    public function run(): void
    {
        $sources = [
            [
                'key' => 'php',
                'name' => 'PHP',
                'kind' => AlertSource::KIND_RSS,
                'config' => ['url' => 'https://www.php.net/feed.atom'],
            ],
            [
                'key' => 'drupal',
                'name' => 'Drupal',
                'kind' => AlertSource::KIND_RSS,
                'config' => ['url' => 'https://www.drupal.org/security/rss.xml'],
            ],
            [
                'key' => 'wordpress',
                'name' => 'WordPress',
                'kind' => AlertSource::KIND_RSS,
                'config' => ['url' => 'https://wordpress.org/news/category/security/feed/'],
            ],
            [
                'key' => 'laravel',
                'name' => 'Laravel',
                'kind' => AlertSource::KIND_GITHUB_ADVISORY,
                'config' => ['ecosystem' => 'composer', 'affects' => 'laravel/framework'],
            ],
            [
                'key' => 'vue',
                'name' => 'Vue',
                'kind' => AlertSource::KIND_GITHUB_ADVISORY,
                'config' => ['ecosystem' => 'npm', 'affects' => 'vue,@vue/compiler-sfc'],
            ],
            [
                'key' => 'angular',
                'name' => 'Angular',
                'kind' => AlertSource::KIND_GITHUB_ADVISORY,
                'config' => [
                    'ecosystem' => 'npm',
                    'affects' => '@angular/core,@angular/router,@angular/common',
                ],
            ],
        ];

        foreach ($sources as $source) {
            AlertSource::query()->updateOrCreate(
                ['key' => $source['key']],
                $source,
            );
        }
    }
}

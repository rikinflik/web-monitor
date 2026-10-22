<?php

namespace Database\Seeders;

use App\Models\AlertSource;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds the six fixed ecosystems tracked by the Tech Update Alerts feature.
 *
 * Idempotent by design (updateOrCreate keyed on `key`): re-running this
 * seeder never duplicates rows or touches already-recorded AlertFinding
 * history, it only refreshes name/kind/config (and security_only) if they
 * changed.
 *
 * `security_only` is read here so this stays the single place the six row
 * definitions live (per source doc comment below) — but this seeder's
 * *first* call happens from inside create_alert_sources_table's own up(),
 * at a point where the `security_only` column does not exist yet (it is
 * added later, additively, by add_security_flag_to_alert_sources_and
 * _findings). That migration re-invokes this seeder once the column
 * exists, so the key is dropped here rather than included unconditionally,
 * to avoid breaking the original migration on a brand-new install.
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
                // General news feed, not security-only: findings are
                // classified per-item on title via AlertSecurityClassifier.
                'security_only' => false,
            ],
            [
                'key' => 'drupal',
                'name' => 'Drupal',
                'kind' => AlertSource::KIND_RSS,
                'config' => ['url' => 'https://www.drupal.org/security/rss.xml'],
                // Security-only by construction: this is drupal.org's
                // dedicated security advisories feed.
                'security_only' => true,
            ],
            [
                'key' => 'wordpress',
                'name' => 'WordPress',
                'kind' => AlertSource::KIND_RSS,
                'config' => ['url' => 'https://wordpress.org/news/category/security/feed/'],
                // Security-only by construction: wordpress.org's security
                // category feed.
                'security_only' => true,
            ],
            [
                'key' => 'laravel',
                'name' => 'Laravel',
                'kind' => AlertSource::KIND_GITHUB_ADVISORY,
                'config' => ['ecosystem' => 'composer', 'affects' => 'laravel/framework'],
                // Security-only by construction: GitHub Advisories only
                // ever returns vulnerability disclosures.
                'security_only' => true,
            ],
            [
                'key' => 'vue',
                'name' => 'Vue',
                'kind' => AlertSource::KIND_GITHUB_ADVISORY,
                'config' => ['ecosystem' => 'npm', 'affects' => 'vue,@vue/compiler-sfc'],
                'security_only' => true,
            ],
            [
                'key' => 'angular',
                'name' => 'Angular',
                'kind' => AlertSource::KIND_GITHUB_ADVISORY,
                'config' => [
                    'ecosystem' => 'npm',
                    'affects' => '@angular/core,@angular/router,@angular/common',
                ],
                'security_only' => true,
            ],
        ];

        $hasSecurityOnlyColumn = Schema::hasColumn('alert_sources', 'security_only');

        foreach ($sources as $source) {
            if (! $hasSecurityOnlyColumn) {
                unset($source['security_only']);
            }

            AlertSource::query()->updateOrCreate(
                ['key' => $source['key']],
                $source,
            );
        }
    }
}

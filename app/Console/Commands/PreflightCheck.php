<?php

namespace App\Console\Commands;

use App\Support\Preflight;
use Illuminate\Console\Command;

/**
 * Checks the deployment configuration for the chosen profile and exits non-zero on any FAIL, so a
 * deploy script can stop. Read-only: it reads configuration, opens no database connection and
 * changes nothing, and it prints check names and fixed messages, never configured values.
 */
class PreflightCheck extends Command
{
    protected $signature = 'bizflow:preflight {--profile= : local, demo or production (default: demo when demo mode is on, production when APP_ENV is production, otherwise local)}';

    protected $description = 'Check this environment is safe to deploy (read-only; prints no configuration values)';

    public function handle(): int
    {
        $profile = $this->option('profile') ?: $this->detectProfile();

        if (! in_array($profile, Preflight::PROFILES, true)) {
            $this->error('Unknown profile. Use one of: '.implode(', ', Preflight::PROFILES).'.');

            return self::INVALID;
        }

        $results = Preflight::evaluate($profile, Preflight::settingsFromConfig());

        $this->line("Profile: {$profile}");

        foreach ($results as $result) {
            $line = sprintf('[%s] %s: %s', $result['level'], $result['check'], $result['message']);

            match ($result['level']) {
                Preflight::FAIL => $this->error($line),
                Preflight::WARN => $this->warn($line),
                default => $this->line($line),
            };
        }

        $failed = count(array_filter($results, fn (array $r) => $r['level'] === Preflight::FAIL));
        $warned = count(array_filter($results, fn (array $r) => $r['level'] === Preflight::WARN));

        $this->newLine();
        $this->line($failed > 0
            ? "{$failed} check(s) failed. Do not deploy."
            : "No failures ({$warned} warning(s)).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function detectProfile(): string
    {
        return match (true) {
            (bool) config('bizflow.demo') => 'demo',
            app()->isProduction() => 'production',
            default => 'local',
        };
    }
}

<?php

namespace Tests\Feature\Security;

use PHPUnit\Framework\TestCase;

/**
 * CI and the weekly audit must audit the whole dependency graph and must be able to fail. The
 * production-only audit (`npm audit --omit=dev`) hid build tools and bundled libraries (axios is a
 * devDependency that ends up in the shipped JavaScript), so it must not come back.
 */
class DependencyAuditConfigTest extends TestCase
{
    private function workflow(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/.github/workflows/'.$name);
    }

    public function test_the_npm_audits_cover_development_dependencies(): void
    {
        foreach (['ci.yml', 'audit.yml'] as $file) {
            $yaml = $this->workflow($file);

            $this->assertMatchesRegularExpression('/^\s*(?:- )?(?:name: npm audit\n\s*)?run: npm audit\s*$/m', $yaml, "{$file} runs a plain npm audit");
            $this->assertStringNotContainsString('--omit=dev', $yaml, "{$file} must audit devDependencies too");
            $this->assertStringNotContainsString('--production', $yaml);
        }
    }

    public function test_audit_failures_are_not_swallowed(): void
    {
        foreach (['ci.yml', 'audit.yml'] as $file) {
            $yaml = $this->workflow($file);

            $this->assertStringNotContainsString('continue-on-error', $yaml, "{$file} must let an audit failure fail the job");
            $this->assertDoesNotMatchRegularExpression('/(?:npm|composer) audit[^\n]*\|\|\s*true/', $yaml);
            $this->assertDoesNotMatchRegularExpression('/audit[^\n]*--(?:ignore|audit-level)/', $yaml, 'no advisory is filtered out');
        }
    }

    public function test_composer_is_audited_in_ci_and_weekly(): void
    {
        $this->assertStringContainsString('composer audit --locked', $this->workflow('ci.yml'));
        $this->assertStringContainsString('composer audit --locked', $this->workflow('audit.yml'));
    }

    public function test_the_axios_case_this_exists_for_is_real(): void
    {
        $package = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/package.json'), true);
        $bootstrap = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/bootstrap.js');

        // If this ever fails the audits above may be narrowed again; until then they may not.
        $this->assertArrayHasKey('axios', $package['devDependencies'] ?? []);
        $this->assertStringContainsString("import axios from 'axios'", $bootstrap);
    }
}

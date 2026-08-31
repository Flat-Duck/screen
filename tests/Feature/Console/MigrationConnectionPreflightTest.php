<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class MigrationConnectionPreflightTest extends TestCase
{
    public function test_it_accepts_separate_pooled_and_direct_production_hosts_without_printing_credentials(): void
    {
        config()->set([
            'app.env' => 'production',
            'database.connections.pgsql.host' => 'ep-example-pooler.eu-central-1.aws.neon.tech',
            'database.connections.pgsql_direct.driver' => 'pgsql',
            'database.connections.pgsql_direct.host' => 'ep-example.eu-central-1.aws.neon.tech',
            'database.connections.pgsql_direct.password' => 'never-print-this',
        ]);

        $this->artisan('database:migration-preflight', ['--no-connect' => true])
            ->expectsOutputToContain('Connection: pgsql_direct')
            ->expectsOutputToContain('Host: ep-example.eu-central-1.aws.neon.tech')
            ->doesntExpectOutputToContain('never-print-this')
            ->assertSuccessful();
    }

    public function test_it_rejects_the_application_connection_for_migrations(): void
    {
        $this->artisan('database:migration-preflight', [
            '--database' => 'pgsql',
            '--no-connect' => true,
        ])->assertFailed();
    }

    public function test_it_rejects_a_pooler_as_the_direct_connection(): void
    {
        config()->set([
            'database.connections.pgsql_direct.driver' => 'pgsql',
            'database.connections.pgsql_direct.host' => 'ep-example-pooler.eu-central-1.aws.neon.tech',
        ]);

        $this->artisan('database:migration-preflight', ['--no-connect' => true])
            ->expectsOutputToContain('pooled hostname')
            ->assertFailed();
    }

    public function test_production_requires_the_application_connection_to_remain_pooled(): void
    {
        config()->set([
            'app.env' => 'production',
            'database.connections.pgsql.host' => 'ep-example.eu-central-1.aws.neon.tech',
            'database.connections.pgsql_direct.driver' => 'pgsql',
            'database.connections.pgsql_direct.host' => 'ep-direct.eu-central-1.aws.neon.tech',
        ]);

        $this->artisan('database:migration-preflight', ['--no-connect' => true])
            ->expectsOutputToContain('application connection must use the pooled Neon hostname')
            ->assertFailed();
    }
}

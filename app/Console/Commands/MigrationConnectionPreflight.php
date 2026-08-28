<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class MigrationConnectionPreflight extends Command
{
    protected $signature = 'database:migration-preflight
        {--database=pgsql_direct : Database connection that migrations will use}
        {--no-connect : Validate configuration without opening a database connection}';

    protected $description = 'Verify migrations are configured for the direct PostgreSQL endpoint';

    public function handle(): int
    {
        $connection = (string) $this->option('database');

        if ($connection !== 'pgsql_direct') {
            $this->components->error('Migrations must explicitly use the pgsql_direct connection.');

            return self::FAILURE;
        }

        /** @var array<string, mixed>|null $configuration */
        $configuration = config("database.connections.{$connection}");
        if ($configuration === null || ($configuration['driver'] ?? null) !== 'pgsql') {
            $this->components->error('The pgsql_direct PostgreSQL connection is not configured.');

            return self::FAILURE;
        }

        $directHost = trim((string) ($configuration['host'] ?? ''));
        $applicationHost = trim((string) config('database.connections.pgsql.host', ''));

        if ($directHost === '') {
            $this->components->error('DB_DIRECT_HOST resolved to an empty value.');

            return self::FAILURE;
        }

        if (str_contains(strtolower($directHost), '-pooler')) {
            $this->components->error('pgsql_direct resolved to a pooled hostname; set DB_DIRECT_HOST to the unpooled endpoint.');

            return self::FAILURE;
        }

        if (config('app.env') === 'production') {
            if (! str_contains(strtolower($applicationHost), '-pooler')) {
                $this->components->error('The production pgsql application connection must use the pooled Neon hostname.');

                return self::FAILURE;
            }

            if (strcasecmp($applicationHost, $directHost) === 0) {
                $this->components->error('Production application and migration connections resolved to the same hostname.');

                return self::FAILURE;
            }
        }

        $this->components->info('Migration connection configuration is safe.');
        $this->line("Connection: {$connection}");
        $this->line('Driver: pgsql');
        $this->line("Host: {$directHost}");
        $this->line('Configuration cache: '.($this->laravel->configurationIsCached() ? 'loaded' : 'not loaded'));

        if ($this->option('no-connect')) {
            $this->line('Connectivity: skipped');

            return self::SUCCESS;
        }

        try {
            DB::connection($connection)->selectOne('select 1');
        } catch (Throwable) {
            $this->components->error('Could not connect through pgsql_direct. Verify the direct host and protected DB credentials.');

            return self::FAILURE;
        }

        $this->line('Connectivity: OK');

        return self::SUCCESS;
    }
}

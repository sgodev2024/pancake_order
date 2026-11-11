<?php

// CLI audit only: no HTTP, migrations, jobs, cache writes, or raw configuration output.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$options = getopt('', ['root:', 'manifest:']);
$root = realpath($options['root'] ?? dirname(__DIR__, 2));
$manifestPath = $options['manifest'] ?? __DIR__.'/release-manifest.json';
$out = ['checked_at_utc' => gmdate(DATE_ATOM), 'read_only' => true];
$connection = null;
try {
    if (! $root || ! is_file($root.'/vendor/autoload.php') || ! is_file($manifestPath)) {
        throw new RuntimeException('Missing application root or release manifest');
    }
    $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $out['candidate_commit'] = $manifest['candidate_commit'];
    putenv('GIT_OPTIONAL_LOCKS=0');
    $git = static function (string $path, array $args): ?string {
        $pipes = [];
        $process = proc_open(['git', '-C', $path, ...$args], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (! is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $text = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? trim($text) : null;
    };
    foreach (['backend' => $root, 'frontend' => $root.'/frontend'] as $label => $path) {
        if ($label === 'frontend' && ! file_exists($path.'/.git')) {
            $out['git'][$label] = ['separate_git_metadata' => false];
            continue;
        }
        $out['git'][$label] = [
            'branch' => $git($path, ['branch', '--show-current']),
            'head' => $git($path, ['rev-parse', 'HEAD']),
            'status' => $git($path, ['status', '--short']),
            'cached_origin_viet_dev' => $git($path, ['rev-parse', '--verify', 'refs/remotes/origin/viet_dev']),
            'remote_ref_may_be_stale' => true,
        ];
    }
    foreach ($manifest['critical_files'] as $path => $expected) {
        $out['critical_files_match_candidate'][$path] = is_file($root.'/'.$path)
            && hash_equals($expected, hash_file('sha256', $root.'/'.$path));
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $out['runtime'] = [
        'php' => PHP_VERSION, 'laravel' => $app->version(), 'app_env' => $app->environment(),
        'app_debug' => (bool) config('app.debug'), 'timezone' => config('app.timezone'),
        'config_cached' => $app->configurationIsCached(), 'queue_connection' => config('queue.default'),
    ];
    $out['warehouse'] = ['activated_at' => config('warehouse.activated_at')];
    $out['aicrm'] = [
        'mode' => config('aicrm.mode'), 'live_enabled' => config('aicrm.live.enabled'),
        'live_creation_enabled' => config('aicrm.live.creation_enabled'),
        'live_contract_confirmed' => config('aicrm.live.contract_confirmed'),
        'live_reconciliation_confirmed' => config('aicrm.live.reconciliation_confirmed'),
        'approved_order_configured' => filled(config('aicrm.live.approved_order_id')),
        'key_configured' => filled(config('aicrm.live.api_key')),
        'shared_provider_id' => config('aicrm.live.shipping_provider_id'),
        'sandbox_enabled' => config('aicrm.sandbox_enabled'),
        'local_enabled' => config('aicrm.local.enabled'),
        'tenant_verified_by_this_audit' => false,
    ];
    $url = parse_url((string) config('aicrm.live.base_url'));
    $out['aicrm']['live_https_expected_host'] = ($url['scheme'] ?? null) === 'https'
        && ($url['host'] ?? null) === 'api-vtp.aicrm.vn' && ($url['port'] ?? 443) === 443
        && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment']);
    $out['aicrm']['expected_host_allowlisted'] = in_array('api-vtp.aicrm.vn', config('aicrm.live.hosts', []), true);
    $out['aicrm']['credential_tenant'] = Illuminate\Support\Str::isUuid(config('aicrm.live.credential_tenant') ?? '')
        ? config('aicrm.live.credential_tenant') : null;
    $map = config('aicrm.live.shop_tenants', []);
    $out['aicrm']['shop_tenants'] = is_array($map) ? array_filter($map, static fn ($v) => is_string($v) && Illuminate\Support\Str::isUuid($v)) : [];
    foreach ((array) config('aicrm.live.tenant_credentials', []) as $tenant => $credential) {
        if (Illuminate\Support\Str::isUuid((string) $tenant) && is_array($credential)) {
            $out['aicrm']['tenant_credentials_summary'][] = [
                'tenant_id' => $tenant, 'key_configured' => filled($credential['api_key'] ?? null),
                'provider_id' => $credential['shipping_provider_id'] ?? null,
            ];
        }
    }
    $connection = Illuminate\Support\Facades\DB::connection();
    if ($connection->getDriverName() !== 'mysql') {
        throw new RuntimeException('This metadata audit requires MySQL/MariaDB');
    }
    $connection->statement('SET SESSION TRANSACTION READ ONLY');
    $connection->beginTransaction();
    $out['database'] = (array) $connection->selectOne('SELECT VERSION() AS version, DATABASE() AS db_name, @@session.time_zone AS session_timezone');
    $out['database']['table_engines'] = $connection->select("SELECT ENGINE AS engine, COUNT(*) AS tables_count FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' GROUP BY ENGINE");
    $applied = Illuminate\Support\Facades\Schema::hasTable('migrations')
        ? $connection->table('migrations')->orderBy('migration')->pluck('migration')->all() : [];
    $candidate = array_keys($manifest['migrations']);
    $out['migrations'] = [
        'applied' => $applied,
        'pending_for_candidate' => array_values(array_diff($candidate, $applied)),
        'applied_not_in_candidate' => array_values(array_diff($applied, $candidate)),
    ];
    foreach (['customers', 'orders', 'products', 'aicrm_shipments', 'aicrm_shipment_attempts'] as $table) {
        $exists = Illuminate\Support\Facades\Schema::hasTable($table);
        $out['schema'][$table] = ['exists' => $exists, 'columns' => $exists ? Illuminate\Support\Facades\Schema::getColumnListing($table) : []];
    }
    foreach (['zalo_connections', 'zalo_account_grants', 'zalo_conversation_links'] as $table) {
        $out['schema'][$table] = ['exists' => Illuminate\Support\Facades\Schema::hasTable($table)];
    }
    $out['schema']['customers_last_order_at_present'] = in_array('last_order_at', $out['schema']['customers']['columns'], true);
    $out['schema']['customers_last_order_at_index_present'] = false;
    if ($out['schema']['customers']['exists']) {
        foreach (Illuminate\Support\Facades\Schema::getIndexes('customers') as $index) {
            if ($index['columns'] === ['shop_id', 'last_order_at']) {
                $out['schema']['customers_last_order_at_index_present'] = true;
            }
        }
    }
    if (Illuminate\Support\Facades\Schema::hasTable('roles') && Illuminate\Support\Facades\Schema::hasTable('role_permissions')) {
        $roles = $connection->table('roles')->whereNull('deleted_at')
            ->whereIn('slug', ['staff-sale', 'staff-cskh', 'manager-cskh', 'admin'])->get(['id', 'slug']);
        foreach ($roles as $role) {
            $out['roles'][] = [
                'id' => $role->id, 'slug' => $role->slug,
                'permissions' => $connection->table('role_permissions AS rp')->join('permissions AS p', 'p.id', '=', 'rp.permission_id')
                    ->where('rp.role_id', $role->id)->whereNull('p.deleted_at')->orderBy('p.slug')->pluck('p.slug')->all(),
                'active_users' => $connection->table('users')->where('role_id', $role->id)->whereNull('deleted_at')->count(),
            ];
        }
    }
    $connection->rollBack();
} catch (Throwable $error) {
    if ($connection && $connection->transactionLevel() > 0) {
        $connection->rollBack();
    }
    $out['audit_error'] = ['class' => get_class($error), 'detail_omitted' => true];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit(isset($out['audit_error']) ? 1 : 0);

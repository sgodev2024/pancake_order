<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$fixture = json_decode(file_get_contents('/tmp/pancake-plan3-test-environment.json'), true);
if ($fixture['database'] !== 'pancake_warehouse_plan3_test_20261021') {
    throw new RuntimeException('Dedicated TEST database required');
}
config(['database.connections.mysql.database' => $fixture['database'], 'database.default' => 'mysql', 'warehouse.activated_at' => $fixture['activation'], 'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync', 'app.url' => 'http://127.0.0.1:8001']);
DB::purge('mysql');
DB::setDefaultConnection('mysql');
$response = $kernel->handle($request = Request::capture());
$response->send();
$kernel->terminate($request, $response);

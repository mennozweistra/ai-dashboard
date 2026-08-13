<?php

declare(strict_types=1);

// Probe the two layouts this package ships in (ticket 269): a checkout, where this file
// sits in public/ next to a sibling vendor/, and a composer global vendor install, where
// this package sits three levels below the consumer's vendor/ root.
$autoload = null;
foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php'] as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}
if ($autoload === null) {
    fwrite(STDERR, "Could not find the Composer autoloader.\n");
    exit(1);
}
require $autoload;

use AiToolset\AiDashboard\Kernel\Application;
use AiToolset\AiDashboard\Kernel\DashboardConfig;
use AiToolset\AiLib\Services\SchemaChecker;
use AiToolset\AiLib\Services\SchemaMigrator;
use Symfony\Component\HttpFoundation\Request;

$tmDb = getenv('TM_DB');
$dbPath = ($tmDb !== false && $tmDb !== '') ? $tmDb : (DashboardConfig::homeDirectory() . '/.ai-tm/store.db');

// Self-migration (ticket 269, requirement 685). This router has no boot phase —
// php -S runs it fresh on every request — so the check has to be cheap: when the
// schema is current, SchemaMigrator opens the store, reads phinxlog once and
// returns without taking a lock or writing anything. Only a store that is behind
// pays for the lock and the migration, and only the first request after an update
// finds one. A store written by a newer release throws SchemaAheadException,
// which propagates like the DashboardConfig parse error below: every request
// fails loudly with it until the user resolves the downgrade.
new SchemaMigrator($dbPath, SchemaChecker::defaultMigrationsPath())->migrate();

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Read-only, machine-local config (ticket 164, requirement 202). No
// long-lived boot phase exists in this router script — php -S runs it fresh
// on every request — so a malformed file is not caught here: the
// ParseException propagates and every request fails loudly with the parse
// error until the file is fixed.
$ideCommand = DashboardConfig::readIdeCommand(DashboardConfig::defaultPath());

$app = new Application($pdo, ideCommand: $ideCommand);
$request = Request::createFromGlobals();
$response = $app->handle($request);
$response->send();
$app->terminate($request, $response);

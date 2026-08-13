<?php

declare(strict_types=1);

// QA scratch router for ticket 164 task 1527 (AI functional review). Mirrors
// public/index.php but points PDO at a scratch, file-backed SQLite database
// instead of the real ~/.ai-tm/store.db, and constructs Application with an
// explicit $ideCommand pointing at the fake-ide test fixture instead of
// reading the machine-local ~/.ai-dashboard/config.toml, per the review's
// scope rule (never create/overwrite/delete that file). This file is not
// part of the composer.json autoload paths or any quality-gate finder path
// and is not committed.

require dirname(__DIR__) . '/vendor/autoload.php';

use AiToolset\AiDashboard\Kernel\Application;
use Symfony\Component\HttpFoundation\Request;

$dbPath = '/tmp/claude-1000/-home-menno-Development-sdr/8e885a28-7330-4a26-9d3f-fdc40c98b2db/scratchpad/ticket164/dashboard.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$ideCommand = getenv('QA_IDE_COMMAND');
$ideCommand = ($ideCommand !== false && $ideCommand !== '') ? $ideCommand : null;

$app = new Application($pdo, ideCommand: $ideCommand);
$request = Request::createFromGlobals();
$response = $app->handle($request);
$response->send();
$app->terminate($request, $response);

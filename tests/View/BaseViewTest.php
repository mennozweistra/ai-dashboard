<?php

declare(strict_types=1);

namespace AiToolset\AiDashboard\Tests\View;

use AiToolset\AiLib\Testing\InMemoryDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class BaseViewTest extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = InMemoryDatabase::create();
    }
}

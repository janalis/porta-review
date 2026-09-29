<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Tests\Support;

use PHPUnit\Framework\Attributes\After;

/** Clears what {@see FakeForge} and its tests put in the environment, so no later test inherits it. */
trait UsesFakeForge
{
    #[After]
    protected function forgetFakeForge(): void
    {
        foreach (['FAKE_FORGE_DIR', 'FAKE_FORGE_TOKEN', 'FAKE_FORGE_TOKEN_2'] as $name) {
            \putenv($name);
        }
    }
}

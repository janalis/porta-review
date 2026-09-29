<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Editor;

/**
 * How much of a conversation the editor shows on the line it hangs on — the
 * `editorDisplay` setting. Each step shows what the one before it does, and more.
 */
enum EditorDisplay: string
{
    case Off = 'off';
    case Gutter = 'gutter';
    case Summary = 'summary';
    case Inline = 'inline';

    public static function fromSetting(bool|int|string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Gutter;
    }

    /** @return list<string> the choice's values, in the order they add to each other */
    public static function values(): array
    {
        return \array_map(static fn (self $d): string => $d->value, self::cases());
    }
}

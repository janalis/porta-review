<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin;

/**
 * A decoded JSON value read by path, with the type asked for or nothing.
 *
 * A forge's answer and an agent's file are both someone else's JSON: a field
 * can be missing, null, or of the wrong type, and none of that may reach the
 * model as anything but "absent". Every read here narrows — `string()` is a
 * string or null, never a number spelled as one — so the mapping code states
 * the shape it wants instead of re-checking it field by field.
 */
final readonly class Json
{
    public function __construct(
        private mixed $value,
    ) {}

    public static function decode(string $text): self
    {
        return new self('' === \trim($text) ? null : \json_decode($text, associative: true));
    }

    /** The value at a path of keys and indexes; absent reads as null. */
    public function at(int|string ...$path): self
    {
        /** @var mixed $value */
        $value = $this->value;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return new self(null);
            }
            /** @var mixed $value */
            $value = $value[$key];
        }

        return new self($value);
    }

    public function string(): ?string
    {
        return \is_string($this->value) ? $this->value : null;
    }

    public function int(): ?int
    {
        return \is_int($this->value) ? $this->value : null;
    }

    /** True only for a JSON `true`. */
    public function isTrue(): bool
    {
        return true === $this->value;
    }

    public function isArray(): bool
    {
        return \is_array($this->value);
    }

    public function isNull(): bool
    {
        return null === $this->value;
    }

    /** @return list<self> an array's values in order; anything else is empty */
    public function list(): array
    {
        return \is_array($this->value) ? \array_values(\array_map(static fn (mixed $v): self => new self($v), $this->value)) : [];
    }

    /** @return array<array-key, mixed>|null the raw array, for a caller that hands it on whole */
    public function array(): ?array
    {
        return \is_array($this->value) ? $this->value : null;
    }
}

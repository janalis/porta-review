<?php

declare(strict_types=1);

namespace Porta\ReviewPlugin\Widget;

use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\CaretVisibility;
use Porta\Ui\CursorStyle;
use Porta\Ui\MultilineInput;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\FieldSpec;
use Porta\Widget\Render\FieldState;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Support\PanelWidth;
use Porta\Widget\Support\StylesDefault;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * The box a reply is written in, under the thread list — the commit box's
 * shape: a multi-line field and a button, Ctrl+Enter sends.
 *
 * It writes to whatever the view last pointed it at — a thread, a line, or
 * the review itself — and says which in its legend, so the reader knows where
 * the words will land before sending them. With no target it is disabled.
 */
final class ReplyBoxWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use KeybindingsTrait;
    use PaneNavigationCallbacks;
    use PanelWidth;
    use StylesDefault;

    private const int TEXT_ROWS = 4;

    private readonly MultilineInput $input;

    /** What the reply goes to, in words; '' while there is nothing to reply to. */
    private string $target = '';

    /** @var (\Closure(string): void)|null */
    private ?\Closure $onSubmit = null;

    public function __construct(
        private readonly Translator $translator,
        int $width = 44,
        ?Styles $styles = null,
        ?CursorStyle $cursorStyle = null,
    ) {
        $this->width = $width;
        $this->initStyles($styles);
        $this->input = new MultilineInput($cursorStyle);
    }

    /** @param \Closure(string): void $onSubmit the text, trimmed and never empty */
    public function onSubmit(\Closure $onSubmit): self
    {
        $this->onSubmit = $onSubmit;

        return $this;
    }

    /** Point the box at something new; the draft is kept only while the target stays the same. */
    public function target(string $label): void
    {
        if ($label !== $this->target) {
            $this->input->clear();
        }
        $this->target = $label;
        $this->invalidate();
    }

    public function targetLabel(): string
    {
        return $this->target;
    }

    public function clear(): void
    {
        $this->input->clear();
        $this->invalidate();
    }

    /**
     * Clear what was sent, once it has gone — and only that: a send answers
     * later, and a draft typed meanwhile is not the text that went.
     */
    public function clearSent(string $body): void
    {
        if (\trim($this->input->value()) === $body) {
            $this->clear();
        }
    }

    public function text(): string
    {
        return $this->input->value();
    }

    public function submit(): void
    {
        $body = \trim($this->input->value());
        if ('' !== $body && '' !== $this->target && null !== $this->onSubmit) {
            ($this->onSubmit)($body);
        }
    }

    public function handleInput(string $data): void
    {
        $kb = $this->getKeybindings();
        if ($kb->matches($data, 'exit_view')) {
            $this->fire($this->onExit);

            return;
        }
        if ($kb->matches($data, 'send')) {
            $this->submit();

            return;
        }
        if ($kb->matches($data, 'focus_next_pane')) {
            $this->fire($this->onFocusNext);

            return;
        }
        if ('' !== $this->target && $this->input->handleKey($data, $kb)) {
            $this->invalidate();
        }
    }

    public function render(RenderContext $context): array
    {
        $width = $this->panelColumns($context);
        $lines = [HeaderRenderer::title($this->translator->trans('Reply'), $width, $this->styles, suffix: 'Esc')];
        $active = '' !== $this->target;
        $typing = $active && $this->focused;
        $legend = $active ? $this->target : $this->translator->trans('Pick a thread, or press c on a line');
        $text = $this->input->render(\max(1, $width - FieldBox::CHROME_COLS), self::TEXT_ROWS, cursor: $typing ? CaretVisibility::Shown : CaretVisibility::Hidden);
        \array_push($lines, ...FieldBox::frame(
            new FieldSpec($legend),
            \array_pad(\array_slice($text, offset: 0, length: self::TEXT_ROWS), self::TEXT_ROWS, value: ''),
            $active ? FieldState::of($typing) : FieldState::Disabled,
            $width,
            $this->styles,
        ));
        $ready = $active && '' !== \trim($this->input->value());
        $lines[] = AnsiText::truncate(
            ' '.$this->styles->of($ready ? Role::Bright : Role::Faint)->apply($this->translator->trans('Send')).'  '.$this->styles->of(Role::Faint)->apply('Ctrl+Enter'),
            $width,
            pad: true,
        );

        return $lines;
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'send' => ['ctrl+enter'],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}

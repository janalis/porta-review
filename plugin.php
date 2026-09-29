<?php

declare(strict_types=1);

use Porta\Event\GitRemoteCompleted;
use Porta\Event\WorkingTreeChanged;
use Porta\GitPlugin\Process\GitProcessRunner;
use Porta\GitPlugin\Process\ProcessLane;
use Porta\Plugin\Api\AbstractPlugin;
use Porta\Plugin\Api\DisposablePlugin;
use Porta\Plugin\Api\PluginApi;
use Porta\ReviewPlugin\Editor\EditorDisplay;
use Porta\ReviewPlugin\Editor\EditorPublisher;
use Porta\ReviewPlugin\Local\LocalReviews;
use Porta\ReviewPlugin\ReviewCommands;
use Porta\ReviewPlugin\Source\Refresher;
use Porta\ReviewPlugin\Source\ReviewStore;
use Porta\ReviewPlugin\Source\Sources;
use Porta\ReviewPlugin\View\ReviewViewController;
use Porta\ReviewPlugin\Widget\ReplyBoxWidget;
use Porta\ReviewPlugin\Widget\ReviewContentWidget;
use Porta\ReviewPlugin\Widget\ReviewListWidget;
use Porta\Workbench\Menu\MenuModel;
use Porta\Workbench\Menu\MenuSurface;
use Porta\Workbench\View\ViewServices;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Code review conversations: a GitHub pull request's or a GitLab merge
 * request's threads, or a local review an agent wrote into `.porta/reviews/`,
 * read and answered without leaving the IDE — in the review view, in its diff,
 * and on the lines of the editor.
 *
 * Requires the git plugin for its classes (the process lane, the diff model),
 * never for its live objects: this plugin builds its own git runner.
 */
return new class('review') extends AbstractPlugin implements DisposablePlugin {
    private ?Refresher $refresher = null;
    private ?ProcessLane $lane = null;

    public function activate(PluginApi $api): void
    {
        $api->registerSetting(
            'provider',
            'Where reviews come from',
            'choice',
            'auto',
            ['auto', 'github', 'gitlab', 'local'],
            hint: 'auto: a local review when one is on disk, else the branch remote\'s forge',
        );
        $api->registerSetting(
            'editorDisplay',
            'Conversations in the editor',
            'choice',
            EditorDisplay::Gutter->value,
            EditorDisplay::values(),
            hint: 'gutter: a ✎ mark · summary: and the first comment after the line · inline: and the whole thread under it',
        );
        $api->registerSetting('showResolved', 'Show resolved threads', 'bool', false);
        $api->registerSetting('refreshSeconds', 'Re-read a forge review every (seconds, 0 = never)', 'int', 0, min: 0, step: 30);
        $api->registerSetting('hosts', 'Self-hosted forges', 'text', '', hint: 'host=github or host=gitlab, comma-separated');
        $api->registerSetting('ghBinary', 'gh path', 'text', '', hint: 'empty: found on PATH');
        $api->registerSetting('glabBinary', 'glab path', 'text', '', hint: 'empty: found on PATH');

        $root = $api->projectRoot();
        $lane = new ProcessLane();
        $this->lane = $lane;
        $git = new GitProcessRunner($root, lane: $lane);
        $local = new LocalReviews($root);
        $author = get_current_user();
        $git->run(['config', 'user.name'], static function (string $name) use (&$author): void {
            $author = '' === trim($name) ? $author : trim($name);
        });
        $finder = new ExecutableFinder();
        $sources = new Sources(
            $root,
            $git,
            $lane,
            $local,
            $api->setting(...),
            static function () use (&$author): string {
                return $author;
            },
            $finder->find(...),
        );
        $store = new ReviewStore($sources->resolve(...));
        $display = static fn (): EditorDisplay => EditorDisplay::fromSetting($api->setting('editorDisplay'));
        $showResolved = static fn (): bool => (bool) $api->setting('showResolved');
        $publisher = new EditorPublisher($api, $store, $git, $display, $showResolved);
        $refresher = new Refresher($store, $local, $git, static fn (): int => (int) $api->setting('refreshSeconds'));
        $this->refresher = $refresher;

        /** @var ReviewViewController|null $view */
        $view = null;
        $commands = new ReviewCommands($api, $store, $publisher, $sources, $local, static function () use (&$view): ?ReviewViewController {
            return $view;
        });

        $store->onChange(static function () use ($store, $publisher, $api): void {
            if (!$store->loading) {
                $publisher->publish();
            }
            $api->refreshStatusSegments();
        });
        $api->onBufferSwitched($publisher->publish(...));
        $api->onFileSaved($publisher->publish(...));
        $api->onFileReloaded($publisher->publish(...));
        $api->on(WorkingTreeChanged::class, $refresher->branchMayHaveMoved(...));
        $api->on(GitRemoteCompleted::class, $store->refresh(...));
        $api->onSettingChanged(static function (string $key) use ($store, $publisher, $refresher): void {
            match ($key) {
                'editorDisplay' => $publisher->publish(),
                'refreshSeconds' => $refresher->rearm(),
                // What is shown, not what the forge says: no re-read.
                'showResolved' => $publisher->publish(),
                default => $store->refresh(),
            };
        });
        $api->onReady(static function () use ($store, $refresher): void {
            $refresher->start();
            $store->refresh();
        });
        $api->onShutdown($this->deactivate(...));

        $api->registerView(ReviewViewController::ID, static function (ViewServices $services) use ($api, $store, $git, $showResolved, &$view): ReviewViewController {
            $width = 48;
            $list = new ReviewListWidget($services->translator, $width, $services->styles);
            $list->setId('review-threads');
            $reply = new ReplyBoxWidget($services->translator, $width, $services->styles, $services->cursorStyle, $services->density);
            $reply->setId('review-reply');
            $content = new ReviewContentWidget($services->translator, $services->styles, $services->density);
            $content->setId('review-diff');

            return $view = new ReviewViewController($services, $api, $store, $git, $list, $reply, $content, $showResolved);
        });

        $api->registerCommand('open', 'Show Review', static fn () => $api->toggleView(ReviewViewController::ID));
        $api->registerCommand('refresh', 'Refresh Review', $store->refresh(...));
        $api->registerCommand('choose', 'Choose Review…', $commands->choose(...));
        $api->registerCommand('copy_link', 'Copy Review Link', $commands->copyLink(...));
        $api->registerCommand('show_conversation', 'Show Conversation on This Line', $commands->showConversationAtCaret(...));
        $api->registerCommand('comment_line', 'Comment on This Line', $commands->commentAtCaret(...));
        $api->addMenuItem('show_conversation', static fn (): bool => null !== $store->review, MenuSurface::EditorContext);
        $api->addMenuItem('comment_line', static fn (): bool => null !== $store->review, MenuSurface::EditorContext);
        $api->registerMenu(
            'Review',
            'Run',
            ['i:review:open', MenuModel::SEP, 'i:review:refresh', 'i:review:choose', 'i:review:copy_link'],
            viewId: ReviewViewController::ID,
            viewSafe: ['ide_quit', 'review:open', 'review:refresh', 'review:choose', 'review:copy_link', 'ide_command_palette', 'ide_help'],
        );
        $api->registerStatusSegment($commands->statusSegment(...));
    }

    public function deactivate(): void
    {
        $this->refresher?->stop();
        $this->lane?->cancelAll();
    }
};

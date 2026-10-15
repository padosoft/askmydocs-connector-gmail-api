<?php

// Filter semantics adapted from Padosoft AskMyDocsConnectorImap, Apache-2.0.
declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail\Mail;

final class MessageFilter
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config) {}

    public function passes(Message $m): bool
    {
        $labels = $m->labels();
        $c = $this->config;
        $exclude = (array) ($c['folders']['exclude'] ?? []);
        if (! ($c['folders']['include_spam'] ?? false)) {
            $exclude[] = 'SPAM';
        }
        if (! ($c['folders']['include_trash'] ?? false)) {
            $exclude[] = 'TRASH';
        }
        if (array_intersect($labels, $exclude) !== []) {
            return false;
        }
        $include = (array) ($c['folders']['include'] ?? []);
        if ($include !== [] && array_intersect($labels, $include) === []) {
            return false;
        }
        $days = (int) ($c['sync_window_days'] ?? 0);
        if ($days > 0 && ($m->date() === null || strtotime($m->date()) < now()->subDays($days)->getTimestamp())) {
            return false;
        }
        $f = $c['filters'] ?? [];
        if (($f['only_unseen'] ?? false) && ! in_array('UNREAD', $labels, true)) {
            return false;
        }
        if (($f['only_flagged'] ?? false) && ! in_array('STARRED', $labels, true)) {
            return false;
        }
        if (($f['skip_auto_generated'] ?? true) && (in_array(strtolower($m->headers['precedence'] ?? ''), ['bulk', 'list', 'junk'], true) || (isset($m->headers['auto-submitted']) && strtolower($m->headers['auto-submitted']) !== 'no') || isset($m->headers['list-unsubscribe']))) {
            return false;
        }
        if (! $this->addresses($m->addresses('from'), $f['senders'] ?? []) || ! $this->addresses([...$m->addresses('to'), ...$m->addresses('cc'), ...$m->addresses('bcc')], $f['recipients'] ?? [])) {
            return false;
        }
        $subject = mb_strtolower($m->subject());
        foreach ($f['subject']['exclude_keywords'] ?? [] as $word) {
            if ($word !== '' && str_contains($subject, mb_strtolower($word))) {
                return false;
            }
        }
        $words = $f['subject']['include_keywords'] ?? [];
        if ($words !== []) {
            foreach ($words as $word) {
                if ($word !== '' && str_contains($subject, mb_strtolower($word))) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /** @param list<string> $addresses @param array<string,mixed> $rule */
    private function addresses(array $addresses, array $rule): bool
    {
        if ($addresses === [] && (($rule['include'] ?? []) !== [] || ($rule['exclude'] ?? []) !== [])) {
            return false;
        }
        $matches = static function (array $needles) use ($addresses): bool {
            foreach ($addresses as $address) {
                foreach ($needles as $needle) {
                    $needle = strtolower($needle);
                    if ($address === $needle || str_ends_with($address, '@'.ltrim($needle, '@'))) {
                        return true;
                    }
                }
            }

            return false;
        };

        return ! $matches($rule['exclude'] ?? []) && (($rule['include'] ?? []) === [] || $matches($rule['include']));
    }
}

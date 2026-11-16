<?php

namespace Base\Video\Service;

use Base\Database\Type\Utc;
use Base\Entity\User;
use Base\Video\Entity\Video;
use Base\Video\Entity\WatchEvent;
use Base\Video\Message\SuggestFeedback;
use Base\Video\Repository\WatchEventRepository;
use Base\Video\Repository\VideoRepository;
use Base\Contract\UnitOfWork;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The player's beacons, and the rule that makes a sitting a view: once
 * min(30 s, half the film) has really been played (video.views.min_seconds,
 * .ratio), and only once per visitor and film every six hours
 * (video.views.dedupe) - the visitor being the account, or a hash of the
 * address and the browser that never leaves this service. Counted views are
 * added to Video::$views by flush() (video:views:flush, every few minutes
 * from cron), not one UPDATE per beacon.
 */
class Views
{
    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly WatchEventRepository $events,
        private readonly VideoRepository $videos,
        #[Autowire(service: 'video.cache')] private readonly CacheInterface $cache,
        private readonly MessageBusInterface $bus,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%video.views.min_seconds%')] private readonly int $minSeconds = 30,
        #[Autowire('%video.views.ratio%')] private readonly float $ratio = 0.5,
        #[Autowire('%video.views.dedupe%')] private readonly int $dedupe = 21600,
        #[Autowire('%video.suggest.provider%')] private readonly string $provider = 'local',
        #[Autowire('%video.suggest.gorse_url%')] private readonly string $gorseUrl = '',
    ) {
    }

    /** Seconds of playing that make a view of this film. */
    public function threshold(?int $duration): float
    {
        return $duration && $duration > 0 ? min((float) $this->minSeconds, $this->ratio * $duration) : (float) $this->minSeconds;
    }

    public function visitor(Request $request, ?User $user = null): string
    {
        return $user?->getId()
            ? 'u'.$user->getId()
            : hash_hmac('sha256', ($request->getClientIp() ?? '').'|'.$request->headers->get('User-Agent', ''), $this->secret.'|video-visitor');
    }

    /**
     * A beacon: {token, event: start|progress|end, position, watched, source, referrer}.
     * Returns the sitting, or null for a beacon that cannot be one.
     *
     * @param array<string, mixed> $beacon
     */
    public function record(Video $video, array $beacon, string $visitor, ?User $user = null, ?\DateTimeInterface $now = null): ?WatchEvent
    {
        $token = (string) ($beacon['token'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $token)) {
            return null;
        }
        $event = $this->events->findOneByToken($video, $token);
        if (!$event) {
            $event = new WatchEvent($video, $token, $visitor, $user, (string) ($beacon['source'] ?? 'direct'), self::referrer($beacon['referrer'] ?? null));
            $this->unitOfWork->persist($event);
            $this->feedback('read', $user, $video);
        } elseif ($event->getVisitor() !== $visitor) {
            // Somebody else's sitting: not theirs to move.
            return null;
        }

        $event->progress((float) ($beacon['position'] ?? 0), (float) ($beacon['watched'] ?? 0), $now);
        if ('end' === ($beacon['event'] ?? null)) {
            $event->complete();
        }
        if (!$event->isCounted() && $event->getWatched() >= $this->threshold($video->getDuration())) {
            $key = 'video.seen.'.hash('xxh128', $visitor.'|'.$video->getId());
            $fresh = false;
            $this->cache->get($key, function (ItemInterface $item) use (&$fresh) {
                $item->expiresAfter($this->dedupe);
                $fresh = true;

                return time();
            });
            if ($fresh) {
                $event->count();
                $this->feedback('watch', $user, $video);
            }
        }
        $this->unitOfWork->flush();

        return $event;
    }

    /** The views counted since the last flush, added to each Video::$views. Returns how many. */
    public function flush(): int
    {
        $counts = $this->events->countUnflushed();
        if (!$counts) {
            return 0;
        }
        $this->unitOfWork->transactional(function () use ($counts): void {
            $this->videos->addViews($counts);
            $this->events->markFlushed(array_keys($counts));
        });
        // The entities read before keep their old count: forget them, in the second-level cache too.
        $this->videos->forgetViews(array_keys($counts));

        return array_sum($counts);
    }

    public function feedback(string $type, ?User $user, Video $video): void
    {
        if ('gorse' !== $this->provider || '' === $this->gorseUrl || !$user?->getId() || !$video->getId()) {
            return;
        }
        try {
            $this->bus->dispatch(new SuggestFeedback($type, (string) $user->getId(), $video->getId(), Utc::now()));
        } catch (\Throwable) {
            // The suggestions lose a gesture; the page does not fail for it.
        }
    }

    /** Only the other site's host, never its full address. */
    private static function referrer(mixed $referrer): ?string
    {
        $host = \is_string($referrer) ? parse_url($referrer, \PHP_URL_HOST) : null;

        return \is_string($host) && '' !== $host ? strtolower($host) : null;
    }
}

<?php

namespace Base\Video\Repository;

use Base\Database\Backend\EntityCache;
use Base\Database\Attribute\Backend\Backends;
use Base\Database\Repository\Finder\Change;
use Base\Entity\ThreadIntl;
use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Aggregate;
use Base\Contract\Runtime;
use Base\Database\Repository\Finder\Key;
use Base\Database\Repository\Finder\Group;
use Base\Entity\User;
use Base\Enum\ThreadState;
use Base\Video\Entity\Channel;
use Base\Video\Entity\Video;
use Base\Video\Entity\WatchEvent;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Type\Utc;

/**
 * @method Video|null find($id, $lockMode = null, $lockVersion = null)
 * @method Video|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method Video[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class VideoRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders, string $class = Video::class)
    {
        parent::__construct($finders, $class);
    }

    /**
     * Listed: public, published, not taken down, playable - as finder criteria. Published: no date, or one at or
     * before the application's clock, in UTC (one time for every engine: no database clock).
     *
     * @return array<int|string, mixed>
     */
    public static function listedCriteria(): array
    {
        return [
            'state' => ThreadState::PUBLISH,
            Group::by('PublishedAtNullOrPublishedAtLowerEqualTo', Utc::from(Runtime::clock()->now())),
            Group::by('TakenDownAtNull'),
            'processing' => [Video::PROCESSING_READY, Video::PROCESSING_NONE],
        ];
    }

    /** @return list<Video> the newest first */
    public function findLatest(int $limit = 24, int $offset = 0, ?Channel $channel = null): array
    {
        return $this->findBy([...self::listedCriteria(), ...($channel ? ['channel' => Key::of($channel)] : [])], ['publishedAt' => 'DESC', 'id' => 'DESC'], $limit, $offset);
    }

    /** @return list<Video> the most seen of all time (ours and the imported views). */
    public function findPopular(int $limit = 24, ?Channel $channel = null): array
    {
        // The order is an expression (our views and the imported ones): one SQL body, on the listed films' rows; the
        // films then read by the finder in that order (findListedByIds() checks they are listed, the scopes applied).
        $sql = 'SELECT v.{Video.id} AS id FROM {Video} v'
            .' WHERE v.{Video.state} = ? AND (v.{Video.publishedAt} IS NULL OR v.{Video.publishedAt} <= ?) AND v.{Video.takenDownAt} IS NULL AND v.{Video.processing} IN (?, ?)'
            .($channel ? ' AND v.{Video.channel} = ?' : '')
            .' ORDER BY v.{Video.views} + COALESCE(v.{Video.legacyViews}, 0) DESC, v.{Video.publishedAt} DESC, v.{Video.id} DESC LIMIT '.max(0, $limit);
        $parameters = [ThreadState::PUBLISH, Utc::from(Runtime::clock()->now()), Video::PROCESSING_READY, Video::PROCESSING_NONE, ...($channel ? [$channel->getId()] : [])];

        return $this->findListedByIds(array_map(static fn (array $row): int => (int) $row['id'], (array) $this->sql($sql, $parameters)));
    }

    /**
     * @return list<Video> the most watched of the last $days days (counted
     *                     sittings), the most seen of all time to fill up
     */
    public function findTrending(int $limit = 24, int $days = 7): array
    {
        $rows = $this->trendingCounts(Utc::from(Runtime::clock()->now())->modify(sprintf('-%d days', $days)), $limit * 2);
        $videos = $this->findListedByIds(array_map('intval', array_column($rows, 'id')));
        if (\count($videos) < $limit) {
            foreach ($this->findPopular($limit * 2) as $video) {
                if (!\in_array($video, $videos, true)) {
                    $videos[] = $video;
                }
            }
        }

        return \array_slice($videos, 0, $limit);
    }

    /** @return list<array{id: int|string, n: int|string}> the sittings counted per film since that moment, the most first */
    public function trendingCounts(\DateTimeInterface $since, int $limit): array
    {
        return (new WatchEventRepository($this->finders(), $this))->findValuesBy(
            ['id' => 'video', 'n' => Aggregate::count()],
            ['counted' => true, Group::of(Parser::SEPARATOR_AND, Criterion::of('startedAt', $since, Parser::OPTION_GREATER_EQUAL))],
            ['n' => 'DESC'], $limit,
        );
    }

    /** The video at that address, if its visitor may see it (listed, unlisted, or theirs). */
    public function findOneReachable(string $slug, ?User $viewer = null, bool $staff = false): ?Video
    {
        $video = $this->findOneBy(['slug' => $slug]);
        if (!$video instanceof Video) {
            return null;
        }

        return $video->isReachable() || $staff || $video->isOwnedBy($viewer) ? $video : null;
    }

    public function findOneByUploadId(string $uploadId): ?Video
    {
        return $this->findOneBy(['uploadId' => $uploadId]);
    }

    /** @return list<Video> a member's own, in the studio (every state but the trash). */
    public function findForStudio(User $user, ?string $visibility = null): array
    {
        // Hers, or her channels': two finds merged (an OR over two collections would repeat a film per join row).
        $state = null === $visibility ? [] : ['state' => match ($visibility) {
            Video::VISIBILITY_PUBLIC => ThreadState::PUBLISH,
            Video::VISIBILITY_UNLISTED => ThreadState::SECRET,
            Video::VISIBILITY_SCHEDULED => ThreadState::FUTURE,
            default => ThreadState::DRAFT,
        }];
        $films = [];
        foreach ([...$this->findBy(['owners' => $user] + $state), ...$this->findBy(['channel.owners.id' => $user->getId()] + $state)] as $film) {
            $films[(int) $film->getId()] = $film;
        }
        usort($films, static fn (Video $a, Video $b): int => [$b->getCreatedAt(), $b->getId()] <=> [$a->getCreatedAt(), $a->getId()]);

        return $films;
    }

    /** Bytes of originals a member has sent: what the quota counts. */
    public function sumUploads(User $user): int
    {
        // The database's SUM() over the member's films (the owners joined, one row each), null without one.
        return (int) ($this->sumOfUploadSizeBy(['owners' => $user]) ?? 0);
    }

    /** @return list<Video> the new videos of the channels a member follows. */
    public function findFromSubscriptions(User $user, int $limit = 48): array
    {
        $channels = array_column((new SubscriptionRepository($this->finders()))->findValuesBy(['channel'], ['user' => Key::of($user)]), 'channel');

        return $channels ? $this->findBy([...self::listedCriteria(), 'channel' => Key::of(array_map('intval', $channels))], ['publishedAt' => 'DESC', 'id' => 'DESC'], $limit) : [];
    }

    /**
     * The database's search, when Typesense is not there: every word in the
     * title, the description, the channel's name.
     *
     * @param array{category?: ?string, channel?: ?string, duration?: ?string, date?: ?string} $filters
     *
     * @return list<Video>
     */
    public function searchListed(string $query, array $filters = [], int $limit = 24, int $offset = 0): array
    {
        // Each word in a translation's title or content, or in the channel's name: the films of each word read by the
        // language (no join of the translations here: a film is listed once, the pages stay right), kept when every
        // word found them; then the listed ones among them, filtered and paged.
        $words = \array_slice(array_values(array_filter(preg_split('/\s+/u', trim($query)) ?: [], static fn ($word) => mb_strlen($word) > 1)), 0, 8);
        $translations = new ServiceEntityRepository($this->finders(), ThreadIntl::class);
        $ids = null;
        foreach ($words as $word) {
            $like = '%'.addcslashes($word, '%_').'%';
            $found = array_map('intval', array_column($translations->findValuesBy(['id' => 'translatable'], [Group::of(Parser::SEPARATOR_OR,
                Criterion::of('title', $like, Parser::OPTION_PARTIAL), Criterion::of('content', $like, Parser::OPTION_PARTIAL))]), 'id'));
            $found = [...$found, ...array_map('intval', array_column($this->findValuesBy(['id'], [Group::of(Parser::SEPARATOR_AND, Criterion::of('channel.name', $like, Parser::OPTION_PARTIAL))]), 'id'))];
            $ids = null === $ids ? array_unique($found) : array_intersect($ids, $found);
        }
        $criteria = self::listedCriteria();
        if (null !== $ids) {
            $criteria['id'] = array_values($ids);
        }
        if (!empty($filters['category'])) {
            $criteria['category'] = $filters['category'];
        }
        if (!empty($filters['channel'])) {
            $criteria['channel.slug'] = $filters['channel'];
        }
        [$min, $max] = self::durationBounds($filters['duration'] ?? null);
        if (null !== $min) {
            $criteria[] = Group::of(Parser::SEPARATOR_AND, Criterion::of('duration', $min, Parser::OPTION_GREATER_EQUAL));
        }
        if (null !== $max) {
            $criteria[] = Group::of(Parser::SEPARATOR_AND, Criterion::of('duration', $max, Parser::OPTION_LOWER));
        }
        if ($since = self::dateSince($filters['date'] ?? null)) {
            $criteria[] = Group::of(Parser::SEPARATOR_AND, Criterion::of('publishedAt', $since, Parser::OPTION_GREATER_EQUAL));
        }

        return $this->findBy($criteria, ['publishedAt' => 'DESC', 'id' => 'DESC'], $limit, $offset);
    }

    /** short: under 4 minutes; medium: 4 to 20; long: over 20. @return array{0: ?int, 1: ?int} */
    public static function durationBounds(?string $duration): array
    {
        return match ($duration) {
            'short' => [null, 240],
            'medium' => [240, 1200],
            'long' => [1200, null],
            default => [null, null],
        };
    }

    /** The moment a search's date filter starts from, counted back from the application's clock, in UTC. */
    public static function dateSince(?string $date): ?\DateTime
    {
        $back = match ($date) {
            'day' => '-1 day',
            'week' => '-7 days',
            'month' => '-1 month',
            'year' => '-1 year',
            default => null,
        };

        return null === $back ? null : \DateTime::createFromImmutable(Utc::from(Runtime::clock()->now())->modify($back));
    }

    /**
     * Views added to the films' counts in the database itself (Base\Video\Service\Views::flush(), inside its
     * transaction): two flushes at once add both.
     *
     * @param array<int, int> $counts video id => views to add
     */
    public function addViews(array $counts): void
    {
        // views = views + n in the database itself (Views::flush(), inside its transaction).
        foreach ($counts as $id => $n) {
            $this->updateBy(['id' => $id], ['views' => Change::add($n)]);
        }
    }

    /**
     * The films whose views were added in the database forgotten by the ORM: evicted from the second-level cache,
     * read again where the identity map holds them.
     *
     * @param list<int> $ids
     */
    public function forgetViews(array $ids): void
    {
        // Their views were added in the database: the cache forgets them, a film already read is read again.
        $cache = Backends::of($this->unitOfWork(), EntityCache::class);
        foreach ($ids as $id) {
            if ($cache->enabled()) {
                $cache->evict(Video::class, $id);
            }
            if (null !== $video = $this->find($id)) {
                $this->unitOfWork()->refresh($video);
            }
        }
    }

    /** @return array<string, int> processing state => videos, for the back office. */
    public function countByProcessing(): array
    {
        // One COUNT per processing state (five): the back office's figures, without a GROUP BY.
        $counts = [];
        foreach ([Video::PROCESSING_NONE, Video::PROCESSING_UPLOADED, Video::PROCESSING_RUNNING, Video::PROCESSING_READY, Video::PROCESSING_FAILED] as $state) {
            if (0 < $count = $this->count(['processing' => $state])) {
                $counts[$state] = $count;
            }
        }

        return $counts;
    }

    /** @return list<Video> */
    public function findProcessing(int $limit = 10): array
    {
        return $this->findBy(['processing' => [Video::PROCESSING_UPLOADED, Video::PROCESSING_RUNNING, Video::PROCESSING_FAILED]], ['updatedAt' => 'DESC'], $limit);
    }

    /** @return list<Video> listed, by id: what the suggestions compute over */
    public function findAllListed(int $limit = 5000): array
    {
        // The channel was fetch-joined: it loads lazily now, the films the same.
        return $this->findBy(self::listedCriteria(), ['id' => 'ASC'], $limit);
    }

    /** @param list<int> $ids @return list<Video> listed, in the order of $ids */
    public function findListedByIds(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $byId = [];
        foreach ($this->findBy([...self::listedCriteria(), 'id' => $ids]) as $video) {
            $byId[$video->getId()] = $video;
        }

        return array_values(array_filter(array_map(fn ($id) => $byId[$id] ?? null, $ids)));
    }
}

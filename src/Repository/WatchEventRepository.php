<?php

namespace Base\Video\Repository;

use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Aggregate;
use Base\Contract\Runtime;
use Base\Database\Repository\Finder\Key;
use Base\Entity\User;
use Base\Video\Entity\Video;
use Base\Video\Entity\WatchEvent;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;
use Base\Database\Type\Utc;

/**
 * @method WatchEvent|null find($id, $lockMode = null, $lockVersion = null)
 * @method WatchEvent|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method WatchEvent[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class WatchEventRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders, private readonly VideoRepository $videos)
    {
        parent::__construct($finders, WatchEvent::class);
    }

    public function findOneByToken(Video $video, string $token): ?WatchEvent
    {
        return $this->findOneBy(['video' => Key::of($video), 'token' => $token]);
    }

    /**
     * A member's history: each video once, the last watched first.
     *
     * @return list<array{video: Video, position: float, at: \DateTimeInterface}>
     */
    public function findHistory(User $user, int $limit = 60): array
    {
        $rows = $this->historyRows($user, $limit);
        if (!$rows) {
            return [];
        }
        $videos = [];
        foreach ($this->videos->findBy(['id' => array_column($rows, 'id')]) as $video) {
            $videos[$video->getId()] = $video;
        }
        $history = [];
        foreach ($rows as $row) {
            if (isset($videos[$row['id']]) && $videos[$row['id']]->isReachable()) {
                $history[] = ['video' => $videos[$row['id']], 'position' => (float) $row['position'], 'at' => $row['at'] instanceof \DateTimeInterface ? \DateTime::createFromInterface($row['at']) : new \DateTime((string) $row['at'], Utc::zone())];
            }
        }

        return $history;
    }

    /**
     * A member's sittings grouped by film: the film's id, the last moment and the furthest position.
     *
     * @return list<array{id: int|string, at: string|\DateTimeInterface, position: float|string}>
     */
    public function historyRows(User $user, int $limit): array
    {
        return $this->findValuesBy(['id' => 'video', 'at' => Aggregate::max('updatedAt'), 'position' => Aggregate::max('position')], ['user' => Key::of($user)], ['at' => 'DESC'], $limit);
    }

    /** @return list<int> the videos a member (or a visitor) watched, the last first. */
    public function findWatchedIds(?User $user, ?string $visitor = null, int $limit = 200): array
    {
        if (!$user && !$visitor) {
            return [];
        }

        return array_map(static fn (array $row): int => (int) $row['id'], $this->findValuesBy(['id' => 'video', 'at' => Aggregate::max('updatedAt')], $user ? ['user' => Key::of($user)] : ['visitor' => $visitor], ['at' => 'DESC'], $limit));
    }

    /** Where a member stopped last time, in seconds (null: never, or near the end). */
    public function findResume(User $user, Video $video): ?float
    {
        $last = $this->findOneBy(['user' => Key::of($user), 'video' => Key::of($video)], ['updatedAt' => 'DESC']);
        if (!$last || $last->isCompleted() || $last->getPosition() < 5) {
            return null;
        }

        return $last->getPosition();
    }

    public function clearHistory(User $user): int
    {
        return $this->updateBy(['user' => Key::of($user)], ['user' => null]);
    }

    public function removeFromHistory(User $user, Video $video): int
    {
        return $this->updateBy(['user' => Key::of($user), 'video' => Key::of($video)], ['user' => null]);
    }

    /** @return array<int, int> video id => views counted and not yet added to Video::$views. */
    public function countUnflushed(int $limit = 5000): array
    {
        $counts = [];
        foreach ($this->findValuesBy(['id' => 'video', 'n' => Aggregate::count()], ['counted' => true, 'flushed' => false], null, $limit) as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        return $counts;
    }

    /** @param list<int> $videoIds */
    public function markFlushed(array $videoIds): int
    {
        return $videoIds ? $this->updateBy(['counted' => true, 'flushed' => false, 'video' => Key::of($videoIds)], ['flushed' => true]) : 0;
    }

    /**
     * Who watched what, for the suggestions' co-watching: viewer key (the
     * account, else the visitor) => the videos they watched enough to count.
     *
     * @return array<string, list<int>>
     */
    public function findCoWatching(int $days = 90, int $limit = 200000): array
    {
        $viewers = [];
        foreach ($this->findValuesBy(['u' => 'user', 'visitor', 'v' => 'video'], ['counted' => true, Group::of(Parser::SEPARATOR_AND, Criterion::of('startedAt', Utc::from(Runtime::clock()->now())->modify(sprintf('-%d days', $days)), Parser::OPTION_GREATER_EQUAL))], null, $limit) as $row) {
            $key = $row['u'] ? 'u'.$row['u'] : 'v'.$row['visitor'];
            $viewers[$key][(int) $row['v']] = true;
        }

        return array_map(static fn (array $videos) => array_keys($videos), $viewers);
    }

    /**
     * The studio's figures for one film.
     *
     * @return array{views: int, sittings: int, average: float, completion: float, retention: list<float>, sources: array<string, int>, days: array<string, int>}
     */
    public function stats(Video $video, int $days = 30, int $buckets = 20): array
    {
        return self::figures($this->statsRows($video), (int) $video->getDuration(), $days, $buckets);
    }

    /** @return list<array{position: float, watched: float, counted: bool, source: string, at: \DateTimeInterface, completed: bool}> every sitting of a film */
    public function statsRows(Video $video): array
    {
        return $this->findValuesBy(['position', 'watched', 'counted', 'source', 'at' => 'startedAt', 'completed'], ['video' => Key::of($video)]);
    }

    /**
     * The figures from raw sittings - separate, so they are tested without a
     * database. The retention curve: for each twentieth of the film, the
     * share of the sittings that reached it.
     *
     * @param list<array{position: float, watched: float, counted: bool, source: string, at: \DateTimeInterface, completed?: bool}> $rows
     *
     * @return array{views: int, sittings: int, average: float, completion: float, retention: list<float>, sources: array<string, int>, days: array<string, int>}
     */
    public static function figures(array $rows, int $duration, int $days = 30, int $buckets = 20, ?\DateTimeInterface $today = null): array
    {
        $today = \DateTimeImmutable::createFromInterface($today ?? Utc::from(Runtime::clock()->now()))->setTime(0, 0);
        $daily = [];
        for ($i = $days - 1; $i >= 0; --$i) {
            $daily[$today->modify("-$i days")->format('Y-m-d')] = 0;
        }
        $sittings = \count($rows);
        $views = 0;
        $watched = 0.0;
        $sources = [];
        $reached = array_fill(0, $buckets, 0);
        $completed = 0;
        foreach ($rows as $row) {
            $watched += (float) $row['watched'];
            if ($row['counted']) {
                ++$views;
                $sources[$row['source']] = ($sources[$row['source']] ?? 0) + 1;
                $day = $row['at'] instanceof \DateTimeInterface ? $row['at']->format('Y-m-d') : substr((string) $row['at'], 0, 10);
                if (isset($daily[$day])) {
                    ++$daily[$day];
                }
            }
            if (!empty($row['completed']) || ($duration > 0 && (float) $row['position'] >= $duration - 1)) {
                ++$completed;
            }
            if ($duration > 0) {
                $fraction = min(1.0, (float) $row['position'] / $duration);
                for ($b = 0; $b < $buckets; ++$b) {
                    if ($fraction >= $b / $buckets) {
                        ++$reached[$b];
                    }
                }
            }
        }
        arsort($sources);

        return [
            'views' => $views,
            'sittings' => $sittings,
            'average' => $sittings ? round($watched / $sittings, 1) : 0.0,
            'completion' => $sittings ? round($completed / $sittings, 3) : 0.0,
            'retention' => array_map(fn ($n) => $sittings ? round($n / $sittings, 3) : 0.0, $reached),
            'sources' => $sources,
            'days' => $daily,
        ];
    }

    /** Counted views over the last $days days, all videos: the dashboard's figure. */
    public function countViewsSince(int $days = 30): int
    {
        return (int) $this->countByCountedTrueAndStartedAtGreaterEqualTo(Utc::from(Runtime::clock()->now())->modify(sprintf('-%d days', $days)));
    }
}

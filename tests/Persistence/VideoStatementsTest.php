<?php

namespace Base\Video\Tests\Persistence;

use Base\Entity\Thread;
use Base\Entity\Thread\Comment;
use Base\Entity\User;
use Base\Enum\ThreadState;
use Base\Enum\WorkflowState;
use Base\Video\Entity\Channel;
use Base\Video\Entity\Playlist;
use Base\Video\Entity\PlaylistItem;
use Base\Video\Entity\Related;
use Base\Video\Entity\Subscription;
use Base\Video\Entity\Video;
use Base\Video\Entity\VideoComment;
use Base\Video\Entity\WatchEvent;
use Base\Video\Repository\PlaylistRepository;
use Base\Video\Repository\RelatedRepository;
use Base\Video\Repository\VideoCommentRepository;
use Base\Video\Repository\VideoRepository;
use Base\Video\Repository\WatchEventRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use Base\Testing\Engines;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Base\Contract\Runtime;

/** The video platform's statements give the same rows on Doctrine and on Eloquent, on a fixed application clock in UTC. */
final class VideoStatementsTest extends TestCase
{
    private static Engines $engines;

    /** @return array<string, array{0: string}> */
    public static function backends(): array
    {
        return Engines::backends();
    }

    private const ALICE = 7;
    private const BOB = 8;

    /** @var array<string, int> */
    private static array $ids = [];

    /** The application's clock of the case (Runtime::clock()): 2026-06-01 12:00 UTC unless the case moves it. */
    private MockClock $clock;

    private string $zone;

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        Runtime::set(ClockInterface::class, $this->clock = new MockClock('2026-06-01 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Runtime::set(ClockInterface::class, null);
        date_default_timezone_set($this->zone);
    }

    public static function setUpBeforeClass(): void
    {
        self::$engines = Engines::of([Thread::class, Video::class, Channel::class, Subscription::class, Playlist::class, PlaylistItem::class, Related::class, WatchEvent::class, Comment::class, VideoComment::class]);
        // The accounts the rows point at (Doctrine joins their table when it compares a collection's member).
        // App\Entity\User, what the associations name: the core's account through the alias, a host's own (the omnibase
        // harness's: a table of its own under the core's) with its row there too.
        $account = (new \ReflectionClass('App\\Entity\\User'))->getName();
        $own = self::$engines->description()->entity($account);
        foreach ([self::ALICE => 'alice', self::BOB => 'bob'] as $id => $name) {
            self::$engines->insert('user', ['id' => $id, 'emailAuthEnabled' => 0, 'trustedTokenVersion' => 0, 'email' => $name.'@example.org', 'roles' => '', 'states' => '',
                'createdAt' => '2026-01-01 10:00:00', 'updatedAt' => '2026-01-01 10:00:00', 'class' => self::$engines->discriminator($account)]);
            if (null !== $own && null !== $own->table && 'user' !== $own->table) {
                $row = ['id' => $id];
                foreach ($own->fields as $field) {
                    if (!$field->id && !$field->nullable && (null === $field->inheritedFrom || $field->inheritedFrom === $own->class)) {
                        $row[$field->column] = str_contains($field->type, 'int') || str_contains($field->type, 'bool') ? 0 : $name;
                    }
                }
                self::$engines->insert($own->table, $row);
            }
        }
        $channel = self::$engines->insert('video_channel', ['slug' => 'chaine', 'name' => 'La chaine des chats', 'shelves' => '[]', 'createdAt' => '2026-01-01 10:00:00']);
        $other = self::$engines->insert('video_channel', ['slug' => 'autre', 'name' => 'Autre', 'shelves' => '[]', 'createdAt' => '2026-01-01 10:00:00']);
        self::$engines->insert('video_channel_owner', ['video_channel_id' => $channel, 'user_id' => self::BOB]);
        self::$engines->insert('video_subscription', ['createdAt' => '2026-01-01 10:00:00', 'user_id' => self::ALICE, 'channel_id' => $channel]);
        $a = self::film('Chats qui dansent', 10, 100, $channel, '2026-01-03 10:00:00', ['duration' => 100, 'category' => 'music']);
        $b = self::film('Chiens', 50, null, $channel, '2026-01-02 10:00:00', ['duration' => 600]);
        $c = self::film('Chats en brouillon', 500, null, $channel, null, [], ThreadState::DRAFT);
        $d = self::film('Oiseaux', 5, null, $other, '2026-01-04 10:00:00', ['processing' => Video::PROCESSING_UPLOADED]);
        $e = self::film('Chats de Alice', 1, null, null, '2026-01-05 10:00:00');
        // Thread::$owners' join table as the description names it (a host's own App\Entity\User names its column).
        $owners = self::$engines->description()->entity(Thread::class)?->relations['owners']->joinTable ?? throw new \LogicException('No Thread::$owners join table.');
        self::$engines->insert($owners->name, [$owners->joinColumns[0]->name => $e, $owners->inverseJoinColumns[0]->name => self::ALICE]);
        self::$ids = ['channel' => $channel, 'a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'e' => $e];

        $public = self::$engines->insert('video_playlist', ['slug' => 'l1', 'title' => 'L1', 'kind' => Playlist::LIST, 'visibility' => Playlist::PUBLIC, 'createdAt' => '2026-01-01 10:00:00', 'updatedAt' => '2026-01-01 10:00:00', 'owner_id' => self::ALICE]);
        $private = self::$engines->insert('video_playlist', ['slug' => 'l2', 'title' => 'L2', 'kind' => Playlist::LIST, 'visibility' => 'private', 'createdAt' => '2026-01-01 10:00:00', 'updatedAt' => '2026-01-01 10:00:00', 'owner_id' => self::ALICE]);
        foreach ([[$public, $a], [$public, $b], [$private, $a]] as $i => [$list, $film]) {
            self::$engines->insert('video_playlist_item', ['position' => $i, 'addedAt' => '2026-01-01 10:00:00', 'playlist_id' => $list, 'video_id' => $film]);
        }
        self::$ids += ['public' => $public, 'private' => $private];
    }

    /** A film's rows (Thread's constructor writes its title through the translator): thread, video, its title. @param array<string, mixed> $more */
    private static function film(string $title, int $views, ?int $legacy, ?int $channel, ?string $publishedAt, array $more = [], string $state = ThreadState::PUBLISH): int
    {
        $id = self::$engines->insert('thread', ['uuid' => uniqid('', true), 'slug' => strtolower(str_replace(' ', '-', $title)), 'state' => $state, 'workflow' => WorkflowState::APPROVED,
            'createdAt' => $publishedAt ?? '2026-01-01 10:00:00', 'updatedAt' => '2026-01-01 10:00:00', 'publishedAt' => $publishedAt, 'class' => self::$engines->discriminator(Video::class)]);
        self::$engines->insert('video', $more + ['id' => $id, 'views' => $views, 'legacyViews' => $legacy, 'channel_id' => $channel, 'processing' => Video::PROCESSING_READY]);
        self::$engines->insert('threadIntl', ['title' => $title, 'keywords' => '[]', 'locale' => 'fr', 'translatable_id' => $id, 'class' => self::$engines->discriminator(\Base\Entity\ThreadIntl::class)]);

        return $id;
    }

    /** @param iterable<Video> $videos @return list<int> */
    private static function ids(iterable $videos): array
    {
        return array_values(array_map(static fn (Video $v): int => (int) $v->getId(), [...$videos]));
    }

    private static function exec(string $sql): void
    {
        self::$engines->pdo()->exec($sql);
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testTheFilmsLists(string $backend): void
    {
        $videos = new VideoRepository(self::$engines->finders($backend));
        $ids = self::$ids;

        self::assertSame([$ids['a'], $ids['b'], $ids['e']], self::ids($videos->findPopular()), 'listed only (C a draft, D processing), 10+100 imported first');
        self::assertSame([$ids['a'], $ids['b']], self::ids($videos->findPopular(24, self::stub(Channel::class, $ids['channel']))));
        self::assertSame([$ids['a'], $ids['b']], self::ids($videos->findFromSubscriptions(self::stub(User::class, self::ALICE))), 'the followed channel, newest first');
        $studio = self::ids($videos->findForStudio(self::stub(User::class, self::BOB)));
        sort($studio);
        self::assertSame([$ids['a'], $ids['b'], $ids['c']], $studio, "Bob's channel's films, every state");
        self::assertSame([$ids['e']], self::ids($videos->findForStudio(self::stub(User::class, self::ALICE))), 'her own film');
        self::assertSame([$ids['c']], self::ids($videos->findForStudio(self::stub(User::class, self::BOB), Video::VISIBILITY_PRIVATE)), 'a draft');
        self::assertSame([$ids['e'], $ids['a'], $ids['b']], self::ids($videos->searchListed('chats')), "every word in a title or the channel's name (C is no listed film)");
        self::assertSame([$ids['a'], $ids['b']], self::ids($videos->searchListed('chaine')), "or in the channel's name");
        self::assertSame([$ids['a']], self::ids($videos->searchListed('chats', ['category' => 'music', 'duration' => 'short'])));
        self::assertSame([$ids['b']], self::ids($videos->searchListed('chiens', ['channel' => 'chaine', 'duration' => 'medium'])));
        self::assertSame([Video::PROCESSING_UPLOADED => 1, Video::PROCESSING_READY => 4], $videos->countByProcessing());
    }

    /**
     * Listed means published by the application's clock, in UTC, for the finder (findLatest) and the statements
     * (findPopular) alike: B, published at 10:00 UTC, is out at 10:30 UTC, not at 09:30 UTC - also with PHP in Tokyo,
     * where 09:30 UTC is 18:30 (a comparison of wall clocks would list it).
     * @dataProvider backends
     */
    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testListedOnTheApplicationsClockInUtc(string $backend): void
    {
        date_default_timezone_set('Asia/Tokyo');
        $videos = new VideoRepository(self::$engines->finders($backend));

        $this->clock->modify('2026-01-02 09:30:00 UTC');
        self::assertSame([], self::ids($videos->findLatest()), 'half an hour early');
        self::assertSame([], self::ids($videos->findPopular()));

        $this->clock->modify('2026-01-02 10:30:00 UTC');
        self::assertSame([self::$ids['b']], self::ids($videos->findLatest()), 'half an hour after');
        self::assertSame([self::$ids['b']], self::ids($videos->findPopular()));
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testViewsAddedInTheDatabase(string $backend): void
    {
        $videos = new VideoRepository(self::$engines->finders($backend));
        $film = $videos->find(self::$ids['d']);
        self::assertInstanceOf(Video::class, $film);
        $before = $film->getViews();

        $videos->addViews([self::$ids['d'] => 3]);
        $videos->forgetViews([self::$ids['d']]);

        self::assertSame($before + 3, $film->getViews(), 'the film read before is read again');
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testTrendingAndTheWatchEvents(string $backend): void
    {
        self::exec('DELETE FROM video_watch');
        $ids = self::$ids;
        $watch = static fn (int $film, ?int $user, string $visitor, string $at, int $counted = 1, int $flushed = 0, float $position = 10.0): int => self::$engines->insert('video_watch', ['token' => uniqid('', true), 'visitor' => $visitor, 'source' => 'direct', 'position' => $position, 'watched' => $position, 'counted' => $counted, 'flushed' => $flushed, 'completed' => 0, 'startedAt' => $at, 'updatedAt' => $at, 'video_id' => $film, 'user_id' => $user]);
        $recent = $this->clock->now()->modify('-1 day')->format('Y-m-d H:i:s');
        $old = $this->clock->now()->modify('-30 days')->format('Y-m-d H:i:s');
        $watch($ids['a'], self::ALICE, 'v1', $recent, 1, 0, 30.0);
        $watch($ids['a'], self::ALICE, 'v1', $old, 1, 0, 50.0);
        $watch($ids['b'], self::BOB, 'v2', $recent);
        $watch($ids['b'], null, 'v3', $recent);
        $watch($ids['b'], null, 'v3', $recent, 0);
        $events = (new WatchEventRepository(self::$engines->finders($backend), new VideoRepository(self::$engines->finders($backend))));
        $videos = new VideoRepository(self::$engines->finders($backend));

        self::assertSame([[$ids['b'], 2], [$ids['a'], 1]], array_map(static fn (array $r): array => [(int) $r['id'], (int) $r['n']], $videos->trendingCounts($this->clock->now()->modify('-7 days'), 10)));
        self::assertSame([$ids['b'], $ids['a']], self::ids($videos->findTrending(2)), 'the last seven days on the application\'s clock');
        self::assertSame([[$ids['a'], 50.0]], array_map(static fn (array $r): array => [(int) $r['id'], (float) $r['position']], $events->historyRows(self::stub(User::class, self::ALICE), 10)));
        self::assertSame([$ids['a']], $events->findWatchedIds(self::stub(User::class, self::ALICE)));
        self::assertSame([$ids['b']], $events->findWatchedIds(null, 'v3'));
        $unflushed = $events->countUnflushed();
        ksort($unflushed);
        self::assertSame([$ids['a'] => 2, $ids['b'] => 2], $unflushed);
        $co = $events->findCoWatching();
        ksort($co);
        self::assertSame(['u'.self::ALICE => [$ids['a']], 'u'.self::BOB => [$ids['b']], 'vv3' => [$ids['b']]], $co);
        $stats = $events->statsRows(self::stub(Video::class, $ids['b']));
        self::assertCount(3, $stats);
        self::assertSame([true, true, false], array_map(static fn (array $r): bool => (bool) $r['counted'], $stats));
        self::assertSame(substr($recent, 0, 10), $stats[0]['at'] instanceof \DateTimeInterface ? $stats[0]['at']->format('Y-m-d') : substr((string) $stats[0]['at'], 0, 10), 'the day it started, what figures() reads');
        self::assertSame(2, $events->markFlushed([$ids['a']]));
        self::assertSame([$ids['b'] => 2], $events->countUnflushed());
        self::assertSame(1, $events->removeFromHistory(self::stub(User::class, self::BOB), self::stub(Video::class, $ids['b'])));
        self::assertSame(2, $events->clearHistory(self::stub(User::class, self::ALICE)));
        self::assertSame([], $events->findWatchedIds(self::stub(User::class, self::ALICE)));
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testPlaylists(string $backend): void
    {
        $playlists = new PlaylistRepository(self::$engines->finders($backend));

        self::assertSame([self::$ids['public']], $playlists->findPublicIdsContaining(self::stub(Video::class, self::$ids['a'])));
        self::assertSame([self::$ids['public'] => [self::$ids['a'], self::$ids['b']]], $playlists->findPublicMemberships());
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testRelated(string $backend): void
    {
        $related = new RelatedRepository(self::$engines->finders($backend));
        $ids = self::$ids;
        $related->replaceAll([[$ids['a'], $ids['b'], 0.5], [$ids['e'], $ids['b'], 0.25], [$ids['a'], $ids['e'], 0.1]], new \DateTime('2026-01-01 10:00:00'));

        self::assertSame([$ids['b'] => 0.75, $ids['e'] => 0.1], $related->scoresFor([$ids['a'], $ids['e']]));
        $related->deleteAll();
        self::assertSame([], $related->scoresFor([$ids['a']]));
    }

    /** @dataProvider backends */
    #[DataProvider('backends')]
    public function testUnpinAll(string $backend): void
    {
        self::exec('DELETE FROM video_comment; DELETE FROM threadComment');
        $comment = static function (int $film, int $pinned): int {
            $id = self::$engines->insert('threadComment', ['name' => 'Jeanne', 'content' => 'Bravo', 'state' => 'approved', 'createdAt' => '2026-01-01 10:00:00', 'thread_id' => $film, 'class' => self::$engines->discriminator(VideoComment::class)]);
            self::$engines->insert('video_comment', ['id' => $id, 'pinned' => $pinned]);

            return $id;
        };
        $comment(self::$ids['a'], 1);
        $comment(self::$ids['a'], 1);
        $other = $comment(self::$ids['b'], 1);

        (new VideoCommentRepository(self::$engines->finders($backend)))->unpinAll(self::stub(Video::class, self::$ids['a']));

        $pdo = self::$engines->pdo() ?? throw new \LogicException('No engines.');
        self::assertSame([$other], array_map('intval', $pdo->query('SELECT id FROM video_comment WHERE pinned = 1')->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** An entity of that id, never read: what a statement taking the entity is given. */
    private static function stub(string $class, int $id): object
    {
        $entity = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($class, 'id'))->setValue($entity, $id);

        return $entity;
    }
}

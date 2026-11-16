<?php

namespace Base\Video\Repository;

use Base\Database\Repository\Finder\Aggregate;
use Base\Database\Repository\Finder\Key;
use Base\Video\Entity\Related;
use Base\Video\Entity\Video;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Related|null find($id, $lockMode = null, $lockVersion = null)
 * @method Related|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method Related[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class RelatedRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Related::class);
    }

    /** @return list<Video> a film's neighbours, the closest first, still listed */
    public function findFor(Video $video, int $limit = 12): array
    {
        // The neighbour was fetch-joined; it is required, the inner join kept every row.
        $rows = $this->findBy(['video' => Key::of($video)], ['score' => 'DESC'], $limit * 2);

        return \array_slice(array_values(array_filter(array_map(fn (Related $r) => $r->getRelated(), $rows), fn (Video $v) => $v->isListed())), 0, $limit);
    }

    /** @param list<int> $videoIds @return array<int, float> neighbour id => summed score over $videoIds. */
    public function scoresFor(array $videoIds, int $limit = 100): array
    {
        if (!$videoIds) {
            return [];
        }
        $scores = [];
        foreach ($this->findValuesBy(['id' => 'related', 's' => Aggregate::sum('score')], ['video' => Key::of($videoIds)], ['s' => 'DESC'], $limit) as $row) {
            $scores[(int) $row['id']] = (float) $row['s'];
        }

        return $scores;
    }

    /**
     * The whole table written again, in one transaction: every row deleted, the new ones inserted 500 at a time (moved
     * here from Base\Video\Suggest\RelatedCalculator::compute()).
     *
     * @param list<array{0: int, 1: int, 2: float}> $rows video id, neighbour id, score
     */
    public function replaceAll(array $rows, \DateTimeInterface $now): void
    {
        // The whole table written again in one transaction: the rows deleted, the new ones written 500 at a time.
        $this->unitOfWork()->transactional(function () use ($rows, $now): void {
            $this->deleteBy([]);
            foreach (array_chunk($rows, 500) as $chunk) {
                foreach ($chunk as [$video, $related, $score]) {
                    $this->unitOfWork()->persist(new Related($this->unitOfWork()->reference(Video::class, $video), $this->unitOfWork()->reference(Video::class, $related), (float) $score, $now));
                }
                $this->unitOfWork()->flush();
                $this->clear();
            }
        });
    }

    public function deleteFor(Video $video): void
    {
        $this->deleteBy(['video' => $video]);
    }

    public function deleteAll(): void
    {
        $this->deleteBy([]);
    }
}

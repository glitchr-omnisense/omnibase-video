<?php

namespace Base\Video\Repository;

use Base\Entity\User;
use Base\Video\Entity\Channel;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Channel|null find($id, $lockMode = null, $lockVersion = null)
 * @method Channel|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method Channel[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class ChannelRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Channel::class);
    }

    /** The first channel a member owns. */
    public function findOneOwnedBy(User $user): ?Channel
    {
        // The collection compared with one member: a join of it, as `JOIN c.owners o WHERE o = :user` was.
        return $this->findOneBy(['owners' => $user], ['id' => 'ASC']);
    }

    /** A free address for $name: "ma-chaine", then "ma-chaine-2"... */
    public function freeSlug(string $name): string
    {
        $base = Channel::slugify($name);
        $slug = $base;
        for ($i = 2; null !== $this->findOneBy(['slug' => $slug]); ++$i) {
            $slug = mb_substr($base, 0, 74).'-'.$i;
        }

        return $slug;
    }

    /** @return list<Channel> the most followed */
    public function findPopular(int $limit = 12): array
    {
        return $this->findBy([], ['subscribers' => 'DESC', 'id' => 'ASC'], $limit);
    }
}

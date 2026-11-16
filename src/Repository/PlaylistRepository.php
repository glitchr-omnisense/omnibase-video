<?php

namespace Base\Video\Repository;

use Base\Database\Repository\Finder\Parser;
use Base\Database\Repository\Finder\Group;
use Base\Database\Repository\Finder\Criterion;
use Base\Database\Repository\Finder\Key;
use Base\Entity\User;
use Base\Video\Entity\Playlist;
use Base\Video\Entity\Video;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Playlist|null find($id, $lockMode = null, $lockVersion = null)
 * @method Playlist|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method Playlist[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class PlaylistRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Playlist::class);
    }

    /** "À regarder plus tard", made the first time it is asked for. */
    public function findLater(User $user, bool $create = true): ?Playlist
    {
        $later = $this->findOneBy(['owner' => $user, 'kind' => Playlist::LATER]);
        if (!$later && $create) {
            $later = new Playlist($user, 'À regarder plus tard', Playlist::LATER);
            $this->persist($later);
        }

        return $later;
    }

    /** @return list<Playlist> a member's own lists (not "plus tard") */
    public function findOwnedBy(User $user): array
    {
        return $this->findBy(['owner' => $user, 'kind' => Playlist::LIST], ['updatedAt' => 'DESC']);
    }

    /** @return list<Playlist> the public lists of these owners, for their channel page */
    public function findPublicOf(iterable $owners, int $limit = 24): array
    {
        $owners = \is_array($owners) ? $owners : iterator_to_array($owners);
        if (!$owners) {
            return [];
        }

        return $this->findBy(['owner' => Key::of(array_values($owners)), 'kind' => Playlist::LIST, 'visibility' => Playlist::PUBLIC], ['updatedAt' => 'DESC'], $limit);
    }

    /** @return list<int> ids of the public lists a video is in (the suggestions' "same list"). */
    public function findPublicIdsContaining(Video $video): array
    {
        return array_map('intval', array_column($this->findValuesBy(['id'], ['items.video' => $video, 'visibility' => Playlist::PUBLIC]), 'id'));
    }

    /** @return array<int, list<int>> public list id => its video ids. */
    public function findPublicMemberships(): array
    {
        $lists = [];
        foreach ($this->findValuesBy(['list' => 'id', 'film' => 'items.video'], ['visibility' => Playlist::PUBLIC, Group::of(Parser::SEPARATOR_AND, Criterion::of('items.video', null, Parser::OPTION_NOT_NULL))]) as $row) {
            $lists[(int) $row['list']][] = (int) $row['film'];
        }

        return $lists;
    }
}

<?php

namespace Base\Video\Repository;

use Base\Database\Repository\Finder\Key;
use Base\Entity\User;
use Base\Video\Entity\Channel;
use Base\Video\Entity\Subscription;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Subscription|null find($id, $lockMode = null, $lockVersion = null)
 * @method Subscription|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method Subscription[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Subscription::class);
    }

    public function findOneFor(User $user, Channel $channel): ?Subscription
    {
        return $this->findOneBy(['user' => Key::of($user), 'channel' => Key::of($channel)]);
    }

    public function countFor(Channel $channel): int
    {
        return $this->count(['channel' => Key::of($channel)]);
    }

    /** @return list<Channel> the channels a member follows */
    public function findChannels(User $user): array
    {
        return array_map(fn (Subscription $s) => $s->getChannel(), $this->findBy(['user' => Key::of($user)], ['createdAt' => 'DESC']));
    }

    /** @return list<Subscription> a channel's subscribers, the newest first */
    public function findSubscribers(Channel $channel, int $limit = 100): array
    {
        return $this->findBy(['channel' => Key::of($channel)], ['createdAt' => 'DESC'], $limit);
    }
}

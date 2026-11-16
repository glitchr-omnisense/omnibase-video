<?php

namespace Base\Video\Service;

use Base\Entity\User;
use Base\Video\Entity\Channel;
use Base\Video\Entity\Subscription;
use Base\Video\Repository\ChannelRepository;
use Base\Video\Repository\SubscriptionRepository;
use Base\Contract\UnitOfWork;

/**
 * A member's channel, made the first time they publish (named after them,
 * renamed in the studio), and the subscriptions to channels, whose count
 * the channel keeps.
 */
class Channels
{
    public function __construct(
        private readonly UnitOfWork $unitOfWork,
        private readonly ChannelRepository $channels,
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    public function forMember(User $user, bool $create = true): ?Channel
    {
        $channel = $this->channels->findOneOwnedBy($user);
        if (!$channel && $create) {
            $name = trim((string) $user) ?: 'Ma chaîne';
            $channel = new Channel($name, $user, $this->channels->freeSlug($name));
            $this->unitOfWork->persist($channel);
        }

        return $channel;
    }

    public function isSubscribed(?User $user, Channel $channel): bool
    {
        return $user && null !== $this->subscriptions->findOneFor($user, $channel);
    }

    /** Follow, or stop following: whether $user follows $channel now. A member never follows their own. */
    public function toggle(User $user, Channel $channel): bool
    {
        if ($channel->isOwnedBy($user)) {
            return false;
        }
        $subscription = $this->subscriptions->findOneFor($user, $channel);
        if ($subscription) {
            $this->unitOfWork->remove($subscription);
        } else {
            $this->unitOfWork->persist(new Subscription($user, $channel));
        }
        $this->unitOfWork->flush();
        $channel->setSubscribers($this->subscriptions->countFor($channel));
        $this->unitOfWork->flush();

        return null === $subscription;
    }
}

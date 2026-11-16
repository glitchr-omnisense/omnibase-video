<?php

namespace Base\Video\Repository;

use Base\Database\Repository\Finder\Key;
use Base\Enum\CommentState;
use Base\Video\Entity\Video;
use Base\Video\Entity\VideoComment;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method VideoComment|null find($id, $lockMode = null, $lockVersion = null)
 * @method VideoComment|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method VideoComment[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class VideoCommentRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, VideoComment::class);
    }

    /** @return list<VideoComment> the comments online under a film (not the replies), the pinned one first, then the newest */
    public function findVisible(Video $video, int $limit = 100, int $offset = 0): array
    {
        return $this->findBy(
            ['thread' => Key::of($video), 'parent' => Key::of(null), 'state' => CommentState::APPROVED],
            ['pinned' => 'DESC', 'createdAt' => 'DESC', 'id' => 'DESC'], $limit, $offset,
        );
    }

    public function countVisible(Video $video): int
    {
        return $this->count(['thread' => Key::of($video), 'state' => CommentState::APPROVED]);
    }

    /** @return list<VideoComment> every comment of a film, for its author's studio */
    public function findForVideo(Video $video): array
    {
        return $this->findByStateNotEqualTo(CommentState::SPAM, ['thread' => Key::of($video)], ['pinned' => 'DESC', 'createdAt' => 'DESC'])->getResult();
    }

    public function countPending(): int
    {
        return $this->count(['state' => CommentState::PENDING]);
    }

    public function unpinAll(Video $video): void
    {
        $this->updateBy(['thread' => Key::of($video)], ['pinned' => false]);
    }
}

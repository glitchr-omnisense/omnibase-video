<?php

namespace Base\Video\Repository;

use Base\Video\Entity\Source;
use Base\Database\Repository\Finders;
use Base\Database\Repository\ServiceEntityRepository;

/**
 * @method Source|null find($id, $lockMode = null, $lockVersion = null)
 * @method Source|null findOneBy(array $criteria, ?array $orderBy = null)
 * @method Source[]    findBy(array $criteria, ?array $orderBy = null, $limit = null, $offset = null)
 */
class SourceRepository extends ServiceEntityRepository
{
    public function __construct(Finders $finders)
    {
        parent::__construct($finders, Source::class);
    }
}

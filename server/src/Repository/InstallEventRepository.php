<?php

namespace App\Repository;

use App\Entity\InstallEvent;
use App\Entity\Package;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<InstallEvent> */
class InstallEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstallEvent::class);
    }

    /** @return list<array<string, mixed>> a package's events since $since, as StatsReport::build() reads them */
    public function rowsOf(Package $package, \DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('e')
            ->select('e.event, e.install, e.os, e.arch, e.version, e.mode, e.page, e.step, e.error, e.seconds, e.day')
            ->where('e.package = :p')->andWhere('e.day >= :since')
            ->setParameter('p', $package)->setParameter('since', $since->setTime(0, 0), 'date_immutable')
            ->orderBy('e.id')
            ->getQuery()->getArrayResult();
        return array_map(static fn (array $r): array => ['day' => $r['day']->format('Y-m-d')] + $r, $rows);
    }

    /** How many events one run already sent (a run sends a few dozen at most). */
    public function countOfInstall(string $install): int
    {
        return $this->count(['install' => $install]);
    }
}

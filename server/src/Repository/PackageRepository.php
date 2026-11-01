<?php

namespace App\Repository;

use App\Entity\Package;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Package> */
class PackageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Package::class);
    }

    /** @return list<Package> */
    public function ofOwner(User $owner): array
    {
        return $this->findBy(['owner' => $owner], ['updatedAt' => 'DESC']);
    }
}

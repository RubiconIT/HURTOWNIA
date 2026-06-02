<?php

namespace App\Repository;

use App\Entity\ApiFetchError;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiFetchError>
 *
 * @method ApiFetchError|null find($id, $lockMode = null, $lockVersion = null)
 * @method ApiFetchError|null findOneBy(array $criteria, array $orderBy = null)
 * @method ApiFetchError[]    findAll()
 * @method ApiFetchError[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ApiFetchErrorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiFetchError::class);
    }

    public function save(ApiFetchError $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ApiFetchError $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function clearErrors(string $commandName): int
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = "DELETE FROM api_fetch_error WHERE command = :command AND `time` < :today";
        $stmt = $conn->prepare($sql);
        $res = $stmt->executeQuery([
            'command' => $commandName,
            'today'   => date('Y-m-d H:i:s'),
        ]);

        return $res->rowCount() ?? 0;
    }

    public function getErrorsCount(string $start): int
    {
        $qb = $this->createQueryBuilder('error');
        $qb->select('COUNT(error.id) as total')
            ->where('error.time >= :start')
            ->setParameter('start', $start);

        return (int)$qb->getQuery()->getSingleScalarResult();
    }
}

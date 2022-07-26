<?php
/*
  * Copyright © Ghost Unicorns snc. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace GhostUnicorns\CrtImportCategory\Transferor;

use Exception;
use GhostUnicorns\CrtActivity\Api\ActivityRepositoryInterface;
use GhostUnicorns\CrtBase\Api\CrtConfigInterface;
use GhostUnicorns\CrtBase\Api\TransferorInterface;
use GhostUnicorns\CrtBase\Exception\CrtException;
use GhostUnicorns\CrtEntity\Api\EntityRepositoryInterface;
use GhostUnicorns\CrtUtils\Model\DotConvention;
use GhostUnicorns\FdiCategory\Model\CreateCategoryIdByCategoriesPathArray;
use GhostUnicorns\FdiCategory\Model\ResourceModel\GetCategoryIdByCategoryPathArray;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Monolog\Logger;

class CategoriesByPathArrayTransferor implements TransferorInterface
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var DotConvention
     */
    private $dotConvention;

    /**
     * @var CrtConfigInterface
     */
    private $config;

    /**
     * @var EntityRepositoryInterface
     */
    private $entityRepository;

    /**
     * @var ActivityRepositoryInterface
     */
    private $activityRepository;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var CreateCategoryIdByCategoriesPathArray
     */
    private $createCategoryIdByCategoriesPathArray;

    /**
     * @var GetCategoryIdByCategoryPathArray
     */
    private $getCategoryIdByCategoryPathArray;

    /**
     * @var string
     */
    private $source;

    /**
     * @var int
     */
    private $rootCategoryId;

    /**
     * @var array
     */
    private $attributesToIgnore;

    /**
     * @param Logger $logger
     * @param DotConvention $dotConvention
     * @param CrtConfigInterface $config
     * @param EntityRepositoryInterface $entityRepository
     * @param ActivityRepositoryInterface $activityRepository
     * @param ResourceConnection $resourceConnection
     * @param GetCategoryIdByCategoryPathArray $getCategoryIdByCategoryPathArray
     * @param CreateCategoryIdByCategoriesPathArray $getOrCreateCategoryIdByCategoriesPathArray
     * @param string $source
     * @param int $rootCategoryId
     * @param array $attributesToIgnore
     */
    public function __construct(
        Logger $logger,
        DotConvention $dotConvention,
        CrtConfigInterface $config,
        EntityRepositoryInterface $entityRepository,
        ActivityRepositoryInterface $activityRepository,
        ResourceConnection $resourceConnection,
        GetCategoryIdByCategoryPathArray $getCategoryIdByCategoryPathArray,
        CreateCategoryIdByCategoriesPathArray $getOrCreateCategoryIdByCategoriesPathArray,
        string $source,
        int $rootCategoryId = 1,
        array $attributesToIgnore = []
    ) {
        $this->logger = $logger;
        $this->dotConvention = $dotConvention;
        $this->config = $config;
        $this->entityRepository = $entityRepository;
        $this->activityRepository = $activityRepository;
        $this->resourceConnection = $resourceConnection;
        $this->getCategoryIdByCategoryPathArray = $getCategoryIdByCategoryPathArray;
        $this->createCategoryIdByCategoriesPathArray = $getOrCreateCategoryIdByCategoriesPathArray;
        $this->source = $source;
        $this->rootCategoryId = $rootCategoryId;
        $this->attributesToIgnore = $attributesToIgnore;
    }

    /**
     * @param int $activityId
     * @param string $transferorType
     * @throws CrtException
     * @throws NoSuchEntityException
     */
    public function execute(int $activityId, string $transferorType): void
    {
        $allActivityEntities = $this->entityRepository->getAllDataRefinedByActivityIdGroupedByIdentifier($activityId);

        $i = 0;
        $ok = 0;
        $ko = 0;
        $tot = count($allActivityEntities);
        foreach ($allActivityEntities as $entityIdentifier => $entities) {
            try {
                $categoriesByPathArray = $this->dotConvention->getValue($entities, $this->source);
                if (!$categoriesByPathArray) {
                    continue;
                }
            } catch (Exception $exception) {
                continue;
            }

            $this->logger->info(__(
                'activityId:%1 ~ Transferor ~ transferorType:%2 ~ entityIdentifier:%3 ~ step:%4/%5 ~ START',
                $activityId,
                $transferorType,
                $entityIdentifier,
                ++$i,
                $tot
            ));

            try {
                $this->resourceConnection->getConnection()->beginTransaction();

                foreach ($categoriesByPathArray as $categoryByPathArray) {
                    try {
                        $categoryId = $this->getCategoryIdByCategoryPathArray->execute(
                            $categoryByPathArray['path_array'],
                            $this->rootCategoryId
                        );
                    } catch (Exception $exception) {
                        $categoryId = $this->createCategoryIdByCategoriesPathArray->execute(
                            $categoryByPathArray['path_array'],
                            $this->rootCategoryId,
                            $categoryByPathArray['data'],
                            $this->attributesToIgnore
                        );
                    }
                }

                $this->logger->info(__(
                    'activityId:%1 ~ Transferor ~ transferorType:%2 ~ entityIdentifier:%3 ~' .
                    ' saved category with id:%4 ~ END',
                    $activityId,
                    $transferorType,
                    $entityIdentifier,
                    $categoryId
                ));

                $this->resourceConnection->getConnection()->commit();
                $ok++;
            } catch (Exception $e) {
                $this->resourceConnection->getConnection()->rollBack();
                $ko++;

                $this->logger->error(__(
                    'activityId:%1 ~ Transferor ~ transferorType:%2 ~ entityIdentifier:%3 ~ ERROR ~ error:%4',
                    $activityId,
                    $transferorType,
                    $entityIdentifier,
                    $e->getMessage()
                ));

                if (!$this->config->continueInCaseOfErrors()) {
                    $this->updateSummary($activityId, $ok, $ko);
                    throw new CrtException(__(
                        'activityId:%1 ~ Transferor ~ transferorType:%2 ~ entityIdentifier:%3 ~ END ~ ' .
                        'Because of continueInCaseOfErrors = false',
                        $activityId,
                        $transferorType,
                        $entityIdentifier
                    ));
                }
            }
        }
        $this->updateSummary($activityId, $ok, $ko);
    }

    /**
     * @param int $activityId
     * @param int $ok
     * @param int $ko
     * @throws NoSuchEntityException
     */
    private function updateSummary(int $activityId, int $ok, int $ko)
    {
        $activity = $this->activityRepository->getById($activityId);
        $activity->addExtraArray(['ok' => $ok, 'ko' => $ko]);
        $this->activityRepository->save($activity);
    }
}

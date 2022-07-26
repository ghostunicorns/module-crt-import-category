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
use GhostUnicorns\FdiCategory\Model\ResourceModel\CreateCategoryWithId;
use GhostUnicorns\FdiCategory\Model\UpdateCategory;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\CategoryRepository;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Monolog\Logger;

class CategoryTransferor implements TransferorInterface
{
    /**
     * @var Logger
     */
    private $logger;

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
     * @var CategoryRepository
     */
    private $categoryRepository;

    /**
     * @var Json
     */
    private $serializer;

    /**
     * @var CreateCategoryWithId
     */
    private $createCategoryWithId;

    /**
     * @var UpdateCategory
     */
    private $updateCategory;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var string
     */
    private $source;

    /**
     * @var array
     */
    private $attributesToIgnore;

    /**
     * @param Logger $logger
     * @param CrtConfigInterface $config
     * @param EntityRepositoryInterface $entityRepository
     * @param ActivityRepositoryInterface $activityRepository
     * @param CategoryRepository $categoryRepository
     * @param Json $serializer
     * @param CreateCategoryWithId $createCategoryWithId
     * @param UpdateCategory $updateCategory
     * @param ResourceConnection $resourceConnection
     * @param string $source
     * @param array $attributesToIgnore
     */
    public function __construct(
        Logger $logger,
        CrtConfigInterface $config,
        EntityRepositoryInterface $entityRepository,
        ActivityRepositoryInterface $activityRepository,
        CategoryRepository $categoryRepository,
        Json $serializer,
        CreateCategoryWithId $createCategoryWithId,
        UpdateCategory $updateCategory,
        ResourceConnection $resourceConnection,
        string $source,
        array $attributesToIgnore = []
    ) {
        $this->logger = $logger;
        $this->config = $config;
        $this->entityRepository = $entityRepository;
        $this->activityRepository = $activityRepository;
        $this->categoryRepository = $categoryRepository;
        $this->serializer = $serializer;
        $this->createCategoryWithId = $createCategoryWithId;
        $this->updateCategory = $updateCategory;
        $this->resourceConnection = $resourceConnection;
        $this->source = $source;
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
            $this->logger->info(__(
                'activityId:%1 ~ Transferor ~ transferorType:%2 ~ entityIdentifier:%3 ~ step:%4/%5 ~ START',
                $activityId,
                $transferorType,
                $entityIdentifier,
                ++$i,
                $tot
            ));

            $categoryData = $entities[$this->source];

            try {
                $this->resourceConnection->getConnection()->beginTransaction();

                $category = $this->getOrCreateCategory($categoryData['id']);
                $this->updateCategory->execute($category, $categoryData, $this->attributesToIgnore);
                $this->logger->info(__(
                    'activityId:%1 ~ Transferor ~ transferorType:%2 ~ entityIdentifier:%3 ~'.
                    ' saved category with values:%4 ~ END',
                    $activityId,
                    $transferorType,
                    $entityIdentifier,
                    $this->serializer->serialize($category->getData())
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
                        'activityId:%1 ~ Transferor ~ transferorType:%2 ~ entityIdentifier:%3 ~ END ~ '.
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
     * @param int $categoryId
     * @return Category
     * @throws NoSuchEntityException
     */
    private function getOrCreateCategory(int $categoryId): Category
    {
        try {
            return $this->categoryRepository->get($categoryId);
        } catch (NoSuchEntityException $e) {
            $this->createCategoryWithId->execute($categoryId);
            return $this->categoryRepository->get($categoryId);
        }
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

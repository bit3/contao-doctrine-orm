<?php

/**
 * Doctrine ORM bridge
 * Copyright (C) 2013 Tristan Lins
 *
 * PHP version 5
 *
 * @copyright  bit3 UG 2013
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @package    doctrine-orm
 * @license    LGPL
 * @filesource
 */

namespace Contao\Doctrine\ORM\DataContainer\General;

use Contao\Doctrine\ORM\EntityAccessor;
use Contao\Doctrine\ORM\EntityHelper;
use Contao\Doctrine\ORM\EntityInterface;
use Contao\Doctrine\ORM\Mapping\Driver\ContaoDcaDriver;
use Contao\Doctrine\ORM\VersionManager;
use ContaoCommunityAlliance\DcGeneral\Data\CollectionInterface;
use ContaoCommunityAlliance\DcGeneral\Data\ConfigInterface;
use ContaoCommunityAlliance\DcGeneral\Data\DataProviderInterface;
use ContaoCommunityAlliance\DcGeneral\Data\DefaultCollection;
use ContaoCommunityAlliance\DcGeneral\Data\DefaultConfig;
use ContaoCommunityAlliance\DcGeneral\Data\DefaultFilterOptionCollection;
use ContaoCommunityAlliance\DcGeneral\Data\DefaultModel;
use ContaoCommunityAlliance\DcGeneral\Data\FilterOptionCollectionInterface;
use ContaoCommunityAlliance\DcGeneral\Data\ModelInterface;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;

class EntityDataProvider implements DataProviderInterface
{
    /**
     * @var string
     */
    protected $tableName;

    /**
     * @var string
     */
    protected $entityClassName;

    /**
     * @var \ReflectionClass
     */
    protected $entityClass;

    /**
     * @var EntityManager
     */
    protected $entityManager;

    /**
     * @var EntityRepository
     */
    protected $entityRepository;

    /**
     * @var EntityAccessor
     */
    protected $entityAccessor;

    /**
     * @return EntityManager
     */
    public function getEntityManager()
    {
        if (!$this->entityManager) {
            $this->entityManager = EntityHelper::getEntityManager();
        }
        return $this->entityManager;
    }

    /**
     * @return EntityRepository
     */
    public function getEntityRepository()
    {
        if (!$this->entityRepository) {
            $this->entityRepository = EntityHelper::getRepository($this->entityClassName);
        }
        return $this->entityRepository;
    }

    /**
     * @param \Contao\Doctrine\ORM\EntityAccessor $entityAccessor
     */
    public function setEntityAccessor($entityAccessor)
    {
        $this->entityAccessor = $entityAccessor;
        return $this;
    }

    /**
     * @return \Contao\Doctrine\ORM\EntityAccessor
     */
    public function getEntityAccessor()
    {
        return $this->entityAccessor;
    }

    /**
     * @param EntityInterface[] $entities
     *
     * @return DefaultCollection
     */
    public function mapEntities($entities)
    {
        $collection = new DefaultCollection();
        foreach ($entities as $entity) {
            $collection->push($this->mapEntity($entity));
        }
        return $collection;
    }

    /**
     * @param EntityInterface $entity
     *
     * @return EntityModel
     */
    public function mapEntity($entity)
    {
        return new EntityModel($entity);
    }

    /**
     * @param VersionModel[] $versions
     *
     * @return DefaultCollection
     */
    public function mapVersions($versions, $activeVersion = false)
    {
        $collection = new DefaultCollection();
        foreach ($versions as $version) {
            $collection->push($this->mapVersion($version, $activeVersion));
        }
        return $collection;
    }

    /**
     * @param VersionModel $version
     *
     * @return VersionModel
     */
    public function mapVersion($version, $activeVersion = false)
    {
        return new VersionModel($version, $activeVersion ? ($version->getId() == $activeVersion) : false);
    }

    /**
     * {@inheritdoc}
     */
    public function setBaseConfig(array $config)
    {
        global $container;

        // Check configuration.
        if (!isset($config["source"])) {
            throw new \Exception("Missing entity table name.");
        }

        // fetch entity manager to register entity class loader
        $container['doctrine.orm.entityManager'];

        $this->tableName       = $config['source'];
        $this->entityClassName = ContaoDcaDriver::tableToClassName($this->tableName);
        $this->entityClass     = new \ReflectionClass($this->entityClassName);
        $this->entityAccessor  = $container['doctrine.orm.entityAccessor'];
    }

    /**
     * {@inheritdoc}
     */
    public function getEmptyConfig()
    {
        return DefaultConfig::init();
    }

    /**
     * {@inheritdoc}
     */
    public function getEmptyModel()
    {
        return new EntityModel($this->entityClass->newInstance());
    }

    /**
     * {@inheritdoc}
     */
    public function getEmptyCollection()
    {
        return new DefaultCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function getEmptyFilterOptionCollection()
    {
        return new DefaultFilterOptionCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function fetch(ConfigInterface $config)
    {
        if ($config->getId()) {
            $repository = $this->getEntityRepository();
            $entity     = $repository->find($config->getId());

            return $entity ? $this->mapEntity($entity) : null;
        }

        if ($config->getFilter()) {
            $config = clone $config;
            $config->setAmount(1);
            $collection = $this->fetchAll($config);

            if (count($collection)) {
                return $collection[0];
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll(ConfigInterface $config)
    {
        $entityRepository = $this->getEntityRepository();
        $entityManager    = $this->getEntityManager();
        $queryBuilder     = $entityManager->createQueryBuilder();

        $queryBuilder
            // TODO Where do I get the name of the primary key from?!
            ->select($config->getIdOnly() ? 'e.id' : 'e')
            ->from($entityRepository->getClassName(), 'e');

        if ($config->getFilter()) {
            $queryBuilder->where(
                $this->buildCondition(
                    $queryBuilder,
                    array('operation' => 'AND', 'children' => $config->getFilter())
                )
            );
        }

        if ($config->getSorting()) {
            $hiddenIndex = 0;

            foreach ($config->getSorting() as $sort => $order) {
                $orderUppercase = strtoupper($order);
                if ($order && $orderUppercase != 'ASC' && $orderUppercase != 'DESC') {
                    $queryBuilder->addSelect($order . ' AS HIDDEN _virtual_sorting_field_' . $hiddenIndex);
                    $sort  = '_virtual_sorting_field_' . $hiddenIndex;
                    $order = null;
                    $hiddenIndex++;
                } else {
                    $sort  = 'e.' . $sort;
                    $order = $orderUppercase;
                }

                $queryBuilder->addOrderBy($sort, $order);
            }
        }

        if ($config->getStart()) {
            $queryBuilder->setFirstResult($config->getStart());
        }

        if ($config->getAmount()) {
            $queryBuilder->setMaxResults($config->getAmount());
        }

        $query = $queryBuilder->getQuery();

        if ($config->getIdOnly()) {
            $entities = $query->getScalarResult();
        } else {
            $entities = $query->getResult();
            $entities = $this->mapEntities($entities);
        }

        return $entities;
    }

    protected function buildCondition(QueryBuilder $queryBuilder, $condition, &$parameterIndex = 1)
    {
        switch ($condition['operation']) {
            case 'AND':
            case 'OR':
                $parts = array();
                foreach ($condition['children'] as $arrChild) {
                    $parts[] = $this->buildCondition($queryBuilder, $arrChild, $parameterIndex);
                }

                if ($condition['operation'] == 'AND') {
                    return call_user_func_array(
                        array($queryBuilder->expr(), 'andX'),
                        $parts
                    );
                } else {
                    return call_user_func_array(
                        array($queryBuilder->expr(), 'orX'),
                        $parts
                    );
                }
                break;

            case '=':
            case '>':
            case '>=':
            case '<':
            case '<=':
            case 'IN':
            case 'LIKE':
                $property = 'e.' . $condition['property'];

                if ($condition['value'] === null) {
                    return $queryBuilder
                        ->expr()
                        ->isNull($property);
                }
                if ($condition['operation'] == 'LIKE') {
                    $condition['value'] = str_replace('*', '%', $condition['value']);
                }

                $parameter = ':parameter' . ($parameterIndex++);
                $queryBuilder->setParameter($parameter, $condition['value']);

                switch ($condition['operation']) {
                    case '=':
                        return $queryBuilder
                            ->expr()
                            ->eq($property, $parameter);
                    case '>':
                        return $queryBuilder
                            ->expr()
                            ->gt($property, $parameter);
                    case '>=':
                        return $queryBuilder
                            ->expr()
                            ->gte($property, $parameter);
                    case '<':
                        return $queryBuilder
                            ->expr()
                            ->lt($property, $parameter);
                    case '<=':
                        return $queryBuilder
                            ->expr()
                            ->lte($property, $parameter);
                    case 'IN':
                        return $queryBuilder
                            ->expr()
                            ->in($property, $parameter);
                    case 'LIKE':
                        return $queryBuilder
                            ->expr()
                            ->like($property, $parameter);
                }
                break;

            default:
                throw new \Exception('Error processing filter array - unknown operation ' . var_export(
                    $condition,
                    true
                ), 1);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getCount(ConfigInterface $config)
    {
        $entityManager = $this->getEntityManager();
        return $entityManager
            ->createQueryBuilder()
            ->select('COUNT(e)')
            ->from($this->entityClassName, 'e')
            ->getQuery()
            ->getResult(Query::HYDRATE_SINGLE_SCALAR);
    }

    /**
     * {@inheritdoc}
     */
    public function save(ModelInterface $item, $recursive = false)
    {
        if (!$item instanceof EntityModel) {
            throw new \RuntimeException('The EntityDataProvider only support EntityModel\'s');
        }

        $entityManager = $this->getEntityManager();
        $entityManager->persist($item->getEntity());
        $entityManager->flush();
    }

    /**
     * {@inheritdoc}
     */
    public function saveEach(CollectionInterface $items, $recursive = false)
    {
        $entityManager = $this->getEntityManager();
        foreach ($items as $item) {
            if (!$item instanceof EntityModel) {
                throw new \RuntimeException('The EntityDataProvider only support EntityModel\'s');
            }

            $entityManager->persist($item->getEntity());
        }
        $entityManager->flush();
    }

    /**
     * {@inheritdoc}
     */
    public function delete($item)
    {
        if (!$item instanceof EntityModel) {
            throw new \RuntimeException('The EntityDataProvider only support EntityModel\'s');
        }

        $entityManager = $this->getEntityManager();

        if ($item instanceof EntityModel) {
            $entity = $item->getEntity();
        } else {
            $entity = $this
                ->getEntityRepository()
                ->find($item);
        }

        if ($entity) {
            $entityManager->remove($entity);
            $entityManager->flush($entity);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function saveVersion(ModelInterface $objModel, $strUsername)
    {
        // do nothing, the version manager do this in the flush event state by itself
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion($mixID, $mixVersion)
    {
        /** @var VersionManager $versionManager */
        /*
		$versionManager = $GLOBALS['container']['doctrine.orm.versionManager'];

		$entity = $versionManager->getEntityVersion($mixVersion);

		if ($entity) {
			return new EntityModel($entity);
		}
		*/

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function getVersions($mixID, $blnOnlyActive = false)
    {
        if ($mixID) {
            /** @var VersionManager $versionManager */
            $versionManager = $GLOBALS['container']['doctrine.orm.versionManager'];

            $entityRepository = $this->getEntityRepository();
            $entity           = $entityRepository->find($mixID);
            if ($entity) {
                $version  = $this->getActiveVersion($entity);
                $versions = $versionManager->findVersions($entity);

                return $this->mapVersions($versions, $version);
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function setVersionActive($mixID, $mixVersion)
    {
        // do nothing, the version manager cannot handle active versions
    }

    /**
     * {@inheritdoc}
     */
    public function getActiveVersion($mixID)
    {
        if ($mixID) {
            /** @var VersionManager $versionManager */
            $versionManager = $GLOBALS['container']['doctrine.orm.versionManager'];

            if (is_object($mixID)) {
                $entity = $mixID;
            } else {
                $entityRepository = $this->getEntityRepository();
                $entity           = $entityRepository->find($mixID);
            }
            $version = $versionManager->findVersion($entity);

            if ($version) {
                $entityAccessor = $this->getEntityAccessor();
                return $entityAccessor->getPrimaryKey($version);
            }
        }
        return null;
    }

    /**
     * Reste the fallback field
     *
     * Documentation:
     *      Evaluation - fallback => If true the field can only be assigned once per table.
     *
     * @return void
     */
    public function resetFallback($strField)
    {
        // TODO: Implement resetFallback() method.
        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function isUniqueValue($field, $value, $id = null)
    {
        $entityClass = $this->entityClass;

        if ($entityClass->isSubclassOf('Contao\Doctrine\ORM\EntityInterface')) {
            $keys = $entityClass
                ->getMethod('entityPrimaryKeyNames')
                ->invoke(null);
        } else {
            $keys = array('id');
        }

        $idValues = $id === null ? null : explode('|', $id);

        if ($idValues && count($keys) != count($idValues)) {
            throw new \RuntimeException('Key count of ' . count($keys) . ' does not match id values count ' . count(
                $idValues
            ));
        }

        $entityManager = $this->getEntityManager();
        $queryBuilder  = $entityManager->createQueryBuilder();
        $queryBuilder
            ->select('COUNT(e.' . $keys[0] . ')')
            ->from($this->entityClassName, 'e')
            ->where(
                $queryBuilder
                    ->expr()
                    ->eq('e.' . $field, ':value')
            )
            ->setParameter(':value', $value);
        if ($idValues) {
            foreach ($keys as $index => $key) {
                $queryBuilder
                    ->andWhere(
                        $queryBuilder
                            ->expr()
                            ->neq('e.' . $key, ':key' . $index)
                    )
                    ->setParameter(':key' . $index, $idValues[$index]);
            }
        }
        $query = $queryBuilder->getQuery();
        return !$query->getResult(Query::HYDRATE_SINGLE_SCALAR);
    }

    /**
     * {@inheritdoc}
     */
    public function fieldExists($strField)
    {
        return $this->entityClass->hasProperty($strField);
    }

    /**
     * {@inheritdoc}
     */
    public function sameModels($objModel1, $objModel2)
    {
        if ($objModel1 instanceof EntityModel &&
            $objModel2 instanceof EntityModel &&
            get_class($objModel1->getEntity()) == get_class($objModel2->getEntity())
        ) {
            $data1 = $this->entityAccessor->getRawProperties($objModel1);
            $data2 = $this->entityAccessor->getRawProperties($objModel2);

            foreach ($data1 as $key => $value) {
                if ($data2[$key] != $value) {
                    return false;
                }
            }

            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getFilterOptions(ConfigInterface $config)
    {
        $properties = $config->getFields();
        $property   = $properties[0];

        if (count($properties) <> 1) {
            throw new \Exception('Config must contain exactly one property to be retrieved.');
        }

        $entityManager = $this->getEntityManager();
        $queryBuilder  = $entityManager->createQueryBuilder();
        $values        = $queryBuilder
            ->select('DISTINCT e.' . $property)
            ->from($this->entityClassName, 'e')
            ->orderBy('e.' . $property)
            ->getQuery()
            ->getResult();

        $collection = new DefaultFilterOptionCollection();
        if ($values) {
            foreach ($values as $value) {
                $collection->add($value[$property], $value[$property]);
            }
        }

        return $collection;
    }
}

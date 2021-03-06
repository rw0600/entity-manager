<?php
namespace Boxspaced\EntityManager\Entity;

use Boxspaced\EntityManager\IdentityMap;
use Boxspaced\EntityManager\UnitOfWork;
use Boxspaced\EntityManager\Mapper\MapperFactory;
use Boxspaced\EntityManager\Collection\Collection;
use Boxspaced\EntityManager\Mapper\Query;
use Boxspaced\EntityManager\Exception;
use DateTime;

class EntityBuilder
{

    /**
     * @var IdentityMap
     */
    protected $identityMap;

    /**
     * @var UnitOfWork
     */
    protected $unitOfWork;

    /**
     * @var EntityFactory
     */
    protected $entityFactory;

    /**
     * @var MapperFactory
     */
    protected $mapperFactory;

    /**
     * @var array
     */
    protected $config;

    /**
     * @param IdentityMap $identityMap
     * @param UnitOfWork $unitOfWork
     * @param EntityFactory $entityFactory
     * @param MapperFactory $mapperFactory
     * @param array $config
     */
    public function __construct(
        IdentityMap $identityMap,
        UnitOfWork $unitOfWork,
        EntityFactory $entityFactory,
        MapperFactory $mapperFactory,
        array $config
    )
    {
        $this->identityMap = $identityMap;
        $this->unitOfWork = $unitOfWork;
        $this->entityFactory = $entityFactory;
        $this->mapperFactory = $mapperFactory;
        $this->config = $config;
    }

    /**
     * @param string
     * @param array $data
     * @return AbstractEntity
     * @throws Exception\UnexpectedValueException
     */
    public function build($type, array $data)
    {
        if (!$data) {
            throw new Exception\UnexpectedValueException(
                'Data array empty, use factories to create new entities'
            );
        }

        if (empty($data['id'])) {
            throw new Exception\UnexpectedValueException(
                "No 'id' field in data, use factories to create new entities"
            );
        }

        $existing = $this->identityMap->exists($type, $data['id']);

        if ($existing) {
            return $existing;
        }

        $entity = $this->createEntity($type, $data);

        $this->unitOfWork->clean($entity); // Data loading will have marked entity dirty
        $this->identityMap->add($entity);

        return $entity;
    }

    /**
     * @param string $type
     * @param array $data
     * @return AbstractEntity
     */
    protected function createEntity($type, array $data)
    {
        $entity = $this->entityFactory->create($type);

        $this->setEntityFields($entity, $data);
        $this->setEntityOneToMany($entity);

        return $entity;
    }

    /**
     * @param AbstractEntity $entity
     * @param array $data
     * @return Builder
     */
    protected function setEntityFields(AbstractEntity $entity, array $data)
    {
        $entityConfig = $this->getEntityConfig(get_class($entity));

        foreach (isset($entityConfig['fields']) ? $entityConfig['fields'] : [] as $field => $fieldConfig) {

            if (!isset($fieldConfig['type'])) {
                throw new Exception\InvalidArgumentException("Type config missing for field: {$field}");
            }

            if (!isset($data[$field])) {
                continue;
            }

            switch ($fieldConfig['type']) {

                case $entity::TYPE_STRING:
                    $entity->set($field, strval($data[$field]));
                    break;

                case $entity::TYPE_INT:
                    $entity->set($field, intval($data[$field]));
                    break;

                case $entity::TYPE_FLOAT:
                    $entity->set($field, floatval($data[$field]));
                    break;

                case $entity::TYPE_BOOL:
                    $entity->set($field, boolval($data[$field]));
                    break;

                case $entity::TYPE_DATETIME:
                    $entity->set($field, new DateTime($data[$field]));
                    break;

                default:
                    $reference = $this->getReference($fieldConfig['type'], $data[$field]);
                    $entity->set($field, $reference);
            }
        }

        return $this;
    }

    /**
     * @param string $type
     * @return array
     * @throws Exception\InvalidArgumentException
     */
    protected function getEntityConfig($type)
    {
        if (!isset($this->config['types'][$type]['entity'])) {
            throw new Exception\InvalidArgumentException("Entity config missing for type: {$type}");
        }

        return $this->config['types'][$type]['entity'];
    }

    /**
     * @param string $type
     * @param int $id
     * @return callable
     */
    protected function getReference($type, $id)
    {
        if (!$id) {
            return null;
        }

        $callback = function() use ($type, $id) {
            return $this->mapperFactory->createForType($type)->find($type, $id);
        };
        $callback->bindTo($this);

        return $callback;
    }

    /**
     * @param AbstractEntity $entity
     * @return Builder
     * @throws Exception\UnexpectedValueException
     */
    protected function setEntityOneToMany(AbstractEntity $entity)
    {
        $entityConfig = $this->getEntityConfig(get_class($entity));

        foreach (isset($entityConfig['one_to_many']) ? $entityConfig['one_to_many'] : [] as $field => $oneToManyConfig) {

            $query = $this->getOneToManyQuery($entity, $oneToManyConfig['type']);
            $collection = $this->getOneToManyCollection($oneToManyConfig['type'], $query);

            $entity->set($field, $collection);
        }

        return $this;
    }

    /**
     * @param AbstractEntity $entity
     * @param string $type
     * @return Query
     */
    protected function getOneToManyQuery(AbstractEntity $entity, $type)
    {
        $field = $this->findEntityFieldByFieldType($type, get_class($entity));

        if (null === $field) {

            throw new Exception\InvalidArgumentException(sprintf(
                "The 'one to many' query for: %s requires a 'many to one' field set on: %s",
                get_class($entity),
                $type
            ));
        }

        $queryField = sprintf('%s.id', $field);

        $manyMapperConfig = $this->getMapperConfig($type);

        if (isset($manyMapperConfig['params']['columns'][$field])) {
            $queryField = $manyMapperConfig['params']['columns'][$field];
        }

        return (new Query())->field($queryField)->eq($entity->get('id'));
    }

    /**
     * @param string $entityType
     * @param string $fieldType
     * @return string|null
     */
    protected function findEntityFieldByFieldType($entityType, $fieldType)
    {
        $entityConfig = $this->getEntityConfig($entityType);

        foreach ($entityConfig['fields'] as $field => $fieldConfig) {

            if ($fieldConfig['type'] === $fieldType) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @param string $type
     * @return array
     * @throws Exception\InvalidArgumentException
     */
    protected function getMapperConfig($type)
    {
        if (!isset($this->config['types'][$type]['mapper'])) {
            throw new Exception\InvalidArgumentException("Mapper config missing for type: {$type}");
        }

        return $this->config['types'][$type]['mapper'];
    }

    /**
     * @param string $type
     * @param Query $query
     * @return Collection
     */
    protected function getOneToManyCollection($type, Query $query = null)
    {
        return $this->mapperFactory->createForType($type)->findAll($type, $query);
    }

}

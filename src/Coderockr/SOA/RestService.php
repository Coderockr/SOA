<?php

namespace Coderockr\SOA;

use Doctrine\ORM\Query\Expr\Join;

class RestService
{
    private $em;
    private $entityNamespace;

    public function setEntityManager($em)
    {
        $this->em = $em;
    }

    public function setEntityNamespace($entityNamespace)
    {
        $this->entityNamespace = $entityNamespace;
    }

	private function getEntityName($entity)
    {
        return $this->entityNamespace . '\\' . ucfirst($entity);
    }

	public function find($entity, $id, $token = null)
    {
        $data = $this->em
                     ->getRepository($this->getEntityName($entity))
                     ->find($id);

        return $data;
    }

    public function findAll($entity, $fields, $joins, $limit, $offset, $filter, $sort, $count, $token = null)
    {
        $queryBuilder = $this->em->createQueryBuilder();
        $queryBuilder->from($this->getEntityName($entity), 'e');

        if ($joins) {
            foreach ($joins as $j) {
                
                $join = explode(':', $j);

                $entityName = 'j'.$join[0];
                $conditionField = $join[1];
                $conditionOp = $join[2];
                $conditionValue = $join[3];

                if ($conditionOp == 'like') {
                    $conditionValue = '%'.$conditionValue.'%';
                }

                $queryBuilder->select($entityName);
                $queryBuilder->innerJoin(
                    'e.'.$join[0], 
                    $entityName, 
                    Join::WITH, 
                    $queryBuilder->expr()->$conditionOp($entityName.'.'.$conditionField, "'".$conditionValue."'"));
            }
        }

        if ($sort) {
            
            $sort = explode(':', $sort);
            if ($sort > 1) {

                $prop = $sort[0];
                if (strpos($prop, '.') === false) {
                    $prop = 'e.' . $prop;
                }
                $queryBuilder->orderBy($prop, $sort[1]);
            }
        }

        if ($limit) {
            $queryBuilder->setMaxResults($limit);
        }

        if ($offset) {
            $queryBuilder->setFirstResult($offset);
        }

        if ($filter) {
            foreach ($filter as $f) {
                $param = explode(":", $f);
                
                $conditionField = $param[0];
                $conditionOp = $param[1];
                $conditionValue = $param[2];

                if ($conditionOp == 'like') {
                    $conditionValue = '%'.$conditionValue.'%';
                }

                $queryBuilder->andWhere($queryBuilder->expr()->$conditionOp('e.' . $conditionField, "'" . $conditionValue . "'"));
            }
        }
        
        $select = 'e';
        if (count($fields) > 0) {
            $select .= '.' . implode(',e.', $fields);
        }
        if ($count == 1) {
            $select = 'count(e.id) recordCount';
        }

        $queryBuilder->select($select);
        $data = $queryBuilder->getQuery()->getResult();

        return $data;
    }   

    public function create($request, $entity, $token = null)
    {
        $entityName = $this->getEntityName($entity);
        $entity = new $entityName;

        $data = $request->request->all();
        $this->em->persist($this->setData($entity, $data));
        $this->em->flush();

        return $entity;
    }

    private function setData($entity, $data) 
    {
        $class = new \ReflectionClass($entity);
        foreach ($data as $name => $value) {
            if (is_array($value)) { //it's a relationship to another entity
                $id = $value['id'];
                $relatedEntity = $this->getEntityName($name);
                if (isset($value['entityName'])) 
                    $relatedEntity = $this->getEntityName($value['entityName']);
                
                $value = $this->em->find($relatedEntity, $id);
            }
            $method = 'set'. ucfirst($name);
            if ($class->hasMethod($method)) {
               call_user_func(array($entity, $method), $value); 
            }
        }

        return $entity;
    }

    public function update($request, $entity, $id, $token = null)
    {
        $entityName = $this->getEntityName($entity);
        $entity = $this->find($entity, $id);
        if (!$entity) {
            return false;
        }

        $data = $request->request->all();
        $entity = $this->setData($entity, $data);
        $entity->setUpdated(date('Y-m-d H:i:s'));

        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }

    public function delete($request, $entity, $id, $token = null)
    {
        $data = $this->em
                     ->getRepository($this->entityNamespace . '\\' . ucfirst($entity))
                     ->find($id);
        if (!$data) {
            return false;
        }

        $this->em->remove($data);
        $this->em->flush();

        return true;
    }
}

<?php
namespace Boxspaced\EntityManager\Test;

use Boxspaced\EntityManager\Entity\AbstractEntity;

class EntityDouble extends AbstractEntity
{

    private static $counter = 0;

    public function __construct(array $config = null)
    {
        $this->config = $config ?: [
            'fields' => [
                'id' => [
                    'type' => static::TYPE_INT,
                ],
                'title' => [
                    'type' => static::TYPE_STRING,
                ],
                'fname' => [
                    'type' => static::TYPE_STRING,
                ],
                'lname' => [
                    'type' => static::TYPE_STRING,
                ],
            ]
        ];

        $this->unitOfWork = new UnitOfWorkDouble();

        $this->set('id', self::$counter++);
    }

    public function getId()
    {
        return $this->get('id');
    }

    public function setId($id)
    {
        return $this->set('id', $id);
    }

    public function getTitle()
    {
        return $this->get('title');
    }

    public function setTitle($title)
    {
        return $this->set('title', $title);
    }

    public function getFname()
    {
        return $this->get('fname');
    }

    public function setFname($fname)
    {
        return $this->set('fname', $fname);
    }

    public function getLname()
    {
        return $this->get('lname');
    }

    public function setLname($lname)
    {
        return $this->set('lname', $lname);
    }

}

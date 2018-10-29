<?php

namespace Minds\Core\Helpdesk\Category;

use Minds\Core\Di\Di;
use Minds\Core\Helpdesk\Entities\Category;
use Minds\Core\Util\UUIDGenerator;
use Minds\Controllers\api\v2\notifications\follow;

class Repository
{
    /** @var \PDO */
    protected $db;

    public function __construct(\PDO $db = null)
    {
        $this->db = $db ?: Di::_()->get('Database\PDO');
    }

    /**
     * @param array $opts
     * @return Category[]
     */
    public function getAll(array $opts = [])
    {
        $opts = array_merge([
            'limit' => 10,
            'offset' => 0,
            'uuid' => '',
        ], $opts);

        $query = "SELECT * FROM helpdesk_categories as cats1";

        $where = [];
        $values = [];

        if ($opts['uuid']) {
            $where[] = 'cats1.uuid = ?';
            $values[] = $opts['uuid'];
        }

        if (count($where) > 0) {
            $query .= ' WHERE ' . implode('AND', $where);
        }

        $statement = $this->db->prepare($query);

        $statement->execute($values);

        $data = $statement->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];

        foreach ($data as $row) {
            $category = new Category();
            $category->setUuid($row['uuid'])
                ->setTitle($row['title'])
                ->setParentUuid($row['parent'])
                ->setBranch($row['branch']);

            $result[] = $category;
        }

        return $result;
    }

    /**
     * Get ona category by uuid
     *
     * @param string $uuid
     * @return Category
     */
    public function getOne($uuid)
    {
        $query = "SELECT * FROM helpdesk_categories WHERE uuid = ?";

        $statement = $this->db->prepare($query);
        $statement->execute([$uuid]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if (!$row) return null;

        $category = new Category();
        $category->setUuid($row['uuid'])
            ->setTitle($row['title'])
            ->setParentUuid($row['parent'])
            ->setBranch($row['branch']);

        return $category;
    }

    /**
     * Get the categories branch given an uuid
     *
     * @param string $uuid
     * @return Catergory
     */
    public function getBranch($uuid) {
        $leaf = $this->getOne($uuid);

        if (!$leaf) return null;

        $branch = explode(':', $leaf->getBranch());
        array_pop($branch);

        $child = $leaf;
        foreach (array_reverse($branch) as $parent_uuid) {
            $parent = $this->getOne($parent_uuid);
            $child->setParent($parent);
            $child = $parent;
        }

        return $leaf;
    }

    public function add(Category $category)
    {
        $query = "INSERT INTO helpdesk_categories(uuid, title, parent, branch) VALUES (?,?,?,?)";
        $uuid = UUIDGenerator::generate();

        // we need to do this as cockroachdb doesn't yet support triggers
        $parent = $category->getParent();
        if (!$parent && $category->getParentUuid() !== null) {
            $parent = $this->getAll(['uuid' => $category->getParentUuid()])[0];
        }
        $values = [
            $uuid,
            $category->getTitle(),
            $category->getParentUuid(),
            $parent && $parent->getBranch() ? $parent->getBranch() . ':' . $uuid : $uuid
        ];

        $statement = $this->db->prepare($query);

        if (!$statement->execute($values)) {
            return false;
        }

        return $uuid;
    }
}
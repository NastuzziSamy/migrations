<?php
/**
 * Handle migrations.
 *
 * @author Samy Nastuzzi <samy@nastuzzi.fr>
 *
 * @copyright Copyright (c) 2019
 * @license MIT
 */

namespace Laramore;

use Illuminate\Filesystem\Filesystem;
use Laramore\Facades\MetaManager;
use Laramore\Fields\Foreign;
use Laramore\Migrations\{
    Command, Contraint, DatabaseNode, MetaNode, Node, Index
};
use Illuminate\Support\Str;
use DB;

class MigrationManager
{
    protected $path;
    protected $counter;
    protected $actualNode;
    protected $wantedNode;
    protected $missingNode;

    public function __construct()
    {
        $this->path = base_path('database'.DIRECTORY_SEPARATOR.'migrations');
        $this->counter = count(app('migrator')->getMigrationFiles($this->path));
    }

    protected function getNodesFromMeta(Meta $meta)
    {
        $nodes = [];
        $tableName = $meta->getTableName();

        foreach ($meta->getFields() as $field) {
            $nodes[] = new Command($tableName, $field->type, $field->attname, $field->getProperties());
        }

        foreach ($meta->getComposites() as $composite) {
            if ($composite instanceof Foreign) {
                $needs = [
                    [
                        'table' => $toTableName = $composite->on::getMeta()->getTableName(),
                        'field' => $composite->to,
                    ],
                    [
                        'table' => $tableName,
                        'field' => $attname = $composite->from,
                    ],
                ];

                $properties = [
                    'references' => $composite->to,
                    'on' => $toTableName
                ];

                $nodes[] = new Contraint($tableName, $attname, $needs, $properties);
            }
        }

        if (is_array($primaries = $meta->getPrimary())) {
            $nodes[] = new Index($tableName, 'primary', array_map(function ($field) {
                return $field->attname;
            }, $primaries));
        }

        foreach ($meta->getUniques() as $unique) {
            $nodes[] = new Index($tableName, 'unique', array_map(function ($field) {
                return $field->attname;
            }, $unique));
        }

        foreach ($meta->getIndexes() as $index) {
            $nodes[] = new Index($tableName, 'index', array_map(function ($field) {
                return $field->attname;
            }, $index));
        }

        return $nodes;
    }

    protected function loadWantedNode()
    {
        $wantedNode = [];

        foreach (MetaManager::getMetas() as $meta) {
            $nodes = $this->getNodesFromMeta($meta);

            if (count($nodes)) {
                $wantedNode[] = new MetaNode($nodes, $meta);
            }
        }

        $this->wantedNode = new Node($wantedNode);
        $this->wantedNode->organize()->optimize();
    }

    protected function loadActualNode()
    {
        $doctrine = DB::connection()->getDoctrineSchemaManager();
        $unvalidTable = config('database.migrations');
        $actualNode = [];

        foreach ($doctrine->listTables() as $table) {
            if ($table->getName() !== $unvalidTable) {
                $actualNode[] = new DatabaseNode($table);
            }
        }

        $this->actualNode = new Node($actualNode);
        $this->actualNode->organize()->optimize();
    }

    protected function loadMissingNode()
    {
        $this->missingNode = $this->getWantedNode()->diff($this->getActualNode());
    }

    public function getWantedNode()
    {
        if (is_null($this->wantedNode)) {
            $this->loadWantedNode();
        }

        return $this->wantedNode;
    }

    public function getActualNode()
    {
        if (is_null($this->actualNode)) {
            $this->loadActualNode();
        }

        return $this->actualNode;
    }

    public function getMissingNode()
    {
        if (is_null($this->missingNode)) {
            $this->loadMissingNode();
        }

        return $this->missingNode;
    }

    protected function getFieldsFromNodes(array $nodes)
    {
        $table = [];

        foreach ($nodes as $tableName => $node) {
            $table[$tableName] = $this->getFieldsFromNode($node);
        }

        return $table;
    }

    public function getDefinedFields()
    {
        return $this->getFieldsFromNodes($this->getWantedNode());
    }

    public function getDatabaseFields()
    {
        return $this->getFieldsFromNodes($this->getActualNode());
    }

    protected function generateMigrationFile($viewName, $data, $path)
    {
        file_put_contents($path, view($viewName, array_merge([
            'php' => '<?php',
            'blueprintVar' => '$table',
        ], $data))->render());
    }

    protected function getCounter()
    {
        $counter = (string) $this->counter++;

        for ($i = (6 - strlen($counter)); $i > 0; $i--) {
            $counter = '0'.$counter;
        }

        return $counter;
    }

    protected function generateMigration(MetaNode $metaNode)
    {
        $meta = $metaNode->getMeta();
        $type = $metaNode->getType();

        $data = [
            'date' => now(),
            'model' => $meta->getModelClass(),
            'type' => $type,
            'table' => $table = $meta->getTableName(),
            'up' => $metaNode->getUp(),
            'down' => $metaNode->getDown(),
            'name' => $name = ucfirst($type).ucfirst($table).'Table',
        ];

        $fileName = date('Y_m_d_').$this->getCounter().'_'.Str::snake($name);

        $this->generateMigrationFile('laramore::migration', $data, $this->path.DIRECTORY_SEPARATOR.$fileName.'.php');

        return $fileName;
    }

    public function clearMigrations()
    {
        (new Filesystem)->cleanDirectory($this->path);
    }

    public function generateMigrations()
    {
        $generatedFiles = [];

        foreach ($this->getMissingNode()->getNodes() as $node) {
            $generatedFiles[] = $this->generateMigration($node);
        }

        return $generatedFiles;
    }
}
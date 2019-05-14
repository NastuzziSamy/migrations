<?php
/**
 * Correspond to a migration node from a database table.
 *
 * @author Samy Nastuzzi <samy@nastuzzi.fr>
 *
 * @copyright Copyright (c) 2019
 * @license MIT
 */

namespace Laramore\Migrations;

use Illuminate\Support\Facades\Schema;
use Doctrine\DBAL\Schema\{
    Table, Column, ForeignKeyConstraint, Index
};

class DatabaseNode extends AbstractNode
{
    protected $nodes = [];
    protected $contraints = [];
    protected $organized = true;
    protected $optimized = true;

    public function __construct(Table $table)
    {
        $this->tableNames = [$table->getName()];

        $this->setNodes(array_merge($table->getColumns(), $table->getForeignKeys()));
        $this->addIndexes($table->getIndexes());
    }

    protected function setNodes(array $nodes)
    {
        $this->nodes = array_map(function ($node) {
            if ($node instanceof Column) {
                return $this->columnToCommand($node);
            } else if ($node instanceof ForeignKeyConstraint) {
                return $this->foreignKeyToContraint($node);
            } else {
                throw new \Exception('A Database node works only with Doctrine columns and foreign key contraints');
            }

            return $node;
        }, array_values($nodes));
    }

    protected function setIndexCommand(Command $command, Index $index)
    {
        if ($index->isPrimary()) {
            foreach ($command->getProperties() as $key => $value) {
                if ($value === $command->getAttname()) {
                    if ($key === 'increments') {
                        return;
                    } else {
                        $command->addProperty('primary', true);
                    }
                }
            }
        }

        if ($index->isUnique()) {
            $command->addProperty('unique', true);
        }
    }

    protected function addIndexes(array $indexes)
    {
        foreach ($indexes as $index) {
            $attname = $index->getColumns()[0];

            foreach ($this->getNodes() as $node) {
                if ($node instanceof Command && $node->getAttname() === $attname) {
                    $this->setIndexCommand($node, $index);
                    break;
                }
            }
        }
    }

    protected function getTypeFromColumn(Column $column)
    {
        $type = $column->getType()->getName();

        if ($type === 'integer') {
            if ($column->getAutoincrement()) {
                return 'increments';
            } else if ($column->getUnsigned()) {
                $type = 'unsigned'.ucfirst($type);
            }
        }

        return $type;
    }

    protected function getPropertiesFromColumn(Column $column, string $type)
    {
        $properties = [];

        if ($length = $column->getLength()) {
            if ($length !== Schema::getFacadeRoot()::$defaultStringLength) {
                $properties['length'] = $length;
            }
        }

        if (!$column->getNotnull()) {
            $properties['nullable'] = true;
        }

        if ($default = $column->getDefault()) {
            if ($type === 'datetime') {
                $properties['useCurrent'] = true;
            } else {
                $properties['default'] = $default;
            }
        }

        return $properties;
    }

    public function columnToCommand(Column $column)
    {
        $properties = array_merge([
            ($type = $this->getTypeFromColumn($column)) => $column->getName(),
        ], $this->getPropertiesFromColumn($column, $type));

        return new Command($this->getTableName(), $column->getName(), $properties);
    }

    protected function getNeedsFromForeignKey(ForeignKeyConstraint $foreignKeyContraint)
    {
        return [
            [
                'table' => $foreignKeyContraint->getForeignTableName(),
                'field' => $foreignKeyContraint->getForeignColumns()[0],
            ],
            [
                'table' => $foreignKeyContraint->getLocalTableName(),
                'field' => $foreignKeyContraint->getLocalColumns()[0],
            ]
        ];
    }

    protected function getPropertiesFromForeignKey(ForeignKeyConstraint $foreignKeyContraint)
    {
        $properties = [
            'foreign' => $foreignKeyContraint->getLocalColumns()[0],
            'references' => $foreignKeyContraint->getForeignColumns()[0],
            'on' => $foreignKeyContraint->getForeignTableName(),
        ];

        return $properties;
    }

    public function foreignKeyToContraint(ForeignKeyConstraint $foreignKeyContraint)
    {
        $needs = $this->getNeedsFromForeignKey($foreignKeyContraint);
        $properties = $this->getPropertiesFromForeignKey($foreignKeyContraint);

        return new Contraint($foreignKeyContraint->getLocalTableName(), $foreignKeyContraint->getLocalColumns()[0], $needs, $properties);
    }

    public function getFieldNodes(): array
    {
        return $this->nodes;
    }

    public function getContraintNodes(): array
    {
        return $this->contraints;
    }

    public function getMeta(): Meta
    {
        return $this->tableMetas[$this->getTableName()];
    }

    public function getTableName(): string
    {
        return $this->tableNames[0];
    }

    public function getType(): string
    {
        return $this->type;
    }

    protected function organizing()
    {
        // Cannot organize a DatabaseNode.
    }

    protected function optimizing()
    {
        // Cannot optimize a DatabaseNode.
    }
}
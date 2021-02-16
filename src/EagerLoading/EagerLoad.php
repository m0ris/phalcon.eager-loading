<?php namespace Sb\Framework\Mvc\Model\EagerLoading;

use Phalcon\Mvc\Model\Relation,
	Phalcon\Mvc\Model\Resultset;

/**
 * Represents a level in the relations tree to be eagerly loaded
 */
final class EagerLoad {
	/** @var RelationInterface */
    private $relation;
    /** @var null|callable */
    private $constraints;
    /** @var Loader|EagerLoad */
    private $parent;
    /** @var null|Phalcon\Mvc\ModelInterface[] */
    private $subject;
    /** @var boolean */
    static private $isPhalcon2;

    /**
     * @param RelationInterface
     * @param null|callable $constraints
     * @param Loader|EagerLoad $parent
     */
	public function __construct(Relation $relation, callable $constraints = NULL, $parent) {
        if (static::$isPhalcon2 === NULL) {
            static::$isPhalcon2 = version_compare(\Phalcon\Version::get(), '2.0.0') >= 0;
        }

		$this->relation    = $relation;
        $this->constraints = $constraints;
		$this->parent      = $parent;
    }

    /**
     * @return null|Phalcon\Mvc\ModelInterface[]
     */
	public function getSubject() {
        return $this->subject;
    }

    /**
     * Executes each db query needed
     *
     * Note: The {$alias} property is set two times because Phalcon Model ignores
     * empty arrays when overloading property set.
     *
     * Also {@see https://github.com/stibiumz/phalcon.eager-loading/issues/1}
     *
     * @return $this
     */
	public function load($options = null) {
        if (empty ($this->parent->getSubject())) {
            return $this;
        }

        $relation = $this->relation;

        $aliasOrig            = $relation->getOptions()['alias'];
        $alias                = strtolower($aliasOrig);
		$relField             = $relation->getFields();
        $relParams            = $relation->getParams();
		$relReferencedModel   = $relation->getReferencedModel();
		$relReferencedField   = $relation->getReferencedFields();
		$relIrModel           = $relation->getIntermediateModel();
		$relIrField           = $relation->getIntermediateFields();
        $relIrReferencedField = $relation->getIntermediateReferencedFields();

        // PHQL has problems with this slash
        if ($relReferencedModel[0] === '\\') {
            $relReferencedModel = ltrim($relReferencedModel, '\\');
        }

        $bindValues = [];

        foreach ($this->parent->getSubject() as $record) {
            $bindValues[$record->readAttribute($relField)] = TRUE;
        }

        $bindValues = array_keys($bindValues);

		$subjectSize         = count($this->parent->getSubject());
        $isManyToManyForMany = FALSE;

        $builder = new QueryBuilder;
        $builder->from($relReferencedModel);
        if (isset($relParams['order'])) {
            $builder->orderBy($relParams['order']);
        }

        if ($isThrough = $relation->isThrough()) {
            if ($subjectSize === 1) {
                // The query is for a single model
                $builder
                    ->innerJoin(
                        $relIrModel,
                        sprintf(
                            '[%s].[%s] = [%s].[%s]',
                            $relIrModel,
                            $relIrReferencedField,
                            $relReferencedModel,
                            $relReferencedField
                        )
                    )
					->inWhere("[{$relIrModel}].[{$relIrField}]", $bindValues)
				;
			}
			else {
                // The query is for many models, so it's needed to execute an
                // extra query
                $isManyToManyForMany = TRUE;

                $relIrValues = (new QueryBuilder)
                    ->from($relIrModel)
                    ->inWhere("[{$relIrModel}].[{$relIrField}]", $bindValues)
                    ->getQuery()
                    ->execute()
					->setHydrateMode(Resultset::HYDRATE_ARRAYS)
				;

                $bindValues = $modelReferencedModelValues = [];

                foreach ($relIrValues as $row) {
                    $bindValues[$row[$relIrReferencedField]] = TRUE;
                    $modelReferencedModelValues[$row[$relIrField]][$row[$relIrReferencedField]] = TRUE;
                }

                unset ($relIrValues, $row);

                $builder->inWhere("[{$relReferencedField}]", array_keys($bindValues));
            }
		}
		else {
            $builder->inWhere("[{$relReferencedField}]", $bindValues);
        }

        if ($this->constraints) {
            call_user_func($this->constraints, $builder);
        }

        // realization any options
        if (isset($options['softDelete'])) {
            $softDeleteName = $options['softDelete']['name'];
            $softDeleteValue = $options['softDelete']['value'];

            $model = new $relReferencedModel();
            $metadata =  $model->getModelsMetaData();
            $attributes = $metadata->getAttributes($model);
            if (in_array($softDeleteName, $attributes)) {
                if ($softDeleteValue === null) {
                    $builder->andWhere("$softDeleteName IS NULL");
                } elseif (is_scalar($softDeleteValue)) {
                    $builder->andWhere("$softDeleteName = :value:", ['value' => $softDeleteValue]);
                } elseif (is_array($softDeleteValue)) {
                    $builder->inWhere($softDeleteName, $softDeleteValue);
                } else {
                    throw new \Exception('Value of soft delete can be only NULL, scalar or array');
                }
            }
        }

        $records = [];

        if ($isManyToManyForMany) {
            foreach ($builder->getQuery()->execute() as $record) {
                $records[$record->readAttribute($relReferencedField)] = $record;
            }

            foreach ($this->parent->getSubject() as $record) {
                $referencedFieldValue = $record->readAttribute($relField);

                if (isset ($modelReferencedModelValues[$referencedFieldValue])) {
                    $referencedModels = [];

                    foreach ($modelReferencedModelValues[$referencedFieldValue] as $idx => $_) {
                        $referencedModels[] = $records[$idx];
                    }

                    if (static::$isPhalcon2) {
                        $record->{$aliasOrig} = NULL;
                    }
                    $record->{$aliasOrig} = $referencedModels;
                } else {
                    $record->{$aliasOrig} = NULL;
                    $record->{$aliasOrig} = [];
                }
            }

            $records = array_values($records);
		}
		else {
            // We expect a single object or a set of it
			$isSingle = ! $isThrough && (
                    $relation->getType() === Relation::HAS_ONE ||
                    $relation->getType() === Relation::BELONGS_TO
                );

            if ($subjectSize === 1) {
                // Keep all records in memory
                foreach ($builder->getQuery()->execute() as $record) {
                    $records[] = $record;
                }

                if ($isSingle) {
                    $this->parent->getSubject()[0]->{$aliasOrig} = empty ($records) ? NULL : $records[0];
                } else {
                    $record = $this->parent->getSubject()[0];

                    if (empty ($records)) {
                        $record->{$aliasOrig} = NULL;
                        $record->{$aliasOrig} = [];
                    } else {
                        $record->{$aliasOrig} = $records;

                        if (static::$isPhalcon2) {
                            $record->{$aliasOrig} = NULL;
                            $record->{$aliasOrig} = $records;
                        }
                    }
                }
			}
			else {
                $indexedRecords = [];

                // Keep all records in memory
                foreach ($builder->getQuery()->execute() as $record) {
                    $records[] = $record;

                    if ($isSingle) {
                        $indexedRecords[$record->readAttribute($relReferencedField)] = $record;
					}
					else {
                        $indexedRecords[$record->readAttribute($relReferencedField)][] = $record;
                    }
                }

                foreach ($this->parent->getSubject() as $record) {
                    $referencedFieldValue = $record->readAttribute($relField);

                    if (isset ($indexedRecords[$referencedFieldValue])) {
                        if (static::$isPhalcon2) {
                            $record->{$aliasOrig} = NULL;
                        }
                        if (is_array($indexedRecords[$referencedFieldValue])) {
                            $record->{$aliasOrig} = $indexedRecords[$referencedFieldValue];
                        } else {
                            $record->{$aliasOrig} = $indexedRecords[$referencedFieldValue];
                        }
                    } else {
                        $record->{$aliasOrig} = NULL;

                        if (!$isSingle) {
                            $record->{$aliasOrig} = [];
                        }
                    }
                }
            }
        }

        $this->subject = $records;

        return $this;
    }
}

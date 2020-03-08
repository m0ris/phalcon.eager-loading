<?php namespace Sb\Framework\Mvc\Model\EagerLoading;

use Phalcon\Mvc\Model\Query\Builder;
use Phalcon\Mvc\Model\Query\BuilderInterface;

final class QueryBuilder extends Builder {
	const E_NOT_ALLOWED_METHOD_CALL = 'When eager loading relations queries must return full entities';
	
	public function distinct($distinct): BuilderInterface {
		throw new \LogicException(static::E_NOT_ALLOWED_METHOD_CALL);
	}

	public function columns($columns): BuilderInterface {
		throw new \LogicException(static::E_NOT_ALLOWED_METHOD_CALL);
	}
}

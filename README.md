This package provides eager-loading support for Phalcon 4.\*, requires PHP 7.2.

For Phalcon 1.3.* - 2.0.* use phalcon_1-2 branch.

Installation
-----

```bash
composer require wojtek77/phalcon.eager-loading:dev-master
```

Usage
-----

The usage is similar to Laravel, I've implemented in a trait `with` and `load` methods, so within a model that uses that trait (`Sb\Framework\Mvc\Model\EagerLoadingTrait`) you can do:

```php
<?php
use Sb\Framework\Mvc\Model\EagerLoading\Loader,
	Sb\Framework\Mvc\Model\EagerLoading\QueryBuilder;

$robotsAndParts = Robot::with('Parts');

// Equivalent to:

$robots = Robot::find();
foreach ($robots as $robot) {
	$robot->parts; // $robot->__get('parts')
}

// Or

$robot = Robot::findFirst()->load('Parts');

// Equivalent to:

$robot = Robot::findFirst();
$robots->parts; // $robot->__get('parts')

// Because Robot::find() returns a resultset, so in that case this is solved with:
$robots = Loader::fromResultset(Robot::find(), 'Parts'); # Equivalent to the second example

// Multiple and nested relations can be used too
$robots = Robot::with('Parts', 'Foo.Bar');

// And arguments can be passed to the find method
$robots = Robot::with('Parts', 'Foo.Bar', ['limit' => 5]);

// And constraints
$robots = Robot::with(
	[
		'Parts',
		'Foo.Bar' => function (QueryBuilder $builder) {
			// Limit Bar
			$builder->limit(5);
		}
	],
	[
		'limit' => 5
	]
);

// constraints with the Loader too
$robots = Loader::fromResultset(Robot::find(), [
        'Foo.Bar' => function (QueryBuilder $builder) {
			$builder->where('Bar.id > 10'); 
	             }
]); 

```

The usage of soft delete:
```php
<?php

use \Sb\Framework\Mvc\Model\EagerLoadingTrait;

trait MyEagerLoadingTrait
{
    // Name of field which is for "soft delete"
    private $__eagerLoadingSoftDeleteName = 'delete_date';
    // Value(s) of field which is for no "soft delete"
    private $__eagerLoadingSoftDeleteValue = null;

    use EagerLoadingTrait;
}
```
```php
<?php

$robot = Robot::findFirst()->loadWithoutSoftDelete('Parts');
```

For more examples, return types etc visit the tests folder or take a look at the code, it's quite small.

License
-------
[The Unlicense](http://unlicense.org/)

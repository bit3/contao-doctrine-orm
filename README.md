Doctrine ORM Bridge
===================

This extension provide [Doctrine ORM](http://www.doctrine-project.org) in the [Contao Open Source CMS](http://contao.org).
It provide an entity manager via the service `$container['doctrine.orm.entityManager']`.
To use the Doctrine Connection within the Contao Database Framework, use [bit3/contao-doctrine-dbal-driver](https://github.com/bit3/contao-doctrine-dbal-driver).

Entity mapping
--------------

To register an entity table, add to your **config.php**:
```php
$GLOBALS['DOCTRINE_ENTITIES'][] = 'tl_my_entity_type';
```

The table name will be converted to `MyEntityType`.

Custom Namespaces can be mapped by a *table name prefix* to *class namespace* map:
```php
$GLOBALS['DOCTRINE_ENTITY_NAMESPACE_MAP']['tl_my_entity'] = 'My\Entity';
```

Now the table name will be converted to `My\Entity\Type`.

Configure entities via DCA
--------------------------

```php
<?php

$GLOBALS['TL_DCA']['...'] = array(
	'entity' => array(
		// (optional) Repository class name
		'repositoryClass' => 'MyEntityRepositoryClassName',
		// (optional) ID generator type (AUTO, IDENTITY, UUID)
		'idGenerator'     => 'UUID',
	),
	'fields' => array(
		'...' => array(
			'field' => array(
				'type' => (string),
				// do not set fieldName!
				'
			),
		),
	),
);
```

Contao hooks
------------

`$GLOBALS['TL_HOOK']['prepareDoctrineEntityManager'] = function(\Doctrine\ORM\Configuration &$config) { ... }`
Called before the entity manager will be created.

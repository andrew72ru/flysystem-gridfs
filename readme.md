# League\Flysystem\GridFS [BETA]

This is a Flysystem adapter for the MongoDB's GridFS.

## Installation

Add to composer

```json
… 
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/andrew72ru/flysystem-gridfs.git"
  }
]
```

than run `composer update`

# Bootstrap

``` php
<?php
use andrew72ru\Flysystem\GridFS\GridFSAdapter;
use MongoDB\Driver\Manager;
use MongoDB\GridFS\Bucket;

use League\Flysystem\Filesystem;

include __DIR__ . '/vendor/autoload.php';

$manager = new Manager('mongodb://localhost:27017');
$bucket = new Bucket($this->manager, 'files-database');

$adapter = new GridFSAdapter($bucket);
```

<?php


use League\Flysystem\Config;
use andrew72ru\Flysystem\GridFS\GridFSAdapter;
use MongoDB\Driver\Manager;
use MongoDB\GridFS\Bucket;

class GridFSTest extends \Codeception\Test\Unit
{
    const FILE_CREATED_AT   = 1483228800;

    protected static $isMongoExtensionInstalled = false;

    /**
     * @var \UnitTester
     */
    protected $tester;

    /** @var Manager */
    protected $manager;
    /** @var Bucket bucket */
    protected $bucket;
    protected $database = 'gridfs-test';
    protected $db;
    protected $source;

    protected function _before()
    {
        self::$isMongoExtensionInstalled = class_exists('MongoRegex');

        if (!self::$isMongoExtensionInstalled) {
            eval('class MongoRegex {}');
            eval('class MongoGridFSException extends RuntimeException {}');
        }

        $this->manager = new Manager('mongodb://localhost:27017');
        $this->bucket = new Bucket($this->manager, $this->database);
        $this->source = codecept_data_dir() . '/dump.sql';
    }

    protected function _after()
    {
//        $this->bucket->drop();
    }

    /**
     * @return Bucket
     */
    protected function getClient()
    {
        return $this->bucket;
    }

    /**
     * @param array $data
     * @param string $filename
     * @return mixed
     */
    protected function getMongoFile(array $data = [], $filename = 'somefile.sql')
    {
        $resource = fopen($this->source, 'r');

        $exist = $this->bucket->findOne(['filename' => $filename]);
        if($exist !== null && array_key_exists('_id', $exist))
        {
            $this->bucket->delete($exist['_id']);
        }

        $file = $this->bucket->uploadFromStream($filename, $resource, array_merge([
        ], $data));

        return $file;
    }

    public function testBucket()
    {
        $adapter = new GridFSAdapter($this->getClient());

        $this->assertInstanceOf('League\Flysystem\GridFS\GridFSAdapter', $adapter);
    }

    public function testHas()
    {
        $this->getMongoFile();
        $this->assertTrue((new GridFSAdapter($this->getClient()))->has('somefile.sql'));
    }

    public function testWriteAndUpdate()
    {
        $adapter = new GridFSAdapter($this->getClient());

        $expectedResult = [
            'path'      => 'file.txt',
            'type'      => 'file',
            'size'      => filesize($this->source),
            'dirname'   => '',
            'mimetype'  => 'text/plain',
        ];

        $source = file_get_contents($this->source);

        $writeResult = $adapter->write('file.txt', $source, new Config());
        $updateResult = $adapter->update('file.txt', $source, new Config());

        unset($writeResult['timestamp']);
        unset($updateResult['timestamp']);

        $this->assertSame($expectedResult, $writeResult);
        $this->assertSame($expectedResult, $updateResult);
    }

    public function testMimeTypeCanBeOverridenOnWrite()
    {
        $adapter = new GridFSAdapter($this->getClient());

        $this->assertSame('application/json', $adapter->write('file.txt', 'no content', new Config([
            'mimetype' => 'application/json'
        ]))['mimetype']);
    }

    public function testWriteStreamAndUpdateStream()
    {
        $adapter = new GridFSAdapter($this->getClient());

        $expectedResult = [
            'path'      => 'file.txt',
            'type'      => 'file',
            'dirname'   => '',
            'mimetype' => 'text/plain'
        ];

        $stream = fopen($this->source, 'r');
        $writeResult = $adapter->writeStream('file.txt', $stream, new Config([
            'mimetype' => 'text/plain'
        ]));
        $updateResult = $adapter->updateStream('file.txt', $stream, new Config([
            'mimetype' => 'application/json'
        ]));

        unset($writeResult['size']);
        unset($writeResult['timestamp']);
        unset($updateResult['size']);
        unset($updateResult['timestamp']);

        $this->assertSame($expectedResult, $writeResult);

        $expectedResult['mimetype'] = 'application/json';
        $this->assertSame($expectedResult, $updateResult);
    }

    public function testDelete()
    {
        $adapter = new GridFSAdapter($this->getClient());
        $this->getMongoFile();

        $this->assertTrue($adapter->delete('somefile.sql'));
    }

    public function testDeleteWhenFileDoesNotExist()
    {
        $this->assertFalse((new GridFSAdapter($this->getClient()))->delete('not exist file'));
    }

    public function testRead()
    {
        $adapter = new GridFSAdapter($this->getClient());
        $this->getMongoFile();

        $expectedResult = ['contents' => stream_get_contents(fopen($this->source, 'r'))];

        $this->assertEquals($expectedResult, $adapter->read('somefile.sql'));
    }

    public function testReadStream()
    {
        $adapter = new GridFSAdapter($this->getClient());
        $this->getMongoFile();

        $result = (array) $adapter->readStream('somefile.sql');
        $this->assertTrue(is_array($result));
        $this->assertArrayHasKey('stream', $result);
        $this->assertInternalType('resource', $result['stream']);
    }

    public function testReadWhenFileDoesntExist()
    {
        $this->assertFalse((new GridFSAdapter($this->getClient()))->read('not existing file.txt'));
    }

    public function testDeleteDir()
    {
        $adapter = new GridFSAdapter($this->getClient());
        $this->getMongoFile([], 'some directory/some file.txt');

        $this->assertTrue($adapter->deleteDir('some_directory'));
        $this->assertFalse($adapter->has('some file.txt'));
    }

    /**
     * @expectedException \LogicException
     */
    public function testVisibilityCantBeSet()
    {
        $adapter = new GridFSAdapter($this->getClient());
        $adapter->setVisibility('foo.bar', 'visibility');
    }

    /**
     * @expectedException  \LogicException
     */
    public function testVisibilityCantBeGet()
    {
        $adapter = new GridFSAdapter($this->getClient());
        $adapter->getVisibility('foo.txt');
    }

    /**
     * @expectedException  \LogicException
     */
    public function testDirectoriesCantBeCreated()
    {
        $adapter = new GridFSAdapter($this->getClient());
        $adapter->createDir('dir', new Config());
    }

    /**
     * @expectedException  \BadMethodCallException
     */
    public function testContentCantBeListedRecursively()
    {
        $adapter = new GridFSAdapter($this->getClient());
        $adapter->listContents('dir', true);
    }

    public function testContentCanBeListed()
    {
        $adapter = new GridFSAdapter($this->getClient());
        $this->getMongoFile([], 'lala dir/file one.txt');
        $this->getMongoFile([], 'lala dir/file_two.txt');

        $expectedResult = [
            [
                'path' => 'lala dir/file one.txt',
                'type' => 'file',
                'size' => filesize($this->source),
                'dirname' => 'lala dir',
            ],
            [
                'path' => 'lala dir/file_two.txt',
                'type' => 'file',
                'size' => filesize($this->source),
                'dirname' => 'lala dir',
            ],
        ];

        $list = $adapter->listContents('lala');
        $this->assertTrue(count($list) === 3);

        foreach ($list as $i => $item)
        {
            if($item['type'] !== 'file')
                continue;

            unset($item['timestamp']);
            $this->assertSame($item, $expectedResult[$i]);
        }
    }

    public function testCopy()
    {
        $adapter = new GridFSAdapter($this->getClient());
        $this->getMongoFile([], 'original.txt');

        $this->assertTrue($adapter->copy('original.txt', 'copy.txt'));
    }

    public function testRename()
    {
        $adapter = new GridFSAdapter($this->getClient());
        $this->getMongoFile([], 'original.txt');

        $this->assertTrue($adapter->rename('original.txt', 'renamed.txt'));
    }
}
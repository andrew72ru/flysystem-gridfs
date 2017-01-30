<?php

namespace League\Flysystem\GridFS;

use BadMethodCallException;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Adapter\Polyfill\StreamedReadingTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use LogicException;
use MongoDB\BSON\Regex;
use MongoDB\GridFS\Bucket;
use MongoDB\Model\BSONDocument;

class GridFSAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;
    use StreamedCopyTrait;
    use StreamedReadingTrait;

    /**
     * @var Bucket
     */
    protected $bucket;

    /**
     * Constructor.
     *
     * @param Bucket $bucket
     */
    public function __construct(Bucket $bucket)
    {
        $this->bucket  = $bucket;
    }

    /**
     * @return Bucket
     */
    public function getBucket()
    {
        return $this->bucket;
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        $location = $this->applyPathPrefix($path);

        return $this->bucket->findOne(['filename' => $location]) !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        $metadata = [];

        if ($config->has('mimetype')) {
            $metadata['mimetype'] = $config->get('mimetype');
        }

        return $this->writeObject($path, $contents, $metadata);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->write($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $result = $this->bucket->findOne(['filename' => $path]);

        return $this->normalizeBSONDocument($result, $path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        $file = $this->bucket->findOne(['filename' => $path]);

        if (!$file) {
            return false;
        }

        $this->bucket->delete($file['_id']);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        if(!$this->bucket->findOne(['filename' => $path]))
            return false;

        $stream = $this->bucket->openDownloadStreamByName($path);

        return ['contents' => stream_get_contents($stream)];
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        return $this->copy($path, $newpath) && $this->delete($path);
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($path, Config $config)
    {
        throw new LogicException(get_class($this).' does not support directory creation.');
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($path)
    {
        $prefix = rtrim($this->applyPathPrefix($path), '/').'/';

        $files = $this->bucket->find([
            'filename' => new Regex(sprintf('/^%s/', $prefix), ''),
        ]);

        foreach ($files as $file) {
            $this->bucket->delete($file['_id']);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     *
     * @todo Implement recursive listing.
     */
    public function listContents($dirname = '', $recursive = false)
    {
        if ($recursive) {
            throw new BadMethodCallException('Recursive listing is not yet implemented');
        }

        $files = $this->bucket->find([
            'filename' => new Regex(sprintf('/^%s/', $dirname), ''),
        ]);

        $keys = array_map(function ($file) {
            return $this->normalizeBSONDocument($file);
        }, $files->toArray());

        return Util::emulateDirectories($keys);
    }

    /**
     * Write an object to GridFS.
     *
     * @param array $metadata
     *
     * @return array normalized file representation
     */
    protected function writeObject($path, $content, array $metadata)
    {
        $stream = $content;

        if (!is_resource($content)) {
            $stream = fopen('php://memory','r+');
            fwrite($stream, $content);
            rewind($stream);
        }

        if (!isset($metadata['mimetype'])) {
            $metadata['mimetype'] = Util::guessMimeType($path, $stream);
        }

        $id = $this->bucket->uploadFromStream($path, $stream, ['metadata' => $metadata]);
        $file = $this->bucket->findOne(['_id' => $id]);

        return $this->normalizeBSONDocument($file, $path);
    }

    /**
     * Normalize a BSONDocument file to a response.
     *
     * @param BSONDocument|array|null|object    $file
     * @param string                            $path
     *
     * @return array
     */
    protected function normalizeBSONDocument(BSONDocument $file, $path = null)
    {
        $result = [
            'path'      => trim($path ?: $file['filename'], '/'),
            'type'      => 'file',
            'size'      => $file['chunkSize'],
            'timestamp' => $file['uploadDate']->toDateTime()->getTimestamp(),
        ];

        $result['dirname'] = Util::dirname($result['path']);

        if (isset($file['metadata']) && !empty($file['metadata']['mimetype'])) {
            $result['mimetype'] = $file['metadata']['mimetype'];
        }

        return $result;
    }
}

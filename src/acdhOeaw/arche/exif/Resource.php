<?php

/*
 * The MIT License
 *
 * Copyright 2024 zozlak.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace acdhOeaw\arche\exif;

use Psr\Log\LoggerInterface;
use termTemplates\PredicateTemplate as PT;
use rdfInterface\DatasetNodeInterface;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;
use acdhOeaw\arche\lib\dissCache\CallbackContextInterface;
use acdhOeaw\arche\lib\dissCache\FileCacheException;

class Resource {

    const DWNLD_CHUNK = 10485760; // 10MB

    /**
     * @param array<mixed> $param
     * @param object{'schema': object, 'exiftoolCmd': string} $config
     */
    static public function cacheHandler(RepoResourceInterface $res,
                                        array $param, object $config,
                                        CallbackContextInterface $context): ResponseCacheItem {

        $exifRes = new self($res, $config, $context);
        return $exifRes->getOutput();
    }

    private DatasetNodeInterface $meta;
    private Schema $schema;

    /**
     * 
     * @param object{'schema': object, 'exiftoolCmd': string} $config
     */
    public function __construct(RepoResourceInterface $res,
                                private object $config,
                                private CallbackContextInterface $context) {
        $this->meta   = $res->getGraph();
        $this->schema = new Schema($config->schema);
    }

    public function getOutput(): ResponseCacheItem {
        $resUrl = (string) $this->meta->getNode();
        /*         * @phpstan-ignore property.notFound */
        $mime   = (string) $this->meta->getObject(new PT($this->schema->format));
        if (empty($mime)) {
            throw new ExifException("Requested resource doesn't have a binary payload\n", 400);
        }
        $fileCache = $this->context->getFileCache();
        $path      = $fileCache->getRefFilePath($resUrl, $mime, $this->context->getNoCache());

        if (!file_exists($path) || !is_file($path)) {
            throw new ExifException("Resource $resUrl not found", 404);
        }

        $cmd     = sprintf(
            "%s -j %s",
            escapeshellcmd($this->config->exiftoolCmd),
            escapeshellarg($path)
        );
        $output  = $resCode = null;
        exec($cmd, $output, $resCode);
        if (str_starts_with($path, sys_get_temp_dir())) {
            unlink($path);
        }
        $data = json_decode(implode('', $output));
        if (!is_array($data)) {
            throw new ExifException("Unable to fetch EXIF data for the resource\n", 500);
        }

        $data = $data[0];
        unset($data->FileName, $data->SourceFile, $data->FileModifyDate, $data->FileAccessDate, $data->FileInodeChangeDate, $data->FilePermissions);
        $data = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new ResponseCacheItem($data, 200, ['Content-Type' => 'application/json'], false);
    }
}

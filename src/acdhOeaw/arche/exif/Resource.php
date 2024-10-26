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
use zozlak\RdfConstants as RDF;
use rdfInterface\DatasetInterface;
use rdfInterface\DatasetNodeInterface;
use rdfInterface\TermInterface;
use rdfInterface\LiteralInterface;
use quickRdf\DataFactory as DF;
use quickRdf\NamedNode;
use termTemplates\QuadTemplate as QT;
use termTemplates\PredicateTemplate as PT;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;

class Resource {

    const DWNLD_CHUNK = 10485760; // 10MB

    /**
     * @param array<mixed> $param
     */
    static public function cacheHandler(RepoResourceInterface $res,
                                        array $param, object $config,
                                        ?LoggerInterface $log = null): ResponseCacheItem {

        $exifRes = new self($res, $config, $log);
        return $exifRes->getOutput(...$param);
    }

    private DatasetNodeInterface $meta;
    private object $config;
    private Schema $schema;
    private LoggerInterface | null $log;

    public function __construct(RepoResourceInterface $res, object $config,
                                ?LoggerInterface $log = null) {
        $this->meta   = $res->getGraph();
        $this->config = $config;
        $this->schema = new Schema($config->schema);
        $this->log    = $log;
    }

    public function getOutput(): ResponseCacheItem {
        $resUrl = (string) $this->meta->getNode();
        $path   = null;
        foreach ($this->config->localAccess ?? [] as $nmsp => $nmspCfg) {
            if (str_starts_with($resUrl, $nmsp)) {
                $path = $this->getLocalFilePath($resUrl, $nmspCfg);
                if (!file_exists($path)) {
                    throw new ExifException("Requested resource doesn't have a binary payload\n", 400);
                }
                /** @phpstan-ignore property.notFound */
                $aclRead  = $this->meta->listObjects(new PT($this->schema->aclRead))->getValues();
                $aclValid = array_intersect($this->config->allowedAclRead, $aclRead);
                if (count($aclValid) === 0) {
                    throw new ExifException("Unauthorized\n", 401);
                }
                $this->log?->debug("Accessing local path $path");
                break;
            }
        }
        if ($path === null) {
            $size = $this->meta->getObjectValue(new PT($this->schema->binarySize));
            if ($size === null) {
                throw new ExifException("Requested resource doesn't have a binary payload\n", 400);
            }
            if (($size >> 20) > $this->config->maxSizeMb) {
                throw new ExifException("Requested resource is too large\n", 413);
            }
            $path = $this->downloadResource((string) $this->meta->getNode());
        }

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
        $data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new ResponseCacheItem($data, 200, ['Content-Type' => 'application/json'], false);
    }

    private function getLocalFilePath(string $resUrl, object $nmspCfg): string {
        $id     = (int) preg_replace('`^.*/`', '', $resUrl);
        $level  = $nmspCfg->level;
        $path   = $nmspCfg->dir;
        $idPart = $id;
        while ($level > 0) {
            $path   .= sprintf('/%02d', $idPart % 100);
            $idPart = (int) ($idPart / 100);
            $level--;
        }
        $path .= '/' . $id;
        return $path;
    }

    private function downloadResource(string $resUrl): string {
        $remote = fopen($resUrl, 'rb');
        if ($remote === false) {
            throw new ExifException("Can't access the requested resource $resUrl\n", 400);
        }
        $path  = tempnam(sys_get_temp_dir(), 'exif_cache_');
        $this->log?->debug("Downloading $resUrl into $path");
        $local = fopen($path, 'wb');
        while (!feof($remote)) {
            fwrite($local, fread($remote, self::DWNLD_CHUNK));
        }
        fclose($remote);
        fclose($local);
        return $path;
    }
}

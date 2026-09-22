<?php

/*
 * The MIT License
 *
 * Copyright 2021 zozlak.
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

namespace acdhOeaw\arche\exif\tests;

use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\dissCache\CachePdo;
use acdhOeaw\arche\lib\dissCache\ResponseCache;
use acdhOeaw\arche\lib\dissCache\RepoWrapperGuzzle;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;
use acdhOeaw\arche\lib\dissCache\FileCacheException;
use acdhOeaw\arche\lib\dissCache\CallbackContextStub;
use acdhOeaw\arche\lib\dissCache\FileCache;
use acdhOeaw\arche\exif\Resource as ExifResource;
use acdhOeaw\arche\exif\ExifException;

/**
 * Description of ResourceTest
 *
 * @author zozlak
 */
class ResourceTest extends \PHPUnit\Framework\TestCase {

    /**
     * 
     * @var object{
     *   'schema': object, 
     *   'exiftoolCmd': string, 
     *   'fileCache': object{
     *     'dir': string, 
     *     'maxDownloadSizeMb': float, 
     *     'mimeProperty': string, 
     *     'localAccess': array<string, object{'dir': string}>
     *   }
     * }
     * 
     */
    static private object $config;
    static private Schema $schema;
    static private CallbackContextStub $context;

    static public function setUpBeforeClass(): void {
        self::$config  = json_decode((string) json_encode(yaml_parse_file(__DIR__ . '/config.yaml')));
        /** @phpstan-ignore property.notFound */
        self::$schema  = new Schema(self::$config->schema);
        self::$context = new CallbackContextStub();
    }

    public function setUp(): void {
        parent::setUp();

        $cfg = self::$config->fileCache;
        mkdir($cfg->dir, recursive: true);
        foreach ((array) ($cfg->localAccess ?? []) as $i) {
            if (!file_exists($i->dir)) {
                mkdir($i->dir, recursive: true);
            }
        }
        self::$context->fileCache = FileCache::fromConfig($cfg);
    }

    public function tearDown(): void {
        parent::tearDown();

        $cfg = self::$config->fileCache;
        system('rm -fR "' . $cfg->dir . '"');
        foreach ((array) ($cfg->localAccess ?? []) as $i) {
            system('rm -fR "' . $i->dir . '"');
        }
    }

    public function testOk(): void {
        $cache = $this->getCache();

        $t0        = microtime(true);
        $response1 = $cache->getResponse([], 'https://hdl.handle.net/21.11115/0000-000C-3476-5');
        $t1        = microtime(true);
        $response2 = $cache->getResponse([], 'https://hdl.handle.net/21.11115/0000-000C-3476-5');
        $t2        = microtime(true) - $t1;
        $t1        = $t1 - $t0;

        $response1 = $this->standardizeExifOutput($response1);
        $response2 = $this->standardizeExifOutput($response2);

        $body     = '{"FileType":"TIFF","FileTypeExtension":"tif","MIMEType":"image/tiff","ExifByteOrder":"Little-endian (Intel, II)","SubfileType":"Full-resolution image","ImageWidth":1700,"ImageHeight":2546,"BitsPerSample":1,"Compression":"T6/Group 4 Fax","PhotometricInterpretation":"WhiteIsZero","FillOrder":"Normal","DocumentName":"G:\\\\Baedeker\\\\Konstantinopel_und_Kleinasien\\\\Baedeker-Konstantinopel_und_Kleinasien_a0002.tif","StripOffsets":416,"Orientation":"Horizontal (normal)","SamplesPerPixel":1,"RowsPerStrip":2546,"StripByteCounts":55173,"XResolution":400,"YResolution":400,"ResolutionUnit":"inches","PageNumber":"0 1","Software":"ImageGear Version:  7.01.002","ModifyDate":"Wed Apr 28 13:41:38 2004\n","Artist":"","ImageSize":"1700x2546","Megapixels":4.3}';
        $expected = new ResponseCacheItem($body, 200, ['Content-Type' => 'application/json'], false);

        $this->assertEquals($expected->withLastModified($response1->lastModified), $response1);
        $this->assertEquals($expected->withHit(true)->withLastModified($response2->lastModified), $response2);
        $this->assertGreaterThan($t2, $t1 / 10);
    }

    public function testNoBinary(): void {
        $cache = $this->getCache();
        try {
            $cache->getResponse([], 'https://hdl.handle.net/21.11115/0000-000C-29F3-4');
            /** @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (ExifException $e) {
            $this->assertEquals(400, $e->getCode());
            $this->assertEquals("Requested resource doesn't have a binary payload\n", $e->getMessage());
        }
    }

    public function testTooLarge(): void {
        $cache = $this->getCache();
        try {
            $cache->getResponse([], 'https://hdl.handle.net/21.11115/0000-000D-D715-9');
            /** @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (FileCacheException $e) {
            $this->assertEquals(413, $e->getCode());
            $this->assertEquals("Requested file too large", $e->getMessage());
        }
    }

    public function testUnauthorized(): void {
        $cache = $this->getCache();
        try {
            $cache->getResponse([], 'https://hdl.handle.net/21.11115/0000-0011-0DB9-F');
            /** @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (FileCacheException $e) {
            $this->assertEquals(403, $e->getCode());
            $this->assertEquals("Forbidden", $e->getMessage());
        }
    }

    private function getCache(): ResponseCache {
        foreach (glob('/tmp/cachePdo_*') ?: [] as $i) {
            unlink($i);
        }
        /** @phpstan-ignore property.notFound */
        $cfg                                  = self::$config->dissCacheService;
        $db                                   = new CachePdo('sqlite::memory:');
        $clbck                                = fn($res, $param, $context) => ExifResource::cacheHandler($res, $param, self::$config, self::$context);
        $repos                                = [new RepoWrapperGuzzle(false)];
        $searchConfig                         = new SearchConfig();
        $searchConfig->metadataMode           = $cfg->metadataMode;
        $searchConfig->metadataParentProperty = $cfg->parentProperty;
        $searchConfig->resourceProperties     = $cfg->resourceProperties;
        $searchConfig->relativesProperties    = $cfg->relativesProperties;

        $cache = new ResponseCache($db, $clbck, $cfg->ttl->resource, $cfg->ttl->response, $repos, $searchConfig);

        return $cache;
    }

    private function standardizeExifOutput(ResponseCacheItem $response): ResponseCacheItem {
        $body = json_decode($response->body);
        unset($body->ExifToolVersion, $body->FileSize, $body->Directory);
        $body = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new ResponseCacheItem($body, $response->responseCode, $response->headers, $response->hit);
    }
}

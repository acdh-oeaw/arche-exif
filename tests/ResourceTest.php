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

use acdhOeaw\arche\lib\dissCache\CachePdo;
use acdhOeaw\arche\lib\dissCache\ResponseCache;
use acdhOeaw\arche\lib\dissCache\RepoWrapperGuzzle;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;
use acdhOeaw\arche\lib\dissCache\UnauthorizedException;
use acdhOeaw\arche\exif\Resource as ExifResource;
use acdhOeaw\arche\exif\ExifException;

/**
 * Description of ResourceTest
 *
 * @author zozlak
 */
class ResourceTest extends \PHPUnit\Framework\TestCase {

    static private object $cfg;
    static private Schema $schema;

    static public function setUpBeforeClass(): void {
        self::$cfg    = json_decode(json_encode(yaml_parse_file(__DIR__ . '/config.yaml')));
        self::$schema = new Schema(self::$cfg->exif->schema);
    }

    public function setUp(): void {
        parent::setUp();

        foreach (glob('/tmp/cachePdo*') as $i) {
            unlink($i);
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

        $body            = '{"Directory":"/tmp","FileSize":"56 kB","FileType":"TIFF","FileTypeExtension":"tif","MIMEType":"image/tiff","ExifByteOrder":"Little-endian (Intel, II)","SubfileType":"Full-resolution image","ImageWidth":1700,"ImageHeight":2546,"BitsPerSample":1,"Compression":"T6/Group 4 Fax","PhotometricInterpretation":"WhiteIsZero","FillOrder":"Normal","DocumentName":"G:\\\\Baedeker\\\\Konstantinopel_und_Kleinasien\\\\Baedeker-Konstantinopel_und_Kleinasien_a0002.tif","StripOffsets":416,"Orientation":"Horizontal (normal)","SamplesPerPixel":1,"RowsPerStrip":2546,"StripByteCounts":55173,"XResolution":400,"YResolution":400,"ResolutionUnit":"inches","PageNumber":"0 1","Software":"ImageGear Version:  7.01.002","ModifyDate":"Wed Apr 28 13:41:38 2004\n","Artist":"","ImageSize":"1700x2546","Megapixels":4.3}';
        $expected        = new ResponseCacheItem($body, 200, ['Content-Type' => 'application/json'], false);
        $response1->body = $this->standardizeExifOutput($response1->body);
        $this->assertEquals($expected, $response1);
        $expected->hit   = true;
        $response2->body = $this->standardizeExifOutput($response2->body);
        $this->assertEquals($expected, $response2);
        $this->assertGreaterThan($t2, $t1 / 10);
    }

    public function testNoBinary(): void {
        $cache = $this->getCache();
        try {
            $cache->getResponse([], 'https://hdl.handle.net/21.11115/0000-000C-29F3-4');
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
            $this->assertTrue(false);
        } catch (ExifException $e) {
            $this->assertEquals(413, $e->getCode());
            $this->assertEquals("Requested resource is too large\n", $e->getMessage());
        }
    }

    public function testUnauthorized(): void {
        $cache = $this->getCache();
        try {
            $cache->getResponse([], 'https://hdl.handle.net/21.11115/0000-0011-0DB9-F');
            $this->assertTrue(false);
        } catch (UnauthorizedException $e) {
            $this->assertEquals(401, $e->getCode());
            $this->assertEquals("Unauthorized\n", $e->getMessage());
        }
    }

    private function getCache(): ResponseCache {
        foreach (glob('/tmp/cachePdo_*') as $i) {
            unlink($i);
        }
        $cfg                                  = self::$cfg->dissCacheService;
        $db                                   = new CachePdo('sqlite::memory:');
        $clbck                                = fn($res, $param) => ExifResource::cacheHandler($res, $param, self::$cfg->exif);
        $repos                                = [new RepoWrapperGuzzle(false)];
        $searchConfig                         = new SearchConfig();
        $searchConfig->metadataMode           = $cfg->metadataMode;
        $searchConfig->metadataParentProperty = $cfg->parentProperty;
        $searchConfig->resourceProperties     = $cfg->resourceProperties;
        $searchConfig->relativesProperties    = $cfg->relativesProperties;

        $cache = new ResponseCache($db, $clbck, $cfg->ttl->resource, $cfg->ttl->response, $repos, $searchConfig);

        return $cache;
    }

    private function standardizeExifOutput(string $output): string {
        $output = json_decode($output);
        unset($output->ExifToolVersion);
        return json_encode($output, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE);
    }
}

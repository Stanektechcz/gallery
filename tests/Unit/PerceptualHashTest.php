<?php

namespace Tests\Unit;

use App\Services\Media\PerceptualHashService;
use Tests\TestCase;

class PerceptualHashTest extends TestCase
{
    private PerceptualHashService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PerceptualHashService();
    }

    /** @test */
    public function test_hamming_distance_identical_hashes(): void
    {
        $this->assertEquals(0, $this->service->hammingDistance('ff00ff00', 'ff00ff00'));
    }

    /** @test */
    public function test_hamming_distance_different_hashes(): void
    {
        $distance = $this->service->hammingDistance('ff00ff00', '00ff00ff');
        $this->assertGreaterThan(0, $distance);
    }

    /** @test */
    public function test_similar_hashes_are_detected(): void
    {
        // Two hashes that differ by 5 bits — should be similar with default threshold 10
        $hash1 = '0000000000000000';
        $hash2 = '0000000000001f00'; // 5 bits different
        $this->assertTrue($this->service->areSimilar($hash1, $hash2, 10));
    }

    /** @test */
    public function test_very_different_hashes_are_not_similar(): void
    {
        $hash1 = '0000000000000000';
        $hash2 = 'ffffffffffffffff'; // All bits different
        $this->assertFalse($this->service->areSimilar($hash1, $hash2, 10));
    }
}

<?php

namespace Tests\Unit;

use App\Agents\Tools\GoogleReviewsTool;
use App\Services\GooglePlacesService;
use Mockery;
use Tests\TestCase;

class GoogleReviewsToolTest extends TestCase
{
    public function test_formats_rating_count_and_reviews(): void
    {
        $places = Mockery::mock(GooglePlacesService::class);
        $places->shouldReceive('reviewsFor')->once()->with('PrimeLaser', 'BG')->andReturn([
            'name'    => 'PrimeLaser Aesthetic Center',
            'address' => 'ул. Александровска 84, Русе',
            'rating'  => 4.9,
            'total'   => 117,
            'reviews' => [
                ['author' => 'Иван', 'rating' => 5, 'time' => 'преди седмица', 'text' => 'Страхотно обслужване!'],
            ],
        ]);

        $out = (new GoogleReviewsTool($places))->execute(['query' => 'PrimeLaser', 'region' => 'BG']);

        $this->assertStringContainsString('4.9', $out);
        $this->assertStringContainsString('117', $out);
        $this->assertStringContainsString('Иван', $out);
        $this->assertStringContainsString('Страхотно', $out);
    }

    public function test_returns_empty_when_no_place_found(): void
    {
        $places = Mockery::mock(GooglePlacesService::class);
        $places->shouldReceive('reviewsFor')->andReturn(null);

        $out = (new GoogleReviewsTool($places))->execute(['query' => 'Nonexistent', 'region' => null]);

        $this->assertSame('', $out);
    }
}

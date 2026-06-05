<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches a business's Google rating + reviews via the Google Places API (New).
 * Plain scraping of Google can't reach the JS-rendered reviews block, so this
 * official API is the reliable source: rating, total review count, and up to 5
 * representative reviews. Returns null when no API key is configured or no place
 * matches, so callers can fall back gracefully.
 */
class GooglePlacesService
{
    private const SEARCH_URL  = 'https://places.googleapis.com/v1/places:searchText';
    private const DETAILS_URL = 'https://places.googleapis.com/v1/places/';

    public function isAvailable(): bool
    {
        return ! empty(config('services.google_places.api_key'));
    }

    /**
     * Resolve the best-matching place for $query and return its rating, review
     * count and sample reviews. $regionCode (ISO-3166 alpha-2, e.g. "BG") biases
     * the search to the right country.
     *
     * @return array{name:string,address:string,rating:float|null,total:int|null,reviews:array<int,array<string,mixed>>}|null
     */
    public function reviewsFor(string $query, ?string $regionCode = null): ?array
    {
        $apiKey = config('services.google_places.api_key');
        if (empty($apiKey) || trim($query) === '') {
            return null;
        }

        try {
            $body = ['textQuery' => $query, 'languageCode' => 'bg'];
            if ($regionCode) {
                $body['regionCode'] = strtoupper($regionCode);
            }

            $search = Http::withHeaders([
                'X-Goog-Api-Key'   => $apiKey,
                'X-Goog-FieldMask' => 'places.id,places.displayName,places.rating,places.userRatingCount,places.formattedAddress',
            ])->post(self::SEARCH_URL, $body);

            if (! $search->successful()) {
                Log::warning('[GooglePlaces] searchText HTTP '.$search->status().' — '.mb_substr($search->body(), 0, 200));

                return null;
            }

            $place = $search->json('places.0');
            if (! is_array($place) || empty($place['id'])) {
                return null;
            }

            // Place Details carries the actual review texts (search results do not).
            $details = Http::withHeaders([
                'X-Goog-Api-Key'   => $apiKey,
                'X-Goog-FieldMask' => 'id,displayName,rating,userRatingCount,formattedAddress,reviews',
            ])->get(self::DETAILS_URL.rawurlencode($place['id']), ['languageCode' => 'bg']);

            if ($details->successful()) {
                return $this->shape($details->json(), $details->json('reviews') ?? []);
            }

            // Details failed but search already gave rating + count — return those.
            return $this->shape($place, []);
        } catch (\Throwable $e) {
            Log::warning('[GooglePlaces] '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $place
     * @param  array<int,array<string,mixed>>  $reviews
     * @return array{name:string,address:string,rating:float|null,total:int|null,reviews:array<int,array<string,mixed>>}
     */
    private function shape(array $place, array $reviews): array
    {
        return [
            'name'    => $place['displayName']['text'] ?? '',
            'address' => $place['formattedAddress'] ?? '',
            'rating'  => isset($place['rating']) ? (float) $place['rating'] : null,
            'total'   => isset($place['userRatingCount']) ? (int) $place['userRatingCount'] : null,
            'reviews' => array_map(static fn (array $r): array => [
                'author' => $r['authorAttribution']['displayName'] ?? '',
                'rating' => $r['rating'] ?? null,
                'time'   => $r['relativePublishTimeDescription'] ?? '',
                'text'   => $r['text']['text'] ?? ($r['originalText']['text'] ?? ''),
            ], array_slice($reviews, 0, 5)),
        ];
    }
}

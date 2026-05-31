<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_page_has_wide_layout_large_description_and_ai_improve_button(): void
    {
        $company = Company::create([
            'name' => 'Спортен Център',
            'description' => 'Описание',
            'industry' => 'Sports',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Web Game Search',
            'description' => 'Search for web games.',
            'status' => 'active',
        ]);

        $response = $this->get(route('flows.edit', $flow));

        $response->assertOk();

        $content = $response->getContent();

        $this->assertStringContainsString('max-w-5xl mx-auto', $content);
        $this->assertStringContainsString('x-data="flowEditForm(', $content);
        $this->assertStringContainsString('rows="6"', $content);
        $this->assertStringContainsString('@click="improveDescription"', $content);
        $this->assertStringContainsString('improve-description', $content);
        $this->assertStringContainsString('Подобри с AI', $content);
    }
}

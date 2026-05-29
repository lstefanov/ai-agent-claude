<?php

namespace Tests\Feature;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_link_uses_centered_button_layout(): void
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

        $response = $this->get(route('flows.show', $flow));

        $response->assertOk();
        $response->assertSeeHtml('href="' . route('flows.edit', $flow) . '"');
        $this->assertStringContainsString(
            'flex items-start gap-2 shrink-0',
            $response->getContent()
        );
        $this->assertStringContainsString(
            'inline-flex items-center justify-center',
            $response->getContent()
        );
    }
}

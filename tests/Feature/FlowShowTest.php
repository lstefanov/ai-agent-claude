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

    public function test_flow_show_renders_readonly_graph_preview(): void
    {
        // The editable agent list was replaced by a read-only graph preview that
        // links to the Graph Editor.
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
        $content = $response->getContent();

        $this->assertStringContainsString('Граф на агентите', $content);
        // The dashboard preview is now a pure Blade partial — no JS graphPreview() function.
        $this->assertStringContainsString('Отвори Граф Редактора', $content);
        $this->assertStringContainsString(route('flows.builder', $flow), $content);
    }

    public function test_flow_description_preserves_line_breaks_and_escapes_html(): void
    {
        $company = Company::create([
            'name' => 'Спортен Център',
            'description' => 'Описание',
            'industry' => 'Sports',
            'language' => 'bg',
        ]);

        $flow = $company->flows()->create([
            'name' => 'Web Game Search',
            'description' => "Първи ред\nВтори ред <script>alert(\"x\")</script>",
            'status' => 'active',
        ]);

        $response = $this->get(route('flows.show', $flow));

        $response->assertOk();
        $this->assertStringContainsString(
            "Първи ред<br />\nВтори ред &lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;",
            $response->getContent()
        );
    }

}

<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\Whatsapp\Models\WhatsappWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WhatsappWidgetEmbedTest extends TestCase
{
    use RefreshDatabase;

    private function widget(array $attrs = []): WhatsappWidget
    {
        $ctx = $this->createWorkspaceContext();

        return WhatsappWidget::create(array_merge([
            'workspace_id' => $ctx['workspace']->id,
            'display_phone' => '15551234567',
            'prefilled_message' => 'Hello there',
            'position' => 'bottom_right',
        ], $attrs));
    }

    public function test_embed_script_is_served_with_js_suffix(): void
    {
        $widget = $this->widget();

        $response = $this->get("/widgets/whatsapp/{$widget->widget_key}.js");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->assertStringContainsString('wa.me/15551234567?text=Hello%20there', $response->getContent());
        $this->assertStringContainsString('_wacw_root', $response->getContent());
    }

    public function test_embed_script_is_served_without_js_suffix(): void
    {
        $widget = $this->widget();

        $response = $this->get("/widgets/whatsapp/{$widget->widget_key}");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->assertStringContainsString('wa.me/15551234567', $response->getContent());
    }

    public function test_unknown_key_returns_404(): void
    {
        $this->get('/widgets/whatsapp/doesnotexist.js')->assertNotFound();
        $this->get('/widgets/whatsapp/doesnotexist')->assertNotFound();
    }

    public function test_embed_url_is_generated_server_side_and_keeps_base_path(): void
    {
        $widget = $this->widget();

        $this->assertSame(
            url("/widgets/whatsapp/{$widget->widget_key}.js"),
            $widget->embed_url,
        );
        $this->assertArrayHasKey('embed_url', $widget->toArray());

        // Sub-directory install (document root above public/): the URL must carry the prefix.
        URL::forceRootUrl('https://example.test/public');
        $this->assertSame(
            "https://example.test/public/widgets/whatsapp/{$widget->widget_key}.js",
            $widget->fresh()->embed_url,
        );
        URL::forceRootUrl(null);
    }

    public function test_generated_script_parses_as_valid_javascript_for_any_content(): void
    {
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node binary not available to syntax-check the widget script');
        }

        $plain = $this->widget();
        $hostile = $this->widget([
            'display_phone' => '+55 (11) 99385-3929',
            'greeting_message' => "Olá!\nWe're \"here\" </script> <b>bold</b> \u{2028}line",
            'agent_name' => 'Ângela',
            'prefilled_message' => 'Hi & "bye" 100%',
            'button_color' => '#fff;}b{c:0}',
            'agent_avatar_color' => 'rgb(1, 2, 3)',
            'position' => 'bottom_left',
            'allowed_domains' => ['Example.com', ' ', 'https://Shop.example.org/path'],
            'working_hours_json' => ['enabled' => true, 'timezone' => 'America/Sao_Paulo', 'schedule' => ['mon' => ['enabled' => true, 'open' => '09:00', 'close' => '18:00']]],
        ]);

        foreach ([$plain, $hostile] as $widget) {
            $content = $this->get("/widgets/whatsapp/{$widget->widget_key}.js")->assertOk()->getContent();

            $file = tempnam(sys_get_temp_dir(), 'wacw').'.js';
            file_put_contents($file, $content);
            exec(escapeshellarg($node).' --check '.escapeshellarg($file).' 2>&1', $output, $exit);
            unlink($file);

            $this->assertSame(0, $exit, "Widget script is not valid JavaScript:\n".implode("\n", $output));
            $this->assertMatchesRegularExpression('/^[\x00-\x7F]*$/', $content, 'Script must be pure ASCII');
        }

        $content = $this->get("/widgets/whatsapp/{$hostile->widget_key}.js")->getContent();
        $this->assertStringContainsString('wa.me/5511993853929?text=Hi%20%26%20%22bye%22%20100%25', $content);
        $this->assertStringNotContainsString('</script>', $content);
        $this->assertStringNotContainsString('#fff;}b{c:0}', $content);
        $this->assertStringContainsString('background:#25D366', $content, 'invalid colour must fall back to the default');
        $this->assertStringContainsString('background:rgb(1, 2, 3)', $content);
        $this->assertStringContainsString('left:20px', $content);
        $this->assertStringContainsString('transform-origin:bottom left', $content);
        $this->assertStringContainsString('["example.com","shop.example.org"]', $content);
        $this->assertStringContainsString(json_encode('Â'), $content, 'avatar initial must be the first character, not the first byte');
        $this->assertMatchesRegularExpression('/var GREETING = ("(?:[^"\\\\]|\\\\.)*");/', $content);
        preg_match('/var GREETING = ("(?:[^"\\\\]|\\\\.)*");/', $content, $m);
        $greeting = json_decode($m[1]);
        $this->assertStringContainsString('<br>', $greeting, 'textarea line breaks must render as <br>');
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $greeting, 'greeting HTML must be escaped');
    }

    public function test_script_waits_for_the_document_before_touching_the_dom(): void
    {
        $widget = $this->widget();

        $content = $this->get("/widgets/whatsapp/{$widget->widget_key}.js")->getContent();

        $this->assertStringContainsString("document.readyState === 'loading'", $content);
        $this->assertStringContainsString("addEventListener('DOMContentLoaded'", $content);
        $this->assertStringNotContainsString('. (', $content, 'no PHP concatenation leaked into the JS');
    }

    public function test_domain_whitelist_is_embedded_in_script(): void
    {
        $widget = $this->widget(['allowed_domains' => ['example.com']]);

        $content = $this->get("/widgets/whatsapp/{$widget->widget_key}.js")->assertOk()->getContent();

        $this->assertStringContainsString('["example.com"]', $content);
    }
}

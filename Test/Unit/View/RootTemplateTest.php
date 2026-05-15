<?php

declare(strict_types=1);

namespace BA\Svelte\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class RootTemplateTest extends TestCase
{
    public function testRootTemplateOmitsFallbackMarkupWhenNoFallbackChildExists(): void
    {
        $output = $this->renderTemplate('{"component":"BA_Svelte::example.svelte"}', '');

        $this->assertStringContainsString('class="svelte-root"', $output);
        $this->assertStringNotContainsString('data-ba-svelte-fallback', $output);
    }

    public function testRootTemplateRendersFallbackMarkupWhenFallbackChildExists(): void
    {
        $fallbackHtml = '<nav><span>Home / Category</span></nav>';
        $output = $this->renderTemplate('{"component":"BA_Svelte::example.svelte"}', $fallbackHtml);

        $this->assertStringContainsString('data-ba-svelte-fallback', $output);
        $this->assertStringContainsString($fallbackHtml, $output);
    }

    private function renderTemplate(string $componentJson, string $fallbackHtml): string
    {
        $block = new class ($componentJson, $fallbackHtml) {
            public function __construct(
                private readonly string $componentJson,
                private readonly string $fallbackHtml
            ) {
            }

            public function getComponentConfigJson(): string
            {
                return $this->componentJson;
            }

            public function getChildHtml(string $alias = ''): string
            {
                return $alias === 'fallback' ? $this->fallbackHtml : '';
            }
        };

        $escaper = new class {
            public function escapeHtmlAttr(string $value): string
            {
                return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        };

        ob_start();
        include '/home/amear/ba/clients/core/src/app/code/BA/Svelte/view/frontend/templates/root.phtml';

        return (string) ob_get_clean();
    }
}

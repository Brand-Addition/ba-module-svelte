<?php
declare(strict_types=1);
namespace BA\Svelte\ViewModel;

use BA\Svelte\Model\Dto\SvelteComponentConfig;

class SvelteBlockRenderer implements \Magento\Framework\View\Element\Block\ArgumentInterface
{
    public function __construct(
        private readonly \Magento\Framework\Escaper $escaper,
        private readonly \Magento\Framework\Serialize\Serializer\Json $jsonSerialiser,
        private readonly \Psr\Log\LoggerInterface $logger
    ) { }

    /**
     * @param array<string, mixed> $props
     * @param array<string, array<int, SvelteComponentConfig>> $svelteChildrenContainers
     */
    public function render(
        string $uniqueName,
        string $svelteTemplatePath,
        array $props,
        array $svelteChildrenContainers = []
    ): string {
        try {
            $config = new SvelteComponentConfig($uniqueName, $svelteTemplatePath, $props, [], $svelteChildrenContainers);
            $dataConfig = $this->escaper->escapeHtmlAttr((string) $this->jsonSerialiser->serialize($config->jsonSerialize()));
            return '
<div
    class="svelte-root"
    data-config="'.$dataConfig.'"
';
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return '<div class="message error"><span>'.__("Error rendering. See logs at %1", date("Y-m-d H:i:s")).'</span></div>';
        }
    }
}
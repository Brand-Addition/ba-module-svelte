<?php

declare(strict_types=1);

namespace BA\Svelte\Model;

use BA\Svelte\Block\ComponentBlock;
use Magento\Framework\App\Area;
use Magento\Framework\App\AreaList;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;

class LayoutBlockInspector
{
    public function __construct(
        private readonly State $appState,
        private readonly AreaList $areaList,
        private readonly PageFactory $pageFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param array<int, string> $layoutHandles
     */
    public function getComponentConfig(array $layoutHandles, string $blockName, ?string $store = null): SvelteComponentConfig
    {
        $blockName = trim($blockName);
        $layoutHandles = $this->normalizeLayoutHandles($layoutHandles);

        if ($layoutHandles === []) {
            throw new LocalizedException(__('At least one layout handle is required.'));
        }

        if ($blockName === '') {
            throw new LocalizedException(__('A block name is required.'));
        }

        return $this->runInFrontendArea(function () use ($layoutHandles, $blockName, $store): SvelteComponentConfig {
            $this->areaList->getArea(Area::AREA_FRONTEND)->load();

            $initialStoreCode = $this->storeManager->getStore()->getCode();

            if ($store !== null && $store !== '') {
                $this->storeManager->setCurrentStore($store);
            }

            try {
                $page = $this->pageFactory->create(true, ['isIsolated' => true]);
                $page->addHandle($layoutHandles);

                $layout = $page->getLayout();
                $layout->generateXml();
                $layout->generateElements();

                $block = $layout->getBlock($blockName);
                if ($block === null) {
                    throw new LocalizedException(__(
                        'Block "%1" was not found in handles: %2. Check the debug log for any errors.',
                        $blockName,
                        implode(', ', $layoutHandles)
                    ));
                }
                if (!$block instanceof ComponentBlock) {
                    throw new LocalizedException(__(
                        'Block "%1" was found in handles: %2, but is not a BA_Svelte component block.',
                        $blockName,
                        implode(', ', $layoutHandles)
                    ));
                }

                return $block->getComponentConfig(includePropTypes: true);
            } finally {
                $this->storeManager->setCurrentStore($initialStoreCode);
            }
        });
    }

    /**
     * @param array<int, string> $layoutHandles
     * @return array<int, string>
     */
    private function normalizeLayoutHandles(array $layoutHandles): array
    {
        $normalizedHandles = ['default'];

        foreach ($layoutHandles as $layoutHandle) {
            $layoutHandle = trim($layoutHandle);
            if ($layoutHandle === '') {
                continue;
            }

            $normalizedHandles[] = $layoutHandle;
        }

        return array_values(array_unique($normalizedHandles));
    }

    private function runInFrontendArea(callable $callback): mixed
    {
        try {
            $currentAreaCode = $this->appState->getAreaCode();
        } catch (LocalizedException) {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);

            return $callback();
        }

        if ($currentAreaCode === Area::AREA_FRONTEND) {
            return $callback();
        }

        return $this->appState->emulateAreaCode(Area::AREA_FRONTEND, $callback);
    }
}

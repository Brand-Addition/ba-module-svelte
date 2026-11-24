<script lang="ts">
    import { _ } from "../../js/lib/i18n.js";

    type AmountValue = number | string | null | undefined;

    export type PriceInformation = {
        final_price?: AmountValue;
        regular_price?: AmountValue;
        has_special_price?: boolean;
        currency_code?: string;
        currency_symbol?: string;
        locale?: string;
        precision?: number;
        show_minimal_price?: boolean;
        use_link_for_as_low_as?: boolean;
        minimal_price?: AmountValue;
        minimal_price_label?: string;
        product_url?: string;
        special_price_label?: string;
        old_price_label?: string;
    };

    let {
        final_price: finalPrice = 0,
        regular_price: regularPrice = 0,
        has_special_price: hasSpecialPrice = false,
        currency_code: currencyCode = window.__baCurrentCurrency,
        currency_symbol: currencySymbol = "",
        locale = window.__baCurrentLocale,
        precision = 2,
        show_minimal_price: showMinimalPrice = false,
        use_link_for_as_low_as: useLinkForAsLowAs = false,
        minimal_price: minimalPrice = null,
        minimal_price_label: minimalPriceLabel = _("As low as"),
        product_url: productUrl = "",
        special_price_label: specialPriceLabel = _("Special Price"),
        old_price_label: oldPriceLabel = _("Was"),
    }: PriceInformation = $props();

    const normalizeAmount = (amount: AmountValue): number | null => {
        const value = Number(amount);

        return Number.isFinite(value) ? value : null;
    };

    const formatAmount = (amount: number): string => {
        try {
            return new Intl.NumberFormat(locale.replace(/_/g, "-"), {
                currency: currencyCode,
                minimumFractionDigits: precision,
                maximumFractionDigits: precision,
                style: "currency",
            }).format(amount);
        } catch (error) {
            console.error("error while rendering price:" + error);
            const prefix = currencySymbol || currencyCode;

            return `${prefix}${amount.toFixed(precision)}`;
        }
    };

    let finalAmount = $derived(normalizeAmount(finalPrice) ?? 0);
    let regularAmount = $derived(normalizeAmount(regularPrice) ?? finalAmount);
    let minimalAmount = $derived(normalizeAmount(minimalPrice));
    let formattedFinalPrice = $derived(formatAmount(finalAmount));
    let formattedRegularPrice = $derived(formatAmount(regularAmount));
    let formattedMinimalPrice = $derived(
        minimalAmount === null ? "" : formatAmount(minimalAmount),
    );
    let hasDiscount = $derived(
        hasSpecialPrice &&
            finalAmount <= regularAmount &&
            finalAmount !== regularAmount,
    );
    let minimalPriceText = $derived(
        formattedMinimalPrice === ""
            ? ""
            : `${minimalPriceLabel} ${formattedMinimalPrice}`,
    );
</script>

<div class="price-box">
    {#if hasDiscount}
        <span class="special-price">
            <span class="price-label">{specialPriceLabel}</span>
            <span class="price" aria-label="Final price"
                >{formattedFinalPrice}</span
            >
        </span>
        <span class="old-price">
            <span class="price-label">{oldPriceLabel}</span>
            <span class="price" aria-label="Regular price"
                >{formattedRegularPrice}</span
            >
        </span>
    {:else}
        <span class="regular-price">
            <span class="price" aria-label="Regular price"
                >{formattedFinalPrice}</span
            >
        </span>
    {/if}

    {#if showMinimalPrice && minimalPriceText}
        {#if useLinkForAsLowAs && productUrl}
            <a href={productUrl} class="minimal-price-link">
                {minimalPriceText}
            </a>
        {:else}
            <span class="minimal-price-link">
                {minimalPriceText}
            </span>
        {/if}
    {/if}
</div>

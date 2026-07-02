<script lang="ts">
    import type { AddToCartService } from '../../js/lib/commerce.ts';
    import { _ } from "../../js/lib/i18n.js";



    interface Props {
          service: AddToCartService;
          className?: string;
          label?: string;
          disabled?: boolean;
      }

      let {
          service,
          className = '',
          label = _('Add to Cart'),
          disabled = false
      }: Props = $props();



    let submitting = false;

    async function handleClick() {
        if (disabled || submitting) return;

        // Trigger minicart loading
        // // needs a compat event like view/frontend/requirejs-config.js
        // window.jQuery?.('[data-block="minicart"]').trigger('contentLoading');

        submitting = true;
        await service.add();
        submitting = false;
    }
</script>

<button
    class={className}
    type="button"
    disabled={disabled || submitting}
    onclick={handleClick}
>
    {label}
</button>

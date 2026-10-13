<script setup lang="ts">
import { cva, type VariantProps } from 'class-variance-authority'
import { computed } from 'vue'
import { cn } from '../lib/cn'

const button = cva(
  'inline-flex shrink-0 select-none items-center justify-center gap-2 whitespace-nowrap rounded-[10px] font-medium outline-none transition-[transform,filter,background-color,border-color,color,box-shadow] duration-150 active:scale-[.97] disabled:pointer-events-none disabled:opacity-45',
  {
    variants: {
      variant: {
        primary: 'grad-bg text-[#04141c] shadow-[0_8px_24px_-10px_var(--accent)] hover:brightness-110 hover:shadow-[0_10px_28px_-8px_var(--accent)]',
        soft: 'border border-line bg-raised text-fg hover:border-line-strong hover:bg-hover',
        outline: 'border border-line-strong text-fg hover:bg-hover',
        ghost: 'text-muted hover:bg-hover hover:text-fg',
        danger: 'bg-bad text-white shadow-[0_8px_24px_-10px_var(--bad)] hover:brightness-110',
      },
      size: {
        xs: 'h-7 px-2.5 text-[12px]',
        sm: 'h-8 px-3 text-[12.5px]',
        md: 'h-9 px-4 text-[13px]',
        icon: 'size-8',
        'icon-sm': 'size-7',
      },
    },
    defaultVariants: { variant: 'soft', size: 'sm' },
  },
)

const props = defineProps<{
  variant?: VariantProps<typeof button>['variant']
  size?: VariantProps<typeof button>['size']
  type?: 'button' | 'submit'
}>()

const classes = computed(() => cn(button({ variant: props.variant, size: props.size })))
</script>

<template>
  <button :type="type ?? 'button'" :class="classes"><slot /></button>
</template>

<script setup>
import { useUiStore } from '../stores/ui';

const ui = useUiStore();
</script>

<template>
    <section class="toast-stack" aria-live="polite" aria-atomic="true">
        <article
            v-for="toast in ui.toasts"
            :key="toast.id"
            class="toast"
            :class="`toast--${toast.type}`"
        >
            <span>{{ toast.message }}</span>
            <button class="toast__close" type="button" @click="ui.removeToast(toast.id)">×</button>
        </article>
    </section>
</template>
